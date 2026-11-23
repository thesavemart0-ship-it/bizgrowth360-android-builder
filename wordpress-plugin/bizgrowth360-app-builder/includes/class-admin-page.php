<?php
/**
 * Admin page controller and view renderer.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPage {

	private $settings;
	private $github_client;

	public function __construct( Settings $settings, GitHubClient $github_client ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_bizgrowth360_download_artifact', array( $this, 'handle_artifact_download' ) );
	}

	/**
	 * Register menu item.
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'Bizgrowth360 App Builder', 'bizgrowth360-app-builder' ),
			__( 'App Builder', 'bizgrowth360-app-builder' ),
			'manage_options',
			'bizgrowth360-app-builder',
			array( $this, 'render_page' ),
			'dashicons-smartphone',
			30
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_bizgrowth360-app-builder' !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_style(
			'bizgrowth360-admin-css',
			BIZGROWTH360_APP_BUILDER_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			BIZGROWTH360_APP_BUILDER_VERSION
		);

		wp_enqueue_script(
			'bizgrowth360-admin-js',
			BIZGROWTH360_APP_BUILDER_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			BIZGROWTH360_APP_BUILDER_VERSION,
			true
		);

		wp_localize_script(
			'bizgrowth360-admin-js',
			'bizgrowth360AppBuilder',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'bizgrowth360_app_builder_nonce' ),
				'download_base_url' => admin_url( 'admin-post.php?action=bizgrowth360_download_artifact' ),
				'download_nonce'    => wp_create_nonce( 'bizgrowth360_download_artifact' ),
				'has_token'         => Config::has_token(),
				'strings'           => array(
					'service_not_configured' => __( 'Build service is not configured. Please define BIZGROWTH360_GITHUB_TOKEN in wp-config.php.', 'bizgrowth360-app-builder' ),
					'triggering_build'       => __( 'Dispatching build request to the cloud compiler...', 'bizgrowth360-app-builder' ),
					'build_queued'           => __( 'Build dispatched successfully! Monitoring progress...', 'bizgrowth360-app-builder' ),
					'build_in_progress'      => __( 'Building Android packages in cloud runner...', 'bizgrowth360-app-builder' ),
					'build_success'          => __( 'Build Succeeded! Android packages are ready for download.', 'bizgrowth360-app-builder' ),
					'build_failed'           => __( 'Build Failed. Please verify app configuration and retry.', 'bizgrowth360-app-builder' ),
					'confirm_build'          => __( 'Are you sure you want to trigger a new Android build now?', 'bizgrowth360-app-builder' ),
					'select_icon'            => __( 'Select App Icon', 'bizgrowth360-app-builder' ),
					'select_splash'          => __( 'Select Splash Image', 'bizgrowth360-app-builder' ),
					'use_image'              => __( 'Use This Image', 'bizgrowth360-app-builder' ),
				),
			)
		);
	}

	/**
	 * Handle secure artifact download proxy.
	 */
	public function handle_artifact_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'bizgrowth360-app-builder' ), 403 );
		}

		check_admin_referer( 'bizgrowth360_download_artifact' );

		$artifact_id = isset( $_GET['artifact_id'] ) ? absint( $_GET['artifact_id'] ) : 0;
		if ( ! $artifact_id ) {
			wp_die( esc_html__( 'Invalid artifact ID.', 'bizgrowth360-app-builder' ), 400 );
		}

		$direct_url = $this->github_client->get_artifact_direct_url( $artifact_id );

		if ( ! empty( $direct_url ) ) {
			wp_redirect( $direct_url );
			exit;
		}

		wp_die(
			esc_html__( 'Unable to retrieve artifact download. The file may have expired or is still being generated. Please re-run the build.', 'bizgrowth360-app-builder' ),
			esc_html__( 'Download Unavailable', 'bizgrowth360-app-builder' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Render the main admin dashboard page.
	 */
	public function render_page() {
		$options = $this->settings->get_options();
		?>
		<div class="wrap bizgrowth360-admin-wrap">
			<!-- Header -->
			<div class="bizgrowth360-header">
				<div class="bizgrowth360-brand">
					<div class="bizgrowth360-logo">
						<span class="dashicons dashicons-smartphone"></span>
					</div>
					<div>
						<h1><?php esc_html_e( 'Bizgrowth360 Android App Builder', 'bizgrowth360-app-builder' ); ?></h1>
						<p class="bizgrowth360-tagline"><?php esc_html_e( 'Convert your WordPress website into a native Android APK & Play Store AAB with one click.', 'bizgrowth360-app-builder' ); ?></p>
					</div>
				</div>
				<div class="bizgrowth360-header-actions">
					<span id="bg360-status-badge" class="bg360-badge bg360-badge-idle">
						<span class="bg360-dot"></span> <?php esc_html_e( 'Engine Ready', 'bizgrowth360-app-builder' ); ?>
					</span>
					<button type="button" id="bg360-trigger-build-btn" class="button button-primary bg360-btn-large">
						<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Build Android App Now', 'bizgrowth360-app-builder' ); ?>
					</button>
				</div>
			</div>

			<!-- System Notice for Unconfigured Token -->
			<?php if ( ! Config::has_token() ) : ?>
				<div class="notice notice-warning inline" style="margin: 16px 0; padding: 12px 16px; border-left-color: #F59E0B;">
					<p style="margin: 0; font-size: 13px;">
						<strong><?php esc_html_e( 'Build Service Notice:', 'bizgrowth360-app-builder' ); ?></strong>
						<?php esc_html_e( 'The cloud build engine is not configured yet. To enable builds, please define BIZGROWTH360_GITHUB_TOKEN in your wp-config.php file.', 'bizgrowth360-app-builder' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Notice Container -->
			<div id="bg360-notice-container"></div>

			<!-- Navigation Tabs -->
			<h2 class="nav-tab-wrapper bizgrowth360-nav-tabs">
				<a href="#tab-builder" class="nav-tab nav-tab-active" data-tab="tab-builder">
					<span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'App Configuration & Live Preview', 'bizgrowth360-app-builder' ); ?>
				</a>
				<a href="#tab-monitor" class="nav-tab" data-tab="tab-monitor">
					<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Build Center & Downloads', 'bizgrowth360-app-builder' ); ?>
				</a>
			</h2>

			<form method="post" action="options.php" id="bizgrowth360-settings-form">
				<?php settings_fields( 'bizgrowth360_app_builder_group' ); ?>

				<!-- TAB 1: App Builder & Live Preview -->
				<div id="tab-builder" class="bizgrowth360-tab-content active">
					<div class="bizgrowth360-split-layout">
						<!-- Left: Configuration Form -->
						<div class="bizgrowth360-config-pane">
							<div class="bizgrowth360-card">
								<h3><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Basic App Information', 'bizgrowth360-app-builder' ); ?></h3>

								<table class="form-table">
									<tr>
										<th scope="row"><label for="bg360_app_name"><?php esc_html_e( 'App Name', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_app_name" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[app_name]" value="<?php echo esc_attr( $options['app_name'] ); ?>" class="regular-text" required />
											<p class="description"><?php esc_html_e( 'The display name shown on Android home screen and app launcher.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_package_id"><?php esc_html_e( 'Package ID (Application ID)', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_package_id" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[package_id]" value="<?php echo esc_attr( $options['package_id'] ); ?>" class="regular-text" required pattern="^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$" />
											<p class="description"><?php esc_html_e( 'Unique reverse-domain identifier (e.g., com.townconnectai.app). Must be at least two segments.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_website_url"><?php esc_html_e( 'Website URL', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="url" id="bg360_website_url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[website_url]" value="<?php echo esc_attr( $options['website_url'] ); ?>" class="regular-text" required />
											<p class="description"><?php esc_html_e( 'The full HTTPS web address that will load inside the native Android web view.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_version_name"><?php esc_html_e( 'Version Name & Code', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_version_name" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[version_name]" value="<?php echo esc_attr( $options['version_name'] ); ?>" class="small-text" placeholder="1.0.0" />
											<input type="number" id="bg360_version_code" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[version_code]" value="<?php echo esc_attr( $options['version_code'] ); ?>" class="small-text" min="1" step="1" />
											<p class="description"><?php esc_html_e( 'Version Name (e.g. 1.0.0) shown to users, and Version Code (integer, e.g. 1) for Play Store updates.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="bizgrowth360-card">
								<h3><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Colors & UI Theme', 'bizgrowth360-app-builder' ); ?></h3>

								<table class="form-table">
									<tr>
										<th scope="row"><label for="bg360_primary_color"><?php esc_html_e( 'Primary / Brand Color', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_primary_color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[primary_color]" value="<?php echo esc_attr( $options['primary_color'] ); ?>" class="bg360-color-field" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_status_bar_color"><?php esc_html_e( 'Status Bar Color', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_status_bar_color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[status_bar_color]" value="<?php echo esc_attr( $options['status_bar_color'] ); ?>" class="bg360-color-field" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_navigation_bar_color"><?php esc_html_e( 'Navigation Bar Color', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_navigation_bar_color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[navigation_bar_color]" value="<?php echo esc_attr( $options['navigation_bar_color'] ); ?>" class="bg360-color-field" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_background_color"><?php esc_html_e( 'Splash Background Color', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_background_color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[background_color]" value="<?php echo esc_attr( $options['background_color'] ); ?>" class="bg360-color-field" />
										</td>
									</tr>
								</table>
							</div>

							<div class="bizgrowth360-card">
								<h3><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Icons & Splash Screen', 'bizgrowth360-app-builder' ); ?></h3>

								<table class="form-table">
									<tr>
										<th scope="row"><label for="bg360_app_icon"><?php esc_html_e( 'App Icon (512x512 PNG)', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<div class="bg360-media-selector">
												<input type="text" id="bg360_app_icon" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[app_icon]" value="<?php echo esc_attr( $options['app_icon'] ); ?>" class="regular-text" />
												<button type="button" class="button bg360-upload-btn" data-target="#bg360_app_icon"><?php esc_html_e( 'Choose Image', 'bizgrowth360-app-builder' ); ?></button>
											</div>
											<p class="description"><?php esc_html_e( 'Recommended: Square PNG 512x512 px. If empty, default app icons will be generated.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_splash_image"><?php esc_html_e( 'Splash Screen Image', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<div class="bg360-media-selector">
												<input type="text" id="bg360_splash_image" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[splash_image]" value="<?php echo esc_attr( $options['splash_image'] ); ?>" class="regular-text" />
												<button type="button" class="button bg360-upload-btn" data-target="#bg360_splash_image"><?php esc_html_e( 'Choose Image', 'bizgrowth360-app-builder' ); ?></button>
											</div>
											<p class="description"><?php esc_html_e( 'Centered logo or branding artwork for the Android launch screen.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
								</table>
							</div>

							<div class="bizgrowth360-card">
								<h3><span class="dashicons dashicons-hammer"></span> <?php esc_html_e( 'Build Options', 'bizgrowth360-app-builder' ); ?></h3>

								<table class="form-table">
									<tr>
										<th scope="row"><label for="bg360_build_target"><?php esc_html_e( 'Build Target', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<select id="bg360_build_target" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[build_target]">
												<option value="both" <?php selected( $options['build_target'], 'both' ); ?>><?php esc_html_e( 'Both APK (Direct Install) & AAB (Play Store)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="apk" <?php selected( $options['build_target'], 'apk' ); ?>><?php esc_html_e( 'APK Only (Fast Testing / Sideloading)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="aab" <?php selected( $options['build_target'], 'aab' ); ?>><?php esc_html_e( 'AAB Only (Google Play Console Release)', 'bizgrowth360-app-builder' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_build_type"><?php esc_html_e( 'Build Type', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<select id="bg360_build_type" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[build_type]">
												<option value="both" <?php selected( $options['build_type'], 'both' ); ?>><?php esc_html_e( 'Both (Release & Debug)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="release" <?php selected( $options['build_type'], 'release' ); ?>><?php esc_html_e( 'Release (Optimized & Signed)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="debug" <?php selected( $options['build_type'], 'debug' ); ?>><?php esc_html_e( 'Debug (Development & Quick Testing)', 'bizgrowth360-app-builder' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_orientation"><?php esc_html_e( 'Screen Orientation', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<select id="bg360_orientation" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[orientation]">
												<option value="portrait" <?php selected( $options['orientation'], 'portrait' ); ?>><?php esc_html_e( 'Portrait (Standard Mobile)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="auto" <?php selected( $options['orientation'], 'auto' ); ?>><?php esc_html_e( 'Auto / Sensor (Rotate freely)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="landscape" <?php selected( $options['orientation'], 'landscape' ); ?>><?php esc_html_e( 'Landscape Only', 'bizgrowth360-app-builder' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'External Links', 'bizgrowth360-app-builder' ); ?></th>
										<td>
											<label>
												<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[allow_external_links]" value="1" <?php checked( $options['allow_external_links'], true ); ?> />
												<?php esc_html_e( 'Open non-domain links (e.g. WhatsApp, Maps, Social Media) in external apps', 'bizgrowth360-app-builder' ); ?>
											</label>
										</td>
									</tr>
								</table>
							</div>

							<div class="bizgrowth360-form-actions">
								<?php submit_button( __( 'Save App Configuration', 'bizgrowth360-app-builder' ), 'secondary', 'submit', false ); ?>
								<button type="button" class="button button-primary bg360-btn-large bg360-trigger-action">
									<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Save & Trigger Build', 'bizgrowth360-app-builder' ); ?>
								</button>
							</div>
						</div>

						<!-- Right: Real-time Smartphone Mockup Preview -->
						<div class="bizgrowth360-preview-pane">
							<div class="bizgrowth360-preview-sticky">
								<h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Live Android Device Preview', 'bizgrowth360-app-builder' ); ?></h3>
								<div class="bg360-phone-mockup">
									<!-- Phone Notch -->
									<div class="bg360-phone-notch">
										<div class="bg360-camera"></div>
										<div class="bg360-speaker"></div>
									</div>

									<!-- Android Status Bar -->
									<div class="bg360-phone-statusbar" id="preview-statusbar" style="background-color: <?php echo esc_attr( $options['status_bar_color'] ); ?>;">
										<span class="bg360-time">10:45</span>
										<div class="bg360-status-icons">
											<span class="dashicons dashicons-networking"></span>
											<span class="dashicons dashicons-chart-bar"></span>
										</div>
									</div>

									<!-- App Header / Toolbar -->
									<div class="bg360-phone-header" id="preview-header" style="background-color: <?php echo esc_attr( $options['primary_color'] ); ?>;">
										<div class="bg360-app-icon-preview" id="preview-icon-wrapper">
											<?php if ( ! empty( $options['app_icon'] ) ) : ?>
												<img src="<?php echo esc_url( $options['app_icon'] ); ?>" alt="App Icon" id="preview-icon-img" />
											<?php else : ?>
												<span class="dashicons dashicons-smartphone" id="preview-icon-fallback"></span>
											<?php endif; ?>
										</div>
										<span class="bg360-preview-title" id="preview-title"><?php echo esc_html( $options['app_name'] ); ?></span>
									</div>

									<!-- Phone Screen Content -->
									<div class="bg360-phone-screen">
										<div class="bg360-webview-simulation">
											<div class="bg360-url-bar">
												<span class="dashicons dashicons-lock"></span>
												<span id="preview-url-text"><?php echo esc_html( $options['website_url'] ); ?></span>
											</div>
											<div class="bg360-mock-page">
												<div class="bg360-mock-hero" id="preview-mock-hero" style="background-color: <?php echo esc_attr( $options['primary_color'] ); ?>20;">
													<div class="bg360-mock-badge" style="background-color: <?php echo esc_attr( $options['primary_color'] ); ?>;"><?php esc_html_e( 'Android Web View', 'bizgrowth360-app-builder' ); ?></div>
													<h4><?php echo esc_html( $options['app_name'] ); ?></h4>
													<p><?php esc_html_e( 'Seamless native mobile experience with pull-to-refresh, back-button handling, and offline fallback.', 'bizgrowth360-app-builder' ); ?></p>
												</div>
												<div class="bg360-mock-card-list">
													<div class="bg360-mock-card">
														<div class="bg360-mock-line line-1"></div>
														<div class="bg360-mock-line line-2"></div>
													</div>
													<div class="bg360-mock-card">
														<div class="bg360-mock-line line-1"></div>
														<div class="bg360-mock-line line-3"></div>
													</div>
												</div>
											</div>
										</div>
									</div>

									<!-- Android Navigation Bar -->
									<div class="bg360-phone-navbar" id="preview-navbar" style="background-color: <?php echo esc_attr( $options['navigation_bar_color'] ); ?>;">
										<span class="dashicons dashicons-arrow-left-alt2"></span>
										<span class="dashicons dashicons-marker"></span>
										<span class="dashicons dashicons-menu"></span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</form>

			<!-- TAB 2: Build Center & Downloads -->
			<div id="tab-monitor" class="bizgrowth360-tab-content">
				<!-- Live Build Progress Card -->
				<div class="bizgrowth360-card bg360-monitor-card" id="bg360-monitor-card">
					<div class="bg360-monitor-header">
						<div>
							<h3><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Active Build Pipeline', 'bizgrowth360-app-builder' ); ?></h3>
							<p class="description" id="bg360-run-subtitle"><?php esc_html_e( 'Track the real-time progress of your cloud Android compilation.', 'bizgrowth360-app-builder' ); ?></p>
						</div>
						<div id="bg360-run-badge-container">
							<span class="bg360-badge bg360-badge-idle" id="bg360-run-badge"><?php esc_html_e( 'No Build Active', 'bizgrowth360-app-builder' ); ?></span>
						</div>
					</div>

					<div class="bg360-progress-container" id="bg360-progress-bar-wrap" style="display: none;">
						<div class="bg360-progress-bar" id="bg360-progress-bar"></div>
					</div>

					<div class="bg360-build-stages" id="bg360-build-stages">
						<div class="bg360-stage" id="stage-init">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'Queue & Dispatch', 'bizgrowth360-app-builder' ); ?></span>
						</div>
						<div class="bg360-stage" id="stage-setup">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'SDK & Dependencies', 'bizgrowth360-app-builder' ); ?></span>
						</div>
						<div class="bg360-stage" id="stage-compile">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'Gradle APK / AAB Compilation', 'bizgrowth360-app-builder' ); ?></span>
						</div>
						<div class="bg360-stage" id="stage-artifacts">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'Verification & Package Ready', 'bizgrowth360-app-builder' ); ?></span>
						</div>
					</div>

					<div class="bg360-run-meta" id="bg360-run-meta" style="display: none;">
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Build Run:', 'bizgrowth360-app-builder' ); ?></span>
							<strong id="bg360-meta-run-id">-</strong>
						</div>
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Trigger Type:', 'bizgrowth360-app-builder' ); ?></span>
							<strong id="bg360-meta-event">-</strong>
						</div>
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Elapsed Time:', 'bizgrowth360-app-builder' ); ?></span>
							<strong id="bg360-meta-time">0s</strong>
						</div>
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Status:', 'bizgrowth360-app-builder' ); ?></span>
							<strong id="bg360-meta-status-text">-</strong>
						</div>
					</div>

					<!-- Downloads Container -->
					<div class="bg360-downloads-zone" id="bg360-downloads-zone" style="display: none;">
						<h4><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Generated Android Packages Ready for Download:', 'bizgrowth360-app-builder' ); ?></h4>
						<div class="bg360-artifact-cards" id="bg360-artifact-cards">
							<!-- Dynamic artifact cards injected via JS -->
						</div>
					</div>
				</div>

				<!-- Build History Card -->
				<div class="bizgrowth360-card">
					<div class="bizgrowth360-card-header-flex">
						<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Recent Build History', 'bizgrowth360-app-builder' ); ?></h3>
						<button type="button" id="bg360-refresh-history-btn" class="button button-secondary">
							<span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Refresh History', 'bizgrowth360-app-builder' ); ?>
						</button>
					</div>

					<div class="bg360-table-responsive">
						<table class="wp-list-table widefat fixed striped" id="bg360-history-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Build #', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'Trigger', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'State', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'Result', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'Date & Time', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'Action', 'bizgrowth360-app-builder' ); ?></th>
								</tr>
							</thead>
							<tbody id="bg360-history-body">
								<tr>
									<td colspan="6" class="text-center"><?php esc_html_e( 'Click "Refresh History" or start a build to load records.', 'bizgrowth360-app-builder' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
