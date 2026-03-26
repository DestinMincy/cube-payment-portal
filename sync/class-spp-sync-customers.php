<?php
/**
 * Customer synchronization between Square and WordPress.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_Sync_Customers
 *
 * Handles bidirectional sync of customers between Square API and WordPress.
 */
class SPP_Sync_Customers {

	/**
	 * Customers API.
	 *
	 * @var SPP_Customers
	 */
	private $customers_api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->customers_api = new SPP_Customers();
	}

	/**
	 * Run full bidirectional sync.
	 *
	 * @return array Sync results with counts.
	 */
	public function sync_all() {
		$results = array(
			'from_square'  => 0,
			'to_square'    => 0,
			'auto_linked'  => 0,
			'errors'       => 0,
			'error_messages' => array(),
		);

		// Step 1: Sync from Square → Local database.
		$square_result = $this->sync_from_square();
		if ( is_wp_error( $square_result ) ) {
			$results['errors']++;
			$results['error_messages'][] = $square_result->get_error_message();
		} else {
			$results['from_square'] = $square_result['synced'];
			$results['auto_linked'] = $square_result['linked'];
			$results['errors'] += $square_result['errors'];
		}

		// Step 2: Sync from WP → Square (WP users not in Square).
		$wp_result = $this->sync_wp_users_to_square();
		if ( is_wp_error( $wp_result ) ) {
			$results['errors']++;
			$results['error_messages'][] = $wp_result->get_error_message();
		} else {
			$results['to_square'] = $wp_result['created'];
			$results['errors'] += $wp_result['errors'];
		}

		// Update last sync time.
		update_option( 'spp_customers_last_sync', time() );
		
		// Invalidate customer list cache so invoice form gets fresh data.
		delete_transient( 'spp_customer_list_cache' );

		return $results;
	}

	/**
	 * Sync customers from Square to local database.
	 *
	 * @return array|WP_Error Sync results.
	 */
	public function sync_from_square() {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		$results = array(
			'synced' => 0,
			'linked' => 0,
			'errors' => 0,
		);

		// Fetch all customers from Square.
		$square_customers = $this->fetch_all_square_customers();
		if ( is_wp_error( $square_customers ) ) {
			return $square_customers;
		}

		foreach ( $square_customers as $customer ) {
			$square_id = $customer['id'];
			$email = $customer['email_address'] ?? null;
			$given_name = $customer['given_name'] ?? null;
			$family_name = $customer['family_name'] ?? null;

			// Check if already exists in local table.
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE square_customer_id = %s", $square_id ),
				ARRAY_A
			);

			// Prepare customer data.
			$customer_data = array(
				'square_customer_id' => $square_id,
				'given_name'         => $given_name,
				'family_name'        => $family_name,
				'email'              => $email,
				'phone'              => $customer['phone_number'] ?? null,
				'company_name'       => $customer['company_name'] ?? null,
				'synced_at'          => current_time( 'mysql' ),
				'sync_status'        => 'synced',
			);

			// Extract address if present.
			if ( ! empty( $customer['address'] ) ) {
				$addr = $customer['address'];
				$customer_data['address_line_1'] = $addr['address_line_1'] ?? null;
				$customer_data['address_city'] = $addr['locality'] ?? null;
				$customer_data['address_state'] = $addr['administrative_district_level_1'] ?? null;
				$customer_data['address_postal_code'] = $addr['postal_code'] ?? null;
				$customer_data['address_country'] = $addr['country'] ?? null;
			}

			if ( $existing ) {
				// Update existing record.
				$wpdb->update(
					$table,
					$customer_data,
					array( 'id' => $existing['id'] ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				// Insert new record.
				$customer_data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( $table, $customer_data );
				
				// Try to auto-link to WP user.
				$wp_user_id = $this->find_matching_wp_user( $email, $given_name, $family_name );
				if ( $wp_user_id ) {
					$insert_id = $wpdb->insert_id;
					$wpdb->update(
						$table,
						array( 'wp_user_id' => $wp_user_id ),
						array( 'id' => $insert_id )
					);
					// Also store in user meta for backward compatibility.
					update_user_meta( $wp_user_id, 'spp_square_customer_id', $square_id );
					$results['linked']++;
				}
			}

			$results['synced']++;
		}

		return $results;
	}

	/**
	 * Sync WordPress users to Square (create if not exists).
	 *
	 * @return array|WP_Error Sync results.
	 */
	public function sync_wp_users_to_square() {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		$results = array(
			'created' => 0,
			'errors'  => 0,
		);

		// Get all WP users. Limit is filterable for large sites.
		$user_limit = apply_filters( 'spp_sync_wp_user_limit', 500 );
		$wp_users = get_users( array( 'number' => $user_limit ) );

		foreach ( $wp_users as $user ) {
			// Skip if already has Square customer ID.
			$existing_square_id = get_user_meta( $user->ID, 'spp_square_customer_id', true );
			if ( $existing_square_id ) {
				continue;
			}

			// Skip admins without explicit opt-in.
			if ( user_can( $user->ID, 'manage_options' ) ) {
				continue;
			}

			// Create customer in Square.
			$customer_data = array(
				'email'        => $user->user_email,
				'first_name'   => get_user_meta( $user->ID, 'first_name', true ) ?: $user->display_name,
				'last_name'    => get_user_meta( $user->ID, 'last_name', true ) ?: '',
				'reference_id' => 'wp_user_' . $user->ID,
			);

			$result = $this->customers_api->create_customer( $customer_data );

			if ( is_wp_error( $result ) ) {
				$results['errors']++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SPP Customer Sync: Failed to create customer for WP user ' . $user->ID . ': ' . $result->get_error_message() );
				}
				continue;
			}
			
			// Rate limit: Add small delay to avoid hitting API limits.
			usleep( 100000 ); // 0.1 second delay

			$square_id = $result['customer']['id'];

			// Store in user meta.
			update_user_meta( $user->ID, 'spp_square_customer_id', $square_id );

			// Also store in local customers table.
			$wpdb->insert(
				$table,
				array(
					'square_customer_id' => $square_id,
					'wp_user_id'         => $user->ID,
					'given_name'         => $customer_data['first_name'],
					'family_name'        => $customer_data['last_name'],
					'email'              => $user->user_email,
					'sync_status'        => 'synced',
					'created_at'         => current_time( 'mysql' ),
					'synced_at'          => current_time( 'mysql' ),
				)
			);

			$results['created']++;
		}

		return $results;
	}

	/**
	 * Fetch all customers from Square API with pagination.
	 *
	 * @return array|WP_Error All customers or error.
	 */
	private function fetch_all_square_customers() {
		$all_customers = array();
		$cursor = null;

		do {
			$result = $this->customers_api->get_customers_list( 100, $cursor );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $result['customers'] ) ) {
				$all_customers = array_merge( $all_customers, $result['customers'] );
			}

			$cursor = $result['cursor'] ?? null;
			
			// Rate limit: Add delay between pagination requests.
			if ( $cursor ) {
				usleep( 100000 ); // 0.1 second delay
			}

		} while ( $cursor );

		return $all_customers;
	}

	/**
	 * Find matching WordPress user by email AND name.
	 * 
	 * Requires BOTH email AND name to match for auto-linking.
	 * This prevents false matches when only name or email matches.
	 *
	 * @param string $email      Email address.
	 * @param string $given_name First name.
	 * @param string $family_name Last name.
	 * @return int|null WP user ID or null.
	 */
	private function find_matching_wp_user( $email, $given_name, $family_name ) {
		// Require email to be present.
		if ( empty( $email ) ) {
			return null;
		}
		
		// Find user by email first.
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return null; // No email match = no link.
		}
		
		// Email matched - now verify name also matches.
		if ( empty( $given_name ) && empty( $family_name ) ) {
			return null; // No name to verify = no link.
		}
		
		$wp_first = strtolower( get_user_meta( $user->ID, 'first_name', true ) );
		$wp_last = strtolower( get_user_meta( $user->ID, 'last_name', true ) );
		$sq_first = strtolower( $given_name );
		$sq_last = strtolower( $family_name );
		
		// Both first AND last name must match (case-insensitive).
		if ( $wp_first === $sq_first && $wp_last === $sq_last ) {
			return $user->ID;
		}
		
		// Email matched but name didn't - don't auto-link.
		return null;
	}

	/**
	 * Manually link a customer to a WordPress user.
	 *
	 * @param int $customer_id Local customer ID.
	 * @param int $wp_user_id  WordPress user ID.
	 * @return bool|WP_Error Success or error.
	 */
	public function link_customer( $customer_id, $wp_user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		// Get the customer record.
		$customer = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $customer_id ),
			ARRAY_A
		);

		if ( ! $customer ) {
			return new WP_Error( 'not_found', __( 'Customer not found.', 'cube-payment-portal' ) );
		}

		// Check if WP user is already linked to another customer (duplicate prevention).
		$existing_link = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE wp_user_id = %d AND id != %d", $wp_user_id, $customer_id )
		);
		if ( $existing_link ) {
			return new WP_Error( 'already_linked', __( 'This WordPress user is already linked to another customer.', 'cube-payment-portal' ) );
		}

		// Update local table.
		$wpdb->update(
			$table,
			array( 'wp_user_id' => $wp_user_id ),
			array( 'id' => $customer_id )
		);

		// Update user meta.
		update_user_meta( $wp_user_id, 'spp_square_customer_id', $customer['square_customer_id'] );

		return true;
	}

	/**
	 * Unlink a customer from WordPress user.
	 *
	 * @param int $customer_id Local customer ID.
	 * @return bool|WP_Error Success or error.
	 */
	public function unlink_customer( $customer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		// Get the customer record.
		$customer = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $customer_id ),
			ARRAY_A
		);

		if ( ! $customer ) {
			return new WP_Error( 'not_found', __( 'Customer not found.', 'cube-payment-portal' ) );
		}

		// Remove from user meta if linked.
		if ( $customer['wp_user_id'] ) {
			delete_user_meta( $customer['wp_user_id'], 'spp_square_customer_id' );
		}

		// Clear WP user link in local table.
		$wpdb->update(
			$table,
			array( 'wp_user_id' => null ),
			array( 'id' => $customer_id )
		);

		return true;
	}

	/**
	 * Get sync status.
	 *
	 * @return array Status info.
	 */
	public function get_sync_status() {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE wp_user_id IS NOT NULL" );
		$unlinked = $total - $linked;
		$last_sync = get_option( 'spp_customers_last_sync', 0 );

		return array(
			'total'     => $total,
			'linked'    => $linked,
			'unlinked'  => $unlinked,
			'last_sync' => $last_sync,
		);
	}

	/**
	 * Sync a single customer from Square webhook data.
	 *
	 * @param array $customer Customer data from Square.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function sync_single_customer( array $customer ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		if ( empty( $customer['id'] ) ) {
			return new WP_Error( 'missing_id', __( 'Customer ID is missing.', 'cube-payment-portal' ) );
		}

		$square_id = $customer['id'];
		$email = $customer['email_address'] ?? null;
		$given_name = $customer['given_name'] ?? null;
		$family_name = $customer['family_name'] ?? null;

		// Check if already exists in local table.
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE square_customer_id = %s", $square_id ),
			ARRAY_A
		);

		// Prepare customer data.
		$customer_data = array(
			'square_customer_id' => $square_id,
			'given_name'         => $given_name,
			'family_name'        => $family_name,
			'email'              => $email,
			'phone'              => $customer['phone_number'] ?? null,
			'company_name'       => $customer['company_name'] ?? null,
			'synced_at'          => current_time( 'mysql' ),
			'sync_status'        => 'synced',
		);

		// Extract address if present.
		if ( ! empty( $customer['address'] ) ) {
			$addr = $customer['address'];
			$customer_data['address_line_1'] = $addr['address_line_1'] ?? null;
			$customer_data['address_city'] = $addr['locality'] ?? null;
			$customer_data['address_state'] = $addr['administrative_district_level_1'] ?? null;
			$customer_data['address_postal_code'] = $addr['postal_code'] ?? null;
			$customer_data['address_country'] = $addr['country'] ?? null;
		}

		if ( $existing ) {
			// Update existing record.
			$result = $wpdb->update(
				$table,
				$customer_data,
				array( 'id' => $existing['id'] )
			);

			if ( false === $result ) {
				return new WP_Error( 'db_error', $wpdb->last_error );
			}
		} else {
			// Insert new record.
			$customer_data['created_at'] = current_time( 'mysql' );
			$result = $wpdb->insert( $table, $customer_data );

			if ( false === $result ) {
				return new WP_Error( 'db_error', $wpdb->last_error );
			}

			// Try to auto-link to WP user.
			$wp_user_id = $this->find_matching_wp_user( $email, $given_name, $family_name );
			if ( $wp_user_id ) {
				$insert_id = $wpdb->insert_id;
				$wpdb->update(
					$table,
					array( 'wp_user_id' => $wp_user_id ),
					array( 'id' => $insert_id )
				);
				// Also store in user meta for backward compatibility.
				update_user_meta( $wp_user_id, 'spp_square_customer_id', $square_id );
			}
		}

		return true;
	}
}
