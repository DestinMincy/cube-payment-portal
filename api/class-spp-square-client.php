<?php
/**
 * Square API client wrapper.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Square_Client
 *
 * Handles all HTTP communication with Square API.
 */
class SPP_Square_Client {

    /**
     * Square API base URL for production.
     *
     * @var string
     */
    const API_URL_PRODUCTION = 'https://connect.squareup.com';

    /**
     * Square API base URL for sandbox.
     *
     * @var string
     */
    const API_URL_SANDBOX = 'https://connect.squareupsandbox.com';

    /**
     * API version.
     *
     * Square deprecates API versions periodically. Check the changelog
     * quarterly for updates: https://developer.squareup.com/docs/build-basics/api-lifecycle
     *
     * @var string
     */
    const API_VERSION = '2026-01-22';

    /**
     * Access token for API requests.
     *
     * @var string
     */
    private $access_token;

    /**
     * Whether to use sandbox mode.
     *
     * @var bool
     */
    private $sandbox_mode;

    /**
     * Default location ID.
     *
     * @var string
     */
    private $location_id;

    /**
     * Constructor.
     *
     * @param string $access_token Access token for API.
     * @param bool   $sandbox_mode Whether to use sandbox.
     * @param string $location_id  Default location ID.
     */
    public function __construct( $access_token = '', $sandbox_mode = null, $location_id = '' ) {
        // Determine sandbox mode.
        if ( is_null( $sandbox_mode ) ) {
            $sandbox_mode = get_option( 'spp_environment', 'production' ) === 'sandbox';
        }
        $this->sandbox_mode = $sandbox_mode;

        // Get access token - either passed in, sandbox direct token, or OAuth token.
        if ( ! empty( $access_token ) ) {
            $this->access_token = $access_token;
        } else {
            // Check for sandbox direct token first.
            if ( $this->sandbox_mode ) {
                $sandbox_token = get_option( 'spp_sandbox_access_token', '' );
                if ( ! empty( $sandbox_token ) ) {
                    $this->access_token = $sandbox_token;
                }
            }
            // Fall back to OAuth token.
            if ( empty( $this->access_token ) ) {
                $oauth = new SPP_OAuth();
                
                // Proactively refresh token if it's about to expire.
                if ( $oauth->token_needs_refresh() ) {
                    $refresh_result = $oauth->refresh_access_token();
                    if ( is_wp_error( $refresh_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'SPP Token Refresh Warning: ' . $refresh_result->get_error_message() );
                    }
                }
                
                $this->access_token = $oauth->get_access_token();
            }
        }

        $this->location_id = $location_id ?: get_option( 'spp_default_location_id', '' );
    }

    /**
     * Get the API base URL.
     *
     * @return string API base URL.
     */
    public function get_api_url() {
        return $this->sandbox_mode ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
    }

