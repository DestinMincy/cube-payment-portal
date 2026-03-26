<?php
/**
 * Internationalization functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_i18n
 *
 * Loads and defines the internationalization files for this plugin.
 */
class SPP_i18n {

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'cube-payment-portal',
            false,
            dirname( SPP_PLUGIN_BASENAME ) . '/languages/'
        );
    }
}
