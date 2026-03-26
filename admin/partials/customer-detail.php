<?php
/**
 * Customer Detail page with insights.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get customer ID from URL - can be local DB ID (integer) or Square customer ID (string).
$customer_id_param = isset( $_GET['customer_id'] ) ? sanitize_text_field( $_GET['customer_id'] ) : '';
$customer_id = 0;
$square_customer_id_lookup = '';

if ( $customer_id_param ) {
    // Check if it's a numeric ID (local database) or a Square customer ID (alphanumeric string).
    if ( is_numeric( $customer_id_param ) ) {
        $customer_id = intval( $customer_id_param );
    } else {
        // It's a Square customer ID - we'll look it up below.
        $square_customer_id_lookup = $customer_id_param;
    }
}

if ( ! $customer_id && empty( $square_customer_id_lookup ) ) {
    wp_die( esc_html__( 'Invalid customer ID.', 'cube-payment-portal' ) );
}

// Load sync class for link/unlink actions.
if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
}

// Handle link action.
$action_message = null;
if ( isset( $_POST['spp_link_customer'] ) && wp_verify_nonce( $_POST['spp_link_nonce'], 'spp_link_customer' ) ) {
    $wp_user_id = intval( $_POST['wp_user_id'] );
    if ( $wp_user_id ) {
        $sync = new SPP_Sync_Customers();
        $result = $sync->link_customer( $customer_id, $wp_user_id );
        if ( is_wp_error( $result ) ) {
            $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
        } else {
            $action_message = array( 'type' => 'success', 'text' => __( 'Customer linked successfully!', 'cube-payment-portal' ) );
        }
    }
}

// Handle unlink action.
if ( isset( $_POST['spp_unlink_customer'] ) && wp_verify_nonce( $_POST['spp_unlink_nonce'], 'spp_unlink_customer' ) ) {
    $sync = new SPP_Sync_Customers();
    $result = $sync->unlink_customer( $customer_id );
    if ( is_wp_error( $result ) ) {
        $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
    } else {
        $action_message = array( 'type' => 'success', 'text' => __( 'Customer unlinked successfully!', 'cube-payment-portal' ) );
    }
}

// Get customer data (refresh after potential link/unlink).
global $wpdb;
$customers_table = $wpdb->prefix . 'spp_customers';
$transactions_table = $wpdb->prefix . 'spp_transactions';
$subscriptions_table = $wpdb->prefix . 'spp_subscriptions';

// Look up customer by local ID or Square customer ID.
if ( $customer_id ) {
    $customer = $wpdb->get_row( $wpdb->prepare(
        "SELECT c.*, u.display_name as wp_display_name, u.user_email as wp_email
         FROM $customers_table c
         LEFT JOIN {$wpdb->users} u ON c.wp_user_id = u.ID
         WHERE c.id = %d",
        $customer_id
    ), ARRAY_A );
} else {
    // Look up by Square customer ID.
    $customer = $wpdb->get_row( $wpdb->prepare(
        "SELECT c.*, u.display_name as wp_display_name, u.user_email as wp_email
         FROM $customers_table c
         LEFT JOIN {$wpdb->users} u ON c.wp_user_id = u.ID
         WHERE c.square_customer_id = %s",
        $square_customer_id_lookup
    ), ARRAY_A );
    
    // Set the local customer_id for subsequent operations.
    if ( $customer ) {
        $customer_id = $customer['id'];
    }
}

if ( ! $customer ) {
    wp_die( esc_html__( 'Customer not found.', 'cube-payment-portal' ) );
}

$square_customer_id = $customer['square_customer_id'];

// Get customer stats.
$lifetime_value = $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND square_customer_id = %s",
    $square_customer_id
) );

$total_transactions = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $transactions_table WHERE status = 'completed' AND square_customer_id = %s",
    $square_customer_id
) );

$first_transaction = $wpdb->get_var( $wpdb->prepare(
    "SELECT MIN(created_at) FROM $transactions_table WHERE status = 'completed' AND square_customer_id = %s",
    $square_customer_id
) );

$last_transaction = $wpdb->get_var( $wpdb->prepare(
    "SELECT MAX(created_at) FROM $transactions_table WHERE status = 'completed' AND square_customer_id = %s",
    $square_customer_id
) );

$avg_transaction = $total_transactions > 0 ? $lifetime_value / $total_transactions : 0;

// Get subscription stats.
$active_subscriptions = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $subscriptions_table WHERE status = 'active' AND square_customer_id = %s",
    $square_customer_id
) );

$total_subscriptions = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $subscriptions_table WHERE square_customer_id = %s",
    $square_customer_id
) );

$mrr = $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM $subscriptions_table WHERE status = 'active' AND square_customer_id = %s",
    $square_customer_id
) );

// Get recent transactions.
$recent_transactions = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $transactions_table WHERE square_customer_id = %s ORDER BY created_at DESC LIMIT 10",
    $square_customer_id
), ARRAY_A );

// Get subscriptions.
$subscriptions = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $subscriptions_table WHERE square_customer_id = %s ORDER BY created_at DESC",
    $square_customer_id
), ARRAY_A );

// Get invoices for this customer.
$invoices_table = $wpdb->prefix . 'spp_invoices';
$invoices = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $invoices_table WHERE square_customer_id = %s ORDER BY created_at DESC LIMIT 10",
    $square_customer_id
), ARRAY_A );

// Invoice stats.
$pending_invoices = 0;
$total_invoiced = 0;
foreach ( $invoices as $inv ) {
    $total_invoiced += (float) ( $inv['amount'] ?? 0 );
    if ( in_array( strtolower( $inv['status'] ?? '' ), array( 'unpaid', 'pending', 'scheduled', 'partially_paid' ), true ) ) {
        $pending_invoices++;
    }
}

// Get cards on file (from Square API).
$cards = array();
if ( class_exists( 'SPP_Cards' ) || file_exists( SPP_PLUGIN_DIR . 'api/class-spp-cards.php' ) ) {
    if ( ! class_exists( 'SPP_Cards' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
    }
    $cards_api = new SPP_Cards();
    $cards_result = $cards_api->list_cards( $square_customer_id );
    if ( ! is_wp_error( $cards_result ) ) {
        $cards = $cards_result;
    }
}

// Get gift cards linked to this customer.
$gift_cards = array();
if ( file_exists( SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php' ) ) {
    if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
    }
    $gift_cards_api = new SPP_Gift_Cards();
    $gc_result = $gift_cards_api->list_gift_cards();
    if ( ! is_wp_error( $gc_result ) && ! empty( $gc_result['gift_cards'] ) ) {
        // Filter gift cards linked to this customer.
        foreach ( $gc_result['gift_cards'] as $gc ) {
            if ( ! empty( $gc['customer_ids'] ) && in_array( $square_customer_id, $gc['customer_ids'], true ) ) {
                $gift_cards[] = $gc;
            }
        }
    }
}

// Get loyalty account for this customer.
$loyalty_account = null;
$loyalty_program = null;
if ( file_exists( SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php' ) ) {
    if ( ! class_exists( 'SPP_Loyalty' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
    }
    $loyalty_api = new SPP_Loyalty();
    
    // Get the loyalty program first.
    // Note: get_program() returns the program directly, not wrapped in a 'program' key.
    $program_result = $loyalty_api->get_program();
    if ( ! is_wp_error( $program_result ) && ! empty( $program_result ) ) {
        // The result might be the program directly or have a 'program' key depending on API response.
        $loyalty_program = isset( $program_result['id'] ) ? $program_result : ( $program_result['program'] ?? null );
        
        if ( $loyalty_program ) {
            // Search for customer's loyalty account.
            $loyalty_account = $loyalty_api->get_account_by_customer( $square_customer_id );
            
            // If customer has a loyalty account, get their rewards.
            if ( $loyalty_account && ! empty( $loyalty_account['id'] ) ) {
                $rewards_result = $loyalty_api->search_rewards( array(
                    'loyalty_account_id' => $loyalty_account['id'],
                    'status' => 'ISSUED',
                ) );
                if ( ! is_wp_error( $rewards_result ) && ! empty( $rewards_result['rewards'] ) ) {
                    $loyalty_rewards = $rewards_result['rewards'];
                }
            }
        }
    }
}

// Initialize loyalty rewards array if not set.
$loyalty_rewards = $loyalty_rewards ?? array();

// Get active disputes for this customer.
// Match disputes to customers by checking if the disputed payment ID matches any of their transactions.
$customer_disputes = array();
if ( file_exists( SPP_PLUGIN_DIR . 'api/class-spp-disputes.php' ) ) {
    if ( ! class_exists( 'SPP_Disputes' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
    }
    
    // Get all payment IDs for this customer's transactions.
    $customer_payment_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT square_payment_id FROM $transactions_table WHERE square_customer_id = %s AND square_payment_id IS NOT NULL",
        $square_customer_id
    ) );
    
    if ( ! empty( $customer_payment_ids ) ) {
        // Real API call.
        $disputes_api = new SPP_Disputes();
        $disputes_result = $disputes_api->list_disputes();
        if ( ! is_wp_error( $disputes_result ) && ! empty( $disputes_result['disputes'] ) ) {
            foreach ( $disputes_result['disputes'] as $dispute ) {
                // Get the payment ID from the dispute.
                $dispute_payment_id = $dispute['disputed_payment']['payment_id'] ?? '';
                $state = $dispute['state'] ?? '';

                // Skip WON and LOST - only show active disputes.
                if ( in_array( $state, array( 'WON', 'LOST' ), true ) ) {
                    continue;
                }

                // Check if this payment ID belongs to this customer.
                if ( $dispute_payment_id && in_array( $dispute_payment_id, $customer_payment_ids, true ) ) {
                    $customer_disputes[] = $dispute;
                }
            }
        }
    }
}

// Get WP users for linking.

$wp_users = get_users( array( 'number' => 200 ) );

// Helper function.
if ( ! function_exists( 'spp_format_currency' ) ) {
    function spp_format_currency( $amount, $currency = 'USD' ) {
        $symbol = '$';
        if ( 'EUR' === $currency ) {
            $symbol = '€';
        } elseif ( 'GBP' === $currency ) {
            $symbol = '£';
        }
        return $symbol . number_format( (float) $amount, 2 );
    }
}

$customer_name = trim( $customer['given_name'] . ' ' . $customer['family_name'] );
if ( empty( $customer_name ) ) {
    $customer_name = $customer['company_name'] ?: __( 'Unnamed Customer', 'cube-payment-portal' );
}

// Calculate customer tenure.
$tenure_text = '';
if ( $first_transaction ) {
    $tenure_days = floor( ( time() - strtotime( $first_transaction ) ) / DAY_IN_SECONDS );
    if ( $tenure_days > 365 ) {
        $tenure_text = sprintf( __( '%d+ years', 'cube-payment-portal' ), floor( $tenure_days / 365 ) );
    } elseif ( $tenure_days > 30 ) {
        $tenure_text = sprintf( __( '%d months', 'cube-payment-portal' ), floor( $tenure_days / 30 ) );
    } else {
        $tenure_text = sprintf( __( '%d days', 'cube-payment-portal' ), $tenure_days );
    }
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Customer Detail', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h2 style="display: flex; align-items: center; gap: 10px; margin: 0; font-size: 24px; font-weight: 600; color: #333;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers' ) ); ?>" style="text-decoration: none; color: #1d2327;">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size: 20px; width: 20px; height: 20px;"></span>
            </a>
            <?php echo esc_html( $customer_name ); ?>
            <?php if ( $active_subscriptions > 0 ) : ?>
                <span style="background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500;">
                    <?php esc_html_e( 'Subscriber', 'cube-payment-portal' ); ?>
                </span>
            <?php endif; ?>
        </h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&action=create&customer_id=' . $customer['id'] ) ); ?>" class="button" style="display: flex; align-items: center; gap: 6px;">
                <span class="dashicons dashicons-media-text" style="font-size: 16px; width: 16px; height: 16px;"></span>
                <?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-subscriptions&action=create&customer_id=' . $customer['id'] ) ); ?>" class="button" style="display: flex; align-items: center; gap: 6px;">
                <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
                <?php esc_html_e( 'Add Subscription', 'cube-payment-portal' ); ?>
            </a>
            <button type="button" class="button button-primary" style="display: flex; align-items: center; gap: 6px;" disabled title="<?php esc_attr_e( 'Coming soon', 'cube-payment-portal' ); ?>">
                <span class="dashicons dashicons-calendar-alt" style="font-size: 16px; width: 16px; height: 16px;"></span>
                <?php esc_html_e( 'Book Appointment', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <?php if ( $action_message ) : ?>
        <div class="notice notice-<?php echo esc_attr( $action_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $action_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Customer Overview Row -->
    <div style="display: grid; grid-template-columns: 300px 1fr; gap: 20px; margin-top: 20px;">
        
        <!-- Left Column: Customer Info -->
        <div>
            <!-- Customer Card -->
            <div style="background: #fff; border-radius: 8px; padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; margin: 0 auto 15px; display: flex; align-items: center; justify-content: center;">
                        <span style="color: #fff; font-size: 32px; font-weight: 600;">
                            <?php echo esc_html( strtoupper( substr( $customer_name, 0, 1 ) ) ); ?>
                        </span>
                    </div>
                    <h2 style="margin: 0 0 5px; font-size: 18px;"><?php echo esc_html( $customer_name ); ?></h2>
                    <?php if ( $customer['company_name'] && $customer_name !== $customer['company_name'] ) : ?>
                        <div style="color: #666; font-size: 13px;"><?php echo esc_html( $customer['company_name'] ); ?></div>
                    <?php endif; ?>
                </div>

                <div style="border-top: 1px solid #eee; padding-top: 20px;">
                    <?php if ( $customer['email'] ) : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <span class="dashicons dashicons-email" style="color: #666;"></span>
                            <a href="mailto:<?php echo esc_attr( $customer['email'] ); ?>" style="text-decoration: none;">
                                <?php echo esc_html( $customer['email'] ); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ( $customer['phone'] ) : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <span class="dashicons dashicons-phone" style="color: #666;"></span>
                            <a href="tel:<?php echo esc_attr( $customer['phone'] ); ?>" style="text-decoration: none;">
                                <?php echo esc_html( $this->format_phone_number( $customer['phone'] ) ); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ( $tenure_text ) : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <span class="dashicons dashicons-calendar-alt" style="color: #666;"></span>
                            <span><?php esc_html_e( 'Customer for', 'cube-payment-portal' ); ?> <?php echo esc_html( $tenure_text ); ?></span>
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                        <span class="dashicons dashicons-id" style="color: #666;"></span>
                        <span style="font-size: 11px; font-family: monospace; color: #999;" title="<?php echo esc_attr( $square_customer_id ); ?>">
                            <?php echo esc_html( substr( $square_customer_id, 0, 16 ) ); ?>...
                        </span>
                    </div>
                </div>

                <!-- WordPress Link Section -->
                <div style="border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px;">
                    <h4 style="margin: 0 0 12px; font-size: 13px; color: #666; text-transform: uppercase;"><?php esc_html_e( 'WordPress Account', 'cube-payment-portal' ); ?></h4>
                    
                    <?php if ( $customer['wp_user_id'] ) : ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <span class="dashicons dashicons-yes-alt" style="color: #4caf50;"></span>
                            <div>
                                <div style="font-weight: 500;"><?php echo esc_html( $customer['wp_display_name'] ); ?></div>
                                <div style="font-size: 12px; color: #666;"><?php echo esc_html( $customer['wp_email'] ); ?></div>
                            </div>
                        </div>
                        <form method="post" action="">
                            <?php wp_nonce_field( 'spp_unlink_customer', 'spp_unlink_nonce' ); ?>
                            <button type="submit" name="spp_unlink_customer" class="button button-small" style="width: 100%;" onclick="return confirm('<?php esc_attr_e( 'Unlink this WordPress account?', 'cube-payment-portal' ); ?>');">
                                <?php esc_html_e( 'Unlink Account', 'cube-payment-portal' ); ?>
                            </button>
                        </form>
                    <?php else : ?>
                        <div style="color: #999; margin-bottom: 15px;">
                            <span class="dashicons dashicons-minus"></span>
                            <?php esc_html_e( 'Not linked to any WordPress user', 'cube-payment-portal' ); ?>
                        </div>
                        <form method="post" action="">
                            <?php wp_nonce_field( 'spp_link_customer', 'spp_link_nonce' ); ?>
                            <select name="wp_user_id" style="width: 100%; margin-bottom: 10px;">
                                <option value=""><?php esc_html_e( 'Select WordPress user...', 'cube-payment-portal' ); ?></option>
                                <?php foreach ( $wp_users as $wp_user ) : ?>
                                    <option value="<?php echo esc_attr( (string) $wp_user->ID ); ?>">
                                        <?php echo esc_html( $wp_user->display_name . ' (' . $wp_user->user_email . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="spp_link_customer" class="button button-primary button-small" style="width: 100%;">
                                <?php esc_html_e( 'Link Account', 'cube-payment-portal' ); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cards on File -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Payment Methods', 'cube-payment-portal' ); ?></h3>
                <?php if ( ! empty( $cards ) ) : ?>
                    <?php foreach ( $cards as $card ) : ?>
                        <div style="display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                            <span class="dashicons dashicons-credit-card" style="color: #666;"></span>
                            <div>
                                <div style="font-weight: 500;"><?php echo esc_html( $card['card_brand'] ?? 'Card' ); ?> •••• <?php echo esc_html( $card['last_4'] ?? '****' ); ?></div>
                                <div style="font-size: 12px; color: #999;">
                                    <?php esc_html_e( 'Expires', 'cube-payment-portal' ); ?> 
                                    <?php echo esc_html( ( $card['exp_month'] ?? '00' ) . '/' . ( $card['exp_year'] ?? '00' ) ); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        <span class="dashicons dashicons-credit-card" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No cards on file', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Gift Cards -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 20px;">
                <h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-money-alt" style="color: #9c27b0;"></span>
                    <?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?>
                </h3>
                <?php if ( ! empty( $gift_cards ) ) : ?>
                    <?php foreach ( $gift_cards as $gc ) : ?>
                        <?php
                        $gc_balance = ( $gc['balance_money']['amount'] ?? 0 ) / 100;
                        $gc_state = $gc['state'] ?? 'UNKNOWN';
                        $state_color = 'ACTIVE' === $gc_state ? '#4caf50' : '#999';
                        ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                            <div>
                                <div style="font-family: monospace; font-size: 12px; color: #666;">
                                    <?php echo esc_html( $gc['gan'] ?? '****' ); ?>
                                </div>
                                <div style="font-size: 12px;">
                                    <span style="color: <?php echo esc_attr( $state_color ); ?>;">●</span>
                                    <?php echo esc_html( ucfirst( strtolower( $gc_state ) ) ); ?>
                                </div>
                            </div>
                            <div style="font-weight: 600; color: #1e3a5f;">
                                <?php echo esc_html( spp_format_currency( $gc_balance ) ); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-gift-cards' ) ); ?>" style="display: block; text-align: center; margin-top: 12px; font-size: 13px; text-decoration: none;">
                        <?php esc_html_e( 'Manage Gift Cards →', 'cube-payment-portal' ); ?>
                    </a>
                <?php else : ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        <span class="dashicons dashicons-money-alt" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No gift cards linked', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Loyalty Program -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 20px;">
                <h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-star-filled" style="color: #ff9800;"></span>
                    <?php esc_html_e( 'Loyalty Program', 'cube-payment-portal' ); ?>
                </h3>
                <?php if ( $loyalty_account ) : ?>
                    <?php
                    $points_balance = $loyalty_account['balance'] ?? 0;
                    $lifetime_points = $loyalty_account['lifetime_points'] ?? 0;
                    $terminology = $loyalty_program['terminology']['one'] ?? 'point';
                    $terminology_plural = $loyalty_program['terminology']['other'] ?? 'points';
                    ?>
                    <div style="background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: #fff; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 15px;">
                        <div style="font-size: 36px; font-weight: 700; line-height: 1.2;"><?php echo esc_html( number_format( $points_balance ) ); ?></div>
                        <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; margin-top: 6px; letter-spacing: 0.5px;">
                            <?php echo esc_html( $points_balance === 1 ? $terminology : $terminology_plural ); ?> <?php esc_html_e( 'Available', 'cube-payment-portal' ); ?>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;"><?php esc_html_e( 'Lifetime Earned', 'cube-payment-portal' ); ?></span>
                        <span style="font-weight: 500;"><?php echo esc_html( number_format( $lifetime_points ) ); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;"><?php esc_html_e( 'Member Since', 'cube-payment-portal' ); ?></span>
                        <span style="font-weight: 500;">
                            <?php echo esc_html( wp_date( 'M j, Y', strtotime( $loyalty_account['created_at'] ?? 'now' ) ) ); ?>
                        </span>
                    </div>
                    
                    <!-- Active Rewards -->
                    <?php if ( ! empty( $loyalty_rewards ) ) : ?>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0;">
                            <h4 style="margin: 0 0 10px; font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase;">
                                <?php esc_html_e( 'Active Rewards', 'cube-payment-portal' ); ?>
                                <span style="background: #4caf50; color: #fff; padding: 2px 6px; border-radius: 8px; font-size: 10px; margin-left: 6px;">
                                    <?php echo esc_html( (string) count( $loyalty_rewards ) ); ?>
                                </span>
                            </h4>
                            <?php foreach ( $loyalty_rewards as $reward ) : ?>
                                <?php
                                // Find the matching reward tier from the program.
                                $reward_tier_id = $reward['reward_tier_id'] ?? '';
                                $reward_name = $reward_tier_id;
                                $reward_points = 0;
                                if ( ! empty( $loyalty_program['reward_tiers'] ) ) {
                                    foreach ( $loyalty_program['reward_tiers'] as $tier ) {
                                        if ( $tier['id'] === $reward_tier_id ) {
                                            $reward_name = $tier['name'] ?? 'Reward';
                                            $reward_points = $tier['points'] ?? 0;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div style="display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f5f5f5;">
                                    <span class="dashicons dashicons-awards" style="color: #ff9800; font-size: 16px; width: 16px; height: 16px;"></span>
                                    <div style="flex: 1;">
                                        <div style="font-size: 13px; font-weight: 500;"><?php echo esc_html( $reward_name ); ?></div>
                                        <div style="font-size: 11px; color: #999;">
                                            <?php esc_html_e( 'Expires:', 'cube-payment-portal' ); ?>
                                            <?php echo esc_html( wp_date( 'M j, Y', strtotime( $reward['expires_at'] ?? '+1 year' ) ) ); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-loyalty' ) ); ?>" style="display: block; text-align: center; margin-top: 12px; font-size: 13px; text-decoration: none;">
                        <?php esc_html_e( 'Manage Loyalty →', 'cube-payment-portal' ); ?>
                    </a>
                <?php elseif ( $loyalty_program ) : ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        <span class="dashicons dashicons-star-empty" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 10px;"></span>
                        <p style="margin: 0 0 15px;"><?php esc_html_e( 'Not enrolled in loyalty program', 'cube-payment-portal' ); ?></p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-loyalty' ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Enroll Customer', 'cube-payment-portal' ); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <div style="text-align: center; padding: 20px; color: #999;">
                        <span class="dashicons dashicons-star-empty" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No loyalty program configured', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Stats & Activity -->

        <div>
            <!-- Stats Row -->
            <?php
            // Calculate gift card total balance.
            $gift_card_balance = 0;
            foreach ( $gift_cards as $gc ) {
                if ( 'ACTIVE' === ( $gc['state'] ?? '' ) ) {
                    $gift_card_balance += ( $gc['balance_money']['amount'] ?? 0 ) / 100;
                }
            }
            
            // Get loyalty points.
            $loyalty_points = $loyalty_account ? ( $loyalty_account['balance'] ?? 0 ) : 0;
            $loyalty_terminology = $loyalty_program['terminology']['other'] ?? 'Points';
            
            // Get dispute count.
            $dispute_count = count( $customer_disputes );
            ?>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 28px; font-weight: 600; color: #1e3a5f;"><?php echo esc_html( spp_format_currency( $lifetime_value ) ); ?></div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Lifetime Value', 'cube-payment-portal' ); ?></div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 28px; font-weight: 600; color: #4caf50;"><?php echo esc_html( spp_format_currency( $mrr ) ); ?></div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Monthly Recurring', 'cube-payment-portal' ); ?></div>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 28px; font-weight: 600; color: #ff9800;"><?php echo esc_html( spp_format_currency( $avg_transaction ) ); ?></div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Avg. Transaction', 'cube-payment-portal' ); ?></div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 20px;">
                <div style="background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 22px; font-weight: 600; color: #0073aa;"><?php echo esc_html( $total_transactions ); ?></div>
                    <div style="color: #666; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Transactions', 'cube-payment-portal' ); ?></div>
                </div>
                <div style="background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 22px; font-weight: 600; color: #0073aa;"><?php echo esc_html( $active_subscriptions ); ?></div>
                    <div style="color: #666; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Subscriptions', 'cube-payment-portal' ); ?></div>
                </div>
                <?php if ( $pending_invoices > 0 ) : ?>
                <div style="background: #fff8e1; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; border: 1px solid #ffecb3;">
                    <div style="font-size: 22px; font-weight: 600; color: #ff9800;"><?php echo esc_html( $pending_invoices ); ?></div>
                    <div style="color: #ff9800; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Pending Invoices', 'cube-payment-portal' ); ?></div>
                </div>
                <?php else : ?>
                <div style="background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 22px; font-weight: 600; color: #0073aa;"><?php echo esc_html( count( $invoices ) ); ?></div>
                    <div style="color: #666; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Invoices', 'cube-payment-portal' ); ?></div>
                </div>
                <?php endif; ?>
                <div style="background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 22px; font-weight: 600; color: #9c27b0;"><?php echo esc_html( spp_format_currency( $gift_card_balance ) ); ?></div>
                    <div style="color: #666; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?></div>
                </div>
                <?php if ( $dispute_count > 0 ) : ?>
                <div style="background: #ffebee; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; border: 1px solid #ffcdd2;">
                    <div style="font-size: 22px; font-weight: 600; color: #dc3232; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <span class="dashicons dashicons-warning" style="font-size: 18px; width: 18px; height: 18px;"></span>
                        <?php echo esc_html( $dispute_count ); ?>
                    </div>
                    <div style="color: #dc3232; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php esc_html_e( 'Disputes', 'cube-payment-portal' ); ?></div>
                </div>
                <?php else : ?>
                <div style="background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                    <div style="font-size: 22px; font-weight: 600; color: #ff9800;"><?php echo esc_html( $loyalty_points ); ?></div>
                    <div style="color: #666; font-size: 11px; text-transform: uppercase; margin-top: 4px;"><?php echo esc_html( $loyalty_terminology ); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Active Disputes -->
            <?php if ( ! empty( $customer_disputes ) ) : ?>
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; border-left: 4px solid #dc3232;">
                <h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; color: #dc3232;">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Active Disputes', 'cube-payment-portal' ); ?>
                    <span style="background: #dc3232; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px;">
                        <?php echo esc_html( (string) count( $customer_disputes ) ); ?>
                    </span>
                </h3>
                <?php foreach ( $customer_disputes as $dispute ) : ?>
                    <?php
                    $dispute_amount = ( $dispute['amount_money']['amount'] ?? 0 ) / 100;
                    $dispute_reason = str_replace( '_', ' ', $dispute['reason'] ?? 'Unknown' );
                    $dispute_state = $dispute['state'] ?? '';
                    $dispute_state_label = $dispute['state_label'] ?? str_replace( '_', ' ', ucwords( strtolower( $dispute_state ) ) );
                    
                    // Status badge colors.
                    $state_colors = array(
                        'EVIDENCE_REQUIRED' => array( 'bg' => '#ffebee', 'text' => '#dc3232' ),
                        'INQUIRY_EVIDENCE_REQUIRED' => array( 'bg' => '#fff8e1', 'text' => '#ff9800' ),
                        'PROCESSING' => array( 'bg' => '#e3f2fd', 'text' => '#0073aa' ),
                    );
                    $state_color = $state_colors[ $dispute_state ] ?? array( 'bg' => '#f0f0f0', 'text' => '#666' );
                    ?>
                    <div style="padding: 12px 0; border-bottom: 1px solid #f0f0f0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; font-size: 15px;"><?php echo esc_html( spp_format_currency( $dispute_amount ) ); ?></span>
                            <span style="font-size: 11px; padding: 3px 10px; border-radius: 12px; background: <?php echo esc_attr( $state_color['bg'] ); ?>; color: <?php echo esc_attr( $state_color['text'] ); ?>; font-weight: 500;">
                                <?php echo esc_html( $dispute_state_label ); ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 12px; color: #666;">
                            <span><?php echo esc_html( ucwords( strtolower( $dispute_reason ) ) ); ?></span>
                            <?php if ( ! empty( $dispute['due_at'] ) ) : ?>
                                <span><?php esc_html_e( 'Due:', 'cube-payment-portal' ); ?> <?php echo esc_html( wp_date( 'M j, Y', strtotime( $dispute['due_at'] ) ) ); ?></span>
                            <?php else : ?>
                                <span style="color: #999;"><?php esc_html_e( 'Awaiting decision', 'cube-payment-portal' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-disputes' ) ); ?>" style="display: block; text-align: center; margin-top: 12px; font-size: 13px; text-decoration: none; color: #dc3232; font-weight: 500;">
                    <?php esc_html_e( 'View Disputes →', 'cube-payment-portal' ); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Subscriptions Section -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Subscriptions', 'cube-payment-portal' ); ?></h3>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-subscriptions&action=create' ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Add Subscription', 'cube-payment-portal' ); ?>
                    </a>
                </div>
                
                <?php if ( ! empty( $subscriptions ) ) : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #f0f0f0;">
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Plan', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Started', 'cube-payment-portal' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $subscriptions as $sub ) : ?>
                                <tr style="border-bottom: 1px solid #f0f0f0;">
                                    <td style="padding: 12px 0;">
                                        <strong><?php echo esc_html( $sub['plan_name'] ?: __( 'Unknown Plan', 'cube-payment-portal' ) ); ?></strong>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <?php echo esc_html( spp_format_currency( $sub['amount'], $sub['currency'] ) ); ?>
                                        <span style="color: #999;">/<?php echo esc_html( strtolower( $sub['cadence'] ?? 'monthly' ) ); ?></span>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <?php 
                                        $status = strtolower( $sub['status'] );
                                        $status_colors = array(
                                            'active' => '#4caf50',
                                            'canceled' => '#f44336',
                                            'paused' => '#ff9800',
                                            'pending' => '#9e9e9e',
                                        );
                                        $color = $status_colors[ $status ] ?? '#666';
                                        ?>
                                        <span style="color: <?php echo esc_attr( $color ); ?>;">●</span>
                                        <?php echo esc_html( ucfirst( $status ) ); ?>
                                    </td>
                                    <td style="padding: 12px 0; color: #666;">
                                        <?php echo esc_html( wp_date( 'M j, Y', strtotime( $sub['created_at'] ) ) ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div style="text-align: center; padding: 30px; color: #999;">
                        <span class="dashicons dashicons-update" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No subscriptions yet', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Invoices Section -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 14px; font-weight: 600;">
                        <?php esc_html_e( 'Invoices', 'cube-payment-portal' ); ?>
                        <?php if ( $pending_invoices > 0 ) : ?>
                            <span style="background: #ff9800; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; margin-left: 8px;">
                                <?php echo esc_html( $pending_invoices ); ?> <?php esc_html_e( 'pending', 'cube-payment-portal' ); ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&action=create&customer_id=' . $customer['id'] ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?>
                    </a>
                </div>
                
                <?php if ( ! empty( $invoices ) ) : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #f0f0f0;">
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Invoice', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $invoices as $inv ) : ?>
                                <?php
                                $inv_status = strtolower( $inv['status'] ?? 'draft' );
                                $inv_status_colors = array(
                                    'paid'           => '#4caf50',
                                    'unpaid'         => '#ff9800',
                                    'pending'        => '#ff9800',
                                    'scheduled'      => '#2196f3',
                                    'partially_paid' => '#ff9800',
                                    'draft'          => '#9e9e9e',
                                    'canceled'       => '#f44336',
                                    'refunded'       => '#9c27b0',
                                );
                                $inv_color = $inv_status_colors[ $inv_status ] ?? '#666';
                                $inv_number = $inv['invoice_number'] ?? substr( $inv['square_invoice_id'] ?? '', 0, 8 );
                                $inv_title = $inv['title'] ?? '';
                                ?>
                                <tr style="border-bottom: 1px solid #f0f0f0;">
                                    <td style="padding: 12px 0;">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&invoice_id=' . intval( $inv['id'] ) ) ); ?>" style="text-decoration: none;">
                                            <strong>#<?php echo esc_html( $inv_number ); ?></strong>
                                            <?php if ( ! empty( $inv_title ) ) : ?>
                                                - <?php echo esc_html( $inv_title ); ?>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <?php echo esc_html( spp_format_currency( $inv['amount'] ?? 0, $inv['currency'] ?? 'USD' ) ); ?>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <span style="color: <?php echo esc_attr( $inv_color ); ?>;">●</span>
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $inv_status ) ) ); ?>
                                    </td>
                                    <td style="padding: 12px 0; color: #666;">
                                        <?php if ( ! empty( $inv['due_date'] ) ) : ?>
                                            <?php echo esc_html( wp_date( 'M j, Y', strtotime( $inv['due_date'] ) ) ); ?>
                                        <?php else : ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&search=' . urlencode( $customer['email'] ?? '' ) ) ); ?>" style="display: block; text-align: center; margin-top: 12px; font-size: 13px; text-decoration: none;">
                        <?php esc_html_e( 'View All Invoices →', 'cube-payment-portal' ); ?>
                    </a>
                <?php else : ?>
                    <div style="text-align: center; padding: 30px; color: #999;">
                        <span class="dashicons dashicons-media-text" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No invoices yet', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Transaction History -->
            <div style="background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Transaction History', 'cube-payment-portal' ); ?></h3>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-transactions&search=' . urlencode( $customer['email'] ?? '' ) ) ); ?>" style="font-size: 13px; text-decoration: none;">
                        <?php esc_html_e( 'View All →', 'cube-payment-portal' ); ?>
                    </a>
                </div>
                
                <?php if ( ! empty( $recent_transactions ) ) : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #f0f0f0;">
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Payment Method', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: right; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                                <th style="text-align: left; padding: 10px 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_transactions as $tx ) : ?>
                                <tr style="border-bottom: 1px solid #f0f0f0;">
                                    <td style="padding: 12px 0;">
                                        <div><?php echo esc_html( wp_date( 'M j, Y', strtotime( $tx['created_at'] ) ) ); ?></div>
                                        <div style="font-size: 11px; color: #999;"><?php echo esc_html( wp_date( 'g:i a', strtotime( $tx['created_at'] ) ) ); ?></div>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <?php if ( $tx['card_brand'] && $tx['card_last_four'] ) : ?>
                                            <?php echo esc_html( $tx['card_brand'] ); ?> •••• <?php echo esc_html( $tx['card_last_four'] ); ?>
                                        <?php else : ?>
                                            <span style="color: #999;"><?php echo esc_html( ucfirst( $tx['source_type'] ?? 'card' ) ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 0; text-align: right; font-weight: 600; color: #1e3a5f;">
                                        <?php echo esc_html( spp_format_currency( $tx['amount'], $tx['currency'] ) ); ?>
                                    </td>
                                    <td style="padding: 12px 0;">
                                        <?php 
                                        $status = strtolower( $tx['status'] );
                                        $status_colors = array(
                                            'completed' => '#4caf50',
                                            'pending' => '#ff9800',
                                            'failed' => '#f44336',
                                            'refunded' => '#9c27b0',
                                        );
                                        $color = $status_colors[ $status ] ?? '#666';
                                        ?>
                                        <span style="color: <?php echo esc_attr( $color ); ?>;">●</span>
                                        <?php echo esc_html( ucfirst( $status ) ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div style="text-align: center; padding: 30px; color: #999;">
                        <span class="dashicons dashicons-money-alt" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 10px;"></span>
                        <p style="margin: 0;"><?php esc_html_e( 'No transactions yet', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width: 900px) {
    .wrap > div[style*="grid-template-columns: 300px"] {
        grid-template-columns: 1fr !important;
    }
    .wrap div[style*="grid-template-columns: repeat(4"] {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
</style>
