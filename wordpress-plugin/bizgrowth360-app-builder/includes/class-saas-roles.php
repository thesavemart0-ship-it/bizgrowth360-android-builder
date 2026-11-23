<?php
/**
 * SaaS Roles, Capabilities, and Access Controls.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SaasRoles {

	const ROLE_CUSTOMER = 'bg360_customer';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_roles' ) );
		add_action( 'admin_init', array( __CLASS__, 'restrict_wp_admin_access' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
	}

	/**
	 * Register the SaaS customer role and capabilities.
	 */
	public static function register_roles() {
		$caps = array(
			'read'                     => true,
			'bg360_access_portal'      => true,
			'bg360_manage_apps'        => true,
			'bg360_trigger_builds'     => true,
			'bg360_download_artifacts' => true,
		);

		add_role( self::ROLE_CUSTOMER, __( 'SaaS Customer', 'bizgrowth360-app-builder' ), $caps );
	}

	/**
	 * Block ordinary SaaS customers from seeing wp-admin.
	 * Redirect them to the Customer Portal.
	 */
	public static function restrict_wp_admin_access() {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			$user = wp_get_current_user();
			if ( in_array( self::ROLE_CUSTOMER, (array) $user->roles, true ) ) {
				$portal_url = home_url( '/app-portal/' );
				wp_safe_redirect( $portal_url );
				exit;
			}
		}
	}

	/**
	 * Hide the WordPress admin bar for SaaS customers.
	 */
	public static function filter_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			$user = wp_get_current_user();
			if ( in_array( self::ROLE_CUSTOMER, (array) $user->roles, true ) ) {
				return false;
			}
		}
		return $show;
	}
}
