<?php
/**
 * Gift Card Activities Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_Sync_Gift_Card_Activities
 *
 * Syncs gift card activities from Square into the spp_transactions table.
 */
class SPP_Sync_Gift_Card_Activities {

	/**
	 * Gift Cards API instance.
	 *
	 * @var SPP_Gift_Cards
	 */
	private $gift_cards_api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
			require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
		}
		$this->gift_cards_api = new SPP_Gift_Cards();
	}

	/**
	 * Sync gift card activities for a specific customer.
	 *
	 * Called lazily when a user views their transactions. Rate-limited
	 * to once per hour per user via a transient.
	 *
	 * @param string $square_customer_id Square customer ID.
	 * @param int    $wp_user_id         WordPress user ID.
	 * @param bool   $bypass_cache       Whether to bypass the transient cache.
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_for_customer( $square_customer_id, $wp_user_id, $bypass_cache = false ) {
		if ( empty( $square_customer_id ) || empty( $wp_user_id ) ) {
			return new WP_Error( 'missing_params', 'Customer ID and user ID are required.' );
		}

		// Rate-limit: once per hour per user.
		$cache_key = 'spp_gc_sync_' . $wp_user_id;
		if ( ! $bypass_cache && get_transient( $cache_key ) ) {
			return array( 'success' => true, 'synced' => 0, 'skipped' => true );
		}

		$synced = 0;
		$errors = 0;

		// Get customer's gift cards.
		$cards = $this->gift_cards_api->get_customer_gift_cards( $square_customer_id );

		if ( is_wp_error( $cards ) ) {
			return $cards;
		}

		if ( empty( $cards ) ) {
			set_transient( $cache_key, time(), HOUR_IN_SECONDS );
			return array( 'success' => true, 'synced' => 0 );
		}

		// Fetch activities for each gift card.
		foreach ( $cards as $card ) {
			$gift_card_id = $card['id'] ?? '';
			if ( empty( $gift_card_id ) ) {
				continue;
			}

			$cursor = null;
			do {
				$result = $this->gift_cards_api->list_activities( $gift_card_id, 50, $cursor );

				if ( is_wp_error( $result ) ) {
					$errors++;
					break;
				}

				$activities = $result['gift_card_activities'] ?? array();
				$cursor     = $result['cursor'] ?? null;

				foreach ( $activities as $activity ) {
					$processed = $this->process_activity( $activity, $square_customer_id, $wp_user_id );
					if ( true === $processed ) {
						$synced++;
					}
				}
			} while ( ! empty( $cursor ) );
		}

		// Set cache transient.
		set_transient( $cache_key, time(), HOUR_IN_SECONDS );

		return array(
			'success' => true,
			'synced'  => $synced,
			'errors'  => $errors,
		);
	}

	/**
	 * Sync all gift card activities for all customers (cron job).
	 *
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_all() {
		global $wpdb;

		$synced_total = 0;
		$errors_total = 0;

		// Get all users with a Square customer ID.
		$users = $wpdb->get_results(
			"SELECT user_id, meta_value AS square_customer_id
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = 'spp_square_customer_id'
			 AND meta_value != ''",
			ARRAY_A
		);

		if ( empty( $users ) ) {
			return array( 'success' => true, 'synced' => 0, 'errors' => 0 );
		}

		foreach ( $users as $user ) {
			$result = $this->sync_for_customer(
				$user['square_customer_id'],
				(int) $user['user_id'],
				true // bypass cache for cron
			);

			if ( ! is_wp_error( $result ) ) {
				$synced_total += $result['synced'] ?? 0;
				$errors_total += $result['errors'] ?? 0;
			} else {
				$errors_total++;
			}
		}

		update_option( 'spp_gc_activities_last_sync', time() );

		return array(
			'success' => true,
			'synced'  => $synced_total,
			'errors'  => $errors_total,
		);
	}

	/**
	 * Process a single gift card activity and insert/update the transaction.
	 *
	 * @param array  $activity           Activity data from Square API.
	 * @param string $square_customer_id Square customer ID.
	 * @param int    $wp_user_id         WordPress user ID.
	 * @return bool|null True if inserted/updated, null if skipped.
	 */
	public function process_activity( $activity, $square_customer_id, $wp_user_id ) {
		global $wpdb;

		$activity_id = $activity['id'] ?? '';
		$type        = $activity['type'] ?? '';

		if ( empty( $activity_id ) || empty( $type ) ) {
			return null;
		}

		// Map activity type to source_type and extract amount.
		$type_map = array(
			'ACTIVATE'         => array( 'source_type' => 'gift_card_purchase', 'details_key' => 'activate_activity_details' ),
			'LOAD'             => array( 'source_type' => 'gift_card_reload',   'details_key' => 'load_activity_details' ),
			'REDEEM'           => array( 'source_type' => 'gift_card_redeem',   'details_key' => 'redeem_activity_details' ),
		);

		if ( ! isset( $type_map[ $type ] ) ) {
			return null; // Skip non-monetary types (DEACTIVATE, CLEAR_BALANCE, etc.).
		}

		$source_type = $type_map[ $type ]['source_type'];
		$details_key = $type_map[ $type ]['details_key'];

		// Extract amount.
		$amount_cents = $activity[ $details_key ]['amount_money']['amount'] ?? 0;
		$currency     = $activity[ $details_key ]['amount_money']['currency'] ?? 'USD';
		$amount       = abs( $amount_cents ) / 100;

		$table        = $wpdb->prefix . 'spp_transactions';
		$canonical_id = 'gca_' . $activity_id;

		// Dedup check 1: Already synced with canonical ID?
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE square_payment_id = %s",
				$canonical_id
			)
		);

		if ( $existing ) {
			return null; // Already synced.
		}

		// Dedup check 2 (REDEEM only): Does a Payment record already exist?
		if ( 'REDEEM' === $type ) {
			$linked_payment_id = $activity[ $details_key ]['payment_id'] ?? '';
			if ( ! empty( $linked_payment_id ) ) {
				$payment_row = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM $table WHERE square_payment_id = %s",
						$linked_payment_id
					)
				);

				if ( $payment_row ) {
					// Payment already exists from payment sync. Enrich it.
					$wpdb->update(
						$table,
						array(
							'source_type' => 'gift_card_redeem',
							'source_id'   => $activity['gift_card_id'] ?? '',
						),
						array( 'id' => $payment_row ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					return true;
				}
			}
		}

		// Dedup check 3 (ACTIVATE/LOAD): Check for legacy gc_* rows from portal.
		if ( in_array( $type, array( 'ACTIVATE', 'LOAD' ), true ) ) {
			$activity_time = gmdate( 'Y-m-d H:i:s', strtotime( $activity['created_at'] ) );
			$legacy_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $table
					 WHERE user_id = %d
					 AND source_type = %s
					 AND amount = %f
					 AND ABS( TIMESTAMPDIFF( SECOND, created_at, %s ) ) < 120
					 AND square_payment_id LIKE %s
					 LIMIT 1",
					$wp_user_id,
					$source_type,
					$amount,
					$activity_time,
					'gc\_%'
				)
			);

			if ( $legacy_id ) {
				// Upgrade legacy row to canonical ID.
				$wpdb->update(
					$table,
					array( 'square_payment_id' => $canonical_id ),
					array( 'id' => $legacy_id ),
					array( '%s' ),
					array( '%d' )
				);
				return true;
			}
		}

		// Build description note.
		$gift_card_id = $activity['gift_card_id'] ?? '';
		$note_map = array(
			'ACTIVATE' => __( 'Gift Card Purchase', 'cube-payment-portal' ),
			'LOAD'     => __( 'Gift Card Reload', 'cube-payment-portal' ),
			'REDEEM'   => __( 'Gift Card Redemption', 'cube-payment-portal' ),
		);
		$note = $note_map[ $type ] ?? $type;

		// Insert new transaction.
		$wpdb->insert(
			$table,
			array(
				'user_id'            => $wp_user_id,
				'square_customer_id' => $square_customer_id,
				'square_payment_id'  => $canonical_id,
				'amount'             => $amount,
				'currency'           => $currency,
				'status'             => 'completed',
				'card_last_four'     => '',
				'card_brand'         => '',
				'source_type'        => $source_type,
				'source_id'          => $gift_card_id,
				'created_at'         => gmdate( 'Y-m-d H:i:s', strtotime( $activity['created_at'] ) ),
				'metadata'           => wp_json_encode( array(
					'gift_card_activity_id'   => $activity_id,
					'gift_card_activity_type' => $type,
					'note'                    => $note,
				) ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Map Square customer ID to WordPress user ID.
	 *
	 * @param string $square_customer_id Square customer ID.
	 * @return int WordPress user ID, or 0 if not found.
	 */
	public function map_customer_id( $square_customer_id ) {
		global $wpdb;

		// Check the customers table first.
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
}
