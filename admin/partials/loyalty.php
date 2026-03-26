<?php
/**
 * Admin Loyalty page.
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

// Load Loyalty API.
if ( ! class_exists( 'SPP_Loyalty' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-loyalty.php';
}

$loyalty_api = new SPP_Loyalty();
$program = $loyalty_api->get_program();
$program_active = ! is_wp_error( $program ) && ! empty( $program['id'] );
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Loyalty Program', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Loyalty Program', 'cube-payment-portal' ); ?></h2>

    <!-- Sync Message -->
    <div id="spp-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <?php if ( ! $program_active ) : ?>
        <div style="background: #fff; padding: 60px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; margin-top: 20px;">
            <span class="dashicons dashicons-star-filled" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
            <h2 style="color: #666;"><?php esc_html_e( 'No Loyalty Program', 'cube-payment-portal' ); ?></h2>
            <p style="color: #999; max-width: 400px; margin: 0 auto 20px;">
                <?php esc_html_e( 'No loyalty program is configured in your Square account. Set up a loyalty program in Square Dashboard to use this feature.', 'cube-payment-portal' ); ?>
            </p>
            <a href="https://squareup.com/dashboard/loyalty" target="_blank" class="button button-primary button-large">
                <?php esc_html_e( 'Configure in Square Dashboard', 'cube-payment-portal' ); ?>
            </a>
        </div>
    <?php else : ?>
        <?php
        // Get program details
        $accrual_rules = $program['accrual_rules'] ?? array();
        $reward_tiers = $program['reward_tiers'] ?? array();
        $terminology = $program['terminology']['one'] ?? __( 'Point', 'cube-payment-portal' );
        $points_per_purchase = ! empty( $accrual_rules[0]['points'] ) ? $accrual_rules[0]['points'] : 1;
        ?>

        <!-- Metrics Cards -->
        <div class="spp-metrics-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0;">
            <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Total Accounts', 'cube-payment-portal' ); ?></div>
                        <div id="spp-metric-accounts" style="font-size: 28px; font-weight: 600; color: #1e3a5f;">0</div>
                    </div>
                    <div style="background: #e3f2fd; padding: 10px; border-radius: 50%;">
                        <span class="dashicons dashicons-groups" style="color: #2196f3; font-size: 20px; width: 20px; height: 20px;"></span>
                    </div>
                </div>
            </div>

            <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Total Points', 'cube-payment-portal' ); ?></div>
                        <div id="spp-metric-points" style="font-size: 28px; font-weight: 600; color: #4caf50;">0</div>
                    </div>
                    <div style="background: #e8f5e9; padding: 10px; border-radius: 50%;">
                        <span class="dashicons dashicons-star-filled" style="color: #4caf50; font-size: 20px; width: 20px; height: 20px;"></span>
                    </div>
                </div>
            </div>

            <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Points Per Purchase', 'cube-payment-portal' ); ?></div>
                        <div style="font-size: 28px; font-weight: 600; color: #9c27b0;"><?php echo esc_html( (string) $points_per_purchase ); ?></div>
                    </div>
                    <div style="background: #f3e5f5; padding: 10px; border-radius: 50%;">
                        <span class="dashicons dashicons-cart" style="color: #9c27b0; font-size: 20px; width: 20px; height: 20px;"></span>
                    </div>
                </div>
            </div>

            <div class="spp-metric-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #666; font-size: 12px; text-transform: uppercase;"><?php esc_html_e( 'Reward Tiers', 'cube-payment-portal' ); ?></div>
                        <div style="font-size: 28px; font-weight: 600; color: #ff9800;"><?php echo esc_html( (string) count( $reward_tiers ) ); ?></div>
                    </div>
                    <div style="background: #fff3e0; padding: 10px; border-radius: 50%;">
                        <span class="dashicons dashicons-awards" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Program Info Bar -->
        <div style="padding: 12px 15px; background: #fff; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center;">
                <span><strong><?php esc_html_e( 'Program:', 'cube-payment-portal' ); ?></strong> <span style="color: #667eea;"><?php echo esc_html( $terminology ); ?>s <?php esc_html_e( 'Loyalty Program', 'cube-payment-portal' ); ?></span></span>
                <span><strong><?php esc_html_e( 'Status:', 'cube-payment-portal' ); ?></strong> <span style="color: #4caf50;"><?php esc_html_e( 'Active', 'cube-payment-portal' ); ?></span></span>
            </div>
            <a href="https://squareup.com/dashboard/loyalty" target="_blank" class="button">
                <?php esc_html_e( 'Manage in Square', 'cube-payment-portal' ); ?>
            </a>
        </div>

        <!-- Reward Tiers -->
        <?php if ( ! empty( $reward_tiers ) ) : ?>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Reward Tiers', 'cube-payment-portal' ); ?></h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ( $reward_tiers as $tier ) : ?>
                    <div style="border: 1px solid #e0e0e0; padding: 15px; border-radius: 6px; background: #fafafa;">
                        <div style="font-weight: 600; margin-bottom: 5px; color: #1d2327;"><?php echo esc_html( $tier['name'] ?? __( 'Reward', 'cube-payment-portal' ) ); ?></div>
                        <div style="color: #667eea; font-weight: 500; font-size: 14px;">
                            <span class="dashicons dashicons-star-filled" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                            <?php printf( esc_html__( '%d points', 'cube-payment-portal' ), $tier['points'] ?? 0 ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form id="spp-loyalty-filter-form">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="search" id="spp-search-filter" placeholder="<?php esc_attr_e( 'Search by customer name...', 'cube-payment-portal' ); ?>" style="min-width: 200px;">
                        <button type="submit" class="button"><?php esc_html_e( 'Search', 'cube-payment-portal' ); ?></button>
                    </div>
                    <button type="button" id="spp-refresh-accounts" class="button button-primary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                        <?php esc_html_e( 'Refresh', 'cube-payment-portal' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Loading -->
        <div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
            <span class="spinner is-active" style="float: none;"></span>
            <p style="color: #666;"><?php esc_html_e( 'Loading loyalty accounts...', 'cube-payment-portal' ); ?></p>
        </div>

        <!-- Empty State -->
        <div id="spp-empty-state" style="display: none; background: #fff; padding: 60px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
            <span class="dashicons dashicons-star-filled" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
            <h2 style="color: #666;"><?php esc_html_e( 'No Loyalty Accounts', 'cube-payment-portal' ); ?></h2>
            <p style="color: #999;"><?php esc_html_e( 'Loyalty accounts will appear here when customers enroll in your program.', 'cube-payment-portal' ); ?></p>
        </div>

        <!-- Loyalty Accounts Table -->
        <div id="spp-accounts-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Loyalty Accounts', 'cube-payment-portal' ); ?></h3>
                <span style="color: #666; font-size: 13px;"><span id="spp-accounts-count">0</span> <?php esc_html_e( 'accounts', 'cube-payment-portal' ); ?></span>
            </div>
            
            <table class="wp-list-table widefat fixed striped" id="spp-loyalty-accounts-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Email', 'cube-payment-portal' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Phone', 'cube-payment-portal' ); ?></th>
                        <th style="width: 10%; text-align: center;"><?php esc_html_e( 'Balance', 'cube-payment-portal' ); ?></th>
                        <th style="width: 10%; text-align: center;"><?php esc_html_e( 'Lifetime', 'cube-payment-portal' ); ?></th>
                        <th style="width: 20%; text-align: right;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Manage Account Modal -->
<div id="spp-manage-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-overlay"></div>
    <div class="spp-modal-content" style="max-width: 700px;">
        <div class="spp-modal-header">
            <h2><?php esc_html_e( 'Manage Loyalty Account', 'cube-payment-portal' ); ?></h2>
            <button type="button" class="spp-modal-close">&times;</button>
        </div>
        <div class="spp-modal-body">
            <!-- Account Summary -->
            <div class="spp-modal-account-header">
                <div class="account-info">
                    <div class="account-label"><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></div>
                    <div class="account-name" id="spp-modal-customer"></div>
                    <div class="account-detail" id="spp-modal-phone"></div>
                </div>
                <div class="account-balance">
                    <div class="account-label"><?php esc_html_e( 'Current Balance', 'cube-payment-portal' ); ?></div>
                    <div class="balance-value" id="spp-modal-balance">0</div>
                    <div class="balance-label"><?php esc_html_e( 'points', 'cube-payment-portal' ); ?></div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="spp-tabs">
                <button type="button" class="spp-tab active" data-tab="adjust">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e( 'Adjust Points', 'cube-payment-portal' ); ?>
                </button>
                <button type="button" class="spp-tab" data-tab="rewards">
                    <span class="dashicons dashicons-awards" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e( 'Rewards', 'cube-payment-portal' ); ?>
                </button>
                <button type="button" class="spp-tab" data-tab="history">
                    <span class="dashicons dashicons-backup" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e( 'History', 'cube-payment-portal' ); ?>
                </button>
            </div>

            <!-- Adjust Points Tab -->
            <div id="tab-adjust" class="spp-tab-content">
                <div class="spp-form-section">
                    <div class="spp-form-row">
                        <div class="spp-form-group">
                            <label for="spp-adjust-points"><?php esc_html_e( 'Points', 'cube-payment-portal' ); ?></label>
                            <input type="number" id="spp-adjust-points" min="1" step="1" value="10">
                        </div>
                        <div class="spp-form-group">
                            <label for="spp-adjust-reason"><?php esc_html_e( 'Reason (optional)', 'cube-payment-portal' ); ?></label>
                            <input type="text" id="spp-adjust-reason" placeholder="<?php esc_attr_e( 'e.g. Customer service adjustment', 'cube-payment-portal' ); ?>">
                        </div>
                    </div>
                    <div class="spp-form-actions">
                        <button type="button" class="button button-primary" id="spp-btn-add-points">
                            <span class="dashicons dashicons-plus" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php esc_html_e( 'Add Points', 'cube-payment-portal' ); ?>
                        </button>
                        <button type="button" class="button" id="spp-btn-subtract-points">
                            <span class="dashicons dashicons-minus" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php esc_html_e( 'Subtract Points', 'cube-payment-portal' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Rewards Tab -->
            <div id="tab-rewards" class="spp-tab-content" style="display: none;">
                <?php if ( ! empty( $reward_tiers ) ) : ?>
                <div class="spp-form-section" style="margin-bottom: 15px;">
                    <h4><?php esc_html_e( 'Issue New Reward', 'cube-payment-portal' ); ?></h4>
                    <div style="display: flex; gap: 10px;">
                        <select id="spp-reward-tier-select" style="flex: 1;">
                            <option value=""><?php esc_html_e( 'Select a reward tier...', 'cube-payment-portal' ); ?></option>
                            <?php foreach ( $reward_tiers as $tier ) : ?>
                                <option value="<?php echo esc_attr( $tier['id'] ); ?>" data-points="<?php echo esc_attr( $tier['points'] ); ?>">
                                    <?php echo esc_html( $tier['name'] ?? __( 'Reward', 'cube-payment-portal' ) ); ?> (<?php echo esc_html( $tier['points'] ); ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-primary" id="spp-btn-create-reward"><?php esc_html_e( 'Create', 'cube-payment-portal' ); ?></button>
                    </div>
                </div>
                <?php endif; ?>
                
                <table class="wp-list-table widefat fixed striped" id="spp-rewards-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Reward', 'cube-payment-portal' ); ?></th>
                            <th style="width: 120px;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                            <th style="width: 120px;"><?php esc_html_e( 'Created', 'cube-payment-portal' ); ?></th>
                            <th style="width: 100px; text-align: right;"><?php esc_html_e( 'Action', 'cube-payment-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- History Tab -->
            <div id="tab-history" class="spp-tab-content" style="display: none;">
                <table class="wp-list-table widefat fixed striped" id="spp-events-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Type', 'cube-payment-portal' ); ?></th>
                            <th style="width: 150px;"><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></th>
                            <th style="width: 100px; text-align: right;"><?php esc_html_e( 'Points', 'cube-payment-portal' ); ?></th>
                            <th style="width: 100px;"><?php esc_html_e( 'Source', 'cube-payment-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentAccountId, currentBalance;
    var allAccounts = [];
    
    function showMessage(message, type) {
        $('#spp-message').removeClass('notice-success notice-error').addClass('notice-' + type).find('p').text(message);
        $('#spp-message').show().delay(5000).fadeOut();
    }

    function updateMetrics(accounts) {
        var totalPoints = 0;
        accounts.forEach(function(acc) {
            totalPoints += parseInt(acc.balance) || 0;
        });
        $('#spp-metric-accounts').text(accounts.length);
        $('#spp-metric-points').text(totalPoints.toLocaleString());
    }
    
    function loadAccounts() {
        $('#spp-loading').show();
        $('#spp-accounts-table-wrap').hide();
        $('#spp-empty-state').hide();
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_loyalty_accounts',
            nonce: sppAdmin.nonce
        }, function(response) {
            $('#spp-loading').hide();
            
            if (response.success && response.data.accounts && response.data.accounts.length > 0) {
                allAccounts = response.data.accounts;
                updateMetrics(allAccounts);
                renderAccounts(allAccounts);
                $('#spp-accounts-table-wrap').show();
            } else {
                $('#spp-empty-state').show();
                updateMetrics([]);
            }
        });
    }

    function renderAccounts(accounts) {
        var searchTerm = $('#spp-search-filter').val().toLowerCase();
        var $tbody = $('#spp-loyalty-accounts-table tbody');
        $tbody.empty();
        
        var displayedCount = 0;
        accounts.forEach(function(item) {
            if (searchTerm && item.customer_name.toLowerCase().indexOf(searchTerm) === -1) {
                return;
            }
            displayedCount++;
            
            var phone = item.customer_phone ? item.customer_phone : (item.mapping && item.mapping.phone_number ? item.mapping.phone_number : '<?php echo esc_js( __( 'No phone', 'cube-payment-portal' ) ); ?>');
            var balanceColor = parseInt(item.balance) > 0 ? '#4caf50' : '#666';
            
            // Build Customer Link (No Tooltip)
            var customerLink = '';
            if (item.square_customer_id) {
                var customerUrl = sppAdmin.adminUrl + 'admin.php?page=spp-customers&customer_id=' + encodeURIComponent(item.square_customer_id);
                customerLink = '<a href="' + customerUrl + '" class="spp-customer-link" style="text-decoration: none; font-weight: 500; color: #667eea;">' + item.customer_name + '</a>';
            } else {
                customerLink = '<strong style="color: #667eea;">' + item.customer_name + '</strong>';
            }

            var email = item.customer_email ? item.customer_email : '<?php echo esc_js( __( 'No email', 'cube-payment-portal' ) ); ?>';

            var row = '<tr>' +
                '<td>' + customerLink + '</td>' +
                '<td style="color: #666; font-size: 13px;">' + email + '</td>' +
                '<td style="color: #999; font-size: 13px;">' + phone + '</td>' +
                '<td style="text-align: center;"><span style="background: #e8f5e9; color: ' + balanceColor + '; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">' + item.balance + '</span></td>' +
                '<td style="text-align: center; color: #666;">' + item.lifetime_points + '</td>' +
                '<td style="text-align: right;">' +
                    '<button type="button" class="button button-primary button-small spp-manage-account" ' +
                        'data-id="' + item.id + '" ' +
                        'data-customer="' + item.customer_name + '" ' +
                        'data-phone="' + phone + '" ' +
                        'data-balance="' + item.balance + '">' +
                        '<?php echo esc_js( __( 'Manage', 'cube-payment-portal' ) ); ?>' +
                    '</button>' +
                '</td>' +
            '</tr>';
            $tbody.append(row);
        });
        
        $('#spp-accounts-count').text(displayedCount);
    }

    loadAccounts();
    
    $('#spp-refresh-accounts').on('click', function() {
        var $btn = $(this);
        $btn.find('.dashicons').addClass('spin');
        loadAccounts();
        setTimeout(function() { $btn.find('.dashicons').removeClass('spin'); }, 1000);
    });

    $('#spp-loyalty-filter-form').on('submit', function(e) {
        e.preventDefault();
        renderAccounts(allAccounts);
    });

    // Modal handling
    $('.spp-modal-close, .spp-modal-overlay').on('click', function() {
        $(this).closest('.spp-modal').hide();
        loadAccounts(); // Refresh on close
    });

    // Tab navigation
    $('.spp-tab').on('click', function() {
        $('.spp-tab').removeClass('active');
        $(this).addClass('active');
        $('.spp-tab-content').hide();
        $('#tab-' + $(this).data('tab')).show();
    });

    // Open manage modal
    $(document).on('click', '.spp-manage-account', function() {
        currentAccountId = $(this).data('id');
        currentBalance = parseInt($(this).data('balance'));
        var customerName = $(this).data('customer');
        var phone = $(this).data('phone');

        $('#spp-modal-customer').text(customerName);
        $('#spp-modal-phone').text(phone);
        $('#spp-modal-balance').text(currentBalance);
        $('#spp-adjust-points').val(10);
        $('#spp-adjust-reason').val('');
        
        // Reset to first tab
        $('.spp-tab').removeClass('active').first().addClass('active');
        $('.spp-tab-content').hide().first().show();

        $('#spp-manage-modal').show();
        
        loadRewards();
        loadEvents();
    });

    // Adjust Points
    function adjustPoints(amount, isNegative) {
        if (isNegative) amount = -amount;
        var reason = $('#spp-adjust-reason').val();

        var $btn = isNegative ? $('#spp-btn-subtract-points') : $('#spp-btn-add-points');
        $btn.prop('disabled', true);

        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_adjust_loyalty_points',
            nonce: sppAdmin.nonce,
            account_id: currentAccountId,
            points: amount,
            reason: reason
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                currentBalance += amount;
                $('#spp-modal-balance').text(currentBalance);
                showMessage('<?php echo esc_js( __( 'Points adjusted successfully.', 'cube-payment-portal' ) ); ?>', 'success');
                loadEvents();
            } else {
                showMessage('Error: ' + response.data.message, 'error');
            }
        });
    }

    $('#spp-btn-add-points').on('click', function() {
        var pts = parseInt($('#spp-adjust-points').val());
        if (pts > 0) adjustPoints(pts, false);
    });

    $('#spp-btn-subtract-points').on('click', function() {
        var pts = parseInt($('#spp-adjust-points').val());
        if (pts > 0) adjustPoints(pts, true);
    });

    // Load Rewards
    function loadRewards() {
        $('#spp-rewards-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #666;">Loading...</td></tr>');
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_get_loyalty_rewards',
            nonce: sppAdmin.nonce,
            account_id: currentAccountId
        }, function(response) {
            if (response.success) {
                var rewards = response.data.rewards;
                if (!rewards || rewards.length === 0) {
                    $('#spp-rewards-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #666;"><?php echo esc_js( __( 'No active rewards.', 'cube-payment-portal' ) ); ?></td></tr>');
                    return;
                }
                
                var html = '';
                rewards.forEach(function(r) {
                    var status = r.status || 'ISSUED';
                    var statusClass = status === 'ISSUED' ? 'spp-status-paid' : 'spp-status-pending';
                    html += '<tr>' +
                        '<td><code style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 11px;">' + r.id.substring(0, 12) + '...</code></td>' +
                        '<td><span class="spp-status-badge ' + statusClass + '">' + status + '</span></td>' +
                        '<td style="color: #666; font-size: 12px;">' + (r.created_at || '').substring(0, 10) + '</td>' +
                        '<td style="text-align: right;"><button type="button" class="button button-small spp-delete-reward" data-id="' + r.id + '" style="color: #dc3232;">Delete</button></td>' +
                    '</tr>';
                });
                $('#spp-rewards-table tbody').html(html);
            }
        });
    }

    // Create Reward
    $('#spp-btn-create-reward').on('click', function() {
        var tierId = $('#spp-reward-tier-select').val();
        if (!tierId) {
            showMessage('<?php echo esc_js( __( 'Please select a reward tier.', 'cube-payment-portal' ) ); ?>', 'error');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_create_loyalty_reward',
            nonce: sppAdmin.nonce,
            account_id: currentAccountId,
            reward_tier_id: tierId
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                showMessage('<?php echo esc_js( __( 'Reward created successfully!', 'cube-payment-portal' ) ); ?>', 'success');
                loadRewards();
                loadEvents();
            } else {
                showMessage('Error: ' + response.data.message, 'error');
            }
        });
    });

    // Delete Reward
    $(document).on('click', '.spp-delete-reward', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete this reward? Points will be returned to the customer.', 'cube-payment-portal' ) ); ?>')) return;
        
        var rewardId = $(this).data('id');
        $.post(sppAdmin.ajaxUrl, {
            action: 'spp_delete_loyalty_reward',
            nonce: sppAdmin.nonce,
            reward_id: rewardId
        }, function(response) {
            if (response.success) {
                showMessage('<?php echo esc_js( __( 'Reward deleted successfully.', 'cube-payment-portal' ) ); ?>', 'success');
                loadRewards();
                loadEvents();
            } else {
                showMessage('Error: ' + response.data.message, 'error');
            }
        });
    });

    // Load Events
    function loadEvents() {
        $('#spp-events-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #666;">Loading...</td></tr>');
        $.ajax({
            url: sppAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'spp_get_loyalty_events',
                nonce: sppAdmin.nonce,
                account_id: currentAccountId
            },
            success: function(response) {
                console.log('Events response:', response);
                if (response.success) {
                    var events = response.data.events;
                    if (!events || events.length === 0) {
                        $('#spp-events-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #666;"><?php echo esc_js( __( 'No history found.', 'cube-payment-portal' ) ); ?></td></tr>');
                        return;
                    }
                    
                    var html = '';
                    events.forEach(function(e) {
                        var type = (e.type || 'UNKNOWN').replace(/_/g, ' ');
                        var points = '';
                        var pointsColor = '#666';
                        
                        if (e.accumulate_points) {
                            points = '+' + e.accumulate_points.points;
                            pointsColor = '#4caf50';
                        } else if (e.adjust_points) {
                            var adj = e.adjust_points.points;
                            points = adj > 0 ? '+' + adj : adj;
                            pointsColor = adj > 0 ? '#4caf50' : '#dc3232';
                        } else if (e.create_reward) {
                            points = '-' + e.create_reward.points;
                            pointsColor = '#dc3232';
                        } else if (e.redeem_reward) {
                            points = '<?php echo esc_js( __( 'Redeemed', 'cube-payment-portal' ) ); ?>';
                        } else if (e.delete_reward) {
                            points = '<?php echo esc_js( __( 'Returned', 'cube-payment-portal' ) ); ?>';
                            pointsColor = '#4caf50';
                        }
                        
                        html += '<tr>' +
                            '<td style="text-transform: capitalize;">' + type.toLowerCase() + '</td>' +
                            '<td style="color: #666; font-size: 12px;">' + (e.created_at || '').replace('T', ' ').substring(0, 16) + '</td>' +
                            '<td style="text-align: right; font-weight: 600; color: ' + pointsColor + ';">' + points + '</td>' +
                            '<td style="font-size: 12px; color: #999;">' + (e.source || 'MANUAL') + '</td>' +
                        '</tr>';
                    });
                    $('#spp-events-table tbody').html(html);
                } else {
                    console.error('Events error:', response.data);
                    $('#spp-events-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #dc3232;">Error: ' + (response.data.message || 'Unknown error') + '</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
                $('#spp-events-table tbody').html('<tr><td colspan="4" style="text-align: center; color: #dc3232;">AJAX Error: ' + error + '</td></tr>');
            }
        });
    }
});
</script>
