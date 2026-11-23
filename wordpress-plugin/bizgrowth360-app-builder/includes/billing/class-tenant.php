<?php
/**
 * Tenant abstraction over SaaS workspaces.
 *
 * The SaaS foundation (SaasDb/TenantManager) owns workspaces, applications and
 * portal users. Billing stores its subscription/transaction/invoice rows against
 * the workspace ID so tenant isolation matches the rest of the plugin.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

use Bizgrowth360\AppBuilder\SaasDb;
use Bizgrowth360\AppBuilder\TenantManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tenant {

	/**
	 * Current workspace ID.
	 *
	 * @return int
	 */
	public static function current_id() {
		$workspace = TenantManager::get_current_workspace();
		if ( $workspace ) {
			return (int) $workspace->id;
		}
		return 0;
	}

	/**
	 * Get a workspace.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function workspace( $workspace_id ) {
		return SaasDb::get_workspace( absint( $workspace_id ) );
	}

	/**
	 * Workspace owner user ID.
	 *
	 * @param int $workspace_id
	 * @return int
	 */
	public static function user_id( $workspace_id ) {
		$workspace = self::workspace( $workspace_id );
		return $workspace ? (int) $workspace->user_id : 0;
	}

	/**
	 * Ensure a billing profile exists for a workspace.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function ensure_profile( $workspace_id ) {
		global $wpdb;
		$workspace_id = absint( $workspace_id );
		if ( ! $workspace_id ) {
			return null;
		}

		$table = BillingDb::table( 'customer_profiles' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %d LIMIT 1", $workspace_id ) );
		if ( $row ) {
			return $row;
		}

		$workspace = self::workspace( $workspace_id );
		$user_id   = $workspace ? (int) $workspace->user_id : 0;
		$user      = $user_id ? get_userdata( $user_id ) : null;
		$now       = BillingDb::now();
		$wpdb->insert(
			$table,
			array(
				'tenant_id'     => $workspace_id,
				'business_name' => $workspace ? $workspace->business_name : '',
				'customer_name' => $user ? $user->display_name : ( $workspace ? $workspace->name : '' ),
				'email'         => $user ? $user->user_email : '',
				'phone'         => $workspace ? $workspace->phone : '',
				'country'       => 'IN',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %d LIMIT 1", $workspace_id ) );
	}

	/**
	 * Get a profile.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function get_profile( $workspace_id ) {
		global $wpdb;
		$table = BillingDb::table( 'customer_profiles' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %d LIMIT 1", absint( $workspace_id ) ) );
	}

	/**
	 * Update billing details.
	 *
	 * @param int   $workspace_id
	 * @param array $data
	 * @return bool
	 */
	public static function update_profile( $workspace_id, $data ) {
		global $wpdb;
		self::ensure_profile( $workspace_id );

		$allowed = array( 'business_name', 'customer_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postal_code', 'country', 'gstin' );
		$clean   = array();
		foreach ( $allowed as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$clean[ $field ] = sanitize_text_field( $data[ $field ] );
			}
		}
		if ( isset( $clean['email'] ) ) {
			$clean['email'] = sanitize_email( $clean['email'] );
		}
		if ( isset( $clean['gstin'] ) ) {
			$clean['gstin'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $clean['gstin'] ) );
		}
		if ( isset( $clean['country'] ) ) {
			$clean['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $clean['country'] ), 0, 2 ) ) ?: 'IN';
		}
		$clean['updated_at'] = BillingDb::now();

		$wpdb->update( BillingDb::table( 'customer_profiles' ), $clean, array( 'tenant_id' => absint( $workspace_id ) ) );

		$workspace_update = array();
		if ( isset( $clean['business_name'] ) ) {
			$workspace_update['business_name'] = $clean['business_name'];
		}
		if ( isset( $clean['phone'] ) ) {
			$workspace_update['phone'] = $clean['phone'];
		}
		if ( $workspace_update ) {
			SaasDb::update_workspace( $workspace_id, $workspace_update );
		}

		BillingDb::audit( 'profile_updated', array( 'tenant_id' => $workspace_id, 'object_type' => 'profile', 'object_id' => $workspace_id, 'details' => array_keys( $clean ) ) );
		return true;
	}

	/**
	 * Point a workspace at its current subscription.
	 *
	 * @param int $workspace_id
	 * @param int $subscription_id
	 */
	public static function set_current_subscription( $workspace_id, $subscription_id ) {
		global $wpdb;
		self::ensure_profile( $workspace_id );
		$wpdb->update(
			BillingDb::table( 'customer_profiles' ),
			array( 'current_subscription_id' => absint( $subscription_id ), 'updated_at' => BillingDb::now() ),
			array( 'tenant_id' => absint( $workspace_id ) )
		);
	}

	/**
	 * Get (or lazily create) the current subscription.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function subscription( $workspace_id ) {
		$workspace_id = absint( $workspace_id );
		if ( ! $workspace_id ) {
			return null;
		}
		$subscription = Subscriptions::get_current( $workspace_id );
		if ( $subscription ) {
			// Keep manually managed subscriptions aligned with an admin workspace
			// plan change while preserving gateway-managed subscriptions.
			$workspace = self::workspace( $workspace_id );
			if ( $workspace && 'manual' === $subscription->gateway && (int) $workspace->plan_id !== (int) $subscription->plan_id ) {
				$plan = Plans::get( $workspace->plan_id );
				if ( $plan ) {
					Subscriptions::change_plan( $subscription->id, $plan, $subscription->billing_interval, true );
					$subscription = Subscriptions::get_current( $workspace_id );
				}
			}
			return $subscription;
		}

		return Subscriptions::ensure_for_workspace( $workspace_id );
	}

	/**
	 * Get the plan for a workspace.
	 *
	 * @param int $workspace_id
	 * @return object|null
	 */
	public static function plan( $workspace_id ) {
		$subscription = self::subscription( $workspace_id );
		return $subscription ? Plans::get( $subscription->plan_id ) : null;
	}

	/**
	 * Whether the workspace owner is a platform administrator.
	 *
	 * @param int $workspace_id
	 * @return bool
	 */
	public static function is_platform_admin( $workspace_id ) {
		$user_id = self::user_id( $workspace_id );
		return $user_id && user_can( $user_id, 'manage_options' );
	}

	/**
	 * Workspace owner display name.
	 *
	 * @param int $workspace_id
	 * @return string
	 */
	public static function name( $workspace_id ) {
		$workspace = self::workspace( $workspace_id );
		if ( $workspace ) {
			return $workspace->business_name ?: $workspace->name;
		}
		return sprintf( 'Workspace #%d', absint( $workspace_id ) );
	}

	/**
	 * Workspace owner email.
	 *
	 * @param int $workspace_id
	 * @return string
	 */
	public static function email( $workspace_id ) {
		$user_id = self::user_id( $workspace_id );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		return $user ? $user->user_email : '';
	}

	/**
	 * Workspace status.
	 *
	 * @param int $workspace_id
	 * @return string
	 */
	public static function status( $workspace_id ) {
		$workspace = self::workspace( $workspace_id );
		return $workspace ? $workspace->status : '';
	}
}
