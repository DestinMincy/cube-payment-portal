<?php
/**
 * Admin Gift Cards page.
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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?></h2>
        <div style="display: flex; gap: 8px;">
            <button type="button" id="spp-create-gift-card-btn" class="spp-button">
                <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Create Gift Card', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <!-- Sync Message -->
    <div id="spp-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Metrics Cards -->
    <div class="spp-metrics-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Total Balance', 'cube-payment-portal' ); ?></div>
                    <div id="spp-metric-balance" style="font-size: 28px; font-weight: 600; color: #1e3a5f;">$0.00</div>
                </div>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-money-alt" style="color: #2196f3; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Active Cards', 'cube-payment-portal' ); ?></div>
                    <div id="spp-metric-active" style="font-size: 28px; font-weight: 600; color: #4caf50;">0</div>
                </div>
                <div style="background: #e8f5e9; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-yes-alt" style="color: #4caf50; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Pending Activation', 'cube-payment-portal' ); ?></div>
                    <div id="spp-metric-pending" style="font-size: 28px; font-weight: 600; color: #ff9800;">0</div>
                </div>
                <div style="background: #fff3e0; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-clock" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>

        <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Linked Customers', 'cube-payment-portal' ); ?></div>
                    <div id="spp-metric-linked" style="font-size: 28px; font-weight: 600; color: #9c27b0;">0</div>
                </div>
                <div style="background: #f3e5f5; padding: 10px; border-radius: 50%;">
                    <span class="dashicons dashicons-admin-users" style="color: #9c27b0; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form id="spp-gift-cards-filter-form">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <select id="spp-state-filter" style="min-width: 150px;">
                        <option value=""><?php esc_html_e( 'All Cards', 'cube-payment-portal' ); ?></option>
                        <option value="ACTIVE"><?php esc_html_e( 'Active', 'cube-payment-portal' ); ?></option>
                        <option value="PENDING"><?php esc_html_e( 'Pending', 'cube-payment-portal' ); ?></option>
                        <option value="DEACTIVATED"><?php esc_html_e( 'Deactivated', 'cube-payment-portal' ); ?></option>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="search" id="spp-search-filter" placeholder="<?php esc_attr_e( 'Search by GAN...', 'cube-payment-portal' ); ?>" style="min-width: 200px;">
                    <button type="button" id="spp-refresh-btn" class="button button-primary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                        <?php esc_html_e( 'Refresh', 'cube-payment-portal' ); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Loading -->
    <div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading gift cards...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-empty-state" style="display: none; background: #fff; padding: 60px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
        <span class="dashicons dashicons-tickets-alt" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Gift Cards Found', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Create your first gift card to get started.', 'cube-payment-portal' ); ?></p>
        <button type="button" id="spp-empty-create-btn" class="button button-primary button-large">
            <?php esc_html_e( 'Create Gift Card', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <!-- Gift Cards Table -->
    <div id="spp-gift-cards-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Gift Cards', 'cube-payment-portal' ); ?></h3>
            <span style="color: #666; font-size: 13px;"><span id="spp-cards-count">0</span> <?php esc_html_e( 'cards', 'cube-payment-portal' ); ?></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 20%;"><?php esc_html_e( 'GAN', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%; text-align: right;"><?php esc_html_e( 'Balance', 'cube-payment-portal' ); ?></th>
                    <th style="width: 10%; text-align: center;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                    <th style="width: 10%; text-align: center;"><?php esc_html_e( 'Type', 'cube-payment-portal' ); ?></th>
                    <th style="width: 18%;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                    <th style="width: 30%;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody id="spp-gift-cards-tbody"></tbody>
        </table>

        <div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
            <div id="spp-load-more-wrap" style="display: none; text-align: center;">
                <button type="button" id="spp-load-more-btn" class="button">
                    <?php esc_html_e( 'Load More', 'cube-payment-portal' ); ?>
                </button>
            </div>
        </div>
    </div>

</div>

<!-- Create Gift Card Modal -->
<div id="spp-create-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 500px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Create Gift Card', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <form id="spp-create-gc-form">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e( 'Card Type', 'cube-payment-portal' ); ?>
                    </label>
                    <select name="type" style="width: 100%;">
                        <option value="DIGITAL"><?php esc_html_e( 'Digital', 'cube-payment-portal' ); ?></option>
                        <option value="PHYSICAL"><?php esc_html_e( 'Physical', 'cube-payment-portal' ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Digital cards are virtual, physical cards require a printed card.', 'cube-payment-portal' ); ?></p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Create Card', 'cube-payment-portal' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Activate Gift Card Modal -->
<div id="spp-activate-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 500px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Activate Gift Card', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <form id="spp-activate-gc-form">
                <input type="hidden" name="gift_card_id" id="activate-gc-id">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e( 'Initial Balance ($)', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="number" name="amount" id="activate-gc-amount" min="1" step="0.01" style="width: 100%;" required>
                    <p class="description"><?php esc_html_e( 'Enter the initial balance to activate the card with.', 'cube-payment-portal' ); ?></p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'cube-payment-portal' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Load Gift Card Modal -->
<div id="spp-load-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 500px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Add Balance', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <form id="spp-load-gc-form">
                <input type="hidden" name="gift_card_id" id="load-gc-id">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e( 'Amount to Add ($)', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="number" name="amount" id="load-gc-amount" min="1" step="0.01" style="width: 100%;" required>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Balance', 'cube-payment-portal' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Redeem Gift Card Modal -->
<div id="spp-redeem-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 500px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Redeem (Charge) Gift Card', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <form id="spp-redeem-gc-form">
                <input type="hidden" name="gift_card_id" id="redeem-gc-id">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e( 'Amount to Redeem ($)', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="number" name="amount" id="redeem-gc-amount" min="0.01" step="0.01" style="width: 100%;" required>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Redeem', 'cube-payment-portal' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link Customer Modal -->
<div id="spp-link-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 500px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Link Customer', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <form id="spp-link-gc-form">
                <input type="hidden" name="gift_card_id" id="link-gc-id">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?>
                    </label>
                    <select name="customer_id" id="link-gc-customer-id" style="width: 100%;" required>
                        <option value=""><?php esc_html_e( 'Select a customer...', 'cube-payment-portal' ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Select the customer to link to this gift card.', 'cube-payment-portal' ); ?></p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Link', 'cube-payment-portal' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- History Modal -->
<div id="spp-history-gc-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 600px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Gift Card History', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <div id="spp-history-content">
                <p><?php esc_html_e( 'Loading history...', 'cube-payment-portal' ); ?></p>
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="button spp-modal-close"><?php esc_html_e( 'Close', 'cube-payment-portal' ); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentCursor = '';
    var allCards = [];
    
    function showMessage(message, type) {
        $('#spp-message').removeClass('notice-success notice-error').addClass('notice-' + type).find('p').text(message);
        $('#spp-message').show().delay(5000).fadeOut();
    }

    function updateMetrics(cards) {
        var totalBalance = 0;
        var activeCount = 0;
        var pendingCount = 0;
        var linkedCount = 0;
        
        cards.forEach(function(card) {
            if (card.balance_money && card.state === 'ACTIVE') {
                totalBalance += (card.balance_money.amount || 0) / 100;
            }
            if (card.state === 'ACTIVE') activeCount++;
            if (card.state === 'PENDING') pendingCount++;
            if (card.customer_ids && card.customer_ids.length > 0) linkedCount++;
        });
        
        $('#spp-metric-balance').text('$' + totalBalance.toFixed(2));
        $('#spp-metric-active').text(activeCount);
        $('#spp-metric-pending').text(pendingCount);
        $('#spp-metric-linked').text(linkedCount);
    }

    function loadGiftCards(append) {
        if (!append) {
            $('#spp-loading').show();
            $('#spp-gift-cards-table-wrap').hide();
            $('#spp-empty-state').hide();
            $('#spp-load-more-wrap').hide();
            currentCursor = '';
            allCards = [];
        }

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_gift_cards',
            nonce: sppAdmin.nonce,
            status: $('#spp-state-filter').val(),
            cursor: currentCursor
        }, function(response) {
            $('#spp-loading').hide();

            if (response.success && response.data.gift_cards && response.data.gift_cards.length > 0) {
                allCards = allCards.concat(response.data.gift_cards);
                updateMetrics(allCards);
                
                var $tbody = $('#spp-gift-cards-tbody');
                if (!append) {
                    $tbody.empty();
                }
                
                var searchTerm = $('#spp-search-filter').val().toLowerCase();
                var displayCount = 0;
                
                $.each(response.data.gift_cards, function(i, card) {
                    if (searchTerm) {
                        var gan = (card.gan || '').toLowerCase();
                        if (gan.indexOf(searchTerm) === -1) {
                            return;
                        }
                    }
                    
                    displayCount++;
                    var balance = card.balance_money ? (card.balance_money.amount / 100).toFixed(2) : '0.00';
                    var currency = card.balance_money ? card.balance_money.currency : 'USD';
                    
                    // Status badge
                    var statusBadge = '';
                    if (card.state === 'ACTIVE') {
                        statusBadge = '<span class="spp-status-badge spp-status-paid"><?php echo esc_js( __( 'Active', 'cube-payment-portal' ) ); ?></span>';
                    } else if (card.state === 'PENDING') {
                        statusBadge = '<span class="spp-status-badge spp-status-pending"><?php echo esc_js( __( 'Pending', 'cube-payment-portal' ) ); ?></span>';
                    } else {
                        statusBadge = '<span class="spp-status-badge spp-status-canceled"><?php echo esc_js( __( 'Deactivated', 'cube-payment-portal' ) ); ?></span>';
                    }
                    
                    // Customer
                    var customerHtml = '';
                    if (card.customer_details && card.customer_details.length > 0) {
                        var cust = card.customer_details[0];
                        var customerUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-customers&customer_id=' ) ); ?>' + cust.id;
                        
                        // Build tooltip content (email and phone only - name is in link text)
                        var tooltipHtml = '<div class="spp-customer-tooltip">';
                        if (cust.email) {
                            tooltipHtml += '<div class="tooltip-row"><span class="dashicons dashicons-email"></span> ' + cust.email + '</div>';
                        }
                        if (cust.phone) {
                            tooltipHtml += '<div class="tooltip-row"><span class="dashicons dashicons-phone"></span> ' + cust.phone + '</div>';
                        }
                        tooltipHtml += '</div>';
                        
                        
                        customerHtml = '<a href="' + customerUrl + '" class="spp-customer-link">' + cust.name + tooltipHtml + '</a>';
                    } else if (card.customer_names && card.customer_names.length > 0) {
                        // Fallback if details not available
                        customerHtml = '<span style="color: #2271b1;">' + card.customer_names[0] + '</span>';
                    } else {
                        customerHtml = '<span style="color: #999;"><?php echo esc_js( __( 'Not linked', 'cube-payment-portal' ) ); ?></span>';
                    }
                    
                    // Type
                    var typeHtml = card.type === 'DIGITAL' 
                        ? '<span class="dashicons dashicons-smartphone" style="color: #666; font-size: 14px; width: 14px; height: 14px;"></span> <?php echo esc_js( __( 'Digital', 'cube-payment-portal' ) ); ?>'
                        : '<span class="dashicons dashicons-id" style="color: #666; font-size: 14px; width: 14px; height: 14px;"></span> <?php echo esc_js( __( 'Physical', 'cube-payment-portal' ) ); ?>';

                    // Actions
                    var actionsHtml = '';
                    
                    if (card.state === 'PENDING') {
                        actionsHtml += '<button type="button" class="button button-primary button-small spp-activate-gc" data-id="' + card.id + '"><?php echo esc_js( __( 'Activate', 'cube-payment-portal' ) ); ?></button> ';
                    } else if (card.state === 'ACTIVE') {
                        actionsHtml += '<button type="button" class="button button-primary button-small spp-load-gc" data-id="' + card.id + '"><?php echo esc_js( __( 'Add Funds', 'cube-payment-portal' ) ); ?></button> ';
                        actionsHtml += '<button type="button" class="button button-small spp-redeem-gc" data-id="' + card.id + '"><?php echo esc_js( __( 'Redeem', 'cube-payment-portal' ) ); ?></button> ';
                    }
                    
                    if (card.customer_ids && card.customer_ids.length > 0) {
                        actionsHtml += '<button type="button" class="button button-small spp-unlink-gc" data-id="' + card.id + '" data-customer="' + card.customer_ids[0] + '"><?php echo esc_js( __( 'Unlink', 'cube-payment-portal' ) ); ?></button> ';
                    } else {
                        actionsHtml += '<button type="button" class="button button-small spp-link-gc" data-id="' + card.id + '"><?php echo esc_js( __( 'Link', 'cube-payment-portal' ) ); ?></button> ';
                    }
                    
                    actionsHtml += '<button type="button" class="button button-small spp-history-gc" data-id="' + card.id + '"><?php echo esc_js( __( 'History', 'cube-payment-portal' ) ); ?></button> ';

                    if (card.state === 'ACTIVE') {
                        actionsHtml += '<button type="button" class="button button-small spp-deactivate-gc" data-id="' + card.id + '" style="color: #dc3232;"><?php echo esc_js( __( 'Deactivate', 'cube-payment-portal' ) ); ?></button>';
                    }

                    var row = '<tr>' +
                        '<td><code style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 13px;">' + (card.gan || '****') + '</code></td>' +
                        '<td style="text-align: right;"><strong style="color: #1e3a5f;">$' + balance + '</strong></td>' +
                        '<td style="text-align: center;">' + statusBadge + '</td>' +
                        '<td style="text-align: center; font-size: 12px; color: #666;">' + typeHtml + '</td>' +
                        '<td>' + customerHtml + '</td>' +
                        '<td>' + actionsHtml + '</td>' +
                    '</tr>';
                    
                    $tbody.append(row);
                });
                
                $('#spp-cards-count').text(allCards.length);
                $('#spp-gift-cards-table-wrap').show();
                
                currentCursor = response.data.cursor || '';
                if (currentCursor) {
                    $('#spp-load-more-wrap').show();
                } else {
                    $('#spp-load-more-wrap').hide();
                }
            } else if (!append) {
                $('#spp-empty-state').show();
                updateMetrics([]);
            }
        });
    }


    loadGiftCards();

    $('#spp-gift-cards-filter-form').on('submit', function(e) {
        e.preventDefault();
        loadGiftCards();
    });

    $('#spp-refresh-btn').on('click', function() {
        var $btn = $(this);
        $btn.find('.dashicons').addClass('spin');
        loadGiftCards(false);
        setTimeout(function() { $btn.find('.dashicons').removeClass('spin'); }, 1000);
    });

    $('#spp-load-more-btn').on('click', function() {
        loadGiftCards(true);
    });

    $('#spp-create-gift-card-btn, #spp-empty-create-btn').on('click', function() {
        $('#spp-create-gc-modal').show();
    });

    $('.spp-modal-close, .spp-modal-overlay').on('click', function() {
        $(this).closest('.spp-modal').hide();
    });

    $('#spp-create-gc-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Creating...', 'cube-payment-portal' ) ); ?>');

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_create_gift_card',
            nonce: sppAdmin.nonce,
            type: $(this).find('[name="type"]').val()
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Create Card', 'cube-payment-portal' ) ); ?>');
            $('#spp-create-gc-modal').hide();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-activate-gc', function() {
        $('#activate-gc-id').val($(this).data('id'));
        $('#activate-gc-amount').val('');
        $('#spp-activate-gc-modal').show();
    });

    $('#spp-activate-gc-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Activating...', 'cube-payment-portal' ) ); ?>');

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_activate_gift_card',
            nonce: sppAdmin.nonce,
            gift_card_id: $('#activate-gc-id').val(),
            amount: $('#activate-gc-amount').val()
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate', 'cube-payment-portal' ) ); ?>');
            $('#spp-activate-gc-modal').hide();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-load-gc', function() {
        $('#load-gc-id').val($(this).data('id'));
        $('#load-gc-amount').val('');
        $('#spp-load-gc-modal').show();
    });

    $('#spp-load-gc-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Loading...', 'cube-payment-portal' ) ); ?>');

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_load_gift_card',
            nonce: sppAdmin.nonce,
            gift_card_id: $('#load-gc-id').val(),
            amount: $('#load-gc-amount').val()
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add Balance', 'cube-payment-portal' ) ); ?>');
            $('#spp-load-gc-modal').hide();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-redeem-gc', function() {
        $('#redeem-gc-id').val($(this).data('id'));
        $('#redeem-gc-amount').val('');
        $('#spp-redeem-gc-modal').show();
    });

    $('#spp-redeem-gc-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Redeeming...', 'cube-payment-portal' ) ); ?>');

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_redeem_gift_card',
            nonce: sppAdmin.nonce,
            gift_card_id: $('#redeem-gc-id').val(),
            amount: $('#redeem-gc-amount').val()
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Redeem', 'cube-payment-portal' ) ); ?>');
            $('#spp-redeem-gc-modal').hide();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-link-gc', function() {
        var cardId = $(this).data('id');
        $('#link-gc-id').val(cardId);
        
        var $select = $('#link-gc-customer-id');
        $select.html('<option value=""><?php echo esc_js( __( 'Loading customers...', 'cube-payment-portal' ) ); ?></option>');
        $select.prop('disabled', true);
        
        $('#spp-link-gc-modal').show();

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_customers',
            nonce: sppAdmin.nonce,
            per_page: 100
        }, function(response) {
            $select.prop('disabled', false);
            
            if (response.success && response.data.customers) {
                var options = '<option value=""><?php echo esc_js( __( 'Select a customer...', 'cube-payment-portal' ) ); ?></option>';
                $.each(response.data.customers, function(i, customer) {
                    var name = customer.given_name ? (customer.given_name + ' ' + (customer.family_name || '')) : customer.email;
                    if (!name) name = customer.id;
                    options += '<option value="' + customer.square_id + '">' + name + ' (' + customer.email + ')</option>';
                });
                $select.html(options);
            } else {
                $select.html('<option value=""><?php echo esc_js( __( 'No customers found', 'cube-payment-portal' ) ); ?></option>');
            }
        });
    });

    $('#spp-link-gc-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Linking...', 'cube-payment-portal' ) ); ?>');

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_link_gift_card_customer',
            nonce: sppAdmin.nonce,
            gift_card_id: $('#link-gc-id').val(),
            customer_id: $('#link-gc-customer-id').val()
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Link', 'cube-payment-portal' ) ); ?>');
            $('#spp-link-gc-modal').hide();
            
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-unlink-gc', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to unlink the customer from this gift card?', 'cube-payment-portal' ) ); ?>')) {
            return;
        }

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_unlink_gift_card_customer',
            nonce: sppAdmin.nonce,
            gift_card_id: $(this).data('id'),
            customer_id: $(this).data('customer')
        }, function(response) {
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });

    $(document).on('click', '.spp-history-gc', function() {
        var giftCardId = $(this).data('id');
        $('#spp-history-content').html('<p><?php echo esc_js( __( 'Loading history...', 'cube-payment-portal' ) ); ?></p>');
        $('#spp-history-gc-modal').show();

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_gift_card_activities',
            nonce: sppAdmin.nonce,
            gift_card_id: giftCardId
        }, function(response) {
            if (response.success) {
                var html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                html += '<thead><tr><th><?php echo esc_js( __( 'Date', 'cube-payment-portal' ) ); ?></th><th><?php echo esc_js( __( 'Type', 'cube-payment-portal' ) ); ?></th><th style="text-align: right;"><?php echo esc_js( __( 'Amount', 'cube-payment-portal' ) ); ?></th></tr></thead><tbody>';
                
                if (response.data.activities && response.data.activities.length > 0) {
                    $.each(response.data.activities, function(i, activity) {
                        var color = activity.amount > 0 ? '#2e7d32' : (activity.amount < 0 ? '#c62828' : '#666');
                        html += '<tr>';
                        html += '<td>' + activity.created_at + '</td>';
                        html += '<td>' + activity.type + '</td>';
                        html += '<td style="text-align: right; color: ' + color + '; font-weight: 500;">$' + Math.abs(activity.amount).toFixed(2) + '</td>';
                        html += '</tr>';
                    });
                } else {
                    html += '<tr><td colspan="3" style="text-align: center;"><?php echo esc_js( __( 'No activity found.', 'cube-payment-portal' ) ); ?></td></tr>';
                }
                
                html += '</tbody></table>';
                $('#spp-history-content').html(html);
            } else {
                var $errP = $('<p>').css('color', '#c62828').text(response.data.message || '<?php echo esc_js( __( 'Error loading history.', 'cube-payment-portal' ) ); ?>');
                $('#spp-history-content').empty().append($errP);
            }
        });
    });

    $(document).on('click', '.spp-deactivate-gc', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate this gift card? This action cannot be undone.', 'cube-payment-portal' ) ); ?>')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_deactivate_gift_card',
            nonce: sppAdmin.nonce,
            gift_card_id: $btn.data('id')
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                showMessage(response.data.message, 'success');
                loadGiftCards();
            } else {
                showMessage(response.data.message, 'error');
            }
        });
    });
});
</script>
