<?php
/**
 * Sync Manager functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Manager
 *
 * Orchestrates bidirectional sync between WordPress and Square.
 */
class SPP_Sync_Manager {

    /**
     * Run the sync process.
     */
    public function run_sync() {
        $direction = get_option( 'spp_sync_direction', 'bidirectional' );

        $subscription_sync = new SPP_Sync_Subscriptions();

        switch ( $direction ) {
            case 'wp_to_square':
                $result = $subscription_sync->sync_to_square();
                break;
            case 'square_to_wp':
                $subscription_sync->sync_from_square();
                break;
            case 'bidirectional':
            default:
                $subscription_sync->sync_bidirectional();
                break;
        }
    }
}
