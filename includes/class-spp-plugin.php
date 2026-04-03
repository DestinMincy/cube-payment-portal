<?php
/**
 * The main plugin class.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Plugin
 *
 * The core plugin class that orchestrates all functionality.
 */
class SPP_Plugin {

    /**
     * The loader responsible for maintaining hooks.
     *
     * @var SPP_Loader
     */
    protected $loader;

    /**
     * The plugin version.
     *
     * @var string
     */
    protected $version;

    /**
     * Initialize the plugin.
     */
    public function __construct() {
        $this->version = SPP_VERSION;
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->check_database_updates();
    }

    /**
     * Check if database needs updates and run migrations if needed.
     */
    private function check_database_updates() {
        $current_db_version = get_option( 'spp_db_version', '0.0.0' );
        
        // Only run migrations if version has changed or is not set.
        if ( version_compare( $current_db_version, SPP_VERSION, '<' ) ) {
            require_once SPP_PLUGIN_DIR . 'includes/class-spp-activator.php';
            SPP_Activator::migrate_tables();
            update_option( 'spp_db_version', SPP_VERSION );
        }
    }

    /**
     * Load required dependencies.
     */
    private function load_dependencies() {
        // Core classes.
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-loader.php';
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-i18n.php';
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-currency.php';
        require_once SPP_PLUGIN_DIR . 'includes/invoice-helpers.php';

        // API classes.
        require_once SPP_PLUGIN_DIR . 'api/class-spp-square-client.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-oauth.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-payments.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-orders.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        require_once SPP_PLUGIN_DIR . 'api/class-spp-webhooks.php';

        // Admin classes.
        require_once SPP_PLUGIN_DIR . 'admin/class-spp-admin.php';
        require_once SPP_PLUGIN_DIR . 'admin/class-spp-settings.php';
        require_once SPP_PLUGIN_DIR . 'admin/class-spp-oauth-controller.php';

        // Public classes.
        require_once SPP_PLUGIN_DIR . 'public/class-spp-public.php';
        require_once SPP_PLUGIN_DIR . 'public/class-spp-client-portal.php';

        // Page template loader (registers template_include + body_class filters).
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-template-loader.php';

        // Menu integration.
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-menu-integration.php';

        // Notifications.
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-notifications.php';

        // GDPR privacy compliance.
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-privacy.php';

        // Database class.
        require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';

        // Sync classes.
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-manager.php';
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';

        // WooCommerce integration (conditional).
        if ( class_exists( 'WooCommerce' ) ) {
            require_once SPP_PLUGIN_DIR . 'woocommerce/class-spp-wc-gateway.php';
        }

        $this->loader = new SPP_Loader();
    }

    /**
     * Set up internationalization.
     */
    private function set_locale() {
        $plugin_i18n = new SPP_i18n();
        $this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
    }

