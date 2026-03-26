<?php
/**
 * Admin Customers page with insights.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Check if viewing customer detail.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view, nonce verified in any action forms.
if ( isset( $_GET['customer_id'] ) ) {
    $customer_id = absint( $_GET['customer_id'] );
    if ( $customer_id > 0 ) {
        include SPP_PLUGIN_DIR . 'admin/partials/customer-detail.php';
        return;
    }
}

// Load sync class.
if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
    require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
}

// Handle sync action.
$sync_message = null;
if ( isset( $_POST['spp_sync_customers'] ) && wp_verify_nonce( $_POST['spp_sync_nonce'], 'spp_sync_customers' ) ) {
    $sync = new SPP_Sync_Customers();
    $result = $sync->sync_all();
    
    $sync_message = array(
        'type' => 'success',
        'text' => sprintf(
            __( 'Sync complete! %d from Square, %d to Square, %d auto-linked, %d errors.', 'cube-payment-portal' ),
            $result['from_square'],
            $result['to_square'],
            $result['auto_linked'],
            $result['errors']
        ),
    );
}

// Handle link action.
if ( isset( $_POST['spp_link_customer'] ) && wp_verify_nonce( $_POST['spp_link_nonce'], 'spp_link_customer' ) ) {
    $customer_id = intval( $_POST['customer_id'] );
    $wp_user_id = intval( $_POST['wp_user_id'] );
    
    if ( $customer_id && $wp_user_id ) {
        $sync = new SPP_Sync_Customers();
        $result = $sync->link_customer( $customer_id, $wp_user_id );
        
        if ( is_wp_error( $result ) ) {
            $sync_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
        } else {
            $sync_message = array( 'type' => 'success', 'text' => __( 'Customer linked successfully!', 'cube-payment-portal' ) );
        }
    }
}

// Handle unlink action.
if ( isset( $_POST['spp_unlink_customer'] ) && wp_verify_nonce( $_POST['spp_unlink_nonce'], 'spp_unlink_customer' ) ) {
    $customer_id = intval( $_POST['customer_id'] );
    
    if ( $customer_id ) {
        $sync = new SPP_Sync_Customers();
        $result = $sync->unlink_customer( $customer_id );
        
        if ( is_wp_error( $result ) ) {
            $sync_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
        } else {
            $sync_message = array( 'type' => 'success', 'text' => __( 'Customer unlinked successfully!', 'cube-payment-portal' ) );
        }
    }
}

// Get sync status.
$sync_class = new SPP_Sync_Customers();
$sync_status = $sync_class->get_sync_status();

// Get filters.
$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 20;

// Query customers.
$query_args = array(
    'limit'  => $per_page,
    'offset' => ( $current_page - 1 ) * $per_page,
    'status' => $status_filter,
    'search' => $search,
);

$customers = SPP_Database::get_customers( $query_args );
$total_customers = SPP_Database::get_customers_count( $query_args );
$total_pages = ceil( $total_customers / $per_page );

// Get all WP users for linking dropdown.
$wp_users = get_users( array( 'number' => 200 ) );

// Get additional metrics.
global $wpdb;
$transactions_table = $wpdb->prefix . 'spp_transactions';
$subscriptions_table = $wpdb->prefix . 'spp_subscriptions';
$customers_table = $wpdb->prefix . 'spp_customers';

// New customers this month.
$new_this_month = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $customers_table WHERE created_at >= %s",
    gmdate( 'Y-m-01 00:00:00' )
) );

// Total lifetime value (all customers).
$total_ltv = $wpdb->get_var(
    "SELECT COALESCE(SUM(amount), 0) FROM $transactions_table WHERE status = 'completed'"
);

// Customers with active subscriptions.
$customers_with_subs = $wpdb->get_var(
    "SELECT COUNT(DISTINCT square_customer_id) FROM $subscriptions_table WHERE status = 'active'"
);

// Average customer lifetime value.
$avg_ltv = $sync_status['total'] > 0 ? $total_ltv / $sync_status['total'] : 0;

// Get customer stats (LTV, last transaction, subscription count) for displayed customers.
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
            'ltv' => (float) $row['ltv'],
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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Customers', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Customers', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <form method="post" action="" style="margin: 0;">
                <?php wp_nonce_field( 'spp_sync_customers', 'spp_sync_nonce' ); ?>
                <button type="submit" name="spp_sync_customers" class="spp-button">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Customers', 'cube-payment-portal' ); ?>
                </button>
            </form>
            <button type="button" id="spp-add-customer-btn" class="spp-button">
                <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Customer', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <?php if ( $sync_message ) : ?>
        <div class="notice notice-<?php echo esc_attr( $sync_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $sync_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Metrics Cards -->
    <div class="spp-metrics-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Total Customers', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #1e3a5f;"><?php echo esc_html( $sync_status['total'] ); ?></div>
                </div>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-groups" style="color: #2196f3; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'New This Month', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #4caf50;"><?php echo esc_html( $new_this_month ); ?></div>
                </div>
                <div style="background: #e8f5e9; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-plus-alt" style="color: #4caf50; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'With Subscriptions', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #9c27b0;"><?php echo esc_html( $customers_with_subs ); ?></div>
                </div>
                <div style="background: #f3e5f5; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-update" style="color: #9c27b0; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Avg. Lifetime Value', 'cube-payment-portal' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; color: #ff9800;"><?php echo esc_html( spp_format_currency( $avg_ltv ) ); ?></div>
                </div>
                <div style="background: #fff3e0; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-chart-bar" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>
    </div>



    <!-- Filters -->
    <div style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form method="get" action="">
            <input type="hidden" name="page" value="spp-customers">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <select name="status" style="min-width: 150px;">
                        <option value=""><?php esc_html_e( 'All Customers', 'cube-payment-portal' ); ?></option>
                        <option value="linked" <?php selected( $status_filter, 'linked' ); ?>><?php esc_html_e( 'Linked to WP', 'cube-payment-portal' ); ?></option>
                        <option value="unlinked" <?php selected( $status_filter, 'unlinked' ); ?>><?php esc_html_e( 'Not Linked', 'cube-payment-portal' ); ?></option>
                    </select>
                    <input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'cube-payment-portal' ); ?>">
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customers...', 'cube-payment-portal' ); ?>" style="min-width: 200px;">
                    <input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'cube-payment-portal' ); ?>">
                </div>
            </div>
        </form>
    </div>

    <!-- Loading -->
    <div id="spp-customers-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading customers...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-customers-empty" style="display: none; background: #fff; padding: 60px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
        <span class="dashicons dashicons-groups" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Customers Found', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Click "Sync Now" to import customers from Square.', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Customer Table -->
    <div id="spp-customers-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Customers', 'cube-payment-portal' ); ?></h3>
            <span style="color: #666; font-size: 13px;"><span id="spp-customers-showing-count">0</span> <?php esc_html_e( 'customers', 'cube-payment-portal' ); ?></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th class="spp-sortable" data-sort="given_name" style="width: 22%; cursor: pointer;">
                        <?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th style="width: 20%;"><?php esc_html_e( 'Contact', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%; text-align: right;"><?php esc_html_e( 'Lifetime Value', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%; text-align: center;"><?php esc_html_e( 'Subscriptions', 'cube-payment-portal' ); ?></th>
                    <th class="spp-sortable" data-sort="last_activity" style="width: 14%; cursor: pointer;">
                        <?php esc_html_e( 'Last Activity', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th style="width: 10%;"><?php esc_html_e( 'WP Link', 'cube-payment-portal' ); ?></th>
                    <th style="width: 10%;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody id="spp-customers-tbody"></tbody>
        </table>

        <div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
            <div class="tablenav-pages" id="spp-customers-pagination">
                <span class="displaying-num" id="spp-customers-total-count"></span>
                <span class="pagination-links" id="spp-customers-pagination-links"></span>
            </div>
        </div>
    </div>

</div>

<script>
jQuery(document).ready(function($) {
	'use strict';

	var SPPCustomers = {
		currentPage: 1,
		perPage: 20,
		sortBy: 'given_name',
		sortOrder: 'ASC',

		init: function() {
			this.bindEvents();
			this.updateSortIndicators();
			this.loadCustomers();
		},

		bindEvents: function() {
			var self = this;

			// Sortable column headers
			$(document).on('click', '.spp-sortable', function() {
				var sortColumn = $(this).data('sort');
				if (self.sortBy === sortColumn) {
					self.sortOrder = self.sortOrder === 'DESC' ? 'ASC' : 'DESC';
				} else {
					self.sortBy = sortColumn;
					// Default to ASC for name, DESC for activity
					self.sortOrder = sortColumn === 'last_activity' ? 'DESC' : 'ASC';
				}
				self.currentPage = 1;
				self.updateSortIndicators();
				self.loadCustomers();
			});

			// Pagination
			$(document).on('click', '.spp-page-link', function(e) {
				e.preventDefault();
				var page = $(this).data('page');
				if (page && page !== self.currentPage) {
					self.currentPage = page;
					self.loadCustomers();
				}
			});

			// Link User
			$(document).on('change', '.spp-link-user-select', function() {
				var customerId = $(this).data('id');
				var userId = $(this).val();
				if (userId) {
					if (confirm('<?php echo esc_js( __( 'Link this customer to the selected WordPress user?', 'cube-payment-portal' ) ); ?>')) {
						self.linkCustomer(customerId, userId);
					} else {
						$(this).val(''); // Reset
					}
				}
			});

			// Unlink User
			$(document).on('click', '.spp-unlink-customer', function(e) {
				e.preventDefault();
				var customerId = $(this).data('id');
				if (confirm('<?php echo esc_js( __( 'Are you sure you want to unlink this customer?', 'cube-payment-portal' ) ); ?>')) {
					self.unlinkCustomer(customerId);
				}
			});
		},

		updateSortIndicators: function() {
			var self = this;
			$('.spp-sortable').removeClass('sorted-asc sorted-desc');
			$('.spp-sort-indicator').removeClass('dashicons-arrow-up dashicons-arrow-down');
			
			var $activeHeader = $('.spp-sortable[data-sort="' + self.sortBy + '"]');
			if (self.sortOrder === 'ASC') {
				$activeHeader.addClass('sorted-asc');
				$activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-up');
			} else {
				$activeHeader.addClass('sorted-desc');
				$activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-down');
			}
		},

		loadCustomers: function() {
			var self = this;
			
			$('#spp-customers-loading').show();
			$('#spp-customers-table-wrap').hide();
			$('#spp-customers-empty').hide();

			$.ajax({
				url: sppAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spp_get_customers',
					nonce: sppAdmin.nonce,
					search: '',
					status: '',
					page: self.currentPage,
					per_page: self.perPage,
					sort_by: self.sortBy,
					sort_order: self.sortOrder
				},
				success: function(response) {
					$('#spp-customers-loading').hide();

					if (!response || !response.success) {
						var errorMsg = (response && response.data && response.data.message) 
							? response.data.message 
							: '<?php echo esc_js( __( 'Failed to load customers.', 'cube-payment-portal' ) ); ?>';
						$('#spp-customers-empty').show().find('h2').text(errorMsg);
						return;
					}

					var data = response.data;
					if (!data || !data.customers || data.customers.length === 0) {
						$('#spp-customers-empty').show();
						return;
					}

					self.renderCustomers(data.customers);
					self.renderPagination(data.pagination);
					$('#spp-customers-table-wrap').show();
				},
				error: function() {
					$('#spp-customers-loading').hide();
					$('#spp-customers-empty').show().find('h2').text('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
				}
			});
		},

		linkCustomer: function(customerId, wpUserId) {
			var self = this;
			$.post(sppAdmin.ajaxUrl, {
				action: 'spp_link_customer',
				nonce: sppAdmin.nonce,
				customer_id: customerId,
				wp_user_id: wpUserId
			}, function(response) {
				if (response.success) {
					self.loadCustomers();
				} else {
					alert(response.data.message || 'Error linking customer');
				}
			});
		},

		unlinkCustomer: function(customerId) {
			var self = this;
			$.post(sppAdmin.ajaxUrl, {
				action: 'spp_unlink_customer',
				nonce: sppAdmin.nonce,
				customer_id: customerId
			}, function(response) {
				if (response.success) {
					self.loadCustomers();
				} else {
					alert(response.data.message || 'Error unlinking customer');
				}
			});
		},

		renderCustomers: function(customers) {
			var $tbody = $('#spp-customers-tbody');
			$tbody.empty();

			var self = this;
			$.each(customers, function(i, cust) {
				var row = self.buildRow(cust);
				$tbody.append(row);
			});

			$('#spp-customers-showing-count').text(customers.length);
		},

		buildRow: function(cust) {
			var detailUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-customers&customer_id=' ) ); ?>' + cust.id;
			var self = this;
			
			// Customer column
			var customerCell = '<td>' +
				'<a href="' + detailUrl + '" style="text-decoration: none; font-weight: 500;">' + this.escapeHtml(cust.display_name) + '</a>';
			if (cust.has_both_names && cust.company_name) {
				customerCell += '<div style="color: #666; font-size: 12px;">' + this.escapeHtml(cust.company_name) + '</div>';
			}
			customerCell += '</td>';

			// Contact column
			var contactCell = '<td>';
			if (cust.email) {
				contactCell += '<div style="font-size: 13px;">' + this.escapeHtml(cust.email) + '</div>';
			}
			if (cust.phone) {
				contactCell += '<div style="font-size: 12px; color: #666;">' + this.escapeHtml(cust.phone) + '</div>';
			}
			if (!cust.email && !cust.phone) {
				contactCell += '<span style="color: #999;">—</span>';
			}
			contactCell += '</td>';

			// LTV column
			var ltvCell = '<td style="text-align: right;">';
			if (cust.ltv_raw > 0) {
				ltvCell += '<div style="font-weight: 600; color: #1e3a5f;">$' + cust.ltv + '</div>';
				ltvCell += '<div style="font-size: 11px; color: #999;">' + cust.tx_count + ' transaction' + (cust.tx_count !== 1 ? 's' : '') + '</div>';
			} else {
				ltvCell += '<span style="color: #999;">$0.00</span>';
			}
			ltvCell += '</td>';

			// Subscriptions column
			var subCell = '<td style="text-align: center;">';
			if (cust.active_subs > 0) {
				subCell += '<span style="background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500;">' + 
					cust.active_subs + ' active</span>';
			} else if (cust.sub_count > 0) {
				subCell += '<span style="color: #999; font-size: 12px;">' + cust.sub_count + ' inactive</span>';
			} else {
				subCell += '<span style="color: #ccc;">—</span>';
			}
			subCell += '</td>';

			// Last Activity column
			var activityCell = '<td>';
			if (cust.last_activity) {
				activityCell += '<div style="font-size: 13px;">' + cust.last_activity_formatted + '</div>';
				activityCell += '<div style="font-size: 11px; color: #999;">' + cust.last_activity_ago + '</div>';
			} else {
				activityCell += '<span style="color: #999; font-size: 12px;"><?php echo esc_js( __( 'No activity', 'cube-payment-portal' ) ); ?></span>';
			}
			activityCell += '</td>';

			// WP Link column
			var wpLinkCell = '<td>';
			if (cust.wp_user_id) {
				wpLinkCell += '<span class="spp-wp-link-info" style="color: #4caf50;" title="' + this.escapeHtml(cust.wp_display_name) + '">' +
					'<a href="user-edit.php?user_id=' + cust.wp_user_id + '" style="color: inherit; text-decoration: none; font-weight: 500;">' + 
					this.escapeHtml(cust.wp_display_name) + '</a>' +
					'<span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-left: 2px;"></span>' +
					' <a href="#" class="spp-unlink-customer" data-id="' + cust.id + '" title="<?php echo esc_attr( 'Unlink', 'cube-payment-portal' ); ?>"><span class="dashicons dashicons-dismiss"></span></a>' +
					'</span>';
			} else if (cust.matching_wp_users && cust.matching_wp_users.length > 0) {
				wpLinkCell += '<select class="spp-link-user-select" data-id="' + cust.id + '" style="max-width: 100%; font-size: 12px; padding: 0 20px 0 5px; min-height: 24px;">';
				wpLinkCell += '<option value=""><?php echo esc_js( __( 'Select User', 'cube-payment-portal' ) ); ?></option>';
				$.each(cust.matching_wp_users, function(i, user) {
					wpLinkCell += '<option value="' + user.id + '">' + self.escapeHtml(user.display_name) + ' (' + self.escapeHtml(user.email) + ')</option>';
				});
				wpLinkCell += '</select>';
			} else {
				wpLinkCell += '<span style="color: #ccc;"><span class="dashicons dashicons-minus"></span></span>';
			}
			wpLinkCell += '</td>';

			// Actions column
			var actionsCell = '<td><a href="' + detailUrl + '" class="button button-small"><?php echo esc_js( __( 'View', 'cube-payment-portal' ) ); ?></a></td>';

			return '<tr>' + customerCell + contactCell + ltvCell + subCell + activityCell + wpLinkCell + actionsCell + '</tr>';
		},

		renderPagination: function(pagination) {
			var total = pagination.total;
			var totalPages = pagination.total_pages;
			var currentPage = pagination.current_page;

			$('#spp-customers-total-count').text(total + ' <?php echo esc_js( __( 'total', 'cube-payment-portal' ) ); ?>');

			if (totalPages <= 1) {
				$('#spp-customers-pagination-links').empty();
				return;
			}

			var links = '';
			links += currentPage > 1 
				? '<a href="#" class="first-page button spp-page-link" data-page="1">&laquo;</a> <a href="#" class="prev-page button spp-page-link" data-page="' + (currentPage - 1) + '">&lsaquo;</a> '
				: '<span class="button disabled">&laquo;</span> <span class="button disabled">&lsaquo;</span> ';
			
			links += '<span class="paging-input">' + currentPage + ' of ' + totalPages + '</span>';
			
			links += currentPage < totalPages 
				? ' <a href="#" class="next-page button spp-page-link" data-page="' + (currentPage + 1) + '">&rsaquo;</a> <a href="#" class="last-page button spp-page-link" data-page="' + totalPages + '">&raquo;</a>'
				: ' <span class="button disabled">&rsaquo;</span> <span class="button disabled">&raquo;</span>';

			$('#spp-customers-pagination-links').html(links);
		},

		escapeHtml: function(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(text));
			return div.innerHTML;
		}
	};

	SPPCustomers.init();
});
</script>

<style>
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
/* Unlink button visibility */
.spp-unlink-customer {
	color: #f44336;
	text-decoration: none;
	margin-left: 5px;
	visibility: hidden;
}
tr:hover .spp-unlink-customer {
	visibility: visible;
}
</style>

