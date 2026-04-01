<?php
/**
 * WooCommerce Payment Gateway.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_WC_Gateway
 *
 * WooCommerce payment gateway integration.
 */
class SPP_WC_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'spp_square';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'Cube Payment Portal', 'cube-payment-portal' );
        $this->method_description = __( 'Accept payments via Square.', 'cube-payment-portal' );
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Load settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Hooks.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Initialize gateway form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Enable/Disable', 'cube-payment-portal' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Cube Payment Portal', 'cube-payment-portal' ),
                'default' => 'no',
            ),
            'title'       => array(
                'title'       => __( 'Title', 'cube-payment-portal' ),
                'type'        => 'text',
                'description' => __( 'Payment method title shown at checkout.', 'cube-payment-portal' ),
                'default'     => __( 'Credit Card (Square)', 'cube-payment-portal' ),
            ),
            'description' => array(
                'title'       => __( 'Description', 'cube-payment-portal' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description shown at checkout.', 'cube-payment-portal' ),
                'default'     => __( 'Pay securely with your credit card.', 'cube-payment-portal' ),
            ),
        );
    }

    /**
     * Output payment fields on checkout.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }

        echo '<div id="spp-card-container"></div>';
        echo '<input type="hidden" name="spp_card_nonce" id="spp-card-nonce" value="">';
    }

    /**
     * Validate payment fields before processing.
     *
     * @return bool True if fields are valid.
     */
    public function validate_fields() {
        $card_nonce = isset( $_POST['spp_card_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_card_nonce'] ) ) : '';

        if ( empty( $card_nonce ) ) {
            wc_add_notice(
                __( 'Payment error: Card information is required. Please enter your card details.', 'cube-payment-portal' ),
                'error'
            );
            return false;
        }

        return true;
    }

    /**
     * Process the payment.
     *
     * @param int $order_id Order ID.
     * @return array Result array.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Payment error: Order not found.', 'cube-payment-portal' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $card_nonce = isset( $_POST['spp_card_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_card_nonce'] ) ) : '';

        if ( empty( $card_nonce ) ) {
            wc_add_notice( __( 'Payment error: Card information is required.', 'cube-payment-portal' ), 'error' );
            return array( 'result' => 'fail' );
        }

        // Build idempotency key from order ID + attempt timestamp (prevents double-charges on retry).
        $idempotency_key = substr( 'wc-' . $order_id . '-' . time(), 0, 45 );

        $payment_data = array(
            'idempotency_key' => $idempotency_key,
            'source_id'       => $card_nonce,
            'amount'          => $order->get_total(),
            'currency'        => get_woocommerce_currency(),
            'note'            => sprintf(
                /* translators: %s: WooCommerce order number */
                __( 'WooCommerce Order #%s', 'cube-payment-portal' ),
                $order->get_order_number()
            ),
        );

        // Link Square customer if buyer has an account.
        $user_id = $order->get_user_id();
        if ( $user_id ) {
            $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
            if ( $square_customer_id ) {
                $payment_data['customer_id'] = $square_customer_id;
            }
        }

        $payments_api = new SPP_Payments();
        $result       = $payments_api->create_payment( $payment_data );

        if ( is_wp_error( $result ) ) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message from Square */
                    __( 'Payment error: %s', 'cube-payment-portal' ),
                    $result->get_error_message()
                ),
                'error'
            );
            return array( 'result' => 'fail' );
        }

        $payment = $result['payment'] ?? array();

        // Verify Square reports a completed/approved status.
        $square_status = $payment['status'] ?? '';
        if ( ! in_array( $square_status, array( 'COMPLETED', 'APPROVED' ), true ) ) {
            wc_add_notice(
                __( 'Payment error: Transaction was not approved. Please try a different card.', 'cube-payment-portal' ),
                'error'
            );
            return array( 'result' => 'fail' );
        }

        // Mark the order as paid.
        $order->payment_complete( $payment['id'] ?? '' );

        // Store the Square payment ID for reference and refunds.
        $order->update_meta_data( '_spp_square_payment_id', $payment['id'] ?? '' );
        $order->save();

        // Reduce stock levels.
        wc_reduce_stock_levels( $order_id );

        // Empty the cart.
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Process a refund via Square.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found.', 'cube-payment-portal' ) );
        }

        $square_payment_id = $order->get_meta( '_spp_square_payment_id' );

        if ( empty( $square_payment_id ) ) {
            return new WP_Error(
                'no_payment_id',
                __( 'Cannot refund: no Square payment ID found for this order.', 'cube-payment-portal' )
            );
        }

        if ( null === $amount || $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', __( 'Refund amount must be greater than zero.', 'cube-payment-portal' ) );
        }

        $client = new SPP_Square_Client();

        $refund_data = array(
            'idempotency_key' => substr( 'refund-wc-' . $order_id . '-' . time(), 0, 45 ),
            'payment_id'      => $square_payment_id,
            'amount_money'    => array(
                'amount'   => SPP_Currency::dollars_to_cents( (float) $amount ),
                'currency' => get_woocommerce_currency(),
            ),
        );

        if ( ! empty( $reason ) ) {
            $refund_data['reason'] = sanitize_text_field( $reason );
        }

        $result = $client->post( 'refunds', $refund_data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $refund_status = $result['refund']['status'] ?? '';

        if ( in_array( $refund_status, array( 'COMPLETED', 'PENDING' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: refund amount, 2: Square refund ID */
                    __( 'Refund of %1$s submitted to Square (ID: %2$s).', 'cube-payment-portal' ),
                    wc_price( $amount ),
                    esc_html( $result['refund']['id'] ?? '' )
                )
            );
            return true;
        }

        return new WP_Error(
            'refund_failed',
            __( 'Refund was submitted but Square returned an unexpected status.', 'cube-payment-portal' )
        );
    }
}
