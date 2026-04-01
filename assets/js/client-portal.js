/**
 * Cube Payment Portal - Client Portal JavaScript
 *
 * Main application module for the client portal.
 * Handles routing, API communication, UI helpers, and component loading.
 * Integrates with Square Web Payments SDK for card tokenization and payments.
 *
 * @package CubePaymentPortal
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    /**
     * Main Client Portal Application Module.
     *
     * @namespace SPPPortal
     */
    const SPPPortal = {
        /**
         * Application configuration.
         *
         * @type {Object}
         */
        config: {
            /** @type {string} Default view to show */
            defaultView: 'dashboard',
            /** @type {number} Notification auto-dismiss timeout for success (ms) */
            notificationSuccessTimeout: 5000,
            /** @type {number} Notification auto-dismiss timeout for errors (ms) */
            notificationErrorTimeout: 8000,
            /** @type {string} Main content container selector */
            contentSelector: '.spp-portal-content',
            /** @type {string} Portal container selector */
            portalSelector: '.spp-portal',
            /** @type {string} Modal container selector */
            modalSelector: '.spp-modal',
            /** @type {string} Default currency code */
            defaultCurrency: 'USD',
            /** @type {string} Default date format locale */
            dateLocale: 'en-US'
        },

        /**
         * Current active view name.
         *
         * @type {string}
         */
        currentView: 'dashboard',

        /**
         * Square payments instance.
         *
         * @type {Object|null}
         */
        payments: null,

        /**
         * Card instance for Square SDK.
         *
         * @type {Object|null}
         */
        card: null,

        /**
         * Whether the portal has been initialized.
         *
         * @type {boolean}
         */
        initialized: false,

        /**
         * Initialize the portal application.
         *
         * @async
         * @returns {Promise<void>}
         */
        init: async function () {
            if (this.initialized) {
                return;
            }

            // Initialize router first
            this.router.init();

            // Register route handlers for post-navigation actions.
            this.registerRouteHandlers();

            // Bind DOM events
            this.bindEvents();

            // Initialize Square SDK only if card container is present (payment views)
            if ($('#spp-card-container').length) {
                await this.initSquare();
            }

            // Check if server already rendered the initial view (SSR).
            // If so, skip the initial loadView() to avoid double-loading.
            const $content = $(this.config.contentSelector);
            if ($content.length && !$content.attr('data-spp-initial-view')) {
                // No SSR content, load current view via AJAX
                this.components.loadView(this.currentView);
            } else {
                // SSR content present — just update nav active state
                this.components.updateActiveNav(this.currentView);
            }

            // Mark as initialized
            this.initialized = true;

            // Start idle session timer (if configured).
            this.idleTimer.start();

            // Auto-load any dashboard widget components
            this.loadDashboardComponents();

            // Also load components after any AJAX view navigation
            document.addEventListener('spp:view:loaded', () => {
                this.loadDashboardComponents();
            });

            // Dispatch custom event for extensions
            document.dispatchEvent(new CustomEvent('spp:portal:ready', {
                detail: { portal: this }
            }));
        },

        /**
         * Register route handlers that run after a view finishes loading.
         *
         * These enable quick-action cards to trigger post-navigation
         * actions such as opening modals, setting filters, or scrolling.
         */
        registerRouteHandlers: function () {
            const self = this;

            // Bookings: auto-open the booking modal.
            this.router.routes['bookings'] = function (params) {
                if (params && params.action === 'book') {
                    setTimeout(function () {
                        $('#spp-book-appointment-btn').trigger('click');
                    }, 300);
                }
            };

            // Invoices: auto-set the filter dropdown.
            this.router.routes['invoices'] = function (params) {
                if (params && params.filter) {
                    setTimeout(function () {
                        const $filter = $('#spp-inv-filter-status');
                        if ($filter.length) {
                            $filter.val(params.filter).trigger('change');
                        }
                    }, 300);
                }
            };

            // Loyalty: scroll to the redeem section.
            this.router.routes['loyalty'] = function (params) {
                if (params && params.scrollTo === 'redeem') {
                    setTimeout(function () {
                        const $redeem = $('.spp-redeem-reward').first().closest('.spp-portal__card');
                        if ($redeem.length) {
                            $redeem[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 300);
                }
            };

            // Payment Methods: auto-open the Buy Gift Card modal.
            this.router.routes['payment-methods'] = function (params) {
                if (params && params.action === 'buy-gift-card') {
                    setTimeout(function () {
                        $('#spp-buy-gift-card-btn').trigger('click');
                    }, 300);
                }
            };
        },

        /**
         * Auto-load all dashboard widget components.
         *
         * Finds all elements with [data-spp-component] and calls
         * the spp_refresh_component AJAX endpoint for each.
         */
        loadDashboardComponents: function () {
            const $components = $('[data-spp-component]');
            if (!$components.length) {
                return;
            }

            $components.each(function () {
                const $el = $(this);
                const componentId = $el.data('spp-component');

                if (!componentId) {
                    return;
                }

                // Find the skeleton placeholder inside the card, or fall back to the card itself
                const $skeleton = $el.find('.spp-portal__skeleton');
                const $target = $skeleton.length ? $skeleton : $el;

                // Replace skeleton with loaded content, preserving the card header
                SPPPortal.api.post('spp_refresh_component', {
                    component: componentId,
                    params: '{}'
                }).then((response) => {
                    $target.replaceWith(response.html);
                }).catch((error) => {
                    sppPublic && sppPublic.debug && console.warn('SPPPortal: Widget load failed for', componentId, error);
                    $target.replaceWith(`<p class="spp-portal__text--muted spp-portal__text--sm">Unable to load. <a href="#" class="spp-portal__link" data-spp-refresh="${this.escapeHtml(componentId)}">Retry</a></p>`);
                });
            });
        },

        // ============================================================
        // ROUTER - History API based routing system
        // ============================================================

        /**
         * Router module for handling view navigation.
         *
         * @namespace SPPPortal.router
         */
        router: {
            /**
             * Whether the router has been initialized.
             *
             * @type {boolean}
             */
            initialized: false,

            /**
             * Registered route handlers.
             *
             * @type {Object}
             */
            routes: {},

            /**
             * Initialize the router.
             *
             * @returns {void}
             */
            init: function () {
                if (this.initialized) {
                    return;
                }

                // Get current view from URL
                SPPPortal.currentView = this.getCurrentView();

                // Listen for browser back/forward navigation
                window.addEventListener('popstate', this.handlePopState.bind(this));

                // Intercept navigation links within the portal
                $(document).on('click', '[data-spp-view]', this.handleViewClick.bind(this));
                $(document).on('click', '.spp-nav-link[href*="view="]', this.handleNavLinkClick.bind(this));

                // Widget retry links
                $(document).on('click', '[data-spp-refresh]', function (e) {
                    e.preventDefault();
                    var componentId = $(this).data('spp-refresh');
                    if (componentId) {
                        SPPPortal.components.refresh(componentId);
                    }
                });

                this.initialized = true;
            },

            /**
             * Get the current view from URL parameters.
             *
             * @returns {string} Current view name or default view.
             */
            getCurrentView: function () {
                const urlParams = new URLSearchParams(window.location.search);
                return urlParams.get('view') || SPPPortal.config.defaultView;
            },

            /**
             * Get URL parameters as an object.
             *
             * @returns {Object} URL parameters.
             */
            getParams: function () {
                const urlParams = new URLSearchParams(window.location.search);
                const params = {};
                for (const [key, value] of urlParams.entries()) {
                    params[key] = value;
                }
                return params;
            },

            /**
             * Navigate to a specific view.
             *
             * @param {string} view - View name to navigate to.
             * @param {Object} [params={}] - Additional URL parameters.
             * @param {boolean} [replaceState=false] - Whether to replace current history entry.
             * @returns {void}
             */
            navigate: function (view, params = {}, replaceState = false) {
                const url = this.buildUrl(view, params);

                if (replaceState) {
                    window.history.replaceState({ view, params }, '', url);
                } else {
                    window.history.pushState({ view, params }, '', url);
                }

                SPPPortal.currentView = view;

                // Dispatch navigation event
                document.dispatchEvent(new CustomEvent('spp:navigate', {
                    detail: { view, params }
                }));

                // Load the view content
                SPPPortal.components.loadView(view, params);
            },

            /**
             * Build URL for a view with parameters.
             *
             * @param {string} view - View name.
             * @param {Object} [params={}] - Additional parameters.
             * @returns {string} Constructed URL.
             */
            buildUrl: function (view, params = {}) {
                const url = new URL(window.location.href);
                url.searchParams.set('view', view);

                // Add additional params
                Object.entries(params).forEach(([key, value]) => {
                    if (value !== null && value !== undefined) {
                        url.searchParams.set(key, value);
                    } else {
                        url.searchParams.delete(key);
                    }
                });

                return url.toString();
            },

            /**
             * Handle browser back/forward navigation.
             *
             * @param {PopStateEvent} event - Popstate event.
             * @returns {void}
             */
            handlePopState: function (event) {
                const view = this.getCurrentView();
                const params = this.getParams();

                SPPPortal.currentView = view;

                // Dispatch navigation event
                document.dispatchEvent(new CustomEvent('spp:navigate', {
                    detail: { view, params, popstate: true }
                }));

                // Load the view content
                SPPPortal.components.loadView(view, params);
            },

            /**
             * Handle click on data-spp-view elements.
             *
             * @param {Event} event - Click event.
             * @returns {void}
             */
            handleViewClick: function (event) {
                event.preventDefault();
                const $target = $(event.currentTarget);
                const view = $target.data('spp-view');
                const params = $target.data('spp-params') || {};

                this.navigate(view, params);
            },

            /**
             * Handle click on navigation links.
             *
             * @param {Event} event - Click event.
             * @returns {void}
             */
            handleNavLinkClick: function (event) {
                const href = $(event.currentTarget).attr('href');
                if (!href) {
                    return;
                }

                // Parse the href to determine if it's a portal link
                try {
                    const url = new URL(href, window.location.origin);
                    const view = url.searchParams.get('view');

                    // Only intercept links with a view parameter
                    // that are on the same origin
                    if (!view || url.origin !== window.location.origin) {
                        return; // Let external or non-portal links work normally
                    }

                    event.preventDefault();

                    const params = {};
                    for (const [key, value] of url.searchParams.entries()) {
                        if (key !== 'view') {
                            params[key] = value;
                        }
                    }

                    // Close mobile nav if open
                    $('#spp-portal-nav').removeClass('spp-portal-nav--open');
                    $('#spp-nav-overlay').removeClass('spp-portal-nav__overlay--visible');
                    $('#spp-nav-toggle').removeClass('spp-portal-nav__toggle--open');

                    this.navigate(view, params);
                } catch (e) {
                    // If URL parsing fails, let the browser handle it
                    return;
                }
            },

            /**
             * Register a route handler.
             *
             * @param {string} viewName - View name to handle.
             * @param {Function} handler - Handler function.
             * @returns {void}
             */
            register: function (viewName, handler) {
                this.routes[viewName] = handler;
            },

            /**
             * Go back in history.
             *
             * @returns {void}
             */
            back: function () {
                window.history.back();
            },

            /**
             * Go forward in history.
             *
             * @returns {void}
             */
            forward: function () {
                window.history.forward();
            }
        },

        // ============================================================
        // API - AJAX communication helpers
        // ============================================================

        /**
         * API module for server communication.
         *
         * @namespace SPPPortal.api
         */
        api: {
            /**
             * Track ongoing requests for cancellation.
             *
             * @type {Map}
             */
            pendingRequests: new Map(),

            /**
             * Make an AJAX request to the WordPress backend.
             *
             * @async
             * @param {string} action - WordPress AJAX action name.
             * @param {Object} [data={}] - Data to send with the request.
             * @param {Object} [options={}] - Additional options.
             * @param {string} [options.method='POST'] - HTTP method.
             * @param {boolean} [options.showLoading=false] - Show loading state.
             * @param {string} [options.loadingContainer] - Container to show loading in.
             * @param {string} [options.requestId] - Unique ID for request cancellation.
             * @returns {Promise<Object>} Response data.
             * @throws {Error} If request fails.
             */
            request: async function (action, data = {}, options = {}) {
                const {
                    method = 'POST',
                    showLoading = false,
                    loadingContainer = null,
                    requestId = null
                } = options;

                // Show loading if requested
                if (showLoading && loadingContainer) {
                    SPPPortal.ui.showLoading(loadingContainer);
                }

                // Cancel previous request with same ID if exists
                if (requestId && this.pendingRequests.has(requestId)) {
                    this.pendingRequests.get(requestId).abort();
                }

                // Prepare request data
                const requestData = {
                    action: action,
                    nonce: sppPublic.nonce,
                    ...data
                };

                return new Promise((resolve, reject) => {
                    const xhr = $.ajax({
                        url: sppPublic.ajaxUrl,
                        type: method,
                        data: requestData,
                        dataType: 'json',
                        success: (response) => {
                            if (response.success) {
                                resolve(response.data);
                            } else {
                                const error = new Error(response.data?.message || 'Request failed');
                                error.response = response;
                                reject(error);
                            }
                        },
                        error: (xhr, status, error) => {
                            if (status === 'abort') {
                                reject(new Error('Request cancelled'));
                            } else {
                                const msg = xhr.responseJSON?.data?.message || error || 'Network error';
                                reject(new Error(msg));
                            }
                        },
                        complete: () => {
                            // Remove from pending requests
                            if (requestId) {
                                this.pendingRequests.delete(requestId);
                            }

                            // Hide loading
                            if (showLoading && loadingContainer) {
                                SPPPortal.ui.hideLoading(loadingContainer);
                            }
                        }
                    });

                    // Track request for potential cancellation
                    if (requestId) {
                        this.pendingRequests.set(requestId, xhr);
                    }
                });
            },

            /**
             * Make a GET-style request (data sent as query params conceptually).
             *
             * @async
             * @param {string} action - WordPress AJAX action name.
             * @param {Object} [params={}] - Parameters to send.
             * @param {Object} [options={}] - Additional options.
             * @returns {Promise<Object>} Response data.
             */
            get: async function (action, params = {}, options = {}) {
                return this.request(action, params, { ...options, method: 'GET' });
            },

            /**
             * Make a POST request.
             *
             * @async
             * @param {string} action - WordPress AJAX action name.
             * @param {Object} [data={}] - Data to send.
             * @param {Object} [options={}] - Additional options.
             * @returns {Promise<Object>} Response data.
             */
            post: async function (action, data = {}, options = {}) {
                return this.request(action, data, { ...options, method: 'POST' });
            },

            /**
             * Cancel a pending request by ID.
             *
             * @param {string} requestId - Request ID to cancel.
             * @returns {boolean} Whether a request was cancelled.
             */
            cancel: function (requestId) {
                if (this.pendingRequests.has(requestId)) {
                    this.pendingRequests.get(requestId).abort();
                    this.pendingRequests.delete(requestId);
                    return true;
                }
                return false;
            },

            /**
             * Cancel all pending requests.
             *
             * @returns {void}
             */
            cancelAll: function () {
                this.pendingRequests.forEach((xhr) => xhr.abort());
                this.pendingRequests.clear();
            }
        },

        // ============================================================
        // UI - User interface helpers
        // ============================================================

        /**
         * UI module for interface helpers.
         *
         * @namespace SPPPortal.ui
         */
        ui: {
            /**
             * Currently active modal element.
             *
             * @type {jQuery|null}
             */
            activeModal: null,

            /**
             * Show a notification message to the user.
             *
             * @param {string} message - Message to display.
             * @param {string} [type='info'] - Notification type: 'success', 'error', 'warning', 'info'.
             * @param {Object} [options={}] - Additional options.
             * @param {number} [options.timeout] - Auto-dismiss timeout in ms (0 to disable).
             * @param {boolean} [options.dismissible=true] - Whether notification can be dismissed.
             * @param {string} [options.container] - Container selector to prepend notification to.
             * @returns {jQuery} The notification element.
             */
            showNotification: function (message, type = 'info', options = {}) {
                const {
                    timeout = type === 'success'
                        ? SPPPortal.config.notificationSuccessTimeout
                        : SPPPortal.config.notificationErrorTimeout,
                    dismissible = true,
                    container = null
                } = options;

                // Limit stacked notifications to 3; remove oldest if at limit.
                const $existing = $('.spp-notification');
                if ($existing.length >= 3) {
                    this.hideNotification($existing.first());
                }

                // Create notification element
                const $notification = $(`
                    <div class="spp-notification spp-notification-${type}" role="alert" aria-live="polite">
                        <span class="spp-notification-icon"></span>
                        <span class="spp-notification-message">${this.escapeHtml(message)}</span>
                        ${dismissible ? '<button type="button" class="spp-notification-close" aria-label="Dismiss">&times;</button>' : ''}
                    </div>
                `);

                // Add close button handler
                if (dismissible) {
                    $notification.find('.spp-notification-close').on('click', () => {
                        this.hideNotification($notification);
                    });
                }

                // Determine container
                const $container = container
                    ? $(container)
                    : $(SPPPortal.config.contentSelector).length
                        ? $(SPPPortal.config.contentSelector)
                        : $(SPPPortal.config.portalSelector).length
                            ? $(SPPPortal.config.portalSelector)
                            : $('body');

                $container.prepend($notification);

                // Trigger animation
                requestAnimationFrame(() => {
                    $notification.addClass('spp-notification-visible');
                });

                // Auto-dismiss
                if (timeout > 0) {
                    setTimeout(() => {
                        this.hideNotification($notification);
                    }, timeout);
                }

                return $notification;
            },

            /**
             * Hide a notification element.
             *
             * @param {jQuery} $notification - Notification element to hide.
             * @returns {void}
             */
            hideNotification: function ($notification) {
                $notification.removeClass('spp-notification-visible');
                setTimeout(() => {
                    $notification.remove();
                }, 300);
            },

            /**
             * Show a modal dialog.
             *
             * @param {string|jQuery} content - Modal content (HTML string or jQuery element).
             * @param {Object} [options={}] - Modal options.
             * @param {string} [options.title] - Modal title.
             * @param {string} [options.size='medium'] - Modal size: 'small', 'medium', 'large', 'full'.
             * @param {boolean} [options.closeable=true] - Whether modal can be closed.
             * @param {boolean} [options.closeOnOverlay=true] - Close when clicking overlay.
             * @param {Function} [options.onClose] - Callback when modal closes.
             * @param {string} [options.confirmText] - Confirm button text.
             * @param {string} [options.cancelText] - Cancel button text.
             * @param {Function} [options.onConfirm] - Confirm button callback.
             * @param {Function} [options.onCancel] - Cancel button callback.
             * @returns {jQuery} The modal element.
             */
            showModal: function (content, options = {}) {
                const {
                    title = '',
                    size = 'medium',
                    closeable = true,
                    closeOnOverlay = true,
                    onClose = null,
                    confirmText = null,
                    cancelText = null,
                    onConfirm = null,
                    onCancel = null
                } = options;

                // Close any existing modal
                this.hideModal();

                // Build footer buttons if needed
                let footerHtml = '';
                if (confirmText || cancelText) {
                    footerHtml = '<div class="spp-modal-footer">';
                    if (cancelText) {
                        footerHtml += `<button type="button" class="spp-btn spp-btn-secondary spp-modal-cancel">${this.escapeHtml(cancelText)}</button>`;
                    }
                    if (confirmText) {
                        footerHtml += `<button type="button" class="spp-btn spp-btn-primary spp-modal-confirm">${this.escapeHtml(confirmText)}</button>`;
                    }
                    footerHtml += '</div>';
                }

                // Create modal element
                const $modal = $(`
                    <div class="spp-modal spp-modal-${size}" role="dialog" aria-modal="true" aria-labelledby="spp-modal-title">
                        <div class="spp-modal-overlay"></div>
                        <div class="spp-modal-container">
                            ${title ? `<div class="spp-modal-header">
                                <h2 id="spp-modal-title" class="spp-modal-title">${this.escapeHtml(title)}</h2>
                                ${closeable ? '<button type="button" class="spp-modal-close" aria-label="Close">&times;</button>' : ''}
                            </div>` : (closeable ? '<button type="button" class="spp-modal-close spp-modal-close-float" aria-label="Close">&times;</button>' : '')}
                            <div class="spp-modal-content"></div>
                            ${footerHtml}
                        </div>
                    </div>
                `);

                // Set content
                const $content = $modal.find('.spp-modal-content');
                if (typeof content === 'string') {
                    $content.html(content);
                } else {
                    $content.append(content);
                }

                // Event handlers
                if (closeable) {
                    $modal.find('.spp-modal-close').on('click', () => {
                        this.hideModal();
                        if (onClose) onClose();
                    });
                }

                if (closeOnOverlay) {
                    $modal.find('.spp-modal-overlay').on('click', () => {
                        this.hideModal();
                        if (onClose) onClose();
                    });
                }

                if (confirmText && onConfirm) {
                    $modal.find('.spp-modal-confirm').on('click', () => {
                        onConfirm();
                    });
                }

                if (cancelText) {
                    $modal.find('.spp-modal-cancel').on('click', () => {
                        this.hideModal();
                        if (onCancel) onCancel();
                        else if (onClose) onClose();
                    });
                }

                // Store the element that triggered the modal for focus restoration.
                this._modalTrigger = document.activeElement;

                // Handle escape key and focus trapping.
                const handleKeydown = (e) => {
                    if (e.key === 'Escape' && closeable) {
                        this.hideModal();
                        if (onClose) onClose();
                        document.removeEventListener('keydown', handleKeydown);
                    }
                    // Focus trap: keep Tab within modal.
                    if (e.key === 'Tab') {
                        const $container = $modal.find('.spp-modal-container');
                        const focusable = $container.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
                        if (focusable.length === 0) return;
                        const first = focusable.first()[0];
                        const last = focusable.last()[0];
                        if (e.shiftKey) {
                            if (document.activeElement === first) {
                                e.preventDefault();
                                last.focus();
                            }
                        } else {
                            if (document.activeElement === last) {
                                e.preventDefault();
                                first.focus();
                            }
                        }
                    }
                };
                document.addEventListener('keydown', handleKeydown);
                $modal.data('_keydownHandler', handleKeydown);

                // Add to DOM and show
                $('body').append($modal);
                this.activeModal = $modal;

                // Prevent body scroll
                $('body').addClass('spp-modal-open');

                // Trigger animation
                requestAnimationFrame(() => {
                    $modal.addClass('spp-modal-visible');
                });

                // Focus management
                $modal.find('.spp-modal-container').attr('tabindex', '-1').focus();

                return $modal;
            },

            /**
             * Hide the current modal.
             *
             * @returns {void}
             */
            hideModal: function () {
                if (!this.activeModal) {
                    return;
                }

                const $modal = this.activeModal;
                $modal.removeClass('spp-modal-visible');

                // Remove keydown handler.
                const handler = $modal.data('_keydownHandler');
                if (handler) {
                    document.removeEventListener('keydown', handler);
                }

                setTimeout(() => {
                    $modal.remove();
                    $('body').removeClass('spp-modal-open');
                }, 300);

                this.activeModal = null;

                // Restore focus to the element that triggered the modal.
                if (this._modalTrigger && typeof this._modalTrigger.focus === 'function') {
                    this._modalTrigger.focus();
                    this._modalTrigger = null;
                }
            },

            /**
             * Show a confirmation dialog.
             *
             * @param {string} message - Confirmation message.
             * @param {Object} [options={}] - Options.
             * @param {string} [options.title='Confirm'] - Dialog title.
             * @param {string} [options.confirmText='Confirm'] - Confirm button text.
             * @param {string} [options.cancelText='Cancel'] - Cancel button text.
             * @param {string} [options.type='warning'] - Dialog type for styling.
             * @returns {Promise<boolean>} Resolves to true if confirmed, false if cancelled.
             */
            confirm: function (message, options = {}) {
                return new Promise((resolve) => {
                    const {
                        title = 'Confirm',
                        confirmText = 'Confirm',
                        cancelText = 'Cancel',
                        type = 'warning'
                    } = options;

                    this.showModal(`<p class="spp-confirm-message spp-confirm-${type}">${this.escapeHtml(message)}</p>`, {
                        title,
                        size: 'small',
                        confirmText,
                        cancelText,
                        onConfirm: () => {
                            this.hideModal();
                            resolve(true);
                        },
                        onCancel: () => {
                            resolve(false);
                        },
                        onClose: () => {
                            resolve(false);
                        }
                    });
                });
            },

            /**
             * Show loading skeleton in a container.
             *
             * @param {string|jQuery} container - Container selector or element.
             * @param {Object} [options={}] - Loading options.
             * @param {string} [options.type='skeleton'] - Loading type: 'skeleton', 'spinner', 'overlay'.
             * @param {number} [options.rows=3] - Number of skeleton rows.
             * @param {string} [options.text='Loading...'] - Loading text for spinner.
             * @returns {void}
             */
            showLoading: function (container, options = {}) {
                const {
                    type = 'skeleton',
                    rows = 3,
                    text = 'Loading...'
                } = options;

                const $container = $(container);

                // Add loading class to container
                $container.addClass('spp-is-loading spp-loading-container-relative');

                // If type is overlay, don't hide contents, just overlay the spinner
                if (type === 'overlay') {
                    $container.addClass('spp-loading-overlay-active');
                    $container.append(`
                        <div class="spp-loading spp-loading-overlay">
                            <div class="spp-spinner"></div>
                        </div>
                    `);
                    return;
                }

                // For skeleton or spinner, we visually hide existing children
                $container.children(':not(.spp-loading)').attr('data-spp-loading-hidden', 'true').hide();

                let loadingHtml = '';

                switch (type) {
                    case 'spinner':
                        loadingHtml = `
                            <div class="spp-loading spp-loading-spinner" style="padding: 40px; text-align: center;">
                                <div class="spp-spinner"></div>
                                <span class="spp-loading-text">${this.escapeHtml(text)}</span>
                            </div>
                        `;
                        break;
                    case 'skeleton':
                    default:
                        let skeletonCards = '';
                        const cardCount = Math.max(rows, 3);
                        for (let i = 0; i < cardCount; i++) {
                            skeletonCards += `
                                <div class="spp-skeleton-card">
                                    <div class="spp-skeleton-row">
                                        <div class="spp-skeleton-line" style="width: ${40 + Math.random() * 30}%; height: 18px;"></div>
                                        <div class="spp-skeleton-line" style="width: 60px; height: 22px; border-radius: 12px;"></div>
                                    </div>
                                    <div class="spp-skeleton-line" style="width: ${30 + Math.random() * 20}%; height: 12px; margin-top: 6px;"></div>
                                    <div class="spp-skeleton-divider"></div>
                                    <div class="spp-skeleton-row">
                                        <div class="spp-skeleton-line" style="width: ${20 + Math.random() * 15}%; height: 12px;"></div>
                                        <div class="spp-skeleton-line" style="width: ${20 + Math.random() * 15}%; height: 12px;"></div>
                                    </div>
                                </div>`;
                        }
                        loadingHtml = `
                            <div class="spp-loading spp-loading-skeleton">
                                <div class="spp-skeleton-line spp-skeleton-title" style="width: 180px; height: 24px; margin-bottom: 24px;"></div>
                                ${skeletonCards}
                            </div>
                        `;
                        break;
                }

                if ($container.find('.spp-loading').length === 0) {
                    $container.append(loadingHtml);
                }
            },

            /**
             * Hide loading state from a container.
             *
             * @param {string|jQuery} container - Container selector or element.
             * @param {string} [newContent] - Optional new content to display.
             * @returns {void}
             */
            hideLoading: function (container, newContent = null) {
                const $container = $(container);

                $container.removeClass('spp-is-loading spp-loading-overlay-active spp-loading-container-relative');
                $container.find('.spp-loading').remove();

                if (newContent !== null) {
                    $container.html(newContent);
                } else {
                    $container.children('[data-spp-loading-hidden]').removeAttr('data-spp-loading-hidden').show();
                }
            },

            /**
             * Format a currency amount.
             *
             * @param {number} amount - Amount in smallest currency unit (e.g., cents).
             * @param {string} [currency='USD'] - ISO currency code.
             * @param {Object} [options={}] - Formatting options.
             * @param {boolean} [options.showSymbol=true] - Whether to show currency symbol.
             * @param {boolean} [options.inDollars=false] - Whether amount is already in dollars (not cents).
             * @returns {string} Formatted currency string.
             */
            formatCurrency: function (amount, currency = null, options = {}) {
                const {
                    showSymbol = true,
                    inDollars = false
                } = options;

                currency = currency || SPPPortal.config.defaultCurrency;

                // Convert from cents to dollars if needed
                const value = inDollars ? amount : amount / 100;

                try {
                    const formatter = new Intl.NumberFormat(SPPPortal.config.dateLocale, {
                        style: showSymbol ? 'currency' : 'decimal',
                        currency: currency,
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    return formatter.format(value);
                } catch (e) {
                    // Fallback for unsupported currencies
                    return showSymbol ? `${currency} ${value.toFixed(2)}` : value.toFixed(2);
                }
            },

            /**
             * Format a date string.
             *
             * @param {string|Date} date - Date to format.
             * @param {Object} [options={}] - Formatting options.
             * @param {string} [options.format='medium'] - Format preset: 'short', 'medium', 'long', 'relative'.
             * @param {boolean} [options.includeTime=false] - Whether to include time.
             * @returns {string} Formatted date string.
             */
            formatDate: function (date, options = {}) {
                const {
                    format = 'medium',
                    includeTime = false
                } = options;

                const dateObj = date instanceof Date ? date : new Date(date);

                if (isNaN(dateObj.getTime())) {
                    return 'Invalid date';
                }

                // Relative time formatting
                if (format === 'relative') {
                    return this.getRelativeTime(dateObj);
                }

                // Standard date formats
                const formatOptions = {
                    short: { month: 'numeric', day: 'numeric', year: '2-digit' },
                    medium: { month: 'short', day: 'numeric', year: 'numeric' },
                    long: { month: 'long', day: 'numeric', year: 'numeric', weekday: 'long' }
                };

                const dateFormatOptions = formatOptions[format] || formatOptions.medium;

                if (includeTime) {
                    dateFormatOptions.hour = 'numeric';
                    dateFormatOptions.minute = '2-digit';
                }

                try {
                    return new Intl.DateTimeFormat(SPPPortal.config.dateLocale, dateFormatOptions).format(dateObj);
                } catch (e) {
                    return dateObj.toLocaleDateString();
                }
            },

            /**
             * Get relative time string (e.g., "2 hours ago").
             *
             * @param {Date} date - Date to compare.
             * @returns {string} Relative time string.
             */
            getRelativeTime: function (date) {
                const now = new Date();
                const diffMs = now - date;
                const diffSec = Math.floor(diffMs / 1000);
                const diffMin = Math.floor(diffSec / 60);
                const diffHour = Math.floor(diffMin / 60);
                const diffDay = Math.floor(diffHour / 24);

                if (diffSec < 60) {
                    return 'just now';
                } else if (diffMin < 60) {
                    return `${diffMin} minute${diffMin !== 1 ? 's' : ''} ago`;
                } else if (diffHour < 24) {
                    return `${diffHour} hour${diffHour !== 1 ? 's' : ''} ago`;
                } else if (diffDay < 7) {
                    return `${diffDay} day${diffDay !== 1 ? 's' : ''} ago`;
                } else {
                    return this.formatDate(date, { format: 'medium' });
                }
            },

            /**
             * Escape HTML entities to prevent XSS.
             *
             * @param {string} str - String to escape.
             * @returns {string} Escaped string.
             */
            escapeHtml: function (str) {
                if (typeof str !== 'string') {
                    return str;
                }
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            },

            /**
             * Set button to loading state.
             *
             * @param {string|jQuery} button - Button selector or element.
             * @param {boolean} [loading=true] - Whether to show loading state.
             * @param {string} [loadingText='Processing...'] - Text to show while loading.
             * @returns {void}
             */
            setButtonLoading: function (button, loading = true, loadingText = 'Processing...') {
                const $button = $(button);

                if (loading) {
                    $button.data('spp-original-text', $button.text());
                    $button.prop('disabled', true).text(loadingText);
                    $button.addClass('spp-btn-loading');
                } else {
                    const originalText = $button.data('spp-original-text');
                    if (originalText) {
                        $button.text(originalText);
                    }
                    $button.prop('disabled', false);
                    $button.removeClass('spp-btn-loading');
                }
            }
        },

        // ============================================================
        // COMPONENTS - View and component loading
        // ============================================================

        /**
         * Components module for loading views and components.
         *
         * @namespace SPPPortal.components
         */
        components: {
            /**
             * Cache for loaded components.
             *
             * @type {Map}
             */
            cache: new Map(),

            /**
             * Load a view via AJAX.
             *
             * @async
             * @param {string} viewName - Name of the view to load.
             * @param {Object} [params={}] - Parameters to pass to the view.
             * @param {Object} [options={}] - Loading options.
             * @param {string} [options.container] - Container selector to load into.
             * @param {boolean} [options.cache=false] - Whether to cache the response.
             * @param {boolean} [options.append=false] - Append instead of replace.
             * @returns {Promise<void>}
             */
            loadView: async function (viewName, params = {}, options = {}) {
                // Warn about unsaved profile changes.
                if ($('.spp-profile-form[data-dirty="true"]').length) {
                    if (!confirm('You have unsaved profile changes. Leave without saving?')) {
                        return;
                    }
                    $('.spp-profile-form').removeAttr('data-dirty');
                }

                const {
                    container = SPPPortal.config.contentSelector,
                    cache = false,
                    append = false
                } = options;

                const $container = $(container);

                if (!$container.length) {
                    sppPublic && sppPublic.debug && console.error('SPPPortal: Content container not found:', container);
                    return;
                }

                // Check cache
                const cacheKey = `view:${viewName}:${JSON.stringify(params)}`;
                if (cache && this.cache.has(cacheKey)) {
                    this.renderContent($container, this.cache.get(cacheKey), append);
                    return;
                }

                // Show loading
                SPPPortal.ui.showLoading($container);

                try {
                    const response = await SPPPortal.api.post('spp_load_view', {
                        view: viewName,
                        params: JSON.stringify(params)
                    });

                    // Cache if requested
                    if (cache) {
                        this.cache.set(cacheKey, response.html);
                    }

                    // Render content
                    this.renderContent($container, response.html, append);

                    // Update active navigation
                    this.updateActiveNav(viewName);

                    // Update document title
                    const viewTitles = {
                        'dashboard': 'Dashboard',
                        'payment-methods': 'Payment Methods',
                        'add-card': 'Add Card',
                        'subscriptions': 'Subscriptions',
                        'invoices': 'Invoices',
                        'transactions': 'Transactions',
                        'bookings': 'Appointments',
                        'loyalty': 'Loyalty & Rewards',
                        'profile': 'Profile'
                    };
                    const portalTitle = (typeof sppPublic !== 'undefined' && sppPublic.portalTitle) ? sppPublic.portalTitle : 'Client Portal';
                    const viewTitle = viewTitles[viewName] || viewName;
                    document.title = viewTitle + ' — ' + portalTitle;

                    // Dispatch view loaded event
                    document.dispatchEvent(new CustomEvent('spp:view:loaded', {
                        detail: { view: viewName, params }
                    }));

                    // Execute any registered route handler
                    if (SPPPortal.router.routes[viewName]) {
                        SPPPortal.router.routes[viewName](params);
                    }

                } catch (error) {
                    sppPublic && sppPublic.debug && console.error('SPPPortal: Failed to load view:', error);
                    SPPPortal.ui.hideLoading($container, `
                        <div class="spp-error-state">
                            <p>Failed to load content. Please try again.</p>
                            <button type="button" class="spp-btn spp-btn-secondary" onclick="SPPPortal.components.loadView('${SPPPortal.ui.escapeHtml(viewName)}', ${SPPPortal.ui.escapeHtml(JSON.stringify(params))})">
                                Retry
                            </button>
                        </div>
                    `);
                }
            },

            /**
             * Render content into a container.
             *
             * @param {jQuery} $container - Container element.
             * @param {string} html - HTML content.
             * @param {boolean} [append=false] - Append instead of replace.
             * @returns {void}
             */
            renderContent: function ($container, html, append = false) {
                $container.removeClass('spp-is-loading');

                if (append) {
                    $container.append(html);
                } else {
                    $container.html(html);
                }

                // Trigger content ready for any scripts that need to initialize
                $container.trigger('spp:content:ready');
            },

            /**
             * Refresh a specific component by ID.
             *
             * @async
             * @param {string} componentId - Component ID to refresh.
             * @param {Object} [params={}] - Parameters to pass.
             * @returns {Promise<void>}
             */
            refresh: async function (componentId, params = {}) {
                const $component = $(`#${componentId}, [data-spp-component="${componentId}"]`);

                if (!$component.length) {
                    sppPublic && sppPublic.debug && console.error('SPPPortal: Component not found:', componentId);
                    return;
                }

                // Find a widget body container, or fall back to the whole component
                let $body = $component.find('.spp-portal__widget-body');
                if (!$body.length) {
                    // After initial load, the skeleton was replaced with content.
                    // Wrap existing non-header content for future refreshes.
                    const $header = $component.children('.spp-flex').first();
                    const $rest = $header.length ? $header.nextAll() : $component.children();
                    if ($header.length && $rest.length) {
                        $rest.wrapAll('<div class="spp-portal__widget-body"></div>');
                        $body = $component.find('.spp-portal__widget-body');
                    } else {
                        $body = $component;
                    }
                }

                SPPPortal.ui.showLoading($component, { type: 'overlay' });

                try {
                    const response = await SPPPortal.api.post('spp_refresh_component', {
                        component: componentId,
                        params: JSON.stringify(params)
                    });

                    SPPPortal.ui.hideLoading($component);
                    $body.html(response.html);

                    // Dispatch component refreshed event
                    document.dispatchEvent(new CustomEvent('spp:component:refreshed', {
                        detail: { component: componentId, params }
                    }));

                } catch (error) {
                    sppPublic && sppPublic.debug && console.error('SPPPortal: Failed to refresh component:', error);
                    SPPPortal.ui.hideLoading($component);
                    SPPPortal.ui.showNotification('Failed to refresh content.', 'error');
                }
            },

            /**
             * Update active state in navigation.
             *
             * @param {string} viewName - Current view name.
             * @returns {void}
             */
            updateActiveNav: function (viewName) {
                // Remove active state from all nav items
                $('.spp-portal-nav__item').removeClass('spp-portal-nav__item--active');
                $('.spp-portal-nav__link').removeAttr('aria-current');

                // Add active state to matching nav item
                const $activeLink = $(`.spp-portal-nav__link[data-spp-view="${viewName}"]`);
                $activeLink.closest('.spp-portal-nav__item').addClass('spp-portal-nav__item--active');
                $activeLink.attr('aria-current', 'page');

                // Update mobile nav bar label
                const label = $activeLink.find('.spp-portal-nav__label').text();
                if (label) {
                    $('#spp-nav-toggle-label').text(label);
                }
            },

            /**
             * Clear component cache.
             *
             * @param {string} [pattern] - Optional pattern to match cache keys.
             * @returns {void}
             */
            clearCache: function (pattern = null) {
                if (pattern) {
                    for (const key of this.cache.keys()) {
                        if (key.includes(pattern)) {
                            this.cache.delete(key);
                        }
                    }
                } else {
                    this.cache.clear();
                }
            }
        },

        // ============================================================
        // PAGINATION - Client-side list pagination
        // ============================================================

        /**
         * Pagination module for list views.
         *
         * @namespace SPPPortal.pagination
         */
        pagination: {
            /**
             * Apply pagination to a list container.
             *
             * @param {string} containerSel - Container selector.
             * @param {string} itemSel - Item selector within container.
             */
            apply: function (containerSel, itemSel) {
                const $container = $(containerSel);
                if (!$container.length) return;

                const perPage = (typeof sppPublic !== 'undefined' && sppPublic.itemsPerPage) ? parseInt(sppPublic.itemsPerPage) : 25;
                const $allItems = $container.find(itemSel);

                // Mark items that the filter hid (display: none means filtered out).
                $allItems.each(function () {
                    $(this).data('spp-filtered-out', $(this).css('display') === 'none');
                });

                // Count non-filtered items.
                const $visible = $allItems.filter(function () {
                    return !$(this).data('spp-filtered-out');
                });

                // Remove any existing pagination bar.
                $container.siblings('.spp-pagination-bar').remove();

                const totalVisible = $visible.length;
                if (totalVisible <= perPage) {
                    // Show all non-filtered, no pagination needed.
                    $visible.show();
                    return;
                }

                const totalPages = Math.ceil(totalVisible / perPage);

                // Store data on the container for goToPage.
                $container.data('spp-page', 1);
                $container.data('spp-per-page', perPage);
                $container.data('spp-total-pages', totalPages);
                $container.data('spp-item-sel', itemSel);

                // Show first page.
                this.showPage($container, 1, perPage, itemSel);

                // Build pagination bar.
                const $bar = $('<div class="spp-pagination-bar" style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: var(--spp-space-5); padding: var(--spp-space-3) 0;"></div>');

                const $prev = $('<button type="button" class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-pagination-prev" disabled>&larr; Prev</button>');
                const $next = $('<button type="button" class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-pagination-next">Next &rarr;</button>');
                const $info = $('<span class="spp-portal__text--muted spp-portal__text--sm spp-pagination-info">Page 1 of ' + totalPages + '</span>');

                $prev.on('click', function () {
                    SPPPortal.pagination.goToPage($container, -1);
                });
                $next.on('click', function () {
                    SPPPortal.pagination.goToPage($container, 1);
                });

                if (totalPages <= 1) $next.prop('disabled', true);

                $bar.append($prev, $info, $next);
                $container.after($bar);
            },

            /**
             * Navigate to a page relative to current.
             *
             * @param {jQuery} $container - The list container.
             * @param {number} delta - Page direction (+1 or -1).
             */
            goToPage: function ($container, delta) {
                let page = ($container.data('spp-page') || 1) + delta;
                const totalPages = $container.data('spp-total-pages') || 1;
                const perPage = $container.data('spp-per-page') || 25;
                const itemSel = $container.data('spp-item-sel');

                page = Math.max(1, Math.min(page, totalPages));
                $container.data('spp-page', page);

                this.showPage($container, page, perPage, itemSel);

                // Update bar.
                const $bar = $container.siblings('.spp-pagination-bar');
                $bar.find('.spp-pagination-info').text('Page ' + page + ' of ' + totalPages);
                $bar.find('.spp-pagination-prev').prop('disabled', page <= 1);
                $bar.find('.spp-pagination-next').prop('disabled', page >= totalPages);

                // Scroll to top of container.
                $container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            },

            /**
             * Show items for a given page number.
             *
             * @param {jQuery} $container - The list container.
             * @param {number} page - 1-indexed page number.
             * @param {number} perPage - Items per page.
             * @param {string} itemSel - Item selector.
             */
            showPage: function ($container, page, perPage, itemSel) {
                const $allItems = $container.find(itemSel);
                let visibleIdx = 0;
                const startIdx = (page - 1) * perPage;
                const endIdx = startIdx + perPage;

                $allItems.each(function () {
                    const $item = $(this);
                    // Skip filtered-out items — keep them hidden.
                    if ($item.data('spp-filtered-out')) {
                        $item.hide();
                        return;
                    }
                    // Paginate non-filtered items.
                    if (visibleIdx >= startIdx && visibleIdx < endIdx) {
                        $item.show();
                    } else {
                        $item.hide();
                    }
                    visibleIdx++;
                });
            }
        },

        // ============================================================
        // IDLE TIMER - Session timeout and auto-logout
        // ============================================================

        /**
         * Idle timer module for auto-logout after inactivity.
         *
         * @namespace SPPPortal.idleTimer
         */
        idleTimer: {
            /** @type {number|null} Main timeout ID. */
            _timeoutId: null,
            /** @type {number|null} Warning timeout ID. */
            _warningId: null,
            /** @type {number} Timeout duration in ms. */
            _timeoutMs: 0,
            /** @type {number} Warning shown N seconds before logout. */
            _warningBeforeMs: 60000,

            /**
             * Start the idle timer.
             */
            start: function () {
                const minutes = (typeof sppPublic !== 'undefined' && sppPublic.sessionTimeout !== undefined) ? parseInt(sppPublic.sessionTimeout) : 30;
                if (!minutes || minutes <= 0) return; // Disabled.

                this._timeoutMs = minutes * 60 * 1000;

                // Listen for user activity.
                const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
                const self = this;
                const resetHandler = function () {
                    self.reset();
                };

                events.forEach(function (evt) {
                    document.addEventListener(evt, resetHandler, { passive: true });
                });

                // Start the countdown.
                this.reset();
            },

            /**
             * Reset the idle countdown.
             */
            reset: function () {
                if (!this._timeoutMs) return;

                // Clear existing timers.
                if (this._timeoutId) clearTimeout(this._timeoutId);
                if (this._warningId) clearTimeout(this._warningId);

                // Dismiss any active warning modal.
                if (this._warningShown) {
                    SPPPortal.ui.hideModal();
                    this._warningShown = false;
                }

                const self = this;

                // Set warning timer (fires N seconds before logout).
                const warningDelay = Math.max(0, this._timeoutMs - this._warningBeforeMs);
                this._warningId = setTimeout(function () {
                    self.showWarning();
                }, warningDelay);

                // Set final logout timer.
                this._timeoutId = setTimeout(function () {
                    self.logout();
                }, this._timeoutMs);
            },

            /**
             * Show a warning modal before auto-logout.
             */
            showWarning: function () {
                this._warningShown = true;
                const self = this;
                const secondsLeft = Math.round(this._warningBeforeMs / 1000);

                SPPPortal.ui.showModal(
                    '<div style="text-align: center; padding: var(--spp-space-4) 0;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--spp-warning, #f59e0b)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;">' +
                    '<circle cx="12" cy="12" r="10"></circle>' +
                    '<line x1="12" y1="8" x2="12" y2="12"></line>' +
                    '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
                    '</svg>' +
                    '<p style="font-size: var(--spp-text-base); margin: 0 0 8px;">Your session is about to expire due to inactivity.</p>' +
                    '<p class="spp-portal__text--muted" style="font-size: var(--spp-text-sm); margin: 0;">You will be logged out in approximately <strong>' + secondsLeft + ' seconds</strong>.</p>' +
                    '</div>',
                    {
                        title: 'Session Expiring',
                        size: 'small',
                        confirmText: 'Stay Logged In',
                        cancelText: 'Log Out Now',
                        closeable: false,
                        onConfirm: function () {
                            SPPPortal.ui.hideModal();
                            self._warningShown = false;
                            self.reset();
                        },
                        onCancel: function () {
                            self.logout();
                        }
                    }
                );
            },

            /**
             * Perform logout.
             */
            logout: function () {
                const logoutUrl = (typeof sppPublic !== 'undefined' && sppPublic.logoutUrl) ? sppPublic.logoutUrl : '/';
                window.location.href = logoutUrl;
            }
        },

        // ============================================================
        // SQUARE SDK INTEGRATION - Card tokenization and payments
        // ============================================================

        /**
         * Initialize Square Web Payments SDK.
         *
         * @async
         * @returns {Promise<void>}
         */
        initSquare: async function () {
            if (typeof Square === 'undefined') {
                sppPublic && sppPublic.debug && console.warn('Square Web SDK not loaded - payment features disabled.');
                this.showSquareLoadError('The Square payment SDK could not be loaded. Please refresh the page or try again later.');
                return;
            }

            if (!sppPublic.applicationId || !sppPublic.locationId) {
                sppPublic && sppPublic.debug && console.warn('Square credentials not configured.');
                this.showSquareLoadError('Payment processing is not configured. Please contact the site administrator.');
                return;
            }

            try {
                this.payments = Square.payments(sppPublic.applicationId, sppPublic.locationId);

                // Initialize card form if container exists
                if ($('#spp-card-container').length) {
                    await this.initCardForm();
                }
            } catch (error) {
                sppPublic && sppPublic.debug && console.error('Failed to initialize Square:', error);
                const detail = error.message || 'Unknown error';
                this.showSquareLoadError('Failed to initialize payment form. ' + detail);
                this.ui.showNotification('Failed to initialize payment form: ' + detail, 'error', { timeout: 0 });
            }
        },

        /**
         * Show error when Square SDK fails to load.
         *
         * @returns {void}
         */
        showSquareLoadError: function (message) {
            const $container = $('#spp-card-container');
            if ($container.length) {
                const displayMsg = this.ui.escapeHtml(message || 'Payment form could not be loaded.');
                $container.html(`
                    <div style="padding: 16px; background: var(--spp-error-bg, #fef2f2); border: 1px solid var(--spp-error, #ef4444); border-radius: var(--spp-radius-md, 8px); color: var(--spp-error, #ef4444); font-size: 14px;">
                        <p style="margin: 0 0 4px; font-weight: 600;">Payment form could not be loaded</p>
                        <p style="margin: 0; opacity: 0.85;">${displayMsg}</p>
                    </div>
                `);
            }
        },

        /**
         * Initialize card form.
         *
         * @async
         * @returns {Promise<void>}
         */
        initCardForm: async function () {
            try {
                this.card = await this.payments.card();
                await this.card.attach('#spp-card-container');

                // Show container now that card is loaded
                $('#spp-card-container').css('opacity', '1');
            } catch (error) {
                sppPublic && sppPublic.debug && console.error('Failed to initialize card form:', error);
                this.ui.showNotification('Failed to load card input. Please refresh the page.', 'error');
            }
        },

        /**
         * Bind event handlers.
         *
         * @returns {void}
         */
        bindEvents: function () {
            // Add card form submission
            $(document).on('submit', '#spp-add-card-form', this.handleAddCard.bind(this));

            // Payment form submission
            $(document).on('submit', '#spp-payment-form', this.handlePayment.bind(this));

            // Delete card button
            $(document).on('click', '.spp-delete-card', this.handleDeleteCard.bind(this));

            // Subscription actions
            $(document).on('click', '.spp-pause-subscription', this.handlePauseSubscription.bind(this));
            $(document).on('click', '.spp-resume-subscription', this.handleResumeSubscription.bind(this));
            $(document).on('click', '.spp-cancel-subscription', this.handleCancelSubscription.bind(this));
            $(document).on('click', '.spp-cancel-booking', this.handleCancelBooking.bind(this));

            // WooCommerce order actions
            $(document).on('click', '.spp-cancel-wc-order', this.handleCancelWcOrder.bind(this));
            $(document).on('click', '.spp-reorder-wc-order', this.handleReorderWcOrder.bind(this));

            // Gift card actions
            $(document).on('click', '#spp-buy-gift-card-btn', this.handleBuyGiftCard.bind(this));
            $(document).on('click', '.spp-reload-gift-card', this.handleReloadGiftCard.bind(this));

            // Loyalty reward redemption
            $(document).on('click', '.spp-redeem-reward', this.handleRedeemReward.bind(this));

            // Profile save
            $(document).on('submit', '.spp-profile-form', this.handleSaveProfile.bind(this));

            // Track unsaved profile changes.
            $(document).on('input change', '.spp-profile-form input, .spp-profile-form select', function () {
                $(this).closest('.spp-profile-form').attr('data-dirty', 'true');
            });
            $(document).on('submit', '.spp-profile-form', function () {
                $(this).removeAttr('data-dirty');
            });

            // Avatar upload/remove
            $(document).on('click', '.spp-avatar-upload__preview', function () {
                $('#spp-avatar-input').trigger('click');
            });
            $(document).on('change', '#spp-avatar-input', this.handleAvatarUpload.bind(this));
            $(document).on('click', '#spp-avatar-remove', this.handleRemoveAvatar.bind(this));

            // Save notification preferences.
            $(document).on('click', '#spp-save-notification-prefs', (e) => {
                const $btn = $(e.currentTarget);
                const prefs = {};
                $('#spp-notification-prefs .spp-notification-toggle').each(function () {
                    prefs[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
                });

                SPPPortal.ui.setButtonLoading($btn, true, 'Saving...');

                SPPPortal.api.post('spp_save_notification_prefs', {
                    preferences: JSON.stringify(prefs)
                }).then(function (result) {
                    SPPPortal.ui.showNotification(result.message || 'Preferences saved.', 'success');
                    SPPPortal.ui.setButtonLoading($btn, false);
                }).catch(function (error) {
                    SPPPortal.ui.showNotification(error.message || 'Failed to save preferences.', 'error');
                    SPPPortal.ui.setButtonLoading($btn, false);
                });
            });

            // Save display preferences.
            $(document).on('click', '#spp-save-display-prefs', (e) => {
                const $btn = $(e.currentTarget);
                const data = {
                    date_format: $('#spp-pref-date-format').val(),
                    time_format: $('#spp-pref-time-format').val()
                };

                SPPPortal.ui.setButtonLoading($btn, true, 'Saving...');

                SPPPortal.api.post('spp_save_display_prefs', data).then(function (result) {
                    SPPPortal.ui.showNotification(result.message || 'Display preferences saved.', 'success');
                    SPPPortal.ui.setButtonLoading($btn, false);

                    // Reload the page so the new PHP formatting takes effect across the portal.
                    setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                }).catch(function (error) {
                    SPPPortal.ui.showNotification(error.message || 'Failed to save preferences.', 'error');
                    SPPPortal.ui.setButtonLoading($btn, false);
                });
            });

            // Phone number auto-formatting
            $(document).on('input', '.spp-phone-input', this.formatPhoneInput.bind(this));

            // Book appointment
            $(document).on('click', '#spp-book-appointment-btn', this.handleBookAppointment.bind(this));

            // Re-initialize Square card form when content changes
            $(document).on('spp:content:ready spp:view:loaded', async () => {
                // Guard against concurrent initializations from multiple events
                if (this._cardInitializing) {
                    return;
                }

                // Destroy previous card instance before creating a new one
                if (this.card) {
                    try { await this.card.destroy(); } catch (e) { /* ignore */ }
                    this.card = null;
                }
                if ($('#spp-card-container').length) {
                    this._cardInitializing = true;
                    try {
                        if (!this.payments) {
                            await this.initSquare();
                        } else {
                            await this.initCardForm();
                        }
                    } finally {
                        this._cardInitializing = false;
                    }
                }
            });

            // Mobile navigation helpers
            function openMobileNav() {
                $('#spp-portal-nav').addClass('spp-portal-nav--open');
                $('#spp-nav-overlay').addClass('spp-portal-nav__overlay--visible');
                $('#spp-nav-toggle').addClass('spp-portal-nav__toggle--open');
            }
            function closeMobileNav() {
                $('#spp-portal-nav').removeClass('spp-portal-nav--open');
                $('#spp-nav-overlay').removeClass('spp-portal-nav__overlay--visible');
                $('#spp-nav-toggle').removeClass('spp-portal-nav__toggle--open');
            }

            // Toggle drawer open/close
            $(document).on('click', '#spp-nav-toggle', function () {
                if ($('#spp-portal-nav').hasClass('spp-portal-nav--open')) {
                    closeMobileNav();
                } else {
                    openMobileNav();
                }
            });

            // Close via overlay
            $(document).on('click', '#spp-nav-overlay', function () {
                closeMobileNav();
            });

            // Close via X button
            $(document).on('click', '#spp-nav-close', function () {
                closeMobileNav();
            });

            // Handle data-spp-view clicks on non-link elements (quick action cards)
            $(document).on('click keypress', '.spp-portal__card--interactive[data-spp-view]', function (e) {
                if (e.type === 'keypress' && e.key !== 'Enter') return;
                e.preventDefault();
                const view = $(this).data('spp-view');
                const params = $(this).data('spp-params') || {};
                if (view) {
                    SPPPortal.router.navigate(view, params);
                }
            });

            // Subscription Filtering and Sorting
            $(document).on('change', '#spp-sub-filter-status, #spp-sub-sort', function () {
                const statusFilter = $('#spp-sub-filter-status').val();
                const sortBy = $('#spp-sub-sort').val();

                const $container = $('#spp-sub-list-container');
                if (!$container.length) return;

                const $cards = $container.find('.spp-sub-card').detach();

                // Show/Hide based on status
                $cards.each(function () {
                    const status = $(this).attr('data-status');
                    if (statusFilter === 'ALL' || status === statusFilter) {
                        $(this).css('display', '');
                    } else {
                        $(this).css('display', 'none');
                    }
                });

                // Sort
                $cards.sort(function (a, b) {
                    if (sortBy === 'newest') {
                        return parseInt($(b).attr('data-created') || 0) - parseInt($(a).attr('data-created') || 0);
                    } else if (sortBy === 'oldest') {
                        return parseInt($(a).attr('data-created') || 0) - parseInt($(b).attr('data-created') || 0);
                    } else if (sortBy === 'next_billing') {
                        // Closest dates first
                        return parseInt($(a).attr('data-next') || 9999999999) - parseInt($(b).attr('data-next') || 9999999999);
                    }
                    return 0;
                });

                $container.append($cards);

                // Update result count.
                var visibleCount = $cards.filter(function() { return $(this).css('display') !== 'none'; }).length;
                var totalCount = $cards.length;
                var $countEl = $('#spp-sub-result-count');
                if (!$countEl.length) {
                    $countEl = $('<span id="spp-sub-result-count" class="spp-portal__text--sm spp-portal__text--muted spp-result-count"></span>');
                    $('#spp-sub-filter-status').closest('.spp-flex').append($countEl);
                }
                $countEl.text(statusFilter === 'ALL' ? totalCount + ' total' : visibleCount + ' of ' + totalCount);
            });

            // Invoices Filtering and Sorting
            $(document).on('change', '#spp-inv-filter-status, #spp-inv-sort', function () {
                const statusFilter = $('#spp-inv-filter-status').val();
                const sortBy = $('#spp-inv-sort').val();

                const $container = $('#spp-inv-list-container');
                if (!$container.length) return;

                const $cards = $container.find('.spp-inv-card').detach();

                // Show/Hide based on status
                $cards.each(function () {
                    const status = $(this).attr('data-status');
                    if (statusFilter === 'ALL' || status === statusFilter) {
                        $(this).css('display', '');
                    } else {
                        $(this).css('display', 'none');
                    }
                });

                // Sort
                $cards.sort(function (a, b) {
                    if (sortBy === 'newest') {
                        return parseInt($(b).attr('data-created') || 0) - parseInt($(a).attr('data-created') || 0);
                    } else if (sortBy === 'oldest') {
                        return parseInt($(a).attr('data-created') || 0) - parseInt($(b).attr('data-created') || 0);
                    } else if (sortBy === 'due_date') {
                        // Closest dates first
                        return parseInt($(a).attr('data-due') || 9999999999) - parseInt($(b).attr('data-due') || 9999999999);
                    }
                    return 0;
                });

                $container.append($cards);

                // Update result count.
                var visibleCount = $cards.filter(function() { return $(this).css('display') !== 'none'; }).length;
                var totalCount = $cards.length;
                var $countEl = $('#spp-inv-result-count');
                if (!$countEl.length) {
                    $countEl = $('<span id="spp-inv-result-count" class="spp-portal__text--sm spp-portal__text--muted spp-result-count"></span>');
                    $('#spp-inv-filter-status').closest('.spp-flex').append($countEl);
                }
                $countEl.text(statusFilter === 'ALL' ? totalCount + ' total' : visibleCount + ' of ' + totalCount);
            });

            // Transactions Filtering and Sorting
            $(document).on('change', '#spp-txn-filter-status, #spp-txn-sort', function () {
                const statusFilter = $('#spp-txn-filter-status').val();
                const sortBy = $('#spp-txn-sort').val();

                const $container = $('#spp-txn-list-container');
                if (!$container.length) return;

                const $cards = $container.find('.spp-txn-card').detach();

                // Show/Hide based on status
                $cards.each(function () {
                    const status = $(this).attr('data-status');
                    if (statusFilter === 'ALL' || status === statusFilter) {
                        $(this).css('display', '');
                    } else {
                        $(this).css('display', 'none');
                    }
                });

                // Sort
                $cards.sort(function (a, b) {
                    if (sortBy === 'newest') {
                        return parseInt($(b).attr('data-created') || 0) - parseInt($(a).attr('data-created') || 0);
                    } else if (sortBy === 'oldest') {
                        return parseInt($(a).attr('data-created') || 0) - parseInt($(b).attr('data-created') || 0);
                    } else if (sortBy === 'highest') {
                        return parseInt($(b).attr('data-amount') || 0) - parseInt($(a).attr('data-amount') || 0);
                    } else if (sortBy === 'lowest') {
                        return parseInt($(a).attr('data-amount') || 0) - parseInt($(b).attr('data-amount') || 0);
                    }
                    return 0;
                });

                $container.append($cards);
            });

            // WooCommerce Orders Filtering and Sorting
            $(document).on('change', '#spp-wc-order-filter-status, #spp-wc-order-sort', function () {
                const statusFilter = $('#spp-wc-order-filter-status').val();
                const sortBy = $('#spp-wc-order-sort').val();

                const $container = $('#spp-wc-order-list-container');
                if (!$container.length) return;

                const $cards = $container.find('.spp-wc-order-card').detach();

                $cards.each(function () {
                    const status = $(this).attr('data-status');
                    if (statusFilter === 'ALL' || status === statusFilter) {
                        $(this).css('display', '');
                    } else {
                        $(this).css('display', 'none');
                    }
                });

                $cards.sort(function (a, b) {
                    if (sortBy === 'newest') {
                        return parseInt($(b).attr('data-created') || 0) - parseInt($(a).attr('data-created') || 0);
                    } else if (sortBy === 'oldest') {
                        return parseInt($(a).attr('data-created') || 0) - parseInt($(b).attr('data-created') || 0);
                    } else if (sortBy === 'highest') {
                        return parseInt($(b).attr('data-amount') || 0) - parseInt($(a).attr('data-amount') || 0);
                    } else if (sortBy === 'lowest') {
                        return parseInt($(a).attr('data-amount') || 0) - parseInt($(b).attr('data-amount') || 0);
                    }
                    return 0;
                });

                $container.append($cards);
            });

            // ── Client-side pagination ──
            // After filter/sort handlers run, re-paginate the affected container.
            $(document).on('change', '#spp-sub-filter-status, #spp-sub-sort', function () {
                setTimeout(() => SPPPortal.pagination.apply('#spp-sub-list-container', '.spp-sub-card'), 0);
            });
            $(document).on('change', '#spp-inv-filter-status, #spp-inv-sort', function () {
                setTimeout(() => SPPPortal.pagination.apply('#spp-inv-list-container', '.spp-inv-card'), 0);
            });
            $(document).on('change', '#spp-txn-filter-status, #spp-txn-sort', function () {
                setTimeout(() => SPPPortal.pagination.apply('#spp-txn-list-container', '.spp-txn-card'), 0);
            });
            $(document).on('change', '#spp-wc-order-filter-status, #spp-wc-order-sort', function () {
                setTimeout(() => SPPPortal.pagination.apply('#spp-wc-order-list-container', '.spp-wc-order-card'), 0);
            });

            // Apply pagination when a view is loaded.
            document.addEventListener('spp:view:loaded', function (e) {
                const view = e.detail.view;
                setTimeout(() => {
                    if (view === 'invoices') {
                        SPPPortal.pagination.apply('#spp-inv-list-container', '.spp-inv-card');
                    } else if (view === 'subscriptions') {
                        SPPPortal.pagination.apply('#spp-sub-list-container', '.spp-sub-card');
                    } else if (view === 'transactions') {
                        SPPPortal.pagination.apply('#spp-txn-list-container', '.spp-txn-card');
                    } else if (view === 'bookings') {
                        SPPPortal.pagination.apply('[data-spp-bookings-list]', '.spp-portal__card');
                    } else if (view === 'wc-orders') {
                        SPPPortal.pagination.apply('#spp-wc-order-list-container', '.spp-wc-order-card');
                    }
                }, 50);
            });
        },

        /**
         * Handle add card form submission.
         *
         * @async
         * @param {Event} e - Form submit event.
         * @returns {Promise<void>}
         */
        handleAddCard: async function (e) {
            e.preventDefault();

            const $form = $(e.target);
            const $submit = $form.find('[type="submit"]');

            this.ui.setButtonLoading($submit, true);

            try {
                const result = await this.card.tokenize();

                if (result.status === 'OK') {
                    const response = await this.api.post('spp_save_card', {
                        token: result.token
                    });

                    this.ui.showNotification('Card saved successfully!', 'success');
                    this.router.navigate('payment-methods');

                } else {
                    const errorMessage = result.errors?.[0]?.message || 'Card verification failed.';
                    this.ui.showNotification(errorMessage, 'error');
                }
            } catch (error) {
                sppPublic && sppPublic.debug && console.error('Add card error:', error);
                this.ui.showNotification(error.message || 'An error occurred while processing your card.', 'error');
            } finally {
                this.ui.setButtonLoading($submit, false);
            }
        },

        /**
         * Handle payment form submission.
         *
         * @async
         * @param {Event} e - Form submit event.
         * @returns {Promise<void>}
         */
        handlePayment: async function (e) {
            e.preventDefault();

            const $form = $(e.target);
            const $submit = $form.find('[type="submit"]');
            const amount = $form.find('[name="amount"]').val();
            const cardId = $form.find('[name="card_id"]').val();

            this.ui.setButtonLoading($submit, true);

            try {
                let sourceId = cardId;

                // If not using saved card, tokenize new card
                if (!cardId && this.card) {
                    const result = await this.card.tokenize();

                    if (result.status !== 'OK') {
                        const errorMessage = result.errors?.[0]?.message || 'Card verification failed.';
                        this.ui.showNotification(errorMessage, 'error');
                        this.ui.setButtonLoading($submit, false);
                        return;
                    }

                    sourceId = result.token;
                }

                await this.processPayment(sourceId, amount);

            } catch (error) {
                sppPublic && sppPublic.debug && console.error('Payment error:', error);
                this.ui.showNotification(error.message || 'An error occurred while processing your payment.', 'error');
                this.ui.setButtonLoading($submit, false);
            }
        },

        /**
         * Process payment via AJAX.
         *
         * @async
         * @param {string} sourceId - Card token or saved card ID.
         * @param {string|number} amount - Payment amount.
         * @returns {Promise<void>}
         */
        processPayment: async function (sourceId, amount) {
            try {
                await this.api.post('spp_process_payment', {
                    source_id: sourceId,
                    amount: amount
                });

                this.ui.showNotification('Payment successful!', 'success');

                // Reload after brief delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                throw error;
            }
        },

        /**
         * Handle delete card button click.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleDeleteCard: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const cardId = $button.data('card-id');

            const confirmed = await this.ui.confirm('Are you sure you want to remove this card?', {
                title: 'Remove Card',
                confirmText: 'Remove',
                cancelText: 'Cancel',
                type: 'warning'
            });

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Removing...');

            try {
                await this.api.post('spp_delete_card', { card_id: cardId });
                this.ui.showNotification('Card removed successfully.', 'success');
                this.components.loadView('payment-methods');
            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to remove card.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        /**
         * Handle pause subscription.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handlePauseSubscription: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const subscriptionId = $button.data('subscription-id');

            const confirmed = await this.ui.confirm('Are you sure you want to pause this subscription?', {
                title: 'Pause Subscription',
                confirmText: 'Pause',
                cancelText: 'Cancel'
            });

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Pausing...');

            try {
                await this.api.post('spp_pause_subscription', {
                    subscription_id: subscriptionId
                });

                this.ui.showNotification('Subscription paused.', 'success');
                this.components.loadView('subscriptions');

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to pause subscription.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        /**
         * Handle resume subscription.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleResumeSubscription: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const subscriptionId = $button.data('subscription-id');

            this.ui.setButtonLoading($button, true, 'Resuming...');

            try {
                await this.api.post('spp_resume_subscription', {
                    subscription_id: subscriptionId
                });

                this.ui.showNotification('Subscription resumed.', 'success');
                this.components.loadView('subscriptions');

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to resume subscription.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        /**
         * Handle cancel subscription.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleCancelSubscription: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const subscriptionId = $button.data('subscription-id');

            const confirmed = await this.ui.confirm(
                'Are you sure you want to cancel this subscription? It will remain active until the end of your current billing period.',
                {
                    title: 'Cancel Subscription',
                    confirmText: 'Cancel Subscription',
                    cancelText: 'Keep Subscription',
                    type: 'warning'
                }
            );

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Cancelling...');

            try {
                await this.api.post('spp_cancel_subscription', {
                    subscription_id: subscriptionId
                });

                this.ui.showNotification('Subscription cancelled.', 'success');
                // Reload the current view via SPA instead of full page reload
                this.components.loadView(this.currentView);

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to cancel subscription.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        // ============================================================
        // BOOKING CANCELLATION
        // ============================================================

        /**
         * Handle cancel booking/appointment.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleCancelBooking: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const bookingId = $button.data('booking-id');
            const bookingVersion = $button.data('booking-version');

            const confirmed = await this.ui.confirm(
                'Are you sure you want to cancel this appointment? This action cannot be undone.',
                {
                    title: 'Cancel Appointment',
                    confirmText: 'Cancel Appointment',
                    cancelText: 'Keep Appointment',
                    type: 'warning'
                }
            );

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Cancelling...');

            try {
                await this.api.post('spp_public_cancel_booking', {
                    booking_id: bookingId,
                    booking_version: bookingVersion
                });

                this.ui.showNotification('Appointment cancelled.', 'success');
                this.components.loadView('bookings');

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to cancel appointment.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        // ============================================================
        // WOOCOMMERCE ORDER ACTIONS
        // ============================================================

        /**
         * Handle WooCommerce order cancellation.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleCancelWcOrder: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const orderId = $button.data('order-id');

            const confirmed = await this.ui.confirm(
                'Are you sure you want to cancel this order? This action cannot be undone.',
                {
                    title: 'Cancel Order',
                    confirmText: 'Cancel Order',
                    cancelText: 'Keep Order',
                    type: 'warning'
                }
            );

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Cancelling...');

            try {
                await this.api.post('spp_cancel_wc_order', {
                    order_id: orderId
                });

                this.ui.showNotification('Order cancelled.', 'success');
                this.components.loadView('wc-orders');

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to cancel order.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        /**
         * Handle WooCommerce reorder — add items to cart.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleReorderWcOrder: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const orderId = $button.data('order-id');

            this.ui.setButtonLoading($button, true, 'Adding to cart...');

            try {
                const result = await this.api.post('spp_reorder_wc_order', {
                    order_id: orderId
                });

                this.ui.showNotification(result.message || 'Items added to cart.', 'success');

                if (result.cart_url) {
                    window.location.href = result.cart_url;
                }

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to reorder.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        // ============================================================
        // LOYALTY REWARD REDEMPTION
        // ============================================================

        /**
         * Handle loyalty reward redemption.
         *
         * @async
         * @param {Event} e - Click event.
         * @returns {Promise<void>}
         */
        handleRedeemReward: async function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const tierId = $button.data('tier-id');
            const tierName = $button.data('tier-name');
            const tierPoints = $button.data('tier-points');

            const confirmed = await this.ui.confirm(
                `Redeem "${tierName}" for ${Number(tierPoints).toLocaleString()} points?`,
                {
                    title: 'Redeem Reward',
                    confirmText: 'Redeem',
                    cancelText: 'Cancel',
                    type: 'info'
                }
            );

            if (!confirmed) {
                return;
            }

            this.ui.setButtonLoading($button, true, 'Redeeming...');

            try {
                const result = await this.api.post('spp_redeem_loyalty_reward', {
                    reward_tier_id: tierId
                });

                this.ui.showNotification(result.message || 'Reward redeemed successfully!', 'success');
                this.components.loadView('loyalty');

            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to redeem reward.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        // ============================================================
        // PROFILE SAVE
        // ============================================================

        /**
         * Handle profile form submission — saves contact or address info.
         *
         * @param {Event} e - Submit event.
         * @returns {void}
         */
        handleSaveProfile: async function (e) {
            e.preventDefault();

            const $form = $(e.currentTarget);
            const $button = $form.find('.spp-save-profile-btn');

            this.ui.setButtonLoading($button, true, 'Saving...');

            // --- Inline validation ---
            $('.spp-field-error').remove();
            $('.spp-portal__input--error').removeClass('spp-portal__input--error');

            var validationErrors = [];

            var $emailField = $('.spp-profile-form').find('input[name="email"]');
            if ($emailField.length) {
                var emailVal = $emailField.val().trim();
                if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    validationErrors.push({ $field: $emailField, message: 'Please enter a valid email address.' });
                }
            }

            var $phoneField = $('.spp-profile-form').find('input[name="phone"]');
            if ($phoneField.length) {
                var phoneVal = $phoneField.val().trim();
                if (phoneVal) {
                    var digitsOnly = phoneVal.replace(/\D/g, '');
                    if (digitsOnly.length < 9 || digitsOnly.length > 16) {
                        validationErrors.push({ $field: $phoneField, message: 'Phone number must be 9-16 digits.' });
                    }
                }
            }

            if (validationErrors.length) {
                validationErrors.forEach(function (err) {
                    err.$field.addClass('spp-portal__input--error');
                    err.$field.after('<span class="spp-field-error">' + err.message + '</span>');
                });
                validationErrors[0].$field.focus();
                this.ui.showNotification('Please fix the highlighted fields.', 'error');
                this.ui.setButtonLoading($button, false);
                return;
            }
            // --- End inline validation ---

            // Collect fields from both profile forms.
            const data = {};
            $('.spp-profile-form').each(function () {
                $(this).find('input, select').each(function () {
                    const name = $(this).attr('name');
                    if (name) {
                        data[name] = $(this).val();
                    }
                });
            });

            // Strip phone formatting to digits-only for server.
            if (data.phone) {
                data.phone = data.phone.replace(/\D/g, '');
                // Keep the leading 1 for +1 numbers, or prepend if 10 digits.
                if (data.phone.length === 10) {
                    data.phone = '+1' + data.phone;
                } else if (data.phone.length === 11 && data.phone[0] === '1') {
                    data.phone = '+' + data.phone;
                }
            }

            try {
                const result = await this.api.post('spp_save_profile', data);
                this.ui.showNotification(result.message || 'Profile updated successfully.', 'success');
                this.components.loadView('profile');
            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to save profile.', 'error');
                this.ui.setButtonLoading($button, false);
            }
        },

        /**
         * Handle avatar file upload.
         *
         * @param {Event} e - Change event from file input.
         * @returns {void}
         */
        handleAvatarUpload: function (e) {
            var file = e.target.files[0];
            if (!file) return;

            // Client-side validation.
            var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (allowed.indexOf(file.type) === -1) {
                this.ui.showNotification('Invalid file type. Allowed: JPG, PNG, GIF, WEBP.', 'error');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                this.ui.showNotification('File too large. Maximum size is 2MB.', 'error');
                return;
            }

            // Instant preview.
            var reader = new FileReader();
            var $img = $('#spp-avatar-img');
            reader.onload = function (ev) {
                $img.attr('src', ev.target.result);
            };
            reader.readAsDataURL(file);

            // Upload via FormData (can't use api.post for file uploads).
            var formData = new FormData();
            formData.append('action', 'spp_upload_avatar');
            formData.append('nonce', sppPublic.nonce);
            formData.append('avatar', file);

            var self = this;
            $.ajax({
                url: sppPublic.ajaxUrl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $img.attr('src', response.data.avatar_url);
                        $('#spp-avatar-remove').show();
                        self.ui.showNotification(response.data.message || 'Profile photo updated.', 'success');
                    } else {
                        self.ui.showNotification((response.data && response.data.message) || 'Upload failed.', 'error');
                    }
                },
                error: function () {
                    self.ui.showNotification('Upload failed. Please try again.', 'error');
                }
            });

            // Reset the input so re-selecting the same file triggers change.
            $(e.target).val('');
        },

        /**
         * Handle removing custom avatar.
         *
         * @param {Event} e - Click event.
         * @returns {void}
         */
        handleRemoveAvatar: async function (e) {
            e.preventDefault();

            try {
                var result = await this.api.post('spp_remove_avatar');
                $('#spp-avatar-img').attr('src', result.avatar_url);
                $('#spp-avatar-remove').hide();
                this.ui.showNotification(result.message || 'Profile photo removed.', 'success');
            } catch (error) {
                this.ui.showNotification(error.message || 'Failed to remove photo.', 'error');
            }
        },

        /**
         * Auto-format phone input as +1 (XXX) XXX-XXXX.
         *
         * @param {Event} e - Input event.
         * @returns {void}
         */
        formatPhoneInput: function (e) {
            const input = e.currentTarget;
            const cursorPos = input.selectionStart;
            const prevLen = input.value.length;

            // Strip to digits only.
            let digits = input.value.replace(/\D/g, '');

            // Remove leading 1 for formatting (we'll add +1 prefix).
            if (digits.length > 0 && digits[0] === '1') {
                digits = digits.substring(1);
            }

            // Cap at 10 digits (US number without country code).
            digits = digits.substring(0, 10);

            // Build formatted string.
            let formatted = '';
            if (digits.length === 0) {
                formatted = '';
            } else if (digits.length <= 3) {
                formatted = '+1 (' + digits;
            } else if (digits.length <= 6) {
                formatted = '+1 (' + digits.substring(0, 3) + ') ' + digits.substring(3);
            } else {
                formatted = '+1 (' + digits.substring(0, 3) + ') ' + digits.substring(3, 6) + '-' + digits.substring(6);
            }

            input.value = formatted;

            // Restore cursor position intelligently.
            const newLen = input.value.length;
            const diff = newLen - prevLen;
            input.setSelectionRange(cursorPos + diff, cursorPos + diff);
        },

        // ============================================================
        // GIFT CARD PURCHASE
        // ============================================================

        /**
         * Handle Buy Gift Card button click — opens purchase modal.
         *
         * @param {Event} e - Click event.
         * @returns {void}
         */
        handleBuyGiftCard: function (e) {
            e.preventDefault();

            // Collect saved cards from the DOM
            const savedCards = [];
            $('.spp-delete-card').each(function () {
                const $card = $(this).closest('.spp-portal__card');
                const cardId = $(this).data('card-id');
                const brand = $card.find('strong').first().text().trim();
                const last4 = $card.find('.spp-portal__text--muted.spp-portal__text--sm').first().text().replace(/[^0-9]/g, '');
                const expiry = $card.find('.spp-portal__text--muted.spp-portal__text--sm').last().text().trim();
                savedCards.push({ id: cardId, brand: brand, last4: last4, expiry: expiry });
            });

            // If no saved cards, prompt user to add one first
            if (savedCards.length === 0) {
                this.ui.showModal(
                    '<div style="text-align:center;padding:var(--spp-space-4);">' +
                    '<p>You need a saved card to purchase a gift card.</p>' +
                    '<a href="#" class="spp-portal__btn spp-portal__btn--primary spp-mt-3" data-spp-view="add-card">Add a Card</a>' +
                    '</div>',
                    { title: 'No Payment Method', size: 'small' }
                );
                return;
            }

            // Build card picker HTML
            let cardPickerHtml = '';
            savedCards.forEach(function (c, i) {
                const checked = i === 0 ? 'checked' : '';
                const active = i === 0 ? ' spp-gc-card-option--active' : '';
                cardPickerHtml += `
                    <label class="spp-gc-card-option${active}">
                        <input type="radio" name="spp_gc_card" value="${SPPPortal.ui.escapeHtml(c.id)}" ${checked} />
                        <svg class="spp-gc-card-option__icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                        <div class="spp-gc-card-option__details">
                            <span class="spp-gc-card-option__brand">${SPPPortal.ui.escapeHtml(c.brand)}</span>
                            <span class="spp-gc-card-option__number">&bull;&bull;&bull;&bull; ${SPPPortal.ui.escapeHtml(c.last4)}</span>
                        </div>
                        <span class="spp-gc-card-option__expiry">${SPPPortal.ui.escapeHtml(c.expiry)}</span>
                        <span class="spp-gc-card-option__check">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </span>
                    </label>`;
            });

            // Build modal content
            const modalHtml = `
                <form id="spp-buy-gc-form" class="spp-gc-form">
                    <div class="spp-gc-form__section">
                        <label class="spp-gc-form__label">Amount</label>
                        <div class="spp-gc-amount-grid">
                            <button type="button" class="spp-gc-chip spp-gc-amount-btn" data-amount="10">$10</button>
                            <button type="button" class="spp-gc-chip spp-gc-amount-btn" data-amount="25">$25</button>
                            <button type="button" class="spp-gc-chip spp-gc-amount-btn" data-amount="50">$50</button>
                            <button type="button" class="spp-gc-chip spp-gc-amount-btn" data-amount="100">$100</button>
                        </div>
                        <div class="spp-gc-custom-amount">
                            <span class="spp-gc-custom-amount__symbol">$</span>
                            <input type="number" id="spp-gc-amount" class="spp-portal__input" min="1" max="2000" step="0.01" placeholder="Custom amount" required />
                        </div>
                    </div>

                    <div class="spp-gc-form__section">
                        <label class="spp-gc-form__label">Recipient</label>
                        <div class="spp-gc-toggle">
                            <label class="spp-gc-toggle__option spp-gc-toggle__option--active">
                                <input type="radio" name="spp_gc_recipient" value="self" checked />
                                <span>For myself</span>
                            </label>
                            <label class="spp-gc-toggle__option">
                                <input type="radio" name="spp_gc_recipient" value="other" />
                                <span>Send to someone</span>
                            </label>
                        </div>
                        <div id="spp-gc-recipient-fields" class="spp-gc-recipient-fields">
                            <input type="text" id="spp-gc-recipient-name" class="spp-portal__input" placeholder="Recipient name" />
                            <input type="email" id="spp-gc-recipient-email" class="spp-portal__input" placeholder="Recipient email address" />
                        </div>
                    </div>

                    <div class="spp-gc-form__section">
                        <label class="spp-gc-form__label">Pay with</label>
                        <div class="spp-gc-card-picker">
                            ${cardPickerHtml}
                        </div>
                    </div>

                    <div id="spp-gc-errors" class="spp-gc-form__errors"></div>

                    <button type="submit" class="spp-portal__btn spp-portal__btn--primary spp-w-full" id="spp-gc-buy-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/><path d="M3 12h18"/><path d="m8 3 4 4 4-4"/></svg>
                        Purchase Gift Card
                    </button>
                </form>
            `;

            const $modal = this.ui.showModal(modalHtml, {
                title: 'Buy Gift Card',
                size: 'medium'
            });

            // Amount preset buttons
            $modal.on('click', '.spp-gc-amount-btn', function (ev) {
                ev.preventDefault();
                const amount = $(this).data('amount');
                $('#spp-gc-amount').val(amount);
                $modal.find('.spp-gc-amount-btn').removeClass('spp-gc-chip--active');
                $(this).addClass('spp-gc-chip--active');
            });

            // Clear chip selection when typing custom amount
            $modal.on('input', '#spp-gc-amount', function () {
                $modal.find('.spp-gc-amount-btn').removeClass('spp-gc-chip--active');
            });

            // Card picker selection
            $modal.on('change', 'input[name="spp_gc_card"]', function () {
                $modal.find('.spp-gc-card-option').removeClass('spp-gc-card-option--active');
                $(this).closest('.spp-gc-card-option').addClass('spp-gc-card-option--active');
            });

            // Recipient toggle
            $modal.on('change', 'input[name="spp_gc_recipient"]', function () {
                const isOther = $(this).val() === 'other';
                $modal.find('.spp-gc-toggle__option').removeClass('spp-gc-toggle__option--active');
                $(this).closest('.spp-gc-toggle__option').addClass('spp-gc-toggle__option--active');
                $('#spp-gc-recipient-fields').toggleClass('spp-gc-recipient-fields--visible', isOther);
                if (isOther) {
                    $('#spp-gc-recipient-email').attr('required', 'required');
                } else {
                    $('#spp-gc-recipient-email').removeAttr('required');
                }
            });

            // Form submission
            const self = this;
            $modal.on('submit', '#spp-buy-gc-form', async function (ev) {
                ev.preventDefault();

                const $errBox = $('#spp-gc-errors');
                $errBox.text('');

                const amount = parseFloat($('#spp-gc-amount').val());
                const cardId = $('input[name="spp_gc_card"]:checked').val();
                const recipientType = $('input[name="spp_gc_recipient"]:checked').val();
                const recipientName = $('#spp-gc-recipient-name').val().trim();
                const recipientEmail = $('#spp-gc-recipient-email').val().trim();

                // Validate amount
                if (!amount || amount < 1) {
                    $errBox.text('Please enter a valid amount ($1 minimum).');
                    return;
                }
                if (amount > 2000) {
                    $errBox.text('Maximum gift card amount is $2,000.');
                    return;
                }

                // Validate recipient email if sending to someone else
                if (recipientType === 'other' && !recipientEmail) {
                    $errBox.text('Please enter the recipient\'s email address.');
                    return;
                }

                const $buyBtn = $('#spp-gc-buy-btn');
                self.ui.setButtonLoading($buyBtn, true, 'Processing...');

                try {
                    const result = await self.api.post('spp_purchase_gift_card', {
                        amount: amount,
                        card_id: cardId,
                        recipient_type: recipientType,
                        recipient_name: recipientName,
                        recipient_email: recipientEmail
                    });

                    self.ui.hideModal();

                    if (recipientType === 'other') {
                        self.ui.showNotification('Gift card purchased and sent to ' + recipientEmail + '!', 'success');
                    } else {
                        self.ui.showNotification('Gift card purchased successfully! Balance: $' + (result.balance || amount.toFixed(2)), 'success');
                    }

                    // Reload payment methods to show the new gift card
                    self.components.loadView('payment-methods');

                } catch (error) {
                    $errBox.text(error.message || 'Failed to purchase gift card. Please try again.');
                    self.ui.setButtonLoading($buyBtn, false);
                }
            });
        },

        /**
         * Handle Reload Gift Card button click — opens reload modal.
         *
         * @param {Event} e - Click event.
         * @returns {void}
         */
        handleReloadGiftCard: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const gcId = $button.data('gc-id');
            const gcLast4 = $button.data('gc-last4');

            // Collect saved cards from the DOM
            const savedCards = [];
            $('.spp-delete-card').each(function () {
                const $card = $(this).closest('.spp-portal__card');
                const cardId = $(this).data('card-id');
                const brand = $card.find('strong').first().text().trim();
                const last4 = $card.find('.spp-portal__text--muted.spp-portal__text--sm').first().text().replace(/[^0-9]/g, '');
                const expiry = $card.find('.spp-portal__text--muted.spp-portal__text--sm').last().text().trim();
                savedCards.push({ id: cardId, brand: brand, last4: last4, expiry: expiry });
            });

            if (savedCards.length === 0) {
                this.ui.showModal(
                    '<div style="text-align:center;padding:var(--spp-space-4);">' +
                    '<p>You need a saved card to reload a gift card.</p>' +
                    '<a href="#" class="spp-portal__btn spp-portal__btn--primary spp-mt-3" data-spp-view="add-card">Add a Card</a>' +
                    '</div>',
                    { title: 'No Payment Method', size: 'small' }
                );
                return;
            }

            // Build card picker HTML
            let cardPickerHtml = '';
            savedCards.forEach(function (c, i) {
                const checked = i === 0 ? 'checked' : '';
                const active = i === 0 ? ' spp-gc-card-option--active' : '';
                cardPickerHtml += `
                    <label class="spp-gc-card-option${active}">
                        <input type="radio" name="spp_reload_card" value="${SPPPortal.ui.escapeHtml(c.id)}" ${checked} />
                        <svg class="spp-gc-card-option__icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                        <div class="spp-gc-card-option__details">
                            <span class="spp-gc-card-option__brand">${SPPPortal.ui.escapeHtml(c.brand)}</span>
                            <span class="spp-gc-card-option__number">&bull;&bull;&bull;&bull; ${SPPPortal.ui.escapeHtml(c.last4)}</span>
                        </div>
                        <span class="spp-gc-card-option__expiry">${SPPPortal.ui.escapeHtml(c.expiry)}</span>
                        <span class="spp-gc-card-option__check">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </span>
                    </label>`;
            });

            const modalHtml = `
                <form id="spp-reload-gc-form" class="spp-gc-form">
                    <div class="spp-gc-form__section">
                        <label class="spp-gc-form__label">Amount to add</label>
                        <div class="spp-gc-amount-grid">
                            <button type="button" class="spp-gc-chip spp-gc-reload-amount-btn" data-amount="10">$10</button>
                            <button type="button" class="spp-gc-chip spp-gc-reload-amount-btn" data-amount="25">$25</button>
                            <button type="button" class="spp-gc-chip spp-gc-reload-amount-btn" data-amount="50">$50</button>
                            <button type="button" class="spp-gc-chip spp-gc-reload-amount-btn" data-amount="100">$100</button>
                        </div>
                        <div class="spp-gc-custom-amount">
                            <span class="spp-gc-custom-amount__symbol">$</span>
                            <input type="number" id="spp-reload-gc-amount" class="spp-portal__input" min="1" max="2000" step="0.01" placeholder="Custom amount" required />
                        </div>
                    </div>

                    <div class="spp-gc-form__section">
                        <label class="spp-gc-form__label">Pay with</label>
                        <div class="spp-gc-card-picker">
                            ${cardPickerHtml}
                        </div>
                    </div>

                    <div id="spp-reload-gc-errors" class="spp-gc-form__errors"></div>

                    <button type="submit" class="spp-portal__btn spp-portal__btn--primary spp-w-full" id="spp-reload-gc-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                        Add Funds
                    </button>
                </form>
            `;

            const $modal = this.ui.showModal(modalHtml, {
                title: 'Reload Gift Card •••• ' + gcLast4,
                size: 'medium'
            });

            // Amount preset buttons
            $modal.on('click', '.spp-gc-reload-amount-btn', function (ev) {
                ev.preventDefault();
                const amount = $(this).data('amount');
                $('#spp-reload-gc-amount').val(amount);
                $modal.find('.spp-gc-reload-amount-btn').removeClass('spp-gc-chip--active');
                $(this).addClass('spp-gc-chip--active');
            });

            // Clear chip selection when typing custom amount
            $modal.on('input', '#spp-reload-gc-amount', function () {
                $modal.find('.spp-gc-reload-amount-btn').removeClass('spp-gc-chip--active');
            });

            // Card picker selection
            $modal.on('change', 'input[name="spp_reload_card"]', function () {
                $modal.find('.spp-gc-card-option').removeClass('spp-gc-card-option--active');
                $(this).closest('.spp-gc-card-option').addClass('spp-gc-card-option--active');
            });

            // Form submission
            const self = this;
            $modal.on('submit', '#spp-reload-gc-form', async function (ev) {
                ev.preventDefault();

                const $errBox = $('#spp-reload-gc-errors');
                $errBox.text('');

                const amount = parseFloat($('#spp-reload-gc-amount').val());
                const cardId = $('input[name="spp_reload_card"]:checked').val();

                if (!amount || amount < 1) {
                    $errBox.text('Please enter a valid amount ($1 minimum).');
                    return;
                }
                if (amount > 2000) {
                    $errBox.text('Maximum reload amount is $2,000.');
                    return;
                }

                const $reloadBtn = $('#spp-reload-gc-btn');
                self.ui.setButtonLoading($reloadBtn, true, 'Processing...');

                try {
                    const result = await self.api.post('spp_reload_gift_card', {
                        gift_card_id: gcId,
                        amount: amount,
                        card_id: cardId
                    });

                    self.ui.hideModal();
                    self.ui.showNotification('Gift card reloaded! New balance: $' + (result.balance || amount.toFixed(2)), 'success');
                    self.components.loadView('payment-methods');

                } catch (error) {
                    $errBox.text(error.message || 'Failed to reload gift card. Please try again.');
                    self.ui.setButtonLoading($reloadBtn, false);
                }
            });
        },

        // ============================================================
        // APPOINTMENT BOOKING
        // ============================================================

        /**
         * Handle Book Appointment — opens multi-step booking modal.
         *
         * @param {Event} e - Click event.
         * @returns {void}
         */
        handleBookAppointment: async function (e) {
            e.preventDefault();

            const self = this;

            // Show loading modal while fetching services
            const $modal = this.ui.showModal(
                '<div class="spp-booking-loading"><div class="spp-portal__spinner"></div><p>Loading available services...</p></div>',
                { title: 'Book Appointment', size: 'medium' }
            );

            try {
                const result = await this.api.post('spp_public_get_services');
                const services = result.services || [];

                if (services.length === 0) {
                    $modal.find('.spp-modal-content').html(
                        '<div style="text-align:center;padding:var(--spp-space-4);">' +
                        '<p class="spp-portal__text--muted">No services are currently available for booking.</p>' +
                        '</div>'
                    );
                    return;
                }

                // Render Step 1: Service selection
                self._bookingRenderServices($modal, services);

            } catch (error) {
                $modal.find('.spp-modal-content').html(
                    '<div style="text-align:center;padding:var(--spp-space-4);">' +
                    '<p style="color:var(--spp-error);">' + SPPPortal.ui.escapeHtml(error.message || 'Failed to load services.') + '</p>' +
                    '</div>'
                );
            }
        },

        /**
         * Build step indicator HTML for the booking modal.
         *
         * @param {number}      activeStep    Current step number (1-based).
         * @param {object|null} selectedStaff Staff object if chosen, null otherwise.
         * @param {object}      callbacks     Map of step labels to CSS class for back-buttons.
         * @returns {string} HTML string.
         */
        _bookingStepIndicator: function (activeStep, selectedStaff) {
            const staffEnabled = !!(sppPublic && sppPublic.allowStaffSelection);
            const steps = staffEnabled
                ? ['Service', 'Staff', 'Date & Time', 'Confirm']
                : ['Service', 'Date & Time', 'Confirm'];

            let html = '<div class="spp-booking-step-indicator">';
            steps.forEach(function (label, idx) {
                const num = idx + 1;
                const isDone = num < activeStep;
                const isActive = num === activeStep;
                const cls = isDone ? 'spp-booking-step--done' : (isActive ? 'spp-booking-step--active' : '');
                const tag = isDone ? 'button type="button"' : 'span';
                const closeTag = isDone ? 'button' : 'span';
                const backCls = isDone ? ' spp-booking-back-to-step-' + num : '';
                html += '<' + tag + ' class="spp-booking-step ' + cls + backCls + '">' + num + '. ' + label + '</' + closeTag + '>';
            });
            html += '</div>';
            return html;
        },

        /**
         * Step 1: Render service picker.
         */
        _bookingRenderServices: function ($modal, services) {
            const self = this;
            let html = '<div class="spp-booking-steps">' +
                this._bookingStepIndicator(1, null) +
                '<div class="spp-booking-service-list">';

            services.forEach(function (s) {
                const priceStr = s.price > 0 ? ('$' + parseFloat(s.price).toFixed(2)) : 'Free';
                const durationStr = s.duration > 0 ? (s.duration + ' min') : '';
                html += `
                    <button type="button" class="spp-booking-service-card" data-service-id="${SPPPortal.ui.escapeHtml(s.id)}" data-service-name="${SPPPortal.ui.escapeHtml(s.name)}" data-service-duration="${s.duration}" data-service-price="${s.price}">
                        <div class="spp-booking-service-card__info">
                            <span class="spp-booking-service-card__name">${SPPPortal.ui.escapeHtml(s.name)}</span>
                            ${s.description ? '<span class="spp-booking-service-card__desc">' + SPPPortal.ui.escapeHtml(s.description) + '</span>' : ''}
                        </div>
                        <div class="spp-booking-service-card__meta">
                            ${durationStr ? '<span class="spp-booking-service-card__duration">' + durationStr + '</span>' : ''}
                            <span class="spp-booking-service-card__price">${priceStr}</span>
                        </div>
                    </button>`;
            });

            html += '</div></div>';
            $modal.find('.spp-modal-content').html(html);

            // Service selection click
            $modal.off('click.bookingService').on('click.bookingService', '.spp-booking-service-card', function () {
                const service = {
                    id: $(this).data('service-id'),
                    name: $(this).data('service-name'),
                    duration: $(this).data('service-duration'),
                    price: $(this).data('service-price')
                };
                if (sppPublic && sppPublic.allowStaffSelection) {
                    self._bookingRenderStaffPicker($modal, services, service);
                } else {
                    self._bookingRenderDatePicker($modal, services, service, null);
                }
            });
        },

        /**
         * Step 2 (when staff selection enabled): Render staff picker.
         */
        _bookingRenderStaffPicker: async function ($modal, services, service) {
            const self = this;

            // Unbind service click handler to prevent delegation conflicts
            // (staff cards share .spp-booking-service-card class for styling)
            $modal.off('click.bookingService');

            // Show loading while fetching staff
            let html = '<div class="spp-booking-steps">' +
                this._bookingStepIndicator(2, null) +
                '<p class="spp-booking-selected-service">' + SPPPortal.ui.escapeHtml(service.name) + '</p>' +
                '<div class="spp-booking-loading"><div class="spp-portal__spinner"></div><p>Loading staff members...</p></div>' +
                '</div>';
            $modal.find('.spp-modal-content').html(html);

            // Back to services
            $modal.off('click.bookingBackStep1').on('click.bookingBackStep1', '.spp-booking-back-to-step-1', function () {
                self._bookingRenderServices($modal, services);
            });

            try {
                const result = await this.api.post('spp_public_get_bookable_staff', {
                    service_variation_id: service.id
                });

                const staffList = result.staff || [];

                let staffHtml = '<div class="spp-booking-steps">' +
                    this._bookingStepIndicator(2, null) +
                    '<p class="spp-booking-selected-service">' + SPPPortal.ui.escapeHtml(service.name) + '</p>' +
                    '<div class="spp-booking-service-list">';

                // "No Preference" option
                staffHtml += `<button type="button" class="spp-booking-service-card spp-booking-staff-card" data-staff-id="" data-staff-name="No Preference">
                    <div class="spp-booking-service-card__info">
                        <span class="spp-booking-service-card__name">No Preference</span>
                        <span class="spp-booking-service-card__desc">Any available staff member</span>
                    </div>
                </button>`;

                staffList.forEach(function (staff) {
                    const initials = (staff.display_name || '?').split(' ').map(function(w) { return w[0]; }).join('').substring(0, 2).toUpperCase();
                    staffHtml += `<button type="button" class="spp-booking-service-card spp-booking-staff-card" data-staff-id="${SPPPortal.ui.escapeHtml(staff.id)}" data-staff-name="${SPPPortal.ui.escapeHtml(staff.display_name)}">
                        <div class="spp-booking-staff-avatar">${staff.profile_image_url
                            ? '<img src="' + SPPPortal.ui.escapeHtml(staff.profile_image_url) + '" alt="" />'
                            : '<span class="spp-booking-staff-initials">' + SPPPortal.ui.escapeHtml(initials) + '</span>'
                        }</div>
                        <div class="spp-booking-service-card__info">
                            <span class="spp-booking-service-card__name">${SPPPortal.ui.escapeHtml(staff.display_name)}</span>
                            ${staff.description ? '<span class="spp-booking-service-card__desc">' + SPPPortal.ui.escapeHtml(staff.description) + '</span>' : ''}
                        </div>
                    </button>`;
                });

                staffHtml += '</div></div>';
                $modal.find('.spp-modal-content').html(staffHtml);

                // Re-bind back button after replacing content
                $modal.off('click.bookingBackStep1').on('click.bookingBackStep1', '.spp-booking-back-to-step-1', function () {
                    self._bookingRenderServices($modal, services);
                });

                // Staff selection click
                $modal.off('click.bookingStaff').on('click.bookingStaff', '.spp-booking-staff-card', function () {
                    const staffId = $(this).data('staff-id');
                    const staffName = $(this).data('staff-name');
                    const selectedStaff = staffId ? { id: staffId, name: staffName } : null;
                    self._bookingRenderDatePicker($modal, services, service, selectedStaff);
                });

            } catch (error) {
                $modal.find('.spp-booking-loading').replaceWith(
                    '<p style="color:var(--spp-error);text-align:center;padding:var(--spp-space-4);">' +
                    SPPPortal.ui.escapeHtml(error.message || 'Failed to load staff members.') + '</p>'
                );
            }
        },

        /**
         * Render date picker and time slots.
         */
        _bookingRenderDatePicker: function ($modal, services, service, selectedStaff) {
            const self = this;
            const staffEnabled = !!(sppPublic && sppPublic.allowStaffSelection);
            const dateStepNum = staffEnabled ? 3 : 2;
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];

            let subHeading = SPPPortal.ui.escapeHtml(service.name);
            if (selectedStaff) {
                subHeading += ' &middot; ' + SPPPortal.ui.escapeHtml(selectedStaff.name);
            }

            let html = '<div class="spp-booking-steps">' +
                this._bookingStepIndicator(dateStepNum, selectedStaff) +
                '<p class="spp-booking-selected-service">' + subHeading + '</p>' +
                '<div class="spp-gc-form__section">' +
                '<label class="spp-gc-form__label">Select a date</label>' +
                '<input type="date" id="spp-booking-date" class="spp-portal__input" min="' + todayStr + '" value="' + todayStr + '" />' +
                '</div>' +
                '<div id="spp-booking-slots-container">' +
                '<div class="spp-booking-loading"><div class="spp-portal__spinner"></div><p>Searching availability...</p></div>' +
                '</div></div>';

            $modal.find('.spp-modal-content').html(html);

            // Back to step 1 (services)
            $modal.off('click.bookingBackStep1').on('click.bookingBackStep1', '.spp-booking-back-to-step-1', function () {
                self._bookingRenderServices($modal, services);
            });

            // Back to step 2 (staff picker, only when staff enabled)
            if (staffEnabled) {
                $modal.off('click.bookingBackStep2').on('click.bookingBackStep2', '.spp-booking-back-to-step-2', function () {
                    self._bookingRenderStaffPicker($modal, services, service);
                });
            }

            // Load slots for today
            self._bookingLoadSlots($modal, services, service, todayStr, selectedStaff);

            // Date change
            $modal.off('change.bookingDate').on('change.bookingDate', '#spp-booking-date', function () {
                const date = $(this).val();
                if (date) {
                    $('#spp-booking-slots-container').html(
                        '<div class="spp-booking-loading"><div class="spp-portal__spinner"></div><p>Searching availability...</p></div>'
                    );
                    self._bookingLoadSlots($modal, services, service, date, selectedStaff);
                }
            });
        },

        /**
         * Fetch and render time slots for a given date.
         */
        _bookingLoadSlots: async function ($modal, services, service, dateStr, selectedStaff) {
            const self = this;
            const startDate = new Date(dateStr + 'T00:00:00');
            const endDate = new Date(dateStr + 'T23:59:59');

            try {
                const postData = {
                    service_variation_id: service.id,
                    start_at: startDate.toISOString(),
                    end_at: endDate.toISOString()
                };
                // Filter availability to chosen staff member
                if (selectedStaff && selectedStaff.id) {
                    postData.team_member_id = selectedStaff.id;
                }

                const result = await this.api.post('spp_public_get_availability', postData);

                const availabilities = result.availabilities || [];

                if (availabilities.length === 0) {
                    $('#spp-booking-slots-container').html(
                        '<p class="spp-portal__text--muted" style="text-align:center;padding:var(--spp-space-4);">No available time slots for this date.</p>'
                    );
                    return;
                }

                let slotsHtml = '<label class="spp-gc-form__label" style="margin-top:12px;">Available times</label><div class="spp-booking-slots-grid">';
                availabilities.forEach(function (slot) {
                    const startTime = new Date(slot.start_at);
                    const hours = startTime.getHours();
                    const minutes = startTime.getMinutes();
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    const displayHour = hours % 12 || 12;
                    const displayMin = minutes < 10 ? '0' + minutes : minutes;
                    const timeLabel = displayHour + ':' + displayMin + ' ' + ampm;
                    const teamMemberId = (slot.appointment_segments && slot.appointment_segments[0])
                        ? slot.appointment_segments[0].team_member_id || ''
                        : '';

                    slotsHtml += `<button type="button" class="spp-gc-chip spp-booking-slot-btn"
                        data-start-at="${SPPPortal.ui.escapeHtml(slot.start_at)}"
                        data-team-member-id="${SPPPortal.ui.escapeHtml(teamMemberId)}">${timeLabel}</button>`;
                });
                slotsHtml += '</div>';
                $('#spp-booking-slots-container').html(slotsHtml);

                // Slot selection
                $modal.off('click.bookingSlot').on('click.bookingSlot', '.spp-booking-slot-btn', function () {
                    const slotData = {
                        start_at: $(this).data('start-at'),
                        team_member_id: $(this).data('team-member-id'),
                        time_label: $(this).text()
                    };
                    self._bookingRenderConfirm($modal, services, service, dateStr, slotData, selectedStaff);
                });

            } catch (error) {
                $('#spp-booking-slots-container').html(
                    '<p style="color:var(--spp-error);text-align:center;padding:var(--spp-space-4);">' +
                    SPPPortal.ui.escapeHtml(error.message || 'Failed to load availability.') + '</p>'
                );
            }
        },

        /**
         * Render confirmation screen (final step).
         */
        _bookingRenderConfirm: function ($modal, services, service, dateStr, slotData, selectedStaff) {
            const self = this;
            const staffEnabled = !!(sppPublic && sppPublic.allowStaffSelection);
            const confirmStepNum = staffEnabled ? 4 : 3;
            const dateStepNum = staffEnabled ? 3 : 2;
            const startDate = new Date(slotData.start_at);
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateDisplay = startDate.toLocaleDateString(undefined, dateOptions);
            const priceStr = service.price > 0 ? ('$' + parseFloat(service.price).toFixed(2)) : 'Free';

            let html = '<div class="spp-booking-steps">' +
                this._bookingStepIndicator(confirmStepNum, selectedStaff) +
                '<div class="spp-booking-confirm-card">' +
                '<div class="spp-booking-confirm-row">' +
                '<span class="spp-booking-confirm-label">Service</span>' +
                '<span class="spp-booking-confirm-value">' + SPPPortal.ui.escapeHtml(service.name) + '</span>' +
                '</div>';

            // Show staff row if a specific staff member was chosen
            if (selectedStaff) {
                html += '<div class="spp-booking-confirm-row">' +
                    '<span class="spp-booking-confirm-label">Staff</span>' +
                    '<span class="spp-booking-confirm-value">' + SPPPortal.ui.escapeHtml(selectedStaff.name) + '</span>' +
                    '</div>';
            }

            html += '<div class="spp-booking-confirm-row">' +
                '<span class="spp-booking-confirm-label">Date</span>' +
                '<span class="spp-booking-confirm-value">' + SPPPortal.ui.escapeHtml(dateDisplay) + '</span>' +
                '</div>' +
                '<div class="spp-booking-confirm-row">' +
                '<span class="spp-booking-confirm-label">Time</span>' +
                '<span class="spp-booking-confirm-value">' + SPPPortal.ui.escapeHtml(slotData.time_label) + '</span>' +
                '</div>' +
                '<div class="spp-booking-confirm-row">' +
                '<span class="spp-booking-confirm-label">Duration</span>' +
                '<span class="spp-booking-confirm-value">' + service.duration + ' minutes</span>' +
                '</div>' +
                '<div class="spp-booking-confirm-row">' +
                '<span class="spp-booking-confirm-label">Price</span>' +
                '<span class="spp-booking-confirm-value">' + priceStr + '</span>' +
                '</div>' +
                '</div>' +
                '<div id="spp-booking-errors" class="spp-gc-form__errors"></div>' +
                '<button type="button" class="spp-portal__btn spp-portal__btn--primary spp-w-full" id="spp-booking-confirm-btn">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                'Confirm Booking' +
                '</button></div>';

            $modal.find('.spp-modal-content').html(html);

            // Back to date picker (step before confirm)
            $modal.off('click.bookingBackDate').on('click.bookingBackDate', '.spp-booking-back-to-step-' + dateStepNum, function () {
                self._bookingRenderDatePicker($modal, services, service, selectedStaff);
            });

            // Back to services (step 1)
            $modal.off('click.bookingBackStep1').on('click.bookingBackStep1', '.spp-booking-back-to-step-1', function () {
                self._bookingRenderServices($modal, services);
            });

            // Back to staff picker (step 2, only when staff enabled)
            if (staffEnabled) {
                $modal.off('click.bookingBackStep2').on('click.bookingBackStep2', '.spp-booking-back-to-step-2', function () {
                    self._bookingRenderStaffPicker($modal, services, service);
                });
            }

            // Confirm booking
            $modal.off('click.bookingConfirm').on('click.bookingConfirm', '#spp-booking-confirm-btn', async function () {
                const $btn = $(this);
                const $errBox = $('#spp-booking-errors');
                $errBox.text('');

                self.ui.setButtonLoading($btn, true, 'Booking...');

                try {
                    await self.api.post('spp_public_create_appointment', {
                        start_at: slotData.start_at,
                        service_variation_id: service.id,
                        team_member_id: slotData.team_member_id
                    });

                    self.ui.hideModal();
                    self.ui.showNotification('Appointment booked successfully!', 'success');
                    self.components.loadView('bookings');

                } catch (error) {
                    $errBox.text(error.message || 'Failed to book appointment. Please try again.');
                    self.ui.setButtonLoading($btn, false);
                }
            });
        },

        // ============================================================
        // LEGACY COMPATIBILITY
        // ============================================================

        /**
         * Legacy showNotification method for backwards compatibility.
         *
         * @deprecated Use SPPPortal.ui.showNotification() instead.
         * @param {string} message - Message to display.
         * @param {string} [type='error'] - Notification type.
         * @returns {jQuery} The notification element.
         */
        showNotification: function (message, type = 'error') {
            return this.ui.showNotification(message, type);
        }
    };

    // Expose SPPPortal globally
    window.SPPPortal = SPPPortal;

    // Initialize on document ready
    $(document).ready(function () {
        SPPPortal.init();
    });

})(jQuery);
