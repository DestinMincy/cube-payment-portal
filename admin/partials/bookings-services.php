<?php
/**
 * Bookings services view.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}
if ( ! class_exists( 'SPP_Team' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-team.php';
}

$catalog = new SPP_Catalog();
$team_api = new SPP_Team();

// Fetch services (APPOINTMENT_SERVICE).
$services = $catalog->get_appointment_services();

// Fetch active team members for assignment
$team_search = $team_api->search_team_members( array(
    'filter' => array(
        'status' => 'ACTIVE',
    ),
) );

if ( is_wp_error( $team_search ) ) {
    $team_members = array();
} else {
    $team_members = $team_search['team_members'] ?? array();
}

if ( is_wp_error( $services ) ) {
    $services_list = array();
    $error = $services->get_error_message();
} else {
    $services_list = $services['objects'] ?? array();
    $error = null;
    
    // Check for search results structure vs list structure
    if ( isset( $services['items'] ) ) {
         $services_list = $services['items'];
    }
}
?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Bookable Services', 'cube-payment-portal' ); ?></h2>
        <button id="spp-add-service-btn" class="button button-primary">
            <?php esc_html_e( 'Add Service', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <div class="spp-card">
        <div class="spp-card-header" style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 16px; margin: 0; font-weight: 600;"><?php esc_html_e( 'Services List', 'cube-payment-portal' ); ?></h2>
                <p class="description" style="margin: 5px 0 0; font-size: 13px; color: #666;"><?php esc_html_e( 'Manage your appointment services.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
        
        <div class="spp-card-body" style="padding: 0;">
            <?php if ( $error ) : ?>
                <div class="notice notice-error inline" style="margin: 20px;"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <style>
                .spp-services-table .spp-row-odd { background: #f6f7f7; }
                .spp-services-table .spp-row-even { background: #fff; }
                .spp-variant-accordion { background: #f9f9f9 !important; }
            </style>
            <table class="wp-list-table widefat fixed spp-services-table" style="border: none;">
                <thead>
                    <tr>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Service Name', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Duration', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></th>
                        <th style="font-weight: 600; padding: 15px 20px; border-bottom: 1px solid #e5e5e5; text-align: right;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $services_list ) ) : ?>
                        <?php $svc_row_index = 0; ?>
                        <?php foreach ( $services_list as $service ) : ?>
                            <?php $svc_row_class = ( $svc_row_index % 2 === 0 ) ? 'spp-row-odd' : 'spp-row-even'; $svc_row_index++; ?>
                            <?php
                            $item_data = $service['item_data'];
                            $variations = $item_data['variations'] ?? array();
                            $first_var = $variations[0]['item_variation_data'] ?? array();
                            $has_multiple = count( $variations ) > 1;
                            
                            $pricing_type = $first_var['pricing_type'] ?? 'FIXED_PRICING';
                            $price_money = $first_var['price_money'] ?? null;
                            $price_amount = $price_money ? $price_money['amount'] / 100 : 0;
                            
                            $price_display = '';
                            if ( $has_multiple ) {
                                // Show price range for multi-variant
                                $prices = array();
                                foreach ( $variations as $v ) {
                                    $vd = $v['item_variation_data'] ?? array();
                                    if ( ( $vd['pricing_type'] ?? '' ) === 'FIXED_PRICING' && ! empty( $vd['price_money']['amount'] ) ) {
                                        $prices[] = $vd['price_money']['amount'] / 100;
                                    }
                                }
                                if ( ! empty( $prices ) ) {
                                    $min = min( $prices );
                                    $max = max( $prices );
                                    $price_display = ( $min === $max ) ? '$' . number_format( $min, 2 ) : '$' . number_format( $min, 2 ) . ' – $' . number_format( $max, 2 );
                                } else {
                                    $price_display = __( 'Variable', 'cube-payment-portal' );
                                }
                            } elseif ( $pricing_type === 'FIXED_PRICING' ) {
                                $price_display = '$' . number_format( $price_amount, 2 );
                            } else {
                                $price_display = __( 'Variable', 'cube-payment-portal' );
                            }
                            
                            $duration_ms = $first_var['service_duration'] ?? 0;
                            $duration_min_total = $duration_ms / 60000;
                            
                            $bookable = ! empty( $first_var['available_for_booking'] );
                            $team_ids = $first_var['team_member_ids'] ?? array();
                            $accordion_id = 'spp-variants-' . esc_attr( $service['id'] );
                            ?>
                            <tr class="<?php echo esc_attr( $svc_row_class ); ?> <?php echo $has_multiple ? 'spp-has-variants' : ''; ?>" data-accordion="<?php echo esc_attr( $accordion_id ); ?>">
                                <td style="padding: 15px 20px; font-weight: 500; color: #1d2327; vertical-align: middle;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if ( $has_multiple ) : ?>
                                            <span class="spp-accordion-arrow" style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; font-size: 12px; color: #666; transition: transform 0.2s ease; user-select: none;">▸</span>
                                        <?php endif; ?>
                                        <div>
                                            <?php echo esc_html( $item_data['name'] ); ?>
                                            <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                                <?php if ( $bookable ) : ?>
                                    <span style="color: #28a745;">&#10003; <?php esc_html_e( 'Online Booking', 'cube-payment-portal' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #999;"><?php esc_html_e( 'Not Bookable Online', 'cube-payment-portal' ); ?></span>
                                <?php endif; ?>
                                                <?php if ( $has_multiple ) : ?>
                                                    <span style="color: #888; margin-left: 6px;">&middot; <?php echo esc_html( count( $variations ) . ' ' . __( 'variants', 'cube-payment-portal' ) ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 15px 20px; color: #50575e; vertical-align: middle;">
                                    <?php echo esc_html( $has_multiple ? __( 'Varies', 'cube-payment-portal' ) : $duration_min_total . ' mins' ); ?>
                                </td>
                                <td style="padding: 15px 20px; font-weight: 600; vertical-align: middle;">
                                    <?php echo esc_html( $price_display ); ?>
                                </td>
                                <td style="padding: 15px 20px; text-align: right; vertical-align: middle;">
                                    <a href="#" class="spp-edit-service" 
                                       data-id="<?php echo esc_attr( $service['id'] ); ?>"
                                       data-name="<?php echo esc_attr( $item_data['name'] ); ?>"
                                       data-description="<?php echo esc_attr( $item_data['description'] ?? '' ); ?>"
                                       data-duration="<?php echo esc_attr( $duration_min_total ); ?>"
                                       data-pricing-type="<?php echo esc_attr( $pricing_type ); ?>"
                                       data-price="<?php echo esc_attr( $price_amount ); ?>"
                                       data-bookable="<?php echo $bookable ? 'true' : 'false'; ?>"
                                       data-team-ids='<?php echo json_encode( $team_ids ); ?>'
                                       data-variations='<?php echo json_encode( $variations ); ?>'
                                    ><?php esc_html_e( 'Edit', 'cube-payment-portal' ); ?></a>
                                    <span style="color: #ddd; margin: 0 5px;">|</span>
                                    <a href="#" class="spp-delete-service" data-id="<?php echo esc_attr( $service['id'] ); ?>" style="color: #a00;"><?php esc_html_e( 'Delete', 'cube-payment-portal' ); ?></a>
                                </td>
                            </tr>
                            <?php if ( $has_multiple ) : ?>
                            <tr class="spp-variant-accordion" id="<?php echo esc_attr( $accordion_id ); ?>" style="display: none;">
                                <td colspan="4" style="padding: 0 20px 0 48px; background: #f9f9f9;">
                                    <table style="width: 100%; border: none; border-collapse: collapse;">
                                        <?php foreach ( $variations as $var ) :
                                            $vd = $var['item_variation_data'] ?? array();
                                            $v_name = $vd['name'] ?? '—';
                                            $v_dur_ms = $vd['service_duration'] ?? 0;
                                            $v_dur_min = $v_dur_ms / 60000;
                                            $v_pt = $vd['pricing_type'] ?? 'FIXED_PRICING';
                                            $v_pm = $vd['price_money'] ?? null;
                                            $v_price = '';
                                            if ( $v_pt === 'FIXED_PRICING' && $v_pm ) {
                                                $v_price = '$' . number_format( $v_pm['amount'] / 100, 2 );
                                            } else {
                                                $v_price = __( 'Variable', 'cube-payment-portal' );
                                            }
                                            $v_bookable = ! empty( $vd['available_for_booking'] );
                                        ?>
                                        <tr style="border-bottom: 1px solid #eee;">
                                            <td style="padding: 10px 8px; color: #444; font-size: 13px; width: 35%;">
                                                <?php echo esc_html( $v_name ); ?>
                                                <?php if ( $v_bookable ) : ?>
                                                    <span style="color: #28a745; font-size: 11px; margin-left: 4px;">&#10003;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 10px 8px; color: #666; font-size: 13px; width: 25%;">
                                                <?php echo esc_html( $v_dur_min . ' mins' ); ?>
                                            </td>
                                            <td style="padding: 10px 8px; color: #444; font-size: 13px; font-weight: 600;">
                                                <?php echo esc_html( $v_price ); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: #666;"><?php esc_html_e( 'No services found.', 'cube-payment-portal' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Add/Edit Service Modal -->
<div id="spp-service-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 600px; max-height: 90vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="spp-modal-title" style="margin: 0; font-size: 18px;"><?php esc_html_e( 'Create Service', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form id="spp-service-form">
            <input type="hidden" name="service_id" id="service_id">
            
            <div style="padding: 20px;">
                
                <!-- General -->
                <div style="margin-bottom: 24px;">
                    <label for="service_name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Name', 'cube-payment-portal' ); ?></label>
                    <input type="text" id="service_name" name="name" class="widefat" required placeholder="e.g. Haircut">
                </div>

                <div style="margin-bottom: 24px;">
                    <label for="service_description" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;"><?php esc_html_e( 'Description', 'cube-payment-portal' ); ?></label>
                    <textarea id="service_description" name="description" class="widefat" rows="2" placeholder="Optional description"></textarea>
                </div>
                
                <hr style="border: 0; border-top: 1px solid #eee; margin: 24px 0;">

                <!-- Variations -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="font-size: 15px; margin: 0; font-weight: 600;"><?php esc_html_e( 'Variations', 'cube-payment-portal' ); ?></h3>
                    <button type="button" id="spp-add-variation-btn" class="button button-secondary button-small"><?php esc_html_e( '+ Add Variation', 'cube-payment-portal' ); ?></button>
                </div>
                
                <div id="spp-variations-container">
                    <!-- Variation rows will be added here via JS -->
                </div>

            </div>

            <div style="padding: 20px; border-top: 1px solid #eee; background: #f9f9f9; display: flex; justify-content: space-between; border-radius: 0 0 8px 8px;">
                <div>
                     <button type="button" id="spp-modal-delete" class="button button-link-delete" style="color: #a00; text-decoration: none; display: none;"><?php esc_html_e( 'Delete Service', 'cube-payment-portal' ); ?></button>
                </div>
                <div>
                    <button type="button" id="spp-modal-cancel" class="button button-secondary" style="margin-right: 10px;"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'cube-payment-portal' ); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var modal = $('#spp-service-modal');
    var form = $('#spp-service-form');
    var variationsContainer = $('#spp-variations-container');

    // Accordion toggle for multi-variant services
    $(document).on('click', '.spp-has-variants td:first-child', function() {
        var row = $(this).closest('tr');
        var accordionId = row.data('accordion');
        var accordion = $('#' + accordionId);
        var arrow = row.find('.spp-accordion-arrow');
        
        if (accordion.is(':visible')) {
            accordion.slideUp(200);
            arrow.css('transform', 'rotate(0deg)');
        } else {
            accordion.slideDown(200);
            arrow.css('transform', 'rotate(90deg)');
        }
    });
    
    // Team Members Data passed from PHP
    var teamMembers = <?php echo json_encode( $team_members ); ?>;

    /**
     * Add Variation Row
     */
    function addVariation(data = {}) {
        var index = $('.spp-variation-row').length;
        var varId = data.id || '';
        var name = data.item_variation_data ? data.item_variation_data.name : (data.name || 'Regular');
        
        var priceMoney = data.item_variation_data ? data.item_variation_data.price_money : {};
        var price = priceMoney.amount ? (priceMoney.amount / 100).toFixed(2) : (data.price || '');
        var pricingType = data.item_variation_data ? data.item_variation_data.pricing_type : (data.pricing_type || 'FIXED_PRICING');
        
        var durationMs = data.item_variation_data ? data.item_variation_data.service_duration : (data.service_duration || 0);
        var durationTotal = Math.floor(durationMs / 60000);
        var hrs = Math.floor(durationTotal / 60);
        var mins = durationTotal % 60;
        
        var bookable = true;
        if (data.item_variation_data) {
            bookable = data.item_variation_data.available_for_booking !== false;
        } else if (typeof data.available_for_booking !== 'undefined') {
            bookable = data.available_for_booking === true;
        }

        var assignedTeamIds = data.item_variation_data ? (data.item_variation_data.team_member_ids || []) : (data.team_member_ids || []);

        var teamHtml = '';
        if (teamMembers && teamMembers.length > 0) {
            teamHtml += '<div style="max-height: 100px; overflow-y: auto; border: 1px solid #ddd; padding: 5px; border-radius: 4px; background: #fff;">';
            teamMembers.forEach(function(member) {
                var checked = assignedTeamIds.includes(member.id) ? 'checked' : '';
                var fullName = (member.given_name || '') + ' ' + (member.family_name || '');
                teamHtml += `
                    <div style="font-size: 13px; margin-bottom: 4px;">
                        <label>
                            <input type="checkbox" class="var-team-checkbox" value="${member.id}" ${checked}>
                            ${fullName}
                        </label>
                    </div>
                `;
            });
            teamHtml += '</div>';
        } else {
            teamHtml = '<p style="color: #666; font-size: 12px; margin: 0;">No active team members.</p>';
        }

        var html = `
            <div class="spp-variation-row" data-id="${varId}" style="background: #fdfdfd; border: 1px solid #e2e4e7; border-radius: 4px; padding: 15px; margin-bottom: 12px; position: relative;">
                <button type="button" class="button-link spp-remove-variation" style="position: absolute; top: 10px; right: 10px; color: #a00; text-decoration: none;">&times; Remove</button>
                
                <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                    <div style="flex: 2;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Variation Name</label>
                        <input type="text" class="widefat var-name" value="${name}" placeholder="Regular">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Price</label>
                        <input type="number" class="widefat var-price" value="${price}" step="0.01" min="0">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Type</label>
                        <select class="widefat var-pricing-type">
                            <option value="FIXED_PRICING" ${pricingType === 'FIXED_PRICING' ? 'selected' : ''}>Fixed</option>
                            <option value="VARIABLE_PRICING" ${pricingType === 'VARIABLE_PRICING' ? 'selected' : ''}>Var</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 12px;">
                    <div style="flex: 1.5;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Duration</label>
                        <div style="display: flex; gap: 5px;">
                            <select class="var-hrs" style="width: 50%;">
                                ${[...Array(13).keys()].map(i => `<option value="${i}" ${i === hrs ? 'selected' : ''}>${i}h</option>`).join('')}
                            </select>
                            <select class="var-mins" style="width: 50%;">
                                ${[0, 5, 10, 15, 20, 30, 45, 50].map(i => `<option value="${i}" ${i === mins ? 'selected' : ''}>${i}m</option>`).join('')}
                            </select>
                        </div>
                        <div style="margin-top: 8px;">
                            <label style="font-size: 13px;">
                                <input type="checkbox" class="var-bookable" ${bookable ? 'checked' : ''}> Online Booking
                            </label>
                        </div>
                    </div>
                    <div style="flex: 2.5;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Team Members</label>
                        ${teamHtml}
                    </div>
                </div>
            </div>
        `;
        
        variationsContainer.append(html);
    }
    
    // Helper to reset form
    function resetForm() {
        form[0].reset();
        $('#service_id').val('');
        variationsContainer.empty();
        $('#spp-modal-delete').hide(); // Hide delete on Add
    }

    // Open Modal (Add)
    $('#spp-add-service-btn').on('click', function() {
        $('#spp-modal-title').text('<?php echo esc_js( __( 'Create Service', 'cube-payment-portal' ) ); ?>');
        resetForm();
        addVariation(); // Add one default blank variation
        modal.css('display', 'flex');
    });

    // Add Variation Button Click
    $('#spp-add-variation-btn').on('click', function() {
        addVariation();
    });

    // Remove Variation Click
    $('body').on('click', '.spp-remove-variation', function() {
        if ($('.spp-variation-row').length > 1) {
            $(this).closest('.spp-variation-row').remove();
        } else {
            alert('A service must have at least one variation.');
        }
    });

    // Open Modal (Edit)
    $('.spp-edit-service').on('click', function(e) {
        e.preventDefault();
        $('#spp-modal-title').text('<?php echo esc_js( __( 'Edit Service', 'cube-payment-portal' ) ); ?>');
        resetForm();

        var btn = $(this);
        
        $('#service_id').val(btn.data('id'));
        $('#service_name').val(btn.data('name'));
        $('#service_description').val(btn.data('description'));
        
        // Load Variations
        var variations = btn.data('variations');
        
        if (variations && variations.length > 0) {
            variations.forEach(function(v) {
                addVariation(v);
            });
        } else {
            // Fallback for flat attributes if no deeper variation data found
            // (Shouldn't happen with new PHP logic but good safety)
            addVariation({
                name: 'Regular',
                price: btn.data('price'),
                pricing_type: btn.data('pricing-type'),
                service_duration: btn.data('duration') * 60000,
                available_for_booking: btn.data('bookable'),
                team_member_ids: btn.data('team-ids')
            });
        }
        
        // Show delete button in modal for existing services
        $('#spp-modal-delete').show().data('id', btn.data('id'));

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
        
        // Collect Variations Data
        var variations = [];
        $('.spp-variation-row').each(function() {
            var row = $(this);
            var varId = row.data('id');
            var hrs = parseInt(row.find('.var-hrs').val()) || 0;
            var mins = parseInt(row.find('.var-mins').val()) || 0;
            var durationMins = (hrs * 60) + mins;
            
            var teamIds = [];
            row.find('.var-team-checkbox:checked').each(function() {
                teamIds.push($(this).val());
            });

            variations.push({
                id: varId,
                name: row.find('.var-name').val(),
                price: row.find('.var-price').val(),
                pricing_type: row.find('.var-pricing-type').val(),
                duration_minutes: durationMins,
                available_for_booking: row.find('.var-bookable').is(':checked'),
                team_member_ids: teamIds
            });
        });

        // Add variations to form data
        var formData = form.serializeArray();
        formData.push({name: 'variations', value: JSON.stringify(variations)});
        // Add action and nonce
        formData.push({name: 'action', value: 'spp_save_service'});
        formData.push({name: 'nonce', value: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>'});

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $.param(formData),
            success: function(response) {
                if (response.success) {
                    // Refresh page to show structure changes (easier as HTML structure is complex)
                    // Or we could dynamic update, but with variations it's complex to map.
                    // Let's reload for safety first, or try dynamic.
                    // Given the request was for dynamic, let's try to update dynamically but it requires refetching
                    // because the table structure depends on variations.
                    // Actually, simple reload might be safer for complex data structure changes.
                    // BUT USER REQUESTED DYNAMIC.
                    // I'll stick to dynamic update of the row.
                    
                    var service = response.data.service;
                    var itemData = service.item_data || {};
                    var vars = itemData.variations || [];
                    var name = itemData.name || '';
                    
                    // Construct row HTML
                    var firstVar = (vars[0] && vars[0].item_variation_data) ? vars[0].item_variation_data : {};
                    var priceM = firstVar.price_money || {};
                    var priceAmt = (priceM.amount || 0) / 100;
                    var priceDisplay = (firstVar.pricing_type === 'FIXED_PRICING') ? '$' + priceAmt.toFixed(2) : 'Variable';
                    var durMs = firstVar.service_duration || 0;
                    var durMins = durMs / 60000;
                    var bookable = firstVar.available_for_booking !== false;
                    var teamIds = firstVar.team_member_ids || [];

                    // If multiple variations, show range or "X Variations"
                    if (vars.length > 1) {
                        priceDisplay = vars.length + ' Variations';
                        durMins = 'Varies';
                    }

                    var rowHtml = `
                                <td style="padding: 15px 20px; font-weight: 500; color: #1d2327; vertical-align: middle;">
                                    ${$('<div/>').text(name).html()}
                                    <div style="font-size: 12px; color: #666; margin-top: 2px;">
                                        ${bookable ? '<span style="color: #28a745;">&#10003; Online Booking</span>' : '<span style="color: #999;">Not Bookable Online</span>'}
                                    </div>
                                </td>
                                <td style="padding: 15px 20px; color: #50575e; vertical-align: middle;">
                                    ${durMins !== 'Varies' ? durMins + ' mins' : 'Varies'}
                                </td>
                                <td style="padding: 15px 20px; font-weight: 600; vertical-align: middle;">
                                    ${priceDisplay}
                                </td>
                                <td style="padding: 15px 20px; text-align: right; vertical-align: middle;">
                                    <a href="#" class="spp-edit-service" 
                                       data-id="${service.id}"
                                       data-name="${$('<div/>').text(name).html().replace(/"/g, '&quot;')}"
                                       data-description="${$('<div/>').text(itemData.description || '').html().replace(/"/g, '&quot;')}"
                                       data-duration="${durMins}"
                                       data-pricing-type="${firstVar.pricing_type}"
                                       data-price="${priceAmt}"
                                       data-bookable="${bookable}"
                                       data-team-ids='${JSON.stringify(teamIds).replace(/'/g, "&#39;")}'
                                       data-variations='${JSON.stringify(vars).replace(/'/g, "&#39;")}'
                                    ><?php esc_html_e( 'Edit', 'cube-payment-portal' ); ?></a>
                                    <span style="color: #ddd; margin: 0 5px;">|</span>
                                    <a href="#" class="spp-delete-service" data-id="${service.id}" style="color: #a00;"><?php esc_html_e( 'Delete', 'cube-payment-portal' ); ?></a>
                                </td>
                    `;

                    var existingRow = $('a.spp-edit-service[data-id="' + service.id + '"]').closest('tr');
                     if (existingRow.length > 0) {
                        existingRow.html(rowHtml);
                    } else {
                        var tbody = $('.wp-list-table tbody');
                        if (tbody.find('td[colspan]').length > 0) tbody.empty();
                        tbody.append('<tr>' + rowHtml + '</tr>');
                    }

             

                    modal.hide();
                    submitBtn.prop('disabled', false).text(originalText);
                    
                } else {
                    alert(response.data.message || 'Error saving service');
                    submitBtn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Network error');
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Delete Service Handler
    $('body').on('click', '.spp-delete-service, #spp-modal-delete', function(e) {
        e.preventDefault();
        var serviceId = $(this).data('id');
        
        if (!serviceId) return;
        
        if (confirm('<?php echo esc_js( __( 'Are you sure you want to delete this service? This cannot be undone.', 'cube-payment-portal' ) ); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spp_delete_service',
                    nonce: '<?php echo wp_create_nonce( 'spp_admin_nonce' ); ?>',
                    service_id: serviceId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row from DOM
                        $('a.spp-delete-service[data-id="' + serviceId + '"]').closest('tr').fadeOut(300, function() { 
                            $(this).remove();
                            
                            // If table empty, show "No services" message
                            if ($('.wp-list-table tbody tr').length === 0) {
                                $('.wp-list-table tbody').html(
                                    '<tr><td colspan="4" style="text-align: center; padding: 40px; color: #666;"><?php esc_html_e( 'No services found.', 'cube-payment-portal' ); ?></td></tr>'
                                );
                            }
                        });
                        
                        // If checking strictly for modal delete button click
                        if ($('#spp-service-modal').is(':visible') && $('#spp-modal-delete').length > 0) {
                            $('#spp-service-modal').hide();
                        }
                    } else {
                        alert(response.data.message || 'Error deleting service');
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