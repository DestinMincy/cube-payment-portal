<?php
/**
 * Catalog database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Catalog
 *
 * Handles catalog-related database queries.
 */
trait SPP_Database_Catalog {

    /**
     * Get catalog items with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Catalog items.
     */
    public static function get_catalog_items( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'search'      => '',
            'category_id' => '',
            'limit'       => 20,
            'offset'      => 0,
            'order_by'    => 'name',
            'order'       => 'ASC',
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'spp_catalog_items';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR description LIKE %s OR square_item_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['category_id'] ) ) {
            $where[] = 'category_id = %s';
            $values[] = $args['category_id'];
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize order by and order.
        $allowed_order_by = array( 'id', 'name', 'price', 'created_at', 'updated_at' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'name';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'DESC' ? 'DESC' : 'ASC';
        $order = esc_sql( $order );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE $where_clause
                 ORDER BY $order_by $order
                 LIMIT %d OFFSET %d",
                array_merge( $values, array( $args['limit'], $args['offset'] ) )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE $where_clause
                 ORDER BY $order_by $order
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get total count of catalog items with filters.
     *
     * @param array $args Query arguments.
     * @return int Total count.
     */
    public static function get_catalog_items_count( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'search'      => '',
            'category_id' => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'spp_catalog_items';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR description LIKE %s OR square_item_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['category_id'] ) ) {
            $where[] = 'category_id = %s';
            $values[] = $args['category_id'];
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE $where_clause",
                $values
            );
        } else {
            $sql = "SELECT COUNT(*) FROM $table WHERE $where_clause";
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get catalog item by Square item ID.
     *
     * @param string $square_item_id Square item ID.
     * @return array|null Item data or null.
     */
    public static function get_catalog_item_by_square_id( $square_item_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_catalog_items';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE square_item_id = %s",
                $square_item_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get all unique categories.
     *
     * @return array Categories.
     */
    public static function get_catalog_categories() {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_catalog_items';

        return $wpdb->get_results(
            "SELECT DISTINCT category_id, category_name FROM $table 
             WHERE category_id IS NOT NULL AND category_id != ''
             ORDER BY category_name ASC",
            ARRAY_A
        );
    }

    /**
     * Upsert a catalog item (insert or update).
     *
     * @param array $data Item data.
     * @return int|false The item ID on success, false on failure.
     */
    public static function upsert_catalog_item( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_catalog_items';

        if ( empty( $data['square_item_id'] ) ) {
            return false;
        }

        $existing = self::get_catalog_item_by_square_id( $data['square_item_id'] );

        if ( $existing ) {
            // Update only provided fields.
            $db_data = array();
            $format = array();

            $field_map = array(
                'name'            => '%s',
                'description'     => '%s',
                'category_id'     => '%s',
                'category_name'   => '%s',
                'product_type'    => '%s',
                'price'           => '%f',
                'currency'        => '%s',
                'variations'      => '%s',
                'image_url'       => '%s',
                'is_deleted'      => '%d',
                'is_archived'     => '%d',
            );

            foreach ( $field_map as $field => $field_format ) {
                if ( array_key_exists( $field, $data ) ) {
                    if ( 'variations' === $field && is_array( $data[ $field ] ) ) {
                        $db_data[ $field ] = wp_json_encode( $data[ $field ] );
                    } else {
                        $db_data[ $field ] = $data[ $field ];
                    }
                    $format[] = $field_format;
                }
            }

            if ( empty( $db_data ) ) {
                return $existing['id'];
            }

            $db_data['synced_at']  = current_time( 'mysql' );
            $format[]              = '%s';
            $db_data['updated_at'] = current_time( 'mysql' );
            $format[]              = '%s';

            $result = $wpdb->update(
                $table,
                $db_data,
                array( 'square_item_id' => $data['square_item_id'] ),
                $format,
                array( '%s' )
            );

            return false !== $result ? $existing['id'] : false;
        } else {
            // Insert.
            $db_data = array(
                'square_item_id'  => $data['square_item_id'],
                'name'            => $data['name'] ?? '',
                'description'     => $data['description'] ?? '',
                'category_id'     => $data['category_id'] ?? null,
                'category_name'   => $data['category_name'] ?? null,
                'product_type'    => $data['product_type'] ?? 'REGULAR',
                'price'           => $data['price'] ?? 0,
                'currency'        => $data['currency'] ?? 'USD',
                'variations'      => isset( $data['variations'] ) ? ( is_array( $data['variations'] ) ? wp_json_encode( $data['variations'] ) : $data['variations'] ) : null,
                'image_url'       => $data['image_url'] ?? null,
                'is_deleted'      => $data['is_deleted'] ?? 0,
                'is_archived'     => $data['is_archived'] ?? 0,
                'created_at'      => $data['created_at'] ?? current_time( 'mysql' ),
                'synced_at'       => current_time( 'mysql' ),
            );

            $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

            $result = $wpdb->insert( $table, $db_data, $format );

            return false !== $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Delete a catalog item by Square item ID.
     *
     * @param string $square_item_id Square item ID.
     * @return bool True on success.
     */
    public static function delete_catalog_item( $square_item_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_catalog_items';

        return false !== $wpdb->delete(
            $table,
            array( 'square_item_id' => $square_item_id ),
            array( '%s' )
        );
    }

    /**
     * Bulk delete catalog items.
     *
     * @param array $square_item_ids Array of Square item IDs.
     * @return int Number of deleted items.
     */
    public static function bulk_delete_catalog_items( array $square_item_ids ) {
        global $wpdb;

        if ( empty( $square_item_ids ) ) {
            return 0;
        }

        $table = $wpdb->prefix . 'spp_catalog_items';
        $placeholders = implode( ',', array_fill( 0, count( $square_item_ids ), '%s' ) );

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE square_item_id IN ($placeholders)",
                $square_item_ids
            )
        );
    }
}
