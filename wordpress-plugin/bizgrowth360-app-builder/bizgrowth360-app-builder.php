<?php
/**
 * Plugin Name:       Bizgrowth360 App Builder
 * Plugin URI:        https://www.bizgrowth360.in
 * Description:       Build real Android APK/AAB and iOS IPA packages for your WordPress website directly from your admin dashboard using GitHub Actions.
 * Version:           1.3.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Bizgrowth360
 * Author URI:        https://www.bizgrowth360.in
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       bizgrowth360-app-builder
 *
 * @package           Bizgrowth360\AppBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BIZGROWTH360_APP_BUILDER_VERSION', '1.3.2' );
define( 'BIZGROWTH360_APP_BUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_FILE', __FILE__ );

// Autoload plugin classes
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-config.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-build-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-device-tokens-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-saas-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-saas-roles.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-saas-auth.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-tenant-manager.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-customer-portal.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-settings.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-design-config.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-native-features.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-rest-api.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-github-client.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-build-manager.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-ajax-handler.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-admin-page.php';

// Billing module (Phase 9)
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-billing-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-plans.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-tenant.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-subscriptions.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-apps.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-usage.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-entitlements.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-tax.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-coupons.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-gateways.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-invoices.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-notifications.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-payments.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-plan-changes.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-metrics.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-cron.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-billing-rest.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-billing-ajax.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-billing-shortcodes.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/billing/class-billing-admin.php';

/**
 * Main plugin bootstrap function.
 */
function bizgrowth360_app_builder_init() {
	$settings      = new \Bizgrowth360\AppBuilder\Settings();
	$github_client = new \Bizgrowth360\AppBuilder\GitHubClient( $settings );
	$build_manager = new \Bizgrowth360\AppBuilder\BuildManager( $settings, $github_client );
	$ajax_handler  = new \Bizgrowth360\AppBuilder\AjaxHandler( $settings, $github_client, $build_manager );
	$admin_page    = new \Bizgrowth360\AppBuilder\AdminPage( $settings, $github_client, $build_manager );
	$rest_api      = new \Bizgrowth360\AppBuilder\RestApi();

	$settings->init();
	$ajax_handler->init();
	$admin_page->init();
	$rest_api->init();

	// SaaS Platform Services
	\Bizgrowth360\AppBuilder\SaasRoles::init();
	\Bizgrowth360\AppBuilder\SaasAuth::init();
	\Bizgrowth360\AppBuilder\CustomerPortal::init();

	// Billing services
	$billing_cron       = new \Bizgrowth360\AppBuilder\Billing\Cron();
	$billing_rest       = new \Bizgrowth360\AppBuilder\Billing\BillingRest();
	$billing_ajax       = new \Bizgrowth360\AppBuilder\Billing\BillingAjax();
	$billing_shortcodes = new \Bizgrowth360\AppBuilder\Billing\BillingShortcodes();
	$billing_admin      = new \Bizgrowth360\AppBuilder\Billing\BillingAdmin();
	$billing_cron->init();
	$billing_rest->init();
	$billing_ajax->init();
	$billing_shortcodes->init();
	$billing_admin->init();

	// Flush the /.well-known/* rules and /app-portal once per plugin version on init action
	add_action( 'init', function() {
		if ( get_option( 'bizgrowth360_rewrite_version' ) !== BIZGROWTH360_APP_BUILDER_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'bizgrowth360_rewrite_version', BIZGROWTH360_APP_BUILDER_VERSION );
		}
	}, 99 );

	// Ensure DB tables exist
	if ( is_admin() || \Bizgrowth360\AppBuilder\CustomerPortal::is_portal_request() ) {
		if ( get_option( \Bizgrowth360\AppBuilder\BuildDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\BuildDb::DB_VERSION ) {
			\Bizgrowth360\AppBuilder\BuildDb::install_table();
		}
		if ( get_option( \Bizgrowth360\AppBuilder\DeviceTokensDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\DeviceTokensDb::DB_VERSION ) {
			\Bizgrowth360\AppBuilder\DeviceTokensDb::install_table();
		}
		if ( get_option( \Bizgrowth360\AppBuilder\SaasDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\SaasDb::DB_VERSION ) {
			\Bizgrowth360\AppBuilder\SaasDb::install_tables();
		}
	}

	// Billing schema depends on the SaaS workspace/app/build tables.
	\Bizgrowth360\AppBuilder\Billing\BillingDb::maybe_upgrade();

	// Ensure customer-facing billing pages exist even when the schema version
	// was already installed by an earlier partial deployment.
	$pricing_page   = (int) get_option( 'bizgrowth360_pricing_page_id', 0 );
	$dashboard_page = (int) get_option( 'bizgrowth360_dashboard_page_id', 0 );
	if ( ! $pricing_page || ! $dashboard_page || ! get_post( $pricing_page ) || ! get_post( $dashboard_page ) ) {
		\Bizgrowth360\AppBuilder\Billing\BillingShortcodes::ensure_pages();
		flush_rewrite_rules( false );
	}
}
add_action( 'plugins_loaded', 'bizgrowth360_app_builder_init' );

/**
 * Plugin activation hook.
 */
function bizgrowth360_app_builder_activate() {
	$settings = new \Bizgrowth360\AppBuilder\Settings();
	$settings->set_defaults();
	\Bizgrowth360\AppBuilder\BuildDb::install_table();
	\Bizgrowth360\AppBuilder\DeviceTokensDb::install_table();
	\Bizgrowth360\AppBuilder\SaasRoles::register_roles();
	\Bizgrowth360\AppBuilder\SaasDb::install_tables();
	\Bizgrowth360\AppBuilder\Billing\BillingDb::install();
	\Bizgrowth360\AppBuilder\Billing\Plans::seed_defaults();
	\Bizgrowth360\AppBuilder\Billing\Subscriptions::ensure_workspaces();
	\Bizgrowth360\AppBuilder\Billing\BillingShortcodes::ensure_pages();
	if ( ! wp_next_scheduled( \Bizgrowth360\AppBuilder\Billing\Cron::HOOK ) ) {
		wp_schedule_event( time() + 300, 'hourly', \Bizgrowth360\AppBuilder\Billing\Cron::HOOK );
	}

	$rest_api = new \Bizgrowth360\AppBuilder\RestApi();
	$rest_api->register_well_known_rewrite();
	\Bizgrowth360\AppBuilder\CustomerPortal::add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bizgrowth360_app_builder_activate' );
