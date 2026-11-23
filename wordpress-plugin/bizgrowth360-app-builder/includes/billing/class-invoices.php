<?php
/**
 * Invoice generation, numbering, secure download and refund status.
 *
 * Invoices are HTML documents intended for printing/saving as PDF. The engine
 * does not claim legal GST compliance; tax configuration and validation remain
 * the merchant's responsibility.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Invoices {

	/**
	 * Get an invoice.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'invoices' ) . ' WHERE id = %d LIMIT 1', absint( $id ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Get an invoice by transaction ID.
	 *
	 * @param int $transaction_id
	 * @return object|null
	 */
	public static function get_by_transaction( $transaction_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . BillingDb::table( 'invoices' ) . ' WHERE transaction_id = %d LIMIT 1', absint( $transaction_id ) )
		);
		return $row ? self::normalize_row( $row ) : null;
	}

	/**
	 * Create an invoice from a successful transaction.
	 *
	 * @param int $transaction_id
	 * @return object|\WP_Error
	 */
	public static function create_from_transaction( $transaction_id ) {
		global $wpdb;

		$existing = self::get_by_transaction( $transaction_id );
		if ( $existing ) {
			return $existing;
		}

		$transaction = Payments::get_transaction( $transaction_id );
		if ( ! $transaction || 'succeeded' !== $transaction->status ) {
			return new \WP_Error( 'invoice_transaction', __( 'A successful transaction is required to create an invoice.', 'bizgrowth360-app-builder' ) );
		}

		$subscription = $transaction->subscription_id ? Subscriptions::get( $transaction->subscription_id ) : null;
		$plan         = $subscription ? Plans::get( $subscription->plan_id ) : null;
		$profile      = Tenant::get_profile( $transaction->tenant_id );
		$meta         = is_array( $transaction->meta ) ? $transaction->meta : array();
		$tax          = is_array( $meta['tax'] ?? null ) ? $meta['tax'] : array();
		$now          = BillingDb::now();
		$number       = self::next_number( 'invoice:' . gmdate( 'Y', strtotime( $now ) ) );

		$record = array(
			'invoice_number'       => $number,
			'tenant_id'            => $transaction->tenant_id,
			'subscription_id'      => $transaction->subscription_id,
			'transaction_id'       => $transaction->id,
			'status'               => 'issued',
			'business_name'        => $profile->business_name ?? '',
			'customer_name'        => $profile->customer_name ?: Tenant::name( $transaction->tenant_id ),
			'email'                => $profile->email ?: Tenant::email( $transaction->tenant_id ),
			'address_1'            => $profile->address_1 ?? '',
			'address_2'            => $profile->address_2 ?? '',
			'city'                 => $profile->city ?? '',
			'state'                => $profile->state ?? '',
			'postal_code'          => $profile->postal_code ?? '',
			'country'              => $profile->country ?? 'IN',
			'gstin'                => $profile->gstin ?? '',
			'plan_name'            => $plan ? $plan->name : ( $meta['plan_name'] ?? '' ),
			'billing_period_start' => $subscription->current_period_start ?? null,
			'billing_period_end'   => $subscription->current_period_end ?? null,
			'subtotal'             => round( (float) ( $meta['subtotal'] ?? $transaction->amount ), 2 ),
			'discount'             => round( (float) ( $meta['discount'] ?? $transaction->discount_amount ), 2 ),
			'taxable_amount'       => round( (float) ( $tax['taxable_amount'] ?? $transaction->amount ), 2 ),
			'tax_rate'             => round( (float) ( $tax['tax_rate'] ?? 0 ), 4 ),
			'tax_amount'           => round( (float) ( $tax['tax_amount'] ?? $transaction->tax_amount ), 2 ),
			'total'                => round( (float) $transaction->amount, 2 ),
			'currency'             => $transaction->currency,
			'payment_status'       => 'paid',
			'payment_reference'    => $transaction->gateway_payment_id ?? '',
			'meta'                 => wp_json_encode( array( 'tax' => $tax, 'gateway' => $transaction->gateway, 'transaction_type' => $transaction->type ) ),
			'issued_at'            => $now,
			'paid_at'              => $now,
			'created_at'           => $now,
		);

		$wpdb->insert( BillingDb::table( 'invoices' ), $record );
		if ( ! $wpdb->insert_id ) {
			return new \WP_Error( 'invoice_error', __( 'Could not create invoice.', 'bizgrowth360-app-builder' ) );
		}

		$invoice_id = (int) $wpdb->insert_id;
		$wpdb->update( BillingDb::table( 'transactions' ), array( 'invoice_id' => $invoice_id ), array( 'id' => $transaction->id ) );
		BillingDb::audit( 'invoice_created', array( 'tenant_id' => $transaction->tenant_id, 'object_type' => 'invoice', 'object_id' => $invoice_id, 'details' => array( 'number' => $number, 'total' => $record['total'] ) ) );

		return self::get( $invoice_id );
	}

	/**
	 * Atomically reserve the next invoice number.
	 *
	 * @param string $scope
	 * @return string
	 */
	public static function next_number( $scope ) {
		global $wpdb;
		$table = BillingDb::table( 'invoice_counters' );
		$scope = sanitize_text_field( $scope );

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (scope, next_number) VALUES (%s, 0)", $scope ) );
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$table} WHERE scope = %s FOR UPDATE", $scope ) );
		$next    = $current + 1;
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_number = %d WHERE scope = %s", $next, $scope ) );
		$wpdb->query( 'COMMIT' );

		$year = gmdate( 'Y' );
		return sprintf( 'BG360/%s/%06d', $year, $next );
	}

	/**
	 * List invoices.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$defaults = array( 'tenant_id' => 0, 'status' => '', 'limit' => 50, 'offset' => 0, 'from' => '', 'to' => '' );
		$r = wp_parse_args( $args, $defaults );
		$where = array( '1=1' );
		$values = array();
		if ( $r['tenant_id'] ) {
			$where[]  = 'tenant_id = %d';
			$values[] = absint( $r['tenant_id'] );
		}
		if ( $r['status'] ) {
			$where[]  = 'payment_status = %s';
			$values[] = sanitize_key( $r['status'] );
		}
		if ( $r['from'] ) {
			$where[]  = 'issued_at >= %s';
			$values[] = sanitize_text_field( $r['from'] );
		}
		if ( $r['to'] ) {
			$where[]  = 'issued_at <= %s';
			$values[] = sanitize_text_field( $r['to'] );
		}
		$values[] = max( 1, absint( $r['limit'] ) );
		$values[] = max( 0, absint( $r['offset'] ) );
		$sql = 'SELECT * FROM ' . BillingDb::table( 'invoices' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		return array_map( array( __CLASS__, 'normalize_row' ), $rows ? $rows : array() );
	}

	/**
	 * Mark an invoice refunded or partially refunded.
	 *
	 * @param int   $invoice_id
	 * @param float $amount
	 */
	public static function mark_refunded( $invoice_id, $amount ) {
		global $wpdb;
		$invoice = self::get( $invoice_id );
		if ( ! $invoice ) {
			return;
		}
		$status = (float) $amount >= (float) $invoice->total ? 'refunded' : 'partially_refunded';
		$wpdb->update(
			BillingDb::table( 'invoices' ),
			array( 'payment_status' => $status, 'status' => $status ),
			array( 'id' => absint( $invoice_id ) )
		);
		BillingDb::audit( 'invoice_refunded', array( 'tenant_id' => $invoice->tenant_id, 'object_type' => 'invoice', 'object_id' => $invoice_id, 'details' => array( 'amount' => $amount, 'status' => $status ) ) );
	}

	/**
	 * Secure download URL.
	 *
	 * @param int $invoice_id
	 * @return string
	 */
	public static function download_url( $invoice_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bizgrowth360_invoice&invoice_id=' . absint( $invoice_id ) ),
			'bizgrowth360_invoice_' . absint( $invoice_id )
		);
	}

	/**
	 * Handle secure invoice download.
	 */
	public static function handle_download() {
		$invoice_id = isset( $_GET['invoice_id'] ) ? absint( $_GET['invoice_id'] ) : 0;
		if ( ! $invoice_id ) {
			wp_die( esc_html__( 'Invoice not found.', 'bizgrowth360-app-builder' ), 404 );
		}
		check_admin_referer( 'bizgrowth360_invoice_' . $invoice_id );

		$invoice = self::get( $invoice_id );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'bizgrowth360-app-builder' ), 404 );
		}
		if ( ! current_user_can( 'manage_options' ) && (int) Tenant::current_id() !== (int) $invoice->tenant_id ) {
			wp_die( esc_html__( 'You are not allowed to download this invoice.', 'bizgrowth360-app-builder' ), 403 );
		}

		BillingDb::audit( 'invoice_downloaded', array( 'tenant_id' => $invoice->tenant_id, 'object_type' => 'invoice', 'object_id' => $invoice_id ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $invoice->invoice_number ) . '.html"' );
		echo self::render_html( $invoice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered with escaped fields.
		exit;
	}

	/**
	 * Render a printable invoice.
	 *
	 * @param object $invoice
	 * @return string
	 */
	public static function render_html( $invoice ) {
		$currency = $invoice->currency;
		$rows = array(
			__( 'Plan', 'bizgrowth360-app-builder' )          => $invoice->plan_name,
			__( 'Billing period', 'bizgrowth360-app-builder' )=> trim( (string) $invoice->billing_period_start . ' — ' . (string) $invoice->billing_period_end ),
			__( 'Subtotal', 'bizgrowth360-app-builder' )      => Tax::format_money( $invoice->subtotal, $currency ),
			__( 'Discount', 'bizgrowth360-app-builder' )      => '-' . Tax::format_money( $invoice->discount, $currency ),
			__( 'Taxable amount', 'bizgrowth360-app-builder' )=> Tax::format_money( $invoice->taxable_amount, $currency ),
			__( 'Tax', 'bizgrowth360-app-builder' )           => Tax::format_money( $invoice->tax_amount, $currency ) . ' (' . $invoice->tax_rate . '%)',
			__( 'Total', 'bizgrowth360-app-builder' )         => Tax::format_money( $invoice->total, $currency ),
			__( 'Payment status', 'bizgrowth360-app-builder' )=> $invoice->payment_status,
			__( 'Payment reference', 'bizgrowth360-app-builder' ) => $invoice->payment_reference,
		);

		$html  = '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $invoice->invoice_number ) . '</title>';
		$html .= '<style>body{font-family:Arial,sans-serif;color:#111;margin:32px;}h1{font-size:22px;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{text-align:left;padding:8px;border-bottom:1px solid #ddd;}th{width:40%;color:#555;} .muted{color:#666;font-size:12px;margin-top:24px;}</style></head><body>';
		$html .= '<h1>' . esc_html__( 'Invoice', 'bizgrowth360-app-builder' ) . ' ' . esc_html( $invoice->invoice_number ) . '</h1>';
		$html .= '<p><strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong><br>' . esc_html( $invoice->business_name ) . '</p>';
		$html .= '<p>' . esc_html( $invoice->customer_name ) . '<br>' . esc_html( $invoice->email ) . '<br>';
		$html .= esc_html( trim( $invoice->address_1 . ' ' . $invoice->address_2 ) ) . '<br>';
		$html .= esc_html( trim( $invoice->city . ', ' . $invoice->state . ' ' . $invoice->postal_code . ' ' . $invoice->country ) ) . '<br>';
		if ( $invoice->gstin ) {
			$html .= esc_html__( 'GSTIN:', 'bizgrowth360-app-builder' ) . ' ' . esc_html( $invoice->gstin );
		}
		$html .= '</p><p>' . esc_html__( 'Invoice date:', 'bizgrowth360-app-builder' ) . ' ' . esc_html( $invoice->issued_at ) . '</p>';
		$html .= '<table>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		$html .= '</table>';
		$html .= '<p class="muted">' . esc_html__( 'This invoice is generated from the configured tax settings. It is not a guarantee of legal GST compliance; verify tax registration and invoice requirements with your tax adviser.', 'bizgrowth360-app-builder' ) . '</p>';
		$html .= '</body></html>';
		return $html;
	}

	/**
	 * Normalize invoice row.
	 *
	 * @param object $row
	 * @return object
	 */
	public static function normalize_row( $row ) {
		$row->id = (int) $row->id;
		$row->tenant_id = (int) $row->tenant_id;
		$row->subtotal = (float) $row->subtotal;
		$row->discount = (float) $row->discount;
		$row->taxable_amount = (float) $row->taxable_amount;
		$row->tax_rate = (float) $row->tax_rate;
		$row->tax_amount = (float) $row->tax_amount;
		$row->total = (float) $row->total;
		$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		return $row;
	}
}
