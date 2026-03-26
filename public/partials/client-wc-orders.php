<?php
/**
 * Client WooCommerce Orders view.
 *
 * Lists WC order history as expandable cards with line items and actions.
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
        'limit'    => 50,
        'orderby'  => 'date',
        'order'    => 'DESC',
    ) );
}

$status_map = array(
    'pending'    => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Pending', 'cube-payment-portal' ) ),
    'processing' => array( 'class' => 'spp-portal__badge--info',      'label' => __( 'Processing', 'cube-payment-portal' ) ),
    'on-hold'    => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'On Hold', 'cube-payment-portal' ) ),
    'completed'  => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Completed', 'cube-payment-portal' ) ),
    'cancelled'  => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Cancelled', 'cube-payment-portal' ) ),
    'refunded'   => array( 'class' => 'spp-portal__badge--secondary', 'label' => __( 'Refunded', 'cube-payment-portal' ) ),
    'failed'     => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Failed', 'cube-payment-portal' ) ),
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center spp-flex-wrap spp-gap-4" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'Orders', 'cube-payment-portal' ); ?></h2>

        <?php if ( ! empty( $orders ) ) : ?>
        <div class="spp-flex spp-gap-3">
            <select id="spp-wc-order-filter-status" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="ALL"><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?></option>
                <option value="processing"><?php esc_html_e( 'Processing', 'cube-payment-portal' ); ?></option>
                <option value="on-hold"><?php esc_html_e( 'On Hold', 'cube-payment-portal' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelled', 'cube-payment-portal' ); ?></option>
                <option value="refunded"><?php esc_html_e( 'Refunded', 'cube-payment-portal' ); ?></option>
                <option value="failed"><?php esc_html_e( 'Failed', 'cube-payment-portal' ); ?></option>
            </select>

            <select id="spp-wc-order-sort" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="newest"><?php esc_html_e( 'Newest First', 'cube-payment-portal' ); ?></option>
                <option value="oldest"><?php esc_html_e( 'Oldest First', 'cube-payment-portal' ); ?></option>
                <option value="highest"><?php esc_html_e( 'Highest Amount', 'cube-payment-portal' ); ?></option>
                <option value="lowest"><?php esc_html_e( 'Lowest Amount', 'cube-payment-portal' ); ?></option>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( empty( $orders ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'No orders found.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div id="spp-wc-order-list-container" style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $orders as $idx => $order ) :
                $order_num   = $order->get_order_number();
                $status      = $order->get_status();
                $total       = $order->get_total();
                $currency    = $order->get_currency();
                $date_created = $order->get_date_created();
                $date_str    = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '';
                $created_ts  = $date_created ? $date_created->getTimestamp() : 0;
                $amount_cents = (int) round( (float) $total * 100 );
                $total_fmt   = '$' . number_format( (float) $total, 2 );

                $payment_method  = $order->get_payment_method_title();
                $shipping_method = $order->get_shipping_method();
                $customer_note   = $order->get_customer_note();

                $status_info = $status_map[ $status ] ?? array( 'class' => 'spp-portal__badge--secondary', 'label' => ucfirst( $status ) );
                $uid         = 'spp-wc-order-' . $idx;

                $can_cancel  = in_array( $status, array( 'pending', 'on-hold' ), true );
                $can_pay     = 'pending' === $status && $order->get_checkout_payment_url();
                $can_reorder = 'completed' === $status;
                ?>
                <div class="spp-portal__card spp-sub-card spp-wc-order-card" style="padding: 0; overflow: hidden;"
                     data-status="<?php echo esc_attr( $status ); ?>"
                     data-created="<?php echo esc_attr( $created_ts ); ?>"
                     data-amount="<?php echo esc_attr( $amount_cents ); ?>">
                    <!-- Collapsed header -->
                    <button type="button"
                            class="spp-sub-card__header"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr( $uid ); ?>"
                            onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded')==='false'?'true':'false'); document.getElementById('<?php echo esc_attr( $uid ); ?>').classList.toggle('spp-sub-card__body--open');">
                        <div style="flex: 1; min-width: 0;">
                            <div class="spp-flex spp-items-center spp-gap-3" style="margin-bottom: 6px;">
                                <h3 class="spp-sub-card__title">#<?php echo esc_html( $order_num ); ?></h3>
                                <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                    <?php echo esc_html( $status_info['label'] ); ?>
                                </span>
                            </div>
                            <div class="spp-sub-card__meta">
                                <span class="spp-sub-card__meta-item">
                                    <strong><?php echo esc_html( $total_fmt ); ?></strong>
                                    <span class="spp-portal__text--muted"><?php echo esc_html( $currency ); ?></span>
                                </span>
                                <?php if ( ! empty( $date_str ) ) : ?>
                                    <span class="spp-sub-card__meta-sep">&bull;</span>
                                    <span class="spp-sub-card__meta-item spp-portal__text--muted">
                                        <?php echo esc_html( SPP_Client_Portal::format_date( $date_str ) ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $payment_method ) ) : ?>
                                    <span class="spp-sub-card__meta-sep">&bull;</span>
                                    <span class="spp-sub-card__meta-item spp-portal__text--muted">
                                        <?php echo esc_html( $payment_method ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <svg class="spp-sub-card__chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>

                    <!-- Expandable body -->
                    <div class="spp-sub-card__body" id="<?php echo esc_attr( $uid ); ?>">
                        <div class="spp-sub-card__details">
                            <div class="spp-sub-card__grid">
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Order Number', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value">#<?php echo esc_html( $order_num ); ?></span>
                                </div>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( ! empty( $date_str ) ? SPP_Client_Portal::format_date( $date_str ) : '—' ); ?></span>
                                </div>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                        <?php echo esc_html( $status_info['label'] ); ?>
                                    </span>
                                </div>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Total', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $total_fmt . ' ' . $currency ); ?></span>
                                </div>

                                <?php if ( ! empty( $payment_method ) ) : ?>
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Payment Method', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $payment_method ); ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $shipping_method ) ) : ?>
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Shipping', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $shipping_method ); ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $customer_note ) ) : ?>
                                <div class="spp-sub-card__detail" style="grid-column: 1 / -1;">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Customer Note', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $customer_note ); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Line Items -->
                            <?php
                            $items = $order->get_items();
                            if ( ! empty( $items ) ) : ?>
                            <div style="margin-top: var(--spp-space-5);">
                                <h4 class="spp-portal__text--sm" style="font-weight: 600; margin: 0 0 var(--spp-space-3) 0;"><?php esc_html_e( 'Items', 'cube-payment-portal' ); ?></h4>
                                <table style="width: 100%; border-collapse: collapse; font-size: var(--spp-text-sm);">
                                    <thead>
                                        <tr style="border-bottom: 1px solid var(--spp-border-light);">
                                            <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: var(--spp-text-muted);"><?php esc_html_e( 'Product', 'cube-payment-portal' ); ?></th>
                                            <th style="text-align: center; padding: 6px 8px; font-weight: 600; color: var(--spp-text-muted);"><?php esc_html_e( 'Qty', 'cube-payment-portal' ); ?></th>
                                            <th style="text-align: right; padding: 6px 8px; font-weight: 600; color: var(--spp-text-muted);"><?php esc_html_e( 'Total', 'cube-payment-portal' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $items as $item ) : ?>
                                        <tr style="border-bottom: 1px solid var(--spp-border-light);">
                                            <td style="padding: 8px; color: var(--spp-text-main);"><?php echo esc_html( $item->get_name() ); ?></td>
                                            <td style="padding: 8px; text-align: center; color: var(--spp-text-muted);"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                            <td style="padding: 8px; text-align: right; color: var(--spp-text-main);">$<?php echo esc_html( number_format( (float) $item->get_total(), 2 ) ); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <!-- Action Buttons -->
                            <?php if ( $can_pay || $can_cancel || $can_reorder ) : ?>
                            <div class="spp-flex spp-gap-3 spp-flex-wrap" style="margin-top: var(--spp-space-5);">
                                <?php if ( $can_pay ) : ?>
                                    <a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm">
                                        <?php esc_html_e( 'Pay Now', 'cube-payment-portal' ); ?>
                                    </a>
                                <?php endif; ?>

                                <?php if ( $can_cancel ) : ?>
                                    <button type="button" class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-cancel-wc-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
                                        <?php esc_html_e( 'Cancel Order', 'cube-payment-portal' ); ?>
                                    </button>
                                <?php endif; ?>

                                <?php if ( $can_reorder ) : ?>
                                    <button type="button" class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-reorder-wc-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
                                        <?php esc_html_e( 'Reorder', 'cube-payment-portal' ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
