<?php
/**
 * AJAX endpoints handler.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxHandler {

	private $settings;
	private $github_client;

	public function __construct( Settings $settings, GitHubClient $github_client ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
	}

	public function init() {
		add_action( 'wp_ajax_bizgrowth360_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_bizgrowth360_trigger_build', array( $this, 'ajax_trigger_build' ) );
		add_action( 'wp_ajax_bizgrowth360_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_bizgrowth360_get_history', array( $this, 'ajax_get_history' ) );
	}

	private function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized user access.', 'bizgrowth360-app-builder' ) ) );
		}
		check_ajax_referer( 'bizgrowth360_app_builder_nonce', 'nonce' );
	}

	public function ajax_test_connection() {
		$this->check_permissions();
		$result = $this->github_client->test_connection();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function ajax_trigger_build() {
		$this->check_permissions();

		$inputs = array();
		if ( isset( $_POST['app_name'] ) ) {
			$inputs['app_name'] = sanitize_text_field( wp_unslash( $_POST['app_name'] ) );
		}
		if ( isset( $_POST['package_id'] ) ) {
			$inputs['package_id'] = sanitize_text_field( wp_unslash( $_POST['package_id'] ) );
		}
		if ( isset( $_POST['website_url'] ) ) {
			$inputs['website_url'] = esc_url_raw( wp_unslash( $_POST['website_url'] ) );
		}
		if ( isset( $_POST['version_name'] ) ) {
			$inputs['version_name'] = sanitize_text_field( wp_unslash( $_POST['version_name'] ) );
		}
		if ( isset( $_POST['version_code'] ) ) {
			$inputs['version_code'] = absint( $_POST['version_code'] );
		}
		if ( isset( $_POST['build_type'] ) ) {
			$inputs['build_type'] = sanitize_text_field( wp_unslash( $_POST['build_type'] ) );
		}
		if ( isset( $_POST['build_target'] ) ) {
			$inputs['build_target'] = sanitize_text_field( wp_unslash( $_POST['build_target'] ) );
		}

		$result = $this->github_client->dispatch_build( $inputs );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function ajax_check_status() {
		$this->check_permissions();

		$runs_data = $this->github_client->get_workflow_runs( 3 );
		if ( ! $runs_data['success'] || empty( $runs_data['runs'] ) ) {
			wp_send_json_error( $runs_data );
		}

		$latest = $runs_data['runs'][0];
		$artifacts = array();

		if ( 'completed' === $latest['status'] && 'success' === $latest['conclusion'] ) {
			$artifacts_data = $this->github_client->get_run_artifacts( $latest['id'] );
			if ( $artifacts_data['success'] ) {
				$artifacts = $artifacts_data['artifacts'];
			}
		}

		wp_send_json_success(
			array(
				'run'       => $latest,
				'artifacts' => $artifacts,
			)
		);
	}

	public function ajax_get_history() {
		$this->check_permissions();
		$result = $this->github_client->get_workflow_runs( 10 );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
