<?php
/**
 * Internal GitHub Actions API Client.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubClient {

	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Normalize booleans that arrive from HTML forms, CLI flags or JSON.
	 *
	 * @param mixed $value
	 * @param bool  $default
	 * @return bool
	 */
	private function to_bool( $value, $default = true ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}
		$normalized = strtolower( trim( (string) $value ) );
		if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $normalized, array( '0', 'false', 'no', 'off', '' ), true ) ) {
			return false;
		}
		return $default;
	}

	/**
	 * Get authorization headers for GitHub API.
	 *
	 * @return array
	 */
	private function get_headers() {
		$token = Config::get_token();
		return array(
			'Authorization' => 'Bearer ' . trim( $token ),
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'Bizgrowth360-App-Builder/' . BIZGROWTH360_APP_BUILDER_VERSION,
		);
	}

	/**
	 * Verify if build engine is configured.
	 *
	 * @return array
	 */
	public function test_connection() {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'The Android build service is currently not configured. Please define BIZGROWTH360_GITHUB_TOKEN in wp-config.php.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		if ( empty( $owner ) || empty( $repo ) ) {
			return array(
				'success' => false,
				'message' => __( 'Build engine configuration is missing.', 'bizgrowth360-app-builder' ),
			);
		}

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Unable to reach the build service. Please check server connectivity.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status ) {
			return array(
				'success' => true,
				'message' => __( 'Build service connection is active and verified.', 'bizgrowth360-app-builder' ),
			);
		}

		return array(
			'success' => false,
			'status'  => $status,
			'message' => __( 'Build service authentication failed. Please verify BIZGROWTH360_GITHUB_TOKEN.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Dispatch a workflow run via workflow_dispatch.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	public function dispatch_build( $custom_inputs = array() ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'The Android build service is currently not configured. Please define BIZGROWTH360_GITHUB_TOKEN in wp-config.php.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner         = Config::get_github_owner();
		$repo          = Config::get_github_repo();
		$branch        = Config::get_github_branch();
		$workflow_file = Config::get_workflow_file();

		// Validate identifiers
		if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $owner ) || ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $repo ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid build engine repository configuration.', 'bizgrowth360-app-builder' ),
			);
		}

		$options = $this->settings->get_options();

		// Phase 3 workflow contract:
		//   build_type    = apk | aab | both
		//   build_variant = release | debug
		// The plugin UI historically used build_type=release/debug and
		// build_target=apk/aab/both. Map the legacy fields here.
		$legacy_build_type = (string) ( $custom_inputs['build_type'] ?? $options['build_type'] );
		$build_variant     = in_array( $legacy_build_type, array( 'release', 'debug' ), true )
			? $legacy_build_type
			: 'release';

		$legacy_build_target = (string) ( $custom_inputs['build_target'] ?? $options['build_target'] );
		$build_type          = in_array( $legacy_build_target, array( 'apk', 'aab', 'both' ), true )
			? $legacy_build_target
			: 'both';

		$orientation = (string) ( $custom_inputs['orientation'] ?? $options['orientation'] ?? 'portrait' );
		if ( ! in_array( $orientation, array( 'portrait', 'landscape', 'sensor', 'auto' ), true ) ) {
			$orientation = 'portrait';
		}

		$inputs = array(
			'app_name'               => (string) ( $custom_inputs['app_name'] ?? $options['app_name'] ),
			'package_id'             => (string) ( $custom_inputs['package_id'] ?? $options['package_id'] ),
			'website_url'            => (string) ( $custom_inputs['website_url'] ?? $options['website_url'] ),
			'version_name'           => (string) ( $custom_inputs['version_name'] ?? $options['version_name'] ),
			'version_code'           => (string) ( $custom_inputs['version_code'] ?? $options['version_code'] ),
			'build_type'             => (string) $build_type,
			'build_variant'          => (string) $build_variant,
			'orientation'            => (string) $orientation,
			'primary_color'          => (string) ( $custom_inputs['primary_color'] ?? $options['primary_color'] ?? '#2563EB' ),
			'background_color'       => (string) ( $custom_inputs['background_color'] ?? $options['background_color'] ?? '#FFFFFF' ),
			'splash_background_color' => (string) ( $custom_inputs['splash_background_color'] ?? $options['splash_background_color'] ?? '' ),
			'icon_url'               => (string) ( $custom_inputs['icon_url'] ?? $options['app_icon'] ?? '' ),
			'splash_url'             => (string) ( $custom_inputs['splash_url'] ?? $options['splash_image'] ?? '' ),
			'allow_external_links'   => $this->to_bool( $custom_inputs['allow_external_links'] ?? ( $options['allow_external_links'] ?? true ), true ) ? 'true' : 'false',
			'enable_status_bar'      => $this->to_bool( $custom_inputs['enable_status_bar'] ?? ( $options['enable_status_bar'] ?? true ), true ) ? 'true' : 'false',
			'design_json'            => (string) ( $custom_inputs['design_json'] ?? '' ),
			'features_json'          => (string) ( $custom_inputs['features_json'] ?? '' ),
			'config_file'            => 'config/default-app.json',
		);

		$legacy_inputs = array(
			'app_name'     => (string) $inputs['app_name'],
			'package_id'   => (string) $inputs['package_id'],
			'website_url'  => (string) $inputs['website_url'],
			'version_name' => (string) $inputs['version_name'],
			'version_code' => (string) $inputs['version_code'],
			'build_type'   => (string) $legacy_build_type,
			'build_target' => (string) $legacy_build_target,
			'config_file'  => 'config/default-app.json',
		);

		$url          = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/workflows/{$workflow_file}/dispatches";
		$last_status  = 0;
		$last_inputs  = array();
		$contract     = 'phase3';

		// Try the Phase 3 contract first. If GitHub rejects it with HTTP 422
		// (old workflow on main without the new inputs), retry once with the
		// Phase 2 contract. A 422 means no workflow run was started.
		foreach ( array( $inputs, $legacy_inputs ) as $index => $attempt_inputs ) {
			$contract    = 0 === $index ? 'phase3' : 'legacy';
			$last_inputs = $attempt_inputs;
			$response    = wp_remote_post(
				$url,
				array(
					'headers' => array_merge( $this->get_headers(), array( 'Content-Type' => 'application/json' ) ),
					'body'    => wp_json_encode(
						array(
							'ref'    => $branch,
							'inputs' => $attempt_inputs,
						)
					),
					'timeout' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => __( 'Network error while contacting the build service.', 'bizgrowth360-app-builder' ),
				);
			}

			$last_status = wp_remote_retrieve_response_code( $response );
			if ( 204 === $last_status ) {
				return array(
					'success'   => true,
					'message'   => __( 'Build request accepted! The cloud compiler is now preparing your Android packages.', 'bizgrowth360-app-builder' ),
					'timestamp' => time(),
					'contract'  => $contract,
					'inputs'    => $attempt_inputs,
				);
			}
			if ( 422 !== $last_status ) {
				break;
			}
		}

		return array(
			'success' => false,
			'status'  => $last_status,
			'contract' => $contract,
			'inputs'  => $last_inputs,
			'message' => __( 'Unable to dispatch build request. Please try again or verify build service configuration.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Dispatch an iOS workflow run via workflow_dispatch.
	 *
	 * Only non-sensitive configuration is sent. Certificates, provisioning
	 * profiles and App Store Connect keys remain GitHub Actions secrets.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	public function dispatch_ios_build( $custom_inputs = array() ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'The build service is not configured. Please define BIZGROWTH360_GITHUB_TOKEN in wp-config.php.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner         = Config::get_github_owner();
		$repo          = Config::get_github_repo();
		$branch        = Config::get_github_branch();
		$workflow_file = Config::get_ios_workflow_file();

		if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $owner ) || ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $repo ) || ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $workflow_file ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid iOS build workflow configuration.', 'bizgrowth360-app-builder' ),
			);
		}

		$options = $this->settings->get_options();

		$inputs = array(
			'app_name'                 => (string) ( $custom_inputs['app_name'] ?? $options['app_name'] ),
			'package_id'               => (string) ( $custom_inputs['package_id'] ?? $options['package_id'] ),
			'ios_bundle_id'            => (string) ( $custom_inputs['ios_bundle_id'] ?? $options['ios_bundle_id'] ),
			'website_url'              => (string) ( $custom_inputs['website_url'] ?? $options['website_url'] ),
			'version_name'             => (string) ( $custom_inputs['version_name'] ?? $options['version_name'] ),
			'version_code'             => (string) ( $custom_inputs['version_code'] ?? $options['version_code'] ),
			'ios_team_id'              => (string) ( $custom_inputs['ios_team_id'] ?? $options['ios_team_id'] ),
			'ios_export_method'        => (string) ( $custom_inputs['ios_export_method'] ?? $options['ios_export_method'] ),
			'ios_mode'                 => (string) ( $custom_inputs['ios_mode'] ?? $options['ios_mode'] ),
			'upload_to_testflight'     => $this->to_bool( $custom_inputs['upload_to_testflight'] ?? false, false ) ? 'true' : 'false',
			'confirm_app_store_upload' => $this->to_bool( $custom_inputs['confirm_app_store_upload'] ?? false, false ) ? 'true' : 'false',
			'macos_runner'             => (string) ( $custom_inputs['macos_runner'] ?? $options['macos_runner'] ),
			'orientation'              => (string) ( $custom_inputs['orientation'] ?? $options['orientation'] ),
			'primary_color'            => (string) ( $custom_inputs['primary_color'] ?? $options['primary_color'] ),
			'background_color'         => (string) ( $custom_inputs['background_color'] ?? $options['background_color'] ),
			'splash_background_color'  => (string) ( $custom_inputs['splash_background_color'] ?? '' ),
			'icon_url'                 => (string) ( $custom_inputs['icon_url'] ?? '' ),
			'splash_url'               => (string) ( $custom_inputs['splash_url'] ?? '' ),
			'allow_external_links'     => $this->to_bool( $custom_inputs['allow_external_links'] ?? ( $options['allow_external_links'] ?? true ), true ) ? 'true' : 'false',
			'enable_status_bar'        => $this->to_bool( $custom_inputs['enable_status_bar'] ?? ( $options['enable_status_bar'] ?? true ), true ) ? 'true' : 'false',
			'design_json'              => (string) ( $custom_inputs['design_json'] ?? '' ),
			'features_json'            => (string) ( $custom_inputs['features_json'] ?? '' ),
			'config_file'              => 'config/default-app.json',
		);

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/workflows/{$workflow_file}/dispatches";
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array_merge( $this->get_headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode(
					array(
						'ref'    => $branch,
						'inputs' => $inputs,
					)
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Network error while contacting the iOS build service.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 204 === $status ) {
			return array(
				'success'   => true,
				'message'   => __( 'iOS build request accepted. The macOS runner is preparing the IPA build.', 'bizgrowth360-app-builder' ),
				'timestamp' => time(),
				'contract'  => 'ios-phase7',
				'inputs'    => $inputs,
			);
		}

		$body_message = '';
		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			$body_message = ' ' . sanitize_text_field( $body['message'] );
		}

		return array(
			'success'  => false,
			'status'   => $status,
			'contract' => 'ios-phase7',
			'inputs'   => $inputs,
			'message'  => sprintf(
				/* translators: %d: HTTP status code */
				__( 'Unable to dispatch the iOS build request (HTTP %d). Verify the ios-build.yml workflow and GitHub secrets.%s', 'bizgrowth360-app-builder' ),
				$status,
				$body_message
			),
		);
	}

	/**
	 * Retrieve latest workflow runs.
	 *
	 * @param int    $limit
	 * @param string $workflow_file Optional workflow file to filter by.
	 * @return array
	 */
	public function get_workflow_runs( $limit = 5, $workflow_file = '' ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$runs_path = "/repos/{$owner}/{$repo}/actions/runs";
		if ( ! empty( $workflow_file ) ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $workflow_file ) ) {
				return array(
					'success' => false,
					'message' => __( 'Invalid build workflow identifier.', 'bizgrowth360-app-builder' ),
				);
			}
			$runs_path = "/repos/{$owner}/{$repo}/actions/workflows/{$workflow_file}/runs";
		}

		$url      = Config::get_api_base() . $runs_path . '?per_page=' . absint( $limit );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not retrieve build history.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && isset( $body['workflow_runs'] ) ) {
			$runs = array();
			foreach ( $body['workflow_runs'] as $run ) {
				$runs[] = array(
					'id'           => $run['id'],
					'run_number'   => $run['run_number'],
					'name'         => 'Build #' . $run['run_number'],
					'event'        => $run['event'],
					'status'       => $run['status'],
					'conclusion'   => $run['conclusion'],
					'created_at'   => $run['created_at'],
					'updated_at'   => $run['updated_at'],
					'duration_sec' => strtotime( $run['updated_at'] ) - strtotime( $run['run_started_at'] ),
				);
			}

			return array(
				'success' => true,
				'total'   => $body['total_count'],
				'runs'    => $runs,
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to fetch build runs from service.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Fetch artifacts for a specific workflow run.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function get_run_artifacts( $run_id ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/runs/{$run_id}/artifacts";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not retrieve build packages.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && isset( $body['artifacts'] ) ) {
			$artifacts = array();
			foreach ( $body['artifacts'] as $art ) {
				$artifacts[] = array(
					'id'           => $art['id'],
					'name'         => $art['name'],
					'size_bytes'   => $art['size_in_bytes'],
					'size_mb'      => round( $art['size_in_bytes'] / ( 1024 * 1024 ), 2 ) . ' MB',
					'download_url' => $art['archive_download_url'],
					'created_at'   => $art['created_at'],
					'expired'      => $art['expired'],
				);
			}

			return array(
				'success'   => true,
				'artifacts' => $artifacts,
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Could not retrieve artifacts for this build.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Get the direct pre-signed download URL for an artifact.
	 *
	 * @param int $artifact_id
	 * @return string|false
	 */
	public function get_artifact_direct_url( $artifact_id ) {
		if ( ! Config::has_token() ) {
			return false;
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/artifacts/{$artifact_id}/zip";
		$response = wp_remote_get(
			$url,
			array(
				'headers'     => $this->get_headers(),
				'timeout'     => 15,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$headers  = wp_remote_retrieve_headers( $response );
		$location = isset( $headers['location'] ) ? $headers['location'] : false;

		if ( 302 === $status && ! empty( $location ) ) {
			return $location;
		}

		return false;
	}

	/**
	 * Retrieve a single workflow run by ID.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function get_run( $run_id ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/runs/{$run_id}";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not reach build service.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && isset( $body['id'] ) ) {
			return array(
				'success' => true,
				'run'     => array(
					'id'           => $body['id'],
					'run_number'   => $body['run_number'],
					'status'       => $body['status'],
					'conclusion'   => $body['conclusion'],
					'created_at'   => $body['created_at'],
					'updated_at'   => $body['updated_at'],
					'duration_sec' => strtotime( $body['updated_at'] ) - strtotime( $body['run_started_at'] ),
				),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Build run not found.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Retrieve jobs and steps for a specific workflow run.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function get_run_jobs( $run_id ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/runs/{$run_id}/jobs";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not fetch job steps.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && isset( $body['jobs'] ) ) {
			return array(
				'success' => true,
				'jobs'    => $body['jobs'],
			);
		}

		return array(
			'success' => false,
			'message' => __( 'No job information found for run.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Fetch plain text build logs for a job or run.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function get_build_logs( $run_id ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		// First get the primary job ID for this run
		$jobs_data = $this->get_run_jobs( $run_id );
		if ( ! $jobs_data['success'] || empty( $jobs_data['jobs'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Build job logs are not available yet.', 'bizgrowth360-app-builder' ),
			);
		}

		$job    = $jobs_data['jobs'][0];
		$job_id = $job['id'];
		$owner  = Config::get_github_owner();
		$repo   = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/jobs/{$job_id}/logs";
		$response = wp_remote_get(
			$url,
			array(
				'headers'     => $this->get_headers(),
				'timeout'     => 15,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not download build logs.', 'bizgrowth360-app-builder' ),
			);
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$headers  = wp_remote_retrieve_headers( $response );
		$location = isset( $headers['location'] ) ? $headers['location'] : false;

		$body = '';
		if ( 302 === $status && ! empty( $location ) ) {
			// Pre-signed cloud storage URL (Azure Blob / AWS S3) must be called WITHOUT the GitHub Bearer Authorization header
			$log_response = wp_remote_get(
				$location,
				array(
					'timeout'     => 25,
					'redirection' => 5,
				)
			);

			if ( ! is_wp_error( $log_response ) && 200 === wp_remote_retrieve_response_code( $log_response ) ) {
				$body = wp_remote_retrieve_body( $log_response );
			}
		} elseif ( 200 === $status ) {
			$body = wp_remote_retrieve_body( $response );
		}

		if ( ! empty( $body ) ) {
			// Limit to last 500KB or ~1000 lines to prevent memory issues
			if ( strlen( $body ) > 500000 ) {
				$body = "... [log truncated for display] ...\n" . substr( $body, -500000 );
			}

			// Mask potential sensitive data (PAT tokens, Apple keys, passwords)
			$body = preg_replace( '/ghp_[a-zA-Z0-9]{36}/', 'ghp_************************************', $body );
			$body = preg_replace( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[REDACTED PRIVATE KEY]', $body );
			$body = preg_replace( '/\b[A-Za-z0-9+\/]{200,}={0,2}\b/', '[REDACTED BASE64 BLOB]', $body );
			$body = preg_replace( '/[a-zA-Z0-9_\-\.]+@[a-zA-Z0-9_\-\.]+\.[a-zA-Z]{2,}/', '***@***.***', $body );

			return array(
				'success'  => true,
				'job_id'   => $job_id,
				'job_name' => $job['name'] ?? 'build',
				'logs'     => $body,
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Logs are not available yet or the job is still initializing.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Cancel an in-progress workflow run.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function cancel_run( $run_id ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/runs/{$run_id}/cancel";
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to contact build service for cancellation.', 'bizgrowth360-app-builder' ),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 202 === $status || 200 === $status ) {
			return array(
				'success' => true,
				'message' => __( 'Cancellation request accepted by build service.', 'bizgrowth360-app-builder' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Could not cancel the build run. It may have already completed or stopped.', 'bizgrowth360-app-builder' ),
		);
	}
}
