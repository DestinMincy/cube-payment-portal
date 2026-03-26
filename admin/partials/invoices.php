<?php
/**
 * Admin Invoices page with insights.
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

// Handle manual sync.
$sync_message = null;
if ( isset( $_POST['spp_sync_now'] ) && wp_verify_nonce( $_POST['spp_sync_nonce'], 'spp_sync_invoices' ) ) {
	if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
	}
	
	$sync = new SPP_Sync_Invoices();
	$result = $sync->sync_from_square();
	
	if ( is_wp_error( $result ) ) {
		$sync_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
	} else {
		$sync_message = array(
			'type' => 'success',
			'text' => sprintf( __( 'Synced %d invoice(s) with %d error(s).', 'cube-payment-portal' ), $result['synced'], $result['errors'] ),
		);
	}
}

// Handle redirect messages.
if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) {
	$sync_message = array( 'type' => 'success', 'text' => __( 'Invoice deleted successfully!', 'cube-payment-portal' ) );
}
if ( isset( $_GET['published'] ) && '1' === $_GET['published'] ) {
	$sync_message = array( 'type' => 'success', 'text' => __( 'Invoice created and sent successfully!', 'cube-payment-portal' ) );
}
if ( isset( $_GET['created'] ) && '1' === $_GET['created'] ) {
	$sync_message = array( 'type' => 'success', 'text' => __( 'Invoice saved as draft successfully!', 'cube-payment-portal' ) );
}

// Handle bulk actions.
$bulk_message = null;
if ( isset( $_POST['spp_bulk_action'] ) && isset( $_POST['invoice_ids'] ) && wp_verify_nonce( $_POST['spp_bulk_nonce'], 'spp_bulk_invoices' ) ) {
	$action = sanitize_text_field( $_POST['spp_bulk_action'] );
	$invoice_ids = array_map( 'intval', (array) $_POST['invoice_ids'] );
	
	if ( empty( $invoice_ids ) ) {
		$bulk_message = array( 'type' => 'error', 'text' => __( 'No invoices selected.', 'cube-payment-portal' ) );
	} elseif ( count( $invoice_ids ) > 50 ) {
		$bulk_message = array( 'type' => 'error', 'text' => __( 'Maximum 50 invoices per bulk action.', 'cube-payment-portal' ) );
	} else {
		$invoices_api = new SPP_Invoices();
		$succeeded = 0;
		$failed = 0;
		$errors = array();
		
		foreach ( $invoice_ids as $invoice_id ) {
			$invoice = SPP_Database::get_invoice_by_id( $invoice_id );
			
			if ( empty( $invoice ) || empty( $invoice['square_invoice_id'] ) ) {
				$failed++;
				continue;
			}
			
			switch ( $action ) {
				case 'cancel':
					$result = $invoices_api->cancel_invoice( $invoice['square_invoice_id'] );
					break;
				case 'delete':
					$result = $invoices_api->delete_invoice( $invoice['square_invoice_id'] );
					break;
				default:
					$result = new WP_Error( 'invalid', 'Invalid action' );
			}
			
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$succeeded++;
			}
		}
		
		if ( $succeeded > 0 && $failed === 0 ) {
			$bulk_message = array( 'type' => 'success', 'text' => sprintf( __( 'Processed %d invoice(s).', 'cube-payment-portal' ), $succeeded ) );
		} elseif ( $succeeded > 0 ) {
			$bulk_message = array( 'type' => 'warning', 'text' => sprintf( __( 'Processed %d of %d invoice(s).', 'cube-payment-portal' ), $succeeded, $succeeded + $failed ) );
		} else {
			$bulk_message = array( 'type' => 'error', 'text' => __( 'Failed to process invoices.', 'cube-payment-portal' ) );
		}
	}
}

// Define helper functions BEFORE includes (they're needed by detail pages).
if ( ! function_exists( 'spp_format_currency' ) ) {
	function spp_format_currency( $amount, $currency = 'USD' ) {
		$symbol = '$';
		if ( 'EUR' === $currency ) $symbol = '€';
		elseif ( 'GBP' === $currency ) $symbol = '£';
		// Amount is already in dollars (converted from cents during storage).
		return $symbol . number_format( (float) $amount, 2 );
	}
}

if ( ! function_exists( 'spp_get_status_badge_class' ) ) {
	function spp_get_status_badge_class( $status, $is_overdue = false ) {
		if ( $is_overdue ) return 'spp-status-overdue';
		$classes = array( 'paid' => 'spp-status-paid', 'unpaid' => 'spp-status-unpaid', 'draft' => 'spp-status-draft', 'canceled' => 'spp-status-canceled' );
		return $classes[ strtolower( $status ) ] ?? 'spp-status-pending';
	}
}

if ( ! function_exists( 'spp_is_overdue' ) ) {
	function spp_is_overdue( $invoice ) {
		if ( strtolower( $invoice['status'] ) !== 'unpaid' || empty( $invoice['due_date'] ) ) return false;
		return strtotime( $invoice['due_date'] ) < strtotime( 'today' );
	}
}

// Check for detail/create views.
if ( isset( $_GET['invoice_id'] ) ) {
	include SPP_PLUGIN_DIR . 'admin/partials/invoice-detail.php';
	return;
}
if ( isset( $_GET['action'] ) && 'create' === $_GET['action'] ) {
	include SPP_PLUGIN_DIR . 'admin/partials/create-invoice.php';
	return;
}

// Database metrics.
global $wpdb;
$invoices_table = $wpdb->prefix . 'spp_invoices';

// Date ranges.
$current_month_start = gmdate( 'Y-m-01 00:00:00' );
$current_month_end = gmdate( 'Y-m-t 23:59:59' );
$today = gmdate( 'Y-m-d' );

// Total outstanding (unpaid).
$total_outstanding = $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM $invoices_table WHERE status = 'unpaid'" );
$unpaid_count = $wpdb->get_var( "SELECT COUNT(*) FROM $invoices_table WHERE status = 'unpaid'" );

// Overdue invoices.
$overdue_count = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $invoices_table WHERE status = 'unpaid' AND due_date < %s",
	$today
) );
$overdue_amount = $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount), 0) FROM $invoices_table WHERE status = 'unpaid' AND due_date < %s",
	$today
) );

// Paid this month.
$paid_this_month = $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount), 0) FROM $invoices_table WHERE status = 'paid' AND paid_at >= %s AND paid_at <= %s",
	$current_month_start,
	$current_month_end
) );
$paid_count_this_month = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $invoices_table WHERE status = 'paid' AND paid_at >= %s AND paid_at <= %s",
	$current_month_start,
	$current_month_end
) );

// Draft invoices.
$draft_count = $wpdb->get_var( "SELECT COUNT(*) FROM $invoices_table WHERE status = 'draft'" );

// Status breakdown.
$status_breakdown = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM $invoices_table GROUP BY status", ARRAY_A );
$status_counts = array( 'draft' => 0, 'unpaid' => 0, 'paid' => 0, 'canceled' => 0 );
foreach ( $status_breakdown as $row ) {
	$status = strtolower( $row['status'] );
	if ( isset( $status_counts[ $status ] ) ) {
		$status_counts[ $status ] = (int) $row['count'];
	}
}

// Monthly invoice totals for chart.
$monthly_data = $wpdb->get_results(
	"SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
	        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid,
	        SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) as unpaid
	 FROM $invoices_table
	 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
	 GROUP BY DATE_FORMAT(created_at, '%Y-%m')
	 ORDER BY month ASC",
	ARRAY_A
);

$chart_labels = array();
$chart_paid = array();
$chart_unpaid = array();
for ( $i = 5; $i >= 0; $i-- ) {
	$month = gmdate( 'Y-m', strtotime( "-$i months" ) );
	$chart_labels[] = gmdate( 'M', strtotime( $month . '-01' ) );
	$chart_paid[ $month ] = 0;
	$chart_unpaid[ $month ] = 0;
}
foreach ( $monthly_data as $row ) {
	if ( isset( $chart_paid[ $row['month'] ] ) ) {
		$chart_paid[ $row['month'] ] = (float) $row['paid'];
		$chart_unpaid[ $row['month'] ] = (float) $row['unpaid'];
	}
}

// Filters.
$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 20;

// Sort parameters.
$order_by = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'id';
$order = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
$allowed_orderby = array( 'id', 'invoice_number', 'amount', 'status', 'due_date', 'created_at' );
if ( ! in_array( $order_by, $allowed_orderby, true ) ) {
	$order_by = 'id';
}

$query_args = array(
	'limit'    => $per_page,
	'offset'   => ( $current_page - 1 ) * $per_page,
	'order_by' => $order_by,
	'order'    => $order,
);
if ( ! empty( $status_filter ) ) {
	$query_args['status'] = $status_filter;
}
if ( ! empty( $search ) ) {
	$query_args['search'] = $search;
}

$invoices = SPP_Database::get_invoices( $query_args );
$total_invoices = SPP_Database::get_invoices_count( $query_args );
$total_pages = ceil( $total_invoices / $per_page );
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Invoices', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Invoices', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <form method="post" style="margin: 0;">
                <?php wp_nonce_field( 'spp_sync_invoices', 'spp_sync_nonce' ); ?>
                <button type="submit" name="spp_sync_now" class="spp-button">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'cube-payment-portal' ); ?>
                </button>
            </form>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&action=create' ) ); ?>" class="spp-button">
                <span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?>
            </a>
        </div>
    </div>

	<?php if ( isset( $sync_message ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $sync_message['type'] ); ?> is-dismissible"><p><?php echo esc_html( $sync_message['text'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $bulk_message ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $bulk_message['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $bulk_message['text'] ); ?></p></div>
	<?php endif; ?>

	<!-- Metrics Row -->
	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<div>
					<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Outstanding', 'cube-payment-portal' ); ?></div>
					<div style="font-size: 26px; font-weight: 600; color: #ff9800;"><?php echo esc_html( spp_format_currency( $total_outstanding ) ); ?></div>
					<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $unpaid_count ); ?> <?php esc_html_e( 'invoices', 'cube-payment-portal' ); ?></div>
				</div>
				<div style="background: #fff3e0; padding: 10px; border-radius: 50%;">
					<span class="dashicons dashicons-clock" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
				</div>
			</div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<div>
					<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Overdue', 'cube-payment-portal' ); ?></div>
					<div style="font-size: 26px; font-weight: 600; color: #f44336;"><?php echo esc_html( spp_format_currency( $overdue_amount ) ); ?></div>
					<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $overdue_count ); ?> <?php esc_html_e( 'invoices', 'cube-payment-portal' ); ?></div>
				</div>
				<div style="background: #ffebee; padding: 10px; border-radius: 50%;">
					<span class="dashicons dashicons-warning" style="color: #f44336; font-size: 20px; width: 20px; height: 20px;"></span>
				</div>
			</div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<div>
					<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Paid This Month', 'cube-payment-portal' ); ?></div>
					<div style="font-size: 26px; font-weight: 600; color: #4caf50;"><?php echo esc_html( spp_format_currency( $paid_this_month ) ); ?></div>
					<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php echo esc_html( $paid_count_this_month ); ?> <?php esc_html_e( 'invoices', 'cube-payment-portal' ); ?></div>
				</div>
				<div style="background: #e8f5e9; padding: 10px; border-radius: 50%;">
					<span class="dashicons dashicons-yes-alt" style="color: #4caf50; font-size: 20px; width: 20px; height: 20px;"></span>
				</div>
			</div>
		</div>

		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<div>
					<div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Drafts', 'cube-payment-portal' ); ?></div>
					<div style="font-size: 26px; font-weight: 600; color: #9e9e9e;"><?php echo esc_html( $draft_count ); ?></div>
					<div style="font-size: 12px; color: #999; margin-top: 5px;"><?php esc_html_e( 'unpublished', 'cube-payment-portal' ); ?></div>
				</div>
				<div style="background: #f5f5f5; padding: 10px; border-radius: 50%;">
					<span class="dashicons dashicons-edit" style="color: #9e9e9e; font-size: 20px; width: 20px; height: 20px;"></span>
				</div>
			</div>
		</div>
	</div>

	<!-- Charts Row -->
	<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
		<!-- Monthly Chart -->
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Invoice Trends (6 Months)', 'cube-payment-portal' ); ?></h3>
			<div style="height: 200px;">
				<canvas id="invoiceChart"></canvas>
			</div>
		</div>

		<!-- Status Breakdown -->
		<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Status Overview', 'cube-payment-portal' ); ?></h3>
			<?php if ( array_sum( $status_counts ) > 0 ) : ?>
				<div style="height: 200px; display: flex; align-items: center; justify-content: center;">
					<canvas id="statusChart"></canvas>
				</div>
			<?php else : ?>
				<p style="color: #999; text-align: center; margin: 60px 0;"><?php esc_html_e( 'No data yet', 'cube-payment-portal' ); ?></p>
			<?php endif; ?>
		</div>
	</div>



	<!-- Filters -->
	<div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
		<form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
			<input type="hidden" name="page" value="spp-invoices">
			
			<select name="status" style="min-width: 150px;">
				<option value=""><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
				<option value="draft" <?php selected( $status_filter, 'draft' ); ?>><?php esc_html_e( 'Draft', 'cube-payment-portal' ); ?></option>
				<option value="scheduled" <?php selected( $status_filter, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'cube-payment-portal' ); ?></option>
				<option value="unpaid" <?php selected( $status_filter, 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'cube-payment-portal' ); ?></option>
				<option value="paid" <?php selected( $status_filter, 'paid' ); ?>><?php esc_html_e( 'Paid', 'cube-payment-portal' ); ?></option>
				<option value="overdue" <?php selected( $status_filter, 'overdue' ); ?>><?php esc_html_e( 'Overdue', 'cube-payment-portal' ); ?></option>
				<option value="canceled" <?php selected( $status_filter, 'canceled' ); ?>><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></option>
			</select>
			
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customers...', 'cube-payment-portal' ); ?>" style="min-width: 250px;">
			
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
			
			<?php if ( ! empty( $status_filter ) || ! empty( $search ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'cube-payment-portal' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<!-- Loading -->
	<div id="spp-invoices-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
		<span class="spinner is-active" style="float: none;"></span>
		<p style="color: #666;"><?php esc_html_e( 'Loading invoices...', 'cube-payment-portal' ); ?></p>
	</div>

	<!-- Empty State -->
	<div id="spp-invoices-empty" style="display: none; background: #fff; padding: 60px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
		<span class="dashicons dashicons-media-spreadsheet" style="font-size: 64px; color: #ccc; width: 64px; height: 64px;"></span>
		<h2 style="color: #666;"><?php esc_html_e( 'No Invoices Found', 'cube-payment-portal' ); ?></h2>
		<p style="color: #999;"><?php esc_html_e( 'Create your first invoice to get started.', 'cube-payment-portal' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices&action=create' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?></a>
	</div>

	<!-- Bulk Actions -->
	<div id="spp-invoices-bulk-wrap" style="display: none; margin-bottom: 15px;">
		<form method="post" id="spp-bulk-form" style="display: flex; gap: 10px; align-items: center;">
			<?php wp_nonce_field( 'spp_bulk_invoices', 'spp_bulk_nonce' ); ?>
			<select name="spp_bulk_action" style="max-width: 180px;">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'cube-payment-portal' ); ?></option>
				<option value="cancel"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete (Draft)', 'cube-payment-portal' ); ?></option>
			</select>
			<button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'cube-payment-portal' ); ?>');"><?php esc_html_e( 'Apply', 'cube-payment-portal' ); ?></button>
			<span id="spp-selected-count" style="color: #666; font-size: 13px;"></span>
		</form>
	</div>

	<!-- Invoices Table -->
	<div id="spp-invoices-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
		<div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
			<h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Invoices', 'cube-payment-portal' ); ?></h3>
			<span style="color: #666; font-size: 13px;"><span id="spp-invoices-showing-count">0</span> <?php esc_html_e( 'invoices', 'cube-payment-portal' ); ?></span>
		</div>
		
		<table class="wp-list-table widefat fixed striped" style="margin: 0;">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="spp-select-all"></td>
					<th class="spp-sortable" data-sort="invoice_number" style="width: 100px; cursor: pointer;">
						<?php esc_html_e( 'Invoice #', 'cube-payment-portal' ); ?>
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
					<th class="spp-sortable" data-sort="due_date" style="width: 120px; cursor: pointer;">
						<?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
					<th class="spp-sortable" data-sort="created_at" style="width: 120px; cursor: pointer;">
						<?php esc_html_e( 'Created', 'cube-payment-portal' ); ?>
						<span class="spp-sort-indicator dashicons"></span>
					</th>
				</tr>
			</thead>
			<tbody id="spp-invoices-tbody"></tbody>
		</table>

		<div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
			<div class="tablenav-pages" id="spp-invoices-pagination">
				<span class="displaying-num" id="spp-invoices-total-count"></span>
				<span class="pagination-links" id="spp-invoices-pagination-links"></span>
			</div>
		</div>
	</div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
	// Invoice Trends Chart
	const invoiceCtx = document.getElementById('invoiceChart');
	if (invoiceCtx) {
		new Chart(invoiceCtx, {
			type: 'bar',
			data: {
				labels: <?php echo wp_json_encode( $chart_labels ); ?>,
				datasets: [
					{
						label: '<?php esc_html_e( 'Paid', 'cube-payment-portal' ); ?>',
						data: <?php echo wp_json_encode( array_values( $chart_paid ) ); ?>,
						backgroundColor: '#4caf50',
						borderRadius: 4
					},
					{
						label: '<?php esc_html_e( 'Unpaid', 'cube-payment-portal' ); ?>',
						data: <?php echo wp_json_encode( array_values( $chart_unpaid ) ); ?>,
						backgroundColor: '#ff9800',
						borderRadius: 4
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15 } } },
				scales: {
					y: { beginAtZero: true, stacked: true, ticks: { callback: function(v) { return '$' + v; } } },
					x: { stacked: true, grid: { display: false } }
				}
			}
		});
	}

	// Status Doughnut
	const statusCtx = document.getElementById('statusChart');
	if (statusCtx) {
		new Chart(statusCtx, {
			type: 'doughnut',
			data: {
				labels: ['<?php esc_html_e( 'Paid', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Unpaid', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Draft', 'cube-payment-portal' ); ?>', '<?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?>'],
				datasets: [{
					data: [<?php echo (int) $status_counts['paid']; ?>, <?php echo (int) $status_counts['unpaid']; ?>, <?php echo (int) $status_counts['draft']; ?>, <?php echo (int) $status_counts['canceled']; ?>],
					backgroundColor: ['#4caf50', '#ff9800', '#9e9e9e', '#f44336'],
					borderWidth: 0
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10, font: { size: 11 } } } },
				cutout: '65%'
			}
		});
	}
});

jQuery(document).ready(function($) {
	'use strict';

	var SPPInvoices = {
		currentPage: 1,
		perPage: 20,
		sortBy: 'invoice_number',
		sortOrder: 'DESC',

		init: function() {
			this.bindEvents();
			this.updateSortIndicators();
			this.loadInvoices();
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
					self.sortOrder = 'DESC';
				}
				self.currentPage = 1;
				self.updateSortIndicators();
				self.loadInvoices();
			});

			// Pagination
			$(document).on('click', '.spp-page-link', function(e) {
				e.preventDefault();
				var page = $(this).data('page');
				if (page && page !== self.currentPage) {
					self.currentPage = page;
					self.loadInvoices();
				}
			});

			// Select all checkbox
			$('#spp-select-all').on('change', function() {
				$('.spp-invoice-checkbox').prop('checked', this.checked);
				self.updateSelectedCount();
			});

			// Individual checkbox
			$(document).on('change', '.spp-invoice-checkbox', function() {
				self.updateSelectedCount();
				$('#spp-select-all').prop('checked', 
					$('.spp-invoice-checkbox:checked').length === $('.spp-invoice-checkbox').length && 
					$('.spp-invoice-checkbox').length > 0
				);
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

		updateSelectedCount: function() {
			var count = $('.spp-invoice-checkbox:checked').length;
			$('#spp-selected-count').text(count > 0 ? count + ' selected' : '');
		},

		loadInvoices: function() {
			var self = this;
			
			$('#spp-invoices-loading').show();
			$('#spp-invoices-table-wrap').hide();
			$('#spp-invoices-empty').hide();
			$('#spp-invoices-bulk-wrap').hide();

			$.ajax({
				url: sppAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spp_get_invoices',
					nonce: sppAdmin.nonce,
					search: '',
					status: '',
					page: self.currentPage,
					per_page: self.perPage,
					sort_by: self.sortBy,
					sort_order: self.sortOrder
				},
				success: function(response) {
					$('#spp-invoices-loading').hide();

					if (!response || !response.success) {
						var errorMsg = (response && response.data && response.data.message) 
							? response.data.message 
							: '<?php echo esc_js( __( 'Failed to load invoices.', 'cube-payment-portal' ) ); ?>';
						$('#spp-invoices-empty').show().find('h2').text(errorMsg);
						return;
					}

					var data = response.data;
					if (!data || !data.invoices || data.invoices.length === 0) {
						$('#spp-invoices-empty').show();
						return;
					}

					self.renderInvoices(data.invoices);
					self.renderPagination(data.pagination);
					$('#spp-invoices-table-wrap').show();
					$('#spp-invoices-bulk-wrap').show();
				},
				error: function() {
					$('#spp-invoices-loading').hide();
					$('#spp-invoices-empty').show().find('h2').text('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
				}
			});
		},

		renderInvoices: function(invoices) {
			var $tbody = $('#spp-invoices-tbody');
			$tbody.empty();

			var self = this;
			$.each(invoices, function(i, inv) {
				var row = self.buildRow(inv);
				$tbody.append(row);
			});

			$('#spp-invoices-showing-count').text(invoices.length);
			$('#spp-select-all').prop('checked', false);
			self.updateSelectedCount();
		},

		buildRow: function(inv) {
		var statusClass = this.getStatusClass(inv.status, inv.is_overdue);
		var currencySymbol = this.getCurrencySymbol(inv.currency);
		var invoiceUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-invoices&invoice_id=' ) ); ?>' + inv.id;
		var displayNumber = inv.invoice_number || '#' + inv.id;
		var dueDateStyle = inv.is_overdue ? 'color: #f44336; font-weight: 600;' : 'color: #666;';

		// Build customer link with tooltip
		var customerHtml = '';
		if (inv.square_customer_id) {
			var customerUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-customers&customer_id=' ) ); ?>' + encodeURIComponent(inv.square_customer_id);
			var tooltipHtml = '<div class="spp-customer-tooltip">' +
					(inv.customer_email ? '<div class="tooltip-row"><span class="dashicons dashicons-email"></span> ' + this.escapeHtml(inv.customer_email) + '</div>' : '') +
					(inv.customer_phone ? '<div class="tooltip-row"><span class="dashicons dashicons-phone"></span> ' + this.escapeHtml(inv.customer_phone) + '</div>' : '') +
				'</div>';

		customerHtml = '<a href="' + customerUrl + '" class="spp-customer-link" style="text-decoration: none; font-weight: 600;" onclick="event.stopPropagation();">' + 
				this.escapeHtml(inv.customer_name) + 
				tooltipHtml + 
			'</a>';
		} else {
			customerHtml = '<strong>' + this.escapeHtml(inv.customer_name) + '</strong>';
		}

		return '<tr style="cursor: pointer;" onclick="if(event.target.type !== \'checkbox\'){window.location=\'' + invoiceUrl + '\';}">' +
			'<th scope="row" class="check-column" onclick="event.stopPropagation();">' +
				'<input type="checkbox" name="invoice_ids[]" value="' + inv.id + '" class="spp-invoice-checkbox" form="spp-bulk-form">' +
			'</th>' +
			'<td><a href="' + invoiceUrl + '" onclick="event.stopPropagation();"><strong>' + this.escapeHtml(displayNumber) + '</strong></a></td>' +
			'<td>' + customerHtml + '</td>' +
			'<td style="font-weight: 600;">' + currencySymbol + inv.amount + '</td>' +
			'<td><span class="spp-status-badge ' + statusClass + '">' + inv.status_label + '</span></td>' +
			'<td style="' + dueDateStyle + '">' + inv.due_date_formatted + '</td>' +
			'<td style="color: #666;">' + inv.created_at_formatted + '</td>' +
		'</tr>';
	},

		getStatusClass: function(status, isOverdue) {
			if (isOverdue) return 'spp-status-overdue';
			var classes = {
				paid: 'spp-status-paid',
				unpaid: 'spp-status-unpaid',
				draft: 'spp-status-draft',
				canceled: 'spp-status-canceled',
				scheduled: 'spp-status-pending'
			};
			return classes[status.toLowerCase()] || 'spp-status-pending';
		},

		getCurrencySymbol: function(currency) {
			var symbols = { EUR: '€', GBP: '£', JPY: '¥' };
			return symbols[currency] || '$';
		},

		renderPagination: function(pagination) {
			var total = pagination.total;
			var totalPages = pagination.total_pages;
			var currentPage = pagination.current_page;

			$('#spp-invoices-total-count').text(total + ' <?php echo esc_js( __( 'total', 'cube-payment-portal' ) ); ?>');

			if (totalPages <= 1) {
				$('#spp-invoices-pagination-links').empty();
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

			$('#spp-invoices-pagination-links').html(links);
		},

		escapeHtml: function(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(text));
			return div.innerHTML;
		}
	};

	SPPInvoices.init();
});
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
