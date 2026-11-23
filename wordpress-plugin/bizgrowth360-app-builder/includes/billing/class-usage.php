<?php
/**
 * Usage accounting and quota reservations.
 *
 * Quota policy:
 * - A dispatched build reserves one "build_started" unit for the tenant period.
 * - Dispatch failures release the reservation.
 * - Successful, failed and cancelled outcomes are recorded separately for reporting.
 * - Monthly quota therefore counts build attempts that actually reached the runner,
 *   including cancelled builds, but not validation or dispatch failures.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Usage {

	const METRICS = array(
		'app_created',
		'build_started',
		'build_apk',
		'build_aab',
		'build_ipa',
		'build_success',
		'build_failed',
		'build_cancelled',
		'storage_mb',
		'premium_feature',
		'quota_warning',
		'quota_exhausted',
	);

	/**
	 * Current billing period for a tenant.
	 *
	 * Uses the subscription period when available, otherwise the calendar month.
	 *
	 * @param int $tenant_id
	 * @return array {start, end}
	 */
	public static function period_for( $tenant_id ) {
		$subscription = Subscriptions::get_current( $tenant_id );
		if ( $subscription && ! empty( $subscription->current_period_start ) && ! empty( $subscription->current_period_end ) ) {
			return array(
				'start' => $subscription->current_period_start,
				'end'   => $subscription->current_period_end,
			);
		}

		$now = current_time( 'timestamp' );
		return array(
			'start' => gmdate( 'Y-m-01 00:00:00', $now ),
			'end'   => gmdate( 'Y-m-t 23:59:59', $now ),
		);
	}

	/**
	 * Record an idempotent usage event.
	 *
	 * @param int    $tenant_id
	 * @param string $metric
	 * @param int    $quantity
	 * @param string $idempotency_key
	 * @param array  $args
	 * @return bool
	 */
	public static function record( $tenant_id, $metric, $quantity = 1, $idempotency_key = '', $args = array() ) {
		global $wpdb;
		$tenant_id = absint( $tenant_id );
		$metric    = sanitize_key( $metric );
		if ( ! $tenant_id || ! in_array( $metric, self::METRICS, true ) ) {
			return false;
		}

		$key = $idempotency_key ?: $metric . ':' . $tenant_id . ':' . wp_generate_uuid4();
		$period = self::period_for( $tenant_id );
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . BillingDb::table( 'usage_events' ) . ' WHERE idempotency_key = %s LIMIT 1', $key )
		);
		if ( $exists ) {
			return true;
		}

		$inserted = $wpdb->insert(
			BillingDb::table( 'usage_events' ),
			array(
				'tenant_id'      => $tenant_id,
				'build_id'       => sanitize_text_field( $args['build_id'] ?? '' ),
				'metric'         => $metric,
				'quantity'       => max( 1, absint( $quantity ) ),
				'period_start'   => $period['start'],
				'period_end'     => $period['end'],
				'idempotency_key'=> $key,
				'meta'           => wp_json_encode( $args['meta'] ?? array() ),
				'created_at'     => BillingDb::now(),
			)
		);

		return (bool) $inserted;
	}

	/**
	 * Reserve one build unit atomically for the tenant period.
	 *
	 * @param int    $tenant_id
	 * @param string $build_id
	 * @param array  $meta
	 * @return true|\WP_Error
	 */
	public static function reserve_build( $tenant_id, $build_id, $meta = array() ) {
		global $wpdb;

		$tenant_id = absint( $tenant_id );
		$build_id  = sanitize_text_field( $build_id );
		$plan      = Tenant::plan( $tenant_id );
		if ( ! $tenant_id || ! $build_id || ! $plan ) {
			return new \WP_Error( 'usage_invalid', __( 'A plan and tenant are required to reserve a build.', 'bizgrowth360-app-builder' ) );
		}

		$limit = (int) $plan->monthly_builds;
		if ( $limit < 0 ) {
			return self::record( $tenant_id, 'build_started', 1, 'build_started:' . $build_id, array( 'build_id' => $build_id, 'meta' => $meta ) )
				? true
				: new \WP_Error( 'usage_error', __( 'Could not reserve build quota.', 'bizgrowth360-app-builder' ) );
		}

		$period = self::period_for( $tenant_id );
		$table  = BillingDb::table( 'usage_events' );

		$wpdb->query( 'START TRANSACTION' );
		$used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(quantity),0) FROM {$table}
				 WHERE tenant_id = %d AND metric = 'build_started'
				 AND period_start = %s AND period_end = %s",
				$tenant_id,
				$period['start'],
				$period['end']
			)
		);

		if ( $used >= $limit ) {
			$wpdb->query( 'ROLLBACK' );
			BillingDb::audit( 'quota_exhausted', array( 'tenant_id' => $tenant_id, 'object_type' => 'build', 'object_id' => $build_id, 'details' => array( 'used' => $used, 'limit' => $limit ) ) );
			return new \WP_Error(
				'build_quota_exhausted',
				sprintf(
					/* translators: 1: used builds, 2: plan limit */
					__( 'Monthly build limit reached (%1$d of %2$d). Upgrade your plan or wait for the next billing period.', 'bizgrowth360-app-builder' ),
					$used,
					$limit
				)
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'tenant_id'       => $tenant_id,
				'build_id'        => $build_id,
				'metric'          => 'build_started',
				'quantity'        => 1,
				'period_start'    => $period['start'],
				'period_end'      => $period['end'],
				'idempotency_key' => 'build_started:' . $build_id,
				'meta'            => wp_json_encode( $meta ),
				'created_at'      => BillingDb::now(),
			)
		);

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			// A duplicate reservation is idempotent success.
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1", 'build_started:' . $build_id )
			);
			return $existing ? true : new \WP_Error( 'usage_error', __( 'Could not reserve build quota.', 'bizgrowth360-app-builder' ) );
		}

		$wpdb->query( 'COMMIT' );

		if ( $used + 1 >= $limit ) {
			self::record( $tenant_id, 'quota_warning', 1, 'quota_warning:' . $build_id, array( 'build_id' => $build_id, 'meta' => array( 'used' => $used + 1, 'limit' => $limit ) ) );
		}

		return true;
	}

	/**
	 * Release a reservation when dispatch fails.
	 *
	 * @param int    $tenant_id
	 * @param string $build_id
	 */
	public static function release_build( $tenant_id, $build_id ) {
		global $wpdb;
		$wpdb->delete(
			BillingDb::table( 'usage_events' ),
			array(
				'tenant_id'       => absint( $tenant_id ),
				'idempotency_key' => 'build_started:' . sanitize_text_field( $build_id ),
			)
		);
	}

	/**
	 * Record a build outcome idempotently.
	 *
	 * @param int    $tenant_id
	 * @param string $build_id
	 * @param string $status completed|failed|cancelled
	 * @param string $platform android|ios
	 * @param string $build_type apk|aab|both|ipa
	 */
	public static function record_outcome( $tenant_id, $build_id, $status, $platform, $build_type ) {
		$status = sanitize_key( $status );
		$metric = 'build_' . ( in_array( $status, array( 'failed', 'cancelled', 'success' ), true ) ? $status : 'failed' );
		if ( 'success' === $status ) {
			$metric = 'build_success';
		}
		self::record(
			$tenant_id,
			$metric,
			1,
			'build_outcome:' . $build_id . ':' . $status,
			array( 'build_id' => $build_id, 'meta' => array( 'platform' => $platform, 'build_type' => $build_type ) )
		);

		$type_metric = 'ios' === $platform ? 'build_ipa' : ( 'apk' === $build_type ? 'build_apk' : ( 'aab' === $build_type ? 'build_aab' : 'build_apk' ) );
		self::record(
			$tenant_id,
			$type_metric,
			1,
			'build_type:' . $build_id . ':' . $type_metric,
			array( 'build_id' => $build_id, 'meta' => array( 'platform' => $platform, 'build_type' => $build_type ) )
		);
	}

	/**
	 * Count a metric for a tenant period.
	 *
	 * @param int    $tenant_id
	 * @param string $metric
	 * @param array|null $period
	 * @return int
	 */
	public static function count( $tenant_id, $metric, $period = null ) {
		global $wpdb;
		$period = $period ?: self::period_for( $tenant_id );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(quantity),0) FROM ' . BillingDb::table( 'usage_events' ) . '
				 WHERE tenant_id = %d AND metric = %s AND period_start = %s AND period_end = %s',
				absint( $tenant_id ),
				sanitize_key( $metric ),
				$period['start'],
				$period['end']
			)
		);
	}

	/**
	 * Count active builds for a tenant.
	 *
	 * @param int $tenant_id
	 * @return int
	 */
	public static function active_builds( $tenant_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bg360_builds';
		$has_tenant = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'tenant_id' ) );
		$where = $has_tenant ? 'tenant_id = %d' : 'created_by = %d';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE {$where}
				 AND status IN ('queued','dispatching','starting','preparing','building','signing','exporting','uploading','in_progress')",
				absint( $tenant_id )
			)
		);
	}

	/**
	 * Approximate stored artifact size for a tenant in MB.
	 *
	 * @param int $tenant_id
	 * @return float
	 */
	public static function storage_used_mb( $tenant_id ) {
		global $wpdb;
		$tenant_id = absint( $tenant_id );
		$cache_key = 'bg360_storage_' . $tenant_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		$table = $wpdb->prefix . 'bg360_builds';
		$has_tenant = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'tenant_id' ) );
		$where = $has_tenant ? 'tenant_id = %d' : 'created_by = %d';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT artifacts FROM {$table} WHERE {$where} AND artifacts IS NOT NULL AND artifacts <> ''",
				$tenant_id
			)
		);

		$bytes = 0;
		foreach ( $rows ? $rows : array() as $row ) {
			$artifacts = json_decode( $row->artifacts, true );
			if ( ! is_array( $artifacts ) ) {
				continue;
			}
			foreach ( $artifacts as $artifact ) {
				$bytes += (int) ( $artifact['size_bytes'] ?? 0 );
			}
		}

		$mb = round( $bytes / 1024 / 1024, 2 );
		set_transient( $cache_key, $mb, 5 * MINUTE_IN_SECONDS );
		return $mb;
	}

	/**
	 * Clear storage cache for a tenant.
	 *
	 * @param int $tenant_id
	 */
	public static function clear_storage_cache( $tenant_id ) {
		delete_transient( 'bg360_storage_' . absint( $tenant_id ) );
	}

	/**
	 * Complete usage/quota summary for a tenant.
	 *
	 * @param int $tenant_id
	 * @return array
	 */
	public static function summary( $tenant_id ) {
		$tenant_id = absint( $tenant_id );
		$plan      = Tenant::plan( $tenant_id );
		$period    = self::period_for( $tenant_id );
		$used      = array(
			'build_started' => self::count( $tenant_id, 'build_started', $period ),
			'build_success' => self::count( $tenant_id, 'build_success', $period ),
			'build_failed'  => self::count( $tenant_id, 'build_failed', $period ),
			'build_cancelled' => self::count( $tenant_id, 'build_cancelled', $period ),
			'build_apk'     => self::count( $tenant_id, 'build_apk', $period ),
			'build_aab'     => self::count( $tenant_id, 'build_aab', $period ),
			'build_ipa'     => self::count( $tenant_id, 'build_ipa', $period ),
			'apps'          => Apps::count( $tenant_id ),
			'active_builds' => self::active_builds( $tenant_id ),
			'storage_mb'    => self::storage_used_mb( $tenant_id ),
		);

		$limits = $plan ? array(
			'apps'          => (int) $plan->max_apps,
			'builds'        => (int) $plan->monthly_builds,
			'concurrent'    => (int) $plan->max_concurrent_builds,
			'storage_mb'    => (int) $plan->storage_mb,
		) : array( 'apps' => 0, 'builds' => 0, 'concurrent' => 0, 'storage_mb' => 0 );

		return array(
			'period' => $period,
			'used'   => $used,
			'limits' => $limits,
			'remaining' => array(
				'apps'       => self::remaining( $used['apps'], $limits['apps'] ),
				'builds'     => self::remaining( $used['build_started'], $limits['builds'] ),
				'concurrent' => self::remaining( $used['active_builds'], $limits['concurrent'] ),
				'storage_mb' => self::remaining( $used['storage_mb'], $limits['storage_mb'] ),
			),
		);
	}

	/**
	 * Remaining units. -1 = unlimited.
	 *
	 * @param float $used
	 * @param int   $limit
	 * @return float|int
	 */
	public static function remaining( $used, $limit ) {
		if ( (int) $limit < 0 ) {
			return -1;
		}
		return max( 0, (float) $limit - (float) $used );
	}
}
