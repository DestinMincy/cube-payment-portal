<?php
/**
 * Plugin Name: Cube Payment Portal
 * Plugin URI: https://destinlmincy.com/cube-payment-portal
 * Description: A comprehensive payment portal integrating Square for client payments, subscriptions, and invoicing.
 * Version: 1.1.7
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Destin L. Mincy
 * Author URI: https://destinlmincy.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: cube-payment-portal
 * Domain Path: /languages
 *
 * @package CubePaymentPortal
 * @author Destin L. Mincy
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Plugin version.
 */
define( 'SPP_VERSION', '1.1.7' );

/**
 * Plugin directory path.
 */
define( 'SPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'SPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'SPP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum PHP version required.
 */
define( 'SPP_MINIMUM_PHP_VERSION', '7.4' );

/**
 * Minimum WordPress version required.
 */
define( 'SPP_MINIMUM_WP_VERSION', '5.0' );

/**
 * Check if requirements are met before loading plugin.
 *
 * @return bool True if requirements are met.
 */
function spp_requirements_met() {
    // Check PHP version.
    if ( version_compare( PHP_VERSION, SPP_MINIMUM_PHP_VERSION, '<' ) ) {
        return false;
    }

    // Check WordPress version.
    if ( version_compare( get_bloginfo( 'version' ), SPP_MINIMUM_WP_VERSION, '<' ) ) {
        return false;
    }

    // Check for SSL (required for Square).
    if ( ! is_ssl() && ! defined( 'SPP_SKIP_SSL_CHECK' ) ) {
        return false;
    }

    return true;
}

/**
 * Display admin notice if requirements not met.
 */
function spp_requirements_notice() {
    $messages = array();

    if ( version_compare( PHP_VERSION, SPP_MINIMUM_PHP_VERSION, '<' ) ) {
        $messages[] = sprintf(
            /* translators: 1: Current PHP version, 2: Required PHP version */
            __( 'Cube Payment Portal requires PHP %2$s or higher. You are running PHP %1$s.', 'cube-payment-portal' ),
            PHP_VERSION,
            SPP_MINIMUM_PHP_VERSION
        );
    }

    if ( version_compare( get_bloginfo( 'version' ), SPP_MINIMUM_WP_VERSION, '<' ) ) {
        $messages[] = sprintf(
            /* translators: 1: Current WP version, 2: Required WP version */
            __( 'Cube Payment Portal requires WordPress %2$s or higher. You are running WordPress %1$s.', 'cube-payment-portal' ),
            get_bloginfo( 'version' ),
            SPP_MINIMUM_WP_VERSION
        );
    }

    if ( ! is_ssl() && ! defined( 'SPP_SKIP_SSL_CHECK' ) ) {
        $messages[] = __( 'Cube Payment Portal requires an SSL certificate. Please enable HTTPS on your site.', 'cube-payment-portal' );
    }

    if ( ! empty( $messages ) ) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__( 'Cube Payment Portal', 'cube-payment-portal' ) . '</strong><br>';
        echo wp_kses( implode( '<br>', array_map( 'esc_html', $messages ) ), array( 'br' => array() ) );
        echo '</p></div>';
    }
}

/**
 * Run activation hook.
 */
function spp_activate() {
    require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
    require_once SPP_PLUGIN_DIR . 'includes/class-spp-activator.php';
    SPP_Activator::activate();
}

/**
 * Run deactivation hook.
 */
function spp_deactivate() {
    require_once SPP_PLUGIN_DIR . 'includes/class-spp-deactivator.php';
    SPP_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'spp_activate' );
register_deactivation_hook( __FILE__, 'spp_deactivate' );

/**
 * Load the plugin.
 */
function spp_init() {
    // Check requirements.
    if ( ! spp_requirements_met() ) {
        add_action( 'admin_notices', 'spp_requirements_notice' );
        return;
    }

    // Load the main plugin class.
    require_once SPP_PLUGIN_DIR . 'includes/class-spp-plugin.php';

    // Initialize the plugin.
    $plugin = new SPP_Plugin();
    $plugin->run();
}

add_action( 'plugins_loaded', 'spp_init' );

