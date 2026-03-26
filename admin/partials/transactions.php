<?php
/**
 * Admin Transactions page with insights.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check - ensure user has permission to view transactions.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Get default currency.
$default_currency = get_option( 'spp_default_currency', 'USD' );

// Database queries for metrics.
global $wpdb;
$transactions_table = $wpdb->prefix . 'spp_transactions';

// Date ranges.
$current_month_start = gmdate( 'Y-m-01 00:00:00' );
$current_month_end = gmdate( 'Y-m-t 23:59:59' );
$last_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );
$last_month_end = gmdate( 'Y-m-t 23:59:59', strtotime( 'last day of last month' ) );
$today_start = gmdate( 'Y-m-d 00:00:00' );

// This month's revenue.
$this_month_revenue = $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s AND created_at <= %s",
	$current_month_start,
	$current_month_end
) );

// Last month's revenue.
$last_month_revenue = $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s AND created_at <= %s",
	$last_month_start,
	$last_month_end
) );

// Revenue change.
$revenue_change = 0;
if ( $last_month_revenue > 0 ) {
	$revenue_change = ( ( $this_month_revenue - $last_month_revenue ) / $last_month_revenue ) * 100;
}

// This month's transactions.
$this_month_count = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s AND created_at <= %s",
	$current_month_start,
	$current_month_end
) );

// Today's revenue.
$today_revenue = $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s",
	$today_start
) );

// Today's transactions.
$today_count = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $transactions_table WHERE status = 'completed' AND created_at >= %s",
	$today_start
) );

// Average transaction value.
$avg_transaction = $this_month_count > 0 ? $this_month_revenue / $this_month_count : 0;

// Status breakdown.
$status_breakdown = $wpdb->get_results(
	"SELECT status, COUNT(*) as count FROM $transactions_table GROUP BY status",
	ARRAY_A
);

$status_counts = array(
	'completed' => 0,
	'pending' => 0,
	'failed' => 0,
	'refunded' => 0,
);
foreach ( $status_breakdown as $row ) {
	$status = strtolower( $row['status'] );
	if ( isset( $status_counts[ $status ] ) ) {
		$status_counts[ $status ] = (int) $row['count'];
	}
}

// Daily revenue for last 7 days.
$daily_revenue = $wpdb->get_results(
	"SELECT DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
	 FROM $transactions_table
	 WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
	 GROUP BY DATE(created_at)
	 ORDER BY day ASC",
	ARRAY_A
);

$chart_labels = array();
$chart_data = array();
for ( $i = 6; $i >= 0; $i-- ) {
	$day = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
	$chart_labels[] = gmdate( 'D', strtotime( $day ) );
	$chart_data[ $day ] = 0;
}
foreach ( $daily_revenue as $row ) {
	if ( isset( $chart_data[ $row['day'] ] ) ) {
		$chart_data[ $row['day'] ] = (float) $row['total'];
	}
}

// Top payment methods.
$top_methods = $wpdb->get_results(
	"SELECT card_brand, COUNT(*) as count, SUM(amount) as total
	 FROM $transactions_table
	 WHERE status = 'completed' AND card_brand IS NOT NULL AND card_brand != ''
	 GROUP BY card_brand
	 ORDER BY count DESC
	 LIMIT 5",
	ARRAY_A
);

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
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Transactions', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Transactions', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <button type="button" id="spp-sync-transactions-header" class="spp-button">
                <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Transactions', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

	<!-- Metrics Row -->
	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; justify-content: space-between; align-items: flex-start;">
				<div>
					<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'This Month', 'cube-payment-portal' ); ?></div>
					<div style="font-size: 26px; font-weight: 600; color: #0073aa;"><?php echo esc_html( spp_format_currency( $this_month_revenue ) ); ?></div>
					<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $this_month_count ); ?> <?php esc_html_e( 'transactions', 'cube-payment-portal' ); ?></div>
				</div>
				<?php if ( $revenue_change != 0 ) : ?>
					<div style="padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; background: <?php echo $revenue_change > 0 ? '#e8f5e9' : '#ffebee'; ?>; color: <?php echo $revenue_change > 0 ? '#2e7d32' : '#c62828'; ?>;">
						<?php echo $revenue_change > 0 ? '+' : ''; ?><?php echo esc_html( number_format( $revenue_change, 1 ) ); ?>%
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Today', 'cube-payment-portal' ); ?></div>
			<div style="font-size: 26px; font-weight: 600; color: #4caf50;"><?php echo esc_html( spp_format_currency( $today_revenue ) ); ?></div>
			<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $today_count ); ?> <?php esc_html_e( 'transactions', 'cube-payment-portal' ); ?></div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Avg. Transaction', 'cube-payment-portal' ); ?></div>
			<div style="font-size: 26px; font-weight: 600; color: #9c27b0;"><?php echo esc_html( spp_format_currency( $avg_transaction ) ); ?></div>
			<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php esc_html_e( 'this month', 'cube-payment-portal' ); ?></div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?></div>
			<div style="font-size: 26px; font-weight: 600; color: #4caf50;"><?php echo esc_html( $status_counts['completed'] ); ?></div>
			<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php esc_html_e( 'all time', 'cube-payment-portal' ); ?></div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Failed / Refunded', 'cube-payment-portal' ); ?></div>
			<div style="font-size: 26px; font-weight: 600; color: #f44336;"><?php echo esc_html( $status_counts['failed'] + $status_counts['refunded'] ); ?></div>
			<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $status_counts['failed'] ); ?> <?php esc_html_e( 'failed', 'cube-payment-portal' ); ?>, <?php echo esc_html( $status_counts['refunded'] ); ?> <?php esc_html_e( 'refunded', 'cube-payment-portal' ); ?></div>
		</div>
	</div>

	<!-- Charts Row -->
	<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
		<!-- Daily Revenue Chart -->
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Last 7 Days Revenue', 'cube-payment-portal' ); ?></h3>
			<div style="height: 180px;">
				<canvas id="revenueChart"></canvas>
			</div>
		</div>

		<!-- Top Payment Methods -->
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Payment Methods', 'cube-payment-portal' ); ?></h3>
			<?php if ( ! empty( $top_methods ) ) : ?>
				<div style="display: flex; flex-direction: column; gap: 10px;">
					<?php foreach ( $top_methods as $method ) : 
						$brand = strtoupper( str_replace( '_', ' ', $method['card_brand'] ) );
						$total = array_sum( array_column( $top_methods, 'count' ) );
						$percentage = $total > 0 ? ( $method['count'] / $total ) * 100 : 0;
					?>
						<div>
							<div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
								<span style="font-size: 13px; font-weight: 500;"><?php echo esc_html( $brand ); ?></span>
								<span style="font-size: 12px; color: #666;"><?php echo esc_html( $method['count'] ); ?> (<?php echo esc_html( number_format( $percentage, 0 ) ); ?>%)</span>
							</div>
							<div style="background: #e0e0e0; border-radius: 4px; height: 6px;">
								<div style="background: #0073aa; border-radius: 4px; height: 6px; width: <?php echo esc_attr( $percentage ); ?>%;"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p style="color: #999; text-align: center; margin: 40px 0;"><?php esc_html_e( 'No data yet', 'cube-payment-portal' ); ?></p>
			<?php endif; ?>
		</div>
	</div>



	<!-- Sync Message -->
	<div id="spp-sync-message" class="notice" style="display: none;">
		<p></p>
	</div>

	<!-- Filters -->
	<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
		<form id="spp-transactions-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
			<div class="spp-filter-group">
				<label for="spp-search" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
					<?php esc_html_e( 'Search', 'cube-payment-portal' ); ?>
				</label>
				<input type="search" id="spp-search" name="search" placeholder="<?php esc_attr_e( 'Customer, email, or Payment ID...', 'cube-payment-portal' ); ?>" style="min-width: 250px;">
			</div>
			
			<div class="spp-filter-group">
				<label for="spp-status" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
					<?php esc_html_e( 'Status', 'cube-payment-portal' ); ?>
				</label>
				<select id="spp-status" name="status" style="min-width: 140px;">
					<option value=""><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
					<option value="completed"><?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'cube-payment-portal' ); ?></option>
					<option value="refunded"><?php esc_html_e( 'Refunded', 'cube-payment-portal' ); ?></option>
				</select>
			</div>
			
			<div class="spp-filter-group">
				<label for="spp-plan" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
					<?php esc_html_e( 'Plan', 'cube-payment-portal' ); ?>
				</label>
				<select id="spp-plan" name="plan_id" style="min-width: 180px;">
					<option value=""><?php esc_html_e( 'All Plans', 'cube-payment-portal' ); ?></option>
				</select>
			</div>
			
			<div class="spp-filter-group">
				<label for="spp-date-from" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
					<?php esc_html_e( 'From', 'cube-payment-portal' ); ?>
				</label>
				<input type="date" id="spp-date-from" name="date_from">
			</div>
			
			<div class="spp-filter-group">
				<label for="spp-date-to" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
					<?php esc_html_e( 'To', 'cube-payment-portal' ); ?>
				</label>
				<input type="date" id="spp-date-to" name="date_to">
			</div>
			
			<div class="spp-filter-group" style="display: flex; gap: 8px;">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
				<button type="button" id="spp-reset-filters" class="button"><?php esc_html_e( 'Reset', 'cube-payment-portal' ); ?></button>
			</div>
		</form>
	</div>

	<!-- Loading -->
	<div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
		<span class="spinner is-active" style="float: none;"></span>
		<p style="color: #666;"><?php esc_html_e( 'Loading transactions...', 'cube-payment-portal' ); ?></p>
	</div>

	<!-- Empty State -->
	<div id="spp-empty-state" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
		<span class="dashicons dashicons-money-alt" style="font-size: 64px; color: #ccc; width: 64px; height: 64px;"></span>
		<h2 style="color: #666;"><?php esc_html_e( 'No Transactions Found', 'cube-payment-portal' ); ?></h2>
		<p style="color: #999;"><?php esc_html_e( 'Transactions will appear here once payments are processed.', 'cube-payment-portal' ); ?></p>
	</div>

	<!-- Transactions Table -->
	<div id="spp-transactions-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
		<div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
			<h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Transaction History', 'cube-payment-portal' ); ?></h3>
			<span style="color: #666; font-size: 13px;"><span id="spp-showing-count">0</span> <?php esc_html_e( 'transactions', 'cube-payment-portal' ); ?></span>
		</div>
		
		<table class="wp-list-table widefat fixed striped" style="margin: 0;">
			<thead>
				<tr>
					<th class="spp-sortable" data-sort="id" style="width: 60px; cursor: pointer;">
						<?php esc_html_e( 'ID', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
					<th><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
					<th class="spp-sortable" data-sort="amount" style="width: 110px; cursor: pointer;">
						<?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
					<th class="spp-sortable" data-sort="status" style="width: 100px; cursor: pointer;">
						<?php esc_html_e( 'Status', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
					<th style="width: 120px;"><?php esc_html_e( 'Method', 'cube-payment-portal' ); ?></th>
					<th><?php esc_html_e( 'Type', 'cube-payment-portal' ); ?></th>
					<th class="spp-sortable" data-sort="created_at" style="width: 150px; cursor: pointer;">
						<?php esc_html_e( 'Date', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
				</tr>
			</thead>
			<tbody id="spp-transactions-tbody"></tbody>
		</table>

		<div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
			<div class="tablenav-pages" id="spp-pagination">
				<span class="displaying-num" id="spp-total-count"></span>
				<span class="pagination-links" id="spp-pagination-links"></span>
			</div>
		</div>
	</div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
	// Revenue Chart
	const ctx = document.getElementById('revenueChart');
	if (ctx) {
		new Chart(ctx, {
			type: 'line',
			data: {
				labels: <?php echo wp_json_encode( $chart_labels ); ?>,
				datasets: [{
					label: '<?php esc_html_e( 'Revenue', 'cube-payment-portal' ); ?>',
					data: <?php echo wp_json_encode( array_values( $chart_data ) ); ?>,
					borderColor: '#0073aa',
					backgroundColor: 'rgba(0, 115, 170, 0.1)',
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
					y: { beginAtZero: true, ticks: { callback: function(v) { return '$' + v; } } },
					x: { grid: { display: false } }
				}
			}
		});
	}
});
</script>

<script>
(function($) {
	'use strict';

	var SPPTransactions = {
		currentPage: 1,
		perPage: 20,
		sortBy: 'id',
		sortOrder: 'DESC',
		debounceTimer: null,

		init: function() {
			this.bindEvents();
			this.loadPlans();
			this.updateSortIndicators();
			this.loadTransactions();
		},

		bindEvents: function() {
			var self = this;

			$('#spp-sync-transactions, #spp-sync-transactions-header').on('click', function() {
				self.syncTransactions();
			});

			$('#spp-transactions-filter-form').on('submit', function(e) {
				e.preventDefault();
				self.currentPage = 1;
				self.loadTransactions();
			});

			$('#spp-reset-filters').on('click', function() {
				$('#spp-transactions-filter-form')[0].reset();
				self.currentPage = 1;
				self.loadTransactions();
			});

			$('#spp-search').on('input', function() {
				clearTimeout(self.debounceTimer);
				self.debounceTimer = setTimeout(function() {
					self.currentPage = 1;
					self.loadTransactions();
				}, 500);
			});

			$('#spp-status, #spp-plan').on('change', function() {
				self.currentPage = 1;
				self.loadTransactions();
			});

			$('#spp-date-from, #spp-date-to').on('change', function() {
				self.currentPage = 1;
				self.loadTransactions();
			});

			$(document).on('click', '.spp-page-link', function(e) {
				e.preventDefault();
				var page = $(this).data('page');
				if (page && page !== self.currentPage) {
					self.currentPage = page;
					self.loadTransactions();
				}
			});

			// Sortable column headers
			$(document).on('click', '.spp-sortable', function() {
				var sortColumn = $(this).data('sort');
				if (self.sortBy === sortColumn) {
					// Toggle sort order if clicking same column
					self.sortOrder = self.sortOrder === 'DESC' ? 'ASC' : 'DESC';
				} else {
					// New column - default to DESC
					self.sortBy = sortColumn;
					self.sortOrder = 'DESC';
				}
				self.currentPage = 1;
				self.updateSortIndicators();
				self.loadTransactions();
			});
		},

		updateSortIndicators: function() {
			var self = this;
			// Clear all indicators
			$('.spp-sortable').removeClass('sorted-asc sorted-desc');
			$('.spp-sort-indicator').removeClass('dashicons-arrow-up dashicons-arrow-down');
			
			// Set active sort indicator
			var $activeHeader = $('.spp-sortable[data-sort="' + self.sortBy + '"]');
			if (self.sortOrder === 'ASC') {
				$activeHeader.addClass('sorted-asc');
				$activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-up');
			} else {
				$activeHeader.addClass('sorted-desc');
				$activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-down');
			}
		},

		loadPlans: function() {
			$.ajax({
				url: sppAdmin.ajaxUrl,
				type: 'POST',
				data: { action: 'spp_get_plans', nonce: sppAdmin.nonce },
				success: function(response) {
					if (response && response.success && response.data && response.data.plans) {
						var $select = $('#spp-plan');
						$.each(response.data.plans, function(i, plan) {
							$select.append($('<option>', { value: plan.id, text: plan.name }));
						});
					}
				}
			});
		},

		loadTransactions: function() {
			var self = this;
			
			$('#spp-loading').show();
			$('#spp-transactions-table-wrap').hide();
			$('#spp-empty-state').hide();

			$.ajax({
				url: sppAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spp_get_transactions',
					nonce: sppAdmin.nonce,
					search: $('#spp-search').val(),
					status: $('#spp-status').val(),
					plan_id: $('#spp-plan').val(),
					date_from: $('#spp-date-from').val(),
					date_to: $('#spp-date-to').val(),
					page: self.currentPage,
					per_page: self.perPage,
					sort_by: self.sortBy,
					sort_order: self.sortOrder
				},
				success: function(response) {
					$('#spp-loading').hide();

					if (!response || !response.success) {
						var errorMsg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to load transactions.', 'cube-payment-portal' ) ); ?>';
						self.showError(errorMsg);
						return;
					}

					var data = response.data;
					if (!data || !data.transactions || data.transactions.length === 0) {
						$('#spp-empty-state').show();
						$('#spp-showing-count').text('0');
						return;
					}

					self.renderTransactions(data.transactions);
					self.renderPagination(data.pagination);
					$('#spp-transactions-table-wrap').show();
				},
				error: function() {
					$('#spp-loading').hide();
					self.showError('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
				}
			});
		},

		renderTransactions: function(transactions) {
			var $tbody = $('#spp-transactions-tbody');
			$tbody.empty();

			var self = this;
			$.each(transactions, function(i, tx) {
				var row = self.buildRow(tx);
				$tbody.append(row);
			});

			$('#spp-showing-count').text(transactions.length);
		},

		buildRow: function(tx) {
			var statusClass = this.getStatusClass(tx.status);
			var paymentMethod = this.formatPaymentMethod(tx.card_brand, tx.card_last_four);
			var planType = tx.plan_name || this.formatSourceType(tx.source_type);
			var currencySymbol = this.getCurrencySymbol(tx.currency);

			// Build customer link with tooltip
			// Build customer link with tooltip
			var customerHtml = '';
			if (tx.square_customer_id) {
				var customerUrl = sppAdmin.adminUrl + 'admin.php?page=spp-customers&customer_id=' + encodeURIComponent(tx.square_customer_id);
				var tooltipHtml = '<div class="spp-customer-tooltip spp-tooltip-left">' +
					(tx.customer_email ? '<div class="tooltip-row"><span class="dashicons dashicons-email"></span> ' + this.escapeHtml(tx.customer_email) + '</div>' : '') +
					(tx.customer_phone ? '<div class="tooltip-row"><span class="dashicons dashicons-phone"></span> ' + this.escapeHtml(tx.customer_phone) + '</div>' : '') +
				'</div>';

				customerHtml = '<a href="' + customerUrl + '" class="spp-customer-link" style="text-decoration: none; font-weight: 600;">' + 
					this.escapeHtml(tx.customer_name) + 
					tooltipHtml + 
				'</a>';
			} else {
				customerHtml = '<strong>' + this.escapeHtml(tx.customer_name) + '</strong>' +
					(tx.customer_email ? '<br><span style="color: #999; font-size: 12px;">' + this.escapeHtml(tx.customer_email) + '</span>' : '');
			}

			return '<tr>' +
				'<td><strong>#' + tx.id + '</strong></td>' +
				'<td>' + customerHtml + '</td>' +
				'<td style="font-weight: 600;">' + currencySymbol + tx.amount + '</td>' +
				'<td><span class="spp-status-badge ' + statusClass + '">' + tx.status_label + '</span></td>' +
				'<td style="font-size: 12px;">' + paymentMethod + '</td>' +
				'<td style="font-size: 13px;">' + this.escapeHtml(planType) + '</td>' +
				'<td style="color: #666; font-size: 13px;">' + tx.created_at_formatted + '</td>' +
			'</tr>';
		},

		getStatusClass: function(status) {
			var classes = { completed: 'spp-status-paid', pending: 'spp-status-pending', failed: 'spp-status-failed', refunded: 'spp-status-refunded', canceled: 'spp-status-canceled' };
			return classes[status.toLowerCase()] || 'spp-status-pending';
		},

		formatPaymentMethod: function(brand, lastFour) {
			if (!brand && !lastFour) return '—';
			var brandFormatted = brand ? brand.replace('_', ' ') : '';
			return brandFormatted + (lastFour ? ' •••• ' + lastFour : '');
		},

		formatSourceType: function(sourceType) {
			var types = { subscription: '<?php echo esc_js( __( 'Subscription', 'cube-payment-portal' ) ); ?>', invoice: '<?php echo esc_js( __( 'Invoice', 'cube-payment-portal' ) ); ?>', one_time: '<?php echo esc_js( __( 'One-time', 'cube-payment-portal' ) ); ?>' };
			return types[sourceType] || sourceType || '<?php echo esc_js( __( 'One-time', 'cube-payment-portal' ) ); ?>';
		},

		getCurrencySymbol: function(currency) {
			var symbols = { EUR: '€', GBP: '£', JPY: '¥' };
			return symbols[currency] || '$';
		},

		renderPagination: function(pagination) {
			var total = pagination.total, totalPages = pagination.total_pages, currentPage = pagination.current_page;
			$('#spp-total-count').text(total + ' <?php echo esc_js( __( 'total', 'cube-payment-portal' ) ); ?>');

			if (totalPages <= 1) { $('#spp-pagination-links').empty(); return; }

			var links = '';
			links += currentPage > 1 ? '<a href="#" class="first-page button spp-page-link" data-page="1">&laquo;</a> <a href="#" class="prev-page button spp-page-link" data-page="' + (currentPage - 1) + '">&lsaquo;</a> ' : '<span class="button disabled">&laquo;</span> <span class="button disabled">&lsaquo;</span> ';
			links += '<span class="paging-input">' + currentPage + ' of ' + totalPages + '</span>';
			links += currentPage < totalPages ? ' <a href="#" class="next-page button spp-page-link" data-page="' + (currentPage + 1) + '">&rsaquo;</a> <a href="#" class="last-page button spp-page-link" data-page="' + totalPages + '">&raquo;</a>' : ' <span class="button disabled">&rsaquo;</span> <span class="button disabled">&raquo;</span>';

			$('#spp-pagination-links').html(links);
		},

		showError: function(message) { $('#spp-empty-state').show().find('h2').text(message); },
		escapeHtml: function(text) { if (!text) return ''; var div = document.createElement('div'); div.appendChild(document.createTextNode(text)); return div.innerHTML; },

		syncTransactions: function() {
			var self = this;
			var $button = $('#spp-sync-transactions, #spp-sync-transactions-header');
			var $message = $('#spp-sync-message');

			$button.prop('disabled', true).find('.dashicons').addClass('spin');

			$.ajax({
				url: sppAdmin.ajaxUrl,
				type: 'POST',
				data: { action: 'spp_sync_transactions', nonce: sppAdmin.nonce },
				success: function(response) {
					$button.prop('disabled', false).find('.dashicons').removeClass('spin');

					if (response && response.success) {
						$message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
						$message.find('p').text((response.data && response.data.message) || '<?php echo esc_js( __( 'Transactions synced successfully.', 'cube-payment-portal' ) ); ?>');
						$('#spp-last-sync-time').text('<?php echo esc_js( __( 'Just now', 'cube-payment-portal' ) ); ?>');
						self.currentPage = 1;
						self.loadTransactions();
					} else {
						$message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
						$message.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Sync failed.', 'cube-payment-portal' ) ); ?>');
					}
					setTimeout(function() { $message.fadeOut(); }, 8000);
				},
				error: function() {
					$button.prop('disabled', false).find('.dashicons').removeClass('spin');
					$message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
					$message.find('p').text('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>');
				}
			});
		}
	};

	$(document).ready(function() { SPPTransactions.init(); });
})(jQuery);
</script>

<style>
@media (max-width: 1200px) {
	.wrap > div[style*="grid-template-columns: 2fr 1fr"] { grid-template-columns: 1fr !important; }
}

/* Sortable column headers */
.spp-sortable {
	user-select: none;
	transition: background-color 0.15s ease;
}
.spp-sortable:hover {
	background-color: #f0f0f1;
}
.spp-sortable.sorted-asc,
.spp-sortable.sorted-desc {
	background-color: #e8f4fc;
}
.spp-sort-indicator {
	font-size: 14px;
	vertical-align: middle;
	margin-left: 4px;
	opacity: 0.7;
}
.spp-sortable:not(.sorted-asc):not(.sorted-desc) .spp-sort-indicator {
	opacity: 0;
}
.spp-sortable:hover:not(.sorted-asc):not(.sorted-desc) .spp-sort-indicator::before {
	content: "\f142"; /* dashicons-arrow-down */
	opacity: 0.3;
}
</style>
