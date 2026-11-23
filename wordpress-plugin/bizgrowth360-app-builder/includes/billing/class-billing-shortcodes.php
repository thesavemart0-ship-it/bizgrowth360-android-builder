<?php
/**
 * Customer pricing, checkout and subscription dashboard shortcodes.
 *
 * [bizgrowth360_pricing]
 * [bizgrowth360_billing_dashboard]
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BillingShortcodes {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_shortcode( 'bizgrowth360_pricing', array( $this, 'render_pricing' ) );
		add_shortcode( 'bizgrowth360_billing_dashboard', array( $this, 'render_dashboard' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue assets when a billing shortcode is present.
	 */
	public function maybe_enqueue() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'bizgrowth360_pricing' ) && ! has_shortcode( $post->post_content, 'bizgrowth360_billing_dashboard' ) ) {
			return;
		}

		wp_enqueue_style( 'bizgrowth360-billing', BIZGROWTH360_APP_BUILDER_URL . 'assets/css/billing.css', array(), BIZGROWTH360_APP_BUILDER_VERSION );
		wp_enqueue_script( 'bizgrowth360-billing', BIZGROWTH360_APP_BUILDER_URL . 'assets/js/billing.js', array( 'jquery' ), BIZGROWTH360_APP_BUILDER_VERSION, true );
		wp_localize_script(
			'bizgrowth360-billing',
			'bizgrowth360Billing',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'bizgrowth360_billing_nonce' ),
				'logged_in'       => is_user_logged_in(),
				'login_url'       => wp_login_url( get_permalink() ),
				'currency_symbol' => '₹',
				'gateways'        => array(
					'active'   => Gateways::active_id(),
					'razorpay' => ( new RazorpayGateway() )->is_configured(),
					'stripe'   => ( new StripeGateway() )->is_configured(),
				),
				'strings'         => array(
					'monthly'        => __( 'Monthly', 'bizgrowth360-app-builder' ),
					'annual'         => __( 'Annual', 'bizgrowth360-app-builder' ),
					'per_month'      => __( '/ month', 'bizgrowth360-app-builder' ),
					'per_year'       => __( '/ year', 'bizgrowth360-app-builder' ),
					'pay_now'        => __( 'Continue to payment', 'bizgrowth360-app-builder' ),
					'creating_order' => __( 'Creating secure order...', 'bizgrowth360-app-builder' ),
					'verifying'      => __( 'Verifying payment...', 'bizgrowth360-app-builder' ),
					'payment_success'=> __( 'Payment verified. Your plan is active.', 'bizgrowth360-app-builder' ),
					'payment_failed' => __( 'Payment could not be verified. No plan was activated.', 'bizgrowth360-app-builder' ),
					'checkout_failed'=> __( 'Could not start checkout. Please retry.', 'bizgrowth360-app-builder' ),
					'cancelled'      => __( 'Checkout was cancelled.', 'bizgrowth360-app-builder' ),
					'coupon_applied' => __( 'Coupon applied.', 'bizgrowth360-app-builder' ),
					'coupon_invalid' => __( 'Coupon could not be applied.', 'bizgrowth360-app-builder' ),
					'confirm_cancel' => __( 'Cancel this subscription at the end of the current period?', 'bizgrowth360-app-builder' ),
					'saved'          => __( 'Billing details saved.', 'bizgrowth360-app-builder' ),
					'success'        => __( 'Done.', 'bizgrowth360-app-builder' ),
					'error'          => __( 'Something went wrong. Please retry.', 'bizgrowth360-app-builder' ),
				),
			)
		);
	}

	/**
	 * Ensure pricing and dashboard pages exist.
	 */
	public static function ensure_pages() {
		$pages = array(
			'bizgrowth360_pricing_page_id'   => array(
				'title'   => __( 'Plans & Pricing', 'bizgrowth360-app-builder' ),
				'slug'    => 'app-builder-pricing',
				'content' => '[bizgrowth360_pricing]',
			),
			'bizgrowth360_dashboard_page_id' => array(
				'title'   => __( 'My Subscription', 'bizgrowth360-app-builder' ),
				'slug'    => 'app-builder-billing',
				'content' => '[bizgrowth360_billing_dashboard]',
			),
		);

		foreach ( $pages as $option => $page ) {
			$existing = (int) get_option( $option, 0 );
			if ( $existing && get_post( $existing ) ) {
				continue;
			}
			$found = get_page_by_path( $page['slug'] );
			if ( $found ) {
				update_option( $option, $found->ID );
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( $option, (int) $id );
			}
		}
	}

	/**
	 * Pricing page.
	 *
	 * @return string
	 */
	public function render_pricing() {
		$plans       = Plans::public_plans();
		$tenant_id   = Tenant::current_id();
		$current_sub = $tenant_id ? Subscriptions::get_current( $tenant_id ) : null;
		$current_plan = $current_sub ? Plans::get( $current_sub->plan_id ) : null;
		$recommended = Plans::recommended_upgrade( $current_plan );
		$tax         = Tax::get_settings();
		$gateways    = Gateways::status();

		ob_start();
		?>
		<div class="bg360-billing-wrap" data-page="pricing">
			<div class="bg360-billing-hero">
				<h1><?php esc_html_e( 'Choose your app-building plan', 'bizgrowth360-app-builder' ); ?></h1>
				<p><?php esc_html_e( 'Build Android and iOS apps from your website with plan limits you can understand.', 'bizgrowth360-app-builder' ); ?></p>
				<div class="bg360-interval-toggle" role="group" aria-label="<?php esc_attr_e( 'Billing interval', 'bizgrowth360-app-builder' ); ?>">
					<button type="button" class="bg360-interval-btn is-active" data-interval="monthly"><?php esc_html_e( 'Monthly', 'bizgrowth360-app-builder' ); ?></button>
					<button type="button" class="bg360-interval-btn" data-interval="annual"><?php esc_html_e( 'Annual', 'bizgrowth360-app-builder' ); ?></button>
				</div>
			</div>

			<?php if ( $current_sub ) : ?>
				<div class="bg360-billing-panel">
					<strong><?php esc_html_e( 'Current plan:', 'bizgrowth360-app-builder' ); ?></strong>
					<?php echo esc_html( $current_plan ? $current_plan->name : '—' ); ?>
					<span class="bg360-muted">(<?php echo esc_html( Entitlements::status_label( $current_sub->status ) ); ?>)</span>
					<?php if ( $current_sub->current_period_end ) : ?>
						<span class="bg360-muted">— <?php esc_html_e( 'renews', 'bizgrowth360-app-builder' ); ?> <?php echo esc_html( $current_sub->current_period_end ); ?></span>
					<?php endif; ?>
					<a class="button button-secondary" href="<?php echo esc_url( self::dashboard_url() ); ?>"><?php esc_html_e( 'Manage subscription', 'bizgrowth360-app-builder' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="bg360-plan-grid">
				<?php foreach ( $plans as $plan ) : ?>
					<?php
					$is_current = $current_plan && (int) $current_plan->id === (int) $plan->id;
					$is_recommended = $recommended && (int) $recommended->id === (int) $plan->id;
					$mode = $current_sub ? 'change' : 'subscribe';
					$button = $is_current
						? __( 'Current plan', 'bizgrowth360-app-builder' )
						: ( $current_sub ? __( 'Change plan', 'bizgrowth360-app-builder' ) : __( 'Choose plan', 'bizgrowth360-app-builder' ) );
					?>
					<div class="bg360-plan-card<?php echo $is_current ? ' is-current' : ''; ?>"
						data-plan-id="<?php echo esc_attr( $plan->id ); ?>"
						data-name="<?php echo esc_attr( $plan->name ); ?>"
						data-monthly="<?php echo esc_attr( $plan->monthly_price ); ?>"
						data-annual="<?php echo esc_attr( $plan->annual_price ); ?>">
						<?php if ( $is_current ) : ?>
							<span class="bg360-plan-badge"><?php esc_html_e( 'Current plan', 'bizgrowth360-app-builder' ); ?></span>
						<?php elseif ( $is_recommended ) : ?>
							<span class="bg360-plan-badge"><?php esc_html_e( 'Recommended upgrade', 'bizgrowth360-app-builder' ); ?></span>
						<?php endif; ?>
						<h3><?php echo esc_html( $plan->name ); ?></h3>
						<p class="bg360-plan-desc"><?php echo esc_html( $plan->description ); ?></p>
						<div class="bg360-plan-price">
							<span class="amount"><?php echo esc_html( Tax::format_money( $plan->monthly_price, $plan->currency ) ); ?></span>
							<small><?php esc_html_e( '/ month', 'bizgrowth360-app-builder' ); ?></small>
						</div>
						<?php if ( (float) $plan->annual_price > 0 ) : ?>
							<div class="bg360-muted"><?php echo esc_html( sprintf( __( 'or %s / year', 'bizgrowth360-app-builder' ), Tax::format_money( $plan->annual_price, $plan->currency ) ) ); ?></div>
						<?php endif; ?>
						<ul class="bg360-plan-features">
							<?php foreach ( Plans::feature_list( $plan ) as $feature ) : ?>
								<li><?php echo esc_html( $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<button type="button" class="button <?php echo $is_current ? 'button-secondary' : 'button-primary'; ?> bg360-select-plan"
							data-plan-id="<?php echo esc_attr( $plan->id ); ?>"
							data-mode="<?php echo esc_attr( $mode ); ?>"
							<?php disabled( $is_current ); ?>>
							<?php echo esc_html( $button ); ?>
						</button>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $gateways['razorpay']['configured'] && ! $gateways['stripe']['configured'] ) : ?>
				<div class="bg360-billing-panel" style="margin-top:18px;">
					<p class="bg360-muted"><?php esc_html_e( 'Online payment is not configured yet. Add Razorpay or Stripe credentials in wp-config.php. Free plans and trials can still be started.', 'bizgrowth360-app-builder' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $tax['enabled'] ) ) : ?>
				<p class="bg360-muted" style="margin-top:16px;">
					<?php echo esc_html( sprintf( __( 'Tax: %1$s at %2$s%%. Final tax is calculated server-side using your billing details. Prices are shown %3$s tax.', 'bizgrowth360-app-builder' ), $tax['label'], $tax['rate'], $tax['inclusive'] ? __( 'including', 'bizgrowth360-app-builder' ) : __( 'excluding', 'bizgrowth360-app-builder' ) ) ); ?>
				</p>
			<?php endif; ?>

			<div id="bg360-checkout-modal" class="bg360-billing-modal" aria-hidden="true">
				<div class="bg360-billing-modal-box" role="dialog" aria-modal="true" aria-labelledby="bg360-checkout-title">
					<button type="button" class="bg360-billing-modal-close" aria-label="<?php esc_attr_e( 'Close', 'bizgrowth360-app-builder' ); ?>">&times;</button>
					<h3 id="bg360-checkout-title"><?php esc_html_e( 'Checkout', 'bizgrowth360-app-builder' ); ?></h3>
					<div class="bg360-billing-summary">
						<div><span><?php esc_html_e( 'Plan', 'bizgrowth360-app-builder' ); ?></span><strong id="bg360-checkout-plan">—</strong></div>
						<div><span><?php esc_html_e( 'Billing interval', 'bizgrowth360-app-builder' ); ?></span><strong id="bg360-checkout-interval">—</strong></div>
						<div><span><?php esc_html_e( 'Subtotal', 'bizgrowth360-app-builder' ); ?></span><strong id="bg360-checkout-subtotal">—</strong></div>
						<div><span><?php esc_html_e( 'Discount', 'bizgrowth360-app-builder' ); ?></span><strong id="bg360-checkout-discount">—</strong></div>
						<div class="total"><span><?php esc_html_e( 'Estimated total', 'bizgrowth360-app-builder' ); ?></span><strong id="bg360-checkout-total">—</strong></div>
					</div>
					<p class="bg360-muted"><?php esc_html_e( 'Tax is calculated server-side after your billing details are applied. The final amount is shown by the payment gateway before payment.', 'bizgrowth360-app-builder' ); ?></p>
					<div class="bg360-billing-field">
						<label for="bg360-checkout-coupon"><?php esc_html_e( 'Coupon code', 'bizgrowth360-app-builder' ); ?></label>
						<div style="display:flex;gap:8px;">
							<input type="text" id="bg360-checkout-coupon" autocomplete="off" />
							<button type="button" class="button" id="bg360-apply-coupon"><?php esc_html_e( 'Apply', 'bizgrowth360-app-builder' ); ?></button>
						</div>
					</div>
					<div class="bg360-billing-field">
						<label for="bg360-checkout-gateway"><?php esc_html_e( 'Payment method', 'bizgrowth360-app-builder' ); ?></label>
						<select id="bg360-checkout-gateway">
							<?php if ( $gateways['razorpay']['configured'] ) : ?>
								<option value="razorpay"><?php esc_html_e( 'Razorpay (cards, UPI, netbanking)', 'bizgrowth360-app-builder' ); ?></option>
							<?php endif; ?>
							<?php if ( $gateways['stripe']['configured'] ) : ?>
								<option value="stripe"><?php esc_html_e( 'Stripe (cards)', 'bizgrowth360-app-builder' ); ?></option>
							<?php endif; ?>
							<?php if ( ! $gateways['razorpay']['configured'] && ! $gateways['stripe']['configured'] ) : ?>
								<option value=""><?php esc_html_e( 'No gateway configured', 'bizgrowth360-app-builder' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<div id="bg360-checkout-message" class="bg360-billing-message" role="status"></div>
					<div class="bg360-billing-actions">
						<button type="button" class="button button-primary" id="bg360-checkout-submit"><?php esc_html_e( 'Continue to payment', 'bizgrowth360-app-builder' ); ?></button>
						<a class="button" href="<?php echo esc_url( self::dashboard_url() ); ?>"><?php esc_html_e( 'Billing details', 'bizgrowth360-app-builder' ); ?></a>
					</div>
					<p class="bg360-muted"><?php esc_html_e( 'Payments are verified on the server. A frontend success message alone never activates a plan.', 'bizgrowth360-app-builder' ); ?></p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Customer subscription dashboard.
	 *
	 * @return string
	 */
	public function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			return '<div class="bg360-billing-wrap"><p>' . esc_html__( 'Please sign in to view your subscription.', 'bizgrowth360-app-builder' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'bizgrowth360-app-builder' ) . '</a></p></div>';
		}

		$tenant_id    = get_current_user_id();
		$subscription = Subscriptions::get_current( $tenant_id );
		$plan         = $subscription ? Plans::get( $subscription->plan_id ) : null;
		$summary      = Usage::summary( $tenant_id );
		$profile      = Tenant::get_profile( $tenant_id );
		$invoices     = Invoices::all( array( 'tenant_id' => $tenant_id, 'limit' => 30 ) );
		$transactions = Payments::transactions( array( 'tenant_id' => $tenant_id, 'limit' => 30 ) );
		$history      = Subscriptions::history_list( array( 'tenant_id' => $tenant_id, 'limit' => 20 ) );

		ob_start();
		?>
		<div class="bg360-billing-wrap" data-page="dashboard">
			<div class="bg360-billing-hero" style="text-align:left;">
				<h1><?php esc_html_e( 'My subscription', 'bizgrowth360-app-builder' ); ?></h1>
				<p><?php echo esc_html( sprintf( __( 'Workspace: %s', 'bizgrowth360-app-builder' ), Tenant::name( $tenant_id ) ) ); ?></p>
			</div>

			<?php if ( ! $subscription ) : ?>
				<div class="bg360-billing-panel">
					<p><?php esc_html_e( 'You do not have a subscription yet.', 'bizgrowth360-app-builder' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( self::pricing_url() ); ?>"><?php esc_html_e( 'View plans', 'bizgrowth360-app-builder' ); ?></a>
				</div>
			<?php else : ?>
				<div class="bg360-billing-panel">
					<h2 style="margin-top:0;"><?php echo esc_html( $plan ? $plan->name : __( 'Plan', 'bizgrowth360-app-builder' ) ); ?></h2>
					<div class="bg360-billing-grid">
						<div class="bg360-billing-stat"><span><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></span><strong><?php echo esc_html( Entitlements::status_label( $subscription->status ) ); ?></strong></div>
						<div class="bg360-billing-stat"><span><?php esc_html_e( 'Billing interval', 'bizgrowth360-app-builder' ); ?></span><strong><?php echo esc_html( ucfirst( $subscription->billing_interval ) ); ?></strong></div>
						<div class="bg360-billing-stat"><span><?php esc_html_e( 'Renewal / period end', 'bizgrowth360-app-builder' ); ?></span><strong><?php echo esc_html( $subscription->current_period_end ?: '—' ); ?></strong></div>
						<div class="bg360-billing-stat"><span><?php esc_html_e( 'Amount', 'bizgrowth360-app-builder' ); ?></span><strong><?php echo esc_html( Tax::format_money( $subscription->amount, $subscription->currency ) ); ?></strong></div>
					</div>
					<?php if ( $subscription->cancel_at_period_end ) : ?>
						<p class="bg360-muted"><?php esc_html_e( 'This subscription is scheduled to cancel at the end of the current period.', 'bizgrowth360-app-builder' ); ?></p>
					<?php endif; ?>
					<?php if ( $subscription->pending_plan_id ) : ?>
						<?php $pending = Plans::get( $subscription->pending_plan_id ); ?>
						<p class="bg360-muted"><?php echo esc_html( sprintf( __( 'Scheduled plan change: %s', 'bizgrowth360-app-builder' ), $pending ? $pending->name : '—' ) ); ?></p>
					<?php endif; ?>
					<div class="bg360-billing-actions">
						<a class="button button-primary" href="<?php echo esc_url( self::pricing_url() ); ?>"><?php esc_html_e( 'Upgrade / change plan', 'bizgrowth360-app-builder' ); ?></a>
						<?php if ( $subscription->cancel_at_period_end ) : ?>
							<button type="button" class="button bg360-resume-subscription"><?php esc_html_e( 'Resume subscription', 'bizgrowth360-app-builder' ); ?></button>
						<?php elseif ( ! in_array( $subscription->status, array( 'cancelled', 'expired' ), true ) ) : ?>
							<button type="button" class="button bg360-cancel-subscription"><?php esc_html_e( 'Cancel at period end', 'bizgrowth360-app-builder' ); ?></button>
						<?php endif; ?>
					</div>
				</div>

				<div class="bg360-billing-panel">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Usage and remaining quotas', 'bizgrowth360-app-builder' ); ?></h3>
					<?php
					$rows = array(
						'builds'     => array( __( 'Builds this period', 'bizgrowth360-app-builder' ), $summary['used']['build_started'], $summary['limits']['builds'], '' ),
						'apps'       => array( __( 'Applications', 'bizgrowth360-app-builder' ), $summary['used']['apps'], $summary['limits']['apps'], '' ),
						'concurrent' => array( __( 'Active builds', 'bizgrowth360-app-builder' ), $summary['used']['active_builds'], $summary['limits']['concurrent'], '' ),
						'storage_mb' => array( __( 'Stored artifacts (MB)', 'bizgrowth360-app-builder' ), $summary['used']['storage_mb'], $summary['limits']['storage_mb'], ' MB' ),
					);
					foreach ( $rows as $key => $row ) :
						$limit = (int) $row[2];
						$used  = (float) $row[1];
						$percent = $limit < 0 ? 0 : ( $limit > 0 ? min( 100, ( $used / $limit ) * 100 ) : 100 );
						$bar_class = $percent >= 100 ? 'is-danger' : ( $percent >= 80 ? 'is-warning' : '' );
						?>
						<div class="bg360-usage-row">
							<label>
								<span><?php echo esc_html( $row[0] ); ?></span>
								<span><?php echo esc_html( $limit < 0 ? __( 'Unlimited', 'bizgrowth360-app-builder' ) : sprintf( '%s / %s%s', number_format_i18n( $used, 2 ), number_format_i18n( $limit ), $row[3] ) ); ?></span>
							</label>
							<div class="bg360-usage-bar <?php echo esc_attr( $bar_class ); ?>"><span style="width:<?php echo esc_attr( $percent ); ?>%"></span></div>
						</div>
					<?php endforeach; ?>
					<p class="bg360-muted"><?php esc_html_e( 'Monthly build quota counts builds that reached the runner. Validation and dispatch failures do not consume quota.', 'bizgrowth360-app-builder' ); ?></p>
				</div>

				<?php if ( $plan ) : ?>
					<div class="bg360-billing-panel">
						<h3 style="margin-top:0;"><?php esc_html_e( 'Plan features', 'bizgrowth360-app-builder' ); ?></h3>
						<ul class="bg360-plan-features">
							<?php foreach ( Plans::feature_list( $plan ) as $feature ) : ?>
								<li><?php echo esc_html( $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="bg360-billing-panel">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Billing details', 'bizgrowth360-app-builder' ); ?></h3>
				<form id="bg360-billing-profile-form">
					<div class="bg360-billing-grid">
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Business name', 'bizgrowth360-app-builder' ); ?><input type="text" name="business_name" value="<?php echo esc_attr( $profile->business_name ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Customer name', 'bizgrowth360-app-builder' ); ?><input type="text" name="customer_name" value="<?php echo esc_attr( $profile->customer_name ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Billing email', 'bizgrowth360-app-builder' ); ?><input type="email" name="email" value="<?php echo esc_attr( $profile->email ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Phone', 'bizgrowth360-app-builder' ); ?><input type="text" name="phone" value="<?php echo esc_attr( $profile->phone ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Address line 1', 'bizgrowth360-app-builder' ); ?><input type="text" name="address_1" value="<?php echo esc_attr( $profile->address_1 ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Address line 2', 'bizgrowth360-app-builder' ); ?><input type="text" name="address_2" value="<?php echo esc_attr( $profile->address_2 ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'City', 'bizgrowth360-app-builder' ); ?><input type="text" name="city" value="<?php echo esc_attr( $profile->city ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'State', 'bizgrowth360-app-builder' ); ?><input type="text" name="state" value="<?php echo esc_attr( $profile->state ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Postal code', 'bizgrowth360-app-builder' ); ?><input type="text" name="postal_code" value="<?php echo esc_attr( $profile->postal_code ?? '' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'Country (2-letter)', 'bizgrowth360-app-builder' ); ?><input type="text" name="country" maxlength="2" value="<?php echo esc_attr( $profile->country ?? 'IN' ); ?>" /></label></div>
						<div class="bg360-billing-field"><label><?php esc_html_e( 'GSTIN (optional)', 'bizgrowth360-app-builder' ); ?><input type="text" name="gstin" value="<?php echo esc_attr( $profile->gstin ?? '' ); ?>" /></label></div>
					</div>
					<div id="bg360-profile-message" class="bg360-billing-message" role="status"></div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save billing details', 'bizgrowth360-app-builder' ); ?></button>
				</form>
			</div>

			<div class="bg360-billing-panel">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Invoices', 'bizgrowth360-app-builder' ); ?></h3>
				<?php if ( $invoices ) : ?>
					<table class="bg360-billing-table">
						<thead><tr><th><?php esc_html_e( 'Invoice', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Plan', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Total', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $invoices as $invoice ) : ?>
								<tr>
									<td><?php echo esc_html( $invoice->invoice_number ); ?></td>
									<td><?php echo esc_html( $invoice->issued_at ); ?></td>
									<td><?php echo esc_html( $invoice->plan_name ); ?></td>
									<td><?php echo esc_html( Tax::format_money( $invoice->total, $invoice->currency ) ); ?></td>
									<td><?php echo esc_html( $invoice->payment_status ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( Invoices::download_url( $invoice->id ) ); ?>"><?php esc_html_e( 'Download', 'bizgrowth360-app-builder' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="bg360-muted"><?php esc_html_e( 'No invoices yet.', 'bizgrowth360-app-builder' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="bg360-billing-panel">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Payment history', 'bizgrowth360-app-builder' ); ?></h3>
				<?php if ( $transactions ) : ?>
					<table class="bg360-billing-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Type', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Amount', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Reference', 'bizgrowth360-app-builder' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $transactions as $transaction ) : ?>
								<tr>
									<td><?php echo esc_html( $transaction->created_at ); ?></td>
									<td><?php echo esc_html( $transaction->type ); ?></td>
									<td><?php echo esc_html( Tax::format_money( $transaction->amount, $transaction->currency ) ); ?></td>
									<td><?php echo esc_html( $transaction->status ); ?></td>
									<td><code><?php echo esc_html( $transaction->gateway_payment_id ?: $transaction->gateway_order_id ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="bg360-muted"><?php esc_html_e( 'No payments yet.', 'bizgrowth360-app-builder' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $history ) : ?>
				<div class="bg360-billing-panel">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Subscription history', 'bizgrowth360-app-builder' ); ?></h3>
					<table class="bg360-billing-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'From', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'To', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Reason', 'bizgrowth360-app-builder' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $history as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row->created_at ); ?></td>
									<td><?php echo esc_html( $row->from_status ?: '—' ); ?></td>
									<td><?php echo esc_html( $row->to_status ); ?></td>
									<td><?php echo esc_html( $row->reason ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Pricing page URL.
	 *
	 * @return string
	 */
	public static function pricing_url() {
		$page_id = (int) get_option( 'bizgrowth360_pricing_page_id', 0 );
		return $page_id ? get_permalink( $page_id ) : home_url( '/?bizgrowth360_pricing=1' );
	}

	/**
	 * Customer dashboard URL.
	 *
	 * @return string
	 */
	public static function dashboard_url() {
		$page_id = (int) get_option( 'bizgrowth360_dashboard_page_id', 0 );
		return $page_id ? get_permalink( $page_id ) : home_url( '/?bizgrowth360_billing=1' );
	}
}
