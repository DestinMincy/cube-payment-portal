<?php
/**
 * Client Payment Methods view.
 *
 * Lists saved cards on file. Data loaded via AJAX.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

// Load Cards API.
$cards = array();
$error_message = '';

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Cards' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
    }

    $cards_api = new SPP_Cards();
    $result = $cards_api->list_cards( $square_customer_id );

    if ( is_wp_error( $result ) ) {
        $error_message = $result->get_error_message();
    } else {
        $cards = is_array( $result ) ? $result : array();
    }
}

// Load Gift Cards API.
$gift_cards = array();

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
    }

    $gift_cards_api = new SPP_Gift_Cards();
    $gc_result = $gift_cards_api->get_customer_gift_cards( $square_customer_id );

    if ( ! is_wp_error( $gc_result ) && is_array( $gc_result ) ) {
        $gift_cards = $gc_result;
    }
}

$brand_icons = array(
    'VISA'             => '💳',
    'MASTERCARD'       => '💳',
    'AMERICAN_EXPRESS' => '💳',
    'DISCOVER'         => '💳',
    'OTHER'            => '💳',
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'Payment Methods', 'cube-payment-portal' ); ?></h2>
        <a href="#" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm" data-spp-view="add-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            <?php esc_html_e( 'Add Card', 'cube-payment-portal' ); ?>
        </a>
    </div>

    <?php if ( ! empty( $error_message ) ) : ?>
        <div class="spp-portal__card">
            <div class="spp-portal__badge--error" style="padding: 12px;">
                <?php echo esc_html( $error_message ); ?>
            </div>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'Your account is not linked to a customer profile. Please contact support.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $cards ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'No payment methods on file.', 'cube-payment-portal' ); ?></p>
                <a href="#" class="spp-portal__btn spp-portal__btn--primary spp-mt-3" data-spp-view="add-card">
                    <?php esc_html_e( 'Add Your First Card', 'cube-payment-portal' ); ?>
                </a>
            </div>
        </div>
    <?php else : ?>
        <div style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $cards as $card ) :
                $brand = $card['card_brand'] ?? 'OTHER';
                $last4 = $card['last_4'] ?? '****';
                $exp_month = $card['exp_month'] ?? '--';
                $exp_year = $card['exp_year'] ?? '----';
                $card_id = $card['id'] ?? '';
                ?>
                <div class="spp-portal__card">
                    <div class="spp-flex spp-justify-between spp-items-center spp-mb-3">
                        <div class="spp-flex spp-items-center spp-gap-3">
                            <span style="font-size: 28px;"><?php echo esc_html( $brand_icons[ $brand ] ?? '💳' ); ?></span>
                            <div>
                                <strong><?php echo esc_html( ucfirst( strtolower( str_replace( '_', ' ', $brand ) ) ) ); ?></strong>
                                <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 0;">
                                    <?php
                                    printf(
                                        /* translators: %s: last 4 digits of card */
                                        esc_html__( '•••• %s', 'cube-payment-portal' ),
                                        esc_html( $last4 )
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="spp-portal__text--muted spp-portal__text--sm">
                            <?php
                            printf(
                                /* translators: %1$s: month, %2$s: year */
                                esc_html__( 'Expires %1$s/%2$s', 'cube-payment-portal' ),
                                esc_html( str_pad( (string) $exp_month, 2, '0', STR_PAD_LEFT ) ),
                                esc_html( substr( (string) $exp_year, -2 ) )
                            );
                            ?>
                        </div>
                    </div>
                    <div class="spp-flex spp-justify-end">
                        <button type="button"
                                class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-portal__btn--danger spp-delete-card"
                                data-card-id="<?php echo esc_attr( $card_id ); ?>">
                            <?php esc_html_e( 'Remove', 'cube-payment-portal' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $square_customer_id ) ) : ?>
        <!-- Gift Cards Section -->
        <div class="spp-flex spp-justify-between spp-items-center" style="margin-top: var(--spp-space-8); margin-bottom: var(--spp-space-5);">
            <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm" id="spp-buy-gift-card-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <?php esc_html_e( 'Buy Gift Card', 'cube-payment-portal' ); ?>
            </button>
        </div>

        <?php if ( empty( $gift_cards ) ) : ?>
            <div class="spp-portal__card spp-text-center spp-p-6">
                <div class="spp-portal__text--muted">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="m8 3 4 4 4-4"/></svg>
                    <p class="spp-mt-3"><?php esc_html_e( 'No gift cards linked to your account.', 'cube-payment-portal' ); ?></p>
                </div>
            </div>
        <?php else : ?>
            <div style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
                <?php foreach ( $gift_cards as $gc ) :
                    $gc_type     = $gc['type'] ?? 'DIGITAL';
                    $gc_gan      = $gc['gan'] ?? '';
                    $gc_last4    = strlen( $gc_gan ) >= 4 ? substr( $gc_gan, -4 ) : $gc_gan;
                    $gc_balance  = ( $gc['balance_money']['amount'] ?? 0 ) / 100;
                    $gc_currency = $gc['balance_money']['currency'] ?? 'USD';
                    $gc_state    = $gc['state'] ?? 'ACTIVE';
                    $type_label  = 'PHYSICAL' === $gc_type
                        ? __( 'Physical Gift Card', 'cube-payment-portal' )
                        : __( 'Digital Gift Card', 'cube-payment-portal' );
                    ?>
                    <div class="spp-portal__card">
                        <div class="spp-flex spp-justify-between spp-items-center spp-mb-3">
                            <div class="spp-flex spp-items-center spp-gap-3">
                                <div class="spp-portal__avatar" style="background: var(--spp-info-bg); color: var(--spp-info);">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="m8 3 4 4 4-4"/></svg>
                                </div>
                                <div>
                                    <strong><?php echo esc_html( $type_label ); ?></strong>
                                    <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 0;">
                                        <?php
                                        printf(
                                            /* translators: %s: last 4 digits of gift card */
                                            esc_html__( '•••• %s', 'cube-payment-portal' ),
                                            esc_html( $gc_last4 )
                                        );
                                        ?>
                                    </p>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 18px; font-weight: 700; color: var(--spp-primary);">
                                    <?php echo esc_html( '$' . number_format( $gc_balance, 2 ) ); ?>
                                </div>
                                <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
                                    <?php esc_html_e( 'Balance', 'cube-payment-portal' ); ?>
                                </p>
                            </div>
                        </div>
                        <div class="spp-flex spp-justify-end">
                            <button type="button"
                                    class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-reload-gift-card"
                                    data-gc-id="<?php echo esc_attr( $gc['id'] ?? '' ); ?>"
                                    data-gc-last4="<?php echo esc_attr( $gc_last4 ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                                <?php esc_html_e( 'Reload', 'cube-payment-portal' ); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
