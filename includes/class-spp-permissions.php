<?php
/**
 * Permissions management for Cube Payment Portal.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Permissions
 *
 * Handles permission checks, impersonation, and capability management.
 */
class SPP_Permissions {

    /**
     * Permission constant: View portal (clients and admins).
     */
    const VIEW_PORTAL = 'spp_view_portal';

    /**
     * Permission constant: Manage portal (admins only).
     */
    const MANAGE_PORTAL = 'spp_manage_portal';

    /**
     * Permission constant: View as client (admin impersonation).
     */
    const VIEW_AS_CLIENT = 'spp_view_as_client';

    /**
     * Transient key prefix for impersonation data.
     */
    const IMPERSONATION_TRANSIENT_PREFIX = 'spp_impersonate_';

    /**
     * Impersonation session duration in seconds (15 minutes).
     */
    const IMPERSONATION_DURATION = 900;

    /**
     * Nonce action for impersonation.
     */
    const IMPERSONATION_NONCE_ACTION = 'spp_impersonate_user';

    /**
     * Check if current user can perform an action.
     *
     * @param string $action  The action to check (use class constants).
     * @param array  $context Optional context data for the permission check.
     * @return bool True if user can perform action, false otherwise.
     */
    public static function current_user_can( $action, $context = array() ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();

        switch ( $action ) {
            case self::VIEW_PORTAL:
                return self::can_view_portal( $user, $context );

            case self::MANAGE_PORTAL:
                return self::can_manage_portal( $user );

            case self::VIEW_AS_CLIENT:
                return self::can_view_as_client( $user );

            default:
                // Allow filtering for custom permission checks.
                return apply_filters( 'spp_current_user_can', false, $action, $user, $context );
        }
    }

    /**
     * Check if user can view the portal.
     *
     * @param WP_User $user    The user to check.
     * @param array   $context Optional context data.
     * @return bool True if user can view portal.
     */
    private static function can_view_portal( $user, $context = array() ) {
        // Admins can always view.
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }

        // Check for custom capability.
        if ( user_can( $user, 'spp_view_portal' ) ) {
            return true;
        }

        // Portal clients can view.
        if ( in_array( 'spp_client', (array) $user->roles, true ) ) {
            return true;
        }

        // Placeholder accounts cannot view.
        if ( in_array( 'spp_client_placeholder', (array) $user->roles, true ) ) {
            return false;
        }

