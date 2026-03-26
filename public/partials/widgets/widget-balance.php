<?php
/**
 * Widget: Outstanding Balance.
 *
 * Shows total unpaid invoice amount and count, or an "All caught up!" message.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

$unpaid_count  = 0;
$unpaid_total  = 0;
$currency      = 'USD';

if ( ! empty( $square_customer_id ) ) {
	if ( ! class_exists( 'SPP_Invoices' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
	}

	$invoices_api = new SPP_Invoices();
	$result       = $invoices_api->list_invoices( $square_customer_id );

	if ( ! is_wp_error( $result ) && is_array( $result ) ) {
		foreach ( $result as $inv ) {
			$status = $inv['status'] ?? '';
			if ( in_array( $status, array( 'UNPAID', 'OVERDUE', 'PAYMENT_PENDING' ), true ) ) {
				$unpaid_count++;
				$unpaid_total += $inv['payment_requests'][0]['computed_amount_money']['amount'] ?? 0;
				$currency      = $inv['payment_requests'][0]['computed_amount_money']['currency'] ?? 'USD';
			}
		}
	}
}

if ( empty( $square_customer_id ) ) : ?>
	<p class="spp-portal__text--muted spp-portal__text--sm">
		<?php esc_html_e( 'Account not linked.', 'cube-payment-portal' ); ?>
	</p>
<?php elseif ( 0 === $unpaid_count ) : ?>
	<div style="text-align: center; padding: 8px 0;">
		<div style="font-size: 28px; margin-bottom: 4px;">✅</div>
		<p class="spp-portal__text--sm" style="font-weight: 600; color: var(--spp-success); margin: 0;">
			<?php esc_html_e( 'All caught up!', 'cube-payment-portal' ); ?>
		</p>
		<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 4px 0 0;">
			<?php esc_html_e( 'No outstanding invoices.', 'cube-payment-portal' ); ?>
		</p>
	</div>
<?php else : ?>
	<div>
		<div style="font-size: 28px; font-weight: 700; color: var(--spp-warning);">
			<?php echo esc_html( '$' . number_format( $unpaid_total / 100, 2 ) ); ?>
		</div>
		<p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 4px 0 0;">
			<?php
			printf(
				/* translators: %d: number of invoices */
				esc_html( _n( '%d unpaid invoice', '%d unpaid invoices', $unpaid_count, 'cube-payment-portal' ) ),
				$unpaid_count
			);
			?>
		</p>
		<a href="#"
		   data-spp-view="invoices"
		   data-spp-params='<?php echo esc_attr( wp_json_encode( array( 'filter' => 'UNPAID' ) ) ); ?>'
		   class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm"
		   style="margin-top: 12px; display: inline-flex; text-decoration: none;">
			<?php esc_html_e( 'Pay Now', 'cube-payment-portal' ); ?>
		</a>
	</div>
<?php endif; ?>
