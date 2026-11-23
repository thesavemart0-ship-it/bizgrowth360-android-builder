<?php
/**
 * Upgrade, downgrade, proration quotes and usage conflict detection.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlanChanges {

	const PRORATION_OPTION = 'bizgrowth360_billing_proration_enabled';

	/**
	 * Whether proration credits are enabled.
	 *
	 * @return bool
	 */
	public static function proration_enabled() {
		return (bool) get_option( self::PRORATION_OPTION, 1 );
	}

	/**
	 * Quote a plan change.
	 *
	 * @param int    $tenant_id
	 * @param object $new_plan
	 * @param string $interval
	 * @param string $coupon_code
	 * @return array
	 */
	public static function quote( $tenant_id, $new_plan, $interval, $coupon_code = '' ) {
		$tenant_id = absint( $tenant_id );
		$interval  = 'annual' === sanitize_key( $interval ) ? 'annual' : 'monthly';
		$current   = Subscriptions::get_current( $tenant_id );
		$plan      = $current ? Plans::get( $current->plan_id ) : null;
		$new_price = Plans::price_for_interval( $new_plan, $interval );
		$old_price = $plan ? Plans::price_for_interval( $plan, $current->billing_interval ) : 0;

		$credit = 0.0;
		if ( $plan && $current && self::proration_enabled() && ! empty( $current->current_period_end ) ) {
			$remaining = max( 0, strtotime( $current->current_period_end ) - current_time( 'timestamp' ) );
			$total     = 'annual' === $current->billing_interval ? YEAR_IN_SECONDS : ( 30 * DAY_IN_SECONDS );
			$fraction  = $total > 0 ? min( 1, $remaining / $total ) : 0;
			$credit    = round( $old_price * $fraction, 2 );
		}

		$discount = 0.0;
		$coupon   = null;
		if ( ! empty( $coupon_code ) ) {
			$result = Coupons::validate( $coupon_code, $new_plan->id, $tenant_id, $new_price );
			if ( ! empty( $result['valid'] ) ) {
				$discount = (float) $result['discount'];
				$coupon   = $result['coupon'];
			}
		}

		$due_before_tax = max( 0, $new_price - $discount - $credit );
		$tax            = Tax::calculate( $due_before_tax );
		$is_downgrade   = $old_price > $new_price;

		return array(
			'current_plan'   => $plan,
			'new_plan'       => $new_plan,
			'interval'       => $interval,
			'old_price'      => $old_price,
			'new_price'      => $new_price,
			'credit'         => $credit,
			'discount'       => $discount,
			'coupon'         => $coupon,
			'tax'            => $tax,
			'amount_due'     => (float) $tax['total'],
			'is_downgrade'   => $is_downgrade,
			'proration'      => self::proration_enabled(),
			'usage_conflicts'=> self::usage_conflicts( $tenant_id, $new_plan ),
		);
	}

	/**
	 * Request a plan change. Downgrades are scheduled; upgrades require payment
	 * unless the proration credit covers the new plan.
	 *
	 * @param int    $tenant_id
	 * @param object $new_plan
	 * @param string $interval
	 * @param string $coupon_code
	 * @return array
	 */
	public static function request( $tenant_id, $new_plan, $interval, $coupon_code = '' ) {
		$current = Subscriptions::get_current( $tenant_id );
		if ( ! $current ) {
			return array( 'success' => false, 'message' => __( 'No current subscription found.', 'bizgrowth360-app-builder' ) );
		}
		$quote = self::quote( $tenant_id, $new_plan, $interval, $coupon_code );

		if ( $quote['is_downgrade'] ) {
			Subscriptions::change_plan( $current->id, $new_plan, $interval, false );
			Notifications::send( 'plan_downgraded', $tenant_id, array( 'plan' => $new_plan->name, 'renewal_date' => $current->current_period_end ) );
			return array(
				'success'   => true,
				'scheduled' => true,
				'message'   => sprintf(
					/* translators: %s: plan name */
					__( 'Downgrade to %s scheduled for the next billing period. Existing apps are not deleted.', 'bizgrowth360-app-builder' ),
					$new_plan->name
				),
				'quote'     => $quote,
			);
		}

		if ( $quote['amount_due'] <= 0 ) {
			Subscriptions::change_plan( $current->id, $new_plan, $interval, true );
			Notifications::send( 'plan_upgraded', $tenant_id, array( 'plan' => $new_plan->name ) );
			return array(
				'success' => true,
				'changed' => true,
				'message' => __( 'Plan upgraded using your proration credit.', 'bizgrowth360-app-builder' ),
				'quote'   => $quote,
			);
		}

		return array(
			'success'       => true,
			'needs_payment' => true,
			'message'       => __( 'Complete payment to activate the upgraded plan.', 'bizgrowth360-app-builder' ),
			'quote'         => $quote,
		);
	}

	/**
	 * Detect usage that exceeds a target plan.
	 *
	 * @param int    $tenant_id
	 * @param object $new_plan
	 * @return array
	 */
	public static function usage_conflicts( $tenant_id, $new_plan ) {
		$summary = Usage::summary( $tenant_id );
		$used    = $summary['used'];
		$conflicts = array();

		if ( (int) $new_plan->max_apps >= 0 && $used['apps'] > (int) $new_plan->max_apps ) {
			$conflicts[] = sprintf(
				/* translators: 1: current apps, 2: new limit */
				__( 'You currently have %1$d applications; the target plan allows %2$d. Existing apps will not be deleted, but you cannot add more.', 'bizgrowth360-app-builder' ),
				$used['apps'],
				$new_plan->max_apps
			);
		}
		if ( (int) $new_plan->monthly_builds >= 0 && $used['build_started'] > (int) $new_plan->monthly_builds ) {
			$conflicts[] = sprintf(
				/* translators: 1: used builds, 2: new limit */
				__( 'You have used %1$d builds this period; the target plan allows %2$d. New builds will be blocked until the next period.', 'bizgrowth360-app-builder' ),
				$used['build_started'],
				$new_plan->monthly_builds
			);
		}
		if ( (int) $new_plan->storage_mb >= 0 && $used['storage_mb'] > (int) $new_plan->storage_mb ) {
			$conflicts[] = sprintf(
				/* translators: 1: used MB, 2: new limit MB */
				__( 'Your stored artifacts use %1$s MB; the target plan allows %2$s MB. Remove old artifacts before the downgrade takes effect.', 'bizgrowth360-app-builder' ),
				number_format_i18n( $used['storage_mb'], 2 ),
				number_format_i18n( $new_plan->storage_mb )
			);
		}

		return $conflicts;
	}
}
