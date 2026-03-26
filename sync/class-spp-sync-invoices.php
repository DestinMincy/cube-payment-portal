<?php
/**
 * Invoice Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Invoices
 *
 * Handles bidirectional sync of invoices between WordPress and Square.
 */
class SPP_Sync_Invoices {

	/**
	 * Square API client.
	 *
	 * @var SPP_Square_Client
	 */
	private $client;

	/**
	 * Invoices API.
	 *
	 * @var SPP_Invoices
	 */
	private $invoices_api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->client       = new SPP_Square_Client();
		$this->invoices_api = new SPP_Invoices( $this->client );
	}

	/**
	 * Sync invoices from Square to WordPress.
	 *
	 * @param array $args Optional arguments.
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_from_square( array $args = array() ) {
		$defaults = array(
			'limit'        => 100,
			'use_cursor'   => true,
			'location_id'  => $this->client->get_location_id(),
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate location_id.
		if ( empty( $args['location_id'] ) ) {
			$error_msg = __( 'No location ID configured. Please go to Settings and set a default location, or connect to Square first.', 'cube-payment-portal' );
			$this->log_sync_error( 'Missing location_id', array( 'message' => $error_msg ) );
			return new WP_Error( 'missing_location_id', $error_msg );
		}

		// Get last sync cursor if enabled.
		$cursor = null;
		if ( $args['use_cursor'] ) {
			$cursor = get_option( 'spp_invoices_sync_cursor', null );
		}

		$synced_count = 0;
		$error_count  = 0;
		$errors       = array();

		// Log sync start.
		$this->log_sync_event( 'Sync started', array( 'cursor' => $cursor, 'location_id' => $args['location_id'] ) );

		try {
			do {
				// Fetch invoices from Square.
				$result = $this->invoices_api->get_all_invoices(
					array(
						'location_id' => $args['location_id'],
						'limit'       => $args['limit'],
						'cursor'      => $cursor,
					)
				);

				if ( is_wp_error( $result ) ) {
					$this->log_sync_error( 'Failed to fetch invoices from Square', array( 'error' => $result->get_error_message() ) );
					return $result;
				}

				$invoices = $result['invoices'] ?? array();
				$cursor   = $result['cursor'] ?? null;

				// Process each invoice.
				foreach ( $invoices as $invoice_data ) {
					$sync_result = $this->sync_single_invoice( $invoice_data );

					if ( is_wp_error( $sync_result ) ) {
						$error_count++;
						$errors[] = array(
							'invoice_id' => $invoice_data['id'] ?? 'unknown',
							'error'      => $sync_result->get_error_message(),
						);
					} else {
						$synced_count++;
					}
				}

				// Save cursor for next sync.
				if ( $cursor ) {
					update_option( 'spp_invoices_sync_cursor', $cursor );
				}

				// Break if no more pages.
				if ( empty( $cursor ) ) {
					break;
				}
			} while ( true );

			// Clear cursor on successful complete sync.
			delete_option( 'spp_invoices_sync_cursor' );

			// Update last sync timestamp.
			update_option( 'spp_invoices_last_sync', time() );

			// Log sync completion.
			$this->log_sync_event(
				'Sync completed',
				array(
					'synced'      => $synced_count,
					'errors'      => $error_count,
					'error_details' => $errors,
				)
			);

			return array(
				'success' => true,
				'synced'  => $synced_count,
				'errors'  => $error_count,
				'details' => $errors,
			);

		} catch ( Exception $e ) {
			$this->log_sync_error( 'Sync exception', array( 'exception' => $e->getMessage() ) );
			return new WP_Error( 'sync_exception', $e->getMessage() );
		}
	}

	/**
	 * Sync a single invoice from Square data.
	 *
	 * @param array $invoice_data Invoice data from Square API.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function sync_single_invoice( array $invoice_data ) {
		global $wpdb;

		if ( empty( $invoice_data['id'] ) ) {
			return new WP_Error( 'invalid_invoice', 'Invoice ID is required' );
		}

		$square_invoice_id = $invoice_data['id'];
		$table_name        = $wpdb->prefix . 'spp_invoices';

		// Check if invoice already exists.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE square_invoice_id = %s",
				$square_invoice_id
			),
			ARRAY_A
		);

		// Map Square customer ID to WordPress user ID.
		$user_id = 0;
		$square_customer_id = null;
		$customer_name_square = null;
		$customer_email_square = null;
		
		if ( ! empty( $invoice_data['primary_recipient']['customer_id'] ) ) {
			$square_customer_id = $invoice_data['primary_recipient']['customer_id'];
			$user_id = $this->map_customer_id( $square_customer_id );
			
			// Extract customer name from primary_recipient.
			$recipient = $invoice_data['primary_recipient'];
			if ( ! empty( $recipient['given_name'] ) || ! empty( $recipient['family_name'] ) ) {
				$customer_name_square = trim( ( $recipient['given_name'] ?? '' ) . ' ' . ( $recipient['family_name'] ?? '' ) );
			} elseif ( ! empty( $recipient['company_name'] ) ) {
				$customer_name_square = $recipient['company_name'];
			}
			
			// Extract customer email.
			if ( ! empty( $recipient['email_address'] ) ) {
				$customer_email_square = $recipient['email_address'];
			}
		}

		// Calculate total amount.
		$amount = 0;
		if ( ! empty( $invoice_data['payment_requests'][0]['computed_amount_money']['amount'] ) ) {
			$amount = $invoice_data['payment_requests'][0]['computed_amount_money']['amount'] / 100;
		}

		// Get currency.
		$currency = 'USD';
		if ( ! empty( $invoice_data['payment_requests'][0]['computed_amount_money']['currency'] ) ) {
			$currency = $invoice_data['payment_requests'][0]['computed_amount_money']['currency'];
		}

		// Get status.
		$status = strtolower( $invoice_data['status'] ?? 'draft' );

		// Get due date.
		$due_date = null;
		if ( ! empty( $invoice_data['payment_requests'][0]['due_date'] ) ) {
			$due_date = $invoice_data['payment_requests'][0]['due_date'];
		}

		// Get order ID.
		$order_id = $invoice_data['order_id'] ?? '';
		
		// Get invoice number from Square.
		$invoice_number = $invoice_data['invoice_number'] ?? null;

		// Get created_at from Square API (convert from ISO 8601 format).
		$created_at = null;
		if ( ! empty( $invoice_data['created_at'] ) ) {
			$created_at = gmdate( 'Y-m-d H:i:s', strtotime( $invoice_data['created_at'] ) );
		}

		// Prepare data.
		$data = array(
			'user_id'              => $user_id,
			'square_invoice_id'    => $square_invoice_id,
			'square_order_id'      => $order_id,
			'square_customer_id'   => $square_customer_id,
			'customer_name_square' => $customer_name_square,
			'customer_email_square'=> $customer_email_square,
			'invoice_number'       => $invoice_number,
			'title'                => $invoice_data['title'] ?? null,
			'description'          => $invoice_data['description'] ?? null,
			'amount'               => $amount,
			'currency'             => $currency,
			'status'               => $status,
			'due_date'             => $due_date,
			'synced_at'            => current_time( 'mysql' ),
			'sync_source'          => 'square',
		);

		$format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );

		// Set paid_at if status is paid.
		if ( 'paid' === $status && empty( $existing['paid_at'] ) ) {
			$data['paid_at'] = current_time( 'mysql' );
			$format[] = '%s';
		}

		if ( $existing ) {
			// Update existing invoice.
			// Also update created_at if it wasn't set correctly before (was using sync time).
			if ( $created_at && ( empty( $existing['created_at'] ) || $existing['created_at'] !== $created_at ) ) {
				$data['created_at'] = $created_at;
				$format[] = '%s';
			}
			
			$updated = $wpdb->update(
				$table_name,
				$data,
				array( 'square_invoice_id' => $square_invoice_id ),
				$format,
				array( '%s' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'db_update_failed', 'Failed to update invoice in database' );
			}

			return true;
		} else {
			// Insert new invoice with created_at from Square.
			$data['created_at'] = $created_at ?: current_time( 'mysql' );
			$format[] = '%s';

			$inserted = $wpdb->insert( $table_name, $data, $format );

			if ( false === $inserted ) {
				return new WP_Error( 'db_insert_failed', 'Failed to insert invoice into database' );
			}

			return true;
		}
	}

	/**
	 * Map Square customer ID to WordPress user ID.
	 *
	 * @param string $square_customer_id Square customer ID.
	 * @return int WordPress user ID, or 0 if not found.
	 */
	private function map_customer_id( $square_customer_id ) {
		global $wpdb;

		// Look for user with this Square customer ID in user meta.
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} 
				 WHERE meta_key = 'spp_square_customer_id' 
				 AND meta_value = %s 
				 LIMIT 1",
				$square_customer_id
			)
		);

		return $user_id ? (int) $user_id : 0;
	}

	/**
	 * Log sync event.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	private function log_sync_event( $message, array $context = array() ) {
		$log_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'type'      => 'info',
			'message'   => $message,
			'context'   => $context,
		);

		$log = get_option( 'spp_sync_log', array() );
		array_unshift( $log, $log_entry );

		// Keep only last 50 entries.
		$log = array_slice( $log, 0, 50 );

		update_option( 'spp_sync_log', $log );

		// Also log to error_log in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SPP Invoice Sync: ' . $message . ' - ' . wp_json_encode( $context ) );
		}
	}

	/**
	 * Log sync error.
	 *
	 * @param string $message Error message.
	 * @param array  $context Additional context.
	 */
	private function log_sync_error( $message, array $context = array() ) {
		$log_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'type'      => 'error',
			'message'   => $message,
			'context'   => $context,
		);

		$log = get_option( 'spp_sync_log', array() );
		array_unshift( $log, $log_entry );

		// Keep only last 50 entries.
		$log = array_slice( $log, 0, 50 );

		update_option( 'spp_sync_log', $log );

		// Log errors in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SPP Invoice Sync ERROR: ' . $message . ' - ' . wp_json_encode( $context ) );
		}
	}

	/**
	 * Get sync log entries.
	 *
	 * @param int $limit Number of entries to retrieve.
	 * @return array Log entries.
	 */
	public function get_sync_log( $limit = 10 ) {
		$log = get_option( 'spp_sync_log', array() );
		return array_slice( $log, 0, $limit );
	}

	/**
	 * Clear sync log.
	 */
	public function clear_sync_log() {
		delete_option( 'spp_sync_log' );
	}
}
