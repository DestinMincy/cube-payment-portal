<?php
/**
 * Client Portal functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Client_Portal
 *
 * Handles the client-facing portal functionality.
 */
class SPP_Client_Portal {

    /**
     * Register shortcodes.
     */
    public function register_shortcodes() {
        add_shortcode( 'spp_client_portal', array( $this, 'render_portal' ) );
        add_shortcode( 'spp_login_form', array( $this, 'render_login_page' ) );
        add_shortcode( 'spp_register_form', array( $this, 'render_register_page' ) );
        add_shortcode( 'spp_payment_form', array( $this, 'render_payment_form' ) );
        add_shortcode( 'spp_payment_button', array( $this, 'render_payment_button' ) );
        add_shortcode( 'spp_subscription_plans', array( $this, 'render_subscription_plans' ) );
        add_shortcode( 'spp_subscribe_button', array( $this, 'render_subscribe_button' ) );
        add_shortcode( 'spp_my_subscriptions', array( $this, 'render_my_subscriptions' ) );
        add_shortcode( 'spp_my_bookings', array( $this, 'render_my_bookings' ) );
        add_shortcode( 'spp_loyalty_points', array( $this, 'render_loyalty_points' ) );
    }

    /**
     * Render the main client portal.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_portal( $atts ) {
        $atts = shortcode_atts(
            array(
                'redirect_url' => '',
            ),
            $atts,
            'spp_client_portal'
        );

        ob_start();

        if ( is_user_logged_in() && $this->is_portal_client() ) {
            $this->render_dashboard();
        } else {
            $this->render_login_form( $atts['redirect_url'] );
        }

        return ob_get_clean();
    }

    /**
     * Get the current user's preferred date format, or fallback to site default.
     *
     * @return string
     */
    public static function get_preferred_date_format() {
        $format = get_user_meta( get_current_user_id(), 'spp_date_format', true );
        return empty( $format ) ? get_option( 'date_format', 'F j, Y' ) : $format;
    }

    /**
     * Get the current user's preferred time format, or fallback to site default.
     *
     * @return string
     */
    public static function get_preferred_time_format() {
        $format = get_user_meta( get_current_user_id(), 'spp_time_format', true );
        return empty( $format ) ? get_option( 'time_format', 'g:i a' ) : $format;
    }

    /**
     * Format a date or timestamp according to the current user's preferences.
     *
     * @param int|string $date_val The date string or timestamp.
     * @param string     $type     'date', 'time', or 'datetime'.
     * @return string Formatted date string.
     */
    public static function format_date( $date_val, $type = 'date' ) {
        if ( empty( $date_val ) ) {
            return '';
        }

        $timestamp = is_numeric( $date_val ) ? (int) $date_val : strtotime( $date_val );
        if ( ! $timestamp ) {
            return is_string( $date_val ) ? $date_val : '';
        }

        $date_format = self::get_preferred_date_format();
        $time_format = self::get_preferred_time_format();

        if ( 'date' === $type ) {
            return wp_date( $date_format, $timestamp );
        } elseif ( 'time' === $type ) {
            return wp_date( $time_format, $timestamp );
        } elseif ( 'datetime' === $type ) {
            return wp_date( $date_format . ' ' . $time_format, $timestamp );
        } else {
            return wp_date( $date_format, $timestamp );
        }
    }

