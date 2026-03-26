<?php
/**
 * Public-facing functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Public
 *
 * Handles public-facing functionality.
 */
class SPP_Public {

    /**
     * Plugin version.
     *
     * @var string
     */
    private $version;

    /**
     * Constructor.
     *
     * @param string $version Plugin version.
     */
    public function __construct( $version ) {
        $this->version = $version;

        // Add CSP headers for Square SDK.
        add_action( 'send_headers', array( $this, 'add_square_csp_headers' ) );
    }

    /**
     * Add Content Security Policy headers for Square Web Payments SDK.
     *
     * Per Square documentation, CSP headers should be added when using
     * the Web Payments SDK to enhance security.
     */
    public function add_square_csp_headers() {
        // Only add headers on pages with payment forms.
        if ( ! $this->page_has_payment_form() ) {
            return;
        }

        // Don't send headers if they're already sent.
        if ( headers_sent() ) {
            return;
        }

        $sandbox = get_option( 'spp_environment', 'sandbox' ) === 'sandbox';
        $square_domain = $sandbox ? 'sandbox.web.squarecdn.com' : 'web.squarecdn.com';
        $connect_domain = $sandbox ? 'connect.squareupsandbox.com' : 'connect.squareup.com';

        // Build CSP header for Square SDK.
        $csp_directives = array(
            "script-src 'self' 'unsafe-inline' https://{$square_domain}",
            "frame-src 'self' https://{$square_domain} https://{$connect_domain}",
            "connect-src 'self' https://{$square_domain} https://{$connect_domain}",
        );

        header( 'Content-Security-Policy: ' . implode( '; ', $csp_directives ) );
    }

    /**
     * Check if current page has a payment form.
     *
     * @return bool True if page has payment form shortcode.
     */
    private function page_has_payment_form() {
        global $post;

        if ( ! $post ) {
            return false;
        }

        return has_shortcode( $post->post_content, 'spp_client_portal' ) ||
               has_shortcode( $post->post_content, 'spp_payment_form' ) ||
               has_shortcode( $post->post_content, 'spp_payment_button' );
    }

    /**
     * Register public stylesheets.
     */
    public function enqueue_styles() {
        // Only load on pages with our shortcodes.
        global $post;
        
        if ( ! $post ) {
            return;
        }

        $has_portal = has_shortcode( $post->post_content, 'spp_client_portal' );
        $has_login  = has_shortcode( $post->post_content, 'spp_login_form' );

        if ( ! $has_portal && ! $has_login ) {
            return;
        }

        // Check if styles are disabled.
        if ( apply_filters( 'spp_disable_styles', false ) ) {
            return;
        }

        // Skeleton base styles.
        wp_enqueue_style(
            'spp-skeleton',
            SPP_PLUGIN_URL . 'assets/css/skeleton.css',
            array(),
            $this->version
        );

        // Client portal design system (glassmorphism, layout, components).
        wp_enqueue_style(
            'spp-client-portal',
            SPP_PLUGIN_URL . 'assets/css/client-portal.css',
            array( 'spp-skeleton' ),
            $this->version
        );

        // Inject custom accent color overrides if configured.
        $accent_color = get_option( 'spp_portal_accent_color', '' );
        if ( ! empty( $accent_color ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $accent_color ) ) {
            $r = hexdec( substr( $accent_color, 1, 2 ) );
            $g = hexdec( substr( $accent_color, 3, 2 ) );
            $b = hexdec( substr( $accent_color, 5, 2 ) );

            // Compute darker shade for hover (20% darker).
            $hover_r = max( 0, (int) ( $r * 0.8 ) );
            $hover_g = max( 0, (int) ( $g * 0.8 ) );
            $hover_b = max( 0, (int) ( $b * 0.8 ) );
            $hover_hex = sprintf( '#%02x%02x%02x', $hover_r, $hover_g, $hover_b );

            // Compute lighter end for gradient (mix with cyan).
            $grad_r = min( 255, (int) ( $r * 0.5 + 0 * 0.5 ) );
            $grad_g = min( 255, (int) ( $g * 0.5 + 210 * 0.5 ) );
            $grad_b = min( 255, (int) ( $b * 0.5 + 255 * 0.5 ) );
            $gradient_end = sprintf( '#%02x%02x%02x', $grad_r, $grad_g, $grad_b );

            $custom_css = sprintf(
                ':root {
                    --spp-primary: %1$s;
                    --spp-primary-hover: %2$s;
                    --spp-primary-dark: %2$s;
                    --spp-primary-light: rgba(%3$d, %4$d, %5$d, 0.1);
                    --spp-primary-gradient: linear-gradient(135deg, %1$s, %6$s);
                    --spp-primary-gradient-hover: linear-gradient(135deg, %2$s, %6$s);
                }',
                $accent_color,
                $hover_hex,
                $r, $g, $b,
                $gradient_end
            );
            wp_add_inline_style( 'spp-client-portal', $custom_css );
        }

