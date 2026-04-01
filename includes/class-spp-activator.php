<?php
/**
 * Fired during plugin activation.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Activator
 *
 * Handles all plugin activation tasks.
 */
class SPP_Activator {

    /**
     * Activate the plugin.
     *
     * Creates necessary database tables, adds default options,
     * and sets up the custom user role.
     */
    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::add_default_options();
        self::create_pages();
        self::schedule_cron_jobs();
        
        // Flush rewrite rules.
        flush_rewrite_rules();
        
        // Set activation flag for welcome notice.
        set_transient( 'spp_activation_redirect', true, 30 );
    }

    /**
     * Create custom database tables.
     * Made public so it can be called manually from settings.
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Transactions table.
        $table_transactions = $wpdb->prefix . 'spp_transactions';
        $sql_transactions = "CREATE TABLE $table_transactions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            square_customer_id varchar(255) DEFAULT NULL,
            square_payment_id varchar(255) DEFAULT NULL,
            square_plan_id varchar(255) DEFAULT NULL,
            square_subscription_id varchar(255) DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(50) NOT NULL DEFAULT 'pending',
            card_last_four varchar(4) DEFAULT NULL,
            card_brand varchar(50) DEFAULT NULL,
            source_type varchar(50) DEFAULT 'one_time',
            source_id varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY square_payment_id (square_payment_id),
            KEY square_plan_id (square_plan_id),
            KEY square_subscription_id (square_subscription_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Subscriptions table.
        $table_subscriptions = $wpdb->prefix . 'spp_subscriptions';
        $sql_subscriptions = "CREATE TABLE $table_subscriptions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            square_subscription_id varchar(255) NOT NULL,
            square_customer_id varchar(255) DEFAULT NULL,
            square_plan_id varchar(255) NOT NULL,
            plan_name varchar(255) DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            cadence varchar(50) NOT NULL DEFAULT 'MONTHLY',
            status varchar(50) NOT NULL DEFAULT 'active',
            start_date datetime DEFAULT NULL,
            next_billing_date datetime DEFAULT NULL,
            canceled_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY square_subscription_id (square_subscription_id),
            KEY square_customer_id (square_customer_id),
            KEY status (status)
        ) $charset_collate;";

        // Invoices table.
        $table_invoices = $wpdb->prefix . 'spp_invoices';
        $sql_invoices = "CREATE TABLE $table_invoices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            square_invoice_id varchar(255) NOT NULL,
            square_order_id varchar(255) DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(50) NOT NULL DEFAULT 'draft',
            due_date date DEFAULT NULL,
            paid_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY square_invoice_id (square_invoice_id),
            KEY status (status)
        ) $charset_collate;";

        // Customers table - stores all Square customers with optional WP user link.
        $table_customers = $wpdb->prefix . 'spp_customers';
        $sql_customers = "CREATE TABLE $table_customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            square_customer_id varchar(255) NOT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            given_name varchar(255) DEFAULT NULL,
            family_name varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            company_name varchar(255) DEFAULT NULL,
            address_line_1 varchar(255) DEFAULT NULL,
            address_city varchar(100) DEFAULT NULL,
            address_state varchar(50) DEFAULT NULL,
            address_postal_code varchar(20) DEFAULT NULL,
            address_country varchar(10) DEFAULT NULL,
            birthday varchar(10) DEFAULT NULL,
            sync_status varchar(20) DEFAULT 'synced',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY square_customer_id (square_customer_id),
            KEY wp_user_id (wp_user_id),
            KEY email (email),
            KEY sync_status (sync_status)
        ) $charset_collate;";

        // Orders table.
        $table_orders = $wpdb->prefix . 'spp_orders';
        $sql_orders = "CREATE TABLE $table_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            square_order_id varchar(255) NOT NULL,
            square_customer_id varchar(255) DEFAULT NULL,
            square_location_id varchar(255) DEFAULT NULL,
            reference_id varchar(255) DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'OPEN',
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            total_tax decimal(10,2) DEFAULT 0,
            total_discount decimal(10,2) DEFAULT 0,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            line_items longtext DEFAULT NULL,
            fulfillments longtext DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY square_order_id (square_order_id),
            KEY square_customer_id (square_customer_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Catalog Items table.
        $table_catalog = $wpdb->prefix . 'spp_catalog_items';
        $sql_catalog = "CREATE TABLE $table_catalog (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            square_item_id varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            category_id varchar(255) DEFAULT NULL,
            category_name varchar(255) DEFAULT NULL,
            product_type varchar(50) DEFAULT 'REGULAR',
            price decimal(10,2) DEFAULT 0,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            variations longtext DEFAULT NULL,
            image_url varchar(500) DEFAULT NULL,
            is_deleted tinyint(1) DEFAULT 0,
            is_archived tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY square_item_id (square_item_id),
            KEY category_id (category_id),
            KEY name (name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_transactions );
        dbDelta( $sql_subscriptions );
        dbDelta( $sql_invoices );
        dbDelta( $sql_customers );
        dbDelta( $sql_orders );
        dbDelta( $sql_catalog );

        // Create Bookings table.
        SPP_Database::create_bookings_table();

        // Migrate existing tables (add new columns if upgrading).
        self::migrate_tables();

        // Store database version.
        update_option( 'spp_db_version', SPP_VERSION );
    }

    /**
     * Migrate database tables for updates.
     * Made public so it can be called manually if needed.
     *
     * Note on SQL Security: These DDL statements use table names constructed from
     * $wpdb->prefix which is a trusted WordPress internal value. wpdb::prepare()
     * doesn't support table identifiers (only values), and these queries contain
     * no user input. This approach is consistent with WordPress core practices.
     */
    public static function migrate_tables() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        
        // Ensure bookings table exists (added in 1.0.6).
        if ( method_exists( 'SPP_Database', 'create_bookings_table' ) ) {
            SPP_Database::create_bookings_table();
        }

        $table_invoices = $wpdb->prefix . 'spp_invoices';
        
        // Check if new columns exist, add them if not.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- DDL statement with trusted table name
        $columns = $wpdb->get_results( "DESCRIBE `{$table_invoices}`", ARRAY_A );
        $column_names = array_column( $columns, 'Field' );
        
        if ( ! in_array( 'synced_at', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN synced_at DATETIME DEFAULT NULL" );
        }
        
        if ( ! in_array( 'square_customer_id', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN square_customer_id VARCHAR(255) DEFAULT NULL" );
            // Check if index already exists before adding.
            $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_invoices}` WHERE Key_name = 'idx_square_customer'", ARRAY_A );
            if ( empty( $indexes ) ) {
                $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD INDEX idx_square_customer (square_customer_id)" );
            }
        }
        
        if ( ! in_array( 'sync_source', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN sync_source VARCHAR(20) DEFAULT 'plugin'" );
        }
        
        // Add columns for Square customer name and email.
        if ( ! in_array( 'customer_name_square', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN customer_name_square VARCHAR(255) DEFAULT NULL" );
        }
        
        if ( ! in_array( 'customer_email_square', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN customer_email_square VARCHAR(255) DEFAULT NULL" );
        }
        
        // Add column for Square invoice number.
        if ( ! in_array( 'invoice_number', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN invoice_number VARCHAR(50) DEFAULT NULL" );
            // Add index for faster lookups.
            $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_invoices}` WHERE Key_name = 'idx_invoice_number'", ARRAY_A );
            if ( empty( $indexes ) ) {
                $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD INDEX idx_invoice_number (invoice_number)" );
            }
        }
        
        // Make user_id nullable to support Square-only customers.
        $user_id_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$table_invoices}` WHERE Field = 'user_id'", ARRAY_A );
        if ( $user_id_col && 'NO' === strtoupper( $user_id_col['Null'] ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` MODIFY COLUMN user_id bigint(20) unsigned DEFAULT NULL" );
        }
        
        // Add invoice title and description columns.
        if ( ! in_array( 'title', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN title VARCHAR(255) DEFAULT NULL" );
        }
        
        if ( ! in_array( 'description', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_invoices}` ADD COLUMN description TEXT DEFAULT NULL" );
        }

        // Migrate transactions table - add plan and subscription columns.
        $table_transactions = $wpdb->prefix . 'spp_transactions';
        $trans_columns = $wpdb->get_results( "DESCRIBE `{$table_transactions}`", ARRAY_A );
        $trans_column_names = array_column( $trans_columns, 'Field' );

        if ( ! in_array( 'square_plan_id', $trans_column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_transactions}` ADD COLUMN square_plan_id VARCHAR(255) DEFAULT NULL" );
            // Add index for filtering by plan.
            $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_transactions}` WHERE Key_name = 'square_plan_id'", ARRAY_A );
            if ( empty( $indexes ) ) {
                $wpdb->query( "ALTER TABLE `{$table_transactions}` ADD INDEX square_plan_id (square_plan_id)" );
            }
        }

        if ( ! in_array( 'square_subscription_id', $trans_column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_transactions}` ADD COLUMN square_subscription_id VARCHAR(255) DEFAULT NULL" );
            // Add index for filtering by subscription.
            $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_transactions}` WHERE Key_name = 'square_subscription_id'", ARRAY_A );
            if ( empty( $indexes ) ) {
                $wpdb->query( "ALTER TABLE `{$table_transactions}` ADD INDEX square_subscription_id (square_subscription_id)" );
            }
        }

        // Migrate catalog table - add product_type.
        $table_catalog = $wpdb->prefix . 'spp_catalog_items';
        $catalog_columns = $wpdb->get_results( "DESCRIBE `{$table_catalog}`", ARRAY_A );
        $catalog_column_names = array_column( $catalog_columns, 'Field' );

        if ( ! in_array( 'product_type', $catalog_column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_catalog}` ADD COLUMN product_type VARCHAR(50) DEFAULT 'REGULAR'" );
            $wpdb->query( "ALTER TABLE `{$table_catalog}` ADD INDEX product_type (product_type)" );
        }

        if ( ! in_array( 'is_archived', $catalog_column_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_catalog}` ADD COLUMN is_archived tinyint(1) DEFAULT 0" );
            $wpdb->query( "ALTER TABLE `{$table_catalog}` ADD INDEX is_archived (is_archived)" );
        }

        // Migrate customers table - add birthday column.
        $table_customers      = $wpdb->prefix . 'spp_customers';
        $customer_columns     = $wpdb->get_results( "DESCRIBE `{$table_customers}`", ARRAY_A );
        $customer_col_names   = array_column( $customer_columns, 'Field' );

        if ( ! in_array( 'birthday', $customer_col_names, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_customers}` ADD COLUMN birthday VARCHAR(10) DEFAULT NULL AFTER address_country" );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Schedule cron jobs.
     */
    private static function schedule_cron_jobs() {
        // Define all sync types with their settings.
        $sync_types = array(
            'customers'     => array(
                'hook'    => 'spp_sync_customers',
                'option'  => 'spp_sync_freq_customers',
                'default' => 'spp_hourly',
            ),
            'invoices'      => array(
                'hook'    => 'spp_sync_invoices',
                'option'  => 'spp_sync_freq_invoices',
                'default' => 'spp_hourly',
            ),
            'subscriptions' => array(
                'hook'    => 'spp_sync_subscriptions',
                'option'  => 'spp_sync_freq_subscriptions',
                'default' => 'spp_hourly',
            ),
            'transactions'  => array(
                'hook'    => 'spp_sync_transactions',
                'option'  => 'spp_sync_freq_transactions',
                'default' => 'spp_6hours',
            ),
            'catalog'       => array(
                'hook'    => 'spp_sync_catalog',
                'option'  => 'spp_sync_freq_catalog',
                'default' => 'spp_daily',
            ),
            'orders'        => array(
                'hook'    => 'spp_sync_orders',
                'option'  => 'spp_sync_freq_orders',
                'default' => 'spp_daily',
            ),
            'gift_card_activities' => array(
                'hook'    => 'spp_sync_gift_card_activities',
                'option'  => 'spp_sync_freq_gift_card_activities',
                'default' => 'spp_6hours',
            ),
        );

        // Schedule each sync type if not already scheduled.
        // First run is one full interval from now to prevent immediate overdue.
        $stagger = 0;
        foreach ( $sync_types as $type_key => $type_config ) {
            $frequency = get_option( $type_config['option'], $type_config['default'] );

            // Skip if disabled or already scheduled.
            if ( 'disabled' === $frequency || wp_next_scheduled( $type_config['hook'] ) ) {
                continue;
            }

            $interval = function_exists( 'spp_get_schedule_interval' ) ? spp_get_schedule_interval( $frequency ) : 3600;
            // Stagger by 30 seconds per task to prevent simultaneous execution.
            wp_schedule_event( time() + $interval + $stagger, $frequency, $type_config['hook'] );
            $stagger += 30;
        }

        // Clear legacy hooks if they exist.
        wp_clear_scheduled_hook( 'spp_customer_sync' );
        wp_clear_scheduled_hook( 'spp_invoice_sync' );

        // Schedule notification reminder cron jobs (run hourly, check frequency internally).
        if ( ! wp_next_scheduled( 'spp_send_appointment_reminders' ) ) {
            wp_schedule_event( time() + 3600, 'hourly', 'spp_send_appointment_reminders' );
        }
        if ( ! wp_next_scheduled( 'spp_send_invoice_reminders' ) ) {
            wp_schedule_event( time() + 3630, 'hourly', 'spp_send_invoice_reminders' );
        }
    }

    /**
     * Create custom user role for portal clients.
     */
    private static function create_roles() {
        add_role(
            'spp_client',
            __( 'Portal Client', 'cube-payment-portal' ),
            array(
                'read' => true,
            )
        );

        // Placeholder role for admin-created customers who haven't claimed their account.
        add_role(
            'spp_client_placeholder',
            __( 'Portal Client (Placeholder)', 'cube-payment-portal' ),
            array(
                'read' => false, // No access until claimed.
            )
        );
    }

    /**
     * Create portal pages if they don't exist.
     *
     * Creates the main Client Portal page and Login page automatically.
     * Made public so it can be called from the settings page ("Recreate Pages" button).
     */
    public static function create_pages() {
        $pages = array(
            'spp_portal_page_id' => array(
                'title'   => __( 'Client Portal', 'cube-payment-portal' ),
                'slug'    => 'portal',
                'content' => '[spp_client_portal]',
                'parent'  => 0,
            ),
            'spp_login_page_id'  => array(
                'title'   => __( 'Portal Login', 'cube-payment-portal' ),
                'slug'    => 'login',
                'content' => '[spp_login_form]',
                'parent'  => 'spp_portal_page_id', // resolved below
            ),
            'spp_register_page_id' => array(
                'title'   => __( 'Portal Registration', 'cube-payment-portal' ),
                'slug'    => 'register',
                'content' => '[spp_register_form]',
                'parent'  => 'spp_portal_page_id',
            ),
        );

        foreach ( $pages as $option_key => $page ) {
            // Check if we already have a valid page.
            $existing_page_id = get_option( $option_key );
            if ( $existing_page_id && 'publish' === get_post_status( $existing_page_id ) ) {
                continue;
            }

            // Resolve parent page ID.
            $parent_id = 0;
            if ( ! empty( $page['parent'] ) && is_string( $page['parent'] ) ) {
                $parent_id = (int) get_option( $page['parent'], 0 );
            }

            // Check if a page with this slug already exists.
            $existing = get_page_by_path(
                $parent_id ? $page['slug'] : $page['slug'],
                OBJECT,
                'page'
            );

            if ( $existing && 'publish' === $existing->post_status ) {
                // Reuse existing page.
                update_option( $option_key, $existing->ID, false );
                continue;
            }

            // Create the page.
            $page_id = wp_insert_post(
                array(
                    'post_title'     => $page['title'],
                    'post_name'      => $page['slug'],
                    'post_content'   => $page['content'],
                    'post_status'    => 'publish',
                    'post_type'      => 'page',
                    'post_parent'    => $parent_id,
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                ),
                true
            );

            if ( ! is_wp_error( $page_id ) ) {
                update_option( $option_key, $page_id, false );
            }
        }
    }

    /**
     * Add default plugin options.
     */
    private static function add_default_options() {
        $default_options = array(
            'spp_environment'           => 'sandbox',
            'spp_application_id'        => '',
            'spp_access_token'          => '',
            'spp_refresh_token'         => '',
            'spp_token_expires_at'      => '',
            'spp_default_location_id'   => '',
            'spp_default_currency'      => 'USD',
            'spp_sandbox_mode'          => true,
            'spp_enable_registration'   => true,
            'spp_require_email_verify'  => false,
            'spp_max_cards_per_client'  => 5,
            'spp_allow_card_deletion'   => true,
            'spp_admin_email'           => get_option( 'admin_email' ),
            'spp_send_receipts'         => true,
            'spp_failed_payment_alerts' => true,
            'spp_webhook_signature_key' => '',
            'spp_sync_direction'        => 'bidirectional',
            'spp_sync_interval'         => 'hourly',
            'spp_default_trial_days'    => 0,
            'spp_enable_multi_location' => false,
            'spp_proration_mode'        => 'prorate_immediately',
        );

        foreach ( $default_options as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
