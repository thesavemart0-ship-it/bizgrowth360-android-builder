<?php
/**
 * Authenticated customer billing AJAX endpoints.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BillingAjax {

	/**
	 * Register hooks.
	 */
	public function init() {
		$actions = array(
			'bizgrowth360_billing_checkout',
			'bizgrowth360_billing_verify',
			'bizgrowth360_billing_plan_change',
			'bizgrowth360_billing_cancel',
			'bizgrowth360_billing_resume',
			'bizgrowth360_billing_profile',
			'bizgrowth360_billing_coupon',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'dispatch' ) );
		}
	}

	/**
	 * Shared permission, nonce and rate-limit check.
	 */
	private function guard() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in to manage billing.', 'bizgrowth360-app-builder' ) ), 401 );
		}
		check_ajax_referer( 'bizgrowth360_billing_nonce', 'nonce' );

		$key = 'bg360_billing_rl_' . get_current_user_id();
		$hits = (int) get_transient( $key );
		if ( $hits >= 20 ) {
			wp_send_json_error( array( 'message' => __( 'Too many billing requests. Please wait a minute.', 'bizgrowth360-app-builder' ) ), 429 );
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Dispatch the AJAX action.
	 */
	public function dispatch() {
		$this->guard();
		$action = sanitize_key( $_POST['action'] ?? '' );
		switch ( $action ) {
			case 'bizgrowth360_billing_checkout':
				$this->checkout();
				break;
			case 'bizgrowth360_billing_verify':
				$this->verify();
				break;
			case 'bizgrowth360_billing_plan_change':
				$this->plan_change();
				break;
			case 'bizgrowth360_billing_cancel':
				$this->cancel();
				break;
			case 'bizgrowth360_billing_resume':
				$this->resume();
				break;
			case 'bizgrowth360_billing_profile':
				$this->profile();
				break;
			case 'bizgrowth360_billing_coupon':
				$this->coupon();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown billing action.', 'bizgrowth360-app-builder' ) ), 400 );
		}
	}

	/**
	 * Create checkout order or start free/trial plan.
	 */
	private function checkout() {
		$plan_id  = absint( $_POST['plan_id'] ?? 0 );
		$interval = sanitize_key( $_POST['interval'] ?? 'monthly' );
		$coupon   = sanitize_text_field( wp_unslash( $_POST['coupon'] ?? '' ) );
		$gateway  = sanitize_key( $_POST['gateway'] ?? '' );
		$result   = Payments::create_checkout( Tenant::current_id(), $plan_id, $interval, $coupon, $gateway );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result, 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Verify a returned checkout payment.
	 */
	private function verify() {
		$transaction_id = absint( $_POST['transaction_id'] ?? 0 );
		$payload = array(
			'razorpay_order_id'   => sanitize_text_field( $_POST['razorpay_order_id'] ?? '' ),
			'razorpay_payment_id' => sanitize_text_field( $_POST['razorpay_payment_id'] ?? '' ),
			'razorpay_signature'  => sanitize_text_field( $_POST['razorpay_signature'] ?? '' ),
			'session_id'          => sanitize_text_field( $_POST['session_id'] ?? '' ),
		);
		$result = Payments::verify_checkout( Tenant::current_id(), $transaction_id, $payload );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result, 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Upgrade/downgrade or start a plan-change checkout.
	 */
	private function plan_change() {
		$plan_id  = absint( $_POST['plan_id'] ?? 0 );
		$interval = sanitize_key( $_POST['interval'] ?? 'monthly' );
		$coupon   = sanitize_text_field( wp_unslash( $_POST['coupon'] ?? '' ) );
		$plan     = Plans::get( $plan_id );
		if ( ! $plan ) {
			wp_send_json_error( array( 'message' => __( 'Plan not found.', 'bizgrowth360-app-builder' ) ), 404 );
		}
		$result = PlanChanges::request( Tenant::current_id(), $plan, $interval, $coupon );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result, 400 );
		}
		if ( ! empty( $result['needs_payment'] ) ) {
			$checkout = Payments::create_checkout( Tenant::current_id(), $plan_id, $interval, $coupon, sanitize_key( $_POST['gateway'] ?? '' ) );
			if ( empty( $checkout['success'] ) ) {
				wp_send_json_error( $checkout, 400 );
			}
			$result['checkout'] = $checkout;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Cancel current subscription.
	 */
	private function cancel() {
		$subscription = Subscriptions::get_current( Tenant::current_id() );
		if ( ! $subscription ) {
			wp_send_json_error( array( 'message' => __( 'No subscription found.', 'bizgrowth360-app-builder' ) ), 404 );
		}
		$immediate = ! empty( $_POST['immediate'] );
		Subscriptions::cancel( $subscription->id, $immediate );
		Notifications::send( 'subscription_cancelled', $subscription->tenant_id, array( 'renewal_date' => $subscription->current_period_end ) );
		wp_send_json_success( array( 'message' => $immediate ? __( 'Subscription cancelled immediately.', 'bizgrowth360-app-builder' ) : __( 'Subscription will cancel at the end of the billing period.', 'bizgrowth360-app-builder' ) ) );
	}

	/**
	 * Resume a subscription scheduled for cancellation.
	 */
	private function resume() {
		$subscription = Subscriptions::get_current( Tenant::current_id() );
		if ( ! $subscription ) {
			wp_send_json_error( array( 'message' => __( 'No subscription found.', 'bizgrowth360-app-builder' ) ), 404 );
		}
		Subscriptions::resume( $subscription->id );
		wp_send_json_success( array( 'message' => __( 'Subscription resumed.', 'bizgrowth360-app-builder' ) ) );
	}

	/**
	 * Update billing profile.
	 */
	private function profile() {
		$fields = array( 'business_name', 'customer_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postal_code', 'country', 'gstin' );
		$data = array();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}
		Tenant::update_profile( Tenant::current_id(), $data );
		wp_send_json_success( array( 'message' => __( 'Billing details saved.', 'bizgrowth360-app-builder' ) ) );
	}

	/**
	 * Validate a coupon and return the discount.
	 */
	private function coupon() {
		$plan_id  = absint( $_POST['plan_id'] ?? 0 );
		$interval = sanitize_key( $_POST['interval'] ?? 'monthly' );
		$code     = sanitize_text_field( wp_unslash( $_POST['coupon'] ?? '' ) );
		$plan     = Plans::get( $plan_id );
		if ( ! $plan ) {
			wp_send_json_error( array( 'message' => __( 'Plan not found.', 'bizgrowth360-app-builder' ) ), 404 );
		}
		$price = Plans::price_for_interval( $plan, $interval );
		$result = Coupons::validate( $code, $plan->id, Tenant::current_id(), $price );
		if ( empty( $result['valid'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 400 );
		}
		wp_send_json_success(
			array(
				'message'  => __( 'Coupon applied.', 'bizgrowth360-app-builder' ),
				'discount' => $result['discount'],
				'total'    => max( 0, $price - $result['discount'] ),
			)
		);
	}
}
