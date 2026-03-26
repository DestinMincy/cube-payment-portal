<?php
/**
 * Admin Subscription Plans page with insights.
 *
 * Shows list of subscription plans from Square Catalog with analytics.
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

// Check if creating a new subscription (must be before plan_id check).
if ( isset( $_GET['action'] ) && 'create' === $_GET['action'] ) {
    include SPP_PLUGIN_DIR . 'admin/partials/create-subscription.php';
    return;
}

// Check if viewing plan detail.
if ( isset( $_GET['plan_id'] ) ) {
    include SPP_PLUGIN_DIR . 'admin/partials/plan-detail.php';
    return;
}

// Load required classes.
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}

$catalog = new SPP_Catalog();

// Handle sync action.
$sync_message = null;
if ( isset( $_POST['spp_sync_plans'] ) && wp_verify_nonce( $_POST['spp_sync_nonce'], 'spp_sync_plans' ) ) {
    // Clear cached plans to force refresh.
    delete_transient( 'spp_subscription_plans' );
    
    // Also sync subscriptions from Square to local database.
    if ( ! class_exists( 'SPP_Sync_Subscriptions' ) ) {
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
    }
    $sync = new SPP_Sync_Subscriptions();
    $sync_result = $sync->sync_from_square();
    
    if ( is_wp_error( $sync_result ) ) {
        $sync_message = array(
            'type' => 'warning',
            'text' => sprintf( 
                __( 'Plans refreshed but subscription sync failed: %s', 'cube-payment-portal' ), 
                $sync_result->get_error_message() 
            ),
        );
    } else {
        $synced_count = $sync_result['synced'] ?? 0;
        $sync_message = array(
            'type' => 'success',
            'text' => sprintf( 
                __( 'Plans refreshed and %d subscription(s) synced from Square.', 'cube-payment-portal' ), 
                $synced_count 
            ),
        );
    }
}

// Get subscription plans from Square.
$plans = $catalog->get_subscription_plans();
if ( is_wp_error( $plans ) ) {
    $plans_error = $plans->get_error_message();
    $plans = array();
} else {
    $plans_error = null;
}

// Get subscriber counts per plan variation from local database.
$subscriber_stats = SPP_Database::get_subscribers_per_plan();

// Build a map of variation IDs to their subscriber counts/revenue.
$variation_subscribers = array();
$variation_revenue = array();
foreach ( $subscriber_stats as $var_id => $stat ) {
    $variation_subscribers[ $var_id ] = $stat['subscribers'] ?? 0;
    $variation_revenue[ $var_id ] = $stat['revenue'] ?? 0;
}

// Aggregate subscriber counts by parent plan (sum all variations).
$subscriber_counts = array();
$plan_revenue = array();
foreach ( $plans as $plan ) {
    $plan_id = $plan['id'];
    $variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();
    
    $total_subs = 0;
    $total_rev = 0;
    
    foreach ( $variations as $variation ) {
        $var_id = $variation['id'] ?? '';
        $total_subs += $variation_subscribers[ $var_id ] ?? 0;
        $total_rev += $variation_revenue[ $var_id ] ?? 0;
    }
    
    $subscriber_counts[ $plan_id ] = $total_subs;
    $plan_revenue[ $plan_id ] = $total_rev;
}

// Calculate overall stats.
$total_plans = count( $plans );
$total_active_subscribers = array_sum( $subscriber_counts );
$total_mrr = array_sum( $plan_revenue );

// Get additional metrics from database.
global $wpdb;
$subscriptions_table = $wpdb->prefix . 'spp_subscriptions';

// Get subscription status breakdown.
$status_breakdown = $wpdb->get_results(
    "SELECT status, COUNT(*) as count FROM $subscriptions_table GROUP BY status",
    ARRAY_A
);

$status_counts = array(
    'active' => 0,
    'canceled' => 0,
    'paused' => 0,
    'pending' => 0,
);
foreach ( $status_breakdown as $row ) {
    $status = strtolower( $row['status'] );
    if ( isset( $status_counts[ $status ] ) ) {
        $status_counts[ $status ] = (int) $row['count'];
    }
}

// New subscriptions this month.
$new_this_month = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $subscriptions_table WHERE created_at >= %s",
    gmdate( 'Y-m-01 00:00:00' )
) );

// Canceled this month.
$canceled_this_month = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $subscriptions_table WHERE status = 'canceled' AND canceled_at >= %s",
    gmdate( 'Y-m-01 00:00:00' )
) );

// Average revenue per subscriber.
$avg_arps = $total_active_subscribers > 0 ? $total_mrr / $total_active_subscribers : 0;

// Recent subscription activity.
$recent_subscriptions = $wpdb->get_results(
    "SELECT s.*, 
            COALESCE(CONCAT(c.given_name, ' ', c.family_name), c.email) as customer_name,
            c.email as customer_email,
            c.phone as customer_phone
     FROM $subscriptions_table s
     LEFT JOIN {$wpdb->prefix}spp_customers c ON s.square_customer_id = c.square_customer_id
     ORDER BY s.created_at DESC
     LIMIT 5",
    ARRAY_A
);

// Get subscription trend for last 6 months.
$monthly_trend = $wpdb->get_results(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
     FROM $subscriptions_table
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY month ASC",
    ARRAY_A
);

$trend_labels = array();
$trend_data = array();
for ( $i = 5; $i >= 0; $i-- ) {
    $month = gmdate( 'Y-m', strtotime( "-$i months" ) );
    $trend_labels[] = gmdate( 'M', strtotime( $month . '-01' ) );
    $trend_data[ $month ] = 0;
}
foreach ( $monthly_trend as $row ) {
    if ( isset( $trend_data[ $row['month'] ] ) ) {
        $trend_data[ $row['month'] ] = (int) $row['count'];
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
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Subscriptions', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Subscriptions', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <form method="post" style="margin: 0;">
                <?php wp_nonce_field( 'spp_sync_plans', 'spp_sync_nonce' ); ?>
                <button type="submit" name="spp_sync_plans" class="spp-button">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Plans', 'cube-payment-portal' ); ?>
                </button>
            </form>
            <a href="https://squareup.com/dashboard/subscriptions/plans" target="_blank" class="spp-button">
                <span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Manage in Square', 'cube-payment-portal' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-subscriptions&action=create' ) ); ?>" class="spp-button">
                <span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Add Subscription', 'cube-payment-portal' ); ?>
            </a>
        </div>
    </div>

    <?php if ( isset( $sync_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $sync_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $sync_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $plans_error ) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html( $plans_error ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Key Metrics Row -->
    <div class="spp-metrics-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Active Subscribers', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #4caf50;"><?php echo esc_html( $status_counts['active'] ); ?></div>
                </div>
                <div style="background: #e8f5e9; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-groups" style="color: #4caf50; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Monthly Revenue', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #0073aa;"><?php echo esc_html( spp_format_currency( $total_mrr ) ); ?></div>
                </div>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-chart-line" style="color: #2196f3; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'New This Month', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #9c27b0;"><?php echo esc_html( $new_this_month ); ?></div>
                </div>
                <div style="background: #f3e5f5; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-plus-alt" style="color: #9c27b0; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Avg. Per Subscriber', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #ff9800;"><?php echo esc_html( spp_format_currency( $avg_arps ) ); ?></div>
                </div>
                <div style="background: #fff3e0; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-chart-bar" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #f44336;"><?php echo esc_html( $status_counts['canceled'] ); ?></div>
                </div>
                <div style="background: #ffebee; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-dismiss" style="color: #f44336; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
        <!-- Subscription Trend Chart -->
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'New Subscriptions Trend', 'cube-payment-portal' ); ?></h3>
            <div style="height: 200px;">
                <canvas id="subscriptionTrendChart"></canvas>
            </div>
        </div>

        <!-- Status Breakdown -->
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Subscription Status', 'cube-payment-portal' ); ?></h3>
            <div style="height: 200px; display: flex; align-items: center; justify-content: center;">
                <?php if ( array_sum( $status_counts ) > 0 ) : ?>
                    <canvas id="statusChart"></canvas>
                <?php else : ?>
                    <div style="text-align: center; color: #999;">
                        <span class="dashicons dashicons-chart-pie" style="font-size: 36px; width: 36px; height: 36px;"></span>
                        <p style="margin: 10px 0 0;"><?php esc_html_e( 'No data yet', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>



    <!-- Two Column Layout -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
        <!-- Plans Table -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Subscription Plans', 'cube-payment-portal' ); ?></h3>
                <span style="color: #666; font-size: 13px;"><?php echo esc_html( sprintf( __( '%d plans', 'cube-payment-portal' ), $total_plans ) ); ?></span>
            </div>
            
            <?php if ( empty( $plans ) ) : ?>
                <div style="padding: 60px 40px; text-align: center;">
                    <span class="dashicons dashicons-clipboard" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></span>
                    <h3 style="margin: 0 0 10px;"><?php esc_html_e( 'No Plans Found', 'cube-payment-portal' ); ?></h3>
                    <p style="color: #666; margin: 0 0 20px;">
                        <?php esc_html_e( 'Create subscription plans in Square to get started.', 'cube-payment-portal' ); ?>
                    </p>
                    <a href="https://squareup.com/dashboard/subscriptions/plans" target="_blank" class="button button-primary">
                        <?php esc_html_e( 'Create in Square →', 'cube-payment-portal' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 40%;"><?php esc_html_e( 'Plan', 'cube-payment-portal' ); ?></th>
                            <th style="width: 25%;"><?php esc_html_e( 'Pricing', 'cube-payment-portal' ); ?></th>
                            <th style="width: 15%; text-align: center;"><?php esc_html_e( 'Subscribers', 'cube-payment-portal' ); ?></th>
                            <th style="width: 20%; text-align: right;"><?php esc_html_e( 'Revenue', 'cube-payment-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $plans as $plan ) : 
                            $plan_id = $plan['id'];
                            $subscribers = $subscriber_counts[ $plan_id ] ?? 0;
                            $revenue = $plan_revenue[ $plan_id ] ?? 0;
                            $detail_url = admin_url( 'admin.php?page=spp-subscriptions&plan_id=' . urlencode( $plan_id ) );
                            $variations_count = count( $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array() );
                        ?>
                            <tr style="cursor: pointer;" onclick="window.location='<?php echo esc_url( $detail_url ); ?>'">
                                <td>
                                    <a href="<?php echo esc_url( $detail_url ); ?>" style="text-decoration: none; font-weight: 500;">
                                        <?php echo esc_html( $plan['name'] ); ?>
                                    </a>
                                    <div style="font-size: 12px; color: #999;">
                                        <?php echo esc_html( sprintf( _n( '%d variation', '%d variations', $variations_count, 'cube-payment-portal' ), $variations_count ) ); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $pricing_type = $plan['pricing_type'] ?? 'STATIC';
                                    if ( 'RELATIVE' === $pricing_type ) : 
                                    ?>
                                        <span style="color: #666; font-size: 13px;"><?php esc_html_e( 'Variable', 'cube-payment-portal' ); ?></span>
                                    <?php elseif ( ! empty( $plan['price_amount'] ) ) : ?>
                                        <span style="font-weight: 500;"><?php echo esc_html( spp_format_currency( $plan['price_amount'] / 100, $plan['price_currency'] ) ); ?></span>
                                        <span style="color: #999;">/<?php echo esc_html( strtolower( SPP_Catalog::format_cadence( $plan['cadence'] ) ) ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #4caf50;"><?php esc_html_e( 'Free', 'cube-payment-portal' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ( $subscribers > 0 ) : ?>
                                        <span style="background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500;">
                                            <?php echo esc_html( $subscribers ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color: #ccc;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ( $revenue > 0 ) : ?>
                                        <span style="font-weight: 600; color: #1e3a5f;"><?php echo esc_html( spp_format_currency( $revenue ) ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #ccc;">$0.00</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee;">
                <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Recent Activity', 'cube-payment-portal' ); ?></h3>
            </div>
            
            <?php if ( ! empty( $recent_subscriptions ) ) : ?>
                <div style="padding: 0;">
                    <?php foreach ( $recent_subscriptions as $sub ) : 
                        $status = strtolower( $sub['status'] );
                        $status_colors = array(
                            'active' => '#4caf50',
                            'canceled' => '#f44336',
                            'paused' => '#ff9800',
                            'pending' => '#9e9e9e',
                        );
                        $color = $status_colors[ $status ] ?? '#666';
                    ?>
                        <div style="padding: 15px 20px; border-bottom: 1px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <?php 
                                $customer_name_display = ! empty( $sub['customer_name'] ) ? trim( $sub['customer_name'] ) : __( 'Unknown', 'cube-payment-portal' );
                                if ( ! empty( $sub['square_customer_id'] ) ) :
                                    $customer_url = admin_url( 'admin.php?page=spp-customers&customer_id=' . urlencode( $sub['square_customer_id'] ) );
                                ?>
                                    <a href="<?php echo esc_url( $customer_url ); ?>" class="spp-customer-link" style="text-decoration: none; font-weight: 500; font-size: 13px;">
                                        <?php echo esc_html( $customer_name_display ); ?>
                                        <div class="spp-customer-tooltip spp-tooltip-left">
                                            <?php if ( ! empty( $sub['customer_email'] ) ) : ?>
                                                <div class="tooltip-row"><span class="dashicons dashicons-email"></span> <?php echo esc_html( $sub['customer_email'] ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $sub['customer_phone'] ) ) : ?>
                                                <div class="tooltip-row"><span class="dashicons dashicons-phone"></span> <?php echo esc_html( $this->format_phone_number( $sub['customer_phone'] ) ); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php else : ?>
                                    <div style="font-weight: 500; font-size: 13px;"><?php echo esc_html( $customer_name_display ); ?></div>
                                <?php endif; ?>
                                <div style="font-size: 12px; color: #999;"><?php echo esc_html( ! empty( $sub['plan_name'] ) ? $sub['plan_name'] : __( 'Unknown Plan', 'cube-payment-portal' ) ); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: <?php echo esc_attr( $color ); ?>; font-size: 12px; font-weight: 500;">● <?php echo esc_html( ucfirst( $status ) ); ?></div>
                                <div style="font-size: 11px; color: #999;"><?php echo esc_html( human_time_diff( strtotime( $sub['created_at'] ), time() ) ); ?> <?php esc_html_e( 'ago', 'cube-payment-portal' ); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div style="padding: 40px 20px; text-align: center; color: #999;">
                    <span class="dashicons dashicons-update" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 10px;"></span>
                    <p style="margin: 0;"><?php esc_html_e( 'No recent activity', 'cube-payment-portal' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Subscription Trend Chart
    const trendCtx = document.getElementById('subscriptionTrendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: <?php echo wp_json_encode( $trend_labels ); ?>,
                datasets: [{
                    label: '<?php esc_html_e( 'New Subscriptions', 'cube-payment-portal' ); ?>',
                    data: <?php echo wp_json_encode( array_values( $trend_data ) ); ?>,
                    backgroundColor: 'rgba(76, 175, 80, 0.8)',
                    borderRadius: 4
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

    // Status Doughnut Chart
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        const statusData = <?php echo wp_json_encode( array_values( $status_counts ) ); ?>;
        if (statusData.some(v => v > 0)) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['<?php esc_html_e( 'Active', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Paused', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?>'],
                    datasets: [{
                        data: statusData,
                        backgroundColor: ['#4caf50', '#f44336', '#ff9800', '#9e9e9e'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 10, usePointStyle: true, font: { size: 11 } } }
                    },
                    cutout: '65%'
                }
            });
        }
    }
});
</script>

<style>
@media (max-width: 1200px) {
    .wrap > div[style*="grid-template-columns: 2fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
