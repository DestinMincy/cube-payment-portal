<?php
/**
 * Widget: Upcoming Appointments.
 *
 * Displays the client's next 3 upcoming appointments as clickable rows
 * that navigate to the Appointments page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
$bookings           = array();

if ( ! empty( $square_customer_id ) ) {
	if ( ! class_exists( 'SPP_Bookings' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
	}

	$bookings_api = new SPP_Bookings();
	$result       = $bookings_api->list_bookings( array(
		'customer_id' => $square_customer_id,
		'start_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
	) );

	if ( ! is_wp_error( $result ) ) {
		$bookings = array_slice( $result['bookings'] ?? array(), 0, 3 );
	}
}

$status_badge_classes = array(
	'ACCEPTED'           => 'spp-portal__badge--success',
	'PENDING'            => 'spp-portal__badge--warning',
	'CANCELLED_BY_BUYER' => 'spp-portal__badge--error',
	'CANCELLED_BY_SELLER'=> 'spp-portal__badge--error',
	'DECLINED'           => 'spp-portal__badge--error',
	'NO_SHOW'            => 'spp-portal__badge--secondary',
);

if ( empty( $bookings ) ) : ?>
	<p class="spp-portal__text--muted spp-portal__text--sm">
		<?php esc_html_e( 'No upcoming appointments.', 'cube-payment-portal' ); ?>
	</p>
<?php else : ?>
	<div class="spp-portal__list">
		<?php foreach ( $bookings as $booking ) :
			$start      = $booking['start_at'] ?? '';
			$status     = $booking['status'] ?? '';
			$booking_id = $booking['id'] ?? '';
			$badge      = $status_badge_classes[ $status ] ?? 'spp-portal__badge--info';

			// Format the status display label.
			$status_labels = array(
				'ACCEPTED'           => __( 'Confirmed', 'cube-payment-portal' ),
				'PENDING'            => __( 'Pending', 'cube-payment-portal' ),
				'CANCELLED_BY_BUYER' => __( 'Cancelled', 'cube-payment-portal' ),
				'CANCELLED_BY_SELLER'=> __( 'Cancelled', 'cube-payment-portal' ),
				'DECLINED'           => __( 'Declined', 'cube-payment-portal' ),
				'NO_SHOW'            => __( 'No Show', 'cube-payment-portal' ),
			);
			$status_label = $status_labels[ $status ] ?? ucfirst( strtolower( $status ) );

			$params_json = wp_json_encode( array( 'highlight' => $booking_id ) );
			?>
			<a href="#"
			   class="spp-portal__list-link spp-flex spp-justify-between spp-items-center"
			   data-spp-view="bookings"
			   data-spp-params='<?php echo esc_attr( $params_json ); ?>'
			   style="padding: 8px 0; border-bottom: 1px solid var(--spp-border-light); text-decoration: none; color: inherit; display: flex;">
				<div style="min-width: 0; flex: 1;">
					<strong class="spp-portal__text--sm"><?php echo esc_html( SPP_Client_Portal::format_date( $start, 'datetime' ) ); ?></strong>
					<p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 2px 0 0;">
						<?php echo esc_html( $booking['service_name'] ?? __( 'Appointment', 'cube-payment-portal' ) ); ?>
					</p>
				</div>
				<span class="spp-portal__badge <?php echo esc_attr( $badge ); ?> spp-portal__badge--sm" style="flex-shrink: 0; margin-left: 8px;">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
