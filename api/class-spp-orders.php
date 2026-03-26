<?php
/**
 * Square Orders API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Orders
 *
 * Handles order management via Square Orders API.
 */
class SPP_Orders {

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
     * Create a draft order (order template) for use with RELATIVE pricing subscriptions.
     *
     * @param array $line_items Array of line items with catalog_object_id and quantity.
     * @return array|WP_Error Order data or error.
     */
    public function create_draft_order( array $line_items ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'order'           => array(
                'location_id' => $this->client->get_location_id(),
                'state'       => 'DRAFT',
                'line_items'  => array(),
            ),
        );

        // Build line items.
        foreach ( $line_items as $item ) {
            $line_item = array(
                'quantity' => (string) ( $item['quantity'] ?? '1' ),
            );

            // Use catalog_object_id for catalog items.
            if ( ! empty( $item['catalog_object_id'] ) ) {
                $line_item['catalog_object_id'] = $item['catalog_object_id'];
            }

            // Support ad-hoc items with name and price.
            if ( ! empty( $item['name'] ) ) {
                $line_item['name'] = $item['name'];
            }
            if ( ! empty( $item['base_price_money'] ) ) {
                $line_item['base_price_money'] = $item['base_price_money'];
            }

            $body['order']['line_items'][] = $line_item;
        }

        $response = $this->client->post( 'orders', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['order'] ?? $response;
    }

    /**
     * Get an order by ID.
     *
     * @param string $order_id Order ID.
     * @return array|WP_Error Order data or error.
     */
    public function get_order( $order_id ) {
        $response = $this->client->get( 'orders/' . $order_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['order'] ?? $response;
    }

    /**
     * Update an order.
     *
     * @param string $order_id Order ID.
     * @param array  $data     Order data to update.
     * @return array|WP_Error Updated order or error.
     */
    public function update_order( $order_id, array $data ) {
        $body = array(
            'order' => array(
                'location_id' => $this->client->get_location_id(),
                'version'     => $data['version'] ?? 1,
            ),
        );

        if ( ! empty( $data['line_items'] ) ) {
            $body['order']['line_items'] = $data['line_items'];
        }

        if ( ! empty( $data['state'] ) ) {
            $body['order']['state'] = $data['state'];
        }

        $response = $this->client->put( 'orders/' . $order_id, $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['order'] ?? $response;
    }

    /**
     * Search orders.
     *
     * @param array $filters Search filters.
     * @return array|WP_Error Array of orders or error.
     */
    public function search_orders( array $filters = array() ) {
        $body = array(
            'location_ids' => array( $this->client->get_location_id() ),
        );

        if ( ! empty( $filters['query'] ) ) {
            $body['query'] = $filters['query'];
        }

        if ( ! empty( $filters['limit'] ) ) {
            $body['limit'] = intval( $filters['limit'] );
        }

        $response = $this->client->post( 'orders/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }
}
