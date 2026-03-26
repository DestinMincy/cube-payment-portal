<?php
/**
 * Currency conversion and formatting utilities.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Currency
 *
 * Handles all currency conversion between dollars and cents (Square API format).
 * Square API requires amounts in the smallest currency unit (cents for USD).
 */
class SPP_Currency {

	/**
	 * Convert dollars to cents.
	 * 
	 * Square API requires integer amounts in cents.
	 * 
	 * @param float|string $dollars Amount in dollars (e.g., 150.00).
	 * @return int Amount in cents (e.g., 15000).
	 */
	public static function dollars_to_cents( $dollars ) {
		return (int) round( (float) $dollars * 100 );
	}

	/**
	 * Convert cents to dollars.
	 * 
	 * @param int $cents Amount in cents (e.g., 15000).
	 * @return float Amount in dollars (e.g., 150.00).
	 */
	public static function cents_to_dollars( $cents ) {
		return (float) ( $cents / 100 );
	}

	/**
	 * Format cents as currency string.
	 * 
	 * @param int    $cents    Amount in cents.
	 * @param string $currency Currency code (default: USD).
	 * @return string Formatted currency (e.g., "$150.00").
	 */
	public static function format_money( $cents, $currency = 'USD' ) {
		$dollars = self::cents_to_dollars( $cents );
		
		// Currency symbols.
		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'CAD' => 'CA$',
			'AUD' => 'A$',
		);
		
		$symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';
		
		return $symbol . number_format( $dollars, 2, '.', ',' );
	}

	/**
	 * Validate line item amount.
	 * 
	 * Ensures amount is a positive integer suitable for Square API.
	 * 
	 * @param mixed $amount Amount to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public static function validate_amount( $amount ) {
		if ( ! is_numeric( $amount ) ) {
			return new WP_Error(
				'invalid_amount',
				__( 'Amount must be a number.', 'cube-payment-portal' )
			);
		}

		if ( $amount < 0 ) {
			return new WP_Error(
				'negative_amount',
				__( 'Amount cannot be negative.', 'cube-payment-portal' )
			);
		}

		return true;
	}

	/**
	 * Calculate line item total.
	 * 
	 * @param int    $unit_price_cents Unit price in cents.
	 * @param string $quantity         Quantity as string.
	 * @return int Total in cents.
	 */
	public static function calculate_line_total( $unit_price_cents, $quantity ) {
		$qty = floatval( $quantity );
		return (int) round( $unit_price_cents * $qty );
	}
}
