<?php
/**
 * Widget: Loyalty Points.
 *
 * Displays the client's loyalty points balance, available rewards count,
 * and progress toward the nearest redeemable tier.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
$loyalty_data       = null;
$reward_tiers       = array();
$user_rewards       = array();

if ( ! empty( $square_customer_id ) ) {
	if ( ! class_exists( 'SPP_Loyalty' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
	}

	$loyalty_api = new SPP_Loyalty();

	// Get loyalty program for reward tiers.
	$program = $loyalty_api->get_program();
	if ( ! is_wp_error( $program ) && ! empty( $program['id'] ) ) {
		$reward_tiers = $program['reward_tiers'] ?? array();
	}

	// Get account.
	$account = $loyalty_api->get_account_by_customer( $square_customer_id );
	if ( ! is_wp_error( $account ) && ! empty( $account ) ) {
		$loyalty_data = $account;

		// Get issued (unredeemed) rewards.
		if ( ! empty( $account['id'] ) ) {
			$rewards_response = $loyalty_api->search_rewards( array(
				'loyalty_account_id' => $account['id'],
				'status'             => 'ISSUED',
			) );
			if ( ! is_wp_error( $rewards_response ) ) {
				$user_rewards = $rewards_response['rewards'] ?? array();
			}
		}
	}
}

if ( empty( $loyalty_data ) ) : ?>
	<p class="spp-portal__text--muted spp-portal__text--sm">
		<?php esc_html_e( 'No loyalty account found.', 'cube-payment-portal' ); ?>
	</p>
<?php else :
	$balance  = $loyalty_data['balance'] ?? 0;
	$lifetime = $loyalty_data['lifetime_points'] ?? 0;
	$rewards_count = count( $user_rewards );

	// Find the nearest redeemable tier (lowest points the user hasn't reached yet).
	$nearest_tier    = null;
	$can_redeem_any  = false;
	if ( ! empty( $reward_tiers ) ) {
		// Sort tiers by points ascending.
		usort( $reward_tiers, function ( $a, $b ) {
			return ( $a['points'] ?? 0 ) - ( $b['points'] ?? 0 );
		} );

		foreach ( $reward_tiers as $tier ) {
			$tier_points = $tier['points'] ?? 0;
			if ( $balance >= $tier_points ) {
				$can_redeem_any = true;
			} else {
				// First tier user can't yet reach → nearest goal.
				$nearest_tier = $tier;
				break;
			}
		}
	}
	?>
	<!-- Points balance row -->
	<div class="spp-flex spp-justify-between spp-items-center">
		<div>
			<div style="font-size: 28px; font-weight: 700; color: var(--spp-primary);">
				<?php echo esc_html( number_format( $balance ) ); ?>
			</div>
			<p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 4px 0 0;">
				<?php esc_html_e( 'Available points', 'cube-payment-portal' ); ?>
			</p>
		</div>
		<div style="text-align: right;">
			<div class="spp-portal__text--sm" style="font-weight: 600;">
				<?php echo esc_html( number_format( $lifetime ) ); ?>
			</div>
			<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
				<?php esc_html_e( 'Lifetime earned', 'cube-payment-portal' ); ?>
			</p>
		</div>
	</div>

	<?php if ( $rewards_count > 0 ) : ?>
		<!-- Available rewards banner -->
		<div style="margin-top: 12px; padding: 8px 12px; background: var(--spp-success-bg); border-radius: var(--spp-radius-md, 8px); display: flex; align-items: center; gap: 8px;">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--spp-success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
			<span style="font-size: var(--spp-text-sm, 14px); font-weight: 600; color: var(--spp-success);">
				<?php
				printf(
					/* translators: %d: number of rewards */
					esc_html( _n( '%d reward ready to use!', '%d rewards ready to use!', $rewards_count, 'cube-payment-portal' ) ),
					$rewards_count
				);
				?>
			</span>
		</div>
	<?php elseif ( $can_redeem_any ) : ?>
		<!-- Can redeem banner -->
		<div style="margin-top: 12px; padding: 8px 12px; background: var(--spp-primary-light, #eff6ff); border-radius: var(--spp-radius-md, 8px); display: flex; align-items: center; gap: 8px;">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--spp-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
			<span style="font-size: var(--spp-text-sm, 14px); font-weight: 600; color: var(--spp-primary);">
				<?php esc_html_e( 'You have enough points to redeem a reward!', 'cube-payment-portal' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<?php if ( $nearest_tier && ! $can_redeem_any ) :
		$tier_name     = $nearest_tier['name'] ?? __( 'Reward', 'cube-payment-portal' );
		$tier_points   = $nearest_tier['points'] ?? 0;
		$remaining     = $tier_points - $balance;
		$progress_pct  = $tier_points > 0 ? min( 100, ( $balance / $tier_points ) * 100 ) : 0;
		?>
		<!-- Next tier progress -->
		<div style="margin-top: 12px;">
			<div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: 4px;">
				<span class="spp-portal__text--xs spp-portal__text--muted">
					<?php echo esc_html( $tier_name ); ?>
				</span>
				<span class="spp-portal__text--xs spp-portal__text--muted">
					<?php
					printf(
						/* translators: %s: points remaining */
						esc_html__( '%s more', 'cube-payment-portal' ),
						esc_html( number_format( $remaining ) )
					);
					?>
				</span>
			</div>
			<div style="background: var(--spp-bg-tertiary, #f3f4f6); border-radius: 4px; height: 6px; overflow: hidden;">
				<div style="height: 100%; background: var(--spp-primary, #3b82f6); border-radius: 4px; width: <?php echo esc_attr( $progress_pct ); ?>%; transition: width 0.3s ease;"></div>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>
