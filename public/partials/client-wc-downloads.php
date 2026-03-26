<?php
/**
 * Client WooCommerce Downloads view.
 *
 * Lists available digital product downloads.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id   = get_current_user_id();
$downloads = array();

if ( function_exists( 'wc_get_customer_available_downloads' ) ) {
    $downloads = wc_get_customer_available_downloads( $user_id );
}
?>

<div class="spp-portal__section">
    <h2 class="spp-portal__subtitle" style="margin-bottom: var(--spp-space-6);"><?php esc_html_e( 'Downloads', 'cube-payment-portal' ); ?></h2>

    <?php if ( empty( $downloads ) ) : ?>
        <div class="spp-portal__card spp-text-center spp-p-6">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <p class="spp-mt-3"><?php esc_html_e( 'No downloads available.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $downloads as $dl ) :
                $product_name    = $dl['product_name'] ?? '';
                $file_name       = $dl['file']['name'] ?? $dl['download_name'] ?? __( 'File', 'cube-payment-portal' );
                $download_url    = $dl['download_url'] ?? '';
                $downloads_remain = $dl['downloads_remaining'] ?? '';
                $access_expires  = $dl['access_expires'] ?? '';
                $order_id        = $dl['order_id'] ?? '';
                ?>
                <div class="spp-portal__card">
                    <div class="spp-flex spp-justify-between spp-items-center spp-flex-wrap spp-gap-4">
                        <div style="flex: 1; min-width: 0;">
                            <h3 class="spp-portal__text--sm" style="font-weight: 600; margin: 0 0 4px 0;"><?php echo esc_html( $product_name ); ?></h3>
                            <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 0;">
                                <?php echo esc_html( $file_name ); ?>
                                <?php if ( ! empty( $order_id ) ) : ?>
                                    <span class="spp-sub-card__meta-sep">&bull;</span>
                                    <?php
                                    printf(
                                        /* translators: %s: order number */
                                        esc_html__( 'Order #%s', 'cube-payment-portal' ),
                                        esc_html( $order_id )
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
                            <div class="spp-flex spp-gap-4 spp-portal__text--xs spp-portal__text--muted" style="margin-top: 6px;">
                                <?php if ( '' !== $downloads_remain ) : ?>
                                    <span>
                                        <?php
                                        if ( 'unlimited' === $downloads_remain || '' === $downloads_remain ) {
                                            esc_html_e( 'Unlimited downloads', 'cube-payment-portal' );
                                        } else {
                                            printf(
                                                /* translators: %s: number of downloads remaining */
                                                esc_html__( '%s downloads remaining', 'cube-payment-portal' ),
                                                esc_html( $downloads_remain )
                                            );
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $access_expires ) ) : ?>
                                    <span>
                                        <?php
                                        printf(
                                            /* translators: %s: expiry date */
                                            esc_html__( 'Expires %s', 'cube-payment-portal' ),
                                            esc_html( SPP_Client_Portal::format_date( $access_expires ) )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( ! empty( $download_url ) ) : ?>
                            <a href="<?php echo esc_url( $download_url ); ?>" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                <?php esc_html_e( 'Download', 'cube-payment-portal' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
