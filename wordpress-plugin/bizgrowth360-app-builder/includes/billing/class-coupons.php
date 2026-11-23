<?php
/**
 * Coupon management and server-side eligibility validation.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coupons {

	/**
	 * Get a coupon by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'coupons' ) . ' WHERE id = %d LIMIT 1', absint( $id ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Get a coupon by code.
	 *
	 * @param string $code
	 * @return object|null
	 */
	public static function get_by_code( $code ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'coupons' ) . ' WHERE code = %s LIMIT 1', strtoupper( sanitize_text_field( $code ) ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * List coupons.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . BillingDb::table( 'coupons' ) . ' ORDER BY id DESC' );
		return array_map( array( __CLASS__, 'normalize_row' ), $rows ? $rows : array() );
	}

	/**
	 * Create or update a coupon.
	 *
	 * @param array $data
	 * @return int|\WP_Error
	 */
	public static function save( $data ) {
		global $wpdb;
		$id   = absint( $data['id'] ?? 0 );
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', $data['code'] ?? '' ) );
		if ( empty( $code ) ) {
			return new \WP_Error( 'invalid_coupon', __( 'Coupon code is required.', 'bizgrowth360-app-builder' ) );
		}

		$type   = in_array( $data['discount_type'] ?? '', array( 'percent', 'fixed' ), true ) ? $data['discount_type'] : 'percent';
		$amount = round( max( 0, (float) ( $data['amount'] ?? 0 ) ), 2 );
		if ( 'percent' === $type && $amount > 100 ) {
			return new \WP_Error( 'invalid_coupon', __( 'Percentage discount cannot exceed 100%.', 'bizgrowth360-app-builder' ) );
		}

		$plans = array();
		if ( ! empty( $data['applicable_plans'] ) ) {
			$plans = is_array( $data['applicable_plans'] )
				? array_map( 'absint', $data['applicable_plans'] )
				: array_map( 'absint', explode( ',', (string) $data['applicable_plans'] ) );
			$plans = array_values( array_filter( $plans ) );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . BillingDb::table( 'coupons' ) . ' WHERE code = %s AND id <> %d LIMIT 1', $code, $id )
		);
		if ( $existing ) {
			return new \WP_Error( 'duplicate_coupon', __( 'A coupon with this code already exists.', 'bizgrowth360-app-builder' ) );
		}

		$now    = BillingDb::now();
		$record = array(
			'code'             => $code,
			'description'      => sanitize_text_field( $data['description'] ?? '' ),
			'discount_type'    => $type,
			'amount'           => $amount,
			'max_discount'     => round( max( 0, (float) ( $data['max_discount'] ?? 0 ) ), 2 ),
			'applicable_plans' => wp_json_encode( $plans ),
			'starts_at'        => ! empty( $data['starts_at'] ) ? sanitize_text_field( $data['starts_at'] ) : null,
			'expires_at'       => ! empty( $data['expires_at'] ) ? sanitize_text_field( $data['expires_at'] ) : null,
			'usage_limit'      => max( 0, absint( $data['usage_limit'] ?? 0 ) ),
			'per_customer_limit' => max( 1, absint( $data['per_customer_limit'] ?? 1 ) ),
			'first_time_only'  => ! empty( $data['first_time_only'] ) ? 1 : 0,
			'active'           => ! empty( $data['active'] ) ? 1 : 0,
			'updated_at'       => $now,
		);

		if ( $id ) {
			$wpdb->update( BillingDb::table( 'coupons' ), $record, array( 'id' => $id ) );
			BillingDb::audit( 'coupon_updated', array( 'object_type' => 'coupon', 'object_id' => $id, 'details' => $record ) );
			return $id;
		}

		$record['created_at'] = $now;
		$wpdb->insert( BillingDb::table( 'coupons' ), $record );
		$new_id = (int) $wpdb->insert_id;
		if ( $new_id ) {
			BillingDb::audit( 'coupon_created', array( 'object_type' => 'coupon', 'object_id' => $new_id, 'details' => $record ) );
		}
		return $new_id ? $new_id : new \WP_Error( 'db_error', __( 'Could not save coupon.', 'bizgrowth360-app-builder' ) );
	}

	/**
	 * Activate or deactivate a coupon.
	 *
	 * @param int  $id
	 * @param bool $active
	 * @return bool
	 */
	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update( BillingDb::table( 'coupons' ), array( 'active' => $active ? 1 : 0, 'updated_at' => BillingDb::now() ), array( 'id' => absint( $id ) ) );
		return true;
	}

	/**
	 * Validate a coupon and calculate its discount.
	 *
	 * @param string $code
	 * @param int    $plan_id
	 * @param int    $tenant_id
	 * @param float  $amount
	 * @return array
	 */
	public static function validate( $code, $plan_id, $tenant_id, $amount ) {
		$coupon = self::get_by_code( $code );
		if ( ! $coupon ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'Coupon code not found.', 'bizgrowth360-app-builder' ) );
		}
		if ( empty( $coupon->active ) ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon is inactive.', 'bizgrowth360-app-builder' ) );
		}

		$now = current_time( 'timestamp' );
		if ( ! empty( $coupon->starts_at ) && strtotime( $coupon->starts_at ) > $now ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon is not active yet.', 'bizgrowth360-app-builder' ) );
		}
		if ( ! empty( $coupon->expires_at ) && strtotime( $coupon->expires_at ) < $now ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon has expired.', 'bizgrowth360-app-builder' ) );
		}

		$plans = json_decode( (string) $coupon->applicable_plans, true );
		if ( is_array( $plans ) && ! empty( $plans ) && ! in_array( (int) $plan_id, array_map( 'intval', $plans ), true ) ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon does not apply to the selected plan.', 'bizgrowth360-app-builder' ) );
		}

		if ( (int) $coupon->usage_limit > 0 ) {
			$used = self::usage_count( $coupon->id );
			if ( $used >= (int) $coupon->usage_limit ) {
				return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon has reached its usage limit.', 'bizgrowth360-app-builder' ) );
			}
		}

		if ( (int) $coupon->per_customer_limit > 0 ) {
			$customer_used = self::customer_usage_count( $coupon->id, $tenant_id );
			if ( $customer_used >= (int) $coupon->per_customer_limit ) {
				return array( 'valid' => false, 'discount' => 0, 'error' => __( 'You have already used this coupon.', 'bizgrowth360-app-builder' ) );
			}
		}

		if ( ! empty( $coupon->first_time_only ) && self::customer_has_paid( $tenant_id ) ) {
			return array( 'valid' => false, 'discount' => 0, 'error' => __( 'This coupon is only available to first-time customers.', 'bizgrowth360-app-builder' ) );
		}

		$discount = self::calculate_discount( $coupon, $amount );
		return array(
			'valid'    => true,
			'discount' => $discount,
			'coupon'   => $coupon,
			'error'    => '',
		);
	}

	/**
	 * Calculate discount for an amount.
	 *
	 * @param object $coupon
	 * @param float  $amount
	 * @return float
	 */
	public static function calculate_discount( $coupon, $amount ) {
		$amount = round( max( 0, (float) $amount ), 2 );
		if ( 'percent' === $coupon->discount_type ) {
			$discount = $amount * ( (float) $coupon->amount / 100 );
		} else {
			$discount = (float) $coupon->amount;
		}
		if ( (float) $coupon->max_discount > 0 ) {
			$discount = min( $discount, (float) $coupon->max_discount );
		}
		return round( min( $amount, max( 0, $discount ) ), 2 );
	}

	/**
	 * Record a redemption after a verified payment.
	 *
	 * @param object $coupon
	 * @param int    $tenant_id
	 * @param int    $subscription_id
	 * @param int    $transaction_id
	 * @param float  $discount
	 * @return bool
	 */
	public static function redeem( $coupon, $tenant_id, $subscription_id, $transaction_id, $discount ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			BillingDb::table( 'coupon_redemptions' ),
			array(
				'coupon_id'       => absint( $coupon->id ),
				'tenant_id'       => absint( $tenant_id ),
				'subscription_id' => absint( $subscription_id ),
				'transaction_id'  => absint( $transaction_id ),
				'discount_amount' => round( (float) $discount, 2 ),
				'created_at'      => BillingDb::now(),
			)
		);
		if ( $inserted ) {
			BillingDb::audit( 'coupon_redeemed', array( 'tenant_id' => $tenant_id, 'object_type' => 'coupon', 'object_id' => $coupon->id, 'details' => array( 'discount' => $discount, 'transaction_id' => $transaction_id ) ) );
		}
		return (bool) $inserted;
	}

	/**
	 * Total redemptions.
	 *
	 * @param int $coupon_id
	 * @return int
	 */
	public static function usage_count( $coupon_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . BillingDb::table( 'coupon_redemptions' ) . ' WHERE coupon_id = %d', absint( $coupon_id ) )
		);
	}

	/**
	 * Redemptions by a customer.
	 *
	 * @param int $coupon_id
	 * @param int $tenant_id
	 * @return int
	 */
	public static function customer_usage_count( $coupon_id, $tenant_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . BillingDb::table( 'coupon_redemptions' ) . ' WHERE coupon_id = %d AND tenant_id = %d',
				absint( $coupon_id ),
				absint( $tenant_id )
			)
		);
	}

	/**
	 * Whether the customer has ever paid.
	 *
	 * @param int $tenant_id
	 * @return bool
	 */
	public static function customer_has_paid( $tenant_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . BillingDb::table( 'transactions' ) . " WHERE tenant_id = %d AND type = 'payment' AND status = 'succeeded'",
				absint( $tenant_id )
			)
		);
		return $count > 0;
	}

	/**
	 * Normalize a coupon row.
	 *
	 * @param object $row
	 * @return object
	 */
	public static function normalize_row( $row ) {
		$row->id = (int) $row->id;
		$row->amount = (float) $row->amount;
		$row->max_discount = (float) $row->max_discount;
		$row->usage_limit = (int) $row->usage_limit;
		$row->per_customer_limit = (int) $row->per_customer_limit;
		$row->first_time_only = ! empty( $row->first_time_only );
		$row->active = ! empty( $row->active );
		$row->applicable_plans = json_decode( (string) $row->applicable_plans, true ) ?: array();
		return $row;
	}
}
