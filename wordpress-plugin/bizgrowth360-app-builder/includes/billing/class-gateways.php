<?php
/**
 * Payment gateway abstraction: Razorpay (primary) and Stripe (optional).
 *
 * Secrets are read from wp-config constants or environment variables only.
 * They are never stored in the database, returned to JavaScript, or logged.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface GatewayInterface {
	public function id();
	public function is_configured();
	public function create_order( $args );
	public function verify_payment( $payload );
	public function verify_webhook( $raw_body, $headers );
	public function refund( $payment_id, $amount, $reason = '' );
	public function public_config();
}

class Gateways {

	/**
	 * Read a secret from a constant or environment variable.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function secret( $name ) {
		if ( defined( $name ) ) {
			$value = constant( $name );
			if ( ! empty( $value ) ) {
				return trim( (string) $value );
			}
		}
		$env = getenv( $name );
		return $env ? trim( (string) $env ) : '';
	}

	/**
	 * Active gateway ID.
	 *
	 * @return string
	 */
	public static function active_id() {
		$gateway = sanitize_key( get_option( 'bizgrowth360_billing_gateway', 'razorpay' ) );
		return (string) apply_filters( 'bizgrowth360_billing_gateway', $gateway );
	}

	/**
	 * Get a gateway instance.
	 *
	 * @param string $id
	 * @return GatewayInterface|null
	 */
	public static function get( $id = '' ) {
		$id = $id ? sanitize_key( $id ) : self::active_id();
		switch ( $id ) {
			case 'razorpay':
				return new RazorpayGateway();
			case 'stripe':
				return new StripeGateway();
			default:
				return null;
		}
	}

	/**
	 * Public gateway status for admin screens.
	 *
	 * @return array
	 */
	public static function status() {
		return array(
			'active'   => self::active_id(),
			'razorpay' => array(
				'configured' => ( new RazorpayGateway() )->is_configured(),
				'key_id'     => self::secret( 'BIZGROWTH360_RAZORPAY_KEY_ID' ) ? 'configured' : 'missing',
			),
			'stripe'   => array(
				'configured' => ( new StripeGateway() )->is_configured(),
				'publishable'=> self::secret( 'BIZGROWTH360_STRIPE_PUBLISHABLE_KEY' ) ? 'configured' : 'missing',
			),
		);
	}
}

class RazorpayGateway implements GatewayInterface {

	/**
	 * Gateway ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'razorpay';
	}

	/**
	 * Whether required secrets exist.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_ID' ) && '' !== Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_SECRET' );
	}

	/**
	 * Create a Razorpay order.
	 *
	 * @param array $args amount, currency, receipt, notes, description
	 * @return array
	 */
	public function create_order( $args ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Razorpay is not configured on this site.', 'bizgrowth360-app-builder' ) );
		}

		$amount   = round( (float) ( $args['amount'] ?? 0 ), 2 );
		$currency = strtoupper( $args['currency'] ?? 'INR' );
		if ( $amount <= 0 ) {
			return array( 'success' => false, 'message' => __( 'Order amount must be greater than zero.', 'bizgrowth360-app-builder' ) );
		}

