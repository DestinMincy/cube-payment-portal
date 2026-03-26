<?php
/**
 * Bookings calendar view — Square Dashboard style.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Fetch staff for filter.
if ( ! class_exists( 'SPP_Bookings' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
}
$bookings_api  = new SPP_Bookings();
$team_members  = array();
$result        = $bookings_api->list_team_member_booking_profiles( true );
if ( ! is_wp_error( $result ) ) {
    $team_members = $result['team_member_booking_profiles'] ?? array();
}
?>

<!-- ── Custom Calendar Header ────────────────────────────────────── -->
<div class="spp-cal-header">
    <!-- Left: Navigation Group -->
    <div class="spp-cal-header__nav-group">
        <button type="button" id="spp-cal-prev" class="spp-cal-nav-arrow" title="<?php esc_attr_e( 'Previous', 'cube-payment-portal' ); ?>">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </button>
        <button type="button" id="spp-cal-next" class="spp-cal-nav-arrow" title="<?php esc_attr_e( 'Next', 'cube-payment-portal' ); ?>">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </button>
        <h2 id="spp-cal-title" class="spp-cal-title"></h2>
    </div>

    <!-- Right: Actions -->
    <div class="spp-cal-header__actions">
        <!-- Range Buttons -->
        <div class="spp-cal-range-group">
            <button type="button" class="spp-cal-range-btn is-active" data-view="dayGridMonth"><?php esc_html_e( 'Month', 'cube-payment-portal' ); ?></button>
            <button type="button" class="spp-cal-range-btn" data-view="timeGridWeek"><?php esc_html_e( 'Week', 'cube-payment-portal' ); ?></button>
            <button type="button" class="spp-cal-range-btn" data-view="timeGridDay"><?php esc_html_e( 'Day', 'cube-payment-portal' ); ?></button>
        </div>

        <!-- Staff Filter -->
        <select id="spp-calendar-staff-filter" class="spp-cal-staff-select">
            <option value=""><?php esc_html_e( 'All Staff', 'cube-payment-portal' ); ?></option>
            <?php foreach ( $team_members as $member ) : ?>
                <?php
                    $name = $member['display_name'] ?? '';
                    if ( empty( $name ) ) continue;
                ?>
                <option value="<?php echo esc_attr( $member['team_member_id'] ); ?>"><?php echo esc_html( $name ); ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Refresh -->
        <button type="button" id="spp-calendar-refresh" class="spp-cal-icon-btn" title="<?php esc_attr_e( 'Refresh', 'cube-payment-portal' ); ?>">
            <span class="dashicons dashicons-update"></span>
        </button>
    </div>
</div>

<!-- ── Calendar Container ────────────────────────────────────────── -->
<div class="spp-cal-wrap">
    <div id="spp-calendar"></div>
</div>

<!-- ── Calendar Styles ───────────────────────────────────────────── -->
<style>
/* ================================================================
   SPP Calendar — Square-Inspired Theme
   ================================================================ */

/* ── Header Bar ─────────────────────────────────────────────────── */
.spp-cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 0;
    margin-bottom: 2px;
    gap: 16px;
    flex-wrap: wrap;
}

.spp-cal-header__nav-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Title */
.spp-cal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1a1a1a;
}

/* Navigation Arrow Buttons (Circles) */
.spp-cal-nav-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid #d9d9d9;
    border-radius: 50%;
    background: #fff;
    color: #1a1a1a;
    cursor: pointer;
    transition: all 0.15s ease;
}
.spp-cal-nav-arrow:hover {
    background: #f5f5f5;
    border-color: #bbb;
}
.spp-cal-nav-arrow .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    line-height: 18px;
}

/* ── Right Actions ──────────────────────────────────────────────── */
.spp-cal-header__actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Range Buttons */
.spp-cal-range-group {
    display: inline-flex;
    border: 1px solid #d9d9d9;
    border-radius: 6px;
    overflow: hidden;
    background: #fff;
}
.spp-cal-range-btn {
    padding: 7px 16px;
    border: none;
    border-right: 1px solid #d9d9d9;
    background: #fff;
    color: #555;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    font-family: inherit;
}
.spp-cal-range-btn:last-child {
    border-right: none;
}
.spp-cal-range-btn:hover {
    background: #f5f5f5;
    color: #1a1a1a;
}
.spp-cal-range-btn.is-active {
    background: #1a1a1a;
    color: #fff;
}

/* Staff Dropdown */
.spp-cal-staff-select {
    height: 36px;
    padding: 0 28px 0 16px;
    border: 1px solid #d9d9d9;
    border-radius: 50px; /* Pill */
    background: #fff;
    color: #1a1a1a;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    min-width: 140px;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23555' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M4 6l4 4 4-4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 10px;
    font-family: inherit;
}
.spp-cal-staff-select:focus {
    border-color: #006aff;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 106, 255, 0.15);
}