    /**
     * Render the standalone login page shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_login_page( $atts ) {
        $atts = shortcode_atts(
            array(
                'redirect_to'    => '',
                'portal_page'    => '',
                'show_logo'      => 'true',
                'show_remember'  => 'true',
                'show_register'  => 'false',
                'contact_url'    => '',
            ),
            $atts,
            'spp_login_form'
        );

        // If user is already logged in, redirect to portal dashboard.
        if ( is_user_logged_in() ) {
            $redirect_url = $this->get_portal_redirect_url( $atts['redirect_to'], $atts['portal_page'] );

            // Use JavaScript redirect since we're in shortcode context (headers already sent).
            return sprintf(
                '<script>window.location.href = %s;</script>
                <div class="spp-login-redirect">
                    <p>%s</p>
                    <p><a href="%s">%s</a></p>
                </div>',
                wp_json_encode( esc_url( $redirect_url ) ),
                esc_html__( 'You are already logged in. Redirecting...', 'cube-payment-portal' ),
                esc_url( $redirect_url ),
                esc_html__( 'Click here if not redirected automatically.', 'cube-payment-portal' )
            );
        }

        // Enqueue login page specific styles and scripts.
        $this->enqueue_login_assets();

        // Prepare template variables.
        $template_vars = array(
            'redirect_to'    => $this->get_portal_redirect_url( $atts['redirect_to'], $atts['portal_page'] ),
            'show_logo'      => filter_var( $atts['show_logo'], FILTER_VALIDATE_BOOLEAN ),
            'show_remember'  => filter_var( $atts['show_remember'], FILTER_VALIDATE_BOOLEAN ),
            'show_register'  => filter_var( $atts['show_register'], FILTER_VALIDATE_BOOLEAN ),
            'contact_url'    => ! empty( $atts['contact_url'] ) ? esc_url( $atts['contact_url'] ) : '',
            'logo_url'       => $this->get_login_logo_url(),
            'login_error'    => $this->get_login_error(),
            'lost_password'  => wp_lostpassword_url( $this->get_portal_redirect_url( $atts['redirect_to'], $atts['portal_page'] ) ),
        );

        ob_start();

        // Extract vars for template.
        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        extract( $template_vars );

        include SPP_PLUGIN_DIR . 'public/partials/portal-login.php';

        return ob_get_clean();
    }

    /**
     * Render the standalone registration page shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_register_page( $atts ) {
        $atts = shortcode_atts(
            array(
                'redirect_to' => '',
                'portal_page' => '',
                'show_logo'   => 'true',
            ),
            $atts,
            'spp_register_form'
        );

        // If user is already logged in, redirect to portal dashboard.
        if ( is_user_logged_in() ) {
            $redirect_url = $this->get_portal_redirect_url( $atts['redirect_to'], $atts['portal_page'] );

            return sprintf(
                '<script>window.location.href = %s;</script>
                <div class="spp-login-redirect">
                    <p>%s</p>
                    <p><a href="%s">%s</a></p>
                </div>',
                wp_json_encode( esc_url( $redirect_url ) ),
                esc_html__( 'You are already logged in. Redirecting...', 'cube-payment-portal' ),
                esc_url( $redirect_url ),
                esc_html__( 'Click here if not redirected automatically.', 'cube-payment-portal' )
            );
        }

        // Enqueue login page styles (shared) and registration script.
        $this->enqueue_login_assets();
        $this->enqueue_register_assets();

        // Get login page URL.
        $login_url = $this->get_register_login_url();

        // Prepare template variables.
        $template_vars = array(
            'redirect_to'    => $this->get_portal_redirect_url( $atts['redirect_to'], $atts['portal_page'] ),
            'show_logo'      => filter_var( $atts['show_logo'], FILTER_VALIDATE_BOOLEAN ),
            'logo_url'       => $this->get_login_logo_url(),
            'login_url'      => $login_url,
            'register_error' => $this->get_register_error(),
        );

        ob_start();

        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        extract( $template_vars );

        include SPP_PLUGIN_DIR . 'public/partials/portal-register.php';

        return ob_get_clean();
    }

    /**
     * Get login page URL for registration page link.
     *
     * @return string Login page URL.
     */
    private function get_register_login_url() {
        $login_page_id = get_option( 'spp_login_page_id' );
        if ( $login_page_id && 'publish' === get_post_status( $login_page_id ) ) {
            return get_permalink( $login_page_id );
        }

        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( $portal_page_id && 'publish' === get_post_status( $portal_page_id ) ) {
            return get_permalink( $portal_page_id );
        }

        return wp_login_url();
    }

    /**
     * Get registration error message if any.
     *
     * @return string Error message or empty string.
     */
    private function get_register_error() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['register'] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error_code = sanitize_text_field( wp_unslash( $_GET['register'] ) );

        $error_messages = array(
            'email_exists' => __( 'An account with this email already exists.', 'cube-payment-portal' ),
            'failed'       => __( 'Registration failed. Please try again.', 'cube-payment-portal' ),
        );

