<?php
/**
 * Plugin Name:       Bizgrowth360 Android App Builder
 * Plugin URI:        https://www.bizgrowth360.in
 * Description:       Build real Android APK and AAB packages for your WordPress website directly from your admin dashboard using GitHub Actions.
 * Version:           1.0.0
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

define( 'BIZGROWTH360_APP_BUILDER_VERSION', '1.0.0' );
define( 'BIZGROWTH360_APP_BUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZGROWTH360_APP_BUILDER_FILE', __FILE__ );

// Autoload plugin classes
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-config.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-settings.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-github-client.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-ajax-handler.php';
require_once BIZGROWTH360_APP_BUILDER_PATH . 'includes/class-admin-page.php';

/**
 * Main plugin bootstrap function.
 */
function bizgrowth360_app_builder_init() {
	$settings      = new \Bizgrowth360\AppBuilder\Settings();
	$github_client = new \Bizgrowth360\AppBuilder\GitHubClient( $settings );
	$ajax_handler  = new \Bizgrowth360\AppBuilder\AjaxHandler( $settings, $github_client );
	$admin_page    = new \Bizgrowth360\AppBuilder\AdminPage( $settings, $github_client );

	$settings->init();
	$ajax_handler->init();
	$admin_page->init();
}
add_action( 'plugins_loaded', 'bizgrowth360_app_builder_init' );

/**
 * Plugin activation hook.
 */
function bizgrowth360_app_builder_activate() {
	$settings = new \Bizgrowth360\AppBuilder\Settings();
	$settings->set_defaults();
}
register_activation_hook( __FILE__, 'bizgrowth360_app_builder_activate' );
