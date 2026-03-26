<?php
/**
 * PHPUnit bootstrap file for Cube Payment Portal tests.
 *
 * @package CubePaymentPortal
 */

// Define plugin constants for testing.
define( 'SPP_VERSION', '1.0.0' );
define( 'SPP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'SPP_PLUGIN_URL', 'http://example.com/wp-content/plugins/cube-payment-portal/' );
define( 'SPP_PLUGIN_BASENAME', 'cube-payment-portal/cube-payment-portal.php' );
define( 'SPP_MINIMUM_PHP_VERSION', '7.4' );
define( 'SPP_MINIMUM_WP_VERSION', '5.0' );
define( 'SPP_SKIP_SSL_CHECK', true );

// Load WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Check if WordPress test suite exists.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find WordPress test suite. Running in standalone mode.\n";
    
    // Define WordPress functions as stubs for standalone testing.
    if ( ! function_exists( 'add_action' ) ) {
        function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
    }
    if ( ! function_exists( 'add_filter' ) ) {
        function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
    }
    if ( ! function_exists( 'get_option' ) ) {
        function get_option( $key, $default = false ) { return $default; }
    }
    if ( ! function_exists( 'update_option' ) ) {
        function update_option( $key, $value ) { return true; }
    }
    if ( ! function_exists( 'add_option' ) ) {
        function add_option( $key, $value ) { return true; }
    }
    if ( ! function_exists( 'delete_option' ) ) {
        function delete_option( $key ) { return true; }
    }
    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $data ) { return json_encode( $data ); }
    }
    if ( ! function_exists( 'esc_html' ) ) {
        function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
    }
    if ( ! function_exists( 'esc_attr' ) ) {
        function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
    }
    if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = 'default' ) { return $text; }
    }
    if ( ! function_exists( 'esc_html__' ) ) {
        function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text ); }
    }
    if ( ! function_exists( 'wp_remote_request' ) ) {
        function wp_remote_request( $url, $args = array() ) { return array(); }
    }
    if ( ! function_exists( 'wp_remote_get' ) ) {
        function wp_remote_get( $url, $args = array() ) { return array(); }
    }
    if ( ! function_exists( 'wp_remote_post' ) ) {
        function wp_remote_post( $url, $args = array() ) { return array(); }
    }
    if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
        function wp_remote_retrieve_response_code( $response ) { return 200; }
    }
    if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
        function wp_remote_retrieve_body( $response ) { return '{}'; }
    }
    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $thing ) { return false; }
    }
    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
    }
    if ( ! function_exists( 'absint' ) ) {
        function absint( $num ) { return abs( intval( $num ) ); }
    }
    if ( ! function_exists( 'wp_generate_uuid4' ) ) {
        function wp_generate_uuid4() {
            return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                mt_rand( 0, 0xffff ),
                mt_rand( 0, 0x0fff ) | 0x4000,
                mt_rand( 0, 0x3fff ) | 0x8000,
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
            );
        }
    }
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', '/tmp/wordpress/' );
    }
} else {
    // Load WordPress test suite.
    require_once $_tests_dir . '/includes/functions.php';

    /**
     * Manually load the plugin being tested.
     */
    function _manually_load_plugin() {
        require SPP_PLUGIN_DIR . 'cube-payment-portal.php';
    }
    tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

    // Start up the WP testing environment.
    require $_tests_dir . '/includes/bootstrap.php';
}

// Load plugin classes for testing.
require_once SPP_PLUGIN_DIR . 'includes/class-spp-loader.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-square-client.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-oauth.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-payments.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
require_once SPP_PLUGIN_DIR . 'api/class-spp-webhooks.php';

echo "Test bootstrap loaded successfully.\n";
