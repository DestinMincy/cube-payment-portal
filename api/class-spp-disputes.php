<?php
/**
 * Square Disputes API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Disputes
 *
 * Handles dispute management via Square Disputes API.
 */
class SPP_Disputes {

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
     * List disputes.
     *
     * @param array $args Query arguments.
     * @return array|WP_Error Disputes or error.
     */
    public function list_disputes( array $args = array() ) {
        $query_params = array();

        if ( ! empty( $args['location_id'] ) ) {
            $query_params['location_id'] = $args['location_id'];
        }

        if ( ! empty( $args['states'] ) ) {
            // States should be comma-separated.
            $query_params['states'] = is_array( $args['states'] ) ? implode( ',', $args['states'] ) : $args['states'];
        }

        if ( ! empty( $args['cursor'] ) ) {
            $query_params['cursor'] = $args['cursor'];
        }

        $query_string = ! empty( $query_params ) ? '?' . http_build_query( $query_params ) : '';
        $response = $this->client->get( 'disputes' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Retrieve a dispute by ID.
     *
     * @param string $dispute_id Dispute ID.
     * @return array|WP_Error Dispute or error.
     */
    public function get_dispute( $dispute_id ) {
        $response = $this->client->get( 'disputes/' . $dispute_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['dispute'] ?? $response;
    }

    /**
     * Accept a dispute (lose the dispute, refund the buyer).
     *
     * @param string $dispute_id Dispute ID.
     * @return array|WP_Error Result or error.
     */
    public function accept_dispute( $dispute_id ) {
        $response = $this->client->post( 'disputes/' . $dispute_id . '/accept', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['dispute'] ?? $response;
    }

    /**
     * List evidence for a dispute.
     *
     * @param string $dispute_id Dispute ID.
     * @param string $cursor     Pagination cursor.
     * @return array|WP_Error Evidence or error.
     */
    public function list_evidence( $dispute_id, $cursor = null ) {
        $query_string = $cursor ? '?cursor=' . urlencode( $cursor ) : '';
        $response = $this->client->get( 'disputes/' . $dispute_id . '/evidence' . $query_string );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Create evidence text for a dispute.
     *
     * @param string $dispute_id    Dispute ID.
     * @param string $evidence_type Evidence type.
     * @param string $evidence_text Evidence text content.
     * @return array|WP_Error Evidence or error.
     */
    public function create_evidence_text( $dispute_id, $evidence_type, $evidence_text ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'evidence_type'   => $evidence_type,
            'evidence_text'   => $evidence_text,
        );

        $response = $this->client->post( 'disputes/' . $dispute_id . '/evidence-text', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['evidence'] ?? $response;
    }

    /**
     * Submit evidence for a dispute.
     *
     * @param string $dispute_id Dispute ID.
     * @return array|WP_Error Dispute or error.
     */
    public function submit_evidence( $dispute_id ) {
        $response = $this->client->post( 'disputes/' . $dispute_id . '/submit-evidence', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['dispute'] ?? $response;
    }

    /**
     * Remove evidence from a dispute.
     *
     * @param string $dispute_id  Dispute ID.
     * @param string $evidence_id Evidence ID.
     * @return bool|WP_Error True on success or error.
     */
    public function remove_evidence( $dispute_id, $evidence_id ) {
        $response = $this->client->delete( 'disputes/' . $dispute_id . '/evidence/' . $evidence_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return true;
    }

    /**
     * Get dispute state display name.
     *
     * @param string $state Dispute state.
     * @return string Display name.
     */
    public static function get_state_display_name( $state ) {
        return self::get_state_display( $state );
    }

    /**
     * Get dispute state display name.
     *
     * @param string $state Dispute state.
     * @return string Display name.
     */
    public static function get_state_display( $state ) {
        $states = array(
            'INQUIRY_EVIDENCE_REQUIRED' => __( 'Inquiry - Evidence Required', 'cube-payment-portal' ),
            'INQUIRY_PROCESSING'        => __( 'Inquiry - Processing', 'cube-payment-portal' ),
            'INQUIRY_CLOSED'            => __( 'Inquiry - Closed', 'cube-payment-portal' ),
            'EVIDENCE_REQUIRED'         => __( 'Evidence Required', 'cube-payment-portal' ),
            'PROCESSING'                => __( 'Processing', 'cube-payment-portal' ),
            'WON'                       => __( 'Won', 'cube-payment-portal' ),
            'LOST'                      => __( 'Lost', 'cube-payment-portal' ),
            'ACCEPTED'                  => __( 'Accepted', 'cube-payment-portal' ),
        );

        return $states[ $state ] ?? $state;
    }

    /**
     * Get dispute reason display name.
     *
     * @param string $reason Dispute reason.
     * @return string Display name.
     */
    public static function get_reason_display_name( $reason ) {
        $reasons = array(
            'AMOUNT_DIFFERS'                  => __( 'Amount Differs', 'cube-payment-portal' ),
            'CANCELLED'                       => __( 'Cancelled', 'cube-payment-portal' ),
            'DUPLICATE'                       => __( 'Duplicate', 'cube-payment-portal' ),
            'NO_KNOWLEDGE'                    => __( 'No Knowledge', 'cube-payment-portal' ),
            'NOT_AS_DESCRIBED'                => __( 'Not as Described', 'cube-payment-portal' ),
            'NOT_RECEIVED'                    => __( 'Not Received', 'cube-payment-portal' ),
            'PAID_BY_OTHER_MEANS'             => __( 'Paid by Other Means', 'cube-payment-portal' ),
            'CUSTOMER_REQUESTS_CREDIT'        => __( 'Customer Requests Credit', 'cube-payment-portal' ),
            'EMV_LIABILITY_SHIFT'             => __( 'EMV Liability Shift', 'cube-payment-portal' ),
        );

        return $reasons[ $reason ] ?? $reason;
    }

    /**
     * Get evidence types.
     *
     * @return array Evidence types.
     */
    public static function get_evidence_types() {
        return array(
            'GENERIC_EVIDENCE'                       => __( 'Generic Evidence', 'cube-payment-portal' ),
            'ONLINE_OR_APP_ACCESS_LOG'               => __( 'Online/App Access Log', 'cube-payment-portal' ),
            'AUTHORIZATION_DOCUMENTATION'            => __( 'Authorization Documentation', 'cube-payment-portal' ),
            'CANCELLATION_OR_REFUND_DOCUMENTATION'   => __( 'Cancellation/Refund Documentation', 'cube-payment-portal' ),
            'CARDHOLDER_COMMUNICATION'               => __( 'Cardholder Communication', 'cube-payment-portal' ),
            'CARDHOLDER_INFORMATION'                 => __( 'Cardholder Information', 'cube-payment-portal' ),
            'PURCHASE_ACKNOWLEDGEMENT'               => __( 'Purchase Acknowledgement', 'cube-payment-portal' ),
            'DUPLICATE_CHARGE_DOCUMENTATION'         => __( 'Duplicate Charge Documentation', 'cube-payment-portal' ),
            'PRODUCT_OR_SERVICE_DESCRIPTION'         => __( 'Product/Service Description', 'cube-payment-portal' ),
            'RECEIPT'                                => __( 'Receipt', 'cube-payment-portal' ),
            'SERVICE_RECEIVED_DOCUMENTATION'         => __( 'Service Received Documentation', 'cube-payment-portal' ),
            'PROOF_OF_DELIVERY_DOCUMENTATION'        => __( 'Proof of Delivery', 'cube-payment-portal' ),
            'RELATED_TRANSACTION_DOCUMENTATION'      => __( 'Related Transaction Documentation', 'cube-payment-portal' ),
            'REBUTTAL_EXPLANATION'                   => __( 'Rebuttal Explanation', 'cube-payment-portal' ),
            'TRACKING_NUMBER'                        => __( 'Tracking Number', 'cube-payment-portal' ),
        );
    }

    /**
     * Create evidence file for a dispute (file upload).
     *
     * Uploads a file as evidence to challenge a dispute.
     * Supported formats: PDF, JPG, PNG, TIFF, HEIC, HEIF.
     * Maximum file size: 5 MB.
     *
     * @param string $dispute_id    Dispute ID.
     * @param string $evidence_type Evidence type (e.g., 'RECEIPT', 'PROOF_OF_DELIVERY_DOCUMENTATION').
     * @param string $file_path     Absolute path to the file.
     * @param string $content_type  MIME type of the file.
     * @return array|WP_Error Evidence object or error.
     */
    public function create_evidence_file( $dispute_id, $evidence_type, $file_path, $content_type ) {
        // Validate file size (5MB limit for dispute evidence per Square docs).
        $file_size = filesize( $file_path );
        if ( $file_size > 5 * 1024 * 1024 ) {
            return new WP_Error(
                'file_too_large',
                __( 'Evidence file exceeds the 5MB limit.', 'cube-payment-portal' )
            );
        }

        // Validate content type.
        $allowed_types = array(
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/heic',
            'image/heif',
            'application/pdf',
        );

        if ( ! in_array( $content_type, $allowed_types, true ) ) {
            return new WP_Error(
                'invalid_file_type',
                __( 'Invalid file type. Allowed types: PDF, JPG, PNG, TIFF, HEIC, HEIF.', 'cube-payment-portal' )
            );
        }

        $json_data = array(
            'idempotency_key' => wp_generate_uuid4(),
            'evidence_type'   => $evidence_type,
            'content_type'    => $content_type,
        );

        $response = $this->client->request_multipart(
            'disputes/' . $dispute_id . '/evidence-files',
            $json_data,
            $file_path,
            'file'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['evidence'] ?? $response;
    }

    /**
     * Get suggested evidence types for a dispute reason.
     *
     * Based on Square's documentation, different dispute reasons
     * are best addressed with specific types of evidence.
     *
     * @param string $reason Dispute reason.
     * @return array Array of suggested evidence type keys.
     */
    public static function get_suggested_evidence_types( $reason ) {
        $evidence_map = array(
            'AMOUNT_DIFFERS' => array(
                'AUTHORIZATION_DOCUMENTATION',
            ),
            'CANCELLED' => array(
                'CANCELLATION_OR_REFUND_DOCUMENTATION',
                'SERVICE_RECEIVED_DOCUMENTATION',
                'PROOF_OF_DELIVERY_DOCUMENTATION',
                'TRACKING_NUMBER',
            ),
            'CUSTOMER_REQUESTS_CREDIT' => array(
                'PAID_BY_OTHER_MEANS',
                'CANCELLATION_OR_REFUND_DOCUMENTATION',
                'SERVICE_RECEIVED_DOCUMENTATION',
                'PROOF_OF_DELIVERY_DOCUMENTATION',
                'TRACKING_NUMBER',
            ),
            'DUPLICATE' => array(
                'DUPLICATE_CHARGE_DOCUMENTATION',
                'RELATED_TRANSACTION_DOCUMENTATION',
            ),
            'NO_KNOWLEDGE' => array(
                'AUTHORIZATION_DOCUMENTATION',
                'ONLINE_OR_APP_ACCESS_LOG',
                'RELATED_TRANSACTION_DOCUMENTATION',
            ),
            'NOT_RECEIVED' => array(
                'PROOF_OF_DELIVERY_DOCUMENTATION',
                'TRACKING_NUMBER',
                'SERVICE_RECEIVED_DOCUMENTATION',
            ),
            'NOT_AS_DESCRIBED' => array(
                'PRODUCT_OR_SERVICE_DESCRIPTION',
                'SERVICE_RECEIVED_DOCUMENTATION',
                'PROOF_OF_DELIVERY_DOCUMENTATION',
                'CANCELLATION_OR_REFUND_DOCUMENTATION',
            ),
            'PAID_BY_OTHER_MEANS' => array(
                'RELATED_TRANSACTION_DOCUMENTATION',
                'RECEIPT',
            ),
            'EMV_LIABILITY_SHIFT' => array(
                'AUTHORIZATION_DOCUMENTATION',
                'CARDHOLDER_INFORMATION',
            ),
        );

        return $evidence_map[ $reason ] ?? array( 'GENERIC_EVIDENCE' );
    }

    /**
     * Get suggested evidence types with display names.
     *
     * Returns an associative array of suggested evidence types
     * with their human-readable display names.
     *
     * @param string $reason Dispute reason.
     * @return array Associative array of type => display name.
     */
    public static function get_suggested_evidence_types_with_labels( $reason ) {
        $suggested_keys = self::get_suggested_evidence_types( $reason );
        $all_types = self::get_evidence_types();
        $result = array();

        foreach ( $suggested_keys as $key ) {
            if ( isset( $all_types[ $key ] ) ) {
                $result[ $key ] = $all_types[ $key ];
            }
        }

        return $result;
    }
}
