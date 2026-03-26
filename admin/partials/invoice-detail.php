<?php
/**
 * Invoice Detail page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check - ensure user has permission to manage invoices.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Get invoice ID from URL.
$invoice_id = isset( $_GET['invoice_id'] ) ? intval( $_GET['invoice_id'] ) : 0;

if ( empty( $invoice_id ) ) {
	wp_die( esc_html__( 'Invalid invoice ID.', 'cube-payment-portal' ) );
}

// Get invoice from database.
$invoice = SPP_Database::get_invoice_by_id( $invoice_id );

if ( empty( $invoice ) ) {
	wp_die( esc_html__( 'Invoice not found.', 'cube-payment-portal' ) );
}

// Handle invoice actions.
$action_message = null;

if ( isset( $_POST['spp_action'] ) && wp_verify_nonce( $_POST['spp_action_nonce'], 'spp_invoice_action_' . $invoice_id ) ) {
	$action = sanitize_text_field( $_POST['spp_action'] );
	$invoices_api = new SPP_Invoices();
	$square_invoice_id = $invoice['square_invoice_id'];
	
	if ( empty( $square_invoice_id ) ) {
		$action_message = array(
			'type' => 'error',
			'text' => __( 'Cannot perform action: Invoice not synced with Square.', 'cube-payment-portal' ),
		);
	} else {
		switch ( $action ) {
			case 'publish':
				$result = $invoices_api->publish_invoice( $square_invoice_id );
				if ( is_wp_error( $result ) ) {
					$action_message = array(
						'type' => 'error',
						'text' => sprintf( __( 'Failed to publish invoice: %s', 'cube-payment-portal' ), esc_html( $result->get_error_message() ) ),
					);
				} else {
					$action_message = array(
						'type' => 'success',
						'text' => __( 'Invoice published successfully!', 'cube-payment-portal' ),
					);
					// Refresh invoice data.
					$invoice = SPP_Database::get_invoice_by_id( $invoice_id );
				}
				break;
				
			case 'cancel':
				$result = $invoices_api->cancel_invoice( $square_invoice_id );
				if ( is_wp_error( $result ) ) {
					$action_message = array(
						'type' => 'error',
						'text' => sprintf( __( 'Failed to cancel invoice: %s', 'cube-payment-portal' ), esc_html( $result->get_error_message() ) ),
					);
				} else {
					$action_message = array(
						'type' => 'success',
						'text' => __( 'Invoice canceled successfully!', 'cube-payment-portal' ),
					);
					// Refresh invoice data.
					$invoice = SPP_Database::get_invoice_by_id( $invoice_id );
				}
				break;
				
			case 'delete':
				$result = $invoices_api->delete_invoice( $square_invoice_id );
				if ( is_wp_error( $result ) ) {
					$action_message = array(
						'type' => 'error',
						'text' => sprintf( __( 'Failed to delete invoice: %s', 'cube-payment-portal' ), esc_html( $result->get_error_message() ) ),
					);
				} else {
					// Redirect to invoices list after successful delete.
					wp_redirect( add_query_arg( 
						array( 'deleted' => '1' ), 
						admin_url( 'admin.php?page=spp-invoices' ) 
					) );
					exit;
				}
				break;
		}
	}
}

// Get full invoice details from Square if we have the Square invoice ID.
$square_invoice = null;
$line_items = array();

if ( ! empty( $invoice['square_invoice_id'] ) ) {
	$invoices_api = new SPP_Invoices();
	$square_invoice = $invoices_api->get_invoice_with_details( $invoice['square_invoice_id'] );
	
	if ( ! is_wp_error( $square_invoice ) && ! empty( $square_invoice['order_details']['line_items'] ) ) {
		$line_items = $square_invoice['order_details']['line_items'];
	}
}

// Check if overdue.
$is_overdue = ( strtolower( $invoice['status'] ) === 'unpaid' && ! empty( $invoice['due_date'] ) && strtotime( $invoice['due_date'] ) < strtotime( 'today' ) );

// Helper to format money from Square (cents to dollars).
if ( ! function_exists( 'spp_format_square_money' ) ) {
	function spp_format_square_money( $money ) {
		if ( empty( $money['amount'] ) ) {
			return '$0.00';
		}
		
		$symbol = '$'; // Default to USD.
		if ( ! empty( $money['currency'] ) ) {
			if ( 'EUR' === $money['currency'] ) {
				$symbol = '€';
			} elseif ( 'GBP' === $money['currency'] ) {
				$symbol = '£';
			}
		}
		
		return $symbol . number_format( $money['amount'] / 100, 2 );
	}
}

// Get Square Dashboard URL for this invoice.
$square_dashboard_url = '';
if ( ! empty( $invoice['square_invoice_id'] ) ) {
	$environment = get_option( 'spp_environment', 'sandbox' );
	$base_url = ( 'production' === $environment ) ? 'https://squareup.com' : 'https://squareupsandbox.com';
	$square_dashboard_url = $base_url . '/dashboard/invoices/' . $invoice['square_invoice_id'];
}

// Get title and description.
$invoice_title = $square_invoice['invoice']['title'] ?? $invoice['title'] ?? '';
$invoice_description = $square_invoice['invoice']['description'] ?? $invoice['description'] ?? '';

// Generate title from line items if no title exists.
if ( empty( $invoice_title ) && ! empty( $line_items ) ) {
	if ( count( $line_items ) === 1 ) {
		$invoice_title = sprintf( 
			__( 'Invoice for %s', 'cube-payment-portal' ), 
			esc_html( $line_items[0]['name'] ?? __( 'Services', 'cube-payment-portal' ) )
		);
	} elseif ( count( $line_items ) === 2 ) {
		$invoice_title = sprintf( 
			__( 'Invoice for %s and %s', 'cube-payment-portal' ), 
			esc_html( $line_items[0]['name'] ?? __( 'Item', 'cube-payment-portal' ) ),
			esc_html( $line_items[1]['name'] ?? __( 'Item', 'cube-payment-portal' ) )
		);
	} else {
		$invoice_title = sprintf( 
			__( 'Invoice for %d items', 'cube-payment-portal' ), 
			count( $line_items ) 
		);
	}
}
?>

<style>
.spp-invoice-header {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: #fff;
	padding: 30px;
	border-radius: 8px;
	margin-bottom: 30px;
	box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.spp-invoice-amount {
	font-size: 36px;
	font-weight: 700;
	margin: 10px 0 18px 0;
}
.spp-invoice-number {
	font-size: 14px;
	opacity: 0.9;
}
.spp-info-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 15px;
	margin-top: 15px;
}
.spp-info-item {
	background: rgba(255,255,255,0.1);
	padding: 12px;
	border-radius: 6px;
}
.spp-info-label {
	font-size: 12px;
	opacity: 0.8;
	margin-bottom: 5px;
}
.spp-info-value {
	font-size: 16px;
	font-weight: 600;
}
</style>

<div class="wrap spp-admin-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Invoice Detail', 'cube-payment-portal' ); ?></h1>
	<hr class="wp-header-end">
	<!-- Header with Key Info -->
	<div class="spp-invoice-header">
		<div style="display: flex; justify-content: space-between; align-items: flex-start;">
			<div style="flex: 1;">
				<div class="spp-invoice-number">
					<?php 
					if ( ! empty( $invoice['invoice_number'] ) ) {
						printf( esc_html__( 'Invoice #%s', 'cube-payment-portal' ), esc_html( $invoice['invoice_number'] ) );
					} else {
						printf( esc_html__( 'Invoice ID: %s', 'cube-payment-portal' ), esc_html( $invoice['id'] ) );
					}
					?>
				</div>
				<?php if ( ! empty( $invoice_title ) ) : ?>
					<h2 style="margin: 5px 0 0 0; color: #fff; font-size: 28px;">
						<?php echo esc_html( $invoice_title ); ?>
					</h2>
				<?php endif; ?>
				<div class="spp-invoice-amount">
					<?php echo esc_html( spp_format_currency( $invoice['amount'], $invoice['currency'] ) ); ?>
				</div>
				<span class="spp-status-badge <?php echo esc_attr( spp_get_status_badge_class( $invoice['status'], $is_overdue ) ); ?>" style="font-size: 14px; background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 20px; display: inline-block; margin-top: 12px;">
					<?php 
					if ( $is_overdue ) {
						esc_html_e( 'Overdue', 'cube-payment-portal' );
					} else {
						echo esc_html( ucfirst( $invoice['status'] ) ); 
					}
					?>
				</span>
			</div>
			<div style="display: flex; gap: 10px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices' ) ); ?>" class="button" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: #fff;">
					<span class="dashicons dashicons-arrow-left-alt2" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Back', 'cube-payment-portal' ); ?>
				</a>
				<?php if ( ! empty( $square_invoice['invoice']['public_url'] ) ) : ?>
					<a href="<?php echo esc_url( $square_invoice['invoice']['public_url'] ); ?>" target="_blank" class="button" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: #fff;">
						<span class="dashicons dashicons-money-alt" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Payment Link', 'cube-payment-portal' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $square_dashboard_url ) ) : ?>
					<a href="<?php echo esc_url( $square_dashboard_url ); ?>" target="_blank" class="button" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: #fff;">
						<?php esc_html_e( 'Square', 'cube-payment-portal' ); ?>
						<span class="dashicons dashicons-external" style="margin-top: 3px;"></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		
		<!-- Key Info Grid -->
		<div class="spp-info-grid">
			<div class="spp-info-item">
				<div class="spp-info-label"><?php esc_html_e( 'Created', 'cube-payment-portal' ); ?></div>
				<div class="spp-info-value">
					<?php echo ! empty( $invoice['created_at'] ) ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $invoice['created_at'] ) ) ) : '—'; ?>
				</div>
			</div>
			<div class="spp-info-item">
				<div class="spp-info-label"><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></div>
				<div class="spp-info-value">
					<?php echo ! empty( $invoice['due_date'] ) ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $invoice['due_date'] ) ) ) : '—'; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if ( isset( $action_message ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $action_message['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $action_message['text'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
		<!-- Left Column -->
		<div>
			<!-- Customer Information -->
			<div class="spp-admin-card">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-businessman" style="color: #2271b1;"></span>
					<?php esc_html_e( 'Bill To', 'cube-payment-portal' ); ?>
				</h2>
				<div>
					<div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
					<?php 
					// Try to get name from Square data first (most accurate)
					$display_customer_name = '';
					if ( ! empty( $square_invoice['invoice']['primary_recipient'] ) ) {
						$recipient = $square_invoice['invoice']['primary_recipient'];
						if ( ! empty( $recipient['given_name'] ) || ! empty( $recipient['family_name'] ) ) {
							$display_customer_name = trim( ( $recipient['given_name'] ?? '' ) . ' ' . ( $recipient['family_name'] ?? '' ) );
						} elseif ( ! empty( $recipient['company_name'] ) ) {
							$display_customer_name = $recipient['company_name'];
						}
					}
					
					// Fallback to customer_name_square from database
					if ( empty( $display_customer_name ) && ! empty( $invoice['customer_name_square'] ) ) {
						$display_customer_name = $invoice['customer_name_square'];
					}
					
					// Last fallback to customer_name (which may be WP display_name)
					if ( empty( $display_customer_name ) && ! empty( $invoice['customer_name'] ) ) {
						$display_customer_name = $invoice['customer_name'];
					}
					
					$display_customer_name = $display_customer_name ?: __( 'Unknown Customer', 'cube-payment-portal' );
					
					// Get customer ID from invoice or Square data
					$customer_id = $invoice['square_customer_id'] ?? ( $square_invoice['invoice']['primary_recipient']['customer_id'] ?? '' );
					
					if ( ! empty( $customer_id ) ) :
						$customer_detail_url = admin_url( 'admin.php?page=spp-customers&customer_id=' . urlencode( $customer_id ) );
					?>
						<a href="<?php echo esc_url( $customer_detail_url ); ?>" style="text-decoration: none; color: #1d2327;
						 transition: color 0.15s;"
						   onmouseover="this.style.color='#2271b1'; this.style.textDecoration='underline';"
						   onmouseout="this.style.color='#1d2327'; this.style.textDecoration='none';">
							<?php echo esc_html( $display_customer_name ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $display_customer_name ); ?>
					<?php endif; ?>
				</div>
					<div style="color: #666; margin-bottom: 5px;">
						<span class="dashicons dashicons-email" style="font-size: 16px; width: 16px; height: 16px;"></span>
						<?php 
						if ( ! empty( $invoice['customer_email'] ) ) {
							echo esc_html( $invoice['customer_email'] );
						} elseif ( ! empty( $square_invoice['invoice']['primary_recipient']['email_address'] ) ) {
							echo esc_html( $square_invoice['invoice']['primary_recipient']['email_address'] );
						} else {
							esc_html_e( 'No email', 'cube-payment-portal' );
						}
						?>
					</div>
					<div style="color: #666; margin-bottom: 5px;">
						<span class="dashicons dashicons-phone" style="font-size: 16px; width: 16px; height: 16px;"></span>
						<?php 
						if ( ! empty( $invoice['customer_phone'] ) ) {
							echo esc_html( $invoice['customer_phone'] );
						} elseif ( ! empty( $square_invoice['invoice']['primary_recipient']['phone_number'] ) ) {
							echo esc_html( $square_invoice['invoice']['primary_recipient']['phone_number'] );
						} else {
							esc_html_e( 'No phone', 'cube-payment-portal' );
						}
						?>
					</div>
					<?php if ( ! empty( $invoice['user_id'] ) ) : ?>
						<div style="margin-top: 10px;">
							<a href="<?php echo esc_url( get_edit_user_link( $invoice['user_id'] ) ); ?>" class="button button-small">
								<?php esc_html_e( 'View WordPress User', 'cube-payment-portal' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Notes (always show) -->
			<div class="spp-admin-card" style="margin-top: 20px;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-text-page" style="color: #2271b1;"></span>
					<?php esc_html_e( 'Notes', 'cube-payment-portal' ); ?>
				</h2>
				<div style="line-height: 1.6;">
					<?php 
					if ( ! empty( $invoice_description ) ) {
						echo esc_html( trim( $invoice_description ) );
					} else {
						echo '<em style="color: #999;">' . esc_html__( 'No notes added to this invoice.', 'cube-payment-portal' ) . '</em>';
					}
					?>
				</div>
			</div>

			<!-- Line Items -->
			<?php if ( ! empty( $line_items ) ) : ?>
				<div class="spp-admin-card" style="margin-top: 20px;">
					<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
						<span class="dashicons dashicons-cart" style="color: #2271b1;"></span>
						<?php esc_html_e( 'Items', 'cube-payment-portal' ); ?>
					</h2>
					<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
						<thead>
							<tr style="background: #f8f9fa;">
								<th style="padding: 12px;"><?php esc_html_e( 'Item', 'cube-payment-portal' ); ?></th>
								<th style="text-align: center; width: 80px; padding: 12px;"><?php esc_html_e( 'Qty', 'cube-payment-portal' ); ?></th>
								<th style="text-align: right; width: 120px; padding: 12px;"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></th>
								<th style="text-align: right; width: 120px; padding: 12px;"><?php esc_html_e( 'Total', 'cube-payment-portal' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php 
							$subtotal = 0;
							foreach ( $line_items as $item ) : 
								$quantity = ! empty( $item['quantity'] ) ? intval( $item['quantity'] ) : 1;
								$unit_price = ! empty( $item['base_price_money'] ) ? $item['base_price_money']['amount'] / 100 : 0;
								$total_price = ! empty( $item['total_money'] ) ? $item['total_money']['amount'] / 100 : ( $unit_price * $quantity );
								$subtotal += $total_price;
							?>
								<tr>
									<td style="padding: 12px;">
										<div style="font-weight: 600; margin-bottom: 4px;"><?php echo esc_html( $item['name'] ?? 'Item' ); ?></div>
										<?php if ( ! empty( $item['note'] ) ) : ?>
											<div style="font-size: 13px; color: #666;"><?php echo esc_html( $item['note'] ); ?></div>
										<?php endif; ?>
									</td>
									<td style="text-align: center; padding: 12px;"><?php echo esc_html( (string) $quantity ); ?></td>
									<td style="text-align: right; padding: 12px; color: #666;"><?php echo esc_html( spp_format_currency( $unit_price, $invoice['currency'] ) ); ?></td>
									<td style="text-align: right; padding: 12px; font-weight: 600;"><?php echo esc_html( spp_format_currency( $total_price, $invoice['currency'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr style="background: #f8f9fa; border-top: 2px solid #2271b1;">
								<td colspan="3" style="text-align: right; padding: 15px; font-size: 18px; font-weight: 700;">
									<?php esc_html_e( 'Total:', 'cube-payment-portal' ); ?>
								</td>
								<td style="text-align: right; padding: 15px; font-size: 18px; font-weight: 700; color: #2271b1;">
									<?php echo esc_html( spp_format_currency( $invoice['amount'], $invoice['currency'] ) ); ?>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<!-- Right Column - Sidebar -->
		<div>
			<!-- Reference Numbers -->
			<div class="spp-admin-card" style="background: #f8f9fa; border-left: 4px solid #2271b1;">
				<div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
					<div style="font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
						<?php esc_html_e( 'Invoice Number', 'cube-payment-portal' ); ?>
					</div>
					<div style="font-size: 16px; font-weight: 700; color: #1e3a5f;">
						<?php echo esc_html( $invoice['invoice_number'] ?? 'N/A' ); ?>
					</div>
				</div>
				<div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
					<div style="font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
						<?php esc_html_e( 'Internal ID', 'cube-payment-portal' ); ?>
					</div>
					<div style="font-size: 14px; font-family: monospace; color: #666;">
						#<?php echo esc_html( $invoice['id'] ); ?>
					</div>
				</div>
				<?php if ( ! empty( $invoice['paid_at'] ) ) : ?>
					<div style="padding: 8px 0;">
						<div style="font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
							<?php esc_html_e( 'Paid On', 'cube-payment-portal' ); ?>
						</div>
						<div style="font-size: 14px; font-weight: 600; color: #46b450;">
							<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $invoice['paid_at'] ) ) ); ?>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $square_invoice['invoice']['creator_team_member_id'] ) ) : ?>
					<div style="padding: 8px 0;">
						<div style="font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
							<?php esc_html_e( 'Created By', 'cube-payment-portal' ); ?>
						</div>
						<div style="font-size: 14px; color: #333;">
							<span class="dashicons dashicons-admin-users" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; color: #666;"></span>
							<code style="font-size: 12px;"><?php echo esc_html( $square_invoice['invoice']['creator_team_member_id'] ); ?></code>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Actions -->
			<div class="spp-admin-card" style="margin-top: 20px;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-admin-tools" style="color: #2271b1;"></span>
					<?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?>
				</h2>
				<div style="display: flex; flex-direction: column; gap: 12px;">
					<?php if ( 'draft' === strtolower( $invoice['status'] ) ) : ?>
						<form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Publish and send this invoice to the customer?', 'cube-payment-portal' ); ?>');">
							<?php wp_nonce_field( 'spp_invoice_action_' . $invoice_id, 'spp_action_nonce' ); ?>
							<input type="hidden" name="spp_action" value="publish">
							<button type="submit" class="button button-primary button-large" style="width: 100%; height: auto; padding: 12px; font-size: 14px;">
								<span class="dashicons dashicons-email-alt" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'Publish & Send', 'cube-payment-portal' ); ?>
							</button>
						</form>
					
						<form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Permanently delete this invoice? This cannot be undone!', 'cube-payment-portal' ); ?>');">
							<?php wp_nonce_field('spp_invoice_action_' . $invoice_id, 'spp_action_nonce' ); ?>
							<input type="hidden" name="spp_action" value="delete">
							<button type="submit" class="button button-large" style="width: 100%; height: auto; padding: 12px; color: #d63638; border-color: #d63638;">
								<span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'Delete', 'cube-payment-portal' ); ?>
							</button>
						</form>
					<?php endif; ?>
					
					<?php if ( in_array( strtolower( $invoice['status'] ), array( 'unpaid', 'overdue', 'scheduled' ), true ) ) : ?>
						<form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Cancel this invoice? This cannot be undone.', 'cube-payment-portal' ); ?>');">
							<?php wp_nonce_field( 'spp_invoice_action_' . $invoice_id, 'spp_action_nonce' ); ?>
							<input type="hidden" name="spp_action" value="cancel">
							<button type="submit" class="button button-large" style="width: 100%; height: auto; padding: 12px;">
								<span class="dashicons dashicons-no" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'Cancel Invoice', 'cube-payment-portal' ); ?>
							</button>
						</form>
					<?php endif; ?>
					
					<?php if ( 'paid' === strtolower( $invoice['status'] ) ) : ?>
						<div style="text-align: center; padding: 30px 20px; background: #f0f9f4; border-radius: 8px; border: 2px solid #46b450;">
							<span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #46b450; width: 48px; height: 48px;"></span>
							<div style="font-size: 16px; font-weight: 600; color: #46b450; margin-top: 10px;">
								<?php esc_html_e( 'Paid in Full', 'cube-payment-portal' ); ?>
							</div>
						</div>
					<?php endif; ?>
					
					<?php if ( in_array( strtolower( $invoice['status'] ), array( 'canceled', 'deleted' ), true ) ) : ?>
						<div style="text-align: center; padding: 20px; color: #999;">
							<span class="dashicons dashicons-dismiss" style="font-size: 32px;"></span>
							<div style="margin-top: 8px;"><?php esc_html_e( 'No actions available', 'cube-payment-portal' ); ?></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