/* Refresh Icon Button */
.spp-cal-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid #d9d9d9;
    border-radius: 6px;
    background: #fff;
    color: #555;
    cursor: pointer;
    transition: all 0.15s ease;
}
.spp-cal-icon-btn:hover {
    background: #f5f5f5;
    border-color: #bbb;
    color: #1a1a1a;
}
.spp-cal-icon-btn .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    line-height: 18px;
}
@keyframes spp-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.spp-cal-icon-btn .dashicons.is-spinning {
    animation: spp-spin 0.6s linear;
}

/* ── Calendar Container ─────────────────────────────────────────── */
.spp-cal-wrap {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
    margin-top: 16px;
}

/* ── FullCalendar Overrides ─────────────────────────────────────── */
#spp-calendar {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #1a1a1a;
}

/* Hide FC built-in toolbar (we have our own) */
.fc .fc-toolbar {
    display: none !important;
}

/* Column Headers (Sun Mon Tue …) */
.fc .fc-col-header {
    border-bottom: 1px solid #e0e0e0;
}
.fc .fc-col-header-cell {
    padding: 12px 0 !important;
    border: none !important;
    background: transparent;
}
.fc .fc-col-header-cell-cushion {
    color: #717171;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    text-decoration: none !important;
}

/* Day Grid Cells */
.fc th { border: none !important; }
.fc td { border-color: #ebebeb !important; }
.fc .fc-daygrid-day { min-height: 100px; }
.fc .fc-daygrid-day-frame { padding: 0 !important; min-height: 100px; }
.fc .fc-scrollgrid { border: none !important; }
.fc .fc-scrollgrid td:last-of-type { border-right: none !important; }
.fc .fc-scrollgrid-section > td { border: none !important; }

/* Day Numbers */
.fc .fc-daygrid-day-top {
    display: flex;
    justify-content: flex-end;
    padding: 6px 8px 2px;
}
.fc .fc-daygrid-day-number {
    color: #1a1a1a;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none !important;
    line-height: 1;
}
.fc .fc-day-other .fc-daygrid-day-number {
    color: #bbb;
    font-weight: 400;
}

/* Today */
.fc .fc-day-today {
    background-color: transparent !important;
}
.fc .fc-day-today .fc-daygrid-day-number {
    background: #006aff;
    color: #fff !important;
    border-radius: 50%;
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}

/* ── Events ────────────────────────────────────────────────────── */
.fc .fc-daygrid-event {
    border: none !important;
    border-radius: 4px !important;
    margin: 1px 4px 2px !important;
    transition: transform 0.1s ease, box-shadow 0.1s ease;
}
.fc .fc-daygrid-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.fc .fc-daygrid-block-event .fc-event-main {
    padding: 3px 6px !important;
    font-weight: 500;
    font-size: 12px;
    line-height: 1.4;
    color: #fff !important;
}
.fc .fc-event-time { font-weight: 600 !important; margin-right: 4px; }
.fc .fc-event-title { font-weight: 500 !important; }

/* Dot Events */
.fc .fc-daygrid-dot-event { padding: 3px 4px; border-radius: 4px; }
.fc .fc-daygrid-dot-event:hover { background: rgba(0, 106, 255, 0.08); }
.fc .fc-daygrid-more-link { color: #006aff; font-weight: 600; font-size: 12px; }

/* ── Week / Day View ───────────────────────────────────────────── */
.fc .fc-timegrid-slot { height: 48px; border-color: #ebebeb !important; }
.fc .fc-timegrid-slot-label-cushion { color: #999; font-size: 11px; font-weight: 500; }
.fc .fc-timegrid-event { border: none !important; border-radius: 4px !important; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12); }
.fc .fc-timegrid-event .fc-event-main { padding: 4px 8px !important; font-size: 12px; color: #fff !important; }
.fc .fc-timegrid-axis { border: none !important; }

</style>

<!-- Booking Detail Modal -->
<div id="spp-booking-detail-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 500px; max-height: 90vh; overflow-y: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        
        <!-- Header -->
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 18px; color: #333;"><?php esc_html_e( 'Booking Details', 'cube-payment-portal' ); ?></h2>
            <button type="button" id="spp-detail-modal-close" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
        </div>
        
        <!-- Content -->
        <div id="spp-detail-modal-content" style="padding: 24px;">
            <div style="text-align: center; color: #666; padding: 20px;">
                <span class="dashicons dashicons-update is-spinning" style="font-size: 24px; width: 24px; height: 24px;"></span>
                <p><?php esc_html_e( 'Loading details...', 'cube-payment-portal' ); ?></p>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #eee; background: #fcfcfc; text-align: right; border-radius: 0 0 8px 8px;">
            <a href="#" id="spp-detail-edit-link" class="button button-primary">
                <?php esc_html_e( 'Edit Booking', 'cube-payment-portal' ); ?>
            </a>
            <button type="button" id="spp-detail-modal-close-btn" class="button">
                <?php esc_html_e( 'Close', 'cube-payment-portal' ); ?>
            </button>
        </div>

    </div>
</div>
