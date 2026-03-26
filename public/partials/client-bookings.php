<?php
/**
 * Client Bookings/Appointments view.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

// Cancellation settings.
$allow_cancellation    = (bool) get_option( 'spp_allow_booking_cancellation', false );
$cancellation_window   = (int) get_option( 'spp_cancellation_window_hours', 24 );

$bookings      = array();
$error_message = '';

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Bookings' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
    }

    $bookings_api      = new SPP_Bookings();
    $now_ts            = time();
    $max_advance_days  = (int) get_option( 'spp_booking_max_advance_days', 90 );
    $end_ts            = strtotime( '+' . $max_advance_days . ' days', $now_ts );
    $current_start = $now_ts;

    // Square limits ListBookings to 31-day windows; fetch in 30-day chunks.
    while ( $current_start < $end_ts ) {
        $chunk_end = min( $end_ts, strtotime( '+30 days', $current_start ) );

        $result = $bookings_api->list_bookings( array(
            'customer_id'  => $square_customer_id,
            'start_at_min' => gmdate( 'Y-m-d\TH:i:s\Z', $current_start ),
            'start_at_max' => gmdate( 'Y-m-d\TH:i:s\Z', $chunk_end ),
        ) );

        if ( is_wp_error( $result ) ) {
            $error_message = $result->get_error_message();
            break;
        }

        $chunk_bookings = $result['bookings'] ?? array();
        $bookings       = array_merge( $bookings, $chunk_bookings );
        $current_start  = $chunk_end;
    }

    // Sort by start_at ascending.
    usort( $bookings, function ( $a, $b ) {
        return strcmp( $a['start_at'] ?? '', $b['start_at'] ?? '' );
    } );
}

// Resolve service names, prices, team member names, and locations from Square.
$service_names     = array();
$service_prices    = array();
$team_member_names = array();
$location_cache    = array();

if ( ! empty( $bookings ) ) {
    $service_ids     = array();
    $team_member_ids = array();
    $location_ids    = array();

    foreach ( $bookings as $booking ) {
        if ( ! empty( $booking['location_id'] ) ) {
            $location_ids[] = $booking['location_id'];
        }
        foreach ( $booking['appointment_segments'] ?? array() as $seg ) {
            if ( ! empty( $seg['service_variation_id'] ) ) {
                $service_ids[] = $seg['service_variation_id'];
            }
            if ( ! empty( $seg['team_member_id'] ) ) {
                $team_member_ids[] = $seg['team_member_id'];
            }
        }
    }

    $service_ids     = array_unique( $service_ids );
    $team_member_ids = array_unique( $team_member_ids );
    $location_ids    = array_unique( $location_ids );

    // Batch-fetch service catalog objects with parent items.
    if ( ! empty( $service_ids ) ) {
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog    = new SPP_Catalog();
        $cat_result = $catalog->batch_retrieve_objects( $service_ids, true );

        if ( ! is_wp_error( $cat_result ) ) {
            // Build a map of item ID → item name from related objects.
            $item_names = array();
            foreach ( $cat_result['related_objects'] ?? array() as $rel ) {
                if ( ( $rel['type'] ?? '' ) === 'ITEM' && ! empty( $rel['id'] ) ) {
                    $item_names[ $rel['id'] ] = $rel['item_data']['name'] ?? '';
                }
            }

            // Map variation ID → service display name and price.
            foreach ( $cat_result['objects'] ?? array() as $obj ) {
                if ( ( $obj['type'] ?? '' ) === 'ITEM_VARIATION' && ! empty( $obj['id'] ) ) {
                    $var_data    = $obj['item_variation_data'] ?? array();
                    $parent_id   = $var_data['item_id'] ?? '';
                    $parent_name = $item_names[ $parent_id ] ?? '';
                    $var_name    = $var_data['name'] ?? '';

                    if ( $parent_name && $var_name && strtolower( $var_name ) !== 'regular' ) {
                        $service_names[ $obj['id'] ] = $parent_name . ' — ' . $var_name;
                    } elseif ( $parent_name ) {
                        $service_names[ $obj['id'] ] = $parent_name;
                    } elseif ( $var_name ) {
                        $service_names[ $obj['id'] ] = $var_name;
                    }

                    // Extract price.
                    if ( ! empty( $var_data['price_money']['amount'] ) ) {
                        $service_prices[ $obj['id'] ] = array(
                            'amount'   => (int) $var_data['price_money']['amount'],
                            'currency' => $var_data['price_money']['currency'] ?? 'USD',
                        );
                    }
                }
            }
        }
    }

    // Fetch team member names.
    if ( ! empty( $team_member_ids ) ) {
        if ( ! class_exists( 'SPP_Team' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
        }

        $team_api = new SPP_Team();
        foreach ( $team_member_ids as $tm_id ) {
            $member = $team_api->get_team_member( $tm_id );
            if ( ! is_wp_error( $member ) ) {
                $given   = $member['given_name'] ?? '';
                $family  = $member['family_name'] ?? '';
                $display = trim( $given . ' ' . $family );
                if ( $display ) {
                    $team_member_names[ $tm_id ] = $display;
                }
            }
        }
    }

    // Resolve location names (using transient cache like admin bookings-detail).
    if ( ! empty( $location_ids ) ) {
        $client = new SPP_Square_Client();
        foreach ( $location_ids as $loc_id ) {
            $loc_transient = 'spp_loc_v2_' . $loc_id;
            $cached_loc    = get_transient( $loc_transient );

            if ( $cached_loc && is_array( $cached_loc ) ) {
                $location_cache[ $loc_id ] = $cached_loc;
            } else {
                $loc_response = $client->get( 'locations/' . $loc_id );
                if ( ! is_wp_error( $loc_response ) && isset( $loc_response['location'] ) ) {
                    $loc_data = $loc_response['location'];
                    $loc_name = $loc_data['business_name'] ?? ( $loc_data['name'] ?? '' );

                    $addr_parts = array();
                    if ( ! empty( $loc_data['address']['locality'] ) ) {
                        $addr_parts[] = $loc_data['address']['locality'];
                    }
                    if ( ! empty( $loc_data['address']['administrative_district_level_1'] ) ) {
                        $addr_parts[] = $loc_data['address']['administrative_district_level_1'];
                    }
                    $loc_address = implode( ', ', $addr_parts );

                    $location_cache[ $loc_id ] = array(
                        'name'    => $loc_name,
                        'address' => $loc_address,
                    );

                    set_transient( $loc_transient, $location_cache[ $loc_id ], DAY_IN_SECONDS );
                }
            }
        }
    }
}

$status_map = array(
    'ACCEPTED'           => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Confirmed', 'cube-payment-portal' ) ),
    'PENDING'            => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Pending', 'cube-payment-portal' ) ),
    'CANCELLED_BY_BUYER' => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'CANCELLED_BY_SELLER'=> array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'DECLINED'           => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Declined', 'cube-payment-portal' ) ),
    'NO_SHOW'            => array( 'class' => 'spp-portal__badge--secondary', 'label' => __( 'No Show', 'cube-payment-portal' ) ),
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'My Appointments', 'cube-payment-portal' ); ?></h2>
        <button type="button" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm" id="spp-book-appointment-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            <?php esc_html_e( 'Book Appointment', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <?php if ( ! empty( $error_message ) ) : ?>
        <div class="spp-portal__card">
            <p style="color: var(--spp-error);"><?php echo esc_html( $error_message ); ?></p>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'Your account is not linked. Please contact support.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $bookings ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'You have no appointments in the next 3 months.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div data-spp-bookings-list style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $bookings as $booking ) :
                $start_time   = ! empty( $booking['start_at'] ) ? strtotime( $booking['start_at'] ) : 0;
                $end_time     = ! empty( $booking['end_at'] ) ? strtotime( $booking['end_at'] ) : 0;
                $status       = $booking['status'] ?? 'PENDING';
                $location     = $booking['location_id'] ?? '';
                $status_info  = $status_map[ $status ] ?? array( 'class' => 'spp-portal__badge--secondary', 'label' => ucfirst( strtolower( $status ) ) );
                $booking_id   = $booking['id'] ?? '';

                // Appointment segments.
                $segments           = $booking['appointment_segments'] ?? array();
                $service_display    = '';
                $team_display       = '';
                $price_display      = '';
                $duration           = 0;
                if ( ! empty( $segments[0] ) ) {
                    $duration        = $segments[0]['duration_minutes'] ?? 0;
                    $seg_service_id  = $segments[0]['service_variation_id'] ?? '';
                    $seg_team_id     = $segments[0]['team_member_id'] ?? '';
                    $service_display = $service_names[ $seg_service_id ] ?? '';
                    $team_display    = $team_member_names[ $seg_team_id ] ?? '';

                    if ( ! empty( $service_prices[ $seg_service_id ] ) ) {
                        $price_amount   = $service_prices[ $seg_service_id ]['amount'];
                        $price_currency = $service_prices[ $seg_service_id ]['currency'];
                        $price_display  = '$' . number_format( $price_amount / 100, 2 );
                    }
                }

                // Location info.
                $loc_info    = $location_cache[ $location ] ?? array();
                $loc_display = $loc_info['name'] ?? '';
                $loc_address = $loc_info['address'] ?? '';
                ?>
                <div class="spp-portal__card">
                    <div class="spp-flex spp-items-start" style="gap: var(--spp-space-4);">
                        <!-- Date badge -->
                        <div style="min-width: 56px; text-align: center; background: var(--spp-primary, #3b82f6); color: #fff; border-radius: var(--spp-radius-md, 8px); padding: 8px 12px; flex-shrink: 0;">
                            <div style="font-size: 11px; text-transform: uppercase; opacity: 0.85; letter-spacing: 0.05em;">
                                <?php echo esc_html( wp_date( 'M', $start_time ) ); ?>
                            </div>
                            <div style="font-size: 22px; font-weight: 700; line-height: 1.1;">
                                <?php echo esc_html( wp_date( 'd', $start_time ) ); ?>
                            </div>
                        </div>

                        <!-- Details -->
                        <div style="flex: 1; min-width: 0;">
                            <div class="spp-flex spp-justify-between spp-items-start">
                                <div>
                                    <?php if ( $service_display ) : ?>
                                        <strong style="font-size: var(--spp-text-base); display: block; margin-bottom: 2px;">
                                            <?php echo esc_html( $service_display ); ?>
                                        </strong>
                                    <?php endif; ?>

                                    <div class="spp-flex spp-items-center spp-gap-3" style="margin-bottom: 4px;">
                                        <span style="font-size: var(--spp-text-sm);">
                                            <?php echo esc_html( wp_date( 'l, ' . SPP_Client_Portal::get_preferred_date_format(), $start_time ) ); ?>
                                            • <?php echo esc_html( wp_date( SPP_Client_Portal::get_preferred_time_format(), $start_time ) ); ?>
                                            <?php if ( $end_time ) : ?>
                                                – <?php echo esc_html( wp_date( SPP_Client_Portal::get_preferred_time_format(), $end_time ) ); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                            <?php echo esc_html( $status_info['label'] ); ?>
                                        </span>
                                    </div>

                                    <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 0;">
                                        <?php
                                        $details = array();
                                        if ( $duration > 0 ) {
                                            $details[] = sprintf(
                                                /* translators: %d: number of minutes */
                                                esc_html__( '%d minutes', 'cube-payment-portal' ),
                                                $duration
                                            );
                                        }
                                        if ( $team_display ) {
                                            $details[] = sprintf(
                                                /* translators: %s: team member name */
                                                esc_html__( 'with %s', 'cube-payment-portal' ),
                                                esc_html( $team_display )
                                            );
                                        }
                                        if ( $loc_display ) {
                                            $loc_text = esc_html( $loc_display );
                                            if ( $loc_address ) {
                                                $loc_text .= ' (' . esc_html( $loc_address ) . ')';
                                            }
                                            $details[] = $loc_text;
                                        }
                                        echo implode( ' • ', $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                                        ?>
                                    </p>
                                </div>

                                <?php if ( $price_display ) : ?>
                                    <div style="text-align: right; flex-shrink: 0; margin-left: var(--spp-space-4);">
                                        <strong style="font-size: var(--spp-text-base);"><?php echo esc_html( $price_display ); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Show cancel button if cancellation is enabled and booking is cancellable.
                            $is_cancellable_status = in_array( $status, array( 'ACCEPTED', 'PENDING' ), true );
                            $hours_until_start     = $start_time > 0 ? ( $start_time - time() ) / 3600 : 0;
                            $within_window         = $hours_until_start > $cancellation_window;
                            $booking_version       = $booking['version'] ?? 0;

                            if ( $allow_cancellation && $is_cancellable_status && $within_window && $booking_id ) : ?>
                                <div style="margin-top: var(--spp-space-3); padding-top: var(--spp-space-3); border-top: 1px solid var(--spp-border-light);">
                                    <button type="button"
                                            class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-portal__btn--danger spp-cancel-booking"
                                            data-booking-id="<?php echo esc_attr( $booking_id ); ?>"
                                            data-booking-version="<?php echo esc_attr( $booking_version ); ?>">
                                        <?php esc_html_e( 'Cancel Appointment', 'cube-payment-portal' ); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="spp-portal__text--muted spp-portal__text--xs" style="margin-top: var(--spp-space-5); text-align: center;">
        <?php
        printf(
            /* translators: %d: number of days */
            esc_html__( 'Showing appointments for the next %d days. Appointments scheduled further out will appear here as they get closer.', 'cube-payment-portal' ),
            $max_advance_days
        );
        ?>
    </p>
</div>
