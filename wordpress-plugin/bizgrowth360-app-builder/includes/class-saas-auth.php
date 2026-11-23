<?php
/**
 * Customer Authentication & Registration Service.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SaasAuth {

	const OPTION_ALLOW_REGISTRATION = 'bizgrowth360_allow_registration';

	public static function init() {
		add_action( 'wp_ajax_nopriv_bg360_saas_register', array( __CLASS__, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_bg360_saas_login', array( __CLASS__, 'ajax_login' ) );
		add_action( 'wp_ajax_nopriv_bg360_saas_reset_password', array( __CLASS__, 'ajax_reset_password' ) );

		add_action( 'wp_ajax_bg360_saas_logout', array( __CLASS__, 'ajax_logout' ) );
		add_action( 'wp_ajax_nopriv_bg360_saas_logout', array( __CLASS__, 'ajax_logout' ) );
	}

	public static function is_registration_enabled() {
		return '0' !== get_option( self::OPTION_ALLOW_REGISTRATION, '1' );
	}

	/**
	 * Rate limiting helper using WordPress transients.
	 */
	public static function check_rate_limit( $key_prefix, $max_attempts = 5, $decay_seconds = 900 ) {
		$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
		$transient_key = 'bg360_rate_' . md5( $key_prefix . '_' . $ip );
		$attempts = (int) get_transient( $transient_key );

		if ( $attempts >= $max_attempts ) {
			return false;
		}

		set_transient( $transient_key, $attempts + 1, $decay_seconds );
		return true;
	}

	/**
	 * Handle AJAX Registration.
	 */
	public static function ajax_register() {
		check_ajax_referer( 'bizgrowth360_saas_auth_nonce', 'nonce' );

		if ( ! self::is_registration_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Customer registration is currently disabled by administrator.', 'bizgrowth360-app-builder' ) ), 403 );
		}

		if ( ! self::check_rate_limit( 'register', 5, 900 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many registration attempts. Please wait 15 minutes before trying again.', 'bizgrowth360-app-builder' ) ), 429 );
		}

		$full_name        = sanitize_text_field( $_POST['full_name'] ?? '' );
		$business_name    = sanitize_text_field( $_POST['business_name'] ?? '' );
		$email            = sanitize_email( $_POST['email'] ?? '' );
		$phone            = sanitize_text_field( $_POST['phone'] ?? '' );
		$password         = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$confirm_password = isset( $_POST['confirm_password'] ) ? (string) $_POST['confirm_password'] : '';

		if ( empty( $full_name ) || empty( $email ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Full name, email, and password are required fields.', 'bizgrowth360-app-builder' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'bizgrowth360-app-builder' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with this email address already exists. Please log in instead.', 'bizgrowth360-app-builder' ) ) );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters long.', 'bizgrowth360-app-builder' ) ) );
		}

		if ( $password !== $confirm_password ) {
			wp_send_json_error( array( 'message' => __( 'Password confirmation does not match.', 'bizgrowth360-app-builder' ) ) );
		}

		// Generate username from email
		$username = sanitize_user( current( explode( '@', $email ) ), true );
		if ( empty( $username ) || username_exists( $username ) ) {
			$username = 'cust_' . wp_generate_password( 8, false, false );
		}

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// Update user profile
		$name_parts = explode( ' ', $full_name, 2 );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $name_parts[0] ?? '',
				'last_name'    => $name_parts[1] ?? '',
				'display_name' => $full_name,
				'role'         => SaasRoles::ROLE_CUSTOMER,
			)
		);

		// Provision customer workspace
		$starter_plan = SaasDb::get_plan_by_slug( 'starter' );
		$plan_id      = $starter_plan ? $starter_plan->id : 1;

		$workspace_name = ! empty( $business_name ) ? $business_name . ' Workspace' : $full_name . "'s Workspace";

		$workspace_id = SaasDb::create_workspace(
			array(
				'user_id'       => $user_id,
				'name'          => $workspace_name,
				'business_name' => $business_name,
				'phone'         => $phone,
				'plan_id'       => $plan_id,
				'status'        => 'active',
			)
		);

		// Automatically log the new user in
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		SaasDb::log_usage( $workspace_id, 'customer_registered', 1, array( 'email' => $email, 'user_id' => $user_id ) );

		wp_send_json_success(
			array(
				'message'      => __( 'Account created successfully! Redirecting to your workspace...', 'bizgrowth360-app-builder' ),
				'redirect_url' => home_url( '/app-portal/' ),
			)
		);
	}

	/**
	 * Handle AJAX Login.
	 */
	public static function ajax_login() {
		check_ajax_referer( 'bizgrowth360_saas_auth_nonce', 'nonce' );

		if ( ! self::check_rate_limit( 'login', 5, 900 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many failed login attempts. Please wait 15 minutes before trying again.', 'bizgrowth360-app-builder' ) ), 429 );
		}

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$remember = ! empty( $_POST['remember'] );

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Email/username and password are required.', 'bizgrowth360-app-builder' ) ) );
		}

		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email or password.', 'bizgrowth360-app-builder' ) ) );
		}

		// Verify account status (if customer)
		$workspace = SaasDb::get_workspace_by_user( $user->ID );
		if ( $workspace && 'suspended' === $workspace->status && ! user_can( $user, 'manage_options' ) ) {
			wp_logout();
			wp_send_json_error( array( 'message' => __( 'Your workspace account has been suspended by platform administration. Please contact support.', 'bizgrowth360-app-builder' ) ), 403 );
		}

		// If user is super admin, redirect to wp-admin; if SaaS customer, redirect to customer portal
		$redirect_url = user_can( $user, 'manage_options' ) ? admin_url( 'admin.php?page=bizgrowth360-app-builder' ) : home_url( '/app-portal/' );

		wp_send_json_success(
			array(
				'message'      => __( 'Login successful! Redirecting...', 'bizgrowth360-app-builder' ),
				'redirect_url' => $redirect_url,
			)
		);
	}

	/**
	 * Handle AJAX Logout.
	 */
	public static function ajax_logout() {
		wp_logout();
		wp_send_json_success(
			array(
				'message'      => __( 'You have been logged out.', 'bizgrowth360-app-builder' ),
				'redirect_url' => home_url( '/app-portal/' ),
			)
		);
	}

	/**
	 * Handle AJAX Password Reset Request.
	 */
	public static function ajax_reset_password() {
		check_ajax_referer( 'bizgrowth360_saas_auth_nonce', 'nonce' );

		$email = sanitize_email( $_POST['email'] ?? '' );
		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'bizgrowth360-app-builder' ) ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			// Don't reveal if user exists
			wp_send_json_success( array( 'message' => __( 'If an account exists with that email address, password reset instructions have been sent.', 'bizgrowth360-app-builder' ) ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not initiate password reset. Please try again later.', 'bizgrowth360-app-builder' ) ) );
		}

		$reset_url = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user->user_login ),
			),
			home_url( '/app-portal/' )
		);

		$subject = sprintf( __( '[%s] Password Reset Request', 'bizgrowth360-app-builder' ), get_bloginfo( 'name' ) );
		$message = sprintf(
			__( "Hello %s,\n\nYou requested a password reset for your Bizgrowth360 App Builder account.\n\nTo reset your password, visit the following link:\n%s\n\nIf you did not request this, you can safely ignore this email.", 'bizgrowth360-app-builder' ),
			$user->display_name,
			$reset_url
		);

		wp_mail( $email, $subject, $message );

		wp_send_json_success( array( 'message' => __( 'Password reset link sent! Please check your email inbox.', 'bizgrowth360-app-builder' ) ) );
	}
}
