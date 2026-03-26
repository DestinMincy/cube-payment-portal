<?php
/**
 * Client Add Card view.
 *
 * Renders the Square Web Payments SDK card form.
 * The JS client-portal.js handles initialization via initCardForm()
 * and tokenization via handleAddCard().
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-items-center spp-gap-3 spp-mb-5">
        <a href="#" class="spp-portal__btn spp-portal__btn--ghost spp-portal__btn--sm" data-spp-view="payment-methods">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
            <?php esc_html_e( 'Back', 'cube-payment-portal' ); ?>
        </a>
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'Add Payment Method', 'cube-payment-portal' ); ?></h2>
    </div>

    <div class="spp-portal__card spp-portal__card--lg" style="max-width: 560px;">
        <form id="spp-add-card-form">
            <div class="spp-mb-4">
                <label class="spp-portal__text--sm spp-portal__text--muted spp-mb-2" style="display: block;">
                    <?php esc_html_e( 'Card Details', 'cube-payment-portal' ); ?>
                </label>
                <!-- Square Web Payments SDK will render the card form here -->
                <div id="spp-card-container" class="spp-card-container"></div>
            </div>

            <div id="spp-card-errors" class="spp-portal__text--sm" style="color: var(--spp-error); min-height: 20px; margin-bottom: 16px;"></div>

            <button type="submit" class="spp-portal__btn spp-portal__btn--primary spp-w-full" id="spp-save-card-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                <?php esc_html_e( 'Save Card', 'cube-payment-portal' ); ?>
            </button>
        </form>

        <p class="spp-portal__text--sm spp-portal__text--muted spp-mt-4 spp-text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <?php esc_html_e( 'Your card is securely tokenized by Square. We never store your full card number.', 'cube-payment-portal' ); ?>
        </p>
    </div>
</div>
