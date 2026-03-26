<?php
/**
 * Admin Orders page.
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

// Get default currency.
$default_currency = get_option( 'spp_default_currency', 'USD' );

// Get order status summary.
$status_summary = SPP_Database::get_orders_status_summary();
$total_orders = array_sum( $status_summary );

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
    <h1 class="screen-reader-text"><?php esc_html_e( 'Orders', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Orders', 'cube-payment-portal' ); ?></h2>
        <div class="spp-header-actions" style="display: flex; gap: 10px;">
            <button type="button" id="spp-bulk-delete" class="button" style="display: none;">
                <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-top: -2px;"></span>
                <?php esc_html_e( 'Delete Selected', 'cube-payment-portal' ); ?>
            </button>
            <button type="button" id="spp-sync-orders" class="spp-button">
                 <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Orders', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>

    <!-- Status Tabs -->
    <div style="display: flex; gap: 10px; margin: 20px 0;">
        <button type="button" class="spp-status-tab active" data-status="" style="padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
            <?php esc_html_e( 'All', 'cube-payment-portal' ); ?>
            <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-size: 12px;"><?php echo esc_html( $total_orders ); ?></span>
        </button>
        <button type="button" class="spp-status-tab" data-status="OPEN" style="padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: 500;">
            <?php esc_html_e( 'Open', 'cube-payment-portal' ); ?>
            <span style="background: #e3f2fd; color: #1565c0; padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-size: 12px;"><?php echo esc_html( $status_summary['OPEN'] ); ?></span>
        </button>
        <button type="button" class="spp-status-tab" data-status="COMPLETED" style="padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: 500;">
            <?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?>
            <span style="background: #e8f5e9; color: #2e7d32; padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-size: 12px;"><?php echo esc_html( $status_summary['COMPLETED'] ); ?></span>
        </button>
        <button type="button" class="spp-status-tab" data-status="CANCELED" style="padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: 500;">
            <?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?>
            <span style="background: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-size: 12px;"><?php echo esc_html( $status_summary['CANCELED'] ); ?></span>
        </button>
        <button type="button" class="spp-status-tab" data-status="DRAFT" style="padding: 10px 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-weight: 500;">
            <?php esc_html_e( 'Draft', 'cube-payment-portal' ); ?>
            <span style="background: #f5f5f5; color: #616161; padding: 2px 8px; border-radius: 10px; margin-left: 5px; font-size: 12px;"><?php echo esc_html( $status_summary['DRAFT'] ); ?></span>
        </button>
    </div>

    <!-- Sync Message -->
    <div id="spp-sync-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Filters -->
    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
        <form id="spp-orders-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="spp-filter-group">
                <label for="spp-search" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Search', 'cube-payment-portal' ); ?>
                </label>
                <input type="search" id="spp-search" name="search" placeholder="<?php esc_attr_e( 'Customer, Order ID...', 'cube-payment-portal' ); ?>" style="min-width: 250px;">
            </div>
            
            <div class="spp-filter-group">
                <label for="spp-date-from" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'From', 'cube-payment-portal' ); ?>
                </label>
                <input type="date" id="spp-date-from" name="date_from">
            </div>
            
            <div class="spp-filter-group">
                <label for="spp-date-to" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'To', 'cube-payment-portal' ); ?>
                </label>
                <input type="date" id="spp-date-to" name="date_to">
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
        <p style="color: #666;"><?php esc_html_e( 'Loading orders...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-empty-state" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <span class="dashicons dashicons-cart" style="font-size: 64px; color: #ccc; width: 64px; height: 64px;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Orders Found', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Orders will appear here once synced from Square.', 'cube-payment-portal' ); ?></p>
        <button type="button" id="spp-sync-orders-empty" class="button button-primary" style="margin-top: 15px;">
            <?php esc_html_e( 'Sync Orders Now', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <!-- Orders Table -->
    <div id="spp-orders-table-wrap" style="display: none; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                    <input type="checkbox" id="spp-select-all">
                    <span style="font-size: 12px; color: #666;"><?php esc_html_e( 'Select All', 'cube-payment-portal' ); ?></span>
                </label>
                <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Orders', 'cube-payment-portal' ); ?></h3>
            </div>
            <span style="color: #666; font-size: 13px;"><span id="spp-showing-count">0</span> <?php esc_html_e( 'orders', 'cube-payment-portal' ); ?></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th class="spp-sortable" data-sort="id" style="width: 80px; cursor: pointer;">
                        <?php esc_html_e( 'ID', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th><?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?></th>
                    <th class="spp-sortable" data-sort="total_amount" style="width: 120px; cursor: pointer;">
                        <?php esc_html_e( 'Total', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th class="spp-sortable" data-sort="status" style="width: 110px; cursor: pointer;">
                        <?php esc_html_e( 'Status', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th style="width: 80px;"><?php esc_html_e( 'Items', 'cube-payment-portal' ); ?></th>
                    <th class="spp-sortable" data-sort="created_at" style="width: 150px; cursor: pointer;">
                        <?php esc_html_e( 'Date', 'cube-payment-portal' ); ?>
                        <span class="spp-sort-indicator dashicons"></span>
                    </th>
                    <th style="width: 80px;"><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                </tr>
            </thead>
            <tbody id="spp-orders-tbody"></tbody>
        </table>

        <div class="tablenav bottom" style="padding: 15px 20px; border-top: 1px solid #eee; margin: 0;">
            <div class="tablenav-pages" id="spp-pagination">
                <span class="displaying-num" id="spp-total-count"></span>
                <span class="pagination-links" id="spp-pagination-links"></span>
            </div>
        </div>
    </div>
</div>

<!-- Order Detail Modal -->
<div id="spp-order-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; overflow: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px;"><?php esc_html_e( 'Order Details', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-close-modal" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
        </div>
        <div id="spp-modal-content" style="padding: 20px;">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var SPPOrders = {
        currentPage: 1,
        perPage: 20,
        sortBy: 'id',
        sortOrder: 'DESC',
        currentStatus: '',
        selectedOrders: [],
        debounceTimer: null,

        init: function() {
            this.bindEvents();
            this.updateSortIndicators();
            this.loadOrders();
        },

        bindEvents: function() {
            var self = this;

            // Sync buttons
            $('#spp-sync-orders, #spp-sync-orders-empty').on('click', function() {
                self.syncOrders();
            });

            // Status tabs
            $('.spp-status-tab').on('click', function() {
                $('.spp-status-tab').removeClass('active').css({ background: '#fff', color: '#333' });
                $(this).addClass('active').css({ background: '#0073aa', color: '#fff' });
                self.currentStatus = $(this).data('status');
                self.currentPage = 1;
                self.loadOrders();
            });

            // Filter form
            $('#spp-orders-filter-form').on('submit', function(e) {
                e.preventDefault();
                self.currentPage = 1;
                self.loadOrders();
            });

            $('#spp-reset-filters').on('click', function() {
                $('#spp-orders-filter-form')[0].reset();
                self.currentPage = 1;
                self.loadOrders();
            });

            // Debounced search
            $('#spp-search').on('input', function() {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.currentPage = 1;
                    self.loadOrders();
                }, 500);
            });

            // Date filters
            $('#spp-date-from, #spp-date-to').on('change', function() {
                self.currentPage = 1;
                self.loadOrders();
            });

            // Pagination
            $(document).on('click', '.spp-page-link', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadOrders();
                }
            });

            // Sortable columns
            $(document).on('click', '.spp-sortable', function() {
                var sortColumn = $(this).data('sort');
                if (self.sortBy === sortColumn) {
                    self.sortOrder = self.sortOrder === 'DESC' ? 'ASC' : 'DESC';
                } else {
                    self.sortBy = sortColumn;
                    self.sortOrder = 'DESC';
                }
                self.currentPage = 1;
                self.updateSortIndicators();
                self.loadOrders();
            });

            // Select all
            $('#spp-select-all').on('change', function() {
                var checked = $(this).is(':checked');
                $('.spp-order-checkbox').prop('checked', checked);
                self.updateSelectedOrders();
            });

            // Individual checkboxes
            $(document).on('change', '.spp-order-checkbox', function() {
                self.updateSelectedOrders();
            });

            // Bulk delete
            $('#spp-bulk-delete').on('click', function() {
                if (self.selectedOrders.length > 0 && confirm('<?php echo esc_js( __( 'Delete selected orders from local database?', 'cube-payment-portal' ) ); ?>')) {
                    self.bulkDelete();
                }
            });

            // View order detail
            $(document).on('click', '.spp-view-order', function(e) {
                e.preventDefault();
                var orderId = $(this).data('id');
                self.showOrderDetail(orderId);
            });

            // Close modal
            $('#spp-close-modal, #spp-order-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#spp-order-modal').hide();
                }
            });
        },

        updateSortIndicators: function() {
            var self = this;
            $('.spp-sortable').removeClass('sorted-asc sorted-desc');
            $('.spp-sort-indicator').removeClass('dashicons-arrow-up dashicons-arrow-down');
            
            var $activeHeader = $('.spp-sortable[data-sort="' + self.sortBy + '"]');
            if (self.sortOrder === 'ASC') {
                $activeHeader.addClass('sorted-asc');
                $activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-up');
            } else {
                $activeHeader.addClass('sorted-desc');
                $activeHeader.find('.spp-sort-indicator').addClass('dashicons-arrow-down');
            }
        },

        updateSelectedOrders: function() {
            var self = this;
            self.selectedOrders = [];
            $('.spp-order-checkbox:checked').each(function() {
                self.selectedOrders.push($(this).val());
            });

            if (self.selectedOrders.length > 0) {
                $('#spp-bulk-delete').show();
            } else {
                $('#spp-bulk-delete').hide();
            }
        },

        loadOrders: function() {
            var self = this;
            
            $('#spp-loading').show();
            $('#spp-orders-table-wrap').hide();
            $('#spp-empty-state').hide();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_get_orders',
                    nonce: sppAdmin.nonce,
                    search: $('#spp-search').val(),
                    status: self.currentStatus,
                    date_from: $('#spp-date-from').val(),
                    date_to: $('#spp-date-to').val(),
                    page: self.currentPage,
                    per_page: self.perPage,
                    sort_by: self.sortBy,
                    sort_order: self.sortOrder
                },
                success: function(response) {
                    $('#spp-loading').hide();

                    if (!response || !response.success) {
                        var errorMsg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to load orders.', 'cube-payment-portal' ) ); ?>';
                        self.showError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !data.orders || data.orders.length === 0) {
                        $('#spp-empty-state').show();
                        $('#spp-showing-count').text('0');
                        return;
                    }

                    self.renderOrders(data.orders);
                    self.renderPagination(data.pagination);
                    $('#spp-orders-table-wrap').show();
                },
                error: function() {
                    $('#spp-loading').hide();
                    self.showError('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
                }
            });
        },

        renderOrders: function(orders) {
            var $tbody = $('#spp-orders-tbody');
            $tbody.empty();

            var self = this;
            $.each(orders, function(i, order) {
                var row = self.buildRow(order);
                $tbody.append(row);
            });

            $('#spp-showing-count').text(orders.length);
            $('#spp-select-all').prop('checked', false);
            self.selectedOrders = [];
            $('#spp-bulk-delete').hide();
        },

        buildRow: function(order) {
            var statusClass = this.getStatusClass(order.status);
            var currencySymbol = this.getCurrencySymbol(order.currency);
            var lineItemCount = order.line_item_count || 0;

            // Build customer link with tooltip
            var customerHtml = '';
            var customerName = order.customer_name || '<?php echo esc_js( __( 'Guest', 'cube-payment-portal' ) ); ?>';
            if (order.square_customer_id) {
                var customerUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-customers&customer_id=' ) ); ?>' + encodeURIComponent(order.square_customer_id);
                var tooltipHtml = '<div class="spp-customer-tooltip spp-tooltip-left">' +
                    (order.customer_email ? '<div class="tooltip-row"><span class="dashicons dashicons-email"></span> ' + this.escapeHtml(order.customer_email) + '</div>' : '') +
                    (order.customer_phone ? '<div class="tooltip-row"><span class="dashicons dashicons-phone"></span> ' + this.escapeHtml(order.customer_phone) + '</div>' : '') +
                '</div>';

                customerHtml = '<a href="' + customerUrl + '" class="spp-customer-link" style="text-decoration: none; font-weight: 600;">' + 
                    this.escapeHtml(customerName) + 
                    tooltipHtml + 
                '</a>';
            } else {
                customerHtml = '<strong>' + this.escapeHtml(customerName) + '</strong>' +
                    (order.customer_email ? '<br><span style="color: #999; font-size: 12px;">' + this.escapeHtml(order.customer_email) + '</span>' : '');
            }

            return '<tr>' +
                '<td><input type="checkbox" class="spp-order-checkbox" value="' + order.square_order_id + '"></td>' +
                '<td><strong>#' + order.id + '</strong></td>' +
                '<td>' + customerHtml + '</td>' +
                '<td style="font-weight: 600;">' + currencySymbol + order.total_amount + '</td>' +
                '<td><span class="spp-status-badge ' + statusClass + '">' + this.escapeHtml(order.status) + '</span></td>' +
                '<td style="text-align: center;">' + lineItemCount + '</td>' +
                '<td style="color: #666; font-size: 13px;">' + order.created_at_formatted + '</td>' +
                '<td><a href="#" class="spp-view-order" data-id="' + order.id + '" style="text-decoration: none;"><span class="dashicons dashicons-visibility" style="color: #0073aa;"></span></a></td>' +
            '</tr>';
        },

        getStatusClass: function(status) {
            var classes = {
                'OPEN': 'spp-status-pending',
                'COMPLETED': 'spp-status-paid',
                'CANCELED': 'spp-status-canceled',
                'DRAFT': 'spp-status-draft'
            };
            return classes[status.toUpperCase()] || 'spp-status-pending';
        },

        getCurrencySymbol: function(currency) {
            var symbols = { EUR: '€', GBP: '£', JPY: '¥' };
            return symbols[currency] || '$';
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

        showOrderDetail: function(orderId) {
            var self = this;
            $('#spp-modal-content').html('<p style="text-align: center;"><span class="spinner is-active" style="float: none;"></span></p>');
            $('#spp-order-modal').show();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_get_order_detail',
                    nonce: sppAdmin.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response && response.success && response.data) {
                        self.renderOrderDetail(response.data);
                    } else {
                        $('#spp-modal-content').html('<p style="color: #c00;"><?php echo esc_js( __( 'Failed to load order details.', 'cube-payment-portal' ) ); ?></p>');
                    }
                },
                error: function() {
                    $('#spp-modal-content').html('<p style="color: #c00;"><?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?></p>');
                }
            });
        },

        renderOrderDetail: function(order) {
            var currencySymbol = this.getCurrencySymbol(order.currency);
            var statusClass = this.getStatusClass(order.status);
            
            // Build customer display (with or without link)
            var customerName = order.customer_name || '<?php echo esc_js( __( 'Guest', 'cube-payment-portal' ) ); ?>';
            var customerHtml = '';
            if (order.square_customer_id) {
                var customerUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-customers&customer_id=' ) ); ?>' + encodeURIComponent(order.square_customer_id);
                customerHtml = '<a href="' + customerUrl + '" style="color: #2271b1; text-decoration: none;">' + this.escapeHtml(customerName) + '</a>';
            } else {
                customerHtml = this.escapeHtml(customerName);
            }
            
            var html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">' +
                '<div>' +
                    '<p><strong><?php echo esc_js( __( 'Order ID:', 'cube-payment-portal' ) ); ?></strong> ' + this.escapeHtml(order.square_order_id) + '</p>' +
                    '<p><strong><?php echo esc_js( __( 'Customer:', 'cube-payment-portal' ) ); ?></strong> ' + customerHtml + '</p>' +
                    '<p><strong><?php echo esc_js( __( 'Email:', 'cube-payment-portal' ) ); ?></strong> ' + this.escapeHtml(order.customer_email || '-') + '</p>' +
                '</div>' +
                '<div>' +
                    '<p><strong><?php echo esc_js( __( 'Status:', 'cube-payment-portal' ) ); ?></strong> <span class="spp-status-badge ' + statusClass + '">' + this.escapeHtml(order.status) + '</span></p>' +
                    '<p><strong><?php echo esc_js( __( 'Created:', 'cube-payment-portal' ) ); ?></strong> ' + order.created_at_formatted + '</p>' +
                '</div>' +
            '</div>';

            // Line Items
            if (order.line_items && order.line_items.length > 0) {
                html += '<h3 style="font-size: 14px; margin: 20px 0 10px;"><?php echo esc_js( __( 'Line Items', 'cube-payment-portal' ) ); ?></h3>';
                html += '<table class="wp-list-table widefat fixed striped" style="margin: 0;">';
                html += '<thead><tr><th><?php echo esc_js( __( 'Item', 'cube-payment-portal' ) ); ?></th><th style="width: 80px;"><?php echo esc_js( __( 'Qty', 'cube-payment-portal' ) ); ?></th><th style="width: 100px;"><?php echo esc_js( __( 'Price', 'cube-payment-portal' ) ); ?></th><th style="width: 100px;"><?php echo esc_js( __( 'Total', 'cube-payment-portal' ) ); ?></th></tr></thead>';
                html += '<tbody>';
                
                for (var i = 0; i < order.line_items.length; i++) {
                    var item = order.line_items[i];
                    html += '<tr>' +
                        '<td>' + this.escapeHtml(item.name) + (item.variation_name ? '<br><small style="color:#999;">' + this.escapeHtml(item.variation_name) + '</small>' : '') + '</td>' +
                        '<td>' + item.quantity + '</td>' +
                        '<td>' + currencySymbol + parseFloat(item.base_price).toFixed(2) + '</td>' +
                        '<td>' + currencySymbol + parseFloat(item.total_money).toFixed(2) + '</td>' +
                    '</tr>';
                }
                
                html += '</tbody></table>';
            }

            // Totals
            html += '<div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">';
            html += '<div style="display: flex; justify-content: flex-end;">';
            html += '<div style="width: 200px;">';
            
            if (parseFloat(order.total_discount) > 0) {
                html += '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;"><span><?php echo esc_js( __( 'Discount:', 'cube-payment-portal' ) ); ?></span><span>-' + currencySymbol + order.total_discount + '</span></div>';
            }
            if (parseFloat(order.total_tax) > 0) {
                html += '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;"><span><?php echo esc_js( __( 'Tax:', 'cube-payment-portal' ) ); ?></span><span>' + currencySymbol + order.total_tax + '</span></div>';
            }
            html += '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 16px;"><span><?php echo esc_js( __( 'Total:', 'cube-payment-portal' ) ); ?></span><span>' + currencySymbol + order.total_amount + '</span></div>';
            
            html += '</div></div></div>';

            $('#spp-modal-content').html(html);
        },

        syncOrders: function() {
            var self = this;
            var $button = $('#spp-sync-orders');
            var $message = $('#spp-sync-message');

            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'spp_sync_orders', nonce: sppAdmin.nonce },
                success: function(response) {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');

                    if (response && response.success) {
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text((response.data && response.data.message) || '<?php echo esc_js( __( 'Orders synced successfully.', 'cube-payment-portal' ) ); ?>');
                        $('#spp-last-sync-time').text('<?php echo esc_js( __( 'Just now', 'cube-payment-portal' ) ); ?>');
                        self.currentPage = 1;
                        self.loadOrders();
                        // Refresh status counts.
                        location.reload();
                    } else {
                        $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                        $message.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Sync failed.', 'cube-payment-portal' ) ); ?>');
                    }
                    setTimeout(function() { $message.fadeOut(); }, 8000);
                },
                error: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                    $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                    $message.find('p').text('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>');
                }
            });
        },

        bulkDelete: function() {
            var self = this;
            var $message = $('#spp-sync-message');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_bulk_delete_orders',
                    nonce: sppAdmin.nonce,
                    order_ids: self.selectedOrders
                },
                success: function(response) {
                    if (response && response.success) {
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text((response.data && response.data.message) || '<?php echo esc_js( __( 'Orders deleted.', 'cube-payment-portal' ) ); ?>');
                        self.loadOrders();
                    } else {
                        $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                        $message.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Delete failed.', 'cube-payment-portal' ) ); ?>');
                    }
                    setTimeout(function() { $message.fadeOut(); }, 5000);
                }
            });
        },

        showError: function(message) { $('#spp-empty-state').show().find('h2').text(message); },
        escapeHtml: function(text) { if (!text) return ''; var div = document.createElement('div'); div.appendChild(document.createTextNode(text)); return div.innerHTML; }
    };

    $(document).ready(function() { SPPOrders.init(); });
})(jQuery);
</script>

<style>
.spp-status-tab.active { border-color: #0073aa !important; }
.spp-status-draft { background: #f5f5f5; color: #616161; }
.spp-sortable { user-select: none; transition: background-color 0.15s ease; }
.spp-sortable:hover { background-color: #f0f0f1; }
.spp-sortable.sorted-asc, .spp-sortable.sorted-desc { background-color: #e8f4fc; }
.spp-sort-indicator { font-size: 14px; vertical-align: middle; margin-left: 4px; opacity: 0.7; }
.spp-sortable:not(.sorted-asc):not(.sorted-desc) .spp-sort-indicator { opacity: 0; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.dashicons.spin { animation: spin 1s linear infinite; }
</style>
