<?php
/**
 * Super Admin billing console: plans, subscriptions, payments, invoices,
 * coupons, tax, policy, notifications, webhooks and audit.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BillingAdmin {

	const SLUG = 'bizgrowth360-billing';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_bizgrowth360_billing_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue billing styles on the billing screen.
	 *
	 * @param string $hook
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'bizgrowth360-billing', BIZGROWTH360_APP_BUILDER_URL . 'assets/css/billing.css', array(), BIZGROWTH360_APP_BUILDER_VERSION );
	}

	/**
	 * Add submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			'bizgrowth360-app-builder',
			__( 'Billing & Plans', 'bizgrowth360-app-builder' ),
			__( 'Billing & Plans', 'bizgrowth360-app-builder' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Billing admin URL.
	 *
	 * @param string $tab
	 * @param array  $args
	 * @return string
	 */
	public static function url( $tab = 'dashboard', $args = array() ) {
		$args = array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Handle admin-post actions.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bizgrowth360-app-builder' ), 403 );
		}
		check_admin_referer( 'bizgrowth360_billing_admin' );

		$op     = sanitize_key( $_POST['op'] ?? '' );
		$tab    = sanitize_key( $_POST['tab'] ?? 'dashboard' );
		$notice = 'saved';

		switch ( $op ) {
			case 'save_plan':
				$result = Plans::save( wp_unslash( $_POST['plan'] ?? array() ) );
				$notice = is_wp_error( $result ) ? 'error' : 'saved';
				break;
			case 'plan_status':
				Plans::set_status( absint( $_POST['plan_id'] ?? 0 ), sanitize_key( $_POST['status'] ?? '' ) );
				$tab = 'plans';
				break;
			case 'save_coupon':
				$result = Coupons::save( wp_unslash( $_POST['coupon'] ?? array() ) );
				$notice = is_wp_error( $result ) ? 'error' : 'saved';
				$tab = 'coupons';
				break;
			case 'coupon_toggle':
				Coupons::set_active( absint( $_POST['coupon_id'] ?? 0 ), ! empty( $_POST['active'] ) );
				$tab = 'coupons';
				break;
			case 'save_tax':
				Tax::save_settings( wp_unslash( $_POST['tax'] ?? array() ) );
				$tab = 'tax';
				break;
			case 'save_policy':
				Cron::save_policy( wp_unslash( $_POST['policy'] ?? array() ) );
				update_option( 'bizgrowth360_billing_gateway', sanitize_key( $_POST['gateway'] ?? 'razorpay' ) );
				update_option( 'bizgrowth360_billing_proration_enabled', ! empty( $_POST['proration_enabled'] ) ? 1 : 0 );
				$tab = 'settings';
				break;
			case 'save_notifications':
				Notifications::save_templates( wp_unslash( $_POST['templates'] ?? array() ) );
				$tab = 'notifications';
				break;
			case 'subscription_status':
				$id     = absint( $_POST['subscription_id'] ?? 0 );
				$status = sanitize_key( $_POST['status'] ?? '' );
				if ( 'renew' === $status ) {
					Subscriptions::renew( $id );
				} elseif ( 'cancel' === $status ) {
					Subscriptions::cancel( $id, false );
				} elseif ( 'cancel_now' === $status ) {
					Subscriptions::cancel( $id, true );
				} elseif ( 'suspend' === $status ) {
					Subscriptions::suspend( $id );
				} elseif ( 'activate' === $status ) {
					Subscriptions::activate( $id, array( 'reason' => 'admin_override' ) );
				} elseif ( 'expire' === $status ) {
					Subscriptions::expire( $id );
				}
				BillingDb::audit( 'admin_subscription_action', array( 'object_type' => 'subscription', 'object_id' => $id, 'details' => array( 'status' => $status ) ) );
				$tab = 'subscriptions';
				break;
			case 'refund':
				$result = Payments::refund( absint( $_POST['transaction_id'] ?? 0 ), (float) ( $_POST['amount'] ?? 0 ), sanitize_text_field( $_POST['reason'] ?? '' ) );
				$notice = ! empty( $result['success'] ) ? 'refunded' : 'error';
				$tab = 'payments';
				break;
			case 'run_cron':
				$cron = new Cron();
				$cron->run();
				$tab = 'dashboard';
				break;
			case 'create_pages':
				BillingShortcodes::ensure_pages();
				$tab = 'settings';
				break;
		}

		wp_safe_redirect( self::url( $tab, array( 'bizgrowth360_notice' => $notice ) ) );
		exit;
	}

	/**
	 * Render the console.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bizgrowth360-app-builder' ), 403 );
		}

		$tab   = sanitize_key( $_GET['tab'] ?? 'dashboard' );
		$tabs  = array(
			'dashboard'     => __( 'Dashboard', 'bizgrowth360-app-builder' ),
			'plans'         => __( 'Plans', 'bizgrowth360-app-builder' ),
			'subscriptions' => __( 'Subscriptions', 'bizgrowth360-app-builder' ),
			'payments'      => __( 'Payments', 'bizgrowth360-app-builder' ),
			'invoices'      => __( 'Invoices', 'bizgrowth360-app-builder' ),
			'coupons'       => __( 'Coupons', 'bizgrowth360-app-builder' ),
			'tax'           => __( 'Tax', 'bizgrowth360-app-builder' ),
			'notifications' => __( 'Notifications', 'bizgrowth360-app-builder' ),
			'settings'      => __( 'Settings', 'bizgrowth360-app-builder' ),
			'webhooks'      => __( 'Webhooks & Audit', 'bizgrowth360-app-builder' ),
		);

		echo '<div class="wrap bizgrowth360-admin-wrap"><h1>' . esc_html__( 'Billing & Plans', 'bizgrowth360-app-builder' ) . '</h1>';
		$this->notice();
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a class="nav-tab %s" href="%s">%s</a>',
				$tab === $key ? 'nav-tab-active' : '',
				esc_url( self::url( $key ) ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'plans':
				$this->render_plans();
				break;
			case 'subscriptions':
				$this->render_subscriptions();
				break;
			case 'payments':
				$this->render_payments();
				break;
			case 'invoices':
				$this->render_invoices();
				break;
			case 'coupons':
				$this->render_coupons();
				break;
			case 'tax':
				$this->render_tax();
				break;
			case 'notifications':
				$this->render_notifications();
				break;
			case 'settings':
				$this->render_settings();
				break;
			case 'webhooks':
				$this->render_webhooks();
				break;
			default:
				$this->render_dashboard();
		}
		echo '</div>';
	}

	/**
	 * Admin notice from query args.
	 */
	private function notice() {
		$notice = sanitize_key( $_GET['bizgrowth360_notice'] ?? '' );
		if ( ! $notice ) {
			return;
		}
		$messages = array(
			'saved'    => __( 'Saved.', 'bizgrowth360-app-builder' ),
			'refunded' => __( 'Refund processed.', 'bizgrowth360-app-builder' ),
			'error'    => __( 'The operation failed. Check the input and gateway configuration.', 'bizgrowth360-app-builder' ),
		);
		$class = 'error' === $notice ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ?? $notice ) . '</p></div>';
	}

	/**
	 * Dashboard tab.
	 */
	private function render_dashboard() {
		$from = sanitize_text_field( $_GET['from'] ?? gmdate( 'Y-m-01' ) );
		$to   = sanitize_text_field( $_GET['to'] ?? gmdate( 'Y-m-t' ) );
		$m    = Metrics::dashboard( $from . ' 00:00:00', $to . ' 23:59:59' );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;display:flex;gap:8px;align-items:end;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="dashboard" />
			<label><?php esc_html_e( 'From', 'bizgrowth360-app-builder' ); ?><br><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
			<label><?php esc_html_e( 'To', 'bizgrowth360-app-builder' ); ?><br><input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
			<button class="button"><?php esc_html_e( 'Filter', 'bizgrowth360-app-builder' ); ?></button>
		</form>
		<div class="bg360-billing-grid">
			<?php
			$stats = array(
				__( 'Gross revenue', 'bizgrowth360-app-builder' ) => Tax::format_money( $m['gross_revenue'], 'INR' ),
				__( 'Refunds', 'bizgrowth360-app-builder' )       => Tax::format_money( $m['refunds'], 'INR' ),
				__( 'Net revenue', 'bizgrowth360-app-builder' )   => Tax::format_money( $m['net_revenue'], 'INR' ),
				__( 'MRR', 'bizgrowth360-app-builder' )           => Tax::format_money( $m['mrr'], 'INR' ),
				__( 'ARR (run rate)', 'bizgrowth360-app-builder' )=> Tax::format_money( $m['arr'], 'INR' ),
				__( 'Active subscriptions', 'bizgrowth360-app-builder' ) => $m['active'],
				__( 'Trials', 'bizgrowth360-app-builder' )        => $m['trials'],
				__( 'Past due', 'bizgrowth360-app-builder' )      => $m['past_due'],
				__( 'Grace period', 'bizgrowth360-app-builder' )  => $m['grace'],
				__( 'Suspended', 'bizgrowth360-app-builder' )     => $m['suspended'],
				__( 'Cancelled', 'bizgrowth360-app-builder' )     => $m['cancelled'],
				__( 'Payment failures', 'bizgrowth360-app-builder' ) => $m['payment_failures'],
			);
			foreach ( $stats as $label => $value ) {
				echo '<div class="bg360-billing-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
			}
			?>
		</div>
		<div class="bg360-billing-panel" style="margin-top:18px;">
			<h3><?php esc_html_e( 'Plan distribution', 'bizgrowth360-app-builder' ); ?></h3>
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Plan', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Subscriptions', 'bizgrowth360-app-builder' ); ?></th></tr></thead><tbody>
			<?php foreach ( $m['plan_distribution'] as $row ) : ?>
				<tr><td><?php echo esc_html( $row->name ); ?></td><td><?php echo esc_html( (string) $row->total ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="bg360-muted"><?php esc_html_e( 'MRR is normalized to monthly value. Annual plans contribute annual price / 12. Gross payments are not MRR.', 'bizgrowth360-app-builder' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" />
				<input type="hidden" name="op" value="run_cron" />
				<input type="hidden" name="tab" value="dashboard" />
				<button class="button"><?php esc_html_e( 'Run billing lifecycle now', 'bizgrowth360-app-builder' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Plans tab.
	 */
	private function render_plans() {
		$edit = isset( $_GET['plan_id'] ) ? Plans::get( absint( $_GET['plan_id'] ) ) : null;
		$plans = Plans::all( array( 'status' => 'all' ) );
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<h2><?php echo $edit ? esc_html__( 'Edit plan', 'bizgrowth360-app-builder' ) : esc_html__( 'Create plan', 'bizgrowth360-app-builder' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" />
				<input type="hidden" name="op" value="save_plan" />
				<input type="hidden" name="tab" value="plans" />
				<input type="hidden" name="plan[id]" value="<?php echo esc_attr( $edit->id ?? 0 ); ?>" />
				<div class="bg360-billing-grid">
					<?php
					$fields = array(
						'name' => __( 'Plan name', 'bizgrowth360-app-builder' ),
						'slug' => __( 'Slug', 'bizgrowth360-app-builder' ),
						'currency' => __( 'Currency (3 letters)', 'bizgrowth360-app-builder' ),
						'monthly_price' => __( 'Monthly price', 'bizgrowth360-app-builder' ),
						'annual_price' => __( 'Annual price', 'bizgrowth360-app-builder' ),
						'trial_days' => __( 'Trial days', 'bizgrowth360-app-builder' ),
						'max_apps' => __( 'Max applications (-1 unlimited)', 'bizgrowth360-app-builder' ),
						'monthly_builds' => __( 'Monthly builds (-1 unlimited)', 'bizgrowth360-app-builder' ),
						'max_concurrent_builds' => __( 'Concurrent builds', 'bizgrowth360-app-builder' ),
						'storage_mb' => __( 'Storage MB (-1 unlimited)', 'bizgrowth360-app-builder' ),
						'support_level' => __( 'Support level', 'bizgrowth360-app-builder' ),
						'sort_order' => __( 'Sort order', 'bizgrowth360-app-builder' ),
					);
					foreach ( $fields as $key => $label ) {
						$value = $edit ? ( $edit->$key ?? '' ) : ( 'currency' === $key ? 'INR' : '' );
						echo '<div class="bg360-billing-field"><label>' . esc_html( $label ) . '<input type="text" name="plan[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" /></label></div>';
					}
					?>
				</div>
				<div class="bg360-billing-field"><label><?php esc_html_e( 'Description', 'bizgrowth360-app-builder' ); ?><textarea name="plan[description]" rows="2"><?php echo esc_textarea( $edit->description ?? '' ); ?></textarea></label></div>
				<div class="bg360-billing-grid">
					<?php
					$selects = array(
						'billing_interval' => array( 'monthly' => 'Monthly', 'annual' => 'Annual' ),
						'visibility'       => array( 'public' => 'Public', 'hidden' => 'Hidden', 'archived' => 'Archived' ),
						'status'           => array( 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived' ),
					);
					foreach ( $selects as $key => $options ) {
						$current = $edit ? ( $edit->$key ?? '' ) : key( $options );
						echo '<div class="bg360-billing-field"><label>' . esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ) . '<select name="plan[' . esc_attr( $key ) . ']">';
						foreach ( $options as $value => $label ) {
							echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select></label></div>';
					}
					$checks = array(
						'apk_access' => __( 'Android APK', 'bizgrowth360-app-builder' ),
						'aab_access' => __( 'Android AAB', 'bizgrowth360-app-builder' ),
						'ipa_access' => __( 'iOS IPA', 'bizgrowth360-app-builder' ),
						'native_features' => __( 'Native features', 'bizgrowth360-app-builder' ),
						'push_notifications' => __( 'Push notifications', 'bizgrowth360-app-builder' ),
						'design_studio' => __( 'Design Studio', 'bizgrowth360-app-builder' ),
						'custom_branding' => __( 'Custom branding', 'bizgrowth360-app-builder' ),
						'priority_build' => __( 'Priority builds', 'bizgrowth360-app-builder' ),
					);
					foreach ( $checks as $key => $label ) {
						$checked = $edit ? ! empty( $edit->$key ) : false;
						echo '<div class="bg360-billing-field"><label><input type="checkbox" style="width:16px;height:16px;" name="plan[' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label></div>';
					}
					?>
				</div>
				<button class="button button-primary"><?php esc_html_e( 'Save plan', 'bizgrowth360-app-builder' ); ?></button>
				<?php if ( $edit ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( 'plans' ) ); ?>"><?php esc_html_e( 'Cancel', 'bizgrowth360-app-builder' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<div class="bg360-billing-panel">
			<table class="bg360-billing-table">
				<thead><tr><th><?php esc_html_e( 'Plan', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Prices', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Limits', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Actions', 'bizgrowth360-app-builder' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $plans as $plan ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $plan->name ); ?></strong><br><code><?php echo esc_html( $plan->slug ); ?></code><br><span class="bg360-muted"><?php echo esc_html( $plan->visibility ); ?></span></td>
						<td><?php echo esc_html( Tax::format_money( $plan->monthly_price, $plan->currency ) . ' / ' . Tax::format_money( $plan->annual_price, $plan->currency ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%s apps, %s builds, %s MB', $plan->max_apps, $plan->monthly_builds, $plan->storage_mb ) ); ?></td>
						<td><?php echo esc_html( $plan->status ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( self::url( 'plans', array( 'plan_id' => $plan->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'bizgrowth360-app-builder' ); ?></a>
							<?php
							$actions = array( 'active' => __( 'Activate', 'bizgrowth360-app-builder' ), 'inactive' => __( 'Deactivate', 'bizgrowth360-app-builder' ), 'archived' => __( 'Archive', 'bizgrowth360-app-builder' ) );
							foreach ( $actions as $status => $label ) {
								if ( $plan->status === $status ) {
									continue;
								}
								echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
								wp_nonce_field( 'bizgrowth360_billing_admin' );
								echo '<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="plan_status" /><input type="hidden" name="tab" value="plans" /><input type="hidden" name="plan_id" value="' . esc_attr( $plan->id ) . '" /><input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
								echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Subscriptions tab.
	 */
	private function render_subscriptions() {
		$status = sanitize_key( $_GET['status'] ?? '' );
		$search = sanitize_text_field( $_GET['s'] ?? '' );
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$data   = Subscriptions::all( array( 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => 25 ) );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;display:flex;gap:8px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" /><input type="hidden" name="tab" value="subscriptions" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Customer search', 'bizgrowth360-app-builder' ); ?>" />
			<select name="status"><option value=""><?php esc_html_e( 'All statuses', 'bizgrowth360-app-builder' ); ?></option>
				<?php foreach ( Subscriptions::STATUSES as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( Entitlements::status_label( $s ) ); ?></option><?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'bizgrowth360-app-builder' ); ?></button>
		</form>
		<div class="bg360-billing-panel">
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Customer', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Plan', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Period end', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Amount', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Actions', 'bizgrowth360-app-builder' ); ?></th></tr></thead><tbody>
			<?php foreach ( $data['items'] as $sub ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $sub->display_name ?: Tenant::name( $sub->tenant_id ) ); ?></strong><br><span class="bg360-muted"><?php echo esc_html( $sub->user_email ?: Tenant::email( $sub->tenant_id ) ); ?></span></td>
					<td><?php echo esc_html( $sub->plan_name ?: '—' ); ?></td>
					<td><?php echo esc_html( Entitlements::status_label( $sub->status ) ); ?></td>
					<td><?php echo esc_html( $sub->current_period_end ?: '—' ); ?></td>
					<td><?php echo esc_html( Tax::format_money( $sub->amount, $sub->currency ) ); ?></td>
					<td>
						<?php
						$actions = array( 'renew' => __( 'Renew', 'bizgrowth360-app-builder' ), 'activate' => __( 'Activate', 'bizgrowth360-app-builder' ), 'cancel' => __( 'Cancel at end', 'bizgrowth360-app-builder' ), 'cancel_now' => __( 'Cancel now', 'bizgrowth360-app-builder' ), 'suspend' => __( 'Suspend', 'bizgrowth360-app-builder' ), 'expire' => __( 'Expire', 'bizgrowth360-app-builder' ) );
						foreach ( $actions as $op => $label ) {
							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
							wp_nonce_field( 'bizgrowth360_billing_admin' );
							echo '<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="subscription_status" /><input type="hidden" name="tab" value="subscriptions" /><input type="hidden" name="subscription_id" value="' . esc_attr( $sub->id ) . '" /><input type="hidden" name="status" value="' . esc_attr( $op ) . '" />';
							echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="bg360-muted"><?php echo esc_html( sprintf( __( '%d subscriptions', 'bizgrowth360-app-builder' ), $data['total'] ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Payments tab.
	 */
	private function render_payments() {
		$transactions = Payments::transactions( array( 'limit' => 100 ) );
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Customer', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Type', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Amount', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Gateway reference', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Refund', 'bizgrowth360-app-builder' ); ?></th></tr></thead><tbody>
			<?php foreach ( $transactions as $tx ) : ?>
				<tr>
					<td><?php echo esc_html( $tx->created_at ); ?></td>
					<td><?php echo esc_html( Tenant::name( $tx->tenant_id ) ); ?></td>
					<td><?php echo esc_html( $tx->type ); ?></td>
					<td><?php echo esc_html( Tax::format_money( $tx->amount, $tx->currency ) ); ?></td>
					<td><?php echo esc_html( $tx->status ); ?></td>
					<td><code><?php echo esc_html( $tx->gateway_payment_id ?: $tx->gateway_order_id ?: $tx->gateway_refund_id ); ?></code></td>
					<td>
						<?php if ( 'payment' === $tx->type && 'succeeded' === $tx->status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;">
								<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
								<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="refund" /><input type="hidden" name="tab" value="payments" /><input type="hidden" name="transaction_id" value="<?php echo esc_attr( $tx->id ); ?>" />
								<input type="number" step="0.01" name="amount" value="<?php echo esc_attr( $tx->amount ); ?>" style="width:90px;" />
								<button class="button button-small"><?php esc_html_e( 'Refund', 'bizgrowth360-app-builder' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Invoices tab.
	 */
	private function render_invoices() {
		$invoices = Invoices::all( array( 'limit' => 100 ) );
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Invoice', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Customer', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Total', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( $invoices as $invoice ) : ?>
				<tr>
					<td><?php echo esc_html( $invoice->invoice_number ); ?></td>
					<td><?php echo esc_html( $invoice->customer_name ); ?></td>
					<td><?php echo esc_html( $invoice->issued_at ); ?></td>
					<td><?php echo esc_html( Tax::format_money( $invoice->total, $invoice->currency ) ); ?></td>
					<td><?php echo esc_html( $invoice->payment_status ); ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( Invoices::download_url( $invoice->id ) ); ?>"><?php esc_html_e( 'Download', 'bizgrowth360-app-builder' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Coupons tab.
	 */
	private function render_coupons() {
		$edit = isset( $_GET['coupon_id'] ) ? Coupons::get( absint( $_GET['coupon_id'] ) ) : null;
		$coupons = Coupons::all();
		$plans   = Plans::all( array( 'status' => 'all' ) );
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<h2><?php echo $edit ? esc_html__( 'Edit coupon', 'bizgrowth360-app-builder' ) : esc_html__( 'Create coupon', 'bizgrowth360-app-builder' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="save_coupon" /><input type="hidden" name="tab" value="coupons" /><input type="hidden" name="coupon[id]" value="<?php echo esc_attr( $edit->id ?? 0 ); ?>" />
				<div class="bg360-billing-grid">
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Code', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[code]" value="<?php echo esc_attr( $edit->code ?? '' ); ?>" required /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Type', 'bizgrowth360-app-builder' ); ?><select name="coupon[discount_type]"><option value="percent" <?php selected( $edit->discount_type ?? '', 'percent' ); ?>>Percent</option><option value="fixed" <?php selected( $edit->discount_type ?? '', 'fixed' ); ?>>Fixed</option></select></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Amount', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[amount]" value="<?php echo esc_attr( $edit->amount ?? '' ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Max discount (0 = none)', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[max_discount]" value="<?php echo esc_attr( $edit->max_discount ?? 0 ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Starts at (YYYY-MM-DD HH:MM:SS)', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[starts_at]" value="<?php echo esc_attr( $edit->starts_at ?? '' ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Expires at', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[expires_at]" value="<?php echo esc_attr( $edit->expires_at ?? '' ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Usage limit (0 = unlimited)', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[usage_limit]" value="<?php echo esc_attr( $edit->usage_limit ?? 0 ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Per-customer limit', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[per_customer_limit]" value="<?php echo esc_attr( $edit->per_customer_limit ?? 1 ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Description', 'bizgrowth360-app-builder' ); ?><input type="text" name="coupon[description]" value="<?php echo esc_attr( $edit->description ?? '' ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Applicable plans (none = all)', 'bizgrowth360-app-builder' ); ?><select name="coupon[applicable_plans][]" multiple size="4"><?php foreach ( $plans as $plan ) : ?><option value="<?php echo esc_attr( $plan->id ); ?>" <?php echo in_array( (int) $plan->id, array_map( 'intval', (array) ( $edit->applicable_plans ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $plan->name ); ?></option><?php endforeach; ?></select></label></div>
					<div class="bg360-billing-field"><label><input type="checkbox" name="coupon[first_time_only]" value="1" <?php checked( ! empty( $edit->first_time_only ) ); ?> /> <?php esc_html_e( 'First-time customers only', 'bizgrowth360-app-builder' ); ?></label></div>
					<div class="bg360-billing-field"><label><input type="checkbox" name="coupon[active]" value="1" <?php checked( $edit ? ! empty( $edit->active ) : true ); ?> /> <?php esc_html_e( 'Active', 'bizgrowth360-app-builder' ); ?></label></div>
				</div>
				<button class="button button-primary"><?php esc_html_e( 'Save coupon', 'bizgrowth360-app-builder' ); ?></button>
			</form>
		</div>
		<div class="bg360-billing-panel">
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Code', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Discount', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Usage', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Active', 'bizgrowth360-app-builder' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( $coupons as $coupon ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $coupon->code ); ?></strong></td>
					<td><?php echo esc_html( 'percent' === $coupon->discount_type ? $coupon->amount . '%' : Tax::format_money( $coupon->amount, 'INR' ) ); ?></td>
					<td><?php echo esc_html( Coupons::usage_count( $coupon->id ) . ( $coupon->usage_limit > 0 ? ' / ' . $coupon->usage_limit : '' ) ); ?></td>
					<td><?php echo esc_html( $coupon->active ? __( 'Yes', 'bizgrowth360-app-builder' ) : __( 'No', 'bizgrowth360-app-builder' ) ); ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( self::url( 'coupons', array( 'coupon_id' => $coupon->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'bizgrowth360-app-builder' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Tax tab.
	 */
	private function render_tax() {
		$tax = Tax::get_settings();
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="save_tax" /><input type="hidden" name="tab" value="tax" />
				<div class="bg360-billing-grid">
					<div class="bg360-billing-field"><label><input type="checkbox" name="tax[enabled]" value="1" <?php checked( ! empty( $tax['enabled'] ) ); ?> /> <?php esc_html_e( 'Enable tax', 'bizgrowth360-app-builder' ); ?></label></div>
					<div class="bg360-billing-field"><label><input type="checkbox" name="tax[inclusive]" value="1" <?php checked( ! empty( $tax['inclusive'] ) ); ?> /> <?php esc_html_e( 'Prices include tax', 'bizgrowth360-app-builder' ); ?></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Tax label', 'bizgrowth360-app-builder' ); ?><input type="text" name="tax[label]" value="<?php echo esc_attr( $tax['label'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Rate (%)', 'bizgrowth360-app-builder' ); ?><input type="text" name="tax[rate]" value="<?php echo esc_attr( $tax['rate'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Country', 'bizgrowth360-app-builder' ); ?><input type="text" name="tax[country]" value="<?php echo esc_attr( $tax['country'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'State', 'bizgrowth360-app-builder' ); ?><input type="text" name="tax[state]" value="<?php echo esc_attr( $tax['state'] ); ?>" /></label></div>
				</div>
				<div class="bg360-billing-field"><label><input type="checkbox" name="tax[gstin_required]" value="1" <?php checked( ! empty( $tax['gstin_required'] ) ); ?> /> <?php esc_html_e( 'Require GSTIN for business invoices', 'bizgrowth360-app-builder' ); ?></label></div>
				<div class="bg360-billing-field"><label><?php esc_html_e( 'Invoice notes', 'bizgrowth360-app-builder' ); ?><textarea name="tax[notes]" rows="3"><?php echo esc_textarea( $tax['notes'] ); ?></textarea></label></div>
				<button class="button button-primary"><?php esc_html_e( 'Save tax settings', 'bizgrowth360-app-builder' ); ?></button>
			</form>
			<p class="bg360-muted"><?php esc_html_e( 'Tax is calculated server-side. This module does not certify legal GST compliance; confirm rates, place of supply and invoice requirements with your adviser.', 'bizgrowth360-app-builder' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Notifications tab.
	 */
	private function render_notifications() {
		$templates = Notifications::get_templates();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bg360-billing-panel" style="margin-top:16px;">
			<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
			<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="save_notifications" /><input type="hidden" name="tab" value="notifications" />
			<?php foreach ( $templates as $event => $template ) : ?>
				<div class="bg360-billing-panel">
					<h3><?php echo esc_html( ucwords( str_replace( '_', ' ', $event ) ) ); ?></h3>
					<div class="bg360-billing-field"><label><input type="checkbox" name="templates[<?php echo esc_attr( $event ); ?>][enabled]" value="1" <?php checked( ! empty( $template['enabled'] ) ); ?> /> <?php esc_html_e( 'Enabled', 'bizgrowth360-app-builder' ); ?></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Subject', 'bizgrowth360-app-builder' ); ?><input type="text" name="templates[<?php echo esc_attr( $event ); ?>][subject]" value="<?php echo esc_attr( $template['subject'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Body', 'bizgrowth360-app-builder' ); ?><textarea name="templates[<?php echo esc_attr( $event ); ?>][body]" rows="4"><?php echo esc_textarea( $template['body'] ); ?></textarea></label></div>
				</div>
			<?php endforeach; ?>
			<p class="bg360-muted"><?php esc_html_e( 'Placeholders: {site_name}, {customer_name}, {plan}, {amount}, {currency}, {renewal_date}, {invoice}, {days}, {status}, {used}, {limit}, {billing_url}.', 'bizgrowth360-app-builder' ); ?></p>
			<button class="button button-primary"><?php esc_html_e( 'Save templates', 'bizgrowth360-app-builder' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private function render_settings() {
		$policy  = Cron::get_policy();
		$gateway = Gateways::active_id();
		$status  = Gateways::status();
		$proration = PlanChanges::proration_enabled();
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<h2><?php esc_html_e( 'Gateway configuration', 'bizgrowth360-app-builder' ); ?></h2>
			<p><?php esc_html_e( 'Secrets are read from wp-config.php constants or environment variables and are never shown here.', 'bizgrowth360-app-builder' ); ?></p>
			<table class="bg360-billing-table">
				<tr><th><?php esc_html_e( 'Razorpay key ID', 'bizgrowth360-app-builder' ); ?></th><td><?php echo esc_html( $status['razorpay']['key_id'] ); ?> (BIZGROWTH360_RAZORPAY_KEY_ID)</td></tr>
				<tr><th><?php esc_html_e( 'Razorpay key secret', 'bizgrowth360-app-builder' ); ?></th><td><?php echo esc_html( $status['razorpay']['configured'] ? 'configured' : 'missing' ); ?> (BIZGROWTH360_RAZORPAY_KEY_SECRET)</td></tr>
				<tr><th><?php esc_html_e( 'Razorpay webhook secret', 'bizgrowth360-app-builder' ); ?></th><td><?php echo esc_html( Gateways::secret( 'BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET' ) ? 'configured' : 'missing' ); ?> (BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET)</td></tr>
				<tr><th><?php esc_html_e( 'Stripe secret key', 'bizgrowth360-app-builder' ); ?></th><td><?php echo esc_html( $status['stripe']['configured'] ? 'configured' : 'missing' ); ?> (BIZGROWTH360_STRIPE_SECRET_KEY)</td></tr>
				<tr><th><?php esc_html_e( 'Stripe webhook secret', 'bizgrowth360-app-builder' ); ?></th><td><?php echo esc_html( Gateways::secret( 'BIZGROWTH360_STRIPE_WEBHOOK_SECRET' ) ? 'configured' : 'missing' ); ?> (BIZGROWTH360_STRIPE_WEBHOOK_SECRET)</td></tr>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="save_policy" /><input type="hidden" name="tab" value="settings" />
				<div class="bg360-billing-grid">
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Active gateway', 'bizgrowth360-app-builder' ); ?><select name="gateway"><option value="razorpay" <?php selected( $gateway, 'razorpay' ); ?>>Razorpay</option><option value="stripe" <?php selected( $gateway, 'stripe' ); ?>>Stripe</option></select></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Grace days', 'bizgrowth360-app-builder' ); ?><input type="number" name="policy[grace_days]" value="<?php echo esc_attr( $policy['grace_days'] ); ?>" min="0" max="60" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Reminder days (comma-separated)', 'bizgrowth360-app-builder' ); ?><input type="text" name="policy[reminder_days]" value="<?php echo esc_attr( $policy['reminder_days'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><?php esc_html_e( 'Retry days', 'bizgrowth360-app-builder' ); ?><input type="text" name="policy[retry_days]" value="<?php echo esc_attr( $policy['retry_days'] ); ?>" /></label></div>
					<div class="bg360-billing-field"><label><input type="checkbox" name="policy[suspend_after_grace]" value="1" <?php checked( ! empty( $policy['suspend_after_grace'] ) ); ?> /> <?php esc_html_e( 'Suspend after grace period', 'bizgrowth360-app-builder' ); ?></label></div>
					<div class="bg360-billing-field"><label><input type="checkbox" name="proration_enabled" value="1" <?php checked( $proration ); ?> /> <?php esc_html_e( 'Enable proration credit on upgrades', 'bizgrowth360-app-builder' ); ?></label></div>
				</div>
				<button class="button button-primary"><?php esc_html_e( 'Save settings', 'bizgrowth360-app-builder' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'bizgrowth360_billing_admin' ); ?>
				<input type="hidden" name="action" value="bizgrowth360_billing_action" /><input type="hidden" name="op" value="create_pages" /><input type="hidden" name="tab" value="settings" />
				<button class="button"><?php esc_html_e( 'Create pricing and billing pages', 'bizgrowth360-app-builder' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Webhooks and audit tab.
	 */
	private function render_webhooks() {
		global $wpdb;
		$webhooks = $wpdb->get_results( 'SELECT * FROM ' . BillingDb::table( 'webhook_events' ) . ' ORDER BY id DESC LIMIT 50' );
		$audit    = $wpdb->get_results( 'SELECT * FROM ' . BillingDb::table( 'audit_log' ) . ' ORDER BY id DESC LIMIT 100' );
		?>
		<div class="bg360-billing-panel" style="margin-top:16px;">
			<h2><?php esc_html_e( 'Recent webhooks', 'bizgrowth360-app-builder' ); ?></h2>
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Gateway', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Event', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Status', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Received', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Error', 'bizgrowth360-app-builder' ); ?></th></tr></thead><tbody>
			<?php foreach ( $webhooks ?: array() as $row ) : ?>
				<tr><td><?php echo esc_html( $row->gateway ); ?></td><td><?php echo esc_html( $row->event_type ); ?></td><td><?php echo esc_html( $row->status ); ?></td><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $row->error ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<div class="bg360-billing-panel">
			<h2><?php esc_html_e( 'Audit log', 'bizgrowth360-app-builder' ); ?></h2>
			<table class="bg360-billing-table"><thead><tr><th><?php esc_html_e( 'Date', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Tenant', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Action', 'bizgrowth360-app-builder' ); ?></th><th><?php esc_html_e( 'Object', 'bizgrowth360-app-builder' ); ?></th></tr></thead><tbody>
			<?php foreach ( $audit ?: array() as $row ) : ?>
				<tr><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $row->tenant_id ? Tenant::name( $row->tenant_id ) : '—' ); ?></td><td><?php echo esc_html( $row->action ); ?></td><td><?php echo esc_html( $row->object_type . ' #' . $row->object_id ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}
}
