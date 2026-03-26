<?php
/**
 * Create Invoice form page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check - ensure user can manage invoices.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Handle error messages from URL parameters.
$error_message = null;
if ( isset( $_GET['error'] ) ) {
	$error_code = sanitize_text_field( wp_unslash( $_GET['error'] ) );
	$error_messages = array(
		'invalid_date_format'  => __( 'Invalid due date format. Please use the date picker.', 'cube-payment-portal' ),
		'past_due_date'        => __( 'Due date cannot be in the past.', 'cube-payment-portal' ),
		'missing_customer'     => __( 'Please select a customer.', 'cube-payment-portal' ),
		'missing_line_items'   => __( 'At least one line item is required.', 'cube-payment-portal' ),
		'creation_failed'      => __( 'Failed to create invoice.', 'cube-payment-portal' ),
		'publish_failed'       => __( 'Invoice created but failed to publish.', 'cube-payment-portal' ),
	);
	
	$error_message = $error_messages[ $error_code ] ?? __( 'An error occurred.', 'cube-payment-portal' );
	
	// Check for detailed error message in transient (more secure than URL params).
	$transient_key = 'spp_invoice_error_' . get_current_user_id();
	$detailed_error = get_transient( $transient_key );
	if ( $detailed_error ) {
		$error_message .= ' ' . esc_html( $detailed_error );
		delete_transient( $transient_key );
	}
}

// Get customers from local database (synced from Square) - with caching.
$synced_customers = get_transient( 'spp_customer_list_cache' );
if ( false === $synced_customers ) {
	$synced_customers = SPP_Database::get_customers( array( 'limit' => 200 ) );
	set_transient( 'spp_customer_list_cache', $synced_customers, 5 * MINUTE_IN_SECONDS );
}

// Get WordPress users not yet synced (fallback).
$wp_users = get_users( array( 'number' => 100 ) );

// Get default due date (30 days from now).
$default_due_date = wp_date( 'Y-m-d', strtotime( '+30 days' ) );

// Get catalog items from Square for autocomplete.
$catalog_items = array();
if ( ! class_exists( 'SPP_Catalog' ) ) {
	require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}
$catalog = new SPP_Catalog();
$catalog_response = $catalog->get_catalog_items();

if ( ! is_wp_error( $catalog_response ) && ! empty( $catalog_response['objects'] ) ) {
	foreach ( $catalog_response['objects'] as $item ) {
		if ( 'ITEM' === $item['type'] && ! empty( $item['item_data'] ) ) {
			$item_name = $item['item_data']['name'] ?? '';
			$item_description = $item['item_data']['description'] ?? '';
			
			foreach ( $item['item_data']['variations'] ?? array() as $variation ) {
				$variation_name = $variation['item_variation_data']['name'] ?? 'Regular';
				$price_cents = $variation['item_variation_data']['price_money']['amount'] ?? 0;
				$price_dollars = SPP_Currency::cents_to_dollars( $price_cents );
				
				$full_name = $item_name;
				if ( 'Regular' !== $variation_name ) {
					$full_name .= ' - ' . $variation_name;
				}
				
				$catalog_items[] = array(
					'name'        => $full_name,
					'description' => $item_description,
					'price'       => $price_dollars,
					'catalog_id'  => $variation['id'] ?? '',
				);
			}
		}
	}
}
?>

<style>
/* jQuery UI Autocomplete custom styling */
.ui-autocomplete {
	max-height: 300px;
	overflow-y: auto;
	overflow-x: hidden;
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	box-shadow: 0 2px 8px rgba(0,0,0,0.1);
	z-index: 10000 !important;
}
.ui-menu-item {
	padding: 0;
	margin: 0;
	list-style: none;
}
.ui-menu-item .ui-menu-item-wrapper {
	padding: 10px 15px;
	cursor: pointer;
	border-bottom: 1px solid #f0f0f0;
	font-size: 14px;
}
.ui-menu-item .ui-menu-item-wrapper:hover,
.ui-menu-item .ui-menu-item-wrapper.ui-state-active {
	background: #2271b1;
	color: #fff;
	margin: 0;
	border: none;
}
.ui-helper-hidden-accessible {
	display: none;
}
</style>

