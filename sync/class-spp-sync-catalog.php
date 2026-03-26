<?php
/**
 * Catalog Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Catalog
 *
 * Handles sync of catalog items from Square to WordPress.
 */
class SPP_Sync_Catalog {

    /**
     * Catalog API instance.
     *
     * @var SPP_Catalog
     */
    private $catalog_api;

    /**
     * Constructor.
     *
     * @param SPP_Square_Client|null $client Optional API client instance.
     */
    public function __construct( ?SPP_Square_Client $client = null ) {
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }
        $this->catalog_api = new SPP_Catalog( $client );
    }

    /**
     * Sync catalog items from Square to WordPress.
     *
     * @param array $args Optional arguments.
     * @return array|WP_Error Sync results or error.
     */
    public function sync_from_square( array $args = array() ) {
        $synced_count = 0;
        $error_count  = 0;
        $errors       = array();
        $cursor       = null;

        // First, get all categories for mapping.
        $categories = $this->get_categories();

        try {
            do {
                // Fetch catalog items from Square.
                $result = $this->catalog_api->get_catalog_items( array(
                    'types'          => 'ITEM',
                    'cursor'         => $cursor,
                    'archived_state' => 'ARCHIVED_STATE_ALL',
                ) );

                if ( is_wp_error( $result ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'SPP Catalog Sync: API error - ' . $result->get_error_message() );
                    }
                    return $result;
                }

                $objects = $result['objects'] ?? array();
                $cursor = $result['cursor'] ?? null;

                // Process each item.
                foreach ( $objects as $item ) {
                    if ( 'ITEM' !== $item['type'] ) {
                        continue;
                    }

                    $sync_result = $this->sync_single_item( $item, $categories );

                    if ( is_wp_error( $sync_result ) ) {
                        $error_count++;
                        $errors[] = array(
                            'item_id' => $item['id'] ?? 'unknown',
                            'error'   => $sync_result->get_error_message(),
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
            update_option( 'spp_catalog_last_sync', time() );

            return array(
                'success' => true,
                'synced'  => $synced_count,
                'errors'  => $error_count,
                'details' => $errors,
            );

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Catalog Sync: Exception - ' . $e->getMessage() );
            }
            return new WP_Error( 'sync_exception', $e->getMessage() );
        }
    }

    /**
     * Get categories from Square catalog.
     *
     * @return array Categories keyed by ID.
     */
    private function get_categories() {
        $result = $this->catalog_api->get_catalog_items( array(
            'types' => 'CATEGORY',
        ) );

        $categories = array();

        if ( ! is_wp_error( $result ) && ! empty( $result['objects'] ) ) {
            foreach ( $result['objects'] as $cat ) {
                if ( 'CATEGORY' === $cat['type'] ) {
                    $categories[ $cat['id'] ] = $cat['category_data']['name'] ?? '';
                }
            }
        }

        return $categories;
    }

    /**
     * Sync a single catalog item.
     *
     * @param array $item       Item data from Square API.
     * @param array $categories Categories mapping.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function sync_single_item( array $item, array $categories = array() ) {
        if ( empty( $item['id'] ) ) {
            return new WP_Error( 'invalid_item', 'Item ID is required' );
        }

        $item_data = $item['item_data'] ?? array();

        // Get category info.
        $category_id = null;
        $category_name = null;
        if ( ! empty( $item_data['category_id'] ) ) {
            $category_id = $item_data['category_id'];
            $category_name = $categories[ $category_id ] ?? '';
        }

        // Get product type.
        $product_type = $item_data['product_type'] ?? 'REGULAR';

        // Get primary variation for pricing.
        $variations = $item_data['variations'] ?? array();
        $price = 0;
        $currency = 'USD';
        $formatted_variations = array();

        foreach ( $variations as $var ) {
            $var_data = $var['item_variation_data'] ?? array();
            $var_price = 0;
            $var_currency = 'USD';

            if ( ! empty( $var_data['price_money'] ) ) {
                $var_price = ( $var_data['price_money']['amount'] ?? 0 ) / 100;
                $var_currency = $var_data['price_money']['currency'] ?? 'USD';
            }

            // Use first variation as primary price.
            if ( empty( $formatted_variations ) ) {
                $price = $var_price;
                $currency = $var_currency;
            }

            $formatted_variations[] = array(
                'id'              => $var['id'],
                'name'            => $var_data['name'] ?? 'Default',
                'sku'             => $var_data['sku'] ?? '',
                'price'           => $var_price,
                'currency'        => $var_currency,
                'track_inventory' => ! empty( $var_data['track_inventory'] ),
            );
        }

        // Get image URL.
        $image_url = $item['image_url'] ?? null;
        if ( empty( $image_url ) && ! empty( $item_data['image_ids'][0] ) ) {
            $image_url = $this->catalog_api->get_image_url( $item_data['image_ids'][0] );
        }

        // Get created_at from Square API.
        $created_at = current_time( 'mysql' );
        if ( ! empty( $item['created_at'] ) ) {
            $created_at = gmdate( 'Y-m-d H:i:s', strtotime( $item['created_at'] ) );
        }

        // Prepare data for upsert.

        $data = array(
            'square_item_id' => $item['id'],
            'name'           => $item_data['name'] ?? '',
            'description'    => $item_data['description'] ?? '',
            'category_id'    => $category_id,
            'category_name'  => $category_name,
            'product_type'   => $product_type,
            'price'          => $price,
            'currency'       => $currency,
            'variations'     => $formatted_variations,
            'image_url'      => $image_url,
            'is_deleted'     => ( ! empty( $item['is_deleted'] ) || ! empty( $item['item_data']['is_deleted'] ) ) ? 1 : 0,
            'is_archived'    => ( ! empty( $item['is_archived'] ) || ! empty( $item['item_data']['is_archived'] ) ) ? 1 : 0,
            'created_at'     => $created_at,
        );

        $result = SPP_Database::upsert_catalog_item( $data );

        if ( false === $result ) {
            return new WP_Error( 'db_error', 'Failed to save catalog item to database' );
        }

        return true;
    }

    /**
     * Bulk sync catalog items by IDs.
     *
     * @param array $item_ids Array of Square item IDs.
     * @return array Sync results.
     */
    public function bulk_sync( array $item_ids ) {
        $synced_count = 0;
        $error_count = 0;
        $errors = array();
        $categories = $this->get_categories();

        foreach ( $item_ids as $item_id ) {
            $item = $this->catalog_api->get_item_details( $item_id );

            if ( is_wp_error( $item ) ) {
                $error_count++;
                $errors[] = array(
                    'item_id' => $item_id,
                    'error'   => $item->get_error_message(),
                );
                continue;
            }

            $result = $this->sync_single_item( $item, $categories );

            if ( is_wp_error( $result ) ) {
                $error_count++;
                $errors[] = array(
                    'item_id' => $item_id,
                    'error'   => $result->get_error_message(),
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
     * Bulk delete local catalog items.
     *
     * @param array $item_ids Array of Square item IDs to delete locally.
     * @return array Delete results.
     */
    public function bulk_delete( array $item_ids ) {
        $deleted_count = SPP_Database::bulk_delete_catalog_items( $item_ids );

        return array(
            'success' => true,
            'deleted' => $deleted_count,
        );
    }
}
