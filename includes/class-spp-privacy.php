<?php
/**
 * GDPR / Privacy compliance for Cube Payment Portal.
 *
 * Registers personal data exporters, erasers, and privacy policy content
 * per GDPR Articles 12, 17, and 20.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_Privacy
 */
class SPP_Privacy {

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Register privacy policy content.
	 */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<ul><li>%s</li><li>%s</li><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ul>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>' .
			'<h3>%s</h3>' .
			'<p>%s</p>',
			__( 'Cube Payment Portal', 'cube-payment-portal' ),
			__( 'This site uses the Cube Payment Portal plugin to manage payments, invoices, subscriptions, bookings, and customer accounts through Square.', 'cube-payment-portal' ),
			__( 'What personal data we collect and store', 'cube-payment-portal' ),
			__( 'When you create an account or interact with the payment portal, we may collect and store the following information locally and sync it with Square:', 'cube-payment-portal' ),
			__( 'Name, email address, and phone number', 'cube-payment-portal' ),
			__( 'Mailing address (street, city, state, postal code, country)', 'cube-payment-portal' ),
			__( 'Date of birth (if provided)', 'cube-payment-portal' ),
			__( 'Transaction and payment history', 'cube-payment-portal' ),
			__( 'Invoice and subscription records', 'cube-payment-portal' ),
			__( 'Booking and appointment details', 'cube-payment-portal' ),
			__( 'How we use your data', 'cube-payment-portal' ),
			__( 'Your data is used to provide payment processing, manage subscriptions and invoices, schedule and manage bookings, and to sync your account information with Square. Your IP address may be temporarily stored for rate limiting and security purposes.', 'cube-payment-portal' ),
			__( 'Third-party data sharing', 'cube-payment-portal' ),
			__( 'Your personal data is shared with Square (squareup.com) for payment processing and account management. Square has its own privacy policy governing how it handles your data. No other third parties receive your data through this plugin.', 'cube-payment-portal' ),
			__( 'Data retention and deletion', 'cube-payment-portal' ),
			__( 'Your personal data is retained in our local database as long as your account is active. You may request export or deletion of your data through the WordPress privacy tools. Note that data synced with Square is subject to Square\'s own data retention policies.', 'cube-payment-portal' )
		);

		wp_add_privacy_policy_content(
			__( 'Cube Payment Portal', 'cube-payment-portal' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters.
	 */
	public function register_exporters( $exporters ) {
		$exporters['spp-customer-data'] = array(
			'exporter_friendly_name' => __( 'Cube Payment Portal - Customer Data', 'cube-payment-portal' ),
			'callback'               => array( $this, 'export_customer_data' ),
		);
		$exporters['spp-transaction-data'] = array(
			'exporter_friendly_name' => __( 'Cube Payment Portal - Transactions', 'cube-payment-portal' ),
			'callback'               => array( $this, 'export_transaction_data' ),
		);
		$exporters['spp-invoice-data'] = array(
			'exporter_friendly_name' => __( 'Cube Payment Portal - Invoices', 'cube-payment-portal' ),
			'callback'               => array( $this, 'export_invoice_data' ),
		);
		$exporters['spp-subscription-data'] = array(
			'exporter_friendly_name' => __( 'Cube Payment Portal - Subscriptions', 'cube-payment-portal' ),
			'callback'               => array( $this, 'export_subscription_data' ),
		);
		$exporters['spp-booking-data'] = array(
			'exporter_friendly_name' => __( 'Cube Payment Portal - Bookings', 'cube-payment-portal' ),
			'callback'               => array( $this, 'export_booking_data' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public function register_erasers( $erasers ) {
		$erasers['spp-customer-data'] = array(
			'eraser_friendly_name' => __( 'Cube Payment Portal - Customer Data', 'cube-payment-portal' ),
			'callback'             => array( $this, 'erase_customer_data' ),
		);
		$erasers['spp-transaction-data'] = array(
			'eraser_friendly_name' => __( 'Cube Payment Portal - Transactions', 'cube-payment-portal' ),
			'callback'             => array( $this, 'erase_transaction_data' ),
		);
		$erasers['spp-invoice-data'] = array(
			'eraser_friendly_name' => __( 'Cube Payment Portal - Invoices', 'cube-payment-portal' ),
			'callback'             => array( $this, 'erase_invoice_data' ),
		);
		$erasers['spp-subscription-data'] = array(
			'eraser_friendly_name' => __( 'Cube Payment Portal - Subscriptions', 'cube-payment-portal' ),
			'callback'             => array( $this, 'erase_subscription_data' ),
		);
		$erasers['spp-booking-data'] = array(
			'eraser_friendly_name' => __( 'Cube Payment Portal - Bookings', 'cube-payment-portal' ),
			'callback'             => array( $this, 'erase_booking_data' ),
		);

		return $erasers;
	}

	/**
	 * Find the customer row by email.
	 *
	 * @param string $email_address User email.
	 * @return object|null Customer row or null.
	 */
	private function get_customer_by_email( $email_address ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spp_customers';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email_address ),
			ARRAY_A
		);
	}

	/**
	 * Export customer data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export response.
	 */
	public function export_customer_data( $email_address, $page = 1 ) {
		$export_items = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer ) {
			$data = array();
			$fields = array(
				'square_id'           => __( 'Square Customer ID', 'cube-payment-portal' ),
				'given_name'          => __( 'First Name', 'cube-payment-portal' ),
				'family_name'         => __( 'Last Name', 'cube-payment-portal' ),
				'email'               => __( 'Email', 'cube-payment-portal' ),
				'phone'               => __( 'Phone', 'cube-payment-portal' ),
				'company_name'        => __( 'Company', 'cube-payment-portal' ),
				'address_line_1'      => __( 'Address Line 1', 'cube-payment-portal' ),
				'address_line_2'      => __( 'Address Line 2', 'cube-payment-portal' ),
				'address_city'        => __( 'City', 'cube-payment-portal' ),
				'address_state'       => __( 'State', 'cube-payment-portal' ),
				'address_postal_code' => __( 'Postal Code', 'cube-payment-portal' ),
				'address_country'     => __( 'Country', 'cube-payment-portal' ),
				'birthday'            => __( 'Birthday', 'cube-payment-portal' ),
				'created_at'          => __( 'Account Created', 'cube-payment-portal' ),
			);

			foreach ( $fields as $key => $label ) {
				if ( ! empty( $customer[ $key ] ) ) {
					$data[] = array(
						'name'  => $label,
						'value' => $customer[ $key ],
					);
				}
			}

			if ( ! empty( $data ) ) {
				$export_items[] = array(
					'group_id'          => 'spp-customer',
					'group_label'       => __( 'Cube Payment Portal - Customer Profile', 'cube-payment-portal' ),
					'group_description' => __( 'Your customer profile data stored by Cube Payment Portal.', 'cube-payment-portal' ),
					'item_id'           => 'spp-customer-' . $customer['id'],
					'data'              => $data,
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export transaction data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export response.
	 */
	public function export_transaction_data( $email_address, $page = 1 ) {
		global $wpdb;
		$export_items = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_transactions';
			$limit = 100;
			$offset = ( $page - 1 ) * $limit;

			$transactions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$customer['square_id'],
					$limit,
					$offset
				),
				ARRAY_A
			);

			foreach ( $transactions as $txn ) {
				$data = array(
					array( 'name' => __( 'Transaction ID', 'cube-payment-portal' ), 'value' => $txn['square_id'] ?? '' ),
					array( 'name' => __( 'Amount', 'cube-payment-portal' ), 'value' => ( $txn['amount'] ?? '0' ) . ' ' . ( $txn['currency'] ?? 'USD' ) ),
					array( 'name' => __( 'Status', 'cube-payment-portal' ), 'value' => $txn['status'] ?? '' ),
					array( 'name' => __( 'Date', 'cube-payment-portal' ), 'value' => $txn['created_at'] ?? '' ),
					array( 'name' => __( 'Source', 'cube-payment-portal' ), 'value' => $txn['source_type'] ?? '' ),
				);

				$export_items[] = array(
					'group_id'          => 'spp-transactions',
					'group_label'       => __( 'Cube Payment Portal - Transactions', 'cube-payment-portal' ),
					'group_description' => __( 'Your payment transaction records.', 'cube-payment-portal' ),
					'item_id'           => 'spp-txn-' . $txn['id'],
					'data'              => $data,
				);
			}

			return array(
				'data' => $export_items,
				'done' => count( $transactions ) < $limit,
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export invoice data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export response.
	 */
	public function export_invoice_data( $email_address, $page = 1 ) {
		global $wpdb;
		$export_items = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_invoices';
			$limit = 100;
			$offset = ( $page - 1 ) * $limit;

			$invoices = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$customer['square_id'],
					$limit,
					$offset
				),
				ARRAY_A
			);

			foreach ( $invoices as $inv ) {
				$data = array(
					array( 'name' => __( 'Invoice ID', 'cube-payment-portal' ), 'value' => $inv['square_id'] ?? '' ),
					array( 'name' => __( 'Title', 'cube-payment-portal' ), 'value' => $inv['title'] ?? '' ),
					array( 'name' => __( 'Amount', 'cube-payment-portal' ), 'value' => ( $inv['amount'] ?? '0' ) . ' ' . ( $inv['currency'] ?? 'USD' ) ),
					array( 'name' => __( 'Status', 'cube-payment-portal' ), 'value' => $inv['status'] ?? '' ),
					array( 'name' => __( 'Due Date', 'cube-payment-portal' ), 'value' => $inv['due_date'] ?? '' ),
					array( 'name' => __( 'Date', 'cube-payment-portal' ), 'value' => $inv['created_at'] ?? '' ),
				);

				$export_items[] = array(
					'group_id'          => 'spp-invoices',
					'group_label'       => __( 'Cube Payment Portal - Invoices', 'cube-payment-portal' ),
					'group_description' => __( 'Your invoice records.', 'cube-payment-portal' ),
					'item_id'           => 'spp-inv-' . $inv['id'],
					'data'              => $data,
				);
			}

			return array(
				'data' => $export_items,
				'done' => count( $invoices ) < $limit,
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export subscription data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export response.
	 */
	public function export_subscription_data( $email_address, $page = 1 ) {
		global $wpdb;
		$export_items = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_subscriptions';

			$subscriptions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %s ORDER BY created_at DESC",
					$customer['square_id']
				),
				ARRAY_A
			);

			foreach ( $subscriptions as $sub ) {
				$data = array(
					array( 'name' => __( 'Subscription ID', 'cube-payment-portal' ), 'value' => $sub['square_id'] ?? '' ),
					array( 'name' => __( 'Plan Name', 'cube-payment-portal' ), 'value' => $sub['plan_name'] ?? '' ),
					array( 'name' => __( 'Status', 'cube-payment-portal' ), 'value' => $sub['status'] ?? '' ),
					array( 'name' => __( 'Amount', 'cube-payment-portal' ), 'value' => ( $sub['amount'] ?? '0' ) . ' ' . ( $sub['currency'] ?? 'USD' ) ),
					array( 'name' => __( 'Start Date', 'cube-payment-portal' ), 'value' => $sub['start_date'] ?? '' ),
					array( 'name' => __( 'Date', 'cube-payment-portal' ), 'value' => $sub['created_at'] ?? '' ),
				);

				$export_items[] = array(
					'group_id'          => 'spp-subscriptions',
					'group_label'       => __( 'Cube Payment Portal - Subscriptions', 'cube-payment-portal' ),
					'group_description' => __( 'Your subscription records.', 'cube-payment-portal' ),
					'item_id'           => 'spp-sub-' . $sub['id'],
					'data'              => $data,
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export booking data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export response.
	 */
	public function export_booking_data( $email_address, $page = 1 ) {
		global $wpdb;
		$export_items = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_bookings';

			$bookings = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %s ORDER BY start_at DESC",
					$customer['square_id']
				),
				ARRAY_A
			);

			foreach ( $bookings as $booking ) {
				$data = array(
					array( 'name' => __( 'Booking ID', 'cube-payment-portal' ), 'value' => $booking['square_id'] ?? '' ),
					array( 'name' => __( 'Service', 'cube-payment-portal' ), 'value' => $booking['service_name'] ?? '' ),
					array( 'name' => __( 'Status', 'cube-payment-portal' ), 'value' => $booking['status'] ?? '' ),
					array( 'name' => __( 'Start', 'cube-payment-portal' ), 'value' => $booking['start_at'] ?? '' ),
					array( 'name' => __( 'End', 'cube-payment-portal' ), 'value' => $booking['end_at'] ?? '' ),
					array( 'name' => __( 'Date Created', 'cube-payment-portal' ), 'value' => $booking['created_at'] ?? '' ),
				);

				$export_items[] = array(
					'group_id'          => 'spp-bookings',
					'group_label'       => __( 'Cube Payment Portal - Bookings', 'cube-payment-portal' ),
					'group_description' => __( 'Your appointment and booking records.', 'cube-payment-portal' ),
					'item_id'           => 'spp-booking-' . $booking['id'],
					'data'              => $data,
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Erase customer data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Eraser response.
	 */
	public function erase_customer_data( $email_address, $page = 1 ) {
		global $wpdb;
		$items_removed = 0;

		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer ) {
			$table = $wpdb->prefix . 'spp_customers';

			// Anonymize customer record rather than delete (preserves referential integrity).
			$result = $wpdb->update(
				$table,
				array(
					'given_name'          => __( '[Deleted]', 'cube-payment-portal' ),
					'family_name'         => '',
					'email'               => 'deleted-' . $customer['id'] . '@anonymized.invalid',
					'phone'               => '',
					'company_name'        => '',
					'address_line_1'      => '',
					'address_line_2'      => '',
					'address_city'        => '',
					'address_state'       => '',
					'address_postal_code' => '',
					'address_country'     => '',
					'birthday'            => '',
				),
				array( 'id' => $customer['id'] )
			);

			if ( false !== $result ) {
				$items_removed = 1;
			}

			// Clear linked WP user meta.
			$wp_user = get_user_by( 'email', $email_address );
			if ( ! $wp_user && ! empty( $customer['wp_user_id'] ) ) {
				$wp_user = get_userdata( $customer['wp_user_id'] );
			}
			if ( $wp_user ) {
				delete_user_meta( $wp_user->ID, 'spp_square_customer_id' );
				delete_user_meta( $wp_user->ID, 'spp_notify_appointment_reminders' );
				delete_user_meta( $wp_user->ID, 'spp_notify_invoice_reminders' );
				delete_user_meta( $wp_user->ID, 'spp_date_format' );
				delete_user_meta( $wp_user->ID, 'spp_time_format' );
				delete_user_meta( $wp_user->ID, 'spp_custom_avatar' );
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase transaction data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Eraser response.
	 */
	public function erase_transaction_data( $email_address, $page = 1 ) {
		global $wpdb;
		$items_removed = 0;
		$items_retained = 0;
		$messages = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_transactions';

			// Anonymize customer_id in transactions (retain for financial records).
			$result = $wpdb->update(
				$table,
				array( 'customer_id' => 'anonymized-' . $customer['id'] ),
				array( 'customer_id' => $customer['square_id'] )
			);

			if ( false !== $result && $result > 0 ) {
				$items_retained = $result;
				$messages[] = sprintf(
					/* translators: %d: number of transactions */
					__( '%d transaction records were anonymized but retained for financial compliance.', 'cube-payment-portal' ),
					$result
				);
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Erase invoice data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Eraser response.
	 */
	public function erase_invoice_data( $email_address, $page = 1 ) {
		global $wpdb;
		$items_removed = 0;
		$items_retained = 0;
		$messages = array();
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_invoices';

			$result = $wpdb->update(
				$table,
				array( 'customer_id' => 'anonymized-' . $customer['id'] ),
				array( 'customer_id' => $customer['square_id'] )
			);

			if ( false !== $result && $result > 0 ) {
				$items_retained = $result;
				$messages[] = sprintf(
					/* translators: %d: number of invoices */
					__( '%d invoice records were anonymized but retained for financial compliance.', 'cube-payment-portal' ),
					$result
				);
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Erase subscription data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Eraser response.
	 */
	public function erase_subscription_data( $email_address, $page = 1 ) {
		global $wpdb;
		$items_removed = 0;
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_subscriptions';

			$result = $wpdb->delete(
				$table,
				array( 'customer_id' => $customer['square_id'] )
			);

			if ( false !== $result ) {
				$items_removed = $result;
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase booking data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Eraser response.
	 */
	public function erase_booking_data( $email_address, $page = 1 ) {
		global $wpdb;
		$items_removed = 0;
		$customer = $this->get_customer_by_email( $email_address );

		if ( $customer && ! empty( $customer['square_id'] ) ) {
			$table = $wpdb->prefix . 'spp_bookings';

			$result = $wpdb->delete(
				$table,
				array( 'customer_id' => $customer['square_id'] )
			);

			if ( false !== $result ) {
				$items_removed = $result;
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
