<?php
/**
 * Fired during plugin deactivation.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Deactivator
 *
 * Handles plugin deactivation tasks.
 */
class SPP_Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Clears scheduled events and flushes rewrite rules.
     * Does NOT delete data - that's handled by uninstall.php.
     */
    public static function deactivate() {
        // Clear sync cron events.
        wp_clear_scheduled_hook( 'spp_sync_customers' );
        wp_clear_scheduled_hook( 'spp_sync_invoices' );
        wp_clear_scheduled_hook( 'spp_sync_subscriptions' );
        wp_clear_scheduled_hook( 'spp_sync_transactions' );
        wp_clear_scheduled_hook( 'spp_sync_catalog' );
        wp_clear_scheduled_hook( 'spp_sync_orders' );
        wp_clear_scheduled_hook( 'spp_sync_gift_card_activities' );

        // Clear notification reminder events.
        wp_clear_scheduled_hook( 'spp_send_appointment_reminders' );
        wp_clear_scheduled_hook( 'spp_send_invoice_reminders' );

        // Clear legacy cron events.
        wp_clear_scheduled_hook( 'spp_subscription_sync' );
        wp_clear_scheduled_hook( 'spp_token_refresh_check' );
        wp_clear_scheduled_hook( 'spp_invoice_sync' );
        wp_clear_scheduled_hook( 'spp_customer_sync' );

        // Permanently delete portal pages so they are recreated fresh on reactivation.
        self::delete_pages();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Permanently delete portal pages and clear their stored IDs.
     */
    private static function delete_pages() {
        $page_options = array(
            'spp_portal_page_id',
            'spp_login_page_id',
            'spp_register_page_id',
        );

        foreach ( $page_options as $option ) {
            $page_id = (int) get_option( $option, 0 );
            if ( $page_id ) {
                wp_delete_post( $page_id, true ); // true = bypass trash, force delete.
            }
            delete_option( $option );
        }
    }
}
