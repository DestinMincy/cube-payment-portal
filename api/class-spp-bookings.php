<?php
/**
 * Square Bookings API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Bookings
 *
 * Handles bookings/appointments management via Square Bookings API.
 */
class SPP_Bookings {

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
     * List bookings.
     *
     * @param array $args Query arguments.
     * @return array|WP_Error Bookings or error.
     */
    public function list_bookings( array $args = array() ) {
        $query_params = array();

        if ( ! empty( $args['location_id'] ) ) {
            $query_params['location_id'] = $args['location_id'];
        }

        if ( ! empty( $args['team_member_id'] ) ) {
            $query_params['team_member_id'] = $args['team_member_id'];
        }

        if ( ! empty( $args['customer_id'] ) ) {
            $query_params['customer_id'] = $args['customer_id'];
        }

        if ( ! empty( $args['start_at_min'] ) ) {
            $query_params['start_at_min'] = $args['start_at_min'];
        }

        if ( ! empty( $args['start_at_max'] ) ) {
            $query_params['start_at_max'] = $args['start_at_max'];
        }

        if ( ! empty( $args['limit'] ) ) {
            $query_params['limit'] = $args['limit'];
        }

        if ( ! empty( $args['cursor'] ) ) {
            $query_params['cursor'] = $args['cursor'];
        }

        $query_string = ! empty( $query_params ) ? '?' . http_build_query( $query_params ) : '';
        $response = $this->client->get( 'bookings' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Retrieve a booking by ID.
     *
     * @param string $booking_id Booking ID.
     * @return array|WP_Error Booking or error.
     */
    public function get_booking( $booking_id ) {
        $response = $this->client->get( 'bookings/' . $booking_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['booking'] ?? $response;
    }

    /**
     * Create a booking.
     *
     * @param array $booking_data Booking data.
     * @return array|WP_Error Created booking or error.
     */
    public function create_booking( array $booking_data ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'booking'         => $booking_data,
        );

        $response = $this->client->post( 'bookings', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['booking'] ?? $response;
    }

    /**
     * Update a booking.
     *
     * @param string $booking_id   Booking ID.
     * @param array  $booking_data Updated booking data.
     * @return array|WP_Error Updated booking or error.
     */
    public function update_booking( $booking_id, array $booking_data ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'booking'         => $booking_data,
        );

        $response = $this->client->put( 'bookings/' . $booking_id, $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['booking'] ?? $response;
    }

    /**
     * Cancel a booking.
     *
     * @param string $booking_id     Booking ID.
     * @param int    $booking_version Booking version.
     * @return array|WP_Error Booking or error.
     */
    public function cancel_booking( $booking_id, $booking_version ) {
        $body = array(
            'idempotency_key'  => wp_generate_uuid4(),
            'booking_version' => $booking_version,
        );

        $response = $this->client->post( 'bookings/' . $booking_id . '/cancel', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['booking'] ?? $response;
    }

    /**
     * Search for availability.
     *
     * @param array $query Availability query.
     * @return array|WP_Error Availabilities or error.
     */
    public function search_availability( array $query ) {
        $body = array(
            'query' => $query,
        );

        $response = $this->client->post( 'bookings/availability/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['availabilities'] ?? array();
    }

    /**
     * List team member booking profiles.
     *
     * @param bool   $bookable_only Only return bookable team members.
     * @param string $location_id   Filter by location.
     * @param string $cursor        Pagination cursor.
     * @return array|WP_Error Team member profiles or error.
     */
    public function list_team_member_booking_profiles( $bookable_only = true, $location_id = null, $cursor = null ) {
        $query_params = array(
            'bookable_only' => $bookable_only ? 'true' : 'false',
        );

        if ( $location_id ) {
            $query_params['location_id'] = $location_id;
        }

        if ( $cursor ) {
            $query_params['cursor'] = $cursor;
        }

        $query_string = http_build_query( $query_params );
        $response = $this->client->get( 'bookings/team-member-booking-profiles?' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Retrieve a team member booking profile.
     *
     * @param string $team_member_id Team Member ID.
     * @return array|WP_Error Booking profile or error.
     */
    public function get_team_member_booking_profile( $team_member_id ) {
        $response = $this->client->get( 'bookings/team-member-booking-profiles/' . $team_member_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['team_member_booking_profile'] ?? $response;
    }

    /**
     * Update a team member booking profile.
     *
     * @param string $team_member_id Team Member ID.
     * @param array  $profile_data   Updated profile data (is_bookable, description, display_name).
     * @return array|WP_Error Updated profile or error.
     */
    public function update_team_member_booking_profile( $team_member_id, array $profile_data ) {
        $body = array(
            'team_member_booking_profile' => $profile_data,
        );

        $response = $this->client->put( 'bookings/team-member-booking-profiles/' . $team_member_id, $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['team_member_booking_profile'] ?? $response;
    }

    /**
     * Retrieve business booking profile.
     *
     * @return array|WP_Error Booking profile or error.
     */
    public function get_business_booking_profile() {
        $response = $this->client->get( 'bookings/business-booking-profile' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['business_booking_profile'] ?? $response;
    }

    /**
     * List catalog services for bookings.
     *
     * @param string $cursor Pagination cursor.
     * @return array|WP_Error Services or error.
     */
    public function list_services( $cursor = null ) {
        // Services are APPOINTMENT_SERVICE type in catalog.
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog( $this->client );
        $result = $catalog->get_appointment_services( array(
            'cursor' => $cursor,
        ) );

        return $result;
    }

    /**
     * Get booking status display name.
     *
     * @param string $status Booking status.
     * @return string Display name.
     */
    public static function get_status_display( $status ) {
        $statuses = array(
            'PENDING'            => __( 'Pending', 'cube-payment-portal' ),
            'CANCELLED_BY_CUSTOMER' => __( 'Cancelled by Customer', 'cube-payment-portal' ),
            'CANCELLED_BY_SELLER'   => __( 'Cancelled by Seller', 'cube-payment-portal' ),
            'DECLINED'           => __( 'Declined', 'cube-payment-portal' ),
            'ACCEPTED'           => __( 'Accepted', 'cube-payment-portal' ),
            'NO_SHOW'            => __( 'No Show', 'cube-payment-portal' ),
        );

        return $statuses[ $status ] ?? $status;
    }
}
