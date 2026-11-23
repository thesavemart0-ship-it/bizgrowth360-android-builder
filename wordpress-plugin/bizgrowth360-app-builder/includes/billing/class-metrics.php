<?php
/**
 * Billing dashboard metrics with explicit calculation rules.
 *
 * Revenue = successful payments minus refunds in the selected date range.
 * MRR = normalized monthly value of active/grace/past-due subscriptions:
 * monthly plans use the monthly amount; annual plans use annual amount / 12.
 * ARR here is an annualized run rate (MRR x 12), not contracted annual value.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metrics {

	/**
	 * Gross payments in a date range.
	 *
	 * @param string $from
	 * @param string $to
	 * @return float
	 */
	public static function gross_payments( $from = '', $to = '' ) {
		global $wpdb;
		$where = array( "type = 'payment'", "status = 'succeeded'" );
		$values = array();
		if ( $from ) {
			$where[] = 'created_at >= %s';
			$values[] = $from;
		}
		if ( $to ) {
			$where[] = 'created_at <= %s';
			$values[] = $to;
		}
		$sql = 'SELECT COALESCE(SUM(amount),0) FROM ' . BillingDb::table( 'transactions' ) . ' WHERE ' . implode( ' AND ', $where );
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		return round( (float) $wpdb->get_var( $sql ), 2 );
	}

	/**
	 * Refunds in a date range.
	 *
	 * @param string $from
	 * @param string $to
	 * @return float
	 */
	public static function refunds( $from = '', $to = '' ) {
		global $wpdb;
		$where = array( "type = 'refund'", "status = 'succeeded'" );
		$values = array();
		if ( $from ) {
			$where[] = 'created_at >= %s';
			$values[] = $from;
		}
		if ( $to ) {
			$where[] = 'created_at <= %s';
			$values[] = $to;
		}
		$sql = 'SELECT COALESCE(SUM(amount),0) FROM ' . BillingDb::table( 'transactions' ) . ' WHERE ' . implode( ' AND ', $where );
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		return round( (float) $wpdb->get_var( $sql ), 2 );
	}

	/**
	 * Net revenue.
	 *
	 * @param string $from
	 * @param string $to
	 * @return float
	 */
	public static function net_revenue( $from = '', $to = '' ) {
		return round( self::gross_payments( $from, $to ) - self::refunds( $from, $to ), 2 );
	}

	/**
	 * MRR across active, grace and past-due subscriptions.
	 *
	 * @return float
	 */
	public static function mrr() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT billing_interval, amount FROM " . BillingDb::table( 'subscriptions' ) . " WHERE status IN ('active','grace','past_due')"
		);
		$mrr = 0.0;
		foreach ( $rows ? $rows : array() as $row ) {
			$amount = (float) $row->amount;
			$mrr += 'annual' === $row->billing_interval ? $amount / 12 : $amount;
		}
		return round( $mrr, 2 );
	}

	/**
	 * Annualized run rate.
	 *
	 * @return float
	 */
	public static function arr() {
		return round( self::mrr() * 12, 2 );
	}

	/**
	 * Subscription status counts.
	 *
	 * @return array
	 */
	public static function status_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT status, COUNT(*) AS total FROM ' . BillingDb::table( 'subscriptions' ) . ' GROUP BY status'
		);
		$counts = array_fill_keys( Subscriptions::STATUSES, 0 );
		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Plan distribution for current subscriptions.
	 *
	 * @return array
	 */
	public static function plan_distribution() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT p.name, p.slug, COUNT(s.id) AS total
			 FROM ' . BillingDb::table( 'plans' ) . ' p
			 LEFT JOIN ' . BillingDb::table( 'subscriptions' ) . " s ON s.plan_id = p.id AND s.status IN ('active','trial','grace','past_due')
			 GROUP BY p.id, p.name, p.slug ORDER BY total DESC, p.sort_order ASC"
		);
		return $rows ?: array();
	}

	/**
	 * Failed payments in a range.
	 *
	 * @param string $from
	 * @param string $to
	 * @return int
	 */
	public static function payment_failures( $from = '', $to = '' ) {
		global $wpdb;
		$where = array( "type = 'payment'", "status = 'failed'" );
		$values = array();
		if ( $from ) {
			$where[] = 'created_at >= %s';
			$values[] = $from;
		}
		if ( $to ) {
			$where[] = 'created_at <= %s';
			$values[] = $to;
		}
		$sql = 'SELECT COUNT(*) FROM ' . BillingDb::table( 'transactions' ) . ' WHERE ' . implode( ' AND ', $where );
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Dashboard metrics.
	 *
	 * @param string $from
	 * @param string $to
	 * @return array
	 */
	public static function dashboard( $from = '', $to = '' ) {
		$statuses = self::status_counts();
		return array(
			'gross_revenue'   => self::gross_payments( $from, $to ),
			'refunds'         => self::refunds( $from, $to ),
			'net_revenue'     => self::net_revenue( $from, $to ),
			'mrr'             => self::mrr(),
			'arr'             => self::arr(),
			'active'          => $statuses['active'],
			'trials'          => $statuses['trial'],
			'past_due'        => $statuses['past_due'],
			'grace'           => $statuses['grace'],
			'suspended'       => $statuses['suspended'],
			'cancelled'       => $statuses['cancelled'],
			'expired'         => $statuses['expired'],
			'payment_failures'=> self::payment_failures( $from, $to ),
			'plan_distribution' => self::plan_distribution(),
			'statuses'        => $statuses,
		);
	}
}
