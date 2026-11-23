<?php
/**
 * Subscription repository and lifecycle state transitions.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subscriptions {

	const STATUSES = array( 'trial', 'active', 'pending', 'past_due', 'grace', 'suspended', 'cancelled', 'expired' );
	const ACCESS_STATUSES = array( 'trial', 'active', 'grace' );

	/**
	 * Get a subscription.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'subscriptions' ) . ' WHERE id = %d LIMIT 1', absint( $id ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Get the current subscription for a tenant.
	 *
	 * @param int $tenant_id
	 * @return object|null
	 */
	public static function get_current( $tenant_id ) {
		global $wpdb;
		$tenant_id = absint( $tenant_id );
		if ( ! $tenant_id ) {
			return null;
		}

		$profile = Tenant::get_profile( $tenant_id );
		if ( $profile && ! empty( $profile->current_subscription_id ) ) {
			$subscription = self::get( $profile->current_subscription_id );
			if ( $subscription ) {
				return $subscription;
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . BillingDb::table( 'subscriptions' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT 1',
				$tenant_id
			)
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * List subscriptions for admin screens.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'status'   => '',
			'plan_id'  => 0,
			'tenant_id'=> 0,
			'search'   => '',
			'page'     => 1,
			'per_page' => 25,
		);
		$r = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $r['status'] ) && 'all' !== $r['status'] ) {
			$where[]  = 's.status = %s';
			$values[] = sanitize_key( $r['status'] );
		}
		if ( ! empty( $r['plan_id'] ) ) {
			$where[]  = 's.plan_id = %d';
			$values[] = absint( $r['plan_id'] );
		}
		if ( ! empty( $r['tenant_id'] ) ) {
			$where[]  = 's.tenant_id = %d';
			$values[] = absint( $r['tenant_id'] );
		}
		if ( ! empty( $r['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$where[]  = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$per_page = max( 1, min( 100, absint( $r['per_page'] ) ) );
		$page     = max( 1, absint( $r['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );

		$count_sql = 'SELECT COUNT(*) FROM ' . BillingDb::table( 'subscriptions' ) . ' s
			LEFT JOIN ' . $wpdb->prefix . 'bg360_workspaces w ON w.id = s.tenant_id
			LEFT JOIN ' . $wpdb->users . ' u ON u.ID = w.user_id
			WHERE ' . $where_sql;
		if ( $values ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$sql = 'SELECT s.*, u.user_login, u.user_email, u.display_name, w.name AS workspace_name, w.business_name,
				p.name AS plan_name, p.slug AS plan_slug
			FROM ' . BillingDb::table( 'subscriptions' ) . ' s
			LEFT JOIN ' . $wpdb->prefix . 'bg360_workspaces w ON w.id = s.tenant_id
			LEFT JOIN ' . $wpdb->users . ' u ON u.ID = w.user_id
			LEFT JOIN ' . BillingDb::table( 'plans' ) . ' p ON p.id = s.plan_id
			WHERE ' . $where_sql . ' ORDER BY s.id DESC LIMIT %d OFFSET %d';
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_values ) );

		return array(
			'items'    => array_map( array( __CLASS__, 'normalize_row' ), $rows ? $rows : array() ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Create a subscription.
	 *
	 * @param array $data
	 * @return int|\WP_Error
	 */
	public static function create( $data ) {
		global $wpdb;

		$tenant_id = absint( $data['tenant_id'] ?? 0 );
		$plan_id   = absint( $data['plan_id'] ?? 0 );
		$plan      = Plans::get( $plan_id );
		if ( ! $tenant_id || ! $plan ) {
			return new \WP_Error( 'invalid_subscription', __( 'A valid customer and plan are required.', 'bizgrowth360-app-builder' ) );
		}

		$status   = sanitize_key( $data['status'] ?? 'pending' );
		$status   = in_array( $status, self::STATUSES, true ) ? $status : 'pending';
		$interval = 'annual' === sanitize_key( $data['billing_interval'] ?? 'monthly' ) ? 'annual' : 'monthly';
		$now      = BillingDb::now();

		$record = array(
			'tenant_id'                 => $tenant_id,
			'plan_id'                   => $plan_id,
			'status'                    => $status,
			'billing_interval'          => $interval,
			'currency'                  => $plan->currency,
			'amount'                    => Plans::price_for_interval( $plan, $interval ),
			'trial_ends_at'             => $data['trial_ends_at'] ?? null,
			'current_period_start'      => $data['current_period_start'] ?? $now,
			'current_period_end'        => $data['current_period_end'] ?? null,
			'grace_ends_at'             => null,
			'cancel_at_period_end'      => ! empty( $data['cancel_at_period_end'] ) ? 1 : 0,
			'cancelled_at'              => null,
			'suspended_at'              => null,
			'ended_at'                  => null,
			'gateway'                   => sanitize_key( $data['gateway'] ?? 'manual' ),
			'gateway_subscription_id'   => sanitize_text_field( $data['gateway_subscription_id'] ?? '' ) ?: null,
			'gateway_customer_id'       => sanitize_text_field( $data['gateway_customer_id'] ?? '' ) ?: null,
			'coupon_id'                 => ! empty( $data['coupon_id'] ) ? absint( $data['coupon_id'] ) : null,
			'pending_plan_id'           => null,
			'pending_billing_interval'  => '',
			'created_at'                => $now,
			'updated_at'                => $now,
		);

		$wpdb->insert( BillingDb::table( 'subscriptions' ), $record );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'db_error', __( 'Could not create subscription.', 'bizgrowth360-app-builder' ) );
		}

		Tenant::ensure_profile( $tenant_id );
		Tenant::set_current_subscription( $tenant_id, $id );
		if ( class_exists( '\Bizgrowth360\AppBuilder\SaasDb' ) ) {
			\Bizgrowth360\AppBuilder\SaasDb::update_workspace( $tenant_id, array( 'plan_id' => $plan_id ) );
		}
		self::history( $id, $tenant_id, '', $status, $plan_id, 'created', get_current_user_id() );
		BillingDb::audit( 'subscription_created', array( 'tenant_id' => $tenant_id, 'object_type' => 'subscription', 'object_id' => $id, 'details' => array( 'plan_id' => $plan_id, 'status' => $status ) ) );

		return $id;
	}

	/**
	 * Update subscription fields and optionally record a transition.
	 *
	 * @param int   $id
	 * @param array $data
	 * @param array $args
	 * @return bool
	 */
	public static function update( $id, $data, $args = array() ) {
		global $wpdb;
		$subscription = self::get( $id );
		if ( ! $subscription ) {
			return false;
		}

		$data['updated_at'] = BillingDb::now();
		$wpdb->update( BillingDb::table( 'subscriptions' ), $data, array( 'id' => absint( $id ) ) );

		if ( ! empty( $args['status'] ) && $args['status'] !== $subscription->status ) {
			self::history(
				$id,
				$subscription->tenant_id,
				$subscription->status,
				sanitize_key( $args['status'] ),
				$data['plan_id'] ?? $subscription->plan_id,
				$args['reason'] ?? '',
				$args['actor_id'] ?? get_current_user_id()
			);
		}

		return true;
	}

	/**
	 * Transition a subscription status with history.
	 *
	 * @param int    $id
	 * @param string $status
	 * @param string $reason
	 * @param array  $extra
	 * @return bool
	 */
	public static function transition( $id, $status, $reason = '', $extra = array() ) {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$data = array_merge( $extra, array( 'status' => $status ) );
		return self::update( $id, $data, array( 'status' => $status, 'reason' => $reason ) );
	}

	/**
	 * Write subscription history.
	 *
	 * @param int    $subscription_id
	 * @param int    $tenant_id
	 * @param string $from
	 * @param string $to
	 * @param int    $plan_id
	 * @param string $reason
	 * @param int    $actor_id
	 */
	public static function history( $subscription_id, $tenant_id, $from, $to, $plan_id, $reason = '', $actor_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			BillingDb::table( 'subscription_history' ),
			array(
				'subscription_id' => absint( $subscription_id ),
				'tenant_id'       => absint( $tenant_id ),
				'from_status'     => sanitize_key( $from ),
				'to_status'       => sanitize_key( $to ),
				'plan_id'         => absint( $plan_id ) ?: null,
				'reason'          => sanitize_text_field( $reason ),
				'actor_id'        => absint( $actor_id ),
				'created_at'      => BillingDb::now(),
			)
		);
	}

	/**
	 * History for a subscription or tenant.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function history_list( $args = array() ) {
		global $wpdb;
		$defaults = array( 'subscription_id' => 0, 'tenant_id' => 0, 'limit' => 50 );
		$r = wp_parse_args( $args, $defaults );
		$where = array( '1=1' );
		$values = array();
		if ( $r['subscription_id'] ) {
			$where[]  = 'subscription_id = %d';
			$values[] = absint( $r['subscription_id'] );
		}
		if ( $r['tenant_id'] ) {
			$where[]  = 'tenant_id = %d';
			$values[] = absint( $r['tenant_id'] );
		}
		$values[] = max( 1, absint( $r['limit'] ) );
		$sql = 'SELECT * FROM ' . BillingDb::table( 'subscription_history' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) ) ?: array();
	}

	/**
	 * Whether a status grants access to paid features.
	 *
	 * @param string $status
	 * @return bool
	 */
	public static function status_allows_access( $status ) {
		return in_array( $status, self::ACCESS_STATUSES, true );
	}

	/**
	 * Whether a status may start new builds.
	 *
	 * @param string $status
	 * @return bool
	 */
	public static function status_allows_build( $status ) {
		return in_array( $status, array( 'trial', 'active', 'grace' ), true );
	}

	/**
	 * Create a trial or pending subscription for checkout.
	 *
	 * @param int    $tenant_id
	 * @param object $plan
	 * @param string $interval
	 * @param string $gateway
	 * @return object|\WP_Error
	 */
	public static function start( $tenant_id, $plan, $interval, $gateway = 'manual' ) {
		$interval = 'annual' === sanitize_key( $interval ) ? 'annual' : 'monthly';
		$trial    = max( 0, (int) $plan->trial_days );
		$now      = BillingDb::now();

		if ( $trial > 0 ) {
			$status = 'trial';
			$trial_end = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . $trial . ' days' ) );
			$period_end = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . ( 'annual' === $interval ? '1 year' : '1 month' ) ) );
		} elseif ( 0.0 === (float) Plans::price_for_interval( $plan, $interval ) ) {
			$status     = 'active';
			$trial_end  = null;
			$period_end = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . ( 'annual' === $interval ? '1 year' : '1 month' ) ) );
		} else {
			$status     = 'pending';
			$trial_end  = null;
			$period_end = null;
		}

		$id = self::create(
			array(
				'tenant_id'            => $tenant_id,
				'plan_id'              => $plan->id,
				'status'               => $status,
				'billing_interval'     => $interval,
				'trial_ends_at'        => $trial_end,
				'current_period_start' => $now,
				'current_period_end'   => $period_end,
				'gateway'              => $gateway,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return self::get( $id );
	}

	/**
	 * Activate or renew a subscription after verified payment.
	 *
	 * @param int    $id
	 * @param array  $args
	 * @return bool
	 */
	public static function activate( $id, $args = array() ) {
		$subscription = self::get( $id );
		if ( ! $subscription ) {
			return false;
		}

		$now      = BillingDb::now();
		$interval = $subscription->billing_interval;
		$start    = ! empty( $args['period_start'] ) ? $args['period_start'] : $now;
		$end      = ! empty( $args['period_end'] )
			? $args['period_end']
			: gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +' . ( 'annual' === $interval ? '1 year' : '1 month' ) ) );

		$data = array(
			'status'                  => 'active',
			'current_period_start'    => $start,
			'current_period_end'      => $end,
			'grace_ends_at'           => null,
			'cancelled_at'            => null,
			'suspended_at'            => null,
			'ended_at'                => null,
			'cancel_at_period_end'    => 0,
			'gateway_subscription_id' => $args['gateway_subscription_id'] ?? $subscription->gateway_subscription_id,
			'gateway_customer_id'     => $args['gateway_customer_id'] ?? $subscription->gateway_customer_id,
		);

		// Apply a scheduled downgrade at the start of the new period.
		if ( ! empty( $subscription->pending_plan_id ) ) {
			$pending = Plans::get( $subscription->pending_plan_id );
			if ( $pending ) {
				$data['plan_id']                  = $pending->id;
				$data['billing_interval']         = $subscription->pending_billing_interval ?: $interval;
				$data['amount']                   = Plans::price_for_interval( $pending, $data['billing_interval'] );
				$data['pending_plan_id']          = null;
				$data['pending_billing_interval'] = '';
			}
		}

		return self::update( $id, $data, array( 'status' => 'active', 'reason' => $args['reason'] ?? 'payment_verified' ) );
	}

	/**
	 * Mark past due and enter a grace window.
	 *
	 * @param int $id
	 * @param int $grace_days
	 * @return bool
	 */
	public static function mark_past_due( $id, $grace_days = 3 ) {
		$grace_end = gmdate( 'Y-m-d H:i:s', strtotime( BillingDb::now() . ' +' . max( 0, (int) $grace_days ) . ' days' ) );
		return self::transition( $id, 'past_due', 'renewal_failed', array( 'grace_ends_at' => $grace_end ) );
	}

	/**
	 * Enter grace period.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function enter_grace( $id ) {
		return self::transition( $id, 'grace', 'grace_period_started' );
	}

	/**
	 * Suspend a subscription.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function suspend( $id ) {
		return self::transition( $id, 'suspended', 'payment_failure', array( 'suspended_at' => BillingDb::now() ) );
	}

	/**
	 * Expire a subscription.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function expire( $id ) {
		return self::transition( $id, 'expired', 'period_ended', array( 'ended_at' => BillingDb::now() ) );
	}

	/**
	 * Cancel now or at period end.
	 *
	 * @param int  $id
	 * @param bool $immediate
	 * @return bool
	 */
	public static function cancel( $id, $immediate = false ) {
		if ( $immediate ) {
			return self::transition( $id, 'cancelled', 'cancelled_immediately', array( 'cancelled_at' => BillingDb::now(), 'ended_at' => BillingDb::now() ) );
		}
		return self::update( $id, array( 'cancel_at_period_end' => 1 ), array() );
	}

	/**
	 * Resume a cancelled-at-period-end subscription.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function resume( $id ) {
		$subscription = self::get( $id );
		if ( ! $subscription ) {
			return false;
		}
		if ( in_array( $subscription->status, array( 'cancelled', 'expired', 'suspended' ), true ) ) {
			return self::transition( $id, 'pending', 'resume_requires_payment', array( 'cancel_at_period_end' => 0 ) );
		}
		return self::update( $id, array( 'cancel_at_period_end' => 0 ), array() );
	}

	/**
	 * Change plan immediately or schedule at period end.
	 *
	 * @param int    $id
	 * @param object $new_plan
	 * @param string $interval
	 * @param bool   $immediate
	 * @return bool
	 */
	public static function change_plan( $id, $new_plan, $interval, $immediate = false ) {
		$subscription = self::get( $id );
		if ( ! $subscription || ! $new_plan ) {
			return false;
		}

		$interval = 'annual' === sanitize_key( $interval ) ? 'annual' : 'monthly';

		if ( ! $immediate ) {
			return self::update(
				$id,
				array(
					'pending_plan_id'          => $new_plan->id,
					'pending_billing_interval' => $interval,
				),
				array()
			);
		}

		$result = self::update(
			$id,
			array(
				'plan_id'                  => $new_plan->id,
				'billing_interval'         => $interval,
				'amount'                   => Plans::price_for_interval( $new_plan, $interval ),
				'pending_plan_id'          => null,
				'pending_billing_interval' => '',
				'current_period_start'     => BillingDb::now(),
				'current_period_end'       => gmdate( 'Y-m-d H:i:s', strtotime( BillingDb::now() . ' +' . ( 'annual' === $interval ? '1 year' : '1 month' ) ) ),
			),
			array( 'status' => $subscription->status, 'reason' => 'plan_changed' )
		);
		if ( $result && class_exists( '\Bizgrowth360\AppBuilder\SaasDb' ) ) {
			\Bizgrowth360\AppBuilder\SaasDb::update_workspace( $subscription->tenant_id, array( 'plan_id' => $new_plan->id ) );
		}
		return $result;
	}

	/**
	 * Extend a subscription by one billing period.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function renew( $id ) {
		$subscription = self::get( $id );
		if ( ! $subscription ) {
			return false;
		}
		$start = BillingDb::now();
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +' . ( 'annual' === $subscription->billing_interval ? '1 year' : '1 month' ) ) );
		return self::activate( $id, array( 'period_start' => $start, 'period_end' => $end, 'reason' => 'renewed' ) );
	}

	/**
	 * Ensure every existing workspace has a billing subscription.
	 */
	public static function ensure_workspaces() {
		global $wpdb;
		$table = $wpdb->prefix . 'bg360_workspaces';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$workspaces = $wpdb->get_results( "SELECT * FROM {$table}" );
		foreach ( $workspaces ? $workspaces : array() as $workspace ) {
			self::ensure_for_workspace( $workspace->id );
		}
	}

	/**
	 * Ensure a workspace has a current subscription based on its SaaS plan.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function ensure_for_workspace( $workspace_id ) {
		$workspace_id = absint( $workspace_id );
		if ( ! $workspace_id ) {
			return null;
		}
		$existing = self::get_current( $workspace_id );
		if ( $existing ) {
			return $existing;
		}
		$workspace = \Bizgrowth360\AppBuilder\SaasDb::get_workspace( $workspace_id );
		if ( ! $workspace ) {
			return null;
		}
		$plan = Plans::get( $workspace->plan_id );
		if ( ! $plan ) {
			$plan = Plans::get_by_slug( 'starter' ) ?: Plans::get_by_slug( 'free' );
		}
		if ( ! $plan ) {
			return null;
		}

		$id = self::create(
			array(
				'tenant_id'            => $workspace_id,
				'plan_id'              => $plan->id,
				'status'               => 'active',
				'billing_interval'     => 'monthly',
				'current_period_start' => BillingDb::now(),
				'current_period_end'   => gmdate( 'Y-m-d H:i:s', strtotime( BillingDb::now() . ' +1 month' ) ),
				'gateway'              => 'manual',
			)
		);
		if ( is_wp_error( $id ) ) {
			return null;
		}
		self::history( $id, $workspace_id, '', 'active', $plan->id, 'workspace_provisioned', 0 );
		return self::get( $id );
	}

	/**
	 * Normalize a subscription row.
	 *
	 * @param object $row
	 * @return object
	 */
	public static function normalize_row( $row ) {
		foreach ( array( 'id', 'tenant_id', 'plan_id', 'coupon_id', 'pending_plan_id' ) as $field ) {
			if ( isset( $row->$field ) ) {
				$row->$field = (int) $row->$field;
			}
		}
		$row->amount = (float) $row->amount;
		$row->cancel_at_period_end = ! empty( $row->cancel_at_period_end );
		return $row;
	}
}
