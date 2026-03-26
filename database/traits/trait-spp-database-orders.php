<?php
/**
 * Order database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Orders
 *
 * Handles order-related database queries.
 */
trait SPP_Database_Orders {

    /**
     * Get orders with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Orders.
     */
    public static function get_orders( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'             => '',
            'search'             => '',
            'square_customer_id' => '',
            'limit'              => 20,
            'offset'             => 0,
            'order_by'           => 'id',
            'order'              => 'DESC',
            'date_from'          => '',
            'date_to'            => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $orders_table = $wpdb->prefix . 'spp_orders';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'o.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['square_customer_id'] ) ) {
            $where[] = 'o.square_customer_id = %s';
            $values[] = $args['square_customer_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(c.given_name LIKE %s OR c.family_name LIKE %s OR c.email LIKE %s OR o.square_order_id LIKE %s OR o.reference_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'o.created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'o.created_at <= %s';
            $values[] = $args['date_to'];
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize order by and order.
        $allowed_order_by = array( 'id', 'created_at', 'total_amount', 'status' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'id';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        // Build query with joins for customer info.
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT o.*,
                 NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), '') as customer_name,
                 c.email as customer_email,
                 c.phone as customer_phone
                 FROM $orders_table o
                 LEFT JOIN $customers_table c ON o.square_customer_id = c.square_customer_id
                 WHERE $where_clause
                 ORDER BY o.$order_by $order
                 LIMIT %d OFFSET %d",
                array_merge( $values, array( $args['limit'], $args['offset'] ) )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT o.*,
                 NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), '') as customer_name,
                 c.email as customer_email,
                 c.phone as customer_phone
                 FROM $orders_table o
                 LEFT JOIN $customers_table c ON o.square_customer_id = c.square_customer_id
                 WHERE $where_clause
                 ORDER BY o.$order_by $order
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get total count of orders with filters.
     *
     * @param array $args Query arguments (same as get_orders).
     * @return int Total count.
     */
    public static function get_orders_count( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'             => '',
            'search'             => '',
            'square_customer_id' => '',
            'date_from'          => '',
            'date_to'            => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $orders_table = $wpdb->prefix . 'spp_orders';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'o.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['square_customer_id'] ) ) {
            $where[] = 'o.square_customer_id = %s';
            $values[] = $args['square_customer_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(c.given_name LIKE %s OR c.family_name LIKE %s OR c.email LIKE %s OR o.square_order_id LIKE %s OR o.reference_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'o.created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'o.created_at <= %s';
            $values[] = $args['date_to'];
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $orders_table o
                 LEFT JOIN $customers_table c ON o.square_customer_id = c.square_customer_id
                 WHERE $where_clause",
                $values
            );
        } else {
            $sql = "SELECT COUNT(*)
                    FROM $orders_table o
                    LEFT JOIN $customers_table c ON o.square_customer_id = c.square_customer_id
                    WHERE $where_clause";
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get order by Square order ID.
     *
     * @param string $square_order_id Square order ID.
     * @return array|null Order data or null.
     */
    public static function get_order_by_square_id( $square_order_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_orders';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE square_order_id = %s",
                $square_order_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get order by local ID.
     *
     * @param int $order_id Local order ID.
     * @return array|null Order data or null.
     */
    public static function get_order( $order_id ) {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'spp_orders';
        $customers_table = $wpdb->prefix . 'spp_customers';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT o.*,
                 NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), '') as customer_name,
                 c.email as customer_email
                 FROM $orders_table o
                 LEFT JOIN $customers_table c ON o.square_customer_id = c.square_customer_id
                 WHERE o.id = %d",
                $order_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get orders summary by status.
     *
     * @return array Status counts.
     */
    public static function get_orders_status_summary() {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_orders';

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table GROUP BY status",
            ARRAY_A
        );

        $summary = array(
            'OPEN'      => 0,
            'COMPLETED' => 0,
            'CANCELED'  => 0,
            'DRAFT'     => 0,
        );

        foreach ( $results as $row ) {
            $status = strtoupper( $row['status'] );
            if ( isset( $summary[ $status ] ) ) {
                $summary[ $status ] = (int) $row['count'];
            }
        }

        return $summary;
    }

    /**
     * Upsert an order (insert or update).
     *
     * @param array $data Order data.
     * @return int|false The order ID on success, false on failure.
     */
    public static function upsert_order( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_orders';

        if ( empty( $data['square_order_id'] ) ) {
            return false;
        }

        $existing = self::get_order_by_square_id( $data['square_order_id'] );

        $db_data = array(
            'square_order_id'    => $data['square_order_id'],
            'square_customer_id' => $data['square_customer_id'] ?? null,
            'square_location_id' => $data['square_location_id'] ?? null,
            'reference_id'       => $data['reference_id'] ?? null,
            'status'             => $data['status'] ?? 'OPEN',
            'total_amount'       => $data['total_amount'] ?? 0,
            'total_tax'          => $data['total_tax'] ?? 0,
            'total_discount'     => $data['total_discount'] ?? 0,
            'currency'           => $data['currency'] ?? 'USD',
            'line_items'         => isset( $data['line_items'] ) ? wp_json_encode( $data['line_items'] ) : null,
            'fulfillments'       => isset( $data['fulfillments'] ) ? wp_json_encode( $data['fulfillments'] ) : null,
            'metadata'           => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
            'synced_at'          => current_time( 'mysql' ),
        );

        $format = array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' );

        if ( $existing ) {
            // Update.
            $result = $wpdb->update(
                $table,
                $db_data,
                array( 'square_order_id' => $data['square_order_id'] ),
                $format,
                array( '%s' )
            );

            return false !== $result ? $existing['id'] : false;
        } else {
            // Insert.
            $db_data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );
            $format[] = '%s';

            $result = $wpdb->insert( $table, $db_data, $format );

            return false !== $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Delete an order by Square order ID.
     *
     * @param string $square_order_id Square order ID.
     * @return bool True on success.
     */
    public static function delete_order( $square_order_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_orders';

        return false !== $wpdb->delete(
            $table,
            array( 'square_order_id' => $square_order_id ),
            array( '%s' )
        );
    }
}
