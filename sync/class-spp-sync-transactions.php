<?php
/**
 * Transaction Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_Sync_Transactions
 *
 * Handles sync of payments/transactions from Square to WordPress.
 */
class SPP_Sync_Transactions {

	/**
	 * Square API client.
	 *
	 * @var SPP_Square_Client
	 */
	private $client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->client = new SPP_Square_Client();
	}

	/**
	 * Sync transactions from Square to WordPress.
	 *
	 * @param array $args Optional arguments.
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_from_square( array $args = array() ) {
		$defaults = array(
			'limit'       => 100,
			'location_id' => $this->client->get_location_id(),
			'begin_time'  => '', // ISO 8601 format.
			'end_time'    => '', // ISO 8601 format.
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate location_id.
		/*
		if ( empty( $args['location_id'] ) ) {
			$error_msg = __( 'No location ID configured. Please set a default location in Settings.', 'cube-payment-portal' );
			return new WP_Error( 'missing_location_id', $error_msg );
		}
		*/

		$synced_count = 0;
		$error_count  = 0;
		$errors       = array();
		$cursor       = null;

		// If no begin_time specified, sync last 30 days by default.
		if ( empty( $args['begin_time'] ) ) {
			$args['begin_time'] = gmdate( 'c', strtotime( '-30 days' ) );
		}

		try {
			do {
				// Build request parameters.
				$params = array(
					'limit'       => $args['limit'],
				);

				if ( ! empty( $args['location_id'] ) ) {
					$params['location_id'] = $args['location_id'];
				}

				if ( ! empty( $args['begin_time'] ) ) {
					$params['begin_time'] = $args['begin_time'];
				}

				if ( ! empty( $args['end_time'] ) ) {
					$params['end_time'] = $args['end_time'];
				}

				if ( $cursor ) {
					$params['cursor'] = $cursor;
				}

				// Build query string.
				$query_string = http_build_query( $params );

				// Log the API call for debugging.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SPP Transaction Sync: Fetching payments with params: ' . $query_string );
			}

				// Fetch payments from Square.
				$result = $this->client->get( 'payments?' . $query_string );

				if ( is_wp_error( $result ) ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'SPP Transaction Sync: API error - ' . $result->get_error_message() );
					}
					return $result;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SPP Transaction Sync: Received ' . count( $result['payments'] ?? array() ) . ' payments' );
			}

				$payments = $result['payments'] ?? array();
				$cursor   = $result['cursor'] ?? null;

				// Process each payment.
				foreach ( $payments as $payment_data ) {
					$sync_result = $this->sync_single_payment( $payment_data );

					if ( is_wp_error( $sync_result ) ) {
						$error_count++;
						$errors[] = array(
							'payment_id' => $payment_data['id'] ?? 'unknown',
							'error'      => $sync_result->get_error_message(),
						);
					} else {
						$synced_count++;
					}
				}

				// Break if no more pages.
				if ( empty( $cursor ) ) {
					break;
				}
			} while ( true );

			// Update last sync timestamp.
			update_option( 'spp_transactions_last_sync', time() );

			return array(
				'success' => true,
				'synced'  => $synced_count,
				'errors'  => $error_count,
				'details' => $errors,
			);

		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SPP Transaction Sync: Exception - ' . $e->getMessage() );
			}
			return new WP_Error( 'sync_exception', $e->getMessage() );
		}
	}

	/**
	 * Sync a single payment from Square data.
	 *
	 * @param array $payment_data Payment data from Square API.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function sync_single_payment( array $payment_data ) {
		global $wpdb;

		if ( empty( $payment_data['id'] ) ) {
			return new WP_Error( 'invalid_payment', 'Payment ID is required' );
		}

		$square_payment_id = $payment_data['id'];
		$table_name        = $wpdb->prefix . 'spp_transactions';

		// Check if payment already exists.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE square_payment_id = %s",
				$square_payment_id
			),
			ARRAY_A
		);

		// Map Square customer ID to WordPress user ID.
		$user_id = 0;
		$square_customer_id = $payment_data['customer_id'] ?? '';

		if ( ! empty( $square_customer_id ) ) {
			$user_id = $this->map_customer_id( $square_customer_id );
		}

		// Get amount.
		$amount = 0;
		$currency = 'USD';
		if ( ! empty( $payment_data['amount_money'] ) ) {
			$amount = ( $payment_data['amount_money']['amount'] ?? 0 ) / 100;
			$currency = $payment_data['amount_money']['currency'] ?? 'USD';
		}

		// Get status.
		$status = strtolower( $payment_data['status'] ?? 'pending' );

		// Get card details.
		$card_last_four = '';
		$card_brand = '';
		if ( ! empty( $payment_data['card_details']['card'] ) ) {
			$card_last_four = $payment_data['card_details']['card']['last_4'] ?? '';
			$card_brand = $payment_data['card_details']['card']['card_brand'] ?? '';
		}

		// Get source type from payment.
		$source_type = 'one_time';
		if ( ! empty( $payment_data['source_type'] ) ) {
			$source_type = strtolower( $payment_data['source_type'] );
		}

		// Get created_at from Square API.
		$created_at = current_time( 'mysql' );
		if ( ! empty( $payment_data['created_at'] ) ) {
			$created_at = gmdate( 'Y-m-d H:i:s', strtotime( $payment_data['created_at'] ) );
		}

		// Prepare base data (only columns that are guaranteed to exist).
		$data = array(
			'user_id'            => $user_id,
			'square_customer_id' => $square_customer_id,
			'square_payment_id'  => $square_payment_id,
			'amount'             => $amount,
			'currency'           => $currency,
			'status'             => $status,
			'card_last_four'     => $card_last_four,
			'card_brand'         => $card_brand,
			'source_type'        => $source_type,
			'source_id'          => $payment_data['source_id'] ?? '',
			'created_at'         => $created_at,
		);

		$format = array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// Check if new columns exist and add them if so.
		$columns = $wpdb->get_col( "DESCRIBE $table_name", 0 );
		
		if ( in_array( 'square_plan_id', $columns, true ) ) {
			$data['square_plan_id'] = '';
			$format[] = '%s';
		}
		
		if ( in_array( 'square_subscription_id', $columns, true ) ) {
			$data['square_subscription_id'] = '';
			$format[] = '%s';
		}

		if ( $existing ) {
			// Update existing transaction.
			$updated = $wpdb->update(
				$table_name,
				$data,
				array( 'square_payment_id' => $square_payment_id ),
				$format,
				array( '%s' )
			);

			if ( false === $updated ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SPP Transaction Sync: DB update failed - ' . $wpdb->last_error );
				}
				return new WP_Error( 'db_update_failed', 'Failed to update transaction: ' . $wpdb->last_error );
			}

			return true;
		} else {
			// Insert new transaction.
			$inserted = $wpdb->insert( $table_name, $data, $format );

			if ( false === $inserted ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SPP Transaction Sync: DB insert failed - ' . $wpdb->last_error );
				}
				return new WP_Error( 'db_insert_failed', 'Failed to insert transaction: ' . $wpdb->last_error );
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

		// First check the customers table.
		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wp_user_id FROM {$wpdb->prefix}spp_customers WHERE square_customer_id = %s",
				$square_customer_id
			)
		);

		if ( $customer && $customer->wp_user_id ) {
			return (int) $customer->wp_user_id;
		}

		// Fallback to user meta.
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
	 * Find subscription by order ID.
	 *
	 * @param string $order_id Square order ID.
	 * @return array|null Subscription data or null.
	 */
	private function find_subscription_by_order( $order_id ) {
		// This is a placeholder - Square doesn't directly link orders to subscriptions.
		// In practice, subscription payments have specific metadata.
		return null;
	}
}
