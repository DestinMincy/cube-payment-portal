<?php
/**
 * Admin Plan Detail page with insights.
 *
 * Shows subscription plan details, analytics, and list of subscribers.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check - ensure user has permission to manage subscriptions.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Load required classes.
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}
if ( ! class_exists( 'SPP_Subscriptions' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
}

$plan_id = sanitize_text_field( $_GET['plan_id'] );
$catalog = new SPP_Catalog();
$subscriptions_api = new SPP_Subscriptions();

// Handle subscriber actions.
$action_message = null;

if ( isset( $_POST['spp_subscription_action'] ) && wp_verify_nonce( $_POST['spp_action_nonce'], 'spp_subscription_action' ) ) {
    $action = sanitize_text_field( $_POST['spp_subscription_action'] );
    $subscription_id = sanitize_text_field( $_POST['subscription_id'] );
    
    switch ( $action ) {
        case 'pause':
            $result = $subscriptions_api->pause_subscription( $subscription_id );
            break;
        case 'resume':
            $result = $subscriptions_api->resume_subscription( $subscription_id );
            break;
        case 'cancel':
            $result = $subscriptions_api->cancel_subscription( $subscription_id );
            break;
        default:
            $result = new WP_Error( 'invalid_action', __( 'Invalid action', 'cube-payment-portal' ) );
    }
    
    if ( is_wp_error( $result ) ) {
        $action_message = array(
            'type' => 'error',
            'text' => $result->get_error_message(),
        );
    } else {
        $action_message = array(
            'type' => 'success',
            'text' => sprintf( __( 'Subscription %s successfully.', 'cube-payment-portal' ), $action . 'd' ),
        );
    }
}

// Get plan details.
$plan = $catalog->get_plan_details( $plan_id );
if ( is_wp_error( $plan ) ) {
    echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $plan->get_error_message() ) . '</p></div></div>';
    return;
}

// Get all variation IDs for this plan.
$variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();
$variation_ids = array();
foreach ( $variations as $variation ) {
    $variation_ids[] = $variation['id'];
}

// Get subscribers for this plan (by variation IDs).
$subscribers = array();
if ( ! empty( $variation_ids ) ) {
    $subscribers = SPP_Database::get_subscriptions_by_variation_ids( $variation_ids );
}

// Calculate stats for this plan.
$active_count = 0;
$paused_count = 0;
$canceled_count = 0;
$total_revenue = 0;
$total_lifetime_revenue = 0;

foreach ( $subscribers as $sub ) {
    $status = strtolower( $sub['status'] );
    if ( 'active' === $status ) {
        $active_count++;
        $total_revenue += floatval( $sub['amount'] );
    } elseif ( 'paused' === $status ) {
        $paused_count++;
    } elseif ( 'canceled' === $status ) {
        $canceled_count++;
    }
    // Calculate total lifetime (all statuses contribute to this).
    $total_lifetime_revenue += floatval( $sub['amount'] );
}

// Get subscription revenue from transactions for this plan.
global $wpdb;
$transactions_table = $wpdb->prefix . 'spp_transactions';
$subscriptions_table = $wpdb->prefix . 'spp_subscriptions';

// Build variation IDs list for SQL.
$variation_placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%s' ) );

// Get monthly subscription trend for this plan.
$monthly_trend = array();
if ( ! empty( $variation_ids ) ) {
    $monthly_trend = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, 
                COUNT(*) as new_subs,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
         FROM $subscriptions_table
         WHERE square_plan_id IN ($variation_placeholders)
         AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
         ORDER BY month ASC",
        ...$variation_ids
    ), ARRAY_A );
}

// Build chart data.
$trend_labels = array();
$trend_new = array();
for ( $i = 5; $i >= 0; $i-- ) {
    $month = gmdate( 'Y-m', strtotime( "-$i months" ) );
    $trend_labels[] = gmdate( 'M', strtotime( $month . '-01' ) );
    $trend_new[ $month ] = 0;
}
foreach ( $monthly_trend as $row ) {
    if ( isset( $trend_new[ $row['month'] ] ) ) {
        $trend_new[ $row['month'] ] = (int) $row['new_subs'];
    }
}

// Helper function to format currency.
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

$back_url = admin_url( 'admin.php?page=spp-subscriptions' );
$create_url = admin_url( 'admin.php?page=spp-subscriptions&action=create&plan_id=' . urlencode( $plan_id ) );

// Get pricing info from first variation.
$pricing_display = __( 'Variable pricing', 'cube-payment-portal' );
$pricing_type = 'RELATIVE';
if ( ! empty( $variations[0] ) ) {
    $first_var = $variations[0];
    $first_phases = $first_var['subscription_plan_variation_data']['phases'] ?? array();
    if ( ! empty( $first_phases[0] ) ) {
        $pricing_type = $first_phases[0]['pricing']['type'] ?? 'RELATIVE';
        if ( 'STATIC' === $pricing_type && isset( $first_phases[0]['pricing']['price']['amount'] ) ) {
            $amount = $first_phases[0]['pricing']['price']['amount'];
            $currency = $first_phases[0]['pricing']['price']['currency'] ?? 'USD';
            $cadence = $first_phases[0]['cadence'] ?? 'MONTHLY';
            $pricing_display = spp_format_currency( $amount / 100, $currency ) . '/' . strtolower( SPP_Catalog::format_cadence( $cadence ) );
        }
    }
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Plan Detail', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <h2 style="display: flex; align-items: center; gap: 12px; margin: 0; font-size: 24px; font-weight: 600; color: #333;">
            <a href="<?php echo esc_url( $back_url ); ?>" style="text-decoration: none; color: #1d2327;">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size: 24px; width: 24px; height: 24px;"></span>
            </a>
            <?php echo esc_html( $plan['name'] ); ?>
            <?php if ( $active_count > 0 ) : ?>
                <span style="background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500;">
                    <?php echo esc_html( sprintf( _n( '%d subscriber', '%d subscribers', $active_count, 'cube-payment-portal' ), $active_count ) ); ?>
                </span>
            <?php endif; ?>
        </h2>
        <div style="display: flex; gap: 10px;">
            <a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
                <span class="dashicons dashicons-plus-alt" style="vertical-align: middle; margin-top: -2px;"></span>
                <?php esc_html_e( 'Add Subscriber', 'cube-payment-portal' ); ?>
            </a>
            <a href="https://squareup.com/dashboard/subscriptions/plans" target="_blank" class="button">
                <?php esc_html_e( 'Edit in Square →', 'cube-payment-portal' ); ?>
            </a>
        </div>
    </div>

    <?php if ( isset( $action_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $action_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $action_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Metrics Row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 32px; font-weight: 600; color: #4caf50;"><?php echo esc_html( (string) $active_count ); ?></div>
            <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Active', 'cube-payment-portal' ); ?></div>
        </div>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 32px; font-weight: 600; color: #ff9800;"><?php echo esc_html( (string) $paused_count ); ?></div>
            <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Paused', 'cube-payment-portal' ); ?></div>
        </div>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 32px; font-weight: 600; color: #f44336;"><?php echo esc_html( (string) $canceled_count ); ?></div>
            <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></div>
        </div>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 28px; font-weight: 600; color: #0073aa;"><?php echo esc_html( spp_format_currency( $total_revenue ) ); ?></div>
            <div style="color: #666; font-size: 12px; text-transform: uppercase; margin-top: 5px;"><?php esc_html_e( 'Monthly Revenue', 'cube-payment-portal' ); ?></div>
        </div>
    </div>

    <!-- Plan Info & Chart Row -->
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
        <!-- Plan Details Card -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 25px;">
            <h3 style="margin: 0 0 20px; font-size: 14px; font-weight: 600; color: #1e3a5f;"><?php esc_html_e( 'Plan Details', 'cube-payment-portal' ); ?></h3>
            
            <div style="margin-bottom: 15px;">
                <div style="color: #999; font-size: 11px; text-transform: uppercase; margin-bottom: 3px;"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></div>
                <div style="font-size: 18px; font-weight: 600; color: #1e3a5f;">
                    <?php echo esc_html( $pricing_display ); ?>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <div style="color: #999; font-size: 11px; text-transform: uppercase; margin-bottom: 3px;"><?php esc_html_e( 'Pricing Type', 'cube-payment-portal' ); ?></div>
                <div>
                    <?php if ( 'RELATIVE' === $pricing_type ) : ?>
                        <span style="background: #fff3e0; color: #e65100; padding: 3px 10px; border-radius: 4px; font-size: 12px;"><?php esc_html_e( 'Variable', 'cube-payment-portal' ); ?></span>
                    <?php else : ?>
                        <span style="background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 4px; font-size: 12px;"><?php esc_html_e( 'Fixed', 'cube-payment-portal' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <div style="color: #999; font-size: 11px; text-transform: uppercase; margin-bottom: 3px;"><?php esc_html_e( 'Variations', 'cube-payment-portal' ); ?></div>
                <div style="font-size: 16px; font-weight: 500;"><?php echo esc_html( (string) count( $variations ) ); ?></div>
            </div>

            <div style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
                <div style="color: #999; font-size: 11px; text-transform: uppercase; margin-bottom: 3px;"><?php esc_html_e( 'Plan ID', 'cube-payment-portal' ); ?></div>
                <code style="font-size: 10px; background: #f5f5f5; padding: 4px 8px; border-radius: 4px; display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis;">
                    <?php echo esc_html( $plan_id ); ?>
                </code>
            </div>
        </div>

        <!-- Trend Chart -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 25px;">
            <h3 style="margin: 0 0 20px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Subscription Trend', 'cube-payment-portal' ); ?></h3>
            <div style="height: 180px;">
                <canvas id="planTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Plan Variations -->
    <?php if ( ! empty( $variations ) ) : ?>
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Pricing Variations', 'cube-payment-portal' ); ?></h3>
        </div>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 30%;"><?php esc_html_e( 'Name', 'cube-payment-portal' ); ?></th>
                    <th style="width: 20%;"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></th>
                    <th style="width: 20%;"><?php esc_html_e( 'Billing Cycle', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'ID', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $variations as $variation ) : 
                    $var_data = $variation['subscription_plan_variation_data'] ?? array();
                    $var_phases = $var_data['phases'] ?? array();
                    $var_price = 0;
                    $var_currency = 'USD';
                    $var_cadence = 'MONTHLY';
                    $var_pricing_type = 'RELATIVE';
                    
                    if ( ! empty( $var_phases[0] ) ) {
                        $var_cadence = $var_phases[0]['cadence'] ?? 'MONTHLY';
                        $var_pricing_type = $var_phases[0]['pricing']['type'] ?? 'RELATIVE';
                        if ( isset( $var_phases[0]['pricing']['price'] ) ) {
                            $var_price = $var_phases[0]['pricing']['price']['amount'] ?? 0;
                            $var_currency = $var_phases[0]['pricing']['price']['currency'] ?? 'USD';
                        }
                    }
                    
                    $is_deleted = ! empty( $variation['is_deleted'] );
                ?>
                    <tr <?php if ( $is_deleted ) echo 'style="opacity: 0.5;"'; ?>>
                        <td>
                            <strong><?php echo esc_html( $var_data['name'] ?? __( 'Default', 'cube-payment-portal' ) ); ?></strong>
                        </td>
                        <td>
                            <?php if ( 'RELATIVE' === $var_pricing_type ) : ?>
                                <span style="color: #666;"><?php esc_html_e( 'Variable', 'cube-payment-portal' ); ?></span>
                            <?php elseif ( $var_price > 0 ) : ?>
                                <strong><?php echo esc_html( spp_format_currency( $var_price / 100, $var_currency ) ); ?></strong>
                            <?php else : ?>
                                <span style="color: #4caf50;"><?php esc_html_e( 'Free', 'cube-payment-portal' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( SPP_Catalog::format_cadence( $var_cadence ) ); ?></td>
                        <td>
                            <?php if ( $is_deleted ) : ?>
                                <span style="background: #ffebee; color: #c62828; padding: 3px 10px; border-radius: 12px; font-size: 11px;">
                                    <?php esc_html_e( 'Deleted', 'cube-payment-portal' ); ?>
                                </span>
                            <?php else : ?>
                                <span style="background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 12px; font-size: 11px;">
                                    <?php esc_html_e( 'Active', 'cube-payment-portal' ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="font-size: 10px; color: #999;"><?php echo esc_html( substr( $variation['id'], 0, 12 ) ); ?>...</code>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Subscribers Table -->
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Subscribers', 'cube-payment-portal' ); ?></h3>
            <span style="color: #666; font-size: 13px;"><?php echo esc_html( sprintf( __( '%d total', 'cube-payment-portal' ), count( $subscribers ) ) ); ?></span>
        </div>
        
        <?php if ( empty( $subscribers ) ) : ?>
            <div style="padding: 60px 40px; text-align: center;">
                <span class="dashicons dashicons-groups" style="font-size: 48px; color: #ccc; margin-bottom: 15px; width: 48px; height: 48px;"></span>
                <h3 style="margin: 0 0 10px; color: #666;"><?php esc_html_e( 'No Subscribers Yet', 'cube-payment-portal' ); ?></h3>
                <p style="color: #999; margin: 0 0 20px;">
                    <?php esc_html_e( 'This plan has no subscriptions. Add your first subscriber to get started.', 'cube-payment-portal' ); ?>
                </p>
                <a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Add First Subscriber', 'cube-payment-portal' ); ?>
                </a>
            </div>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 25%;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                        <th scope="col" style="width: 12%;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                        <th scope="col" style="width: 15%;"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                        <th scope="col" style="width: 13%;"><?php esc_html_e( 'Started', 'cube-payment-portal' ); ?></th>
                        <th scope="col" style="width: 13%;"><?php esc_html_e( 'Next Billing', 'cube-payment-portal' ); ?></th>
                        <th scope="col" style="width: 22%;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $subscribers as $sub ) : 
                        $status = strtolower( $sub['status'] ?? 'active' );
                        $status_styles = array(
                            'active'   => array( 'bg' => '#e8f5e9', 'color' => '#2e7d32' ),
                            'paused'   => array( 'bg' => '#fff3e0', 'color' => '#e65100' ),
                            'canceled' => array( 'bg' => '#ffebee', 'color' => '#c62828' ),
                            'pending'  => array( 'bg' => '#e3f2fd', 'color' => '#1565c0' ),
                        );
                        $style = $status_styles[ $status ] ?? $status_styles['active'];
                        
                        $customer_name = ! empty( $sub['customer_name'] ) ? trim( $sub['customer_name'] ) : '';
                        if ( empty( $customer_name ) ) {
                            $customer_name = $sub['customer_email'] ?? __( 'Unknown', 'cube-payment-portal' );
                        }
                        
                        $customer_detail_url = '';
                        if ( ! empty( $sub['square_customer_id'] ) ) {
                            // Try to get customer local ID.
                            $customer_row = $wpdb->get_row( $wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}spp_customers WHERE square_customer_id = %s",
                                $sub['square_customer_id']
                            ) );
                            if ( $customer_row ) {
                                $customer_detail_url = admin_url( 'admin.php?page=spp-customers&customer_id=' . $customer_row->id );
                            }
                        }
                    ?>
                        <tr>
                            <td>
                                <?php if ( $customer_detail_url ) : ?>
                                    <a href="<?php echo esc_url( $customer_detail_url ); ?>" style="text-decoration: none; font-weight: 500;">
                                        <?php echo esc_html( $customer_name ); ?>
                                    </a>
                                <?php else : ?>
                                    <strong><?php echo esc_html( $customer_name ); ?></strong>
                                <?php endif; ?>
                                <?php if ( ! empty( $sub['customer_email'] ) && $customer_name !== $sub['customer_email'] ) : ?>
                                    <div style="color: #999; font-size: 12px;"><?php echo esc_html( $sub['customer_email'] ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="background: <?php echo esc_attr( $style['bg'] ); ?>; color: <?php echo esc_attr( $style['color'] ); ?>; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize;">
                                    <?php echo esc_html( $status ); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 500;"><?php echo esc_html( spp_format_currency( $sub['amount'] ?? 0, $sub['currency'] ?? 'USD' ) ); ?></span>
                                <span style="color: #999; font-size: 11px;">/<?php echo esc_html( strtolower( $sub['cadence'] ?? 'monthly' ) ); ?></span>
                            </td>
                            <td style="color: #666; font-size: 13px;">
                                <?php echo ! empty( $sub['start_date'] ) ? esc_html( wp_date( 'M j, Y', strtotime( $sub['start_date'] ) ) ) : '—'; ?>
                            </td>
                            <td style="color: #666; font-size: 13px;">
                                <?php echo ! empty( $sub['next_billing_date'] ) ? esc_html( wp_date( 'M j, Y', strtotime( $sub['next_billing_date'] ) ) ) : '—'; ?>
                            </td>
                            <td>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field( 'spp_subscription_action', 'spp_action_nonce' ); ?>
                                    <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub['square_subscription_id'] ); ?>">
                                    
                                    <?php if ( 'active' === $status ) : ?>
                                        <button type="submit" name="spp_subscription_action" value="pause" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Pause this subscription?', 'cube-payment-portal' ); ?>');">
                                            <?php esc_html_e( 'Pause', 'cube-payment-portal' ); ?>
                                        </button>
                                        <button type="submit" name="spp_subscription_action" value="cancel" class="button button-small" style="color: #c62828;" onclick="return confirm('<?php esc_attr_e( 'Cancel this subscription? This cannot be undone.', 'cube-payment-portal' ); ?>');">
                                            <?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?>
                                        </button>
                                    <?php elseif ( 'paused' === $status ) : ?>
                                        <button type="submit" name="spp_subscription_action" value="resume" class="button button-small button-primary">
                                            <?php esc_html_e( 'Resume', 'cube-payment-portal' ); ?>
                                        </button>
                                        <button type="submit" name="spp_subscription_action" value="cancel" class="button button-small" style="color: #c62828;" onclick="return confirm('<?php esc_attr_e( 'Cancel this subscription?', 'cube-payment-portal' ); ?>');">
                                            <?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <span style="color: #999; font-size: 12px;"><?php esc_html_e( 'No actions', 'cube-payment-portal' ); ?></span>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('planTrendChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo wp_json_encode( $trend_labels ); ?>,
                datasets: [{
                    label: '<?php esc_html_e( 'New Subscriptions', 'cube-payment-portal' ); ?>',
                    data: <?php echo wp_json_encode( array_values( $trend_new ) ); ?>,
                    borderColor: '#4caf50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<style>
@media (max-width: 900px) {
    .wrap > div[style*="grid-template-columns: 1fr 2fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
