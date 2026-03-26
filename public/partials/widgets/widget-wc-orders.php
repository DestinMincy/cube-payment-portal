<?php
/**
 * Widget: Recent WooCommerce Orders.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$orders  = array();

if ( function_exists( 'wc_get_orders' ) ) {
    $orders = wc_get_orders( array(
        'customer' => $user_id,
        'limit'    => 3,
        'orderby'  => 'date',
        'order'    => 'DESC',
    ) );
}

$status_classes = array(
    'pending'    => 'spp-portal__badge--warning',
    'processing' => 'spp-portal__badge--info',
    'on-hold'    => 'spp-portal__badge--warning',
    'completed'  => 'spp-portal__badge--success',
    'cancelled'  => 'spp-portal__badge--error',
    'refunded'   => 'spp-portal__badge--secondary',
    'failed'     => 'spp-portal__badge--error',
);

if ( empty( $orders ) ) : ?>
    <p class="spp-portal__text--muted spp-portal__text--sm">
        <?php esc_html_e( 'No orders yet.', 'cube-payment-portal' ); ?>
    </p>
<?php else : ?>
    <div class="spp-portal__list">
        <?php foreach ( $orders as $order ) :
            $order_num = $order->get_order_number();
            $status    = $order->get_status();
            $total     = $order->get_total();
            $currency  = $order->get_currency();
            $total_fmt = '$' . number_format( (float) $total, 2 );
            $badge     = $status_classes[ $status ] ?? 'spp-portal__badge--secondary';
            ?>
            <div class="spp-flex spp-justify-between spp-items-center" style="padding: 8px 0; border-bottom: 1px solid var(--spp-border-light);">
                <div>
                    <strong class="spp-portal__text--sm">#<?php echo esc_html( $order_num ); ?></strong>
                    <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
                        <?php echo esc_html( $currency . ' ' . $total_fmt ); ?>
                    </p>
                </div>
                <span class="spp-portal__badge <?php echo esc_attr( $badge ); ?> spp-portal__badge--sm">
                    <?php echo esc_html( ucfirst( $status ) ); ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
