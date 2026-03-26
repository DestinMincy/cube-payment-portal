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
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
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
     * Process the payment.
     *
     * @param int $order_id Order ID.
     * @return array Result array.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Process a refund.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return true;
    }
}