    /**
     * Register admin-side hooks.
     */
    private function define_admin_hooks() {
        $plugin_admin = new SPP_Admin( $this->get_version() );

        // Security and compatibility notices.
        $this->loader->add_action( 'admin_notices', $this, 'show_security_notices' );

        // Auto-update control.
        $this->loader->add_filter( 'auto_update_plugin', $this, 'control_auto_update', 10, 2 );
        $this->loader->add_filter( 'plugin_auto_update_setting_html', $this, 'auto_update_setting_html', 10, 3 );
        $this->loader->add_action( 'update_option_spp_auto_update', $this, 'sync_auto_update_to_core', 10, 2 );

        // Admin scripts and styles.
        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

        // Admin menu.
        $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_admin_menu' );

        // Custom admin footer on plugin pages.
        $this->loader->add_filter( 'admin_footer_text', $plugin_admin, 'custom_admin_footer_text' );
        $this->loader->add_filter( 'update_footer', $plugin_admin, 'custom_admin_footer_version', 11 );

        // AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_get_transactions', $plugin_admin, 'ajax_get_transactions' );
        $this->loader->add_action( 'wp_ajax_spp_get_plans', $plugin_admin, 'ajax_get_plans' );
        $this->loader->add_action( 'wp_ajax_spp_sync_transactions', $plugin_admin, 'ajax_sync_transactions' );
        $this->loader->add_action( 'wp_ajax_spp_get_invoices', $plugin_admin, 'ajax_get_invoices' );
        $this->loader->add_action( 'wp_ajax_spp_get_customers', $plugin_admin, 'ajax_get_customers' );
        $this->loader->add_action( 'wp_ajax_spp_link_customer', $plugin_admin, 'ajax_link_customer' );
        $this->loader->add_action( 'wp_ajax_spp_unlink_customer', $plugin_admin, 'ajax_unlink_customer' );
        $this->loader->add_action( 'wp_ajax_spp_get_customer_cards', $plugin_admin, 'ajax_get_customer_cards' );
        $this->loader->add_action( 'wp_ajax_spp_create_card', $plugin_admin, 'ajax_create_card' );
        $this->loader->add_action( 'wp_ajax_spp_sync_all', $plugin_admin, 'ajax_sync_all' );
        $this->loader->add_action( 'wp_ajax_spp_run_scheduled_task', $plugin_admin, 'ajax_run_scheduled_task' );
        $this->loader->add_action( 'wp_ajax_spp_get_orders', $plugin_admin, 'ajax_get_orders' );
        $this->loader->add_action( 'wp_ajax_spp_get_order_detail', $plugin_admin, 'ajax_get_order_detail' );
        $this->loader->add_action( 'wp_ajax_spp_sync_orders', $plugin_admin, 'ajax_sync_orders' );
        $this->loader->add_action( 'wp_ajax_spp_bulk_delete_orders', $plugin_admin, 'ajax_bulk_delete_orders' );
        $this->loader->add_action( 'wp_ajax_spp_get_catalog', $plugin_admin, 'ajax_get_catalog' );
        $this->loader->add_action( 'wp_ajax_spp_sync_catalog', $plugin_admin, 'ajax_sync_catalog' );
        $this->loader->add_action( 'wp_ajax_spp_bulk_delete_catalog', $plugin_admin, 'ajax_bulk_delete_catalog' );
        $this->loader->add_action( 'wp_ajax_spp_create_catalog_item', $plugin_admin, 'ajax_create_catalog_item' );
        $this->loader->add_action( 'wp_ajax_spp_update_catalog_item', $plugin_admin, 'ajax_update_catalog_item' );
        $this->loader->add_action( 'wp_ajax_spp_delete_catalog_item', $plugin_admin, 'ajax_delete_catalog_item' );
        $this->loader->add_action( 'wp_ajax_spp_archive_catalog_item', $plugin_admin, 'ajax_archive_catalog_item' );
        $this->loader->add_action( 'wp_ajax_spp_duplicate_catalog_item', $plugin_admin, 'ajax_duplicate_catalog_item' );

        $this->loader->add_action( 'wp_ajax_spp_get_square_categories', $plugin_admin, 'ajax_get_square_categories' );
        $this->loader->add_action( 'wp_ajax_spp_create_customer', $plugin_admin, 'ajax_create_customer' );
        $this->loader->add_action( 'wp_ajax_spp_get_inventory', $plugin_admin, 'ajax_get_inventory' );
        $this->loader->add_action( 'wp_ajax_spp_get_low_stock', $plugin_admin, 'ajax_get_low_stock' );
        $this->loader->add_action( 'wp_ajax_spp_adjust_inventory', $plugin_admin, 'ajax_adjust_inventory' );
        $this->loader->add_action( 'wp_ajax_spp_get_locations', $plugin_admin, 'ajax_get_locations' );
        $this->loader->add_action( 'wp_ajax_spp_toggle_feature', $plugin_admin, 'ajax_toggle_feature' );
        
        // Loyalty AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_get_loyalty_accounts', $plugin_admin, 'ajax_get_loyalty_accounts' );
        $this->loader->add_action( 'wp_ajax_spp_search_loyalty_customer', $plugin_admin, 'ajax_search_loyalty_customer' );
        $this->loader->add_action( 'wp_ajax_spp_adjust_loyalty_points', $plugin_admin, 'ajax_adjust_loyalty_points' );
        $this->loader->add_action( 'wp_ajax_spp_get_loyalty_rewards', $plugin_admin, 'ajax_get_loyalty_rewards' );
        $this->loader->add_action( 'wp_ajax_spp_create_loyalty_reward', $plugin_admin, 'ajax_create_loyalty_reward' );
        $this->loader->add_action( 'wp_ajax_spp_delete_loyalty_reward', $plugin_admin, 'ajax_delete_loyalty_reward' );
        $this->loader->add_action( 'wp_ajax_spp_get_loyalty_events', $plugin_admin, 'ajax_get_loyalty_events' );
        
        // Gift Cards AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_get_gift_cards', $plugin_admin, 'ajax_get_gift_cards' );
        $this->loader->add_action( 'wp_ajax_spp_create_gift_card', $plugin_admin, 'ajax_create_gift_card' );
        $this->loader->add_action( 'wp_ajax_spp_activate_gift_card', $plugin_admin, 'ajax_activate_gift_card' );
        $this->loader->add_action( 'wp_ajax_spp_load_gift_card', $plugin_admin, 'ajax_load_gift_card' );
        $this->loader->add_action( 'wp_ajax_spp_link_gift_card_customer', $plugin_admin, 'ajax_link_gift_card_customer' );
        $this->loader->add_action( 'wp_ajax_spp_unlink_gift_card_customer', $plugin_admin, 'ajax_unlink_gift_card_customer' );
        $this->loader->add_action( 'wp_ajax_spp_redeem_gift_card', $plugin_admin, 'ajax_redeem_gift_card' );
        $this->loader->add_action( 'wp_ajax_spp_get_gift_card_activities', $plugin_admin, 'ajax_get_gift_card_activities' );
        $this->loader->add_action( 'wp_ajax_spp_deactivate_gift_card', $plugin_admin, 'ajax_deactivate_gift_card' );
        
        // Disputes AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_get_disputes', $plugin_admin, 'ajax_get_disputes' );
        $this->loader->add_action( 'wp_ajax_spp_get_dispute_detail', $plugin_admin, 'ajax_get_dispute_detail' );
        $this->loader->add_action( 'wp_ajax_spp_accept_dispute', $plugin_admin, 'ajax_accept_dispute' );
        $this->loader->add_action( 'wp_ajax_spp_submit_evidence_text', $plugin_admin, 'ajax_submit_evidence_text' );
        $this->loader->add_action( 'wp_ajax_spp_upload_evidence_file', $plugin_admin, 'ajax_upload_evidence_file' );
        $this->loader->add_action( 'wp_ajax_spp_remove_evidence', $plugin_admin, 'ajax_remove_evidence' );
        $this->loader->add_action( 'wp_ajax_spp_submit_dispute', $plugin_admin, 'ajax_submit_dispute' );
        
        // Bookings AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_get_bookings', $plugin_admin, 'ajax_get_bookings' );
        $this->loader->add_action( 'wp_ajax_spp_get_booking_detail', $plugin_admin, 'ajax_get_booking_detail' );
        $this->loader->add_action( 'wp_ajax_spp_cancel_booking', $plugin_admin, 'ajax_cancel_booking' );
        $this->loader->add_action( 'wp_ajax_spp_get_calendar_events', $plugin_admin, 'ajax_get_calendar_events' );

        // Form processing (before output).
        $this->loader->add_action( 'admin_init', $plugin_admin, 'process_form_submissions' );

        // Settings.
        $plugin_settings = new SPP_Settings();
        $this->loader->add_action( 'admin_init', $plugin_settings, 'register_settings' );
    }

