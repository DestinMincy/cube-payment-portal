<?php
/**
 * Subscription database operations trait.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Subscriptions
 *
 * Handles subscription-related database queries.
 */
trait SPP_Database_Subscriptions {

    /**
     * Get subscriptions with optional filters.
     *
     * @param array $args Query arguments.
     * @return array Subscriptions.
     */
    public static function get_subscriptions( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id'  => 0,
            'status'   => '',
            'plan_id'  => '',
            'search'   => '',
            'limit'    => 20,
            'offset'   => 0,
            'order_by' => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );
        $subs_table = $wpdb->prefix . 'spp_subscriptions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 's.user_id = %d';
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 's.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['plan_id'] ) ) {
            $where[] = 's.square_plan_id = %s';
            $values[] = $args['plan_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR s.plan_name LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize order by and order.
        $allowed_order_by = array( 'created_at', 'amount', 'status', 'start_date', 'next_billing_date', 'plan_name' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'created_at';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        // Build query with user join for customer info.
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT s.*,
                 COALESCE(u.display_name, NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), '')) as customer_name,
                 COALESCE(u.user_email, c.email) as customer_email,
                 c.phone as customer_phone
                 FROM $subs_table s
                 LEFT JOIN $users_table u ON s.user_id = u.ID
                 LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
                 WHERE $where_clause
                 ORDER BY s.$order_by $order
                 LIMIT %d OFFSET %d",
                array_merge( $values, array( $args['limit'], $args['offset'] ) )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT s.*,
                 COALESCE(u.display_name, NULLIF(TRIM(CONCAT(COALESCE(c.given_name, ''), ' ', COALESCE(c.family_name, ''))), '')) as customer_name,
                 COALESCE(u.user_email, c.email) as customer_email
                 FROM $subs_table s
                 LEFT JOIN $users_table u ON s.user_id = u.ID
                 LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
                 WHERE $where_clause
                 ORDER BY s.$order_by $order
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get subscriptions count with filters.
     *
     * @param array $args Query arguments.
     * @return int Total count.
     */
    public static function get_subscriptions_count( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'user_id' => 0,
            'status'  => '',
            'plan_id' => '',
            'search'  => '',
        );

