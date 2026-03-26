<?php
/**
 * Widget: Payment Methods Summary.
 *
 * Shows the count of saved cards on file and total gift card balance.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

$card_count      = 0;
$gc_balance      = 0;
$gc_count        = 0;
$gc_currency     = 'USD';

if ( ! empty( $square_customer_id ) ) {
	// Count saved cards.
	if ( ! class_exists( 'SPP_Cards' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-cards.php';
	}
	$cards_api = new SPP_Cards();
	$cards     = $cards_api->list_cards( $square_customer_id );
	if ( ! is_wp_error( $cards ) && is_array( $cards ) ) {
		$card_count = count( $cards );
	}

	// Sum gift card balances.
	if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
	}
	$gc_api    = new SPP_Gift_Cards();
	$gc_result = $gc_api->get_customer_gift_cards( $square_customer_id );
	if ( ! is_wp_error( $gc_result ) && is_array( $gc_result ) ) {
		foreach ( $gc_result as $gc ) {
			$state = $gc['state'] ?? '';
			if ( 'ACTIVE' === $state ) {
				$gc_count++;
				$gc_balance += $gc['balance_money']['amount'] ?? 0;
				$gc_currency = $gc['balance_money']['currency'] ?? 'USD';
			}
		}
	}
}

if ( empty( $square_customer_id ) ) : ?>
	<p class="spp-portal__text--muted spp-portal__text--sm">
		<?php esc_html_e( 'Account not linked.', 'cube-payment-portal' ); ?>
	</p>
<?php else : ?>
	<div style="display: flex; flex-direction: column; gap: 12px;">
		<!-- Saved Cards -->
		<div class="spp-flex spp-items-center" style="gap: 10px;">
			<div style="width: 36px; height: 36px; border-radius: var(--spp-radius-md, 8px); background: var(--spp-primary-light, #eff6ff); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--spp-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
			</div>
			<div>
				<div style="font-weight: 600; font-size: var(--spp-text-base, 16px);">
					<?php echo esc_html( $card_count ); ?>
				</div>
				<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 0;">
					<?php echo esc_html( _n( 'Saved card', 'Saved cards', $card_count, 'cube-payment-portal' ) ); ?>
				</p>
			</div>
		</div>

		<?php if ( $gc_count > 0 ) : ?>
			<!-- Gift Card Balance -->
			<div class="spp-flex spp-items-center" style="gap: 10px;">
				<div style="width: 36px; height: 36px; border-radius: var(--spp-radius-md, 8px); background: #f3e8ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>
				</div>
				<div>
					<div style="font-weight: 600; font-size: var(--spp-text-base, 16px);">
						<?php echo esc_html( '$' . number_format( $gc_balance / 100, 2 ) ); ?>
					</div>
					<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 0;">
						<?php
						printf(
							/* translators: %d: number of gift cards */
							esc_html( _n( '%d gift card', '%d gift cards', $gc_count, 'cube-payment-portal' ) ),
							$gc_count
						);
						?>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( 0 === $card_count ) : ?>
			<a href="#" data-spp-view="add-card" class="spp-portal__link spp-portal__text--sm" style="margin-top: 2px;">
				<?php esc_html_e( '+ Add a card', 'cube-payment-portal' ); ?>
			</a>
		<?php endif; ?>
	</div>
<?php endif; ?>