    /**
     * Register public-facing hooks.
     */
    private function define_public_hooks() {
        $plugin_public = new SPP_Public( $this->get_version() );

        // Public scripts and styles.
        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

        // Shortcodes.
        $client_portal = new SPP_Client_Portal();
        $this->loader->add_action( 'init', $client_portal, 'register_shortcodes' );

        // Portal page template — intercepts template_include and sets body class.
        new SPP_Template_Loader();

        // Navbar login/signup & user dropdown.
        $menu_integration = new SPP_Menu_Integration();
        $menu_integration->init();

        // Redirect logged-in users from login page to portal.
        $this->loader->add_action( 'template_redirect', $this, 'portal_login_redirect' );

        // Redirect spp_client users to portal after WordPress login.
        $this->loader->add_filter( 'login_redirect', $this, 'portal_after_login_redirect', 10, 3 );

        // Webhooks endpoint.
        $webhooks = new SPP_Webhooks();
        $this->loader->add_action( 'rest_api_init', $webhooks, 'register_routes' );

        // Auto-link placeholder accounts on user registration.
        $this->loader->add_action( 'user_register', $this, 'auto_link_placeholder_account', 10, 1 );
        $this->loader->add_filter( 'authenticate', $this, 'prevent_placeholder_login', 30, 3 );
        $this->loader->add_action( 'password_reset', $this, 'upgrade_placeholder_on_password_reset', 10, 2 );

        // Custom avatar support — override Gravatar when user has uploaded a photo.
        $this->loader->add_filter( 'pre_get_avatar_data', $this, 'use_custom_avatar', 10, 2 );

        // Override the portal page title with the configured setting.
        $this->loader->add_filter( 'the_title', $this, 'override_portal_page_title', 10, 2 );

        // Cron jobs.
        $sync_manager = new SPP_Sync_Manager();
        $this->loader->add_action( 'spp_subscription_sync', $sync_manager, 'run_sync' );

        // GDPR privacy compliance.
        new SPP_Privacy();

        // Notification reminder cron hooks.
        $notifications = new SPP_Notifications();
        $this->loader->add_action( 'spp_send_appointment_reminders', $notifications, 'send_appointment_reminders' );
        $this->loader->add_action( 'spp_send_invoice_reminders', $notifications, 'send_invoice_reminders' );

        // Public AJAX handlers.
        $this->loader->add_action( 'wp_ajax_spp_public_get_services', $plugin_public, 'ajax_get_services' );
        $this->loader->add_action( 'wp_ajax_nopriv_spp_public_get_services', $plugin_public, 'ajax_get_services' );
        
        $this->loader->add_action( 'wp_ajax_spp_public_get_availability', $plugin_public, 'ajax_get_availability' );
        $this->loader->add_action( 'wp_ajax_nopriv_spp_public_get_availability', $plugin_public, 'ajax_get_availability' );
        
        $this->loader->add_action( 'wp_ajax_spp_public_create_appointment', $plugin_public, 'ajax_create_appointment' );
        $this->loader->add_action( 'wp_ajax_nopriv_spp_public_create_appointment', $plugin_public, 'ajax_create_appointment' );

        $this->loader->add_action( 'wp_ajax_spp_public_get_bookable_staff', $plugin_public, 'ajax_get_bookable_staff' );
        $this->loader->add_action( 'wp_ajax_nopriv_spp_public_get_bookable_staff', $plugin_public, 'ajax_get_bookable_staff' );

        // SPA view loading (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_load_view', $plugin_public, 'ajax_load_view' );
        $this->loader->add_action( 'wp_ajax_spp_refresh_component', $plugin_public, 'ajax_refresh_component' );

        // Client subscription management (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_pause_subscription', $plugin_public, 'ajax_pause_subscription' );
        $this->loader->add_action( 'wp_ajax_spp_resume_subscription', $plugin_public, 'ajax_resume_subscription' );
        $this->loader->add_action( 'wp_ajax_spp_cancel_subscription', $plugin_public, 'ajax_cancel_subscription' );
        $this->loader->add_action( 'wp_ajax_spp_public_cancel_booking', $plugin_public, 'ajax_cancel_booking' );

        // Client card management (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_save_card', $plugin_public, 'ajax_save_card' );
        $this->loader->add_action( 'wp_ajax_spp_delete_card', $plugin_public, 'ajax_delete_card' );

        // Client gift card management (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_purchase_gift_card', $plugin_public, 'ajax_purchase_gift_card' );
        $this->loader->add_action( 'wp_ajax_spp_reload_gift_card', $plugin_public, 'ajax_reload_gift_card' );

        // Client loyalty redemption (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_redeem_loyalty_reward', $plugin_public, 'ajax_redeem_loyalty_reward' );

        // Client profile management (authenticated only).
        $this->loader->add_action( 'wp_ajax_spp_save_profile', $plugin_public, 'ajax_save_profile' );
        $this->loader->add_action( 'wp_ajax_spp_upload_avatar', $plugin_public, 'ajax_upload_avatar' );
        $this->loader->add_action( 'wp_ajax_spp_remove_avatar', $plugin_public, 'ajax_remove_avatar' );
        $this->loader->add_action( 'wp_ajax_spp_save_notification_prefs', $plugin_public, 'ajax_save_notification_prefs' );
        $this->loader->add_action( 'wp_ajax_spp_save_display_prefs', $plugin_public, 'ajax_save_display_prefs' );

        // Public registration (unauthenticated).
        $this->loader->add_action( 'wp_ajax_nopriv_spp_register_account', $plugin_public, 'ajax_register_account' );

        // Admin AJAX: Recreate portal pages.
        $this->loader->add_action( 'wp_ajax_spp_recreate_pages', $this, 'ajax_recreate_pages' );

        // WooCommerce integration.
        if ( class_exists( 'WooCommerce' ) ) {
            $this->loader->add_filter( 'woocommerce_payment_gateways', $this, 'add_wc_gateway' );

            // Redirect WC My Account to SPP portal.
            $this->loader->add_action( 'template_redirect', $this, 'redirect_wc_myaccount_to_portal' );

            // WC order action AJAX handlers.
            $this->loader->add_action( 'wp_ajax_spp_cancel_wc_order', $plugin_public, 'ajax_cancel_wc_order' );
            $this->loader->add_action( 'wp_ajax_spp_reorder_wc_order', $plugin_public, 'ajax_reorder_wc_order' );
        }
    }

