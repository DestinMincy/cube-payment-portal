<?php
/**
 * Widget: Recent Invoices.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
$invoices           = array();

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Invoices' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
    }

    $invoices_api = new SPP_Invoices();
    $result       = $invoices_api->list_invoices( $square_customer_id );

    if ( ! is_wp_error( $result ) && is_array( $result ) ) {
        $invoices = array_slice( $result, 0, 3 );
    }
}

$status_classes = array(
    'PAID'      => 'spp-portal__badge--success',
    'UNPAID'    => 'spp-portal__badge--warning',
    'OVERDUE'   => 'spp-portal__badge--error',
    'DRAFT'     => 'spp-portal__badge--secondary',
    'SCHEDULED' => 'spp-portal__badge--info',
    'CANCELED'  => 'spp-portal__badge--secondary',
);

if ( empty( $invoices ) ) : ?>
    <p class="spp-portal__text--muted spp-portal__text--sm">
        <?php esc_html_e( 'No recent invoices.', 'cube-payment-portal' ); ?>
    </p>
<?php else : ?>
    <div class="spp-portal__list">
        <?php foreach ( $invoices as $inv ) :
            $inv_num    = $inv['invoice_number'] ?? '—';
            $status     = $inv['status'] ?? 'DRAFT';
            $amount_raw = $inv['payment_requests'][0]['computed_amount_money']['amount'] ?? 0;
            $currency   = $inv['payment_requests'][0]['computed_amount_money']['currency'] ?? 'USD';
            $amount     = number_format( $amount_raw / 100, 2 );
            $badge      = $status_classes[ $status ] ?? 'spp-portal__badge--secondary';
            ?>
            <div class="spp-flex spp-justify-between spp-items-center" style="padding: 8px 0; border-bottom: 1px solid var(--spp-border-light);">
                <div>
                    <strong class="spp-portal__text--sm">#<?php echo esc_html( $inv_num ); ?></strong>
                    <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
                        <?php echo esc_html( $currency . ' $' . $amount ); ?>
                    </p>
                </div>
                <span class="spp-portal__badge <?php echo esc_attr( $badge ); ?> spp-portal__badge--sm">
                    <?php echo esc_html( ucfirst( strtolower( $status ) ) ); ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
