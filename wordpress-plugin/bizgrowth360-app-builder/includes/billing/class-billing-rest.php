<?php
/**
 * Billing REST endpoints (signed gateway webhooks).
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BillingRest {

	const NAMESPACE = 'bizgrowth360/v1';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register webhook routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/billing/webhook/(?P<gateway>razorpay|stripe)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'gateway' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Handle a signed webhook.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$gateway  = sanitize_key( $request->get_param( 'gateway' ) );
		$raw_body = $request->get_body();
		$headers  = $request->get_headers();

		if ( empty( $raw_body ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Empty webhook body.' ), 400 );
		}

		$result = Payments::handle_webhook( $gateway, $raw_body, $headers );
		$status = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}
}