        // Inject custom CSS if configured.
        $portal_custom_css = get_option( 'spp_portal_custom_css', '' );
        if ( ! empty( $portal_custom_css ) ) {
            wp_add_inline_style( 'spp-client-portal', $portal_custom_css );
        }
    }

    /**
     * Register public scripts.
     */
    public function enqueue_scripts() {
        global $post;
        
        if ( ! $post ) {
            return;
        }

        $has_payment_shortcode = has_shortcode( $post->post_content, 'spp_client_portal' ) ||
                                 has_shortcode( $post->post_content, 'spp_payment_form' ) ||
                                 has_shortcode( $post->post_content, 'spp_payment_button' );

        if ( ! $has_payment_shortcode ) {
            return;
        }

        // Square Web Payments SDK.
        $sandbox = get_option( 'spp_environment', 'sandbox' ) === 'sandbox';
        $sdk_url = $sandbox
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';

        wp_enqueue_script(
            'square-web-sdk',
            $sdk_url,
            array(),
            null,
            true
        );

        wp_enqueue_script(
            'spp-public',
            SPP_PLUGIN_URL . 'assets/js/client-portal.js',
            array( 'jquery', 'square-web-sdk' ),
            $this->version,
            true
        );

        wp_localize_script(
            'spp-public',
            'sppPublic',
            array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'spp_public_nonce' ),
                'applicationId' => get_option( 'spp_application_id', '' ),
                'locationId'    => get_option( 'spp_default_location_id', '' ),
                'sandbox'       => get_option( 'spp_environment', 'sandbox' ) === 'sandbox',
                'portalTitle'   => get_option( 'spp_portal_title', '' ) ?: __( 'Client Portal', 'cube-payment-portal' ),
                'itemsPerPage'     => (int) get_option( 'spp_portal_items_per_page', 25 ),
                'sessionTimeout'   => (int) get_option( 'spp_portal_session_timeout', 30 ),
                'allowStaffSelection' => (bool) get_option( 'spp_allow_staff_selection', false ),
                'logoutUrl'        => wp_logout_url( SPP_Plugin::get_logout_redirect_url() ),
            )
        );
    }

    /**
     * Check rate limit for public AJAX requests.
     *
     * @param string $action Action name for rate limiting.
     * @return bool|WP_Error True if within limit, WP_Error if exceeded.
     */
    private function check_rate_limit( $action = 'default' ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rate_key = 'spp_public_rate_' . $action . '_' . md5( $ip );
        $count = (int) get_transient( $rate_key );
        $limit = (int) get_option( 'spp_rate_limit_per_minute', 30 );

        if ( $count >= $limit ) {
            return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'cube-payment-portal' ) );
        }

        set_transient( $rate_key, $count + 1, 60 );
        return true;
    }

    /**
     * AJAX handler for getting public services.
     */
    public function ajax_get_services() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting.
        $rate_check = $this->check_rate_limit( 'services' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $services = $catalog->get_appointment_services();

        if ( is_wp_error( $services ) ) {
            wp_send_json_error( array( 'message' => $services->get_error_message() ) );
        }

        $formatted = array();
        foreach ( ( $services['objects'] ?? array() ) as $service ) {
            $item_data = $service['item_data'];
            $variations = $item_data['variations'] ?? array();
            $first_var = $variations[0]['item_variation_data'] ?? array();
            
            $formatted[] = array(
                'id'          => $variations[0]['id'], // Use variation ID for booking
                'name'        => $item_data['name'],
                'description' => $item_data['description'] ?? '',
                'duration'    => ( $first_var['service_duration'] ?? 0 ) / 60000, // ms to mins
                'price'       => ( $first_var['price_money']['amount'] ?? 0 ) / 100,
                'currency'    => $first_var['price_money']['currency'] ?? 'USD',
            );
        }

        wp_send_json_success( array( 'services' => $formatted ) );
    }

    /**
     * AJAX handler for fetching bookable staff members.
     */
    public function ajax_get_bookable_staff() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting.
        $rate_check = $this->check_rate_limit( 'bookable_staff' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }

        $bookings_api = new SPP_Bookings();
        $location_id  = get_option( 'spp_default_location_id', '' );
        $response     = $bookings_api->list_team_member_booking_profiles( true, $location_id );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $profiles  = $response['team_member_booking_profiles'] ?? array();
        $formatted = array();
        foreach ( $profiles as $profile ) {
            $formatted[] = array(
                'id'                => $profile['team_member_id'] ?? '',
                'display_name'      => $profile['display_name'] ?? '',
                'description'       => $profile['description'] ?? '',
                'profile_image_url' => $profile['profile_image_url'] ?? '',
            );
        }

        wp_send_json_success( array( 'staff' => $formatted ) );
    }

    /**
     * AJAX handler for searching availability.
     */
    public function ajax_get_availability() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting.
        $rate_check = $this->check_rate_limit( 'availability' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        $start_at = sanitize_text_field( $_POST['start_at'] ?? '' );
        $end_at = sanitize_text_field( $_POST['end_at'] ?? '' );
        $service_id = sanitize_text_field( $_POST['service_variation_id'] ?? '' );
        $team_member_id = sanitize_text_field( $_POST['team_member_id'] ?? '' ); // Optional

        if ( empty( $start_at ) || empty( $end_at ) || empty( $service_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'cube-payment-portal' ) ) );
        }

        // Clamp end_at to max advance booking days.
        $max_advance_days = (int) get_option( 'spp_booking_max_advance_days', 90 );
        $max_end          = strtotime( '+' . $max_advance_days . ' days' );
        $requested_end    = strtotime( $end_at );
        if ( $requested_end > $max_end ) {
            $end_at = gmdate( 'Y-m-d\TH:i:s\Z', $max_end );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }

        $bookings_api = new SPP_Bookings();

        // Build query segment filter.
        $segment_filter = array(
            'service_variation_id' => $service_id,
        );
        if ( ! empty( $team_member_id ) ) {
            $segment_filter['team_member_id_filter'] = array( 'any' => array( $team_member_id ) );
        }

        $query = array(
            'filter' => array(
                'start_at_range' => array(
                    'start_at' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $start_at ) ),
                    'end_at'   => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $end_at ) ),
                ),
                'segment_filters' => array( $segment_filter ),
                'location_id' => get_option( 'spp_default_location_id' ),
            ),
        );

        $availabilities = $bookings_api->search_availability( $query );

        if ( is_wp_error( $availabilities ) ) {
            wp_send_json_error( array( 'message' => $availabilities->get_error_message() ) );
        }

        wp_send_json_success( array( 'availabilities' => $availabilities ) );
    }

    /**
     * AJAX handler for creating an appointment.
     */
    public function ajax_create_appointment() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting - stricter for write operations.
        $rate_check = $this->check_rate_limit( 'appointment' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        // Basic validation.
        $start_at        = sanitize_text_field( $_POST['start_at'] ?? '' );
        $service_id      = sanitize_text_field( $_POST['service_variation_id'] ?? '' );
        $customer_id     = sanitize_text_field( $_POST['customer_id'] ?? '' );
        $team_member_id  = sanitize_text_field( $_POST['team_member_id'] ?? '' );
        $location_id     = get_option( 'spp_default_location_id' );

        if ( empty( $start_at ) || empty( $service_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $team_member_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Team member is required. Please select a time slot.', 'cube-payment-portal' ) ) );
        }

        // Validate max advance booking days.
        $max_advance_days = (int) get_option( 'spp_booking_max_advance_days', 90 );
        $max_booking_time = strtotime( '+' . $max_advance_days . ' days' );
        if ( strtotime( $start_at ) > $max_booking_time ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %d: number of days */
                    __( 'Appointments cannot be booked more than %d days in advance.', 'cube-payment-portal' ),
                    $max_advance_days
                ),
            ) );
        }

        // If customer_id is empty, try to get from logged in user.
        if ( empty( $customer_id ) && is_user_logged_in() ) {
            $customer_id = get_user_meta( get_current_user_id(), 'spp_square_customer_id', true );
        }

        if ( empty( $customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Customer identification required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }

        $bookings_api = new SPP_Bookings();

        // Fetch service details to get duration and version.
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }
        $catalog   = new SPP_Catalog();
        $variation = $catalog->get_item_details( $service_id );

        if ( is_wp_error( $variation ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid service.', 'cube-payment-portal' ) ) );
        }

        $var_data     = $variation['item_variation_data'] ?? array();
        $duration_ms  = $var_data['service_duration'] ?? 0;
        $duration_min = max( 15, (int) ( $duration_ms / 60000 ) );
        $version      = $variation['version'] ?? null;

        // Build the appointment segment.
        $segment = array(
            'duration_minutes'      => $duration_min,
            'service_variation_id'  => $service_id,
            'team_member_id'        => $team_member_id,
        );

        // Square requires service_variation_version for booking creation.
        if ( $version ) {
            $segment['service_variation_version'] = (int) $version;
        }

        $booking_data = array(
            'start_at'             => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $start_at ) ),
            'location_id'          => $location_id,
            'customer_id'          => $customer_id,
            'appointment_segments' => array( $segment ),
        );

        $booking = $bookings_api->create_booking( $booking_data );

        if ( is_wp_error( $booking ) ) {
            wp_send_json_error( array( 'message' => $booking->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Appointment booked successfully!', 'cube-payment-portal' ),
            'booking' => $booking,
        ) );
    }

    /**
     * Check if current user is a portal client.
     *
     * @return bool True if client or admin.
     */
    private function is_portal_client() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $user = wp_get_current_user();
        return in_array( 'spp_client', (array) $user->roles, true ) || current_user_can( 'manage_options' );
    }

    /**
     * Get allowed views for the client portal SPA.
     *
     * @return array Mapping of view slug => partial file basename.
     */
    private function get_allowed_views() {
        $views = array(
            'dashboard'       => 'client-dashboard.php',
            'payment-methods' => 'client-payment-methods.php',
            'add-card'        => 'client-add-card.php',
            'transactions'    => 'client-transactions.php',
            'profile'         => 'client-profile.php',
        );

        // Conditionally include feature-gated views.
        if ( get_option( 'spp_feature_subscriptions', true ) ) {
            $views['subscriptions'] = 'client-subscriptions.php';
        }
        if ( get_option( 'spp_feature_invoices', true ) ) {
            $views['invoices'] = 'client-invoices.php';
        }
        if ( get_option( 'spp_feature_bookings', false ) ) {
            $views['bookings'] = 'client-bookings.php';
        }
        if ( get_option( 'spp_feature_loyalty', false ) ) {
            $views['loyalty'] = 'client-loyalty.php';
        }

        // WooCommerce views (conditional on WC being active).
        if ( class_exists( 'WooCommerce' ) ) {
            $views['wc-orders']    = 'client-wc-orders.php';
            $views['wc-downloads'] = 'client-wc-downloads.php';
        }

        return $views;
    }

    /**
     * AJAX handler for loading a portal view (SPA endpoint).
     *
     * Called by SPPPortal.components.loadView() on the client side.
     * Returns the rendered HTML for the requested view.
     */
    public function ajax_load_view() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting.
        $rate_check = $this->check_rate_limit( 'load_view' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        // Must be a portal client.
        if ( ! $this->is_portal_client() ) {
            wp_send_json_error( array( 'message' => __( 'Access denied.', 'cube-payment-portal' ) ), 403 );
        }

        $view = sanitize_text_field( wp_unslash( $_POST['view'] ?? '' ) );
        $params = json_decode( sanitize_text_field( wp_unslash( $_POST['params'] ?? '{}' ) ), true );

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $allowed_views = $this->get_allowed_views();

        if ( empty( $view ) || ! isset( $allowed_views[ $view ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid view.', 'cube-payment-portal' ) ) );
        }

        $partial_file = SPP_PLUGIN_DIR . 'public/partials/' . $allowed_views[ $view ];

        if ( ! file_exists( $partial_file ) ) {
            wp_send_json_error( array( 'message' => __( 'View template not found.', 'cube-payment-portal' ) ) );
        }

        // Make params available to the template.
        $spp_params = $params;

        ob_start();
        include $partial_file;
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * AJAX handler for refreshing a portal component.
     *
     * Called by SPPPortal.components.refresh() on the client side.
     * Returns the rendered HTML for a specific component/widget.
     */
    public function ajax_refresh_component() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        // Rate limiting.
        $rate_check = $this->check_rate_limit( 'refresh_component' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
        }

        // Must be a portal client.
        if ( ! $this->is_portal_client() ) {
            wp_send_json_error( array( 'message' => __( 'Access denied.', 'cube-payment-portal' ) ), 403 );
        }

        $component = sanitize_text_field( wp_unslash( $_POST['component'] ?? '' ) );
        $params = json_decode( sanitize_text_field( wp_unslash( $_POST['params'] ?? '{}' ) ), true );

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        // Map component IDs to partial files (widget fragments).
        $allowed_components = array(
            'upcoming-appointments'  => 'widgets/widget-appointments.php',
            'recent-invoices'        => 'widgets/widget-invoices.php',
            'subscription-status'    => 'widgets/widget-subscriptions.php',
            'loyalty-points'         => 'widgets/widget-loyalty.php',
            'quick-actions'          => 'widgets/widget-quick-actions.php',
            'outstanding-balance'    => 'widgets/widget-balance.php',
            'payment-methods-summary'=> 'widgets/widget-payment-methods.php',
            'wc-recent-orders'       => 'widgets/widget-wc-orders.php',
        );

        if ( empty( $component ) || ! isset( $allowed_components[ $component ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid component.', 'cube-payment-portal' ) ) );
        }

        $partial_file = SPP_PLUGIN_DIR . 'public/partials/' . $allowed_components[ $component ];

        if ( ! file_exists( $partial_file ) ) {
            wp_send_json_error( array( 'message' => __( 'Component template not found.', 'cube-payment-portal' ) ) );
        }

        $spp_params = $params;

        ob_start();
        include $partial_file;
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Verify subscription ownership.
     *
     * Ensures the subscription belongs to the current user's Square customer ID.
     *
     * @param string $subscription_id Subscription ID.
     * @return true|WP_Error True if owned, WP_Error otherwise.
     */
    private function verify_subscription_ownership( $subscription_id ) {
        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            return new WP_Error( 'no_customer', __( 'Account not linked.', 'cube-payment-portal' ) );
        }

        if ( ! class_exists( 'SPP_Subscriptions' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
        }

        // Search customer's subscriptions to verify ownership.
        $subs_api = new SPP_Subscriptions();
        $all_subs = $subs_api->get_customer_subscriptions( $square_customer_id );

        if ( is_wp_error( $all_subs ) ) {
            return $all_subs;
        }

        foreach ( $all_subs as $sub ) {
            if ( ( $sub['id'] ?? '' ) === $subscription_id ) {
                return true;
            }
        }

        return new WP_Error( 'forbidden', __( 'You do not have permission to manage this subscription.', 'cube-payment-portal' ) );
    }

    /**
     * AJAX handler: Pause subscription.
     */
    public function ajax_pause_subscription() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'pause_subscription' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $subscription_id = sanitize_text_field( wp_unslash( $_POST['subscription_id'] ?? '' ) );
        if ( empty( $subscription_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Subscription ID is required.', 'cube-payment-portal' ) ), 400 );
        }

        $sub = $this->verify_subscription_ownership( $subscription_id );
        if ( is_wp_error( $sub ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Pause Error - Ownership Check Failed: ' . $sub->get_error_message() );
            }
            wp_send_json_error( array( 'message' => $sub->get_error_message() ), 403 );
        }

        $subs_api = new SPP_Subscriptions();
        $result   = $subs_api->pause_subscription( $subscription_id );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Pause Error - Square API Failed: ' . $result->get_error_message() );
            }
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Subscription paused.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler: Resume subscription.
     */
    public function ajax_resume_subscription() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'resume_subscription' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $subscription_id = sanitize_text_field( wp_unslash( $_POST['subscription_id'] ?? '' ) );
        if ( empty( $subscription_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Subscription ID is required.', 'cube-payment-portal' ) ), 400 );
        }

        $sub = $this->verify_subscription_ownership( $subscription_id );
        if ( is_wp_error( $sub ) ) {
            wp_send_json_error( array( 'message' => $sub->get_error_message() ), 403 );
        }

        $subs_api = new SPP_Subscriptions();
        $result   = $subs_api->resume_subscription( $subscription_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Subscription resumed.', 'cube-payment-portal' ) ) );
    }

    /**
     * Verify card ownership for the current user.
     *
     * Ensures the card belongs to the current user's Square customer.
     *
     * @param string $card_id Card ID.
     * @return true|WP_Error True if owned, WP_Error otherwise.
     */
    private function verify_card_ownership( $card_id ) {
        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            return new WP_Error( 'no_customer', __( 'Account not linked.', 'cube-payment-portal' ) );
        }

        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }

        $cards_api = new SPP_Cards();
        $card      = $cards_api->get_card( $card_id );

        if ( is_wp_error( $card ) ) {
            return $card;
        }

        if ( ( $card['customer_id'] ?? '' ) !== $square_customer_id ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to manage this card.', 'cube-payment-portal' ) );
        }

        return true;
    }

    /**
     * Record a gift card transaction in the local database.
     *
     * @param float  $amount             Amount in dollars.
     * @param string $card_id            Payment card ID used.
     * @param string $source_type        'gift_card_purchase' or 'gift_card_reload'.
     * @param string $gift_card_id       Square gift card ID.
     * @param string $note               Description for the transaction.
     */
    private function record_gift_card_transaction( $amount, $card_id, $source_type, $gift_card_id, $note = '', $activity_id = '' ) {
        global $wpdb;

        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        // Fetch card details for brand/last4.
        $card_last_four = '';
        $card_brand     = '';
        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }
        $cards_api = new SPP_Cards();
        $card      = $cards_api->get_card( $card_id );
        if ( ! is_wp_error( $card ) ) {
            $card_last_four = $card['last_4'] ?? '';
            $card_brand     = $card['card_brand'] ?? '';
        }

        $table = $wpdb->prefix . 'spp_transactions';
        $wpdb->insert(
            $table,
            array(
                'user_id'            => $user_id,
                'square_customer_id' => $square_customer_id,
                'square_payment_id'  => ! empty( $activity_id ) ? 'gca_' . $activity_id : 'gc_' . $gift_card_id . '_' . time(),
                'amount'             => $amount,
                'currency'           => 'USD',
                'status'             => 'completed',
                'card_last_four'     => $card_last_four,
                'card_brand'         => $card_brand,
                'source_type'        => $source_type,
                'source_id'          => $gift_card_id,
                'created_at'         => current_time( 'mysql' ),
                'metadata'           => wp_json_encode( array( 'note' => $note ) ),
            ),
            array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Ensure a Square customer and WP account exist for a gift card recipient.
     *
     * Handles three cases:
     *   1. Active WP user exists → reuse, ensure Square customer linked.
     *   2. Placeholder WP user exists → reuse, generate activation URL.
     *   3. No WP user → create Square customer + WP placeholder.
     *
     * @param string $recipient_email Recipient email address.
     * @param string $recipient_name  Recipient full name.
     * @return array {
     *     @type string $square_customer_id Square customer ID.
     *     @type int    $wp_user_id         WordPress user ID (0 on failure).
     *     @type string $activation_url     Activation URL (empty if not needed).
     * }
     */
    private function ensure_recipient_account( $recipient_email, $recipient_name ) {
        if ( ! class_exists( 'SPP_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
        }

        $customers_api = new SPP_Customers();

        // Parse name into first/last.
        $name_parts = explode( ' ', trim( $recipient_name ), 2 );
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';

        $result = array(
            'square_customer_id' => '',
            'wp_user_id'         => 0,
            'activation_url'     => '',
        );

        // Check for an existing WordPress user with this email.
        $existing_user = get_user_by( 'email', $recipient_email );

        if ( $existing_user ) {
            $result['wp_user_id'] = $existing_user->ID;
            $is_placeholder       = in_array( 'spp_client_placeholder', (array) $existing_user->roles, true );

            // Ensure they have a Square customer (creates one if missing).
            $sq_id = $customers_api->ensure_customer_for_user( $existing_user->ID );
            if ( ! is_wp_error( $sq_id ) ) {
                $result['square_customer_id'] = $sq_id;
            }

            // Generate activation URL for placeholder accounts.
            if ( $is_placeholder ) {
                $reset_key = get_password_reset_key( $existing_user );
                if ( ! is_wp_error( $reset_key ) ) {
                    $result['activation_url'] = network_site_url(
                        'wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode( $existing_user->user_login ),
                        'login'
                    );
                }
            }

            return $result;
        }

        // No WP user exists — resolve or create the Square customer.
        $square_customer = $customers_api->get_customer_by_email( $recipient_email );

        if ( is_wp_error( $square_customer ) ) {
            // No existing Square customer — create one.
            $square_customer = $customers_api->create_customer( array(
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $recipient_email,
            ) );

            if ( is_wp_error( $square_customer ) ) {
                return $result; // Square customer creation failed — return empty.
            }
        }

        $result['square_customer_id'] = $square_customer['id'] ?? '';

        // Create WordPress placeholder user (same pattern as admin/class-spp-admin.php).
        $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
        if ( empty( $username ) ) {
            $username = sanitize_user( strtolower( strstr( $recipient_email, '@', true ) ), true );
        }
        if ( empty( $username ) ) {
            $username = 'client';
        }

        $base_username = $username;
        $counter       = 1;
        while ( username_exists( $username ) ) {
            $username = $base_username . $counter;
            $counter++;
        }

        $password   = wp_generate_password( 24, true, true );
        $wp_user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $recipient_email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
            'role'         => 'spp_client_placeholder',
        ) );

        if ( is_wp_error( $wp_user_id ) ) {
            return $result; // WP user creation failed — gift card still purchased.
        }

        $result['wp_user_id'] = $wp_user_id;

        // Link WP user to Square customer.
        update_user_meta( $wp_user_id, 'spp_square_customer_id', $result['square_customer_id'] );
        update_user_meta( $wp_user_id, 'spp_placeholder_account', true );

        // Sync to local customers table.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'spp_customers',
            array( 'wp_user_id' => $wp_user_id ),
            array( 'square_customer_id' => $result['square_customer_id'] ),
            array( '%d' ),
            array( '%s' )
        );

        // Generate activation URL.
        $user_obj  = get_userdata( $wp_user_id );
        $reset_key = get_password_reset_key( $user_obj );
        if ( ! is_wp_error( $reset_key ) ) {
            $result['activation_url'] = network_site_url(
                'wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode( $user_obj->user_login ),
                'login'
            );
        }

        return $result;
    }

    /**
     * AJAX handler: Save a card on file.
     */
    public function ajax_save_card() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'save_card' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Card token is required.', 'cube-payment-portal' ) ), 400 );
        }

        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Your account is not linked to a customer profile. Please contact support.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }

        $cards_api = new SPP_Cards();

        if ( ! $cards_api->validate_token( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid card token.', 'cube-payment-portal' ) ), 400 );
        }

        $result = $cards_api->create_card( $square_customer_id, $token );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Card saved successfully.', 'cube-payment-portal' ),
            'card'    => $cards_api->format_card_for_display( $result ),
        ) );
    }

    /**
     * AJAX handler: Delete (disable) a card.
     */
    public function ajax_delete_card() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'delete_card' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $card_id = sanitize_text_field( wp_unslash( $_POST['card_id'] ?? '' ) );
        if ( empty( $card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Card ID is required.', 'cube-payment-portal' ) ), 400 );
        }

        $ownership = $this->verify_card_ownership( $card_id );
        if ( is_wp_error( $ownership ) ) {
            wp_send_json_error( array( 'message' => $ownership->get_error_message() ), 403 );
        }

        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }

        $cards_api = new SPP_Cards();
        $result    = $cards_api->delete_card( $card_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Card removed successfully.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler: Purchase a gift card.
     */
    public function ajax_purchase_gift_card() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'purchase_gift_card' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $amount          = floatval( $_POST['amount'] ?? 0 );
        $card_id         = sanitize_text_field( wp_unslash( $_POST['card_id'] ?? '' ) );
        $recipient_type  = sanitize_text_field( wp_unslash( $_POST['recipient_type'] ?? 'self' ) );
        $recipient_name  = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
        $recipient_email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );

        // Validate amount ($1 – $2,000).
        if ( $amount < 1 || $amount > 2000 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid amount. Must be between $1 and $2,000.', 'cube-payment-portal' ) ), 400 );
        }

        if ( empty( $card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Please select a payment card.', 'cube-payment-portal' ) ), 400 );
        }

        if ( 'other' === $recipient_type && empty( $recipient_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Recipient email is required.', 'cube-payment-portal' ) ), 400 );
        }

        // Verify card belongs to user.
        $ownership = $this->verify_card_ownership( $card_id );
        if ( is_wp_error( $ownership ) ) {
            wp_send_json_error( array( 'message' => $ownership->get_error_message() ), 403 );
        }

        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
        $location_id        = get_option( 'spp_default_location_id', '' );

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment processing is not configured. Please contact support.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
        }

        $gc_api     = new SPP_Gift_Cards();
        $amount_cents = (int) round( $amount * 100 );

        // Step 1: Create an empty digital gift card.
        $gift_card = $gc_api->create_gift_card( $location_id, 'DIGITAL' );
        if ( is_wp_error( $gift_card ) ) {
            wp_send_json_error( array( 'message' => $gift_card->get_error_message() ) );
        }

        $gift_card_id = $gift_card['id'] ?? '';
        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Failed to create gift card.', 'cube-payment-portal' ) ) );
        }

        // Step 2: Activate (fund) the gift card using the saved card.
        $activation = $gc_api->activate_gift_card( $gift_card_id, $amount_cents, $location_id, 'USD', $card_id );
        if ( is_wp_error( $activation ) ) {
            wp_send_json_error( array( 'message' => $activation->get_error_message() ) );
        }

        $activation_activity_id = $activation['id'] ?? '';
        $gan     = $gift_card['gan'] ?? ( $activation['gift_card']['gan'] ?? '' );
        $balance = $amount;

        // Step 3: Link to customer or send via email.
        if ( 'self' === $recipient_type ) {
            // Link the gift card to the current user's Square customer.
            if ( ! empty( $square_customer_id ) ) {
                $gc_api->link_customer( $gift_card_id, $square_customer_id );
            }
        } else {
            // Ensure the recipient has a Square customer + WP account.
            $recipient_result    = $this->ensure_recipient_account( $recipient_email, $recipient_name );
            $recipient_square_id = $recipient_result['square_customer_id'];

            // Link the gift card to the recipient's Square customer.
            if ( ! empty( $recipient_square_id ) ) {
                $gc_api->link_customer( $gift_card_id, $recipient_square_id );
            }

            // Build and send the email.
            $user        = wp_get_current_user();
            $sender_name = ! empty( $user->first_name ) ? $user->first_name . ' ' . $user->last_name : $user->display_name;
            $site_name   = get_bloginfo( 'name' );

            $subject = sprintf(
                /* translators: %s: sender name */
                __( '%s sent you a gift card!', 'cube-payment-portal' ),
                $sender_name
            );

            // Build the activation block (only for unactivated accounts).
            $activation_block = '';
            if ( ! empty( $recipient_result['activation_url'] ) ) {
                $activation_block = sprintf(
                    '<div style="margin:24px 0;text-align:center;">' .
                    '<a href="%s" style="display:inline-block;padding:12px 32px;background:#006aff;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">%s</a>' .
                    '<p style="margin:12px 0 0;color:#666;font-size:13px;">%s</p>' .
                    '</div>',
                    esc_url( $recipient_result['activation_url'] ),
                    esc_html__( 'Activate Your Account', 'cube-payment-portal' ),
                    esc_html__( 'Set your password to access your account and manage your gift card.', 'cube-payment-portal' )
                );
            }

            $body = sprintf(
                "<div style=\"font-family:sans-serif;max-width:480px;margin:0 auto;padding:32px;\">\n" .
                "<h2 style=\"color:#333;\">%s</h2>\n" .
                "%s" .
                "<p>%s sent you a <strong>\$%s</strong> gift card from %s.</p>\n" .
                "<div style=\"background:#f5f5f5;border-radius:8px;padding:20px;text-align:center;margin:24px 0;\">\n" .
                "<p style=\"margin:0 0 8px;color:#666;font-size:13px;\">Gift Card Number</p>\n" .
                "<p style=\"margin:0;font-size:22px;font-weight:700;letter-spacing:2px;color:#333;\">%s</p>\n" .
                "</div>\n" .
                "%s" .
                "<p style=\"color:#999;font-size:12px;\">This gift card was purchased through %s.</p>\n" .
                "</div>",
                esc_html__( 'You received a gift card!', 'cube-payment-portal' ),
                ! empty( $recipient_name ) ? '<p>Hi ' . esc_html( $recipient_name ) . ',</p>' : '',
                esc_html( $sender_name ),
                esc_html( number_format( $amount, 2 ) ),
                esc_html( $site_name ),
                esc_html( $gan ),
                $activation_block,
                esc_html( $site_name )
            );

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            wp_mail( $recipient_email, $subject, $body, $headers );
        }

        // Record transaction in local database.
        $gc_note = 'self' === $recipient_type
            ? sprintf( 'Gift Card Purchase (•••• %s)', substr( $gan, -4 ) )
            : sprintf( 'Gift Card Purchase for %s', $recipient_email );
        $this->record_gift_card_transaction( $amount, $card_id, 'gift_card_purchase', $gift_card_id, $gc_note, $activation_activity_id );

        wp_send_json_success( array(
            'message' => __( 'Gift card purchased successfully!', 'cube-payment-portal' ),
            'balance' => number_format( $balance, 2 ),
            'gan'     => $gan,
        ) );
    }

    /**
     * AJAX handler: Reload (add funds to) an existing gift card.
     */
    public function ajax_reload_gift_card() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'reload_gift_card' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $gift_card_id = sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ?? '' ) );
        $amount       = floatval( $_POST['amount'] ?? 0 );
        $card_id      = sanitize_text_field( wp_unslash( $_POST['card_id'] ?? '' ) );

        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID is required.', 'cube-payment-portal' ) ), 400 );
        }

        if ( $amount < 1 || $amount > 2000 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid amount. Must be between $1 and $2,000.', 'cube-payment-portal' ) ), 400 );
        }

        if ( empty( $card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Please select a payment card.', 'cube-payment-portal' ) ), 400 );
        }

        // Verify card belongs to user.
        $ownership = $this->verify_card_ownership( $card_id );
        if ( is_wp_error( $ownership ) ) {
            wp_send_json_error( array( 'message' => $ownership->get_error_message() ), 403 );
        }

        // Verify the gift card belongs to this customer.
        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
        }

        $gc_api    = new SPP_Gift_Cards();
        $gift_card = $gc_api->get_gift_card( $gift_card_id );

        if ( is_wp_error( $gift_card ) ) {
            wp_send_json_error( array( 'message' => $gift_card->get_error_message() ) );
        }

        // Ensure the gift card is linked to this customer.
        $gc_customers = $gift_card['customer_ids'] ?? array();
        if ( ! in_array( $square_customer_id, $gc_customers, true ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to reload this gift card.', 'cube-payment-portal' ) ), 403 );
        }

        $location_id  = get_option( 'spp_default_location_id', '' );
        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment processing is not configured. Please contact support.', 'cube-payment-portal' ) ) );
        }

        $amount_cents = (int) round( $amount * 100 );

        $result = $gc_api->load_gift_card( $gift_card_id, $amount_cents, $location_id, 'USD', $card_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $reload_activity_id = $result['id'] ?? '';

        // Calculate new balance from the activity response or from original + added.
        $new_balance_cents = $result['gift_card_balance_money']['amount'] ?? null;
        if ( null !== $new_balance_cents ) {
            $new_balance = number_format( $new_balance_cents / 100, 2 );
        } else {
            $old_balance_cents = $gift_card['balance_money']['amount'] ?? 0;
            $new_balance = number_format( ( $old_balance_cents + $amount_cents ) / 100, 2 );
        }

        // Record transaction in local database.
        $gc_gan  = $gift_card['gan'] ?? '';
        $gc_last = strlen( $gc_gan ) >= 4 ? substr( $gc_gan, -4 ) : $gc_gan;
        $this->record_gift_card_transaction( $amount, $card_id, 'gift_card_reload', $gift_card_id, sprintf( 'Gift Card Reload (•••• %s)', $gc_last ), $reload_activity_id );

        wp_send_json_success( array(
            'message' => __( 'Gift card reloaded successfully!', 'cube-payment-portal' ),
            'balance' => $new_balance,
        ) );
    }

    /**
     * AJAX handler: Cancel subscription.
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'cancel_subscription' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $subscription_id = sanitize_text_field( wp_unslash( $_POST['subscription_id'] ?? '' ) );
        if ( empty( $subscription_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Subscription ID is required.', 'cube-payment-portal' ) ), 400 );
        }

        $sub = $this->verify_subscription_ownership( $subscription_id );
        if ( is_wp_error( $sub ) ) {
            wp_send_json_error( array( 'message' => $sub->get_error_message() ), 403 );
        }

        $subs_api = new SPP_Subscriptions();
        $result   = $subs_api->cancel_subscription( $subscription_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Subscription canceled.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler: Cancel a booking/appointment.
     */
    public function ajax_cancel_booking() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'cancel_booking' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        // Check that cancellation is enabled.
        if ( ! get_option( 'spp_allow_booking_cancellation', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Appointment cancellation is not enabled.', 'cube-payment-portal' ) ), 403 );
        }

        $booking_id = sanitize_text_field( wp_unslash( $_POST['booking_id'] ?? '' ) );

        if ( empty( $booking_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid booking data.', 'cube-payment-portal' ) ), 400 );
        }

        // Verify the booking belongs to this customer.
        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Your account is not linked to a customer profile.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }

        $bookings_api = new SPP_Bookings();
        $booking      = $bookings_api->get_booking( $booking_id );

        if ( is_wp_error( $booking ) ) {
            wp_send_json_error( array( 'message' => $booking->get_error_message() ) );
        }

        if ( ( $booking['customer_id'] ?? '' ) !== $square_customer_id ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to cancel this appointment.', 'cube-payment-portal' ) ), 403 );
        }

        // Check cancellation window.
        $start_at = $booking['start_at'] ?? '';
        if ( ! empty( $start_at ) ) {
            $start_time          = strtotime( $start_at );
            $hours_until_start   = ( $start_time - time() ) / 3600;
            $cancellation_window = (int) get_option( 'spp_cancellation_window_hours', 24 );

            if ( $hours_until_start <= $cancellation_window ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: %d: number of hours */
                        __( 'Appointments can only be canceled at least %d hours in advance.', 'cube-payment-portal' ),
                        $cancellation_window
                    ),
                ) );
            }
        }

        // Use the version from the freshly-fetched booking (ListBookings may not include it).
        $booking_version = (int) ( $booking['version'] ?? 0 );

        $result = $bookings_api->cancel_booking( $booking_id, $booking_version );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Square marks all API-initiated cancellations as CANCELLED_BY_SELLER.
        // Override the local DB status to reflect that the client initiated it.
        SPP_Database::save_booking( array(
            'square_id' => $booking_id,
            'status'    => 'CANCELLED_BY_CUSTOMER',
        ) );

        wp_send_json_success( array( 'message' => __( 'Appointment canceled.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler: Redeem loyalty reward.
     */
    public function ajax_redeem_loyalty_reward() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        $rate_check = $this->check_rate_limit( 'redeem_loyalty' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $reward_tier_id = sanitize_text_field( wp_unslash( $_POST['reward_tier_id'] ?? '' ) );
        if ( empty( $reward_tier_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Reward tier is required.', 'cube-payment-portal' ) ), 400 );
        }

        $user_id            = get_current_user_id();
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Your account is not linked to a customer profile. Please contact support.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();

        // Get the customer's loyalty account.
        $account = $loyalty->get_account_by_customer( $square_customer_id );
        if ( is_wp_error( $account ) ) {
            wp_send_json_error( array( 'message' => $account->get_error_message() ) );
        }
        if ( empty( $account ) ) {
            wp_send_json_error( array( 'message' => __( 'No loyalty account found. Please contact support.', 'cube-payment-portal' ) ) );
        }

        $account_id     = $account['id'];
        $points_balance = $account['balance'] ?? 0;

        // Validate the tier exists and user has enough points.
        $program = $loyalty->get_program();
        if ( is_wp_error( $program ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not load loyalty program. Please try again later.', 'cube-payment-portal' ) ) );
        }

        $reward_tiers   = $program['reward_tiers'] ?? array();
        $selected_tier  = null;
        foreach ( $reward_tiers as $tier ) {
            if ( ( $tier['id'] ?? '' ) === $reward_tier_id ) {
                $selected_tier = $tier;
                break;
            }
        }

        if ( ! $selected_tier ) {
            wp_send_json_error( array( 'message' => __( 'Invalid reward tier.', 'cube-payment-portal' ) ), 400 );
        }

        $required_points = $selected_tier['points'] ?? 0;
        if ( $points_balance < $required_points ) {
            wp_send_json_error( array( 'message' => __( 'You do not have enough points to redeem this reward.', 'cube-payment-portal' ) ) );
        }

        // Create the reward (deducts points in Square, status becomes ISSUED).
        $reward = $loyalty->create_reward( $reward_tier_id, $account_id );
        if ( is_wp_error( $reward ) ) {
            wp_send_json_error( array( 'message' => $reward->get_error_message() ) );
        }

        // Get updated balance.
        $updated_account = $loyalty->get_account_by_customer( $square_customer_id );
        $new_balance     = ! is_wp_error( $updated_account ) ? ( $updated_account['balance'] ?? 0 ) : ( $points_balance - $required_points );

        wp_send_json_success( array(
            'message'     => sprintf(
                /* translators: %s: reward name */
                __( '%s claimed! It will be applied at your next visit.', 'cube-payment-portal' ),
                $selected_tier['name'] ?? __( 'Reward', 'cube-payment-portal' )
            ),
            'new_balance' => $new_balance,
        ) );
    }

    /**
     * AJAX: Save client profile.
     *
     * Updates WordPress user data, user meta, and syncs to Square.
     */
    public function ajax_save_profile() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize inputs.
        $first_name      = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name       = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $email           = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone           = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $company         = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
        $birthday        = sanitize_text_field( wp_unslash( $_POST['birthday'] ?? '' ) );
        $address_line    = sanitize_text_field( wp_unslash( $_POST['address_line'] ?? '' ) );
        $address_city    = sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) );
        $address_state   = sanitize_text_field( wp_unslash( $_POST['address_state'] ?? '' ) );
        $address_zip     = sanitize_text_field( wp_unslash( $_POST['address_zip'] ?? '' ) );
        $address_country = sanitize_text_field( wp_unslash( $_POST['address_country'] ?? '' ) );

        // Validate email if provided.
        if ( ! empty( $email ) ) {
            $existing_user = get_user_by( 'email', $email );
            if ( $existing_user && $existing_user->ID !== $user_id ) {
                wp_send_json_error( array( 'message' => __( 'That email address is already in use by another account.', 'cube-payment-portal' ) ) );
            }
        }

        // Validate birthday format (YYYY-MM-DD) if provided.
        if ( ! empty( $birthday ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthday ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid birthday format.', 'cube-payment-portal' ) ) );
        }

        // Update WordPress user data.
        $user_data = array( 'ID' => $user_id );

        if ( $first_name !== '' || $last_name !== '' ) {
            $user_data['first_name']   = $first_name;
            $user_data['last_name']    = $last_name;
            $user_data['display_name'] = trim( $first_name . ' ' . $last_name ) ?: get_userdata( $user_id )->user_login;
        }

        if ( ! empty( $email ) ) {
            $user_data['user_email'] = $email;
        }

        $wp_result = wp_update_user( $user_data );
        if ( is_wp_error( $wp_result ) ) {
            wp_send_json_error( array( 'message' => $wp_result->get_error_message() ) );
        }

        // Update phone in user meta.
        update_user_meta( $user_id, 'billing_phone', $phone );

        // Sync to Square if the user has a linked customer.
        $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
        $square_sync_failed = false;

        if ( ! empty( $square_customer_id ) ) {
            if ( ! class_exists( 'SPP_Customers' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
            }

            $customers_api = new SPP_Customers();

            // Build Square update data — only include non-empty optional fields
            // to avoid Square API validation errors (e.g. empty phone_number).
            $square_data = array();

            if ( $first_name !== '' ) {
                $square_data['first_name'] = $first_name;
            }
            if ( $last_name !== '' ) {
                $square_data['last_name'] = $last_name;
            }
            if ( ! empty( $email ) ) {
                $square_data['email'] = $email;
            }
            if ( $phone !== '' ) {
                $square_data['phone'] = $phone;
            }
            if ( $company !== '' ) {
                $square_data['company_name'] = $company;
            }
            if ( ! empty( $birthday ) ) {
                $square_data['birthday'] = $birthday;
            }

            // Build address object for Square.
            $address = array();
            if ( $address_line !== '' ) {
                $address['address_line_1'] = $address_line;
            }
            if ( $address_city !== '' ) {
                $address['locality'] = $address_city;
            }
            if ( $address_state !== '' ) {
                $address['administrative_district_level_1'] = $address_state;
            }
            if ( $address_zip !== '' ) {
                $address['postal_code'] = $address_zip;
            }
            if ( $address_country !== '' ) {
                $address['country'] = $address_country;
            }
            if ( ! empty( $address ) ) {
                $square_data['address'] = $address;
            }

            if ( ! empty( $square_data ) ) {
                $square_result = $customers_api->update_customer( $square_customer_id, $square_data );

                if ( is_wp_error( $square_result ) ) {
                    $square_sync_failed = true;
                }
            }

            // Persist to local spp_customers table so profile displays fresh data.
            global $wpdb;
            $table = $wpdb->prefix . 'spp_customers';

            $local_data = array(
                'given_name'          => $first_name,
                'family_name'         => $last_name,
                'email'               => ! empty( $email ) ? $email : '',
                'phone'               => $phone,
                'company_name'        => $company,
                'birthday'            => $birthday,
                'address_line_1'      => $address_line,
                'address_city'        => $address_city,
                'address_state'       => $address_state,
                'address_postal_code' => $address_zip,
                'address_country'     => $address_country,
            );

            $update_data   = $local_data;
            $update_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

            if ( ! $square_sync_failed ) {
                $update_data['synced_at'] = current_time( 'mysql' );
                $update_format[]          = '%s';
            }

            $updated = $wpdb->update(
                $table,
                $update_data,
                array( 'square_customer_id' => $square_customer_id ),
                $update_format,
                array( '%s' )
            );

            // If no row existed, insert one so future loads read from local DB.
            if ( 0 === $updated || false === $updated ) {
                $local_data['square_customer_id'] = $square_customer_id;
                $local_data['wp_user_id']         = $user_id;
                $local_data['created_at']         = current_time( 'mysql' );

                $insert_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

                if ( ! $square_sync_failed ) {
                    $local_data['synced_at'] = current_time( 'mysql' );
                    $insert_format[]         = '%s';
                }

                $wpdb->insert( $table, $local_data, $insert_format );
            }
        }

        if ( $square_sync_failed ) {
            wp_send_json_success( array(
                'message' => __( 'Profile saved locally, but failed to sync with Square. Changes will sync on next update.', 'cube-payment-portal' ),
            ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Profile updated successfully.', 'cube-payment-portal' ),
        ) );
    }

    /**
     * AJAX handler: Upload a custom avatar.
     */
    public function ajax_upload_avatar() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        if ( empty( $_FILES['avatar'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'cube-payment-portal' ) ) );
        }

        $file = $_FILES['avatar'];

        // Validate file size (2MB max).
        if ( $file['size'] > 2 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => __( 'File too large. Maximum size is 2MB.', 'cube-payment-portal' ) ) );
        }

        // Validate file type.
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $file_type     = wp_check_filetype( $file['name'] );
        $mime          = ! empty( $file_type['type'] ) ? $file_type['type'] : $file['type'];

        if ( ! in_array( $mime, $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP.', 'cube-payment-portal' ) ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => $upload['error'] ) );
        }

        $user_id = get_current_user_id();

        // Delete previous custom avatar attachment if exists.
        $old_attachment_id = get_user_meta( $user_id, 'spp_custom_avatar_id', true );
        if ( $old_attachment_id ) {
            wp_delete_attachment( $old_attachment_id, true );
        }

        // Create attachment.
        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( $file['name'] ),
            'post_status'    => 'inherit',
        ), $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Failed to save avatar.', 'cube-payment-portal' ) ) );
        }

        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

        // Store in user meta.
        update_user_meta( $user_id, 'spp_custom_avatar', $upload['url'] );
        update_user_meta( $user_id, 'spp_custom_avatar_id', $attachment_id );

        wp_send_json_success( array(
            'message'    => __( 'Profile photo updated.', 'cube-payment-portal' ),
            'avatar_url' => $upload['url'],
        ) );
    }

    /**
     * AJAX handler: Remove custom avatar.
     */
    public function ajax_remove_avatar() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $user_id       = get_current_user_id();
        $attachment_id = get_user_meta( $user_id, 'spp_custom_avatar_id', true );

        if ( $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }

        delete_user_meta( $user_id, 'spp_custom_avatar' );
        delete_user_meta( $user_id, 'spp_custom_avatar_id' );

        $default_url = get_avatar_url( $user_id, array( 'size' => 120, 'default' => 'mystery' ) );

        wp_send_json_success( array(
            'message'    => __( 'Profile photo removed.', 'cube-payment-portal' ),
            'avatar_url' => $default_url,
        ) );
    }

    /**
     * AJAX handler: Save client notification preferences.
     */
    public function ajax_save_notification_prefs() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $user_id = get_current_user_id();

        // Whitelist of allowed notification keys.
        $allowed_keys = array(
            'payment_receipts',
            'invoice_reminders',
            'subscription_updates',
            'appointment_reminders',
            'loyalty_updates',
            'promotional',
        );

        $prefs = isset( $_POST['preferences'] ) ? (array) json_decode( wp_unslash( $_POST['preferences'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        foreach ( $allowed_keys as $key ) {
            if ( isset( $prefs[ $key ] ) ) {
                update_user_meta( $user_id, 'spp_notify_' . $key, $prefs[ $key ] ? '1' : '0' );
            }
        }

        wp_send_json_success( array(
            'message' => __( 'Notification preferences saved.', 'cube-payment-portal' ),
        ) );
    }

    /**
     * AJAX handler: Save client display preferences (date/time formats).
     */
    public function ajax_save_display_prefs() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        $user_id = get_current_user_id();

        // Whitelist allowed formats.
        $allowed_date = array( 'M j, Y', 'F j, Y', 'm/d/Y', 'd/m/Y', 'Y-m-d' );
        $allowed_time = array( 'g:i a', 'g:i A', 'H:i' );

        $date_format = sanitize_text_field( wp_unslash( $_POST['date_format'] ?? '' ) );
        $time_format = sanitize_text_field( wp_unslash( $_POST['time_format'] ?? '' ) );

        if ( ! empty( $date_format ) && in_array( $date_format, $allowed_date, true ) ) {
            update_user_meta( $user_id, 'spp_date_format', $date_format );
        }

        if ( ! empty( $time_format ) && in_array( $time_format, $allowed_time, true ) ) {
            update_user_meta( $user_id, 'spp_time_format', $time_format );
        }

        wp_send_json_success( array(
            'message' => __( 'Display preferences saved.', 'cube-payment-portal' ),
        ) );
    }

    /**
     * AJAX handler: Cancel a WooCommerce order.
     */
    public function ajax_cancel_wc_order() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce is not available.', 'cube-payment-portal' ) ), 400 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'cube-payment-portal' ) ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'cube-payment-portal' ) ), 404 );
        }

        // Ownership check.
        if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to cancel this order.', 'cube-payment-portal' ) ), 403 );
        }

        // Only pending or on-hold orders can be cancelled.
        $allowed_statuses = array( 'pending', 'on-hold' );
        if ( ! in_array( $order->get_status(), $allowed_statuses, true ) ) {
            wp_send_json_error( array(
                'message' => __( 'Only pending or on-hold orders can be cancelled.', 'cube-payment-portal' ),
            ) );
        }

        $order->update_status( 'cancelled', __( 'Cancelled by customer via portal.', 'cube-payment-portal' ) );

        wp_send_json_success( array( 'message' => __( 'Order cancelled.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler: Reorder — add items from a previous WooCommerce order to the cart.
     */
    public function ajax_reorder_wc_order() {
        check_ajax_referer( 'spp_public_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'cube-payment-portal' ) ), 401 );
        }

        if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'WC' ) ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce is not available.', 'cube-payment-portal' ) ), 400 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'cube-payment-portal' ) ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'cube-payment-portal' ) ), 404 );
        }

        // Ownership check.
        if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to reorder this order.', 'cube-payment-portal' ) ), 403 );
        }

        WC()->cart->empty_cart();

        $added   = 0;
        $skipped = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                $skipped[] = $item->get_name();
                continue;
            }

            $qty = $item->get_quantity();
            WC()->cart->add_to_cart( $product->get_id(), $qty );
            $added++;
        }

        if ( 0 === $added ) {
            wp_send_json_error( array(
                'message' => __( 'None of the items from this order are currently available.', 'cube-payment-portal' ),
            ) );
        }

        $message = __( 'Items added to cart.', 'cube-payment-portal' );
        if ( ! empty( $skipped ) ) {
            $message .= ' ' . sprintf(
                /* translators: %s: comma-separated list of product names */
                __( 'Some items were unavailable: %s', 'cube-payment-portal' ),
                implode( ', ', $skipped )
            );
        }

        wp_send_json_success( array(
            'message'  => $message,
            'cart_url' => wc_get_cart_url(),
        ) );
    }

    /**
     * AJAX handler: Register a new account.
     *
     * Creates a WP user with the spp_client role and optionally creates a
     * matching Square customer, then auto-logs in the user.
     */
    public function ajax_register_account() {
        check_ajax_referer( 'spp_register_form', 'nonce' );

        // Rate limit registration attempts.
        $rate_check = $this->check_rate_limit( 'registration' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
        }

        if ( ! get_option( 'spp_enable_registration', true ) ) {
            wp_send_json_error( array( 'message' => __( 'Registration is currently disabled.', 'cube-payment-portal' ) ) );
        }

        $first_name       = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name        = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $email            = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $password         = $_POST['password'] ?? ''; // Raw — will be hashed by wp_insert_user.
        $password_confirm = $_POST['password_confirm'] ?? '';

        // Validation.
        if ( empty( $first_name ) ) {
            wp_send_json_error( array( 'message' => __( 'First name is required.', 'cube-payment-portal' ) ) );
        }
        if ( empty( $last_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Last name is required.', 'cube-payment-portal' ) ) );
        }
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'A valid email address is required.', 'cube-payment-portal' ) ) );
        }
        if ( empty( $password ) || strlen( $password ) < 8 ) {
            wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'cube-payment-portal' ) ) );
        }
        if ( $password !== $password_confirm ) {
            wp_send_json_error( array( 'message' => __( 'Passwords do not match.', 'cube-payment-portal' ) ) );
        }

        // Check if email already exists.
        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'An account with this email already exists. Please sign in instead.', 'cube-payment-portal' ) ) );
        }

        // Generate username from name.
        $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
        if ( empty( $username ) ) {
            $username = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
        }
        if ( empty( $username ) ) {
            $username = 'client';
        }

        $base_username = $username;
        $counter       = 1;
        while ( username_exists( $username ) ) {
            $username = $base_username . $counter;
            $counter++;
        }

        // Create WordPress user.
        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ),
            'role'         => 'spp_client',
        ) );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        // Try to create a matching Square customer.
        $square_customer_id = '';
        if ( ! class_exists( 'SPP_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
        }
        $api = new SPP_Square_Client();
        $customers_api = new SPP_Customers( $api );

        $square_data = array(
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
        );

        $result = $customers_api->create_customer( $square_data );
        if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
            $square_customer_id = $result['id'];
            update_user_meta( $user_id, 'spp_square_customer_id', $square_customer_id );

            // Insert into local customers table.
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'spp_customers',
                array(
                    'square_customer_id' => $square_customer_id,
                    'wp_user_id'         => $user_id,
                    'given_name'         => $first_name,
                    'family_name'        => $last_name,
                    'email'              => $email,
                    'created_at'         => current_time( 'mysql' ),
                    'synced_at'          => current_time( 'mysql' ),
                ),
                array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
            );
        }

        // Fire action for extensibility.
        do_action( 'spp_client_registered', $user_id, $square_customer_id );

        // Auto-login the new user.
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        // Determine redirect URL (use wp_validate_redirect to prevent open redirects).
        $redirect_to = '';
        if ( ! empty( $_POST['redirect_to'] ) ) {
            $redirect_to = wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), '' );
        }
        if ( empty( $redirect_to ) ) {
            $portal_page_id = get_option( 'spp_portal_page_id' );
            if ( $portal_page_id && 'publish' === get_post_status( $portal_page_id ) ) {
                $redirect_to = get_permalink( $portal_page_id );
            } else {
                $redirect_to = home_url();
            }
        }

        wp_send_json_success( array(
            'message'     => __( 'Account created successfully!', 'cube-payment-portal' ),
            'redirect_to' => $redirect_to,
        ) );
    }
}

