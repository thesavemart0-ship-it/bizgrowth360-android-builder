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
			'build_type'             => $build_type,
			'build_variant'          => $build_variant,
			'orientation'            => $orientation,
			'primary_color'          => (string) ( $options['primary_color'] ?? '#2563EB' ),
			'background_color'       => (string) ( $options['background_color'] ?? '#FFFFFF' ),
			'splash_background_color' => (string) ( $options['splash_background_color'] ?? '' ),
			'icon_url'               => (string) ( $options['app_icon'] ?? '' ),
			'splash_url'             => (string) ( $options['splash_image'] ?? '' ),
			'allow_external_links'   => ! empty( $options['allow_external_links'] ) ? 'true' : 'false',
			'enable_status_bar'      => 'true',
			'config_file'            => 'config/default-app.json',
		);

		$legacy_inputs = array(
			'app_name'     => $inputs['app_name'],
			'package_id'   => $inputs['package_id'],
			'website_url'  => $inputs['website_url'],
			'version_name' => $inputs['version_name'],
			'version_code' => $inputs['version_code'],
			'build_type'   => $legacy_build_type,
			'build_target' => $legacy_build_target,
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
	 * Retrieve latest workflow runs.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_workflow_runs( $limit = 5 ) {
		if ( ! Config::has_token() ) {
			return array(
				'success' => false,
				'message' => __( 'Build service token is not configured.', 'bizgrowth360-app-builder' ),
			);
		}

		$owner = Config::get_github_owner();
		$repo  = Config::get_github_repo();

		$url      = Config::get_api_base() . "/repos/{$owner}/{$repo}/actions/runs?per_page={$limit}";
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
}
