<?php
/**
 * Admin Inventory page.
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

// Load inventory API.
if ( ! class_exists( 'SPP_Inventory' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-inventory.php';
}

$inventory_api = new SPP_Inventory();

// Get locations.
$locations = $inventory_api->get_locations();
$locations = is_wp_error( $locations ) ? array() : $locations;

// Get low stock threshold from settings.
$low_stock_threshold = get_option( 'spp_low_stock_threshold', 10 );

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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Inventory', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Inventory', 'cube-payment-portal' ); ?></h2>

    <!-- Location Selector -->
    <div style="background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 20px 0; display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; gap: 15px; align-items: center;">
            <label for="spp-location-select" style="font-weight: 500;">
                <?php esc_html_e( 'Location:', 'cube-payment-portal' ); ?>
            </label>
            <select id="spp-location-select" style="min-width: 200px;">
                <option value=""><?php esc_html_e( 'All Locations', 'cube-payment-portal' ); ?></option>
                <?php foreach ( $locations as $location ) : ?>
                    <option value="<?php echo esc_attr( $location['id'] ); ?>">
                        <?php echo esc_html( $location['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="spp-refresh-inventory" class="button button-primary">
            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
            <?php esc_html_e( 'Refresh', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <!-- Low Stock Alerts -->
    <div id="spp-low-stock-section" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
        <h3 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
            <span class="dashicons dashicons-warning" style="color: #ff9800;"></span>
            <?php esc_html_e( 'Low Stock Alerts', 'cube-payment-portal' ); ?>
            <span id="spp-low-stock-count" style="background: #ff9800; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 12px;">0</span>
        </h3>
        <div id="spp-low-stock-list" style="display: grid; gap: 10px;"></div>
        <div id="spp-low-stock-empty" style="display: none; text-align: center; padding: 20px; color: #666;">
            <span class="dashicons dashicons-yes-alt" style="font-size: 32px; color: #4caf50;"></span>
            <p><?php esc_html_e( 'All items are well stocked!', 'cube-payment-portal' ); ?></p>
        </div>
    </div>

    <!-- Sync Message -->
    <div id="spp-inventory-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Filters -->
    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
        <form id="spp-inventory-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="spp-filter-group">
                <label for="spp-search" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Search', 'cube-payment-portal' ); ?>
                </label>
                <input type="search" id="spp-search" name="search" placeholder="<?php esc_attr_e( 'Item name...', 'cube-payment-portal' ); ?>" style="min-width: 250px;">
            </div>
            
            <div class="spp-filter-group">
                <label for="spp-stock-filter" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Stock Status', 'cube-payment-portal' ); ?>
                </label>
                <select id="spp-stock-filter" style="min-width: 150px;">
                    <option value=""><?php esc_html_e( 'All', 'cube-payment-portal' ); ?></option>
                    <option value="low"><?php esc_html_e( 'Low Stock', 'cube-payment-portal' ); ?></option>
                    <option value="out"><?php esc_html_e( 'Out of Stock', 'cube-payment-portal' ); ?></option>
                    <option value="in"><?php esc_html_e( 'In Stock', 'cube-payment-portal' ); ?></option>
                </select>
            </div>
            
            <div class="spp-filter-group" style="display: flex; gap: 8px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
                <button type="button" id="spp-reset-filters" class="button"><?php esc_html_e( 'Reset', 'cube-payment-portal' ); ?></button>
            </div>
        </form>
    </div>

    <!-- Loading -->
    <div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading inventory...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-empty-state" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <span class="dashicons dashicons-archive" style="font-size: 64px; color: #ccc; width: 64px; height: 64px;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Inventory Items', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Sync your catalog to see inventory counts.', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Inventory Table -->
    <div id="spp-inventory-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Inventory Counts', 'cube-payment-portal' ); ?></h3>
            <span style="color: #666; font-size: 13px;"><span id="spp-showing-count">0</span> <?php esc_html_e( 'items', 'cube-payment-portal' ); ?></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 30px; padding-left: 10px;"></th>
                    <th style="width: 35%;"><?php esc_html_e( 'Item', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Variation', 'cube-payment-portal' ); ?></th>
                    <th style="width: 12%; text-align: center;"><?php esc_html_e( 'Quantity', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'Last Updated', 'cube-payment-portal' ); ?></th>
                    <th style="width: 8%;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody id="spp-inventory-tbody"></tbody>
        </table>

        <div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
            <div class="tablenav-pages" id="spp-pagination">
                <span class="displaying-num" id="spp-total-count"></span>
                <span class="pagination-links" id="spp-pagination-links"></span>
            </div>
        </div>
    </div>
</div>

<!-- Adjust Quantity Modal -->
<div id="spp-adjust-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-content" style="max-width: 450px;">
        <div class="spp-modal-header">
            <h2 id="spp-modal-title"><?php esc_html_e( 'Adjust Quantity', 'cube-payment-portal' ); ?></h2>
            <span class="spp-close-modal" id="spp-close-adjust-modal">&times;</span>
        </div>
        
        <form id="spp-adjust-form">
            <div class="spp-modal-body">
                <input type="hidden" id="spp-adjust-item-id" name="catalog_object_id">
                
                <div class="spp-adjustment-info-card">
                    <p id="spp-adjust-item-name" class="spp-item-name-preview"></p>
                    <div class="spp-qty-badge">
                        <span class="label"><?php esc_html_e( 'Current stock:', 'cube-payment-portal' ); ?></span>
                        <strong id="spp-adjust-current-qty">0</strong>
                    </div>
                </div>

                <div class="spp-form-group">
                    <label class="spp-label"><?php esc_html_e( 'Adjustment', 'cube-payment-portal' ); ?></label>
                    <div class="spp-adjust-input-group">
                        <select id="spp-adjust-type" class="spp-select">
                            <option value="add"><?php esc_html_e( 'Add', 'cube-payment-portal' ); ?></option>
                            <option value="remove"><?php esc_html_e( 'Remove', 'cube-payment-portal' ); ?></option>
                            <option value="set"><?php esc_html_e( 'Set to', 'cube-payment-portal' ); ?></option>
                        </select>
                        <input type="number" id="spp-adjust-quantity" class="spp-input-large" min="0" value="0">
                    </div>
                </div>

                <div class="spp-form-group">
                    <label for="spp-adjust-reason" class="spp-label"><?php esc_html_e( 'Reason', 'cube-payment-portal' ); ?></label>
                    <select id="spp-adjust-reason" class="spp-select">
                        <option value="Stock received"><?php esc_html_e( 'Stock received', 'cube-payment-portal' ); ?></option>
                        <option value="Inventory re-count"><?php esc_html_e( 'Inventory re-count', 'cube-payment-portal' ); ?></option>
                        <option value="Damage"><?php esc_html_e( 'Damage', 'cube-payment-portal' ); ?></option>
                        <option value="Theft"><?php esc_html_e( 'Theft', 'cube-payment-portal' ); ?></option>
                        <option value="Loss"><?php esc_html_e( 'Loss', 'cube-payment-portal' ); ?></option>
                        <option value="Restock return"><?php esc_html_e( 'Restock return', 'cube-payment-portal' ); ?></option>
                    </select>
                </div>

                <div id="spp-adjust-message" class="spp-alert" style="display: none;"></div>
            </div>
            
            <div class="spp-modal-footer">
                <button type="button" id="spp-cancel-adjust" class="button spp-close-modal-btn"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary button-large" id="spp-save-adjust-btn">
                    <?php esc_html_e( 'Save Adjustment', 'cube-payment-portal' ); ?>
                    <span class="spinner" id="spp-adjust-spinner"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var SPPInventory = {
        currentPage: 1,
        perPage: 20,
        currentLocation: '',
        lowStockThreshold: <?php echo (int) $low_stock_threshold; ?>,
        debounceTimer: null,
        
        // Reason mapping
        reasons: {
            'add': [
                { value: 'Stock received', text: '<?php echo esc_js( __( 'Stock received', 'cube-payment-portal' ) ); ?>' },
                { value: 'Restock return', text: '<?php echo esc_js( __( 'Restock return', 'cube-payment-portal' ) ); ?>' }
            ],
            'remove': [
                { value: 'Damage', text: '<?php echo esc_js( __( 'Damage', 'cube-payment-portal' ) ); ?>' },
                { value: 'Theft', text: '<?php echo esc_js( __( 'Theft', 'cube-payment-portal' ) ); ?>' },
                { value: 'Loss', text: '<?php echo esc_js( __( 'Loss', 'cube-payment-portal' ) ); ?>' }
            ],
            'set': [
                { value: 'Inventory re-count', text: '<?php echo esc_js( __( 'Inventory re-count', 'cube-payment-portal' ) ); ?>' }
            ]
        },

        init: function() {
            this.bindEvents();
            this.loadLowStockAlerts();
            this.loadInventory();
        },

        bindEvents: function() {
            var self = this;

            // Refresh
            $('#spp-refresh-inventory').on('click', function() {
                self.loadLowStockAlerts();
                self.loadInventory();
            });

            // Location change
            $('#spp-location-select').on('change', function() {
                self.currentLocation = $(this).val();
                self.currentPage = 1;
                self.loadLowStockAlerts();
                self.loadInventory();
            });

            // Filter form
            $('#spp-inventory-filter-form').on('submit', function(e) {
                e.preventDefault();
                self.currentPage = 1;
                self.loadInventory();
            });

            $('#spp-reset-filters').on('click', function() {
                $('#spp-inventory-filter-form')[0].reset();
                self.currentPage = 1;
                self.loadInventory();
            });

            // Debounced search
            $('#spp-search').on('input', function() {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.currentPage = 1;
                    self.loadInventory();
                }, 500);
            });

            // Stock filter
            $('#spp-stock-filter').on('change', function() {
                self.currentPage = 1;
                self.loadInventory();
            });

            // Pagination
            $(document).on('click', '.spp-page-link', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadInventory();
                }
            });

            // Adjust modal
            $(document).on('click', '.spp-adjust-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('tr');
                
                // Get data from button first (for single variation items on parent rows), then row
                var id = $btn.data('id') || $row.data('id');
                var name = $btn.data('name') || $row.data('name');
                var qty = $btn.data('qty') !== undefined ? $btn.data('qty') : $row.data('qty');

                $('#spp-adjust-item-id').val(id);
                $('#spp-adjust-item-name').text(name);
                $('#spp-adjust-current-qty').text(qty);
                $('#spp-adjust-quantity').val(0);
                $('#spp-adjust-type').val('add').trigger('change');
                $('#spp-adjust-reason').val('Stock received');
                $('#spp-adjust-message').hide();
                $('#spp-adjust-modal').css('display', 'flex');
            });

            // Close modal
            $('#spp-close-adjust-modal, #spp-cancel-adjust, .spp-close-modal-btn').on('click', function() {
                $('#spp-adjust-modal').hide();
            });

            $('#spp-adjust-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Submit adjustment
            $('#spp-adjust-form').on('submit', function(e) {
                e.preventDefault();
                self.submitAdjustment();
            });

            // Dynamic reasons
            $('#spp-adjust-type').on('change', function() {
                var type = $(this).val();
                self.updateReasons(type);
            });

            // Row toggle
            $(document).on('click', '.spp-row-toggle', function() {
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var itemId = $row.data('id');
                var $variations = $('.spp-parent-' + itemId);

                $variations.toggle();
                $btn.toggleClass('dashicons-arrow-right-alt2 dashicons-arrow-down-alt2');
            });
        },

        updateReasons: function(type) {
            var $select = $('#spp-adjust-reason');
            $select.empty();
            
            var options = this.reasons[type] || [];
            $.each(options, function(i, opt) {
                $select.append($('<option></option>').val(opt.value).text(opt.text));
            });
        },

        loadLowStockAlerts: function() {
            var self = this;

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_get_low_stock',
                    nonce: sppAdmin.nonce,
                    location_id: self.currentLocation,
                    threshold: self.lowStockThreshold
                },
                success: function(response) {
                    if (response && response.success && response.data.items) {
                        self.renderLowStockAlerts(response.data.items);
                    }
                }
            });
        },

        renderLowStockAlerts: function(items) {
            var $list = $('#spp-low-stock-list');
            $list.empty();

            $('#spp-low-stock-count').text(items.length);

            if (items.length === 0) {
                $('#spp-low-stock-empty').show();
                $list.hide();
                return;
            }

            $('#spp-low-stock-empty').hide();
            $list.show();

            var self = this;
            $.each(items, function(i, item) {
                var statusClass = item.quantity === 0 ? 'spp-status-failed' : 'spp-status-pending';
                var statusText = item.quantity === 0 ? '<?php echo esc_js( __( 'Out of Stock', 'cube-payment-portal' ) ); ?>' : '<?php echo esc_js( __( 'Low Stock', 'cube-payment-portal' ) ); ?>';
                
                var html = '<div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #fffbf0; border: 1px solid #ffe0b2; border-radius: 4px;">' +
                    '<div>' +
                        '<strong>' + self.escapeHtml(item.item_name) + '</strong>' +
                        (item.variation_name && item.variation_name !== 'Default' ? '<span style="color: #666;"> - ' + self.escapeHtml(item.variation_name) + '</span>' : '') +
                    '</div>' +
                    '<div style="display: flex; align-items: center; gap: 15px;">' +
                        '<span style="font-weight: 600;">' + item.quantity + ' <?php echo esc_js( __( 'in stock', 'cube-payment-portal' ) ); ?></span>' +
                        '<span class="spp-status-badge ' + statusClass + '">' + statusText + '</span>' +
                    '</div>' +
                '</div>';
                
                $list.append(html);
            });
        },

        loadInventory: function() {
            var self = this;
            
            $('#spp-loading').show();
            $('#spp-inventory-table-wrap').hide();
            $('#spp-empty-state').hide();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_get_inventory',
                    nonce: sppAdmin.nonce,
                    search: $('#spp-search').val(),
                    stock_filter: $('#spp-stock-filter').val(),
                    location_id: self.currentLocation,
                    page: self.currentPage,
                    per_page: self.perPage
                },
                success: function(response) {
                    $('#spp-loading').hide();

                    if (!response || !response.success) {
                        var errorMsg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to load inventory.', 'cube-payment-portal' ) ); ?>';
                        self.showError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !data.items || data.items.length === 0) {
                        $('#spp-empty-state').show();
                        $('#spp-showing-count').text('0');
                        return;
                    }

                    self.renderInventory(data.items);
                    self.renderPagination(data.pagination);
                    $('#spp-inventory-table-wrap').show();
                },
                error: function() {
                    $('#spp-loading').hide();
                    self.showError('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
                }
            });
        },

        renderInventory: function(items) {
            var $tbody = $('#spp-inventory-tbody');
            $tbody.empty();

            var self = this;
            $.each(items, function(i, item) {
                var rows = self.buildRows(item);
                $tbody.append(rows);
            });

            $('#spp-showing-count').text(items.length);
        },

        buildRows: function(item) {
            var self = this;
            var isTracked = item.track_inventory;
            var variations = item.variations || [];
            var variationCount = variations.length;

            // Prepare status badge for parent
            var statusInfo = this.getStatusInfo(item.quantity, isTracked, item.is_archived);
            
            // Expand toggle
            var toggleHtml = variationCount > 1 
                ? '<span class="spp-row-toggle dashicons dashicons-arrow-right-alt2" style="cursor: pointer; color: #666; font-size: 16px; width: 16px; height: 16px;"></span>' 
                : '';

            // Parent Row
            var html = '<tr class="spp-item-row' + (item.is_archived ? ' is-archived' : '') + '" data-id="' + item.id + '">' +
                '<td style="padding-left: 10px;">' + toggleHtml + '</td>' +
                '<td><strong>' + this.escapeHtml(item.item_name) + '</strong></td>' +
                '<td>' + (variationCount === 1 ? this.escapeHtml(variations[0].variation_name) : '-') + '</td>' +
                '<td style="text-align: center; font-weight: 600; font-size: 16px;">' + (isTracked ? item.quantity : '-') + '</td>' +
                '<td>' + statusInfo.badgeHtml + '</td>' +
                '<td style="color: #666; font-size: 13px;">' + (item.updated_at || '-') + '</td>' +
                '<td>' + (variationCount === 1 ? '<button class="button button-small spp-adjust-btn" ' + (isTracked ? '' : 'disabled') + ' data-id="' + variations[0].catalog_object_id + '" data-name="' + this.escapeHtml(item.item_name + ' - ' + variations[0].variation_name) + '" data-qty="' + variations[0].quantity + '"><?php echo esc_js( __( 'Adjust', 'cube-payment-portal' ) ); ?></button>' : '-') + '</td>' +
            '</tr>';

            // Variation Rows
            if (variationCount > 1) {
                $.each(variations, function(idx, v) {
                    var vStatusInfo = self.getStatusInfo(v.quantity, v.track_inventory, v.is_archived);
                    
                    html += '<tr class="spp-variation-row spp-parent-' + item.id + '" style="display: none; background: #fafafa;" data-id="' + v.catalog_object_id + '" data-name="' + self.escapeHtml(item.item_name + ' - ' + v.variation_name) + '" data-qty="' + v.quantity + '">' +
                        '<td></td>' +
                        '<td style="padding-left: 20px;">' +
                            '<div style="display: flex; align-items: center; gap: 10px; padding-left: 15px; border-left: 2px solid #eee;">' +
                                '<span style="color: #666; font-size: 13px;">' + self.escapeHtml(v.variation_name) + '</span>' +
                            '</div>' +
                        '</td>' +
                        '<td></td>' +
                        '<td style="text-align: center; font-weight: 600; font-size: 14px; color: #666;">' + (v.track_inventory ? v.quantity : '-') + '</td>' +
                        '<td>' + vStatusInfo.badgeHtml + '</td>' +
                        '<td style="color: #666; font-size: 13px;">' + (v.updated_at || '-') + '</td>' +
                        '<td><button class="button button-small spp-adjust-btn" ' + (v.track_inventory ? '' : 'disabled') + '><?php echo esc_js( __( 'Adjust', 'cube-payment-portal' ) ); ?></button></td>' +
                    '</tr>';
                });
            }

            return html;
        },

        getStatusInfo: function(quantity, isTracked, isArchived) {
            var statusClass = 'spp-status-paid';
            var statusText = '<?php echo esc_js( __( 'In Stock', 'cube-payment-portal' ) ); ?>';
            var statusStyle = '';
            
            var archivedBadge = isArchived ? '<span class="spp-status-badge" style="background: #f0f0f1; color: #72aee6; border: 1px solid #72aee6; margin-left: 5px; font-size: 10px;"><?php echo esc_js( __( 'Archived', 'cube-payment-portal' ) ); ?></span>' : '';

            if (!isTracked) {
                statusClass = ''; 
                statusText = '<?php echo esc_js( __( 'Not Tracked', 'cube-payment-portal' ) ); ?>';
                statusStyle = 'background: #e0e0e0; color: #666; border: 1px solid #ccc;';
            } else if (quantity === 0) {
                statusClass = 'spp-status-failed';
                statusText = '<?php echo esc_js( __( 'Out of Stock', 'cube-payment-portal' ) ); ?>';
            } else if (quantity <= this.lowStockThreshold) {
                statusClass = 'spp-status-pending';
                statusText = '<?php echo esc_js( __( 'Low Stock', 'cube-payment-portal' ) ); ?>';
            }

            var badgeHtml = ( statusClass 
                ? '<span class="spp-status-badge ' + statusClass + '">' + statusText + '</span>'
                : '<span class="spp-status-badge" style="' + statusStyle + '">' + statusText + '</span>' ) + archivedBadge;

            return { badgeHtml: badgeHtml, statusClass: statusClass };
        },

        renderPagination: function(pagination) {
            var total = pagination.total, totalPages = pagination.total_pages, currentPage = pagination.current_page;
            $('#spp-total-count').text(total + ' <?php echo esc_js( __( 'total', 'cube-payment-portal' ) ); ?>');

            if (totalPages <= 1) { $('#spp-pagination-links').empty(); return; }

            var links = '';
            links += currentPage > 1 ? '<a href="#" class="first-page button spp-page-link" data-page="1">&laquo;</a> <a href="#" class="prev-page button spp-page-link" data-page="' + (currentPage - 1) + '">&lsaquo;</a> ' : '<span class="button disabled">&laquo;</span> <span class="button disabled">&lsaquo;</span> ';
            links += '<span class="paging-input">' + currentPage + ' of ' + totalPages + '</span>';
            links += currentPage < totalPages ? ' <a href="#" class="next-page button spp-page-link" data-page="' + (currentPage + 1) + '">&rsaquo;</a> <a href="#" class="last-page button spp-page-link" data-page="' + totalPages + '">&raquo;</a>' : ' <span class="button disabled">&rsaquo;</span> <span class="button disabled">&raquo;</span>';

            $('#spp-pagination-links').html(links);
        },

        submitAdjustment: function() {
            var self = this;
            var $btn = $('#spp-save-adjust-btn');
            var $msg = $('#spp-adjust-message');

            $btn.prop('disabled', true);
            $msg.hide();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_adjust_inventory',
                    nonce: sppAdmin.nonce,
                    catalog_object_id: $('#spp-adjust-item-id').val(),
                    adjust_type: $('#spp-adjust-type').val(),
                    quantity: $('#spp-adjust-quantity').val(),
                    reason: $('#spp-adjust-reason').val(),
                    location_id: self.currentLocation
                },
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response && response.success) {
                        $msg.css({ background: '#e8f5e9', color: '#2e7d32' }).text(response.data.message).show();
                        setTimeout(function() {
                            $('#spp-adjust-modal').hide();
                            self.loadInventory();
                            self.loadLowStockAlerts();
                        }, 1000);
                    } else {
                        $msg.css({ background: '#ffebee', color: '#c62828' }).text(response.data.message || '<?php echo esc_js( __( 'Failed to adjust inventory.', 'cube-payment-portal' ) ); ?>').show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $msg.css({ background: '#ffebee', color: '#c62828' }).text('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>').show();
                }
            });
        },

        showError: function(message) { $('#spp-empty-state').show().find('h2').text(message); },
        escapeHtml: function(text) { if (!text) return ''; var div = document.createElement('div'); div.appendChild(document.createTextNode(text)); return div.innerHTML; }
    };

    $(document).ready(function() { SPPInventory.init(); });
})(jQuery);
</script>

<style>
/* Standard Modal Styles */
.spp-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
    display: none;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}
