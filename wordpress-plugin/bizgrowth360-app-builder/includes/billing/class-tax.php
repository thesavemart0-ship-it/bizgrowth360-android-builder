<?php
/**
 * Server-side tax configuration and calculation.
 *
 * Tax rates are configuration, not business constants. The engine supports
 * inclusive or exclusive pricing and stores a breakdown on every invoice.
 * This is not a substitute for professional GST advice or filing validation.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tax {

	const OPTION_KEY = 'bizgrowth360_billing_tax';

	/**
	 * Default tax configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => false,
			'label'          => 'GST',
			'rate'           => 18.0,
			'inclusive'      => false,
			'country'        => 'IN',
			'state'          => '',
			'gstin_required' => false,
			'notes'          => '',
		);
	}

	/**
	 * Get saved tax configuration.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Save tax configuration.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function save_settings( $input ) {
		$rate = (float) ( $input['rate'] ?? 0 );
		$clean = array(
			'enabled'        => ! empty( $input['enabled'] ),
			'label'          => sanitize_text_field( $input['label'] ?? 'GST' ),
			'rate'           => max( 0, min( 100, round( $rate, 4 ) ) ),
			'inclusive'      => ! empty( $input['inclusive'] ),
			'country'        => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $input['country'] ?? 'IN' ), 0, 2 ) ) ?: 'IN',
			'state'          => sanitize_text_field( $input['state'] ?? '' ),
			'gstin_required' => ! empty( $input['gstin_required'] ),
			'notes'          => sanitize_textarea_field( $input['notes'] ?? '' ),
		);
		update_option( self::OPTION_KEY, $clean );
		BillingDb::audit( 'tax_settings_updated', array( 'object_type' => 'settings', 'details' => $clean ) );
		return $clean;
	}

	/**
	 * Calculate tax for an amount after discounts.
	 *
	 * @param float  $amount Amount after discount, in plan currency.
	 * @param string $country
	 * @param string $gstin
	 * @return array
	 */
	public static function calculate( $amount, $country = '', $gstin = '' ) {
		$settings = self::get_settings();
		$amount   = round( max( 0, (float) $amount ), 2 );

		if ( empty( $settings['enabled'] ) || (float) $settings['rate'] <= 0 ) {
			return array(
				'taxable_amount' => $amount,
				'tax_rate'       => 0.0,
				'tax_amount'     => 0.0,
				'total'          => $amount,
				'inclusive'      => false,
				'label'          => $settings['label'],
				'enabled'        => false,
			);
		}

		$rate = (float) $settings['rate'] / 100;

		if ( ! empty( $settings['inclusive'] ) ) {
			$taxable = $amount / ( 1 + $rate );
			$tax     = $amount - $taxable;
			$total   = $amount;
		} else {
			$taxable = $amount;
			$tax     = $amount * $rate;
			$total   = $amount + $tax;
		}

		return array(
			'taxable_amount' => round( $taxable, 2 ),
			'tax_rate'       => round( (float) $settings['rate'], 4 ),
			'tax_amount'     => round( $tax, 2 ),
			'total'          => round( $total, 2 ),
			'inclusive'      => ! empty( $settings['inclusive'] ),
			'label'          => $settings['label'],
			'enabled'        => true,
		);
	}

	/**
	 * Validate a GSTIN format without claiming tax compliance.
	 *
	 * @param string $gstin
	 * @return bool
	 */
	public static function is_valid_gstin_format( $gstin ) {
		return (bool) preg_match( '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', strtoupper( (string) $gstin ) );
	}

	/**
	 * Format money for display.
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return string
	 */
	public static function format_money( $amount, $currency = 'INR' ) {
		$symbols = array( 'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ' );
		$symbol  = $symbols[ strtoupper( $currency ) ] ?? strtoupper( $currency ) . ' ';
		return $symbol . number_format_i18n( (float) $amount, 2 );
	}
}