<!-- Add Customer Modal -->
<div id="spp-add-customer-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px;"><?php esc_html_e( 'Add New Customer', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-close-customer-modal" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
        </div>
        <form id="spp-add-customer-form" style="padding: 20px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'First Name', 'cube-payment-portal' ); ?> <span style="color: #c00;">*</span></label>
                    <input type="text" name="first_name" required style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Last Name', 'cube-payment-portal' ); ?> <span style="color: #c00;">*</span></label>
                    <input type="text" name="last_name" required style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Email', 'cube-payment-portal' ); ?> <span style="color: #c00;">*</span></label>
                    <input type="email" name="email" required style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Phone', 'cube-payment-portal' ); ?></label>
                    <input type="tel" name="phone" style="width: 100%;">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Company', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="company_name" style="width: 100%;">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Address Line 1', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="address_line_1" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'City', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="city" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'State/Province', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="state" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Postal Code', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="postal_code" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Country', 'cube-payment-portal' ); ?></label>
                    <select name="country" style="width: 100%;">
                        <option value="US"><?php esc_html_e( 'United States', 'cube-payment-portal' ); ?></option>
                        <option value="CA"><?php esc_html_e( 'Canada', 'cube-payment-portal' ); ?></option>
                        <option value="GB"><?php esc_html_e( 'United Kingdom', 'cube-payment-portal' ); ?></option>
                        <option value="AU"><?php esc_html_e( 'Australia', 'cube-payment-portal' ); ?></option>
                    </select>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Reference ID', 'cube-payment-portal' ); ?></label>
                    <input type="text" name="reference_id" style="width: 100%;" placeholder="<?php esc_attr_e( 'Optional external reference', 'cube-payment-portal' ); ?>">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Note', 'cube-payment-portal' ); ?></label>
                    <textarea name="note" rows="3" style="width: 100%;"></textarea>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="create_wp_user" value="1" checked>
                        <span><?php esc_html_e( 'Create a WordPress account (placeholder) for this customer', 'cube-payment-portal' ); ?></span>
                    </label>
                    <p style="color: #666; font-size: 12px; margin: 5px 0 0 24px;"><?php esc_html_e( 'When the customer registers with the same email, their account will be automatically linked.', 'cube-payment-portal' ); ?></p>
                </div>
            </div>
            <div id="spp-add-customer-message" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px;"></div>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="spp-cancel-customer" class="button"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary" id="spp-save-customer-btn">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-top: -2px;"></span>
                    <?php esc_html_e( 'Create Customer', 'cube-payment-portal' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Open modal
    $('#spp-add-customer-btn').on('click', function() {
        $('#spp-add-customer-modal').show();
        $('#spp-add-customer-form')[0].reset();
        $('#spp-add-customer-message').hide();
    });

    // Close modal
    $('#spp-close-customer-modal, #spp-cancel-customer').on('click', function() {
        $('#spp-add-customer-modal').hide();
    });

    $('#spp-add-customer-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });

    // Submit form
    $('#spp-add-customer-form').on('submit', function(e) {
        e.preventDefault();
        
        var $btn = $('#spp-save-customer-btn');
        var $msg = $('#spp-add-customer-message');
        
        $btn.prop('disabled', true).find('.dashicons').addClass('spin');
        $msg.hide();

        $.ajax({
            url: sppAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'spp_create_customer',
                nonce: sppAdmin.nonce,
                first_name: $('input[name="first_name"]').val(),
                last_name: $('input[name="last_name"]').val(),
                email: $('input[name="email"]').val(),
                phone: $('input[name="phone"]').val(),
                company_name: $('input[name="company_name"]').val(),
                address_line_1: $('input[name="address_line_1"]').val(),
                city: $('input[name="city"]').val(),
                state: $('input[name="state"]').val(),
                postal_code: $('input[name="postal_code"]').val(),
                country: $('select[name="country"]').val(),
                reference_id: $('input[name="reference_id"]').val(),
                note: $('textarea[name="note"]').val(),
                create_wp_user: $('input[name="create_wp_user"]').is(':checked') ? 1 : 0
            },
            success: function(response) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');

                if (response && response.success) {
                    $msg.css({ background: '#e8f5e9', color: '#2e7d32' }).text(response.data.message).show();
                    setTimeout(function() {
                        $('#spp-add-customer-modal').hide();
                        location.reload();
                    }, 1500);
                } else {
                    $msg.css({ background: '#ffebee', color: '#c62828' }).text(response.data.message || '<?php echo esc_js( __( 'Failed to create customer.', 'cube-payment-portal' ) ); ?>').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                $msg.css({ background: '#ffebee', color: '#c62828' }).text('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>').show();
            }
        });
    });
});
</script>