		$response = $this->request(
			'POST',
			'/v1/orders',
			array(
				'amount'          => (int) round( $amount * 100 ),
				'currency'        => $currency,
				'receipt'         => substr( sanitize_text_field( $args['receipt'] ?? wp_generate_uuid4() ), 0, 40 ),
				'notes'           => $args['notes'] ?? array(),
				'payment_capture' => 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		if ( empty( $response['id'] ) ) {
			return array( 'success' => false, 'message' => __( 'Razorpay did not return an order ID.', 'bizgrowth360-app-builder' ) );
		}

		return array(
			'success'  => true,
			'gateway'  => 'razorpay',
			'order_id' => $response['id'],
			'amount'   => $amount,
			'currency' => $currency,
			'key_id'   => Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_ID' ),
			'raw'      => $response,
		);
	}

	/**
	 * Verify a checkout payment signature and the gateway payment state.
	 *
	 * @param array $payload order_id, payment_id, signature
	 * @return array
	 */
	public function verify_payment( $payload ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Razorpay is not configured.', 'bizgrowth360-app-builder' ) );
		}

		$order_id   = sanitize_text_field( $payload['razorpay_order_id'] ?? $payload['order_id'] ?? '' );
		$payment_id = sanitize_text_field( $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? '' );
		$signature  = sanitize_text_field( $payload['razorpay_signature'] ?? $payload['signature'] ?? '' );

		if ( empty( $order_id ) || empty( $payment_id ) || empty( $signature ) ) {
			return array( 'success' => false, 'message' => __( 'Incomplete Razorpay payment response.', 'bizgrowth360-app-builder' ) );
		}

		$expected = hash_hmac( 'sha256', $order_id . '|' . $payment_id, Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_SECRET' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return array( 'success' => false, 'message' => __( 'Razorpay signature verification failed.', 'bizgrowth360-app-builder' ) );
		}

		$payment = $this->request( 'GET', '/v1/payments/' . rawurlencode( $payment_id ) );
		if ( is_wp_error( $payment ) ) {
			return array( 'success' => false, 'message' => $payment->get_error_message() );
		}
		if ( empty( $payment['id'] ) || empty( $payment['order_id'] ) || $payment['order_id'] !== $order_id ) {
			return array( 'success' => false, 'message' => __( 'Razorpay payment does not match the order.', 'bizgrowth360-app-builder' ) );
		}
		if ( ! in_array( $payment['status'] ?? '', array( 'captured', 'authorized' ), true ) ) {
			return array( 'success' => false, 'message' => __( 'Razorpay payment is not captured.', 'bizgrowth360-app-builder' ) );
		}

		return array(
			'success'    => true,
			'gateway'    => 'razorpay',
			'payment_id' => $payment_id,
			'order_id'   => $order_id,
			'amount'     => round( (float) ( $payment['amount'] ?? 0 ) / 100, 2 ),
			'currency'   => strtoupper( $payment['currency'] ?? 'INR' ),
			'status'     => $payment['status'],
			'raw'        => $payment,
		);
	}

