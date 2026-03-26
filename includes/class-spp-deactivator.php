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
        
        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
