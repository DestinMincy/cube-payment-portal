<?php
/**
 * Transaction database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Transactions
 *
 * Handles transaction-related database queries.
 */
trait SPP_Database_Transactions {

    /**
     * Get transactions with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Transactions.
     */
    public static function get_transactions( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id'                => 0,
            'status'                 => '',
            'search'                 => '',
            'square_plan_id'         => '',
            'square_subscription_id' => '',
            'square_customer_id'     => '',
            'limit'                  => 20,
            'offset'                 => 0,
            'order_by'               => 'id',
            'order'                  => 'DESC',
            'date_from'              => '',
            'date_to'                => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $trans_table = $wpdb->prefix . 'spp_transactions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';
        $subs_table = $wpdb->prefix . 'spp_subscriptions';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 't.user_id = %d';
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 't.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['square_plan_id'] ) ) {
            $where[] = 't.square_plan_id = %s';
            $values[] = $args['square_plan_id'];
        }

        if ( ! empty( $args['square_subscription_id'] ) ) {
            $where[] = 't.square_subscription_id = %s';
            $values[] = $args['square_subscription_id'];
        }

        if ( ! empty( $args['square_customer_id'] ) ) {
            $where[] = 't.square_customer_id = %s';
            $values[] = $args['square_customer_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR c.given_name LIKE %s OR c.family_name LIKE %s OR c.email LIKE %s OR t.square_payment_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 't.created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 't.created_at <= %s';
            $values[] = $args['date_to'];
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize order by and order.
        $allowed_order_by = array( 'id', 'created_at', 'amount', 'status' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'id';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        // Build query with joins for customer info and plan name.
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT t.*,
                 COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), ''), u.display_name) as customer_name,
                 COALESCE(c.email, u.user_email) as customer_email,
                 c.phone as customer_phone,
                 s.plan_name
                 FROM $trans_table t
                 LEFT JOIN $users_table u ON t.user_id = u.ID
                 LEFT JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
                 LEFT JOIN $subs_table s ON t.square_subscription_id = s.square_subscription_id
                 WHERE $where_clause
                 ORDER BY t.$order_by $order
                 LIMIT %d OFFSET %d",
                array_merge( $values, array( $args['limit'], $args['offset'] ) )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT t.*,
                 COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), ''), u.display_name) as customer_name,
                 COALESCE(c.email, u.user_email) as customer_email,
                 c.phone as customer_phone,
                 s.plan_name
                 FROM $trans_table t
                 LEFT JOIN $users_table u ON t.user_id = u.ID
                 LEFT JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
                 LEFT JOIN $subs_table s ON t.square_subscription_id = s.square_subscription_id
                 WHERE $where_clause
                 ORDER BY t.$order_by $order
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get total count of transactions with filters.
     *
     * @param array $args Query arguments (same as get_transactions).
     * @return int Total count.
     */
    public static function get_transactions_count( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id'                => 0,
            'status'                 => '',
            'search'                 => '',
            'square_plan_id'         => '',
            'square_subscription_id' => '',
            'square_customer_id'     => '',
            'date_from'              => '',
            'date_to'                => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $trans_table = $wpdb->prefix . 'spp_transactions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 't.user_id = %d';
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 't.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['square_plan_id'] ) ) {
            $where[] = 't.square_plan_id = %s';
            $values[] = $args['square_plan_id'];
        }

        if ( ! empty( $args['square_subscription_id'] ) ) {
            $where[] = 't.square_subscription_id = %s';
            $values[] = $args['square_subscription_id'];
        }

        if ( ! empty( $args['square_customer_id'] ) ) {
            $where[] = 't.square_customer_id = %s';
            $values[] = $args['square_customer_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR c.given_name LIKE %s OR c.family_name LIKE %s OR c.email LIKE %s OR t.square_payment_id LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 't.created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 't.created_at <= %s';
            $values[] = $args['date_to'];
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $trans_table t
                 LEFT JOIN $users_table u ON t.user_id = u.ID
                 LEFT JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
                 WHERE $where_clause",
                $values
            );
        } else {
            $sql = "SELECT COUNT(*)
                    FROM $trans_table t
                    LEFT JOIN $users_table u ON t.user_id = u.ID
                    LEFT JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
                    WHERE $where_clause";
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get revenue summary.
     *
     * @param string $date_from Start date.
     * @param string $date_to   End date.
     * @return array Revenue data.
     */
    public static function get_revenue_summary( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_transactions';

        $where = "status = 'completed'";
        $values = array();

        if ( ! empty( $date_from ) ) {
            $where .= ' AND created_at >= %s';
            $values[] = $date_from;
        }

        if ( ! empty( $date_to ) ) {
            $where .= ' AND created_at <= %s';
            $values[] = $date_to;
        }

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT SUM(amount) as total, COUNT(*) as count FROM $table WHERE $where",
                $values
            );
        } else {
            $sql = "SELECT SUM(amount) as total, COUNT(*) as count FROM $table WHERE $where";
        }

        return $wpdb->get_row( $sql, ARRAY_A );
    }
}
