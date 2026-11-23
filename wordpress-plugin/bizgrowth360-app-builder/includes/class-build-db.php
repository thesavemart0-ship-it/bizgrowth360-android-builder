<?php
/**
 * Database schema and repository for Android build requests.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuildDb {

	const TABLE_NAME = 'bg360_builds';
	const DB_VERSION = '1.1.0';
	const DB_VERSION_OPTION = 'bizgrowth360_build_db_version';

	/**
	 * Get the full table name including prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the custom database table using dbDelta.
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			build_id varchar(64) NOT NULL,
			parent_build_id varchar(64) DEFAULT NULL,
			app_name varchar(255) NOT NULL,
			package_id varchar(255) NOT NULL,
			platform varchar(16) NOT NULL DEFAULT 'android',
			ios_bundle_id varchar(255) DEFAULT NULL,
			ios_export_method varchar(20) DEFAULT NULL,
			ios_upload_testflight tinyint(1) NOT NULL DEFAULT 0,
			website_url text NOT NULL,
			version_name varchar(64) NOT NULL DEFAULT '1.0.0',
			version_code int(11) NOT NULL DEFAULT 1,
			build_type varchar(20) NOT NULL DEFAULT 'both',
			build_variant varchar(20) NOT NULL DEFAULT 'release',
			status varchar(30) NOT NULL DEFAULT 'queued',
			stage varchar(50) NOT NULL DEFAULT 'init',
			run_id bigint(20) unsigned DEFAULT NULL,
			run_number int(11) unsigned DEFAULT NULL,
			config_snapshot longtext NOT NULL,
			artifacts longtext DEFAULT NULL,
			error_message text DEFAULT NULL,
			duration_seconds int(11) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY build_id (build_id),
			KEY parent_build_id (parent_build_id),
			KEY status (status),
			KEY platform (platform),
			KEY run_id (run_id),
			KEY app_name (app_name(191)),
			KEY package_id (package_id(191)),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert a new build record into the database.
	 *
	 * @param array $data
	 * @return int|false Record ID or false on failure.
	 */
	public static function create_build( $data ) {
		global $wpdb;

		$defaults = array(
			'build_id'         => 'bg360_bld_' . wp_generate_password( 12, false, false ),
			'parent_build_id'  => null,
			'app_name'         => '',
			'package_id'       => '',
			'platform'         => 'android',
			'ios_bundle_id'    => '',
			'ios_export_method' => '',
			'ios_upload_testflight' => 0,
			'website_url'      => '',
			'version_name'     => '1.0.0',
			'version_code'     => 1,
			'build_type'       => 'both',
			'build_variant'    => 'release',
			'status'           => 'queued',
			'stage'            => 'init',
			'run_id'           => null,
			'run_number'       => null,
			'config_snapshot'  => '{}',
			'artifacts'        => null,
			'error_message'    => null,
			'duration_seconds' => null,
			'created_by'       => get_current_user_id(),
			'created_at'       => current_time( 'mysql' ),
			'started_at'       => null,
			'completed_at'     => null,
		);

		$record = wp_parse_args( $data, $defaults );

		if ( is_array( $record['config_snapshot'] ) ) {
			$record['config_snapshot'] = wp_json_encode( $record['config_snapshot'] );
		}
		if ( is_array( $record['artifacts'] ) ) {
			$record['artifacts'] = wp_json_encode( $record['artifacts'] );
		}

		$inserted = $wpdb->insert(
			self::get_table_name(),
			$record,
			array(
				'%s', // build_id
				'%s', // parent_build_id
				'%s', // app_name
				'%s', // package_id
				'%s', // platform
				'%s', // ios_bundle_id
				'%s', // ios_export_method
				'%d', // ios_upload_testflight
				'%s', // website_url
				'%s', // version_name
				'%d', // version_code
				'%s', // build_type
				'%s', // build_variant
				'%s', // status
				'%s', // stage
				'%d', // run_id
				'%d', // run_number
				'%s', // config_snapshot
				'%s', // artifacts
				'%s', // error_message
				'%d', // duration_seconds
				'%d', // created_by
				'%s', // created_at
				'%s', // started_at
				'%s', // completed_at
			)
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing build record.
	 *
	 * @param string $build_id
	 * @param array  $data
	 * @return bool
	 */
	public static function update_build( $build_id, $data ) {
		global $wpdb;

		if ( isset( $data['config_snapshot'] ) && is_array( $data['config_snapshot'] ) ) {
			$data['config_snapshot'] = wp_json_encode( $data['config_snapshot'] );
		}
		if ( isset( $data['artifacts'] ) && is_array( $data['artifacts'] ) ) {
			$data['artifacts'] = wp_json_encode( $data['artifacts'] );
		}

		$updated = $wpdb->update(
			self::get_table_name(),
			$data,
			array( 'build_id' => $build_id )
		);

		return false !== $updated;
	}

	/**
	 * Retrieve a single build by its unique build_id.
	 *
	 * @param string $build_id
	 * @return object|null
	 */
	public static function get_build( $build_id ) {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE build_id = %s LIMIT 1", $build_id )
		);

		return self::format_row( $row );
	}

	/**
	 * Retrieve a single build by its GitHub run_id.
	 *
	 * @param int $run_id
	 * @return object|null
	 */
	public static function get_build_by_run_id( $run_id ) {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d LIMIT 1", $run_id )
		);

		return self::format_row( $row );
	}

	/**
	 * Get the currently active build (queued or running).
	 *
	 * @return object|null
	 */
	public static function get_active_build() {
		global $wpdb;
		$table = self::get_table_name();

		$row = $wpdb->get_row(
			"SELECT * FROM {$table} 
			 WHERE status IN ('queued', 'dispatching', 'starting', 'preparing', 'building', 'signing', 'exporting', 'uploading', 'in_progress')
			 ORDER BY id DESC LIMIT 1"
		);

		return self::format_row( $row );
	}

	/**
	 * Retrieve builds with search, filter, and pagination.
	 *
	 * @param array $args
	 * @return array array( 'items' => array, 'total' => int, 'pages' => int )
	 */
	public static function get_builds( $args = array() ) {
		global $wpdb;
		$table = self::get_table_name();

		$defaults = array(
			'search'     => '',
			'status'     => '',
			'build_type' => '',
			'platform'   => '',
			'page'       => 1,
			'per_page'   => 10,
			'orderby'    => 'id',
			'order'      => 'DESC',
		);

		$r = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $r['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$where[] = '(app_name LIKE %s OR package_id LIKE %s OR ios_bundle_id LIKE %s OR build_id LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $r['status'] ) && 'all' !== $r['status'] ) {
			if ( 'active' === $r['status'] ) {
				$where[] = "status IN ('queued', 'dispatching', 'starting', 'preparing', 'building', 'signing', 'exporting', 'uploading', 'in_progress')";
			} else {
				$where[] = 'status = %s';
				$values[] = $r['status'];
			}
		}

		if ( ! empty( $r['platform'] ) && 'all' !== $r['platform'] ) {
			$where[] = 'platform = %s';
			$values[] = $r['platform'];
		}

		if ( ! empty( $r['build_type'] ) && 'all' !== $r['build_type'] ) {
			$where[] = 'build_type = %s';
			$values[] = $r['build_type'];
		}

		$where_clause = implode( ' AND ', $where );

		// Count total
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		// Query items
		$per_page = max( 1, (int) $r['per_page'] );
		$page     = max( 1, (int) $r['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$orderby  = in_array( strtolower( $r['orderby'] ), array( 'id', 'created_at', 'status' ), true ) ? strtolower( $r['orderby'] ) : 'id';
		$order    = 'ASC' === strtoupper( $r['order'] ) ? 'ASC' : 'DESC';

		$items_sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		$items_sql = $wpdb->prepare( $items_sql, $query_values );

		$rows = $wpdb->get_results( $items_sql );
		$items = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = self::format_row( $row );
			}
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
		);
	}

	/**
	 * Helper to parse JSON fields on row objects.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function format_row( $row ) {
		if ( ! $row ) {
			return null;
		}

		$row->config_snapshot = json_decode( $row->config_snapshot, true ) ?: array();
		$row->artifacts       = ! empty( $row->artifacts ) ? ( json_decode( $row->artifacts, true ) ?: array() ) : array();
		$row->platform        = ! empty( $row->platform ) ? $row->platform : 'android';
		$row->ios_upload_testflight = ! empty( $row->ios_upload_testflight );

		return $row;
	}
}