    /**
     * Make an API request.
     *
     * @param string $endpoint API endpoint.
     * @param string $method   HTTP method.
     * @param array  $data     Request data.
     * @return array|WP_Error Response data or error.
     */
    public function request( $endpoint, $method = 'GET', $data = array() ) {
        $url = $this->get_api_url() . '/v2/' . ltrim( $endpoint, '/' );

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $this->access_token,
                'Square-Version' => self::API_VERSION,
                'Content-Type'   => 'application/json',
            ),
            'timeout' => 30,
        );

        if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            // Check for specific timeout error.
            if ( strpos( $response->get_error_message(), 'timed out' ) !== false ||
                 strpos( $response->get_error_message(), 'cURL error 28' ) !== false ) {
                return new WP_Error( 
                    'request_timeout', 
                    __( 'The request to Square timed out. Please try again.', 'cube-payment-portal' ),
                    array( 'original_error' => $response->get_error_message() )
                );
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Handle rate limiting (429 Too Many Requests).
        if ( 429 === $code ) {
            $retry_after = wp_remote_retrieve_header( $response, 'Retry-After' );
            $retry_after = ! empty( $retry_after ) ? intval( $retry_after ) : 60;
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 
                    'SPP API Rate Limited: Endpoint=%s, Retry-After=%d seconds', 
                    $endpoint, 
                    $retry_after 
                ) );
            }
            
            return new WP_Error( 
                'rate_limited', 
                sprintf( 
                    /* translators: %d: number of seconds to wait */
                    __( 'Square API rate limit reached. Please try again in %d seconds.', 'cube-payment-portal' ), 
                    $retry_after 
                ),
                array( 
                    'status'      => 429,
                    'retry_after' => $retry_after,
                )
            );
        }

        if ( $code >= 400 ) {
            $error_message = isset( $data['errors'][0]['detail'] ) 
                ? $data['errors'][0]['detail'] 
                : __( 'Square API error', 'cube-payment-portal' );
            
            return new WP_Error( 'square_api_error', $error_message, array( 'status' => $code ) );
        }

        return $data;
    }

    /**
     * GET request helper.
     *
     * @param string $endpoint API endpoint.
     * @return array|WP_Error Response data.
     */
    public function get( $endpoint ) {
        return $this->request( $endpoint, 'GET' );
    }

    /**
     * POST request helper.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @return array|WP_Error Response data.
     */
    public function post( $endpoint, $data = array() ) {
        return $this->request( $endpoint, 'POST', $data );
    }

    /**
     * PUT request helper.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @return array|WP_Error Response data.
     */
    public function put( $endpoint, $data = array() ) {
        return $this->request( $endpoint, 'PUT', $data );
    }

    /**
     * DELETE request helper.
     *
     * @param string $endpoint API endpoint.
     * @return array|WP_Error Response data.
     */
    public function delete( $endpoint ) {
        return $this->request( $endpoint, 'DELETE' );
    }

    /**
     * Check if client is connected/authenticated.
     *
     * @return bool True if connected.
     */
    public function is_connected() {
        return ! empty( $this->access_token );
    }

    /**
     * Get the default location ID.
     *
     * @return string Location ID.
     */
    public function get_location_id() {
        return $this->location_id;
    }

    /**
     * Set the access token.
     *
     * @param string $token Access token.
     */
    public function set_access_token( $token ) {
        $this->access_token = $token;
    }

    /**
     * Get the access token.
     *
     * @return string Access token.
     */
    public function get_access_token() {
        return $this->access_token;
    }

    /**
     * Make an API request with automatic retry for transient failures.
     *
     * Uses exponential backoff to handle network hiccups and temporary outages.
     * Ideal for background cron jobs and sync operations.
     *
     * @param string $endpoint API endpoint.
     * @param string $method   HTTP method.
     * @param array  $data     Request data.
     * @param int    $retries  Maximum number of retry attempts (default: 3).
     * @return array|WP_Error Response data or error.
     */
    public function request_with_retry( $endpoint, $method = 'GET', $data = array(), $retries = 3 ) {
        $last_error = null;

        for ( $attempt = 0; $attempt < $retries; $attempt++ ) {
            $result = $this->request( $endpoint, $method, $data );

            // Success - return immediately.
            if ( ! is_wp_error( $result ) ) {
                return $result;
            }

            $last_error = $result;
            $error_code = $result->get_error_code();

            // Only retry on transient/network errors, not on API errors.
            $retryable_errors = array(
                'http_request_failed',
                'request_timeout',
                'rate_limited',
            );

            if ( ! in_array( $error_code, $retryable_errors, true ) ) {
                // Non-retryable error - return immediately.
                return $result;
            }

            // Handle rate limiting with Retry-After header.
            if ( 'rate_limited' === $error_code ) {
                $error_data   = $result->get_error_data();
                $wait_seconds = isset( $error_data['retry_after'] ) ? $error_data['retry_after'] : 10;
                // Cap at 10 seconds — PHP max_execution_time is often 30s on shared hosts.
                $wait_seconds = min( $wait_seconds, 10 );
            } else {
                // Exponential backoff: 1s, 2s, 4s — capped at 5s to stay within execution limits.
                $wait_seconds = min( pow( 2, $attempt ), 5 );
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    'SPP API Retry: Attempt %d/%d for %s %s failed (%s). Waiting %d seconds.',
                    $attempt + 1,
                    $retries,
                    $method,
                    $endpoint,
                    $error_code,
                    $wait_seconds
                ) );
            }

            // Wait before retrying (only if not the last attempt).
            if ( $attempt < $retries - 1 ) {
                sleep( $wait_seconds );
            }
        }

        // All retries exhausted - return last error.
        return $last_error;
    }

    /**
     * GET request with retry helper.
     *
     * @param string $endpoint API endpoint.
     * @param int    $retries  Maximum retry attempts.
     * @return array|WP_Error Response data.
     */
    public function get_with_retry( $endpoint, $retries = 3 ) {
        return $this->request_with_retry( $endpoint, 'GET', array(), $retries );
    }

    /**
     * POST request with retry helper.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @param int    $retries  Maximum retry attempts.
     * @return array|WP_Error Response data.
     */
    public function post_with_retry( $endpoint, $data = array(), $retries = 3 ) {
        return $this->request_with_retry( $endpoint, 'POST', $data, $retries );
    }

    /**
     * Make a multipart/form-data request for file uploads.
     *
     * Used for Square endpoints that require file uploads (disputes evidence, catalog images).
     * Builds a proper multipart body with JSON metadata and binary file content.
     *
     * @param string $endpoint    API endpoint (without /v2/ prefix).
     * @param array  $json_data   JSON data for the 'request' part.
     * @param string $file_path   Absolute path to the file to upload.
     * @param string $file_field  Form field name for the file (default: 'file').
     * @return array|WP_Error Response data or error.
     */
    public function request_multipart( $endpoint, $json_data, $file_path, $file_field = 'file' ) {
        // Validate file exists and is readable.
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error(
                'file_not_found',
                __( 'File not found.', 'cube-payment-portal' )
            );
        }

        if ( ! is_readable( $file_path ) ) {
            return new WP_Error(
                'file_not_readable',
                __( 'File is not readable by the server.', 'cube-payment-portal' )
            );
        }

        // Build the full URL.
        $url = $this->get_api_url() . '/v2/' . ltrim( $endpoint, '/' );

        // Generate a unique boundary for multipart.
        $boundary = wp_generate_password( 24, false );

        // Get file MIME type.
        $file_info = wp_check_filetype( $file_path );
        $content_type = ! empty( $file_info['type'] ) ? $file_info['type'] : 'application/octet-stream';

        // For HEIC/HEIF files, WordPress may not recognize the MIME type.
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'heic' === $extension ) {
            $content_type = 'image/heic';
        } elseif ( 'heif' === $extension ) {
            $content_type = 'image/heif';
        }

        // Build multipart body.
        $body = '';

        // JSON 'request' part.
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"request\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode( $json_data ) . "\r\n";

        // Binary file part.
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$file_field}\"; filename=\"" . basename( $file_path ) . "\"\r\n";
        $body .= "Content-Type: {$content_type}\r\n\r\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $body .= file_get_contents( $file_path ) . "\r\n";

        // End boundary.
        $body .= "--{$boundary}--\r\n";

        // Set headers.
        $headers = array(
            'Authorization'  => 'Bearer ' . $this->access_token,
            'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
            'Square-Version' => self::API_VERSION,
        );

        // Execute request with extended timeout for file uploads.
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $headers,
                'body'    => $body,
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $response ) ) {
            // Check for timeout.
            if ( strpos( $response->get_error_message(), 'timed out' ) !== false ||
                 strpos( $response->get_error_message(), 'cURL error 28' ) !== false ) {
                return new WP_Error(
                    'request_timeout',
                    __( 'The file upload timed out. Please try again with a smaller file.', 'cube-payment-portal' )
                );
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        // Handle errors.
        if ( $code >= 400 ) {
            $error_message = isset( $data['errors'][0]['detail'] )
                ? $data['errors'][0]['detail']
                : __( 'Square API error during file upload.', 'cube-payment-portal' );

            return new WP_Error( 'square_api_error', $error_message, array( 'status' => $code ) );
        }

        return $data;
    }
}