    /**
     * Add WooCommerce payment gateway.
     *
     * @param array $gateways Existing gateways.
     * @return array Modified gateways.
     */
    public function add_wc_gateway( $gateways ) {
        $gateways[] = 'SPP_WC_Gateway';
        return $gateways;
    }

    /**
     * Run the plugin.
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * Get the plugin version.
     *
     * @return string The plugin version.
     */
    public function get_version() {
        return $this->version;
    }

    /**
     * Auto-link placeholder accounts on user registration.
     *
     * When a user registers with an email that matches a placeholder account,
     * upgrade the placeholder account instead of creating a new one.
     *
     * @param int $user_id The newly registered user ID.
     */
    public function auto_link_placeholder_account( $user_id ) {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        // Check if this is already a placeholder account (avoid recursion).
        if ( in_array( 'spp_client_placeholder', $user->roles, true ) ) {
            return;
        }

        // Look for a placeholder account with the same email.
        $placeholder_users = get_users( array(
            'role'   => 'spp_client_placeholder',
            'search' => $user->user_email,
            'search_columns' => array( 'user_email' ),
            'number' => 1,
        ) );

        if ( empty( $placeholder_users ) ) {
            return;
        }

        $placeholder = $placeholder_users[0];

        // Don't merge if it's the same user.
        if ( $placeholder->ID === $user_id ) {
            return;
        }

        // Get the Square customer ID from the placeholder.
        $square_customer_id = get_user_meta( $placeholder->ID, 'spp_square_customer_id', true );

        if ( empty( $square_customer_id ) ) {
            return;
        }

        // Transfer the Square customer ID to the new user.
        update_user_meta( $user_id, 'spp_square_customer_id', $square_customer_id );

        // Update the customers table to link to the new user.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'spp_customers',
            array( 'wp_user_id' => $user_id ),
            array( 'square_customer_id' => $square_customer_id ),
            array( '%d' ),
            array( '%s' )
        );

