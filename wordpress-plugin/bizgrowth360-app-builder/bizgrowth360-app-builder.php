<?php
/**
 * Plugin Name:       Bizgrowth360 App Builder
 * Plugin URI:        https://www.bizgrowth360.in
 * Description:       Build real Android APK/AAB and iOS IPA packages for your WordPress website directly from your admin dashboard using GitHub Actions.
 * Version:           1.1.0
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

define( 'BIZGROWTH360_APP_BUILDER_VERSION', '1.1.0' );
define( 'BIZGROWTH360_APP_BUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_FILE', __FILE__ );

// Autoload plugin classes
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-config.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-build-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-device-tokens-db.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-settings.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-design-config.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-native-features.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-rest-api.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-github-client.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-build-manager.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-ajax-handler.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-admin-page.php';

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

	// Ensure DB tables exist
	if ( is_admin() ) {
		if ( get_option( \Bizgrowth360\AppBuilder\BuildDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\BuildDb::DB_VERSION ) {
			\Bizgrowth360\AppBuilder\BuildDb::install_table();
		}
		if ( get_option( \Bizgrowth360\AppBuilder\DeviceTokensDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\DeviceTokensDb::DB_VERSION ) {
			\Bizgrowth360\AppBuilder\DeviceTokensDb::install_table();
		}
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

	// Register the /.well-known/* rewrite rules before flushing so Apple and
	// Android domain verification work immediately after activation.
	$rest_api = new \Bizgrowth360\AppBuilder\RestApi();
	$rest_api->register_well_known_rewrite();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bizgrowth360_app_builder_activate' );
