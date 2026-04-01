<?php
/**
 * Admin Dispute Detail page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Get dispute ID from URL.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$dispute_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

if ( empty( $dispute_id ) ) {
    wp_die( esc_html__( 'Dispute ID is required.', 'cube-payment-portal' ) );
}

// Load Disputes API.
if ( ! class_exists( 'SPP_Disputes' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-disputes.php';
}

// Helper function.
if ( ! function_exists( 'spp_format_currency' ) ) {
    function spp_format_currency( $amount, $currency = 'USD' ) {
        $symbol = '$';
        if ( 'EUR' === $currency ) {
            $symbol = '€';
        } elseif ( 'GBP' === $currency ) {
            $symbol = '£';
        }
        return $symbol . number_format( (float) $amount, 2 );
    }
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Dispute Details', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-disputes' ) ); ?>" style="text-decoration: none; color: inherit;">
            ← <?php esc_html_e( 'Disputes', 'cube-payment-portal' ); ?>
        </a>
        <span style="color: #999;"> / </span>
        <?php esc_html_e( 'Dispute Details', 'cube-payment-portal' ); ?>
    </h2>


    <!-- Loading -->
    <div id="spp-loading" style="text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading dispute details...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Error -->
    <div id="spp-error" style="display: none;" class="notice notice-error">
        <p id="spp-error-message"></p>
    </div>

    <!-- Message -->
    <div id="spp-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Dispute Content -->
    <div id="spp-dispute-content" style="display: none;">
        
        <!-- Header Card -->
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;">
                <div>
                    <h2 style="margin: 0 0 10px 0; font-size: 24px;">
                        <span id="dispute-amount" style="font-weight: 700;"></span>
                        <span id="dispute-state-badge" class="spp-status-badge" style="margin-left: 10px;"></span>
                    </h2>
                    <p style="color: #666; margin: 0;">
                        <strong><?php esc_html_e( 'Dispute ID:', 'cube-payment-portal' ); ?></strong>
                        <code id="dispute-id" style="cursor: pointer;" title="<?php esc_attr_e( 'Click to copy', 'cube-payment-portal' ); ?>"></code>
                    </p>
                </div>
                <div id="dispute-deadline" style="text-align: right; display: none;">
                    <div style="background: #fcf0f0; border: 1px solid #d63638; border-radius: 6px; padding: 15px;">
                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                        <strong style="color: #d63638;"><?php esc_html_e( 'Response Deadline:', 'cube-payment-portal' ); ?></strong>
                        <span id="deadline-date" style="color: #d63638; font-weight: 600;"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px;">
            
            <!-- Dispute Info -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 15px 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <span class="dashicons dashicons-info" style="color: #2271b1; margin-right: 5px;"></span>
                    <?php esc_html_e( 'Dispute Information', 'cube-payment-portal' ); ?>
                </h3>
                <table style="width: 100%;">
                    <tr>
                        <td style="padding: 8px 0; color: #666;"><?php esc_html_e( 'Reason:', 'cube-payment-portal' ); ?></td>
                        <td id="dispute-reason" style="padding: 8px 0; font-weight: 500;"></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #666;"><?php esc_html_e( 'Card Brand:', 'cube-payment-portal' ); ?></td>
                        <td id="dispute-card-brand" style="padding: 8px 0;"></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #666;"><?php esc_html_e( 'Created:', 'cube-payment-portal' ); ?></td>
                        <td id="dispute-created" style="padding: 8px 0;"></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #666;"><?php esc_html_e( 'Payment ID:', 'cube-payment-portal' ); ?></td>
                        <td id="dispute-payment-id" style="padding: 8px 0;"></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #666;"><?php esc_html_e( 'Location ID:', 'cube-payment-portal' ); ?></td>
                        <td id="dispute-location-id" style="padding: 8px 0;"></td>
                    </tr>
                </table>
            </div>

            <!-- Suggested Evidence -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 15px 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <span class="dashicons dashicons-lightbulb" style="color: #dba617; margin-right: 5px;"></span>
                    <?php esc_html_e( 'Suggested Evidence', 'cube-payment-portal' ); ?>
                </h3>
                <p style="color: #666; margin-bottom: 10px;"><?php esc_html_e( 'Based on the dispute reason, provide these types of evidence to improve your chances of winning:', 'cube-payment-portal' ); ?></p>
                <ul id="suggested-evidence-list" style="margin: 0; padding-left: 20px;">
                </ul>
            </div>

        </div>

        <!-- Evidence Section -->
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h3 style="margin: 0 0 15px 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                <span class="dashicons dashicons-media-document" style="color: #2271b1; margin-right: 5px;"></span>
                <?php esc_html_e( 'Submitted Evidence', 'cube-payment-portal' ); ?>
            </h3>
            
            <div id="evidence-list"></div>
            
            <div id="evidence-empty" style="display: none; text-align: center; padding: 30px; color: #666; background: #f9f9f9; border-radius: 4px;">
                <span class="dashicons dashicons-upload" style="font-size: 32px; width: 32px; height: 32px; color: #999;"></span>
                <p><?php esc_html_e( 'No evidence uploaded yet. Add evidence below to challenge this dispute.', 'cube-payment-portal' ); ?></p>
            </div>
            
            <p style="color: #999; font-size: 12px; margin-top: 10px;">
                <strong><?php esc_html_e( 'Note:', 'cube-payment-portal' ); ?></strong>
                <?php esc_html_e( 'Keep copies of all files you upload. Square does not store the files themselves after processing.', 'cube-payment-portal' ); ?>
            </p>
        </div>

        <!-- Actions Section (only if state allows) -->
        <div id="dispute-actions" style="display: none; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 15px 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                <span class="dashicons dashicons-admin-tools" style="color: #2271b1; margin-right: 5px;"></span>
                <?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?>
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                
                <!-- Add Text Evidence -->
                <div style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4 style="margin: 0 0 10px 0;"><?php esc_html_e( 'Add Text Evidence', 'cube-payment-portal' ); ?></h4>
                    
                    <div style="margin-bottom: 10px;">
                        <label for="text-evidence-type" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Evidence Type:', 'cube-payment-portal' ); ?></label>
                        <select id="text-evidence-type" style="width: 100%;">
                            <?php foreach ( SPP_Disputes::get_evidence_types() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <label for="text-evidence-content" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Evidence Text:', 'cube-payment-portal' ); ?> <span style="color: #666; font-weight: normal;">(<?php esc_html_e( 'max 500 chars', 'cube-payment-portal' ); ?>)</span></label>
                        <textarea id="text-evidence-content" rows="4" style="width: 100%;" maxlength="500" placeholder="<?php esc_attr_e( 'Describe the evidence supporting your case...', 'cube-payment-portal' ); ?>"></textarea>
                        <span id="text-char-count" style="color: #666; font-size: 12px;">0/500</span>
                    </div>
                    
                    <button id="btn-add-text-evidence" class="button button-primary"><?php esc_html_e( 'Add Text Evidence', 'cube-payment-portal' ); ?></button>
                </div>
                
                <!-- Upload File Evidence -->
                <div style="border: 1px solid #ddd; padding: 15px; border-radius: 6px;">
                    <h4 style="margin: 0 0 10px 0;"><?php esc_html_e( 'Upload File Evidence', 'cube-payment-portal' ); ?></h4>
                    
                    <div style="margin-bottom: 10px;">
                        <label for="file-evidence-type" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'Evidence Type:', 'cube-payment-portal' ); ?></label>
                        <select id="file-evidence-type" style="width: 100%;">
                            <?php foreach ( SPP_Disputes::get_evidence_types() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <label for="file-evidence-input" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php esc_html_e( 'File:', 'cube-payment-portal' ); ?> <span style="color: #666; font-weight: normal;">(<?php esc_html_e( 'PDF, JPG, PNG, TIFF, HEIC - max 5MB', 'cube-payment-portal' ); ?>)</span></label>
                        <input type="file" id="file-evidence-input" accept=".pdf,.jpg,.jpeg,.png,.tiff,.heic,.heif">
                    </div>
                    
                    <button id="btn-upload-file-evidence" class="button button-primary"><?php esc_html_e( 'Upload File', 'cube-payment-portal' ); ?></button>
                </div>
            </div>
            
            <!-- Submit/Accept Buttons -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; gap: 15px; justify-content: flex-end;">
                <button id="btn-accept-dispute" class="button" style="color: #d63638; border-color: #d63638;">
                    <span class="dashicons dashicons-no" style="vertical-align: middle; margin-top: -2px;"></span>
                    <?php esc_html_e( 'Accept Dispute (You Lose)', 'cube-payment-portal' ); ?>
                </button>
                <button id="btn-submit-challenge" class="button button-primary button-hero" style="background: #00a32a; border-color: #00a32a;">
                    <span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-top: -2px;"></span>
                    <?php esc_html_e( 'Submit Challenge to Bank', 'cube-payment-portal' ); ?>
                </button>
            </div>
        </div>

        <!-- Read-only notice for processed disputes -->
        <div id="dispute-readonly-notice" style="display: none; background: #f0f6fc; border: 1px solid #72aee6; padding: 15px; border-radius: 6px; text-align: center;">
            <span class="dashicons dashicons-info" style="color: #72aee6;"></span>
            <span id="readonly-message"><?php esc_html_e( 'This dispute has been submitted and is being processed. Evidence can no longer be edited.', 'cube-payment-portal' ); ?></span>
        </div>

    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var disputeId = '<?php echo esc_js( $dispute_id ); ?>';
    var disputeData = null;
    
    function showMessage(message, type) {
        var $msg = $('#spp-message');
        $msg.removeClass('notice-success notice-error notice-warning').addClass('notice-' + type);
        $msg.find('p').text(message);
        $msg.show();
        setTimeout(function() { $msg.fadeOut(); }, 5000);
    }
    
    function formatDate(dateString) {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleDateString();
    }
    
    function loadDisputeDetail() {
        $('#spp-loading').show();
        $('#spp-dispute-content').hide();
        $('#spp-error').hide();
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_dispute_detail',
            nonce: sppAdmin.nonce,
            dispute_id: disputeId
        }, function(response) {
            $('#spp-loading').hide();
            
            if (response.success && response.data.dispute) {
                disputeData = response.data.dispute;
                renderDispute(disputeData);
                $('#spp-dispute-content').show();
            } else {
                $('#spp-error-message').text(response.data?.message || 'Failed to load dispute');
                $('#spp-error').show();
            }
        }).fail(function() {
            $('#spp-loading').hide();
            $('#spp-error-message').text('Network error');
            $('#spp-error').show();
        });
    }
    
    function renderDispute(dispute) {
        // Header
        var amount = dispute.amount_money ? '$' + (dispute.amount_money.amount / 100).toFixed(2) : '$0.00';
        var currency = dispute.amount_money?.currency || 'USD';
        $('#dispute-amount').text(amount + ' ' + currency);
        
        // State badge
        var stateClass = dispute.state === 'WON' ? 'spp-status-paid' : 
                        (dispute.state === 'LOST' || dispute.state === 'ACCEPTED' ? 'spp-status-failed' : 
                        (dispute.state === 'EVIDENCE_REQUIRED' || dispute.state === 'INQUIRY_EVIDENCE_REQUIRED' ? 'spp-status-pending' : 'spp-status-draft'));
        $('#dispute-state-badge').text(dispute.state).attr('class', 'spp-status-badge ' + stateClass);
        
        // ID
        $('#dispute-id').text(dispute.id);
        
        // Deadline
        if (dispute.due_at && (dispute.state === 'EVIDENCE_REQUIRED' || dispute.state === 'INQUIRY_EVIDENCE_REQUIRED')) {
            $('#deadline-date').text(formatDate(dispute.due_at));
            $('#dispute-deadline').show();
        }
        
        // Details
        $('#dispute-reason').text(dispute.reason || '-');
        $('#dispute-card-brand').text(dispute.card_brand || '-');
        $('#dispute-created').text(formatDate(dispute.created_at));
        if (dispute.payment_id) {
            var $payCode = $('<code>').css('font-size', '11px').text(dispute.payment_id);
            $('#dispute-payment-id').empty().append($payCode);
        } else {
            $('#dispute-payment-id').text('-');
        }
        if (dispute.location_id) {
            var $locCode = $('<code>').css('font-size', '11px').text(dispute.location_id);
            $('#dispute-location-id').empty().append($locCode);
        } else {
            $('#dispute-location-id').text('-');
        }
        
        // Suggested Evidence based on reason
        var suggestedTypes = getSuggestedEvidence(dispute.reason);
        var suggestedHtml = '';
        suggestedTypes.forEach(function(type) {
            suggestedHtml += '<li style="margin-bottom: 5px;">' + type + '</li>';
        });
        $('#suggested-evidence-list').html(suggestedHtml);
        
        // Evidence
        renderEvidence(dispute.evidence || []);
        
        // Actions visibility
        var canRespond = ['EVIDENCE_REQUIRED', 'INQUIRY_EVIDENCE_REQUIRED'].includes(dispute.state);
        if (canRespond) {
            $('#dispute-actions').show();
            $('#dispute-readonly-notice').hide();
        } else {
            $('#dispute-actions').hide();
            if (['PROCESSING', 'WON', 'LOST', 'ACCEPTED'].includes(dispute.state)) {
                if (dispute.state === 'WON') {
                    $('#readonly-message').text('<?php echo esc_js( __( 'You won this dispute! The funds have been returned.', 'cube-payment-portal' ) ); ?>');
                } else if (dispute.state === 'LOST' || dispute.state === 'ACCEPTED') {
                    $('#readonly-message').text('<?php echo esc_js( __( 'This dispute was lost. The buyer received a refund.', 'cube-payment-portal' ) ); ?>');
                } else {
                    $('#readonly-message').text('<?php echo esc_js( __( 'This dispute has been submitted and is being processed. Evidence can no longer be edited.', 'cube-payment-portal' ) ); ?>');
                }
                $('#dispute-readonly-notice').show();
            }
        }
    }
    
    function getSuggestedEvidence(reason) {
        var map = {
            'AMOUNT_DIFFERS': ['Authorization Documentation'],
            'CANCELLED': ['Cancellation/Refund Documentation', 'Service Received Documentation', 'Proof of Delivery', 'Tracking Number'],
            'CUSTOMER_REQUESTS_CREDIT': ['Cancellation/Refund Documentation', 'Service Received Documentation', 'Proof of Delivery', 'Tracking Number'],
            'DUPLICATE': ['Duplicate Charge Documentation', 'Related Transaction Documentation'],
            'NO_KNOWLEDGE': ['Authorization Documentation', 'Online/App Access Log', 'Related Transaction Documentation'],
            'NOT_RECEIVED': ['Proof of Delivery', 'Tracking Number', 'Service Received Documentation'],
            'NOT_AS_DESCRIBED': ['Product/Service Description', 'Service Received Documentation', 'Proof of Delivery', 'Cancellation/Refund Documentation']
        };
        return map[reason] || ['Generic Evidence'];
    }
    
    function renderEvidence(evidence) {
        if (!evidence || evidence.length === 0) {
            $('#evidence-list').hide();
            $('#evidence-empty').show();
            return;
        }
        
        $('#evidence-empty').hide();
        var html = '<table class="wp-list-table widefat fixed striped" style="margin: 0;">';
        html += '<thead><tr><th><?php echo esc_js( __( 'Type', 'cube-payment-portal' ) ); ?></th><th><?php echo esc_js( __( 'Content', 'cube-payment-portal' ) ); ?></th><th><?php echo esc_js( __( 'Uploaded', 'cube-payment-portal' ) ); ?></th><th style="width: 100px;"><?php echo esc_js( __( 'Actions', 'cube-payment-portal' ) ); ?></th></tr></thead>';
        html += '<tbody>';
        
        evidence.forEach(function(item) {
            var content = item.evidence_text || (item.evidence_file ? '📎 ' + (item.evidence_file.filename || 'File') : '-');
            var canDelete = ['EVIDENCE_REQUIRED', 'INQUIRY_EVIDENCE_REQUIRED'].includes(disputeData?.state);
            
            html += '<tr data-evidence-id="' + item.id + '">';
            html += '<td>' + (item.evidence_type || '-') + '</td>';
            html += '<td>' + content.substring(0, 100) + (content.length > 100 ? '...' : '') + '</td>';
            html += '<td>' + formatDate(item.uploaded_at) + '</td>';
            html += '<td>';
            if (canDelete) {
                html += '<button class="button button-small btn-remove-evidence" data-id="' + item.id + '" style="color: #d63638;">×</button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#evidence-list').html(html).show();
    }
    
    // Copy ID on click
    $(document).on('click', '#dispute-id', function() {
        navigator.clipboard.writeText($(this).text());
        showMessage('<?php echo esc_js( __( 'Dispute ID copied to clipboard', 'cube-payment-portal' ) ); ?>', 'success');
    });
    
    // Character counter for text evidence
    $('#text-evidence-content').on('input', function() {
        $('#text-char-count').text($(this).val().length + '/500');
    });
    
    // Add text evidence
    $('#btn-add-text-evidence').on('click', function() {
        var $btn = $(this);
        var type = $('#text-evidence-type').val();
        var text = $('#text-evidence-content').val().trim();
        
        if (!text) {
            showMessage('<?php echo esc_js( __( 'Please enter evidence text', 'cube-payment-portal' ) ); ?>', 'error');
            return;
        }
        
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Submitting...', 'cube-payment-portal' ) ); ?>');
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_submit_evidence_text',
            nonce: sppAdmin.nonce,
            dispute_id: disputeId,
            evidence_type: type,
            evidence_text: text
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add Text Evidence', 'cube-payment-portal' ) ); ?>');
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                $('#text-evidence-content').val('');
                $('#text-char-count').text('0/500');
                loadDisputeDetail();
            } else {
                showMessage(response.data?.message || '<?php echo esc_js( __( 'Failed to add evidence', 'cube-payment-portal' ) ); ?>', 'error');
            }
        });
    });
    
    // Upload file evidence
    $('#btn-upload-file-evidence').on('click', function() {
        var $btn = $(this);
        var type = $('#file-evidence-type').val();
        var fileInput = $('#file-evidence-input')[0];
        
        if (!fileInput.files || !fileInput.files[0]) {
            showMessage('<?php echo esc_js( __( 'Please select a file', 'cube-payment-portal' ) ); ?>', 'error');
            return;
        }
        
        var file = fileInput.files[0];
        
        // Check file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showMessage('<?php echo esc_js( __( 'File exceeds 5MB limit', 'cube-payment-portal' ) ); ?>', 'error');
            return;
        }
        
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Uploading...', 'cube-payment-portal' ) ); ?>');
        
        var formData = new FormData();
        formData.append('action', 'spp_upload_evidence_file');
        formData.append('nonce', sppAdmin.nonce);
        formData.append('dispute_id', disputeId);
        formData.append('evidence_type', type);
        formData.append('evidence_file', file);
        
        $.ajax({
            url: sppAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Upload File', 'cube-payment-portal' ) ); ?>');
                
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    $('#file-evidence-input').val('');
                    loadDisputeDetail();
                } else {
                    showMessage(response.data?.message || '<?php echo esc_js( __( 'Failed to upload file', 'cube-payment-portal' ) ); ?>', 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Upload File', 'cube-payment-portal' ) ); ?>');
                showMessage('<?php echo esc_js( __( 'Network error during upload', 'cube-payment-portal' ) ); ?>', 'error');
            }
        });
    });
    
    // Remove evidence
    $(document).on('click', '.btn-remove-evidence', function() {
        var evidenceId = $(this).data('id');
        
        if (!confirm('<?php echo esc_js( __( 'Remove this evidence?', 'cube-payment-portal' ) ); ?>')) {
            return;
        }
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_remove_evidence',
            nonce: sppAdmin.nonce,
            dispute_id: disputeId,
            evidence_id: evidenceId
        }, function(response) {
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadDisputeDetail();
            } else {
                showMessage(response.data?.message || '<?php echo esc_js( __( 'Failed to remove evidence', 'cube-payment-portal' ) ); ?>', 'error');
            }
        });
    });
    
    // Accept dispute
    $('#btn-accept-dispute').on('click', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to ACCEPT this dispute? This means you will LOSE the dispute and the buyer will receive a refund. This action CANNOT be undone.', 'cube-payment-portal' ) ); ?>')) {
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Processing...', 'cube-payment-portal' ) ); ?>');
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_accept_dispute',
            nonce: sppAdmin.nonce,
            dispute_id: disputeId
        }, function(response) {
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadDisputeDetail();
            } else {
                showMessage(response.data?.message || '<?php echo esc_js( __( 'Failed to accept dispute', 'cube-payment-portal' ) ); ?>', 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-no" style="vertical-align: middle; margin-top: -2px;"></span> <?php echo esc_js( __( 'Accept Dispute (You Lose)', 'cube-payment-portal' ) ); ?>');
            }
        });
    });
    
    // Submit challenge
    $('#btn-submit-challenge').on('click', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to submit your challenge to the bank? Once submitted, you CANNOT add or modify evidence. Make sure you have uploaded all supporting documents.', 'cube-payment-portal' ) ); ?>')) {
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Submitting...', 'cube-payment-portal' ) ); ?>');
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_submit_dispute',
            nonce: sppAdmin.nonce,
            dispute_id: disputeId
        }, function(response) {
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadDisputeDetail();
            } else {
                showMessage(response.data?.message || '<?php echo esc_js( __( 'Failed to submit challenge', 'cube-payment-portal' ) ); ?>', 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-top: -2px;"></span> <?php echo esc_js( __( 'Submit Challenge to Bank', 'cube-payment-portal' ) ); ?>');
            }
        });
    });
    
    // Load on page load
    loadDisputeDetail();
});
</script>