        // Delete the placeholder user (transfers nothing since placeholder had no content).
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $placeholder->ID, $user_id );

        // Log the merge.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                'SPP: Merged placeholder account #%d into new user #%d (Square Customer: %s)',
                $placeholder->ID,
                $user_id,
                $square_customer_id
            ) );
        }

        do_action( 'spp_placeholder_account_merged', $user_id, $placeholder->ID, $square_customer_id );
    }

    /**
     * Prevent placeholder accounts from logging in.
     *
     * @param WP_User|WP_Error|null $user     The user object, error, or null.
     * @param string                $username The username.
     * @param string                $password The password.
     * @return WP_User|WP_Error The user or error.
     */
    public function prevent_placeholder_login( $user, $username, $password ) {
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        // Check if user has the placeholder role.
        if ( in_array( 'spp_client_placeholder', $user->roles, true ) ) {
            return new WP_Error(
                'placeholder_account',
                __( 'This account has not been activated. Please register with your email to claim your account.', 'cube-payment-portal' )
            );
        }

        return $user;
    }

    /**
     * Upgrade a placeholder account to full client when they set their password.
     *
     * Fires on the 'password_reset' action after a user completes the
     * wp-login.php?action=rp password reset flow (used as the activation link).
     *
     * @param WP_User $user     The user who reset their password.
     * @param string  $new_pass The new password (already set by WordPress).
     */
    public function upgrade_placeholder_on_password_reset( $user, $new_pass ) {
        if ( ! in_array( 'spp_client_placeholder', (array) $user->roles, true ) ) {
            return;
        }

        $user->set_role( 'spp_client' );
        delete_user_meta( $user->ID, 'spp_placeholder_account' );

        /**
         * Fires after a placeholder account is activated via password reset.
         *
         * @param int    $user_id The activated user ID.
         * @param string $email   The user's email.
         */
        do_action( 'spp_placeholder_account_activated', $user->ID, $user->user_email );
    }

    /**
     * Override Gravatar with custom uploaded avatar when available.
     *
     * @param array $args        Avatar data array.
     * @param mixed $id_or_email User ID, email, WP_User, or WP_Comment.
     * @return array Modified avatar data.
     */
    public function use_custom_avatar( $args, $id_or_email ) {
        $user_id = 0;

        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) {
                $user_id = $user->ID;
            }
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            if ( $id_or_email->user_id ) {
                $user_id = (int) $id_or_email->user_id;
            }
        }

        if ( $user_id ) {
            $custom_avatar = get_user_meta( $user_id, 'spp_custom_avatar', true );
            if ( ! empty( $custom_avatar ) ) {
                $args['url']          = $custom_avatar;
                $args['found_avatar'] = true;
            }
        }

        return $args;
    }

    /**
     * Redirect logged-in users from the login page to the portal.
     *
     * If a logged-in user visits the login page, redirect them to the portal.
     */
    public function portal_login_redirect() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $login_page_id    = get_option( 'spp_login_page_id' );
        $register_page_id = get_option( 'spp_register_page_id' );

        $on_login    = $login_page_id && is_page( (int) $login_page_id );
        $on_register = $register_page_id && is_page( (int) $register_page_id );

        if ( ! $on_login && ! $on_register ) {
            return;
        }

        // User is logged in and on the login/register page — redirect to portal.
        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( $portal_page_id && 'publish' === get_post_status( $portal_page_id ) ) {
            wp_safe_redirect( get_permalink( $portal_page_id ) );
            exit;
        }
    }

    /**
     * Redirect spp_client users to the portal after WordPress login.
     *
     * @param string           $redirect_to           The redirect URL.
     * @param string           $requested_redirect_to The originally requested redirect URL.
     * @param WP_User|WP_Error $user                  The user object.
     * @return string The redirect URL.
     */
    public function portal_after_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( ! $user instanceof WP_User ) {
            return $redirect_to;
        }

        // Only redirect spp_client users (not admins).
        if ( ! in_array( 'spp_client', $user->roles, true ) ) {
            return $redirect_to;
        }

        // If a specific redirect was explicitly requested, honor it.
        if ( ! empty( $requested_redirect_to ) && admin_url() !== $requested_redirect_to ) {
            return $redirect_to;
        }

        // Check for a configured login redirect page.
        $login_redirect_page = (int) get_option( 'spp_login_redirect_page', 0 );
        if ( $login_redirect_page && 'publish' === get_post_status( $login_redirect_page ) ) {
            return get_permalink( $login_redirect_page );
        }

        // Default: redirect to the portal page.
        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( $portal_page_id && 'publish' === get_post_status( $portal_page_id ) ) {
            return get_permalink( $portal_page_id );
        }

        return $redirect_to;
    }

    /**
     * Redirect WooCommerce My Account page to the SPP portal.
     *
     * Only registered when WooCommerce is active. When the plugin is
     * deactivated, this hook is removed and WC My Account works normally.
     */
    public function redirect_wc_myaccount_to_portal() {
        if ( ! function_exists( 'wc_get_page_id' ) ) {
            return;
        }

        $myaccount_page_id = wc_get_page_id( 'myaccount' );
        if ( ! $myaccount_page_id || ! is_page( $myaccount_page_id ) ) {
            return;
        }

        // Not logged in — redirect to SPP login page if configured.
        if ( ! is_user_logged_in() ) {
            $login_page_id = get_option( 'spp_login_page_id' );
            if ( $login_page_id && 'publish' === get_post_status( $login_page_id ) ) {
                wp_safe_redirect( get_permalink( $login_page_id ) );
                exit;
            }
            return; // Fall through to WC's own login form.
        }

        // Only redirect portal clients and admins.
        $user = wp_get_current_user();
        if ( ! in_array( 'spp_client', (array) $user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( ! $portal_page_id || 'publish' !== get_post_status( $portal_page_id ) ) {
            return;
        }

        // Map WC endpoints to portal views.
        $view = '';
        if ( function_exists( 'is_wc_endpoint_url' ) ) {
            if ( is_wc_endpoint_url( 'orders' ) ) {
                $view = 'wc-orders';
            } elseif ( is_wc_endpoint_url( 'downloads' ) ) {
                $view = 'wc-downloads';
            } elseif ( is_wc_endpoint_url( 'edit-address' ) || is_wc_endpoint_url( 'edit-account' ) ) {
                $view = 'profile';
            } elseif ( is_wc_endpoint_url( 'payment-methods' ) ) {
                $view = 'payment-methods';
            }
        }

        $redirect_url = get_permalink( $portal_page_id );
        if ( ! empty( $view ) ) {
            $redirect_url = add_query_arg( 'view', $view, $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Get the logout redirect URL for portal clients.
     *
     * @return string The logout redirect URL.
     */
    public static function get_logout_redirect_url() {
        $logout_page = (int) get_option( 'spp_logout_redirect_page', 0 );
        if ( $logout_page && 'publish' === get_post_status( $logout_page ) ) {
            return get_permalink( $logout_page );
        }
        return home_url();
    }

    /**
     * Override the portal page title with the configured setting.
     *
     * @param string $title   The page title.
     * @param int    $post_id The post ID.
     * @return string The filtered title.
     */
    public function override_portal_page_title( $title, $post_id ) {
        $portal_page_id = (int) get_option( 'spp_portal_page_id', 0 );
        if ( ! $portal_page_id || (int) $post_id !== $portal_page_id ) {
            return $title;
        }

        $custom_title = get_option( 'spp_portal_title', '' );
        if ( ! empty( $custom_title ) ) {
            return $custom_title;
        }

        return $title;
    }

    /**
     * AJAX handler: Recreate portal pages.
     */
    public function ajax_recreate_pages() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        require_once SPP_PLUGIN_DIR . 'includes/class-spp-activator.php';
        SPP_Activator::create_pages();
        flush_rewrite_rules();

        $portal_page_id   = get_option( 'spp_portal_page_id' );
        $login_page_id    = get_option( 'spp_login_page_id' );
        $register_page_id = get_option( 'spp_register_page_id' );

        wp_send_json_success( array(
            'message'          => __( 'Portal pages created successfully.', 'cube-payment-portal' ),
            'portal_page_id'   => $portal_page_id,
            'login_page_id'    => $login_page_id,
            'register_page_id' => $register_page_id,
            'portal_url'       => $portal_page_id ? get_permalink( $portal_page_id ) : '',
            'login_url'        => $login_page_id ? get_permalink( $login_page_id ) : '',
            'register_url'     => $register_page_id ? get_permalink( $register_page_id ) : '',
        ) );
    }

    /**
     * Show security and compatibility notices in admin.
     */
    public function show_security_notices() {
        // Only show on plugin pages.
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'spp' ) === false ) {
            return;
        }

        // Only show to administrators.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check for OpenSSL in production mode.
        $environment = get_option( 'spp_environment', 'production' );
        if ( 'production' === $environment && ! function_exists( 'openssl_encrypt' ) ) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__( 'Cube Payment Portal Security Warning:', 'cube-payment-portal' ) . '</strong> ';
            echo esc_html__( 'OpenSSL extension is not available. OAuth tokens cannot be encrypted properly. Please enable the OpenSSL PHP extension for secure operation.', 'cube-payment-portal' );
            echo '</p></div>';
        }

        // Check for webhook signature key in production.
        if ( 'production' === $environment && empty( get_option( 'spp_webhook_signature_key', '' ) ) ) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>' . esc_html__( 'Cube Payment Portal:', 'cube-payment-portal' ) . '</strong> ';
            echo esc_html__( 'Webhook signature key is not configured. Webhooks are disabled in production without signature verification. Configure this in Settings > Webhooks.', 'cube-payment-portal' );
            echo '</p></div>';
        }

        // Check for plugin update availability.
        $update_plugins = get_site_transient( 'update_plugins' );
        if ( isset( $update_plugins->response[ SPP_PLUGIN_BASENAME ] ) ) {
            $new_version         = $update_plugins->response[ SPP_PLUGIN_BASENAME ]->new_version;
            $auto_updates        = (array) get_site_option( 'auto_update_plugins', array() );
            $auto_update_enabled = (bool) get_option( 'spp_auto_update', false ) || in_array( SPP_PLUGIN_BASENAME, $auto_updates, true );

            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Cube Payment Portal:', 'cube-payment-portal' ) . '</strong> ';
            printf(
                /* translators: 1: new version number, 2: current version number */
                esc_html__( 'Version %1$s is available (you are running %2$s).', 'cube-payment-portal' ),
                esc_html( $new_version ),
                esc_html( SPP_VERSION )
            );

            if ( $auto_update_enabled ) {
                echo ' ' . esc_html__( 'Auto-updates are enabled. The update will be installed automatically.', 'cube-payment-portal' );
            } else {
                echo ' <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Update now', 'cube-payment-portal' ) . '</a>';
            }

            echo '</p></div>';
        }

        // Check for Square API version (quarterly update reminder).
        $this->check_api_version_notice();
    }

    /**
     * Control whether this plugin should be auto-updated.
     *
     * Hooks into the WordPress 5.5+ auto_update_plugin filter.
     *
     * @param bool|null $update Whether to update.
     * @param object    $item   The plugin update object.
     * @return bool|null Whether to auto-update this plugin.
     */
    public function control_auto_update( $update, $item ) {
        if ( ! isset( $item->plugin ) || SPP_PLUGIN_BASENAME !== $item->plugin ) {
            return $update;
        }

        // Check our settings page option.
        if ( get_option( 'spp_auto_update', false ) ) {
            return true;
        }

        // Also respect WordPress's native auto_update_plugins option (from Plugins page toggle).
        $auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
        if ( in_array( SPP_PLUGIN_BASENAME, $auto_updates, true ) ) {
            return true;
        }

        return $update;
    }

    /**
     * Provide auto-update toggle HTML for the Plugins page.
     *
     * Ensures the Enable/Disable auto-updates link appears for this plugin
     * even before it is listed on WordPress.org.
     *
     * @param string $html        The existing auto-update HTML.
     * @param string $plugin_file Plugin basename.
     * @param array  $plugin_data Plugin header data.
     * @return string Modified HTML.
     */
    public function auto_update_setting_html( $html, $plugin_file, $plugin_data ) {
        if ( SPP_PLUGIN_BASENAME !== $plugin_file ) {
            return $html;
        }

        // Only on WordPress 5.5+.
        if ( version_compare( get_bloginfo( 'version' ), '5.5', '<' ) ) {
            return $html;
        }

        // If WordPress already generated a working toggle link, keep it.
        if ( ! empty( $html ) && strpos( $html, 'toggle-auto-update' ) !== false ) {
            return $html;
        }

        // Override any "Auto-updates unavailable" text with a working toggle.
        $auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
        $enabled      = in_array( $plugin_file, $auto_updates, true ) || get_option( 'spp_auto_update', false );

        if ( $enabled ) {
            $text   = __( 'Disable auto-updates', 'cube-payment-portal' );
            $action = 'disable';
        } else {
            $text   = __( 'Enable auto-updates', 'cube-payment-portal' );
            $action = 'enable';
        }

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'toggle-auto-updates',
                    'plugin' => rawurlencode( $plugin_file ),
                    'paged'  => 1,
                ),
                'plugins.php'
            ),
            'updates'
        );

        $html = sprintf(
            '<a href="%s" class="toggle-auto-update aria-button-if-js" data-wp-action="%s">
                <span class="dashicons dashicons-update spin hidden"></span><span class="label">%s</span>
            </a>',
            esc_url( $url ),
            esc_attr( $action ),
            esc_html( $text )
        );

        return $html;
    }

    /**
     * Sync spp_auto_update option to WordPress core auto_update_plugins.
     *
     * When the admin toggles auto-updates from the plugin settings page,
     * this keeps the Plugins page toggle in sync.
     *
     * @param mixed $old_value Previous value.
     * @param mixed $new_value New value.
     */
    public function sync_auto_update_to_core( $old_value, $new_value ) {
        $auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
        $in_list      = in_array( SPP_PLUGIN_BASENAME, $auto_updates, true );

        if ( $new_value && ! $in_list ) {
            $auto_updates[] = SPP_PLUGIN_BASENAME;
            update_site_option( 'auto_update_plugins', $auto_updates );
        } elseif ( ! $new_value && $in_list ) {
            $auto_updates = array_diff( $auto_updates, array( SPP_PLUGIN_BASENAME ) );
            update_site_option( 'auto_update_plugins', $auto_updates );
        }
    }

    /**
     * Check if Square API version might need updating.
     *
     * Square releases new API versions quarterly and deprecates old ones.
     * This notice reminds admins to check for updates.
     */
    private function check_api_version_notice() {
        // Only check once per week.
        $last_check = get_transient( 'spp_api_version_check' );
        if ( false !== $last_check ) {
            return;
        }

        set_transient( 'spp_api_version_check', time(), WEEK_IN_SECONDS );

        // Parse the current API version date.
        $api_version = SPP_Square_Client::API_VERSION; // Format: YYYY-MM-DD
        $version_date = strtotime( $api_version );

        if ( ! $version_date ) {
            return;
        }

        // If the API version is more than 6 months old, show a notice.
        $six_months_ago = strtotime( '-6 months' );
        if ( $version_date < $six_months_ago ) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Cube Payment Portal:', 'cube-payment-portal' ) . '</strong> ';
            printf(
                /* translators: 1: current API version, 2: Square changelog URL */
                esc_html__( 'The Square API version (%1$s) may be outdated. Square releases new API versions quarterly. Check the %2$s for updates and consider updating the plugin.', 'cube-payment-portal' ),
                esc_html( $api_version ),
                '<a href="https://developer.squareup.com/docs/build-basics/api-lifecycle" target="_blank">' . esc_html__( 'Square API changelog', 'cube-payment-portal' ) . '</a>'
            );
            echo '</p></div>';
        }
    }
}
