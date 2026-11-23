<?php
/**
 * SaaS Database Schema, Migrations, and Repositories.
 *
 * Implements multi-tenant tables:
 * - wp_bg360_plans
 * - wp_bg360_workspaces
 * - wp_bg360_apps
 * - wp_bg360_usage
 * Updates existing wp_bg360_builds with workspace_id and app_id.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SaasDb {

	const DB_VERSION = '1.0.0';
	const DB_VERSION_OPTION = 'bizgrowth360_saas_db_version';

	const TABLE_PLANS      = 'bg360_plans';
	const TABLE_WORKSPACES = 'bg360_workspaces';
	const TABLE_APPS       = 'bg360_apps';
	const TABLE_USAGE      = 'bg360_usage';

	public static function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Run all SaaS schema installations and migrations.
	 */
	public static function install_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$plans_table      = self::get_table_name( self::TABLE_PLANS );
		$workspaces_table = self::get_table_name( self::TABLE_WORKSPACES );
		$apps_table       = self::get_table_name( self::TABLE_APPS );
		$usage_table      = self::get_table_name( self::TABLE_USAGE );
		$builds_table     = BuildDb::get_table_name();

		// 1. Plans Table
		$sql_plans = "CREATE TABLE $plans_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			name varchar(128) NOT NULL,
			max_apps int(11) NOT NULL DEFAULT 1,
			max_builds_per_month int(11) NOT NULL DEFAULT 5,
			features_allowed longtext DEFAULT NULL,
			price decimal(10,2) NOT NULL DEFAULT 0.00,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_active (is_active)
		) $charset_collate;";
		dbDelta( $sql_plans );

		// 2. Workspaces Table
		$sql_workspaces = "CREATE TABLE $workspaces_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			business_name varchar(255) NOT NULL DEFAULT '',
			phone varchar(32) DEFAULT '',
			plan_id bigint(20) unsigned NOT NULL DEFAULT 1,
			status varchar(32) NOT NULL DEFAULT 'active',
			settings_json longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY status (status),
			KEY plan_id (plan_id),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_workspaces );

		// 3. Workspace Applications Table
		$sql_apps = "CREATE TABLE $apps_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			app_name varchar(255) NOT NULL,
			package_id varchar(255) NOT NULL,
			website_url text NOT NULL,
			version_name varchar(64) NOT NULL DEFAULT '1.0.0',
			version_code int(11) NOT NULL DEFAULT 1,
			primary_color varchar(16) NOT NULL DEFAULT '#7C3AED',
			status_bar_color varchar(16) NOT NULL DEFAULT '#6D28D9',
			navigation_bar_color varchar(16) NOT NULL DEFAULT '#FFFFFF',
			background_color varchar(16) NOT NULL DEFAULT '#FFFFFF',
			app_icon text DEFAULT NULL,
			splash_image text DEFAULT NULL,
			allow_external_links tinyint(1) NOT NULL DEFAULT 1,
			show_status_bar tinyint(1) NOT NULL DEFAULT 1,
			orientation varchar(32) NOT NULL DEFAULT 'portrait',
			config_json longtext DEFAULT NULL,
			design_json longtext DEFAULT NULL,
			features_json longtext DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY workspace_id (workspace_id),
			KEY package_id (package_id(191)),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_apps );

		// 4. SaaS Usage & Audit Log Table
		$sql_usage = "CREATE TABLE $usage_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT 0,
			app_id bigint(20) unsigned DEFAULT NULL,
			event_type varchar(64) NOT NULL,
			event_value int(11) NOT NULL DEFAULT 1,
			details_json longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY workspace_id (workspace_id),
			KEY user_id (user_id),
			KEY app_id (app_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_usage );

		// 5. Update Builds Table: ensure workspace_id and app_id columns exist
		self::migrate_builds_table();

		// 6. Seed Default Plans if empty
		self::seed_default_plans();

		// 7. Migrate Legacy Admin App & Builds to Default Workspace #1
		self::migrate_legacy_data();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure workspace_id and app_id exist on wp_bg360_builds.
	 */
	public static function migrate_builds_table() {
		global $wpdb;
		$builds_table = BuildDb::get_table_name();

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $builds_table ) ) !== $builds_table ) {
			return;
		}

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $builds_table", 0 );
		if ( ! in_array( 'workspace_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE $builds_table ADD COLUMN workspace_id bigint(20) unsigned NOT NULL DEFAULT 1 AFTER build_id" );
			$wpdb->query( "ALTER TABLE $builds_table ADD INDEX idx_workspace_id (workspace_id)" );
		}

		if ( ! in_array( 'app_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE $builds_table ADD COLUMN app_id bigint(20) unsigned DEFAULT NULL AFTER workspace_id" );
			$wpdb->query( "ALTER TABLE $builds_table ADD INDEX idx_app_id (app_id)" );
		}
	}

	/**
	 * Seed standard subscription plans.
	 */
	public static function seed_default_plans() {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_PLANS );

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count > 0 ) {
			return;
		}

		$now = current_time( 'mysql' );

		$plans = array(
			array(
				'slug'                 => 'starter',
				'name'                 => 'Starter Plan',
				'max_apps'             => 2,
				'max_builds_per_month' => 10,
				'features_allowed'     => wp_json_encode( array( 'push_notifications', 'native_share', 'offline_engine', 'camera_upload' ) ),
				'price'                => 0.00,
				'is_active'            => 1,
				'created_at'           => $now,
			),
			array(
				'slug'                 => 'pro',
				'name'                 => 'Professional Plan',
				'max_apps'             => 10,
				'max_builds_per_month' => 50,
				'features_allowed'     => wp_json_encode( array( 'push_notifications', 'deep_linking', 'native_share', 'camera_upload', 'offline_engine', 'permission_manager', 'app_updates' ) ),
				'price'                => 29.00,
				'is_active'            => 1,
				'created_at'           => $now,
			),
			array(
				'slug'                 => 'enterprise',
				'name'                 => 'Enterprise SaaS Plan',
				'max_apps'             => 50,
				'max_builds_per_month' => 300,
				'features_allowed'     => wp_json_encode( array( 'push_notifications', 'deep_linking', 'native_share', 'camera_upload', 'offline_engine', 'permission_manager', 'app_updates', 'white_label', 'priority_builds' ) ),
				'price'                => 99.00,
				'is_active'            => 1,
				'created_at'           => $now,
			),
		);

		foreach ( $plans as $plan ) {
			$wpdb->insert( $table, $plan );
		}
	}

	/**
	 * Migrate legacy single-tenant settings and builds into Workspace #1.
	 */
	public static function migrate_legacy_data() {
		global $wpdb;
		$workspaces_table = self::get_table_name( self::TABLE_WORKSPACES );
		$apps_table       = self::get_table_name( self::TABLE_APPS );
		$builds_table     = BuildDb::get_table_name();

		// Check if admin user exists (default to first administrator)
		$admin_users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		$admin_user_id = ! empty( $admin_users ) ? $admin_users[0]->ID : 1;

		$now = current_time( 'mysql' );

		// Check if admin workspace #1 exists
		$workspace = $wpdb->get_row( "SELECT * FROM $workspaces_table WHERE id = 1 OR user_id = $admin_user_id" );
		if ( ! $workspace ) {
			$wpdb->insert(
				$workspaces_table,
				array(
					'user_id'       => $admin_user_id,
					'name'          => 'Platform Super Admin Workspace',
					'business_name' => get_bloginfo( 'name' ),
					'phone'         => '',
					'plan_id'       => 3, // Enterprise
					'status'        => 'active',
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
			$workspace_id = $wpdb->insert_id;
		} else {
			$workspace_id = $workspace->id;
		}

		// Migrate existing app settings from options table if no apps exist in wp_bg360_apps
		$app_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $apps_table WHERE workspace_id = %d", $workspace_id ) );
		if ( $app_count == 0 ) {
			$settings = get_option( Settings::OPTION_KEY, array() );
			if ( ! empty( $settings['app_name'] ) ) {
				$design_config = get_option( DesignConfig::OPTION_KEY, array() );
				$native_features = get_option( NativeFeatures::OPTION_KEY, array() );

				$wpdb->insert(
					$apps_table,
					array(
						'workspace_id'         => $workspace_id,
						'app_name'             => sanitize_text_field( $settings['app_name'] ),
						'package_id'           => sanitize_text_field( $settings['package_id'] ?? 'in.bizgrowth360.app' ),
						'website_url'          => esc_url_raw( $settings['website_url'] ?? home_url() ),
						'version_name'         => sanitize_text_field( $settings['version_name'] ?? '1.0.0' ),
						'version_code'         => absint( $settings['version_code'] ?? 1 ),
						'primary_color'        => sanitize_hex_color( $settings['primary_color'] ?? '#7C3AED' ) ?: '#7C3AED',
						'status_bar_color'     => sanitize_hex_color( $settings['status_bar_color'] ?? '#6D28D9' ) ?: '#6D28D9',
						'navigation_bar_color' => sanitize_hex_color( $settings['navigation_bar_color'] ?? '#FFFFFF' ) ?: '#FFFFFF',
						'background_color'     => sanitize_hex_color( $settings['background_color'] ?? '#FFFFFF' ) ?: '#FFFFFF',
						'app_icon'             => esc_url_raw( $settings['app_icon'] ?? '' ),
						'splash_image'         => esc_url_raw( $settings['splash_image'] ?? '' ),
						'allow_external_links' => ! empty( $settings['allow_external_links'] ) ? 1 : 0,
						'show_status_bar'      => ! empty( $settings['show_status_bar'] ) ? 1 : 0,
						'orientation'          => in_array( $settings['orientation'] ?? 'portrait', array( 'portrait', 'landscape', 'sensor' ), true ) ? $settings['orientation'] : 'portrait',
						'config_json'          => wp_json_encode( $settings ),
						'design_json'          => ! empty( $design_config ) ? wp_json_encode( $design_config ) : null,
						'features_json'        => ! empty( $native_features ) ? wp_json_encode( $native_features ) : null,
						'status'               => 'active',
						'created_at'           => $now,
						'updated_at'           => $now,
					)
				);
				$app_id = $wpdb->insert_id;

				// Associate any existing builds with this migrated app
				$wpdb->query( $wpdb->prepare( "UPDATE $builds_table SET workspace_id = %d, app_id = %d WHERE workspace_id = 0 OR workspace_id = 1", $workspace_id, $app_id ) );
			}
		}

		// Ensure all legacy builds have a valid workspace_id
		$wpdb->query( $wpdb->prepare( "UPDATE $builds_table SET workspace_id = %d WHERE workspace_id = 0", $workspace_id ) );
	}

	/* ------------------------------------------------------------------------
	 * Workspaces CRUD
	 * ------------------------------------------------------------------------ */

	public static function get_workspace( $workspace_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_WORKSPACES );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $workspace_id ) );
	}

	public static function get_workspace_by_user( $user_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_WORKSPACES );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d", $user_id ) );
	}

	public static function create_workspace( $data ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_WORKSPACES );
		$now   = current_time( 'mysql' );

		$insert_data = array(
			'user_id'       => absint( $data['user_id'] ),
			'name'          => sanitize_text_field( $data['name'] ?? 'My Workspace' ),
			'business_name' => sanitize_text_field( $data['business_name'] ?? '' ),
			'phone'         => sanitize_text_field( $data['phone'] ?? '' ),
			'plan_id'       => absint( $data['plan_id'] ?? 1 ),
			'status'        => sanitize_key( $data['status'] ?? 'active' ),
			'settings_json' => isset( $data['settings_json'] ) ? ( is_string( $data['settings_json'] ) ? $data['settings_json'] : wp_json_encode( $data['settings_json'] ) ) : null,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$result = $wpdb->insert( $table, $insert_data );
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_workspace( $workspace_id, $data ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_WORKSPACES );

		$update = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['name'] ) ) $update['name'] = sanitize_text_field( $data['name'] );
		if ( isset( $data['business_name'] ) ) $update['business_name'] = sanitize_text_field( $data['business_name'] );
		if ( isset( $data['phone'] ) ) $update['phone'] = sanitize_text_field( $data['phone'] );
		if ( isset( $data['plan_id'] ) ) $update['plan_id'] = absint( $data['plan_id'] );
		if ( isset( $data['status'] ) ) $update['status'] = sanitize_key( $data['status'] );
		if ( isset( $data['settings_json'] ) ) {
			$update['settings_json'] = is_string( $data['settings_json'] ) ? $data['settings_json'] : wp_json_encode( $data['settings_json'] );
		}

		return $wpdb->update( $table, $update, array( 'id' => $workspace_id ) );
	}

	public static function get_all_workspaces( $args = array() ) {
		global $wpdb;
		$w_table = self::get_table_name( self::TABLE_WORKSPACES );
		$p_table = self::get_table_name( self::TABLE_PLANS );
		$u_table = $wpdb->users;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = isset( $args['search'] ) ? trim( $args['search'] ) : '';
		$status   = isset( $args['status'] ) ? trim( $args['status'] ) : '';

		$where = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $status ) ) {
			$where .= ' AND w.status = %s';
			$params[] = $status;
		}

		if ( ! empty( $search ) ) {
			$where .= ' AND (w.name LIKE %s OR w.business_name LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(*) FROM $w_table w LEFT JOIN $u_table u ON w.user_id = u.ID $where";
		$total = ! empty( $params ) ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql );

		$sql = "SELECT w.*, p.name as plan_name, p.slug as plan_slug, p.max_apps, p.max_builds_per_month, u.user_email, u.display_name
			FROM $w_table w
			LEFT JOIN $p_table p ON w.plan_id = p.id
			LEFT JOIN $u_table u ON w.user_id = u.ID
			$where
			ORDER BY w.id DESC
			LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$items = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array(
			'items'       => $items,
			'total'       => (int) $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/* ------------------------------------------------------------------------
	 * Applications CRUD
	 * ------------------------------------------------------------------------ */

	public static function get_app( $app_id, $workspace_id = null ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );
		if ( $workspace_id !== null ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND workspace_id = %d", $app_id, $workspace_id ) );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $app_id ) );
	}

	public static function get_apps_by_workspace( $workspace_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE workspace_id = %d AND status != 'deleted' ORDER BY id DESC", $workspace_id ) );
	}

	public static function count_apps_by_workspace( $workspace_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE workspace_id = %d AND status != 'deleted'", $workspace_id ) );
	}

	public static function create_app( $workspace_id, $data ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );
		$now   = current_time( 'mysql' );

		$insert = array(
			'workspace_id'         => absint( $workspace_id ),
			'app_name'             => sanitize_text_field( $data['app_name'] ?? 'My App' ),
			'package_id'           => sanitize_text_field( $data['package_id'] ?? 'com.example.app' ),
			'website_url'          => esc_url_raw( $data['website_url'] ?? home_url() ),
			'version_name'         => sanitize_text_field( $data['version_name'] ?? '1.0.0' ),
			'version_code'         => max( 1, absint( $data['version_code'] ?? 1 ) ),
			'primary_color'        => sanitize_hex_color( $data['primary_color'] ?? '#7C3AED' ) ?: '#7C3AED',
			'status_bar_color'     => sanitize_hex_color( $data['status_bar_color'] ?? '#6D28D9' ) ?: '#6D28D9',
			'navigation_bar_color' => sanitize_hex_color( $data['navigation_bar_color'] ?? '#FFFFFF' ) ?: '#FFFFFF',
			'background_color'     => sanitize_hex_color( $data['background_color'] ?? '#FFFFFF' ) ?: '#FFFFFF',
			'app_icon'             => esc_url_raw( $data['app_icon'] ?? '' ),
			'splash_image'         => esc_url_raw( $data['splash_image'] ?? '' ),
			'allow_external_links' => ! empty( $data['allow_external_links'] ) ? 1 : 0,
			'show_status_bar'      => ! empty( $data['show_status_bar'] ) ? 1 : 0,
			'orientation'          => in_array( $data['orientation'] ?? 'portrait', array( 'portrait', 'landscape', 'sensor' ), true ) ? ( $data['orientation'] ?? 'portrait' ) : 'portrait',
			'config_json'          => isset( $data['config_json'] ) ? ( is_string( $data['config_json'] ) ? $data['config_json'] : wp_json_encode( $data['config_json'] ) ) : null,
			'design_json'          => isset( $data['design_json'] ) ? ( is_string( $data['design_json'] ) ? $data['design_json'] : wp_json_encode( $data['design_json'] ) ) : null,
			'features_json'        => isset( $data['features_json'] ) ? ( is_string( $data['features_json'] ) ? $data['features_json'] : wp_json_encode( $data['features_json'] ) ) : null,
			'status'               => 'active',
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		$result = $wpdb->insert( $table, $insert );
		if ( $result ) {
			$app_id = $wpdb->insert_id;
			self::log_usage( $workspace_id, 'app_created', 1, array( 'app_id' => $app_id, 'app_name' => $insert['app_name'] ) );
			return $app_id;
		}
		return false;
	}

	public static function update_app( $app_id, $workspace_id, $data ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );

		// Validate ownership first!
		$existing = self::get_app( $app_id, $workspace_id );
		if ( ! $existing ) {
			return false;
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$allowed_fields = array(
			'app_name', 'package_id', 'website_url', 'version_name', 'version_code',
			'primary_color', 'status_bar_color', 'navigation_bar_color', 'background_color',
			'app_icon', 'splash_image', 'allow_external_links', 'show_status_bar',
			'orientation', 'config_json', 'design_json', 'features_json', 'status'
		);

		foreach ( $allowed_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$val = $data[ $field ];
				if ( in_array( $field, array( 'primary_color', 'status_bar_color', 'navigation_bar_color', 'background_color' ), true ) ) {
					$update[ $field ] = sanitize_hex_color( $val ) ?: '#7C3AED';
				} elseif ( in_array( $field, array( 'website_url', 'app_icon', 'splash_image' ), true ) ) {
					$update[ $field ] = esc_url_raw( $val );
				} elseif ( in_array( $field, array( 'allow_external_links', 'show_status_bar' ), true ) ) {
					$update[ $field ] = ! empty( $val ) ? 1 : 0;
				} elseif ( in_array( $field, array( 'version_code' ), true ) ) {
					$update[ $field ] = max( 1, absint( $val ) );
				} elseif ( in_array( $field, array( 'config_json', 'design_json', 'features_json' ), true ) ) {
					$update[ $field ] = is_string( $val ) ? $val : wp_json_encode( $val );
				} else {
					$update[ $field ] = sanitize_text_field( $val );
				}
			}
		}

		return $wpdb->update( $table, $update, array( 'id' => $app_id, 'workspace_id' => $workspace_id ) );
	}

	public static function delete_app( $app_id, $workspace_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_APPS );

		$existing = self::get_app( $app_id, $workspace_id );
		if ( ! $existing ) {
			return false;
		}

		// Soft-delete to preserve build records
		$result = $wpdb->update( $table, array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $app_id, 'workspace_id' => $workspace_id ) );
		if ( $result ) {
			self::log_usage( $workspace_id, 'app_deleted', 1, array( 'app_id' => $app_id ) );
		}
		return $result;
	}

	/* ------------------------------------------------------------------------
	 * Plans CRUD
	 * ------------------------------------------------------------------------ */

	public static function get_plan( $plan_id ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_PLANS );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $plan_id ) );
	}

	public static function get_plan_by_slug( $slug ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_PLANS );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s", $slug ) );
	}

	public static function get_all_plans() {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_PLANS );
		return $wpdb->get_results( "SELECT * FROM $table WHERE is_active = 1 ORDER BY price ASC" );
	}

	/* ------------------------------------------------------------------------
	 * Usage Logging & Metrics
	 * ------------------------------------------------------------------------ */

	public static function log_usage( $workspace_id, $event_type, $value = 1, $details = array() ) {
		global $wpdb;
		$table = self::get_table_name( self::TABLE_USAGE );

		$insert = array(
			'workspace_id' => absint( $workspace_id ),
			'user_id'      => get_current_user_id() ?: 0,
			'app_id'       => isset( $details['app_id'] ) ? absint( $details['app_id'] ) : null,
			'event_type'   => sanitize_key( $event_type ),
			'event_value'  => intval( $value ),
			'details_json' => ! empty( $details ) ? wp_json_encode( $details ) : null,
			'created_at'   => current_time( 'mysql' ),
		);

		return $wpdb->insert( $table, $insert );
	}

	public static function get_monthly_build_count( $workspace_id ) {
		global $wpdb;
		$builds_table = BuildDb::get_table_name();
		$first_of_month = gmdate( 'Y-m-01 00:00:00' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $builds_table WHERE workspace_id = %d AND created_at >= %s",
				$workspace_id,
				$first_of_month
			)
		);
	}

	public static function get_workspace_usage_summary( $workspace_id ) {
		global $wpdb;
		$builds_table = BuildDb::get_table_name();
		$apps_table   = self::get_table_name( self::TABLE_APPS );
		$w_table      = self::get_table_name( self::TABLE_WORKSPACES );
		$p_table      = self::get_table_name( self::TABLE_PLANS );

		$workspace = self::get_workspace( $workspace_id );
		$plan      = $workspace ? self::get_plan( $workspace->plan_id ) : null;

		$total_apps     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $apps_table WHERE workspace_id = %d AND status != 'deleted'", $workspace_id ) );
		$active_apps    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $apps_table WHERE workspace_id = %d AND status = 'active'", $workspace_id ) );
		$total_builds   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $builds_table WHERE workspace_id = %d", $workspace_id ) );
		$success_builds = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $builds_table WHERE workspace_id = %d AND status = 'completed'", $workspace_id ) );
		$failed_builds  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $builds_table WHERE workspace_id = %d AND status = 'failed'", $workspace_id ) );
		$monthly_builds = self::get_monthly_build_count( $workspace_id );

		$max_apps   = $plan ? $plan->max_apps : 1;
		$max_builds = $plan ? $plan->max_builds_per_month : 5;

		return array(
			'workspace'            => $workspace,
			'plan'                 => $plan,
			'total_apps'           => $total_apps,
			'active_apps'          => $active_apps,
			'remaining_app_slots'  => max( 0, $max_apps - $total_apps ),
			'total_builds'         => $total_builds,
			'successful_builds'    => $success_builds,
			'failed_builds'        => $failed_builds,
			'monthly_builds'       => $monthly_builds,
			'remaining_build_credits' => max( 0, $max_builds - $monthly_builds ),
			'max_apps'             => $max_apps,
			'max_builds_per_month' => $max_builds,
		);
	}

	public static function get_platform_metrics() {
		global $wpdb;
		$w_table      = self::get_table_name( self::TABLE_WORKSPACES );
		$apps_table   = self::get_table_name( self::TABLE_APPS );
		$builds_table = BuildDb::get_table_name();

		$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w_table" );
		$active_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w_table WHERE status = 'active'" );
		$suspended_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w_table WHERE status = 'suspended'" );
		$total_apps = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $apps_table WHERE status != 'deleted'" );
		$total_builds = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $builds_table" );
		$success_builds = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $builds_table WHERE status = 'completed'" );
		$failed_builds = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $builds_table WHERE status = 'failed'" );

		return array(
			'total_customers'     => $total_customers,
			'active_customers'    => $active_customers,
			'suspended_customers' => $suspended_customers,
			'total_apps'          => $total_apps,
			'total_builds'        => $total_builds,
			'successful_builds'   => $success_builds,
			'failed_builds'       => $failed_builds,
		);
	}
}
