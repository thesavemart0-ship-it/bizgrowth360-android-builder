<?php
/**
 * Tenant Isolation & Security Service.
 *
 * Enforces server-side tenant boundaries, IDOR prevention, and plan limits.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TenantManager {

	/**
	 * Check if current user is Platform Super Administrator.
	 *
	 * @return bool
	 */
	public static function is_super_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get current active workspace for the logged in user.
	 *
	 * @return object|null Workspace record or null if unauthenticated.
	 */
	public static function get_current_workspace() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user_id = get_current_user_id();

		// Check if user has an assigned workspace
		$workspace = SaasDb::get_workspace_by_user( $user_id );
		if ( $workspace ) {
			return $workspace;
		}

		// If user is super admin, return platform default workspace #1
		if ( self::is_super_admin() ) {
			return SaasDb::get_workspace( 1 );
		}

		return null;
	}

	/**
	 * Validate application ownership and permissions (IDOR Prevention).
	 *
	 * @param int $app_id
	 * @return object|\WP_Error Application object if valid, WP_Error with 403 if unauthorized.
	 */
	public static function validate_app_access( $app_id ) {
		$app_id = absint( $app_id );
		if ( ! $app_id ) {
			return new \WP_Error( 'invalid_app_id', __( 'Invalid application ID.', 'bizgrowth360-app-builder' ), array( 'status' => 400 ) );
		}

		$app = SaasDb::get_app( $app_id );
		if ( ! $app || 'deleted' === $app->status ) {
			return new \WP_Error( 'app_not_found', __( 'Application not found.', 'bizgrowth360-app-builder' ), array( 'status' => 404 ) );
		}

		// Super Admins can access all tenant applications
		if ( self::is_super_admin() ) {
			return $app;
		}

		$workspace = self::get_current_workspace();
		if ( ! $workspace ) {
			return new \WP_Error( 'unauthenticated', __( 'User authentication required.', 'bizgrowth360-app-builder' ), array( 'status' => 401 ) );
		}

		// Strict tenant boundary check
		if ( (int) $app->workspace_id !== (int) $workspace->id ) {
			error_log( sprintf( '[Bizgrowth360 Security Alert] Cross-tenant IDOR attempt detected! User #%d (Workspace #%d) attempted to access App #%d (owned by Workspace #%d).', get_current_user_id(), $workspace->id, $app->id, $app->workspace_id ) );
			return new \WP_Error( 'forbidden_tenant_access', __( 'Access denied: You do not have permission to access this application.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		if ( 'suspended' === $workspace->status ) {
			return new \WP_Error( 'workspace_suspended', __( 'This workspace is currently suspended by administration.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		return $app;
	}

	/**
	 * Validate build record ownership and permissions (IDOR Prevention).
	 *
	 * @param string $build_id
	 * @return object|\WP_Error Build object if valid, WP_Error with 403 if unauthorized.
	 */
	public static function validate_build_access( $build_id ) {
		$build_id = sanitize_text_field( $build_id );
		if ( empty( $build_id ) ) {
			return new \WP_Error( 'invalid_build_id', __( 'Invalid build ID.', 'bizgrowth360-app-builder' ), array( 'status' => 400 ) );
		}

		$build = BuildDb::get_build( $build_id );
		if ( ! $build ) {
			return new \WP_Error( 'build_not_found', __( 'Build record not found.', 'bizgrowth360-app-builder' ), array( 'status' => 404 ) );
		}

		// Super Admins can inspect all tenant builds
		if ( self::is_super_admin() ) {
			return $build;
		}

		$workspace = self::get_current_workspace();
		if ( ! $workspace ) {
			return new \WP_Error( 'unauthenticated', __( 'User authentication required.', 'bizgrowth360-app-builder' ), array( 'status' => 401 ) );
		}

		// Strict tenant boundary check
		if ( (int) $build->workspace_id !== (int) $workspace->id ) {
			error_log( sprintf( '[Bizgrowth360 Security Alert] Cross-tenant IDOR attempt detected! User #%d (Workspace #%d) attempted to access Build %s (owned by Workspace #%d).', get_current_user_id(), $workspace->id, $build->build_id, $build->workspace_id ) );
			return new \WP_Error( 'forbidden_tenant_access', __( 'Access denied: You do not have permission to access this build.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		if ( 'suspended' === $workspace->status ) {
			return new \WP_Error( 'workspace_suspended', __( 'This workspace is currently suspended by administration.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		return $build;
	}

	/**
	 * Validate if workspace can create another application under its plan.
	 *
	 * @param int $workspace_id
	 * @return true|\WP_Error
	 */
	public static function can_create_app( $workspace_id ) {
		$workspace = SaasDb::get_workspace( $workspace_id );
		if ( ! $workspace ) {
			return new \WP_Error( 'invalid_workspace', __( 'Workspace not found.', 'bizgrowth360-app-builder' ), array( 'status' => 404 ) );
		}

		if ( 'suspended' === $workspace->status ) {
			return new \WP_Error( 'workspace_suspended', __( 'Your workspace is suspended.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		// Super Admins have unlimited app creation
		if ( self::is_super_admin() ) {
			return true;
		}

		$plan = SaasDb::get_plan( $workspace->plan_id );
		$max_apps = $plan ? (int) $plan->max_apps : 1;

		$current_app_count = SaasDb::count_apps_by_workspace( $workspace_id );
		if ( $current_app_count >= $max_apps ) {
			return new \WP_Error(
				'plan_limit_exceeded',
				sprintf( __( 'You have reached your plan limit of %d app(s). Please upgrade your subscription to create more applications.', 'bizgrowth360-app-builder' ), $max_apps ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate if workspace can trigger a new cloud build under its plan.
	 *
	 * @param int $workspace_id
	 * @return true|\WP_Error
	 */
	public static function can_trigger_build( $workspace_id ) {
		$workspace = SaasDb::get_workspace( $workspace_id );
		if ( ! $workspace ) {
			return new \WP_Error( 'invalid_workspace', __( 'Workspace not found.', 'bizgrowth360-app-builder' ), array( 'status' => 404 ) );
		}

		if ( 'suspended' === $workspace->status ) {
			return new \WP_Error( 'workspace_suspended', __( 'Your workspace is suspended. Builds are disabled.', 'bizgrowth360-app-builder' ), array( 'status' => 403 ) );
		}

		// Super Admins have unlimited builds
		if ( self::is_super_admin() ) {
			return true;
		}

		$plan = SaasDb::get_plan( $workspace->plan_id );
		$max_builds = $plan ? (int) $plan->max_builds_per_month : 5;

		$monthly_build_count = SaasDb::get_monthly_build_count( $workspace_id );
		if ( $monthly_build_count >= $max_builds ) {
			return new \WP_Error(
				'build_limit_exceeded',
				sprintf( __( 'You have used all %d build credits for this monthly billing cycle. Please upgrade your plan or wait for the next billing cycle.', 'bizgrowth360-app-builder' ), $max_builds ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if workspace plan permits a specific native feature.
	 *
	 * @param int    $workspace_id
	 * @param string $feature_slug
	 * @return bool
	 */
	public static function can_use_feature( $workspace_id, $feature_slug ) {
		if ( self::is_super_admin() ) {
			return true;
		}

		$workspace = SaasDb::get_workspace( $workspace_id );
		if ( ! $workspace ) {
			return false;
		}

		$plan = SaasDb::get_plan( $workspace->plan_id );
		if ( ! $plan ) {
			return false;
		}

		$allowed = json_decode( $plan->features_allowed, true );
		if ( ! is_array( $allowed ) ) {
			return true;
		}

		return in_array( $feature_slug, $allowed, true );
	}
}
