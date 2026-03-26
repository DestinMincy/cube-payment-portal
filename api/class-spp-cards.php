<?php
/**
 * Square Cards API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Cards
 *
 * Handles card tokenization and management via Square Cards API.
 */
class SPP_Cards {

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
     * Create (save) a card on file from a payment token.
     *
     * @param string $customer_id Square customer ID.
     * @param string $card_token  Card token from Web Payments SDK.
     * @param array  $billing     Optional billing address.
     * @return array|WP_Error Card data or error.
     */
    public function create_card( $customer_id, $card_token, array $billing = array() ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'source_id'       => $card_token,
            'card'            => array(
                'customer_id' => $customer_id,
            ),
        );

        if ( ! empty( $billing ) ) {
            $body['card']['billing_address'] = array(
                'address_line_1' => $billing['address_1'] ?? '',
                'address_line_2' => $billing['address_2'] ?? '',
                'locality'       => $billing['city'] ?? '',
                'administrative_district_level_1' => $billing['state'] ?? '',
                'postal_code'    => $billing['postal_code'] ?? '',
                'country'        => $billing['country'] ?? 'US',
            );
        }

        $response = $this->client->post( 'cards', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['card'] ?? $response;
    }

    /**
     * List all cards for a customer.
     *
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Array of cards or error.
     */
    public function list_cards( $customer_id ) {
        $response = $this->client->get( 'cards?customer_id=' . urlencode( $customer_id ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['cards'] ?? array();
    }

    /**
     * Get a specific card.
     *
     * @param string $card_id Card ID.
     * @return array|WP_Error Card data or error.
     */
    public function get_card( $card_id ) {
        $response = $this->client->get( 'cards/' . $card_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['card'] ?? $response;
    }

    /**
     * Disable (delete) a card.
     *
     * @param string $card_id Card ID.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function delete_card( $card_id ) {
        $response = $this->client->post( 'cards/' . $card_id . '/disable', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return true;
    }

    /**
     * Validate a card token before saving.
     *
     * @param string $token Card token.
     * @return bool True if valid.
     */
    public function validate_token( $token ) {
        // Token should be a non-empty string starting with expected prefix.
        if ( empty( $token ) || ! is_string( $token ) ) {
            return false;
        }

        // Square tokens have specific format.
        return strlen( $token ) > 10;
    }

    /**
     * Format card for display.
     *
     * @param array $card Card data from Square.
     * @return array Formatted card data.
     */
    public function format_card_for_display( array $card ) {
        return array(
            'id'         => $card['id'] ?? '',
            'brand'      => $card['card_brand'] ?? 'UNKNOWN',
            'last_four'  => $card['last_4'] ?? '****',
            'exp_month'  => $card['exp_month'] ?? '',
            'exp_year'   => $card['exp_year'] ?? '',
            'cardholder' => $card['cardholder_name'] ?? '',
        );
    }
}
