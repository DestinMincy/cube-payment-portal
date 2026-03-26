<?php
/**
 * Square Inventory API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Inventory
 *
 * Handles inventory management via Square Inventory API.
 */
class SPP_Inventory {

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
     * Batch retrieve inventory counts for catalog items.
     *
     * @param array  $catalog_object_ids Array of catalog object IDs.
     * @param array  $location_ids       Optional specific location IDs.
     * @param string $cursor             Pagination cursor.
     * @return array|WP_Error Inventory counts or error.
     */
    public function batch_retrieve_counts( array $catalog_object_ids, array $location_ids = array(), $cursor = null ) {
        $body = array(
            'catalog_object_ids' => $catalog_object_ids,
        );

        if ( ! empty( $location_ids ) ) {
            $body['location_ids'] = $location_ids;
        }

        if ( $cursor ) {
            $body['cursor'] = $cursor;
        }

        $response = $this->client->post( 'inventory/counts/batch-retrieve', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Batch retrieve inventory changes (adjustments, transfers, etc).
     *
     * @param array  $catalog_object_ids Array of catalog object IDs.
     * @param array  $location_ids       Optional specific location IDs.
     * @param array  $types              Change types (ADJUSTMENT, TRANSFER, etc).
     * @param string $begin_time         Start time (ISO 8601).
     * @param string $end_time           End time (ISO 8601).
     * @param string $cursor             Pagination cursor.
     * @return array|WP_Error Inventory changes or error.
     */
    public function batch_retrieve_changes( 
        array $catalog_object_ids = array(), 
        array $location_ids = array(), 
        array $types = array(),
        $begin_time = null,
        $end_time = null,
        $cursor = null 
    ) {
        $body = array();

        if ( ! empty( $catalog_object_ids ) ) {
            $body['catalog_object_ids'] = $catalog_object_ids;
        }

        if ( ! empty( $location_ids ) ) {
            $body['location_ids'] = $location_ids;
        }

        if ( ! empty( $types ) ) {
            $body['types'] = $types;
        }

        if ( $begin_time ) {
            $body['updated_after'] = $begin_time;
        }

        if ( $end_time ) {
            $body['updated_before'] = $end_time;
        }

        if ( $cursor ) {
            $body['cursor'] = $cursor;
        }

        $response = $this->client->post( 'inventory/changes/batch-retrieve', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Retrieve inventory count for a specific item at a location.
     *
     * @param string $catalog_object_id Catalog object ID.
     * @param string $location_id       Location ID (optional, uses default).
     * @return array|WP_Error Inventory count or error.
     */
    public function retrieve_count( $catalog_object_id, $location_id = null ) {
        if ( empty( $location_id ) ) {
            $location_id = $this->client->get_location_id();
        }

        $response = $this->client->get( 
            'inventory/' . $catalog_object_id . '?location_ids=' . $location_id 
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['counts'] ?? array();
    }

    /**
     * Batch change inventory counts.
     *
     * @param array $changes Array of inventory changes.
     * @return array|WP_Error Result or error.
     */
    public function batch_change_inventory( array $changes ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'changes'         => $changes,
        );

        $response = $this->client->post( 'inventory/changes/batch-create', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Adjust inventory quantity for a single item.
     *
     * @param string $catalog_object_id Catalog object ID.
     * @param int    $quantity          Quantity change (positive to add, negative to remove).
     * @param string $reason            Adjustment reason.
     * @param string $location_id       Optional location ID.
     * @return array|WP_Error Result or error.
     */
    public function adjust_quantity( $catalog_object_id, $quantity, $reason = 'Adjustment', $location_id = null ) {
        if ( empty( $location_id ) ) {
            $location_id = $this->client->get_location_id();
        }

        $change = array(
            'type'       => 'ADJUSTMENT',
            'adjustment' => array(
                'catalog_object_id' => $catalog_object_id,
                'location_id'       => $location_id,
                'quantity'          => (string) abs( $quantity ),
                'from_state'        => $quantity > 0 ? 'NONE' : 'IN_STOCK',
                'to_state'          => $quantity > 0 ? 'IN_STOCK' : 'SOLD',
                'occurred_at'       => gmdate( 'c' ),
            ),
        );

        return $this->batch_change_inventory( array( $change ) );
    }

    /**
     * Transfer stock between locations.
     *
     * @param string $catalog_object_id Catalog object ID.
     * @param int    $quantity          Quantity to transfer.
     * @param string $from_location_id  Source location ID.
     * @param string $to_location_id    Destination location ID.
     * @return array|WP_Error Result or error.
     */
    public function transfer_stock( $catalog_object_id, $quantity, $from_location_id, $to_location_id ) {
        $change = array(
            'type'     => 'TRANSFER',
            'transfer' => array(
                'catalog_object_id' => $catalog_object_id,
                'from_location_id'  => $from_location_id,
                'to_location_id'    => $to_location_id,
                'quantity'          => (string) abs( $quantity ),
                'occurred_at'       => gmdate( 'c' ),
            ),
        );

        return $this->batch_change_inventory( array( $change ) );
    }

    /**
     * Get all locations.
     *
     * @return array|WP_Error Locations or error.
     */
    public function get_locations() {
        $response = $this->client->get( 'locations' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['locations'] ?? array();
    }

    /**
     * Get low stock items.
     *
     * @param int   $threshold     Low stock threshold.
     * @param array $location_ids  Optional location IDs.
     * @return array|WP_Error Low stock items or error.
     */
    public function get_low_stock_items( $threshold = 10, array $location_ids = array() ) {
        // First, get all catalog items from the database.
        if ( ! class_exists( 'SPP_Database' ) ) {
            require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
        }

        // Fetch all catalog items.
        $catalog_items = SPP_Database::get_catalog_items( array(
            'limit' => 1000, // Reasonable limit for low stock check
        ) );

        if ( empty( $catalog_items ) ) {
            return array();
        }

        // Get all variation IDs and map them to item info.
        $variation_ids = array();
        $variation_map = array();

        foreach ( $catalog_items as $item ) {
            $variations = ! empty( $item['variations'] ) ? json_decode( $item['variations'], true ) : array();
            
            foreach ( $variations as $variation ) {
                // Only include if tracking is enabled.
                if ( ! empty( $variation['track_inventory'] ) ) {
                    $v_id = $variation['id'];
                    $variation_ids[] = $v_id;
                    $variation_map[ $v_id ] = array(
                        'item_id'        => $item['square_item_id'],
                        'item_name'      => $item['name'] ?? '',
                        'variation_name' => $variation['name'] ?? 'Default',
                    );
                }
            }
        }

        if ( empty( $variation_ids ) ) {
            return array();
        }

        // Batch retrieve counts from Square.
        $counts_result = $this->batch_retrieve_counts( $variation_ids, $location_ids );

        if ( is_wp_error( $counts_result ) ) {
            return $counts_result;
        }

        // Filter for low stock.
        $low_stock = array();
        foreach ( $counts_result['counts'] ?? array() as $count ) {
            $quantity = (int) ( $count['quantity'] ?? 0 );
            
            // Only alert if in stock and below threshold (include 0 as out of stock/low stock).
            if ( $quantity <= $threshold && 'IN_STOCK' === ( $count['state'] ?? '' ) ) {
                $catalog_object_id = $count['catalog_object_id'] ?? '';
                $item_info = $variation_map[ $catalog_object_id ] ?? array();

                $low_stock[] = array(
                    'catalog_object_id' => $catalog_object_id,
                    'location_id'       => $count['location_id'] ?? '',
                    'quantity'          => $quantity,
                    'item_name'         => $item_info['item_name'] ?? '',
                    'variation_name'    => $item_info['variation_name'] ?? '',
                    'calculated_at'     => $count['calculated_at'] ?? '',
                );
            }
        }

        return $low_stock;
    }
}
