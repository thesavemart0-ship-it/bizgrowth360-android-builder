<?php
/**
 * GitHub Actions API Client.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubClient {

	private $settings;
	private $api_base = 'https://api.github.com';

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get authorization headers for GitHub API.
	 *
	 * @return array
	 */
	private function get_headers() {
		$token = $this->settings->get( 'github_token' );
		return array(
			'Authorization' => 'Bearer ' . trim( $token ),
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'Bizgrowth360-App-Builder/' . BIZGROWTH360_APP_BUILDER_VERSION,
		);
	}

	/**
	 * Test GitHub connection and repository access.
	 *
	 * @return array
	 */
	public function test_connection() {
		$owner = $this->settings->get( 'github_owner' );
		$repo  = $this->settings->get( 'github_repo' );

		if ( empty( $owner ) || empty( $repo ) ) {
			return array(
				'success' => false,
				'message' => __( 'GitHub repository owner and name are required.', 'bizgrowth360-app-builder' ),
			);
		}

		$url      = "{$this->api_base}/repos/{$owner}/{$repo}";
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
				'message' => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status ) {
			return array(
				'success'    => true,
				'repo_name'  => $body['full_name'],
				'is_private' => $body['private'],
				'default_branch' => $body['default_branch'],
				'html_url'   => $body['html_url'],
				'message'    => __( 'Connected to GitHub repository successfully!', 'bizgrowth360-app-builder' ),
			);
		}

		return array(
			'success' => false,
			'status'  => $status,
			'message' => isset( $body['message'] ) ? $body['message'] : __( 'Failed connecting to GitHub API.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Dispatch a workflow run via workflow_dispatch.
	 *
	 * @param array $custom_inputs
	 * @return array
	 */
	public function dispatch_build( $custom_inputs = array() ) {
		$owner         = $this->settings->get( 'github_owner' );
		$repo          = $this->settings->get( 'github_repo' );
		$branch        = $this->settings->get( 'github_branch', 'main' );
		$workflow_file = $this->settings->get( 'workflow_file', 'android-build.yml' );

		$options = $this->settings->get_options();
		$inputs  = array(
			'app_name'     => (string) ( $custom_inputs['app_name'] ?? $options['app_name'] ),
			'package_id'   => (string) ( $custom_inputs['package_id'] ?? $options['package_id'] ),
			'website_url'  => (string) ( $custom_inputs['website_url'] ?? $options['website_url'] ),
			'version_name' => (string) ( $custom_inputs['version_name'] ?? $options['version_name'] ),
			'version_code' => (string) ( $custom_inputs['version_code'] ?? $options['version_code'] ),
			'build_type'   => (string) ( $custom_inputs['build_type'] ?? $options['build_type'] ),
			'build_target' => (string) ( $custom_inputs['build_target'] ?? $options['build_target'] ),
		);

		$payload = array(
			'ref'    => $branch,
			'inputs' => $inputs,
		);

		$url      = "{$this->api_base}/repos/{$owner}/{$repo}/actions/workflows/{$workflow_file}/dispatches";
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array_merge( $this->get_headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 204 === $status ) {
			return array(
				'success'   => true,
				'message'   => __( 'Build dispatched successfully! GitHub Actions is now compiling your Android packages.', 'bizgrowth360-app-builder' ),
				'timestamp' => time(),
				'inputs'    => $inputs,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'success' => false,
			'status'  => $status,
			'message' => isset( $body['message'] ) ? $body['message'] : __( 'Failed to dispatch workflow run.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Retrieve latest workflow runs.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_workflow_runs( $limit = 5 ) {
		$owner = $this->settings->get( 'github_owner' );
		$repo  = $this->settings->get( 'github_repo' );

		$url      = "{$this->api_base}/repos/{$owner}/{$repo}/actions/runs?per_page={$limit}";
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
				'message' => $response->get_error_message(),
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
					'name'         => $run['name'],
					'event'        => $run['event'],
					'status'       => $run['status'],
					'conclusion'   => $run['conclusion'],
					'html_url'     => $run['html_url'],
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
			'message' => isset( $body['message'] ) ? $body['message'] : __( 'Failed to fetch workflow runs.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Fetch artifacts for a specific workflow run.
	 *
	 * @param int $run_id
	 * @return array
	 */
	public function get_run_artifacts( $run_id ) {
		$owner = $this->settings->get( 'github_owner' );
		$repo  = $this->settings->get( 'github_repo' );

		$url      = "{$this->api_base}/repos/{$owner}/{$repo}/actions/runs/{$run_id}/artifacts";
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
				'message' => $response->get_error_message(),
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
			'message' => __( 'Could not retrieve artifacts for this run.', 'bizgrowth360-app-builder' ),
		);
	}
}

