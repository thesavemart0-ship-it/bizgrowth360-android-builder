<?php
/**
 * Settings and options manager.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY = 'bizgrowth360_app_builder_options';

	/**
	 * Initialize settings hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'migrate_legacy_options' ) );
	}

	/**
	 * Register settings with WordPress.
	 */
	public function register_settings() {
		register_setting(
			'bizgrowth360_app_builder_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_options' )
		);
	}

	/**
	 * Migrate legacy options by removing stored GitHub credentials/repository settings
	 * while strictly preserving all customer app configurations.
	 */
	public function migrate_legacy_options() {
		$options = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $options ) ) {
			return;
		}

		$legacy_keys = array( 'github_owner', 'github_repo', 'github_branch', 'github_token', 'workflow_file' );
		$modified    = false;

		foreach ( $legacy_keys as $key ) {
			if ( array_key_exists( $key, $options ) ) {
				unset( $options[ $key ] );
				$modified = true;
			}
		}

		if ( $modified ) {
			update_option( self::OPTION_KEY, $options );
		}
	}

	/**
	 * Get all options with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		$defaults = $this->get_defaults();
		$options  = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $options, $defaults );
	}

	/**
	 * Get single option.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$options = $this->get_options();
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Set defaults upon activation.
	 */
	public function set_defaults() {
		if ( ! get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, $this->get_defaults() );
		} else {
			$this->migrate_legacy_options();
		}
	}

	/**
	 * Return default settings (App Configuration only; no GitHub/CI internals).
	 *
	 * @return array
	 */
	public function get_defaults() {
		$site_title = get_bloginfo( 'name' );
		if ( empty( $site_title ) ) {
			$site_title = 'TownConnect App';
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $host );
		$package_id = 'com.' . ( ! empty( $host_clean ) ? $host_clean : 'townconnectai' ) . '.app';

		return array(
			// App Branding
			'app_name'             => $site_title,
			'package_id'           => $package_id,
			'website_url'          => home_url( '/' ),
			'version_name'         => '1.0.0',
			'version_code'         => 1,
			'primary_color'        => '#2563EB',
			'background_color'     => '#FFFFFF',
			'status_bar_color'     => '#2563EB',
			'navigation_bar_color' => '#FFFFFF',
			'orientation'          => 'portrait',
			'allow_external_links' => true,
			'app_icon'             => '',
			'splash_image'         => '',

			// Build targets
			'platform'             => 'android',
			'build_type'           => 'both',
			'build_target'         => 'both',

			// iOS / Apple
			'ios_bundle_id'        => self::default_bundle_id( $package_id ),
			'ios_team_id'          => '',
			'ios_export_method'    => 'app-store',
			'ios_mode'             => 'ipa',
			'ios_upload_testflight' => false,
			'macos_runner'         => 'macos-14',
		);
	}

	/**
	 * Derive a valid Apple bundle identifier from an Android package ID.
	 *
	 * Apple bundle identifiers cannot contain underscores.
	 *
	 * @param string $package_id
	 * @return string
	 */
	public static function default_bundle_id( $package_id ) {
		$id = str_replace( '_', '-', (string) $package_id );
		$id = preg_replace( '/[^A-Za-z0-9.-]/', '', $id );
		$segments = array_filter( explode( '.', $id ) );
		$segments = array_map(
			static function ( $segment ) {
				return trim( $segment, '-' );
			},
			$segments
		);
		$id = implode( '.', array_filter( $segments ) );
		return ! empty( $id ) ? $id : 'in.bizgrowth360.app';
	}

	/**
	 * Validate and normalize an Apple bundle identifier.
	 *
	 * @param string $bundle_id
	 * @return string Empty string when invalid.
	 */
	public static function sanitize_bundle_id( $bundle_id ) {
		$bundle_id = trim( (string) $bundle_id );
		if ( strlen( $bundle_id ) > 155 ) {
			return '';
		}
		$segment = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
		if ( ! preg_match( '/^' . $segment . '(\.' . $segment . ')+$/', $bundle_id ) ) {
			return '';
		}
		if ( ! preg_match( '/[A-Za-z]/', $bundle_id ) ) {
			return '';
		}
		if ( preg_match( '/\.(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $bundle_id ) ) {
			return '';
		}
		return $bundle_id;
	}

	/**
	 * Sanitize options before saving.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();
		$defaults  = $this->get_defaults();

		$sanitized['app_name']             = ! empty( $input['app_name'] ) ? sanitize_text_field( $input['app_name'] ) : $defaults['app_name'];
		$sanitized['package_id']           = ! empty( $input['package_id'] ) ? sanitize_text_field( $input['package_id'] ) : $defaults['package_id'];
		$sanitized['website_url']          = ! empty( $input['website_url'] ) ? esc_url_raw( $input['website_url'] ) : $defaults['website_url'];
		$sanitized['version_name']         = ! empty( $input['version_name'] ) ? sanitize_text_field( $input['version_name'] ) : '1.0.0';
		$sanitized['version_code']         = ! empty( $input['version_code'] ) ? absint( $input['version_code'] ) : 1;
		$sanitized['primary_color']        = ! empty( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '#2563EB';
		$sanitized['background_color']     = ! empty( $input['background_color'] ) ? sanitize_hex_color( $input['background_color'] ) : '#FFFFFF';
		$sanitized['status_bar_color']     = ! empty( $input['status_bar_color'] ) ? sanitize_hex_color( $input['status_bar_color'] ) : $sanitized['primary_color'];
		$sanitized['navigation_bar_color'] = ! empty( $input['navigation_bar_color'] ) ? sanitize_hex_color( $input['navigation_bar_color'] ) : '#FFFFFF';
		$sanitized['orientation']          = in_array( $input['orientation'] ?? 'portrait', array( 'portrait', 'landscape', 'sensor', 'auto' ), true ) ? $input['orientation'] : 'portrait';
		$sanitized['allow_external_links'] = ! empty( $input['allow_external_links'] );
		$sanitized['app_icon']             = esc_url_raw( $input['app_icon'] ?? '' );
		$sanitized['splash_image']         = esc_url_raw( $input['splash_image'] ?? '' );

		$sanitized['build_type']           = in_array( $input['build_type'] ?? 'both', array( 'both', 'release', 'debug' ), true ) ? $input['build_type'] : 'both';
		$sanitized['build_target']         = in_array( $input['build_target'] ?? 'both', array( 'both', 'apk', 'aab' ), true ) ? $input['build_target'] : 'both';
		$sanitized['platform']             = in_array( $input['platform'] ?? 'android', array( 'android', 'ios', 'both' ), true ) ? $input['platform'] : 'android';

		// iOS / Apple settings. Invalid values fall back to safe defaults rather
		// than being stored and failing later on the macOS runner.
		$bundle_input = self::sanitize_bundle_id( $input['ios_bundle_id'] ?? '' );
		$sanitized['ios_bundle_id'] = ! empty( $bundle_input )
			? $bundle_input
			: self::default_bundle_id( $sanitized['package_id'] );

		$team_id = strtoupper( sanitize_text_field( $input['ios_team_id'] ?? '' ) );
		$sanitized['ios_team_id'] = preg_match( '/^[A-Z0-9]{10}$/', $team_id ) ? $team_id : '';

		$sanitized['ios_export_method'] = in_array( $input['ios_export_method'] ?? 'app-store', array( 'app-store', 'ad-hoc', 'development' ), true )
			? $input['ios_export_method']
			: 'app-store';
		$sanitized['ios_mode'] = in_array( $input['ios_mode'] ?? 'ipa', array( 'ipa', 'validation' ), true ) ? $input['ios_mode'] : 'ipa';
		$sanitized['ios_upload_testflight'] = ! empty( $input['ios_upload_testflight'] );
		$sanitized['macos_runner'] = in_array( $input['macos_runner'] ?? 'macos-14', array( 'macos-14', 'macos-15' ), true )
			? $input['macos_runner']
			: 'macos-14';

		return $sanitized;
	}
}
