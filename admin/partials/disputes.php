<?php
/**
 * Admin Disputes page.
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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Disputes', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Disputes', 'cube-payment-portal' ); ?></h2>
    </div>


    <!-- Filters -->
    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 20px 0;">
        <form id="spp-disputes-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="spp-filter-group">
                <label for="spp-state-filter" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Status', 'cube-payment-portal' ); ?>
                </label>
                <select id="spp-state-filter" style="min-width: 180px;">
                    <option value=""><?php esc_html_e( 'All Disputes', 'cube-payment-portal' ); ?></option>
                    <option value="EVIDENCE_REQUIRED"><?php esc_html_e( 'Evidence Required', 'cube-payment-portal' ); ?></option>
                    <option value="PROCESSING"><?php esc_html_e( 'Processing', 'cube-payment-portal' ); ?></option>
                    <option value="WON"><?php esc_html_e( 'Won', 'cube-payment-portal' ); ?></option>
                    <option value="LOST"><?php esc_html_e( 'Lost', 'cube-payment-portal' ); ?></option>
                </select>
            </div>
            
            <div class="spp-filter-group" style="display: flex; gap: 8px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
                <button type="button" id="spp-refresh-btn" class="button">
                    <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                    <?php esc_html_e( 'Refresh', 'cube-payment-portal' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Message -->
    <div id="spp-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Loading -->
    <div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading disputes...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-empty-state" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <span class="dashicons dashicons-shield-alt" style="font-size: 64px; color: #4caf50; width: 64px; height: 64px;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Disputes', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Great news! You have no payment disputes.', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Disputes Table -->
    <div id="spp-disputes-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 12%;"><?php esc_html_e( 'Dispute ID', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%;"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                    <th style="width: 13%;"><?php esc_html_e( 'Reason', 'cube-payment-portal' ); ?></th>
                    <th style="width: 13%;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%;"><?php esc_html_e( 'Due Date', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Created', 'cube-payment-portal' ); ?></th>
                    <th style="width: 8%;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody id="spp-disputes-tbody"></tbody>
        </table>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    function loadDisputes() {
        $('#spp-loading').show();
        $('#spp-disputes-table-wrap').hide();
        $('#spp-empty-state').hide();

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_disputes',
            nonce: sppAdmin.nonce,
            status: $('#spp-state-filter').val()
        }, function(response) {
            $('#spp-loading').hide();

            if (response.success && response.data.disputes && response.data.disputes.length > 0) {
                var html = '';
                $.each(response.data.disputes, function(i, dispute) {
                    var stateClass = dispute.state === 'WON' ? 'spp-status-paid' : 
                                    (dispute.state === 'LOST' || dispute.state === 'ACCEPTED' ? 'spp-status-failed' : 
                                    (dispute.state === 'EVIDENCE_REQUIRED' || dispute.state === 'INQUIRY_EVIDENCE_REQUIRED' ? 'spp-status-pending' : 'spp-status-draft'));
                    
                    // Add urgent indicator if due date is approaching (within 3 days)
                    var dueDateHtml = dispute.due_at || '-';
                    if (dispute.due_at && (dispute.state === 'EVIDENCE_REQUIRED' || dispute.state === 'INQUIRY_EVIDENCE_REQUIRED')) {
                        dueDateHtml = '<strong style="color: #d63638;">' + dispute.due_at + ' ⚠️</strong>';
                    }
                    
                    var viewUrl = '<?php echo esc_url( admin_url( 'admin.php?page=spp-disputes&view=detail&id=' ) ); ?>' + dispute.id;
                    var customerUrl = '<?php echo esc_url( admin_url( 'admin.php?page=spp-customers&view=detail&customer_id=' ) ); ?>' + dispute.customer_id;
                    
                    // Build customer cell HTML with tooltip
                    var customerHtml = '-';
                    if (dispute.customer_name && dispute.customer_id) {
                        var tooltipHtml = '<div class="spp-customer-tooltip spp-tooltip-left">' +
                            (dispute.customer_email ? '<div class="tooltip-row"><span class="dashicons dashicons-email"></span> ' + dispute.customer_email + '</div>' : '') +
                            (dispute.customer_phone ? '<div class="tooltip-row"><span class="dashicons dashicons-phone"></span> ' + dispute.customer_phone + '</div>' : '') +
                        '</div>';

                        customerHtml = '<a href="' + customerUrl + '" class="spp-customer-link" style="text-decoration: none; font-weight: 500;">' + 
                            dispute.customer_name + 
                            tooltipHtml + 
                        '</a>';
                    } else if (dispute.customer_name) {
                        customerHtml = dispute.customer_name;
                    }
                    
                    html += '<tr>' +
                        '<td><code style="font-size: 11px;">' + dispute.id.substring(0, 12) + '...</code></td>' +
                        '<td>' + customerHtml + '</td>' +
                        '<td style="font-weight: 600;">$' + dispute.amount + '</td>' +
                        '<td>' + (dispute.reason || '-') + '</td>' +
                        '<td><span class="spp-status-badge ' + stateClass + '">' + dispute.state_label + '</span></td>' +
                        '<td>' + dueDateHtml + '</td>' +
                        '<td>' + (dispute.created_at || '-') + '</td>' +
                        '<td><a href="' + viewUrl + '" class="button button-small"><?php echo esc_js( __( 'View', 'cube-payment-portal' ) ); ?></a></td>' +
                    '</tr>';
                });
                $('#spp-disputes-tbody').html(html);
                $('#spp-disputes-table-wrap').show();
            } else {
                $('#spp-empty-state').show();
            }
        });
    }

    loadDisputes();

    $('#spp-disputes-filter-form').on('submit', function(e) {
        e.preventDefault();
        loadDisputes();
    });

    $('#spp-refresh-btn').on('click', loadDisputes);
});
</script>

