<?php
/**
 * Square Gift Cards API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Gift_Cards
 *
 * Handles gift card management via Square Gift Cards API.
 */
class SPP_Gift_Cards {

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
     * List gift cards.
     *
     * @param array $args Query arguments.
     * @return array|WP_Error Gift cards or error.
     */
    public function list_gift_cards( array $args = array() ) {
        $query_params = array();

        if ( ! empty( $args['type'] ) ) {
            $query_params['type'] = $args['type'];
        }

        if ( ! empty( $args['state'] ) ) {
            $query_params['state'] = $args['state'];
        }

        if ( ! empty( $args['limit'] ) ) {
            $query_params['limit'] = $args['limit'];
        }

        if ( ! empty( $args['cursor'] ) ) {
            $query_params['cursor'] = $args['cursor'];
        }

        $query_string = ! empty( $query_params ) ? '?' . http_build_query( $query_params ) : '';
        $response = $this->client->get( 'gift-cards' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Get active gift cards linked to a specific customer.
     *
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Array of gift cards or error.
     */
    public function get_customer_gift_cards( $customer_id ) {
        $result = $this->list_gift_cards( array( 'state' => 'ACTIVE' ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $all_cards = $result['gift_cards'] ?? array();
        $customer_cards = array();

        foreach ( $all_cards as $card ) {
            if ( ! empty( $card['customer_ids'] ) && in_array( $customer_id, $card['customer_ids'], true ) ) {
                $customer_cards[] = $card;
            }
        }

        return $customer_cards;
    }

    /**
     * Retrieve a gift card by ID.
     *
     * @param string $gift_card_id Gift card ID.
     * @return array|WP_Error Gift card or error.
     */
    public function get_gift_card( $gift_card_id ) {
        $response = $this->client->get( 'gift-cards/' . $gift_card_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card'] ?? $response;
    }

    /**
     * Retrieve a gift card by GAN (Gift Card Account Number).
     *
     * @param string $gan Gift card account number.
     * @return array|WP_Error Gift card or error.
     */
    public function get_gift_card_by_gan( $gan ) {
        $body = array(
            'gan' => $gan,
        );

        $response = $this->client->post( 'gift-cards/from-gan', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card'] ?? $response;
    }

    /**
     * Create a gift card.
     *
     * @param string $location_id Location ID.
     * @param string $type        Gift card type (DIGITAL or PHYSICAL).
     * @return array|WP_Error Created gift card or error.
     */
    public function create_gift_card( $location_id, $type = 'DIGITAL' ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'location_id'     => $location_id,
            'gift_card'       => array(
                'type' => $type,
            ),
        );

        $response = $this->client->post( 'gift-cards', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card'] ?? $response;
    }

    /**
     * Activate a gift card.
     *
     * @param string $gift_card_id            Gift card ID.
     * @param int    $amount                  Amount in cents.
     * @param string $location_id             Location ID.
     * @param string $currency                Currency code.
     * @param string $buyer_payment_instrument_id Optional payment instrument (card nonce or card ID).
     * @return array|WP_Error Activated gift card or error.
     */
    public function activate_gift_card( $gift_card_id, $amount, $location_id, $currency = 'USD', $buyer_payment_instrument_id = '' ) {
        // For sandbox mode without a payment instrument, use the test card nonce.
        $is_sandbox = get_option( 'spp_environment', 'sandbox' ) === 'sandbox';
        if ( empty( $buyer_payment_instrument_id ) && $is_sandbox ) {
            // Square sandbox test card nonce.
            $buyer_payment_instrument_id = 'cnon:card-nonce-ok';
        }

        // Build activity details.
        $activate_details = array(
            'amount_money' => array(
                'amount'   => $amount,
                'currency' => $currency,
            ),
        );

        // Add buyer payment instrument if provided.
        if ( ! empty( $buyer_payment_instrument_id ) ) {
            $activate_details['buyer_payment_instrument_ids'] = array( $buyer_payment_instrument_id );
        }

        $body = array(
            'idempotency_key'    => wp_generate_uuid4(),
            'gift_card_activity' => array(
                'gift_card_id'               => $gift_card_id,
                'type'                       => 'ACTIVATE',
                'location_id'                => $location_id,
                'activate_activity_details'  => $activate_details,
            ),
        );

        $response = $this->client->post( 'gift-cards/activities', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card_activity'] ?? $response;
    }

    /**
     * Load (add funds to) a gift card.
     *
     * @param string $gift_card_id            Gift card ID.
     * @param int    $amount                  Amount in cents.
     * @param string $location_id             Location ID.
     * @param string $currency                Currency code.
     * @param string $buyer_payment_instrument_id Optional payment instrument (card nonce or card ID).
     * @return array|WP_Error Gift card activity or error.
     */
    public function load_gift_card( $gift_card_id, $amount, $location_id, $currency = 'USD', $buyer_payment_instrument_id = '' ) {
        // For sandbox mode without a payment instrument, use the test card nonce.
        $is_sandbox = get_option( 'spp_environment', 'sandbox' ) === 'sandbox';
        if ( empty( $buyer_payment_instrument_id ) && $is_sandbox ) {
            // Square sandbox test card nonce.
            $buyer_payment_instrument_id = 'cnon:card-nonce-ok';
        }

        // Build load details.
        $load_details = array(
            'amount_money' => array(
                'amount'   => $amount,
                'currency' => $currency,
            ),
        );

        // Add buyer payment instrument if provided.
        if ( ! empty( $buyer_payment_instrument_id ) ) {
            $load_details['buyer_payment_instrument_ids'] = array( $buyer_payment_instrument_id );
        }

        $body = array(
            'idempotency_key'    => wp_generate_uuid4(),
            'gift_card_activity' => array(
                'gift_card_id'          => $gift_card_id,
                'type'                  => 'LOAD',
                'location_id'           => $location_id,
                'load_activity_details' => $load_details,
            ),
        );

        $response = $this->client->post( 'gift-cards/activities', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card_activity'] ?? $response;
    }

    /**
     * Redeem (deduct funds from) a gift card.
     *
     * @param string $gift_card_id Gift card ID.
     * @param int    $amount       Amount in cents.
     * @param string $location_id  Location ID.
     * @param string $currency     Currency code.
     * @return array|WP_Error Gift card activity or error.
     */
    public function redeem_gift_card( $gift_card_id, $amount, $location_id, $currency = 'USD' ) {
        $body = array(
            'idempotency_key'    => wp_generate_uuid4(),
            'gift_card_activity' => array(
                'gift_card_id'            => $gift_card_id,
                'type'                    => 'REDEEM',
                'location_id'             => $location_id,
                'redeem_activity_details' => array(
                    'amount_money' => array(
                        'amount'   => $amount,
                        'currency' => $currency,
                    ),
                ),
            ),
        );

        $response = $this->client->post( 'gift-cards/activities', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card_activity'] ?? $response;
    }

    /**
     * Link a customer to a gift card.
     *
     * @param string $gift_card_id Gift card ID.
     * @param string $customer_id  Customer ID.
     * @return array|WP_Error Gift card or error.
     */
    public function link_customer( $gift_card_id, $customer_id ) {
        $body = array(
            'customer_id' => $customer_id,
        );

        $response = $this->client->post( 'gift-cards/' . $gift_card_id . '/link-customer', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card'] ?? $response;
    }

    /**
     * Unlink a customer from a gift card.
     *
     * @param string $gift_card_id Gift card ID.
     * @param string $customer_id  Customer ID.
     * @return array|WP_Error Gift card or error.
     */
    public function unlink_customer( $gift_card_id, $customer_id ) {
        $body = array(
            'customer_id' => $customer_id,
        );

        $response = $this->client->post( 'gift-cards/' . $gift_card_id . '/unlink-customer', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card'] ?? $response;
    }

    /**
     * Retrieve gift card activity.
     *
     * @param string $gift_card_id Gift card ID (optional).
     * @param int    $limit        Number of activities.
     * @param string $cursor       Pagination cursor.
     * @return array|WP_Error Activities or error.
     */
    public function list_activities( $gift_card_id = null, $limit = 50, $cursor = null, $begin_time = null, $end_time = null ) {
        $query_params = array( 'limit' => $limit );

        if ( $gift_card_id ) {
            $query_params['gift_card_id'] = $gift_card_id;
        }

        if ( $cursor ) {
            $query_params['cursor'] = $cursor;
        }

        if ( $begin_time ) {
            $query_params['begin_time'] = $begin_time;
        }

        if ( $end_time ) {
            $query_params['end_time'] = $end_time;
        }

        $query_string = http_build_query( $query_params );
        $response = $this->client->get( 'gift-cards/activities?' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Deactivate a gift card.
     *
     * @param string $gift_card_id Gift card ID.
     * @param string $location_id  Location ID.
     * @return array|WP_Error Deactivated gift card or error.
     */
    public function deactivate_gift_card( $gift_card_id, $location_id ) {
        $body = array(
            'idempotency_key'            => wp_generate_uuid4(),
            'gift_card_activity'         => array(
                'gift_card_id'               => $gift_card_id,
                'type'                       => 'DEACTIVATE',
                'location_id'                => $location_id,
                'deactivate_activity_details' => array(
                    'reason' => 'SUSPICIOUS_ACTIVITY',
                ),
            ),
        );

        $response = $this->client->post( 'gift-cards/activities', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['gift_card_activity'] ?? $response;
    }
}

