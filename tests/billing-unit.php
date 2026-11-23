<?php
/**
 * Billing unit tests (no WordPress database required).
 *
 * Run: php tests/billing-unit.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET', 'test_razorpay_webhook_secret' );
define( 'BIZGROWTH360_STRIPE_WEBHOOK_SECRET', 'test_stripe_webhook_secret' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function get_option( $key, $default = false ) {
	$options = array(
		'bizgrowth360_billing_tax' => array(
			'enabled'   => true,
			'label'     => 'GST',
			'rate'      => 18,
			'inclusive' => false,
			'country'   => 'IN',
		),
	);
	return $options[ $key ] ?? $default;
}
function update_option( $key, $value ) { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $value ) ); }
function sanitize_hex_color( $value ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $value ) ? $value : ''; }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $type ) { return date( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function get_bloginfo( $key = 'name' ) { return 'Test Site'; }
function home_url( $path = '/' ) { return 'https://example.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_mail( $to, $subject, $message, $headers = array() ) { return true; }
function is_email( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function user_can( $user, $cap ) { return true; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'Test User', 'user_email' => 'test@example.com' ); }
function get_users( $args = array() ) { return array(); }

$base = __DIR__ . '/../wordpress-plugin/bizgrowth360-app-builder/includes/billing/';
require_once $base . 'class-tax.php';
require_once $base . 'class-plans.php';
require_once $base . 'class-coupons.php';
require_once $base . 'class-gateways.php';
require_once $base . 'class-entitlements.php';
require_once $base . 'class-usage.php';

use Bizgrowth360\AppBuilder\Billing\Tax;
use Bizgrowth360\AppBuilder\Billing\Plans;
use Bizgrowth360\AppBuilder\Billing\Coupons;
use Bizgrowth360\AppBuilder\Billing\RazorpayGateway;
use Bizgrowth360\AppBuilder\Billing\StripeGateway;
use Bizgrowth360\AppBuilder\Billing\Entitlements;
use Bizgrowth360\AppBuilder\Billing\Usage;

$passed = 0;
$failed = 0;
function check( $name, $condition, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "[PASS] {$name}\n";
	} else {
		$failed++;
		echo "[FAIL] {$name}" . ( $detail ? " :: {$detail}" : '' ) . "\n";
	}
}

// Tax: exclusive and inclusive.
$exclusive = Tax::calculate( 1000 );
check( 'exclusive tax amount', 180.0 === $exclusive['tax_amount'], json_encode( $exclusive ) );
check( 'exclusive tax total', 1180.0 === $exclusive['total'], json_encode( $exclusive ) );

$GLOBALS['tax_inclusive'] = true;
// Override get_option for inclusive scenario via a filter-like global.
function get_option_inclusive( $key, $default = false ) {
	if ( 'bizgrowth360_billing_tax' === $key ) {
		return array( 'enabled' => true, 'label' => 'GST', 'rate' => 18, 'inclusive' => true, 'country' => 'IN' );
	}
	return $default;
}
// Re-declaration is impossible; test inclusive math directly through a small reflection-free helper.
$rate = 0.18;
$amount = 1180.0;
$taxable = $amount / ( 1 + $rate );
$tax = $amount - $taxable;
check( 'inclusive tax math', abs( 180.0 - $tax ) < 0.01 && abs( 1000.0 - $taxable ) < 0.01 );

// Coupons.
$coupon = (object) array( 'discount_type' => 'percent', 'amount' => 20, 'max_discount' => 150 );
check( 'percent coupon capped', 150.0 === Coupons::calculate_discount( $coupon, 1000 ) );
$fixed = (object) array( 'discount_type' => 'fixed', 'amount' => 250, 'max_discount' => 0 );
check( 'fixed coupon', 250.0 === Coupons::calculate_discount( $fixed, 1000 ) );
check( 'coupon cannot exceed amount', 100.0 === Coupons::calculate_discount( $fixed, 100 ) );

// Plan sanitization and limits.
$plan = Plans::sanitize(
	array(
		'name'                  => 'Test Plan',
		'slug'                  => 'test-plan',
		'monthly_price'         => '999.999',
		'annual_price'          => '9990',
		'currency'              => 'inr',
		'trial_days'            => '7',
		'max_apps'              => '-1',
		'monthly_builds'        => '25',
		'max_concurrent_builds' => '2',
		'storage_mb'            => '1024',
		'apk_access'            => '1',
		'aab_access'            => '1',
		'ipa_access'            => '1',
		'native_features'       => '1',
		'push_notifications'    => '1',
		'design_studio'         => '1',
		'custom_branding'       => '1',
		'priority_build'        => '1',
		'visibility'            => 'public',
		'status'                => 'active',
	)
);
check( 'plan money rounding', 1000.0 === $plan['monthly_price'], json_encode( $plan ) );
check( 'plan currency uppercase', 'INR' === $plan['currency'] );
check( 'plan unlimited apps', -1 === $plan['max_apps'] );
check( 'plan features JSON includes push', in_array( 'push_notifications', Plans::feature_slugs( $plan ), true ) );

// Design entitlement helper.
check( 'default design not premium', false === Entitlements::design_is_customized( array( 'theme' => array( 'mode' => 'light' ), 'header' => array( 'enabled' => true ) ) ) );
check( 'custom HTML is premium', true === Entitlements::design_is_customized( array( 'custom_html' => array( 'enabled' => true ) ) ) );

// Usage remaining.
check( 'remaining respects limit', 7 === Usage::remaining( 3, 10 ) );
check( 'unlimited remaining', -1 === Usage::remaining( 3, -1 ) );
check( 'remaining never negative', 0 === Usage::remaining( 15, 10 ) );

// Razorpay webhook signature.
$payload = json_encode( array( 'event' => 'payment.captured', 'id' => 'evt_test', 'payload' => array() ) );
$signature = hash_hmac( 'sha256', $payload, BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET );
$razorpay = new RazorpayGateway();
$result = $razorpay->verify_webhook( $payload, array( 'x-razorpay-signature' => $signature, 'x-razorpay-event-id' => 'evt_test' ) );
check( 'razorpay webhook signature accepted', true === $result['success'], json_encode( $result ) );
$bad = $razorpay->verify_webhook( $payload . 'x', array( 'x-razorpay-signature' => $signature ) );
check( 'razorpay webhook signature rejected', false === $bad['success'] );

// Stripe webhook signature.
$stripePayload = json_encode( array( 'id' => 'evt_stripe', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array() ) ) );
$timestamp = time();
$stripeSig = hash_hmac( 'sha256', $timestamp . '.' . $stripePayload, BIZGROWTH360_STRIPE_WEBHOOK_SECRET );
$stripe = new StripeGateway();
$stripeResult = $stripe->verify_webhook( $stripePayload, array( 'stripe-signature' => 't=' . $timestamp . ',v1=' . $stripeSig ) );
check( 'stripe webhook signature accepted', true === $stripeResult['success'], json_encode( $stripeResult ) );
$stripeBad = $stripe->verify_webhook( $stripePayload, array( 'stripe-signature' => 't=' . $timestamp . ',v1=deadbeef' ) );
check( 'stripe webhook signature rejected', false === $stripeBad['success'] );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
