<?php
/**
 * Client Invoices view.
 *
 * Lists client's invoices with status filtering and pay links.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

$invoices = array();
$error_message = '';

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Invoices' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-invoices.php';
    }

    $invoices_api = new SPP_Invoices();
    $result = $invoices_api->list_invoices( $square_customer_id );

    if ( is_wp_error( $result ) ) {
        $error_message = $result->get_error_message();
    } else {
        $invoices = is_array( $result ) ? $result : array();
        
        // Fetch order details for line items
        $order_ids = array_filter( array_column( $invoices, 'order_id' ) );
        $orders = array();
        if ( ! empty( $order_ids ) ) {
            $batch_result = $invoices_api->get_orders_for_invoices( $order_ids );
            if ( ! is_wp_error( $batch_result ) ) {
                $orders = $batch_result;
            }
        }
    }
}

$status_map = array(
    'PAID'              => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Paid', 'cube-payment-portal' ) ),
    'UNPAID'            => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Unpaid', 'cube-payment-portal' ) ),
    'PARTIALLY_PAID'    => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Partial', 'cube-payment-portal' ) ),
    'OVERDUE'           => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Overdue', 'cube-payment-portal' ) ),
    'SCHEDULED'         => array( 'class' => 'spp-portal__badge--info',      'label' => __( 'Scheduled', 'cube-payment-portal' ) ),
    'CANCELED'          => array( 'class' => 'spp-portal__badge--secondary', 'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'DRAFT'             => array( 'class' => 'spp-portal__badge--secondary', 'label' => __( 'Draft', 'cube-payment-portal' ) ),
    'PAYMENT_PENDING'   => array( 'class' => 'spp-portal__badge--info',      'label' => __( 'Pending', 'cube-payment-portal' ) ),
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center spp-flex-wrap spp-gap-4" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'My Invoices', 'cube-payment-portal' ); ?></h2>
        
        <?php if ( ! empty( $invoices ) ) : ?>
        <div class="spp-flex spp-gap-3">
            <select id="spp-inv-filter-status" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="ALL"><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
                <option value="PAID"><?php esc_html_e( 'Paid', 'cube-payment-portal' ); ?></option>
                <option value="UNPAID"><?php esc_html_e( 'Unpaid', 'cube-payment-portal' ); ?></option>
                <option value="PARTIALLY_PAID"><?php esc_html_e( 'Partial', 'cube-payment-portal' ); ?></option>
                <option value="OVERDUE"><?php esc_html_e( 'Overdue', 'cube-payment-portal' ); ?></option>
                <option value="SCHEDULED"><?php esc_html_e( 'Scheduled', 'cube-payment-portal' ); ?></option>
                <option value="PAYMENT_PENDING"><?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?></option>
                <option value="DRAFT"><?php esc_html_e( 'Draft', 'cube-payment-portal' ); ?></option>
                <option value="CANCELED"><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></option>
            </select>
            
            <select id="spp-inv-sort" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="newest"><?php esc_html_e( 'Newest First', 'cube-payment-portal' ); ?></option>
                <option value="oldest"><?php esc_html_e( 'Oldest First', 'cube-payment-portal' ); ?></option>
                <option value="due_date"><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></option>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $error_message ) ) : ?>
        <div class="spp-portal__card">
            <p style="color: var(--spp-error);"><?php echo esc_html( $error_message ); ?></p>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'Your account is not linked. Please contact support.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $invoices ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'No invoices found.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div id="spp-inv-list-container" style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $invoices as $idx => $invoice ) :
                $inv_id           = $invoice['id'] ?? '';
                $inv_number       = $invoice['invoice_number'] ?? $inv_id;
                $status           = $invoice['status'] ?? 'DRAFT';
                $payment_requests = $invoice['payment_requests'] ?? array();
                $total_amount     = 0;
                $currency         = 'USD';
                $due_date         = '';
                $public_url       = $invoice['public_url'] ?? '';
                $created_at       = $invoice['created_at'] ?? '';
                $title            = $invoice['title'] ?? '';
                $description      = $invoice['description'] ?? '';
                $order_id         = $invoice['order_id'] ?? '';
                
                $line_items = array();
                if ( ! empty( $order_id ) && isset( $orders[ $order_id ]['line_items'] ) ) {
                    $line_items = $orders[ $order_id ]['line_items'];
                }

                if ( ! empty( $payment_requests ) ) {
                    $primary      = $payment_requests[0];
                    $total_amount = $primary['computed_amount_money']['amount'] ?? $primary['total_completed_amount_money']['amount'] ?? 0;
                    $currency     = $primary['computed_amount_money']['currency'] ?? 'USD';
                    $due_date     = $primary['due_date'] ?? '';
                }

                $status_info = $status_map[ $status ] ?? array( 'class' => 'spp-portal__badge--secondary', 'label' => $status );
                $is_payable  = in_array( $status, array( 'UNPAID', 'PARTIALLY_PAID', 'OVERDUE', 'PAYMENT_PENDING' ), true );
                $formatted   = number_format( $total_amount / 100, 2 );
                $uid         = 'spp-inv-' . $idx;
                
                $created_ts = ! empty( $created_at ) ? strtotime( $created_at ) : 0;
                $due_ts     = ! empty( $due_date ) ? strtotime( $due_date ) : 9999999999;
                
                // Determine the display title
                $display_title = $title;
                if ( empty( $display_title ) ) {
                    $display_title = sprintf(
                        /* translators: %s: invoice number */
                        __( 'Invoice #%s', 'cube-payment-portal' ),
                        $inv_number
                    );
                }
                ?>
                <div class="spp-portal__card spp-inv-card" style="padding: 0; overflow: hidden;"
                     data-status="<?php echo esc_attr( $status ); ?>"
                     data-created="<?php echo esc_attr( $created_ts ); ?>"
                     data-due="<?php echo esc_attr( $due_ts ); ?>">
                    
                    <!-- ─── Collapsed header (always visible) ─── -->
                    <div class="spp-inv-card__header-row" style="display: flex; align-items: center;">
                        <button type="button"
                                class="spp-sub-card__header"
                                aria-expanded="false"
                                aria-controls="<?php echo esc_attr( $uid ); ?>"
                                onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded')==='false'?'true':'false'); document.getElementById('<?php echo esc_attr( $uid ); ?>').classList.toggle('spp-sub-card__body--open');"
                                style="flex: 1; min-width: 0;">
                            <div style="flex: 1; min-width: 0;">
                                <!-- Header: Invoice title/number + badge -->
                                <div class="spp-flex spp-items-center spp-gap-3" style="margin-bottom: 6px;">
                                    <h3 class="spp-sub-card__title">
                                        <?php echo esc_html( $display_title ); ?>
                                    </h3>
                                    <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                        <?php echo esc_html( $status_info['label'] ); ?>
                                    </span>
                                </div>

                                <!-- Key info row -->
                                <div class="spp-sub-card__meta">
                                    <span class="spp-sub-card__meta-item">
                                        <strong>$<?php echo esc_html( $formatted ); ?></strong>
                                        <span class="spp-portal__text--muted spp-portal__text--xs"><?php echo esc_html( $currency ); ?></span>
                                    </span>

                                    <?php if ( ! empty( $due_date ) ) : ?>
                                        <span class="spp-sub-card__meta-sep">•</span>
                                        <span class="spp-sub-card__meta-item <?php echo $is_payable && $due_ts < time() ? 'spp-portal__text--error' : ''; ?>">
                                            <?php esc_html_e( 'Due:', 'cube-payment-portal' ); ?>
                                            <strong><?php echo esc_html( SPP_Client_Portal::format_date( $due_date ) ); ?></strong>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $created_at ) ) : ?>
                                        <span class="spp-sub-card__meta-sep">•</span>
                                        <span class="spp-sub-card__meta-item spp-portal__text--muted">
                                            <?php
                                            printf(
                                                esc_html__( 'Issued %s', 'cube-payment-portal' ),
                                                esc_html( SPP_Client_Portal::format_date( $created_at ) )
                                            );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Chevron -->
                            <svg class="spp-sub-card__chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>

                        <!-- Pay Now button (sibling of button to avoid nested interactive elements) -->
                        <?php if ( $is_payable && ! empty( $public_url ) ) : ?>
                            <a href="<?php echo esc_url( $public_url ); ?>"
                               class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm"
                               style="flex-shrink: 0; margin-right: var(--spp-space-5);"
                               target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Pay Now', 'cube-payment-portal' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- ─── Expandable body (hidden by default) ─── -->
                    <div class="spp-sub-card__body" id="<?php echo esc_attr( $uid ); ?>">
                        <div class="spp-sub-card__details">
                            <!-- Description -->
                            <?php if ( ! empty( $description ) ) : ?>
                                <p style="margin: 0 0 var(--spp-space-4) 0; color: var(--spp-text-muted); font-size: var(--spp-text-sm);">
                                    <?php echo esc_html( $description ); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Detail grid -->
                            <div class="spp-sub-card__grid">
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Invoice ID', 'cube-payment-portal' ); ?></span>
                                    <code class="spp-sub-card__detail-code"><?php echo esc_html( $inv_id ); ?></code>
                                </div>

                                <?php if ( ! empty( $inv_number ) && $inv_number !== $inv_id ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Invoice Number', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $inv_number ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $order_id ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Order ID', 'cube-payment-portal' ); ?></span>
                                        <code class="spp-sub-card__detail-code"><?php echo esc_html( $order_id ); ?></code>
                                    </div>
                                <?php endif; ?>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( '$' . $formatted . ' ' . $currency ); ?></span>
                                </div>

                                <?php if ( ! empty( $created_at ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Issued Date', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( SPP_Client_Portal::format_date( $created_at ) ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $due_date ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value <?php echo $is_payable && $due_ts < time() ? 'spp-portal__text--error' : ''; ?>">
                                            <?php echo esc_html( SPP_Client_Portal::format_date( $due_date ) ); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $status_info['label'] ); ?></span>
                                </div>
                            </div>

                            <!-- Line Items -->
                            <?php if ( ! empty( $line_items ) ) : ?>
                                <div style="margin-top: var(--spp-space-5);">
                                    <h4 style="font-size: var(--spp-text-xs); color: var(--spp-text-muted); margin: 0 0 var(--spp-space-3) 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">
                                        <?php esc_html_e( 'Items', 'cube-payment-portal' ); ?>
                                    </h4>
                                    <div style="background: rgba(0,0,0,0.02); border: 1px solid var(--spp-border-light); border-radius: var(--spp-radius-md); padding: var(--spp-space-4);">
                                        <?php 
                                        $item_count = count($line_items);
                                        $current = 0;
                                        foreach ( $line_items as $item ) : 
                                            $current++;
                                            $qty = ! empty( $item['quantity'] ) ? intval( $item['quantity'] ) : 1;
                                            $unit_price = ! empty( $item['base_price_money']['amount'] ) ? $item['base_price_money']['amount'] / 100 : 0;
                                            $total_price = ! empty( $item['total_money']['amount'] ) ? $item['total_money']['amount'] / 100 : ( $unit_price * $qty );
                                            $border_style = ( $current < $item_count ) ? 'border-bottom: 1px solid var(--spp-border-light); padding-bottom: var(--spp-space-3); margin-bottom: var(--spp-space-3);' : '';
                                        ?>
                                            <div class="spp-flex spp-justify-between spp-items-start" style="<?php echo esc_attr( $border_style ); ?>">
                                                <div style="flex: 1; padding-right: var(--spp-space-4);">
                                                    <div style="font-weight: 500; font-size: var(--spp-text-sm); color: var(--spp-text-main);"><?php echo esc_html( $item['name'] ?? 'Item' ); ?></div>
                                                    <div class="spp-portal__text--muted spp-portal__text--xs" style="margin-top: 2px;">
                                                        <?php echo esc_html( $qty ); ?> &times; $<?php echo esc_html( number_format( $unit_price, 2 ) ); ?>
                                                    </div>
                                                </div>
                                                <div style="font-weight: 600; font-size: var(--spp-text-sm); color: var(--spp-text-main);">
                                                    $<?php echo esc_html( number_format( $total_price, 2 ) ); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Action buttons -->
                            <?php if ( $is_payable && ! empty( $public_url ) ) : ?>
                                <div class="spp-flex spp-justify-end" style="margin-top: var(--spp-space-5); padding-top: var(--spp-space-4); border-top: 1px solid var(--spp-border-light);">
                                    <a href="<?php echo esc_url( $public_url ); ?>"
                                       class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm"
                                       target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e( 'Pay Invoice', 'cube-payment-portal' ); ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
