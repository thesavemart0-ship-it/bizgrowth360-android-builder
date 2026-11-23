<?php
/**
 * AJAX endpoints handler for Bizgrowth360 App Builder.
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
	private $build_manager;

	public function __construct( Settings $settings, GitHubClient $github_client, BuildManager $build_manager ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
		$this->build_manager = $build_manager;
	}

	public function init() {
		add_action( 'wp_ajax_bizgrowth360_validate_build', array( $this, 'ajax_validate_build' ) );
		add_action( 'wp_ajax_bizgrowth360_start_build', array( $this, 'ajax_start_build' ) );
		add_action( 'wp_ajax_bizgrowth360_trigger_build', array( $this, 'ajax_start_build' ) ); // Backward compatibility
		add_action( 'wp_ajax_bizgrowth360_get_build_status', array( $this, 'ajax_get_build_status' ) );
		add_action( 'wp_ajax_bizgrowth360_check_status', array( $this, 'ajax_get_build_status' ) ); // Backward compatibility
		add_action( 'wp_ajax_bizgrowth360_get_builds', array( $this, 'ajax_get_builds' ) );
		add_action( 'wp_ajax_bizgrowth360_get_history', array( $this, 'ajax_get_builds' ) ); // Backward compatibility
		add_action( 'wp_ajax_bizgrowth360_get_build_logs', array( $this, 'ajax_get_build_logs' ) );
		add_action( 'wp_ajax_bizgrowth360_retry_build', array( $this, 'ajax_retry_build' ) );
		add_action( 'wp_ajax_bizgrowth360_cancel_build', array( $this, 'ajax_cancel_build' ) );
		add_action( 'wp_ajax_bizgrowth360_save_design', array( $this, 'ajax_save_design' ) );
		add_action( 'wp_ajax_bizgrowth360_reset_design', array( $this, 'ajax_reset_design' ) );
		add_action( 'wp_ajax_bizgrowth360_save_native_features', array( $this, 'ajax_save_native_features' ) );
		add_action( 'wp_ajax_bizgrowth360_get_native_features', array( $this, 'ajax_get_native_features' ) );
		add_action( 'wp_ajax_bizgrowth360_send_test_push', array( $this, 'ajax_send_test_push' ) );
		add_action( 'wp_ajax_bizgrowth360_generate_assetlinks', array( $this, 'ajax_generate_assetlinks' ) );
	}

	private function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized user access.', 'bizgrowth360-app-builder' ) ), 403 );
		}
		check_ajax_referer( 'bizgrowth360_app_builder_nonce', 'nonce' );
	}

	/**
	 * Validate configuration and return pre-build confirmation summary.
	 */
	public function ajax_validate_build() {
		$this->check_permissions();

		$raw_inputs = wp_unslash( $_POST );
		$validation = $this->build_manager->validate_inputs( $raw_inputs );

		if ( ! $validation['valid'] ) {
			wp_send_json_error(
				array(
					'message' => implode( "\n", $validation['errors'] ),
					'errors'  => $validation['errors'],
				)
			);
		}

		wp_send_json_success(
			array(
				'valid'     => true,
				'summary'   => $validation['sanitized'],
				'has_token' => Config::has_token(),
			)
		);
	}

	/**
	 * Start a new Android build.
	 */
	public function ajax_start_build() {
		$this->check_permissions();

		$raw_inputs = wp_unslash( $_POST );
		$result     = $this->build_manager->start_build( $raw_inputs );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Poll status of a specific build or active build.
	 */
	public function ajax_get_build_status() {
		$this->check_permissions();

		$build_id = isset( $_REQUEST['build_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['build_id'] ) ) : '';

		if ( empty( $build_id ) ) {
			$active = BuildDb::get_active_build();
			if ( $active ) {
				$build_id = $active->build_id;
			}
		}

		if ( empty( $build_id ) ) {
			// Fallback: check latest GitHub workflow runs
			$runs_data = $this->github_client->get_workflow_runs( 3 );
			if ( ! $runs_data['success'] || empty( $runs_data['runs'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No active build found.', 'bizgrowth360-app-builder' ) ) );
			}

			$latest = $runs_data['runs'][0];
			$artifacts = array();
			if ( 'completed' === $latest['status'] && 'success' === $latest['conclusion'] ) {
				$art_res = $this->github_client->get_run_artifacts( $latest['id'] );
				if ( $art_res['success'] ) {
					$artifacts = $art_res['artifacts'];
				}
			}

			wp_send_json_success(
				array(
					'run'       => $latest,
					'artifacts' => $artifacts,
				)
			);
		}

		$status_res = $this->build_manager->get_build_status( $build_id );
		if ( $status_res['success'] ) {
			wp_send_json_success( $status_res );
		} else {
			wp_send_json_error( $status_res );
		}
	}

	/**
	 * Get build history with filtering, searching, and pagination.
	 */
	public function ajax_get_builds() {
		$this->check_permissions();

		$args = array(
			'search'     => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'status'     => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'build_type' => isset( $_POST['build_type'] ) ? sanitize_text_field( wp_unslash( $_POST['build_type'] ) ) : '',
			'platform'   => isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '',
			'page'       => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
			'per_page'   => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10,
		);

		if ( ! in_array( $args['platform'], array( '', 'all', 'android', 'ios' ), true ) ) {
			$args['platform'] = '';
		}

		$db_builds = BuildDb::get_builds( $args );

		// If DB has records, return them
		if ( ! empty( $db_builds['total'] ) ) {
			wp_send_json_success( $db_builds );
		}

		// Fallback for first-time use or existing GitHub runs
		$runs_data = $this->github_client->get_workflow_runs( 10 );
		if ( $runs_data['success'] && ! empty( $runs_data['runs'] ) ) {
			wp_send_json_success(
				array(
					'items'    => array(),
					'runs'     => $runs_data['runs'],
					'total'    => $runs_data['total'],
					'page'     => 1,
					'per_page' => 10,
					'pages'    => 1,
				)
			);
		}

		wp_send_json_success(
			array(
				'items'    => array(),
				'runs'     => array(),
				'total'    => 0,
				'page'     => 1,
				'per_page' => 10,
				'pages'    => 0,
			)
		);
	}

	/**
	 * Fetch build logs.
	 */
	public function ajax_get_build_logs() {
		$this->check_permissions();

		$run_id   = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		$build_id = isset( $_POST['build_id'] ) ? sanitize_text_field( wp_unslash( $_POST['build_id'] ) ) : '';

		if ( ! $run_id && ! empty( $build_id ) ) {
			$build = BuildDb::get_build( $build_id );
			if ( $build && ! empty( $build->run_id ) ) {
				$run_id = $build->run_id;
			}
		}

		if ( ! $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Run ID is required to fetch logs.', 'bizgrowth360-app-builder' ) ) );
		}

		$logs_res = $this->github_client->get_build_logs( $run_id );
		if ( $logs_res['success'] ) {
			wp_send_json_success( $logs_res );
		} else {
			wp_send_json_error( $logs_res );
		}
	}

	/**
	 * Retry a failed build.
	 */
	public function ajax_retry_build() {
		$this->check_permissions();

		$build_id = isset( $_POST['build_id'] ) ? sanitize_text_field( wp_unslash( $_POST['build_id'] ) ) : '';
		if ( empty( $build_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Build ID is required to retry.', 'bizgrowth360-app-builder' ) ) );
		}

		$result = $this->build_manager->retry_build( $build_id );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Cancel an in-progress build.
	 */
	public function ajax_cancel_build() {
		$this->check_permissions();

		$build_id = isset( $_POST['build_id'] ) ? sanitize_text_field( wp_unslash( $_POST['build_id'] ) ) : '';
		if ( empty( $build_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Build ID is required to cancel.', 'bizgrowth360-app-builder' ) ) );
		}

		$result = $this->build_manager->cancel_build( $build_id );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Persist the App Design Studio configuration.
	 */
	public function ajax_save_design() {
		$this->check_permissions();

		$raw = isset( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			wp_send_json_error( array( 'message' => __( 'Design configuration is required.', 'bizgrowth360-app-builder' ) ) );
		}
		if ( strlen( $raw ) > 200000 ) {
			wp_send_json_error( array( 'message' => __( 'Design configuration is too large.', 'bizgrowth360-app-builder' ) ) );
		}

		$design = json_decode( $raw, true );
		if ( ! is_array( $design ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid design JSON.', 'bizgrowth360-app-builder' ) ) );
		}

		$design_access = \Bizgrowth360\AppBuilder\Billing\Entitlements::assert_design( \Bizgrowth360\AppBuilder\Billing\Tenant::current_id(), $design );
		if ( is_wp_error( $design_access ) ) {
			wp_send_json_error( array( 'message' => $design_access->get_error_message(), 'code' => $design_access->get_error_code() ), 403 );
		}

		$config = new DesignConfig();
		$saved  = $config->save( $design );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'design'   => $saved,
				'saved_at' => (int) get_option( DesignConfig::SAVED_AT_KEY, time() ),
				'message'  => __( 'Design saved successfully.', 'bizgrowth360-app-builder' ),
			)
		);
	}

	/**
	 * Reset one design section or the complete design.
	 */
	public function ajax_reset_design() {
		$this->check_permissions();

		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$config  = new DesignConfig();
		$saved   = $config->reset( $section );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'design'   => $saved,
				'saved_at' => (int) get_option( DesignConfig::SAVED_AT_KEY, time() ),
				'message'  => $section
					? __( 'Design section reset successfully.', 'bizgrowth360-app-builder' )
					: __( 'Entire design reset successfully.', 'bizgrowth360-app-builder' ),
			)
		);
	}

	/**
	 * Persist Native Features configuration.
	 */
	public function ajax_save_native_features() {
		$this->check_permissions();

		$raw = isset( $_POST['features'] ) ? wp_unslash( $_POST['features'] ) : '';
		if ( is_string( $raw ) ) {
			$features = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$features = $raw;
		} else {
			$features = array();
		}

		if ( ! is_array( $features ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid native features payload.', 'bizgrowth360-app-builder' ) ) );
		}

		$features_access = \Bizgrowth360\AppBuilder\Billing\Entitlements::assert_native_features( \Bizgrowth360\AppBuilder\Billing\Tenant::current_id(), $features );
		if ( is_wp_error( $features_access ) ) {
			wp_send_json_error( array( 'message' => $features_access->get_error_message(), 'code' => $features_access->get_error_code() ), 403 );
		}

		$saved  = NativeFeatures::save_features( $features );
		$status = NativeFeatures::get_features_with_status();

		wp_send_json_success(
			array(
				'features' => $saved,
				'status'   => $status,
				'message'  => __( 'Native features settings saved successfully.', 'bizgrowth360-app-builder' ),
			)
		);
	}

	/**
	 * Retrieve current native features configuration, status, and device token metrics.
	 */
	public function ajax_get_native_features() {
		$this->check_permissions();

		wp_send_json_success(
			array(
				'features'     => NativeFeatures::get_features(),
				'status'       => NativeFeatures::get_features_with_status(),
				'tokens_count' => DeviceTokensDb::count_tokens(),
			)
		);
	}

	/**
	 * Send test push notification from WordPress admin dashboard.
	 */
	public function ajax_send_test_push() {
		$this->check_permissions();

		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'Test Notification' ) );
		$body  = sanitize_text_field( wp_unslash( $_POST['body'] ?? 'This is a test push notification from Bizgrowth360 App Builder.' ) );
		$url   = esc_url_raw( wp_unslash( $_POST['url'] ?? home_url() ) );

		$features   = NativeFeatures::get_features();
		$server_key = $features['push_notifications']['firebase_server_key'] ?? '';

		$push_access = \Bizgrowth360\AppBuilder\Billing\Entitlements::assert_feature( \Bizgrowth360\AppBuilder\Billing\Tenant::current_id(), 'push_notifications' );
		if ( is_wp_error( $push_access ) ) {
			wp_send_json_error( array( 'message' => $push_access->get_error_message(), 'code' => $push_access->get_error_code() ), 403 );
		}

		if ( empty( $server_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase Server Key is not configured in Native Features > Push Notifications.', 'bizgrowth360-app-builder' ) ) );
		}

		$tokens = DeviceTokensDb::get_tokens(
			array(
				'platform' => 'android',
				'limit'    => 10,
			)
		);
		if ( empty( $tokens ) ) {
			wp_send_json_error( array( 'message' => __( 'No registered Android devices found. Launch your compiled Android app to register an FCM device token first. iOS APNs tokens require a separate APNs provider.', 'bizgrowth360-app-builder' ) ) );
		}

		$device_tokens = array_map( function( $t ) { return $t->token; }, $tokens );

		$rest   = new RestApi();
		$result = $rest->send_fcm_notification( $server_key, $device_tokens, $title, $body, array( 'url' => $url ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Push notification sent to %d device(s).', 'bizgrowth360-app-builder' ), count( $device_tokens ) ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Generate and preview Digital Asset Links (assetlinks.json).
	 */
	public function ajax_generate_assetlinks() {
		$this->check_permissions();

		$sha256 = sanitize_text_field( wp_unslash( $_POST['sha256_fingerprint'] ?? '' ) );
		$json   = NativeFeatures::generate_assetlinks_json( $sha256 );

		wp_send_json_success(
			array(
				'assetlinks_json' => $json,
				'well_known_url'  => home_url( '/.well-known/assetlinks.json' ),
			)
		);
	}
}
