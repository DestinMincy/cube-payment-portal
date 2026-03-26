<?php
/**
 * OAuth 2.0 handling for Square API.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_OAuth
 *
 * Handles OAuth 2.0 authentication flow with Square.
 */
class SPP_OAuth {

    /**
     * Square OAuth base URL for production.
     *
     * @var string
     */
    const BASE_URL_PRODUCTION = 'https://connect.squareup.com';

    /**
     * Square OAuth base URL for sandbox.
     *
     * @var string
     */
    const BASE_URL_SANDBOX = 'https://connect.squareupsandbox.com';

    /**
     * Application ID.
     *
     * @var string
     */
    private $application_id;

    /**
     * Application secret.
     *
     * @var string
     */
    private $application_secret;

    /**
     * Whether to use sandbox mode.
     *
     * @var bool
     */
    private $sandbox;

    /**
     * Constructor.
     *
     * @param string $application_id     Application ID.
     * @param string $application_secret Application secret.
     */
    public function __construct( $application_id = '', $application_secret = '' ) {
        $this->application_id     = $application_id ?: get_option( 'spp_application_id', '' );
        $this->application_secret = $application_secret ?: get_option( 'spp_application_secret', '' );
        $this->sandbox            = get_option( 'spp_environment', 'sandbox' ) === 'sandbox';
    }

    /**
     * Get the base URL for OAuth endpoints.
     *
     * @return string Base URL.
     */
    private function get_base_url() {
        return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /**
     * Get the OAuth authorization URL.
     *
     * @param string $redirect_uri The redirect URI after authorization.
     * @param string $state        State parameter for CSRF protection.
     * @return string Authorization URL.
     */
    public function get_authorization_url( $redirect_uri, $state = '' ) {
        // Build scope string with + separator (Square's expected format).
        $scope_string = implode( '+', $this->get_required_scopes() );

        // Build authorization URL manually to control encoding.
        $base_url = $this->get_base_url() . '/oauth2/authorize';
        
        $params = array(
            'client_id' => $this->application_id,
            'scope'     => $scope_string,
        );

        // Add redirect_uri if provided.
        if ( ! empty( $redirect_uri ) ) {
            $params['redirect_uri'] = $redirect_uri;
        }

        // Add state for CSRF protection.
        if ( ! empty( $state ) ) {
            $params['state'] = $state;
        }

        // Only add session=false for production (sandbox ignores it).
        if ( ! $this->sandbox ) {
            $params['session'] = 'false';
        }

        // Build query string - don't encode the + in scopes.
        $query_parts = array();
        foreach ( $params as $key => $value ) {
            if ( $key === 'scope' ) {
                // Don't URL encode the scope (keep + as-is).
                $query_parts[] = $key . '=' . $value;
            } else {
                $query_parts[] = $key . '=' . rawurlencode( $value );
            }
        }

        $full_url = $base_url . '?' . implode( '&', $query_parts );

        return $full_url;
    }

    /**
     * Get required OAuth scopes.
     *
     * @return array List of required scopes.
     */
    public function get_required_scopes() {
        return array(
            'MERCHANT_PROFILE_READ',
            'CUSTOMERS_READ',
            'CUSTOMERS_WRITE',
            'PAYMENTS_READ',
            'PAYMENTS_WRITE',
            'PAYMENTS_WRITE_ADDITIONAL_RECIPIENTS',
            'SUBSCRIPTIONS_READ',
            'SUBSCRIPTIONS_WRITE',
            'INVOICES_READ',
            'INVOICES_WRITE',
            'ORDERS_READ',
            'ORDERS_WRITE',
            'ITEMS_READ',
        );
    }

    /**
     * Exchange authorization code for access tokens.
     *
     * @param string $code         Authorization code.
     * @param string $redirect_uri Redirect URI used in authorization.
     * @return array|WP_Error Token data or error.
     */
    public function exchange_code_for_tokens( $code, $redirect_uri ) {
        $response = wp_remote_post(
            $this->get_base_url() . '/oauth2/token',
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'client_id'     => $this->application_id,
                        'client_secret' => $this->application_secret,
                        'code'          => $code,
                        'redirect_uri'  => $redirect_uri,
                        'grant_type'    => 'authorization_code',
                    )
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error(
                'oauth_error',
                $body['error_description'] ?? $body['error'],
                $body
            );
        }

        // Store tokens securely.
        $this->store_tokens( $body );

        return $body;
    }

    /**
     * Refresh the access token.
     *
     * @param string $refresh_token The refresh token.
     * @return array|WP_Error Token data or error.
     */
    public function refresh_access_token( $refresh_token = '' ) {
        if ( empty( $refresh_token ) ) {
            $refresh_token = $this->get_decrypted_token( 'spp_refresh_token' );
        }

        if ( empty( $refresh_token ) ) {
            return new WP_Error( 'no_refresh_token', __( 'No refresh token available.', 'cube-payment-portal' ) );
        }

        $response = wp_remote_post(
            $this->get_base_url() . '/oauth2/token',
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'client_id'     => $this->application_id,
                        'client_secret' => $this->application_secret,
                        'refresh_token' => $refresh_token,
                        'grant_type'    => 'refresh_token',
                    )
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error(
                'oauth_refresh_error',
                $body['error_description'] ?? $body['error'],
                $body
            );
        }

        // Store updated tokens.
        $this->store_tokens( $body );