.spp-modal-content {
    background-color: #fff;
    margin: auto;
    width: 90%;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    animation: sppModalFadeIn 0.3s ease-out;
}

@keyframes sppModalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.spp-modal-header {
    padding: 15px 25px;
    border-bottom: 1px solid #f0f0f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fcfcfc;
}
.spp-modal-header h2 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 600;
    color: #1e293b;
}
.spp-close-modal {
    color: #64748b;
    font-size: 24px;
    font-weight: 400;
    cursor: pointer;
    line-height: 1;
    padding: 5px;
    border-radius: 4px;
    transition: all 0.2s;
}
.spp-close-modal:hover {
    color: #0f172a;
    background: #f1f5f9;
}

.spp-modal-body {
    padding: 25px;
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}

.spp-modal-footer {
    padding: 15px 25px;
    border-top: 1px solid #f0f0f1;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #fcfcfc;
}

.spp-form-group {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.spp-label {
    font-weight: 600;
    font-size: 13px;
    color: #475569;
}

.spp-input-large {
    padding: 8px 12px !important;
    font-size: 14px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    width: 100%;
}
.spp-select {
    padding: 6px 10px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    width: 100%;
    height: 38px !important;
}

/* Adjustment Specific Styles */
.spp-adjustment-info-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}
.spp-item-name-preview {
    margin: 0 0 10px 0 !important;
    font-weight: 600;
    color: #1e293b;
    font-size: 15px;
}
.spp-qty-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #64748b;
}
.spp-qty-badge strong {
    color: #2271b1;
    font-size: 15px;
}

.spp-adjust-input-group {
    display: flex;
    gap: 10px;
}
.spp-adjust-input-group .spp-select {
    flex: 1;
}
.spp-adjust-input-group .spp-input-large {
    width: 100px;
}

.spp-alert {
    padding: 10px 15px;
    border-radius: 6px;
    font-size: 13px;
    margin-top: 15px;
}

/* Hierarchical view styles */
#spp-inventory-tbody .spp-item-row { background: #fff !important; }
#spp-inventory-tbody .spp-variation-row td { 
    border-top: none !important; 
    padding-top: 5px !important; 
    padding-bottom: 5px !important; 
}
#spp-inventory-tbody .spp-variation-row:last-child td { border-bottom: 1px solid #f0f0f1 !important; }
.spp-row-toggle:hover { color: #2271b1 !important; }

/* Archived Item Styling */
.is-archived td {
    opacity: 0.6;
    background-color: #fbfbfb;
}
</style>
