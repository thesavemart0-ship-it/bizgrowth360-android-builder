<?php
/**
 * Android build lifecycle manager and orchestration engine.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuildManager {

	private $settings;
	private $github_client;

	public function __construct( Settings $settings, GitHubClient $github_client ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
	}

	/**
	 * Validate configuration inputs before starting a build.
	 *
	 * @param array $inputs
	 * @return array array( 'valid' => bool, 'errors' => array, 'sanitized' => array )
	 */
	public function validate_inputs( $inputs ) {
		$errors    = array();
		$sanitized = array();

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

		// Package ID
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
		$sanitized['version_name'] = $version_name ?: '1.0.0';

		// Version Code
		$version_code = absint( $inputs['version_code'] ?? 1 );
		if ( $version_code < 1 ) {
			$errors[] = __( 'Version Code must be a positive integer greater than or equal to 1.', 'bizgrowth360-app-builder' );
		}
		$sanitized['version_code'] = max( 1, $version_code );

		// Build Type
		$build_type = sanitize_key( $inputs['build_type'] ?? 'both' );
		if ( ! in_array( $build_type, array( 'apk', 'aab', 'both' ), true ) ) {
			$build_type = 'both';
		}
		$sanitized['build_type'] = $build_type;

		// Build Variant
		$build_variant = sanitize_key( $inputs['build_variant'] ?? 'release' );
		if ( ! in_array( $build_variant, array( 'release', 'debug' ), true ) ) {
			$build_variant = 'release';
		}
		$sanitized['build_variant'] = $build_variant;

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
					// Fallback to empty if not https
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

		return array(
			'valid'     => empty( $errors ),
			'errors'    => $errors,
			'sanitized' => $sanitized,
		);
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

		// Check concurrency: allow max 1 active build
		$active = BuildDb::get_active_build();
		if ( $active ) {
			return array(
				'success'  => false,
				'message'  => sprintf(
					/* translators: %s: active build ID */
					__( 'Another build (#%s) is currently in progress. Please wait for it to complete or cancel it before starting a new one.', 'bizgrowth360-app-builder' ),
					$active->build_id
				),
				'active_id' => $active->build_id,
			);
		}

		// Merge stored settings with submitted inputs
		$stored_options = $this->settings->get_options();
		$merged_inputs  = wp_parse_args( $custom_inputs, $stored_options );

		// Validate
		$val_result = $this->validate_inputs( $merged_inputs );
		if ( ! $val_result['valid'] ) {
			return array(
				'success' => false,
				'message' => implode( ' ', $val_result['errors'] ),
				'errors'  => $val_result['errors'],
			);
		}

		$config = $val_result['sanitized'];

		// Design Studio configuration is snapshotted with this build. Retries
		// reuse the original snapshot so a build is reproducible.
		$design = null;
		if ( isset( $custom_inputs['design'] ) && is_array( $custom_inputs['design'] ) ) {
			$design = $custom_inputs['design'];
		} else {
			$design_config = new DesignConfig();
			$design        = $design_config->get();
		}
		$design_json = wp_json_encode( $design );
		if ( false === $design_json || strlen( $design_json ) > 60000 ) {
			return array(
				'success' => false,
				'message' => __( 'Design configuration is invalid or too large to dispatch.', 'bizgrowth360-app-builder' ),
			);
		}
		$config['design'] = $design;

		// Native Features snapshot
		$features = null;
		if ( isset( $custom_inputs['features'] ) && is_array( $custom_inputs['features'] ) ) {
			$features = $custom_inputs['features'];
		} else {
			$features = NativeFeatures::get_features();
		}
		$features_json = wp_json_encode( $features );
		if ( false === $features_json || strlen( $features_json ) > 60000 ) {
			$features_json = '{}';
		}
		$config['features'] = $features;

		// Create unique build ID
		$build_id = 'bg360_bld_' . wp_generate_password( 12, false, false );

		// Create DB record with immutable configuration snapshot
		$db_data = array(
			'build_id'        => $build_id,
			'parent_build_id' => $custom_inputs['parent_build_id'] ?? null,
			'app_name'        => $config['app_name'],
			'package_id'      => $config['package_id'],
			'website_url'     => $config['website_url'],
			'version_name'    => $config['version_name'],
			'version_code'    => $config['version_code'],
			'build_type'      => $config['build_type'],
			'build_variant'   => $config['build_variant'],
			'status'          => 'dispatching',
			'stage'           => 'init',
			'config_snapshot' => $config,
			'created_at'      => current_time( 'mysql' ),
			'started_at'      => current_time( 'mysql' ),
		);

		$record_id = BuildDb::create_build( $db_data );
		if ( ! $record_id ) {
			return array(
				'success' => false,
				'message' => __( 'Could not initialize build database record.', 'bizgrowth360-app-builder' ),
			);
		}

		// Dispatch build via GitHub Actions API
		$dispatch_inputs = array(
			'app_name'               => (string) $config['app_name'],
			'package_id'             => (string) $config['package_id'],
			'website_url'            => (string) $config['website_url'],
			'version_name'           => (string) $config['version_name'],
			'version_code'           => (string) $config['version_code'],
			'build_type'             => (string) $config['build_type'],
			'build_variant'          => (string) $config['build_variant'],
			'orientation'            => (string) $config['orientation'],
			'primary_color'          => (string) $config['primary_color'],
			'background_color'       => (string) $config['background_color'],
			'splash_background_color' => (string) ( $config['splash_background_color'] ?? '' ),
			'icon_url'               => (string) ( $config['icon_url'] ?? '' ),
			'splash_url'             => (string) ( $config['splash_url'] ?? '' ),
			'allow_external_links'   => $config['allow_external_links'] ? 'true' : 'false',
			'enable_status_bar'      => $config['enable_status_bar'] ? 'true' : 'false',
			'design_json'            => $design_json,
			'features_json'          => $features_json,
			'config_file'            => 'config/default-app.json',
		);

		$dispatch_res = $this->github_client->dispatch_build( $dispatch_inputs );

		if ( $dispatch_res['success'] ) {
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
				'message'  => __( 'Build request accepted! The cloud build engine is now preparing your packages.', 'bizgrowth360-app-builder' ),
				'config'   => $config,
			);
		}

		// Dispatch failed
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
			'message'  => $dispatch_res['message'] ?? __( 'Failed to dispatch build to cloud runner.', 'bizgrowth360-app-builder' ),
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

		// If already in terminal state (completed, failed, cancelled) with artifacts, return cached
		if ( in_array( $build->status, array( 'completed', 'failed', 'cancelled' ), true ) && ! empty( $build->artifacts ) ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		// If no GitHub run_id is linked yet, attempt to find matching run
		if ( empty( $build->run_id ) ) {
			$runs_res = $this->github_client->get_workflow_runs( 5 );
			if ( $runs_res['success'] && ! empty( $runs_res['runs'] ) ) {
				// Match latest run created around or after this build's started_at
				$latest = $runs_res['runs'][0];
				$build->run_id     = $latest['id'];
				$build->run_number = $latest['run_number'];
				BuildDb::update_build(
					$build_id,
					array(
						'run_id'     => $latest['id'],
						'run_number' => $latest['run_number'],
					)
				);
			}
		}

		if ( empty( $build->run_id ) ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		// Fetch granular run status and jobs from GitHub
		$run_data  = $this->github_client->get_run( $build->run_id );
		$jobs_data = $this->github_client->get_run_jobs( $build->run_id );

		if ( ! $run_data['success'] ) {
			return array(
				'success' => true,
				'build'   => $build,
			);
		}

		$gh_run = $run_data['run'];
		$status = $gh_run['status'];      // queued, in_progress, completed
		$conc   = $gh_run['conclusion'];  // success, failure, cancelled, etc.
		$stage  = 'init';

		// Determine granular stage from job steps
		if ( $jobs_data['success'] && ! empty( $jobs_data['jobs'] ) ) {
			$steps = $jobs_data['jobs'][0]['steps'] ?? array();
			foreach ( $steps as $step ) {
				$s_name = strtolower( $step['name'] ?? '' );
				$s_stat = $step['status'] ?? '';
				if ( 'in_progress' === $s_stat || 'completed' === $s_stat ) {
					if ( false !== strpos( $s_name, 'keystore' ) || false !== strpos( $s_name, 'signing' ) ) {
						$stage = 'signing';
					} elseif ( false !== strpos( $s_name, 'build' ) || false !== strpos( $s_name, 'gradle' ) ) {
						$stage = 'building';
					} elseif ( false !== strpos( $s_name, 'dependencies' ) || false !== strpos( $s_name, 'setup' ) || false !== strpos( $s_name, 'prepare' ) ) {
						$stage = 'preparing';
					}
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
			// In progress: use fine-grained status
			if ( 'signing' === $stage ) {
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

		// If completed, fetch artifacts
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
	 * Retry a failed or cancelled build.
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
