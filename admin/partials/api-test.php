<?php
/**
 * API Test page for debugging Square connection.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check - ensure user has permission to run API tests.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

$oauth = new SPP_OAuth();
$is_connected = $oauth->is_connected();

// Handle test actions.
$test_result = null;
$test_type = '';

if ( isset( $_POST['spp_test_action'] ) && wp_verify_nonce( $_POST['spp_test_nonce'], 'spp_api_test' ) ) {
    $test_type = sanitize_text_field( $_POST['spp_test_action'] );
    
    switch ( $test_type ) {
        case 'test_connection':
            $client = new SPP_Square_Client();
            $test_result = $client->get( 'locations' );
            break;

        case 'test_locations':
            $client = new SPP_Square_Client();
            $test_result = $client->get( 'locations' );
            break;

        case 'test_create_customer':
            $customers = new SPP_Customers();
            $test_result = $customers->create_customer( array(
                'first_name' => 'Test',
                'last_name'  => 'Customer',
                'email'      => 'test' . time() . '@example.com',
            ) );
            break;

        case 'test_list_customers':
            $client = new SPP_Square_Client();
            $test_result = $client->get( 'customers' );
            break;

        case 'test_catalog':
            $client = new SPP_Square_Client();
            // Request both SUBSCRIPTION_PLAN and SUBSCRIPTION_PLAN_VARIATION types
            $test_result = $client->get( 'catalog/list?types=SUBSCRIPTION_PLAN,SUBSCRIPTION_PLAN_VARIATION' );
            break;

        case 'test_catalog_variations':
            // Debug: Get a specific variation to see its structure
            $client = new SPP_Square_Client();
            $variation_id = isset( $_POST['variation_id'] ) ? sanitize_text_field( $_POST['variation_id'] ) : 'P2GXB5MA5S75JAYKGMTHQ6ZO';
            $test_result = $client->get( 'catalog/object/' . $variation_id . '?include_related_objects=true' );
            break;

        case 'test_merchant':
            $client = new SPP_Square_Client();
            $test_result = $client->get( 'merchants/me' );
            break;

        case 'test_list_subscriptions':
            $client = new SPP_Square_Client();
            // Get the default location ID - required for SearchSubscriptions
            $location_id = $client->get_location_id();
            
            if ( empty( $location_id ) ) {
                // If no default location, try to get the first location
                $locations = $client->get( 'locations' );
                if ( ! is_wp_error( $locations ) && ! empty( $locations['locations'] ) ) {
                    $location_id = $locations['locations'][0]['id'];
                }
            }
            
            if ( ! empty( $location_id ) ) {
                // Use the search endpoint with location filter (required by Square API)
                $test_result = $client->post( 'subscriptions/search', array(
                    'query' => array(
                        'filter' => array(
                            'location_ids' => array( $location_id ),
                        ),
                    ),
                    'limit' => 50, // Increased limit to catch all subscriptions
                ) );
            } else {
                $test_result = new WP_Error( 'no_location', 'No location ID configured. Please set a default location in Settings.' );
            }
            break;

        case 'test_get_subscription':
            // Retrieve a specific subscription by ID to verify status
            $client = new SPP_Square_Client();
            $subscription_id = isset( $_POST['subscription_id'] ) 
                ? sanitize_text_field( $_POST['subscription_id'] ) 
                : '37e2e316-7550-4dd7-af2e-0dbf77be088b';
            $test_result = $client->get( 'subscriptions/' . $subscription_id );
            break;

        case 'test_sync_subscriptions':
            // Run the subscription sync and return detailed results
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
            $sync = new SPP_Sync_Subscriptions();
            $sync_result = $sync->sync_from_square();
            
            // Also get the debug log content
            $debug_log = '';
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if ( file_exists( $log_file ) ) {
                $lines = file( $log_file );
                // Get the last 50 lines related to SPP
                $spp_lines = array_filter( $lines, function( $line ) {
                    return strpos( $line, 'SPP' ) !== false; // Changed from 'SPP Subscription Sync' to 'SPP' to catch all
                });
                $debug_log = implode( '', array_slice( $spp_lines, -50 ) );
            }
            
            $test_result = array(
                'sync_result' => $sync_result,
                'recent_log' => $debug_log,
            );
            break;

        case 'test_list_team_members':
            if ( ! class_exists( 'SPP_Team' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
            }
            $team_api = new SPP_Team();
            $result = $team_api->search_team_members( array(
                'filter' => array( 'status' => 'ACTIVE' )
            ) );
            
            if ( is_wp_error( $result ) ) {
                $test_result = $result; 
            } else {
                $members = $result['team_members'] ?? array();
                // Just return raw members to verify API connection
                $test_result = $members;
            }
            break;



        case 'test_get_transactions_db':
            // Test direct database query for transactions to debug display issues
            $transactions = SPP_Database::get_transactions( array( 'limit' => 5 ) );
            global $wpdb;
            $last_error = $wpdb->last_error;
            $last_query = $wpdb->last_query;
            $count = SPP_Database::get_transactions_count();
            
            $test_result = array(
                'count' => $count,
                'transactions' => $transactions,
                'last_error' => $last_error,
                'last_query' => $last_query,
            );
            break;

        case 'test_list_invoices':
            $client = new SPP_Square_Client();
            // Get the default location ID
            $location_id = $client->get_location_id();
            
            if ( empty( $location_id ) ) {
                // If no default location, try to get the first location
                $locations = $client->get( 'locations' );
                if ( ! is_wp_error( $locations ) && ! empty( $locations['locations'] ) ) {
                    $location_id = $locations['locations'][0]['id'];
                }
            }
            
            if ( ! empty( $location_id ) ) {
                // Use ListInvoices endpoint with location_id query param
                $test_result = $client->get( 'invoices?location_id=' . $location_id . '&limit=10' );
            } else {
                $test_result = new WP_Error( 'no_location', 'No location ID configured. Please set a default location in Settings.' );
            }
            break;

        case 'test_list_payments':
            $client = new SPP_Square_Client();
            // Payments API uses GET with query params - limit to recent 10
            $test_result = $client->get( 'payments?limit=10&sort_order=DESC' );
            break;

        case 'test_force_db_update':
            // Force database migration
            if ( ! defined( 'SPP_PLUGIN_DIR' ) ) {
                $test_result = new WP_Error( 'plugin_dir_missing', 'SPP_PLUGIN_DIR constant not defined.' );
                break;
            }
            
            require_once SPP_PLUGIN_DIR . 'includes/class-spp-activator.php';
            
            try {
                SPP_Activator::create_tables(); // This includes migrate_tables() call
                
                // Double check if columns exist now
                global $wpdb;
                $table_name = $wpdb->prefix . 'spp_transactions';
                $columns = $wpdb->get_results( "DESCRIBE `$table_name`", ARRAY_A );
                $fields = wp_list_pluck( $columns, 'Field' );
                
                $has_plan_id = in_array( 'square_plan_id', $fields );
                $has_sub_id = in_array( 'square_subscription_id', $fields );
                
                $test_result = array(
                    'message' => 'Database update routine executed.',
                    'verification' => array(
                        'table' => $table_name,
                        'has_square_plan_id' => $has_plan_id ? 'Yes' : 'No',
                        'has_square_subscription_id' => $has_sub_id ? 'Yes' : 'No',
                    ),
                    'tables_created' => true
                );
            } catch ( Exception $e ) {
                $test_result = new WP_Error( 'db_update_error', $e->getMessage() );
            }
            break;

        case 'test_get_transactions_db':
            // Test direct database query for transactions to debug display issues
            $transactions = SPP_Database::get_transactions( array( 'limit' => 5 ) );
            global $wpdb;
            $last_error = $wpdb->last_error;
            $last_query = $wpdb->last_query;
            $count = SPP_Database::get_transactions_count();
            
            $test_result = array(
                'count' => $count,
                'transactions' => $transactions,
                'last_error' => $last_error,
                'last_query' => $last_query,
            );
            break;

        case 'test_create_subscription':
            $client = new SPP_Square_Client();
            $location_id = $client->get_location_id();
            
            // Get first available customer.
            $customers_response = $client->get( 'customers?limit=1' );
            if ( is_wp_error( $customers_response ) || empty( $customers_response['customers'] ) ) {
                $test_result = new WP_Error( 'no_customers', 'No customers found. Please create a test customer first.' );
                break;
            }
            $customer_id = $customers_response['customers'][0]['id'];
            
            // CHECK FOR CARDS
            $cards_response = $client->get( 'cards?customer_id=' . urlencode( $customer_id ) );
            $card_id = '';
            
            if ( ! is_wp_error( $cards_response ) && ! empty( $cards_response['cards'] ) ) {
                $card_id = $cards_response['cards'][0]['id'];
            } else {
                // If no card, CREATE ONE using sandbox nonce
                $create_card_body = array(
                    'idempotency_key' => wp_generate_uuid4(),
                    'source_id'       => 'cnon:card-nonce-ok',
                    'card'            => array(
                        'customer_id' => $customer_id,
                    ),
                );
                $card_result = $client->post( 'cards', $create_card_body );
                if ( ! is_wp_error( $card_result ) && ! empty( $card_result['card']['id'] ) ) {
                    $card_id = $card_result['card']['id'];
                } else {
                    $test_result = new WP_Error( 'create_card_failed', 'Failed to create test card for customer. ' . ($card_result->get_error_message() ?? '') );
                    break;
                }
            }
            
            // Get first subscription plan variation.
            $catalog_response = $client->get( 'catalog/list?types=SUBSCRIPTION_PLAN' );
            if ( is_wp_error( $catalog_response ) || empty( $catalog_response['objects'] ) ) {
                $test_result = new WP_Error( 'no_plans', 'No subscription plans found. Please create a plan in Square Dashboard first.' );
                break;
            }
            
            $plan_variation_id = '';
            
            // Loop through plans to find one with STATIC pricing (avoid RELATIVE pricing error)
            foreach ( $catalog_response['objects'] as $p_obj ) {
                $phases = $p_obj['subscription_plan_data']['phases'] ?? array();
                $is_complex = false;
                
                foreach ( $phases as $phase ) {
                    // Check if pricing type is RELATIVE
                    if ( isset( $phase['pricing']['type'] ) && $phase['pricing']['type'] === 'RELATIVE' ) {
                        $is_complex = true;
                        break;
                    }
                }
                
                if ( ! $is_complex ) {
                    $vars = $p_obj['subscription_plan_data']['subscription_plan_variations'] ?? array();
                    if ( ! empty( $vars ) ) {
                        $plan_variation_id = $vars[0]['id'];
                        break; // Found a valid simple plan
                    }
                }
            }
            
            if ( empty( $plan_variation_id ) ) {
                $test_result = new WP_Error( 'no_simple_plan', 'Could not find a simple fixed-price subscription plan. The available plans appear to use RELATIVE pricing which requires complex setup not supported by this test.' );
                break;
            }
            
            // Create subscription using plan_variation_id AND card_id.
            $subscription_body = array(
                'idempotency_key' => wp_generate_uuid4(),
                'location_id'     => $location_id,
                'plan_variation_id' => $plan_variation_id,
                'customer_id'     => $customer_id,
                'card_id'         => $card_id, // Pass the card ID to make it ACTIVE immediately
                'start_date'      => wp_date( 'Y-m-d' ),
            );
            
            $test_result = $client->post( 'subscriptions', $subscription_body );
            break;

        case 'test_list_loyalty_program':
            $loyalty = new SPP_Loyalty();
            $test_result = $loyalty->get_program();
            break;

        case 'test_create_loyalty_account':
            $loyalty = new SPP_Loyalty();
            $program = $loyalty->get_program();
            if ( is_wp_error( $program ) ) {
                $test_result = $program;
                break;
            }
            $program_id = $program['id'];

            // Get first available customer.
            $client = new SPP_Square_Client();
            $customers_response = $client->get( 'customers?limit=1' );
            if ( is_wp_error( $customers_response ) || empty( $customers_response['customers'] ) ) {
                $test_result = new WP_Error( 'no_customers', 'No customers found. Please create a test customer first.' );
                break;
            }
            $customer_id = $customers_response['customers'][0]['id'];
            $customer_phone = $customers_response['customers'][0]['phone_number'] ?? '';

            // If no phone number, provide a valid test one (Square Sandbox requirement: +1<area>555<digits>)
            if ( empty( $customer_phone ) ) {
                $customer_phone = '+16295551234';
            }

            $test_result = $loyalty->create_account( $program_id, $customer_id, $customer_phone );
            break;

        case 'test_list_loyalty_accounts':
            $loyalty = new SPP_Loyalty();
            $test_result = $loyalty->search_accounts();
            break;

        case 'test_list_gift_cards':
            $gift_cards = new SPP_Gift_Cards();
            $test_result = $gift_cards->list_gift_cards();
            break;

        case 'test_create_gift_card':
            $gift_cards = new SPP_Gift_Cards();
            $client = new SPP_Square_Client();
            $location_id = $client->get_location_id();
            
            if ( empty( $location_id ) ) {
                $locations = $client->get( 'locations' );
                if ( ! is_wp_error( $locations ) && ! empty( $locations['locations'] ) ) {
                    $location_id = $locations['locations'][0]['id'];
                }
            }

            if ( ! empty( $location_id ) ) {
                $test_result = $gift_cards->create_gift_card( $location_id );
            } else {
                $test_result = new WP_Error( 'no_location', 'No location ID configured.' );
            }
            break;

        case 'test_accumulate_points':
            $loyalty = new SPP_Loyalty();
            $accounts_response = $loyalty->search_accounts();
            
            if ( is_wp_error( $accounts_response ) || empty( $accounts_response['loyalty_accounts'] ) ) {
                $test_result = new WP_Error( 'no_accounts', 'No loyalty accounts found. Please create one first.' );
                break;
            }
            
            $account_id = $accounts_response['loyalty_accounts'][0]['id'];
            $test_result = $loyalty->accumulate_points( $account_id, 10 );
            break;

        case 'test_activate_gift_card':
            $gift_cards = new SPP_Gift_Cards();
            $cards_response = $gift_cards->list_gift_cards();
            
            if ( is_wp_error( $cards_response ) || empty( $cards_response['gift_cards'] ) ) {
                $test_result = new WP_Error( 'no_cards', 'No gift cards found. Please create one first.' );
                break;
            }
            
            $gift_card_id = $cards_response['gift_cards'][0]['id'];
            $test_result = $gift_cards->activate_gift_card( $gift_card_id, 5000 ); // $50.00
            break;

        case 'test_sync_bookings':
            if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
            }
            $syncer = new SPP_Sync_Bookings();
            // Sync next 30 days by default
            $test_result = $syncer->sync_from_square();
            break;

        case 'test_list_bookings':
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }
            $bookings = new SPP_Bookings();
            $client = new SPP_Square_Client();
            $location_id = $client->get_location_id();
            
            $query_args = array(
                'limit'        => 5,
                'start_at_min' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-30 days' ) )
            );
            
            if ( ! empty( $location_id ) ) {
                $query_args['location_id'] = $location_id;
            }
            
            // Fetch from API
            $api_result = $bookings->list_bookings( $query_args );
            
            // Check DB
            global $wpdb;
            $db_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}spp_bookings" );
            $latest_db = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}spp_bookings ORDER BY created_at DESC LIMIT 3" );
            
            $test_result = array(
                'used_location_id' => $location_id ? $location_id : 'None (All Locations)',
                'api_response' => $api_result,
                'db_count' => $db_count,
                'latest_db_entries' => $latest_db
            );
            break;

        case 'test_list_booking_services':
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }
            $bookings = new SPP_Bookings();
            $test_result = $bookings->list_services();
            break;

        case 'test_list_team_profiles':
            if ( ! class_exists( 'SPP_Bookings' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
            }
            $bookings = new SPP_Bookings();
            $client = new SPP_Square_Client();
            $location_id = $client->get_location_id();
            
            if ( empty( $location_id ) ) {
                $test_result = new WP_Error( 'no_location', 'No location ID configured.' );
            } else {
                $test_result = $bookings->list_team_member_booking_profiles( true, $location_id );
            }
            break;
    }
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Square API Test', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Square API Test', 'cube-payment-portal' ); ?></h2>

    <!-- Connection Status -->
    <div class="spp-admin-card">
        <h2><?php esc_html_e( 'Connection Status', 'cube-payment-portal' ); ?></h2>
        
        <?php if ( $is_connected ) : ?>
            <div class="notice notice-success inline" style="margin: 0;">
                <p><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> 
                    <strong><?php esc_html_e( 'Connected to Square', 'cube-payment-portal' ); ?></strong>
                </p>
            </div>
            
            <p style="margin-top: 15px;">
                <strong><?php esc_html_e( 'Environment:', 'cube-payment-portal' ); ?></strong> 
                <?php echo esc_html( get_option( 'spp_environment', 'sandbox' ) === 'sandbox' ? 'Sandbox (Testing)' : 'Production' ); ?>
            </p>
            
            <?php 
            $sandbox_token = get_option( 'spp_sandbox_access_token', '' );
            if ( ! empty( $sandbox_token ) ) : 
            ?>
                <p>
                    <strong><?php esc_html_e( 'Using:', 'cube-payment-portal' ); ?></strong> 
                    <?php esc_html_e( 'Direct Sandbox Access Token', 'cube-payment-portal' ); ?>
                </p>
            <?php endif; ?>
            
        <?php else : ?>
            <div class="notice notice-error inline" style="margin: 0;">
                <p><span class="dashicons dashicons-warning" style="color: #dc3232;"></span> 
                    <strong><?php esc_html_e( 'Not Connected', 'cube-payment-portal' ); ?></strong>
                </p>
            </div>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-settings' ) ); ?>" class="button">
                    <?php esc_html_e( 'Go to Settings', 'cube-payment-portal' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php if ( $is_connected ) : ?>

    <!-- Test Buttons -->
    <div class="spp-admin-card" style="margin-top: 20px;">
        <h2><?php esc_html_e( 'API Tests', 'cube-payment-portal' ); ?></h2>
        <p><?php esc_html_e( 'Click a button to test the Square API connection.', 'cube-payment-portal' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'spp_api_test', 'spp_test_nonce' ); ?>
            
            <h3><?php esc_html_e( 'Basic Connection Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_connection" class="button button-primary">
                    <?php esc_html_e( 'Test Connection', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_force_db_update" class="button button-secondary">
                    <?php esc_html_e( 'Force DB Update', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_locations" class="button">
                    <?php esc_html_e( 'List Locations', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_merchant" class="button">
                    <?php esc_html_e( 'Get Merchant Info', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Customer API Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_list_customers" class="button">
                    <?php esc_html_e( 'List Customers', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_create_customer" class="button">
                    <?php esc_html_e( 'Create Test Customer', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Catalog & Subscription Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_catalog" class="button">
                    <?php esc_html_e( 'List Plans & Variations', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_catalog_variations" class="button">
                    <?php esc_html_e( 'Get Variation Details', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_list_subscriptions" class="button">
                    <?php esc_html_e( 'List Subscriptions', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_get_subscription" class="button">
                    <?php esc_html_e( 'Get Subscription by ID', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_sync_subscriptions" class="button button-secondary">
                    <?php esc_html_e( 'Run Subscription Sync', 'cube-payment-portal' ); ?>
                </button>
                
                <button type="submit" name="spp_test_action" value="test_create_subscription" class="button button-secondary" onclick="return confirm('This will create a test subscription using the first customer and first plan. Continue?');">
                    <?php esc_html_e( 'Create Test Subscription', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Invoice Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_list_invoices" class="button">
                    <?php esc_html_e( 'List Invoices', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Payment Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_list_payments" class="button">
                    <?php esc_html_e( 'List Payments', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_get_transactions_db" class="button button-secondary">
                    <?php esc_html_e( 'Debug DB Transactions', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Bookings API Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_sync_bookings" class="button button-primary">
                    <?php esc_html_e( 'Sync Bookings (30 Days)', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_list_bookings" class="button">
                    <?php esc_html_e( 'List Bookings', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_list_team_members" class="button">
                    <?php esc_html_e( 'List Team Members', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_list_booking_services" class="button">
                    <?php esc_html_e( 'List Services', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_list_team_profiles" class="button">
                    <?php esc_html_e( 'List Team Profiles', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Loyalty API Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_list_loyalty_program" class="button">
                    <?php esc_html_e( 'List Loyalty Program', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_list_loyalty_accounts" class="button">
                    <?php esc_html_e( 'List Loyalty Accounts', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_create_loyalty_account" class="button button-secondary" onclick="return confirm('This will create/retrieve a loyalty account for the first customer. Continue?');">
                    <?php esc_html_e( 'Create Loyalty Account', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_accumulate_points" class="button button-secondary">
                    <?php esc_html_e( 'Accumulate 10 Points', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <h3><?php esc_html_e( 'Gift Card API Tests', 'cube-payment-portal' ); ?></h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                <button type="submit" name="spp_test_action" value="test_list_gift_cards" class="button">
                    <?php esc_html_e( 'List Gift Cards', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_create_gift_card" class="button button-secondary" onclick="return confirm('This will create a new digital gift card. Continue?');">
                    <?php esc_html_e( 'Create Digital Gift Card', 'cube-payment-portal' ); ?>
                </button>
                <button type="submit" name="spp_test_action" value="test_activate_gift_card" class="button button-secondary">
                    <?php esc_html_e( 'Activate Gift Card ($50)', 'cube-payment-portal' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Test Results -->
    <?php if ( $test_result !== null ) : ?>
    <div class="spp-admin-card" style="margin-top: 20px;">
        <h2>
            <?php 
            printf( 
                esc_html__( 'Result: %s', 'cube-payment-portal' ), 
                esc_html( ucwords( str_replace( '_', ' ', $test_type ) ) ) 
            ); 
            ?>
        </h2>
        
        <?php if ( is_wp_error( $test_result ) ) : ?>
            <div class="notice notice-error inline" style="margin: 0 0 15px 0;">
                <p><strong><?php esc_html_e( 'Error:', 'cube-payment-portal' ); ?></strong> 
                    <?php echo esc_html( $test_result->get_error_message() ); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="notice notice-success inline" style="margin: 0 0 15px 0;">
                <p><strong><?php esc_html_e( 'Success!', 'cube-payment-portal' ); ?></strong></p>
            </div>
        <?php endif; ?>
        
        <h3><?php esc_html_e( 'Response Data:', 'cube-payment-portal' ); ?></h3>
        <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 500px;">
<?php 
if ( is_wp_error( $test_result ) ) {
    echo esc_html( wp_json_encode( array(
        'error' => $test_result->get_error_code(),
        'message' => $test_result->get_error_message(),
        'data' => $test_result->get_error_data(),
    ), JSON_PRETTY_PRINT ) );
} else {
    echo esc_html( wp_json_encode( $test_result, JSON_PRETTY_PRINT ) );
}
?>
        </pre>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <!-- API Quick Reference -->
    <div class="spp-admin-card" style="margin-top: 20px;">
        <h2><?php esc_html_e( 'Available API Classes', 'cube-payment-portal' ); ?></h2>
        
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Class', 'cube-payment-portal' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'cube-payment-portal' ); ?></th>
                    <th><?php esc_html_e( 'Key Methods', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>SPP_Payments</code></td>
                    <td><?php esc_html_e( 'Process payments and refunds', 'cube-payment-portal' ); ?></td>
                    <td><code>create_payment()</code>, <code>create_refund()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Cards</code></td>
                    <td><?php esc_html_e( 'Save and manage cards on file', 'cube-payment-portal' ); ?></td>
                    <td><code>create_card()</code>, <code>list_cards()</code>, <code>delete_card()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Customers</code></td>
                    <td><?php esc_html_e( 'Manage Square customers', 'cube-payment-portal' ); ?></td>
                    <td><code>create_customer()</code>, <code>ensure_customer_for_user()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Subscriptions</code></td>
                    <td><?php esc_html_e( 'Manage recurring subscriptions', 'cube-payment-portal' ); ?></td>
                    <td><code>create_subscription()</code>, <code>pause_subscription()</code>, <code>cancel_subscription()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Invoices</code></td>
                    <td><?php esc_html_e( 'Create and send invoices', 'cube-payment-portal' ); ?></td>
                    <td><code>create_invoice()</code>, <code>publish_invoice()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Bookings</code></td>
                    <td><?php esc_html_e( 'Manage appointments and bookings', 'cube-payment-portal' ); ?></td>
                    <td><code>list_bookings()</code>, <code>create_booking()</code>, <code>search_availability()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Loyalty</code></td>
                    <td><?php esc_html_e( 'Manage loyalty programs and accounts', 'cube-payment-portal' ); ?></td>
                    <td><code>get_program()</code>, <code>create_account()</code>, <code>accumulate_points()</code></td>
                </tr>
                <tr>
                    <td><code>SPP_Gift_Cards</code></td>
                    <td><?php esc_html_e( 'Manage gift cards and activities', 'cube-payment-portal' ); ?></td>
                    <td><code>list_gift_cards()</code>, <code>create_gift_card()</code>, <code>activate_gift_card()</code></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