        return $body;
    }

    /**
     * Revoke the access token.
     *
     * @param string $access_token Token to revoke.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function revoke_tokens( $access_token = '' ) {
        if ( empty( $access_token ) ) {
            $access_token = $this->get_decrypted_token( 'spp_access_token' );
        }

        $response = wp_remote_post(
            $this->get_base_url() . '/oauth2/revoke',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Client ' . $this->application_secret,
                ),
                'body'    => wp_json_encode(
                    array(
                        'client_id'    => $this->application_id,
                        'access_token' => $access_token,
                    )
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            // Clear stored tokens.
            delete_option( 'spp_access_token' );
            delete_option( 'spp_refresh_token' );
            delete_option( 'spp_token_expires_at' );
            delete_option( 'spp_merchant_id' );
            
            return true;
        }

        return new WP_Error( 'revoke_failed', __( 'Failed to revoke token.', 'cube-payment-portal' ) );
    }

    /**
     * Store tokens securely.
     *
     * @param array $token_data Token data from OAuth response.
     */
    public function store_tokens( $token_data ) {
        if ( isset( $token_data['access_token'] ) ) {
            update_option( 'spp_access_token', $this->encrypt_token( $token_data['access_token'] ) );
        }

        if ( isset( $token_data['refresh_token'] ) ) {
            update_option( 'spp_refresh_token', $this->encrypt_token( $token_data['refresh_token'] ) );
        }

        if ( isset( $token_data['expires_at'] ) ) {
            update_option( 'spp_token_expires_at', $token_data['expires_at'] );
        }

        if ( isset( $token_data['merchant_id'] ) ) {
            update_option( 'spp_merchant_id', $token_data['merchant_id'] );
        }
    }

    /**
     * Encrypt a token for storage.
     *
     * @param string $token Token to encrypt.
     * @return string|WP_Error Encrypted token or error if encryption unavailable in production.
     */
    public function encrypt_token( $token ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            $environment = get_option( 'spp_environment', 'sandbox' );
            
            // In production, require proper encryption.
            if ( 'production' === $environment ) {
                return new WP_Error( 
                    'encryption_unavailable', 
                    __( 'OpenSSL extension is required for production use. Please enable it in your PHP configuration.', 'cube-payment-portal' ) 
                );
            }
            
            // In sandbox, allow base64 encoding with warning.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Security Warning: Token stored with base64 encoding (no encryption). Enable OpenSSL for production.' );
            }
            return base64_encode( $token );
        }

        $key    = $this->get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );

        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a stored token.
     *
     * @param string $encrypted Encrypted token.
     * @return string Decrypted token.
     */
    public function decrypt_token( $encrypted ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $encrypted );
        }

        $data = base64_decode( $encrypted );
        $iv   = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );
        $key  = $this->get_encryption_key();

        return openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
    }

    /**
     * Get decrypted token from options.
     *
     * @param string $option_name Option name.
     * @return string Decrypted token.
     */
    public function get_decrypted_token( $option_name ) {
        $encrypted = get_option( $option_name, '' );
        
        if ( empty( $encrypted ) ) {
            return '';
        }

        return $this->decrypt_token( $encrypted );
    }

    /**
     * Get encryption key from WordPress salts.
     *
     * Uses WordPress salts if available, otherwise generates and stores
     * a random key. This ensures tokens are encrypted with unpredictable keys.
     *
     * @return string Encryption key.
     */
    private function get_encryption_key() {
        // Prefer WordPress authentication salts.
        if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) && 
             AUTH_KEY !== 'put your unique phrase here' && 
             SECURE_AUTH_KEY !== 'put your unique phrase here' ) {
            return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY );
        }
        
        // Fallback: use a randomly generated key stored in the database.
        $stored_key = get_option( 'spp_encryption_key', '' );
        
        if ( empty( $stored_key ) ) {
            // Generate a random key and store it.
            $stored_key = wp_generate_password( 64, true, true );
            update_option( 'spp_encryption_key', $stored_key, false ); // false = not autoloaded
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Security Notice: Generated fallback encryption key. Consider configuring WordPress salts in wp-config.php.' );
            }
        }
        
        return hash( 'sha256', $stored_key );
    }

    /**
     * Check if token is expired or expiring soon.
     *
     * @return bool True if token needs refresh.
     */
    public function token_needs_refresh() {
        $expires_at = get_option( 'spp_token_expires_at', '' );
        
        if ( empty( $expires_at ) ) {
            return true;
        }

        // Refresh if less than 1 hour until expiry.
        $expires_timestamp = strtotime( $expires_at );
        return $expires_timestamp < ( time() + 3600 );
    }

    /**
     * Check if OAuth is connected (either via OAuth or direct token).
     *
     * @return bool True if connected.
     */
    public function is_connected() {
        // Check for sandbox direct access token first.
        if ( $this->sandbox ) {
            $sandbox_token = get_option( 'spp_sandbox_access_token', '' );
            if ( ! empty( $sandbox_token ) ) {
                return true;
            }
        }

        // Check for OAuth access token.
        $access_token = get_option( 'spp_access_token', '' );
        return ! empty( $access_token );
    }

    /**
     * Get the current access token (sandbox direct token or OAuth token).
     *
     * @return string Access token or empty string.
     */
    public function get_access_token() {
        // Check for sandbox direct access token first.
        if ( $this->sandbox ) {
            $sandbox_token = get_option( 'spp_sandbox_access_token', '' );
            if ( ! empty( $sandbox_token ) ) {
                return $sandbox_token;
            }
        }

        // Fall back to OAuth token (decrypted).
        return $this->get_decrypted_token( 'spp_access_token' );
    }
}