	/**
	 * Verify a Razorpay webhook signature.
	 *
	 * @param string $raw_body
	 * @param array  $headers
	 * @return array
	 */
	public function verify_webhook( $raw_body, $headers ) {
		$secret = Gateways::secret( 'BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET' );
		if ( empty( $secret ) ) {
			return array( 'success' => false, 'message' => __( 'Razorpay webhook secret is not configured.', 'bizgrowth360-app-builder' ) );
		}

		$signature = '';
		foreach ( $headers as $key => $value ) {
			if ( 'x-razorpay-signature' === strtolower( $key ) ) {
				$signature = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				break;
			}
		}
		if ( empty( $signature ) ) {
			return array( 'success' => false, 'message' => __( 'Missing Razorpay webhook signature.', 'bizgrowth360-app-builder' ) );
		}

		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return array( 'success' => false, 'message' => __( 'Razorpay webhook signature verification failed.', 'bizgrowth360-app-builder' ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid Razorpay webhook payload.', 'bizgrowth360-app-builder' ) );
		}

		$event_id = '';
		foreach ( $headers as $key => $value ) {
			if ( 'x-razorpay-event-id' === strtolower( $key ) ) {
				$event_id = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				break;
			}
		}
		$event_id = $event_id ?: ( $payload['id'] ?? hash( 'sha256', $raw_body ) );

		return array(
			'success'    => true,
			'gateway'    => 'razorpay',
			'event_id'   => sanitize_text_field( (string) $event_id ),
			'event_type' => sanitize_text_field( $payload['event'] ?? '' ),
			'payload'    => $payload,
		);
	}

	/**
	 * Refund a Razorpay payment.
	 *
	 * @param string $payment_id
	 * @param float  $amount
	 * @param string $reason
	 * @return array
	 */
	public function refund( $payment_id, $amount, $reason = '' ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Razorpay is not configured.', 'bizgrowth360-app-builder' ) );
		}
		$response = $this->request(
			'POST',
			'/v1/payments/' . rawurlencode( $payment_id ) . '/refund',
			array(
				'amount' => (int) round( (float) $amount * 100 ),
				'notes'  => array( 'reason' => sanitize_text_field( $reason ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		return array(
			'success'   => true,
			'refund_id' => $response['id'] ?? '',
			'amount'    => round( (float) ( $response['amount'] ?? 0 ) / 100, 2 ),
			'status'    => $response['status'] ?? '',
			'raw'       => $response,
		);
	}

	/**
	 * Public checkout configuration (never includes secrets).
	 *
	 * @return array
	 */
	public function public_config() {
		return array(
			'gateway' => 'razorpay',
			'key_id'  => Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_ID' ),
			'name'    => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Razorpay API request.
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_ID' ) . ':' . Gateways::secret( 'BIZGROWTH360_RAZORPAY_KEY_SECRET' ) ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( 'https://api.razorpay.com' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) ? ( $data['error']['description'] ?? $data['message'] ?? 'Razorpay request failed.' ) : 'Razorpay request failed.';
			return new \WP_Error( 'razorpay_error', sanitize_text_field( $message ) );
		}
		return is_array( $data ) ? $data : array();
	}
}

class StripeGateway implements GatewayInterface {

	/**
	 * Gateway ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'stripe';
	}

	/**
	 * Whether required secrets exist.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== Gateways::secret( 'BIZGROWTH360_STRIPE_SECRET_KEY' );
	}

	/**
	 * Create a Stripe Checkout Session.
	 *
	 * @param array $args
	 * @return array
	 */
	public function create_order( $args ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Stripe is not configured on this site.', 'bizgrowth360-app-builder' ) );
		}

		$amount   = round( (float) ( $args['amount'] ?? 0 ), 2 );
		$currency = strtolower( $args['currency'] ?? 'inr' );
		if ( $amount <= 0 ) {
			return array( 'success' => false, 'message' => __( 'Order amount must be greater than zero.', 'bizgrowth360-app-builder' ) );
		}

		$success_url = add_query_arg( 'bizgrowth360_checkout', 'success', $args['return_url'] ?? home_url( '/' ) );
		$cancel_url  = add_query_arg( 'bizgrowth360_checkout', 'cancelled', $args['return_url'] ?? home_url( '/' ) );

		$body = array(
			'mode'                 => 'payment',
			'success_url'          => $success_url . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'           => $cancel_url,
			'client_reference_id'  => (string) ( $args['transaction_id'] ?? '' ),
			'line_items[0][quantity]' => 1,
			'line_items[0][price_data][currency]' => $currency,
			'line_items[0][price_data][unit_amount]' => (int) round( $amount * 100 ),
			'line_items[0][price_data][product_data][name]' => sanitize_text_field( $args['description'] ?? 'Subscription' ),
			'metadata[transaction_id]' => (string) ( $args['transaction_id'] ?? '' ),
			'metadata[tenant_id]'      => (string) ( $args['tenant_id'] ?? '' ),
			'metadata[plan_id]'        => (string) ( $args['plan_id'] ?? '' ),
		);

		$response = $this->request( 'POST', '/v1/checkout/sessions', $body );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		if ( empty( $response['id'] ) || empty( $response['url'] ) ) {
			return array( 'success' => false, 'message' => __( 'Stripe did not return a checkout session.', 'bizgrowth360-app-builder' ) );
		}

		return array(
			'success'    => true,
			'gateway'    => 'stripe',
			'order_id'   => $response['id'],
			'checkout_url' => $response['url'],
			'amount'     => $amount,
			'currency'   => strtoupper( $currency ),
			'raw'        => $response,
		);
	}

	/**
	 * Verify a Stripe Checkout Session after return.
	 *
	 * @param array $payload session_id
	 * @return array
	 */
	public function verify_payment( $payload ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Stripe is not configured.', 'bizgrowth360-app-builder' ) );
		}
		$session_id = sanitize_text_field( $payload['session_id'] ?? '' );
		if ( empty( $session_id ) ) {
			return array( 'success' => false, 'message' => __( 'Missing Stripe checkout session.', 'bizgrowth360-app-builder' ) );
		}

		$session = $this->request( 'GET', '/v1/checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return array( 'success' => false, 'message' => $session->get_error_message() );
		}
		if ( ( $session['payment_status'] ?? '' ) !== 'paid' ) {
			return array( 'success' => false, 'message' => __( 'Stripe payment is not complete.', 'bizgrowth360-app-builder' ) );
		}

		return array(
			'success'    => true,
			'gateway'    => 'stripe',
			'payment_id' => $session['payment_intent'] ?? $session_id,
			'order_id'   => $session_id,
			'amount'     => round( (float) ( $session['amount_total'] ?? 0 ) / 100, 2 ),
			'currency'   => strtoupper( $session['currency'] ?? 'INR' ),
			'status'     => 'paid',
			'metadata'   => $session['metadata'] ?? array(),
			'raw'        => $session,
		);
	}

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * @param string $raw_body
	 * @param array  $headers
	 * @return array
	 */
	public function verify_webhook( $raw_body, $headers ) {
		$secret = Gateways::secret( 'BIZGROWTH360_STRIPE_WEBHOOK_SECRET' );
		if ( empty( $secret ) ) {
			return array( 'success' => false, 'message' => __( 'Stripe webhook secret is not configured.', 'bizgrowth360-app-builder' ) );
		}

		$header = '';
		foreach ( $headers as $key => $value ) {
			if ( 'stripe-signature' === strtolower( $key ) ) {
				$header = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				break;
			}
		}
		if ( empty( $header ) ) {
			return array( 'success' => false, 'message' => __( 'Missing Stripe webhook signature.', 'bizgrowth360-app-builder' ) );
		}

		$timestamp = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			}
			if ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || empty( $signatures ) || abs( time() - $timestamp ) > 300 ) {
			return array( 'success' => false, 'message' => __( 'Stripe webhook timestamp or signature is invalid.', 'bizgrowth360-app-builder' ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		$valid    = false;
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return array( 'success' => false, 'message' => __( 'Stripe webhook signature verification failed.', 'bizgrowth360-app-builder' ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid Stripe webhook payload.', 'bizgrowth360-app-builder' ) );
		}

		return array(
			'success'    => true,
			'gateway'    => 'stripe',
			'event_id'   => sanitize_text_field( $payload['id'] ?? hash( 'sha256', $raw_body ) ),
			'event_type' => sanitize_text_field( $payload['type'] ?? '' ),
			'payload'    => $payload,
		);
	}

	/**
	 * Refund a Stripe payment intent.
	 *
	 * @param string $payment_id
	 * @param float  $amount
	 * @param string $reason
	 * @return array
	 */
	public function refund( $payment_id, $amount, $reason = '' ) {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'Stripe is not configured.', 'bizgrowth360-app-builder' ) );
		}
		$response = $this->request(
			'POST',
			'/v1/refunds',
			array(
				'payment_intent' => $payment_id,
				'amount'         => (int) round( (float) $amount * 100 ),
				'reason'         => 'requested_by_customer',
				'metadata[reason]' => sanitize_text_field( $reason ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		return array(
			'success'   => true,
			'refund_id' => $response['id'] ?? '',
			'amount'    => round( (float) ( $response['amount'] ?? 0 ) / 100, 2 ),
			'status'    => $response['status'] ?? '',
			'raw'       => $response,
		);
	}

	/**
	 * Public checkout configuration.
	 *
	 * @return array
	 */
	public function public_config() {
		return array(
			'gateway'     => 'stripe',
			'publishable' => Gateways::secret( 'BIZGROWTH360_STRIPE_PUBLISHABLE_KEY' ),
		);
	}

	/**
	 * Stripe API request.
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . Gateways::secret( 'BIZGROWTH360_STRIPE_SECRET_KEY' ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( 'https://api.stripe.com' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) ? ( $data['error']['message'] ?? 'Stripe request failed.' ) : 'Stripe request failed.';
			return new \WP_Error( 'stripe_error', sanitize_text_field( $message ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
