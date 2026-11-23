<?php
/**
 * Cross-platform (Android + iOS) build lifecycle manager.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuildManager {

	const DISPATCH_LOCK = 'bizgrowth360_build_dispatch_lock';
	const ACTIVE_STATUSES = array( 'queued', 'dispatching', 'starting', 'preparing', 'building', 'signing', 'exporting', 'uploading', 'in_progress' );

	private $settings;
	private $github_client;

	public function __construct( Settings $settings, GitHubClient $github_client ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
	}

	/**
	 * Resolve the requested build action.
	 *
	 * Supported: apk, aab, both (Android), ios (IPA), all (Android + iOS).
	 * Legacy UI payloads used build_type/build_target instead of build_action.
	 *
	 * @param array $inputs
	 * @return string
	 */
	private function resolve_build_action( $inputs ) {
		$action = sanitize_key( $inputs['build_action'] ?? '' );
		if ( in_array( $action, array( 'apk', 'aab', 'both', 'ios', 'all' ), true ) ) {
			return $action;
		}

		$legacy_type = sanitize_key( $inputs['build_type'] ?? '' );
		if ( in_array( $legacy_type, array( 'apk', 'aab', 'both' ), true ) ) {
			return $legacy_type;
		}

		$platform = sanitize_key( $inputs['platform'] ?? 'android' );
		if ( 'ios' === $platform ) {
			return 'ios';
		}
		if ( 'both' === $platform ) {
			return 'all';
		}

		$target = sanitize_key( $inputs['build_target'] ?? 'both' );
		return in_array( $target, array( 'apk', 'aab', 'both' ), true ) ? $target : 'both';
	}

	/**
	 * Validate configuration inputs before starting a build.
	 *
	 * @param array       $inputs
	 * @param string|null $platform android|ios. Null = derive from inputs.
	 * @return array array( 'valid' => bool, 'errors' => array, 'warnings' => array, 'sanitized' => array )
	 */
	public function validate_inputs( $inputs, $platform = null ) {
		$errors    = array();
		$warnings  = array();
		$sanitized = array();

		$platform = $platform ? sanitize_key( $platform ) : null;
		if ( null === $platform ) {
			$requested_action = $this->resolve_build_action( $inputs );
			if ( 'ios' === $requested_action ) {
				$platform = 'ios';
			} elseif ( 'all' === $requested_action ) {
				$platform = 'both';
			} else {
				$platform = sanitize_key( $inputs['platform'] ?? 'android' );
			}
		}
		if ( ! in_array( $platform, array( 'android', 'ios', 'both' ), true ) ) {
			$platform = 'android';
		}

		// App Name
		$app_name = sanitize_text_field( $inputs['app_name'] ?? '' );
		if ( empty( $app_name ) ) {
			$errors[] = __( 'App Name is required.', 'bizgrowth360-app-builder' );
		} elseif ( mb_strlen( $app_name ) > 50 ) {
			$errors[] = __( 'App Name must not exceed 50 characters.', 'bizgrowth360-app-builder' );
		} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $app_name ) ) {
			$errors[] = __( 'App Name contains invalid control characters.', 'bizgrowth360-app-builder' );
		}
		$sanitized['app_name'] = $app_name;

		// Android Package ID (always part of the shared configuration snapshot)
		$package_id = sanitize_text_field( $inputs['package_id'] ?? '' );
		if ( empty( $package_id ) ) {
			$errors[] = __( 'Package ID is required.', 'bizgrowth360-app-builder' );
		} elseif ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $package_id ) ) {
			$errors[] = __( 'Package ID must be a valid Android application ID (e.g., com.example.myapp) with at least two segments.', 'bizgrowth360-app-builder' );
		}
		$sanitized['package_id'] = $package_id;

		// Website URL
		$website_url = esc_url_raw( $inputs['website_url'] ?? '' );
		if ( empty( $website_url ) ) {
			$errors[] = __( 'Website URL is required.', 'bizgrowth360-app-builder' );
		} elseif ( 0 !== strpos( strtolower( $website_url ), 'https://' ) ) {
			$errors[] = __( 'Website URL must be a valid secure HTTPS address.', 'bizgrowth360-app-builder' );
		}
		$sanitized['website_url'] = $website_url;

		// Version Name
		$version_name = sanitize_text_field( $inputs['version_name'] ?? '1.0.0' );
		if ( ! preg_match( '/^[0-9]+(\.[0-9]+)*([a-zA-Z0-9_\-\.])*$/', $version_name ) ) {
			$errors[] = __( 'Version Name must be in standard version format (e.g., 1.0.0).', 'bizgrowth360-app-builder' );
		}
		if ( in_array( $platform, array( 'ios', 'both' ), true ) && ! preg_match( '/^[0-9]+(\.[0-9]+){0,2}$/', $version_name ) ) {
			$errors[] = __( 'iOS version name (CFBundleShortVersionString) must contain 1-3 numeric components, e.g. 1.0.0.', 'bizgrowth360-app-builder' );
		}
		$sanitized['version_name'] = $version_name ?: '1.0.0';

		// Version Code / iOS build number
		$version_code = absint( $inputs['version_code'] ?? 1 );
		if ( $version_code < 1 ) {
			$errors[] = __( 'Version Code must be a positive integer greater than or equal to 1.', 'bizgrowth360-app-builder' );
		}
		$sanitized['version_code'] = max( 1, $version_code );

		// Build Type (variant) and Android target
		$build_variant = sanitize_key( $inputs['build_variant'] ?? $inputs['build_type'] ?? 'release' );
		if ( ! in_array( $build_variant, array( 'release', 'debug' ), true ) ) {
			$build_variant = 'release';
		}
		$sanitized['build_variant'] = $build_variant;

		$build_target = sanitize_key( $inputs['build_target'] ?? $inputs['build_type'] ?? 'both' );
		if ( ! in_array( $build_target, array( 'apk', 'aab', 'both' ), true ) ) {
			$build_target = 'both';
		}
		$sanitized['build_target'] = $build_target;

		// Orientation
		$orientation = sanitize_key( $inputs['orientation'] ?? 'portrait' );
		if ( ! in_array( $orientation, array( 'portrait', 'landscape', 'auto', 'sensor' ), true ) ) {
			$orientation = 'portrait';
		}
		$sanitized['orientation'] = $orientation;

		// Colors
		$color_keys = array( 'primary_color', 'background_color', 'status_bar_color', 'navigation_bar_color', 'splash_background_color' );
		foreach ( $color_keys as $ck ) {
			$val = sanitize_hex_color( $inputs[ $ck ] ?? '' );
			if ( empty( $val ) && in_array( $ck, array( 'primary_color', 'background_color' ), true ) ) {
				$val = 'primary_color' === $ck ? '#2563EB' : '#FFFFFF';
			}
			$sanitized[ $ck ] = $val;
		}

		// Icon and Splash URLs (SSRF validation)
		foreach ( array( 'icon_url', 'splash_url' ) as $ak ) {
			$raw_url = esc_url_raw( $inputs[ $ak ] ?? '' );
			if ( ! empty( $raw_url ) ) {
				if ( 0 !== strpos( strtolower( $raw_url ), 'https://' ) ) {
					$raw_url = '';
				} else {
					$host = parse_url( $raw_url, PHP_URL_HOST );
					if ( empty( $host ) || in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
						$raw_url = '';
					}
				}
			}
			$sanitized[ $ak ] = $raw_url;
		}

		// Booleans
		$sanitized['allow_external_links'] = ! empty( $inputs['allow_external_links'] );
		$sanitized['enable_status_bar']    = ! isset( $inputs['enable_status_bar'] ) || ! empty( $inputs['enable_status_bar'] );

		// Platform and iOS settings
		$sanitized['platform'] = $platform;
		$sanitized['build_action'] = $this->resolve_build_action( $inputs );

		$bundle_input = Settings::sanitize_bundle_id( $inputs['ios_bundle_id'] ?? '' );
		if ( ! empty( $inputs['ios_bundle_id'] ) && empty( $bundle_input ) ) {
			$errors[] = __( 'iOS Bundle Identifier is invalid. Use at least two dot-separated segments containing letters, numbers and hyphens.', 'bizgrowth360-app-builder' );
		}
		$sanitized['ios_bundle_id'] = ! empty( $bundle_input )
			? $bundle_input
			: Settings::default_bundle_id( $package_id );

		$team_id = strtoupper( sanitize_text_field( $inputs['ios_team_id'] ?? '' ) );
		if ( ! empty( $team_id ) && ! preg_match( '/^[A-Z0-9]{10}$/', $team_id ) ) {
			$errors[] = __( 'Apple Team ID must be a 10-character alphanumeric value.', 'bizgrowth360-app-builder' );
		}
		$sanitized['ios_team_id'] = preg_match( '/^[A-Z0-9]{10}$/', $team_id ) ? $team_id : '';

		$export_method = sanitize_key( $inputs['ios_export_method'] ?? 'app-store' );
		if ( ! in_array( $export_method, array( 'app-store', 'ad-hoc', 'development' ), true ) ) {
			$export_method = 'app-store';
		}
		$sanitized['ios_export_method'] = $export_method;

		$ios_mode = sanitize_key( $inputs['ios_mode'] ?? 'ipa' );
		$sanitized['ios_mode'] = in_array( $ios_mode, array( 'ipa', 'validation' ), true ) ? $ios_mode : 'ipa';

		$macos_runner = sanitize_key( $inputs['macos_runner'] ?? 'macos-15' );
		$sanitized['macos_runner'] = in_array( $macos_runner, array( 'macos-15' ), true ) ? $macos_runner : 'macos-15';

		$sanitized['ios_upload_testflight'] = ! empty( $inputs['ios_upload_testflight'] );
		$sanitized['confirm_app_store_upload'] = ! empty( $inputs['confirm_app_store_upload'] );

		if ( in_array( $platform, array( 'ios', 'both' ), true ) ) {
			if ( 'app-store' !== $export_method && ! empty( $inputs['ios_upload_testflight'] ) ) {
				$warnings[] = __( 'TestFlight upload is only available with the App Store export method; it will be skipped for this build.', 'bizgrowth360-app-builder' );
			}
			if ( empty( $team_id ) ) {
				$warnings[] = __( 'Apple Team ID is not set. The IOS_TEAM_ID GitHub secret can supply it on the runner.', 'bizgrowth360-app-builder' );
			}
		}

		return array(
			'valid'     => empty( $errors ),
			'errors'    => $errors,
			'warnings'  => $warnings,
			'sanitized' => $sanitized,
		);
	}

	/**
	 * Snapshot Design Studio configuration with a hard size limit.
	 *
	 * @param array $custom_inputs
	 * @return array|false
	 */
	private function snapshot_design( $custom_inputs ) {
		if ( isset( $custom_inputs['design'] ) && is_array( $custom_inputs['design'] ) ) {
			$design = $custom_inputs['design'];
		} else {
			$design_config = new DesignConfig();
			$design        = $design_config->get();
		}

		$json = wp_json_encode( $design );
		if ( false === $json || strlen( $json ) > 60000 ) {
			return false;
		}

		return $design;
	}

	/**
	 * Snapshot Native Features configuration.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	private function snapshot_features( $custom_inputs ) {
		if ( isset( $custom_inputs['features'] ) && is_array( $custom_inputs['features'] ) ) {
			$features = $custom_inputs['features'];
		} else {
			$features = NativeFeatures::get_features();
		}

		$json = wp_json_encode( $features );
		return ( false === $json || strlen( $json ) > 60000 ) ? array() : $features;
	}

	/**
	 * Initiate a new build request.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	public function start_build( $custom_inputs = array() ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service is not configured. Please define BIZGROWTH360_GITHUB_TOKEN in wp-config.php.', 'bizgrowth360-app-builder' ),
			);
		}

		if ( get_transient( self::DISPATCH_LOCK ) ) {
			return array(
				'success' => false,
				'message' => __( 'A build request is already being dispatched. Please retry in a few seconds.', 'bizgrowth360-app-builder' ),
			);
		}

		$action = $this->resolve_build_action( $custom_inputs );
		set_transient( self::DISPATCH_LOCK, time(), 45 );

		try {
			if ( 'all' === $action ) {
				return $this->start_all_platforms( $custom_inputs );
			}

			$platform = 'ios' === $action ? 'ios' : 'android';
			return $this->start_single_build( $custom_inputs, $platform, $action );
		} finally {
			delete_transient( self::DISPATCH_LOCK );
		}
	}

	/**
	 * Start one platform build and dispatch it to GitHub Actions.
	 *
	 * @param array  $custom_inputs
	 * @param string $platform android|ios
	 * @param string $action   Android target or ios
	 * @return array
	 */
	private function start_single_build( $custom_inputs, $platform, $action ) {
		$stored_options = $this->settings->get_options();
		$merged_inputs  = wp_parse_args( $custom_inputs, $stored_options );

		$val_result = $this->validate_inputs( $merged_inputs, $platform );
		if ( ! $val_result['valid'] ) {
			return array(
				'success' => false,
				'message' => implode( ' ', $val_result['errors'] ),
				'errors'  => $val_result['errors'],
			);
		}

		$config = $val_result['sanitized'];
		$config['platform']     = $platform;
		$config['build_action'] = 'ios' === $platform ? 'ios' : $action;

		$design = $this->snapshot_design( $custom_inputs );
		if ( false === $design ) {
			return array(
				'success' => false,
				'message' => __( 'Design configuration is invalid or too large to dispatch.', 'bizgrowth360-app-builder' ),
			);
		}
		$config['design'] = $design;

		$features = $this->snapshot_features( $custom_inputs );
		$config['features'] = $features;

		$workspace = TenantManager::get_current_workspace();
		$workspace_id = $workspace ? (int) $workspace->id : 1;
		$config['workspace_id'] = $workspace_id;

		$result = $this->create_and_dispatch( $config, $action, $platform );

		if ( $result['success'] ) {
			$result['config'] = $config;
		}

		return $result;
	}

	/**
	 * Create the immutable DB record and dispatch the selected workflow.
	 *
	 * @param array  $config   Sanitized config (design + features included).
	 * @param string $action   apk|aab|both|ios
	 * @param string $platform android|ios
	 * @return array
	 */
	private function create_and_dispatch( $config, $action, $platform ) {
		$build_id = 'bg360_bld_' . wp_generate_password( 12, false, false );

		$build_type = 'ios' === $platform ? 'ipa' : $action;

		$will_upload = 'ios' === $platform
			&& ! empty( $config['ios_upload_testflight'] )
			&& ! empty( $config['confirm_app_store_upload'] )
			&& 'app-store' === $config['ios_export_method']
			&& 'ipa' === $config['ios_mode'];

		$db_data = array(
			'build_id'              => $build_id,
			'parent_build_id'       => $config['parent_build_id'] ?? null,
			'workspace_id'          => absint( $config['workspace_id'] ?? 0 ),
			'app_id'                => ! empty( $config['app_id'] ) ? absint( $config['app_id'] ) : null,
			'app_name'              => $config['app_name'],
			'package_id'            => $config['package_id'],
			'platform'              => $platform,
			'ios_bundle_id'         => $config['ios_bundle_id'] ?? '',
			'ios_export_method'     => 'ios' === $platform ? $config['ios_export_method'] : '',
			'ios_upload_testflight' => $will_upload ? 1 : 0,
			'website_url'           => $config['website_url'],
			'version_name'          => $config['version_name'],
			'version_code'          => $config['version_code'],
			'build_type'            => $build_type,
			'build_variant'         => $config['build_variant'],
			'status'                => 'dispatching',
			'stage'                 => 'init',
			'config_snapshot'       => $config,
			'created_at'            => current_time( 'mysql' ),
			'started_at'            => current_time( 'mysql' ),
		);

		$record_id = BuildDb::create_build( $db_data );
		if ( ! $record_id ) {
			return array(
				'success' => false,
				'message' => __( 'Could not initialize build database record.', 'bizgrowth360-app-builder' ),
			);
		}

		$design_json   = wp_json_encode( $config['design'] ?? array() );
		$features_json = wp_json_encode( $config['features'] ?? array() );

		if ( 'ios' === $platform ) {
			$upload = $will_upload;

			$dispatch_inputs = array(
				'app_name'                 => (string) $config['app_name'],
				'package_id'               => (string) $config['package_id'],
				'ios_bundle_id'            => (string) $config['ios_bundle_id'],
				'website_url'              => (string) $config['website_url'],
				'version_name'             => (string) $config['version_name'],
				'version_code'             => (string) $config['version_code'],
				'ios_team_id'              => (string) $config['ios_team_id'],
				'ios_export_method'        => (string) $config['ios_export_method'],
				'ios_mode'                 => (string) $config['ios_mode'],
				'upload_to_testflight'     => $upload,
				'confirm_app_store_upload' => $upload,
				'macos_runner'             => (string) $config['macos_runner'],
				'orientation'              => (string) $config['orientation'],
				'primary_color'            => (string) $config['primary_color'],
				'background_color'         => (string) $config['background_color'],
				'splash_background_color'  => (string) ( $config['splash_background_color'] ?? '' ),
				'icon_url'                 => (string) ( $config['icon_url'] ?? '' ),
				'splash_url'               => (string) ( $config['splash_url'] ?? '' ),
				'allow_external_links'     => $config['allow_external_links'] ? 'true' : 'false',
				'enable_status_bar'        => $config['enable_status_bar'] ? 'true' : 'false',
				'design_json'              => (string) $design_json,
				'features_json'            => (string) $features_json,
				'config_file'              => 'config/default-app.json',
			);

			$dispatch_res = $this->github_client->dispatch_ios_build( $dispatch_inputs );
		} else {
			$dispatch_inputs = array(
				'app_name'                => (string) $config['app_name'],
				'package_id'              => (string) $config['package_id'],
				'website_url'             => (string) $config['website_url'],
				'version_name'            => (string) $config['version_name'],
				'version_code'            => (string) $config['version_code'],
				'build_type'              => (string) $config['build_variant'],
				'build_variant'           => (string) $config['build_variant'],
				'build_target'            => (string) $action,
				'orientation'             => (string) $config['orientation'],
				'primary_color'           => (string) $config['primary_color'],
				'background_color'        => (string) $config['background_color'],
				'splash_background_color' => (string) ( $config['splash_background_color'] ?? '' ),
				'icon_url'                => (string) ( $config['icon_url'] ?? '' ),
				'splash_url'              => (string) ( $config['splash_url'] ?? '' ),
				'allow_external_links'    => $config['allow_external_links'] ? 'true' : 'false',
				'enable_status_bar'       => $config['enable_status_bar'] ? 'true' : 'false',
				'design_json'             => (string) $design_json,
				'features_json'           => (string) $features_json,
				'config_file'             => 'config/default-app.json',
			);

			$dispatch_res = $this->github_client->dispatch_build( $dispatch_inputs );
		}

		if ( ! empty( $dispatch_res['success'] ) ) {
			BuildDb::update_build(
				$build_id,
				array(
					'status' => 'queued',
					'stage'  => 'init',
				)
			);

			return array(
				'success'  => true,
				'build_id' => $build_id,
				'platform' => $platform,
				'build'    => array(
					'build_id'  => $build_id,
					'platform'  => $platform,
					'build_type' => $build_type,
				),
				'message'  => 'ios' === $platform
					? __( 'iOS build accepted! The macOS runner is preparing the IPA build.', 'bizgrowth360-app-builder' )
					: __( 'Build request accepted! The cloud build engine is now preparing your Android packages.', 'bizgrowth360-app-builder' ),
			);
		}

		BuildDb::update_build(
			$build_id,
			array(
				'status'        => 'failed',
				'stage'         => 'init',
				'error_message' => $dispatch_res['message'] ?? 'Dispatch failed',
				'completed_at'  => current_time( 'mysql' ),
			)
		);

		return array(
			'success'  => false,
			'build_id' => $build_id,
			'platform' => $platform,
			'message'  => $dispatch_res['message'] ?? __( 'Failed to dispatch build to cloud runner.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Dispatch separate Android and iOS build records for "Build All Platforms".
	 *
	 * Both platforms are validated before either build is dispatched so a
	 * predictable validation error never leaves a half-started pair.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	private function start_all_platforms( $custom_inputs ) {
		$stored_options = $this->settings->get_options();
		$merged_inputs  = wp_parse_args( $custom_inputs, $stored_options );

		$android_validation = $this->validate_inputs( $merged_inputs, 'android' );
		$ios_validation     = $this->validate_inputs( $merged_inputs, 'ios' );

		$errors = array_merge( $android_validation['errors'], $ios_validation['errors'] );
		if ( ! empty( $errors ) ) {
			return array(
				'success' => false,
				'message' => implode( ' ', array_unique( $errors ) ),
				'errors'  => array_values( array_unique( $errors ) ),
			);
		}

		$design = $this->snapshot_design( $custom_inputs );
		if ( false === $design ) {
			return array(
				'success' => false,
				'message' => __( 'Design configuration is invalid or too large to dispatch.', 'bizgrowth360-app-builder' ),
			);
		}

		$features = $this->snapshot_features( $custom_inputs );

		$workspace = TenantManager::get_current_workspace();
		$workspace_id = $workspace ? (int) $workspace->id : 1;

		$android_action = $android_validation['sanitized']['build_target'];

		$android_config = $android_validation['sanitized'];
		$android_config['platform']     = 'android';
		$android_config['build_action'] = $android_action;
		$android_config['workspace_id'] = $workspace_id;
		$android_config['design']       = $design;
		$android_config['features']     = $features;

		$ios_config = $ios_validation['sanitized'];
		$ios_config['platform']     = 'ios';
		$ios_config['build_action'] = 'ios';
		$ios_config['workspace_id'] = $workspace_id;
		$ios_config['design']       = $design;
		$ios_config['features']     = $features;

		$android_result = $this->create_and_dispatch( $android_config, $android_action, 'android' );
		$ios_result     = $this->create_and_dispatch( $ios_config, 'ios', 'ios' );

		$builds = array();
		foreach ( array( $android_result, $ios_result ) as $result ) {
			if ( ! empty( $result['build'] ) ) {
				$builds[] = $result['build'];
			}
		}

		$success = ! empty( $android_result['success'] ) || ! empty( $ios_result['success'] );
		$partial = $success && ( empty( $android_result['success'] ) || empty( $ios_result['success'] ) );

		$messages = array();
		if ( ! empty( $android_result['success'] ) ) {
			$messages[] = __( 'Android build dispatched.', 'bizgrowth360-app-builder' );
		} else {
			$messages[] = sprintf( __( 'Android dispatch failed: %s', 'bizgrowth360-app-builder' ), $android_result['message'] ?? 'unknown error' );
		}
		if ( ! empty( $ios_result['success'] ) ) {
			$messages[] = __( 'iOS build dispatched.', 'bizgrowth360-app-builder' );
		} else {
			$messages[] = sprintf( __( 'iOS dispatch failed: %s', 'bizgrowth360-app-builder' ), $ios_result['message'] ?? 'unknown error' );
		}

		return array(
			'success' => $success,
			'partial' => $partial,
			'builds'  => $builds,
			'build_id' => ! empty( $builds ) ? $builds[0]['build_id'] : '',
			'message' => implode( ' ', $messages ),
			'config'  => $android_config,
		);
	}

	/**
	 * Synchronize and return the real-time status of a build.
	 *
	 * @param string $build_id
	 * @return array
	 */
	public function get_build_status( $build_id ) {
		$build = BuildDb::get_build( $build_id );
		if ( ! $build ) {
			return array(
				'success' => false,
				'message' => __( 'Build record not found.', 'bizgrowth360-app-builder' ),
			);
		}

		// If already in terminal state with artifacts, return cached.
		if ( in_array( $build->status, array( 'completed', 'failed', 'cancelled' ), true ) && ! empty( $build->artifacts ) ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		$workflow_file = 'ios' === $build->platform ? Config::get_ios_workflow_file() : Config::get_workflow_file();

		if ( empty( $build->run_id ) ) {
			$runs_res = $this->github_client->get_workflow_runs( 5, $workflow_file );
			if ( $runs_res['success'] && ! empty( $runs_res['runs'] ) ) {
				$started_ts = $build->started_at ? strtotime( $build->started_at ) : 0;
				foreach ( $runs_res['runs'] as $candidate ) {
					$created_ts = ! empty( $candidate['created_at'] ) ? strtotime( $candidate['created_at'] ) : 0;
					// Allow a generous clock skew between WordPress and GitHub.
					if ( ! $started_ts || $created_ts >= ( $started_ts - 180 ) ) {
						$build->run_id     = $candidate['id'];
						$build->run_number = $candidate['run_number'];
						BuildDb::update_build(
							$build_id,
							array(
								'run_id'     => $candidate['id'],
								'run_number' => $candidate['run_number'],
							)
						);
						break;
					}
				}
			}
		}

		if ( empty( $build->run_id ) ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		$run_data  = $this->github_client->get_run( $build->run_id );
		$jobs_data = $this->github_client->get_run_jobs( $build->run_id );

		if ( ! $run_data['success'] ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		$gh_run = $run_data['run'];
		$status = $gh_run['status'];
		$conc   = $gh_run['conclusion'];
		$stage  = 'init';

		if ( $jobs_data['success'] && ! empty( $jobs_data['jobs'] ) ) {
			$steps = $jobs_data['jobs'][0]['steps'] ?? array();
			foreach ( $steps as $step ) {
				$s_name = strtolower( $step['name'] ?? '' );
				$s_stat = $step['status'] ?? '';
				if ( 'in_progress' !== $s_stat && 'completed' !== $s_stat ) {
					continue;
				}
				if ( false !== strpos( $s_name, 'logs' ) || false !== strpos( $s_name, 'cleanup' ) || false !== strpos( $s_name, 'summary' ) ) {
					continue;
				}

				if ( false !== strpos( $s_name, 'testflight' ) || false !== strpos( $s_name, 'app store connect' ) ) {
					$stage = 'uploading';
				} elseif ( false !== strpos( $s_name, 'export' ) || false !== strpos( $s_name, 'ipa' ) ) {
					$stage = 'exporting';
				} elseif ( false !== strpos( $s_name, 'signing' ) || false !== strpos( $s_name, 'certificate' ) || false !== strpos( $s_name, 'keychain' ) || false !== strpos( $s_name, 'provisioning' ) || false !== strpos( $s_name, 'keystore' ) ) {
					$stage = 'signing';
				} elseif ( false !== strpos( $s_name, 'xcode' ) || false !== strpos( $s_name, 'build' ) || false !== strpos( $s_name, 'gradle' ) || false !== strpos( $s_name, 'archive' ) ) {
					$stage = 'building';
				} elseif ( false !== strpos( $s_name, 'node' ) || false !== strpos( $s_name, 'dependencies' ) || false !== strpos( $s_name, 'cocoapods' ) || false !== strpos( $s_name, 'sdk' ) || false !== strpos( $s_name, 'setup' ) || false !== strpos( $s_name, 'prepare' ) || false !== strpos( $s_name, 'xcode toolchain' ) ) {
					$stage = 'preparing';
				}
			}
		}

		$mapped_status = 'in_progress';
		if ( 'queued' === $status ) {
			$mapped_status = 'queued';
			$stage         = 'init';
		} elseif ( 'completed' === $status ) {
			$stage = 'artifacts';
			if ( 'success' === $conc ) {
				$mapped_status = 'completed';
			} elseif ( 'cancelled' === $conc ) {
				$mapped_status = 'cancelled';
			} else {
				$mapped_status = 'failed';
			}
		} else {
			if ( 'uploading' === $stage ) {
				$mapped_status = 'uploading';
			} elseif ( 'exporting' === $stage ) {
				$mapped_status = 'exporting';
			} elseif ( 'signing' === $stage ) {
				$mapped_status = 'signing';
			} elseif ( 'building' === $stage ) {
				$mapped_status = 'building';
			} elseif ( 'preparing' === $stage ) {
				$mapped_status = 'preparing';
			} else {
				$mapped_status = 'starting';
			}
		}

		$update_fields = array(
			'status'           => $mapped_status,
			'stage'            => $stage,
			'duration_seconds' => $gh_run['duration_sec'] ?? null,
		);

		if ( 'completed' === $mapped_status ) {
			$update_fields['completed_at'] = current_time( 'mysql' );
			$art_res = $this->github_client->get_run_artifacts( $build->run_id );
			if ( $art_res['success'] ) {
				$update_fields['artifacts'] = $art_res['artifacts'];
				$build->artifacts           = $art_res['artifacts'];
			}
		}

		BuildDb::update_build( $build_id, $update_fields );

		$build->status           = $mapped_status;
		$build->stage            = $stage;
		$build->duration_seconds = $gh_run['duration_sec'] ?? null;

		return array(
			'success' => true,
			'build'   => $build,
		);
	}

	/**
	 * Retry a failed or cancelled build using its immutable snapshot.
	 *
	 * App Store upload confirmation is deliberately never inherited by retry:
	 * every upload needs fresh explicit approval.
	 *
	 * @param string $build_id
	 * @return array
	 */
	public function retry_build( $build_id ) {
		$original = BuildDb::get_build( $build_id );
		if ( ! $original ) {
			return array(
				'success' => false,
				'message' => __( 'Original build record not found.', 'bizgrowth360-app-builder' ),
			);
		}

		$snapshot = $original->config_snapshot;
		$snapshot['parent_build_id'] = $build_id;

		if ( 'ios' === $original->platform ) {
			$snapshot['build_action'] = 'ios';
			$snapshot['platform']     = 'ios';
		} else {
			$snapshot['platform'] = 'android';
			$snapshot['build_action'] = $original->build_type;
		}

		$snapshot['confirm_app_store_upload'] = false;
		$snapshot['ios_upload_testflight']    = false;

		return $this->start_build( $snapshot );
	}

	/**
	 * Cancel an active build.
	 *
	 * @param string $build_id
	 * @return array
	 */
	public function cancel_build( $build_id ) {
		$build = BuildDb::get_build( $build_id );
		if ( ! $build ) {
			return array(
				'success' => false,
				'message' => __( 'Build record not found.', 'bizgrowth360-app-builder' ),
			);
		}

		if ( in_array( $build->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'This build is already stopped and cannot be cancelled.', 'bizgrowth360-app-builder' ),
			);
		}

		if ( ! empty( $build->run_id ) ) {
			$this->github_client->cancel_run( $build->run_id );
		}

		BuildDb::update_build(
			$build_id,
			array(
				'status'       => 'cancelled',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Build has been cancelled.', 'bizgrowth360-app-builder' ),
		);
	}
}