/**
 * Add custom cron schedules for sync frequency options.
 *
 * @param array $schedules Existing schedules.
 * @return array Modified schedules.
 */
function spp_add_cron_schedule( $schedules ) {
	// 15 minutes.
	if ( ! isset( $schedules['spp_15min'] ) ) {
		$schedules['spp_15min'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 Minutes', 'cube-payment-portal' ),
		);
	}
	
	// 30 minutes.
	if ( ! isset( $schedules['spp_30min'] ) ) {
		$schedules['spp_30min'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 Minutes', 'cube-payment-portal' ),
		);
	}
	
	// Hourly (existing).
	if ( ! isset( $schedules['spp_hourly'] ) ) {
		$schedules['spp_hourly'] = array(
			'interval' => 3600,
			'display'  => __( 'Once Hourly', 'cube-payment-portal' ),
		);
	}
	
	// Every 6 hours.
	if ( ! isset( $schedules['spp_6hours'] ) ) {
		$schedules['spp_6hours'] = array(
			'interval' => 21600,
			'display'  => __( 'Every 6 Hours', 'cube-payment-portal' ),
		);
	}
	
	// Daily.
	if ( ! isset( $schedules['spp_daily'] ) ) {
		$schedules['spp_daily'] = array(
			'interval' => 86400,
			'display'  => __( 'Once Daily', 'cube-payment-portal' ),
		);
	}
	
	return $schedules;
}
add_filter( 'cron_schedules', 'spp_add_cron_schedule' );

/**
 * Generic sync runner that handles locking and error logging.
 *
 * @param string   $sync_type   Type of sync (customers, invoices, etc.).
 * @param callable $sync_action Callback to perform the sync.
 */
function spp_run_sync_with_lock( $sync_type, $sync_action ) {
	$lock_key = 'spp_' . $sync_type . '_sync_lock';

	// Prevent overlapping execution with a lock.
	if ( get_transient( $lock_key ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SPP ' . ucfirst( $sync_type ) . ' Sync Cron: Skipped - previous sync still running.' );
		}
		return;
	}

	// Set lock for 5 minutes.
	set_transient( $lock_key, true, 5 * MINUTE_IN_SECONDS );

	try {
		$result = call_user_func( $sync_action );

		// Update last sync timestamp so the admin UI reflects cron runs.
		update_option( 'spp_' . $sync_type . '_last_sync', time() );

		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SPP ' . ucfirst( $sync_type ) . ' Sync Cron Error: ' . $result->get_error_message() );
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$synced = isset( $result['synced'] ) ? $result['synced'] : ( isset( $result['from_square'] ) ? $result['from_square'] : 0 );
			$errors = isset( $result['errors'] ) ? $result['errors'] : 0;
			error_log( 'SPP ' . ucfirst( $sync_type ) . ' Sync Cron: Synced ' . $synced . ' with ' . $errors . ' errors' );
		}
	} finally {
		delete_transient( $lock_key );
	}
}

/**
 * Get the interval in seconds for a given schedule slug.
 *
 * @param string $schedule Schedule slug (e.g. 'spp_30min', 'spp_hourly').
 * @return int Interval in seconds, or 3600 as fallback.
 */
function spp_get_schedule_interval( $schedule ) {
	$schedules = wp_get_schedules();
	if ( isset( $schedules[ $schedule ]['interval'] ) ) {
		return (int) $schedules[ $schedule ]['interval'];
	}
	return 3600; // Fallback to 1 hour.
}

/**
 * Run customers sync cron job.
 */
function spp_run_sync_customers() {
	if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
	}
	
	spp_run_sync_with_lock( 'customers', function() {
		$sync = new SPP_Sync_Customers();
		return $sync->sync_all();
	});
}
add_action( 'spp_sync_customers', 'spp_run_sync_customers' );
add_action( 'spp_customer_sync', 'spp_run_sync_customers' ); // Legacy hook support.

/**
 * Run invoices sync cron job.
 */
