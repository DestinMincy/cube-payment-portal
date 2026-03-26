<?php
/**
 * Client Transactions view.
 *
 * Lists transaction history as expandable cards with full details.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

$transactions  = array();
$error_message = '';

if ( ! empty( $square_customer_id ) ) {
    // Lazy-sync gift card activities from Square before querying.
    if ( ! class_exists( 'SPP_Sync_Gift_Card_Activities' ) ) {
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-gift-card-activities.php';
    }
    $gc_sync = new SPP_Sync_Gift_Card_Activities();
    $gc_sync->sync_for_customer( $square_customer_id, $user_id );

    if ( ! class_exists( 'SPP_Database' ) ) {
        require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
    }

    $db           = new SPP_Database();
    $transactions = $db->get_transactions( array(
        'square_customer_id' => $square_customer_id,
        'limit'              => 50,
        'order_by'           => 'created_at',
        'order'              => 'DESC',
    ) );

    if ( is_wp_error( $transactions ) ) {
        $error_message = $transactions->get_error_message();
        $transactions  = array();
    }
}

$status_map = array(
    'COMPLETED' => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Completed', 'cube-payment-portal' ) ),
    'completed' => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Completed', 'cube-payment-portal' ) ),
    'APPROVED'  => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Approved', 'cube-payment-portal' ) ),
    'approved'  => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Approved', 'cube-payment-portal' ) ),
    'PENDING'   => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Pending', 'cube-payment-portal' ) ),
    'pending'   => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Pending', 'cube-payment-portal' ) ),
    'CANCELED'  => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'canceled'  => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'FAILED'    => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Failed', 'cube-payment-portal' ) ),
    'failed'    => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Failed', 'cube-payment-portal' ) ),
);

$source_labels = array(
    'one_time'            => __( 'One-time Payment', 'cube-payment-portal' ),
    'subscription'        => __( 'Subscription', 'cube-payment-portal' ),
    'invoice'             => __( 'Invoice', 'cube-payment-portal' ),
    'gift_card_purchase'  => __( 'Gift Card Purchase', 'cube-payment-portal' ),
    'gift_card_reload'    => __( 'Gift Card Reload', 'cube-payment-portal' ),
    'gift_card_redeem'    => __( 'Gift Card Redemption', 'cube-payment-portal' ),
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center spp-flex-wrap spp-gap-4" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'Transaction History', 'cube-payment-portal' ); ?></h2>

        <?php if ( ! empty( $transactions ) ) : ?>
        <div class="spp-flex spp-gap-3">
            <select id="spp-txn-filter-status" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="ALL"><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
                <option value="COMPLETED"><?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?></option>
                <option value="APPROVED"><?php esc_html_e( 'Approved', 'cube-payment-portal' ); ?></option>
                <option value="PENDING"><?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?></option>
                <option value="CANCELED"><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></option>
                <option value="FAILED"><?php esc_html_e( 'Failed', 'cube-payment-portal' ); ?></option>
            </select>

            <select id="spp-txn-sort" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="newest"><?php esc_html_e( 'Newest First', 'cube-payment-portal' ); ?></option>
                <option value="oldest"><?php esc_html_e( 'Oldest First', 'cube-payment-portal' ); ?></option>
                <option value="highest"><?php esc_html_e( 'Highest Amount', 'cube-payment-portal' ); ?></option>
                <option value="lowest"><?php esc_html_e( 'Lowest Amount', 'cube-payment-portal' ); ?></option>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $error_message ) ) : ?>
        <div class="spp-portal__card">
            <p style="color: var(--spp-error);"><?php echo esc_html( $error_message ); ?></p>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'Your account is not linked. Please contact support.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $transactions ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'No transactions found.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div id="spp-txn-list-container" style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $transactions as $idx => $txn ) :
                $txn_date       = $txn['created_at'] ?? '';
                $amount_raw     = $txn['amount'] ?? 0;
                $currency       = $txn['currency'] ?? 'USD';
                $status_raw     = $txn['status'] ?? 'completed';
                $status         = strtoupper( $status_raw );
                $note           = $txn['note'] ?? $txn['description'] ?? '';
                $receipt_url    = $txn['receipt_url'] ?? '';
                $payment_id     = $txn['square_payment_id'] ?? '';
                $card_last_four = $txn['card_last_four'] ?? '';
                $card_brand     = $txn['card_brand'] ?? '';
                $source_type    = $txn['source_type'] ?? 'one_time';
                $source_id      = $txn['source_id'] ?? '';
                $plan_name      = $txn['plan_name'] ?? '';
                $sub_id         = $txn['square_subscription_id'] ?? '';
                $plan_id        = $txn['square_plan_id'] ?? '';
                $metadata_raw   = $txn['metadata'] ?? '';
                $metadata       = ! empty( $metadata_raw ) ? json_decode( $metadata_raw, true ) : array();

                // Format amount — stored in dollars (decimal).
                $amount_fmt = '$' . number_format( (float) $amount_raw, 2 );

                // Format payment method.
                $payment_method = '';
                if ( ! empty( $card_brand ) && ! empty( $card_last_four ) ) {
                    $payment_method = ucfirst( strtolower( str_replace( '_', ' ', $card_brand ) ) ) . ' ****' . $card_last_four;
                } elseif ( ! empty( $card_last_four ) ) {
                    $payment_method = '****' . $card_last_four;
                }

                // Description for header — check metadata note as fallback.
                $description = $note;
                if ( empty( $description ) && ! empty( $metadata['note'] ) ) {
                    $description = $metadata['note'];
                }
                if ( empty( $description ) && ! empty( $plan_name ) ) {
                    $description = $plan_name;
                }
                if ( empty( $description ) ) {
                    $description = $source_labels[ $source_type ] ?? __( 'Payment', 'cube-payment-portal' );
                }

                $status_info = $status_map[ $status_raw ] ?? $status_map[ $status ] ?? array( 'class' => 'spp-portal__badge--secondary', 'label' => ucfirst( strtolower( $status ) ) );
                $created_ts  = ! empty( $txn_date ) ? strtotime( $txn_date ) : 0;
                $amount_cents = (int) round( (float) $amount_raw * 100 );
                $uid         = 'spp-txn-' . $idx;
                ?>
                <div class="spp-portal__card spp-sub-card spp-txn-card" style="padding: 0; overflow: hidden;"
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
                            <!-- Description + badge -->
                            <div class="spp-flex spp-items-center spp-gap-3" style="margin-bottom: 6px;">
                                <h3 class="spp-sub-card__title"><?php echo esc_html( $description ); ?></h3>
                                <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                    <?php echo esc_html( $status_info['label'] ); ?>
                                </span>
                            </div>
                            <!-- Meta row -->
                            <div class="spp-sub-card__meta">
                                <span class="spp-sub-card__meta-item">
                                    <strong><?php echo esc_html( $amount_fmt ); ?></strong>
                                    <span class="spp-portal__text--muted"><?php echo esc_html( $currency ); ?></span>
                                </span>
                                <?php if ( ! empty( $txn_date ) ) : ?>
                                    <span class="spp-sub-card__meta-sep">&bull;</span>
                                    <span class="spp-sub-card__meta-item spp-portal__text--muted">
                                        <?php echo esc_html( SPP_Client_Portal::format_date( $txn_date ) ); ?>
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
                        <!-- Chevron -->
                        <svg class="spp-sub-card__chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>

                    <!-- Expandable body -->
                    <div class="spp-sub-card__body" id="<?php echo esc_attr( $uid ); ?>">
                        <div class="spp-sub-card__details">
                            <div class="spp-sub-card__grid">
                                <?php if ( ! empty( $payment_id ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Payment ID', 'cube-payment-portal' ); ?></span>
                                        <code class="spp-sub-card__detail-code"><?php echo esc_html( $payment_id ); ?></code>
                                    </div>
                                <?php endif; ?>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $amount_fmt . ' ' . $currency ); ?></span>
                                </div>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                        <?php echo esc_html( $status_info['label'] ); ?>
                                    </span>
                                </div>

                                <?php if ( ! empty( $payment_method ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Payment Method', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $payment_method ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Source', 'cube-payment-portal' ); ?></span>
                                    <span class="spp-sub-card__detail-value"><?php echo esc_html( $source_labels[ $source_type ] ?? ucfirst( str_replace( '_', ' ', $source_type ) ) ); ?></span>
                                </div>

                                <?php if ( ! empty( $plan_name ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Plan', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $plan_name ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $sub_id ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Subscription ID', 'cube-payment-portal' ); ?></span>
                                        <code class="spp-sub-card__detail-code"><?php echo esc_html( $sub_id ); ?></code>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $txn_date ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Date & Time', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( SPP_Client_Portal::format_date( $txn_date, 'datetime' ) ); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ( ! empty( $receipt_url ) ) : ?>
                                <div style="margin-top: var(--spp-space-4); padding-top: var(--spp-space-3); border-top: 1px solid var(--spp-border-light);">
                                    <a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer"
                                       class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm">
                                        <?php esc_html_e( 'View Receipt', 'cube-payment-portal' ); ?>
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
