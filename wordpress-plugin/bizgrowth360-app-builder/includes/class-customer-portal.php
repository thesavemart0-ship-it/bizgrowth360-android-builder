<?php
/**
 * SaaS Customer Portal Controller, Template Router, and UI Renderer.
 *
 * Provides a clean, modern, fully responsive SaaS frontend interface
 * for customers to manage their workspaces, apps, designs, native features,
 * and builds without touching WordPress wp-admin.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomerPortal {

	const PORTAL_SLUG = 'app-portal';

	public static function init() {
		add_shortcode( 'bizgrowth360_customer_portal', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'catch_portal_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_portal_assets' ) );

		// Customer AJAX Endpoints
		add_action( 'wp_ajax_bg360_portal_get_dashboard', array( __CLASS__, 'ajax_get_dashboard' ) );
		add_action( 'wp_ajax_bg360_portal_get_apps', array( __CLASS__, 'ajax_get_apps' ) );
		add_action( 'wp_ajax_bg360_portal_save_app', array( __CLASS__, 'ajax_save_app' ) );
		add_action( 'wp_ajax_bg360_portal_delete_app', array( __CLASS__, 'ajax_delete_app' ) );
		add_action( 'wp_ajax_bg360_portal_clone_app', array( __CLASS__, 'ajax_clone_app' ) );
		add_action( 'wp_ajax_bg360_portal_trigger_build', array( __CLASS__, 'ajax_trigger_build' ) );
		add_action( 'wp_ajax_bg360_portal_get_build_status', array( __CLASS__, 'ajax_get_build_status' ) );
		add_action( 'wp_ajax_bg360_portal_get_builds', array( __CLASS__, 'ajax_get_builds' ) );
		add_action( 'wp_ajax_bg360_portal_update_workspace', array( __CLASS__, 'ajax_update_workspace' ) );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^' . self::PORTAL_SLUG . '/?$', 'index.php?bg360_portal=1', 'top' );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = 'bg360_portal';
		return $vars;
	}

	public static function is_portal_request() {
		global $wp_query;
		if ( isset( $wp_query->query_vars['bg360_portal'] ) ) {
			return true;
		}
		$uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		return $uri === self::PORTAL_SLUG;
	}

	public static function catch_portal_route() {
		if ( self::is_portal_request() ) {
			// Render clean standalone portal page
			self::render_standalone_portal();
			exit;
		}
	}

	public static function enqueue_portal_assets() {
		if ( self::is_portal_request() || ( is_singular() && has_shortcode( get_post()->post_content, 'bizgrowth360_customer_portal' ) ) ) {
			wp_enqueue_style( 'dashicons' );

			$css_ver = BIZGROWTH360_APP_BUILDER_VERSION . '.' . ( file_exists( BIZGROWTH360_APP_BUILDER_PATH . 'assets/css/portal.css' ) ? filemtime( BIZGROWTH360_APP_BUILDER_PATH . 'assets/css/portal.css' ) : time() );
			$js_ver  = BIZGROWTH360_APP_BUILDER_VERSION . '.' . ( file_exists( BIZGROWTH360_APP_BUILDER_PATH . 'assets/js/portal.js' ) ? filemtime( BIZGROWTH360_APP_BUILDER_PATH . 'assets/js/portal.js' ) : time() );

			wp_enqueue_style(
				'bizgrowth360-portal-css',
				BIZGROWTH360_APP_BUILDER_URL . 'assets/css/portal.css',
				array( 'dashicons' ),
				$css_ver
			);

			wp_enqueue_script(
				'bizgrowth360-portal-js',
				BIZGROWTH360_APP_BUILDER_URL . 'assets/js/portal.js',
				array( 'jquery' ),
				$js_ver,
				true
			);

			$workspace = TenantManager::get_current_workspace();

			wp_localize_script(
				'bizgrowth360-portal-js',
				'bg360Portal',
				array(
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'auth_nonce'        => wp_create_nonce( 'bizgrowth360_saas_auth_nonce' ),
					'portal_nonce'      => wp_create_nonce( 'bizgrowth360_portal_nonce' ),
					'download_base_url' => admin_url( 'admin-post.php?action=bizgrowth360_download_artifact' ),
					'download_nonce'    => wp_create_nonce( 'bizgrowth360_download_artifact' ),
					'is_logged_in'      => is_user_logged_in(),
					'portal_url'        => home_url( '/' . self::PORTAL_SLUG . '/' ),
					'workspace_id'      => $workspace ? $workspace->id : 0,
					'workspace_name'    => $workspace ? $workspace->name : '',
				)
			);
		}
	}

	public static function render_shortcode() {
		ob_start();
		self::render_portal_body();
		return ob_get_clean();
	}

	public static function render_standalone_portal() {
		self::enqueue_portal_assets();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> &mdash; <?php esc_html_e( 'App Builder SaaS Portal', 'bizgrowth360-app-builder' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="bg360-portal-body">
			<?php self::render_portal_body(); ?>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Main portal UI renderer (handles Auth Views vs Authenticated Portal).
	 */
	public static function render_portal_body() {
		if ( ! is_user_logged_in() ) {
			self::render_auth_views();
			return;
		}

		$user      = wp_get_current_user();
		$workspace = TenantManager::get_current_workspace();

		if ( ! $workspace ) {
			?>
			<div class="bg360-portal-container" style="max-width: 600px; margin: 60px auto; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); text-align: center;">
				<h2><?php esc_html_e( 'No Workspace Found', 'bizgrowth360-app-builder' ); ?></h2>
				<p><?php esc_html_e( 'Your account does not have an active workspace assigned. Please contact the platform administrator.', 'bizgrowth360-app-builder' ); ?></p>
				<button type="button" class="bg360-btn bg360-btn-secondary" id="bg360-portal-logout-btn"><?php esc_html_e( 'Log Out', 'bizgrowth360-app-builder' ); ?></button>
			</div>
			<?php
			return;
		}

		$usage = SaasDb::get_workspace_usage_summary( $workspace->id );
		$apps  = SaasDb::get_apps_by_workspace( $workspace->id );
		?>
		<div class="bg360-portal-layout" id="bg360-portal-app">
			<!-- SIDEBAR -->
			<aside class="bg360-portal-sidebar">
				<div class="bg360-portal-brand">
					<div class="bg360-brand-icon">
						<span class="dashicons dashicons-smartphone"></span>
					</div>
					<div>
						<h3><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h3>
						<span class="bg360-plan-chip"><?php echo esc_html( $usage['plan']->name ?? 'Starter' ); ?></span>
					</div>
				</div>

				<nav class="bg360-portal-nav">
					<a href="#overview" class="bg360-nav-item active" data-view="overview">
						<span class="dashicons dashicons-dashboard"></span>
						<span><?php esc_html_e( 'Dashboard', 'bizgrowth360-app-builder' ); ?></span>
					</a>
					<a href="#apps" class="bg360-nav-item" data-view="apps">
						<span class="dashicons dashicons-smartphone"></span>
						<span><?php esc_html_e( 'My Applications', 'bizgrowth360-app-builder' ); ?></span>
					</a>
					<a href="#create-app" class="bg360-nav-item" data-view="create-app">
						<span class="dashicons dashicons-plus-alt2"></span>
						<span><?php esc_html_e( 'Create New App', 'bizgrowth360-app-builder' ); ?></span>
					</a>
					<a href="#builds" class="bg360-nav-item" data-view="builds">
						<span class="dashicons dashicons-cloud"></span>
						<span><?php esc_html_e( 'Build Center', 'bizgrowth360-app-builder' ); ?></span>
					</a>
					<a href="#usage" class="bg360-nav-item" data-view="usage">
						<span class="dashicons dashicons-chart-bar"></span>
						<span><?php esc_html_e( 'Usage & Quota', 'bizgrowth360-app-builder' ); ?></span>
					</a>
					<a href="#settings" class="bg360-nav-item" data-view="settings">
						<span class="dashicons dashicons-admin-generic"></span>
						<span><?php esc_html_e( 'Workspace Settings', 'bizgrowth360-app-builder' ); ?></span>
					</a>
				</nav>

				<div class="bg360-portal-user-footer">
					<div class="bg360-user-info">
						<strong><?php echo esc_html( $user->display_name ); ?></strong>
						<small><?php echo esc_html( $workspace->business_name ?: $workspace->name ); ?></small>
					</div>
					<button type="button" class="bg360-btn-logout" id="bg360-portal-logout-btn" title="<?php esc_attr_e( 'Log Out', 'bizgrowth360-app-builder' ); ?>">
						<span class="dashicons dashicons-migrate"></span>
					</button>
				</div>
			</aside>

			<!-- MAIN CONTENT AREA -->
			<main class="bg360-portal-main">
				<header class="bg360-portal-header">
					<div class="bg360-mobile-menu-toggle" id="bg360-mobile-menu-toggle">
						<span class="dashicons dashicons-menu-alt3"></span>
					</div>
					<div class="bg360-header-title">
						<h2 id="bg360-page-title"><?php esc_html_e( 'Dashboard Overview', 'bizgrowth360-app-builder' ); ?></h2>
					</div>
					<div class="bg360-header-actions">
						<button type="button" class="bg360-btn bg360-btn-primary" data-view-target="create-app">
							<span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'New App', 'bizgrowth360-app-builder' ); ?>
						</button>
					</div>
				</header>

				<div class="bg360-portal-content-body">
					<!-- NOTICES -->
					<div id="bg360-portal-notice-zone"></div>

					<!-- VIEW: OVERVIEW -->
					<section class="bg360-view-panel active" id="view-overview">
						<!-- Metric Cards Grid -->
						<div class="bg360-metric-grid">
							<div class="bg360-metric-card">
								<div class="bg360-metric-icon bg360-icon-purple">
									<span class="dashicons dashicons-smartphone"></span>
								</div>
								<div class="bg360-metric-data">
									<span class="bg360-metric-label"><?php esc_html_e( 'Total Apps', 'bizgrowth360-app-builder' ); ?></span>
									<span class="bg360-metric-value"><?php echo (int) $usage['total_apps']; ?> <small>/ <?php echo (int) $usage['max_apps']; ?></small></span>
								</div>
							</div>

							<div class="bg360-metric-card">
								<div class="bg360-metric-icon bg360-icon-blue">
									<span class="dashicons dashicons-cloud"></span>
								</div>
								<div class="bg360-metric-data">
									<span class="bg360-metric-label"><?php esc_html_e( 'Monthly Builds', 'bizgrowth360-app-builder' ); ?></span>
									<span class="bg360-metric-value"><?php echo (int) $usage['monthly_builds']; ?> <small>/ <?php echo (int) $usage['max_builds_per_month']; ?></small></span>
								</div>
							</div>

							<div class="bg360-metric-card">
								<div class="bg360-metric-icon bg360-icon-green">
									<span class="dashicons dashicons-yes-alt"></span>
								</div>
								<div class="bg360-metric-data">
									<span class="bg360-metric-label"><?php esc_html_e( 'Successful Builds', 'bizgrowth360-app-builder' ); ?></span>
									<span class="bg360-metric-value"><?php echo (int) $usage['successful_builds']; ?></span>
								</div>
							</div>

							<div class="bg360-metric-card">
								<div class="bg360-metric-icon bg360-icon-amber">
									<span class="dashicons dashicons-tickets-alt"></span>
								</div>
								<div class="bg360-metric-data">
									<span class="bg360-metric-label"><?php esc_html_e( 'Remaining Credits', 'bizgrowth360-app-builder' ); ?></span>
									<span class="bg360-metric-value"><?php echo (int) $usage['remaining_build_credits']; ?></span>
								</div>
							</div>
						</div>

						<!-- Recent Apps & Activity -->
						<div class="bg360-portal-grid-2col">
							<div class="bg360-portal-card">
								<div class="bg360-portal-card-header">
									<h3><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Active Applications', 'bizgrowth360-app-builder' ); ?></h3>
									<a href="#apps" class="bg360-link-small" data-view-target="apps"><?php esc_html_e( 'View All', 'bizgrowth360-app-builder' ); ?> &rarr;</a>
								</div>
								<div class="bg360-portal-card-body">
									<?php if ( empty( $apps ) ) : ?>
										<div class="bg360-empty-state">
											<span class="dashicons dashicons-smartphone"></span>
											<p><?php esc_html_e( 'You have not created any applications yet.', 'bizgrowth360-app-builder' ); ?></p>
											<button type="button" class="bg360-btn bg360-btn-primary bg360-btn-small" data-view-target="create-app"><?php esc_html_e( 'Create Your First App', 'bizgrowth360-app-builder' ); ?></button>
										</div>
									<?php else : ?>
										<div class="bg360-mini-app-list">
											<?php foreach ( array_slice( $apps, 0, 4 ) as $app ) : ?>
												<div class="bg360-mini-app-item">
													<div class="bg360-mini-app-icon" style="background-color: <?php echo esc_attr( $app->primary_color ?: '#7C3AED' ); ?>">
														<?php if ( ! empty( $app->app_icon ) ) : ?>
															<img src="<?php echo esc_url( $app->app_icon ); ?>" alt="icon" />
														<?php else : ?>
															<span class="dashicons dashicons-smartphone"></span>
														<?php endif; ?>
													</div>
													<div class="bg360-mini-app-meta">
														<strong><?php echo esc_html( $app->app_name ); ?></strong>
														<code><?php echo esc_html( $app->package_id ); ?></code>
													</div>
													<button type="button" class="bg360-btn bg360-btn-secondary bg360-btn-small bg360-btn-edit-app" data-app-id="<?php echo esc_attr( $app->id ); ?>">
														<?php esc_html_e( 'Manage', 'bizgrowth360-app-builder' ); ?>
													</button>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>

							<div class="bg360-portal-card">
								<div class="bg360-portal-card-header">
									<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Workspace Plan & Limits', 'bizgrowth360-app-builder' ); ?></h3>
								</div>
								<div class="bg360-portal-card-body">
									<div class="bg360-plan-summary-box">
										<div class="bg360-plan-title">
											<h4><?php echo esc_html( $usage['plan']->name ?? 'Starter Plan' ); ?></h4>
											<span class="bg360-badge bg360-badge-success"><?php echo esc_html( strtoupper( $workspace->status ) ); ?></span>
										</div>
										<ul class="bg360-plan-feature-list">
											<li><span class="dashicons dashicons-yes"></span> <?php echo (int) $usage['max_apps']; ?> <?php esc_html_e( 'Total Android & iOS Applications', 'bizgrowth360-app-builder' ); ?></li>
											<li><span class="dashicons dashicons-yes"></span> <?php echo (int) $usage['max_builds_per_month']; ?> <?php esc_html_e( 'Cloud Compilations per Month', 'bizgrowth360-app-builder' ); ?></li>
											<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Release APK & Google Play AAB Packaging', 'bizgrowth360-app-builder' ); ?></li>
											<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Firebase Push Notifications & Deep Linking', 'bizgrowth360-app-builder' ); ?></li>
										</ul>
										<div class="bg360-plan-cta">
											<p class="description"><?php esc_html_e( 'Need higher build limits or white-label services? Contact platform sales.', 'bizgrowth360-app-builder' ); ?></p>
										</div>
									</div>
								</div>
							</div>
						</div>
					</section>

					<!-- VIEW: MY APPLICATIONS -->
					<section class="bg360-view-panel" id="view-apps">
						<div class="bg360-apps-header-bar">
							<div>
								<h3><?php esc_html_e( 'Your Applications', 'bizgrowth360-app-builder' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Manage, configure, and trigger real Android/iOS packages for your websites.', 'bizgrowth360-app-builder' ); ?></p>
							</div>
							<button type="button" class="bg360-btn bg360-btn-primary" data-view-target="create-app">
								<span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Create New Application', 'bizgrowth360-app-builder' ); ?>
							</button>
						</div>

						<div class="bg360-apps-grid" id="bg360-apps-grid-container">
							<!-- Populated via JS / PHP Initial load -->
							<?php if ( empty( $apps ) ) : ?>
								<div class="bg360-empty-state" style="grid-column: 1 / -1;">
									<span class="dashicons dashicons-smartphone"></span>
									<p><?php esc_html_e( 'You have no applications yet. Click "Create New Application" above to get started.', 'bizgrowth360-app-builder' ); ?></p>
								</div>
							<?php else : ?>
								<?php foreach ( $apps as $app ) : ?>
									<div class="bg360-app-card" data-app-id="<?php echo esc_attr( $app->id ); ?>">
										<div class="bg360-app-card-top">
											<div class="bg360-app-avatar" style="background-color: <?php echo esc_attr( $app->primary_color ?: '#7C3AED' ); ?>">
												<?php if ( ! empty( $app->app_icon ) ) : ?>
													<img src="<?php echo esc_url( $app->app_icon ); ?>" alt="icon" />
												<?php else : ?>
													<span class="dashicons dashicons-smartphone"></span>
												<?php endif; ?>
											</div>
											<div class="bg360-app-meta">
												<h4><?php echo esc_html( $app->app_name ); ?></h4>
												<code><?php echo esc_html( $app->package_id ); ?></code>
												<a href="<?php echo esc_url( $app->website_url ); ?>" target="_blank" class="bg360-app-url"><?php echo esc_html( $app->website_url ); ?></a>
											</div>
										</div>
										<div class="bg360-app-card-info">
											<span>v<?php echo esc_html( $app->version_name ); ?> (Build <?php echo esc_html( $app->version_code ); ?>)</span>
											<span class="bg360-badge bg360-badge-idle"><?php echo esc_html( ucfirst( $app->orientation ) ); ?></span>
										</div>
										<div class="bg360-app-card-actions">
											<button type="button" class="bg360-btn bg360-btn-primary bg360-btn-small bg360-trigger-build-btn" data-app-id="<?php echo esc_attr( $app->id ); ?>" data-app-name="<?php echo esc_attr( $app->app_name ); ?>">
												<span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'Build App', 'bizgrowth360-app-builder' ); ?>
											</button>
											<button type="button" class="bg360-btn bg360-btn-secondary bg360-btn-small bg360-btn-edit-app" data-app-id="<?php echo esc_attr( $app->id ); ?>">
												<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Edit', 'bizgrowth360-app-builder' ); ?>
											</button>
											<button type="button" class="bg360-btn bg360-btn-secondary bg360-btn-small bg360-btn-clone-app" data-app-id="<?php echo esc_attr( $app->id ); ?>" title="<?php esc_attr_e( 'Clone Application', 'bizgrowth360-app-builder' ); ?>">
												<span class="dashicons dashicons-admin-page"></span>
											</button>
											<button type="button" class="bg360-btn bg360-btn-danger bg360-btn-small bg360-btn-delete-app" data-app-id="<?php echo esc_attr( $app->id ); ?>" title="<?php esc_attr_e( 'Delete Application', 'bizgrowth360-app-builder' ); ?>">
												<span class="dashicons dashicons-trash"></span>
											</button>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</section>

					<!-- VIEW: CREATE / EDIT APP -->
					<section class="bg360-view-panel" id="view-create-app">
						<div class="bg360-portal-card" style="max-width: 800px; margin: 0 auto;">
							<div class="bg360-portal-card-header">
								<h3 id="bg360-form-heading"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Application Details', 'bizgrowth360-app-builder' ); ?></h3>
							</div>
							<div class="bg360-portal-card-body">
								<form id="bg360-app-form">
									<input type="hidden" id="app_id" name="app_id" value="0" />

									<div class="bg360-form-row">
										<label for="app_name"><?php esc_html_e( 'Application Name', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
										<input type="text" id="app_name" name="app_name" class="bg360-input" required maxlength="50" placeholder="e.g. My Online Store" />
										<small><?php esc_html_e( 'Name displayed on Android home screen and app launcher.', 'bizgrowth360-app-builder' ); ?></small>
									</div>

									<div class="bg360-form-row">
										<label for="package_id"><?php esc_html_e( 'Android Package Identifier', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
										<input type="text" id="package_id" name="package_id" class="bg360-input" required placeholder="com.mybusiness.app" pattern="^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$" />
										<small><?php esc_html_e( 'Unique reverse-domain ID (e.g. com.example.store). Must have at least two segments.', 'bizgrowth360-app-builder' ); ?></small>
									</div>

									<div class="bg360-form-row">
										<label for="website_url"><?php esc_html_e( 'Target Website URL', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
										<input type="url" id="website_url" name="website_url" class="bg360-input" required placeholder="https://example.com" />
										<small><?php esc_html_e( 'The full HTTPS web address that will load inside the native Android WebView.', 'bizgrowth360-app-builder' ); ?></small>
									</div>

									<div class="bg360-form-row-2col">
										<div>
											<label for="version_name"><?php esc_html_e( 'Version Name', 'bizgrowth360-app-builder' ); ?></label>
											<input type="text" id="version_name" name="version_name" class="bg360-input" value="1.0.0" placeholder="1.0.0" />
										</div>
										<div>
											<label for="version_code"><?php esc_html_e( 'Version Code', 'bizgrowth360-app-builder' ); ?></label>
											<input type="number" id="version_code" name="version_code" class="bg360-input" value="1" min="1" step="1" />
										</div>
									</div>

									<div class="bg360-form-row-2col">
										<div>
											<label for="primary_color"><?php esc_html_e( 'Primary Brand Color', 'bizgrowth360-app-builder' ); ?></label>
											<input type="color" id="primary_color" name="primary_color" class="bg360-color-picker-input" value="#7C3AED" />
										</div>
										<div>
											<label for="status_bar_color"><?php esc_html_e( 'Android Status Bar Color', 'bizgrowth360-app-builder' ); ?></label>
											<input type="color" id="status_bar_color" name="status_bar_color" class="bg360-color-picker-input" value="#6D28D9" />
										</div>
									</div>

									<div class="bg360-form-row">
										<label for="app_icon"><?php esc_html_e( 'App Icon Image URL (512x512 PNG recommended)', 'bizgrowth360-app-builder' ); ?></label>
										<input type="url" id="app_icon" name="app_icon" class="bg360-input" placeholder="https://example.com/wp-content/uploads/icon.png" />
									</div>

									<div class="bg360-form-row">
										<label for="splash_image"><?php esc_html_e( 'Splash Screen Image URL (1080x1920 PNG recommended)', 'bizgrowth360-app-builder' ); ?></label>
										<input type="url" id="splash_image" name="splash_image" class="bg360-input" placeholder="https://example.com/wp-content/uploads/splash.png" />
									</div>

									<div class="bg360-form-row">
										<label for="orientation"><?php esc_html_e( 'Screen Orientation', 'bizgrowth360-app-builder' ); ?></label>
										<select id="orientation" name="orientation" class="bg360-select">
											<option value="portrait"><?php esc_html_e( 'Portrait (Default)', 'bizgrowth360-app-builder' ); ?></option>
											<option value="landscape"><?php esc_html_e( 'Landscape', 'bizgrowth360-app-builder' ); ?></option>
											<option value="sensor"><?php esc_html_e( 'Auto-Rotate (Sensor)', 'bizgrowth360-app-builder' ); ?></option>
										</select>
									</div>

									<div class="bg360-form-actions">
										<button type="submit" class="bg360-btn bg360-btn-primary" id="bg360-save-app-btn">
											<span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save Application', 'bizgrowth360-app-builder' ); ?>
										</button>
										<button type="button" class="bg360-btn bg360-btn-secondary" data-view-target="apps">
											<?php esc_html_e( 'Cancel', 'bizgrowth360-app-builder' ); ?>
										</button>
									</div>
								</form>
							</div>
						</div>
					</section>

					<!-- VIEW: BUILD CENTER -->
					<section class="bg360-view-panel" id="view-builds">
						<div class="bg360-portal-card" style="margin-bottom: 24px;">
							<div class="bg360-portal-card-header">
								<h3><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'Trigger Cloud Compilation', 'bizgrowth360-app-builder' ); ?></h3>
							</div>
							<div class="bg360-portal-card-body">
								<form id="bg360-portal-build-trigger-form">
									<div class="bg360-form-row-2col">
										<div>
											<label for="build_app_select"><?php esc_html_e( 'Select Application to Build:', 'bizgrowth360-app-builder' ); ?></label>
											<select id="build_app_select" class="bg360-select" required>
												<?php foreach ( $apps as $app ) : ?>
													<option value="<?php echo esc_attr( $app->id ); ?>"><?php echo esc_html( $app->app_name ); ?> (<?php echo esc_html( $app->package_id ); ?>)</option>
												<?php endforeach; ?>
											</select>
										</div>
										<div>
											<label for="build_target_select"><?php esc_html_e( 'Package Format:', 'bizgrowth360-app-builder' ); ?></label>
											<select id="build_target_select" class="bg360-select">
												<option value="both"><?php esc_html_e( 'Build Both (APK + AAB)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="apk"><?php esc_html_e( 'Direct Install APK Only', 'bizgrowth360-app-builder' ); ?></option>
												<option value="aab"><?php esc_html_e( 'Google Play AAB Bundle Only', 'bizgrowth360-app-builder' ); ?></option>
											</select>
										</div>
									</div>

									<div class="bg360-build-dispatch-bar">
										<button type="submit" class="bg360-btn bg360-btn-primary" id="bg360-portal-start-build-btn">
											<span class="dashicons dashicons-hammer"></span> <?php esc_html_e( 'Compile & Generate Android Packages', 'bizgrowth360-app-builder' ); ?>
										</button>
										<span id="bg360-portal-build-status-text"></span>
									</div>
								</form>
							</div>
						</div>

						<!-- ACTIVE BUILD LIVE MONITOR -->
						<div class="bg360-portal-card" id="bg360-portal-active-build-card" style="display: none; margin-bottom: 24px;">
							<div class="bg360-portal-card-header bg360-header-active">
								<h3><span class="dashicons dashicons-update spin"></span> <?php esc_html_e( 'Active Cloud Compilation in Progress', 'bizgrowth360-app-builder' ); ?></h3>
								<span class="bg360-badge bg360-badge-progress" id="bg360-portal-active-stage">Compiling...</span>
							</div>
							<div class="bg360-portal-card-body">
								<div class="bg360-active-build-details">
									<p id="bg360-portal-active-app-name"></p>
									<div class="bg360-progress-bar-container">
										<div class="bg360-progress-bar-fill"></div>
									</div>
								</div>
								<!-- Artifacts Download Zone -->
								<div id="bg360-portal-artifacts-zone" style="display: none; margin-top: 20px;">
									<h4><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Generated Packages Ready for Download:', 'bizgrowth360-app-builder' ); ?></h4>
									<div class="bg360-artifact-buttons" id="bg360-portal-artifact-buttons"></div>
								</div>
							</div>
						</div>

						<!-- HISTORICAL BUILDS TABLE -->
						<div class="bg360-portal-card">
							<div class="bg360-portal-card-header">
								<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Your Workspace Build History', 'bizgrowth360-app-builder' ); ?></h3>
							</div>
							<div class="bg360-portal-card-body">
								<div class="bg360-table-responsive">
									<table class="bg360-portal-table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Build ID', 'bizgrowth360-app-builder' ); ?></th>
												<th><?php esc_html_e( 'Application', 'bizgrowth360-app-builder' ); ?></th>
												<th><?php esc_html_e( 'Format', 'bizgrowth360-app-builder' ); ?></th>
												<th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th>
												<th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th>
												<th><?php esc_html_e( 'Download Artifacts', 'bizgrowth360-app-builder' ); ?></th>
											</tr>
										</thead>
										<tbody id="bg360-portal-build-history-body">
											<!-- Populated dynamically -->
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</section>

					<!-- VIEW: USAGE -->
					<section class="bg360-view-panel" id="view-usage">
						<div class="bg360-portal-card" style="max-width: 800px; margin: 0 auto;">
							<div class="bg360-portal-card-header">
								<h3><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Resource Usage & Audit Breakdown', 'bizgrowth360-app-builder' ); ?></h3>
							</div>
							<div class="bg360-portal-card-body">
								<table class="bg360-portal-table">
									<tbody>
										<tr>
											<td><strong><?php esc_html_e( 'Applications Allowed', 'bizgrowth360-app-builder' ); ?></strong></td>
											<td><?php echo (int) $usage['total_apps']; ?> / <?php echo (int) $usage['max_apps']; ?></td>
										</tr>
										<tr>
											<td><strong><?php esc_html_e( 'Monthly Builds Used', 'bizgrowth360-app-builder' ); ?></strong></td>
											<td><?php echo (int) $usage['monthly_builds']; ?> / <?php echo (int) $usage['max_builds_per_month']; ?></td>
										</tr>
										<tr>
											<td><strong><?php esc_html_e( 'Successful Builds (Lifetime)', 'bizgrowth360-app-builder' ); ?></strong></td>
											<td><?php echo (int) $usage['successful_builds']; ?></td>
										</tr>
										<tr>
											<td><strong><?php esc_html_e( 'Failed Builds (Lifetime)', 'bizgrowth360-app-builder' ); ?></strong></td>
											<td><?php echo (int) $usage['failed_builds']; ?></td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</section>

					<!-- VIEW: WORKSPACE SETTINGS -->
					<section class="bg360-view-panel" id="view-settings">
						<div class="bg360-portal-card" style="max-width: 700px; margin: 0 auto;">
							<div class="bg360-portal-card-header">
								<h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Workspace & Business Profile', 'bizgrowth360-app-builder' ); ?></h3>
							</div>
							<div class="bg360-portal-card-body">
								<form id="bg360-workspace-settings-form">
									<div class="bg360-form-row">
										<label for="ws_name"><?php esc_html_e( 'Workspace Name', 'bizgrowth360-app-builder' ); ?></label>
										<input type="text" id="ws_name" name="name" class="bg360-input" value="<?php echo esc_attr( $workspace->name ); ?>" required />
									</div>
									<div class="bg360-form-row">
										<label for="ws_business"><?php esc_html_e( 'Business / Organization Name', 'bizgrowth360-app-builder' ); ?></label>
										<input type="text" id="ws_business" name="business_name" class="bg360-input" value="<?php echo esc_attr( $workspace->business_name ); ?>" />
									</div>
									<div class="bg360-form-row">
										<label for="ws_phone"><?php esc_html_e( 'Contact Phone Number', 'bizgrowth360-app-builder' ); ?></label>
										<input type="tel" id="ws_phone" name="phone" class="bg360-input" value="<?php echo esc_attr( $workspace->phone ); ?>" />
									</div>
									<div class="bg360-form-actions">
										<button type="submit" class="bg360-btn bg360-btn-primary" id="bg360-save-ws-btn">
											<span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Update Workspace Profile', 'bizgrowth360-app-builder' ); ?>
										</button>
										<span id="bg360-ws-status-text"></span>
									</div>
								</form>
							</div>
						</div>
					</section>
				</div>
			</main>
		</div>
		<?php
	}

	/**
	 * Unauthenticated Auth Views (Login, Register, Password Reset).
	 */
	public static function render_auth_views() {
		$allow_registration = SaasAuth::is_registration_enabled();
		?>
		<div class="bg360-auth-wrapper">
			<div class="bg360-auth-card">
				<div class="bg360-auth-brand">
					<div class="bg360-brand-icon">
						<span class="dashicons dashicons-smartphone"></span>
					</div>
					<h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
					<p><?php esc_html_e( 'Android App Builder SaaS Portal', 'bizgrowth360-app-builder' ); ?></p>
				</div>

				<div class="bg360-auth-tabs">
					<button type="button" class="bg360-auth-tab active" data-auth-target="login"><?php esc_html_e( 'Sign In', 'bizgrowth360-app-builder' ); ?></button>
					<?php if ( $allow_registration ) : ?>
						<button type="button" class="bg360-auth-tab" data-auth-target="register"><?php esc_html_e( 'Create Account', 'bizgrowth360-app-builder' ); ?></button>
					<?php endif; ?>
				</div>

				<div id="bg360-auth-alert-zone"></div>

				<!-- LOGIN FORM -->
				<form id="bg360-login-form" class="bg360-auth-form active">
					<div class="bg360-form-row">
						<label for="login_user"><?php esc_html_e( 'Email or Username', 'bizgrowth360-app-builder' ); ?></label>
						<input type="text" id="login_user" name="username" class="bg360-input" required autocomplete="username" />
					</div>
					<div class="bg360-form-row">
						<label for="login_pass"><?php esc_html_e( 'Password', 'bizgrowth360-app-builder' ); ?></label>
						<input type="password" id="login_pass" name="password" class="bg360-input" required autocomplete="current-password" />
					</div>
					<div class="bg360-auth-options">
						<label><input type="checkbox" name="remember" value="1" /> <?php esc_html_e( 'Remember me', 'bizgrowth360-app-builder' ); ?></label>
						<a href="#reset" id="bg360-open-reset-btn"><?php esc_html_e( 'Forgot password?', 'bizgrowth360-app-builder' ); ?></a>
					</div>
					<button type="submit" class="bg360-btn bg360-btn-primary bg360-btn-block" id="bg360-login-btn">
						<?php esc_html_e( 'Sign In to Workspace', 'bizgrowth360-app-builder' ); ?>
					</button>
				</form>

				<!-- REGISTER FORM -->
				<?php if ( $allow_registration ) : ?>
					<form id="bg360-register-form" class="bg360-auth-form">
						<div class="bg360-form-row">
							<label for="reg_name"><?php esc_html_e( 'Full Name', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
							<input type="text" id="reg_name" name="full_name" class="bg360-input" required />
						</div>
						<div class="bg360-form-row">
							<label for="reg_business"><?php esc_html_e( 'Business / App Brand Name', 'bizgrowth360-app-builder' ); ?></label>
							<input type="text" id="reg_business" name="business_name" class="bg360-input" placeholder="e.g. SaveMart Store" />
						</div>
						<div class="bg360-form-row">
							<label for="reg_email"><?php esc_html_e( 'Email Address', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
							<input type="email" id="reg_email" name="email" class="bg360-input" required autocomplete="email" />
						</div>
						<div class="bg360-form-row">
							<label for="reg_phone"><?php esc_html_e( 'Phone Number', 'bizgrowth360-app-builder' ); ?></label>
							<input type="tel" id="reg_phone" name="phone" class="bg360-input" />
						</div>
						<div class="bg360-form-row-2col">
							<div>
								<label for="reg_pass"><?php esc_html_e( 'Password (Min 8 chars)', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
								<input type="password" id="reg_pass" name="password" class="bg360-input" required minlength="8" autocomplete="new-password" />
							</div>
							<div>
								<label for="reg_pass_confirm"><?php esc_html_e( 'Confirm Password', 'bizgrowth360-app-builder' ); ?> <span class="bg360-required">*</span></label>
								<input type="password" id="reg_pass_confirm" name="confirm_password" class="bg360-input" required minlength="8" autocomplete="new-password" />
							</div>
						</div>
						<button type="submit" class="bg360-btn bg360-btn-primary bg360-btn-block" id="bg360-register-btn">
							<?php esc_html_e( 'Register Free Workspace', 'bizgrowth360-app-builder' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<!-- FORGOT PASSWORD FORM -->
				<form id="bg360-reset-form" class="bg360-auth-form">
					<p class="description"><?php esc_html_e( 'Enter your account email address and we will send you a password reset link.', 'bizgrowth360-app-builder' ); ?></p>
					<div class="bg360-form-row">
						<label for="reset_email"><?php esc_html_e( 'Account Email', 'bizgrowth360-app-builder' ); ?></label>
						<input type="email" id="reset_email" name="email" class="bg360-input" required />
					</div>
					<button type="submit" class="bg360-btn bg360-btn-primary bg360-btn-block" id="bg360-reset-btn">
						<?php esc_html_e( 'Send Reset Instructions', 'bizgrowth360-app-builder' ); ?>
					</button>
					<div style="text-align: center; margin-top: 12px;">
						<a href="#login" id="bg360-back-to-login-btn">&larr; <?php esc_html_e( 'Back to Sign In', 'bizgrowth360-app-builder' ); ?></a>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------------
	 * Customer Portal AJAX Handlers (Strictly Tenant-Isolated)
	 * ------------------------------------------------------------------------ */

	private static function check_customer_auth() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'bizgrowth360-app-builder' ) ), 401 );
		}
		check_ajax_referer( 'bizgrowth360_portal_nonce', 'nonce' );

		$workspace = TenantManager::get_current_workspace();
		if ( ! $workspace ) {
			wp_send_json_error( array( 'message' => __( 'No active workspace found.', 'bizgrowth360-app-builder' ) ), 403 );
		}

		if ( 'suspended' === $workspace->status && ! TenantManager::is_super_admin() ) {
			wp_send_json_error( array( 'message' => __( 'Workspace account is suspended.', 'bizgrowth360-app-builder' ) ), 403 );
		}

		return $workspace;
	}

	public static function ajax_get_dashboard() {
		$workspace = self::check_customer_auth();
		$summary   = SaasDb::get_workspace_usage_summary( $workspace->id );
		$apps      = SaasDb::get_apps_by_workspace( $workspace->id );

		wp_send_json_success(
			array(
				'workspace' => $workspace,
				'summary'   => $summary,
				'apps'      => $apps,
			)
		);
	}

	public static function ajax_get_apps() {
		$workspace = self::check_customer_auth();
		$apps      = SaasDb::get_apps_by_workspace( $workspace->id );
		wp_send_json_success( array( 'apps' => $apps ) );
	}

	public static function ajax_save_app() {
		$workspace = self::check_customer_auth();
		$app_id    = isset( $_POST['app_id'] ) ? absint( $_POST['app_id'] ) : 0;

		$app_name    = sanitize_text_field( $_POST['app_name'] ?? '' );
		$package_id  = sanitize_text_field( $_POST['package_id'] ?? '' );
		$website_url = esc_url_raw( $_POST['website_url'] ?? '' );

		if ( empty( $app_name ) || empty( $package_id ) || empty( $website_url ) ) {
			wp_send_json_error( array( 'message' => __( 'App Name, Package ID, and Website URL are required.', 'bizgrowth360-app-builder' ) ) );
		}

		if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $package_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Package ID format. Must follow reverse-domain style (e.g. com.example.app).', 'bizgrowth360-app-builder' ) ) );
		}

		$data = array(
			'app_name'             => $app_name,
			'package_id'           => $package_id,
			'website_url'          => $website_url,
			'version_name'         => sanitize_text_field( $_POST['version_name'] ?? '1.0.0' ),
			'version_code'         => max( 1, absint( $_POST['version_code'] ?? 1 ) ),
			'primary_color'        => sanitize_hex_color( $_POST['primary_color'] ?? '#7C3AED' ) ?: '#7C3AED',
			'status_bar_color'     => sanitize_hex_color( $_POST['status_bar_color'] ?? '#6D28D9' ) ?: '#6D28D9',
			'app_icon'             => esc_url_raw( $_POST['app_icon'] ?? '' ),
			'splash_image'         => esc_url_raw( $_POST['splash_image'] ?? '' ),
			'orientation'          => in_array( $_POST['orientation'] ?? 'portrait', array( 'portrait', 'landscape', 'sensor' ), true ) ? $_POST['orientation'] : 'portrait',
		);

		if ( $app_id > 0 ) {
			// Update existing: Check tenant ownership!
			$app_access = TenantManager::validate_app_access( $app_id );
			if ( is_wp_error( $app_access ) ) {
				wp_send_json_error( array( 'message' => $app_access->get_error_message() ), $app_access->get_error_data()['status'] ?? 403 );
			}

			SaasDb::update_app( $app_id, $workspace->id, $data );
			$saved_id = $app_id;
			$message  = __( 'Application updated successfully!', 'bizgrowth360-app-builder' );
		} else {
			// Create new: Check plan limit!
			$can_create = TenantManager::can_create_app( $workspace->id );
			if ( is_wp_error( $can_create ) ) {
				wp_send_json_error( array( 'message' => $can_create->get_error_message() ), 403 );
			}

			$saved_id = SaasDb::create_app( $workspace->id, $data );
			$message  = __( 'Application created successfully!', 'bizgrowth360-app-builder' );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'app_id'  => $saved_id,
				'app'     => SaasDb::get_app( $saved_id ),
			)
		);
	}

	public static function ajax_delete_app() {
		$workspace = self::check_customer_auth();
		$app_id    = absint( $_POST['app_id'] ?? 0 );

		$app_access = TenantManager::validate_app_access( $app_id );
		if ( is_wp_error( $app_access ) ) {
			wp_send_json_error( array( 'message' => $app_access->get_error_message() ), 403 );
		}

		SaasDb::delete_app( $app_id, $workspace->id );
		wp_send_json_success( array( 'message' => __( 'Application deleted successfully.', 'bizgrowth360-app-builder' ) ) );
	}

	public static function ajax_clone_app() {
		$workspace = self::check_customer_auth();
		$app_id    = absint( $_POST['app_id'] ?? 0 );

		$app = TenantManager::validate_app_access( $app_id );
		if ( is_wp_error( $app ) ) {
			wp_send_json_error( array( 'message' => $app->get_error_message() ), 403 );
		}

		$can_create = TenantManager::can_create_app( $workspace->id );
		if ( is_wp_error( $can_create ) ) {
			wp_send_json_error( array( 'message' => $can_create->get_error_message() ), 403 );
		}

		$clone_data = (array) $app;
		unset( $clone_data['id'], $clone_data['created_at'], $clone_data['updated_at'] );
		$clone_data['app_name']   = $app->app_name . ' (Copy)';
		$clone_data['package_id'] = $app->package_id . '.copy';

		$new_id = SaasDb::create_app( $workspace->id, $clone_data );
		wp_send_json_success(
			array(
				'message' => __( 'Application cloned successfully!', 'bizgrowth360-app-builder' ),
				'app_id'  => $new_id,
			)
		);
	}

	/**
	 * Trigger build from customer portal with strict quota and tenant attribution.
	 */
	public static function ajax_trigger_build() {
		$workspace = self::check_customer_auth();
		$app_id    = absint( $_POST['app_id'] ?? 0 );
		$target    = sanitize_key( $_POST['target'] ?? 'both' );

		$app = TenantManager::validate_app_access( $app_id );
		if ( is_wp_error( $app ) ) {
			wp_send_json_error( array( 'message' => $app->get_error_message() ), 403 );
		}

		// Enforce monthly build limits!
		$can_build = TenantManager::can_trigger_build( $workspace->id );
		if ( is_wp_error( $can_build ) ) {
			wp_send_json_error( array( 'message' => $can_build->get_error_message() ), 403 );
		}

		// Construct build payload with tenant ownership
		$build_data = array(
			'workspace_id'    => $workspace->id,
			'app_id'          => $app->id,
			'app_name'        => $app->app_name,
			'package_id'      => $app->package_id,
			'platform'        => 'android',
			'website_url'     => $app->website_url,
			'version_name'    => $app->version_name,
			'version_code'    => $app->version_code,
			'build_type'      => $target,
			'build_variant'   => 'release',
			'status'          => 'queued',
			'stage'           => 'init',
			'config_snapshot' => array(
				'app_name'             => $app->app_name,
				'package_id'           => $app->package_id,
				'website_url'          => $app->website_url,
				'version_name'         => $app->version_name,
				'version_code'         => $app->version_code,
				'primary_color'        => $app->primary_color,
				'status_bar_color'     => $app->status_bar_color,
				'navigation_bar_color' => $app->navigation_bar_color,
				'background_color'     => $app->background_color,
				'app_icon'             => $app->app_icon,
				'splash_image'         => $app->splash_image,
				'allow_external_links' => $app->allow_external_links,
				'show_status_bar'      => $app->show_status_bar,
				'orientation'          => $app->orientation,
				'design_config'        => json_decode( $app->design_json ?? '{}', true ) ?: array(),
				'native_features'      => json_decode( $app->features_json ?? '{}', true ) ?: array(),
			),
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql' ),
		);

		$record_id = BuildDb::create_build( $build_data );
		if ( ! $record_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to initialize build request in database.', 'bizgrowth360-app-builder' ) ) );
		}

		// Fetch the full build record
		$created_build = BuildDb::get_build_by_id( $record_id );

		// Dispatch via GitHub Actions Client
		$github_client = new GitHubClient();
		$dispatched    = $github_client->dispatch_build(
			$created_build->build_id,
			$app->app_name,
			$app->package_id,
			$app->website_url,
			$app->version_name,
			(string) $app->version_code,
			$target,
			'release',
			$app->primary_color,
			$app->app_icon,
			$app->splash_image,
			$app->orientation,
			$app->allow_external_links ? 'true' : 'false',
			$app->show_status_bar ? 'true' : 'false',
			$app->design_json,
			$app->features_json
		);

		if ( is_wp_error( $dispatched ) ) {
			BuildDb::update_build(
				$created_build->build_id,
				array(
					'status'        => 'failed',
					'stage'         => 'dispatch_error',
					'error_message' => $dispatched->get_error_message(),
					'completed_at'  => current_time( 'mysql' ),
				)
			);
			SaasDb::log_usage( $workspace->id, 'build_failed', 1, array( 'build_id' => $created_build->build_id, 'app_id' => $app->id ) );
			wp_send_json_error( array( 'message' => __( 'Failed to dispatch workflow to cloud builder: ', 'bizgrowth360-app-builder' ) . $dispatched->get_error_message() ) );
		}

		BuildDb::update_build(
			$created_build->build_id,
			array(
				'status'     => 'dispatching',
				'stage'      => 'workflow_dispatched',
				'started_at' => current_time( 'mysql' ),
			)
		);

		SaasDb::log_usage( $workspace->id, 'build_initiated', 1, array( 'build_id' => $created_build->build_id, 'app_id' => $app->id ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Cloud build initiated! Runner is compiling your app...', 'bizgrowth360-app-builder' ),
				'build_id' => $created_build->build_id,
				'build'    => BuildDb::get_build( $created_build->build_id, $workspace->id ),
			)
		);
	}

	public static function ajax_get_build_status() {
		$workspace = self::check_customer_auth();
		$build_id  = sanitize_text_field( $_POST['build_id'] ?? '' );

		if ( ! empty( $build_id ) ) {
			$build = TenantManager::validate_build_access( $build_id );
			if ( is_wp_error( $build ) ) {
				wp_send_json_error( array( 'message' => $build->get_error_message() ), 403 );
			}
		} else {
			$build = BuildDb::get_active_build( $workspace->id );
		}

		wp_send_json_success( array( 'build' => $build ) );
	}

	public static function ajax_get_builds() {
		$workspace = self::check_customer_auth();
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page  = max( 1, min( 50, absint( $_POST['per_page'] ?? 10 ) ) );
		$app_id    = isset( $_POST['app_id'] ) ? absint( $_POST['app_id'] ) : null;

		$results = BuildDb::get_builds(
			array(
				'workspace_id' => $workspace->id,
				'app_id'       => $app_id,
				'page'         => $page,
				'per_page'     => $per_page,
			)
		);

		wp_send_json_success( $results );
	}

	public static function ajax_update_workspace() {
		$workspace = self::check_customer_auth();

		$name          = sanitize_text_field( $_POST['name'] ?? '' );
		$business_name = sanitize_text_field( $_POST['business_name'] ?? '' );
		$phone         = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Workspace name is required.', 'bizgrowth360-app-builder' ) ) );
		}

		SaasDb::update_workspace(
			$workspace->id,
			array(
				'name'          => $name,
				'business_name' => $business_name,
				'phone'         => $phone,
			)
		);

		wp_send_json_success(
			array(
				'message'   => __( 'Workspace settings saved successfully!', 'bizgrowth360-app-builder' ),
				'workspace' => SaasDb::get_workspace( $workspace->id ),
			)
		);
	}
}
