<?php
/**
 * OAuth Controller for handling Square OAuth flow.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_OAuth_Controller
 *
 * Handles OAuth initiation and callback processing.
 */
class SPP_OAuth_Controller {

    /**
     * OAuth instance.
     *
     * @var SPP_OAuth
     */
    private $oauth;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->oauth = new SPP_OAuth();
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Handle OAuth callback.
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
        
        // AJAX handlers.
        add_action( 'wp_ajax_spp_initiate_oauth', array( $this, 'ajax_initiate_oauth' ) );
        add_action( 'wp_ajax_spp_disconnect', array( $this, 'ajax_disconnect' ) );
    }

    /**
     * AJAX handler to initiate OAuth flow.
     */
    public function ajax_initiate_oauth() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $application_id = get_option( 'spp_application_id', '' );

        if ( empty( $application_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter your Application ID first.', 'cube-payment-portal' ) ) );
        }

        // Generate state for CSRF protection.
        $state = wp_generate_password( 32, false );
        set_transient( 'spp_oauth_state', $state, 600 ); // 10 minutes.

        $redirect_uri = $this->get_oauth_redirect_uri();
        $auth_url = $this->oauth->get_authorization_url( $redirect_uri, $state );

        wp_send_json_success( array( 'auth_url' => $auth_url ) );
    }

    /**
     * Handle OAuth callback from Square.
     */
    public function handle_oauth_callback() {
        // Check if this is an OAuth callback.
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'spp-settings' ) {
            return;
        }

        if ( ! isset( $_GET['code'] ) ) {
            return;
        }

        // Verify user has admin capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Verify state parameter.
        $state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';
        $stored_state = get_transient( 'spp_oauth_state' );

        if ( empty( $state ) || $state !== $stored_state ) {
            add_settings_error(
                'spp_oauth',
                'invalid_state',
                __( 'Invalid OAuth state. Please try again.', 'cube-payment-portal' ),
                'error'
            );
            return;
        }

        // Clear the state transient.
        delete_transient( 'spp_oauth_state' );

        // Exchange code for tokens.
        $code = sanitize_text_field( $_GET['code'] );
        $redirect_uri = $this->get_oauth_redirect_uri();
        
        try {
            $result = $this->oauth->exchange_code_for_tokens( $code, $redirect_uri );

            if ( is_wp_error( $result ) ) {
                add_settings_error(
                    'spp_oauth',
                    'oauth_error',
                    sprintf(
                        /* translators: %s: error message */
                        __( 'Failed to connect to Square: %s', 'cube-payment-portal' ),
                        $result->get_error_message()
                    ),
                    'error'
                );
                wp_safe_redirect( admin_url( 'admin.php?page=spp-settings&error=1' ) );
                exit;
            }

            // Try to fetch and store location ID (non-fatal if fails).
            try {
                $this->fetch_default_location();
            } catch ( Exception $e ) {
                // Log but don't fail.
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP: Failed to fetch default location: ' . $e->getMessage() );
                }
            }

            // Redirect to settings with success.
            wp_safe_redirect( admin_url( 'admin.php?page=spp-settings&connected=1' ) );
            exit;

        } catch ( Exception $e ) {
            add_settings_error(
                'spp_oauth',
                'oauth_exception',
                sprintf(
                    /* translators: %s: error message */
                    __( 'OAuth error: %s', 'cube-payment-portal' ),
                    $e->getMessage()
                ),
                'error'
            );
            wp_safe_redirect( admin_url( 'admin.php?page=spp-settings&error=1' ) );
            exit;
        }
    }

    /**
     * AJAX handler to disconnect from Square.
     */
    public function ajax_disconnect() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        // Revoke tokens.
        $this->oauth->revoke_tokens();

        // Clear stored data.
        delete_option( 'spp_access_token' );
        delete_option( 'spp_refresh_token' );
        delete_option( 'spp_token_expires_at' );
        delete_option( 'spp_merchant_id' );
        delete_option( 'spp_default_location_id' );

        wp_send_json_success( array( 'message' => __( 'Disconnected from Square.', 'cube-payment-portal' ) ) );
    }

    /**
     * Fetch and store the default location ID.
     */
    private function fetch_default_location() {
        $client = new SPP_Square_Client(
            $this->oauth->get_decrypted_token( 'spp_access_token' ),
            get_option( 'spp_environment' ) === 'sandbox'
        );

        $response = $client->get( 'locations' );

        if ( ! is_wp_error( $response ) && ! empty( $response['locations'] ) ) {
            // Get the first active location.
            foreach ( $response['locations'] as $location ) {
                if ( isset( $location['status'] ) && $location['status'] === 'ACTIVE' ) {
                    update_option( 'spp_default_location_id', $location['id'] );
                    break;
                }
            }
        }
    }

    /**
     * Get OAuth redirect URI.
     *
     * @return string Redirect URI.
     */
    public function get_oauth_redirect_uri() {
        // Use HTTPS for OAuth redirect (required by Square).
        $redirect_uri = admin_url( 'admin.php?page=spp-settings' );
        
        // Ensure HTTPS is used.
        if ( strpos( $redirect_uri, 'http://' ) === 0 ) {
            $redirect_uri = 'https://' . substr( $redirect_uri, 7 );
        }
        
        return $redirect_uri;
    }
}

// Initialize the controller.
new SPP_OAuth_Controller();