        return isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : '';
    }

    /**
     * Enqueue registration page assets.
     */
    private function enqueue_register_assets() {
        wp_enqueue_script(
            'spp-portal-register',
            SPP_PLUGIN_URL . 'assets/js/portal-register.js',
            array( 'jquery' ),
            defined( 'SPP_VERSION' ) ? SPP_VERSION : '1.0.0',
            true
        );

        wp_localize_script(
            'spp-portal-register',
            'sppRegister',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'spp_register_form' ),
                'strings' => array(
                    'loading'           => __( 'Creating account...', 'cube-payment-portal' ),
                    'emptyFirstName'    => __( 'Please enter your first name.', 'cube-payment-portal' ),
                    'emptyLastName'     => __( 'Please enter your last name.', 'cube-payment-portal' ),
                    'emptyEmail'        => __( 'Please enter your email address.', 'cube-payment-portal' ),
                    'invalidEmail'      => __( 'Please enter a valid email address.', 'cube-payment-portal' ),
                    'emptyPassword'     => __( 'Please enter a password.', 'cube-payment-portal' ),
                    'shortPassword'     => __( 'Password must be at least 8 characters.', 'cube-payment-portal' ),
                    'passwordMismatch'  => __( 'Passwords do not match.', 'cube-payment-portal' ),
                    'success'           => __( 'Account created successfully! Signing you in...', 'cube-payment-portal' ),
                ),
            )
        );
    }

    /**
     * Get the redirect URL for after login.
     *
     * @param string $redirect_to  Explicit redirect URL.
     * @param string $portal_page  Portal page slug/ID.
     * @return string Redirect URL.
     */
    private function get_portal_redirect_url( $redirect_to = '', $portal_page = '' ) {
        // Priority 1: Explicit redirect_to parameter.
        if ( ! empty( $redirect_to ) ) {
            return esc_url( $redirect_to );
        }

        // Priority 2: Check for redirect_to in query string.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['redirect_to'] ) ) {
            return esc_url( sanitize_url( wp_unslash( $_GET['redirect_to'] ) ) );
        }

        // Priority 3: Portal page setting.
        if ( ! empty( $portal_page ) ) {
            $page_url = get_permalink( $portal_page );
            if ( $page_url ) {
                return $page_url;
            }
        }

        // Priority 4: Use stored portal page ID (set by auto-page creation).
        $portal_page_id = $this->find_portal_page();
        if ( $portal_page_id ) {
            return get_permalink( $portal_page_id );
        }

        // Fallback: Home URL.
        return home_url();
    }

    /**
     * Find the page containing the client portal shortcode.
     *
     * @return int|false Page ID or false if not found.
     */
    private function find_portal_page() {
        // Check stored option first (set by auto-page creation on activation).
        $stored_page_id = get_option( 'spp_portal_page_id' );
        if ( $stored_page_id && 'publish' === get_post_status( $stored_page_id ) ) {
            return (int) $stored_page_id;
        }

        // Fallback: search for page with shortcode.
        $pages = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                's'              => '[spp_client_portal',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            )
        );

        if ( ! empty( $pages ) ) {
            // Cache for future use.
            update_option( 'spp_portal_page_id', $pages[0], false );
            return $pages[0];
        }

        return false;
    }

    /**
     * Get the login logo URL.
     *
     * @return string Logo URL.
     */
    private function get_login_logo_url() {
        // Check for custom logo option.
        $custom_logo = get_option( 'spp_login_logo_url' );
        if ( ! empty( $custom_logo ) ) {
            return esc_url( $custom_logo );
        }

        // Use site custom logo if available.
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
            if ( $logo_url ) {
                return $logo_url;
            }
        }

        // Use site icon (favicon) if available.
        $site_icon_url = get_site_icon_url( 128 );
        if ( ! empty( $site_icon_url ) ) {
            return $site_icon_url;
        }

        // No site logo or icon configured — return empty to trigger site name fallback.
        return '';
    }

    /**
     * Get login error message if any.
     *
     * @return string Error message or empty string.
     */
    private function get_login_error() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['login'] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error_code = sanitize_text_field( wp_unslash( $_GET['login'] ) );

        $error_messages = array(
            'failed'        => __( 'Invalid username or password. Please try again.', 'cube-payment-portal' ),
            'empty'         => __( 'Please enter your username and password.', 'cube-payment-portal' ),
            'invalid_email' => __( 'Invalid email address.', 'cube-payment-portal' ),
            'invalidkey'    => __( 'Invalid password reset link.', 'cube-payment-portal' ),
            'expiredkey'    => __( 'Password reset link has expired.', 'cube-payment-portal' ),
            'loggedout'     => '', // Not an error, just logged out.
        );

        return isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : '';
    }

    /**
     * Enqueue login page assets.
     */
    private function enqueue_login_assets() {
        wp_enqueue_style(
            'spp-portal-login',
            SPP_PLUGIN_URL . 'assets/css/portal-login.css',
            array(),
            defined( 'SPP_VERSION' ) ? SPP_VERSION : '1.0.0'
        );

        wp_enqueue_script(
            'spp-portal-login',
            SPP_PLUGIN_URL . 'assets/js/portal-login.js',
            array( 'jquery' ),
            defined( 'SPP_VERSION' ) ? SPP_VERSION : '1.0.0',
            true
        );

        wp_localize_script(
            'spp-portal-login',
            'sppLogin',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'spp_login_nonce' ),
                'strings'   => array(
                    'loading'        => __( 'Signing in...', 'cube-payment-portal' ),
                    'emptyUsername'  => __( 'Please enter your username or email.', 'cube-payment-portal' ),
                    'emptyPassword'  => __( 'Please enter your password.', 'cube-payment-portal' ),
                    'invalidEmail'   => __( 'Please enter a valid email address.', 'cube-payment-portal' ),
                ),
            )
        );
    }

    /**
     * Check if current user is a portal client.
     *
     * @return bool True if client.
     */
    private function is_portal_client() {
        $user = wp_get_current_user();
        return in_array( 'spp_client', (array) $user->roles, true ) || current_user_can( 'manage_options' );
    }

    /**
     * Render the client dashboard.
     *
     * Outputs the SPA shell with sidebar navigation and a content area.
     * The initial view is rendered server-side for fast first paint;
     * subsequent navigation is handled client-side via AJAX.
     */
    private function render_dashboard() {
        // Validate view against allowed values — filtered by feature flags.
        $allowed_views = array( 'dashboard', 'payment-methods', 'add-card', 'transactions', 'profile' );
        if ( get_option( 'spp_feature_subscriptions', true ) ) {
            $allowed_views[] = 'subscriptions';
        }
        if ( get_option( 'spp_feature_invoices', true ) ) {
            $allowed_views[] = 'invoices';
        }
        if ( get_option( 'spp_feature_bookings', false ) ) {
            $allowed_views[] = 'bookings';
        }
        if ( get_option( 'spp_feature_loyalty', false ) ) {
            $allowed_views[] = 'loyalty';
        }
        if ( class_exists( 'WooCommerce' ) ) {
            $allowed_views[] = 'wc-orders';
            $allowed_views[] = 'wc-downloads';
        }

        $view = 'dashboard';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['view'] ) ) {
            $requested_view = sanitize_text_field( wp_unslash( $_GET['view'] ) );
            if ( in_array( $requested_view, $allowed_views, true ) ) {
                $view = $requested_view;
            }
        }

        $user = wp_get_current_user();
        $display_name = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;
        $avatar_initials = strtoupper( mb_substr( $display_name, 0, 1 ) );
        ?>
        <div class="spp-portal" data-spp-base-url="<?php echo esc_url( get_permalink() ); ?>">
            <!-- SVG Gradient Definition for progress circles -->
            <svg width="0" height="0" style="position: absolute;">
                <defs>
                    <linearGradient id="spp-progress-gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#006aff"/>
                        <stop offset="100%" style="stop-color:#00d2ff"/>
                    </linearGradient>
                </defs>
            </svg>

            <!-- Sidebar Navigation -->
            <?php $this->render_navigation( $view ); ?>

            <!-- Main Content Area -->
            <main class="spp-portal-content" data-spp-initial-view="<?php echo esc_attr( $view ); ?>">
                <?php
                // Render initial view server-side for fast first paint.
                $partial_map = array(
                    'dashboard'       => 'client-dashboard.php',
                    'payment-methods' => 'client-payment-methods.php',
                    'add-card'        => 'client-add-card.php',
                    'subscriptions'   => 'client-subscriptions.php',
                    'invoices'        => 'client-invoices.php',
                    'transactions'    => 'client-transactions.php',
                    'bookings'        => 'client-bookings.php',
                    'loyalty'         => 'client-loyalty.php',
                    'wc-orders'       => 'client-wc-orders.php',
                    'wc-downloads'    => 'client-wc-downloads.php',
                    'profile'         => 'client-profile.php',
                );

                $partial_file = SPP_PLUGIN_DIR . 'public/partials/' . ( $partial_map[ $view ] ?? 'client-dashboard.php' );
                if ( file_exists( $partial_file ) ) {
                    include $partial_file;
                }
                ?>
            </main>
        </div>
        <?php
    }

    /**
     * Render portal navigation sidebar.
     *
     * Uses data-spp-view attributes for SPA client-side routing.
     *
     * @param string $current_view Current view slug.
     */
    private function render_navigation( $current_view ) {
        $base_url = get_permalink();

        $nav_items = array(
            'dashboard' => array(
                'label' => __( 'Dashboard', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>',
            ),
            'payment-methods' => array(
                'label' => __( 'Payment Methods', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
            ),
            'subscriptions' => array(
                'label'   => __( 'Subscriptions', 'cube-payment-portal' ),
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>',
                'feature' => 'spp_feature_subscriptions',
            ),
            'invoices' => array(
                'label'   => __( 'Invoices', 'cube-payment-portal' ),
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>',
                'feature' => 'spp_feature_invoices',
            ),
            'transactions' => array(
                'label' => __( 'Transactions', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>',
            ),
            'wc-orders' => array(
                'label' => __( 'Orders', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>',
                'wc'    => true,
            ),
            'wc-downloads' => array(
                'label' => __( 'Downloads', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>',
                'wc'    => true,
            ),
            'bookings' => array(
                'label'   => __( 'Appointments', 'cube-payment-portal' ),
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
                'feature' => 'spp_feature_bookings',
            ),
            'loyalty' => array(
                'label'   => __( 'Loyalty & Rewards', 'cube-payment-portal' ),
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
                'feature' => 'spp_feature_loyalty',
            ),
            'profile' => array(
                'label' => __( 'Profile', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
            ),
        );

        // Filter out nav items for disabled features or missing WooCommerce.
        foreach ( $nav_items as $slug => $item ) {
            if ( ! empty( $item['feature'] ) && ! get_option( $item['feature'], true ) ) {
                unset( $nav_items[ $slug ] );
            }
            if ( ! empty( $item['wc'] ) && ! class_exists( 'WooCommerce' ) ) {
                unset( $nav_items[ $slug ] );
            }
        }
        ?>
        <!-- Mobile navigation bar (outside nav so transform doesn't trap it) -->
        <button type="button" class="spp-portal-nav__toggle" id="spp-nav-toggle" aria-label="<?php esc_attr_e( 'Toggle navigation', 'cube-payment-portal' ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            <span class="spp-portal-nav__toggle-label" id="spp-nav-toggle-label"><?php echo esc_html( $nav_items[ $current_view ]['label'] ?? __( 'Menu', 'cube-payment-portal' ) ); ?></span>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spp-portal-nav__toggle-chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>

        <!-- Overlay for mobile drawer -->
        <div class="spp-portal-nav__overlay" id="spp-nav-overlay"></div>

        <nav class="spp-portal-nav" id="spp-portal-nav">
            <!-- Drawer header (mobile only) -->
            <div class="spp-portal-nav__header">
                <span class="spp-portal-nav__header-title"><?php esc_html_e( 'Menu', 'cube-payment-portal' ); ?></span>
                <button type="button" class="spp-portal-nav__close" id="spp-nav-close" aria-label="<?php esc_attr_e( 'Close menu', 'cube-payment-portal' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <!-- Navigation items -->
            <ul class="spp-portal-nav__list" id="spp-nav-list">
                <?php foreach ( $nav_items as $view_slug => $item ) :
                    $url = add_query_arg( 'view', $view_slug, $base_url );
                    $is_active = ( $current_view === $view_slug );
                    ?>
                    <li class="spp-portal-nav__item <?php echo $is_active ? 'spp-portal-nav__item--active' : ''; ?>">
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="spp-portal-nav__link spp-nav-link"
                           data-spp-view="<?php echo esc_attr( $view_slug ); ?>"
                           <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                            <span class="spp-portal-nav__icon"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded above ?></span>
                            <span class="spp-portal-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

        </nav>
        <?php
    }

    /**
     * Render login form.
     *
     * @param string $redirect_url Redirect URL after login.
     */
    private function render_login_form( $redirect_url = '' ) {
        $this->enqueue_login_assets();

        $redirect_to   = $this->get_portal_redirect_url( $redirect_url );
        $show_logo     = true;
        $show_remember = true;
        $show_register = false;
        $contact_url   = '';
        $logo_url      = $this->get_login_logo_url();
        $login_error   = $this->get_login_error();
        $lost_password = wp_lostpassword_url( $redirect_to );

        include SPP_PLUGIN_DIR . 'public/partials/portal-login.php';
    }

    /**
     * Render payment form shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_payment_form( $atts ) {
        $atts = shortcode_atts(
            array(
                'amount'      => '',
                'description' => '',
            ),
            $atts,
            'spp_payment_form'
        );

        ob_start();
        include SPP_PLUGIN_DIR . 'templates/shortcodes/payment-form.php';
        return ob_get_clean();
    }

    /**
     * Render payment button shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_payment_button( $atts ) {
        $atts = shortcode_atts(
            array(
                'amount' => '',
                'text'   => __( 'Pay', 'cube-payment-portal' ),
                'style'  => 'primary',
            ),
            $atts,
            'spp_payment_button'
        );

        return sprintf(
            '<button class="spp-payment-button spp-button-%s" data-amount="%s">%s</button>',
            esc_attr( $atts['style'] ),
            esc_attr( $atts['amount'] ),
            esc_html( $atts['text'] )
        );
    }

    /**
     * Render subscription plans shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_subscription_plans( $atts ) {
        $atts = shortcode_atts(
            array(
                'plan_ids' => '',
                'columns'  => 3,
            ),
            $atts,
            'spp_subscription_plans'
        );

        ob_start();
        include SPP_PLUGIN_DIR . 'templates/shortcodes/subscription-form.php';
        return ob_get_clean();
    }

    /**
     * Render subscribe button shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_subscribe_button( $atts ) {
        $atts = shortcode_atts(
            array(
                'plan_id' => '',
                'text'    => __( 'Subscribe', 'cube-payment-portal' ),
                'style'   => 'primary',
            ),
            $atts,
            'spp_subscribe_button'
        );

        return sprintf(
            '<button class="spp-subscribe-button spp-button-%s" data-plan-id="%s">%s</button>',
            esc_attr( $atts['style'] ),
            esc_attr( $atts['plan_id'] ),
            esc_html( $atts['text'] )
        );
    }

    /**
     * Render my subscriptions shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_my_subscriptions( $atts ) {
        $atts = shortcode_atts(
            array(
                'show_history' => 'false',
            ),
            $atts,
            'spp_my_subscriptions'
        );

        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your subscriptions.', 'cube-payment-portal' ) . '</p>';
        }

        ob_start();
        include SPP_PLUGIN_DIR . 'public/partials/client-subscriptions.php';
        return ob_get_clean();
    }

    /**
     * Render my bookings shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_my_bookings( $atts ) {
        $atts = shortcode_atts(
            array(
                'show_past' => 'false',
            ),
            $atts,
            'spp_my_bookings'
        );

        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your appointments.', 'cube-payment-portal' ) . '</p>';
        }

        ob_start();
        include SPP_PLUGIN_DIR . 'public/partials/client-bookings.php';
        return ob_get_clean();
    }

    /**
     * Render loyalty points shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_loyalty_points( $atts ) {
        $atts = shortcode_atts(
            array(
                'show_rewards' => 'true',
            ),
            $atts,
            'spp_loyalty_points'
        );

        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your loyalty points.', 'cube-payment-portal' ) . '</p>';
        }

        ob_start();
        include SPP_PLUGIN_DIR . 'public/partials/client-loyalty.php';
        return ob_get_clean();
    }
}
