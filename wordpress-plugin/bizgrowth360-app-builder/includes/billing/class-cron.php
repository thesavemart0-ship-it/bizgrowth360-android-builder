<?php
/**
 * Billing cron: reminders, expiry, grace, suspension and recovery.
 *
 * WP-Cron is not a reliable scheduler. Production sites should also run
 * `wp cron event run --due-now` from a real system cron every 5–15 minutes.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron {

	const HOOK = 'bizgrowth360_billing_cron';
	const LOCK = 'bizgrowth360_billing_cron_lock';
	const POLICY_OPTION = 'bizgrowth360_billing_policy';

	/**
	 * Default lifecycle policy.
	 *
	 * @return array
	 */
	public static function policy_defaults() {
		return array(
			'grace_days'    => 3,
			'reminder_days' => '7,3,1',
			'retry_days'    => '1,3',
			'suspend_after_grace' => true,
		);
	}

	/**
	 * Get policy settings.
	 *
	 * @return array
	 */
	public static function get_policy() {
		$stored = get_option( self::POLICY_OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::policy_defaults() );
	}

	/**
	 * Save policy settings.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function save_policy( $input ) {
		$clean = array(
			'grace_days'    => max( 0, min( 60, absint( $input['grace_days'] ?? 3 ) ) ),
			'reminder_days' => implode( ',', array_filter( array_map( 'absint', explode( ',', (string) ( $input['reminder_days'] ?? '7,3,1' ) ) ) ) ),
			'retry_days'    => implode( ',', array_filter( array_map( 'absint', explode( ',', (string) ( $input['retry_days'] ?? '1,3' ) ) ) ) ),
			'suspend_after_grace' => ! empty( $input['suspend_after_grace'] ),
		);
		update_option( self::POLICY_OPTION, $clean );
		BillingDb::audit( 'billing_policy_updated', array( 'object_type' => 'settings', 'details' => $clean ) );
		return $clean;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	/**
	 * Schedule the hourly event.
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Run lifecycle processing.
	 *
	 * @return array
	 */
	public function run() {
		if ( get_transient( self::LOCK ) ) {
			return array( 'skipped' => true, 'reason' => 'locked' );
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		global $wpdb;
		$now      = current_time( 'mysql' );
		$policy   = self::get_policy();
		$table    = BillingDb::table( 'subscriptions' );
		$results  = array( 'reminders' => 0, 'past_due' => 0, 'suspended' => 0, 'expired' => 0 );

		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('trial','active','grace','past_due') ORDER BY current_period_end ASC LIMIT 500"
			)
		);

		foreach ( $subscriptions ? $subscriptions : array() as $row ) {
			$subscription = Subscriptions::normalize_row( $row );
			$end          = $subscription->current_period_end;
			$trial_end    = $subscription->trial_ends_at;

			if ( 'trial' === $subscription->status && $trial_end && strtotime( $trial_end ) <= strtotime( $now ) ) {
				Subscriptions::transition( $subscription->id, 'expired', 'trial_expired', array( 'ended_at' => $now ) );
				Notifications::send( 'subscription_expiring', $subscription->tenant_id, array( 'renewal_date' => $trial_end, 'status' => 'Expired' ) );
				$results['expired']++;
				continue;
			}

			if ( $end && strtotime( $end ) <= strtotime( $now ) ) {
				if ( $subscription->cancel_at_period_end ) {
					Subscriptions::transition( $subscription->id, 'expired', 'cancelled_at_period_end', array( 'ended_at' => $now ) );
					Notifications::send( 'subscription_cancelled', $subscription->tenant_id, array( 'renewal_date' => $end ) );
					$results['expired']++;
					continue;
				}

				if ( in_array( $subscription->status, array( 'active', 'trial' ), true ) ) {
					Subscriptions::mark_past_due( $subscription->id, (int) $policy['grace_days'] );
					Notifications::send( 'payment_failed', $subscription->tenant_id, array( 'amount' => $subscription->amount, 'currency' => $subscription->currency, 'status' => 'Past Due' ) );
					$results['past_due']++;
				}
			}

			if ( 'past_due' === $subscription->status ) {
				if ( $subscription->grace_ends_at && strtotime( $subscription->grace_ends_at ) <= strtotime( $now ) ) {
					if ( ! empty( $policy['suspend_after_grace'] ) ) {
						Subscriptions::suspend( $subscription->id );
						Notifications::send( 'grace_period', $subscription->tenant_id, array( 'renewal_date' => $subscription->grace_ends_at, 'status' => 'Suspended' ) );
						$results['suspended']++;
					} else {
						Subscriptions::expire( $subscription->id );
						$results['expired']++;
					}
				} else {
					Notifications::send( 'grace_period', $subscription->tenant_id, array( 'renewal_date' => $subscription->grace_ends_at, 'status' => 'Grace Period' ) );
				}
			}

			if ( 'grace' === $subscription->status && $subscription->grace_ends_at && strtotime( $subscription->grace_ends_at ) <= strtotime( $now ) ) {
				Subscriptions::suspend( $subscription->id );
				$results['suspended']++;
			}

			// Renewal reminders, once per subscription period and reminder day.
			if ( $end && in_array( $subscription->status, array( 'active', 'trial' ), true ) ) {
				$days_left = (int) floor( ( strtotime( $end ) - strtotime( $now ) ) / DAY_IN_SECONDS );
				$reminders = array_filter( array_map( 'absint', explode( ',', (string) $policy['reminder_days'] ) ) );
				foreach ( $reminders as $days ) {
					if ( $days_left === (int) $days ) {
						$key = 'bg360_reminder_' . $subscription->id . '_' . md5( (string) $end ) . '_' . $days;
						if ( ! get_option( $key ) ) {
							Notifications::send( 'renewal_reminder', $subscription->tenant_id, array( 'renewal_date' => $end, 'days' => $days ) );
							update_option( $key, 1, false );
							$results['reminders']++;
						}
					}
				}
			}
		}

		delete_transient( self::LOCK );
		BillingDb::audit( 'billing_cron_run', array( 'object_type' => 'cron', 'details' => $results ) );
		return $results;
	}
}
