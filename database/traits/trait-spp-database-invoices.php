<?php
/**
 * Invoice database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Invoices
 *
 * Handles invoice-related database queries.
 */
trait SPP_Database_Invoices {

    /**
     * Get invoices with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Invoices.
     */
    public static function get_invoices( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id'      => 0,
            'status'       => '',
            'search'       => '',
            'date_from'    => '',
            'date_to'      => '',
            'amount_min'   => 0,
            'amount_max'   => 0,
            'order_by'     => 'id',
            'order'        => 'DESC',
            'limit'        => 20,
            'offset'       => 0,
        );

        $args = wp_parse_args( $args, $defaults );
        $invoices_table = $wpdb->prefix . 'spp_invoices';
        $users_table = $wpdb->prefix . 'users';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = "i.user_id = %d";
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            // Handle 'overdue' as a special case.
            if ( 'overdue' === $args['status'] ) {
                $where[] = "i.status = 'unpaid' AND i.due_date < CURDATE()";
            } else {
                $where[] = "i.status = %s";
                $values[] = $args['status'];
            }
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = "i.created_at >= %s";
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = "i.created_at <= %s";
            $values[] = $args['date_to'];
        }

        if ( ! empty( $args['amount_min'] ) ) {
            $where[] = "i.amount >= %f";
            $values[] = $args['amount_min'];
        }

        if ( ! empty( $args['amount_max'] ) ) {
            $where[] = "i.amount <= %f";
            $values[] = $args['amount_max'];
        }

        $where_clause = implode( ' AND ', $where );
        
        // Sanitize order by and order.
        $allowed_order_by = array( 'id', 'invoice_number', 'created_at', 'amount', 'status', 'due_date' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'id';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        // Build query with or without prepare depending on whether we have placeholders.
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT i.*, 
                 COALESCE(u.display_name, i.customer_name_square) as customer_name,
                 COALESCE(u.user_email, i.customer_email_square) as customer_email,
                 c.phone as customer_phone
                 FROM $invoices_table i
                 LEFT JOIN $users_table u ON i.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}spp_customers c ON i.square_customer_id = c.square_customer_id
                 WHERE $where_clause 
                 ORDER BY i.$order_by $order 
                 LIMIT %d OFFSET %d",
                array_merge( $values, array( $args['limit'], $args['offset'] ) )
            );
        } else {
            // No placeholders - just prepare the LIMIT and OFFSET.
            $sql = $wpdb->prepare(
                "SELECT i.*, 
                 COALESCE(u.display_name, i.customer_name_square) as customer_name,
                 COALESCE(u.user_email, i.customer_email_square) as customer_email,
                 c.phone as customer_phone
                 FROM $invoices_table i
                 LEFT JOIN $users_table u ON i.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}spp_customers c ON i.square_customer_id = c.square_customer_id
                 WHERE $where_clause 
                 ORDER BY i.$order_by $order 
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get single invoice by ID.
     *
     * @param int $invoice_id Local invoice ID.
     * @return array|null Invoice data or null.
     */
    public static function get_invoice_by_id( $invoice_id ) {
        global $wpdb;

        $invoices_table = $wpdb->prefix . 'spp_invoices';
        $users_table = $wpdb->prefix . 'users';

        $sql = $wpdb->prepare(
            "SELECT i.*, 
             COALESCE(u.display_name, i.customer_name_square) as customer_name,
             COALESCE(u.user_email, i.customer_email_square) as customer_email
             FROM $invoices_table i
             LEFT JOIN $users_table u ON i.user_id = u.ID
             WHERE i.id = %d",
            $invoice_id
        );

        return $wpdb->get_row( $sql, ARRAY_A );
    }

    /**
     * Get total count of invoices with filters.
     *
     * @param array $args Query arguments (same as get_invoices).
     * @return int Total count.
     */
    public static function get_invoices_count( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id'    => 0,
            'status'     => '',
            'search'     => '',
            'date_from'  => '',
            'date_to'    => '',
            'amount_min' => 0,
            'amount_max' => 0,
        );

        $args = wp_parse_args( $args, $defaults );
        $invoices_table = $wpdb->prefix . 'spp_invoices';
        $users_table = $wpdb->prefix . 'users';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = "i.user_id = %d";
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            if ( 'overdue' === $args['status'] ) {
                $where[] = "i.status = 'unpaid' AND i.due_date < CURDATE()";
            } else {
                $where[] = "i.status = %s";
                $values[] = $args['status'];
            }
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = "i.created_at >= %s";
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = "i.created_at <= %s";
            $values[] = $args['date_to'];
        }

        if ( ! empty( $args['amount_min'] ) ) {
            $where[] = "i.amount >= %f";
            $values[] = $args['amount_min'];
        }

        if ( ! empty( $args['amount_max'] ) ) {
            $where[] = "i.amount <= %f";
            $values[] = $args['amount_max'];
        }

        $where_clause = implode( ' AND ', $where );

        // Build query with or without prepare depending on whether we have placeholders.
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM $invoices_table i
                 LEFT JOIN $users_table u ON i.user_id = u.ID
                 WHERE $where_clause",
                $values
            );
        } else {
            // No placeholders - direct query.
            $sql = "SELECT COUNT(*) 
                    FROM $invoices_table i
                    LEFT JOIN $users_table u ON i.user_id = u.ID
                    WHERE $where_clause";
        }

        return (int) $wpdb->get_var( $sql );
    }
}
