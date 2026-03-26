<?php
/**
 * Order Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Orders
 *
 * Handles sync of orders from Square to WordPress.
 */
class SPP_Sync_Orders {

    /**
     * Square API client.
     *
     * @var SPP_Square_Client
     */
    private $client;

    /**
     * Orders API instance.
     *
     * @var SPP_Orders
     */
    private $orders_api;

    /**
     * Constructor.
     *
     * @param SPP_Square_Client|null $client Optional API client instance.
     */
    public function __construct( ?SPP_Square_Client $client = null ) {
        $this->client = $client ?: new SPP_Square_Client();

        if ( ! class_exists( 'SPP_Orders' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-orders.php';
        }
        $this->orders_api = new SPP_Orders( $this->client );
    }

    /**
     * Sync orders from Square to WordPress.
     *
     * @param array $args Optional arguments.
     * @return array|WP_Error Sync results or error.
     */
    public function sync_from_square( array $args = array() ) {
        $defaults = array(
            'limit'      => 100,
            'begin_time' => '',
            'end_time'   => '',
            'states'     => array(), // Filter by order states.
        );

        $args = wp_parse_args( $args, $defaults );

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
                // Build search query.
                $query = array(
                    'filter' => array(
                        'date_time_filter' => array(
                            'created_at' => array(
                                'start_at' => $args['begin_time'],
                            ),
                        ),
                    ),
                    'sort' => array(
                        'sort_field' => 'CREATED_AT',
                        'sort_order' => 'DESC',
                    ),
                );

                if ( ! empty( $args['end_time'] ) ) {
                    $query['filter']['date_time_filter']['created_at']['end_at'] = $args['end_time'];
                }

                if ( ! empty( $args['states'] ) ) {
                    $query['filter']['state_filter'] = array(
                        'states' => $args['states'],
                    );
                }

                $filters = array(
                    'query' => $query,
                    'limit' => $args['limit'],
                );

                if ( $cursor ) {
                    $filters['cursor'] = $cursor;
                }

                // Fetch orders from Square.
                $result = $this->orders_api->search_orders( $filters );

                if ( is_wp_error( $result ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'SPP Order Sync: API error - ' . $result->get_error_message() );
                    }
                    return $result;
                }

                // The search_orders method returns the full response.
                $orders = $result['orders'] ?? array();
                $cursor = $result['cursor'] ?? null;

                // Process each order.
                foreach ( $orders as $order_data ) {
                    $sync_result = $this->sync_single_order( $order_data );

                    if ( is_wp_error( $sync_result ) ) {
                        $error_count++;
                        $errors[] = array(
                            'order_id' => $order_data['id'] ?? 'unknown',
                            'error'    => $sync_result->get_error_message(),
                        );
                    } else {
                        $synced_count++;
                    }
                }

                // If no cursor, we are done.
                if ( empty( $cursor ) ) {
                    break;
                }

            } while ( true );

            // Update last sync timestamp.
            update_option( 'spp_orders_last_sync', time() );

