<?php
/**
 * Central subscription entitlement and plan enforcement service.
 *
 * Every build, app registration, native-feature save and design save must pass
 * through this service. Frontend checks are only a convenience.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entitlements {

	/**
	 * Current plan for a tenant.
	 *
	 * @param int $tenant_id
	 * @return object|null
	 */
	public static function plan( $tenant_id ) {
		return Tenant::plan( $tenant_id );
	}

	/**
	 * Current subscription for a tenant.
	 *
	 * @param int $tenant_id
	 * @return object|null
	 */
	public static function subscription( $tenant_id ) {
		return Tenant::subscription( $tenant_id );
	}

	/**
	 * Whether the tenant has access to paid features.
	 *
	 * @param int $tenant_id
	 * @return bool
	 */
	public static function has_access( $tenant_id ) {
		$subscription = self::subscription( $tenant_id );
		return $subscription && Subscriptions::status_allows_access( $subscription->status );
	}

	/**
	 * Assert active access.
	 *
	 * @param int $tenant_id
	 * @return true|\WP_Error
	 */
	public static function assert_access( $tenant_id ) {
		$workspace = Tenant::workspace( $tenant_id );
		if ( ! $workspace ) {
			return new \WP_Error( 'workspace_required', __( 'No workspace is assigned to this account.', 'bizgrowth360-app-builder' ) );
		}
		if ( 'suspended' === $workspace->status ) {
			return new \WP_Error( 'workspace_suspended', __( 'Your workspace is suspended by platform administration.', 'bizgrowth360-app-builder' ) );
		}
		$subscription = self::subscription( $tenant_id );
		if ( ! $subscription ) {
			return new \WP_Error( 'subscription_required', __( 'An active subscription is required. Choose a plan to continue.', 'bizgrowth360-app-builder' ) );
		}
		if ( ! Subscriptions::status_allows_access( $subscription->status ) ) {
			return new \WP_Error(
				'subscription_inactive',
				sprintf(
					/* translators: %s: subscription status */
					__( 'Your subscription is %s. Renew or update billing to continue.', 'bizgrowth360-app-builder' ),
					self::status_label( $subscription->status )
				)
			);
		}
		return true;
	}

	/**
	 * Whether a plan feature is enabled.
	 *
	 * @param object|null $plan
	 * @param string      $feature
	 * @return bool
	 */
	public static function plan_has( $plan, $feature ) {
		if ( ! $plan ) {
			return false;
		}
		$feature = sanitize_key( $feature );
		return ! empty( $plan->$feature );
	}

	/**
	 * Assert a plan feature.
	 *
	 * @param int    $tenant_id
	 * @param string $feature
	 * @return true|\WP_Error
	 */
	public static function assert_feature( $tenant_id, $feature ) {
		$access = self::assert_access( $tenant_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$plan = self::plan( $tenant_id );
		if ( ! self::plan_has( $plan, $feature ) ) {
			return new \WP_Error(
				'feature_not_in_plan',
				sprintf(
					/* translators: %s: feature label */
					__( 'Your current plan does not include %s. Upgrade your plan to use it.', 'bizgrowth360-app-builder' ),
					self::feature_label( $feature )
				)
			);
		}
		return true;
	}

	/**
	 * Full build entitlement check before reserving quota.
	 *
	 * @param int    $tenant_id
	 * @param string $platform android|ios
	 * @param string $action apk|aab|both|ios|all
	 * @param array  $config
	 * @return true|\WP_Error
	 */
	public static function assert_can_start_build( $tenant_id, $platform, $action, $config = array() ) {
		$tenant_id = absint( $tenant_id );
		$access    = self::assert_access( $tenant_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$plan = self::plan( $tenant_id );
		if ( ! $plan ) {
			return new \WP_Error( 'plan_missing', __( 'No plan is assigned to this workspace.', 'bizgrowth360-app-builder' ) );
		}

		$required = array();
		if ( in_array( $action, array( 'apk', 'both', 'all' ), true ) ) {
			$required[] = 'apk_access';
		}
		if ( in_array( $action, array( 'aab', 'both', 'all' ), true ) ) {
			$required[] = 'aab_access';
		}
		if ( in_array( $action, array( 'ios', 'all' ), true ) ) {
			$required[] = 'ipa_access';
		}
		if ( 'android' === $platform && 'ios' === $action ) {
			$required = array( 'ipa_access' );
		}

		foreach ( array_unique( $required ) as $feature ) {
			if ( ! self::plan_has( $plan, $feature ) ) {
				return new \WP_Error(
					'build_type_not_in_plan',
					sprintf(
						/* translators: %s: build type label */
						__( 'Your current plan does not include %s builds. Upgrade to continue.', 'bizgrowth360-app-builder' ),
						self::feature_label( $feature )
					)
				);
			}
		}

		// Storage is checked before dispatch using the latest artifact sizes.
		$storage_limit = (int) $plan->storage_mb;
		if ( $storage_limit >= 0 ) {
			$used = Usage::storage_used_mb( $tenant_id );
			if ( $used >= $storage_limit ) {
				return new \WP_Error(
					'storage_quota_exhausted',
					sprintf(
						/* translators: 1: used MB, 2: plan limit MB */
						__( 'Storage limit reached (%1$s MB of %2$s MB). Upgrade your plan or remove old artifacts.', 'bizgrowth360-app-builder' ),
						number_format_i18n( $used, 2 ),
						number_format_i18n( $storage_limit )
					)
				);
			}
		}

		$concurrent_limit = (int) $plan->max_concurrent_builds;
		if ( $concurrent_limit >= 0 && Usage::active_builds( $tenant_id ) >= $concurrent_limit ) {
			return new \WP_Error(
				'concurrent_build_limit',
				sprintf(
					/* translators: %d: concurrent build limit */
					__( 'Concurrent build limit reached (%d active). Wait for a build to finish or upgrade your plan.', 'bizgrowth360-app-builder' ),
					$concurrent_limit
				)
			);
		}

		$monthly_limit = (int) $plan->monthly_builds;
		if ( $monthly_limit >= 0 ) {
			$period = Usage::period_for( $tenant_id );
			$used   = Usage::count( $tenant_id, 'build_started', $period );
			if ( $used >= $monthly_limit ) {
				return new \WP_Error(
					'build_quota_exhausted',
					sprintf(
						/* translators: 1: used builds, 2: plan limit */
						__( 'Monthly build limit reached (%1$d of %2$d). Upgrade your plan or wait for the next billing period.', 'bizgrowth360-app-builder' ),
						$used,
						$monthly_limit
					)
				);
			}
		}

		$branding = self::assert_branding_allowed( $tenant_id, $config );
		if ( is_wp_error( $branding ) ) {
			return $branding;
		}

		return true;
	}

	/**
	 * Register/validate an app against the plan's application limit.
	 *
	 * @param int    $tenant_id
	 * @param string $app_name
	 * @param string $package_id
	 * @param string $website_url
	 * @return int|\WP_Error Application ID or error.
	 */
	public static function assert_can_use_app( $tenant_id, $app_name, $package_id, $website_url = '' ) {
		$access = self::assert_access( $tenant_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		return Apps::ensure( $tenant_id, $app_name, $package_id, $website_url );
	}

	/**
	 * Validate native feature configuration against the plan.
	 *
	 * @param int   $tenant_id
	 * @param array $features
	 * @return true|\WP_Error
	 */
	public static function assert_native_features( $tenant_id, $features ) {
		$access = self::assert_access( $tenant_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$plan = self::plan( $tenant_id );
		if ( ! is_array( $features ) ) {
			return true;
		}

		$requires_native = array( 'deep_linking', 'native_share', 'camera_upload', 'offline_engine', 'permission_manager', 'app_updates' );
		foreach ( $requires_native as $key ) {
			if ( ! empty( $features[ $key ]['enabled'] ) && ! self::plan_has( $plan, 'native_features' ) ) {
				return new \WP_Error( 'native_features_not_in_plan', __( 'Native features are not included in your plan. Upgrade to enable them.', 'bizgrowth360-app-builder' ) );
			}
			if ( 'camera_upload' === $key && ( ! empty( $features[ $key ]['camera_enabled'] ) || ! empty( $features[ $key ]['gallery_enabled'] ) ) && ! self::plan_has( $plan, 'native_features' ) ) {
				return new \WP_Error( 'native_features_not_in_plan', __( 'Camera and file upload features are not included in your plan.', 'bizgrowth360-app-builder' ) );
			}
		}

		if ( ! empty( $features['push_notifications']['enabled'] ) && ! self::plan_has( $plan, 'push_notifications' ) ) {
			return new \WP_Error( 'push_not_in_plan', __( 'Push notifications are not included in your plan. Upgrade to enable them.', 'bizgrowth360-app-builder' ) );
		}

		return true;
	}

	/**
	 * Validate Design Studio usage against the plan.
	 *
	 * @param int   $tenant_id
	 * @param array $design
	 * @return true|\WP_Error
	 */
	public static function assert_design( $tenant_id, $design ) {
		$access = self::assert_access( $tenant_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$plan = self::plan( $tenant_id );
		if ( self::plan_has( $plan, 'design_studio' ) || ! is_array( $design ) ) {
			return true;
		}

		if ( self::design_is_customized( $design ) ) {
			return new \WP_Error( 'design_studio_not_in_plan', __( 'Design Studio customizations are not included in your plan. Upgrade to use them.', 'bizgrowth360-app-builder' ) );
		}
		return true;
	}

	/**
	 * Detect meaningful Design Studio customization.
	 *
	 * @param array $design
	 * @return bool
	 */
	public static function design_is_customized( $design ) {
		$checks = array(
			array( 'custom_html', 'enabled' ),
			array( 'navigation', 'enabled' ),
			array( 'menu', 'enabled' ),
			array( 'homepage', 'enabled' ),
		);
		foreach ( $checks as $path ) {
			if ( ! empty( $design[ $path[0] ][ $path[1] ] ) ) {
				return true;
			}
		}
		if ( ! empty( $design['typography']['font'] ) && 'system' !== $design['typography']['font'] ) {
			return true;
		}
		if ( ! empty( $design['theme']['mode'] ) && 'light' !== $design['theme']['mode'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Custom branding entitlement (custom icon/splash).
	 *
	 * @param int   $tenant_id
	 * @param array $config
	 * @return true|\WP_Error
	 */
	public static function assert_branding_allowed( $tenant_id, $config ) {
		$plan = self::plan( $tenant_id );
		if ( ! $plan || self::plan_has( $plan, 'custom_branding' ) ) {
			return true;
		}
		if ( ! empty( $config['icon_url'] ) || ! empty( $config['splash_url'] ) ) {
			return new \WP_Error( 'custom_branding_not_in_plan', __( 'Custom icons and splash screens are not included in your plan. Upgrade to use them.', 'bizgrowth360-app-builder' ) );
		}
		return true;
	}

	/**
	 * Assert a generic plan feature, used by admin/AJAX endpoints.
	 *
	 * @param int    $tenant_id
	 * @param string $feature
	 * @return true|\WP_Error
	 */
	public static function assert_plan_feature( $tenant_id, $feature ) {
		return self::assert_feature( $tenant_id, $feature );
	}

	/**
	 * Human-readable status label.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'trial'     => __( 'Trial', 'bizgrowth360-app-builder' ),
			'active'    => __( 'Active', 'bizgrowth360-app-builder' ),
			'pending'   => __( 'Pending', 'bizgrowth360-app-builder' ),
			'past_due'  => __( 'Past Due', 'bizgrowth360-app-builder' ),
			'grace'     => __( 'Grace Period', 'bizgrowth360-app-builder' ),
			'suspended' => __( 'Suspended', 'bizgrowth360-app-builder' ),
			'cancelled' => __( 'Cancelled', 'bizgrowth360-app-builder' ),
			'expired'   => __( 'Expired', 'bizgrowth360-app-builder' ),
		);
		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', (string) $status ) );
	}

	/**
	 * Human-readable feature label.
	 *
	 * @param string $feature
	 * @return string
	 */
	public static function feature_label( $feature ) {
		$labels = array(
			'apk_access'         => __( 'Android APK', 'bizgrowth360-app-builder' ),
			'aab_access'         => __( 'Android AAB', 'bizgrowth360-app-builder' ),
			'ipa_access'         => __( 'iOS IPA', 'bizgrowth360-app-builder' ),
			'native_features'    => __( 'Native features', 'bizgrowth360-app-builder' ),
			'push_notifications' => __( 'Push notifications', 'bizgrowth360-app-builder' ),
			'design_studio'      => __( 'Design Studio', 'bizgrowth360-app-builder' ),
			'custom_branding'    => __( 'Custom branding', 'bizgrowth360-app-builder' ),
			'priority_build'     => __( 'Priority builds', 'bizgrowth360-app-builder' ),
		);
		return $labels[ $feature ] ?? ucfirst( str_replace( '_', ' ', (string) $feature ) );
	}

	/**
	 * Upgrade URL for a tenant.
	 *
	 * @param int $tenant_id
	 * @return string
	 */
	public static function upgrade_url( $tenant_id = 0 ) {
		$page_id = (int) get_option( 'bizgrowth360_pricing_page_id', 0 );
		if ( $page_id ) {
			return get_permalink( $page_id );
		}
		return home_url( '/?bizgrowth360_pricing=1' );
	}
}
