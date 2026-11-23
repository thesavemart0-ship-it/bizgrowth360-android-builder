<?php
/**
 * Database schema and repository for FCM device tokens.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DeviceTokensDb {

	const TABLE_NAME = 'bg360_device_tokens';
	const DB_VERSION = '1.0.0';
	const DB_VERSION_OPTION = 'bizgrowth360_device_tokens_db_version';

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Install or upgrade the device tokens database table.
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			device_id varchar(128) NOT NULL,
			token text NOT NULL,
			platform varchar(32) NOT NULL DEFAULT 'android',
			app_version varchar(32) NOT NULL DEFAULT '1.0.0',
			package_id varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY device_id (device_id),
			KEY platform (platform),
			KEY package_id (package_id(191)),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Register or update an FCM device token.
	 *
	 * @param array $data
	 * @return bool
	 */
	public static function register_token( $data ) {
		global $wpdb;

		$device_id   = sanitize_text_field( $data['device_id'] ?? '' );
		$token       = sanitize_text_field( $data['token'] ?? '' );
		$platform    = sanitize_key( $data['platform'] ?? 'android' );
		$app_version = sanitize_text_field( $data['app_version'] ?? '1.0.0' );
		$package_id  = sanitize_text_field( $data['package_id'] ?? '' );
		$user_id     = ! empty( $data['user_id'] ) ? absint( $data['user_id'] ) : null;

		if ( empty( $device_id ) || empty( $token ) ) {
			return false;
		}

		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		// Check if exists
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE device_id = %s LIMIT 1", $device_id )
		);

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				array(
					'token'       => $token,
					'platform'    => $platform,
					'app_version' => $app_version,
					'package_id'  => $package_id,
					'user_id'     => $user_id,
					'updated_at'  => $now,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			return false !== $updated;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'device_id'   => $device_id,
				'token'       => $token,
				'platform'    => $platform,
				'app_version' => $app_version,
				'package_id'  => $package_id,
				'user_id'     => $user_id,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Get device tokens list.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function get_tokens( $args = array() ) {
		global $wpdb;
		$table = self::get_table_name();

		$defaults = array(
			'platform'   => '',
			'package_id' => '',
			'limit'      => 100,
			'offset'     => 0,
		);
		$r = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $r['platform'] ) ) {
			$where[]  = 'platform = %s';
			$values[] = $r['platform'];
		}
		if ( ! empty( $r['package_id'] ) ) {
			$where[]  = 'package_id = %s';
			$values[] = $r['package_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $r['limit'], $r['offset'] );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC {$limit_sql}";
		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return $wpdb->get_results( $sql ) ?: array();
	}

	/**
	 * Count total registered devices.
	 *
	 * @param string $package_id Optional filter.
	 * @return int
	 */
	public static function get_device_count( $package_id = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		if ( ! empty( $package_id ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE package_id = %s", $package_id )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Get single device token row by device ID.
	 *
	 * @param string $device_id
	 * @return object|null
	 */
	public static function get_token_by_device( $device_id ) {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE device_id = %s LIMIT 1", $device_id )
		);
	}

	/**
	 * Count total registered devices (alias).
	 *
	 * @param string $package_id Optional filter.
	 * @return int
	 */
	public static function count_tokens( $package_id = '' ) {
		return self::get_device_count( $package_id );
	}

	/**
	 * Unregister token by device ID.
	 *
	 * @param string $device_id
	 * @return bool
	 */
	public static function unregister_token( $device_id ) {
		global $wpdb;
		$table = self::get_table_name();
		return false !== $wpdb->delete( $table, array( 'device_id' => $device_id ) );
	}

	/**
	 * Delete a token by string.
	 *
	 * @param string $token
	 * @return bool
	 */
	public static function delete_token( $token ) {
		global $wpdb;
		$table = self::get_table_name();
		return false !== $wpdb->delete( $table, array( 'token' => $token ) );
	}
}