            return array(
                'success' => true,
                'synced'  => $synced_count,
                'errors'  => $error_count,
                'details' => $errors,
            );

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Order Sync: Exception - ' . $e->getMessage() );
            }
            return new WP_Error( 'sync_exception', $e->getMessage() );
        }
    }

    /**
     * Sync a single order from Square data.
     *
     * @param array $order_data Order data from Square API.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function sync_single_order( array $order_data ) {
        if ( empty( $order_data['id'] ) ) {
            return new WP_Error( 'invalid_order', 'Order ID is required' );
        }

        // Calculate totals.
        $total_amount = 0;
        $total_tax = 0;
        $total_discount = 0;
        $currency = 'USD';

        if ( ! empty( $order_data['total_money'] ) ) {
            $total_amount = ( $order_data['total_money']['amount'] ?? 0 ) / 100;
            $currency = $order_data['total_money']['currency'] ?? 'USD';
        }

        if ( ! empty( $order_data['total_tax_money'] ) ) {
            $total_tax = ( $order_data['total_tax_money']['amount'] ?? 0 ) / 100;
        }

        if ( ! empty( $order_data['total_discount_money'] ) ) {
            $total_discount = ( $order_data['total_discount_money']['amount'] ?? 0 ) / 100;
        }

        // Process line items.
        $line_items = array();
        if ( ! empty( $order_data['line_items'] ) ) {
            foreach ( $order_data['line_items'] as $item ) {
                $line_items[] = array(
                    'uid'                 => $item['uid'] ?? '',
                    'name'                => $item['name'] ?? '',
                    'quantity'            => $item['quantity'] ?? '1',
                    'catalog_object_id'   => $item['catalog_object_id'] ?? '',
                    'variation_name'      => $item['variation_name'] ?? '',
                    'base_price'          => isset( $item['base_price_money'] ) ? ( $item['base_price_money']['amount'] ?? 0 ) / 100 : 0,
                    'total_money'         => isset( $item['total_money'] ) ? ( $item['total_money']['amount'] ?? 0 ) / 100 : 0,
                    'total_tax'           => isset( $item['total_tax_money'] ) ? ( $item['total_tax_money']['amount'] ?? 0 ) / 100 : 0,
                    'total_discount'      => isset( $item['total_discount_money'] ) ? ( $item['total_discount_money']['amount'] ?? 0 ) / 100 : 0,
                    'note'                => $item['note'] ?? '',
                );
            }
        }

        // Get created_at from Square API.
        $created_at = current_time( 'mysql' );
        if ( ! empty( $order_data['created_at'] ) ) {
            $created_at = gmdate( 'Y-m-d H:i:s', strtotime( $order_data['created_at'] ) );
        }

        // Prepare data for upsert.
        $data = array(
            'square_order_id'    => $order_data['id'],
            'square_customer_id' => $order_data['customer_id'] ?? null,
            'square_location_id' => $order_data['location_id'] ?? null,
            'reference_id'       => $order_data['reference_id'] ?? null,
            'status'             => $order_data['state'] ?? 'OPEN',
            'total_amount'       => $total_amount,
            'total_tax'          => $total_tax,
            'total_discount'     => $total_discount,
            'currency'           => $currency,
            'line_items'         => $line_items,
            'fulfillments'       => $order_data['fulfillments'] ?? array(),
            'metadata'           => array(
                'source'     => $order_data['source'] ?? null,
                'version'    => $order_data['version'] ?? null,
                'updated_at' => $order_data['updated_at'] ?? null,
            ),
            'created_at'         => $created_at,
        );

        $result = SPP_Database::upsert_order( $data );

        if ( false === $result ) {
            return new WP_Error( 'db_error', 'Failed to save order to database' );
        }

        return true;
    }

    /**
     * Bulk sync orders by IDs.
     *
     * @param array $order_ids Array of Square order IDs.
     * @return array Sync results.
     */
    public function bulk_sync( array $order_ids ) {
        $synced_count = 0;
        $error_count = 0;
        $errors = array();

        foreach ( $order_ids as $order_id ) {
            $order_data = $this->orders_api->get_order( $order_id );

            if ( is_wp_error( $order_data ) ) {
                $error_count++;
                $errors[] = array(
                    'order_id' => $order_id,
                    'error'    => $order_data->get_error_message(),
                );
                continue;
            }

            $result = $this->sync_single_order( $order_data );

            if ( is_wp_error( $result ) ) {
                $error_count++;
                $errors[] = array(
                    'order_id' => $order_id,
                    'error'    => $result->get_error_message(),
                );
            } else {
                $synced_count++;
            }

            // Rate limiting.
            usleep( 100000 ); // 0.1 second delay.
        }

        return array(
            'success' => true,
            'synced'  => $synced_count,
            'errors'  => $error_count,
            'details' => $errors,
        );
    }

    /**
     * Bulk delete local orders.
     *
     * @param array $order_ids Array of Square order IDs to delete locally.
     * @return array Delete results.
     */
    public function bulk_delete( array $order_ids ) {
        $deleted_count = 0;
        $error_count = 0;

        foreach ( $order_ids as $order_id ) {
            $result = SPP_Database::delete_order( $order_id );

            if ( $result ) {
                $deleted_count++;
            } else {
                $error_count++;
            }
        }

        return array(
            'success' => true,
            'deleted' => $deleted_count,
            'errors'  => $error_count,
        );
    }
}