        $args = wp_parse_args( $args, $defaults );
        $subs_table = $wpdb->prefix . 'spp_subscriptions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 's.user_id = %d';
            $values[] = $args['user_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 's.status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['plan_id'] ) ) {
            $where[] = 's.square_plan_id = %s';
            $values[] = $args['plan_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR s.plan_name LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where_clause = implode( ' AND ', $where );

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $subs_table s
                 LEFT JOIN $users_table u ON s.user_id = u.ID
                 LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
                 WHERE $where_clause",
                $values
            );
        } else {
            $sql = "SELECT COUNT(*)
                    FROM $subs_table s
                    LEFT JOIN $users_table u ON s.user_id = u.ID
                    LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
                    WHERE $where_clause";
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get single subscription by ID.
     *
     * @param int $subscription_id Local subscription ID.
     * @return array|null Subscription data or null.
     */
    public static function get_subscription_by_id( $subscription_id ) {
        global $wpdb;

        $subs_table = $wpdb->prefix . 'spp_subscriptions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $sql = $wpdb->prepare(
            "SELECT s.*,
             COALESCE(u.display_name, CONCAT(c.given_name, ' ', c.family_name)) as customer_name,
             COALESCE(u.user_email, c.email) as customer_email,
             c.square_customer_id
             FROM $subs_table s
             LEFT JOIN $users_table u ON s.user_id = u.ID
             LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
             WHERE s.id = %d",
            $subscription_id
        );

        return $wpdb->get_row( $sql, ARRAY_A );
    }

    /**
     * Get subscription by Square subscription ID.
     *
     * @param string $square_subscription_id Square subscription ID.
     * @return array|null Subscription data or null.
     */
    public static function get_subscription_by_square_id( $square_subscription_id ) {
        global $wpdb;

        $subs_table = $wpdb->prefix . 'spp_subscriptions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        $sql = $wpdb->prepare(
            "SELECT s.*,
             COALESCE(u.display_name, CONCAT(c.given_name, ' ', c.family_name)) as customer_name,
             COALESCE(u.user_email, c.email) as customer_email,
             c.square_customer_id
             FROM $subs_table s
             LEFT JOIN $users_table u ON s.user_id = u.ID
             LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id
             WHERE s.square_subscription_id = %s",
            $square_subscription_id
        );

        return $wpdb->get_row( $sql, ARRAY_A );
    }

    /**
     * Get subscriptions by plan ID.
     *
     * @param string $plan_id Square plan ID.
     * @param array  $args    Query arguments.
     * @return array Subscriptions.
     */
    public static function get_subscriptions_by_plan_id( $plan_id, array $args = array() ) {
        $args['plan_id'] = $plan_id;
        return self::get_subscriptions( $args );
    }

    /**
     * Get subscriptions by multiple variation IDs.
     *
     * Since subscriptions store plan_variation_id (not plan_id) in square_plan_id column,
     * this method allows querying by all variation IDs belonging to a plan.
     *
     * @param array $variation_ids Array of Square variation IDs.
     * @param array $args          Query arguments.
     * @return array Subscriptions.
     */
    public static function get_subscriptions_by_variation_ids( array $variation_ids, array $args = array() ) {
        global $wpdb;

        if ( empty( $variation_ids ) ) {
            return array();
        }

        $defaults = array(
            'status'   => '',
            'order_by' => 'created_at',
            'order'    => 'DESC',
            'limit'    => 100,
            'offset'   => 0,
        );

        $args = wp_parse_args( $args, $defaults );
        $subs_table = $wpdb->prefix . 'spp_subscriptions';
        $users_table = $wpdb->prefix . 'users';
        $customers_table = $wpdb->prefix . 'spp_customers';

        // Build IN clause for variation IDs.
        $placeholders = implode( ', ', array_fill( 0, count( $variation_ids ), '%s' ) );
        
        $where = array( "s.square_plan_id IN ($placeholders)" );
        $values = $variation_ids;

        if ( ! empty( $args['status'] ) ) {
            $where[] = 's.status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize order by and order.
        $allowed_order_by = array( 'created_at', 'amount', 'status', 'start_date', 'next_billing_date', 'plan_name' );
        $order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'created_at';
        $order_by = esc_sql( $order_by );
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $order = esc_sql( $order );

        $values[] = $args['limit'];
        $values[] = $args['offset'];

        $sql = $wpdb->prepare(
            "SELECT s.*,
             COALESCE(u.display_name, CONCAT(c.given_name, ' ', c.family_name), CONCAT(c2.given_name, ' ', c2.family_name), 'Unknown Customer') as customer_name,
             COALESCE(u.user_email, c.email, c2.email) as customer_email
             FROM $subs_table s
             LEFT JOIN $users_table u ON s.user_id = u.ID AND s.user_id > 0
             LEFT JOIN $customers_table c ON s.user_id = c.wp_user_id AND s.user_id > 0
             LEFT JOIN $customers_table c2 ON s.square_customer_id = c2.square_customer_id
             WHERE $where_clause
             ORDER BY s.$order_by $order
             LIMIT %d OFFSET %d",
            $values
        );

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get subscription statistics.
     *
     * Returns aggregate stats including active count, MRR, and churn rate.
     *
     * @return array Statistics (active_count, paused_count, canceled_count, mrr, churn_rate).
     */
    public static function get_subscription_stats() {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_subscriptions';

        // Get counts by status.
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count, SUM(amount) as total_amount
             FROM $table
             GROUP BY status",
            ARRAY_A
        );

        $stats = array(
            'active_count'   => 0,
            'paused_count'   => 0,
            'canceled_count' => 0,
            'pending_count'  => 0,
            'total_count'    => 0,
            'mrr'            => 0.00,
            'churn_30_days'  => 0,
            'churn_rate'     => 0.00,
        );

        foreach ( $status_counts as $row ) {
            $count = (int) $row['count'];
            $stats['total_count'] += $count;

            switch ( strtolower( $row['status'] ) ) {
                case 'active':
                    $stats['active_count'] = $count;
                    $stats['mrr'] = (float) $row['total_amount'];
                    break;
                case 'paused':
                    $stats['paused_count'] = $count;
                    break;
                case 'canceled':
                    $stats['canceled_count'] = $count;
                    break;
                case 'pending':
                    $stats['pending_count'] = $count;
                    break;
            }
        }

        // Get churn in last 30 days.
        $thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $churn_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                 WHERE status = 'canceled'
                 AND canceled_at >= %s",
                $thirty_days_ago
            )
        );
        $stats['churn_30_days'] = (int) $churn_count;

        // Calculate churn rate.
        $base_count = $stats['active_count'] + $stats['churn_30_days'];
        if ( $base_count > 0 ) {
            $stats['churn_rate'] = round( ( $stats['churn_30_days'] / $base_count ) * 100, 2 );
        }

        return $stats;
    }

    /**
     * Get subscribers count per plan.
     *
     * @return array Plan ID => subscriber data mapping.
     */
    public static function get_subscribers_per_plan() {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_subscriptions';

        $results = $wpdb->get_results(
            "SELECT square_plan_id, plan_name, COUNT(*) as count, SUM(amount) as revenue
             FROM $table
             WHERE status = 'active'
             GROUP BY square_plan_id, plan_name",
            ARRAY_A
        );

        $plans = array();
        foreach ( $results as $row ) {
            $plans[ $row['square_plan_id'] ] = array(
                'plan_id'     => $row['square_plan_id'],
                'plan_name'   => $row['plan_name'],
                'subscribers' => (int) $row['count'],
                'revenue'     => (float) $row['revenue'],
            );
        }

        return $plans;
    }

    /**
     * Insert or update a subscription record.
     *
     * @param array $data Subscription data.
     * @return int|false Insert ID or false on failure.
     */
    public static function upsert_subscription( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'spp_subscriptions';

        // Check if exists by Square subscription ID.
        $existing = null;
        if ( ! empty( $data['square_subscription_id'] ) ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE square_subscription_id = %s",
                    $data['square_subscription_id']
                )
            );
        }

        $insert_data = array(
            'user_id'                => $data['user_id'] ?? 0,
            'square_subscription_id' => $data['square_subscription_id'] ?? '',
            'square_customer_id'     => $data['square_customer_id'] ?? '',
            'square_plan_id'         => $data['square_plan_id'] ?? '',
            'plan_name'              => $data['plan_name'] ?? '',
            'amount'                 => $data['amount'] ?? 0,
            'currency'               => $data['currency'] ?? 'USD',
            'cadence'                => $data['cadence'] ?? 'MONTHLY',
            'status'                 => $data['status'] ?? 'active',
            'start_date'             => $data['start_date'] ?? null,
            'next_billing_date'      => $data['next_billing_date'] ?? null,
            'canceled_at'            => $data['canceled_at'] ?? null,
        );

        $format = array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $existing ) {
            $wpdb->update(
                $table,
                $insert_data,
                array( 'id' => $existing->id ),
                $format,
                array( '%d' )
            );
            return $existing->id;
        } else {
            $insert_data['created_at'] = current_time( 'mysql' );
            $format[] = '%s';
            $wpdb->insert( $table, $insert_data, $format );
            return $wpdb->insert_id;
        }
    }
}
