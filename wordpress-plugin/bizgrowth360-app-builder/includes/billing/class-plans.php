<?php
/**
 * Plan repository and plan configuration rules.
 *
 * All prices and limits live in the database. Business logic must read them
 * through this repository rather than hardcoding plan values.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plans {

	const STATUSES     = array( 'active', 'inactive', 'archived' );
	const VISIBILITIES = array( 'public', 'hidden', 'archived' );
	const INTERVALS    = array( 'monthly', 'annual' );

	/**
	 * Get a plan by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'plans' ) . ' WHERE id = %d LIMIT 1', absint( $id ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Get a plan by slug.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'plans' ) . ' WHERE slug = %s LIMIT 1', sanitize_title( $slug ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Query plans.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'status'     => '',
			'visibility' => '',
			'public'     => false,
			'orderby'    => 'sort_order',
			'order'      => 'ASC',
		);
		$r = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $r['status'] ) && 'all' !== $r['status'] ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $r['status'] );
		}
		if ( ! empty( $r['visibility'] ) && 'all' !== $r['visibility'] ) {
			$where[]  = 'visibility = %s';
			$values[] = sanitize_key( $r['visibility'] );
		}
		if ( ! empty( $r['public'] ) ) {
			$where[] = "status = 'active' AND visibility = 'public'";
		}

		$orderby = in_array( $r['orderby'], array( 'sort_order', 'monthly_price', 'annual_price', 'name', 'id' ), true ) ? $r['orderby'] : 'sort_order';
		$order   = 'DESC' === strtoupper( $r['order'] ) ? 'DESC' : 'ASC';

		$sql = 'SELECT * FROM ' . BillingDb::table( 'plans' ) . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}, id ASC";
		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		$rows = $wpdb->get_results( $sql );
		return array_map( array( __CLASS__, 'normalize_row' ), $rows ? $rows : array() );
	}

	/**
	 * Public, active plans for pricing pages.
	 *
	 * @return array
	 */
	public static function public_plans() {
		return self::all( array( 'public' => true ) );
	}

	/**
	 * Create or update a plan.
	 *
	 * @param array $data
	 * @return int|\WP_Error
	 */
	public static function save( $data ) {
		global $wpdb;

		$id     = absint( $data['id'] ?? 0 );
		$clean  = self::sanitize( $data );
		$errors = array();

		if ( empty( $clean['name'] ) ) {
			$errors[] = __( 'Plan name is required.', 'bizgrowth360-app-builder' );
		}
		if ( empty( $clean['slug'] ) ) {
			$clean['slug'] = sanitize_title( $clean['name'] );
		}
		if ( empty( $clean['slug'] ) ) {
			$errors[] = __( 'Plan slug is required.', 'bizgrowth360-app-builder' );
		}
		if ( ! in_array( $clean['status'], self::STATUSES, true ) ) {
			$errors[] = __( 'Invalid plan status.', 'bizgrowth360-app-builder' );
		}
		if ( ! in_array( $clean['visibility'], self::VISIBILITIES, true ) ) {
			$errors[] = __( 'Invalid plan visibility.', 'bizgrowth360-app-builder' );
		}
		if ( $clean['monthly_price'] < 0 || $clean['annual_price'] < 0 ) {
			$errors[] = __( 'Plan prices cannot be negative.', 'bizgrowth360-app-builder' );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . BillingDb::table( 'plans' ) . ' WHERE slug = %s AND id <> %d LIMIT 1',
				$clean['slug'],
				$id
			)
		);
		if ( $existing ) {
			$errors[] = __( 'Another plan already uses this slug.', 'bizgrowth360-app-builder' );
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'invalid_plan', implode( ' ', $errors ) );
		}

		$now = BillingDb::now();
		$clean['updated_at'] = $now;

		// Keep the SaaS plan columns in sync so the workspace portal and the
		// billing engine always see the same limits and features.
		$clean['price']                = $clean['monthly_price'];
		$clean['max_builds_per_month'] = $clean['monthly_builds'];
		$clean['is_active']            = 'active' === $clean['status'] ? 1 : 0;
		$clean['features_allowed']     = wp_json_encode( self::feature_slugs( $clean ) );

		if ( $id ) {
			$wpdb->update( BillingDb::table( 'plans' ), $clean, array( 'id' => $id ) );
			BillingDb::audit( 'plan_updated', array( 'object_type' => 'plan', 'object_id' => $id, 'details' => $clean ) );
			return $id;
		}

		$clean['created_at'] = $now;
		$wpdb->insert( BillingDb::table( 'plans' ), $clean );
		$new_id = (int) $wpdb->insert_id;
		if ( $new_id ) {
			BillingDb::audit( 'plan_created', array( 'object_type' => 'plan', 'object_id' => $new_id, 'details' => $clean ) );
		}
		return $new_id ? $new_id : new \WP_Error( 'db_error', __( 'Could not save plan.', 'bizgrowth360-app-builder' ) );
	}

	/**
	 * Activate, deactivate or archive a plan.
	 *
	 * @param int    $id
	 * @param string $status
	 * @return bool|\WP_Error
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid plan status.', 'bizgrowth360-app-builder' ) );
		}
		$data = array(
			'status'     => $status,
			'is_active'  => 'active' === $status ? 1 : 0,
			'updated_at' => BillingDb::now(),
		);
		if ( 'archived' === $status ) {
			$data['visibility'] = 'archived';
		}
		$wpdb->update( BillingDb::table( 'plans' ), $data, array( 'id' => absint( $id ) ) );
		BillingDb::audit( 'plan_status', array( 'object_type' => 'plan', 'object_id' => $id, 'details' => array( 'status' => $status ) ) );
		return true;
	}

	/**
	 * Normalize and validate plan input.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function sanitize( $input ) {
		$interval = sanitize_key( $input['billing_interval'] ?? 'monthly' );
		return array(
			'name'                  => sanitize_text_field( $input['name'] ?? '' ),
			'slug'                  => sanitize_title( $input['slug'] ?? '' ),
			'description'           => sanitize_textarea_field( $input['description'] ?? '' ),
			'monthly_price'         => self::money( $input['monthly_price'] ?? 0 ),
			'annual_price'          => self::money( $input['annual_price'] ?? 0 ),
			'currency'              => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $input['currency'] ?? 'INR' ), 0, 3 ) ) ?: 'INR',
			'billing_interval'      => in_array( $interval, self::INTERVALS, true ) ? $interval : 'monthly',
			'trial_days'            => max( 0, absint( $input['trial_days'] ?? 0 ) ),
			'max_apps'              => self::limit( $input['max_apps'] ?? 1 ),
			'monthly_builds'        => self::limit( $input['monthly_builds'] ?? 0 ),
			'max_concurrent_builds' => max( 1, absint( $input['max_concurrent_builds'] ?? 1 ) ),
			'storage_mb'            => self::limit( $input['storage_mb'] ?? 0 ),
			'apk_access'            => ! empty( $input['apk_access'] ) ? 1 : 0,
			'aab_access'            => ! empty( $input['aab_access'] ) ? 1 : 0,
			'ipa_access'            => ! empty( $input['ipa_access'] ) ? 1 : 0,
			'native_features'       => ! empty( $input['native_features'] ) ? 1 : 0,
			'push_notifications'    => ! empty( $input['push_notifications'] ) ? 1 : 0,
			'design_studio'         => ! empty( $input['design_studio'] ) ? 1 : 0,
			'custom_branding'       => ! empty( $input['custom_branding'] ) ? 1 : 0,
			'priority_build'        => ! empty( $input['priority_build'] ) ? 1 : 0,
			'support_level'         => sanitize_text_field( $input['support_level'] ?? 'standard' ),
			'visibility'            => in_array( $input['visibility'] ?? 'public', self::VISIBILITIES, true ) ? $input['visibility'] : 'public',
			'sort_order'            => (int) ( $input['sort_order'] ?? 0 ),
			'status'                => in_array( $input['status'] ?? 'active', self::STATUSES, true ) ? $input['status'] : 'active',
		);
	}

	/**
	 * Price for a selected interval.
	 *
	 * @param object|array $plan
	 * @param string       $interval
	 * @return float
	 */
	public static function price_for_interval( $plan, $interval ) {
		$interval = 'annual' === sanitize_key( $interval ) ? 'annual' : 'monthly';
		$price    = 'annual' === $interval ? ( $plan->annual_price ?? 0 ) : ( $plan->monthly_price ?? 0 );
		return self::money( $price );
	}

	/**
	 * Normalize a DB row into typed values.
	 *
	 * @param object $row
	 * @return object
	 */
	public static function normalize_row( $row ) {
		$bool_fields = array( 'apk_access', 'aab_access', 'ipa_access', 'native_features', 'push_notifications', 'design_studio', 'custom_branding', 'priority_build' );
		foreach ( $bool_fields as $field ) {
			$row->$field = ! empty( $row->$field );
		}
		foreach ( array( 'id', 'trial_days', 'max_apps', 'monthly_builds', 'max_concurrent_builds', 'storage_mb', 'sort_order' ) as $field ) {
			$row->$field = (int) $row->$field;
		}
		$row->monthly_price = self::money( $row->monthly_price );
		$row->annual_price  = self::money( $row->annual_price );
		return $row;
	}

	/**
	 * Convert a money value to a 2-decimal float.
	 *
	 * @param mixed $value
	 * @return float
	 */
	public static function money( $value ) {
		return round( max( 0, (float) $value ), 2 );
	}

	/**
	 * Normalize a limit. -1 means unlimited.
	 *
	 * @param mixed $value
	 * @return int
	 */
	public static function limit( $value ) {
		$value = (int) $value;
		return -1 === $value ? -1 : max( 0, $value );
	}

	/**
	 * Seed starter plans when the SaaS table is empty, and enrich the SaaS
	 * default plans with billing fields when they already exist.
	 */
	public static function seed_defaults() {
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BillingDb::table( 'plans' ) );
		if ( $count > 0 ) {
			self::enrich_existing_plans();
			return;
		}

		$defaults = array(
			array(
				'name'                  => 'Free',
				'slug'                  => 'free',
				'description'           => 'Try the builder with one app and two APK builds per month.',
				'monthly_price'         => 0,
				'annual_price'          => 0,
				'currency'              => 'INR',
				'billing_interval'      => 'monthly',
				'trial_days'            => 0,
				'max_apps'              => 1,
				'monthly_builds'        => 2,
				'max_concurrent_builds' => 1,
				'storage_mb'            => 100,
				'apk_access'            => 1,
				'aab_access'            => 0,
				'ipa_access'            => 0,
				'native_features'       => 1,
				'push_notifications'    => 0,
				'design_studio'         => 0,
				'custom_branding'       => 0,
				'priority_build'        => 0,
				'support_level'         => 'community',
				'visibility'            => 'public',
				'sort_order'            => 10,
				'status'                => 'active',
			),
			array(
				'name'                  => 'Starter',
				'slug'                  => 'starter',
				'description'           => 'For small businesses shipping Android APK and Play Store AAB builds.',
				'monthly_price'         => 999,
				'annual_price'          => 9990,
				'currency'              => 'INR',
				'billing_interval'      => 'monthly',
				'trial_days'            => 7,
				'max_apps'              => 3,
				'monthly_builds'        => 20,
				'max_concurrent_builds' => 2,
				'storage_mb'            => 1024,
				'apk_access'            => 1,
				'aab_access'            => 1,
				'ipa_access'            => 0,
				'native_features'       => 1,
				'push_notifications'    => 0,
				'design_studio'         => 1,
				'custom_branding'       => 1,
				'priority_build'        => 0,
				'support_level'         => 'email',
				'visibility'            => 'public',
				'sort_order'            => 20,
				'status'                => 'active',
			),
			array(
				'name'                  => 'Professional',
				'slug'                  => 'professional',
				'description'           => 'Android and iOS delivery with native features, push notifications and priority builds.',
				'monthly_price'         => 2999,
				'annual_price'          => 29990,
				'currency'              => 'INR',
				'billing_interval'      => 'monthly',
				'trial_days'            => 14,
				'max_apps'              => 10,
				'monthly_builds'        => 100,
				'max_concurrent_builds' => 3,
				'storage_mb'            => 5120,
				'apk_access'            => 1,
				'aab_access'            => 1,
				'ipa_access'            => 1,
				'native_features'       => 1,
				'push_notifications'    => 1,
				'design_studio'         => 1,
				'custom_branding'       => 1,
				'priority_build'        => 1,
				'support_level'         => 'priority',
				'visibility'            => 'public',
				'sort_order'            => 30,
				'status'                => 'active',
			),
			array(
				'name'                  => 'Legacy Owner',
				'slug'                  => 'legacy-owner',
				'description'           => 'Grandfathered access for the existing site owner. Not publicly selectable.',
				'monthly_price'         => 0,
				'annual_price'          => 0,
				'currency'              => 'INR',
				'billing_interval'      => 'monthly',
				'trial_days'            => 0,
				'max_apps'              => -1,
				'monthly_builds'        => -1,
				'max_concurrent_builds' => 5,
				'storage_mb'            => -1,
				'apk_access'            => 1,
				'aab_access'            => 1,
				'ipa_access'            => 1,
				'native_features'       => 1,
				'push_notifications'    => 1,
				'design_studio'         => 1,
				'custom_branding'       => 1,
				'priority_build'        => 1,
				'support_level'         => 'priority',
				'visibility'            => 'hidden',
				'sort_order'            => 100,
				'status'                => 'active',
			),
		);

		foreach ( $defaults as $plan ) {
			self::save( $plan );
		}
	}

	/**
	 * Map plan flags to SaaS feature slugs.
	 *
	 * @param array $plan
	 * @return array
	 */
	public static function feature_slugs( $plan ) {
		$features = array();
		if ( ! empty( $plan['native_features'] ) ) {
			$features = array_merge( $features, array( 'native_share', 'camera_upload', 'offline_engine', 'permission_manager', 'app_updates', 'deep_linking' ) );
		}
		if ( ! empty( $plan['push_notifications'] ) ) {
			$features[] = 'push_notifications';
		}
		if ( ! empty( $plan['design_studio'] ) ) {
			$features[] = 'design_studio';
		}
		if ( ! empty( $plan['custom_branding'] ) ) {
			$features[] = 'white_label';
		}
		if ( ! empty( $plan['priority_build'] ) ) {
			$features[] = 'priority_builds';
		}
		if ( ! empty( $plan['ipa_access'] ) ) {
			$features[] = 'ios_ipa';
		}
		if ( ! empty( $plan['aab_access'] ) ) {
			$features[] = 'android_aab';
		}
		return array_values( array_unique( $features ) );
	}

	/**
	 * Enrich plans seeded by the SaaS foundation.
	 */
	public static function enrich_existing_plans() {
		global $wpdb;
		$table = BillingDb::table( 'plans' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}" );
		foreach ( $rows ? $rows : array() as $row ) {
			if ( ! empty( $row->updated_at ) ) {
				continue;
			}
			$slug = $row->slug;
			$data = array(
				'currency'              => 'USD' === strtolower( (string) ( $row->currency ?? '' ) ) ? 'USD' : ( in_array( $slug, array( 'pro', 'enterprise' ), true ) ? 'USD' : 'INR' ),
				'trial_days'            => 'starter' === $slug ? 7 : 14,
				'max_concurrent_builds' => 'enterprise' === $slug ? 5 : 2,
				'storage_mb'            => 'starter' === $slug ? 512 : ( 'pro' === $slug ? 5120 : 20480 ),
				'apk_access'            => 1,
				'aab_access'            => 1,
				'ipa_access'            => 'starter' === $slug ? 0 : 1,
				'native_features'       => 1,
				'push_notifications'    => 'starter' === $slug ? 0 : 1,
				'design_studio'         => 1,
				'custom_branding'       => 'starter' === $slug ? 0 : 1,
				'priority_build'        => 'enterprise' === $slug ? 1 : 0,
				'support_level'         => 'enterprise' === $slug ? 'priority' : 'email',
				'visibility'            => 'public',
				'sort_order'            => 'starter' === $slug ? 10 : ( 'pro' === $slug ? 20 : 30 ),
				'status'                => 'active',
				'updated_at'            => BillingDb::now(),
			);
			$data['monthly_price'] = self::money( $row->price );
			$data['annual_price']  = self::money( $row->price * 10 );
			$data['monthly_builds'] = (int) $row->max_builds_per_month;
			$data['price']          = $data['monthly_price'];
			$data['max_builds_per_month'] = $data['monthly_builds'];
			$data['is_active']      = 1;
			$data['features_allowed'] = wp_json_encode( self::feature_slugs( $data ) );
			$wpdb->update( $table, $data, array( 'id' => $row->id ) );
		}
	}

	/**
	 * Human-readable feature list for a plan.
	 *
	 * @param object $plan
	 * @return array
	 */
	public static function feature_list( $plan ) {
		$features = array(
			(int) $plan->max_apps < 0
				? __( 'Unlimited applications', 'bizgrowth360-app-builder' )
				: sprintf( _n( '%d application', '%d applications', (int) $plan->max_apps, 'bizgrowth360-app-builder' ), (int) $plan->max_apps ),
			(int) $plan->monthly_builds < 0
				? __( 'Unlimited monthly builds', 'bizgrowth360-app-builder' )
				: sprintf( _n( '%d build / month', '%d builds / month', (int) $plan->monthly_builds, 'bizgrowth360-app-builder' ), (int) $plan->monthly_builds ),
			sprintf( __( '%d concurrent build(s)', 'bizgrowth360-app-builder' ), (int) $plan->max_concurrent_builds ),
			(int) $plan->storage_mb < 0
				? __( 'Unlimited storage', 'bizgrowth360-app-builder' )
				: sprintf( __( '%s MB storage', 'bizgrowth360-app-builder' ), number_format_i18n( (int) $plan->storage_mb ) ),
		);

		if ( $plan->apk_access ) {
			$features[] = __( 'Android APK', 'bizgrowth360-app-builder' );
		}
		if ( $plan->aab_access ) {
			$features[] = __( 'Android AAB', 'bizgrowth360-app-builder' );
		}
		if ( $plan->ipa_access ) {
			$features[] = __( 'iOS IPA', 'bizgrowth360-app-builder' );
		}
		if ( $plan->native_features ) {
			$features[] = __( 'Native features', 'bizgrowth360-app-builder' );
		}
		if ( $plan->push_notifications ) {
			$features[] = __( 'Push notifications', 'bizgrowth360-app-builder' );
		}
		if ( $plan->design_studio ) {
			$features[] = __( 'Design Studio', 'bizgrowth360-app-builder' );
		}
		if ( $plan->custom_branding ) {
			$features[] = __( 'Custom branding', 'bizgrowth360-app-builder' );
		}
		if ( $plan->priority_build ) {
			$features[] = __( 'Priority builds', 'bizgrowth360-app-builder' );
		}

		return $features;
	}

	/**
	 * Suggested next plan above the current sort order.
	 *
	 * @param object|null $current
	 * @return object|null
	 */
	public static function recommended_upgrade( $current ) {
		$plans = self::public_plans();
		$found = false;
		foreach ( $plans as $plan ) {
			if ( $found ) {
				return $plan;
			}
			if ( $current && (int) $plan->id === (int) $current->id ) {
				$found = true;
			}
		}
		return null;
	}
}