        // Allow filtering for custom logic.
        return apply_filters( 'spp_can_view_portal', false, $user, $context );
    }

    /**
     * Check if user can manage the portal (admin dashboard).
     *
     * @param WP_User $user The user to check.
     * @return bool True if user can manage portal.
     */
    private static function can_manage_portal( $user ) {
        // Admins can always manage.
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }

        // Check for custom capability.
        if ( user_can( $user, 'spp_manage_portal' ) ) {
            return true;
        }

        return apply_filters( 'spp_can_manage_portal', false, $user );
    }

    /**
     * Check if user can impersonate clients.
     *
     * @param WP_User $user The user to check.
     * @return bool True if user can impersonate.
     */
    private static function can_view_as_client( $user ) {
        // Only admins can impersonate.
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }

        // Check for custom capability.
        if ( user_can( $user, 'spp_view_as_client' ) ) {
            return true;
        }

        return apply_filters( 'spp_can_view_as_client', false, $user );
    }

    /**
     * Get the current user's Square customer ID.
     *
     * If impersonating, returns the impersonated user's customer ID.
     *
     * @return string|false The Square customer ID or false if not found.
     */
    public static function get_current_customer_id() {
        // Check for impersonation first.
        if ( self::is_impersonating() ) {
            $impersonated_user_id = self::get_impersonated_user_id();
            if ( $impersonated_user_id ) {
                return get_user_meta( $impersonated_user_id, 'spp_square_customer_id', true );
            }
        }

        // Return current user's customer ID.
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $customer_id = get_user_meta( get_current_user_id(), 'spp_square_customer_id', true );

        return ! empty( $customer_id ) ? $customer_id : false;
    }

    /**
     * Check if current admin is impersonating a client.
     *
     * @return bool True if currently impersonating.
     */
    public static function is_impersonating() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        // Only admins can impersonate.
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $impersonation_data = self::get_impersonation_data();

        return ! empty( $impersonation_data );
    }

    /**
     * Get the impersonated user ID if applicable.
     *
     * @return int|false The impersonated user ID or false if not impersonating.
     */
    public static function get_impersonated_user_id() {
        $impersonation_data = self::get_impersonation_data();

        if ( empty( $impersonation_data ) || empty( $impersonation_data['user_id'] ) ) {
            return false;
        }

        return (int) $impersonation_data['user_id'];
    }

    /**
     * Get the impersonated user object if applicable.
     *
     * @return WP_User|false The impersonated user object or false.
     */
    public static function get_impersonated_user() {
        $user_id = self::get_impersonated_user_id();

        if ( ! $user_id ) {
            return false;
        }

        $user = get_userdata( $user_id );

        return $user instanceof WP_User ? $user : false;
    }

    /**
     * Set impersonation context for a user.
     *
     * @param int    $user_id The user ID to impersonate.
     * @param string $nonce   Security nonce for verification.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function set_impersonation( $user_id, $nonce = '' ) {
        // Verify the admin can impersonate.
        if ( ! self::current_user_can( self::VIEW_AS_CLIENT ) ) {
            return new WP_Error(
                'permission_denied',
                __( 'You do not have permission to view as another user.', 'cube-payment-portal' )
            );
        }

        // Verify nonce (required).
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::IMPERSONATION_NONCE_ACTION ) ) {
            return new WP_Error(
                'invalid_nonce',
                __( 'Security verification failed. Please try again.', 'cube-payment-portal' )
            );
        }

        // Validate target user exists.
        $target_user = get_userdata( $user_id );
        if ( ! $target_user ) {
            return new WP_Error(
                'invalid_user',
                __( 'The specified user does not exist.', 'cube-payment-portal' )
            );
        }

        // Prevent impersonating other admins.
        if ( user_can( $target_user, 'manage_options' ) ) {
            return new WP_Error(
                'cannot_impersonate_admin',
                __( 'Cannot impersonate administrator accounts.', 'cube-payment-portal' )
            );
        }

        $admin_id = get_current_user_id();
        $transient_key = self::IMPERSONATION_TRANSIENT_PREFIX . $admin_id;

        $impersonation_data = array(
            'user_id'    => $user_id,
            'admin_id'   => $admin_id,
            'started_at' => current_time( 'mysql' ),
            'ip_address' => self::get_client_ip(),
        );

        // Store impersonation data in transient.
        set_transient( $transient_key, $impersonation_data, self::IMPERSONATION_DURATION );

        // Log the impersonation event.
        self::log_impersonation_event( 'start', $admin_id, $user_id );

        /**
         * Fires when impersonation begins.
         *
         * @param int   $user_id  The impersonated user ID.
         * @param int   $admin_id The admin user ID.
         * @param array $data     Impersonation data.
         */
        do_action( 'spp_impersonation_started', $user_id, $admin_id, $impersonation_data );

        return true;
    }

    /**
     * Clear impersonation context.
     *
     * @return bool True on success, false if not impersonating.
     */
    public static function clear_impersonation() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $admin_id = get_current_user_id();
        $transient_key = self::IMPERSONATION_TRANSIENT_PREFIX . $admin_id;

        // Get current impersonation data before clearing.
        $impersonation_data = get_transient( $transient_key );

        if ( empty( $impersonation_data ) ) {
            return false;
        }

        $impersonated_user_id = $impersonation_data['user_id'] ?? 0;

        // Delete the transient.
        delete_transient( $transient_key );

        // Log the end of impersonation.
        self::log_impersonation_event( 'end', $admin_id, $impersonated_user_id );

        /**
         * Fires when impersonation ends.
         *
         * @param int $user_id  The previously impersonated user ID.
         * @param int $admin_id The admin user ID.
         */
        do_action( 'spp_impersonation_ended', $impersonated_user_id, $admin_id );

        return true;
    }

    /**
     * Get impersonation data for current admin.
     *
     * @return array|false Impersonation data or false if not impersonating.
     */
    private static function get_impersonation_data() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $admin_id = get_current_user_id();
        $transient_key = self::IMPERSONATION_TRANSIENT_PREFIX . $admin_id;

        $data = get_transient( $transient_key );

        if ( empty( $data ) ) {
            return false;
        }

        // Validate the data structure.
        if ( ! isset( $data['user_id'], $data['admin_id'] ) ) {
            delete_transient( $transient_key );
            return false;
        }

        // Verify the admin ID matches (security check).
        if ( (int) $data['admin_id'] !== $admin_id ) {
            delete_transient( $transient_key );
            return false;
        }

        return $data;
    }

    /**
     * Log impersonation events for audit trail.
     *
     * @param string $event_type Type of event ('start' or 'end').
     * @param int    $admin_id   The admin user ID.
     * @param int    $user_id    The impersonated user ID.
     */
    private static function log_impersonation_event( $event_type, $admin_id, $user_id ) {
        $admin = get_userdata( $admin_id );
        $user = get_userdata( $user_id );

        $admin_name = $admin ? $admin->display_name : "ID: {$admin_id}";
        $user_name = $user ? $user->display_name : "ID: {$user_id}";

        $message = sprintf(
            'SPP Impersonation %s: Admin "%s" (ID: %d) %s impersonating user "%s" (ID: %d) from IP: %s',
            strtoupper( $event_type ),
            $admin_name,
            $admin_id,
            'start' === $event_type ? 'started' : 'stopped',
            $user_name,
            $user_id,
            self::get_client_ip()
        );

        // Log to WordPress debug log if enabled.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }

        /**
         * Fires when an impersonation event is logged.
         *
         * @param string $event_type The event type.
         * @param int    $admin_id   The admin ID.
         * @param int    $user_id    The user ID.
         * @param string $message    The log message.
         */
        do_action( 'spp_impersonation_logged', $event_type, $admin_id, $user_id, $message );
    }

    /**
     * Get the client IP address safely.
     *
     * @return string The client IP address.
     */
    private static function get_client_ip() {
        $ip = '';

        // Check for proxy headers first.
        $headers = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                // Handle comma-separated IPs (from proxies).
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip = trim( $ips[0] );
                }
                break;
            }
        }

        // Validate IP format.
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $ip = 'unknown';
        }

        return $ip;
    }

    /**
     * Create a nonce URL for impersonation.
     *
     * @param int    $user_id  The user ID to impersonate.
     * @param string $base_url The base URL to add parameters to.
     * @return string The URL with nonce and user ID parameters.
     */
    public static function get_impersonation_url( $user_id, $base_url = '' ) {
        if ( empty( $base_url ) ) {
            $base_url = home_url();
        }

        return wp_nonce_url(
            add_query_arg(
                array(
                    'spp_view_as' => $user_id,
                ),
                $base_url
            ),
            self::IMPERSONATION_NONCE_ACTION,
            'spp_impersonate_nonce'
        );
    }

    /**
     * Get the URL to exit impersonation mode.
     *
     * @param string $return_url Optional URL to return to after exiting.
     * @return string The exit impersonation URL.
     */
    public static function get_exit_impersonation_url( $return_url = '' ) {
        if ( empty( $return_url ) ) {
            $return_url = home_url();
        }

        return wp_nonce_url(
            add_query_arg(
                array(
                    'spp_exit_view_as' => '1',
                ),
                $return_url
            ),
            'spp_exit_impersonation',
            'spp_exit_nonce'
        );
    }

    /**
     * Handle impersonation request from URL parameters.
     *
     * Should be called early in the request lifecycle.
     */
    public static function handle_impersonation_request() {
        // Handle starting impersonation.
        if ( isset( $_GET['spp_view_as'], $_GET['spp_impersonate_nonce'] ) ) {
            $user_id = absint( $_GET['spp_view_as'] );
            $nonce = sanitize_text_field( wp_unslash( $_GET['spp_impersonate_nonce'] ) );

            $result = self::set_impersonation( $user_id, $nonce );

            if ( is_wp_error( $result ) ) {
                // Add admin notice for error.
                add_action( 'admin_notices', function() use ( $result ) {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html( $result->get_error_message() )
                    );
                } );
            } else {
                // Redirect to remove URL parameters.
                $redirect_url = remove_query_arg( array( 'spp_view_as', 'spp_impersonate_nonce' ) );
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }

        // Handle exiting impersonation.
        if ( isset( $_GET['spp_exit_view_as'], $_GET['spp_exit_nonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['spp_exit_nonce'] ) );

            if ( wp_verify_nonce( $nonce, 'spp_exit_impersonation' ) ) {
                self::clear_impersonation();

                // Redirect to remove URL parameters.
                $redirect_url = remove_query_arg( array( 'spp_exit_view_as', 'spp_exit_nonce' ) );
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }
    }

    /**
     * Get the effective user ID (impersonated or current).
     *
     * @return int The effective user ID.
     */
    public static function get_effective_user_id() {
        if ( self::is_impersonating() ) {
            $impersonated_id = self::get_impersonated_user_id();
            if ( $impersonated_id ) {
                return $impersonated_id;
            }
        }

        return get_current_user_id();
    }

    /**
     * Register custom capabilities on plugin activation.
     *
     * Should be called during plugin activation.
     */
    public static function register_capabilities() {
        // Add capabilities to administrator role.
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( 'spp_view_portal' );
            $admin_role->add_cap( 'spp_manage_portal' );
            $admin_role->add_cap( 'spp_view_as_client' );
        }

        // Add view capability to client role.
        $client_role = get_role( 'spp_client' );
        if ( $client_role ) {
            $client_role->add_cap( 'spp_view_portal' );
        }
    }
}

/**
 * Wrapper function for permission checks.
 *
 * Provides a convenient way to check permissions throughout the plugin.
 *
 * @param string $action  The action to check.
 * @param array  $context Optional context data.
 * @return bool True if user can perform action.
 */
function spp_current_user_can( $action, $context = array() ) {
    return SPP_Permissions::current_user_can( $action, $context );
}
