<?php
/**
 * Customer database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Customers
 *
 * Handles customer-related database queries.
 */
trait SPP_Database_Customers {

    /**
     * Get customers with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Customers.
     */
    public static function get_customers( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'limit'   => 20,
            'offset'  => 0,
            'status'  => '', // linked, unlinked, all.
            'search'  => '',
            'orderby' => 'id',
            'order'   => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'spp_customers';

        $where = '1=1';
        $values = array();

        // Filter by link status.
        if ( 'linked' === $args['status'] ) {
            $where .= ' AND c.wp_user_id IS NOT NULL';
        } elseif ( 'unlinked' === $args['status'] ) {
            $where .= ' AND c.wp_user_id IS NULL';
        }

        // Search.
        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= $wpdb->prepare(
                ' AND (c.given_name LIKE %s OR c.family_name LIKE %s OR c.email LIKE %s OR c.company_name LIKE %s)',
                $search, $search, $search, $search
            );
        }

        // Whitelist ORDER BY columns to prevent SQL injection.
        $allowed_orderby = array( 'id', 'given_name', 'family_name', 'email', 'synced_at', 'created_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
        $orderby = esc_sql( $orderby );
        $order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        // Build query.
        $sql = "SELECT c.*, 
                       u.display_name as wp_display_name,
                       u.user_email as wp_email
                FROM $table c
                LEFT JOIN {$wpdb->users} u ON c.wp_user_id = u.ID
                WHERE $where
                ORDER BY c.{$orderby} {$order}
                LIMIT %d OFFSET %d";

        $sql = $wpdb->prepare( $sql, $args['limit'], $args['offset'] );

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get customer by ID.
     *
     * @param int $customer_id Local customer ID.
     * @return array|null Customer data or null.
     */
    public static function get_customer_by_id( $customer_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'spp_customers';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, u.display_name as wp_display_name, u.user_email as wp_email
                 FROM $table c
                 LEFT JOIN {$wpdb->users} u ON c.wp_user_id = u.ID
                 WHERE c.id = %d",
                $customer_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get customer by Square ID.
     *
     * @param string $square_customer_id Square customer ID.
     * @return array|null Customer data or null.
     */
    public static function get_customer_by_square_id( $square_customer_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'spp_customers';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, u.display_name as wp_display_name, u.user_email as wp_email
                 FROM $table c
                 LEFT JOIN {$wpdb->users} u ON c.wp_user_id = u.ID
                 WHERE c.square_customer_id = %s",
                $square_customer_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get user by Square ID (helper for WP User lookup).
     *
     * @param string $square_customer_id Square customer ID.
     * @return WP_User|false User object or false.
     */
    public static function get_user_by_square_id( $square_customer_id ) {
        $users = get_users( array(
            'meta_key'   => 'spp_square_customer_id',
            'meta_value' => $square_customer_id,
            'number'     => 1,
        ) );

        if ( ! empty( $users ) ) {
            return $users[0];
        }

        return false;
    }

    /**
     * Get customers count with filters.
     *
     * @param array $args Query arguments.
     * @return int Total count.
     */
    public static function get_customers_count( array $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_customers';

        $where = '1=1';

        // Filter by link status.
        if ( ! empty( $args['status'] ) ) {
            if ( 'linked' === $args['status'] ) {
                $where .= ' AND wp_user_id IS NOT NULL';
            } elseif ( 'unlinked' === $args['status'] ) {
                $where .= ' AND wp_user_id IS NULL';
            }
        }

        // Search.
        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= $wpdb->prepare(
                ' AND (given_name LIKE %s OR family_name LIKE %s OR email LIKE %s OR company_name LIKE %s)',
                $search, $search, $search, $search
            );
        }

        $sql = "SELECT COUNT(*) FROM $table WHERE $where";

        return (int) $wpdb->get_var( $sql );
    }
}