<?php
/**
 * Client Loyalty view.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

if ( ! class_exists( 'SPP_Loyalty' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
}

$loyalty_api    = new SPP_Loyalty();
$program        = $loyalty_api->get_program();
$program_active = ! is_wp_error( $program ) && ! empty( $program['id'] );

$account       = null;
$error_message = '';

if ( $program_active && ! empty( $square_customer_id ) ) {
    $account = $loyalty_api->get_account_by_customer( $square_customer_id );
    if ( is_wp_error( $account ) ) {
        $error_message = $account->get_error_message();
        $account       = null;
    }
}

$points_balance = $account['balance'] ?? 0;
$reward_tiers   = $program['reward_tiers'] ?? array();
$terminology    = $program['terminology']['one'] ?? __( 'Point', 'cube-payment-portal' );
$program_name   = $program['terminology']['other'] ?? __( 'Points', 'cube-payment-portal' );

// Fetch the user's issued (unclaimed) rewards.
$user_rewards = array();
if ( ! empty( $account['id'] ) ) {
    $rewards_response = $loyalty_api->search_rewards( array(
        'loyalty_account_id' => $account['id'],
        'status'             => 'ISSUED',
    ) );
    if ( ! is_wp_error( $rewards_response ) ) {
        $user_rewards = $rewards_response['rewards'] ?? array();
    }
}

// Build a tier ID → tier name map for display.
$tier_map = array();
foreach ( $reward_tiers as $tier ) {
    if ( ! empty( $tier['id'] ) ) {
        $tier_map[ $tier['id'] ] = $tier['name'] ?? __( 'Reward', 'cube-payment-portal' );
    }
}
?>

<div class="spp-portal__section">
    <h2 class="spp-portal__subtitle" style="margin-bottom: var(--spp-space-6);"><?php esc_html_e( 'Loyalty & Rewards', 'cube-payment-portal' ); ?></h2>

    <?php if ( ! $program_active ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'No loyalty program is currently available.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'Your account is not linked. Please contact support to start earning rewards.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $account ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.35;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                <h3 style="margin: 16px 0 6px; font-size: var(--spp-text-lg); font-weight: 600;"><?php esc_html_e( 'Join Our Loyalty Program!', 'cube-payment-portal' ); ?></h3>
                <p style="margin: 0;"><?php esc_html_e( 'Start earning points with every purchase and redeem them for exclusive rewards.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <!-- Points Balance Card -->
        <div class="spp-portal__card" style="background: linear-gradient(135deg, var(--spp-primary, #3b82f6) 0%, #7c3aed 100%); color: #fff; text-align: center; padding: var(--spp-space-8); border: none; margin-bottom: var(--spp-space-6);">
            <div style="font-size: 48px; font-weight: 700; line-height: 1;">
                <?php echo esc_html( number_format( $points_balance ) ); ?>
            </div>
            <div style="font-size: 18px; opacity: 0.9; margin-top: 4px;">
                <?php echo esc_html( $program_name ); ?>
            </div>
            <div style="opacity: 0.7; font-size: 14px; margin-top: 8px;">
                <?php esc_html_e( 'Keep shopping to earn more rewards!', 'cube-payment-portal' ); ?>
            </div>
        </div>

        <!-- Redeem Points -->
        <?php if ( ! empty( $reward_tiers ) ) : ?>
            <h3 style="margin: 0 0 var(--spp-space-4) 0; font-size: var(--spp-text-base); font-weight: 600;">
                <?php esc_html_e( 'Redeem Points', 'cube-payment-portal' ); ?>
            </h3>
            <div style="display: flex; flex-direction: column; gap: var(--spp-space-3);">
                <?php foreach ( $reward_tiers as $tier ) :
                    $required_points = $tier['points'] ?? 0;
                    $can_redeem      = $points_balance >= $required_points;
                    $remaining       = $required_points - $points_balance;
                    ?>
                    <div class="spp-portal__card" style="<?php echo esc_attr( $can_redeem ? 'border-left: 3px solid var(--spp-success, #22c55e);' : '' ); ?>">
                        <div class="spp-flex spp-justify-between spp-items-center">
                            <div>
                                <strong style="font-size: var(--spp-text-base);">
                                    <?php echo esc_html( $tier['name'] ?? __( 'Reward', 'cube-payment-portal' ) ); ?>
                                </strong>
                                <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 2px 0 0;">
                                    <?php
                                    printf(
                                        esc_html__( '%s %s required', 'cube-payment-portal' ),
                                        esc_html( number_format( $required_points ) ),
                                        esc_html( strtolower( $program_name ) )
                                    );
                                    ?>
                                </p>
                            </div>
                            <?php if ( $can_redeem ) : ?>
                                <button type="button"
                                    class="spp-btn spp-btn-primary spp-btn-sm spp-redeem-reward"
                                    data-tier-id="<?php echo esc_attr( $tier['id'] ?? '' ); ?>"
                                    data-tier-name="<?php echo esc_attr( $tier['name'] ?? __( 'Reward', 'cube-payment-portal' ) ); ?>"
                                    data-tier-points="<?php echo esc_attr( $required_points ); ?>">
                                    <?php esc_html_e( 'Redeem', 'cube-payment-portal' ); ?>
                                </button>
                            <?php else : ?>
                                <span class="spp-portal__badge spp-portal__badge--secondary">
                                    <?php
                                    printf(
                                        esc_html__( '%s more needed', 'cube-payment-portal' ),
                                        esc_html( number_format( $remaining ) )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ( ! $can_redeem ) : ?>
                            <!-- Progress bar -->
                            <div style="margin-top: var(--spp-space-3); background: var(--spp-bg-tertiary, #f3f4f6); border-radius: 4px; height: 6px; overflow: hidden;">
                                <div style="height: 100%; background: var(--spp-primary, #3b82f6); border-radius: 4px; width: <?php echo esc_attr( min( 100, ( $points_balance / max( 1, $required_points ) ) * 100 ) ); ?>%; transition: width 0.3s ease;"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Your Rewards (issued but not yet used) -->
        <?php if ( ! empty( $user_rewards ) ) : ?>
            <h3 style="margin: var(--spp-space-6) 0 var(--spp-space-4) 0; font-size: var(--spp-text-base); font-weight: 600;">
                <?php esc_html_e( 'Your Rewards', 'cube-payment-portal' ); ?>
            </h3>
            <div style="display: flex; flex-direction: column; gap: var(--spp-space-3);">
                <?php foreach ( $user_rewards as $reward ) :
                    $tier_name  = $tier_map[ $reward['reward_tier_id'] ?? '' ] ?? __( 'Reward', 'cube-payment-portal' );
                    $created_at = $reward['created_at'] ?? '';
                    $date_display = ! empty( $created_at ) ? SPP_Client_Portal::format_date( $created_at ) : '';
                    ?>
                    <div class="spp-portal__card" style="border-left: 3px solid var(--spp-primary, #3b82f6);">
                        <div class="spp-flex spp-justify-between spp-items-center">
                            <div>
                                <strong style="font-size: var(--spp-text-base);">
                                    <?php echo esc_html( $tier_name ); ?>
                                </strong>
                                <?php if ( $date_display ) : ?>
                                    <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 2px 0 0;">
                                        <?php
                                        printf(
                                            /* translators: %s: date */
                                            esc_html__( 'Earned %s', 'cube-payment-portal' ),
                                            esc_html( $date_display )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="spp-portal__badge spp-portal__badge--success">
                                <?php esc_html_e( 'Ready to use', 'cube-payment-portal' ); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
