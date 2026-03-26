<?php
/**
 * Admin functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Admin
 *
 * Handles all admin-side functionality.
 */
class SPP_Admin {

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
        
        // Register AJAX handlers.
        add_action( 'wp_ajax_spp_save_service', array( $this, 'ajax_save_service' ) );
        add_action( 'wp_ajax_spp_delete_service', array( $this, 'ajax_delete_service' ) );
        add_action( 'wp_ajax_spp_get_team_member_details', array( $this, 'ajax_get_team_member_details' ) );
        add_action( 'wp_ajax_spp_save_team_member', array( $this, 'ajax_save_team_member' ) );
        add_action( 'wp_ajax_spp_delete_team_member', array( $this, 'ajax_delete_team_member' ) );
        add_action( 'wp_ajax_spp_create_booking', array( $this, 'ajax_create_booking' ) );
        add_action( 'wp_ajax_spp_update_booking', array( $this, 'ajax_update_booking' ) );

        add_action( 'wp_ajax_spp_cancel_booking', array( $this, 'ajax_cancel_booking' ) );
        add_action( 'wp_ajax_spp_search_customers_autocomplete', array( $this, 'ajax_search_customers_autocomplete' ) );
    }

    /**
     * Process form submissions before any output.
     * Hooked to admin_init to allow redirects.
     */
    public function process_form_submissions() {
        // Only process on our admin pages.
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'spp-' ) === false ) {
            return;
        }

        // Handle subscription creation.
        if ( isset( $_POST['spp_create_subscription'] ) && 
             isset( $_POST['spp_create_nonce'] ) && 
             wp_verify_nonce( $_POST['spp_create_nonce'], 'spp_create_subscription' ) &&
             current_user_can( 'manage_options' ) ) {
            
            $this->handle_create_subscription();
        }

        // Handle invoice creation.
        if ( isset( $_POST['spp_create_invoice'] ) && 
             isset( $_POST['spp_invoice_nonce'] ) && 
             wp_verify_nonce( $_POST['spp_invoice_nonce'], 'spp_create_invoice' ) &&
             current_user_can( 'manage_options' ) ) {
            
            $this->handle_create_invoice();
        }
    }

    /**
     * Handle invoice creation form submission.
     */
    private function handle_create_invoice() {
        // Load required classes.
        if ( ! class_exists( 'SPP_Invoices' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
        }

        $invoices_api = new SPP_Invoices();
        $should_publish = ( 'publish' === $_POST['spp_create_invoice'] );
        $customer_value = sanitize_text_field( $_POST['customer_id'] ?? '' );
        $due_date = sanitize_text_field( $_POST['due_date'] ?? '' );

        // Validate due date format.
        if ( ! empty( $due_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) ) {
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-invoices',
                'action' => 'create',
                'error' => 'invalid_date_format'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Validate due date is not in the past.
        if ( ! empty( $due_date ) && strtotime( $due_date ) < strtotime( 'today' ) ) {
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-invoices',
                'action' => 'create',
                'error' => 'past_due_date'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Validate customer is selected.
        if ( empty( $customer_value ) ) {
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-invoices',
                'action' => 'create',
                'error' => 'missing_customer'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $invoice_data = array(
            'title'           => sanitize_text_field( $_POST['invoice_title'] ?? '' ),
            'description'     => sanitize_textarea_field( $_POST['invoice_description'] ?? '' ),
            'due_date'        => $due_date,
            'delivery_method' => sanitize_text_field( $_POST['delivery_method'] ?? 'EMAIL' ),
            'payment_type'    => sanitize_text_field( $_POST['payment_type'] ?? 'BALANCE' ),
            'line_items'      => array(),
        );

        // Check if customer is WP user (numeric) or Square customer (starts with 'sq_').
        if ( strpos( $customer_value, 'sq_' ) === 0 ) {
            $invoice_data['customer_id'] = substr( $customer_value, 3 );
        } else {
            $invoice_data['wp_user_id'] = intval( $customer_value );
        }

        // Process line items.
        if ( ! empty( $_POST['line_item_name'] ) ) {
            foreach ( $_POST['line_item_name'] as $index => $name ) {
                if ( empty( $name ) ) continue;

                $quantity = floatval( $_POST['line_item_qty'][ $index ] ?? 1 );
                $price_dollars = floatval( $_POST['line_item_price'][ $index ] ?? 0 );

                $invoice_data['line_items'][] = array(
                    'name'             => sanitize_text_field( $name ),
                    'quantity'         => (string) $quantity,
                    'note'             => sanitize_text_field( $_POST['line_item_note'][ $index ] ?? '' ),
                    'base_price_money' => array(
                        'amount'   => SPP_Currency::dollars_to_cents( $price_dollars ),
                        'currency' => 'USD',
                    ),
                );
            }
        }

        // Validate at least one line item exists.
        if ( empty( $invoice_data['line_items'] ) ) {
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-invoices',
                'action' => 'create',
                'error' => 'missing_line_items'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Create the invoice.
        $result = $invoices_api->create_invoice_with_order( $invoice_data );

        if ( is_wp_error( $result ) ) {
            // Store error in transient to avoid unsanitized messages in URL.
            set_transient( 'spp_invoice_error_' . get_current_user_id(), sanitize_text_field( $result->get_error_message() ), 30 );
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-invoices',
                'action' => 'create',
                'error' => 'creation_failed'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // If publish was requested, publish the invoice now.
        if ( $should_publish ) {
            $publish_result = $invoices_api->publish_invoice( $result['id'] );
            if ( is_wp_error( $publish_result ) ) {
                // Store error in transient to avoid unsanitized messages in URL.
                set_transient( 'spp_invoice_error_' . get_current_user_id(), sanitize_text_field( $publish_result->get_error_message() ), 30 );
                wp_redirect( add_query_arg( array( 
                    'page' => 'spp-invoices',
                    'action' => 'create',
                    'error' => 'publish_failed'
                ), admin_url( 'admin.php' ) ) );
                exit;
            }

            // Success - published, redirect to invoices list.
            wp_redirect( admin_url( 'admin.php?page=spp-invoices&published=1' ) );
            exit;
        }

        // Success - draft, redirect to invoices list.
        wp_redirect( admin_url( 'admin.php?page=spp-invoices&created=1' ) );
        exit;
    }

    /**
     * Handle subscription creation form submission.
     */
    private function handle_create_subscription() {
        // Load required classes.
        if ( ! class_exists( 'SPP_Subscriptions' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
        }

        $subscriptions_api = new SPP_Subscriptions();
        $customer_id = sanitize_text_field( $_POST['customer_id'] ?? '' );
        $plan_variation_id = sanitize_text_field( $_POST['plan_variation_id'] ?? '' );
        $card_id = sanitize_text_field( $_POST['card_id'] ?? '' );
        $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
        $items_json = wp_unslash( $_POST['subscription_items'] ?? '[]' );
        $pricing_type = sanitize_text_field( $_POST['pricing_type'] ?? 'STATIC' );

        if ( empty( $customer_id ) || empty( $plan_variation_id ) || empty( $card_id ) ) {
            wp_redirect( add_query_arg( array( 
                'page' => 'spp-create-subscription',
                'error' => 'missing_fields'
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $subscription_data = array(
            'customer_id'       => $customer_id,
            'plan_variation_id' => $plan_variation_id,
            'card_id'           => $card_id,
        );

        if ( ! empty( $start_date ) ) {
            $subscription_data['start_date'] = $start_date;
        }

        // For RELATIVE pricing, create draft order template.
        $items = json_decode( $items_json, true );
        if ( 'RELATIVE' === $pricing_type && ! empty( $items ) && is_array( $items ) ) {
            // Load the Orders API class.
            if ( ! class_exists( 'SPP_Orders' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-orders.php';
            }

            $orders_api = new SPP_Orders();

            // Build line items for the order template.
            $line_items = array();
            foreach ( $items as $item ) {
                $line_items[] = array(
                    'catalog_object_id' => $item['variation_id'],
                    'quantity'          => (string) intval( $item['quantity'] ?? 1 ),
                );
            }

            // Create a draft order (order template).
            $order_result = $orders_api->create_draft_order( $line_items );

            if ( is_wp_error( $order_result ) ) {
                set_transient( 'spp_subscription_error_' . get_current_user_id(), sanitize_text_field( $order_result->get_error_message() ), 30 );
                wp_redirect( add_query_arg( array(
                    'page' => 'spp-create-subscription',
                    'error' => 'order_template_failed',
                ), admin_url( 'admin.php' ) ) );
                exit;
            }

            // Add phases with the order template ID.
            $subscription_data['phases'] = array(
                array(
                    'ordinal'           => 0,
                    'order_template_id' => $order_result['id'],
                ),
            );
        }

        // Get customer name from database.
        $customer_name = '';
        $customer = SPP_Database::get_customer_by_square_id( $customer_id );
        if ( $customer ) {
            $customer_name = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
            if ( empty( $customer_name ) && ! empty( $customer['email'] ) ) {
                $customer_name = $customer['email'];
            }
        }

        // Get plan name for logging.
        $plan_name = '';
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }
        $catalog = new SPP_Catalog();
        $plans = $catalog->get_subscription_plans();
        
        if ( ! is_wp_error( $plans ) ) {
            foreach ( $plans as $plan ) {
                $variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();
                foreach ( $variations as $variation ) {
                    if ( ( $variation['id'] ?? '' ) === $plan_variation_id ) {
                        $plan_name = $plan['name'];
                        if ( ! empty( $variation['subscription_plan_variation_data']['name'] ) ) {
                            $plan_name .= ' - ' . $variation['subscription_plan_variation_data']['name'];
                        }
                        break 2;
                    }
                }
            }
        }

        // Create the subscription.
        $result = $subscriptions_api->create_subscription( $subscription_data, array(
            'plan_name'     => $plan_name,
            'customer_name' => $customer_name,
        ) );

        if ( is_wp_error( $result ) ) {
            set_transient( 'spp_subscription_error_' . get_current_user_id(), sanitize_text_field( $result->get_error_message() ), 30 );
            wp_redirect( add_query_arg( array(
                'page' => 'spp-create-subscription',
                'error' => 'creation_failed',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Success - redirect to subscriptions list.
        wp_redirect( admin_url( 'admin.php?page=spp-subscriptions&created=1' ) );
        exit;
    }

    /**
     * Check if we're on a Square Portal admin page.
     *
     * @return bool True if on a Square Portal page.
     */
    private function is_spp_admin_page() {
        $screen = get_current_screen();
        
        if ( ! $screen ) {
            return false;
        }

        // Check for main page and all submenu pages.
        $spp_pages = array(
            'toplevel_page_cube-payment-portal',
            'square-portal_page_spp-customers',
            'square-portal_page_spp-subscriptions',
            'square-portal_page_spp-invoices',
            'square-portal_page_spp-orders',
            'square-portal_page_spp-catalog',
            'square-portal_page_spp-inventory',
            'square-portal_page_spp-loyalty',
            'square-portal_page_spp-gift-cards',
            'square-portal_page_spp-disputes',
            'square-portal_page_spp-bookings',
            'square-portal_page_spp-transactions',
            'square-portal_page_spp-settings',
            'square-portal_page_spp-api-test',
        );

        return in_array( $screen->id, $spp_pages, true ) || strpos( $screen->id, 'cube-payment-portal' ) !== false || strpos( $screen->id, 'spp-' ) !== false;
    }

    /**
     * Register admin stylesheets.
     */
    public function enqueue_styles() {
        if ( $this->is_spp_admin_page() ) {
            // Enqueue jQuery UI CSS for autocomplete.
            wp_enqueue_style( 'wp-jquery-ui-dialog' );
            
            wp_enqueue_style(
                'spp-admin',
                SPP_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                $this->version
            );
        }
    }

    /**
     * Customize admin footer text on plugin pages.
     *
     * @param string $text Default footer text.
     * @return string Modified footer text.
     */
    public function custom_admin_footer_text( $text ) {
        if ( $this->is_spp_admin_page() ) {
            return sprintf(
                /* translators: %1$s: Cube Payment Portal, %2$s: Author name */
                esc_html__( 'Powered by %1$s by %2$s', 'cube-payment-portal' ),
                '<strong>Cube Payment Portal</strong>',
                '<a href="https://destinlmincy.com" target="_blank" style="text-decoration: none;">Destin L. Mincy</a>'
            );
        }
        return $text;
    }

    /**
     * Customize admin footer version on plugin pages.
     *
     * @param string $text Default version text.
     * @return string Modified version text.
     */
    public function custom_admin_footer_version( $text ) {
        if ( $this->is_spp_admin_page() ) {
            return 'v' . $this->version;
        }
        return $text;
    }

    /**
     * Register admin scripts.
     */
    public function enqueue_scripts() {
        if ( $this->is_spp_admin_page() ) {
            // Enqueue WordPress media uploader.
            wp_enqueue_media();

            // Enqueue jQuery UI for autocomplete on invoice pages.
            wp_enqueue_script( 'jquery-ui-autocomplete' );

            // Enqueue color picker and sortable on settings page.
            if ( isset( $_GET['page'] ) && 'spp-settings' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
                wp_enqueue_style( 'wp-color-picker' );
                wp_enqueue_script( 'wp-color-picker' );
                wp_enqueue_script( 'jquery-ui-sortable' );
                wp_add_inline_script( 'wp-color-picker', 'jQuery(document).ready(function($){$(".spp-color-picker").wpColorPicker();});' );
            }
            
            wp_enqueue_script(
                'spp-admin',
                SPP_PLUGIN_URL . 'assets/js/admin.js',
                array( 'jquery', 'jquery-ui-autocomplete' ),
                $this->version,
                true
            );

            wp_localize_script(
                'spp-admin',
                'sppAdmin',
                array(
                    'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                    'adminUrl' => admin_url(),
                    'nonce'    => wp_create_nonce( 'spp_admin_nonce' ),
                )
            );

            // Enqueue Calendar scripts if on bookings page with calendar tab
            if ( isset( $_GET['page'] ) && 'spp-bookings' === $_GET['page'] && isset( $_GET['tab'] ) && 'calendar' === $_GET['tab'] ) {
                wp_enqueue_script(
                    'spp-fullcalendar',
                    'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js',
                    array(),
                    '6.1.10',
                    true
                );

                // Add SRI integrity hash and crossorigin attribute for FullCalendar CDN script.
                add_filter( 'script_loader_tag', array( $this, 'add_sri_attributes' ), 10, 3 );

                wp_enqueue_script(
                    'spp-admin-calendar',
                    SPP_PLUGIN_URL . 'assets/js/admin-calendar.js',
                    array( 'jquery', 'spp-fullcalendar' ),
                    $this->version,
                    true
                );
                
                wp_localize_script(
                    'spp-admin-calendar',
                    'sppCalendar',
                    array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( 'spp_admin_nonce' ),
                    )
                );
            }
        }
    }

    /**
     * Add SRI integrity and crossorigin attributes to CDN-loaded scripts.
     *
     * @param string $tag    The script tag HTML.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     * @return string Modified script tag with SRI attributes.
     */
    public function add_sri_attributes( $tag, $handle, $src ) {
        $sri_hashes = array(
            'spp-fullcalendar' => 'sha384-WfE/vOHqht3KDj6FvpwQUf3UxEPUHoGJ3w1yZ8rhpLWnVigt8HjXL2zXqtcfS7mf',
        );

        if ( isset( $sri_hashes[ $handle ] ) ) {
            $tag = str_replace(
                '></',
                ' integrity="' . $sri_hashes[ $handle ] . '" crossorigin="anonymous"></',
                $tag
            );
        }

        return $tag;
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu() {
        // Main menu.
        add_menu_page(
            __( 'Square Portal', 'cube-payment-portal' ),
            __( 'Square Portal', 'cube-payment-portal' ),
            'manage_options',
            'cube-payment-portal',
            array( $this, 'render_dashboard_page' ),
            'dashicons-money-alt',
            30
        );

        // Dashboard.
        add_submenu_page(
            'cube-payment-portal',
            __( 'Dashboard', 'cube-payment-portal' ),
            __( 'Dashboard', 'cube-payment-portal' ),
            'manage_options',
            'cube-payment-portal',
            array( $this, 'render_dashboard_page' )
        );

        // Customers (Always enabled for now, or check core?).
        add_submenu_page(
            'cube-payment-portal',
            __( 'Customers', 'cube-payment-portal' ),
            __( 'Customers', 'cube-payment-portal' ),
            'manage_options',
            'spp-customers',
            array( $this, 'render_customers_page' )
        );

        // Subscriptions.
        if ( get_option( 'spp_feature_subscriptions', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Subscriptions', 'cube-payment-portal' ),
                __( 'Subscriptions', 'cube-payment-portal' ),
                'manage_options',
                'spp-subscriptions',
                array( $this, 'render_subscriptions_page' )
            );
        }

        // Invoices.
        if ( get_option( 'spp_feature_invoices', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Invoices', 'cube-payment-portal' ),
                __( 'Invoices', 'cube-payment-portal' ),
                'manage_options',
                'spp-invoices',
                array( $this, 'render_invoices_page' )
            );
        }

        // Orders.
        if ( get_option( 'spp_feature_orders', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Orders', 'cube-payment-portal' ),
                __( 'Orders', 'cube-payment-portal' ),
                'manage_options',
                'spp-orders',
                array( $this, 'render_orders_page' )
            );
        }

        // Catalog.
        if ( get_option( 'spp_feature_catalog', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Catalog', 'cube-payment-portal' ),
                __( 'Catalog', 'cube-payment-portal' ),
                'manage_options',
                'spp-catalog',
                array( $this, 'render_catalog_page' )
            );
        }

        // Inventory.
        if ( get_option( 'spp_feature_inventory', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Inventory', 'cube-payment-portal' ),
                __( 'Inventory', 'cube-payment-portal' ),
                'manage_options',
                'spp-inventory',
                array( $this, 'render_inventory_page' )
            );
        }

        // Loyalty.
        if ( get_option( 'spp_feature_loyalty', '1' ) ) { // Assuming loyalty is disabled by default for now
             add_submenu_page(
                'cube-payment-portal',
                __( 'Loyalty', 'cube-payment-portal' ),
                __( 'Loyalty', 'cube-payment-portal' ),
                'manage_options',
                'spp-loyalty',
                array( $this, 'render_loyalty_page' )
            );
        }

        // Gift Cards.
        if ( get_option( 'spp_feature_gift_cards', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Gift Cards', 'cube-payment-portal' ),
                __( 'Gift Cards', 'cube-payment-portal' ),
                'manage_options',
                'spp-gift-cards',
                array( $this, 'render_gift_cards_page' )
            );
        }

        // Disputes.
        if ( get_option( 'spp_feature_disputes', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Disputes', 'cube-payment-portal' ),
                __( 'Disputes', 'cube-payment-portal' ),
                'manage_options',
                'spp-disputes',
                array( $this, 'render_disputes_page' )
            );
        }

        // Bookings.
        if ( get_option( 'spp_feature_bookings', '1' ) ) {
            add_submenu_page(
                'cube-payment-portal',
                __( 'Bookings', 'cube-payment-portal' ),
                __( 'Bookings', 'cube-payment-portal' ),
                'manage_options',
                'spp-bookings',
                array( $this, 'render_bookings_page' )
            );
        }

        // Transactions.
        add_submenu_page(
            'cube-payment-portal',
            __( 'Transactions', 'cube-payment-portal' ),
            __( 'Transactions', 'cube-payment-portal' ),
            'manage_options',
            'spp-transactions',
            array( $this, 'render_transactions_page' )
        );

        // Settings.
        add_submenu_page(
            'cube-payment-portal',
            __( 'Settings', 'cube-payment-portal' ),
            __( 'Settings', 'cube-payment-portal' ),
            'manage_options',
            'spp-settings',
            array( $this, 'render_settings_page' )
        );

        // API Test (for debugging/development).
        add_submenu_page(
            'cube-payment-portal',
            __( 'API Test', 'cube-payment-portal' ),
            __( 'API Test', 'cube-payment-portal' ),
            'manage_options',
            'spp-api-test',
            array( $this, 'render_api_test_page' )
        );
    }

    /**
     * Get invoice statistics for the dashboard.
     *
     * @return array Invoice stats (outstanding count, amount, overdue count).
     */
    public function get_dashboard_invoice_stats() {
        global $wpdb;
        $table_invoices = $wpdb->prefix . 'spp_invoices';

        // Outstanding (Sent or Draft)
        $outstanding = $wpdb->get_row( "
            SELECT COUNT(*) as count, SUM(amount) as total 
            FROM $table_invoices 
            WHERE status IN ('SENT', 'SCHEDULED', 'DRAFT')
        ", ARRAY_A );

        // Overdue
        $overdue = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) 
            FROM $table_invoices 
            WHERE status = 'SENT' AND due_date < %s
        ", current_time( 'Y-m-d' ) ) );

        return array(
            'outstanding_count' => (int) ( $outstanding['count'] ?? 0 ),
            'outstanding_amount' => (float) ( $outstanding['total'] ?? 0 ),
            'overdue_count'      => (int) $overdue,
        );
    }

    /**
     * Get recent invoices for the dashboard.
     *
     * @param int $limit Number of invoices to retrieve.
     * @return array Recent invoices.
     */
    public function get_dashboard_recent_invoices( $limit = 5 ) {
        global $wpdb;
        $table_invoices = $wpdb->prefix . 'spp_invoices';
        $table_customers = $wpdb->prefix . 'spp_customers';
        $limit = absint( $limit );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe internal values.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*,
                   COALESCE(c.given_name, '') as first_name,
                   COALESCE(c.family_name, '') as last_name,
                   c.email as customer_email,
                   c.phone as customer_phone,
                   c.company_name
            FROM $table_invoices i
            LEFT JOIN $table_customers c ON i.square_customer_id = c.square_customer_id
            ORDER BY i.created_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Get dispute statistics for the dashboard.
     *
     * @return array Dispute stats (actionable count, active disputes list).
     */
    public function get_dashboard_dispute_stats() {
        // Check transient first to avoid API spam on dashboard load.
        $cached_disputes = get_transient( 'spp_dashboard_disputes' );
        if ( false !== $cached_disputes ) {
            $disputes = $cached_disputes;
        } else {
            // Fetch from API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }
            $disputes_api = new SPP_Disputes();
            // We want all open disputes to calculate stats.
            $result = $disputes_api->list_disputes( array(
                'states' => array( 'EVIDENCE_REQUIRED', 'INQUIRY_EVIDENCE_REQUIRED', 'PROCESSING', 'INQUIRY_PROCESSING' )
            ) );

            if ( is_wp_error( $result ) ) {
                $disputes = array();
            } else {
                $disputes = $result['disputes'] ?? array();
                // Cache for 15 minutes.
                set_transient( 'spp_dashboard_disputes', $disputes, 15 * MINUTE_IN_SECONDS );
            }
        }

        // Filter for actionable disputes (Evidence Required).
        $actionable = array_filter( $disputes, function( $d ) {
            return in_array( $d['state'], array( 'EVIDENCE_REQUIRED', 'INQUIRY_EVIDENCE_REQUIRED' ), true );
        } );

        return array(
            'actionable_count' => count( $actionable ),
            'total_active'     => count( $disputes ),
            'recent_disputes'  => array_slice( $disputes, 0, 5 ),
        );
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }

    /**
     * Render customers page.
     */
    public function render_customers_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/customers.php';
    }

    /**
     * Render subscriptions page.
     */
    public function render_subscriptions_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/subscriptions.php';
    }

    /**
     * Render invoices page.
     */
    public function render_invoices_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/invoices.php';
    }

    /**
     * Render orders page.
     */
    public function render_orders_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/orders.php';
    }

    /**
     * Render catalog page.
     */
    public function render_catalog_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/catalog.php';
    }

    /**
     * Render inventory page.
     */
    public function render_inventory_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/inventory.php';
    }

    /**
     * Render loyalty page.
     */
    public function render_loyalty_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/loyalty.php';
    }

    /**
     * Render gift cards page.
     */
    public function render_gift_cards_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/gift-cards.php';
    }

    /**
     * Render disputes page.
     * Routes to list or detail view based on URL parameters.
     */
    public function render_disputes_page() {
        // Verify user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }

        // Check if viewing a specific dispute detail.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view, nonce verified in AJAX calls for actual actions.
        $view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list';
        // Validate view parameter.
        $view = in_array( $view, array( 'list', 'detail' ), true ) ? $view : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $dispute_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

        if ( 'detail' === $view && ! empty( $dispute_id ) ) {
            include SPP_PLUGIN_DIR . 'admin/partials/dispute-detail.php';
        } else {
            include SPP_PLUGIN_DIR . 'admin/partials/disputes.php';
        }
    }

    /**
     * Render bookings page.
     */
    public function render_bookings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/bookings.php';
    }

    /**
     * Render transactions page.
     */
    public function render_transactions_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/transactions.php';
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }

    /**
     * Render API test page.
     */
    public function render_api_test_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
        }
        include SPP_PLUGIN_DIR . 'admin/partials/api-test.php';
    }

    /**
     * AJAX handler for fetching transactions.
     */
    public function ajax_get_transactions() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Sanitize and gather filter parameters.
        $args = array(
            'search'                 => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'status'                 => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
            'square_plan_id'         => isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id'] ) ) : '',
            'square_subscription_id' => isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '',
            'date_from'              => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
            'date_to'                => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
            'limit'                  => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20,
            'offset'                 => isset( $_POST['page'] ) ? ( absint( $_POST['page'] ) - 1 ) * ( isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20 ) : 0,
            'order_by'               => isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'id',
            'order'                  => isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'ASC' ? 'ASC' : 'DESC',
        );

        // Format date range if provided.
        if ( ! empty( $args['date_from'] ) ) {
            $args['date_from'] = gmdate( 'Y-m-d 00:00:00', strtotime( $args['date_from'] ) );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $args['date_to'] = gmdate( 'Y-m-d 23:59:59', strtotime( $args['date_to'] ) );
        }

        // Fetch transactions.
        global $wpdb;
        $transactions = SPP_Database::get_transactions( $args );

        if ( is_null( $transactions ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Error: get_transactions returned null. SQL Error: ' . $wpdb->last_error );
            }
            wp_send_json_error( array( 'message' => __( 'Database error while fetching transactions.', 'cube-payment-portal' ) ) );
        }

        $total_count = SPP_Database::get_transactions_count( $args );

        // Get default currency for formatting.
        $default_currency = get_option( 'spp_default_currency', 'USD' );

        // Format transactions for response.
        $formatted = array();
        foreach ( $transactions as $transaction ) {
            $formatted[] = array(
                'id'                     => $transaction['id'],
                'square_payment_id'      => $transaction['square_payment_id'],
                'square_customer_id'     => $transaction['square_customer_id'] ?? '',
                'customer_name'          => $transaction['customer_name'] ?? __( 'Unknown', 'cube-payment-portal' ),
                'customer_email'         => $transaction['customer_email'] ?? '',
                'customer_phone'         => $this->format_phone_number( $transaction['customer_phone'] ?? '' ),
                'amount'                 => number_format( (float) $transaction['amount'], 2 ),
                'amount_raw'             => (float) $transaction['amount'],
                'currency'               => $transaction['currency'] ?? $default_currency,
                'status'                 => $transaction['status'],
                'status_label'           => ucfirst( $transaction['status'] ),
                'card_brand'             => $transaction['card_brand'] ?? '',
                'card_last_four'         => $transaction['card_last_four'] ?? '',
                'source_type'            => $transaction['source_type'] ?? 'one_time',
                'plan_name'              => $transaction['plan_name'] ?? '',
                'square_plan_id'         => $transaction['square_plan_id'] ?? '',
                'square_subscription_id' => $transaction['square_subscription_id'] ?? '',
                'created_at'             => $transaction['created_at'],
                'created_at_formatted'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction['created_at'] ) ),
            );
        }

        // Calculate pagination.
        $per_page = $args['limit'];
        $current_page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $total_pages = ceil( $total_count / $per_page );

        wp_send_json_success( array(
            'transactions' => $formatted,
            'pagination'   => array(
                'total'        => $total_count,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
            ),
        ) );
    }

    /**
     * AJAX handler for fetching subscription plans (for filter dropdown).
     */
    public function ajax_get_plans() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Try to get plans from cache first.
        $plans = get_transient( 'spp_subscription_plans' );

        if ( false === $plans ) {
            // Fetch from Square API.
            if ( ! class_exists( 'SPP_Catalog' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
            }

            $catalog = new SPP_Catalog();
            $plans = $catalog->get_subscription_plans();

            if ( is_wp_error( $plans ) ) {
                wp_send_json_error( array( 'message' => $plans->get_error_message() ) );
            }

            // Cache for 1 hour.
            set_transient( 'spp_subscription_plans', $plans, HOUR_IN_SECONDS );
        }

        // Format plans for dropdown.
        $formatted = array();
        if ( is_array( $plans ) ) {
            foreach ( $plans as $plan ) {
                // Get variations for the plan.
                $variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();

                foreach ( $variations as $variation ) {
                    $var_id = $variation['id'];
                    $var_data = $variation['subscription_plan_variation_data'] ?? array();
                    $var_name = $var_data['name'] ?? 'Default';

                    $formatted[] = array(
                        'id'   => $var_id,
                        'name' => $plan['name'] . ' - ' . $var_name,
                    );
                }
            }
        }

        wp_send_json_success( array( 'plans' => $formatted ) );
    }

    /**
     * AJAX handler for fetching invoices with sorting.
     */
    public function ajax_get_invoices() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Sanitize and gather filter parameters.
        $args = array(
            'search'   => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'status'   => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
            'limit'    => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20,
            'offset'   => isset( $_POST['page'] ) ? ( absint( $_POST['page'] ) - 1 ) * ( isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20 ) : 0,
            'order_by' => isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'id',
            'order'    => isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'ASC' ? 'ASC' : 'DESC',
        );

        // Fetch invoices.
        $invoices = SPP_Database::get_invoices( $args );
        $total_count = SPP_Database::get_invoices_count( $args );

        // Get default currency for formatting.
        $default_currency = get_option( 'spp_default_currency', 'USD' );
        $date_format = get_option( 'date_format' );
        $today = strtotime( 'today' );

        // Format invoices for response.
        $formatted = array();
        foreach ( $invoices as $invoice ) {
            $is_overdue = strtolower( $invoice['status'] ) === 'unpaid' && ! empty( $invoice['due_date'] ) && strtotime( $invoice['due_date'] ) < $today;
            
            $formatted[] = array(
                'id'                   => $invoice['id'],
                'invoice_number'       => $invoice['invoice_number'] ?? '',
                'square_invoice_id'    => $invoice['square_invoice_id'] ?? '',
                'square_customer_id'   => $invoice['square_customer_id'] ?? '',
                'customer_name'        => ! empty( $invoice['customer_name_square'] ) ? $invoice['customer_name_square'] : ( $invoice['customer_name'] ?? __( 'N/A', 'cube-payment-portal' ) ),
                'customer_email'       => ! empty( $invoice['customer_email_square'] ) ? $invoice['customer_email_square'] : ( $invoice['customer_email'] ?? '' ),
                'customer_phone'       => $this->format_phone_number( $invoice['customer_phone'] ?? '' ),
                'amount'               => number_format( (float) $invoice['amount'], 2 ),
                'amount_raw'           => (float) $invoice['amount'],
                'currency'             => $invoice['currency'] ?? $default_currency,
                'status'               => $invoice['status'],
                'status_label'         => $is_overdue ? __( 'Overdue', 'cube-payment-portal' ) : ucfirst( $invoice['status'] ),
                'is_overdue'           => $is_overdue,
                'due_date'             => $invoice['due_date'] ?? '',
                'due_date_formatted'   => ! empty( $invoice['due_date'] ) ? wp_date( $date_format, strtotime( $invoice['due_date'] ) ) : '—',
                'created_at'           => $invoice['created_at'] ?? '',
                'created_at_formatted' => ! empty( $invoice['created_at'] ) ? wp_date( $date_format, strtotime( $invoice['created_at'] ) ) : '—',
            );
        }

        // Calculate pagination.
        $per_page = $args['limit'];
        $current_page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $total_pages = ceil( $total_count / $per_page );

        wp_send_json_success( array(
            'invoices'   => $formatted,
            'pagination' => array(
                'total'        => $total_count,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
            ),
        ) );
    }

    /**
     * AJAX handler for fetching customers with sorting.
     */
    public function ajax_get_customers() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        global $wpdb;
        $transactions_table = $wpdb->prefix . 'spp_transactions';
        $subscriptions_table = $wpdb->prefix . 'spp_subscriptions';

        // Sanitize and gather filter parameters.
        $args = array(
            'search'  => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'status'  => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
            'limit'   => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20,
            'offset'  => isset( $_POST['page'] ) ? ( absint( $_POST['page'] ) - 1 ) * ( isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20 ) : 0,
            'orderby' => isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'given_name',
            'order'   => isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'DESC' ? 'DESC' : 'ASC',
        );

        // Fetch customers.
        $customers = SPP_Database::get_customers( $args );
        $total_count = SPP_Database::get_customers_count( $args );

        // Get customer stats (LTV, last transaction, subscription count).
        $customer_ids = array_column( $customers, 'square_customer_id' );
        $customer_stats = array();

        if ( ! empty( $customer_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%s' ) );
            
            // Get LTV per customer.
            $ltv_results = $wpdb->get_results( $wpdb->prepare(
                "SELECT square_customer_id, SUM(amount) as ltv, COUNT(*) as tx_count, MAX(created_at) as last_tx
                 FROM $transactions_table 
                 WHERE status = 'completed' AND square_customer_id IN ($placeholders)
                 GROUP BY square_customer_id",
                ...$customer_ids
            ), ARRAY_A );
            
            foreach ( $ltv_results as $row ) {
                $customer_stats[ $row['square_customer_id'] ] = array(
                    'ltv'     => (float) $row['ltv'],
                    'tx_count' => (int) $row['tx_count'],
                    'last_tx' => $row['last_tx'],
                );
            }
            
            // Get subscription count per customer.
            $sub_results = $wpdb->get_results( $wpdb->prepare(
                "SELECT square_customer_id, COUNT(*) as sub_count, 
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_subs
                 FROM $subscriptions_table 
                 WHERE square_customer_id IN ($placeholders)
                 GROUP BY square_customer_id",
                ...$customer_ids
            ), ARRAY_A );
            
            foreach ( $sub_results as $row ) {
                if ( ! isset( $customer_stats[ $row['square_customer_id'] ] ) ) {
                    $customer_stats[ $row['square_customer_id'] ] = array( 'ltv' => 0, 'tx_count' => 0, 'last_tx' => null );
                }
                $customer_stats[ $row['square_customer_id'] ]['sub_count'] = (int) $row['sub_count'];
                $customer_stats[ $row['square_customer_id'] ]['active_subs'] = (int) $row['active_subs'];
            }
        }

        // Format customers for response.
        $date_format = get_option( 'date_format' );
        $formatted = array();
        
        foreach ( $customers as $customer ) {
            $name = trim( $customer['given_name'] . ' ' . $customer['family_name'] );
            $display_name = $name ?: $customer['company_name'] ?: __( 'Unnamed', 'cube-payment-portal' );
            $stats = $customer_stats[ $customer['square_customer_id'] ] ?? array();
            $ltv = $stats['ltv'] ?? 0;
            $tx_count = $stats['tx_count'] ?? 0;
            $last_tx = $stats['last_tx'] ?? null;
            $active_subs = $stats['active_subs'] ?? 0;
            $sub_count = $stats['sub_count'] ?? 0;

            // Find matching WP users by email if not already linked.
            $matching_wp_users = array();
            if ( empty( $customer['wp_user_id'] ) && ! empty( $customer['email'] ) ) {
                $matching_user = get_user_by( 'email', $customer['email'] );
                if ( $matching_user ) {
                    $matching_wp_users[] = array(
                        'id'           => $matching_user->ID,
                        'display_name' => $matching_user->display_name,
                        'email'        => $matching_user->user_email,
                    );
                }
            }

            $formatted[] = array(
                'id'              => $customer['id'],
                'square_id'       => $customer['square_customer_id'],
                'display_name'    => $display_name,
                'company_name'    => $customer['company_name'] ?? '',
                'has_both_names'  => ! empty( $name ) && ! empty( $customer['company_name'] ),
                'email'           => $customer['email'] ?? '',
                'phone'           => $this->format_phone_number( $customer['phone'] ?? '' ),
                'ltv'             => number_format( $ltv, 2 ),
                'ltv_raw'         => $ltv,
                'tx_count'        => $tx_count,
                'active_subs'     => $active_subs,
                'sub_count'       => $sub_count,
                'last_activity'   => $last_tx,
                'last_activity_formatted' => $last_tx ? wp_date( 'M j, Y', strtotime( $last_tx ) ) : '',
                'last_activity_ago' => $last_tx ? human_time_diff( strtotime( $last_tx ), time() ) . ' ' . __( 'ago', 'cube-payment-portal' ) : '',
                'wp_user_id'      => $customer['wp_user_id'] ?? null,
                'wp_display_name' => $customer['wp_display_name'] ?? '',
                'matching_wp_users' => $matching_wp_users,
            );
        }

        // If sorting by last_activity, we need to sort in PHP.
        $sort_by = isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'given_name';
        $sort_order = isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'DESC' ? SORT_DESC : SORT_ASC;
        
        if ( $sort_by === 'last_activity' ) {
            usort( $formatted, function( $a, $b ) use ( $sort_order ) {
                $a_time = $a['last_activity'] ? strtotime( $a['last_activity'] ) : 0;
                $b_time = $b['last_activity'] ? strtotime( $b['last_activity'] ) : 0;
                return $sort_order === SORT_DESC ? $b_time - $a_time : $a_time - $b_time;
            });
        }

        // Calculate pagination.
        $per_page = $args['limit'];
        $current_page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $total_pages = ceil( $total_count / $per_page );

        wp_send_json_success( array(
            'customers'  => $formatted,
            'pagination' => array(
                'total'        => $total_count,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
            ),
        ) );
    }

    /**
     * AJAX handler for linking a customer to a WP user.
     */
    public function ajax_link_customer() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
        $wp_user_id  = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;

        if ( ! $customer_id || ! $wp_user_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
        }

        $sync = new SPP_Sync_Customers();
        $result = $sync->link_customer( $customer_id, $wp_user_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        } else {
            wp_send_json_success( array( 'message' => __( 'Customer linked successfully.', 'cube-payment-portal' ) ) );
        }
    }

    /**
     * AJAX handler for unlinking a customer from a WP user.
     */
    public function ajax_unlink_customer() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;

        if ( ! $customer_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid customer ID.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
        }

        $sync = new SPP_Sync_Customers();
        $result = $sync->unlink_customer( $customer_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        } else {
            wp_send_json_success( array( 'message' => __( 'Customer unlinked successfully.', 'cube-payment-portal' ) ) );
        }
    }

    /**
     * AJAX handler for syncing transactions from Square.
     */
    public function ajax_sync_transactions() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        try {
            // Load sync class if not already loaded.
            if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
            }

            $sync = new SPP_Sync_Transactions();
            $result = $sync->sync_from_square();

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %1$d: synced count, %2$d: error count */
                    __( 'Successfully synced %1$d transaction(s) with %2$d error(s).', 'cube-payment-portal' ),
                    $result['synced'],
                    $result['errors']
                ),
                'synced'  => $result['synced'],
                'errors'  => $result['errors'],
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => __( 'Sync error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        } catch ( Error $e ) {
            wp_send_json_error( array( 'message' => __( 'PHP error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for syncing all data from Square.
     */
    public function ajax_sync_all() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $results = array(
            'customers'     => null,
            'invoices'      => null,
            'subscriptions' => null,
            'transactions'  => null,
            'catalog'       => null,
            'orders'        => null,
            'bookings'      => null,
            'errors'        => array(),
        );

        try {
            // 1. Sync Customers.
            if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
            }
            $customer_sync = new SPP_Sync_Customers();
            $customer_result = $customer_sync->sync_all();
            if ( is_wp_error( $customer_result ) ) {
                $results['errors'][] = __( 'Customers: ', 'cube-payment-portal' ) . $customer_result->get_error_message();
            } else {
                $results['customers'] = $customer_result;
            }

            // 2. Sync Subscriptions.
            if ( get_option( 'spp_feature_subscriptions', '1' ) ) {
                if ( ! class_exists( 'SPP_Sync_Subscriptions' ) ) {
                    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
                }
                $subscription_sync = new SPP_Sync_Subscriptions();
                $subscription_result = $subscription_sync->sync_from_square();
                if ( is_wp_error( $subscription_result ) ) {
                    $results['errors'][] = __( 'Subscriptions: ', 'cube-payment-portal' ) . $subscription_result->get_error_message();
                } else {
                    $results['subscriptions'] = $subscription_result;
                }
            }

            // 3. Sync Invoices.
            if ( get_option( 'spp_feature_invoices', '1' ) ) {
                if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
                    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
                }
                $invoice_sync = new SPP_Sync_Invoices();
                $invoice_result = $invoice_sync->sync_from_square();
                if ( is_wp_error( $invoice_result ) ) {
                    $results['errors'][] = __( 'Invoices: ', 'cube-payment-portal' ) . $invoice_result->get_error_message();
                } else {
                    $results['invoices'] = $invoice_result;
                }
            }

            // 4. Sync Transactions.
            if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
            }
            $transaction_sync = new SPP_Sync_Transactions();
            $transaction_result = $transaction_sync->sync_from_square();
            if ( is_wp_error( $transaction_result ) ) {
                $results['errors'][] = __( 'Transactions: ', 'cube-payment-portal' ) . $transaction_result->get_error_message();
            } else {
                $results['transactions'] = $transaction_result;
            }

            // 5. Sync Catalog.
            if ( get_option( 'spp_feature_catalog', '1' ) ) {
                if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
                    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
                }
                $catalog_sync = new SPP_Sync_Catalog();
                $catalog_result = $catalog_sync->sync_from_square();
                if ( is_wp_error( $catalog_result ) ) {
                    $results['errors'][] = __( 'Catalog: ', 'cube-payment-portal' ) . $catalog_result->get_error_message();
                } else {
                    $results['catalog'] = $catalog_result;
                }
            }

            // 6. Sync Orders.
            if ( get_option( 'spp_feature_orders', '1' ) ) {
                if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
                    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
                }
                $orders_sync = new SPP_Sync_Orders();
                $orders_result = $orders_sync->sync_from_square();
                if ( is_wp_error( $orders_result ) ) {
                    $results['errors'][] = __( 'Orders: ', 'cube-payment-portal' ) . $orders_result->get_error_message();
                } else {
                    $results['orders'] = $orders_result;
                }
            }

            // 7. Sync Bookings.
            if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
            }
            $bookings_sync = new SPP_Sync_Bookings();
            $bookings_result = $bookings_sync->sync_from_square();
            if ( is_wp_error( $bookings_result ) ) {
                $results['errors'][] = __( 'Bookings: ', 'cube-payment-portal' ) . $bookings_result->get_error_message();
            } else {
                $results['bookings'] = $bookings_result;
            }

            // Update last full sync time.
            update_option( 'spp_last_full_sync', time() );

            // Build summary message.
            $summary_parts = array();

            if ( $results['customers'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of customers synced */
                    __( 'Customers: %d synced', 'cube-payment-portal' ),
                    $results['customers']['from_square'] ?? 0
                );
            }

            if ( $results['invoices'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of invoices synced */
                    __( 'Invoices: %d synced', 'cube-payment-portal' ),
                    $results['invoices']['synced'] ?? 0
                );
            }

            if ( $results['subscriptions'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of subscriptions synced */
                    __( 'Subscriptions: %d synced', 'cube-payment-portal' ),
                    $results['subscriptions']['synced'] ?? 0
                );
            }

            if ( $results['transactions'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of transactions synced */
                    __( 'Transactions: %d synced', 'cube-payment-portal' ),
                    $results['transactions']['synced'] ?? 0
                );
            }

            if ( $results['catalog'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of catalog items synced */
                    __( 'Catalog: %d synced', 'cube-payment-portal' ),
                    $results['catalog']['synced'] ?? 0
                );
            }

            if ( $results['orders'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of orders synced */
                    __( 'Orders: %d synced', 'cube-payment-portal' ),
                    $results['orders']['synced'] ?? 0
                );
            }

            if ( $results['bookings'] ) {
                $summary_parts[] = sprintf(
                    /* translators: %d: number of bookings synced */
                    __( 'Bookings: %d synced', 'cube-payment-portal' ),
                    $results['bookings']['synced'] ?? 0
                );
            }

            $message = implode( ' | ', $summary_parts );

            if ( ! empty( $results['errors'] ) ) {
                $message .= ' | ' . sprintf(
                    /* translators: %d: number of errors */
                    __( '%d error(s) occurred', 'cube-payment-portal' ),
                    count( $results['errors'] )
                );
            }

            wp_send_json_success( array(
                'message' => $message,
                'results' => $results,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => __( 'Sync error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        } catch ( Error $e ) {
            wp_send_json_error( array( 'message' => __( 'PHP error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for getting customer cards.
     */
    public function ajax_get_customer_cards() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_get_cards', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';

        if ( empty( $customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Customer ID is required.', 'cube-payment-portal' ) ) );
        }

        // Load Cards API.
        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }

        try {
            $cards_api = new SPP_Cards();
            $cards = $cards_api->list_cards( $customer_id );

            if ( is_wp_error( $cards ) ) {
                wp_send_json_error( array( 'message' => $cards->get_error_message() ) );
            }

            // Format cards for the dropdown.
            $formatted_cards = array();
            foreach ( $cards as $card ) {
                $formatted_cards[] = array(
                    'id'         => $card['id'] ?? '',
                    'card_brand' => $card['card_brand'] ?? 'UNKNOWN',
                    'last_4'     => $card['last_4'] ?? '****',
                    'exp_month'  => $card['exp_month'] ?? '',
                    'exp_year'   => $card['exp_year'] ?? '',
                );
            }

            wp_send_json_success( array( 'cards' => $formatted_cards ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for creating a new card on file.
     */
    public function ajax_create_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_create_card', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';
        $card_token  = isset( $_POST['card_token'] ) ? sanitize_text_field( wp_unslash( $_POST['card_token'] ) ) : '';

        if ( empty( $customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Customer ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $card_token ) ) {
            wp_send_json_error( array( 'message' => __( 'Card token is required.', 'cube-payment-portal' ) ) );
        }

        // Load Cards API.
        if ( ! class_exists( 'SPP_Cards' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
        }

        try {
            $cards_api = new SPP_Cards();
            $card = $cards_api->create_card( $customer_id, $card_token );

            if ( is_wp_error( $card ) ) {
                wp_send_json_error( array( 'message' => $card->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => __( 'Card saved successfully.', 'cube-payment-portal' ),
                'card'    => array(
                    'id'         => $card['id'] ?? '',
                    'card_brand' => $card['card_brand'] ?? 'UNKNOWN',
                    'last_4'     => $card['last_4'] ?? '****',
                ),
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching orders.
     */
    public function ajax_get_orders() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Sanitize and gather filter parameters.
        $args = array(
            'search'    => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'status'    => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
            'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
            'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
            'limit'     => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20,
            'offset'    => isset( $_POST['page'] ) ? ( absint( $_POST['page'] ) - 1 ) * ( isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20 ) : 0,
            'order_by'  => isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'id',
            'order'     => isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'ASC' ? 'ASC' : 'DESC',
        );

        // Format date range if provided.
        if ( ! empty( $args['date_from'] ) ) {
            $args['date_from'] = gmdate( 'Y-m-d 00:00:00', strtotime( $args['date_from'] ) );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $args['date_to'] = gmdate( 'Y-m-d 23:59:59', strtotime( $args['date_to'] ) );
        }

        // Fetch orders.
        $orders = SPP_Database::get_orders( $args );
        $total_count = SPP_Database::get_orders_count( $args );

        // Get default currency for formatting.
        $default_currency = get_option( 'spp_default_currency', 'USD' );
        $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        // Format orders for response.
        $formatted = array();
        foreach ( $orders as $order ) {
            $line_items = ! empty( $order['line_items'] ) ? json_decode( $order['line_items'], true ) : array();

            $formatted[] = array(
                'id'                   => $order['id'],
                'square_order_id'      => $order['square_order_id'],
                'square_customer_id'   => $order['square_customer_id'],
                'customer_name'        => $order['customer_name'] ?? '',
                'customer_email'       => $order['customer_email'] ?? '',
                'customer_phone'       => $this->format_phone_number( $order['customer_phone'] ?? '' ),
                'total_amount'         => number_format( (float) $order['total_amount'], 2 ),
                'total_tax'            => number_format( (float) ( $order['total_tax'] ?? 0 ), 2 ),
                'total_discount'       => number_format( (float) ( $order['total_discount'] ?? 0 ), 2 ),
                'currency'             => $order['currency'] ?? $default_currency,
                'status'               => $order['status'],
                'line_item_count'      => count( $line_items ),
                'created_at'           => $order['created_at'] ?? '',
                'created_at_formatted' => ! empty( $order['created_at'] ) ? wp_date( $date_format, strtotime( $order['created_at'] ) ) : '',
            );
        }

        // Calculate pagination.
        $per_page = $args['limit'];
        $current_page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $total_pages = ceil( $total_count / $per_page );

        wp_send_json_success( array(
            'orders'     => $formatted,
            'pagination' => array(
                'total'        => $total_count,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
            ),
        ) );
    }

    /**
     * AJAX handler for fetching order detail.
     */
    public function ajax_get_order_detail() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'cube-payment-portal' ) ) );
        }

        $order = SPP_Database::get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'cube-payment-portal' ) ) );
        }

        $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $line_items = ! empty( $order['line_items'] ) ? json_decode( $order['line_items'], true ) : array();

        $formatted = array(
            'id'                   => $order['id'],
            'square_order_id'      => $order['square_order_id'],
            'customer_name'        => $order['customer_name'] ?? '',
            'customer_email'       => $order['customer_email'] ?? '',
            'total_amount'         => number_format( (float) $order['total_amount'], 2 ),
            'total_tax'            => number_format( (float) ( $order['total_tax'] ?? 0 ), 2 ),
            'total_discount'       => number_format( (float) ( $order['total_discount'] ?? 0 ), 2 ),
            'currency'             => $order['currency'] ?? 'USD',
            'status'               => $order['status'],
            'line_items'           => $line_items,
            'created_at'           => $order['created_at'] ?? '',
            'created_at_formatted' => ! empty( $order['created_at'] ) ? wp_date( $date_format, strtotime( $order['created_at'] ) ) : '',
        );

        wp_send_json_success( $formatted );
    }

    /**
     * AJAX handler for syncing orders from Square.
     */
    public function ajax_sync_orders() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        try {
            // Load sync class if not already loaded.
            if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
            }

            $sync = new SPP_Sync_Orders();
            $result = $sync->sync_from_square();

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %1$d: synced count, %2$d: error count */
                    __( 'Successfully synced %1$d order(s) with %2$d error(s).', 'cube-payment-portal' ),
                    $result['synced'],
                    $result['errors']
                ),
                'synced'  => $result['synced'],
                'errors'  => $result['errors'],
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => __( 'Sync error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for bulk deleting orders.
     */
    public function ajax_bulk_delete_orders() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $order_ids = isset( $_POST['order_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['order_ids'] ) : array();

        if ( empty( $order_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No orders selected.', 'cube-payment-portal' ) ) );
        }

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
        }

        $sync = new SPP_Sync_Orders();
        $result = $sync->bulk_delete( $order_ids );

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: deleted count */
                __( 'Deleted %d order(s) from local database.', 'cube-payment-portal' ),
                $result['deleted']
            ),
        ) );
    }

    /**
     * AJAX handler for fetching catalog items.
     */
    public function ajax_get_catalog() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Sanitize and gather filter parameters.
        $args = array(
            'search'      => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'category_id' => isset( $_POST['category_id'] ) ? sanitize_text_field( wp_unslash( $_POST['category_id'] ) ) : '',
            'limit'       => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20,
            'offset'      => isset( $_POST['page'] ) ? ( absint( $_POST['page'] ) - 1 ) * ( isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20 ) : 0,
            'order_by'    => isset( $_POST['sort_by'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_by'] ) ) : 'name',
            'order'       => isset( $_POST['sort_order'] ) && strtoupper( $_POST['sort_order'] ) === 'DESC' ? 'DESC' : 'ASC',
        );

        // Fetch catalog items.
        $items = SPP_Database::get_catalog_items( $args );
        $total_count = SPP_Database::get_catalog_items_count( $args );

        // Format items for response and collect variation IDs for inventory lookup.
        $formatted = array();
        $all_variation_ids = array();
        foreach ( $items as $item ) {
            $variations = ! empty( $item['variations'] ) ? json_decode( $item['variations'], true ) : array();
            foreach ( $variations as $v ) {
                if ( ! empty( $v['id'] ) ) {
                    $all_variation_ids[] = $v['id'];
                }
            }

            $formatted[] = array(
                'id'             => $item['id'],
                'square_item_id' => $item['square_item_id'],
                'name'           => $item['name'] ?? '',
                'description'    => $item['description'] ?? '',
                'category_id'    => $item['category_id'] ?? '',
                'category_name'  => $item['category_name'] ?? '',
                'price'          => (float) ( $item['price'] ?? 0 ),
                'currency'       => $item['currency'] ?? 'USD',
                'variations'     => $variations,
                'product_type'   => $item['product_type'] ?? 'REGULAR',
                'image_url'      => $item['image_url'] ?? '',
                'is_archived'    => ! empty( $item['is_archived'] ),
                'synced_at'      => $item['synced_at'] ?? null,
            );
        }

        // Fetch inventory counts if variations exist.
        if ( ! empty( $all_variation_ids ) ) {
            if ( ! class_exists( 'SPP_Inventory' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
            }
            $inventory_api = new SPP_Inventory();
            $counts_result = $inventory_api->batch_retrieve_counts( $all_variation_ids );

            if ( ! is_wp_error( $counts_result ) && ! empty( $counts_result['counts'] ) ) {
                $count_map = array();
                foreach ( $counts_result['counts'] as $count ) {
                    if ( 'IN_STOCK' === ( $count['state'] ?? '' ) ) {
                        $var_id = $count['catalog_object_id'];
                        $count_map[ $var_id ] = ( $count_map[ $var_id ] ?? 0 ) + (int) $count['quantity'];
                    }
                }

                // Inject counts back into variations.
                foreach ( $formatted as &$f_item ) {
                    if ( ! empty( $f_item['variations'] ) ) {
                        foreach ( $f_item['variations'] as &$f_var ) {
                            $is_tracked = ! empty( $f_var['track_inventory'] );
                            
                            if ( $is_tracked ) {
                                $f_var['inventory_count'] = $count_map[ $f_var['id'] ] ?? 0;
                            } else {
                                // Explicitly unset or leave as null for untracked
                                unset( $f_var['inventory_count'] );
                            }
                        }
                    }
                }
            }
        }

        // Calculate pagination.
        $per_page = $args['limit'];
        $current_page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $total_pages = ceil( $total_count / $per_page );

        wp_send_json_success( array(
            'items'      => $formatted,
            'pagination' => array(
                'total'        => $total_count,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
            ),
        ) );
    }

    /**
     * AJAX handler for syncing catalog from Square.
     */
    public function ajax_sync_catalog() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        try {
            // Load sync class if not already loaded.
            if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
            }

            $sync = new SPP_Sync_Catalog();
            $result = $sync->sync_from_square();

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %1$d: synced count, %2$d: error count */
                    __( 'Successfully synced %1$d item(s) with %2$d error(s).', 'cube-payment-portal' ),
                    $result['synced'],
                    $result['errors']
                ),
                'synced'  => $result['synced'],
                'errors'  => $result['errors'],
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => __( 'Sync error: ', 'cube-payment-portal' ) . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for creating a customer.
     */
    public function ajax_create_customer() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Gather and sanitize input.
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
        $address_line_1 = isset( $_POST['address_line_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_line_1'] ) ) : '';
        $city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $postal_code = isset( $_POST['postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['postal_code'] ) ) : '';
        $country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : 'US';
        $reference_id = isset( $_POST['reference_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_id'] ) ) : '';
        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
        $create_wp_user = isset( $_POST['create_wp_user'] ) && $_POST['create_wp_user'] === '1';

        // Validate required fields.
        if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'First name, last name, and email are required.', 'cube-payment-portal' ) ) );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Customers API.
            if ( ! class_exists( 'SPP_Customers' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
            }

            $customers_api = new SPP_Customers();

            // Create customer in Square.
            $customer_data = array(
                'first_name'     => $first_name,
                'last_name'      => $last_name,
                'email'          => $email,
                'phone'          => $phone,
                'company_name'   => $company_name,
                'address_line_1' => $address_line_1,
                'city'           => $city,
                'state'          => $state,
                'postal_code'    => $postal_code,
                'country'        => $country,
                'reference_id'   => $reference_id,
                'note'           => $note,
            );

            $square_customer = $customers_api->create_customer( $customer_data );

            if ( is_wp_error( $square_customer ) ) {
                wp_send_json_error( array( 'message' => $square_customer->get_error_message() ) );
            }

            $square_customer_id = $square_customer['id'];
            $wp_user_id = null;

            // Create WordPress placeholder user if requested.
            if ( $create_wp_user ) {
                // Check if email already exists.
                $existing_user = get_user_by( 'email', $email );

                if ( $existing_user ) {
                    // Link to existing user.
                    $wp_user_id = $existing_user->ID;
                    update_user_meta( $wp_user_id, 'spp_square_customer_id', $square_customer_id );
                } else {
                    // Create placeholder user.
                    $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
                    
                    // Ensure unique username.
                    $base_username = $username;
                    $counter = 1;
                    while ( username_exists( $username ) ) {
                        $username = $base_username . $counter;
                        $counter++;
                    }

                    // Generate secure random password.
                    $password = wp_generate_password( 24, true, true );

                    $wp_user_id = wp_insert_user( array(
                        'user_login'    => $username,
                        'user_pass'     => $password,
                        'user_email'    => $email,
                        'first_name'    => $first_name,
                        'last_name'     => $last_name,
                        'display_name'  => $first_name . ' ' . $last_name,
                        'role'          => 'spp_client_placeholder',
                    ) );

                    if ( is_wp_error( $wp_user_id ) ) {
                        // Customer created in Square but WP user failed - still success.
                        $wp_user_id = null;
                    } else {
                        // Link to Square customer.
                        update_user_meta( $wp_user_id, 'spp_square_customer_id', $square_customer_id );
                        update_user_meta( $wp_user_id, 'spp_placeholder_account', true );
                    }
                }
            }

            // Sync customer to local database.
            if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
            }

            $sync = new SPP_Sync_Customers();
            $sync->sync_single_customer( $square_customer );

            // If we have a WP user, update the link in the database.
            if ( $wp_user_id ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'spp_customers',
                    array( 'wp_user_id' => $wp_user_id ),
                    array( 'square_customer_id' => $square_customer_id ),
                    array( '%d' ),
                    array( '%s' )
                );
            }

            wp_send_json_success( array(
                'message'     => __( 'Customer created successfully!', 'cube-payment-portal' ),
                'customer_id' => $square_customer_id,
                'wp_user_id'  => $wp_user_id,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for bulk deleting catalog items.
     */
    public function ajax_bulk_delete_catalog() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $item_ids = isset( $_POST['item_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['item_ids'] ) : array();

        if ( empty( $item_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No items selected.', 'cube-payment-portal' ) ) );
        }

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
        }

        $sync = new SPP_Sync_Catalog();
        $result = $sync->bulk_delete( $item_ids );

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: deleted count */
                __( 'Deleted %d item(s) from local database.', 'cube-payment-portal' ),
                $result['deleted']
            ),
        ) );
    }

    /**
     * AJAX handler for creating a catalog item.
     */
    public function ajax_create_catalog_item() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Gather and sanitize input.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $price = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0;
        $currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
        $category_id = isset( $_POST['category_id'] ) ? sanitize_text_field( wp_unslash( $_POST['category_id'] ) ) : '';
        $image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
        $product_type = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : 'REGULAR';
        if ( 'DIGITAL' === $product_type ) {
            $product_type = '';
        }
        $service_duration = isset( $_POST['service_duration'] ) ? absint( $_POST['service_duration'] ) : 0;
        $track_inventory = isset( $_POST['track_inventory'] ) && ( $_POST['track_inventory'] === '1' || $_POST['track_inventory'] === 'true' );

        // Validate required fields.
        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => __( 'Item name is required.', 'cube-payment-portal' ) ) );
        }

        if ( $price < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Price cannot be negative.', 'cube-payment-portal' ) ) );
        }

        try {
            $catalog = new SPP_Catalog();
            
            // 1. Create the item first.
            $item = $catalog->create_item( array(
                'name'             => $name,
                'description'      => $description,
                'price'            => $price,
                'currency'         => $currency,
                'category_id'      => $category_id,
                'product_type'     => $product_type,
                'track_inventory'  => $track_inventory,
                'service_duration' => $service_duration,
            ) );

            if ( is_wp_error( $item ) ) {
                wp_send_json_error( array( 'message' => $item->get_error_message() ) );
            }

            // 2. Upload image if provided.
            if ( $image_id ) {
                $file_path = get_attached_file( $image_id );
                if ( $file_path ) {
                    $image_result = $catalog->upload_item_image( $item['id'], $file_path );
                    
                    if ( is_wp_error( $image_result ) ) {
                        // Log error but don't fail the whole request since item is created.
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'SPP Image Upload Error: ' . $image_result->get_error_message() );
                        }
                    } else {
                        // Update item object with image data for local sync.
                        $item['image_url'] = $image_result['image_data']['url'] ?? '';
                    }
                }
            }

            // Sync the new item to local database.
            if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
            }
            $sync = new SPP_Sync_Catalog();
            $sync->sync_single_item( $item );

            wp_send_json_success( array(
                'message' => __( 'Item created successfully!', 'cube-payment-portal' ),
                'item'    => $item,
            ) );

        } catch ( Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Fatal Error in create_catalog_item: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
            }
            wp_send_json_error( array( 'message' => 'Fatal Error: ' . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for updating a catalog item.
     */
    public function ajax_update_catalog_item() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Gather and sanitize input.
        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $price = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : null;
        $currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
        $category_id = isset( $_POST['category_id'] ) ? sanitize_text_field( wp_unslash( $_POST['category_id'] ) ) : null;
        $image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
        $product_type = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : null;
        $track_inventory = isset( $_POST['track_inventory'] ) && '1' === $_POST['track_inventory']; // boolean

        // Validate required fields.
        if ( empty( $item_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Item ID is required.', 'cube-payment-portal' ) ) );
        }

        $data = array();
        if ( ! empty( $name ) ) {
            $data['name'] = $name;
        }
        if ( ! empty( $description ) || isset( $_POST['description'] ) ) {
            $data['description'] = $description;
        }
        if ( null !== $price ) {
            $data['price'] = $price;
            $data['currency'] = $currency;
        }
        if ( null !== $category_id ) {
            $data['category_id'] = $category_id;
        }
        if ( null !== $product_type ) {
            $data['product_type'] = $product_type;
        }
        if ( null !== $track_inventory ) {
            $data['track_inventory'] = $track_inventory;
        }

        // Variation specific prices and names.
        if ( isset( $_POST['variation_prices'] ) && is_array( $_POST['variation_prices'] ) ) {
            $data['variation_prices'] = array_map( 'floatval', $_POST['variation_prices'] );
        }
        if ( isset( $_POST['variation_names'] ) && is_array( $_POST['variation_names'] ) ) {
            $data['variation_names'] = array_map( 'sanitize_text_field', $_POST['variation_names'] );
        }

        try {
            $catalog = new SPP_Catalog();
            
            // 1. Update item details.
            $result = $catalog->update_item( $item_id, $data );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // 2. Upload image if provided.
            if ( $image_id ) {
                $file_path = get_attached_file( $image_id );
                if ( $file_path ) {
                    $image_result = $catalog->upload_item_image( $item_id, $file_path );
                    
                    if ( is_wp_error( $image_result ) ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'SPP Image Upload Error: ' . $image_result->get_error_message() );
                        }
                    } else {
                        // Update result object with new image URL.
                        $result['image_url'] = $image_result['image_data']['url'] ?? '';
                    }
                }
            }

            // Sync the updated item to local database.
            if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
            }
            $sync = new SPP_Sync_Catalog();
            $sync->sync_single_item( $result );

            wp_send_json_success( array(
                'message' => __( 'Item updated successfully!', 'cube-payment-portal' ),
                'item'    => $result,
            ) );

        } catch ( Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Fatal Error in update_catalog_item: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
            }
            wp_send_json_error( array( 'message' => 'Fatal Error: ' . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for deleting a catalog item.
     */
    public function ajax_delete_catalog_item() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';

        if ( empty( $item_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Item ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            $catalog = new SPP_Catalog();
            $result = $catalog->delete_item( $item_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => __( 'Item deleted successfully!', 'cube-payment-portal' ),
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for archiving a catalog item.
     */
    public function ajax_archive_catalog_item() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
        $archived = ! empty( $_POST['archived'] );

        if ( empty( $item_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Item ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            $catalog = new SPP_Catalog();
            $result = $catalog->archive_item( $item_id, $archived );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => $archived ? __( 'Item archived successfully!', 'cube-payment-portal' ) : __( 'Item unarchived successfully!', 'cube-payment-portal' ),
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for duplicating a catalog item.
     */
    public function ajax_duplicate_catalog_item() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';

        if ( empty( $item_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Item ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            $catalog = new SPP_Catalog();
            $result = $catalog->duplicate_item( $item_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => __( 'Item duplicated successfully!', 'cube-payment-portal' ),
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }



    /**
     * AJAX handler for getting Square categories.
     */
    public function ajax_get_square_categories() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        try {
            $catalog = new SPP_Catalog();
            $categories = $catalog->get_categories();

            if ( is_wp_error( $categories ) ) {
                wp_send_json_error( array( 'message' => $categories->get_error_message() ) );
            }

            wp_send_json_success( array( 'categories' => $categories ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching inventory.
     */
    public function ajax_get_inventory() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $stock_filter = isset( $_POST['stock_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_filter'] ) ) : '';
        $location_id = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '';
        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;

        $low_stock_threshold = get_option( 'spp_low_stock_threshold', 10 );

        try {
            // Get catalog items from local database.
            $catalog_items = SPP_Database::get_catalog_items( array(
                'search' => $search,
                'limit'  => 1000, // Get all for filtering.
            ) );

            if ( empty( $catalog_items ) ) {
                wp_send_json_success( array( 'items' => array(), 'pagination' => array( 'total' => 0, 'total_pages' => 0, 'current_page' => 1 ) ) );
            }

            // Load Inventory API.
            if ( ! class_exists( 'SPP_Inventory' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
            }

            $inventory_api = new SPP_Inventory();

            // Get variation IDs from catalog items.
            $variation_ids = array();
            $item_map = array();

            foreach ( $catalog_items as $item ) {
                $variations = ! empty( $item['variations'] ) ? json_decode( $item['variations'], true ) : array();
                
                foreach ( $variations as $variation ) {
                    $var_id = $variation['id'] ?? '';
                    if ( $var_id ) {
                        $variation_ids[] = $var_id;
                        $item_map[ $var_id ] = array(
                            'item_name'       => $item['name'],
                            'variation_name'  => $variation['name'] ?? 'Default',
                            'product_type'    => $item['product_type'] ?? 'REGULAR',
                            'track_inventory' => (bool) ( $variation['track_inventory'] ?? false ),
                            'is_archived'     => ! empty( $item['is_archived'] ) || ! empty( $variation['is_archived'] ),
                        );
                    }
                }
            }

            if ( empty( $variation_ids ) ) {
                wp_send_json_success( array( 'items' => array(), 'pagination' => array( 'total' => 0, 'total_pages' => 0, 'current_page' => 1 ) ) );
            }

            // Batch retrieve inventory counts.
            $location_ids = ! empty( $location_id ) ? array( $location_id ) : array();
            $counts_result = $inventory_api->batch_retrieve_counts( $variation_ids, $location_ids );

            if ( is_wp_error( $counts_result ) ) {
                wp_send_json_error( array( 'message' => $counts_result->get_error_message() ) );
            }

            // Build items array.
            $items = array();
            $counts = $counts_result['counts'] ?? array();

            // Create a map of counts by catalog object ID.
            $count_map = array();
            foreach ( $counts as $count ) {
                if ( 'IN_STOCK' === ( $count['state'] ?? '' ) ) {
                    $catalog_object_id = $count['catalog_object_id'] ?? '';
                    $count_map[ $catalog_object_id ] = array(
                        'quantity'   => (int) ( $count['quantity'] ?? 0 ),
                        'updated_at' => $count['calculated_at'] ?? '',
                    );
                }
            }

            // Build final hierarchical structure.
            $grouped_items = array();
            $variation_list = array();

            // First pass: organize counts by variation ID for easy lookup
            $count_by_var = array();
            foreach ( $count_map as $var_id => $info ) {
                $count_by_var[ $var_id ] = $info;
            }

            // Second pass: group variations by parent item
            foreach ( $catalog_items as $item_raw ) {
                $item_id = $item_raw['square_item_id'];
                $variations = ! empty( $item_raw['variations'] ) ? json_decode( $item_raw['variations'], true ) : array();
                
                $item_vars = array();
                $item_total_qty = 0;
                $item_has_tracked = false;
                $item_is_archived = ! empty( $item_raw['is_archived'] );
                $last_updated = '';

                foreach ( $variations as $v ) {
                    $v_id = $v['id'] ?? '';
                    if ( ! $v_id ) continue;

                    $c_info = $count_by_var[ $v_id ] ?? array( 'quantity' => 0, 'updated_at' => '' );
                    $qty = $c_info['quantity'];
                    $v_tracked = ! empty( $v['track_inventory'] );

                    // Apply filters at variation level
                    $matches_filter = true;
                    if ( 'low' === $stock_filter && ( ! $v_tracked || $qty > $low_stock_threshold || $qty === 0 ) ) {
                        $matches_filter = false;
                    }
                    if ( 'out' === $stock_filter && ( ! $v_tracked || $qty > 0 ) ) {
                        $matches_filter = false;
                    }
                    if ( 'in' === $stock_filter && ( ! $v_tracked || $qty <= 0 ) ) {
                        $matches_filter = false;
                    }

                    if ( ! $matches_filter ) continue;

                    $item_vars[] = array(
                        'catalog_object_id' => $v_id,
                        'variation_name'    => $v['name'] ?? 'Default',
                        'quantity'          => $qty,
                        'track_inventory'   => $v_tracked,
                        'is_archived'       => $item_is_archived || ! empty( $v['is_archived'] ),
                        'updated_at'        => ! empty( $c_info['updated_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $c_info['updated_at'] ) ) : '',
                    );

                    if ( $v_tracked ) {
                        $item_total_qty += $qty;
                        $item_has_tracked = true;
                        
                        // Keep the latest update date
                        if ( ! empty( $c_info['updated_at'] ) && ( ! $last_updated || strtotime( $c_info['updated_at'] ) > strtotime( $last_updated ) ) ) {
                            $last_updated = $c_info['updated_at'];
                        }
                    }
                }

                if ( ! empty( $item_vars ) ) {
                    $grouped_items[] = array(
                        'id'              => $item_id,
                        'item_name'       => $item_raw['name'],
                        'product_type'    => $item_raw['product_type'] ?? 'REGULAR',
                        'is_archived'     => $item_is_archived,
                        'track_inventory' => $item_has_tracked,
                        'quantity'        => $item_total_qty,
                        'updated_at'      => $last_updated ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_updated ) ) : '',
                        'variations'      => $item_vars,
                    );
                }
            }

            // Pagination.
            $total = count( $grouped_items );
            $offset = ( $page - 1 ) * $per_page;
            $items = array_slice( $grouped_items, $offset, $per_page );

            wp_send_json_success( array(
                'items'      => $items,
                'pagination' => array(
                    'total'        => $total,
                    'per_page'     => $per_page,
                    'current_page' => $page,
                    'total_pages'  => ceil( $total / $per_page ),
                ),
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for getting low stock items.
     */
    public function ajax_get_low_stock() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $location_id = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '';
        $threshold = isset( $_POST['threshold'] ) ? absint( $_POST['threshold'] ) : 10;

        try {
            // Load Inventory API.
            if ( ! class_exists( 'SPP_Inventory' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
            }

            $inventory_api = new SPP_Inventory();
            $location_ids = ! empty( $location_id ) ? array( $location_id ) : array();

            $low_stock = $inventory_api->get_low_stock_items( $threshold, $location_ids );

            if ( is_wp_error( $low_stock ) ) {
                wp_send_json_error( array( 'message' => $low_stock->get_error_message() ) );
            }

            wp_send_json_success( array( 'items' => $low_stock ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for adjusting inventory.
     */
    public function ajax_adjust_inventory() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $catalog_object_id = isset( $_POST['catalog_object_id'] ) ? sanitize_text_field( wp_unslash( $_POST['catalog_object_id'] ) ) : '';
        $adjust_type = isset( $_POST['adjust_type'] ) ? sanitize_text_field( wp_unslash( $_POST['adjust_type'] ) ) : 'add';
        $quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'Manual adjustment';
        $location_id = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '';

        if ( empty( $catalog_object_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid item ID.', 'cube-payment-portal' ) ) );
        }

        if ( $quantity <= 0 && 'set' !== $adjust_type ) {
            wp_send_json_error( array( 'message' => __( 'Quantity must be greater than 0.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Inventory API.
            if ( ! class_exists( 'SPP_Inventory' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
            }

            $inventory_api = new SPP_Inventory();

            // Calculate the actual adjustment.
            $adjustment = $quantity;
            if ( 'remove' === $adjust_type ) {
                $adjustment = -$quantity;
            } elseif ( 'set' === $adjust_type ) {
                // For "set to", we need to get current quantity first.
                $current = $inventory_api->retrieve_count( $catalog_object_id, $location_id );
                $current_qty = 0;
                if ( ! is_wp_error( $current ) && ! empty( $current ) ) {
                    foreach ( $current as $count ) {
                        if ( 'IN_STOCK' === ( $count['state'] ?? '' ) ) {
                            $current_qty = (int) ( $count['quantity'] ?? 0 );
                            break;
                        }
                    }
                }
                $adjustment = $quantity - $current_qty;
            }

            if ( $adjustment === 0 ) {
                wp_send_json_success( array( 'message' => __( 'No change needed.', 'cube-payment-portal' ) ) );
            }

            $result = $inventory_api->adjust_quantity( $catalog_object_id, $adjustment, $reason, $location_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array( 'message' => __( 'Inventory adjusted successfully.', 'cube-payment-portal' ) ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for searching loyalty customer accounts.
     */
    public function ajax_search_loyalty_customer() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        // Accept either customer_id directly or query (email/phone).
        $customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';
        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( empty( $customer_id ) && empty( $query ) ) {
            wp_send_json_error( array( 'message' => __( 'Search query is required.', 'cube-payment-portal' ) ) );
        }

        try {
            $customer_name = '';

            // If we have a query (email/phone), search for the customer first.
            if ( empty( $customer_id ) && ! empty( $query ) ) {
                // Search local customers table.
                global $wpdb;
                $table = $wpdb->prefix . 'spp_customers';
                
                $customer = $wpdb->get_row( $wpdb->prepare(
                    "SELECT square_customer_id, given_name, family_name, email FROM $table 
                     WHERE email LIKE %s OR phone LIKE %s 
                     LIMIT 1",
                    '%' . $wpdb->esc_like( $query ) . '%',
                    '%' . $wpdb->esc_like( $query ) . '%'
                ), ARRAY_A );

                if ( $customer ) {
                    $customer_id = $customer['square_customer_id'];
                    $customer_name = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
                    if ( empty( $customer_name ) ) {
                        $customer_name = $customer['email'] ?? '';
                    }
                } else {
                    wp_send_json_success( array( 'account' => null, 'customer_name' => '' ) );
                }
            }

            // Load Loyalty API.
            if ( ! class_exists( 'SPP_Loyalty' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
            }

            $loyalty_api = new SPP_Loyalty();
            $account = $loyalty_api->search_loyalty_account( $customer_id );

            if ( is_wp_error( $account ) ) {
                wp_send_json_error( array( 'message' => $account->get_error_message() ) );
            }

            wp_send_json_success( array( 
                'account'       => $account,
                'customer_name' => $customer_name,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching gift cards.
     */
    public function ajax_get_gift_cards() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $limit = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
        $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';

        try {
            // Load Gift Cards API.
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();

            // If searching by GAN, use get_gift_card_by_gan.
            if ( ! empty( $search ) ) {
                $card = $gift_cards_api->get_gift_card_by_gan( $search );
                if ( is_wp_error( $card ) ) {
                    wp_send_json_success( array( 'gift_cards' => array(), 'cursor' => '' ) );
                }
                $gift_cards = array( $card );
            } else {
                $args = array(
                    'limit' => $limit,
                );

                if ( ! empty( $status ) ) {
                    $args['state'] = $status;
                }
                if ( ! empty( $cursor ) ) {
                    $args['cursor'] = $cursor;
                }

                $result = $gift_cards_api->list_gift_cards( $args );

                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                }

                $gift_cards = $result['gift_cards'] ?? array();
                $cursor = $result['cursor'] ?? '';
            }

            // Collect unique customer IDs and fetch their names.
            $customer_ids = array();
            foreach ( $gift_cards as $card ) {
                if ( ! empty( $card['customer_ids'] ) ) {
                    foreach ( $card['customer_ids'] as $cid ) {
                        $customer_ids[ $cid ] = true;
                    }
                }
            }

            // Fetch customer names from Square.
            $customer_names = array();
            $customer_details = array(); // Full customer data for tooltips.
            if ( ! empty( $customer_ids ) ) {
                if ( ! class_exists( 'SPP_Customers' ) ) {
                    require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
                }
                $customers_api = new SPP_Customers();
                
                foreach ( array_keys( $customer_ids ) as $customer_id ) {
                    $customer = $customers_api->get_customer( $customer_id );
                    if ( ! is_wp_error( $customer ) && ! empty( $customer ) ) {
                        $given = $customer['given_name'] ?? '';
                        $family = $customer['family_name'] ?? '';
                        $name = trim( $given . ' ' . $family );
                        if ( empty( $name ) ) {
                            $name = $customer['email_address'] ?? $customer_id;
                        }
                        $customer_names[ $customer_id ] = $name;
                        
                        // Store full customer details for tooltip.
                        $customer_details[ $customer_id ] = array(
                            'id'    => $customer_id,
                            'name'  => $name,
                            'email' => $customer['email_address'] ?? '',
                            'phone' => $this->format_phone_number( $customer['phone_number'] ?? '' ),
                        );
                    }
                }
            }

            // Add customer names and details to gift cards.
            foreach ( $gift_cards as &$card ) {
                $card['customer_names'] = array();
                $card['customer_details'] = array(); // Full customer data.
                if ( ! empty( $card['customer_ids'] ) ) {
                    foreach ( $card['customer_ids'] as $cid ) {
                        $card['customer_names'][] = $customer_names[ $cid ] ?? $cid;
                        if ( isset( $customer_details[ $cid ] ) ) {
                            $card['customer_details'][] = $customer_details[ $cid ];
                        }
                    }
                }
            }
            unset( $card );


            wp_send_json_success( array(
                'gift_cards' => $gift_cards,
                'cursor'     => $cursor ?? '',
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for creating a gift card.
     */
    public function ajax_create_gift_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'DIGITAL';
        $location_id = get_option( 'spp_default_location_id', '' );

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Default location ID is not set. Please configure it in settings.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Gift Cards API.
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $result = $gift_cards_api->create_gift_card( $location_id, $type );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Gift card created successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for activating a gift card.
     */
    public function ajax_activate_gift_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
        $location_id = get_option( 'spp_default_location_id', '' );

        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Amount must be greater than 0.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Default location ID is not set. Please configure it in settings.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Gift Cards API.
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $amount_cents = SPP_Currency::dollars_to_cents( $amount );
            $result = $gift_cards_api->activate_gift_card( $gift_card_id, $amount_cents, $location_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Gift card activated successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for loading a gift card balance.
     */
    public function ajax_load_gift_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
        $location_id = get_option( 'spp_default_location_id', '' );

        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Amount must be greater than 0.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Default location ID is not set. Please configure it in settings.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Gift Cards API.
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $amount_cents = SPP_Currency::dollars_to_cents( $amount );
            $result = $gift_cards_api->load_gift_card( $gift_card_id, $amount_cents, $location_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Gift card loaded successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for linking a customer to a gift card.
     */
    public function ajax_link_gift_card_customer() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';

        if ( empty( $gift_card_id ) || empty( $customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID and Customer ID are required.', 'cube-payment-portal' ) ) );
        }

        try {
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $result = $gift_cards_api->link_customer( $gift_card_id, $customer_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Customer linked successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for unlinking a customer from a gift card.
     */
    public function ajax_unlink_gift_card_customer() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';

        if ( empty( $gift_card_id ) || empty( $customer_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID and Customer ID are required.', 'cube-payment-portal' ) ) );
        }

        try {
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $result = $gift_cards_api->unlink_customer( $gift_card_id, $customer_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Customer unlinked successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for redeeming (deducting) from a gift card.
     */
    public function ajax_redeem_gift_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
        $location_id = get_option( 'spp_default_location_id', '' );

        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Amount must be greater than 0.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Default location ID is not set. Please configure it in settings.', 'cube-payment-portal' ) ) );
        }

        try {
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $amount_cents = SPP_Currency::dollars_to_cents( $amount );
            $result = $gift_cards_api->redeem_gift_card( $gift_card_id, $amount_cents, $location_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'   => __( 'Gift card redeemed successfully.', 'cube-payment-portal' ),
                'gift_card' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching gift card activities.
     */
    public function ajax_get_gift_card_activities() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
        $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';

        try {
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $result = $gift_cards_api->list_activities( $gift_card_id, $limit, $cursor );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Format activities for display.
            $formatted = array();
            foreach ( $result['gift_card_activities'] ?? array() as $activity ) {
                $amount = 0;
                $currency = 'USD';
                $type_label = $activity['type'] ?? 'UNKNOWN';
                
                // Determine amount and currency based on activity type details.
                if ( isset( $activity['load_activity_details']['amount_money']['amount'] ) ) {
                    $amount = $activity['load_activity_details']['amount_money']['amount'] / 100;
                    $currency = $activity['load_activity_details']['amount_money']['currency'] ?? 'USD';
                    $type_label = __( 'Load', 'cube-payment-portal' );
                } elseif ( isset( $activity['activate_activity_details']['amount_money']['amount'] ) ) {
                    $amount = $activity['activate_activity_details']['amount_money']['amount'] / 100;
                    $currency = $activity['activate_activity_details']['amount_money']['currency'] ?? 'USD';
                    $type_label = __( 'Activate', 'cube-payment-portal' );
                } elseif ( isset( $activity['redeem_activity_details']['amount_money']['amount'] ) ) {
                    $amount = -($activity['redeem_activity_details']['amount_money']['amount'] / 100);
                    $currency = $activity['redeem_activity_details']['amount_money']['currency'] ?? 'USD';
                    $type_label = __( 'Redeem', 'cube-payment-portal' );
                } elseif ( isset( $activity['adjust_increment_activity_details']['amount_money']['amount'] ) ) {
                    $amount = $activity['adjust_increment_activity_details']['amount_money']['amount'] / 100;
                    $currency = $activity['adjust_increment_activity_details']['amount_money']['currency'] ?? 'USD';
                    $type_label = __( 'Adjustment (+)', 'cube-payment-portal' );
                } elseif ( isset( $activity['adjust_decrement_activity_details']['amount_money']['amount'] ) ) {
                    $amount = -($activity['adjust_decrement_activity_details']['amount_money']['amount'] / 100);
                    $currency = $activity['adjust_decrement_activity_details']['amount_money']['currency'] ?? 'USD';
                    $type_label = __( 'Adjustment (-)', 'cube-payment-portal' );
                }

                $formatted[] = array(
                    'id'         => $activity['id'],
                    'type'       => $type_label,
                    'created_at' => isset( $activity['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $activity['created_at'] ) ) : '',
                    'amount'     => $amount,
                    'currency'   => $currency,
                );
            }

            wp_send_json_success( array(
                'activities' => $formatted,
                'cursor'     => $result['cursor'] ?? '',
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for deactivating a gift card.
     */
    public function ajax_deactivate_gift_card() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $gift_card_id = isset( $_POST['gift_card_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_id'] ) ) : '';
        $location_id = get_option( 'spp_default_location_id', '' );

        if ( empty( $gift_card_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Gift card ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Default location ID is not set. Please configure it in settings.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Gift Cards API.
            if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
            }

            $gift_cards_api = new SPP_Gift_Cards();
            $result = $gift_cards_api->deactivate_gift_card( $gift_card_id, $location_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'  => __( 'Gift card deactivated successfully.', 'cube-payment-portal' ),
                'activity' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching disputes.
     */
    public function ajax_get_disputes() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();

            $args = array();
            if ( ! empty( $status ) ) {
                $args['states'] = $status;
            }
            if ( ! empty( $cursor ) ) {
                $args['cursor'] = $cursor;
            }

            $result = $disputes_api->list_disputes( $args );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Build customer lookup map from disputed payment IDs.
            global $wpdb;
            $transactions_table = $wpdb->prefix . 'spp_transactions';
            $customers_table = $wpdb->prefix . 'spp_customers';
            
            // Extract all payment IDs from disputes.
            $payment_ids = array();
            foreach ( $result['disputes'] ?? array() as $dispute ) {
                $payment_id = $dispute['disputed_payment']['payment_id'] ?? '';
                if ( $payment_id ) {
                    $payment_ids[] = $payment_id;
                }
            }
            
            // Query database for customer info if we have payment IDs.
            $customer_map = array();
            if ( ! empty( $payment_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $payment_ids ), '%s' ) );
                $query = $wpdb->prepare(
                    "SELECT t.square_payment_id, c.id as customer_id, c.square_customer_id, c.given_name, c.family_name, c.company_name, c.phone
                     FROM $transactions_table t
                     JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
                     WHERE t.square_payment_id IN ($placeholders)",
                    ...$payment_ids
                );
                $results = $wpdb->get_results( $query, ARRAY_A );
                
                foreach ( $results as $row ) {
                    $name = trim( ( $row['given_name'] ?? '' ) . ' ' . ( $row['family_name'] ?? '' ) );
                    if ( empty( $name ) ) {
                        $name = $row['company_name'] ?? '';
                    }
                    $customer_map[ $row['square_payment_id'] ] = array(
                        'id'    => $row['customer_id'],
                        'name'  => $name ?: __( 'Unknown', 'cube-payment-portal' ),
                        'phone' => $row['phone'] ?? '',
                    );
                }
            }

            // Format disputes for display.
            $formatted = array();
            foreach ( $result['disputes'] ?? array() as $dispute ) {
                $payment_id = $dispute['disputed_payment']['payment_id'] ?? '';
                $customer_info = $customer_map[ $payment_id ] ?? null;
                
                $formatted[] = array(
                    'id'               => $dispute['id'] ?? '',
                    'dispute_id'       => $dispute['dispute_id'] ?? $dispute['id'] ?? '',
                    'amount'           => number_format( ( (float) ( $dispute['amount_money']['amount'] ?? 0 ) ) / 100, 2 ),
                    'currency'         => $dispute['amount_money']['currency'] ?? 'USD',
                    'reason'           => SPP_Disputes::get_reason_display_name( $dispute['reason'] ?? '' ),
                    'state'            => $dispute['state'] ?? '',
                    'state_label'      => SPP_Disputes::get_state_display_name( $dispute['state'] ?? '' ),
                    'due_at'           => ! empty( $dispute['due_at'] ) ? wp_date( get_option( 'date_format' ), strtotime( $dispute['due_at'] ) ) : '',
                    'created_at'       => ! empty( $dispute['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $dispute['created_at'] ) ) : '',
                    'card_brand'       => $dispute['card_brand'] ?? '',
                    'customer_id'      => $customer_info['id'] ?? '',
                    'customer_name'    => $customer_info['name'] ?? '',
                    'customer_phone'   => $this->format_phone_number( $customer_info['phone'] ?? '' ),
                );
            }

            wp_send_json_success( array(
                'disputes' => $formatted,
                'cursor'   => $result['cursor'] ?? '',
            ) );


        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching dispute detail.
     */
    public function ajax_get_dispute_detail() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $dispute = $disputes_api->get_dispute( $dispute_id );

            if ( is_wp_error( $dispute ) ) {
                wp_send_json_error( array( 'message' => $dispute->get_error_message() ) );
            }

            // Get evidence.
            $evidence = $disputes_api->list_evidence( $dispute_id );
            $dispute['evidence'] = is_wp_error( $evidence ) ? array() : ( $evidence['evidence'] ?? array() );

            wp_send_json_success( array( 'dispute' => $dispute ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }


    /**
     * AJAX handler for accepting a dispute.
     */
    public function ajax_accept_dispute() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $result = $disputes_api->accept_dispute( $dispute_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array( 'message' => __( 'Dispute accepted.', 'cube-payment-portal' ) ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for submitting text evidence.
     */
    public function ajax_submit_evidence_text() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';
        $evidence_type = isset( $_POST['evidence_type'] ) ? sanitize_text_field( wp_unslash( $_POST['evidence_type'] ) ) : 'GENERIC_EVIDENCE';
        $evidence_text = isset( $_POST['evidence_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence_text'] ) ) : '';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $evidence_text ) ) {
            wp_send_json_error( array( 'message' => __( 'Evidence text is required.', 'cube-payment-portal' ) ) );
        }

        // Square limits evidence text to 500 characters.
        if ( strlen( $evidence_text ) > 500 ) {
            wp_send_json_error( array( 'message' => __( 'Evidence text exceeds the 500 character limit.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $result = $disputes_api->create_evidence_text( $dispute_id, $evidence_type, $evidence_text );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'  => __( 'Evidence text added successfully.', 'cube-payment-portal' ),
                'evidence' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for uploading evidence file.
     */
    public function ajax_upload_evidence_file() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';
        $evidence_type = isset( $_POST['evidence_type'] ) ? sanitize_text_field( wp_unslash( $_POST['evidence_type'] ) ) : 'GENERIC_EVIDENCE';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $_FILES['evidence_file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load WordPress file handling functions.
            require_once ABSPATH . 'wp-admin/includes/file.php';

            // Define allowed MIME types for dispute evidence.
            $upload_overrides = array(
                'test_form' => false,
                'mimes'     => array(
                    'jpg|jpeg' => 'image/jpeg',
                    'png'      => 'image/png',
                    'tiff'     => 'image/tiff',
                    'pdf'      => 'application/pdf',
                    'heic'     => 'image/heic',
                    'heif'     => 'image/heif',
                ),
            );

            // Use WordPress upload handler for secure file handling.
            $uploaded = wp_handle_upload( $_FILES['evidence_file'], $upload_overrides );

            if ( isset( $uploaded['error'] ) ) {
                wp_send_json_error( array( 'message' => $uploaded['error'] ) );
            }

            // Validate file size (5MB limit per Square documentation).
            $file_size = filesize( $uploaded['file'] );
            if ( $file_size > 5 * 1024 * 1024 ) {
                unlink( $uploaded['file'] ); // Clean up.
                wp_send_json_error( array( 'message' => __( 'File exceeds the 5MB limit for dispute evidence.', 'cube-payment-portal' ) ) );
            }

            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $result = $disputes_api->create_evidence_file(
                $dispute_id,
                $evidence_type,
                $uploaded['file'],
                $uploaded['type']
            );

            // Always clean up the temporary file.
            unlink( $uploaded['file'] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message'  => __( 'Evidence file uploaded successfully.', 'cube-payment-portal' ),
                'evidence' => $result,
            ) );

        } catch ( Exception $e ) {
            // Clean up file if it exists.
            if ( isset( $uploaded['file'] ) && file_exists( $uploaded['file'] ) ) {
                unlink( $uploaded['file'] );
            }
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for removing evidence from a dispute.
     */
    public function ajax_remove_evidence() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';
        $evidence_id = isset( $_POST['evidence_id'] ) ? sanitize_text_field( wp_unslash( $_POST['evidence_id'] ) ) : '';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( empty( $evidence_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Evidence ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $result = $disputes_api->remove_evidence( $dispute_id, $evidence_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array( 'message' => __( 'Evidence removed successfully.', 'cube-payment-portal' ) ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for submitting dispute challenge to the bank.
     */
    public function ajax_submit_dispute() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $dispute_id = isset( $_POST['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) ) : '';

        if ( empty( $dispute_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Dispute ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Disputes API.
            if ( ! class_exists( 'SPP_Disputes' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
            }

            $disputes_api = new SPP_Disputes();
            $result = $disputes_api->submit_evidence( $dispute_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => __( 'Dispute challenge submitted to the bank. Evidence can no longer be edited.', 'cube-payment-portal' ),
                'dispute' => $result,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching bookings.
     */
    public function ajax_get_bookings() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';
        $limit = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;

        try {
            // Load Bookings API.
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }

            $bookings_api = new SPP_Bookings();

            $args = array(
                'limit' => $limit,
            );

            if ( ! empty( $start_date ) ) {
                $args['start_at_min'] = gmdate( 'c', strtotime( $start_date ) );
            }
            if ( ! empty( $end_date ) ) {
                $args['start_at_max'] = gmdate( 'c', strtotime( $end_date . ' 23:59:59' ) );
            }
            if ( ! empty( $cursor ) ) {
                $args['cursor'] = $cursor;
            }

            $result = $bookings_api->list_bookings( $args );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Format bookings for display.
            $formatted = array();
            $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

            foreach ( $result['bookings'] ?? array() as $booking ) {
                $formatted[] = array(
                    'id'                   => $booking['id'] ?? '',
                    'customer_id'          => $booking['customer_id'] ?? '',
                    'status'               => $booking['status'] ?? '',
                    'start_at'             => ! empty( $booking['start_at'] ) ? wp_date( $date_format, strtotime( $booking['start_at'] ) ) : '',
                    'duration_minutes'     => $booking['appointment_segments'][0]['duration_minutes'] ?? 0,
                    'service_variation_id' => $booking['appointment_segments'][0]['service_variation_id'] ?? '',
                    'team_member_id'       => $booking['appointment_segments'][0]['team_member_id'] ?? '',
                    'created_at'           => ! empty( $booking['created_at'] ) ? wp_date( $date_format, strtotime( $booking['created_at'] ) ) : '',
                );
            }

            wp_send_json_success( array(
                'bookings' => $formatted,
                'cursor'   => $result['cursor'] ?? '',
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler for fetching booking detail.
     */
    public function ajax_get_booking_detail() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $booking_id = isset( $_POST['booking_id'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_id'] ) ) : '';

        if ( empty( $booking_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking ID is required.', 'cube-payment-portal' ) ) );
        }

        try {
            // Load Bookings API.
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }
            if ( ! class_exists( 'SPP_Database' ) ) {
                require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
            }

            $bookings_api = new SPP_Bookings();
            $booking = $bookings_api->get_booking( $booking_id );

            if ( is_wp_error( $booking ) ) {
                wp_send_json_error( array( 'message' => $booking->get_error_message() ) );
            }

            // 1. Calculate End Date (Fix "Invalid Date")
            $start_ts = strtotime( $booking['start_at'] );
            $end_ts = 0;
            if ( ! empty( $booking['end_at'] ) ) {
                $end_ts = strtotime( $booking['end_at'] );
            }
            
            if ( $end_ts <= $start_ts ) {
                $duration_minutes = 0;
                if ( ! empty( $booking['appointment_segments'] ) && is_array( $booking['appointment_segments'] ) ) {
                    foreach ( $booking['appointment_segments'] as $seg ) {
                        $duration_minutes += intval( $seg['duration_minutes'] ?? 0 );
                    }
                }
                if ( $duration_minutes <= 0 ) {
                    $duration_minutes = 30;
                }
                $end_ts = $start_ts + ( $duration_minutes * 60 );
                $booking['end_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $end_ts );
            }

            // 2. Resolve Names (Fix "Unknown Service", "Guest")
            global $wpdb;
            $table = $wpdb->prefix . 'spp_bookings';
            $local = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE square_id = %s", $booking_id ), ARRAY_A );

            // Service Name
            $service_name = '';
            if ( $local && ! empty( $local['service_name'] ) ) {
                $service_name = $local['service_name'];
            } else {
                $segment = $booking['appointment_segments'][0] ?? array();
                $var_id = $segment['service_variation_id'] ?? '';
                if ( ! empty( $var_id ) ) {
                    if ( ! class_exists( 'SPP_Catalog' ) ) {
                        require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
                    }
                    $catalog = new SPP_Catalog();
                    $item_details = $catalog->get_item_details( $var_id );
                    if ( ! is_wp_error( $item_details ) ) {
                        $var_data = $item_details['item_variation_data'] ?? array();
                        $var_name = $var_data['name'] ?? '';
                        $parent_id = $var_data['item_id'] ?? '';
                        $service_name = $var_name;
                        
                        if ( ! empty( $parent_id ) ) {
                            $parent = $catalog->get_item_details( $parent_id );
                            if ( ! is_wp_error( $parent ) ) {
                                $item_name = $parent['item_data']['name'] ?? '';
                                if ( ! empty( $item_name ) ) {
                                    $service_name = ( 'Regular' === $var_name || empty( $var_name ) ) ? $item_name : "$item_name - $var_name";
                                }
                            }
                        }
                    }
                }
            }
            if ( empty( $service_name ) ) {
                $service_name = __( 'Unknown Service', 'cube-payment-portal' );
            }
            $booking['service_name'] = $service_name;

            // Customer Name
            $customer_name = '';
            $customer_email = '';
            $customer_phone = '';
            $customer_id = $booking['customer_id'] ?? '';

            if ( $local && ! empty( $local['customer_id'] ) ) {
                $cust = SPP_Database::get_customer_by_square_id( $local['customer_id'] );
                if ( $cust ) {
                    $customer_name  = trim( ( $cust['given_name'] ?? '' ) . ' ' . ( $cust['family_name'] ?? '' ) );
                    $customer_email = $cust['email'] ?? '';
                    $customer_phone = $cust['phone'] ?? '';
                }
            }
            
            if ( empty( $customer_name ) && ! empty( $customer_id ) ) {
                if ( ! class_exists( 'SPP_Customers' ) ) {
                    require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
                }
                $cust_api = new SPP_Customers();
                $cust_obj = $cust_api->get_customer( $customer_id );
                if ( ! is_wp_error( $cust_obj ) ) {
                    $customer_name = trim( ( $cust_obj['given_name'] ?? '' ) . ' ' . ( $cust_obj['family_name'] ?? '' ) );
                    $customer_email = $cust_obj['email_address'] ?? '';
                    $customer_phone = $cust_obj['phone_number'] ?? '';
                }
            }
            $booking['customer_name']  = $customer_name ?: __( 'Guest', 'cube-payment-portal' );
            $booking['customer_email'] = $customer_email;
            $booking['customer_phone'] = $customer_phone;

            // Team Name
            $team_name = '';
            $segment = $booking['appointment_segments'][0] ?? array();
            $team_id = $segment['team_member_id'] ?? '';

            if ( empty( $team_name ) && ! empty( $team_id ) ) {
                 if ( ! class_exists( 'SPP_Team' ) ) {
                    require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
                }
                $team = new SPP_Team();
                $tm = $team->get_team_member( $team_id );
                if ( ! is_wp_error( $tm ) ) {
                    $team_name = trim( ( $tm['given_name'] ?? '' ) . ' ' . ( $tm['family_name'] ?? '' ) );
                }
            }
            $booking['team_member_name'] = $team_name ?: __( 'Unassigned', 'cube-payment-portal' );

            wp_send_json_success( array( 'booking' => $booking ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }



    /**
     * AJAX handler for getting inventory locations.
     */
    public function ajax_get_locations() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        try {
            // Load Inventory API.
            if ( ! class_exists( 'SPP_Inventory' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
            }

            $inventory_api = new SPP_Inventory();
            $locations = $inventory_api->get_locations();

            if ( is_wp_error( $locations ) ) {
                wp_send_json_error( array( 'message' => $locations->get_error_message() ) );
            }

            wp_send_json_success( array( 'locations' => $locations ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }
    /**
     * AJAX handler for toggling features.
     */
    public function ajax_toggle_feature() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        // Verify permissions.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
        $enabled = isset( $_POST['enabled'] ) && ( $_POST['enabled'] === 'true' || $_POST['enabled'] === '1' );

        // Validate feature name (whitelist to prevent arbitrary option updates).
        $allowed_toggles = array(
            'spp_allow_booking_cancellation',
            'spp_allow_staff_selection',
            'spp_reminder_appointment_enabled',
            'spp_reminder_invoice_enabled',
            'spp_auto_update',
        );
        $is_valid = ! empty( $feature ) && (
            strpos( $feature, 'spp_feature_' ) === 0 || in_array( $feature, $allowed_toggles, true )
        );
        if ( ! $is_valid ) {
             wp_send_json_error( array( 'message' => __( 'Invalid feature specified.', 'cube-payment-portal' ) ) );
        }

        // Update the option.
        update_option( $feature, $enabled );

        wp_send_json_success( array(
            'message' => __( 'Feature setting saved.', 'cube-payment-portal' ),
            'enabled' => $enabled,
        ) );
    }

    /**
     * AJAX handler for fetching loyalty accounts.
     */
    public function ajax_get_loyalty_accounts() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( $_POST['cursor'] ) : null;
        
        $response = $loyalty->search_accounts( array(), $cursor );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $accounts = $response['loyalty_accounts'] ?? array();
        
        // Enrich accounts with customer names if possible.
        foreach ( $accounts as &$account ) {
            $customer_id = $account['customer_id'];
            $customer = SPP_Database::get_customer_by_square_id( $customer_id );
            if ( $customer ) {
                $account['customer_name']      = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
                $account['customer_email']     = $customer['email'] ?? '';
                $account['customer_phone']     = $this->format_phone_number( $customer['phone'] ?? '' );
                $account['square_customer_id'] = $customer['square_customer_id'] ?? $customer_id;

                if ( empty( $account['customer_name'] ) && ! empty( $customer['email'] ) ) {
                    $account['customer_name'] = $customer['email'];
                }
            } else {
                $account['customer_name']      = __( 'Unknown Customer', 'cube-payment-portal' );
                $account['customer_email']     = '';
                $account['customer_phone']     = '';
                $account['square_customer_id'] = '';
            }
        }

        wp_send_json_success( array(
            'accounts' => $accounts,
            'cursor'   => $response['cursor'] ?? null,
        ) );
    }


    /**
     * AJAX handler for adjusting loyalty points.
     */
    public function ajax_adjust_loyalty_points() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( $_POST['account_id'] ) : '';
        $points = isset( $_POST['points'] ) ? intval( $_POST['points'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( $_POST['reason'] ) : '';

        if ( empty( $account_id ) || $points === 0 ) {
            wp_send_json_error( array( 'message' => __( 'Account ID and non-zero points are required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $result = $loyalty->adjust_points( $account_id, $points, $reason );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'event' => $result ) );
    }

    /**
     * AJAX handler for fetching loyalty rewards for an account.
     */
    public function ajax_get_loyalty_rewards() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
        if ( empty( $account_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Account ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $response = $loyalty->search_rewards( array( 'loyalty_account_id' => $account_id ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        wp_send_json_success( array( 'rewards' => $response['rewards'] ?? array() ) );
    }

    /**
     * AJAX handler for creating a loyalty reward.
     */
    public function ajax_create_loyalty_reward() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( $_POST['account_id'] ) : '';
        $reward_tier_id = isset( $_POST['reward_tier_id'] ) ? sanitize_text_field( $_POST['reward_tier_id'] ) : '';

        if ( empty( $account_id ) || empty( $reward_tier_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Account ID and Reward Tier ID are required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $result = $loyalty->create_reward( $reward_tier_id, $account_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'reward' => $result ) );
    }

    /**
     * AJAX handler for deleting a loyalty reward.
     */
    public function ajax_delete_loyalty_reward() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $reward_id = isset( $_POST['reward_id'] ) ? sanitize_text_field( $_POST['reward_id'] ) : '';
        if ( empty( $reward_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Reward ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $result = $loyalty->delete_reward( $reward_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler for fetching loyalty events.
     */
    public function ajax_get_loyalty_events() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
        if ( empty( $account_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Account ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Loyalty' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
        }

        $loyalty = new SPP_Loyalty();
        $events = $loyalty->list_events( $account_id );

        if ( is_wp_error( $events ) ) {
            wp_send_json_error( array( 'message' => $events->get_error_message() ) );
        }

        wp_send_json_success( array( 'events' => $events ) );
    }

    /**
     * Helper to format phone numbers.
     *
     * @param string $phone Raw phone number.
     * @return string Formatted phone number.
     */
    public function format_phone_number( $phone ) {
        if ( empty( $phone ) ) {
            return '';
        }

        // Strip all non-numeric characters.
        $cleaned = preg_replace( '/[^0-9]/', '', $phone );

        // Standard US 10-digit format.
        if ( strlen( $cleaned ) === 10 ) {
            return sprintf( '(%s) %s-%s',
                substr( $cleaned, 0, 3 ),
                substr( $cleaned, 3, 3 ),
                substr( $cleaned, 6, 4 )
            );
        }

        // US 11-digit format starting with 1.
        if ( strlen( $cleaned ) === 11 && substr( $cleaned, 0, 1 ) === '1' ) {
            return sprintf( '1 (%s) %s-%s',
                substr( $cleaned, 1, 3 ),
                substr( $cleaned, 4, 3 ),
                substr( $cleaned, 7, 4 )
            );
        }

        // Return original if no format match (or maybe international).
        return $phone;
    }

    /**
     * AJAX: Get calendar events for bookings.
     */
    public function ajax_get_calendar_events() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! class_exists( 'SPP_Database' ) ) {
            require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
        }

        // FullCalendar request parameters
        // It sends 'start' and 'end' as ISO8601 strings.
        $start = isset( $_GET['start'] ) ? sanitize_text_field( $_GET['start'] ) : '';
        $end   = isset( $_GET['end'] )   ? sanitize_text_field( $_GET['end'] )   : '';
        $team_member_id = isset( $_GET['team_member_id'] ) ? sanitize_text_field( $_GET['team_member_id'] ) : '';

        // Trigger on-demand sync for the requested period to ensure valid data.
        if ( ! empty( $start ) && ! empty( $end ) ) {
            if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
            }
            $syncer = new SPP_Sync_Bookings();
            // We ignore errors here so the calendar still loads whatever we have if sync fails.
            $syncer->sync_period( $start, $end );
        }

        $args = array(
            'limit'    => 500, // Reasonable limit for a month view
            'start_at' => $start,
            'end_at'   => $end,
        );

        if ( ! empty( $team_member_id ) ) {
            $args['team_member_id'] = $team_member_id;
        }

        // We can't strictly filter by start/end via SPP_Database::get_bookings efficiently 
        // because the trait uses simple exact timestamp matching or complex query requirements.
        // However, looking at SPP_Database_Bookings::get_bookings, it supports 'start_at' (>=) and 'end_at' (<= start_at?).
        // actually, the trait implementation of 'end_at' filter is 'AND start_at <= %s'.
        // So passing 'start_at' => $start checks 'start_at >= $start'.
        // And passing 'end_at' => $end checks 'start_at <= $end'.
        // This effectively gets all bookings STARTING within the window. Perfect.

        
        $bookings = SPP_Database::get_bookings( $args );
        
        $events = array();
        foreach ( $bookings as $booking ) {
            // Filter out cancelled appointments
            if ( in_array( $booking['status'], array( 'CANCELLED_BY_SELLER', 'CANCELLED_BY_CUSTOMER', 'DECLINED', 'NO_SHOW' ) ) ) {
                continue;
            }

            // Determine color
            // Priority: Status Color > Default
            $color = '#006aff'; // Default Blue
            
            // Check Status Color
            $status = $booking['status'];
            switch ( $status ) {
                case 'ACCEPTED':
                    $color = '#28a745'; // Green
                    break;
                case 'PENDING':
                    $color = '#ffc107'; // Yellow/Orange
                    break;
            }

            // Title: Customer Name (or Service Name/Appointment if guest)
            // Square's calendar emphasizes the Person.
            $title = __( 'Guest', 'cube-payment-portal' );
            
            // Try to resolve customer name
            $customer_name = '';
            if ( ! empty( $booking['customer_id'] ) ) {
                $customer = SPP_Database::get_customer_by_square_id( $booking['customer_id'] );
                if ( $customer ) {
                    $customer_name = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
                }
            }
            
            if ( $customer_name ) {
                $title = $customer_name;
            } elseif ( ! empty( $booking['service_name'] ) ) {
                $title = $booking['service_name'];
            } elseif ( ! empty( $booking['service_id'] ) ) {
                $title = __( 'Service', 'cube-payment-portal' );
            }

            // Url link to detail
            $url = admin_url( 'admin.php?page=spp-bookings&view=detail&id=' . $booking['id'] );

            // Ensure we have an end date. If missing, default to +30 mins.
            $event_start = $booking['start_at'];
            $event_end = $booking['end_at'];
            if ( empty( $event_end ) || $event_end === $event_start ) {
                $event_end = date( 'Y-m-d H:i:s', strtotime( $event_start ) + 1800 );
            }

            $events[] = array(
                'id' => $booking['square_id'],
                'title' => $title,
                'start' => $event_start,
                'end' => $event_end,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'url' => $url,
                'extendedProps' => array(
                    'status'       => $booking['status'],
                    'service_name' => $booking['service_name'] ?? '',
                )
            );
        }

        wp_send_json( $events );
    }

    /**
     * AJAX: Save Service (Create or Update).
     */
    public function ajax_save_service() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $service_id = sanitize_text_field( $_POST['service_id'] ?? '' );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $desc       = sanitize_textarea_field( $_POST['description'] ?? '' );
        
        // Variations (JSON string)
        $variations_json = stripslashes( $_POST['variations'] ?? '' );
        $variations = json_decode( $variations_json, true );

        // If no variations provided, try to build one from legacy/flat fields (for backward compat or simple edits)
        if ( empty( $variations ) || ! is_array( $variations ) ) {
             // Pricing
            $pricing_type = sanitize_text_field( $_POST['pricing_type'] ?? 'FIXED_PRICING' );
            $price = sanitize_text_field( $_POST['price'] ?? '' );
            
            // Duration (Hours + Minutes)
            $duration_hours = intval( $_POST['duration_hours'] ?? 0 );
            $duration_mins  = intval( $_POST['duration_minutes'] ?? 0 );
            $total_minutes  = ( $duration_hours * 60 ) + $duration_mins;
            
            // Settings
            $bookable = isset( $_POST['bookable'] ) && $_POST['bookable'] === 'true';
            $team_members = isset( $_POST['team_member_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['team_member_ids'] ) : array();

            if ( $total_minutes <= 0 ) {
                 wp_send_json_error( array( 'message' => __( 'Duration must be greater than 0.', 'cube-payment-portal' ) ) );
            }

            $variations = array(
                array(
                    'name' => 'Regular',
                    'pricing_type' => $pricing_type,
                    'price' => $price,
                    'duration_minutes' => $total_minutes,
                    'available_for_booking' => $bookable,
                    'team_member_ids' => $team_members,
                )
            );
        } else {
            // Sanitize input variations
            foreach ( $variations as &$v ) {
                $v['name'] = sanitize_text_field( $v['name'] ?? '' );
                $v['pricing_type'] = sanitize_text_field( $v['pricing_type'] ?? 'FIXED_PRICING' );
                $v['price'] = sanitize_text_field( $v['price'] ?? '' ); // Keep as string or float
                $v['duration_minutes'] = intval( $v['duration_minutes'] ?? 0 );
                $v['available_for_booking'] = (bool) ( $v['available_for_booking'] ?? true );
                $v['team_member_ids'] = isset( $v['team_member_ids'] ) && is_array( $v['team_member_ids'] ) ? array_map( 'sanitize_text_field', $v['team_member_ids'] ) : array();
                
                if ( isset( $v['id'] ) ) {
                    $v['id'] = sanitize_text_field( $v['id'] );
                }
            }
            unset( $v );
        }

        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => __( 'Service Name is required.', 'cube-payment-portal' ) ) );
        }
        
        // Load Catalog
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }
        $catalog = new SPP_Catalog();

        $data = array(
            'name'                  => $name,
            'description'           => $desc,
            'variations'            => $variations,
            'currency'              => 'USD',
        );

        if ( ! empty( $service_id ) ) {
            // Update
            $result = $catalog->update_appointment_service( $service_id, $data );
        } else {
            // Create
            $result = $catalog->create_appointment_service( $data );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 
            'message' => __( 'Service saved successfully.', 'cube-payment-portal' ),
            'service' => $result,
        ) );
    }

    /**
     * AJAX: Delete Service.
     */
    public function ajax_delete_service() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $service_id = sanitize_text_field( $_POST['service_id'] ?? '' );

        if ( empty( $service_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Service ID is required.', 'cube-payment-portal' ) ) );
        }

        // Load Catalog
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }
        $catalog = new SPP_Catalog();

        $result = $catalog->delete_item( $service_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Service deleted successfully.', 'cube-payment-portal' ) ) );
    }

    /**
     * AJAX handler to get detailed team member info (Wages).
     */
    public function ajax_get_team_member_details() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : '';
        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'Missing ID' ) );
        }

        if ( ! class_exists( 'SPP_Team' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
        }

        $team_api = new SPP_Team();
        $wage_setting = $team_api->get_wage_setting( $id );

        if ( is_wp_error( $wage_setting ) ) {
            // It's possible they don't have one, or API error. Return empty success to clear fields.
            wp_send_json_success( array() );
        }

        wp_send_json_success( $wage_setting );
    }

    /**
     * AJAX handler to save (create/update) a team member.
     */
    public function ajax_save_team_member() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $id = sanitize_text_field( $_POST['id'] ?? '' );
        $given_name = sanitize_text_field( $_POST['given_name'] ?? '' );
        $family_name = sanitize_text_field( $_POST['family_name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $phone = sanitize_text_field( $_POST['phone_number'] ?? '' );
    
        // Auto-format phone to E.164 (e.g., +11234567890)
        if ( ! empty( $phone ) ) {
            // Strip everything except digits
            $digits = preg_replace( '/[^0-9]/', '', $phone );
            if ( strlen( $digits ) === 10 ) {
                $phone = '+1' . $digits; // Assume US
            } elseif ( strlen( $digits ) >= 11 ) {
                $phone = '+' . $digits;
            } else {
                $phone = '+' . $digits; // Best effort for short numbers
            }
        }
        $status = sanitize_text_field( $_POST['status'] ?? 'ACTIVE' ); // ACTIVE or INACTIVE

        if ( empty( $given_name ) || empty( $family_name ) ) {
            wp_send_json_error( array( 'message' => __( 'First and Last Name are required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Team' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
        }
        $team_api = new SPP_Team();

        // Prepare Data for Square API
        $data = array(
            'given_name'    => $given_name,
            'family_name'   => $family_name,
            'email_address' => $email,
            'phone_number'  => $phone,
            'status'        => $status,
        );

        if ( ! empty( $id ) ) {
            // Update - Fetch current version first
            $current = $team_api->get_team_member( $id );
            if ( is_wp_error( $current ) ) {
                wp_send_json_error( array( 'message' => $current->get_error_message() ) );
            }
            
            $data['version'] = $current['version'];
            
            $result = $team_api->update_team_member( $id, $data );
        } else {
            // Create
            $result = $team_api->create_team_member( $data );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Handle Booking Profile Updates (Bio, Display Name, Bookable)
        // Only if we have a valid ID (from create or update) and fields are set
        $team_member_id = $result['id'] ?? $id;
        
        if ( $team_member_id && isset( $_POST['is_bookable'] ) ) {
            // Check for Booking API
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }
            $bookings_api = new SPP_Bookings();
            
            $profile_data = array(
                'is_bookable' => 'true' === $_POST['is_bookable'],
            );
            
            if ( isset( $_POST['display_name'] ) ) {
                $profile_data['display_name'] = sanitize_text_field( $_POST['display_name'] );
            }
            
            if ( isset( $_POST['description'] ) ) {
                $profile_data['description'] = sanitize_textarea_field( $_POST['description'] );
            }
            
            // Try to update profile. Note: If it doesn't exist, Square creates it? 
            // Endpoints are usually Retrieve or Update. Let's try Update.
            $bookings_api->update_team_member_booking_profile( $team_member_id, $profile_data );
        }

        // Handle Wage Settings
        $job_title = isset( $_POST['job_title'] ) ? sanitize_text_field( $_POST['job_title'] ) : '';
        $pay_type = isset( $_POST['pay_type'] ) ? sanitize_text_field( $_POST['pay_type'] ) : 'NONE';
        $pay_amount = isset( $_POST['pay_amount'] ) ? (float) $_POST['pay_amount'] : 0;

        if ( ! empty( $team_member_id ) ) {
            $wage_data = array(
                'team_member_id' => $team_member_id,
            );
            
            if ( ! empty( $job_title ) ) {
                $wage_data['title'] = $job_title;
            }
            
            if ( $pay_type !== 'NONE' ) {
                $wage_data['pay_type'] = $pay_type;
                if ( $pay_amount > 0 ) {
                    $amount_cents = (int) ( $pay_amount * 100 );
                    if ( $pay_type === 'HOURLY' ) {
                        $wage_data['hourly_rate'] = array(
                            'amount' => $amount_cents,
                            'currency' => 'USD',
                        );
                    } elseif ( $pay_type === 'SALARY' ) {
                            $wage_data['annual_rate'] = array(
                            'amount' => $amount_cents,
                            'currency' => 'USD',
                        );
                    }
                }
            }
            
            if ( count( $wage_data ) > 1 ) {
                $team_api->update_wage_setting( $team_member_id, $wage_data );
            }
        }

        wp_send_json_success( array( 
            'message' => __( 'Team member saved successfully.', 'cube-payment-portal' ),
            'team_member' => $result,
        ) );
    }

    /**
     * AJAX handler to deactivate (delete) a team member.
     */
    public function ajax_delete_team_member() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $id = sanitize_text_field( $_POST['id'] ?? '' );

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => __( 'ID is required.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Team' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
        }
        $team_api = new SPP_Team();

        // Retrieve current to keep other data? Actually update_team_member replaces data?
        // Square API: "UpdateTeamMember... The `team_member` object in the request must contain the `version` field... The `team_member` object replaces the existing resource."
        // So we MUST fetch first to get version and existing data.
        
        $current = $team_api->get_team_member( $id );
        if ( is_wp_error( $current ) ) {
            wp_send_json_error( array( 'message' => $current->get_error_message() ) );
        }

        $data = array(
            'version' => $current['version'], // Optimistic locking
            'status' => 'INACTIVE', // Deactivate
            // We should probably keep other fields? The API "replaces" the resource.
            // If we only send status, other fields might be cleared?
            // "The team_member object in the request must contain the version field... The team_member object in the request replaces the existing resource."
            // Yes, we need to pass back all existing data with updated status.
            'given_name' => $current['given_name'] ?? '',
            'family_name' => $current['family_name'] ?? '',
            'email_address' => $current['email_address'] ?? '',
            'phone_number' => $current['phone_number'] ?? '',
        );

        $result = $team_api->update_team_member( $id, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 
            'message' => __( 'Team member deactivated successfully.', 'cube-payment-portal' ),
            'team_member' => $result
        ) );
    }


    /**
     * AJAX: Create Booking.
     */
    public function ajax_create_booking() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }
        if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
        }

        $customer_id = sanitize_text_field( $_POST['customer_id'] ?? '' );
        $start_at    = sanitize_text_field( $_POST['start_at'] ?? '' );
        $location_id = sanitize_text_field( $_POST['location_id'] ?? '' );
        $note        = sanitize_textarea_field( $_POST['customer_note'] ?? '' );
        
        $service_vars = isset( $_POST['service_variation_id'] ) ? (array) $_POST['service_variation_id'] : array();
        $team_members = isset( $_POST['team_member_id'] ) ? (array) $_POST['team_member_id'] : array();
        $service_versions = isset( $_POST['service_variation_version'] ) ? (array) $_POST['service_variation_version'] : array();

        if ( empty( $customer_id ) || empty( $start_at ) || empty( $service_vars ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'cube-payment-portal' ) ) );
        }

        // Use default location if none provided.
        if ( empty( $location_id ) ) {
            $location_id = get_option( 'spp_default_location_id', '' );
        }

        // Build appointment segments — fetch version from Catalog API if missing.
        $segments = array();
        
        foreach ( $service_vars as $index => $var_id ) {
            $tm_id = ! empty( $team_members[ $index ] ) ? sanitize_text_field( $team_members[ $index ] ) : '';
            $svc_version = ! empty( $service_versions[ $index ] ) ? intval( $service_versions[ $index ] ) : 0;

            // If version is missing, look it up from the Catalog API.
            if ( 0 === $svc_version ) {
                if ( ! class_exists( 'SPP_Catalog' ) ) {
                    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
                }
                $catalog = new SPP_Catalog();
                $obj = $catalog->get_item_details( sanitize_text_field( $var_id ) );
                if ( ! is_wp_error( $obj ) && ! empty( $obj['version'] ) ) {
                    $svc_version = intval( $obj['version'] );
                }
            }

            $segment = array(
                'service_variation_id'      => sanitize_text_field( $var_id ),
                'service_variation_version' => $svc_version,
            );

            // Only include team_member_id if specified (omit for "Any Team Member").
            if ( ! empty( $tm_id ) ) {
                $segment['team_member_id'] = $tm_id;
            } else {
                $segment['any_team_member'] = true;
            }

            $segments[] = $segment;
        }

        $booking_data = array(
            'customer_id'          => $customer_id,
            'start_at'             => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $start_at ) ),
            'appointment_segments' => $segments,
        );

        // Only include location_id if we have one.
        if ( ! empty( $location_id ) ) {
            $booking_data['location_id'] = $location_id;
        }

        // Only include customer_note if non-empty.
        if ( ! empty( $note ) ) {
            $booking_data['customer_note'] = $note;
        }

        $bookings_api = new SPP_Bookings();
        $result = $bookings_api->create_booking( $booking_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Sync immediately
        $sync = new SPP_Sync_Bookings();
        $sync->sync_single_booking( $result );

        wp_send_json_success( array( 'booking' => $result ) );
    }

    /**
     * AJAX: Update Booking.
     */
    public function ajax_update_booking() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }
        if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
        }

        $booking_id = sanitize_text_field( $_POST['booking_id'] ?? '' );
        $version    = intval( $_POST['version'] ?? 0 );
        $start_at   = sanitize_text_field( $_POST['start_at'] ?? '' );
        $note       = sanitize_textarea_field( $_POST['customer_note'] ?? '' );
        
        if ( empty( $booking_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking ID is required.', 'cube-payment-portal' ) ) );
        }

        $bookings_api = new SPP_Bookings();

        $booking_data = array(
             'version' => $version, // Required for optimistic concurrency
        );

        if ( ! empty( $start_at ) ) {
            $booking_data['start_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $start_at ) );
        }
        
        if ( isset( $_POST['customer_note'] ) ) {
            $booking_data['customer_note'] = $note;
        }

        // Handle segments update if provided
        if ( isset( $_POST['service_variation_id'] ) ) {
            $service_vars = (array) $_POST['service_variation_id'];
            $team_members = isset( $_POST['team_member_id'] ) ? (array) $_POST['team_member_id'] : array();
            $service_versions = isset( $_POST['service_variation_version'] ) ? (array) $_POST['service_variation_version'] : array();
            
            // If no team_member selected, try to get one from the existing booking
            $existing_team_member_id = '';
            if ( empty( array_filter( $team_members ) ) ) {
                $existing_booking = $bookings_api->get_booking( $booking_id );
                if ( ! is_wp_error( $existing_booking ) && ! empty( $existing_booking['appointment_segments'][0]['team_member_id'] ) ) {
                    $existing_team_member_id = $existing_booking['appointment_segments'][0]['team_member_id'];
                }
            }

            $segments = array();
            foreach ( $service_vars as $index => $var_id ) {
                $tm_id = ! empty( $team_members[ $index ] ) ? sanitize_text_field( $team_members[ $index ] ) : $existing_team_member_id;
                $svc_version = ! empty( $service_versions[ $index ] ) ? intval( $service_versions[ $index ] ) : 0;
                
                $segment = array(
                    'service_variation_id'      => sanitize_text_field( $var_id ),
                    'service_variation_version' => $svc_version,
                    'team_member_id'            => $tm_id,
                );
                $segments[] = $segment;
            }
            $booking_data['appointment_segments'] = $segments;
        }

        $result = $bookings_api->update_booking( $booking_id, $booking_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Sync immediately
        $sync = new SPP_Sync_Bookings();
        $sync->sync_single_booking( $result );

        wp_send_json_success( array( 'booking' => $result ) );
    }

    /**
     * AJAX: Cancel Booking.
     */
    public function ajax_cancel_booking() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }
        if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
        }

        $booking_id = sanitize_text_field( $_POST['booking_id'] ?? '' );
        $version    = intval( $_POST['version'] ?? 0 );

        if ( empty( $booking_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking ID is required.', 'cube-payment-portal' ) ) );
        }

        $bookings_api = new SPP_Bookings();
        $result = $bookings_api->cancel_booking( $booking_id, $version );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Sync immediately
        $sync = new SPP_Sync_Bookings();
        $sync->sync_single_booking( $result );

        wp_send_json_success( array( 'booking' => $result ) );
    }

    /**
     * AJAX: Search customers for autocomplete.
     *
     * Queries the local spp_customers table by name, email, or company.
     * Square's SearchCustomers API does not support fuzzy name search,
     * so we use the synced local data instead.
     */
    public function ajax_search_customers_autocomplete() {
        check_ajax_referer( 'spp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ) );
        }

        $term = sanitize_text_field( $_REQUEST['term'] ?? '' );

        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( array() );
        }

        if ( ! class_exists( 'SPP_Database' ) ) {
            require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
        }

        $customers = SPP_Database::get_customers( array(
            'search' => $term,
            'limit'  => 10,
        ) );

        $results = array();

        foreach ( $customers as $customer ) {
            $name  = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) );
            $email = $customer['email'] ?? '';
            $label = $name;

            if ( ! empty( $email ) ) {
                $label .= ' (' . $email . ')';
            }

            if ( empty( trim( $name ) ) && ! empty( $email ) ) {
                $label = $email;
            }

            // The booking form needs the Square customer ID, not the local DB ID.
            $square_id = $customer['square_customer_id'] ?? '';

            if ( empty( $square_id ) ) {
                continue; // Skip customers without a Square ID.
            }

            $results[] = array(
                'label' => $label,
                'value' => $square_id,
            );
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler: Run a single scheduled task immediately.
     */
    public function ajax_run_scheduled_task() {
        if ( ! check_ajax_referer( 'spp_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'cube-payment-portal' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cube-payment-portal' ) ), 403 );
        }

        $task_hook = sanitize_text_field( wp_unslash( $_POST['task_hook'] ?? '' ) );

        // Whitelist of allowed hooks and their runner callables / last-run option keys.
        $allowed_tasks = array(
            'spp_sync_customers'              => array( 'runner' => 'spp_run_sync_customers',              'last_run' => 'spp_customers_last_sync' ),
            'spp_sync_invoices'               => array( 'runner' => 'spp_run_sync_invoices',               'last_run' => 'spp_invoices_last_sync' ),
            'spp_sync_subscriptions'          => array( 'runner' => 'spp_run_sync_subscriptions',          'last_run' => 'spp_subscriptions_last_sync' ),
            'spp_sync_transactions'           => array( 'runner' => 'spp_run_sync_transactions',           'last_run' => 'spp_transactions_last_sync' ),
            'spp_sync_catalog'                => array( 'runner' => 'spp_run_sync_catalog',                'last_run' => 'spp_catalog_last_sync' ),
            'spp_sync_orders'                 => array( 'runner' => 'spp_run_sync_orders',                 'last_run' => 'spp_orders_last_sync' ),
            'spp_sync_gift_card_activities'   => array( 'runner' => 'spp_run_sync_gift_card_activities',   'last_run' => 'spp_gift_card_activities_last_sync' ),
            'spp_send_appointment_reminders'  => array( 'runner' => 'notification_appointment',            'last_run' => 'spp_last_appointment_reminder' ),
            'spp_send_invoice_reminders'      => array( 'runner' => 'notification_invoice',                'last_run' => 'spp_last_invoice_reminder' ),
        );

        if ( ! isset( $allowed_tasks[ $task_hook ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown task.', 'cube-payment-portal' ) ) );
        }

        $task = $allowed_tasks[ $task_hook ];

        try {
            if ( 'notification_appointment' === $task['runner'] ) {
                if ( ! class_exists( 'SPP_Notifications' ) ) {
                    require_once SPP_PLUGIN_DIR . 'includes/class-spp-notifications.php';
                }
                $notifications = new SPP_Notifications();
                $notifications->send_appointment_reminders();
            } elseif ( 'notification_invoice' === $task['runner'] ) {
                if ( ! class_exists( 'SPP_Notifications' ) ) {
                    require_once SPP_PLUGIN_DIR . 'includes/class-spp-notifications.php';
                }
                $notifications = new SPP_Notifications();
                $notifications->send_invoice_reminders();
            } elseif ( function_exists( $task['runner'] ) ) {
                call_user_func( $task['runner'] );
            } else {
                wp_send_json_error( array( 'message' => __( 'Task runner not available.', 'cube-payment-portal' ) ) );
            }

            update_option( $task['last_run'], time() );

            wp_send_json_success( array(
                'message'  => __( 'Task completed successfully.', 'cube-payment-portal' ),
                'last_run' => current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
            ) );
        } catch ( \Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }
}
 // End Class