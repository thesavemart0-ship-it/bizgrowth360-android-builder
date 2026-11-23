<?php
/**
 * Billing notification templates and delivery.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notifications {

	const OPTION_KEY = 'bizgrowth360_billing_notifications';

	/**
	 * Default templates.
	 *
	 * @return array
	 */
	public static function defaults() {
		$site = get_bloginfo( 'name' );
		return array(
			'subscription_trial_started' => array(
				'enabled' => true,
				'subject' => 'Your {site_name} trial has started',
				'body'    => "Hello {customer_name},\n\nYour {plan} trial is active until {renewal_date}. No payment was taken.\n\nManage your subscription: {billing_url}",
			),
			'subscription_activated' => array(
				'enabled' => true,
				'subject' => 'Your {site_name} subscription is active',
				'body'    => "Hello {customer_name},\n\nYour {plan} subscription is active. Next renewal: {renewal_date}.\n\n{billing_url}",
			),
			'payment_success' => array(
				'enabled' => true,
				'subject' => 'Payment received — {site_name}',
				'body'    => "Hello {customer_name},\n\nWe received {currency} {amount}. Invoice: {invoice}.\n\n{billing_url}",
			),
			'payment_failed' => array(
				'enabled' => true,
				'subject' => 'Payment failed — {site_name}',
				'body'    => "Hello {customer_name},\n\nA payment of {currency} {amount} failed. Please update your payment method or retry.\n\n{billing_url}",
			),
			'renewal_reminder' => array(
				'enabled' => true,
				'subject' => 'Your {site_name} subscription renews in {days} days',
				'body'    => "Hello {customer_name},\n\nYour {plan} subscription renews on {renewal_date}.\n\n{billing_url}",
			),
			'subscription_expiring' => array(
				'enabled' => true,
				'subject' => 'Your {site_name} subscription is expiring',
				'body'    => "Hello {customer_name},\n\nYour {plan} subscription expires on {renewal_date}. Renew to keep building.\n\n{billing_url}",
			),
			'grace_period' => array(
				'enabled' => true,
				'subject' => 'Grace period started — {site_name}',
				'body'    => "Hello {customer_name},\n\nYour subscription is in a grace period until {renewal_date}. Please renew to avoid suspension.\n\n{billing_url}",
			),
			'plan_upgraded' => array(
				'enabled' => true,
				'subject' => 'Plan upgraded — {site_name}',
				'body'    => "Hello {customer_name},\n\nYour plan is now {plan}.\n\n{billing_url}",
			),
			'plan_downgraded' => array(
				'enabled' => true,
				'subject' => 'Plan change scheduled — {site_name}',
				'body'    => "Hello {customer_name},\n\nYour plan will change to {plan} on {renewal_date}. Review your usage before then.\n\n{billing_url}",
			),
			'subscription_cancelled' => array(
				'enabled' => true,
				'subject' => 'Subscription cancelled — {site_name}',
				'body'    => "Hello {customer_name},\n\nYour subscription has been cancelled. Access continues until {renewal_date} if cancellation is at period end.\n\n{billing_url}",
			),
			'refund_processed' => array(
				'enabled' => true,
				'subject' => 'Refund processed — {site_name}',
				'body'    => "Hello {customer_name},\n\nA refund of {currency} {amount} was processed.\n\n{billing_url}",
			),
			'quota_warning' => array(
				'enabled' => true,
				'subject' => 'You are close to your {site_name} build limit',
				'body'    => "Hello {customer_name},\n\nYou have used {used} of {limit} builds this period.\n\n{billing_url}",
			),
			'quota_exhausted' => array(
				'enabled' => true,
				'subject' => 'Build limit reached — {site_name}',
				'body'    => "Hello {customer_name},\n\nYou have reached your {plan} build limit. Upgrade or wait for the next billing period.\n\n{billing_url}",
			),
		);
	}

	/**
	 * Get saved templates merged with defaults.
	 *
	 * @return array
	 */
	public static function get_templates() {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$templates = self::defaults();
		foreach ( $templates as $event => $default ) {
			if ( isset( $stored[ $event ] ) && is_array( $stored[ $event ] ) ) {
				$templates[ $event ] = wp_parse_args( $stored[ $event ], $default );
			}
		}
		return $templates;
	}

	/**
	 * Save templates.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function save_templates( $input ) {
		$templates = self::defaults();
		$clean = array();
		foreach ( $templates as $event => $default ) {
			$row = $input[ $event ] ?? array();
			$clean[ $event ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'subject' => sanitize_text_field( $row['subject'] ?? $default['subject'] ),
				'body'    => sanitize_textarea_field( $row['body'] ?? $default['body'] ),
			);
		}
		update_option( self::OPTION_KEY, $clean );
		BillingDb::audit( 'notification_templates_updated', array( 'object_type' => 'settings' ) );
		return $clean;
	}

	/**
	 * Send a billing notification.
	 *
	 * @param string $event
	 * @param int    $tenant_id
	 * @param array  $data
	 * @return bool
	 */
	public static function send( $event, $tenant_id, $data = array() ) {
		$templates = self::get_templates();
		if ( empty( $templates[ $event ] ) || empty( $templates[ $event ]['enabled'] ) ) {
			return false;
		}

		$tenant_id = absint( $tenant_id );
		$email     = Tenant::email( $tenant_id );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$subscription = Subscriptions::get_current( $tenant_id );
		$plan         = $subscription ? Plans::get( $subscription->plan_id ) : null;
		$profile      = Tenant::get_profile( $tenant_id );

		$replacements = array(
			'{site_name}'     => get_bloginfo( 'name' ),
			'{customer_name}' => $profile->customer_name ?: Tenant::name( $tenant_id ),
			'{plan}'          => $data['plan'] ?? ( $plan ? $plan->name : '' ),
			'{amount}'        => isset( $data['amount'] ) ? number_format_i18n( (float) $data['amount'], 2 ) : '',
			'{currency}'      => $data['currency'] ?? ( $subscription ? $subscription->currency : 'INR' ),
			'{renewal_date}'  => $data['renewal_date'] ?? ( $subscription->current_period_end ?? '' ),
			'{invoice}'       => $data['invoice'] ?? '',
			'{days}'          => $data['days'] ?? '',
			'{status}'        => $data['status'] ?? ( $subscription ? Entitlements::status_label( $subscription->status ) : '' ),
			'{used}'          => $data['used'] ?? '',
			'{limit}'         => $data['limit'] ?? '',
			'{billing_url}'   => Entitlements::upgrade_url( $tenant_id ),
		);

		$subject = strtr( $templates[ $event ]['subject'], $replacements );
		$body    = strtr( $templates[ $event ]['body'], $replacements );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $email, $subject, $body, $headers );
		global $wpdb;
		$wpdb->insert(
			BillingDb::table( 'notification_log' ),
			array(
				'tenant_id' => $tenant_id,
				'event'     => sanitize_key( $event ),
				'recipient' => sanitize_email( $email ),
				'subject'   => sanitize_text_field( $subject ),
				'status'    => $sent ? 'sent' : 'failed',
				'error'     => $sent ? null : 'wp_mail returned false',
				'created_at'=> BillingDb::now(),
			)
		);

		return (bool) $sent;
	}
}
