<?php
/**
 * Bookings list view.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure SPP_Bookings is loaded for status display helper.
if ( ! class_exists( 'SPP_Bookings' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
}

// Handle manual sync.
$sync_message = null;
if ( isset( $_POST['spp_sync_bookings'] ) && wp_verify_nonce( $_POST['spp_sync_nonce'], 'spp_sync_bookings' ) ) {
    if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
        require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
    }
    
    $sync = new SPP_Sync_Bookings();
    $result = $sync->sync_from_square();
    
    if ( is_wp_error( $result ) ) {
        $sync_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
    } else {
        $sync_message = array(
            'type' => 'success',
            'text' => sprintf( __( 'Synced %d booking(s) with %d error(s).', 'cube-payment-portal' ), $result['synced'], $result['errors'] ),
        );
    }
}

// Fetch bookings.
$page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit = 20;
$offset = ( $page - 1 ) * $limit;

$args = array(
    'limit'  => $limit,
    'offset' => $offset,
    'order'  => 'ASC',
    'orderby' => 'start_at',
);

$bookings = SPP_Database::get_bookings( $args );
$total_bookings = SPP_Database::get_bookings_count( $args );
$total_pages = ceil( $total_bookings / $limit );

// Fetch team members to resolve names.
$team_map = array();
if ( ! empty( $bookings ) ) {
    if ( ! class_exists( 'SPP_Team' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
    }
    $team_api = new SPP_Team();
    $team_result = $team_api->search_team_members( array( 'filter' => array( 'status' => 'ACTIVE' ) ) );
    if ( ! is_wp_error( $team_result ) && ! empty( $team_result['team_members'] ) ) {
        foreach ( $team_result['team_members'] as $tm ) {
            $team_map[ $tm['id'] ] = trim( ( $tm['given_name'] ?? '' ) . ' ' . ( $tm['family_name'] ?? '' ) ) ?: $tm['id'];
        }
    }
}

?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Bookings', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <form method="post" style="margin: 0;">
                <?php wp_nonce_field( 'spp_sync_bookings', 'spp_sync_nonce' ); ?>
                <button type="submit" name="spp_sync_bookings" class="spp-button">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Now', 'cube-payment-portal' ); ?>
                </button>
            </form>
            <button id="spp-new-booking-btn" class="spp-button">
                <span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'New Booking', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <?php if ( isset( $sync_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $sync_message['type'] ); ?> is-dismissible"><p><?php echo esc_html( $sync_message['text'] ); ?></p></div>
    <?php endif; ?>

    <div class="spp-card">
        <div class="spp-card-body">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Time', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Service', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Staff', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $bookings ) ) : ?>
                        <?php foreach ( $bookings as $booking ) : ?>
                            <?php
                            $start_date = date_i18n( get_option( 'date_format' ), strtotime( $booking['start_at'] ) );
                            $start_time = date_i18n( get_option( 'time_format' ), strtotime( $booking['start_at'] ) );
                            $end_time = date_i18n( get_option( 'time_format' ), strtotime( $booking['end_at'] ) );
                            
                            $status_class = 'spp-status-pending';
                            switch ( $booking['status'] ) {
                                case 'ACCEPTED': $status_class = 'spp-status-active'; break;
                                case 'CANCELLED_BY_CUSTOMER':
                                case 'CANCELLED_BY_SELLER': 
                                case 'DECLINED': 
                                case 'NO_SHOW': $status_class = 'spp-status-canceled'; break;
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $start_date ); ?></td>
                                <td><?php echo esc_html( $start_time . ' - ' . $end_time ); ?></td>
                                <td>
                                    <?php if ( ! empty( $booking['customer_id'] ) ) : ?>
                                        <?php 
                                        $customer = SPP_Database::get_customer_by_square_id( $booking['customer_id'] );
                                        $customer_name = $booking['customer_id'];
                                        if ( $customer ) {
                                            $customer_name = trim( ( $customer['given_name'] ?? '' ) . ' ' . ( $customer['family_name'] ?? '' ) ) ?: $customer['email'] ?: $customer['square_customer_id'];
                                        }
                                        ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers&customer_id=' . $booking['customer_id'] ) ); ?>">
                                            <?php echo esc_html( $customer_name ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Guest', 'cube-payment-portal' ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $booking['service_name'] ?: 'Unknown Service' ); ?></td>
                                <td><?php echo esc_html( $team_map[ $booking['team_member_id'] ] ?? $booking['team_member_id'] ); ?></td>
                                <td>
                                    <span class="spp-status-badge <?php echo esc_attr( $status_class ); ?>">
                                        <?php echo esc_html( SPP_Bookings::get_status_display( $booking['status'] ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=spp-bookings&view=detail&id=<?php echo esc_attr( $booking['id'] ); ?>" class="button button-small">
                                        <?php esc_html_e( 'View', 'cube-payment-portal' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'No bookings found.', 'cube-payment-portal' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="pagination-links">
                            <?php
                            echo paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => __( '&laquo;', 'cube-payment-portal' ),
                                'next_text' => __( '&raquo;', 'cube-payment-portal' ),
                                'total'     => $total_pages,
                                'current'   => $page,
                            ) );
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php
// Pre-fetch Data for Modal (Services & Staff)
// In a real app we might AJAX this, but for simplicity let's pre-load available options.
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}
$catalog_api = new SPP_Catalog();
$services_result = $catalog_api->get_appointment_services();
$available_services = $services_result['objects'] ?? array();
// Map: ID -> Name, Duration, Variation
$services_data = array();
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
                'team_member_ids' => $var['item_variation_data']['team_member_ids'] ?? array(),
                'duration' => $var['item_variation_data']['service_duration'] ?? 0,
            );
        }
    }
}

// Staff (Already loaded in $team_map but we need full objects for filtering)
// Reuse $team_result from top of file if available
$available_staff = array();
if ( isset( $team_result['team_members'] ) ) {
    $available_staff = $team_result['team_members'];
}
?>

<style>
    #spp-booking-modal .ui-autocomplete {
        z-index: 10001 !important;
        max-height: 200px;
        overflow-y: auto;
        overflow-x: hidden;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        list-style: none;
        padding: 0;
        margin: 0;
    }
    #spp-booking-modal .ui-menu-item-wrapper {
        padding: 8px 12px;
        cursor: pointer;
        font-size: 13px;
    }
    #spp-booking-modal .ui-menu-item-wrapper.ui-state-active,
    #spp-booking-modal .ui-menu-item-wrapper:hover {
        background: #0073aa;
        color: #fff;
    }
</style>

<!-- New Booking Modal -->
<div id="spp-booking-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 600px; max-height: 90vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px;"><?php esc_html_e( 'New Booking', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        
        <form id="spp-booking-form">
            <input type="hidden" name="action" value="spp_create_booking">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>">
            
            <div style="padding: 20px;">
                
                <!-- Customer Search -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></label>
                    <input type="text" id="spp_customer_search" class="widefat" placeholder="<?php esc_attr_e( 'Search by name or email...', 'cube-payment-portal' ); ?>">
                    <input type="hidden" name="customer_id" id="spp_customer_id" required>
                    <div id="spp_customer_results" style="border: 1px solid #ddd; display: none; max-height: 200px; overflow-y: auto; margin-top: 5px;"></div>
                </div>

                <!-- Date & Time -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Date & Time', 'cube-payment-portal' ); ?></label>
                    <input type="datetime-local" name="start_at" class="widefat" required>
                </div>

                <!-- Service -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Service', 'cube-payment-portal' ); ?></label>
                    <select name="service_variation_id[]" id="spp_service_select" class="widefat" required>
                        <option value=""><?php esc_html_e( '-- Select Service --', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $services_data as $svc ) : ?>
                            <option value="<?php echo esc_attr( $svc['id'] ); ?>" data-team='<?php echo json_encode( $svc['team_member_ids'] ); ?>'>
                                <?php echo esc_html( $svc['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Staff -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Staff Member', 'cube-payment-portal' ); ?></label>
                    <select name="team_member_id[]" id="spp_staff_select" class="widefat">
                        <option value=""><?php esc_html_e( 'Any Team Member', 'cube-payment-portal' ); ?></option>
                        <!-- Populated via JS based on Service -->
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
                    <textarea name="customer_note" class="widefat" rows="2"></textarea>
                </div>

            </div>

            <div style="padding: 20px; border-top: 1px solid #eee; background: #f9f9f9; display: flex; justify-content: flex-end; border-radius: 0 0 8px 8px;">
                <button type="button" id="spp-modal-cancel" class="button button-secondary" style="margin-right: 10px;"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Create Booking', 'cube-payment-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var modal = $('#spp-booking-modal');
    var customerResults = $('#spp_customer_results');
    var staffSelect = $('#spp_staff_select');
    var allStaff = <?php echo json_encode( $available_staff ); ?>;

    // Open Modal
    $('#spp-new-booking-btn').on('click', function(e) {
        e.preventDefault();
        $('#spp-booking-form')[0].reset();
        $('#spp_customer_id').val('');
        modal.css('display', 'flex');
    });

    // Close Modal
    $('#spp-modal-close, #spp-modal-cancel').on('click', function() {
        modal.hide();
    });

    // Customer Autocomplete Search
    $('#spp_customer_search').autocomplete({
        minLength: 2,
        delay: 400,
        appendTo: '#spp-booking-modal',
        source: function(request, response) {
            console.log('[SPP] Searching customers for:', request.term);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spp_search_customers_autocomplete',
                    term: request.term,
                    nonce: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>'
                },
                success: function(data) {
                    console.log('[SPP] Customer search response:', data);
                    if (data.success && data.data && data.data.length > 0) {
                        response(data.data);
                    } else {
                        response([{label: '<?php echo esc_js( __( 'No customers found', 'cube-payment-portal' ) ); ?>', value: ''}]);
                    }
                },
                error: function(xhr, status, err) {
                    console.error('[SPP] Customer search error:', status, err);
                    response([]);
                }
            });
        },
        select: function(event, ui) {
            if (!ui.item.value) return false; // Ignore "no results" item
            $('#spp_customer_id').val(ui.item.value);
            $('#spp_customer_search').val(ui.item.label);
            return false;
        },
        focus: function(event, ui) {
            if (!ui.item.value) return false;
            $('#spp_customer_search').val(ui.item.label);
            return false;
        }
    });

    // Service Selection -> Filter Staff
    $('#spp_service_select').on('change', function() {
        var opt = $(this).find(':selected');
        var teamIds = opt.data('team');
        
        staffSelect.empty();
        staffSelect.append('<option value=""><?php esc_html_e( 'Any Team Member', 'cube-payment-portal' ); ?></option>');
        
        if (allStaff) {
            allStaff.forEach(function(s) {
                // If service has strict team_member_ids, filter. If empty, all allowed? 
                // Usually Square catalog implies if team_member_ids is populated, only they can perform it.
                if (!teamIds || teamIds.length === 0 || teamIds.includes(s.id)) {
                     staffSelect.append(`<option value="${s.id}">${s.given_name} ${s.family_name}</option>`);
                }
            });
        }
    });

    // Submit
    $('#spp-booking-form').on('submit', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Creating...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error creating booking');
                    btn.prop('disabled', false).text('Create Booking');
                }
            },
            error: function() {
                alert('Network error');
                btn.prop('disabled', false).text('Create Booking');
            }
        });
    });
});
</script>