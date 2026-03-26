<?php
/**
 * Admin Dashboard page.
 *
 * Displays key metrics, charts, and insights similar to Square Dashboard.
 *
 * @package CubePaymentPortal
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check - ensure user has permission to view admin dashboard.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

$oauth = new SPP_OAuth();
$is_connected = $oauth->is_connected();

// Get current date range (this month).
$current_month_start = gmdate( 'Y-m-01 00:00:00' );
$current_month_end = gmdate( 'Y-m-t 23:59:59' );
$last_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( '-1 month' ) );
$last_month_end = gmdate( 'Y-m-t 23:59:59', strtotime( '-1 month' ) );

// Fetch dashboard metrics.
global $wpdb;
$transactions_table = $wpdb->prefix . 'spp_transactions';
$subscriptions_table = $wpdb->prefix . 'spp_subscriptions';
$customers_table = $wpdb->prefix . 'spp_customers';

// This month's revenue.
$this_month_revenue = $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s AND created_at <= %s",
    $current_month_start,
    $current_month_end
) );

// Last month's revenue (for comparison).
$last_month_revenue = $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s AND created_at <= %s",
    $last_month_start,
    $last_month_end
) );

// Active subscriptions.
$active_subscriptions = $wpdb->get_var( "SELECT COUNT(*) FROM $subscriptions_table WHERE status = 'active'" );

// Monthly Recurring Revenue (MRR) from active subscriptions.
$mrr = $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM $subscriptions_table WHERE status = 'active'" );

// Revenue change percentage.
$revenue_change = 0;
if ( $last_month_revenue > 0 ) {
    $revenue_change = ( ( $this_month_revenue - $last_month_revenue ) / $last_month_revenue ) * 100;
}

// Get daily revenue for the last 30 days (for chart).
$daily_revenue = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as count
     FROM $transactions_table 
     WHERE status = 'completed' AND created_at >= %s
     GROUP BY DATE(created_at)
     ORDER BY date ASC",
    gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) )
), ARRAY_A );

// Build chart data arrays.
$chart_labels = array();
$chart_revenue = array();
$chart_count = array();

// Fill in all 30 days (including days with no transactions).
for ( $i = 29; $i >= 0; $i-- ) {
    $date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
    $chart_labels[] = gmdate( 'M j', strtotime( $date ) );
    $chart_revenue[ $date ] = 0;
    $chart_count[ $date ] = 0;
}

foreach ( $daily_revenue as $day ) {
    if ( isset( $chart_revenue[ $day['date'] ] ) ) {
        $chart_revenue[ $day['date'] ] = (float) $day['revenue'];
        $chart_count[ $day['date'] ] = (int) $day['count'];
    }
}

// Get recent transactions.
$recent_transactions = $wpdb->get_results(
    "SELECT t.*, 
            COALESCE(c.given_name, '') as first_name,
            COALESCE(c.family_name, '') as last_name,
            c.email as customer_email,
            c.phone as customer_phone
     FROM $transactions_table t
     LEFT JOIN $customers_table c ON t.square_customer_id = c.square_customer_id
     ORDER BY t.created_at DESC
     LIMIT 5",
    ARRAY_A
);

// Get subscription status breakdown.
$subscription_breakdown = $wpdb->get_results(
    "SELECT status, COUNT(*) as count FROM $subscriptions_table GROUP BY status",
    ARRAY_A
);

$sub_status_counts = array(
    'active' => 0,
    'canceled' => 0,
    'paused' => 0,
    'pending' => 0,
);
foreach ( $subscription_breakdown as $row ) {
    $status = strtolower( $row['status'] );
    if ( isset( $sub_status_counts[ $status ] ) ) {
        $sub_status_counts[ $status ] = (int) $row['count'];
    }
}

// Get top plans by subscriber count.
$top_plans = $wpdb->get_results(
    "SELECT plan_name, COUNT(*) as subscribers, SUM(amount) as revenue
     FROM $subscriptions_table 
     WHERE status = 'active' AND plan_name IS NOT NULL AND plan_name != ''
     GROUP BY plan_name
     ORDER BY subscribers DESC
     LIMIT 5",
    ARRAY_A
);

// Get Invoice Stats
$invoice_stats = $this->get_dashboard_invoice_stats();

// Get Dispute Stats
$dispute_stats = $this->get_dashboard_dispute_stats();

// Get Recent Invoices
$recent_invoices = $this->get_dashboard_recent_invoices();

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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Cube Payment Portal', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">

    <!-- Top Header & Actions -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Cube Payment Portal', 'cube-payment-portal' ); ?></h2>
            <?php if ( $is_connected ) : ?>
                <div class="connection-status connected" title="<?php esc_attr_e( 'Connected to Square', 'cube-payment-portal' ); ?>">
                     <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Connected', 'cube-payment-portal' ); ?>
                </div>
            <?php else : ?>
                <div class="connection-status disconnected">
                     <span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Not Connected', 'cube-payment-portal' ); ?>
                     <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-settings' ) ); ?>" style="margin-left: 8px; text-decoration: underline; color: inherit;"><?php esc_html_e( 'Connect', 'cube-payment-portal' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Right Quick Actions -->
        <div style="display: flex; gap: 8px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&action=create' ) ); ?>" class="spp-button">
                <span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Invoice', 'cube-payment-portal' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-subscriptions&action=create' ) ); ?>" class="spp-button">
                <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Subscription', 'cube-payment-portal' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers' ) ); ?>" class="spp-button icon-only" title="<?php esc_attr_e( 'Manage Customers', 'cube-payment-portal' ); ?>">
                <span class="dashicons dashicons-admin-users"></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-settings' ) ); ?>" class="spp-button icon-only" title="<?php esc_attr_e( 'Settings', 'cube-payment-portal' ); ?>">
                <span class="dashicons dashicons-admin-settings"></span>
            </a>
        </div>
    </div>

    <!-- Alert Banner (Urgent Disputes) -->
    <?php if ( ! empty( $dispute_stats['recent_disputes'] ) ) : ?>
        <div class="spp-alert-banner">
            <div class="banner-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="banner-content">
                <strong><?php echo esc_html( sprintf( __( '%d Actionable Disputes Required', 'cube-payment-portal' ), $dispute_stats['actionable_count'] ) ); ?></strong>
                <span style="opacity: 0.9;"> &mdash; <?php esc_html_e( 'These payments are at risk of being reversed.', 'cube-payment-portal' ); ?></span>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-disputes' ) ); ?>" class="banner-action">
                <?php esc_html_e( 'Review Disputes', 'cube-payment-portal' ); ?> &rarr;
            </a>
        </div>
    <?php endif; ?>

    <!-- Key Metrics Row (4 Columns) -->
    <div class="spp-metrics-grid">
        <!-- Revenue -->
        <div class="spp-metric-card">
            <div class="metric-header">
                <span class="metric-label"><?php esc_html_e( 'Revenue This Month', 'cube-payment-portal' ); ?></span>
                <span class="dashicons dashicons-chart-line metric-icon icon-green"></span>
            </div>
            <div class="metric-value"><?php echo esc_html( spp_format_currency( $this_month_revenue ) ); ?></div>
            <?php if ( $revenue_change != 0 ) : ?>
                <div class="metric-trend <?php echo $revenue_change >= 0 ? 'trend-up' : 'trend-down'; ?>">
                    <?php echo $revenue_change >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( abs( round( $revenue_change, 1 ) ) ); ?>%
                    <span class="trend-label"><?php esc_html_e( 'vs last month', 'cube-payment-portal' ); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Subscriptions -->
        <div class="spp-metric-card">
            <div class="metric-header">
                <span class="metric-label"><?php esc_html_e( 'Active Subscriptions', 'cube-payment-portal' ); ?></span>
                <span class="dashicons dashicons-update metric-icon icon-blue"></span>
            </div>
            <div class="metric-value"><?php echo esc_html( $active_subscriptions ); ?></div>
            <div class="metric-subtext">
                <?php echo esc_html( sprintf( __( '%s MRR', 'cube-payment-portal' ), spp_format_currency( $mrr ) ) ); ?>
            </div>
        </div>

        <!-- Outstanding Invoices -->
        <div class="spp-metric-card">
            <div class="metric-header">
                <span class="metric-label"><?php esc_html_e( 'Outstanding Invoices', 'cube-payment-portal' ); ?></span>
                <span class="dashicons dashicons-media-text metric-icon icon-orange"></span>
            </div>
            <div class="metric-value"><?php echo esc_html( spp_format_currency( $invoice_stats['outstanding_amount'] ) ); ?></div>
            <div class="metric-subtext">
                <?php echo esc_html( sprintf( __( '%d outstanding', 'cube-payment-portal' ), $invoice_stats['outstanding_count'] ) ); ?>
                <?php if ( $invoice_stats['overdue_count'] > 0 ) : ?>
                    <span class="text-red">(<?php echo esc_html( $invoice_stats['overdue_count'] ); ?> <?php esc_html_e( 'overdue', 'cube-payment-portal' ); ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actionable Disputes -->
        <div class="spp-metric-card <?php echo $dispute_stats['actionable_count'] > 0 ? 'border-red-left' : ''; ?>">
            <div class="metric-header">
                <span class="metric-label"><?php esc_html_e( 'Actionable Disputes', 'cube-payment-portal' ); ?></span>
                <span class="dashicons dashicons-warning metric-icon <?php echo $dispute_stats['actionable_count'] > 0 ? 'icon-red' : 'icon-gray'; ?>"></span>
            </div>
            <div class="metric-value <?php echo $dispute_stats['actionable_count'] > 0 ? 'text-red' : ''; ?>">
                <?php echo esc_html( $dispute_stats['actionable_count'] ); ?>
            </div>
            <div class="metric-subtext">
                 <?php echo esc_html( sprintf( __( '%d total active', 'cube-payment-portal' ), $dispute_stats['total_active'] ) ); ?>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="spp-dashboard-layout">
        
        <!-- Left Column: Chart & Activity Feed -->
        <div class="spp-main-column">
            
            <!-- Revenue Chart -->
            <div class="spp-card">
                <div class="card-header">
                    <h2><?php esc_html_e( 'Revenue Trend (30 Days)', 'cube-payment-portal' ); ?></h2>
                </div>
                <div style="height: 250px; padding: 0 20px 20px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Tabbed Activity Feed -->
            <div class="spp-card">
                <div class="spp-tabs-header">
                    <button class="spp-tab-btn active" onclick="openTab(event, 'tab-transactions')"><?php esc_html_e( 'Recent Transactions', 'cube-payment-portal' ); ?></button>
                    <button class="spp-tab-btn" onclick="openTab(event, 'tab-invoices')"><?php esc_html_e( 'Recent Invoices', 'cube-payment-portal' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-transactions' ) ); ?>" class="view-all-link"><?php esc_html_e( 'View All', 'cube-payment-portal' ); ?></a>
                </div>

                <!-- Transactions Tab -->
                <div id="tab-transactions" class="spp-tab-content active">
                    <?php if ( ! empty( $recent_transactions ) ) : ?>
                        <table class="spp-table">
                            <?php foreach ( $recent_transactions as $tx ) : 
                                $customer_name = trim( $tx['first_name'] . ' ' . $tx['last_name'] );
                                if ( empty( $customer_name ) ) {
                                    $customer_name = $tx['customer_email'] ?? __( 'Unknown', 'cube-payment-portal' );
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div class="row-title">
                                            <?php if ( ! empty( $tx['square_customer_id'] ) ) : 
                                                $customer_url = admin_url( 'admin.php?page=spp-customers&customer_id=' . urlencode( $tx['square_customer_id'] ) );
                                            ?>
                                                <a href="<?php echo esc_url( $customer_url ); ?>" class="spp-customer-link">
                                                    <?php echo esc_html( $customer_name ); ?>
                                                    <!-- Tooltip -->
                                                    <div class="spp-customer-tooltip spp-tooltip-left">
                                                        <?php if ( ! empty( $tx['customer_email'] ) ) : ?>
                                                            <div class="tooltip-row"><span class="dashicons dashicons-email"></span> <?php echo esc_html( $tx['customer_email'] ); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ( ! empty( $tx['customer_phone'] ) ) : ?>
                                                            <div class="tooltip-row"><span class="dashicons dashicons-phone"></span> <?php echo esc_html( $this->format_phone_number( $tx['customer_phone'] ) ); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </a>
                                            <?php else : ?>
                                                <?php echo esc_html( $customer_name ); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="row-meta"><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $tx['created_at'] ) ) ); ?></div>
                                    </td>
                                    <td class="text-right">
                                        <div class="row-amount"><?php echo esc_html( spp_format_currency( $tx['amount'], $tx['currency'] ) ); ?></div>
                                        <div class="row-status status-<?php echo esc_attr( strtolower( $tx['status'] ) ); ?>">
                                            <?php echo esc_html( ucfirst( $tx['status'] ) ); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                         <div class="empty-state">
                            <p><?php esc_html_e( 'No transactions yet.', 'cube-payment-portal' ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Invoices Tab -->
                <div id="tab-invoices" class="spp-tab-content">
                    <?php if ( ! empty( $recent_invoices ) ) : ?>
                        <table class="spp-table">
                             <?php foreach ( $recent_invoices as $inv ) : 
                                 $customer_name = trim( $inv['first_name'] . ' ' . $inv['last_name'] );
                                 if ( empty( $customer_name ) ) {
                                     $customer_name = $inv['company_name'] ?: ( $inv['customer_email'] ?? __( 'Unknown', 'cube-payment-portal' ) );
                                 }
                                 $is_overdue = $inv['status'] === 'SENT' && ! empty( $inv['due_date'] ) && strtotime( $inv['due_date'] ) < time();
                            ?>
                                <tr>
                                    <td>
                                        <div class="row-title">
                                             <?php if ( ! empty( $inv['square_customer_id'] ) ) : 
                                                 $customer_url = admin_url( 'admin.php?page=spp-customers&customer_id=' . urlencode( $inv['square_customer_id'] ) );
                                             ?>
                                                 <a href="<?php echo esc_url( $customer_url ); ?>" class="spp-customer-link">
                                                     <?php echo esc_html( $customer_name ); ?>
                                                     <!-- Tooltip -->
                                                     <div class="spp-customer-tooltip spp-tooltip-left">
                                                         <?php if ( ! empty( $inv['customer_email'] ) ) : ?>
                                                             <div class="tooltip-row"><span class="dashicons dashicons-email"></span> <?php echo esc_html( $inv['customer_email'] ); ?></div>
                                                         <?php endif; ?>
                                                         <?php if ( ! empty( $inv['customer_phone'] ) ) : ?>
                                                             <div class="tooltip-row"><span class="dashicons dashicons-phone"></span> <?php echo esc_html( $this->format_phone_number( $inv['customer_phone'] ) ); ?></div>
                                                         <?php endif; ?>
                                                     </div>
                                                 </a>
                                             <?php else : ?>
                                                 <?php echo esc_html( $customer_name ); ?>
                                             <?php endif; ?>
                                        </div>
                                        <div class="row-meta">
                                            #<?php echo esc_html( $inv['invoice_number'] ?? $inv['id'] ); ?> &bull; 
                                            <?php echo esc_html( ! empty( $inv['due_date'] ) ? 'Due ' . wp_date( 'M j', strtotime( $inv['due_date'] ) ) : 'No due date' ); ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="row-amount"><?php echo esc_html( spp_format_currency( $inv['amount'], $inv['currency'] ) ); ?></div>
                                        <div class="row-status status-<?php echo sanitize_html_class( $is_overdue ? 'overdue' : strtolower( $inv['status'] ) ); ?>">
                                            <?php echo esc_html( $is_overdue ? __( 'Overdue', 'cube-payment-portal' ) : ucfirst( strtolower( $inv['status'] ) ) ); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <div class="empty-state">
                            <p><?php esc_html_e( 'No invoices found.', 'cube-payment-portal' ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        
        <!-- Right Column: Plans & Stats (Quick Actions moved to Top) -->
        <div class="spp-sidebar-column">
            
            <!-- Subscription Breakdown (Doughnut) -->
            <div class="spp-card">
                <div class="card-header">
                    <h2><?php esc_html_e( 'Subscription Health', 'cube-payment-portal' ); ?></h2>
                </div>
                <div style="height: 200px; padding: 10px 20px 20px; display: flex; align-items: center; justify-content: center;">
                    <?php if ( array_sum( $sub_status_counts ) > 0 ) : ?>
                        <canvas id="subscriptionChart"></canvas>
                    <?php else : ?>
                        <div class="empty-state">
                            <span class="dashicons dashicons-chart-pie" style="font-size: 32px; height: 32px; width: 32px; color: #ddd;"></span>
                            <p><?php esc_html_e( 'No data yet', 'cube-payment-portal' ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Plans -->
            <div class="spp-card">
                <div class="card-header">
                    <h2><?php esc_html_e( 'Top Plans', 'cube-payment-portal' ); ?></h2>
                </div>
                <?php if ( ! empty( $top_plans ) ) : ?>
                    <table class="spp-table simple-table">
                        <?php foreach ( $top_plans as $plan ) : ?>
                            <tr>
                                <td>
                                    <div class="row-title" style="font-size: 13px;"><?php echo esc_html( $plan['plan_name'] ); ?></div>
                                </td>
                                <td class="text-right">
                                    <div class="row-amount" style="font-size: 13px;"><?php echo esc_html( spp_format_currency( $plan['revenue'] ) ); ?></div>
                                    <div class="row-meta"><?php echo esc_html( $plan['subscribers'] ); ?> <?php esc_html_e( 'subs', 'cube-payment-portal' ); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else : ?>
                    <div class="empty-state">
                        <p><?php esc_html_e( 'No active plans', 'cube-payment-portal' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
// Simple Tab Logic
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("spp-tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
        tabcontent[i].classList.remove("active"); 
    }
    tablinks = document.getElementsByClassName("spp-tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.className += " active";
}

document.addEventListener('DOMContentLoaded', function() {
    // Default open
    document.getElementById('tab-transactions').style.display = "block";

    // Chart Config
    const chartFont = { family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif", size: 11 };
    
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo wp_json_encode( $chart_labels ); ?>,
                datasets: [{
                    label: '<?php esc_html_e( 'Revenue', 'cube-payment-portal' ); ?>',
                    data: <?php echo wp_json_encode( array_values( $chart_revenue ) ); ?>,
                    borderColor: '#006AFF',
                    backgroundColor: 'rgba(0, 106, 255, 0.05)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) { return '$' + context.parsed.y.toFixed(2); }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: chartFont, maxTicksLimit: 8 } },
                    y: { grid: { borderDash: [5, 5], color: '#f0f0f0' }, ticks: { font: chartFont, callback: function(value) { return '$' + value; } } }
                }
            }
        });
    }

    // Subscription Doughnut Chart
    const subCtx = document.getElementById('subscriptionChart');
    if (subCtx) {
        const subData = <?php echo wp_json_encode( array_values( $sub_status_counts ) ); ?>;
        if (subData.some(v => v > 0)) {
            new Chart(subCtx, {
                type: 'doughnut',
                data: {
                    labels: ['<?php esc_html_e( 'Active', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Paused', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?>'],
                    datasets: [{
                        data: subData,
                        backgroundColor: ['#10B981', '#EF4444', '#F59E0B', '#9CA3AF'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { boxWidth: 10, font: chartFont } }
                    },
                    cutout: '70%'
                }
            });
        }
    }
});
</script>


