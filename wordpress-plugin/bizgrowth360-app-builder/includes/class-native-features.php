<?php
/**
 * Native Features Manager and Configuration.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NativeFeatures {

	const OPTION_KEY = 'bizgrowth360_native_features';

	/**
	 * Get default configuration for all native features.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$host = parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';

		return array(
			'push_notifications' => array(
				'enabled'              => false,
				'channel_id'           => 'default_channel',
				'channel_name'         => 'General Notifications',
				'firebase_project_id'  => '',
				'firebase_server_key'  => '',
				'google_services_json' => '',
			),
			'deep_linking' => array(
				'enabled'            => false,
				'domain'             => $host,
				'scheme'             => 'bizgrowth360',
				'sha256_fingerprint' => '',
				'auto_verify'        => true,
			),
			'native_share' => array(
				'enabled'       => true,
				'default_title' => get_bloginfo( 'name' ),
				'default_text'  => 'Check this out on ' . get_bloginfo( 'name' ),
			),
			'camera_upload' => array(
				'camera_enabled'     => true,
				'gallery_enabled'    => true,
				'max_file_size_mb'   => 25,
				'allowed_mime_types' => 'image/*,application/pdf',
			),
			'offline_engine' => array(
				'enabled'        => true,
				'title'          => __( 'No Internet Connection', 'bizgrowth360-app-builder' ),
				'message'        => __( 'Please check your connection and tap retry to reload the page.', 'bizgrowth360-app-builder' ),
				'auto_reconnect' => true,
			),
			'permission_manager' => array(
				'enabled'           => true,
				'location_enabled'  => false,
				'rationale_title'   => __( 'Permission Required', 'bizgrowth360-app-builder' ),
				'rationale_message' => __( 'This permission is needed to access native device features.', 'bizgrowth360-app-builder' ),
			),
			'app_updates' => array(
				'enabled'             => true,
				'min_version_code'    => 1,
				'latest_version_code' => 1,
				'latest_version_name' => '1.0.0',
				'mode'                => 'recommended', // 'recommended' | 'mandatory'
				'changelog'           => __( 'Performance improvements and bug fixes.', 'bizgrowth360-app-builder' ),
				'play_store_url'      => '',
				'direct_apk_url'      => '',
			),
		);
	}

	/**
	 * Get current features configuration merged with defaults.
	 *
	 * @return array
	 */
	public static function get_features() {
		$defaults = self::get_defaults();
		$stored   = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$merged = array();
		foreach ( $defaults as $feature_key => $default_fields ) {
			$merged[ $feature_key ] = wp_parse_args( $stored[ $feature_key ] ?? array(), $default_fields );
		}

		return $merged;
	}

	/**
	 * Sanitize and save features configuration.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function save_features( $input ) {
		$current  = self::get_features();
		$defaults = self::get_defaults();
		$clean    = array();

		// Push Notifications
		$clean['push_notifications'] = array(
			'enabled'              => ! empty( $input['push_notifications']['enabled'] ),
			'channel_id'           => sanitize_key( $input['push_notifications']['channel_id'] ?? 'default_channel' ),
			'channel_name'         => sanitize_text_field( $input['push_notifications']['channel_name'] ?? 'General Notifications' ),
			'firebase_project_id'  => sanitize_text_field( $input['push_notifications']['firebase_project_id'] ?? '' ),
			'firebase_server_key'  => sanitize_text_field( $input['push_notifications']['firebase_server_key'] ?? '' ),
			'google_services_json' => trim( (string) ( $input['push_notifications']['google_services_json'] ?? '' ) ),
		);

		// Deep Linking
		$domain = sanitize_text_field( $input['deep_linking']['domain'] ?? '' );
		$domain = preg_replace( '#^https?://#i', '', rtrim( $domain, '/' ) );
		$clean['deep_linking'] = array(
			'enabled'            => ! empty( $input['deep_linking']['enabled'] ),
			'domain'             => $domain,
			'scheme'             => sanitize_key( $input['deep_linking']['scheme'] ?? 'bizgrowth360' ),
			'sha256_fingerprint' => sanitize_text_field( $input['deep_linking']['sha256_fingerprint'] ?? '' ),
			'auto_verify'        => ! empty( $input['deep_linking']['auto_verify'] ),
		);

		// Native Share
		$clean['native_share'] = array(
			'enabled'       => ! empty( $input['native_share']['enabled'] ),
			'default_title' => sanitize_text_field( $input['native_share']['default_title'] ?? get_bloginfo( 'name' ) ),
			'default_text'  => sanitize_text_field( $input['native_share']['default_text'] ?? '' ),
		);

		// Camera and Uploads
		$clean['camera_upload'] = array(
			'camera_enabled'     => ! empty( $input['camera_upload']['camera_enabled'] ),
			'gallery_enabled'    => ! empty( $input['camera_upload']['gallery_enabled'] ),
			'max_file_size_mb'   => max( 1, min( 100, absint( $input['camera_upload']['max_file_size_mb'] ?? 25 ) ) ),
			'allowed_mime_types' => sanitize_text_field( $input['camera_upload']['allowed_mime_types'] ?? 'image/*,application/pdf' ),
		);

		// Offline Engine
		$clean['offline_engine'] = array(
			'enabled'        => ! empty( $input['offline_engine']['enabled'] ),
			'title'          => sanitize_text_field( $input['offline_engine']['title'] ?? 'No Internet Connection' ),
			'message'        => sanitize_textarea_field( $input['offline_engine']['message'] ?? '' ),
			'auto_reconnect' => ! empty( $input['offline_engine']['auto_reconnect'] ),
		);

		// Permission Manager
		$clean['permission_manager'] = array(
			'enabled'           => ! empty( $input['permission_manager']['enabled'] ),
			'location_enabled'  => ! empty( $input['permission_manager']['location_enabled'] ),
			'rationale_title'   => sanitize_text_field( $input['permission_manager']['rationale_title'] ?? 'Permission Required' ),
			'rationale_message' => sanitize_textarea_field( $input['permission_manager']['rationale_message'] ?? '' ),
		);

		// App Updates
		$clean['app_updates'] = array(
			'enabled'             => ! empty( $input['app_updates']['enabled'] ),
			'min_version_code'    => max( 1, absint( $input['app_updates']['min_version_code'] ?? 1 ) ),
			'latest_version_code' => max( 1, absint( $input['app_updates']['latest_version_code'] ?? 1 ) ),
			'latest_version_name' => sanitize_text_field( $input['app_updates']['latest_version_name'] ?? '1.0.0' ),
			'mode'                => in_array( $input['app_updates']['mode'] ?? '', array( 'mandatory', 'recommended' ), true ) ? $input['app_updates']['mode'] : 'recommended',
			'changelog'           => sanitize_textarea_field( $input['app_updates']['changelog'] ?? '' ),
			'play_store_url'      => esc_url_raw( $input['app_updates']['play_store_url'] ?? '' ),
			'direct_apk_url'      => esc_url_raw( $input['app_updates']['direct_apk_url'] ?? '' ),
		);

		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	/**
	 * Compute status indicator for a specific feature.
	 *
	 * @param string $feature_key
	 * @param array  $features
	 * @return string 'configured' | 'needs_setup' | 'enabled' | 'disabled'
	 */
	public static function get_feature_status( $feature_key, $features = null ) {
		if ( null === $features ) {
			$features = self::get_features();
		}

		$feat = $features[ $feature_key ] ?? null;
		if ( ! $feat || empty( $feat['enabled'] ) ) {
			// For camera_upload which has two sub-flags:
			if ( 'camera_upload' === $feature_key ) {
				if ( empty( $feat['camera_enabled'] ) && empty( $feat['gallery_enabled'] ) ) {
					return 'disabled';
				}
				return 'configured';
			}
			return 'disabled';
		}

		switch ( $feature_key ) {
			case 'push_notifications':
				if ( empty( $feat['google_services_json'] ) && empty( $feat['firebase_server_key'] ) ) {
					return 'needs_setup';
				}
				return 'configured';

			case 'deep_linking':
				if ( empty( $feat['domain'] ) || empty( $feat['sha256_fingerprint'] ) ) {
					return 'needs_setup';
				}
				return 'configured';

			case 'app_updates':
				if ( empty( $feat['play_store_url'] ) && empty( $feat['direct_apk_url'] ) ) {
					return 'needs_setup';
				}
				return 'configured';

			default:
				return 'configured';
		}
	}

	/**
	 * Dashboard summary statistics and statuses.
	 *
	 * @return array
	 */
	public static function get_dashboard_summary() {
		$features = self::get_features();
		$summary  = array(
			'total'       => count( $features ),
			'configured'  => 0,
			'needs_setup' => 0,
			'disabled'    => 0,
			'statuses'    => array(),
		);

		foreach ( array_keys( $features ) as $key ) {
			$st = self::get_feature_status( $key, $features );
			$summary['statuses'][ $key ] = $st;
			if ( 'configured' === $st ) {
				$summary['configured']++;
			} elseif ( 'needs_setup' === $st ) {
				$summary['needs_setup']++;
			} else {
				$summary['disabled']++;
			}
		}

		return $summary;
	}

	/**
	 * Get an associative array of all features with their status.
	 *
	 * @return array
	 */
	public static function get_features_with_status() {
		$summary = self::get_dashboard_summary();
		return $summary['statuses'] ?? array();
	}

	/**
	 * Get status summary statistics (alias).
	 *
	 * @return array
	 */
	public static function get_status_summary() {
		return self::get_dashboard_summary();
	}

	/**
	 * Generate Digital Asset Links JSON content.
	 *
	 * @param string $package_id
	 * @param string $fingerprint
	 * @return array
	 */
	public static function generate_assetlinks_json( $package_id = '', $fingerprint = '' ) {
		$features = self::get_features();

		if ( empty( $package_id ) ) {
			$settings   = new Settings();
			$options    = $settings->get_options();
			$package_id = $options['package_id'] ?? 'in.bizgrowth360.demoapp';
		}

		if ( empty( $fingerprint ) ) {
			$fingerprint = $features['deep_linking']['sha256_fingerprint'] ?? '';
		}

		// Normalize fingerprint to colon-separated uppercase or list
		$fingerprints = array();
		if ( ! empty( $fingerprint ) ) {
			$parts = preg_split( '/[\r\n,]+/', $fingerprint );
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( ! empty( $p ) ) {
					$fingerprints[] = $p;
				}
			}
		}

		if ( empty( $fingerprints ) ) {
			$fingerprints = array( '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00' );
		}

		return array(
			array(
				'relation' => array(
					'delegate_permission/common.handle_all_urls',
				),
				'target'   => array(
					'namespace'                => 'android_app',
					'package_name'             => $package_id,
					'sha256_cert_fingerprints' => $fingerprints,
				),
			),
		);
	}
}
