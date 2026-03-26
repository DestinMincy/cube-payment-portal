<?php
/**
 * Database operations for bookings.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait SPP_Database_Bookings
 */
trait SPP_Database_Bookings {

    /**
     * Create the bookings table.
     */
    public static function create_bookings_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'spp_bookings';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            square_id varchar(191) NOT NULL,
            customer_id varchar(191) NOT NULL,
            wp_user_id bigint(20) DEFAULT NULL,
            service_id varchar(191) NOT NULL,
            service_name varchar(255) DEFAULT '',
            team_member_id varchar(191) NOT NULL,
            location_id varchar(191) NOT NULL,
            start_at datetime NOT NULL,
            end_at datetime NOT NULL,
            status varchar(50) NOT NULL,
            version int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY square_id (square_id),
            KEY customer_id (customer_id),
            KEY wp_user_id (wp_user_id),
            KEY status (status),
            KEY start_at (start_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Save or update a booking.
     *
     * @param array $data Booking data.
     * @return int|false The number of rows inserted/updated, or false on error.
     */
    public static function save_booking( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_bookings';

        // Prepare data.
        $defaults = array(
            'version' => 0,
        );
        $data = wp_parse_args( $data, $defaults );

        // Map WP User ID if customer_id exists and not provided.
        if ( empty( $data['wp_user_id'] ) && ! empty( $data['customer_id'] ) ) {
            $user = self::get_user_by_square_id( $data['customer_id'] );
            if ( $user ) {
                $data['wp_user_id'] = $user->ID;
            }
        }

        // Format dates.
        if ( isset( $data['start_at'] ) ) {
            $data['start_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $data['start_at'] ) );
        }
        if ( isset( $data['end_at'] ) ) {
            $data['end_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $data['end_at'] ) );
        }
        // Handle ISO dates for created/updated if coming directly from API.
        if ( isset( $data['created_at'] ) ) {
            $data['created_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $data['created_at'] ) );
        }
        if ( isset( $data['updated_at'] ) ) {
            $data['updated_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $data['updated_at'] ) );
        }

        // Check if exists.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE square_id = %s", $data['square_id'] ) );

        if ( $exists ) {
            return $wpdb->update( $table_name, $data, array( 'square_id' => $data['square_id'] ) );
        } else {
            return $wpdb->insert( $table_name, $data );
        }
    }

    /**
     * Get bookings.
     *
     * @param array $args Query arguments.
     * @return array Array of bookings.
     */
    public static function get_bookings( $args = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_bookings';

        $defaults = array(
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'start_at',
            'order'   => 'ASC',
            'status'  => '',
            'customer_id' => '',
            'wp_user_id' => '',
            'team_member_id' => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = array();

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['customer_id'] ) ) {
            $where .= ' AND customer_id = %s';
            $params[] = $args['customer_id'];
        }

        if ( ! empty( $args['wp_user_id'] ) ) {
            $where .= ' AND wp_user_id = %d';
            $params[] = $args['wp_user_id'];
        }
        
        if ( ! empty( $args['team_member_id'] ) ) {
            $where .= ' AND team_member_id = %s';
            $params[] = $args['team_member_id'];
        }

        // Date Range (Start At).
        if ( ! empty( $args['start_at'] ) ) {
            $where .= ' AND start_at >= %s';
            $params[] = $args['start_at'];
        }
        if ( ! empty( $args['end_at'] ) ) {
            $where .= ' AND start_at <= %s';
            $params[] = $args['end_at'];
        }

        // Search.
        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= ' AND (square_id LIKE %s OR service_name LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        $allowed_orderby = array( 'id', 'square_id', 'customer_id', 'service_name', 'start_at', 'end_at', 'status', 'created_at', 'updated_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'start_at';
        $order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';
        $limit   = absint( $args['limit'] );
        $offset  = absint( $args['offset'] );

        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Get total bookings count.
     * 
     * @param array $args Query arguments.
     * @return int Count.
     */
    public static function get_bookings_count( $args = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_bookings';
        
        $where = '1=1';
        $params = array();

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        
        if ( ! empty( $args['customer_id'] ) ) {
            $where .= ' AND customer_id = %s';
            $params[] = $args['customer_id'];
        }

        if ( ! empty( $args['wp_user_id'] ) ) {
            $where .= ' AND wp_user_id = %d';
            $params[] = $args['wp_user_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= ' AND (square_id LIKE %s OR service_name LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT COUNT(*) FROM $table_name WHERE $where";
        
        if ( ! empty( $params ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Delete a booking.
     * 
     * @param string $square_id Square ID.
     * @return int|false Number of rows deleted or false.
     */
    public static function delete_booking( $square_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spp_bookings';
        return $wpdb->delete( $table_name, array( 'square_id' => $square_id ) );
    }
}
