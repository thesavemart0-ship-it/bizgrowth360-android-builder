<?php
/**
 * Checkout, verified payment processing, webhook idempotency and refunds.
 *
 * Frontend success callbacks are never trusted. A subscription is activated
 * only after a server-side gateway verification or a signed webhook.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payments {

	/**
	 * Create a checkout or start a free/trial subscription.
	 *
	 * @param int    $tenant_id
	 * @param int    $plan_id
	 * @param string $interval
	 * @param string $coupon_code
	 * @param string $gateway_id
	 * @return array
	 */
	public static function create_checkout( $tenant_id, $plan_id, $interval, $coupon_code = '', $gateway_id = '' ) {
		$tenant_id = absint( $tenant_id );
		if ( ! $tenant_id ) {
			return array( 'success' => false, 'message' => __( 'Please sign in to subscribe.', 'bizgrowth360-app-builder' ) );
		}

		$plan = Plans::get( $plan_id );
		if ( ! $plan || 'active' !== $plan->status || 'public' !== $plan->visibility ) {
			return array( 'success' => false, 'message' => __( 'This plan is not available for purchase.', 'bizgrowth360-app-builder' ) );
		}

		$interval = 'annual' === sanitize_key( $interval ) ? 'annual' : 'monthly';
		$current  = Subscriptions::get_current( $tenant_id );
		$is_change = false;
		$change_subscription_id = 0;
		$change_quote = null;

		if ( $current && in_array( $current->status, array( 'active', 'trial', 'grace', 'past_due' ), true ) ) {
			if ( (int) $current->plan_id === (int) $plan->id && $current->billing_interval === $interval ) {
				return array(
					'success' => false,
					'message' => __( 'This is already your current plan and billing interval.', 'bizgrowth360-app-builder' ),
					'code'    => 'already_subscribed',
				);
			}

			$change_quote = PlanChanges::quote( $tenant_id, $plan, $interval, $coupon_code );
			if ( ! empty( $change_quote['is_downgrade'] ) ) {
				$scheduled = PlanChanges::request( $tenant_id, $plan, $interval, $coupon_code );
				return array(
					'success'   => ! empty( $scheduled['success'] ),
					'scheduled' => true,
					'message'   => $scheduled['message'] ?? __( 'Downgrade scheduled for the next billing period.', 'bizgrowth360-app-builder' ),
					'quote'     => $change_quote,
				);
			}

			if ( (float) $change_quote['amount_due'] <= 0 ) {
				$changed = PlanChanges::request( $tenant_id, $plan, $interval, $coupon_code );
				return array(
					'success' => ! empty( $changed['success'] ),
					'changed' => true,
					'message' => $changed['message'] ?? __( 'Plan upgraded using proration credit.', 'bizgrowth360-app-builder' ),
					'quote'   => $change_quote,
				);
			}

			$is_change              = true;
			$change_subscription_id = (int) $current->id;
			$price                  = (float) $change_quote['new_price'];
			$discount               = (float) $change_quote['discount'];
			$tax                    = $change_quote['tax'];
			$total                  = (float) $change_quote['amount_due'];
			$coupon_result          = array(
				'valid'    => true,
				'discount' => $discount,
				'coupon'   => $change_quote['coupon'],
			);
		} else {
			$price = Plans::price_for_interval( $plan, $interval );
		}

		$trial = max( 0, (int) $plan->trial_days );

		// A customer may use one trial across the workspace. Trials never apply
		// to an existing paid subscription plan change.
		if ( ! $is_change && $trial > 0 && ! self::has_used_trial( $tenant_id ) ) {
			$subscription = Subscriptions::start( $tenant_id, $plan, $interval, 'trial' );
			if ( is_wp_error( $subscription ) ) {
				return array( 'success' => false, 'message' => $subscription->get_error_message() );
			}
			Notifications::send( 'subscription_trial_started', $tenant_id, array( 'plan' => $plan->name, 'ends' => $subscription->trial_ends_at ) );
			return array(
				'success'       => true,
				'trial_started' => true,
				'subscription_id' => $subscription->id,
				'message'       => __( 'Your free trial has started. No payment was taken.', 'bizgrowth360-app-builder' ),
				'redirect'      => Entitlements::upgrade_url( $tenant_id ),
			);
		}

		// Free plan: activate immediately without a gateway.
		if ( ! $is_change && $price <= 0 ) {
			$subscription = Subscriptions::start( $tenant_id, $plan, $interval, 'manual' );
			if ( is_wp_error( $subscription ) ) {
				return array( 'success' => false, 'message' => $subscription->get_error_message() );
			}
			Notifications::send( 'subscription_activated', $tenant_id, array( 'plan' => $plan->name ) );
			return array(
				'success'       => true,
				'free_activated'=> true,
				'subscription_id' => $subscription->id,
				'message'       => __( 'Your free plan is active.', 'bizgrowth360-app-builder' ),
				'redirect'      => Entitlements::upgrade_url( $tenant_id ),
			);
		}

		if ( ! $is_change ) {
			$coupon_result = array( 'valid' => true, 'discount' => 0, 'coupon' => null );
			if ( ! empty( $coupon_code ) ) {
				$coupon_result = Coupons::validate( $coupon_code, $plan->id, $tenant_id, $price );
				if ( ! $coupon_result['valid'] ) {
					return array( 'success' => false, 'message' => $coupon_result['error'] );
				}
			}

			$discount = (float) $coupon_result['discount'];
			$tax      = Tax::calculate( max( 0, $price - $discount ), '', '' );
			$total    = (float) $tax['total'];
		}

		$gateway  = Gateways::get( $gateway_id );
		if ( ! $gateway || ! $gateway->is_configured() ) {
			return array( 'success' => false, 'message' => __( 'The selected payment gateway is not configured.', 'bizgrowth360-app-builder' ) );
		}

		if ( $is_change ) {
			$subscription_id = $change_subscription_id;
		} else {
			$subscription_id = self::reuse_pending_subscription( $tenant_id, $plan, $interval );
			if ( is_wp_error( $subscription_id ) ) {
				return array( 'success' => false, 'message' => $subscription_id->get_error_message() );
			}
		}

		$idempotency = 'checkout:' . $tenant_id . ':' . $plan->id . ':' . $interval . ':' . wp_generate_uuid4();
		$meta = array(
			'plan_id'        => $plan->id,
			'plan_name'      => $plan->name,
			'billing_interval' => $interval,
			'coupon_id'      => $coupon_result['coupon'] ? $coupon_result['coupon']->id : 0,
			'coupon_code'    => $coupon_result['coupon'] ? $coupon_result['coupon']->code : '',
			'subtotal'       => $price,
			'discount'       => $discount,
			'tax'            => $tax,
			'total'          => $total,
			'change_subscription_id' => $is_change ? $change_subscription_id : 0,
			'credit'         => $is_change ? (float) $change_quote['credit'] : 0,
		);

		$transaction_id = self::create_transaction(
			array(
				'tenant_id'       => $tenant_id,
				'subscription_id' => $subscription_id,
				'type'            => 'payment',
				'status'          => 'created',
				'gateway'         => $gateway->id(),
				'amount'          => $total,
				'currency'        => $plan->currency,
				'tax_amount'      => $tax['tax_amount'],
				'discount_amount' => $discount,
				'idempotency_key' => $idempotency,
				'meta'            => $meta,
			)
		);
		if ( is_wp_error( $transaction_id ) ) {
			return array( 'success' => false, 'message' => $transaction_id->get_error_message() );
		}

		$order = $gateway->create_order(
			array(
				'amount'         => $total,
				'currency'       => $plan->currency,
				'receipt'        => 'bg360-' . $transaction_id,
				'description'    => sprintf( '%s (%s)', $plan->name, $interval ),
				'notes'          => array(
					'tenant_id'      => $tenant_id,
					'transaction_id' => $transaction_id,
					'plan_id'        => $plan->id,
					'interval'       => $interval,
				),
				'transaction_id' => $transaction_id,
				'tenant_id'      => $tenant_id,
				'plan_id'        => $plan->id,
				'return_url'     => Entitlements::upgrade_url( $tenant_id ),
			)
		);

		if ( empty( $order['success'] ) ) {
			self::update_transaction( $transaction_id, array( 'status' => 'failed' ) );
			return array( 'success' => false, 'message' => $order['message'] ?? __( 'Could not create payment order.', 'bizgrowth360-app-builder' ) );
		}

		self::update_transaction(
			$transaction_id,
			array(
				'status'           => 'pending',
				'gateway_order_id' => $order['order_id'],
			)
		);

		$profile = Tenant::get_profile( $tenant_id );
		return array(
			'success'        => true,
			'gateway'        => $gateway->id(),
			'transaction_id' => $transaction_id,
			'subscription_id'=> $subscription_id,
			'order_id'       => $order['order_id'],
			'amount'         => $total,
			'currency'       => $plan->currency,
			'plan_name'      => $plan->name,
			'interval'       => $interval,
			'checkout_url'   => $order['checkout_url'] ?? '',
			'public_config'  => $gateway->public_config(),
			'prefill'        => array(
				'name'    => $profile->customer_name ?? Tenant::name( $tenant_id ),
				'email'   => $profile->email ?? Tenant::email( $tenant_id ),
				'contact' => $profile->phone ?? '',
			),
			'description'    => sprintf( '%s subscription (%s)', $plan->name, $interval ),
			'breakdown'      => array(
				'subtotal' => $price,
				'discount' => $discount,
				'tax'      => $tax,
				'total'    => $total,
			),
			'message'        => __( 'Payment order created. Complete checkout to activate the plan.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Verify a checkout payment server-side and activate the subscription.
	 *
	 * @param int   $tenant_id
	 * @param int   $transaction_id
	 * @param array $payload
	 * @return array
	 */
	public static function verify_checkout( $tenant_id, $transaction_id, $payload ) {
		$transaction = self::get_transaction( $transaction_id );
		if ( ! $transaction || (int) $transaction->tenant_id !== (int) $tenant_id ) {
			return array( 'success' => false, 'message' => __( 'Payment transaction not found.', 'bizgrowth360-app-builder' ) );
		}
		if ( 'succeeded' === $transaction->status ) {
			return array( 'success' => true, 'message' => __( 'Payment already verified.', 'bizgrowth360-app-builder' ), 'transaction_id' => $transaction->id );
		}

		$gateway = Gateways::get( $transaction->gateway );
		if ( ! $gateway ) {
			return array( 'success' => false, 'message' => __( 'Payment gateway is unavailable.', 'bizgrowth360-app-builder' ) );
		}

		$verification = $gateway->verify_payment( $payload );
		if ( empty( $verification['success'] ) ) {
			self::update_transaction( $transaction->id, array( 'status' => 'failed', 'gateway_event_id' => $verification['payment_id'] ?? '' ) );
			return array( 'success' => false, 'message' => $verification['message'] ?? __( 'Payment verification failed.', 'bizgrowth360-app-builder' ) );
		}

		if ( abs( (float) $verification['amount'] - (float) $transaction->amount ) > 0.01 || strtoupper( $verification['currency'] ) !== strtoupper( $transaction->currency ) ) {
			self::update_transaction( $transaction->id, array( 'status' => 'failed' ) );
			return array( 'success' => false, 'message' => __( 'Paid amount or currency does not match the order.', 'bizgrowth360-app-builder' ) );
		}

		return self::complete_payment( $transaction, $verification );
	}

	/**
	 * Complete a verified payment idempotently.
	 *
	 * @param object $transaction
	 * @param array  $verification
	 * @return array
	 */
	public static function complete_payment( $transaction, $verification ) {
		global $wpdb;

		$payment_id = sanitize_text_field( $verification['payment_id'] ?? '' );
		$order_id   = sanitize_text_field( $verification['order_id'] ?? $transaction->gateway_order_id );
		if ( empty( $payment_id ) ) {
			return array( 'success' => false, 'message' => __( 'Payment reference is missing.', 'bizgrowth360-app-builder' ) );
		}

		$existing = self::find_by_payment_id( $payment_id );
		if ( $existing && (int) $existing->id !== (int) $transaction->id ) {
			return array( 'success' => true, 'message' => __( 'Payment already processed.', 'bizgrowth360-app-builder' ), 'transaction_id' => $existing->id );
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . BillingDb::table( 'transactions' ) . " SET status = 'processing', updated_at = %s WHERE id = %d AND status <> 'succeeded'",
				BillingDb::now(),
				absint( $transaction->id )
			)
		);
		if ( ! $claimed ) {
			$current = self::get_transaction( $transaction->id );
			if ( $current && 'succeeded' === $current->status ) {
				return array( 'success' => true, 'message' => __( 'Payment already verified.', 'bizgrowth360-app-builder' ), 'transaction_id' => $current->id );
			}
			return array( 'success' => true, 'message' => __( 'Payment is being processed.', 'bizgrowth360-app-builder' ), 'transaction_id' => $transaction->id );
		}

		$meta         = is_array( $transaction->meta ) ? $transaction->meta : array();
		$subscription = Subscriptions::get( $transaction->subscription_id );
		if ( ! $subscription ) {
			self::update_transaction( $transaction->id, array( 'status' => 'failed' ) );
			return array( 'success' => false, 'message' => __( 'Subscription record is missing.', 'bizgrowth360-app-builder' ) );
		}

		$now   = BillingDb::now();
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . ( 'annual' === $subscription->billing_interval ? '1 year' : '1 month' ) ) );
		$change_id = absint( $meta['change_subscription_id'] ?? 0 );

		if ( $change_id ) {
			$new_plan = Plans::get( $meta['plan_id'] ?? 0 );
			if ( ! $new_plan ) {
				self::update_transaction( $transaction->id, array( 'status' => 'failed' ) );
				return array( 'success' => false, 'message' => __( 'The upgraded plan no longer exists.', 'bizgrowth360-app-builder' ) );
			}
			Subscriptions::change_plan( $change_id, $new_plan, $meta['billing_interval'] ?? $subscription->billing_interval, true );
			$subscription = Subscriptions::get( $change_id );
		} else {
			Subscriptions::activate(
				$subscription->id,
				array(
					'period_start'           => $now,
					'period_end'             => $end,
					'gateway_subscription_id'=> $verification['subscription_id'] ?? $subscription->gateway_subscription_id,
					'gateway_customer_id'    => $verification['customer_id'] ?? $subscription->gateway_customer_id,
					'reason'                 => 'payment_verified',
				)
			);
		}

		$updated = $wpdb->update(
			BillingDb::table( 'transactions' ),
			array(
				'status'             => 'succeeded',
				'gateway_payment_id' => $payment_id,
				'gateway_order_id'   => $order_id,
				'gateway_event_id'   => sanitize_text_field( $verification['event_id'] ?? '' ),
				'fee'                => round( (float) ( $verification['fee'] ?? 0 ), 2 ),
				'updated_at'         => BillingDb::now(),
			),
			array( 'id' => absint( $transaction->id ) )
		);
		if ( false === $updated ) {
			self::update_transaction( $transaction->id, array( 'status' => 'failed' ) );
			return array( 'success' => false, 'message' => __( 'Could not record the verified payment.', 'bizgrowth360-app-builder' ) );
		}

		$invoice = Invoices::create_from_transaction( $transaction->id );
		if ( is_wp_error( $invoice ) ) {
			BillingDb::audit( 'invoice_generation_failed', array( 'tenant_id' => $transaction->tenant_id, 'object_type' => 'transaction', 'object_id' => $transaction->id, 'details' => array( 'error' => $invoice->get_error_message() ) ) );
		}

		if ( ! empty( $meta['coupon_id'] ) ) {
			$coupon = Coupons::get( $meta['coupon_id'] );
			if ( $coupon ) {
				Coupons::redeem( $coupon, $transaction->tenant_id, $subscription->id, $transaction->id, (float) ( $meta['discount'] ?? 0 ) );
			}
		}

		Usage::clear_storage_cache( $transaction->tenant_id );
		Notifications::send( 'payment_success', $transaction->tenant_id, array( 'amount' => $transaction->amount, 'currency' => $transaction->currency, 'invoice' => is_wp_error( $invoice ) ? '' : $invoice->invoice_number ) );
		Notifications::send( $change_id ? 'plan_upgraded' : 'subscription_activated', $transaction->tenant_id, array( 'plan' => $meta['plan_name'] ?? '' ) );
		BillingDb::audit( 'payment_verified', array( 'tenant_id' => $transaction->tenant_id, 'object_type' => 'transaction', 'object_id' => $transaction->id, 'details' => array( 'payment_id' => $payment_id, 'amount' => $transaction->amount ) ) );

		return array(
			'success'        => true,
			'transaction_id' => $transaction->id,
			'invoice_id'     => is_wp_error( $invoice ) ? 0 : $invoice->id,
			'message'        => __( 'Payment verified and subscription activated.', 'bizgrowth360-app-builder' ),
		);
	}

	/**
	 * Process a signed gateway webhook.
	 *
	 * @param string $gateway_id
	 * @param string $raw_body
	 * @param array  $headers
	 * @return array
	 */
	public static function handle_webhook( $gateway_id, $raw_body, $headers ) {
		global $wpdb;

		$gateway = Gateways::get( $gateway_id );
		if ( ! $gateway ) {
			return array( 'success' => false, 'message' => __( 'Unknown payment gateway.', 'bizgrowth360-app-builder' ) );
		}

		$verified = $gateway->verify_webhook( $raw_body, $headers );
		if ( empty( $verified['success'] ) ) {
			BillingDb::audit( 'webhook_rejected', array( 'object_type' => 'webhook', 'details' => array( 'gateway' => $gateway_id, 'message' => $verified['message'] ?? '' ) ) );
			return array( 'success' => false, 'message' => $verified['message'] ?? __( 'Webhook verification failed.', 'bizgrowth360-app-builder' ) );
		}

		$event_id   = sanitize_text_field( $verified['event_id'] );
		$event_type = sanitize_text_field( $verified['event_type'] );
		$table      = BillingDb::table( 'webhook_events' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'gateway'    => $gateway_id,
				'event_id'   => $event_id,
				'event_type' => $event_type,
				'status'     => 'received',
				'payload'    => wp_json_encode( $verified['payload'] ),
				'created_at' => BillingDb::now(),
			)
		);
		if ( ! $inserted ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND event_id = %s LIMIT 1", $gateway_id, $event_id )
			);
			if ( $existing && 'processed' === $existing->status ) {
				return array( 'success' => true, 'message' => __( 'Duplicate webhook ignored.', 'bizgrowth360-app-builder' ), 'duplicate' => true );
			}
			return array( 'success' => true, 'message' => __( 'Webhook is already being processed.', 'bizgrowth360-app-builder' ), 'duplicate' => true );
		}

		$webhook_row = (int) $wpdb->insert_id;
		try {
			$result = self::process_webhook_event( $gateway_id, $event_type, $verified['payload'], $event_id );
			$status = ! empty( $result['success'] ) ? 'processed' : 'failed';
			$wpdb->update(
				$table,
				array(
					'status'       => $status,
					'error'        => empty( $result['success'] ) ? sanitize_text_field( $result['message'] ?? '' ) : null,
					'processed_at' => BillingDb::now(),
				),
				array( 'id' => $webhook_row )
			);
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->update(
				$table,
				array( 'status' => 'failed', 'error' => sanitize_text_field( $e->getMessage() ), 'processed_at' => BillingDb::now() ),
				array( 'id' => $webhook_row )
			);
			return array( 'success' => false, 'message' => __( 'Webhook processing error.', 'bizgrowth360-app-builder' ) );
		}
	}

	/**
	 * Dispatch a verified webhook event.
	 *
	 * @param string $gateway
	 * @param string $event_type
	 * @param array  $payload
	 * @param string $event_id
	 * @return array
	 */
	private static function process_webhook_event( $gateway, $event_type, $payload, $event_id ) {
		if ( 'razorpay' === $gateway ) {
			return self::process_razorpay_event( $event_type, $payload, $event_id );
		}
		if ( 'stripe' === $gateway ) {
			return self::process_stripe_event( $event_type, $payload, $event_id );
		}
		return array( 'success' => false, 'message' => __( 'Unsupported webhook gateway.', 'bizgrowth360-app-builder' ) );
	}

	/**
	 * Razorpay event handling.
	 *
	 * @param string $event_type
	 * @param array  $payload
	 * @param string $event_id
	 * @return array
	 */
	private static function process_razorpay_event( $event_type, $payload, $event_id ) {
		$entity = $payload['payload']['payment']['entity'] ?? $payload['payload']['refund']['entity'] ?? array();

		if ( 'payment.captured' === $event_type || 'order.paid' === $event_type ) {
			$order_id   = sanitize_text_field( $entity['order_id'] ?? '' );
			$payment_id = sanitize_text_field( $entity['id'] ?? '' );
			$transaction = self::find_by_order_id( $order_id );
			if ( ! $transaction ) {
				return array( 'success' => true, 'message' => __( 'No matching transaction for this payment.', 'bizgrowth360-app-builder' ) );
			}
			$verification = array(
				'success'    => true,
				'payment_id' => $payment_id,
				'order_id'   => $order_id,
				'amount'     => round( (float) ( $entity['amount'] ?? 0 ) / 100, 2 ),
				'currency'   => strtoupper( $entity['currency'] ?? 'INR' ),
				'status'     => $entity['status'] ?? '',
				'fee'        => round( (float) ( $entity['fee'] ?? 0 ) / 100, 2 ),
				'event_id'   => $event_id,
			);
			if ( abs( $verification['amount'] - (float) $transaction->amount ) > 0.01 ) {
				return array( 'success' => false, 'message' => __( 'Webhook amount does not match the transaction.', 'bizgrowth360-app-builder' ) );
			}
			return self::complete_payment( $transaction, $verification );
		}

		if ( 'payment.failed' === $event_type ) {
			$order_id = sanitize_text_field( $entity['order_id'] ?? '' );
			$transaction = self::find_by_order_id( $order_id );
			if ( $transaction ) {
				self::update_transaction( $transaction->id, array( 'status' => 'failed', 'gateway_event_id' => $event_id ) );
				if ( $transaction->subscription_id ) {
					Subscriptions::mark_past_due( $transaction->subscription_id, 3 );
				}
				Notifications::send( 'payment_failed', $transaction->tenant_id, array( 'amount' => $transaction->amount, 'currency' => $transaction->currency ) );
			}
			return array( 'success' => true, 'message' => __( 'Payment failure recorded.', 'bizgrowth360-app-builder' ) );
		}

		if ( 'refund.processed' === $event_type || 'refund.created' === $event_type ) {
			$refund_id = sanitize_text_field( $entity['id'] ?? '' );
			$payment_id = sanitize_text_field( $entity['payment_id'] ?? '' );
			$transaction = self::find_by_payment_id( $payment_id );
			if ( $transaction ) {
				self::record_refund( $transaction, $refund_id, round( (float) ( $entity['amount'] ?? 0 ) / 100, 2 ), $event_id );
			}
			return array( 'success' => true, 'message' => __( 'Refund recorded.', 'bizgrowth360-app-builder' ) );
		}

		if ( in_array( $event_type, array( 'subscription.charged', 'subscription.halted', 'subscription.cancelled' ), true ) ) {
			$subscription_id = sanitize_text_field( $payload['payload']['subscription']['entity']['id'] ?? '' );
			$subscription = self::find_subscription_by_gateway_id( $subscription_id, 'razorpay' );
			if ( $subscription ) {
				if ( 'subscription.charged' === $event_type ) {
					Subscriptions::renew( $subscription->id );
				} elseif ( 'subscription.halted' === $event_type ) {
					Subscriptions::mark_past_due( $subscription->id, 3 );
				} else {
					Subscriptions::cancel( $subscription->id, false );
				}
			}
			return array( 'success' => true, 'message' => __( 'Subscription webhook processed.', 'bizgrowth360-app-builder' ) );
		}

		return array( 'success' => true, 'message' => __( 'Webhook event ignored.', 'bizgrowth360-app-builder' ) );
	}

	/**
	 * Stripe event handling.
	 *
	 * @param string $event_type
	 * @param array  $payload
	 * @param string $event_id
	 * @return array
	 */
	private static function process_stripe_event( $event_type, $payload, $event_id ) {
		$object = $payload['data']['object'] ?? array();

		if ( 'checkout.session.completed' === $event_type ) {
			$transaction_id = absint( $object['metadata']['transaction_id'] ?? 0 );
			$transaction    = self::get_transaction( $transaction_id );
			if ( ! $transaction ) {
				return array( 'success' => true, 'message' => __( 'No matching transaction for this session.', 'bizgrowth360-app-builder' ) );
			}
			$verification = array(
				'success'    => true,
				'payment_id' => sanitize_text_field( $object['payment_intent'] ?? $object['id'] ?? '' ),
				'order_id'   => sanitize_text_field( $object['id'] ?? '' ),
				'amount'     => round( (float) ( $object['amount_total'] ?? 0 ) / 100, 2 ),
				'currency'   => strtoupper( $object['currency'] ?? 'INR' ),
				'status'     => 'paid',
				'event_id'   => $event_id,
			);
			if ( abs( $verification['amount'] - (float) $transaction->amount ) > 0.01 ) {
				return array( 'success' => false, 'message' => __( 'Webhook amount does not match the transaction.', 'bizgrowth360-app-builder' ) );
			}
			return self::complete_payment( $transaction, $verification );
		}

		if ( in_array( $event_type, array( 'invoice.paid', 'invoice.payment_failed', 'customer.subscription.deleted' ), true ) ) {
			$gateway_subscription = sanitize_text_field( $object['subscription'] ?? $object['id'] ?? '' );
			$subscription = self::find_subscription_by_gateway_id( $gateway_subscription, 'stripe' );
			if ( $subscription ) {
				if ( 'invoice.paid' === $event_type ) {
					Subscriptions::renew( $subscription->id );
				} elseif ( 'invoice.payment_failed' === $event_type ) {
					Subscriptions::mark_past_due( $subscription->id, 3 );
					Notifications::send( 'payment_failed', $subscription->tenant_id, array( 'amount' => $subscription->amount, 'currency' => $subscription->currency ) );
				} else {
					Subscriptions::cancel( $subscription->id, false );
				}
			}
			return array( 'success' => true, 'message' => __( 'Stripe subscription event processed.', 'bizgrowth360-app-builder' ) );
		}

		return array( 'success' => true, 'message' => __( 'Stripe event ignored.', 'bizgrowth360-app-builder' ) );
	}

	/**
	 * Refund a transaction through its gateway.
	 *
	 * @param int    $transaction_id
	 * @param float  $amount
	 * @param string $reason
	 * @return array
	 */
	public static function refund( $transaction_id, $amount = 0, $reason = '' ) {
		$transaction = self::get_transaction( $transaction_id );
		if ( ! $transaction || 'payment' !== $transaction->type || 'succeeded' !== $transaction->status ) {
			return array( 'success' => false, 'message' => __( 'Only a successful payment can be refunded.', 'bizgrowth360-app-builder' ) );
		}
		$amount = $amount > 0 ? round( (float) $amount, 2 ) : (float) $transaction->amount;
		if ( $amount > (float) $transaction->amount ) {
			return array( 'success' => false, 'message' => __( 'Refund amount exceeds the payment amount.', 'bizgrowth360-app-builder' ) );
		}
		$gateway = Gateways::get( $transaction->gateway );
		if ( ! $gateway ) {
			return array( 'success' => false, 'message' => __( 'Payment gateway is unavailable.', 'bizgrowth360-app-builder' ) );
		}
		$result = $gateway->refund( $transaction->gateway_payment_id, $amount, $reason );
		if ( empty( $result['success'] ) ) {
			return array( 'success' => false, 'message' => $result['message'] ?? __( 'Refund failed.', 'bizgrowth360-app-builder' ) );
		}
		self::record_refund( $transaction, $result['refund_id'], $amount, '' );
		return array( 'success' => true, 'message' => __( 'Refund processed.', 'bizgrowth360-app-builder' ), 'refund_id' => $result['refund_id'] );
	}

	/**
	 * Record a refund transaction and update invoice status.
	 *
	 * @param object $transaction
	 * @param string $refund_id
	 * @param float  $amount
	 * @param string $event_id
	 */
	public static function record_refund( $transaction, $refund_id, $amount, $event_id = '' ) {
		$refund_tx = self::create_transaction(
			array(
				'tenant_id'         => $transaction->tenant_id,
				'subscription_id'   => $transaction->subscription_id,
				'invoice_id'        => $transaction->invoice_id,
				'type'              => 'refund',
				'status'            => 'succeeded',
				'gateway'           => $transaction->gateway,
				'gateway_payment_id'=> $transaction->gateway_payment_id,
				'gateway_refund_id' => $refund_id,
				'gateway_event_id'  => $event_id,
				'amount'            => $amount,
				'currency'          => $transaction->currency,
				'idempotency_key'   => 'refund:' . $refund_id,
				'meta'              => array( 'original_transaction_id' => $transaction->id, 'reason' => 'refund' ),
			)
		);
		if ( ! is_wp_error( $refund_tx ) ) {
			Invoices::mark_refunded( $transaction->invoice_id, $amount );
			Notifications::send( 'refund_processed', $transaction->tenant_id, array( 'amount' => $amount, 'currency' => $transaction->currency ) );
			BillingDb::audit( 'refund_recorded', array( 'tenant_id' => $transaction->tenant_id, 'object_type' => 'transaction', 'object_id' => $refund_tx, 'details' => array( 'amount' => $amount, 'refund_id' => $refund_id ) ) );
		}
	}

	/**
	 * Create a transaction row.
	 *
	 * @param array $data
	 * @return int|\WP_Error
	 */
	public static function create_transaction( $data ) {
		global $wpdb;
		$now = BillingDb::now();
		$record = array(
			'tenant_id'         => absint( $data['tenant_id'] ?? 0 ),
			'subscription_id'   => ! empty( $data['subscription_id'] ) ? absint( $data['subscription_id'] ) : null,
			'invoice_id'        => ! empty( $data['invoice_id'] ) ? absint( $data['invoice_id'] ) : null,
			'type'              => sanitize_key( $data['type'] ?? 'payment' ),
			'status'            => sanitize_key( $data['status'] ?? 'created' ),
			'gateway'           => sanitize_key( $data['gateway'] ?? 'manual' ),
			'gateway_order_id'  => sanitize_text_field( $data['gateway_order_id'] ?? '' ) ?: null,
			'gateway_payment_id'=> sanitize_text_field( $data['gateway_payment_id'] ?? '' ) ?: null,
			'gateway_refund_id' => sanitize_text_field( $data['gateway_refund_id'] ?? '' ) ?: null,
			'gateway_event_id'  => sanitize_text_field( $data['gateway_event_id'] ?? '' ) ?: null,
			'amount'            => round( (float) ( $data['amount'] ?? 0 ), 2 ),
			'currency'          => strtoupper( substr( $data['currency'] ?? 'INR', 0, 3 ) ),
			'fee'               => round( (float) ( $data['fee'] ?? 0 ), 2 ),
			'tax_amount'        => round( (float) ( $data['tax_amount'] ?? 0 ), 2 ),
			'discount_amount'   => round( (float) ( $data['discount_amount'] ?? 0 ), 2 ),
			'idempotency_key'   => sanitize_text_field( $data['idempotency_key'] ?? '' ) ?: null,
			'meta'              => wp_json_encode( $data['meta'] ?? array() ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$wpdb->insert( BillingDb::table( 'transactions' ), $record );
		if ( ! $wpdb->insert_id ) {
			if ( ! empty( $record['idempotency_key'] ) ) {
				$existing = $wpdb->get_var(
					$wpdb->prepare( 'SELECT id FROM ' . BillingDb::table( 'transactions' ) . ' WHERE idempotency_key = %s LIMIT 1', $record['idempotency_key'] )
				);
				if ( $existing ) {
					return (int) $existing;
				}
			}
			return new \WP_Error( 'transaction_error', __( 'Could not create payment transaction.', 'bizgrowth360-app-builder' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a transaction.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update_transaction( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = BillingDb::now();
		return false !== $wpdb->update( BillingDb::table( 'transactions' ), $data, array( 'id' => absint( $id ) ) );
	}

	/**
	 * Get a transaction.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get_transaction( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'transactions' ) . ' WHERE id = %d LIMIT 1', absint( $id ) )
		);
		if ( ! $row ) {
			return null;
		}
		$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		return $row;
	}

	/**
	 * Find a transaction by gateway order ID.
	 *
	 * @param string $order_id
	 * @return object|null
	 */
	public static function find_by_order_id( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'transactions' ) . ' WHERE gateway_order_id = %s ORDER BY id DESC LIMIT 1', sanitize_text_field( $order_id ) )
		);
		if ( ! $row ) {
			return null;
		}
		$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		return $row;
	}

	/**
	 * Find a transaction by gateway payment ID.
	 *
	 * @param string $payment_id
	 * @return object|null
	 */
	public static function find_by_payment_id( $payment_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'transactions' ) . ' WHERE gateway_payment_id = %s ORDER BY id DESC LIMIT 1', sanitize_text_field( $payment_id ) )
		);
		if ( ! $row ) {
			return null;
		}
		$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		return $row;
	}

	/**
	 * Find a subscription by gateway subscription ID.
	 *
	 * @param string $gateway_id
	 * @param string $gateway
	 * @return object|null
	 */
	public static function find_subscription_by_gateway_id( $gateway_id, $gateway ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . BillingDb::table( 'subscriptions' ) . ' WHERE gateway_subscription_id = %s AND gateway = %s ORDER BY id DESC LIMIT 1',
				sanitize_text_field( $gateway_id ),
				sanitize_key( $gateway )
			)
		);
		return $row ? Subscriptions::normalize_row( $row ) : null;
	}

	/**
	 * List transactions for admin/customer screens.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function transactions( $args = array() ) {
		global $wpdb;
		$defaults = array( 'tenant_id' => 0, 'status' => '', 'type' => '', 'limit' => 50, 'offset' => 0, 'from' => '', 'to' => '' );
		$r = wp_parse_args( $args, $defaults );
		$where = array( '1=1' );
		$values = array();
		if ( $r['tenant_id'] ) {
			$where[]  = 'tenant_id = %d';
			$values[] = absint( $r['tenant_id'] );
		}
		if ( $r['status'] ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $r['status'] );
		}
		if ( $r['type'] ) {
			$where[]  = 'type = %s';
			$values[] = sanitize_key( $r['type'] );
		}
		if ( $r['from'] ) {
			$where[]  = 'created_at >= %s';
			$values[] = sanitize_text_field( $r['from'] );
		}
		if ( $r['to'] ) {
			$where[]  = 'created_at <= %s';
			$values[] = sanitize_text_field( $r['to'] );
		}
		$values[] = max( 1, absint( $r['limit'] ) );
		$values[] = max( 0, absint( $r['offset'] ) );
		$sql = 'SELECT * FROM ' . BillingDb::table( 'transactions' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		foreach ( $rows ? $rows : array() as $row ) {
			$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		}
		return $rows ?: array();
	}

	/**
	 * Whether a tenant has ever started a trial.
	 *
	 * @param int $tenant_id
	 * @return bool
	 */
	public static function has_used_trial( $tenant_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . BillingDb::table( 'subscriptions' ) . ' WHERE tenant_id = %d AND trial_ends_at IS NOT NULL',
				absint( $tenant_id )
			)
		);
		return $count > 0;
	}

	/**
	 * Reuse an existing pending subscription for the same plan/interval.
	 *
	 * @param int    $tenant_id
	 * @param object $plan
	 * @param string $interval
	 * @return int|\WP_Error
	 */
	private static function reuse_pending_subscription( $tenant_id, $plan, $interval ) {
		$current = Subscriptions::get_current( $tenant_id );
		if ( $current && 'pending' === $current->status && (int) $current->plan_id === (int) $plan->id && $current->billing_interval === $interval ) {
			return $current->id;
		}
		$subscription = Subscriptions::start( $tenant_id, $plan, $interval, 'pending' );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		return $subscription->id;
	}
}
