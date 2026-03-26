<?php
/**
 * Settings page functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Settings
 *
 * Handles plugin settings registration and validation.
 */
class SPP_Settings {

    /**
     * Register all settings.
     */
    public function register_settings() {
        // API Configuration.
        register_setting( 'spp_api_settings', 'spp_environment', array(
            'sanitize_callback' => array( $this, 'sanitize_environment' ),
            'default'           => 'sandbox',
        ) );
        register_setting( 'spp_api_settings', 'spp_application_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'spp_api_settings', 'spp_application_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'spp_api_settings', 'spp_sandbox_access_token', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        // General Settings.
        register_setting( 'spp_general_settings', 'spp_client_portal_page', array( 'sanitize_callback' => 'absint' ) );
        register_setting( 'spp_general_settings', 'spp_owner_dashboard_page', array( 'sanitize_callback' => 'absint' ) );
        register_setting( 'spp_general_settings', 'spp_default_currency', array(
            'sanitize_callback' => array( $this, 'sanitize_currency' ),
            'default'           => 'USD',
        ) );
        register_setting( 'spp_general_settings', 'spp_default_location_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'spp_general_settings', 'spp_sandbox_mode', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'spp_general_settings', 'spp_auto_update', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ) );

        // Client Portal Settings.
        register_setting( 'spp_portal_settings', 'spp_portal_title', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'spp_portal_settings', 'spp_portal_welcome_message', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'spp_portal_settings', 'spp_portal_accent_color', array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '',
        ) );
        register_setting( 'spp_portal_settings', 'spp_portal_items_per_page', array(
            'sanitize_callback' => 'absint',
            'default'           => 25,
        ) );
        register_setting( 'spp_portal_settings', 'spp_portal_session_timeout', array(
            'sanitize_callback' => 'absint',
            'default'           => 30,
        ) );
        register_setting( 'spp_portal_settings', 'spp_portal_custom_css', array(
            'sanitize_callback' => array( $this, 'sanitize_custom_css' ),
            'default'           => '',
        ) );
        register_setting( 'spp_portal_settings', 'spp_enable_registration', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'spp_portal_settings', 'spp_require_email_verify', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'spp_portal_settings', 'spp_max_cards_per_client', array( 'sanitize_callback' => 'absint' ) );
        register_setting( 'spp_portal_settings', 'spp_allow_card_deletion', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );

        // Dashboard Customization Settings.
        register_setting( 'spp_portal_settings', 'spp_dashboard_quick_actions', array(
            'sanitize_callback' => array( $this, 'sanitize_string_array' ),
            'default'           => array(),
        ) );
        register_setting( 'spp_portal_settings', 'spp_dashboard_widgets', array(
            'sanitize_callback' => array( $this, 'sanitize_string_array' ),
            'default'           => array(),
        ) );
        register_setting( 'spp_portal_settings', 'spp_dashboard_widget_order', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );

        // Redirect Settings.
        register_setting( 'spp_portal_settings', 'spp_login_redirect_page', array(
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ) );
        register_setting( 'spp_portal_settings', 'spp_logout_redirect_page', array(
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ) );

        // Booking Settings.
        register_setting( 'spp_portal_settings', 'spp_booking_max_advance_days', array(
            'sanitize_callback' => 'absint',
            'default'           => 90,
        ) );
        register_setting( 'spp_portal_settings', 'spp_allow_booking_cancellation', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ) );
        register_setting( 'spp_portal_settings', 'spp_cancellation_window_hours', array(
            'sanitize_callback' => 'absint',
            'default'           => 24,
        ) );
        register_setting( 'spp_portal_settings', 'spp_allow_staff_selection', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ) );

