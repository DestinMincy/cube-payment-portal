<?php
/**
 * Bookings dashboard view.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'bookings';
?>
<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Bookings & Appointments', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Bookings & Appointments', 'cube-payment-portal' ); ?></h2>
    
    <nav class="nav-tab-wrapper">
        <a href="?page=spp-bookings&tab=bookings" class="nav-tab <?php echo $tab === 'bookings' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Bookings', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-bookings&tab=services" class="nav-tab <?php echo $tab === 'services' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Services', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-bookings&tab=staff" class="nav-tab <?php echo $tab === 'staff' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Staff', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-bookings&tab=calendar" class="nav-tab <?php echo $tab === 'calendar' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Calendar', 'cube-payment-portal' ); ?>
        </a>
    </nav>
    
    <div class="spp-bookings-content">
        <?php
        switch ( $tab ) {
            case 'services':
                if ( file_exists( SPP_PLUGIN_DIR . 'admin/partials/bookings-services.php' ) ) {
                    require_once SPP_PLUGIN_DIR . 'admin/partials/bookings-services.php';
                } else {
                    echo '<p>' . esc_html__( 'Services management coming soon.', 'cube-payment-portal' ) . '</p>';
                }
                break;
            case 'staff':
                if ( file_exists( SPP_PLUGIN_DIR . 'admin/partials/bookings-staff.php' ) ) {
                    require_once SPP_PLUGIN_DIR . 'admin/partials/bookings-staff.php';
                } else {
                    echo '<p>' . esc_html__( 'Staff management coming soon.', 'cube-payment-portal' ) . '</p>';
                }
                break;
            case 'calendar':
                if ( file_exists( SPP_PLUGIN_DIR . 'admin/partials/bookings-calendar.php' ) ) {
                    require_once SPP_PLUGIN_DIR . 'admin/partials/bookings-calendar.php';
                } else {
                    echo '<p>' . esc_html__( 'Calendar view coming soon.', 'cube-payment-portal' ) . '</p>';
                }
                break;
            case 'bookings':
            default:
                $view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : 'list';
                if ( 'detail' === $view && file_exists( SPP_PLUGIN_DIR . 'admin/partials/bookings-detail.php' ) ) {
                    require_once SPP_PLUGIN_DIR . 'admin/partials/bookings-detail.php';
                } elseif ( file_exists( SPP_PLUGIN_DIR . 'admin/partials/bookings-list.php' ) ) {
                    require_once SPP_PLUGIN_DIR . 'admin/partials/bookings-list.php';
                } else {
                    echo '<p>' . esc_html__( 'Bookings list coming soon.', 'cube-payment-portal' ) . '</p>';
                }
                break;
        }
        ?>
    </div>
</div>