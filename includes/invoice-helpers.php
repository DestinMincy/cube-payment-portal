<?php
/**
 * Invoice validation helpers.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validate invoice data before creation.
 * 
 * @param array $data Invoice data to validate.
 * @return bool|WP_Error True if valid, WP_Error if invalid.
 */
function spp_validate_invoice_data( $data ) {
	$errors = array();

	// Customer is required.
	if ( empty( $data['customer_id'] ) && empty( $data['wp_user_id'] ) ) {
		$errors[] = __( 'Customer is required.', 'cube-payment-portal' );
	}

	// At least one line item required.
	if ( empty( $data['line_items'] ) || ! is_array( $data['line_items'] ) ) {
		$errors[] = __( 'At least one line item is required.', 'cube-payment-portal' );
	} else {
		// Validate each line item.
		foreach ( $data['line_items'] as $index => $item ) {
			$item_errors = spp_validate_line_item( $item, $index + 1 );
			if ( is_wp_error( $item_errors ) ) {
				$errors[] = $item_errors->get_error_message();
			}
		}
	}

	// Due date should be in the future (if provided).
	if ( ! empty( $data['due_date'] ) ) {
		$due_timestamp = strtotime( $data['due_date'] );
		if ( $due_timestamp < strtotime( 'today' ) ) {
			$errors[] = __( 'Due date must be in the future.', 'cube-payment-portal' );
		}
	}

	if ( ! empty( $errors ) ) {
		return new WP_Error( 'validation_failed', implode( '<br>', $errors ) );
	}

	return true;
}

/**
 * Validate a single line item.
 * 
 * @param array $item  Line item data.
 * @param int   $index Item index (for error messages).
 * @return bool|WP_Error True if valid, WP_Error if invalid.
 */
function spp_validate_line_item( $item, $index = 1 ) {
	// Name is required.
	if ( empty( $item['name'] ) ) {
		return new WP_Error(
			'missing_name',
			sprintf( __( 'Line item #%d: Name is required.', 'cube-payment-portal' ), $index )
		);
	}

	// Name length check (Square max: 255).
	if ( strlen( $item['name'] ) > 255 ) {
		return new WP_Error(
			'name_too_long',
			sprintf( __( 'Line item #%d: Name is too long (max 255 characters).', 'cube-payment-portal' ), $index )
		);
	}

	// Quantity must be present and positive.
	if ( ! isset( $item['quantity'] ) || floatval( $item['quantity'] ) <= 0 ) {
		return new WP_Error(
			'invalid_quantity',
			sprintf( __( 'Line item #%d: Quantity must be a positive number.', 'cube-payment-portal' ), $index )
		);
	}

	// Price is required.
	if ( ! isset( $item['base_price_money']['amount'] ) ) {
		return new WP_Error(
			'missing_price',
			sprintf( __( 'Line item #%d: Price is required.', 'cube-payment-portal' ), $index )
		);
	}

	// Amount must be integer (cents).
	$amount = $item['base_price_money']['amount'];
	if ( ! is_int( $amount ) || $amount < 0 ) {
		return new WP_Error(
			'invalid_amount',
			sprintf( __( 'Line item #%d: Amount must be a non-negative integer (in cents).', 'cube-payment-portal' ), $index )
		);
	}

	// Currency is required.
	if ( empty( $item['base_price_money']['currency'] ) ) {
		return new WP_Error(
			'missing_currency',
			sprintf( __( 'Line item #%d: Currency is required.', 'cube-payment-portal' ), $index )
		);
	}

	return true;
}

/**
 * Format line item data for Square API.
 * 
 * Ensures proper format (quantity as string, amount as int, etc.).
 * 
 * @param array $item Raw line item data.
 * @return array Formatted line item.
 */
function spp_format_line_item( $item ) {
	$formatted = array(
		'name'     => sanitize_text_field( $item['name'] ),
		'quantity' => (string) floatval( $item['quantity'] ), // Must be string!
		'base_price_money' => array(
			'amount'   => (int) $item['base_price_money']['amount'],
			'currency' => strtoupper( $item['base_price_money']['currency'] ?? 'USD' ),
		),
	);

	// Optional fields.
	if ( ! empty( $item['note'] ) ) {
		$formatted['note'] = sanitize_textarea_field( $item['note'] );
	}

	if ( ! empty( $item['catalog_object_id'] ) ) {
		$formatted['catalog_object_id'] = sanitize_text_field( $item['catalog_object_id'] );
	}

	if ( ! empty( $item['variation_name'] ) ) {
		$formatted['variation_name'] = sanitize_text_field( $item['variation_name'] );
	}

	return $formatted;
}

/**
 * Calculate invoice totals from line items.
 * 
 * @param array $line_items Array of line items.
 * @param array $options    Optional tax/discount settings.
 * @return array Totals (subtotal, tax, discount, total in cents).
 */
function spp_calculate_invoice_totals( $line_items, $options = array() ) {
	$subtotal = 0;

	// Calculate subtotal.
	foreach ( $line_items as $item ) {
		$quantity = floatval( $item['quantity'] );
		$unit_price = (int) $item['base_price_money']['amount'];
		$subtotal += SPP_Currency::calculate_line_total( $unit_price, (string) $quantity );
	}

	// Apply tax.
	$tax_rate = floatval( $options['tax_rate'] ?? 0 );
	$tax = (int) round( $subtotal * ( $tax_rate / 100 ) );

	// Apply discount.
	$discount = (int) ( $options['discount_amount'] ?? 0 );

	// Calculate total.
	$total = $subtotal + $tax - $discount;

	return array(
		'subtotal' => $subtotal,
		'tax'      => $tax,
		'discount' => $discount,
		'total'    => max( 0, $total ), // Never negative.
	);
}
