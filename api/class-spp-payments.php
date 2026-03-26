<?php
/**
 * Square Payments API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Payments
 *
 * Handles payment processing via Square Payments API.
 */
class SPP_Payments {

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
     * Validate and sanitize idempotency key.
     *
     * Square requires idempotency keys to be 1-45 characters, unique per request.
     *
     * @param string $key Idempotency key to validate.
     * @return string|WP_Error Valid key or error.
     */
    private function validate_idempotency_key( $key ) {
        // If empty, generate a new UUID.
        if ( empty( $key ) ) {
            return wp_generate_uuid4();
        }

        // Square allows 1-45 characters.
        $key = sanitize_text_field( $key );
        $length = strlen( $key );

        if ( $length < 1 || $length > 45 ) {
            return new WP_Error(
                'invalid_idempotency_key',
                __( 'Idempotency key must be between 1 and 45 characters.', 'cube-payment-portal' )
            );
        }

        // Only allow alphanumeric, hyphens, and underscores.
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
            return new WP_Error(
                'invalid_idempotency_key',
                __( 'Idempotency key contains invalid characters.', 'cube-payment-portal' )
            );
        }

        return $key;
    }

    /**
     * Create a payment.
     *
     * @param array $data Payment data.
     * @return array|WP_Error Payment data or error.
     */
    public function create_payment( array $data ) {
        // Validate idempotency key.
        $idempotency_key = $this->validate_idempotency_key( $data['idempotency_key'] ?? '' );
        if ( is_wp_error( $idempotency_key ) ) {
            return $idempotency_key;
        }

        $body = array(
            'idempotency_key' => $idempotency_key,
            'source_id'       => $data['source_id'], // Card token or card ID.
            'amount_money'    => array(
                'amount'   => $this->to_cents( $data['amount'] ),
                'currency' => $data['currency'] ?? get_option( 'spp_default_currency', 'USD' ),
            ),
            'location_id'     => $data['location_id'] ?? $this->client->get_location_id(),
        );

        if ( ! empty( $data['customer_id'] ) ) {
            $body['customer_id'] = $data['customer_id'];
        }

        if ( ! empty( $data['note'] ) ) {
            $body['note'] = $data['note'];
        }

        if ( ! empty( $data['reference_id'] ) ) {
            $body['reference_id'] = $data['reference_id'];
        }

        // Auto-complete by default.
        $body['autocomplete'] = $data['autocomplete'] ?? true;

        $response = $this->client->post( 'payments', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $payment = $response['payment'] ?? $response;

        // Log transaction locally.
        $this->log_transaction( $payment, $data );

        return $payment;
    }

    /**
     * Get a payment by ID.
     *
     * @param string $payment_id Square payment ID.
     * @return array|WP_Error Payment data or error.
     */
    public function get_payment( $payment_id ) {
        $response = $this->client->get( 'payments/' . $payment_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['payment'] ?? $response;
    }

    /**
     * Complete (capture) an authorized payment.
     *
     * @param string $payment_id Payment ID.
     * @return array|WP_Error Payment data or error.
     */
    public function complete_payment( $payment_id ) {
        $response = $this->client->post( 'payments/' . $payment_id . '/complete', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['payment'] ?? $response;
    }

    /**
     * Cancel a payment.
     *
     * @param string $payment_id Payment ID.
     * @return array|WP_Error Payment data or error.
     */
    public function cancel_payment( $payment_id ) {
        $response = $this->client->post( 'payments/' . $payment_id . '/cancel', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['payment'] ?? $response;
    }

    /**
     * Create a refund.
     *
     * @param string $payment_id Payment ID to refund.
     * @param float  $amount     Refund amount (null for full refund).
     * @param string $reason     Refund reason.
     * @return array|WP_Error Refund data or error.
     */
    public function create_refund( $payment_id, $amount = null, $reason = '' ) {
        $payment = $this->get_payment( $payment_id );

        if ( is_wp_error( $payment ) ) {
            return $payment;
        }

        $refund_amount = $amount !== null 
            ? $this->to_cents( $amount ) 
            : $payment['amount_money']['amount'];

        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'payment_id'      => $payment_id,
            'amount_money'    => array(
                'amount'   => $refund_amount,
                'currency' => $payment['amount_money']['currency'],
            ),
        );

        if ( ! empty( $reason ) ) {
            $body['reason'] = $reason;
        }

        $response = $this->client->post( 'refunds', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['refund'] ?? $response;
    }

    /**
     * Convert dollars to cents.
     *
     * @param float $amount Amount in dollars.
     * @return int Amount in cents.
     */
    public function to_cents( $amount ) {
        return (int) round( floatval( $amount ) * 100 );
    }

    /**
     * Convert cents to dollars.
     *
     * @param int $cents Amount in cents.
     * @return float Amount in dollars.
     */
    public function from_cents( $cents ) {
        return floatval( $cents ) / 100;
    }

    /**
     * Log transaction to local database.
     *
     * @param array $payment     Payment data from Square.
     * @param array $local_data  Local payment data.
     */
    private function log_transaction( array $payment, array $local_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spp_transactions';

        $card_details = $payment['card_details'] ?? array();

        // Extract plan and subscription IDs from local data or metadata.
        $square_plan_id = $local_data['square_plan_id'] ?? '';
        $square_subscription_id = $local_data['square_subscription_id'] ?? '';

        // Fallback to metadata if not provided directly.
        if ( empty( $square_plan_id ) && ! empty( $local_data['metadata']['plan_id'] ) ) {
            $square_plan_id = $local_data['metadata']['plan_id'];
        }
        if ( empty( $square_subscription_id ) && ! empty( $local_data['metadata']['subscription_id'] ) ) {
            $square_subscription_id = $local_data['metadata']['subscription_id'];
        }

        $wpdb->insert(
            $table_name,
            array(
                'user_id'                => $local_data['user_id'] ?? 0,
                'square_customer_id'     => $payment['customer_id'] ?? '',
                'square_payment_id'      => $payment['id'],
                'square_plan_id'         => $square_plan_id,
                'square_subscription_id' => $square_subscription_id,
                'amount'                 => $this->from_cents( $payment['amount_money']['amount'] ),
                'currency'               => $payment['amount_money']['currency'],
                'status'                 => strtolower( $payment['status'] ),
                'card_last_four'         => $card_details['card']['last_4'] ?? '',
                'card_brand'             => $card_details['card']['card_brand'] ?? '',
                'source_type'            => $local_data['source_type'] ?? 'one_time',
                'source_id'              => $local_data['source_id'] ?? '',
                'created_at'             => current_time( 'mysql' ),
                'metadata'               => wp_json_encode( $local_data['metadata'] ?? array() ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }
}
