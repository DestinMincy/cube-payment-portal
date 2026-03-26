<?php
/**
 * Bookings staff view.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SPP_Team' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
}

if ( ! class_exists( 'SPP_Bookings' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
}

$team_api = new SPP_Team();
$bookings_api = new SPP_Bookings();

// Fetch active team members.
$result = $team_api->search_team_members( array(
    'filter' => array(
        'status' => 'ACTIVE',
    )
) );

$team_members = array();
$error = null;

if ( is_wp_error( $result ) ) {
    $error = $result->get_error_message();
} else {
    $team_members = $result['team_members'] ?? array();
    
    // Fetch Booking Profiles for "Bookable" status
    $profiles_result = $bookings_api->list_team_member_booking_profiles( false ); 
    $profiles_map = array();
    
    if ( ! is_wp_error( $profiles_result ) ) {
        $profiles = $profiles_result['team_member_booking_profiles'] ?? array();
        foreach ( $profiles as $profile ) {
            $profiles_map[ $profile['team_member_id'] ] = $profile;
        }
    }
}

// No local settings or catalog services needed in simplified version
?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Team Members', 'cube-payment-portal' ); ?></h2>
        <div>
            <a href="https://squareup.com/dashboard/team" target="_blank" class="button" style="margin-right: 10px;">
                <?php esc_html_e( 'Manage Staff in Square', 'cube-payment-portal' ); ?> <span class="dashicons dashicons-external" style="line-height: 26px;"></span>
            </a>
            <button id="spp-add-team-member-btn" class="button button-primary">
                <?php esc_html_e( 'Add Team Member', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <div class="spp-card">
        <div class="spp-card-header" style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 16px; margin: 0; font-weight: 600;"><?php esc_html_e( 'Staff List', 'cube-payment-portal' ); ?></h2>
                <p class="description" style="margin: 5px 0 0; font-size: 13px; color: #666;"><?php esc_html_e( 'Manage your team members, permissions, and service availability.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
        
        <div class="spp-card-body" style="padding: 0;">
            <?php if ( $error ) : ?>
                <div class="notice notice-error inline" style="margin: 20px;"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <table id="spp-team-table" class="wp-list-table widefat fixed striped" style="border: none;">
                <thead>
                    <tr>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Name', 'cube-payment-portal' ); ?></th>
                        <!-- Removed Role header -->
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Email', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Phone', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5; text-align: right;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $team_members ) ) : ?>
                        <?php foreach ( $team_members as $member ) : 
                            $profile = $profiles_map[ $member['id'] ] ?? null;
                            $is_bookable = $profile ? ( $profile['is_bookable'] ?? false ) : false;
                            $display_name = $profile ? ( $profile['display_name'] ?? '' ) : '';
                            $description = $profile ? ( $profile['description'] ?? '' ) : '';
                            ?>
                            <tr>
                                <td style="padding: 15px 20px; font-weight: 500; color: #1d2327; vertical-align: middle;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; background: #006aff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                                            <?php echo esc_html( substr( $member['given_name'], 0, 1 ) . substr( $member['family_name'], 0, 1 ) ); ?>
                                        </div>
                                        <div>
                                            <?php echo esc_html( $member['given_name'] . ' ' . $member['family_name'] ); ?>
                                            <?php if ( $is_bookable ) : ?>
                                                <div style="font-size: 11px; color: #28a745; margin-top: 2px;">&#10003; Bookable Online</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <!-- Removed Permissions Column -->
                                <td style="padding: 15px 20px; color: #50575e; vertical-align: middle;"><?php echo esc_html( $member['email_address'] ?? '' ); ?></td>
                                <td style="padding: 15px 20px; color: #50575e; vertical-align: middle;"><?php echo esc_html( $member['phone_number'] ?? '' ); ?></td>
                                <td style="padding: 15px 20px; vertical-align: middle;">
                                    <span class="spp-status-badge <?php echo 'ACTIVE' === $member['status'] ? 'spp-status-active' : 'spp-status-inactive'; ?>">
                                        <?php echo esc_html( ucfirst( strtolower( $member['status'] ) ) ); ?>
                                    </span>
                                </td>
                                <td style="padding: 15px 20px; text-align: right; vertical-align: middle;">
                                    <a href="#" class="spp-edit-team-member" 
                                       data-id="<?php echo esc_attr( $member['id'] ); ?>"
                                       data-given-name="<?php echo esc_attr( $member['given_name'] ); ?>"
                                       data-family-name="<?php echo esc_attr( $member['family_name'] ); ?>"
                                       data-email="<?php echo esc_attr( $member['email_address'] ?? '' ); ?>"
                                       data-phone="<?php echo esc_attr( $member['phone_number'] ?? '' ); ?>"
                                       data-status="<?php echo esc_attr( $member['status'] ); ?>"
                                       
                                       
                                       data-display-name="<?php echo esc_attr( $display_name ); ?>"
                                       data-description="<?php echo esc_attr( $description ); ?>"
                                       data-is-bookable="<?php echo $is_bookable ? 'true' : 'false'; ?>"
                                       
                                    ><?php esc_html_e( 'Edit', 'cube-payment-portal' ); ?></a>
                                    <span style="color: #ddd; margin: 0 5px;">|</span>
                                    <a href="#" class="spp-delete-team-member" data-id="<?php echo esc_attr( $member['id'] ); ?>" style="color: #a00;"><?php esc_html_e( 'Deactivate', 'cube-payment-portal' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #666;"><?php esc_html_e( 'No team members found.', 'cube-payment-portal' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Add/Edit Team Member Modal -->
<div id="spp-team-member-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 800px; max-height: 90vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="spp-modal-title" style="margin: 0; font-size: 18px;"><?php esc_html_e( 'Add Team Member', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form id="spp-team-member-form">
            <input type="hidden" name="id" id="team_member_id">
            
            <div style="display: flex;">
                <!-- Left Column -->
                <div style="flex: 1; padding: 20px; border-right: 1px solid #eee;">
                    
                    <h3 style="margin: 0 0 15px; font-size: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php esc_html_e( 'Personal Information', 'cube-payment-portal' ); ?></h3>

                    <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label for="given_name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'First Name', 'cube-payment-portal' ); ?></label>
                            <input type="text" id="given_name" name="given_name" class="widefat" required>
                        </div>
                        <div style="flex: 1;">
                            <label for="family_name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Last Name', 'cube-payment-portal' ); ?></label>
                            <input type="text" id="family_name" name="family_name" class="widefat" required>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="display_name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Display Name', 'cube-payment-portal' ); ?></label>
                        <input type="text" id="display_name" name="display_name" class="widefat" placeholder="<?php esc_attr_e( 'Optional. Name displayed to customers.', 'cube-payment-portal' ); ?>">
                    </div>

                    <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label for="email" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Email', 'cube-payment-portal' ); ?></label>
                            <input type="email" id="email" name="email" class="widefat">
                        </div>
                    </div>
                     <div style="margin-bottom: 16px;">
                        <label for="phone_number" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Phone Number', 'cube-payment-portal' ); ?></label>
                        <input type="text" id="phone_number" name="phone_number" class="widefat" placeholder="+1234567890">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <a href="https://squareup.com/dashboard/team" target="_blank" class="button">
                            <?php esc_html_e( 'Manage Roles & Permissions in Square', 'cube-payment-portal' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 26px;"></span>
                        </a>
                        <p class="description"><?php esc_html_e( 'Advanced team permissions are managed in Square.', 'cube-payment-portal' ); ?></p>
                    </div>

                    <h3 style="margin: 20px 0 15px; font-size: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php esc_html_e( 'Compensation', 'cube-payment-portal' ); ?></h3>

                    <div style="margin-bottom: 16px;">
                        <label for="job_title" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Job Title', 'cube-payment-portal' ); ?></label>
                        <input type="text" id="job_title" name="job_title" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Senior Stylist', 'cube-payment-portal' ); ?>">
                    </div>

                    <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label for="pay_type" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Pay Type', 'cube-payment-portal' ); ?></label>
                            <select id="pay_type" name="pay_type" class="widefat">
                                <option value="NONE"><?php esc_html_e( 'None', 'cube-payment-portal' ); ?></option>
                                <option value="HOURLY"><?php esc_html_e( 'Hourly', 'cube-payment-portal' ); ?></option>
                                <option value="SALARY"><?php esc_html_e( 'Annual Salary', 'cube-payment-portal' ); ?></option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label for="pay_amount" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Rate / Salary', 'cube-payment-portal' ); ?></label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #666;">$</span>
                                <input type="number" id="pay_amount" name="pay_amount" class="widefat" style="padding-left: 25px;" step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right Column -->
                <div style="flex: 1; padding: 20px;">
                    
                    <h3 style="margin: 0 0 15px; font-size: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php esc_html_e( 'Online Booking Settings', 'cube-payment-portal' ); ?></h3>

                    <div style="margin-bottom: 16px; background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #eee;">
                        <label for="is_bookable" style="display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 14px; cursor: pointer;">
                            <span><?php esc_html_e( 'Bookable by customers online', 'cube-payment-portal' ); ?></span>
                            <input type="checkbox" id="is_bookable" name="is_bookable" value="true">
                        </label>
                    </div>

                    <!-- Removed Services & Color Selection -->

                     <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Availability', 'cube-payment-portal' ); ?></label>
                        <a href="https://squareup.com/dashboard/appointments/availability" target="_blank" class="button">
                            <?php esc_html_e( 'Manage Hours & Availability', 'cube-payment-portal' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 26px;"></span>
                        </a>
                        <p class="description"><?php esc_html_e( 'Availability is managed directly in Square.', 'cube-payment-portal' ); ?></p>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label for="description" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Biography', 'cube-payment-portal' ); ?></label>
                        <textarea id="description" name="description" class="widefat" rows="2" placeholder="<?php esc_attr_e( 'Add a short bio...', 'cube-payment-portal' ); ?>"></textarea>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label for="status" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></label>
                        <select id="status" name="status" class="widefat">
                            <option value="ACTIVE"><?php esc_html_e( 'Active', 'cube-payment-portal' ); ?></option>
                            <option value="INACTIVE"><?php esc_html_e( 'Inactive', 'cube-payment-portal' ); ?></option>
                        </select>
                    </div>

                </div>
            </div>

            <div style="padding: 20px; border-top: 1px solid #eee; background: #f9f9f9; display: flex; justify-content: flex-end; border-radius: 0 0 8px 8px;">
                <button type="button" id="spp-modal-cancel" class="button button-secondary" style="margin-right: 10px;"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'cube-payment-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>



<script type="text/javascript">
jQuery(document).ready(function($) {
    var modal = $('#spp-team-member-modal');
    var form = $('#spp-team-member-form');
    
    // Color Picker Logic: Removed

    // Helper to reset form
    function resetForm() {
        form[0].reset();
        $('#team_member_id').val('');
        $('#status').val('ACTIVE');
        $('#display_name').val('');
        $('#description').val('');
        $('#is_bookable').prop('checked', false);
        
        // Reset Metadata fields
        $('#permissions').val('Service Provider'); // Default removed but harmless to leave if hidden
        $('input[name^="notification_"]').prop('checked', false);
        $('input[name^="notification_"]').prop('checked', false);
        $('input[name="service_ids[]"]').prop('checked', false);
        
        // Reset Compensation
        $('#job_title').val('');
        $('#pay_type').val('NONE');
        $('#pay_amount').val('');
    }

    // Open Modal (Add)
    $('#spp-add-team-member-btn').on('click', function() {
        $('#spp-modal-title').text('<?php echo esc_js( __( 'Add Team Member', 'cube-payment-portal' ) ); ?>');
        resetForm();
        modal.css('display', 'flex');
    });

    // Open Modal (Edit)
    $(document).on('click', '.spp-edit-team-member', function(e) {
        e.preventDefault();
        $('#spp-modal-title').text('<?php echo esc_js( __( 'Edit Team Member', 'cube-payment-portal' ) ); ?>');
        resetForm();

        var btn = $(this);
        
        $('#team_member_id').val(btn.data('id'));
        $('#given_name').val(btn.data('given-name'));
        $('#family_name').val(btn.data('family-name'));
        $('#email').val(btn.data('email'));
        $('#phone_number').val(btn.data('phone'));
        $('#status').val(btn.data('status'));
        
        // Profile Data
        $('#display_name').val(btn.data('display-name'));
        $('#description').val(btn.data('description'));
        $('#is_bookable').prop('checked', btn.data('is-bookable') === true);

        // Fetch Wage Settings
        // Disable fields while loading
        $('#job_title, #pay_type, #pay_amount').prop('disabled', true).val('Loading...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spp_get_team_member_details',
                id: btn.data('id'),
                nonce: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>'
            },
            success: function(response) {
                if (response.success && response.data) {
                    var data = response.data;
                    $('#job_title').val(data.job_title || '').prop('disabled', false);
                    $('#pay_type').val(data.pay_type || 'NONE').prop('disabled', false);
                    
                    // Format money amount if exists
                    var amount = '';
                    if (data.hourly_rate) {
                        amount = (data.hourly_rate.amount / 100).toFixed(2);
                    } else if (data.annual_rate) {
                        amount = (data.annual_rate.amount / 100).toFixed(2);
                    }
                    $('#pay_amount').val(amount).prop('disabled', false);
                } else {
                     // Enable empty if failed/no data
                     $('#job_title').val('').prop('disabled', false);
                     $('#pay_type').val('NONE').prop('disabled', false);
                     $('#pay_amount').val('').prop('disabled', false);
                }
            },
            error: function() {
                alert('Failed to load compensation details');
                $('#job_title, #pay_type, #pay_amount').prop('disabled', false).val('');
            }
        });

        modal.css('display', 'flex');
    });

    // Close Modal
    $('#spp-modal-close, #spp-modal-cancel').on('click', function() {
        modal.hide();
    });

    // Submit Form
    form.on('submit', function(e) {
        e.preventDefault();
        var submitBtn = $(this).find('button[type="submit"]');
        var originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'cube-payment-portal' ) ); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: form.serialize() + '&action=spp_save_team_member&nonce=<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error saving team member');
                    submitBtn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Network error');
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Deactivate Button
    $(document).on('click', '.spp-delete-team-member', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        
        if (confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate this team member?', 'cube-payment-portal' ) ); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spp_delete_team_member',
                    id: id,
                    nonce: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error deactivating team member');
                    }
                },
                error: function() {
                    alert('Network error');
                }
            });
        }
    });

});
</script>