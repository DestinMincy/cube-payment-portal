<?php
/**
 * Widget: Subscription Status.
 *
 * Displays the client's top 3 subscriptions, sorted by status priority
 * (ACTIVE first), with plan names and pricing resolved from the Catalog API.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
$subscriptions      = array();

if ( ! empty( $square_customer_id ) ) {
	if ( ! class_exists( 'SPP_Subscriptions' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
	}

	$subs_api = new SPP_Subscriptions();
	$result   = $subs_api->get_customer_subscriptions( $square_customer_id );

	if ( ! is_wp_error( $result ) ) {
		$all_subs = is_array( $result ) ? $result : array();

		// Sort by status priority: ACTIVE first, inactive last.
		$status_priority = array(
			'ACTIVE'      => 0,
			'PENDING'     => 1,
			'PAUSED'      => 2,
			'COMPLETED'   => 3,
			'CANCELED'    => 4,
			'DEACTIVATED' => 5,
		);

		usort( $all_subs, function ( $a, $b ) use ( $status_priority ) {
			$pa = $status_priority[ $a['status'] ?? '' ] ?? 99;
			$pb = $status_priority[ $b['status'] ?? '' ] ?? 99;
			return $pa - $pb;
		} );

		$subscriptions = array_slice( $all_subs, 0, 3 );
	}
}

// ── Resolve plan names + pricing from Catalog ──
$plan_details = array();
if ( ! empty( $subscriptions ) ) {
	$plan_var_ids = array();
	foreach ( $subscriptions as $sub ) {
		$pv = $sub['plan_variation_id'] ?? $sub['plan_id'] ?? '';
		if ( ! empty( $pv ) ) {
			$plan_var_ids[ $pv ] = true;
		}
	}
	$plan_var_ids = array_keys( $plan_var_ids );

	if ( ! empty( $plan_var_ids ) ) {
		if ( ! class_exists( 'SPP_Catalog' ) ) {
			require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
		}

		$catalog = new SPP_Catalog();
		$batch   = $catalog->batch_retrieve_objects( $plan_var_ids, true );

		if ( ! is_wp_error( $batch ) && ! empty( $batch['objects'] ) ) {
			$parent_map = array();
			if ( ! empty( $batch['related_objects'] ) ) {
				foreach ( $batch['related_objects'] as $rel ) {
					$parent_map[ $rel['id'] ] = $rel;
				}
			}

			foreach ( $batch['objects'] as $obj ) {
				$obj_id   = $obj['id'];
				$obj_type = $obj['type'] ?? '';

				if ( 'SUBSCRIPTION_PLAN_VARIATION' === $obj_type ) {
					$var_data  = $obj['subscription_plan_variation_data'] ?? array();
					$phases    = $var_data['phases'] ?? array();
					$price     = 0;
					$currency  = 'USD';
					$cadence   = '';

					if ( ! empty( $phases[0] ) ) {
						$price    = $phases[0]['recurring_price_money']['amount'] ?? 0;
						$currency = $phases[0]['recurring_price_money']['currency'] ?? 'USD';
						$cadence  = $phases[0]['cadence'] ?? '';
					}

					$plan_name = $var_data['name'] ?? '';
					$parent_id = $var_data['subscription_plan_id'] ?? '';
					if ( ! empty( $parent_id ) && isset( $parent_map[ $parent_id ] ) ) {
						$parent_plan_data = $parent_map[ $parent_id ]['subscription_plan_data'] ?? array();
						$plan_name        = $parent_plan_data['name'] ?? $plan_name;
					}
					if ( empty( $plan_name ) ) {
						$plan_name = __( 'Subscription Plan', 'cube-payment-portal' );
					}

					$plan_details[ $obj_id ] = array(
						'name'        => $plan_name,
						'price'       => $price,
						'currency'    => $currency,
						'cadence_fmt' => ! empty( $cadence ) ? SPP_Catalog::format_cadence( $cadence ) : '',
					);
				} elseif ( 'SUBSCRIPTION_PLAN' === $obj_type ) {
					$plan_data = $obj['subscription_plan_data'] ?? array();
					$phases    = $plan_data['phases'] ?? array();
					$price     = 0;
					$currency  = 'USD';
					$cadence   = '';

					if ( ! empty( $phases[0] ) ) {
						$price    = $phases[0]['recurring_price_money']['amount'] ?? 0;
						$currency = $phases[0]['recurring_price_money']['currency'] ?? 'USD';
						$cadence  = $phases[0]['cadence'] ?? '';
					}

					$plan_details[ $obj_id ] = array(
						'name'        => $plan_data['name'] ?? __( 'Subscription Plan', 'cube-payment-portal' ),
						'price'       => $price,
						'currency'    => $currency,
						'cadence_fmt' => ! empty( $cadence ) ? SPP_Catalog::format_cadence( $cadence ) : '',
					);
				}
			}
		}
	}
}

$status_classes = array(
	'ACTIVE'      => 'spp-portal__badge--success',
	'PAUSED'      => 'spp-portal__badge--warning',
	'CANCELED'    => 'spp-portal__badge--error',
	'COMPLETED'   => 'spp-portal__badge--info',
	'PENDING'     => 'spp-portal__badge--warning',
	'DEACTIVATED' => 'spp-portal__badge--secondary',
);

if ( empty( $subscriptions ) ) : ?>
	<p class="spp-portal__text--muted spp-portal__text--sm">
		<?php esc_html_e( 'No active subscriptions.', 'cube-payment-portal' ); ?>
	</p>
<?php else : ?>
	<div class="spp-portal__list">
		<?php foreach ( $subscriptions as $sub ) :
			$status      = $sub['status'] ?? 'PENDING';
			$badge       = $status_classes[ $status ] ?? 'spp-portal__badge--secondary';
			$plan_var_id = $sub['plan_variation_id'] ?? $sub['plan_id'] ?? '';
			$plan        = $plan_details[ $plan_var_id ] ?? null;
			$plan_name   = $plan ? $plan['name'] : ucfirst( strtolower( $status ) );
			$next        = $sub['charged_through_date'] ?? '';

			// Build price string.
			$price_str = '';
			if ( $plan && $plan['price'] > 0 ) {
				$price_str = '$' . number_format( $plan['price'] / 100, 2 );
				if ( ! empty( $plan['cadence_fmt'] ) ) {
					$price_str .= '/' . strtolower( $plan['cadence_fmt'] );
				}
			}
			?>
			<div class="spp-flex spp-justify-between spp-items-center" style="padding: 8px 0; border-bottom: 1px solid var(--spp-border-light);">
				<div style="min-width: 0; flex: 1;">
					<strong class="spp-portal__text--sm" style="display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
						<?php echo esc_html( $plan_name ); ?>
					</strong>
					<?php if ( ! empty( $price_str ) ) : ?>
						<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
							<?php echo esc_html( $price_str ); ?>
						</p>
					<?php elseif ( ! empty( $next ) ) : ?>
						<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
							<?php
							printf(
								/* translators: %s: next billing date */
								esc_html__( 'Next: %s', 'cube-payment-portal' ),
								esc_html( SPP_Client_Portal::format_date( $next ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<span class="spp-portal__badge <?php echo esc_attr( $badge ); ?> spp-portal__badge--sm" style="flex-shrink: 0; margin-left: 8px;">
					<?php echo esc_html( ucfirst( strtolower( $status ) ) ); ?>
				</span>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
