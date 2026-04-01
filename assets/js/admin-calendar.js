/**
 * SPP Calendar — Square-style bookings calendar.
 *
 * Wires FullCalendar v6 to custom header controls defined in
 * bookings-calendar.php.  The built-in toolbar is hidden via CSS;
 * navigation, view switching, and filtering happen through our own UI.
 */
jQuery(document).ready(function ($) {
    'use strict';

    var calendarEl = document.getElementById('spp-calendar');
    if (!calendarEl) return;

    // ── Functions ────────────────────────────────────────────────

    function updateTitle(view) {
        $('#spp-cal-title').text(view.title);
    }
    var calendar = new FullCalendar.Calendar(calendarEl, {

        /* Views */
        initialView: 'dayGridMonth',
        headerToolbar: false,            // We supply our own header
        firstDay: 0,                     // Sunday

        /* Sizing */
        height: 'auto',
        expandRows: true,
        dayMaxEvents: 3,                 // "+2 more" link after 3

        /* Time formatting — 12-hour, e.g. "9:00 AM" */
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        },
        slotLabelFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        },

        /* Event rendering */
        eventDisplay: 'block',
        displayEventTime: true,
        displayEventEnd: false,
        nowIndicator: true,              // Red "now" line in Week/Day

        /* Business-hour shading (optional, subtle guide) */
        businessHours: {
            daysOfWeek: [1, 2, 3, 4, 5],
            startTime: '08:00',
            endTime: '18:00'
        },

        /* Data source */
        events: {
            url: sppCalendar.ajaxUrl,
            method: 'GET',
            extraParams: function () {
                return {
                    action: 'spp_get_calendar_events',
                    nonce: sppCalendar.nonce,
                    team_member_id: $('#spp-calendar-staff-filter').val()
                };
            },
            failure: function () {
                sppCalendar && sppCalendar.debug && console.error('[SPP Calendar] Failed to load events.');
            }
        },

        /* When FullCalendar changes its date range, update header title */
        datesSet: function (info) {
            updateTitle(info.view);
        },

        /* Click → open modal */
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            // Event click handler.
            if (typeof openBookingModal === 'function') {
                openBookingModal(info.event);
            } else {
                // Fallback if modal function missing
                if (info.event.url) window.location.href = info.event.url;
            }
        },

        /* Rich tooltip */
        eventDidMount: function (info) {
            var ev = info.event;
            var svc = ev.extendedProps.service_name || '';
            var stat = ev.extendedProps.status || '';
            var tip = ev.title;
            if (svc) tip += '\n' + svc;
            if (stat) tip += ' — ' + stat.replace(/_/g, ' ').toLowerCase();
            info.el.title = tip;
        }
    });

    calendar.render();

    // ── Custom Header Wiring ────────────────────────────────────

    // Navigation
    $('#spp-cal-prev').on('click', function () { calendar.prev(); });
    $('#spp-cal-next').on('click', function () { calendar.next(); });

    // Range Buttons
    $('.spp-cal-range-btn').on('click', function () {
        var view = $(this).data('view');
        if (view) {
            calendar.changeView(view);
            $('.spp-cal-range-btn').removeClass('is-active');
            $(this).addClass('is-active');
        }
    });

    // Staff Filter
    $('#spp-calendar-staff-filter').on('change', function () {
        calendar.refetchEvents();
    });

    // Refresh
    $('#spp-calendar-refresh').on('click', function () {
        var btn = $(this);
        var icon = btn.find('.dashicons');
        icon.addClass('is-spinning');
        calendar.refetchEvents();
        setTimeout(function () { icon.removeClass('is-spinning'); }, 600);
    });

    // ── Modal Logic ─────────────────────────────────────────────

    var $modal = $('#spp-booking-detail-modal');
    var $modalContent = $('#spp-detail-modal-content');
    var $editLink = $('#spp-detail-edit-link');

    // Close Modal Logic
    function closeModal() {
        $modal.animate({ opacity: 0 }, 200, function () {
            $(this).css('display', 'none');
        });
    }

    // Close on X
    $('#spp-detail-modal-close, #spp-detail-modal-close-btn').on('click', closeModal);

    // Close on background click
    $modal.on('click', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    function openBookingModal(event) {
        // Show modal with loading state - Use Flex for centering
        $modal.css({ display: 'flex', opacity: 0 }).animate({ opacity: 1 }, 200);
        $modalContent.html('<div style="text-align: center; color: #666; padding: 20px;"><span class="dashicons dashicons-update is-spinning" style="font-size: 24px; color: #006aff;"></span><p style="margin-top: 10px;">Loading details...</p></div>');
        $editLink.hide();

        // Fetch details
        $.ajax({
            url: sppCalendar.ajaxUrl,
            method: 'POST',
            data: {
                action: 'spp_get_booking_detail',
                nonce: sppCalendar.nonce,
                booking_id: event.id
            },
            success: function (response) {
                if (response.success) {
                    renderBookingDetails(response.data.booking);
                } else {
                    $modalContent.html('<div class="notice notice-error inline" style="margin: 20px;"><p>' + esc_html(response.data.message || 'Error loading details') + '</p></div>');
                }
            },
            error: function () {
                $modalContent.html('<div class="notice notice-error inline" style="margin: 20px;"><p>Connection error. Please try again.</p></div>');
            }
        });
    }

    function renderBookingDetails(booking) {
        // Parse dates safely
        var start = new Date(booking.start_at);
        var end = new Date(booking.end_at);

        // Simple formatting
        var optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        var optionsTime = { hour: 'numeric', minute: '2-digit' };

        var dateStr = start.toLocaleDateString(undefined, optionsDate);
        var timeStr = start.toLocaleTimeString(undefined, optionsTime) + ' - ' + end.toLocaleTimeString(undefined, optionsTime);

        // Status class mapping
        var statusClass = 'spp-status-pending';
        var st = booking.status;
        if (st === 'ACCEPTED') statusClass = 'spp-status-active';
        else if (['CANCELLED_BY_CUSTOMER', 'CANCELLED_BY_SELLER', 'DECLINED', 'NO_SHOW'].indexOf(st) !== -1) statusClass = 'spp-status-canceled';

        var html = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                
                <!-- Customer -->
                <div style="grid-column: 1 / -1; padding-bottom: 16px; border-bottom: 1px solid #f0f0f0;">
                    <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Customer</label>
                    <div style="font-size: 18px; font-weight: 600; color: #1a1a1a;">
                        ${esc_html(booking.customer_name)}
                    </div>
                    ${booking.customer_email ? `<div style="font-size: 13px; color: #555; margin-top: 2px;">${esc_html(booking.customer_email)}</div>` : ''}
                    ${booking.customer_phone ? `<div style="font-size: 13px; color: #555;">${esc_html(booking.customer_phone)}</div>` : ''}
                </div>

                <!-- Date & Time -->
                <div style="grid-column: 1 / -1;">
                     <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Time</label>
                     <div style="font-size: 15px; color: #1a1a1a; font-weight: 500;">${esc_html(dateStr)}</div>
                     <div style="font-size: 15px; color: #555;">${esc_html(timeStr)}</div>
                </div>

                <!-- Service -->
                <div>
                    <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Service</label>
                    <div style="font-size: 14px; font-weight: 500; color: #1a1a1a;">${esc_html(booking.service_name)}</div>
                </div>

                <!-- Team & Status -->
                <div>
                    <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Staff</label>
                    <div style="font-size: 14px; color: #1a1a1a;">${esc_html(booking.team_member_name)}</div>
                </div>

                <!-- Status Badge -->
                <div>
                    <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Status</label>
                    <div>
                        <span class="spp-status-badge ${esc_html(statusClass)}" style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; color: #fff; background-color: #777;">
                            ${esc_html(booking.status.replace(/_/g, ' '))}
                        </span>
                    </div>
                </div>

                 <!-- ID -->
                <div>
                    <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Reference ID</label>
                    <div style="font-size: 12px; color: #999; font-family: monospace;">${esc_html(booking.id)}</div>
                </div>

                 <!-- Note -->
                ${booking.seller_note ? `
                <div style="grid-column: 1 / -1; margin-top: 8px; padding: 12px; background: #fafafa; border-radius: 6px; border: 1px solid #eee;">
                     <label style="display: block; font-weight: 600; color: #717171; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Note</label>
                     <div style="font-size: 13px; color: #333; line-height: 1.5; font-style: italic;">${esc_html(booking.seller_note)}</div>
                </div>` : ''}

            </div>
        `;

        // Update Edit Link
        $editLink.attr('href', 'admin.php?page=spp-bookings&view=detail&id=' + booking.id).show();

        // Inject HTML
        $modalContent.html(html);

        // Apply fallback styles for badges if CSS classes missing
        var $badge = $modalContent.find('.spp-status-badge');
        if ($badge.hasClass('spp-status-active')) $badge.css('background-color', '#46b450');
        else if ($badge.hasClass('spp-status-canceled')) $badge.css('background-color', '#dc3232');
        else if ($badge.hasClass('spp-status-pending')) $badge.css('background-color', '#ffb900');
    }

    function esc_html(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function (m) {
            switch (m) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
            }
        });
    }

});