<div class="wrap spp-admin-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?></h1>
	<hr class="wp-header-end">
	<h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-invoices' ) ); ?>" class="page-title-action" style="margin-right: 10px;">
			&larr; <?php esc_html_e( 'Back to Invoices', 'cube-payment-portal' ); ?>
		</a>
		<?php esc_html_e( 'Create Invoice', 'cube-payment-portal' ); ?>
	</h2>

	<?php if ( $error_message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $error_message ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="" id="spp-create-invoice-form">
		<?php wp_nonce_field( 'spp_create_invoice', 'spp_invoice_nonce' ); ?>
		
		<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
			
			<!-- Main Content Column -->
			<div>
				<!-- Customer Selection -->
				<div class="spp-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></h2>
					
					<select name="customer_id" id="customer_id" class="regular-text" style="width: 100%;" required>
						<option value=""><?php esc_html_e( '-- Select Customer --', 'cube-payment-portal' ); ?></option>
						
						<?php if ( ! empty( $synced_customers ) ) : ?>
							<?php
							// Split customers into linked and unlinked.
							$linked = array_filter( $synced_customers, fn( $c ) => ! empty( $c['wp_user_id'] ) );
							$unlinked = array_filter( $synced_customers, fn( $c ) => empty( $c['wp_user_id'] ) );
							?>
							
							<?php if ( ! empty( $linked ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'Linked Customers', 'cube-payment-portal' ); ?>">
									<?php foreach ( $linked as $customer ) :
										$name = trim( $customer['given_name'] . ' ' . $customer['family_name'] );
										$name = $name ?: $customer['company_name'] ?: __( 'Unnamed', 'cube-payment-portal' );
									?>
										<option value="<?php echo esc_attr( $customer['wp_user_id'] ); ?>">
											<?php echo esc_html( $name . ( $customer['email'] ? ' (' . $customer['email'] . ')' : '' ) ); ?> ✓
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
							
							<?php if ( ! empty( $unlinked ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'Square Only (No WP User)', 'cube-payment-portal' ); ?>">
									<?php foreach ( $unlinked as $customer ) :
										$name = trim( $customer['given_name'] . ' ' . $customer['family_name'] );
										$name = $name ?: $customer['company_name'] ?: __( 'Unnamed', 'cube-payment-portal' );
									?>
										<option value="sq_<?php echo esc_attr( $customer['square_customer_id'] ); ?>">
											<?php echo esc_html( $name . ( $customer['email'] ? ' (' . $customer['email'] . ')' : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						<?php else : ?>
							<!-- Fallback to WP users if no customers synced -->
							<?php if ( ! empty( $wp_users ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'WordPress Users', 'cube-payment-portal' ); ?>">
									<?php foreach ( $wp_users as $user ) : ?>
										<option value="<?php echo esc_attr( $user->ID ); ?>">
											<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						<?php endif; ?>
					</select>
					<p class="description">
						<?php if ( empty( $synced_customers ) ) : ?>
							<strong><?php esc_html_e( 'No customers synced.', 'cube-payment-portal' ); ?></strong>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers' ) ); ?>"><?php esc_html_e( 'Sync customers first', 'cube-payment-portal' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Select a customer. ✓ = Linked to WordPress user.', 'cube-payment-portal' ); ?>
						<?php endif; ?>
					</p>
				</div>

				<!-- Line Items -->
				<div class="spp-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Line Items', 'cube-payment-portal' ); ?></h2>
					
					<table class="wp-list-table widefat striped" id="spp-line-items-table">
						<thead>
							<tr>
								<th style="width: 35%;"><?php esc_html_e( 'Item', 'cube-payment-portal' ); ?></th>
								<th style="width: 25%;"><?php esc_html_e( 'Description', 'cube-payment-portal' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Qty', 'cube-payment-portal' ); ?></th>
								<th style="width: 15%;"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Total', 'cube-payment-portal' ); ?></th>
								<th style="width: 5%;"></th>
							</tr>
						</thead>
						<tbody id="spp-line-items-body">
							<tr class="spp-line-item-row">
								<td>
									<input type="text" name="line_item_name[]" class="regular-text spp-item-name-autocomplete" style="width: 100%;" placeholder="<?php esc_attr_e( 'Item name', 'cube-payment-portal' ); ?>" required autocomplete="off">
								</td>
								<td>
									<input type="text" name="line_item_note[]" class="regular-text spp-item-description" style="width: 100%;" placeholder="<?php esc_attr_e( 'Description', 'cube-payment-portal' ); ?>">
								</td>
								<td>
									<input type="number" name="line_item_qty[]" class="spp-qty-input" value="1" min="0.01" step="0.01" style="width: 100%;">
								</td>
								<td>
									<div style="display: flex; align-items: center;">
										<span>$</span>
										<input type="number" name="line_item_price[]" class="spp-price-input" value="0.00" min="0" step="0.01" style="width: 100%;">
									</div>
								</td>
								<td class="spp-line-total" style="font-weight: bold;">$0.00</td>
								<td>
									<button type="button" class="button spp-remove-line-item" title="<?php esc_attr_e( 'Remove', 'cube-payment-portal' ); ?>">&times;</button>
								</td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="6" style="padding: 15px;">
									<button type="button" class="button button-secondary" id="spp-add-line-item">
										<span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
										<?php esc_html_e( 'Add Line Item', 'cube-payment-portal' ); ?>
									</button>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>

				<!-- Invoice Details -->
				<div class="spp-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Invoice Details', 'cube-payment-portal' ); ?></h2>
					
					<table class="form-table">
						<tr>
							<th><label for="invoice_title"><?php esc_html_e( 'Invoice Title', 'cube-payment-portal' ); ?></label></th>
							<td>
								<input type="text" name="invoice_title" id="invoice_title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Website Development', 'cube-payment-portal' ); ?>">
							</td>
						</tr>
						<tr>
							<th><label for="invoice_description"><?php esc_html_e( 'Notes', 'cube-payment-portal' ); ?></label></th>
							<td>
								<textarea name="invoice_description" id="invoice_description" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Additional notes or terms', 'cube-payment-portal' ); ?>"></textarea>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Sidebar Column -->
			<div>
				<!-- Summary -->
				<div class="spp-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Summary', 'cube-payment-portal' ); ?></h2>
					
					<table style="width: 100%; border-collapse: collapse;">
						<tr>
							<td style="padding: 8px 0;"><?php esc_html_e( 'Subtotal', 'cube-payment-portal' ); ?></td>
							<td style="text-align: right; padding: 8px 0;" id="spp-subtotal">$0.00</td>
						</tr>
						<tr>
							<td style="padding: 8px 0; border-top: 2px solid #000; font-weight: bold; font-size: 18px;">
								<?php esc_html_e( 'Total', 'cube-payment-portal' ); ?>
							</td>
							<td style="text-align: right; padding: 8px 0; border-top: 2px solid #000; font-weight: bold; font-size: 18px;" id="spp-total">
								$0.00
							</td>
						</tr>
					</table>
				</div>

				<!-- Payment Settings -->
				<div class="spp-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Payment Settings', 'cube-payment-portal' ); ?></h2>
					
					<p>
						<label for="due_date"><strong><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></strong></label><br>
						<input type="date" name="due_date" id="due_date" value="<?php echo esc_attr( $default_due_date ); ?>" style="width: 100%;" required>
					</p>
					
					<p>
						<label for="payment_type"><strong><?php esc_html_e( 'Payment Type', 'cube-payment-portal' ); ?></strong></label><br>
						<select name="payment_type" id="payment_type" style="width: 100%;">
							<option value="BALANCE"><?php esc_html_e( 'Full Balance', 'cube-payment-portal' ); ?></option>
							<option value="DEPOSIT"><?php esc_html_e( 'Deposit', 'cube-payment-portal' ); ?></option>
						</select>
					</p>
					
					<p>
						<label for="delivery_method"><strong><?php esc_html_e( 'Delivery Method', 'cube-payment-portal' ); ?></strong></label><br>
						<select name="delivery_method" id="delivery_method" style="width: 100%;">
							<option value="EMAIL"><?php esc_html_e( 'Email', 'cube-payment-portal' ); ?></option>
							<option value="SMS"><?php esc_html_e( 'SMS', 'cube-payment-portal' ); ?></option>
							<option value="SHARE_MANUALLY"><?php esc_html_e( 'Share Manually', 'cube-payment-portal' ); ?></option>
						</select>
					</p>
				</div>

				<!-- Actions -->
				<div class="spp-card" style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 20px;">
					<button type="submit" name="spp_create_invoice" value="publish" class="button button-primary button-large" style="width: 100%; margin-bottom: 10px; background: #2271b1; border-color: #2271b1;">
						<?php esc_html_e( 'Create & Send Invoice', 'cube-payment-portal' ); ?>
					</button>
					<p class="description" style="text-align: center; margin-bottom: 15px;">
						<?php esc_html_e( 'Invoice will be sent to the customer immediately.', 'cube-payment-portal' ); ?>
					</p>
					
					<hr style="margin: 15px 0;">
					
					<button type="submit" name="spp_create_invoice" value="draft" class="button button-secondary button-large" style="width: 100%;">
						<?php esc_html_e( 'Save as Draft', 'cube-payment-portal' ); ?>
					</button>
					<p class="description" style="text-align: center;">
						<?php esc_html_e( 'Save without sending. You can edit and publish later.', 'cube-payment-portal' ); ?>
					</p>
				</div>
			</div>
		</div>
	</form>
</div>

<script>
jQuery(document).ready(function($) {
	// Catalog items data from Square.
	var catalogItems = <?php echo wp_json_encode( $catalog_items ); ?>;
	
	// Initialize autocomplete for item name fields.
	function initAutocomplete(element) {
		$(element).autocomplete({
			source: catalogItems.map(function(item) {
				return {
					label: item.name,
					value: item.name,
					description: item.description,
					price: item.price
				};
			}),
			minLength: 0,
			select: function(event, ui) {
				var row = $(this).closest('tr');
				// Auto-populate description and price.
				row.find('.spp-item-description').val(ui.item.description);
				row.find('.spp-price-input').val(ui.item.price.toFixed(2));
				// Recalculate totals.
				setTimeout(function() {
					row.find('.spp-price-input').trigger('input');
				}, 10);
			}
		}).on('focus', function() {
			// Show all items when field is clicked.
			$(this).autocomplete('search', '');
		});
	}
	
	// Initialize autocomplete on existing item fields.
	$('.spp-item-name-autocomplete').each(function() {
		initAutocomplete(this);
	});
	
	// Add line item.
	$('#spp-add-line-item').on('click', function() {
		var newRow = $('#spp-line-items-body tr.spp-line-item-row:first').clone();
		newRow.find('input').val('');
		newRow.find('.spp-qty-input').val('1');
		newRow.find('.spp-price-input').val('0.00');
		newRow.find('.spp-line-total').text('$0.00');
		$('#spp-line-items-body').append(newRow);
		// Initialize autocomplete for the new row.
		initAutocomplete(newRow.find('.spp-item-name-autocomplete'));
		calculateTotals();
	});

	// Remove line item.
	$(document).on('click', '.spp-remove-line-item', function() {
		if ($('#spp-line-items-body tr').length > 1) {
			$(this).closest('tr').remove();
			calculateTotals();
		} else {
			alert('<?php echo esc_js( __( 'At least one line item is required.', 'cube-payment-portal' ) ); ?>');
		}
	});

	// Recalculate on input change.
	$(document).on('input', '.spp-qty-input, .spp-price-input', function() {
		var row = $(this).closest('tr');
		var qty = parseFloat(row.find('.spp-qty-input').val()) || 0;
		var price = parseFloat(row.find('.spp-price-input').val()) || 0;
		var total = qty * price;
		row.find('.spp-line-total').text('$' + total.toFixed(2));
		calculateTotals();
	});

	function calculateTotals() {
		var subtotal = 0;
		$('#spp-line-items-body tr').each(function() {
			var qty = parseFloat($(this).find('.spp-qty-input').val()) || 0;
			var price = parseFloat($(this).find('.spp-price-input').val()) || 0;
			subtotal += qty * price;
		});
		$('#spp-subtotal').text('$' + subtotal.toFixed(2));
		$('#spp-total').text('$' + subtotal.toFixed(2));
	}

	// Initial calculation.
	calculateTotals();
	
	// Prevent double-submission by disabling buttons on form submit.
	$('#spp-create-invoice-form').on('submit', function(e) {
		var $form = $(this);
		var $buttons = $form.find('button[type="submit"]');
		
		// Check if already submitting.
		if ($form.data('submitting')) {
			e.preventDefault();
			return false;
		}
		
		// Mark as submitting and disable buttons.
		$form.data('submitting', true);
		
		// Update button text to show processing.
		var $clickedBtn = $(document.activeElement);
		if ($clickedBtn.is('button[type="submit"]')) {
			var originalText = $clickedBtn.text();
			$clickedBtn.data('original-text', originalText);
			$clickedBtn.html('<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear;"></span> Processing...');
		}
		
		// Disable all buttons after a short delay to allow form submission.
		setTimeout(function() {
			$buttons.prop('disabled', true);
		}, 100);
		
		// Allow the form to submit normally.
		return true;
	});
});
</script>

<style>
@keyframes rotation {
	from { transform: rotate(0deg); }
	to { transform: rotate(359deg); }
}
</style>