function spp_run_sync_invoices() {
	if ( ! get_option( 'spp_feature_invoices', '1' ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
	}
	
	spp_run_sync_with_lock( 'invoices', function() {
		$sync = new SPP_Sync_Invoices();
		return $sync->sync_from_square();
	});
}
add_action( 'spp_sync_invoices', 'spp_run_sync_invoices' );
add_action( 'spp_invoice_sync', 'spp_run_sync_invoices' ); // Legacy hook support.

/**
 * Run subscriptions sync cron job.
 */
function spp_run_sync_subscriptions() {
	if ( ! get_option( 'spp_feature_subscriptions', '1' ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Sync_Subscriptions' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
	}
	
	spp_run_sync_with_lock( 'subscriptions', function() {
		$sync = new SPP_Sync_Subscriptions();
		return $sync->sync_from_square();
	});
}
add_action( 'spp_sync_subscriptions', 'spp_run_sync_subscriptions' );

/**
 * Run transactions sync cron job.
 */
function spp_run_sync_transactions() {
	if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
	}
	
	spp_run_sync_with_lock( 'transactions', function() {
		$sync = new SPP_Sync_Transactions();
		return $sync->sync_from_square();
	});
}
add_action( 'spp_sync_transactions', 'spp_run_sync_transactions' );

/**
 * Run gift card activities sync cron job.
 */
function spp_run_sync_gift_card_activities() {
	if ( ! get_option( 'spp_feature_gift_cards', '1' ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Sync_Gift_Card_Activities' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-gift-card-activities.php';
	}

	spp_run_sync_with_lock( 'gift_card_activities', function() {
		$sync = new SPP_Sync_Gift_Card_Activities();
		return $sync->sync_all();
	});
}
add_action( 'spp_sync_gift_card_activities', 'spp_run_sync_gift_card_activities' );

/**
 * Run catalog sync cron job.
 */
function spp_run_sync_catalog() {
	if ( ! get_option( 'spp_feature_catalog', '1' ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
	}
	
	spp_run_sync_with_lock( 'catalog', function() {
		$sync = new SPP_Sync_Catalog();
		return $sync->sync_from_square();
	});
}
add_action( 'spp_sync_catalog', 'spp_run_sync_catalog' );

/**
 * Run orders sync cron job.
 */
function spp_run_sync_orders() {
	if ( ! get_option( 'spp_feature_orders', '1' ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
	}
	
	spp_run_sync_with_lock( 'orders', function() {
		$sync = new SPP_Sync_Orders();
		return $sync->sync_from_square();
	});
}
add_action( 'spp_sync_orders', 'spp_run_sync_orders' );

/**
 * Handle gift card activity webhook in real-time.
 *
 * When a gift card is redeemed at POS or activities happen outside the portal,
 * the webhook fires this action. We process the activity immediately so it
 * appears in the user's transaction history without waiting for the next sync.
 *
 * @param string $activity_id Activity ID from Square.
 * @param array  $activity    Full activity data.
 */
function spp_handle_gift_card_activity_webhook( $activity_id, $activity ) {
	if ( empty( $activity_id ) || empty( $activity ) ) {
		return;
	}

	$gift_card_id = $activity['gift_card_id'] ?? '';
	if ( empty( $gift_card_id ) ) {
		return;
	}

	if ( ! class_exists( 'SPP_Gift_Cards' ) ) {
		require_once SPP_PLUGIN_DIR . 'api/class-spp-gift-cards.php';
	}

	$gc_api    = new SPP_Gift_Cards();
	$gift_card = $gc_api->get_gift_card( $gift_card_id );

	if ( is_wp_error( $gift_card ) ) {
		return;
	}

	$customer_ids = $gift_card['customer_ids'] ?? array();
	if ( empty( $customer_ids ) ) {
		return; // Unlinked card — can't attribute to a user.
	}

	if ( ! class_exists( 'SPP_Sync_Gift_Card_Activities' ) ) {
		require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-gift-card-activities.php';
	}

	$sync = new SPP_Sync_Gift_Card_Activities();

	foreach ( $customer_ids as $sq_customer_id ) {
		$wp_user_id = $sync->map_customer_id( $sq_customer_id );
		if ( $wp_user_id ) {
			$sync->process_activity( $activity, $sq_customer_id, $wp_user_id );
			break; // Process for the primary linked customer.
		}
	}
}
add_action( 'spp_gift_card_activity_created', 'spp_handle_gift_card_activity_webhook', 10, 2 );
