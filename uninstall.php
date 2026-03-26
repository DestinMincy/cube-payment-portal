<?php
/**
 * Uninstall script.
 *
 * Fired when the plugin is deleted (not just deactivated).
 * Removes all plugin data from the database.
 *
 * @package CubePaymentPortal
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_transactions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_subscriptions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_invoices" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_customers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_orders" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_catalog_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spp_bookings" );

// Delete all plugin options.
$options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'spp_%'"
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Delete user meta.
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'spp_%'"
);

// Remove custom roles.
remove_role( 'spp_client' );
remove_role( 'spp_client_placeholder' );

// Clear any transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_spp_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_spp_%'"
);