        // Reminder / Notification Settings.
        register_setting( 'spp_portal_settings', 'spp_reminder_appointment_enabled', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ) );
        register_setting( 'spp_portal_settings', 'spp_reminder_invoice_enabled', array(
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ) );
        register_setting( 'spp_portal_settings', 'spp_reminder_frequency_hours', array(
            'sanitize_callback' => 'absint',
            'default'           => 24,
        ) );

        // Rate Limiting.
        register_setting( 'spp_portal_settings', 'spp_rate_limit_per_minute', array(
            'sanitize_callback' => 'absint',
            'default'           => 30,
        ) );

        // Menu Integration.
        register_setting( 'spp_portal_settings', 'spp_menu_location', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'auto',
        ) );

        // Notification Settings.
        register_setting( 'spp_notification_settings', 'spp_admin_email', array( 'sanitize_callback' => 'sanitize_email' ) );
        register_setting( 'spp_notification_settings', 'spp_send_receipts', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'spp_notification_settings', 'spp_failed_payment_alerts', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );

        // Webhook Settings.
        register_setting( 'spp_webhook_settings', 'spp_webhook_signature_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        // Subscription Settings.
        register_setting( 'spp_subscription_settings', 'spp_sync_direction', array(
            'sanitize_callback' => array( $this, 'sanitize_sync_direction' ),
            'default'           => 'both',
        ) );
        register_setting( 'spp_subscription_settings', 'spp_sync_interval', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ) );
        register_setting( 'spp_subscription_settings', 'spp_default_trial_days', array( 'sanitize_callback' => 'absint' ) );
        register_setting( 'spp_subscription_settings', 'spp_proration_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        // Feature Settings.
        $features = array(
            'spp_feature_subscriptions' => true,
            'spp_feature_invoices'      => true,
            'spp_feature_orders'        => true,
            'spp_feature_catalog'       => true,
            'spp_feature_inventory'     => true,
            'spp_feature_loyalty'       => false,
            'spp_feature_gift_cards'    => false,
            'spp_feature_disputes'      => false,
            'spp_feature_bookings'      => false,
            'spp_feature_portal'        => true, // Client Portal
        );

        foreach ( $features as $key => $default ) {
            register_setting( 'spp_feature_settings', $key, array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => $default,
            ) );
        }

        // Location Settings.
        register_setting( 'spp_location_settings', 'spp_enable_multi_location', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'spp_location_settings', 'spp_default_location_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'spp_location_settings', 'spp_location_selection', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        // Add settings sections.
        $this->add_settings_sections();
    }

    /**
     * Sanitize environment setting.
     *
     * @param string $value Setting value.
     * @return string Sanitized value.
     */
    public function sanitize_environment( $value ) {
        return in_array( $value, array( 'sandbox', 'production' ), true ) ? $value : 'sandbox';
    }

    /**
     * Sanitize currency setting.
     *
     * @param string $value Setting value.
     * @return string Sanitized value.
     */
    public function sanitize_currency( $value ) {
        $value = strtoupper( sanitize_text_field( $value ) );
        $valid_currencies = array( 'USD', 'CAD', 'GBP', 'EUR', 'AUD', 'JPY' );
        return in_array( $value, $valid_currencies, true ) ? $value : 'USD';
    }

    /**
     * Sanitize an array of strings (for multi-checkbox settings).
     *
     * @param mixed $value Setting value.
     * @return array Sanitized array of strings.
     */
    public function sanitize_string_array( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        return array_map( 'sanitize_text_field', $value );
    }

    /**
     * Sanitize sync direction setting.
     *
     * @param string $value Setting value.
     * @return string Sanitized value.
     */
    public function sanitize_sync_direction( $value ) {
        return in_array( $value, array( 'both', 'to_square', 'from_square' ), true ) ? $value : 'both';
    }

    /**
     * Sanitize custom CSS input.
     *
     * Strips HTML tags and removes potentially dangerous CSS constructs
     * like url(), @import, and expression().
     *
     * @param string $value Raw CSS input.
     * @return string Sanitized CSS.
     */
    public function sanitize_custom_css( $value ) {
        $value = wp_strip_all_tags( $value );
        // Remove potentially dangerous CSS constructs.
        $value = preg_replace( '/url\s*\(/i', '/* blocked-url */(', $value );
        $value = preg_replace( '/@import/i', '/* blocked-import */', $value );
        $value = preg_replace( '/expression\s*\(/i', '/* blocked-expression */(', $value );
        $value = preg_replace( '/javascript\s*:/i', '/* blocked */', $value );
        $value = preg_replace( '/-moz-binding\s*:/i', '/* blocked */', $value );
        $value = preg_replace( '/behavior\s*:/i', '/* blocked */', $value );
        return $value;
    }

    /**
     * Add settings sections and fields.
     */
    private function add_settings_sections() {
        // Features Section.
        add_settings_section(
            'spp_features_section',
            __( 'Plugin Features', 'cube-payment-portal' ),
            array( $this, 'render_features_section' ),
            'spp_feature_settings'
        );


        $features_list = array(
            'spp_feature_portal'        => array(
                'label' => __( 'Client Portal', 'cube-payment-portal' ),
                'desc'  => __( 'Provide a dedicated area for clients to view invoices, manage subscriptions, and pay online.', 'cube-payment-portal' ),
            ),
            'spp_feature_subscriptions' => array(
                'label' => __( 'Subscriptions', 'cube-payment-portal' ),
                'desc'  => __( 'Create and manage recurring billing plans. Syncs bidirectionally with Square Subscriptions.', 'cube-payment-portal' ),
            ),
            'spp_feature_invoices'      => array(
                'label' => __( 'Invoices', 'cube-payment-portal' ),
                'desc'  => __( 'View, create, and sync invoices. Clients can pay outstanding invoices directly from the portal.', 'cube-payment-portal' ),
            ),
            'spp_feature_orders'        => array(
                'label' => __( 'Orders', 'cube-payment-portal' ),
                'desc'  => __( 'Track and manage Square orders. Required for subscription itemization and complex checkout flows.', 'cube-payment-portal' ),
            ),
            'spp_feature_catalog'       => array(
                'label' => __( 'Catalog', 'cube-payment-portal' ),
                'desc'  => __( 'Sync your Square Item Library. Use items for subscription plans and invoice line items.', 'cube-payment-portal' ),
            ),
            'spp_feature_inventory'     => array(
                'label' => __( 'Inventory', 'cube-payment-portal' ),
                'desc'  => __( 'Monitor stock levels across locations. Syncs inventory counts from Square in real-time.', 'cube-payment-portal' ),
            ),
            'spp_feature_loyalty'       => array(
                'label' => __( 'Loyalty', 'cube-payment-portal' ),
                'desc'  => __( 'Enroll customers in your loyalty program and allow them to view their points balance.', 'cube-payment-portal' ),
            ),
            'spp_feature_gift_cards'    => array(
                'label' => __( 'Gift Cards', 'cube-payment-portal' ),
                'desc'  => __( 'Sell and manage digital gift cards. Allow customers to check balances and redeem cards.', 'cube-payment-portal' ),
            ),
            'spp_feature_disputes'      => array(
                'label' => __( 'Disputes', 'cube-payment-portal' ),
                'desc'  => __( 'View and manage payment disputes (chargebacks) directly from your WordPress dashboard.', 'cube-payment-portal' ),
            ),
            'spp_feature_bookings'      => array(
                'label' => __( 'Bookings', 'cube-payment-portal' ),
                'desc'  => __( 'Integrate Square Appointments. Allow clients to view upcoming appointments and history.', 'cube-payment-portal' ),
            ),
        );

        foreach ( $features_list as $key => $data ) {
            add_settings_field(
                $key,
                $data['label'],
                array( $this, 'render_feature_toggle_field' ),
                'spp_feature_settings',
                'spp_features_section',
                array( 
                    'label_for' => $key,
                    'desc'      => $data['desc'],
                )
            );
        }

        // API Configuration Section.
        add_settings_section(
            'spp_api_section',
            __( 'API Configuration', 'cube-payment-portal' ),
            array( $this, 'render_api_section' ),
            'spp_api_settings'
        );

        add_settings_field(
            'spp_environment',
            __( 'Environment', 'cube-payment-portal' ),
            array( $this, 'render_environment_field' ),
            'spp_api_settings',
            'spp_api_section'
        );

        add_settings_field(
            'spp_application_id',
            __( 'Application ID', 'cube-payment-portal' ),
            array( $this, 'render_application_id_field' ),
            'spp_api_settings',
            'spp_api_section'
        );

        // Portal Pages Section.
        add_settings_section(
            'spp_portal_pages_section',
            __( 'Portal Pages', 'cube-payment-portal' ),
            array( $this, 'render_portal_pages_section' ),
            'spp_general_settings'
        );

        add_settings_field(
            'spp_portal_page_info',
            __( 'Portal Page', 'cube-payment-portal' ),
            array( $this, 'render_portal_page_field' ),
            'spp_general_settings',
            'spp_portal_pages_section'
        );

        add_settings_field(
            'spp_login_page_info',
            __( 'Login Page', 'cube-payment-portal' ),
            array( $this, 'render_login_page_field' ),
            'spp_general_settings',
            'spp_portal_pages_section'
        );

        add_settings_field(
            'spp_register_page_info',
            __( 'Registration Page', 'cube-payment-portal' ),
            array( $this, 'render_register_page_field' ),
            'spp_general_settings',
            'spp_portal_pages_section'
        );
    }

    /**
     * Render Features section description.
     */
    public function render_features_section() {
        echo '<p>' . esc_html__( 'Enable or disable features. Disabling a feature will hide it from the menu and stop background synchronization for that data type.', 'cube-payment-portal' ) . '</p>';
    }

    /**
     * Render generic boolean toggle for features.
     *
     * @param array $args Field arguments.
     */
    public function render_feature_toggle_field( $args ) {
        $option_name = $args['label_for'];
        $value = get_option( $option_name, '1' ); // Default to enabled if not set, or handle defaults in register_setting
        // Check default again if needed, but get_option 2nd arg handles it mostly. 
        // Logic: if not set in DB, verify default logic matching register_settings.
        // Actually get_option returns string '1' or '' for boolean usually if sanitized? 
        // rest_sanitize_boolean returns boolean true/false. get_option returns what's in DB or default.
        
        // Let's rely on the default map we used in register_settings if strictly needed, but simple checked() works with boolean.
        
        $description = isset( $args['desc'] ) ? $args['desc'] : '';
        ?>
        <div class="spp-feature-desc"><?php echo esc_html( $description ); ?></div>
        
        <label class="spp-switch">
            <input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" value="1" <?php checked( $value, true ); ?>>
            <span class="spp-slider round"></span>
            <span class="spp-toggle-label"><?php echo $value ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?></span>
        </label>
        <?php
    }

    /**
     * Render API section description.
     */
    public function render_api_section() {
        echo '<p>' . esc_html__( 'Configure your Square API credentials. Get these from the Square Developer Dashboard.', 'cube-payment-portal' ) . '</p>';
    }

    /**
     * Render environment select field.
     */
    public function render_environment_field() {
        $value = get_option( 'spp_environment', 'sandbox' );
        ?>
        <select name="spp_environment" id="spp_environment">
            <option value="sandbox" <?php selected( $value, 'sandbox' ); ?>>
                <?php esc_html_e( 'Sandbox (Testing)', 'cube-payment-portal' ); ?>
            </option>
            <option value="production" <?php selected( $value, 'production' ); ?>>
                <?php esc_html_e( 'Production', 'cube-payment-portal' ); ?>
            </option>
        </select>
        <?php
    }

    /**
     * Render application ID field.
     */
    public function render_application_id_field() {
        $value = get_option( 'spp_application_id', '' );
        ?>
        <input type="text" name="spp_application_id" id="spp_application_id" 
               value="<?php echo esc_attr( $value ); ?>" class="regular-text">
        <?php
    }

    /**
     * Render Portal Pages section description.
     */
    public function render_portal_pages_section() {
        echo '<p>' . esc_html__( 'These pages are automatically created on plugin activation. If accidentally deleted, click "Recreate Pages" to restore them.', 'cube-payment-portal' ) . '</p>';
        echo '<p><button type="button" class="button" id="spp-recreate-pages">' . esc_html__( 'Recreate Pages', 'cube-payment-portal' ) . '</button> <span id="spp-recreate-pages-status"></span></p>';
    }

    /**
     * Render the portal page info field.
     */
    public function render_portal_page_field() {
        $page_id = get_option( 'spp_portal_page_id' );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            $page_title = get_the_title( $page_id );
            $page_url   = get_permalink( $page_id );
            $edit_url   = get_edit_post_link( $page_id );
            printf(
                '<strong>%s</strong> &mdash; <a href="%s" target="_blank">%s</a> | <a href="%s">%s</a>',
                esc_html( $page_title ),
                esc_url( $page_url ),
                esc_html__( 'View', 'cube-payment-portal' ),
                esc_url( $edit_url ),
                esc_html__( 'Edit', 'cube-payment-portal' )
            );
            echo '<p class="description">' . esc_html( $page_url ) . '</p>';
        } else {
            echo '<span style="color: #d63638;">' . esc_html__( 'Not found. Click "Recreate Pages" to restore.', 'cube-payment-portal' ) . '</span>';
        }
    }

    /**
     * Render the login page info field.
     */
    public function render_login_page_field() {
        $page_id = get_option( 'spp_login_page_id' );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            $page_title = get_the_title( $page_id );
            $page_url   = get_permalink( $page_id );
            $edit_url   = get_edit_post_link( $page_id );
            printf(
                '<strong>%s</strong> &mdash; <a href="%s" target="_blank">%s</a> | <a href="%s">%s</a>',
                esc_html( $page_title ),
                esc_url( $page_url ),
                esc_html__( 'View', 'cube-payment-portal' ),
                esc_url( $edit_url ),
                esc_html__( 'Edit', 'cube-payment-portal' )
            );
            echo '<p class="description">' . esc_html( $page_url ) . '</p>';
        } else {
            echo '<span style="color: #d63638;">' . esc_html__( 'Not found. Click "Recreate Pages" to restore.', 'cube-payment-portal' ) . '</span>';
        }
    }

    /**
     * Render the registration page info field.
     */
    public function render_register_page_field() {
        $page_id = get_option( 'spp_register_page_id' );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            $page_title = get_the_title( $page_id );
            $page_url   = get_permalink( $page_id );
            $edit_url   = get_edit_post_link( $page_id );
            printf(
                '<strong>%s</strong> &mdash; <a href="%s" target="_blank">%s</a> | <a href="%s">%s</a>',
                esc_html( $page_title ),
                esc_url( $page_url ),
                esc_html__( 'View', 'cube-payment-portal' ),
                esc_url( $edit_url ),
                esc_html__( 'Edit', 'cube-payment-portal' )
            );
            echo '<p class="description">' . esc_html( $page_url ) . '</p>';
        } else {
            echo '<span style="color: #d63638;">' . esc_html__( 'Not found. Click "Recreate Pages" to restore.', 'cube-payment-portal' ) . '</span>';
        }
    }

    /**
     * Get OAuth redirect URI.
     *
     * @return string Redirect URI.
     */
    public static function get_oauth_redirect_uri() {
        return admin_url( 'admin.php?page=spp-settings&spp_oauth_callback=1' );
    }
}
