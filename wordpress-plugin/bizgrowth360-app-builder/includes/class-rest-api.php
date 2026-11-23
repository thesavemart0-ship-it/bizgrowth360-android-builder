<?php
/**
 * REST API Endpoints for Bizgrowth360 App Builder.
 *
 * Provides endpoints for FCM device registration, app update detection,
 * push notification delivery, and Digital Asset Links.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE = 'bizgrowth360/v1';

	/**
	 * Initialize REST API routes and well-known handlers.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_well_known_rewrite' ) );
		add_action( 'template_redirect', array( $this, 'handle_well_known_assetlinks' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Prevent canonical trailing slash redirect for /.well-known/assetlinks.json.
	 * Android App Links strictly requires direct 200 response without redirects.
	 *
	 * @param string|false $redirect_url
	 * @param string       $requested_url
	 * @return string|false
	 */
	public function filter_canonical_redirect( $redirect_url, $requested_url ) {
		if ( false !== strpos( $requested_url, '.well-known/assetlinks.json' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Device registration
		register_rest_route(
			self::NAMESPACE,
			'/register-device',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register_device' ),
				'permission_callback' => '__return_true', // Publicly callable by native apps
			)
		);

		// App updates
		register_rest_route(
			self::NAMESPACE,
			'/app-update',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_app_update' ),
				'permission_callback' => '__return_true',
			)
		);

		// Asset links endpoint
		register_rest_route(
			self::NAMESPACE,
			'/assetlinks.json',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_assetlinks_rest' ),
				'permission_callback' => '__return_true',
			)
		);

		// Send push notification (Admin only)
		register_rest_route(
			self::NAMESPACE,
			'/send-push',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_send_push' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Check if request has admin privileges.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Device registration handler.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle_register_device( $request ) {
		$params = $request->get_json_params() ?: $request->get_params();

		$device_id = sanitize_text_field( $params['device_id'] ?? '' );
		$token     = sanitize_text_field( $params['token'] ?? '' );

		if ( empty( $device_id ) || empty( $token ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'device_id and token parameters are required.', 'bizgrowth360-app-builder' ),
				),
				400
			);
		}

		$registered = DeviceTokensDb::register_token(
			array(
				'device_id'   => $device_id,
				'token'       => $token,
				'platform'    => sanitize_key( $params['platform'] ?? 'android' ),
				'app_version' => sanitize_text_field( $params['app_version'] ?? '1.0.0' ),
				'package_id'  => sanitize_text_field( $params['package_id'] ?? '' ),
				'user_id'     => get_current_user_id() ?: null,
			)
		);

		if ( $registered ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Device token registered successfully.', 'bizgrowth360-app-builder' ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => __( 'Could not save device token.', 'bizgrowth360-app-builder' ),
			),
			500
		);
	}

	/**
	 * App update check handler.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle_app_update( $request ) {
		$features     = NativeFeatures::get_features();
		$update_conf  = $features['app_updates'] ?? array();
		$current_code = absint( $request->get_param( 'version_code' ) ?? 1 );

		if ( empty( $update_conf['enabled'] ) ) {
			return new \WP_REST_Response(
				array(
					'update_available' => false,
					'message'          => __( 'App update engine disabled.', 'bizgrowth360-app-builder' ),
				),
				200
			);
		}

		$latest_code = absint( $update_conf['latest_version_code'] ?? 1 );
		$min_code    = absint( $update_conf['min_version_code'] ?? 1 );

		$update_available = $current_code < $latest_code;
		$is_mandatory     = ( 'mandatory' === $update_conf['mode'] ) || ( $current_code < $min_code );

		return new \WP_REST_Response(
			array(
				'update_available'    => $update_available,
				'mandatory'           => $update_available && $is_mandatory,
				'current_version'     => $current_code,
				'latest_version_code' => $latest_code,
				'latest_version_name' => $update_conf['latest_version_name'] ?? '1.0.0',
				'min_version_code'    => $min_code,
				'changelog'           => $update_conf['changelog'] ?? '',
				'play_store_url'      => $update_conf['play_store_url'] ?? '',
				'direct_apk_url'      => $update_conf['direct_apk_url'] ?? '',
			),
			200
		);
	}

	/**
	 * Digital Asset Links REST callback.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_assetlinks_rest() {
		$json = NativeFeatures::generate_assetlinks_json();
		return new \WP_REST_Response( $json, 200 );
	}

	/**
	 * Register rewrite for /.well-known/assetlinks.json
	 */
	public function register_well_known_rewrite() {
		add_rewrite_rule( '^\.well-known/assetlinks\.json$', 'index.php?bg360_assetlinks=1', 'top' );
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'bg360_assetlinks';
				return $vars;
			}
		);
	}

	/**
	 * Serve /.well-known/assetlinks.json with proper headers.
	 */
	public function handle_well_known_assetlinks() {
		global $wp_query;

		$is_assetlinks = false;
		if ( isset( $wp_query->query_vars['bg360_assetlinks'] ) ) {
			$is_assetlinks = true;
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '.well-known/assetlinks.json' ) ) {
			$is_assetlinks = true;
		}

		if ( $is_assetlinks ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			echo wp_json_encode( NativeFeatures::generate_assetlinks_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}
	}

	/**
	 * Send test push notification via Firebase.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle_send_push( $request ) {
		$params = $request->get_json_params() ?: $request->get_params();

		$title   = sanitize_text_field( $params['title'] ?? 'Test Notification' );
		$body    = sanitize_text_field( $params['body'] ?? 'This is a test notification from Bizgrowth360.' );
		$target  = sanitize_text_field( $params['target'] ?? 'broadcast' ); // 'broadcast' | single device token
		$deep_url= esc_url_raw( $params['deep_link'] ?? '' );

		$features = NativeFeatures::get_features();
		$push_cfg = $features['push_notifications'] ?? array();

		if ( empty( $push_cfg['enabled'] ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notifications are currently disabled in Native Features settings.', 'bizgrowth360-app-builder' ),
				),
				400
			);
		}

		$server_key = trim( $push_cfg['firebase_server_key'] ?? '' );
		if ( empty( $server_key ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Firebase Server Key is not configured. Please enter it in Native Features settings.', 'bizgrowth360-app-builder' ),
				),
				400
			);
		}

		// Gather tokens
		$tokens = array();
		if ( 'broadcast' === $target ) {
			$device_rows = DeviceTokensDb::get_tokens( array( 'limit' => 500 ) );
			foreach ( $device_rows as $row ) {
				$tokens[] = $row->token;
			}
		} else {
			$tokens[] = $target;
		}

		if ( empty( $tokens ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No registered devices found to receive push notification.', 'bizgrowth360-app-builder' ),
				),
				400
			);
		}

		// Dispatch via FCM Legacy HTTP API (supported with Server Key)
		$fcm_url = 'https://fcm.googleapis.com/fcm/send';
		$payload = array(
			'registration_ids' => array_values( array_unique( $tokens ) ),
			'notification'     => array(
				'title'        => $title,
				'body'         => $body,
				'sound'        => 'default',
				'channel_id'   => $push_cfg['channel_id'] ?? 'default_channel',
			),
			'data'             => array(
				'deep_link'    => $deep_url,
				'timestamp'    => time(),
			),
		);

		$response = wp_remote_post(
			$fcm_url,
			array(
				'headers' => array(
					'Authorization' => 'key=' . $server_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $response->get_error_message(),
				),
				500
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && ! empty( $body ) ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: success count, 2: failure count */
						__( 'Notification sent! Success: %1$d, Failed: %2$d', 'bizgrowth360-app-builder' ),
						$body['success'] ?? 0,
						$body['failure'] ?? 0
					),
					'details' => $body,
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => __( 'Firebase rejected the notification request. Please verify your Server Key.', 'bizgrowth360-app-builder' ),
				'details' => $body,
			),
			400
		);
	}
}
