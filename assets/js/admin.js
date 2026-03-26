// @ts-nocheck
/**
 * Cube Payment Portal - Admin JavaScript
 */

(function ($) {
    'use strict';

    /**
     * Admin functionality.
     */
    const SPPAdmin = {
        /**
         * Initialize.
         */
        init: function () {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function () {
            // OAuth connect button.
            $(document).on('click', '.spp-connect-button', this.handleConnect);

            // OAuth disconnect button.
            $(document).on('click', '.spp-disconnect-button', this.handleDisconnect);

            // Sync button.
            $(document).on('click', '.spp-sync-button', this.handleSync);

            // Sync All button.
            $(document).on('click', '#spp-sync-all-button', this.handleSyncAll);

            // Feature toggles.
            $(document).on('change', '.spp-switch input[type="checkbox"]', this.handleFeatureToggle);

            // Customer tooltip with 1-second hover delay.
            this.initCustomerTooltips();
        },

        /**
         * Initialize customer tooltips with 1-second hover delay.
         */
        initCustomerTooltips: function () {
            let tooltipTimer = null;

            // Mouse enter - start 1 second timer
            $(document).on('mouseenter', '.spp-customer-link', function () {
                const $tooltip = $(this).find('.spp-customer-tooltip');
                if ($tooltip.length) {
                    tooltipTimer = setTimeout(function () {
                        $tooltip.addClass('show');
                    }, 1000); // 1 second delay
                }
            });

            // Mouse leave - clear timer and hide tooltip
            $(document).on('mouseleave', '.spp-customer-link', function () {
                if (tooltipTimer) {
                    clearTimeout(tooltipTimer);
                    tooltipTimer = null;
                }
                $(this).find('.spp-customer-tooltip').removeClass('show');
            });
        },

        /**
         * Handle OAuth connect button click.
         */
        handleConnect: function (e) {
            e.preventDefault();

            const $button = $(this);
            $button.prop('disabled', true).text('Connecting...');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_initiate_oauth',
                    nonce: sppAdmin.nonce
                },
                success: function (response) {
                    if (response.success && response.data.auth_url) {
                        // Redirect to Square OAuth.
                        window.location.href = response.data.auth_url;
                    } else {
                        alert(response.data.message || 'Failed to initiate connection.');
                        $button.prop('disabled', false).text('Connect to Square');
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false).text('Connect to Square');
                }
            });
        },

        /**
         * Handle disconnect button click.
         */
        handleDisconnect: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to disconnect from Square?')) {
                return;
            }

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_disconnect',
                    nonce: sppAdmin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || 'Failed to disconnect.');
                    }
                }
            });
        },

        /**
         * Handle sync button click.
         */
        handleSync: function (e) {
            e.preventDefault();

            const $button = $(this);
            $button.prop('disabled', true).text('Syncing...');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_sync_now',
                    nonce: sppAdmin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        alert('Sync completed successfully.');
                        location.reload();
                    } else {
                        alert(response.data.message || 'Sync failed.');
                    }
                },
                complete: function () {
                    $button.prop('disabled', false).text('Sync Now');
                }
            });
        },

        /**
         * Handle Sync All button click.
         */
        handleSyncAll: function (e) {
            e.preventDefault();

            const $button = $('#spp-sync-all-button');
            const $spinner = $('#spp-sync-all-spinner');
            const $result = $('#spp-sync-all-result');
            const $resultMessage = $result.find('.spp-sync-result-message');
            const $resultDetails = $result.find('.spp-sync-result-details');
            const originalText = $button.html();

            // Disable button and show spinner.
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update" style="margin-top: 3px; margin-right: 3px; animation: rotation 1s infinite linear;"></span> Synchronizing...');
            $spinner.addClass('is-active');
            $result.hide();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_sync_all',
                    nonce: sppAdmin.nonce
                },
                timeout: 300000, // 5 minute timeout for large syncs.
                success: function (response) {
                    $result.show();

                    if (response.success) {
                        $resultMessage.html('<div class="notice notice-success inline"><p><strong>Sync completed!</strong> ' + response.data.message + '</p></div>');

                        // Build detailed results.
                        let details = '<ul style="margin: 0; padding-left: 20px;">';
                        const results = response.data.results;

                        if (results.customers) {
                            details += '<li><strong>Customers:</strong> ' + (results.customers.from_square || 0) + ' synced from Square, ' + (results.customers.to_square || 0) + ' pushed to Square, ' + (results.customers.auto_linked || 0) + ' auto-linked</li>';
                        }

                        if (results.subscriptions) {
                            details += '<li><strong>Subscriptions:</strong> ' + (results.subscriptions.synced || 0) + ' synced, ' + (results.subscriptions.errors || 0) + ' errors</li>';
                        }

                        if (results.invoices) {
                            details += '<li><strong>Invoices:</strong> ' + (results.invoices.synced || 0) + ' synced, ' + (results.invoices.errors || 0) + ' errors</li>';
                        }

                        if (results.transactions) {
                            details += '<li><strong>Transactions:</strong> ' + (results.transactions.synced || 0) + ' synced, ' + (results.transactions.errors || 0) + ' errors</li>';
                        }

                        if (results.errors && results.errors.length > 0) {
                            details += '<li style="color: #d63638;"><strong>Errors:</strong><ul style="margin: 5px 0 0 0; padding-left: 20px;">';
                            results.errors.forEach(function (err) {
                                details += '<li>' + err + '</li>';
                            });
                            details += '</ul></li>';
                        }

                        details += '</ul>';
                        $resultDetails.html(details);
                    } else {
                        $resultMessage.html('<div class="notice notice-error inline"><p><strong>Sync failed:</strong> ' + (response.data.message || 'Unknown error') + '</p></div>');
                        $resultDetails.html('');
                    }
                },
                error: function (xhr, status, error) {
                    $result.show();
                    let errorMsg = 'An error occurred during sync.';

                    if (status === 'timeout') {
                        errorMsg = 'The sync operation timed out. This may happen with large datasets. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }

                    $resultMessage.html('<div class="notice notice-error inline"><p><strong>Sync failed:</strong> ' + errorMsg + '</p></div>');
                    $resultDetails.html('');
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Handle feature toggle change.
         */
        handleFeatureToggle: function (e) {
            const $checkbox = $(this);
            const $label = $checkbox.closest('.spp-switch').find('.spp-toggle-label');
            const feature = $checkbox.attr('name');
            const isEnabled = $checkbox.is(':checked');
            const originalLabel = $label.text();

            // Disable input and show saving state.
            $checkbox.prop('disabled', true);
            $label.text('Saving...');
            $label.css('opacity', '0.7');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_toggle_feature',
                    feature: feature,
                    enabled: isEnabled,
                    nonce: sppAdmin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $label.text(isEnabled ? 'Enabled' : 'Disabled');
                        // Show success color briefly
                        $label.css('color', '#007cba');
                        setTimeout(function () {
                            $label.css('color', '');
                        }, 1000);

                        // If toggling a main feature, we might want to refresh the page to update menu items,
                        // but for now we'll just let the user see the update on next nav.
                        // Optionally reload if user wants instant menu update:
                        // location.reload(); 
                    } else {
                        alert(response.data.message || 'Failed to save setting.');
                        $checkbox.prop('checked', !isEnabled); // Revert
                        $label.text(originalLabel);
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                    $checkbox.prop('checked', !isEnabled); // Revert
                    $label.text(originalLabel);
                },
                complete: function () {
                    $checkbox.prop('disabled', false);
                    $label.css('opacity', '1');
                }
            });
        }
    };

    // Add rotation animation for spinner icon.
    if (!document.getElementById('spp-sync-animation-style')) {
        const style = document.createElement('style');
        style.id = 'spp-sync-animation-style';
        style.textContent = '@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    }

    // Initialize on document ready.
    $(document).ready(function () {
        SPPAdmin.init();

        // Widget order drag-and-drop sortable.
        var $sortable = $('#spp-widget-sortable');
        if ($sortable.length && $.fn.sortable) {
            $sortable.sortable({
                placeholder: 'ui-sortable-placeholder',
                cursor: 'grabbing',
                opacity: 0.8,
                tolerance: 'pointer',
                update: function () {
                    var order = [];
                    $sortable.find('li').each(function () {
                        order.push($(this).data('widget-key'));
                    });
                    $('#spp_dashboard_widget_order').val(order.join(', '));
                }
            });

            // Sync initial order on page load if items exist.
            if ($sortable.find('li').length) {
                var order = [];
                $sortable.find('li').each(function () {
                    order.push($(this).data('widget-key'));
                });
                $('#spp_dashboard_widget_order').val(order.join(', '));
            }
        }
    });

})(jQuery);
