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
	private $build_manager;

	public function __construct( Settings $settings, GitHubClient $github_client, BuildManager $build_manager ) {
		$this->settings      = $settings;
		$this->github_client = $github_client;
		$this->build_manager = $build_manager;
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
					'triggering_build'       => __( 'Dispatching build request to cloud compiler...', 'bizgrowth360-app-builder' ),
					'build_queued'           => __( 'Build queued! Awaiting cloud runner...', 'bizgrowth360-app-builder' ),
					'build_in_progress'      => __( 'Compiling Android application in cloud runner...', 'bizgrowth360-app-builder' ),
					'build_success'          => __( 'Build Succeeded! Android packages are ready for download.', 'bizgrowth360-app-builder' ),
					'build_failed'           => __( 'Build Failed. Please verify app configuration and retry.', 'bizgrowth360-app-builder' ),
					'build_cancelled'        => __( 'Build was cancelled.', 'bizgrowth360-app-builder' ),
					'confirm_build'          => __( 'Are you sure you want to trigger a new Android build now?', 'bizgrowth360-app-builder' ),
					'select_icon'            => __( 'Select App Icon', 'bizgrowth360-app-builder' ),
					'select_splash'          => __( 'Select Splash Image', 'bizgrowth360-app-builder' ),
					'use_image'              => __( 'Use This Image', 'bizgrowth360-app-builder' ),
					'loading_logs'           => __( 'Fetching build logs from runner...', 'bizgrowth360-app-builder' ),
					'confirm_cancel'         => __( 'Are you sure you want to cancel this active build?', 'bizgrowth360-app-builder' ),
				),
			)
		);

		$design_config = new DesignConfig();
		$app_options   = $this->settings->get_options();

		wp_enqueue_style(
			'bizgrowth360-design-studio-css',
			BIZGROWTH360_APP_BUILDER_URL . 'assets/css/design-studio.css',
			array( 'bizgrowth360-admin-css' ),
			BIZGROWTH360_APP_BUILDER_VERSION
		);

		wp_enqueue_script(
			'bizgrowth360-design-studio-js',
			BIZGROWTH360_APP_BUILDER_URL . 'assets/js/design-studio.js',
			array( 'jquery' ),
			BIZGROWTH360_APP_BUILDER_VERSION,
			true
		);

		wp_localize_script(
			'bizgrowth360-design-studio-js',
			'bizgrowth360DesignStudio',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'bizgrowth360_app_builder_nonce' ),
				'design'         => $design_config->get(),
				'defaults'       => $design_config->get_defaults(),
				'saved_at'       => (int) get_option( DesignConfig::SAVED_AT_KEY, 0 ),
				'presets'        => $this->get_design_presets(),
				'fonts'          => DesignConfig::FONTS,
				'icons'          => DesignConfig::ICONS,
				'section_types'  => DesignConfig::SECTION_TYPES,
				'menu_types'     => DesignConfig::MENU_TYPES,
				'nav_types'      => DesignConfig::NAV_TYPES,
				'nav_styles'     => DesignConfig::NAV_STYLES,
				'component_presets' => DesignConfig::COMPONENT_PRESETS,
				'section_layouts'   => DesignConfig::SECTION_LAYOUTS,
				'image_ratios'      => DesignConfig::IMAGE_RATIOS,
				'shadows'           => DesignConfig::SHADOWS,
				'card_styles'       => DesignConfig::CARD_STYLES,
				'button_styles'     => DesignConfig::BUTTON_STYLES,
				'icon_styles'       => DesignConfig::ICON_STYLES,
				'schema_version'    => DesignConfig::SCHEMA_VERSION,
				'home_url'          => home_url( '/' ),
				'app_name'          => $app_options['app_name'] ?? get_bloginfo( 'name' ),
				'website_url'       => $app_options['website_url'] ?? home_url( '/' ),
				'strings'           => array(
					'saved'            => __( 'Design saved.', 'bizgrowth360-app-builder' ),
					'saving'           => __( 'Saving design...', 'bizgrowth360-app-builder' ),
					'autosaved'        => __( 'Autosaved', 'bizgrowth360-app-builder' ),
					'unsaved'          => __( 'Unsaved changes', 'bizgrowth360-app-builder' ),
					'save_error'       => __( 'Could not save design. Please retry.', 'bizgrowth360-app-builder' ),
					'confirm_reset'    => __( 'Reset the entire design? This cannot be undone.', 'bizgrowth360-app-builder' ),
					'confirm_section'  => __( 'Reset this section?', 'bizgrowth360-app-builder' ),
					'discard'          => __( 'Discard unsaved changes?', 'bizgrowth360-app-builder' ),
					'select_image'     => __( 'Select Image', 'bizgrowth360-app-builder' ),
					'use_image'        => __( 'Use This Image', 'bizgrowth360-app-builder' ),
					'preview_only'     => __( 'Live preview - links are disabled.', 'bizgrowth360-app-builder' ),
				),
			)
		);
	}

	/**
	 * Ready-to-use theme palettes.
	 *
	 * @return array
	 */
	private function get_design_presets() {
		return array(
			'modern_blue' => array(
				'label'      => __( 'Modern Blue', 'bizgrowth360-app-builder' ),
				'mode'       => 'light',
				'components' => 'modern',
				'colors'     => array(
					'primary_color' => '#2563EB', 'secondary_color' => '#0F172A', 'accent_color' => '#F59E0B',
					'background_color' => '#FFFFFF', 'card_color' => '#F8FAFC', 'text_color' => '#111827',
					'heading_color' => '#0F172A', 'button_color' => '#2563EB', 'button_text_color' => '#FFFFFF',
					'border_color' => '#E2E8F0', 'status_bar_color' => '#2563EB', 'navigation_bar_color' => '#FFFFFF',
				),
			),
			'business_green' => array(
				'label'      => __( 'Business Green', 'bizgrowth360-app-builder' ),
				'mode'       => 'light',
				'components' => 'rounded',
				'colors'     => array(
					'primary_color' => '#059669', 'secondary_color' => '#064E3B', 'accent_color' => '#F59E0B',
					'background_color' => '#FFFFFF', 'card_color' => '#F0FDF4', 'text_color' => '#111827',
					'heading_color' => '#064E3B', 'button_color' => '#059669', 'button_text_color' => '#FFFFFF',
					'border_color' => '#D1FAE5', 'status_bar_color' => '#065F46', 'navigation_bar_color' => '#FFFFFF',
				),
			),
			'elegant_black' => array(
				'label'      => __( 'Elegant Black', 'bizgrowth360-app-builder' ),
				'mode'       => 'dark',
				'components' => 'premium',
				'colors'     => array(
					'primary_color' => '#F59E0B', 'secondary_color' => '#E5E7EB', 'accent_color' => '#FBBF24',
					'background_color' => '#0B1220', 'card_color' => '#111827', 'text_color' => '#E5E7EB',
					'heading_color' => '#F9FAFB', 'button_color' => '#F59E0B', 'button_text_color' => '#0B1220',
					'border_color' => '#1F2937', 'status_bar_color' => '#0B1220', 'navigation_bar_color' => '#0B1220',
				),
			),
			'minimal_white' => array(
				'label'      => __( 'Minimal White', 'bizgrowth360-app-builder' ),
				'mode'       => 'light',
				'components' => 'minimal',
				'colors'     => array(
					'primary_color' => '#0F172A', 'secondary_color' => '#334155', 'accent_color' => '#2563EB',
					'background_color' => '#FFFFFF', 'card_color' => '#FFFFFF', 'text_color' => '#111827',
					'heading_color' => '#0F172A', 'button_color' => '#0F172A', 'button_text_color' => '#FFFFFF',
					'border_color' => '#E5E7EB', 'status_bar_color' => '#FFFFFF', 'navigation_bar_color' => '#FFFFFF',
				),
			),
			'premium_gold' => array(
				'label'      => __( 'Premium Gold', 'bizgrowth360-app-builder' ),
				'mode'       => 'light',
				'components' => 'premium',
				'colors'     => array(
					'primary_color' => '#A16207', 'secondary_color' => '#1C1917', 'accent_color' => '#D4AF37',
					'background_color' => '#FFFBEB', 'card_color' => '#FEF3C7', 'text_color' => '#1C1917',
					'heading_color' => '#78350F', 'button_color' => '#A16207', 'button_text_color' => '#FFFFFF',
					'border_color' => '#FDE68A', 'status_bar_color' => '#A16207', 'navigation_bar_color' => '#FFFBEB',
				),
			),
			'vibrant_orange' => array(
				'label'      => __( 'Vibrant Orange', 'bizgrowth360-app-builder' ),
				'mode'       => 'light',
				'components' => 'material',
				'colors'     => array(
					'primary_color' => '#EA580C', 'secondary_color' => '#7C2D12', 'accent_color' => '#F59E0B',
					'background_color' => '#FFF7ED', 'card_color' => '#FFEDD5', 'text_color' => '#1C1917',
					'heading_color' => '#7C2D12', 'button_color' => '#EA580C', 'button_text_color' => '#FFFFFF',
					'border_color' => '#FED7AA', 'status_bar_color' => '#EA580C', 'navigation_bar_color' => '#FFF7ED',
				),
			),
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
		$build_id    = isset( $_GET['build_id'] ) ? sanitize_text_field( wp_unslash( $_GET['build_id'] ) ) : '';
		$type        = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';

		if ( ! $artifact_id && ! empty( $build_id ) ) {
			$build = BuildDb::get_build( $build_id );
			if ( $build && ! empty( $build->artifacts ) ) {
				foreach ( $build->artifacts as $art ) {
					$is_apk = false !== stripos( $art['name'], 'apk' );
					$is_aab = false !== stripos( $art['name'], 'aab' );
					if ( ( 'apk' === $type && $is_apk ) || ( 'aab' === $type && $is_aab ) ) {
						$artifact_id = absint( $art['id'] );
						break;
					}
				}
			}
		}

		if ( ! $artifact_id ) {
			wp_die( esc_html__( 'Invalid artifact ID or package not found.', 'bizgrowth360-app-builder' ), 400 );
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
					<!-- 3 Direct Build Buttons -->
					<div class="bg360-build-button-group">
						<button type="button" class="button button-secondary bg360-open-confirm-btn" data-build-target="apk">
							<span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Build APK', 'bizgrowth360-app-builder' ); ?>
						</button>
						<button type="button" class="button button-secondary bg360-open-confirm-btn" data-build-target="aab">
							<span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Build AAB', 'bizgrowth360-app-builder' ); ?>
						</button>
						<button type="button" class="button button-primary bg360-btn-large bg360-open-confirm-btn" data-build-target="both">
							<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Build Both', 'bizgrowth360-app-builder' ); ?>
						</button>
					</div>
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
				<a href="#tab-design" class="nav-tab" data-tab="tab-design">
					<span class="dashicons dashicons-art"></span> <?php esc_html_e( 'App Design Studio', 'bizgrowth360-app-builder' ); ?>
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
											<input type="text" id="bg360_app_name" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[app_name]" value="<?php echo esc_attr( $options['app_name'] ); ?>" class="regular-text" required maxlength="50" />
											<p class="description"><?php esc_html_e( 'The display name shown on Android home screen and app launcher.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_package_id"><?php esc_html_e( 'Package ID (Application ID)', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_package_id" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[package_id]" value="<?php echo esc_attr( $options['package_id'] ); ?>" class="regular-text" required pattern="^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$" />
											<p class="description"><?php esc_html_e( 'Unique reverse-domain identifier (e.g., com.townconnectai.app). Must have at least two segments.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_website_url"><?php esc_html_e( 'Website URL', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="url" id="bg360_website_url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[website_url]" value="<?php echo esc_attr( $options['website_url'] ); ?>" class="regular-text" required placeholder="https://example.com" />
											<p class="description"><?php esc_html_e( 'The full HTTPS web address that will load inside the native Android web view.', 'bizgrowth360-app-builder' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_version_name"><?php esc_html_e( 'Version Name & Code', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<input type="text" id="bg360_version_name" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[version_name]" value="<?php echo esc_attr( $options['version_name'] ); ?>" class="small-text" placeholder="1.0.0" />
											<input type="number" id="bg360_version_code" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[version_code]" value="<?php echo esc_attr( $options['version_code'] ); ?>" class="small-text" min="1" step="1" />
											<p class="description"><?php esc_html_e( 'Version Name (e.g. 1.0.0) shown to users, and Version Code (integer >= 1) for updates.', 'bizgrowth360-app-builder' ); ?></p>
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
								<h3><span class="dashicons dashicons-hammer"></span> <?php esc_html_e( 'Default Build Configuration', 'bizgrowth360-app-builder' ); ?></h3>

								<table class="form-table">
									<tr>
										<th scope="row"><label for="bg360_build_target"><?php esc_html_e( 'Default Target', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<select id="bg360_build_target" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[build_target]">
												<option value="both" <?php selected( $options['build_target'], 'both' ); ?>><?php esc_html_e( 'Both APK (Direct Install) & AAB (Play Store)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="apk" <?php selected( $options['build_target'], 'apk' ); ?>><?php esc_html_e( 'APK Only (Fast Testing / Sideloading)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="aab" <?php selected( $options['build_target'], 'aab' ); ?>><?php esc_html_e( 'AAB Only (Google Play Console Release)', 'bizgrowth360-app-builder' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="bg360_build_type"><?php esc_html_e( 'Build Variant', 'bizgrowth360-app-builder' ); ?></label></th>
										<td>
											<select id="bg360_build_type" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[build_type]">
												<option value="release" <?php selected( $options['build_type'], 'release' ); ?>><?php esc_html_e( 'Release (Optimized & Signed)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="debug" <?php selected( $options['build_type'], 'debug' ); ?>><?php esc_html_e( 'Debug (Development & Quick Testing)', 'bizgrowth360-app-builder' ); ?></option>
												<option value="both" <?php selected( $options['build_type'], 'both' ); ?>><?php esc_html_e( 'Both (Release & Debug)', 'bizgrowth360-app-builder' ); ?></option>
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
												<input type="checkbox" id="bg360_allow_external_links" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[allow_external_links]" value="1" <?php checked( $options['allow_external_links'], true ); ?> />
												<?php esc_html_e( 'Open non-domain links (e.g. WhatsApp, Maps, Social Media) in external apps', 'bizgrowth360-app-builder' ); ?>
											</label>
										</td>
									</tr>
								</table>
							</div>

							<div class="bizgrowth360-form-actions">
								<?php submit_button( __( 'Save App Configuration', 'bizgrowth360-app-builder' ), 'secondary', 'submit', false ); ?>
								<button type="button" class="button button-primary bg360-btn-large bg360-open-confirm-btn" data-build-target="both">
									<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Save & Start Build', 'bizgrowth360-app-builder' ); ?>
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

			<?php $this->render_design_studio(); ?>

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
							<button type="button" id="bg360-cancel-build-btn" class="button button-link-delete" style="display:none; margin-left: 10px;">
								<span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Cancel Build', 'bizgrowth360-app-builder' ); ?>
							</button>
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
							<span class="bg360-stage-label"><?php esc_html_e( 'Gradle Compilation', 'bizgrowth360-app-builder' ); ?></span>
						</div>
						<div class="bg360-stage" id="stage-signing">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'Signing & Keystore', 'bizgrowth360-app-builder' ); ?></span>
						</div>
						<div class="bg360-stage" id="stage-artifacts">
							<span class="bg360-stage-dot"></span>
							<span class="bg360-stage-label"><?php esc_html_e( 'Packages Ready', 'bizgrowth360-app-builder' ); ?></span>
						</div>
					</div>

					<div class="bg360-run-meta" id="bg360-run-meta" style="display: none;">
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Build ID:', 'bizgrowth360-app-builder' ); ?></span>
							<strong id="bg360-meta-run-id">-</strong>
						</div>
						<div class="bg360-meta-item">
							<span class="bg360-meta-label"><?php esc_html_e( 'Target & Variant:', 'bizgrowth360-app-builder' ); ?></span>
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

				<!-- Build Center History & Management -->
				<div class="bizgrowth360-card">
					<div class="bizgrowth360-card-header-flex">
						<div>
							<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Build Center & History', 'bizgrowth360-app-builder' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Review past builds, inspect immutable snapshots, view technical logs, and download Android packages.', 'bizgrowth360-app-builder' ); ?></p>
						</div>
						<div class="bg360-filter-controls">
							<input type="search" id="bg360-filter-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search app, package or build ID...', 'bizgrowth360-app-builder' ); ?>" style="max-width:220px;" />
							<select id="bg360-filter-status">
								<option value="all"><?php esc_html_e( 'All Statuses', 'bizgrowth360-app-builder' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Completed / Succeeded', 'bizgrowth360-app-builder' ); ?></option>
								<option value="active"><?php esc_html_e( 'In Progress / Queued', 'bizgrowth360-app-builder' ); ?></option>
								<option value="failed"><?php esc_html_e( 'Failed', 'bizgrowth360-app-builder' ); ?></option>
								<option value="cancelled"><?php esc_html_e( 'Cancelled', 'bizgrowth360-app-builder' ); ?></option>
							</select>
							<select id="bg360-filter-target">
								<option value="all"><?php esc_html_e( 'All Targets', 'bizgrowth360-app-builder' ); ?></option>
								<option value="both"><?php esc_html_e( 'Both (APK + AAB)', 'bizgrowth360-app-builder' ); ?></option>
								<option value="apk"><?php esc_html_e( 'APK Only', 'bizgrowth360-app-builder' ); ?></option>
								<option value="aab"><?php esc_html_e( 'AAB Only', 'bizgrowth360-app-builder' ); ?></option>
							</select>
							<button type="button" id="bg360-refresh-history-btn" class="button button-secondary">
								<span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Refresh', 'bizgrowth360-app-builder' ); ?>
							</button>
						</div>
					</div>

					<div class="bg360-table-responsive" style="margin-top:15px;">
						<table class="wp-list-table widefat fixed striped" id="bg360-history-table">
							<thead>
								<tr>
									<th style="width:130px;"><?php esc_html_e( 'Build ID', 'bizgrowth360-app-builder' ); ?></th>
									<th><?php esc_html_e( 'App Name & Version', 'bizgrowth360-app-builder' ); ?></th>
									<th style="width:110px;"><?php esc_html_e( 'Target', 'bizgrowth360-app-builder' ); ?></th>
									<th style="width:110px;"><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th>
									<th style="width:90px;"><?php esc_html_e( 'Duration', 'bizgrowth360-app-builder' ); ?></th>
									<th style="width:150px;"><?php esc_html_e( 'Date & Time', 'bizgrowth360-app-builder' ); ?></th>
									<th style="width:230px;"><?php esc_html_e( 'Actions', 'bizgrowth360-app-builder' ); ?></th>
								</tr>
							</thead>
							<tbody id="bg360-history-body">
								<tr>
									<td colspan="7" class="text-center"><?php esc_html_e( 'Loading build history...', 'bizgrowth360-app-builder' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- Pagination -->
					<div class="tablenav" id="bg360-history-pagination" style="display:none; margin-top:10px;">
						<div class="tablenav-pages">
							<span class="displaying-num" id="bg360-pagination-count">0 items</span>
							<span class="pagination-links">
								<button type="button" class="button prev-page" id="bg360-page-prev"><span class="dashicons dashicons-arrow-left-alt2"></span></button>
								<span class="paging-input">
									<span class="current-page" id="bg360-current-page">1</span> <?php esc_html_e( 'of', 'bizgrowth360-app-builder' ); ?> <span class="total-pages" id="bg360-total-pages">1</span>
								</span>
								<button type="button" class="button next-page" id="bg360-page-next"><span class="dashicons dashicons-arrow-right-alt2"></span></button>
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- MODAL 1: Build Confirmation Summary -->
			<div id="bg360-confirm-modal" class="bg360-modal-overlay" style="display:none;">
				<div class="bg360-modal-box">
					<div class="bg360-modal-header">
						<h3><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Confirm Android Build Request', 'bizgrowth360-app-builder' ); ?></h3>
						<button type="button" class="bg360-modal-close" data-close="#bg360-confirm-modal">&times;</button>
					</div>
					<div class="bg360-modal-body">
						<p><?php esc_html_e( 'Please verify your app configuration snapshot before dispatching the build to the cloud compiler. An immutable snapshot of this configuration will be stored for this build.', 'bizgrowth360-app-builder' ); ?></p>
						<table class="widefat striped bg360-summary-table">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'App Name:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-app-name"><strong>-</strong></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Package ID:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-package-id"><code>-</code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Website URL:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-url"><a href="#" target="_blank" id="modal-summary-url-link">-</a></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Version:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-version">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Build Target:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-target"><strong>-</strong></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Build Variant:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-variant">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Orientation:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-orientation">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Primary Color:', 'bizgrowth360-app-builder' ); ?></th>
									<td>
										<span id="modal-summary-color-box" style="display:inline-block; width:16px; height:16px; vertical-align:middle; border-radius:3px; margin-right:5px; border:1px solid #ccc;"></span>
										<span id="modal-summary-color">-</span>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Custom Icon:', 'bizgrowth360-app-builder' ); ?></th>
									<td id="modal-summary-icon">-</td>
								</tr>
							</tbody>
						</table>
						<div id="modal-summary-error-wrap" class="notice notice-error inline" style="display:none; margin-top:10px;">
							<p id="modal-summary-error-text"></p>
						</div>
					</div>
					<div class="bg360-modal-footer">
						<button type="button" class="button button-secondary" data-close="#bg360-confirm-modal"><?php esc_html_e( 'Cancel', 'bizgrowth360-app-builder' ); ?></button>
						<button type="button" id="bg360-confirm-start-btn" class="button button-primary bg360-btn-large">
							<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Confirm & Dispatch Build', 'bizgrowth360-app-builder' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- MODAL 2: Build Logs Viewer -->
			<div id="bg360-logs-modal" class="bg360-modal-overlay" style="display:none;">
				<div class="bg360-modal-box bg360-modal-wide">
					<div class="bg360-modal-header">
						<h3><span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'Build Execution Logs', 'bizgrowth360-app-builder' ); ?> <span id="logs-modal-title-id"></span></h3>
						<button type="button" class="bg360-modal-close" data-close="#bg360-logs-modal">&times;</button>
					</div>
					<div class="bg360-modal-body">
						<div class="bg360-logs-toolbar">
							<span id="logs-modal-status-text" class="bg360-badge bg360-badge-idle">Loading...</span>
							<button type="button" id="bg360-copy-logs-btn" class="button button-secondary button-small">
								<span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Copy Logs', 'bizgrowth360-app-builder' ); ?>
							</button>
						</div>
						<pre id="bg360-logs-content" class="bg360-code-viewer"><?php esc_html_e( 'Fetching logs...', 'bizgrowth360-app-builder' ); ?></pre>
					</div>
					<div class="bg360-modal-footer">
						<button type="button" class="button button-secondary" data-close="#bg360-logs-modal"><?php esc_html_e( 'Close', 'bizgrowth360-app-builder' ); ?></button>
					</div>
				</div>
			</div>

			<!-- MODAL 3: Build Details / Snapshot Viewer -->
			<div id="bg360-details-modal" class="bg360-modal-overlay" style="display:none;">
				<div class="bg360-modal-box">
					<div class="bg360-modal-header">
						<h3><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Build Configuration Snapshot', 'bizgrowth360-app-builder' ); ?> <span id="details-modal-title-id"></span></h3>
						<button type="button" class="bg360-modal-close" data-close="#bg360-details-modal">&times;</button>
					</div>
					<div class="bg360-modal-body">
						<pre id="bg360-details-content" class="bg360-code-viewer"></pre>
					</div>
					<div class="bg360-modal-footer">
						<button type="button" class="button button-secondary" data-close="#bg360-details-modal"><?php esc_html_e( 'Close', 'bizgrowth360-app-builder' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the App Design Studio tab shell. The interactive panels and the
	 * phone preview are rendered by assets/js/design-studio.js from the saved,
	 * server-sanitized design configuration.
	 */
	public function render_design_studio() {
		$sections = array(
			'theme'      => array( 'dashicons-admin-appearance', __( 'App Theme', 'bizgrowth360-app-builder' ) ),
			'header'     => array( 'dashicons-align-center', __( 'App Header', 'bizgrowth360-app-builder' ) ),
			'navigation' => array( 'dashicons-menu-alt', __( 'Navigation', 'bizgrowth360-app-builder' ) ),
			'menu'       => array( 'dashicons-list-view', __( 'Menu Builder', 'bizgrowth360-app-builder' ) ),
			'homepage'   => array( 'dashicons-welcome-widgets-menus', __( 'Homepage Builder', 'bizgrowth360-app-builder' ) ),
			'components' => array( 'dashicons-screenoptions', __( 'App Components', 'bizgrowth360-app-builder' ) ),
			'typography' => array( 'dashicons-editor-textcolor', __( 'Typography', 'bizgrowth360-app-builder' ) ),
			'layout'     => array( 'dashicons-editor-expand', __( 'Layout & Spacing', 'bizgrowth360-app-builder' ) ),
			'preview'    => array( 'dashicons-visibility', __( 'Live Preview', 'bizgrowth360-app-builder' ) ),
			'reset'      => array( 'dashicons-image-rotate', __( 'Reset & Save', 'bizgrowth360-app-builder' ) ),
		);
		?>
		<div id="tab-design" class="bizgrowth360-tab-content">
			<div id="bg360-design-studio" class="bg360-studio" data-schema-version="<?php echo esc_attr( DesignConfig::SCHEMA_VERSION ); ?>">
				<div class="bg360-studio-topbar">
					<div class="bg360-studio-topbar-title">
						<span class="dashicons dashicons-art"></span>
						<div>
							<strong><?php esc_html_e( 'App Design Studio', 'bizgrowth360-app-builder' ); ?></strong>
							<span class="bg360-studio-subtitle"><?php esc_html_e( 'Design the native app shell, navigation and homepage. Changes are applied to the next Android build.', 'bizgrowth360-app-builder' ); ?></span>
						</div>
					</div>
					<div class="bg360-studio-savebar">
						<span id="bg360-design-dirty" class="bg360-save-state is-saved"><?php esc_html_e( 'All changes saved', 'bizgrowth360-app-builder' ); ?></span>
						<label class="bg360-autosave-toggle">
							<input type="checkbox" id="bg360-design-autosave" checked="checked" />
							<?php esc_html_e( 'Autosave', 'bizgrowth360-app-builder' ); ?>
						</label>
						<button type="button" id="bg360-design-discard-btn" class="button button-secondary"><?php esc_html_e( 'Discard', 'bizgrowth360-app-builder' ); ?></button>
						<button type="button" id="bg360-design-save-btn" class="button button-primary">
							<span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save Design', 'bizgrowth360-app-builder' ); ?>
						</button>
					</div>
				</div>

				<div class="bg360-studio-layout">
					<nav class="bg360-studio-nav" aria-label="<?php esc_attr_e( 'Design Studio sections', 'bizgrowth360-app-builder' ); ?>">
						<?php foreach ( $sections as $key => $section ) : ?>
							<button type="button" class="bg360-studio-nav-item<?php echo 'theme' === $key ? ' active' : ''; ?>" data-design-panel="<?php echo esc_attr( $key ); ?>">
								<span class="dashicons <?php echo esc_attr( $section[0] ); ?>"></span>
								<span><?php echo esc_html( $section[1] ); ?></span>
							</button>
						<?php endforeach; ?>
					</nav>

					<div class="bg360-studio-panels" id="bg360-studio-panels">
						<?php foreach ( $sections as $key => $section ) : ?>
							<section class="bg360-studio-panel<?php echo 'theme' === $key ? ' active' : ''; ?>" data-design-panel-content="<?php echo esc_attr( $key ); ?>">
								<div class="bg360-panel-heading">
									<h3><span class="dashicons <?php echo esc_attr( $section[0] ); ?>"></span> <?php echo esc_html( $section[1] ); ?></h3>
								</div>
								<div id="bg360-panel-<?php echo esc_attr( $key ); ?>" class="bg360-panel-body"></div>
							</section>
						<?php endforeach; ?>
					</div>

					<aside class="bg360-studio-preview" aria-label="<?php esc_attr_e( 'Live app preview', 'bizgrowth360-app-builder' ); ?>">
						<div class="bg360-preview-toolbar">
							<div class="bg360-preview-modes" role="group" aria-label="<?php esc_attr_e( 'Preview mode', 'bizgrowth360-app-builder' ); ?>">
								<button type="button" class="button is-active" data-preview-mode="home"><?php esc_html_e( 'Home', 'bizgrowth360-app-builder' ); ?></button>
								<button type="button" class="button" data-preview-mode="menu"><?php esc_html_e( 'Menu', 'bizgrowth360-app-builder' ); ?></button>
								<button type="button" class="button" data-preview-mode="navigation"><?php esc_html_e( 'Nav', 'bizgrowth360-app-builder' ); ?></button>
							</div>
							<div class="bg360-preview-options">
								<select id="bg360-preview-size" aria-label="<?php esc_attr_e( 'Preview screen size', 'bizgrowth360-app-builder' ); ?>">
									<option value="small"><?php esc_html_e( 'Small phone', 'bizgrowth360-app-builder' ); ?></option>
									<option value="phone" selected="selected"><?php esc_html_e( 'Phone', 'bizgrowth360-app-builder' ); ?></option>
									<option value="large"><?php esc_html_e( 'Large phone', 'bizgrowth360-app-builder' ); ?></option>
									<option value="tablet"><?php esc_html_e( 'Tablet', 'bizgrowth360-app-builder' ); ?></option>
								</select>
								<button type="button" id="bg360-preview-theme-toggle" class="button" title="<?php esc_attr_e( 'Toggle preview light/dark mode', 'bizgrowth360-app-builder' ); ?>">
									<span class="dashicons dashicons-lightbulb"></span>
								</button>
							</div>
						</div>

						<div class="bg360-studio-device size-phone" id="bg360-studio-device">
							<div class="bg360-studio-notch"></div>
							<div class="bg360-preview-screen" id="bg360-preview-screen">
								<div id="bg360-preview-app" class="bg360-preview-app"></div>
								<div id="bg360-preview-drawer" class="bg360-preview-drawer" aria-hidden="true"></div>
							</div>
							<div class="bg360-studio-homebar"></div>
						</div>

						<p class="bg360-preview-note">
							<?php esc_html_e( 'Live preview uses the current unsaved settings. The app header, menu and homepage render in the native app shell; pages you open from the website keep the website’s own layout.', 'bizgrowth360-app-builder' ); ?>
						</p>
					</aside>
				</div>

				<div id="bg360-design-toast" class="bg360-design-toast" role="status" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}
}
