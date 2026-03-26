<?php
/**
 * Square Invoices API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Invoices
 *
 * Handles invoice creation and management via Square Invoices API.
 */
class SPP_Invoices {

    /**
     * Square API client.
     *
     * @var SPP_Square_Client
     */
    private $client;

    /**
     * Constructor.
     *
     * @param SPP_Square_Client $client Optional API client instance.
     */
    public function __construct( ?SPP_Square_Client $client = null ) {
        $this->client = $client ?: new SPP_Square_Client();
    }

    /**
     * Create invoice with full workflow (customer verification, order, invoice).
     * 
     * This is the primary method for creating invoices with comprehensive
     * error handling and rollback logic.
     * 
     * @param array $data Invoice data with all required fields.
     * @return array|WP_Error Created invoice or error.
     */
    public function create_invoice_with_order( $data ) {
        // Step 1: Validate all data before making any API calls.
        $validation = spp_validate_invoice_data( $data );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Step 2: Ensure customer exists in Square.
        $customer_id = $this->ensure_customer_exists( $data );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }
        $data['customer_id'] = $customer_id;

        // Step 3: Format line items properly.
        $formatted_items = array();
        foreach ( $data['line_items'] as $item ) {
            $formatted_items[] = spp_format_line_item( $item );
        }
        $data['line_items'] = $formatted_items;

        // Step 4: Create order with line items.
        $order = $this->create_order_for_invoice( $data );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        // Step 5: Create invoice linked to the order.
        $invoice = $this->create_invoice_from_order( $order['id'], $data );
        
        if ( is_wp_error( $invoice ) ) {
            // Rollback: Delete the orphaned order.
            $this->delete_order( $order['id'] );
            return $invoice;
        }

        // Step 6: Store invoice locally.
        $this->store_invoice_locally( $invoice, $data );

