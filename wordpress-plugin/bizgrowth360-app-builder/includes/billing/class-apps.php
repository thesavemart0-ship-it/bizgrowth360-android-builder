<?php
/**
 * Application registry adapter over the SaaS bg360_apps table.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

use Bizgrowth360\AppBuilder\SaasDb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Apps {

	/**
	 * Count active applications for a workspace.
	 *
	 * @param int $workspace_id
	 * @return int
	 */
	public static function count( $workspace_id ) {
		return SaasDb::count_apps_by_workspace( absint( $workspace_id ) );
	}

	/**
	 * List applications for a workspace.
	 *
	 * @param int $workspace_id
	 * @return array
	 */
	public static function all( $workspace_id ) {
		return SaasDb::get_apps_by_workspace( absint( $workspace_id ) );
	}

	/**
	 * Ensure a package ID is registered and allowed by the plan.
	 *
	 * @param int    $workspace_id
	 * @param string $app_name
	 * @param string $package_id
	 * @param string $website_url
	 * @return int|\WP_Error Application ID or error.
	 */
	public static function ensure( $workspace_id, $app_name, $package_id, $website_url = '' ) {
		$workspace_id = absint( $workspace_id );
		$package_id   = sanitize_text_field( $package_id );
		if ( ! $workspace_id || empty( $package_id ) ) {
			return new \WP_Error( 'invalid_app', __( 'A workspace and package ID are required.', 'bizgrowth360-app-builder' ) );
		}

		$apps = SaasDb::get_apps_by_workspace( $workspace_id );
		foreach ( $apps ? $apps : array() as $app ) {
			if ( $app->package_id === $package_id ) {
				SaasDb::update_app(
					$app->id,
					$workspace_id,
					array(
						'app_name'    => $app_name,
						'website_url' => $website_url,
					)
				);
				return (int) $app->id;
			}
		}

		$plan  = Tenant::plan( $workspace_id );
		$limit = $plan ? (int) $plan->max_apps : 0;
		$count = self::count( $workspace_id );
		if ( $limit >= 0 && $count >= $limit ) {
			BillingDb::audit( 'app_limit_reached', array( 'tenant_id' => $workspace_id, 'object_type' => 'app', 'details' => array( 'count' => $count, 'limit' => $limit ) ) );
			return new \WP_Error(
				'app_limit_reached',
				sprintf(
					/* translators: 1: current apps, 2: plan limit */
					__( 'Application limit reached (%1$d of %2$d). Upgrade your plan to add another application.', 'bizgrowth360-app-builder' ),
					$count,
					$limit
				)
			);
		}

		$app_id = SaasDb::create_app(
			$workspace_id,
			array(
				'app_name'    => $app_name,
				'package_id'  => $package_id,
				'website_url' => $website_url,
			)
		);
		if ( ! $app_id ) {
			return new \WP_Error( 'app_error', __( 'Could not register application.', 'bizgrowth360-app-builder' ) );
		}

		Usage::record( $workspace_id, 'app_created', 1, 'app_created:' . $workspace_id . ':' . $package_id, array( 'meta' => array( 'package_id' => $package_id, 'app_id' => $app_id ) ) );
		BillingDb::audit( 'app_created', array( 'tenant_id' => $workspace_id, 'object_type' => 'app', 'object_id' => $app_id, 'details' => array( 'package_id' => $package_id ) ) );
		return (int) $app_id;
	}
}
