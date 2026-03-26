<?php
/**
 * Booking detail view.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SPP_Bookings' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
}
if ( ! class_exists( 'SPP_Database' ) ) {
    require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
}
if ( ! class_exists( 'SPP_Square_Client' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-square-client.php';
}

$booking_id_input = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';

if ( empty( $booking_id_input ) ) {
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid booking ID.', 'cube-payment-portal' ) . '</p></div>';
    return;
}

// -----------------------------------------------------------------------------
// Helper: Format Phone (US-centric default, could be enhanced)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'spp_format_phone_us' ) ) {
    function spp_format_phone_us( $phone ) {
        // Strip all non-numeric
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        // If 11 digits and starts with 1, strip it
        if ( strlen( $phone ) === 11 && substr( $phone, 0, 1 ) === '1' ) {
            $phone = substr( $phone, 1 );
        }
        // If 10 digits, format
        if ( strlen( $phone ) === 10 ) {
            return sprintf( '(%s) %s-%s',
                substr( $phone, 0, 3 ),
                substr( $phone, 3, 3 ),
                substr( $phone, 6 )
            );
        }
        return $phone; // Return original if not standard US length
    }
}

// -----------------------------------------------------------------------------
// Data Fetching
// -----------------------------------------------------------------------------

// Resolve ID (Local DB ID -> Square ID)
$square_booking_id = $booking_id_input;
$local_booking = null;

global $wpdb;
$table_bookings = $wpdb->prefix . 'spp_bookings';

// Check if input is numeric (Local DB ID)
if ( is_numeric( $booking_id_input ) ) {
    $local_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_bookings WHERE id = %d", $booking_id_input ), ARRAY_A );
    if ( $local_booking ) {
        $square_booking_id = $local_booking['square_id'];
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Booking not found in local database.', 'cube-payment-portal' ) . '</p></div>';
        return;
    }
} else {
    $local_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_bookings WHERE square_id = %s", $booking_id_input ), ARRAY_A );
    $square_booking_id = $booking_id_input;
}

// Fetch Booking from API
$bookings_api = new SPP_Bookings();
$booking = $bookings_api->get_booking( $square_booking_id );

if ( is_wp_error( $booking ) ) {
    echo '<div class="notice notice-error"><p>' . esc_html( $booking->get_error_message() ) . '</p></div>';
    return;
}

// API Client for auxiliary fetches (Location, Team)
$client = new SPP_Square_Client();

// Resolve Location Name & Address
$location_id = $booking['location_id'] ?? '';
$location_name = $location_id;
$location_address = '';

if ( ! empty( $location_id ) ) {
    // Check transient (v2 for object structure)
    $loc_transient = 'spp_loc_v2_' . $location_id;
    $cached_loc = get_transient( $loc_transient );
    
    if ( $cached_loc && is_array( $cached_loc ) ) {
        $location_name = $cached_loc['name'];
        $location_address = $cached_loc['address']; // Pre-formatted
    } else {
        $loc_response = $client->get( 'locations/' . $location_id );
        if ( ! is_wp_error( $loc_response ) && isset( $loc_response['location'] ) ) {
            $loc_data = $loc_response['location'];
            $location_name = $loc_data['business_name'] ?? ( $loc_data['name'] ?? $location_id );
            
            // Format Address: City, State
            $addr_parts = array();
            if ( ! empty( $loc_data['address']['locality'] ) ) {
                $addr_parts[] = $loc_data['address']['locality'];
            }
            if ( ! empty( $loc_data['address']['administrative_district_level_1'] ) ) {
                $addr_parts[] = $loc_data['address']['administrative_district_level_1'];
            }
            $location_address = implode( ', ', $addr_parts );
            
            set_transient( $loc_transient, array(
                'name' => $location_name,
                'address' => $location_address
            ), DAY_IN_SECONDS );
        }
    }
}


// Format Dates
$start_at = isset( $booking['start_at'] ) ? strtotime( $booking['start_at'] ) : current_time( 'timestamp' );
$end_at = isset( $booking['end_at'] ) ? strtotime( $booking['end_at'] ) : 0;

if ( empty( $end_at ) || $end_at <= $start_at ) {
    $duration_minutes = 0;
    if ( ! empty( $booking['appointment_segments'] ) && is_array( $booking['appointment_segments'] ) ) {
        foreach ( $booking['appointment_segments'] as $seg ) {
            $duration_minutes += isset( $seg['duration_minutes'] ) ? (int) $seg['duration_minutes'] : 0;
        }
    }
    if ( $duration_minutes <= 0 ) {
        $duration_minutes = 30;
    }
    $end_at = $start_at + ( $duration_minutes * 60 );
}

// Resolve Customer
$customer_id = $booking['customer_id'] ?? '';
$customer_note = $booking['customer_note'] ?? '';
$customer_name = __( 'Guest', 'cube-payment-portal' );
$customer_email = '';
$customer_phone = '';

if ( ! empty( $customer_id ) ) {
    $customer_data = SPP_Database::get_customer_by_square_id( $customer_id );
    if ( ! $customer_data ) {
        if ( ! class_exists( 'SPP_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
        }
        $customers_api = new SPP_Customers();
        $api_customer = $customers_api->get_customer( $customer_id );
        if ( ! is_wp_error( $api_customer ) ) {
            $customer_data = $api_customer; 
        }
    }
    
    if ( $customer_data ) {
        $given = $customer_data['given_name'] ?? '';
        $family = $customer_data['family_name'] ?? '';
        $company = $customer_data['company_name'] ?? '';
        $name_str = trim( "$given $family" );
        $customer_name = $name_str ?: ( $company ?: $customer_id );
        $customer_email = $customer_data['email_address'] ?? ( $customer_data['email'] ?? '' );
        $customer_phone = $customer_data['phone_number'] ?? ( $customer_data['phone'] ?? '' );
    }
}


?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">
            <?php esc_html_e( 'Booking Details', 'cube-payment-portal' ); ?>
            <span class="spp-badge spp-badge-<?php echo esc_attr( strtolower( $booking['status'] ) ); ?>" style="font-size: 0.6em; vertical-align: middle; margin-left: 10px;">
                <?php echo esc_html( SPP_Bookings::get_status_display( $booking['status'] ) ); ?>
            </span>
        </h2>
        <div class="spp-actions">
            <a href="?page=spp-bookings&tab=bookings" class="button button-secondary"><?php esc_html_e( 'Back to List', 'cube-payment-portal' ); ?></a>
            <a href="?page=spp-bookings&tab=calendar" class="button button-secondary"><?php esc_html_e( 'View Calendar', 'cube-payment-portal' ); ?></a>
            <?php if ( in_array( $booking['status'], array( 'PENDING', 'ACCEPTED', 'SCHEDULED' ) ) ) : ?>
                <button class="button button-primary" id="spp-edit-booking-btn" style="margin-right: 5px;">
                    <?php esc_html_e( 'Edit Booking', 'cube-payment-portal' ); ?>
                </button>
                <button class="button button-secondary" id="spp-cancel-booking-btn" data-id="<?php echo esc_attr( $booking['id'] ); ?>" data-version="<?php echo esc_attr( $booking['version'] ); ?>">
                    <?php esc_html_e( 'Cancel Booking', 'cube-payment-portal' ); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="spp-card">
        <div class="spp-card-body" style="padding: 0;">
            <div class="spp-detail-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; padding: 20px;">
                
                <!-- Appointment Info -->
                <div class="spp-detail-section">
                    <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php esc_html_e( 'Appointment Info', 'cube-payment-portal' ); ?></h3>
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th style="padding: 10px 0; width: 100px;"><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></th>
                            <td style="padding: 10px 0;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $start_at ) ); ?></td>
                        </tr>
                        <tr>
                            <th style="padding: 10px 0;"><?php esc_html_e( 'Time', 'cube-payment-portal' ); ?></th>
                            <td style="padding: 10px 0;"><?php echo esc_html( date_i18n( get_option( 'time_format' ), $start_at ) . ' - ' . date_i18n( get_option( 'time_format' ), $end_at ) ); ?></td>
                        </tr>
                        <tr>
                            <th style="padding: 10px 0;"><?php esc_html_e( 'Duration', 'cube-payment-portal' ); ?></th>
                            <td style="padding: 10px 0;"><?php echo esc_html( round( ( $end_at - $start_at ) / 60 ) . ' ' . __( 'minutes', 'cube-payment-portal' ) ); ?></td>
                        </tr>
                        <tr>
                            <th style="padding: 10px 0;"><?php esc_html_e( 'Location', 'cube-payment-portal' ); ?></th>
                            <td style="padding: 10px 0;">
                                <div><strong><?php echo esc_html( $location_name ); ?></strong></div>
                                <?php if ( ! empty( $location_address ) ) : ?>
                                    <div style="color: #666; font-style: italic; font-size: 0.9em; margin-top: 2px;">
                                        <?php echo esc_html( $location_address ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Customer Info -->
                <div class="spp-detail-section">
                    <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php esc_html_e( 'Customer Info', 'cube-payment-portal' ); ?></h3>
                    
                    <p>
                        <strong><?php esc_html_e( 'Name:', 'cube-payment-portal' ); ?></strong>
                        <?php if ( ! empty( $customer_id ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers&view=detail&id=' . $customer_id ) ); ?>" style="text-decoration: none; font-weight: 500;">
                                <?php echo esc_html( $customer_name ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $customer_name ); ?>
                        <?php endif; ?>
                    </p>

                    <?php if ( ! empty( $customer_email ) ) : ?>
                        <p><strong><?php esc_html_e( 'Email:', 'cube-payment-portal' ); ?></strong> <a href="mailto:<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a></p>
                    <?php endif; ?>
                    
                    <?php if ( ! empty( $customer_phone ) ) : ?>
                         <p><strong><?php esc_html_e( 'Phone:', 'cube-payment-portal' ); ?></strong> <?php echo esc_html( spp_format_phone_us( $customer_phone ) ); ?></p>
                    <?php endif; ?>
                    
                    <?php if ( $customer_note ) : ?>
                        <div style="background: #fff9c4; padding: 10px; border-radius: 4px; margin-top: 15px;">
                            <strong><?php esc_html_e( 'Customer Note:', 'cube-payment-portal' ); ?></strong>
                            <p style="margin: 5px 0 0; font-style: italic;"><?php echo esc_html( $customer_note ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Segments Table -->
            <div class="spp-detail-full" style="border-top: 1px solid #eee; padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px;"><?php esc_html_e( 'Services & Staff', 'cube-payment-portal' ); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Service', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Team Member', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Duration', 'cube-payment-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $booking['appointment_segments'] as $segment ) : ?>
                            <?php
                                // Resolve Service Name
                                $service_name = '';
                                $svc_var_id = $segment['service_variation_id'] ?? '';
                                
                                // First try local DB.
                                if ( ! empty( $local_booking['service_name'] ) && $local_booking['service_id'] === $svc_var_id ) {
                                    $service_name = $local_booking['service_name'];
                                }
                                
                                // Fallback: fetch from Catalog API.
                                if ( empty( $service_name ) && ! empty( $svc_var_id ) ) {
                                    if ( ! class_exists( 'SPP_Catalog' ) ) {
                                        require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
                                    }
                                    $catalog_api = new SPP_Catalog();
                                    $svc_obj = $catalog_api->get_item_details( $svc_var_id );
                                    if ( ! is_wp_error( $svc_obj ) ) {
                                        $var_name = $svc_obj['item_variation_data']['name'] ?? '';
                                        $parent_item_id = $svc_obj['item_variation_data']['item_id'] ?? '';
                                        if ( ! empty( $parent_item_id ) ) {
                                            $parent_obj = $catalog_api->get_item_details( $parent_item_id );
                                            if ( ! is_wp_error( $parent_obj ) && ! empty( $parent_obj['item_data']['name'] ) ) {
                                                $service_name = $parent_obj['item_data']['name'];
                                                if ( ! empty( $var_name ) && 'Regular' !== $var_name ) {
                                                    $service_name .= ' - ' . $var_name;
                                                }
                                            }
                                        }
                                        if ( empty( $service_name ) && ! empty( $var_name ) ) {
                                            $service_name = $var_name;
                                        }
                                    }
                                }
                                
                                // Final fallback to the raw ID.
                                if ( empty( $service_name ) ) {
                                    $service_name = $svc_var_id;
                                }

                                // Resolve Staff Name
                                $team_id = $segment['team_member_id'];
                                $team_name = $team_id; 
                                
                                // Fetch Profile
                                if ( ! empty( $team_id ) ) {
                                    $tm_transient = 'spp_tm_' . $team_id;
                                    $cached_tm = get_transient( $tm_transient );
                                    if ( $cached_tm ) {
                                        $team_name = $cached_tm;
                                    } else {
                                        $tm_resp = $client->get( 'bookings/team-member-booking-profiles/' . $team_id );
                                        if ( ! is_wp_error( $tm_resp ) && isset( $tm_resp['team_member_booking_profile'] ) ) {
                                            $tm_data = $tm_resp['team_member_booking_profile'];
                                            $team_name = $tm_data['display_name'] ?? $team_id;
                                            set_transient( $tm_transient, $team_name, DAY_IN_SECONDS );
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $service_name ); ?></td>
                                <td><?php echo esc_html( $team_name ); ?></td>
                                <td><?php echo esc_html( $segment['duration_minutes'] . ' mins' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php
// Pre-load data for Edit Modal
// ─── Services ───
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}
$catalog_api = new SPP_Catalog();
$services_result = $catalog_api->get_appointment_services();
$available_services = ( ! is_wp_error( $services_result ) ) ? ( $services_result['objects'] ?? array() ) : array();

// Map catalog services into flat list: ID → Name, Duration, Team
$services_data = array();
$service_ids_in_dropdown = array(); // Track what we've added
foreach ( $available_services as $svc ) {
    if ( ! empty( $svc['item_data']['variations'] ) ) {
        foreach ( $svc['item_data']['variations'] as $idx => $var ) {
            $name = $svc['item_data']['name'];
            if ( ! empty( $var['item_variation_data']['name'] ) && 'Regular' !== $var['item_variation_data']['name'] ) {
                $name .= ' - ' . $var['item_variation_data']['name'];
            }
            $services_data[] = array(
                'id' => $var['id'],
                'name' => $name,
                'version' => $var['version'] ?? 0,
                'team_member_ids' => $var['item_variation_data']['team_member_ids'] ?? array(),
                'duration' => $var['item_variation_data']['service_duration'] ?? 0,
            );
            $service_ids_in_dropdown[] = $var['id'];
        }
    }
}

// FALLBACK: If the API didn't return services, or the current booking's service isn't listed,
// ensure we always have the current booking's service as an option. Use local DB data.
if ( ! empty( $booking['appointment_segments'] ) ) {
    foreach ( $booking['appointment_segments'] as $seg ) {
        $seg_svc_id = $seg['service_variation_id'] ?? '';
        if ( ! empty( $seg_svc_id ) && ! in_array( $seg_svc_id, $service_ids_in_dropdown, true ) ) {
            // Use local booking's service_name if available, otherwise use the ID
            $fallback_name = ( ! empty( $local_booking['service_name'] ) ) ? $local_booking['service_name'] : $seg_svc_id;
            $services_data[] = array(
                'id' => $seg_svc_id,
                'name' => $fallback_name,
                'version' => $seg['service_variation_version'] ?? 0,
                'team_member_ids' => array(),
                'duration' => $seg['duration_minutes'] ?? 30,
            );
            $service_ids_in_dropdown[] = $seg_svc_id;
        }
    }
}

// ─── Staff ───
if ( ! class_exists( 'SPP_Team' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
}
$team_api = new SPP_Team();
$team_result = $team_api->search_team_members( array( 'status' => 'ACTIVE' ) );
$available_staff = ( ! is_wp_error( $team_result ) ) ? ( $team_result['team_members'] ?? array() ) : array();

// FALLBACK: If the API didn't return staff, ensure the current booking's team member is listed.
$staff_ids_in_dropdown = array_column( $available_staff, 'id' );
if ( ! empty( $booking['appointment_segments'] ) ) {
    foreach ( $booking['appointment_segments'] as $seg ) {
        $seg_tm_id = $seg['team_member_id'] ?? '';
        if ( ! empty( $seg_tm_id ) && ! in_array( $seg_tm_id, $staff_ids_in_dropdown, true ) ) {
            // Resolve name from transient cache (already set by the detail view rendering above)
            $tm_transient = 'spp_tm_' . $seg_tm_id;
            $cached_name = get_transient( $tm_transient );
            $tm_display = $cached_name ? $cached_name : $seg_tm_id;
            // Split display name into given/family for consistency
            $name_parts = explode( ' ', $tm_display, 2 );
            $available_staff[] = array(
                'id' => $seg_tm_id,
                'given_name' => $name_parts[0] ?? '',
                'family_name' => $name_parts[1] ?? '',
            );
            $staff_ids_in_dropdown[] = $seg_tm_id;
        }
    }
}

// Prepare JS object for current booking
$current_booking_js = array(
    'id' => $booking['id'],
    'version' => $booking['version'],
    'start_at' => date( 'Y-m-d\TH:i', strtotime( $booking['start_at'] ) ), // HTML5 datetime-local format
    'customer_note' => $booking['customer_note'] ?? '',
    'segments' => $booking['appointment_segments'] ?? array(),
);
?>


<!-- Edit Booking Modal -->
<div id="spp-edit-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 600px; max-height: 90vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px;"><?php esc_html_e( 'Edit Booking', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-edit-close" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        
        <form id="spp-edit-form">
            <input type="hidden" name="action" value="spp_update_booking">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>">
            <input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
            <input type="hidden" name="version" value="<?php echo esc_attr( $booking['version'] ); ?>">
            
            <div style="padding: 20px;">
                
                <!-- Date & Time -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Date & Time', 'cube-payment-portal' ); ?></label>
                    <input type="datetime-local" name="start_at" id="edit_start_at" class="widefat" required>
                </div>

                <!-- Service -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Service', 'cube-payment-portal' ); ?></label>
                    <select name="service_variation_id[]" id="edit_service_select" class="widefat" required>
                        <option value=""><?php esc_html_e( '-- Select Service --', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $services_data as $svc ) : ?>
                            <option value="<?php echo esc_attr( $svc['id'] ); ?>" data-team='<?php echo json_encode( $svc['team_member_ids'] ); ?>' data-version="<?php echo esc_attr( $svc['version'] ); ?>">
                                <?php echo esc_html( $svc['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="service_variation_version[]" id="edit_service_version" value="">
                </div>

                <!-- Staff -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Staff Member', 'cube-payment-portal' ); ?></label>
                    <select name="team_member_id[]" id="edit_staff_select" class="widefat">
                        <option value=""><?php esc_html_e( 'Any Team Member', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $available_staff as $staff ) : ?>
                            <option value="<?php echo esc_attr( $staff['id'] ); ?>">
                                <?php echo esc_html( trim( $staff['given_name'] . ' ' . $staff['family_name'] ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Notes -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Customer Note', 'cube-payment-portal' ); ?></label>
                    <textarea name="customer_note" id="edit_customer_note" class="widefat" rows="2"></textarea>
                </div>

            </div>

            <div style="padding: 20px; border-top: 1px solid #eee; background: #f9f9f9; display: flex; justify-content: flex-end; border-radius: 0 0 8px 8px;">
                <button type="button" id="spp-edit-cancel" class="button button-secondary" style="margin-right: 10px;"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'cube-payment-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Current Booking Data
    var bookingData = <?php echo json_encode( $current_booking_js ); ?>;
    var allStaff = <?php echo json_encode( $available_staff ); ?>;
    var modal = $('#spp-edit-modal');
    var staffSelect = $('#edit_staff_select');
    var serviceSelect = $('#edit_service_select');

    // Populate Helper
    function populateForm() {
        $('#edit_start_at').val(bookingData.start_at);
        $('#edit_customer_note').val(bookingData.customer_note || '');
        
        // Populate Service & Staff (assuming single segment for now)
        if (bookingData.segments && bookingData.segments.length > 0) {
            var seg = bookingData.segments[0];
            
            // Store target staff ID so the change handler can restore it
            serviceSelect.data('pending-staff', seg.team_member_id || '');
            
            // Set service - this triggers 'change' which rebuilds staff dropdown
            serviceSelect.val(seg.service_variation_id).trigger('change');
        }
    }

    // Open Edit Modal
    $('#spp-edit-booking-btn').on('click', function(e) {
        e.preventDefault();
        populateForm();
        modal.css('display', 'flex');
    });

    // Close Modal
    $('#spp-edit-close, #spp-edit-cancel').on('click', function() {
        modal.hide();
    });

    // Service -> Filter Staff
    serviceSelect.on('change', function() {
        var opt = $(this).find(':selected');
        var teamIds = opt.data('team');
        var version = opt.data('version') || '';

        // Sync the hidden version input
        $('#edit_service_version').val(version);

        staffSelect.empty();
        staffSelect.append('<option value=""><?php esc_html_e( 'Any Team Member', 'cube-payment-portal' ); ?></option>');
        
        if (allStaff) {
            allStaff.forEach(function(s) {
                if (!teamIds || teamIds.length === 0 || teamIds.includes(s.id)) {
                     staffSelect.append('<option value="' + s.id + '">' + (s.given_name || '') + ' ' + (s.family_name || '') + '</option>');
                }
            });
        }
        
        // Restore pending staff selection (set by populateForm)
        var pendingStaff = serviceSelect.data('pending-staff');
        if (pendingStaff) {
            staffSelect.val(pendingStaff);
            serviceSelect.removeData('pending-staff'); // Clear after using
        }
    });

    // Submit Edit
    $('#spp-edit-form').on('submit', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error updating booking');
                    btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function() {
                alert('Network error');
                btn.prop('disabled', false).text('Save Changes');
            }
        });
    });

    // Cancel Booking Logic
    $('#spp-cancel-booking-btn').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to cancel this booking?', 'cube-payment-portal' ) ); ?>')) {
            return;
        }

        var btn = $(this);
        var bookingId = btn.data('id');
        var version = btn.data('version');
        
        btn.prop('disabled', true).text('<?php echo esc_js( __( 'Cancelling...', 'cube-payment-portal' ) ); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spp_cancel_booking',
                nonce: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>',
                booking_id: bookingId,
                version: version
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js( __( 'Failed to cancel booking.', 'cube-payment-portal' ) ); ?>');
                    btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel Booking', 'cube-payment-portal' ) ); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>');
                btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel Booking', 'cube-payment-portal' ) ); ?>');
            }
        });
    });
});
</script>