        return $invoice;
    }

    /**
     * Ensure customer exists in Square, create if necessary.
     * 
     * @param array $data Invoice data containing wp_user_id or customer_id.
     * @return string|WP_Error Square customer ID or error.
     */
    private function ensure_customer_exists( $data ) {
        // If customer_id already provided, verify it exists.
        if ( ! empty( $data['customer_id'] ) ) {
            $customers_api = new SPP_Customers( $this->client );
            if ( $customers_api->verify_customer_exists( $data['customer_id'] ) ) {
                return $data['customer_id'];
            }
            return new WP_Error(
                'customer_not_found',
                __( 'Specified customer does not exist in Square.', 'cube-payment-portal' )
            );
        }

        // If wp_user_id provided, ensure customer exists.
        if ( ! empty( $data['wp_user_id'] ) ) {
            $customers_api = new SPP_Customers( $this->client );
            return $customers_api->ensure_customer_verified( $data['wp_user_id'] );
        }

        return new WP_Error(
            'missing_customer',
            __( 'Customer ID or WordPress user ID is required.', 'cube-payment-portal' )
        );
    }

    /**
     * Create an order for the invoice (new implementation).
     * 
     * @param array $data Invoice/order data with formatted line items.
     * @return array|WP_Error Created order or error.
     */
    private function create_order_for_invoice( $data ) {
        $location_id = $data['location_id'] ?? $this->client->get_location_id();
        
        if ( empty( $location_id ) ) {
            return new WP_Error(
                'missing_location',
                __( 'Location ID is required. Please configure in plugin settings.', 'cube-payment-portal' )
            );
        }

        $order_data = array(
            'idempotency_key' => wp_generate_uuid4(),
            'order'           => array(
                'location_id'  => $location_id,
                'customer_id'  => $data['customer_id'],
                'line_items'   => $data['line_items'],
                'state'        => 'OPEN', // Required for invoices.
            ),
        );

        // Add taxes if provided.
        if ( ! empty( $data['taxes'] ) ) {
            $order_data['order']['taxes'] = $data['taxes'];
        }

        // Add discounts if provided.
        if ( ! empty( $data['discounts'] ) ) {
            $order_data['order']['discounts'] = $data['discounts'];
        }

        $response = $this->client->post( 'orders', $order_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['order'] ?? $response;
    }

    /**
     * Create invoice from an existing order.
     * 
     * @param string $order_id Square order ID.
     * @param array  $data     Invoice settings.
     * @return array|WP_Error Created invoice or error.
     */
    private function create_invoice_from_order( $order_id, $data ) {
        $location_id = $data['location_id'] ?? $this->client->get_location_id();

        $invoice_data = array(
            'idempotency_key' => wp_generate_uuid4(),
            'invoice'         => array(
                'location_id'       => $location_id,
                'order_id'          => $order_id,
                'primary_recipient' => array(
                    'customer_id' => $data['customer_id'],
                ),
                'payment_requests'  => $this->build_payment_requests( $data ),
                'delivery_method'   => $data['delivery_method'] ?? 'EMAIL',
                'accepted_payment_methods' => $data['payment_methods'] ?? array(
                    'card'         => true,
                    'square_gift_card' => false,
                    'bank_account' => false,
                ),
            ),
        );

        // Optional fields.
        if ( ! empty( $data['title'] ) ) {
            $invoice_data['invoice']['title'] = $data['title'];
        }

        if ( ! empty( $data['description'] ) ) {
            $invoice_data['invoice']['description'] = $data['description'];
        }

        if ( ! empty( $data['invoice_number'] ) ) {
            $invoice_data['invoice']['invoice_number'] = $data['invoice_number'];
        }

        $response = $this->client->post( 'invoices', $invoice_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['invoice'] ?? $response;
    }

    /**
     * Build payment requests array for invoice.
     * 
     * @param array $data Invoice data.
     * @return array Payment requests.
     */
    private function build_payment_requests( $data ) {
        $payment_requests = array();

        // Default: single balance payment.
        $payment_request = array(
            'request_type' => $data['payment_type'] ?? 'BALANCE',
            'due_date'     => $data['due_date'] ?? wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
        );

        // Add automatic payment if card on file.
        if ( ! empty( $data['auto_payment'] ) && ! empty( $data['card_id'] ) ) {
            $payment_request['automatic_payment_source'] = 'CARD_ON_FILE';
            $payment_request['card_id'] = $data['card_id'];
        }

        // Add fixed amount if specified (for deposits/installments).
        if ( ! empty( $data['payment_amount_cents'] ) ) {
            $payment_request['fixed_amount_requested_money'] = array(
                'amount'   => (int) $data['payment_amount_cents'],
                'currency' => $data['currency'] ?? 'USD',
            );
        }

        // Add percentage if specified.
        if ( ! empty( $data['payment_percentage'] ) ) {
            $payment_request['percentage_requested'] = (string) $data['payment_percentage'];
        }

        // Add reminders if enabled.
        if ( ! empty( $data['reminders_enabled'] ) ) {
            $payment_request['reminders'] = $data['reminders'] ?? array(
                array(
                    'relative_scheduled_days' => -1, // 1 day before.
                    'message'                 => __( 'Reminder: Payment due tomorrow', 'cube-payment-portal' ),
                ),
            );
        }

        $payment_requests[] = $payment_request;

        return $payment_requests;
    }

    /**
     * Delete an order (for rollback).
     * 
     * Square requires the current version when updating orders.
     * This method fetches the order first to get its version, then cancels it.
     * 
     * @param string $order_id Square order ID.
     * @return bool|WP_Error True on success, error on failure.
     */
    private function delete_order( $order_id ) {
        // Note: Square doesn't have a direct delete endpoint for orders.
        // Orders can be canceled but not deleted.
        
        // Step 1: Fetch the order to get its current version.
        $order_response = $this->client->get( "orders/{$order_id}" );
        
        if ( is_wp_error( $order_response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "SPP: Cannot retrieve order {$order_id} for cancellation: " . $order_response->get_error_message() );
            }
            // Don't fail the invoice creation if we can't clean up the order.
            return $order_response;
        }
        
        $current_version = $order_response['order']['version'] ?? 1;
        
        // Step 2: Cancel the order using its current version.
        $response = $this->client->put(
            "orders/{$order_id}",
            array(
                'order' => array(
                    'state'   => 'CANCELED',
                    'version' => $current_version,  // Use actual current version.
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            // Log but don't fail - orphaned order is minor issue.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "SPP: Failed to cancel orphaned order {$order_id}: " . $response->get_error_message() );
            }
            return $response;
        }

        return true;
    }

    /**
     * Store invoice in local database.
     * 
     * @param array $invoice Square invoice object.
     * @param array $data    Original invoice data.
     * @return bool Success.
     */
    private function store_invoice_locally( $invoice, $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_invoices';

        // Get customer info from Square customer record and from invoice.
        $customer_name = '';
        $customer_email = '';
        $square_customer_id = $invoice['primary_recipient']['customer_id'] ?? '';
        
        // Try to get from invoice recipient first.
        $recipient = $invoice['primary_recipient'] ?? array();
        if ( ! empty( $recipient['given_name'] ) || ! empty( $recipient['family_name'] ) ) {
            $customer_name = trim( ( $recipient['given_name'] ?? '' ) . ' ' . ( $recipient['family_name'] ?? '' ) );
        } elseif ( ! empty( $recipient['company_name'] ) ) {
            $customer_name = $recipient['company_name'];
        }
        
        if ( ! empty( $recipient['email_address'] ) ) {
            $customer_email = $recipient['email_address'];
        }
        
        // Fall back to database lookup if needed.
        if ( empty( $customer_name ) && $square_customer_id ) {
            $customer = SPP_Database::get_customer_by_square_id( $square_customer_id );
            if ( $customer ) {
                $customer_name = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
                if ( empty( $customer_email ) ) {
                    $customer_email = $customer['email'] ?? '';
                }
            }
        }

        // Convert amount from cents to dollars for storage.
        $amount_cents = $invoice['payment_requests'][0]['computed_amount_money']['amount'] ?? 0;
        $amount_dollars = (float) $amount_cents / 100;

        // Build data array - user_id is now optional.
        $insert_data = array(
            'square_invoice_id'    => $invoice['id'],
            'square_order_id'      => $invoice['order_id'],
            'square_customer_id'   => $square_customer_id,
            'customer_name_square' => $customer_name,
            'customer_email_square'=> $customer_email,
            'invoice_number'       => $invoice['invoice_number'] ?? null,
            'title'                => $invoice['title'] ?? null,
            'description'          => $invoice['description'] ?? null,
            'amount'               => $amount_dollars,
            'currency'             => $invoice['payment_requests'][0]['computed_amount_money']['currency'] ?? 'USD',
            'status'               => strtolower( $invoice['status'] ),
            'due_date'             => $invoice['payment_requests'][0]['due_date'] ?? null,
            'created_at'           => current_time( 'mysql' ),
            'synced_at'            => current_time( 'mysql' ),
            'sync_source'          => 'plugin',
        );
        
        $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );
        
        // Include user_id if we have a WP user.
        if ( ! empty( $data['wp_user_id'] ) ) {
            $insert_data['user_id'] = intval( $data['wp_user_id'] );
            array_unshift( $format, '%d' );
        }
        
        $wpdb->insert( $table_name, $insert_data, $format );

        return true;
    }

    /**
     * Create an invoice (legacy method - kept for backwards compatibility).
     *
     * @param array $data Invoice data.
     * @return array|WP_Error Invoice data or error.
     */
    public function create_invoice( array $data ) {
        // First create an order.
        $order = $this->create_order( $data );

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $invoice_data = array(
            'idempotency_key' => wp_generate_uuid4(),
            'invoice'         => array(
                'location_id'        => $data['location_id'] ?? $this->client->get_location_id(),
                'order_id'           => $order['id'],
                'primary_recipient'  => array(
                    'customer_id' => $data['customer_id'],
                ),
                'payment_requests'   => array(
                    array(
                        'request_type'        => 'BALANCE',
                        'due_date'            => $data['due_date'] ?? wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
                        'automatic_payment_source' => 'CARD_ON_FILE',
                    ),
                ),
                'delivery_method'    => 'EMAIL',
                'accepted_payment_methods' => array(
                    'card' => true,
                ),
            ),
        );

        if ( ! empty( $data['title'] ) ) {
            $invoice_data['invoice']['title'] = $data['title'];
        }

        $response = $this->client->post( 'invoices', $invoice_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $invoice = $response['invoice'] ?? $response;

        // Log locally.
        $this->log_invoice( $invoice, $data );

        return $invoice;
    }

    /**
     * Create an order for the invoice.
     *
     * @param array $data Invoice data including line items.
     * @return array|WP_Error Order data or error.
     */
    private function create_order( array $data ) {
        $line_items = array();

        foreach ( $data['line_items'] as $item ) {
            $line_items[] = array(
                'name'            => $item['name'],
                'quantity'        => (string) ( $item['quantity'] ?? 1 ),
                'base_price_money' => array(
                    'amount'   => (int) round( floatval( $item['price'] ) * 100 ),
                    'currency' => $data['currency'] ?? get_option( 'spp_default_currency', 'USD' ),
                ),
            );
        }

        $order_data = array(
            'idempotency_key' => wp_generate_uuid4(),
            'order'           => array(
                'location_id' => $data['location_id'] ?? $this->client->get_location_id(),
                'customer_id' => $data['customer_id'],
                'line_items'  => $line_items,
            ),
        );

        $response = $this->client->post( 'orders', $order_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['order'] ?? $response;
    }

    /**
     * Get an invoice by ID.
     *
     * @param string $invoice_id Invoice ID.
     * @return array|WP_Error Invoice data or error.
     */
    public function get_invoice( $invoice_id ) {
        $response = $this->client->get( 'invoices/' . $invoice_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['invoice'] ?? $response;
    }

    /**
     * List invoices for a customer.
     *
     * @param string $customer_id Customer ID.
     * @return array|WP_Error Array of invoices or error.
     */
    public function list_invoices( $customer_id ) {
        $location_id = get_option( 'spp_default_location_id', '' );

        $filter = array(
            'customer_ids' => array( $customer_id ),
        );

        // Square requires location_ids in the filter.
        if ( ! empty( $location_id ) ) {
            $filter['location_ids'] = array( $location_id );
        }

        $body = array(
            'query' => array(
                'filter' => $filter,
                'sort'   => array(
                    'field' => 'INVOICE_SORT_DATE',
                    'order' => 'DESC',
                ),
            ),
        );

        $response = $this->client->post( 'invoices/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['invoices'] ?? array();
    }

    /**
     * Get all invoices (not customer-specific).
     *
     * @param array $args Query arguments.
     * @return array|WP_Error Array of invoices or error.
     */
    public function get_all_invoices( array $args = array() ) {
        $defaults = array(
            'location_id' => $this->client->get_location_id(),
            'limit'       => 100,
            'cursor'      => null,
        );

        $args = wp_parse_args( $args, $defaults );

        // Validate location_id is set.
        if ( empty( $args['location_id'] ) ) {
            return new WP_Error(
                'missing_location_id',
                __( 'Location ID is required. Please configure a default location in Settings.', 'cube-payment-portal' )
            );
        }

        $query_params = array(
            'location_id' => $args['location_id'],
            'limit'       => $args['limit'],
        );

        if ( ! empty( $args['cursor'] ) ) {
            $query_params['cursor'] = $args['cursor'];
        }

        $query_string = http_build_query( $query_params );
        $response = $this->client->get( 'invoices?' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return array(
            'invoices' => $response['invoices'] ?? array(),
            'cursor'   => $response['cursor'] ?? null,
        );
    }

    /**
     * Search invoices with filters.
     *
     * @param array $args Search arguments.
     * @return array|WP_Error Array of invoices or error.
     */
    public function search_invoices( array $args = array() ) {
        $body = array();

        // Build query.
        $query = array();

        // Filter by location.
        if ( ! empty( $args['location_id'] ) ) {
            $query['filter']['location_ids'] = array( $args['location_id'] );
        }

        // Filter by customer.
        if ( ! empty( $args['customer_id'] ) ) {
            $query['filter']['customer_ids'] = array( $args['customer_id'] );
        }

        // Sort.
        if ( ! empty( $args['sort_field'] ) ) {
            $query['sort'] = array(
                'field' => $args['sort_field'],
                'order' => $args['sort_order'] ?? 'DESC',
            );
        } else {
            $query['sort'] = array(
                'field' => 'INVOICE_SORT_DATE',
                'order' => 'DESC',
            );
        }

        $body['query'] = $query;

        // Limit.
        if ( ! empty( $args['limit'] ) ) {
            $body['limit'] = $args['limit'];
        }

        $response = $this->client->post( 'invoices/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['invoices'] ?? array();
    }

    /**
     * Get invoice with full details including line items.
     *
     * @param string $invoice_id Invoice ID.
     * @return array|WP_Error Invoice data with details or error.
     */
    public function get_invoice_with_details( $invoice_id ) {
        $invoice = $this->get_invoice( $invoice_id );

        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }

        // Get the order to fetch line items.
        if ( ! empty( $invoice['order_id'] ) ) {
            $order = $this->client->get( 'orders/' . $invoice['order_id'] );

            if ( ! is_wp_error( $order ) ) {
                $invoice['order_details'] = $order['order'] ?? $order;
            }
        }

        return $invoice;
    }

    /**
     * Batch retrieve orders for a list of invoices.
     *
     * @param array $order_ids Array of Square Order IDs.
     * @return array|WP_Error Array of orders mapped by order_id, or error.
     */
    public function get_orders_for_invoices( $order_ids ) {
        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return array();
        }

        // Ensure unique IDs and reset keys
        $unique_ids = array_values( array_unique( array_filter( $order_ids ) ) );

        if ( empty( $unique_ids ) ) {
            return array();
        }

        $body = array(
            'order_ids' => $unique_ids,
        );

        $response = $this->client->post( 'orders/batch-retrieve', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $orders = $response['orders'] ?? array();
        $mapped_orders = array();

        foreach ( $orders as $order ) {
            if ( ! empty( $order['id'] ) ) {
                $mapped_orders[ $order['id'] ] = $order;
            }
        }

        return $mapped_orders;
    }

    /**
     * Check if an invoice is overdue.
     *
     * @param array $invoice Invoice data.
     * @return bool True if overdue.
     */
    public function is_invoice_overdue( array $invoice ) {
        // Only unpaid invoices can be overdue.
        if ( empty( $invoice['status'] ) || strtolower( $invoice['status'] ) !== 'unpaid' ) {
            return false;
        }

        // Check payment requests for due date.
        if ( ! empty( $invoice['payment_requests'] ) ) {
            foreach ( $invoice['payment_requests'] as $request ) {
                if ( ! empty( $request['due_date'] ) ) {
                    $due_date = strtotime( $request['due_date'] );
                    $today = strtotime( 'today' );

                    if ( $due_date < $today ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Log invoice to local database.
     *
     * @param array $invoice    Invoice data from Square.
     * @param array $local_data Local data.
     */
    private function log_invoice( array $invoice, array $local_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spp_invoices';

        $total = 0;
        foreach ( $local_data['line_items'] as $item ) {
            $total += floatval( $item['price'] ) * ( $item['quantity'] ?? 1 );
        }

        $wpdb->insert(
            $table_name,
            array(
                'user_id'           => $local_data['user_id'] ?? 0,
                'square_invoice_id' => $invoice['id'],
                'square_order_id'   => $invoice['order_id'] ?? '',
                'amount'            => $total,
                'currency'          => $local_data['currency'] ?? get_option( 'spp_default_currency', 'USD' ),
                'status'            => strtolower( $invoice['status'] ?? 'draft' ),
                'due_date'          => $local_data['due_date'] ?? null,
                'created_at'        => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Update local invoice status.
     *
     * @param string $invoice_id Square invoice ID.
     * @param string $status     New status.
     */
    private function update_local_status( $invoice_id, $status ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spp_invoices';

        $data = array( 'status' => $status );

        if ( 'paid' === $status ) {
            $data['paid_at'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $table_name,
            $data,
            array( 'square_invoice_id' => $invoice_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );
    }

    /**
     * Publish a draft invoice.
     *
     * @param string $square_invoice_id Square invoice ID.
     * @param array  $options Optional publish options.
     * @return array|WP_Error Invoice data or error.
     */
    public function publish_invoice( $square_invoice_id, $options = array() ) {
        // Get the invoice to retrieve its current version.
        $invoice = $this->get_invoice( $square_invoice_id );
        
        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }
        
        $defaults = array(
            'idempotency_key' => wp_generate_uuid4(),
            'version'         => $invoice['version'] ?? 0,
        );

        $body = wp_parse_args( $options, $defaults );

        $response = $this->client->post(
            "invoices/{$square_invoice_id}/publish",
            $body
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Sync the invoice after successful publish.
        $this->sync_after_action( $square_invoice_id );

        return $response['invoice'] ?? $response;
    }

    /**
     * Cancel an unpaid invoice.
     *
     * @param string $square_invoice_id Square invoice ID.
     * @return array|WP_Error Invoice data or error.
     */
    public function cancel_invoice( $square_invoice_id ) {
        $response = $this->client->post(
            "invoices/{$square_invoice_id}/cancel",
            array(
                'version' => $this->get_invoice_version( $square_invoice_id ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Sync the invoice after successful cancel.
        $this->sync_after_action( $square_invoice_id );

        return $response['invoice'] ?? $response;
    }

    /**
     * Delete a draft invoice.
     * Note: Only DRAFT invoices can be deleted via API.
     *
     * @param string $square_invoice_id Square invoice ID.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function delete_invoice( $square_invoice_id ) {
        $response = $this->client->delete( "invoices/{$square_invoice_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Delete from local database as well.
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_invoices';
        $wpdb->delete(
            $table_name,
            array( 'square_invoice_id' => $square_invoice_id ),
            array( '%s' )
        );

        return true;
    }

    /**
     * Get invoice version for updates.
     *
     * @param string $square_invoice_id Square invoice ID.
     * @return int Invoice version.
     */
    private function get_invoice_version( $square_invoice_id ) {
        $invoice = $this->get_invoice_with_details( $square_invoice_id );
        
        if ( is_wp_error( $invoice ) ) {
            return 0;
        }

        return $invoice['invoice']['version'] ?? 0;
    }

    /**
     * Sync invoice after action with smart bidirectional logic.
     *
     * @param string $square_invoice_id Square invoice ID.
     * @return bool|WP_Error True on success, error on failure.
     */
    private function sync_after_action( $square_invoice_id ) {
        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
        }

        // Fetch latest data from Square.
        $invoice = $this->get_invoice( $square_invoice_id );
        
        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }

        // Sync using the sync class.
        $sync = new SPP_Sync_Invoices();
        return $sync->sync_single_invoice( $invoice );
    }
}
