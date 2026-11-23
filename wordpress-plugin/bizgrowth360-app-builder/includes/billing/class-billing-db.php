<?php
/**
 * Billing database schema, migrations and audit helpers.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BillingDb {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'bizgrowth360_billing_db_version';

	/**
	 * Full table name for a billing table.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'bg360_' . $name;
	}

	/**
	 * Current MySQL datetime.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Install or upgrade every billing table. Non-destructive dbDelta updates.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$schemas = array();

		// The SaaS foundation owns bg360_plans and bg360_apps. Billing extends
		// those tables instead of creating duplicate competing schemas.
		if ( class_exists( '\Bizgrowth360\AppBuilder\SaasDb' ) ) {
			\Bizgrowth360\AppBuilder\SaasDb::install_tables();
		}
		self::migrate_plan_columns();

		$schemas[] = "CREATE TABLE " . self::table( 'subscriptions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tenant_id bigint(20) unsigned NOT NULL,
			plan_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			billing_interval varchar(20) NOT NULL DEFAULT 'monthly',
			currency char(3) NOT NULL DEFAULT 'INR',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			trial_ends_at datetime DEFAULT NULL,
			current_period_start datetime DEFAULT NULL,
			current_period_end datetime DEFAULT NULL,
			grace_ends_at datetime DEFAULT NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			cancelled_at datetime DEFAULT NULL,
			suspended_at datetime DEFAULT NULL,
			ended_at datetime DEFAULT NULL,
			gateway varchar(30) NOT NULL DEFAULT 'manual',
			gateway_subscription_id varchar(191) DEFAULT NULL,
			gateway_customer_id varchar(191) DEFAULT NULL,
			coupon_id bigint(20) unsigned DEFAULT NULL,
			pending_plan_id bigint(20) unsigned DEFAULT NULL,
			pending_billing_interval varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY plan_id (plan_id),
			KEY current_period_end (current_period_end),
			KEY gateway_subscription_id (gateway_subscription_id)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'subscription_history' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subscription_id bigint(20) unsigned NOT NULL,
			tenant_id bigint(20) unsigned NOT NULL,
			from_status varchar(20) NOT NULL DEFAULT '',
			to_status varchar(20) NOT NULL DEFAULT '',
			plan_id bigint(20) unsigned DEFAULT NULL,
			reason varchar(191) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY subscription_id (subscription_id),
			KEY tenant_id (tenant_id),
			KEY created_at (created_at)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'transactions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tenant_id bigint(20) unsigned NOT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			invoice_id bigint(20) unsigned DEFAULT NULL,
			type varchar(20) NOT NULL DEFAULT 'payment',
			status varchar(20) NOT NULL DEFAULT 'created',
			gateway varchar(30) NOT NULL DEFAULT 'manual',
			gateway_order_id varchar(191) DEFAULT NULL,
			gateway_payment_id varchar(191) DEFAULT NULL,
			gateway_refund_id varchar(191) DEFAULT NULL,
			gateway_event_id varchar(191) DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL DEFAULT 'INR',
			fee decimal(12,2) NOT NULL DEFAULT 0.00,
			tax_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			idempotency_key varchar(191) DEFAULT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_payment_id (gateway_payment_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY tenant_id (tenant_id),
			KEY subscription_id (subscription_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'invoices' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_number varchar(64) NOT NULL,
			tenant_id bigint(20) unsigned NOT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			transaction_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'issued',
			business_name varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			address_1 varchar(191) NOT NULL DEFAULT '',
			address_2 varchar(191) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(100) NOT NULL DEFAULT '',
			postal_code varchar(20) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT 'IN',
			gstin varchar(20) NOT NULL DEFAULT '',
			plan_name varchar(191) NOT NULL DEFAULT '',
			billing_period_start datetime DEFAULT NULL,
			billing_period_end datetime DEFAULT NULL,
			subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			discount decimal(12,2) NOT NULL DEFAULT 0.00,
			taxable_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
			tax_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			total decimal(12,2) NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL DEFAULT 'INR',
			payment_status varchar(20) NOT NULL DEFAULT 'pending',
			payment_reference varchar(191) NOT NULL DEFAULT '',
			meta longtext NULL,
			issued_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_number (invoice_number),
			KEY tenant_id (tenant_id),
			KEY status (status),
			KEY issued_at (issued_at)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'coupons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(64) NOT NULL,
			description varchar(191) NOT NULL DEFAULT '',
			discount_type varchar(20) NOT NULL DEFAULT 'percent',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			max_discount decimal(12,2) NOT NULL DEFAULT 0.00,
			applicable_plans longtext NULL,
			starts_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			usage_limit int(11) NOT NULL DEFAULT 0,
			per_customer_limit int(11) NOT NULL DEFAULT 1,
			first_time_only tinyint(1) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY active (active)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'coupon_redemptions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_id bigint(20) unsigned NOT NULL,
			tenant_id bigint(20) unsigned NOT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			transaction_id bigint(20) unsigned DEFAULT NULL,
			discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY coupon_transaction (coupon_id,transaction_id),
			KEY tenant_coupon (tenant_id,coupon_id)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'usage_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tenant_id bigint(20) unsigned NOT NULL,
			build_id varchar(64) NOT NULL DEFAULT '',
			metric varchar(40) NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			period_start datetime DEFAULT NULL,
			period_end datetime DEFAULT NULL,
			idempotency_key varchar(191) NOT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY tenant_metric_period (tenant_id,metric,period_start),
			KEY build_id (build_id)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'webhook_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gateway varchar(30) NOT NULL,
			event_id varchar(191) NOT NULL,
			event_type varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'received',
			payload longtext NULL,
			error text NULL,
			created_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_event (gateway,event_id),
			KEY status (status)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'customer_profiles' ) . " (
			tenant_id bigint(20) unsigned NOT NULL,
			business_name varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			address_1 varchar(191) NOT NULL DEFAULT '',
			address_2 varchar(191) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(100) NOT NULL DEFAULT '',
			postal_code varchar(20) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT 'IN',
			gstin varchar(20) NOT NULL DEFAULT '',
			current_subscription_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (tenant_id),
			KEY current_subscription_id (current_subscription_id)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'invoice_counters' ) . " (
			scope varchar(50) NOT NULL,
			next_number bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (scope)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'audit_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tenant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(100) NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id varchar(64) NOT NULL DEFAULT '',
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_id (tenant_id),
			KEY action (action),
			KEY created_at (created_at)
		) $charset;";

		$schemas[] = "CREATE TABLE " . self::table( 'notification_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tenant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(50) NOT NULL,
			recipient varchar(191) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'sent',
			error text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_id (tenant_id),
			KEY event (event)
		) $charset;";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}

		self::migrate_plan_columns();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Add billing columns to the SaaS-owned bg360_plans table.
	 */
	public static function migrate_plan_columns() {
		global $wpdb;
		$table = self::table( 'plans' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$columns  = array(
			'description'           => 'text NULL',
			'monthly_price'         => 'decimal(12,2) NOT NULL DEFAULT 0.00',
			'annual_price'          => 'decimal(12,2) NOT NULL DEFAULT 0.00',
			'currency'              => "char(3) NOT NULL DEFAULT 'INR'",
			'billing_interval'      => "varchar(20) NOT NULL DEFAULT 'monthly'",
			'trial_days'            => 'int(11) NOT NULL DEFAULT 0',
			'monthly_builds'        => 'int(11) NOT NULL DEFAULT 0',
			'max_concurrent_builds' => 'int(11) NOT NULL DEFAULT 1',
			'storage_mb'            => 'int(11) NOT NULL DEFAULT 0',
			'apk_access'            => 'tinyint(1) NOT NULL DEFAULT 1',
			'aab_access'            => 'tinyint(1) NOT NULL DEFAULT 0',
			'ipa_access'            => 'tinyint(1) NOT NULL DEFAULT 0',
			'native_features'       => 'tinyint(1) NOT NULL DEFAULT 0',
			'push_notifications'    => 'tinyint(1) NOT NULL DEFAULT 0',
			'design_studio'         => 'tinyint(1) NOT NULL DEFAULT 0',
			'custom_branding'       => 'tinyint(1) NOT NULL DEFAULT 0',
			'priority_build'        => 'tinyint(1) NOT NULL DEFAULT 0',
			'support_level'         => "varchar(50) NOT NULL DEFAULT 'standard'",
			'visibility'            => "varchar(20) NOT NULL DEFAULT 'public'",
			'sort_order'            => 'int(11) NOT NULL DEFAULT 0',
			'status'                => "varchar(20) NOT NULL DEFAULT 'active'",
			'updated_at'            => 'datetime DEFAULT NULL',
		);

		foreach ( $columns as $column => $definition ) {
			if ( ! in_array( $column, $existing, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			}
		}

		// Keep the SaaS legacy columns and the billing columns in sync.
		$wpdb->query( "UPDATE {$table} SET monthly_price = price WHERE monthly_price = 0 AND price > 0" );
		$wpdb->query( "UPDATE {$table} SET price = monthly_price WHERE price = 0 AND monthly_price > 0" );
		$wpdb->query( "UPDATE {$table} SET monthly_builds = max_builds_per_month WHERE monthly_builds = 0 AND max_builds_per_month > 0" );
		$wpdb->query( "UPDATE {$table} SET max_builds_per_month = monthly_builds WHERE max_builds_per_month = 0 AND monthly_builds > 0" );
		$wpdb->query( "UPDATE {$table} SET status = IF(is_active = 1, 'active', 'inactive') WHERE status = '' OR status IS NULL" );
		$wpdb->query( "UPDATE {$table} SET is_active = IF(status = 'active', 1, 0)" );
	}

	/**
	 * Run schema installation only when the billing or SaaS schema changed.
	 */
	public static function maybe_upgrade() {
		$saas_stale = class_exists( '\Bizgrowth360\AppBuilder\SaasDb' )
			&& get_option( \Bizgrowth360\AppBuilder\SaasDb::DB_VERSION_OPTION ) !== \Bizgrowth360\AppBuilder\SaasDb::DB_VERSION;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && ! $saas_stale ) {
			return;
		}

		self::install();
		\Bizgrowth360\AppBuilder\Billing\Plans::seed_defaults();
		\Bizgrowth360\AppBuilder\Billing\Subscriptions::ensure_workspaces();
		\Bizgrowth360\AppBuilder\Billing\BillingShortcodes::ensure_pages();
	}

	/**
	 * Write an audit record. Never store secrets.
	 *
	 * @param string $action
	 * @param array  $args
	 */
	public static function audit( $action, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'tenant_id'   => 0,
			'actor_id'    => get_current_user_id(),
			'object_type' => '',
			'object_id'   => '',
			'details'     => array(),
		);
		$r = wp_parse_args( $args, $defaults );

		$wpdb->insert(
			self::table( 'audit_log' ),
			array(
				'tenant_id'   => absint( $r['tenant_id'] ),
				'actor_id'    => absint( $r['actor_id'] ),
				'action'      => sanitize_key( $action ),
				'object_type' => sanitize_key( $r['object_type'] ),
				'object_id'   => sanitize_text_field( (string) $r['object_id'] ),
				'details'     => wp_json_encode( $r['details'] ),
				'created_at'  => self::now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
