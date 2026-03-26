<?php
/**
 * Subscription synchronization.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Subscriptions
 *
 * Handles bidirectional sync of subscriptions with Square.
 */
class SPP_Sync_Subscriptions {

    /**
     * Square API client.
     *
     * @var SPP_Square_Client
     */
    private $client;

    /**
     * Constructor.
     */
    public function __construct() {
        if ( ! class_exists( 'SPP_Square_Client' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-square-client.php';
        }
        $this->client = new SPP_Square_Client();
    }

    /**
     * Sync subscriptions from Square.
     *
     * @return array|WP_Error Result with synced count and errors, or error.
     */
    public function sync_from_square() {
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $synced = 0;
        $errors = 0;

        // Get the location ID - required for SearchSubscriptions.
        $location_id = $this->client->get_location_id();
        if ( empty( $location_id ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: No location ID configured.' );
            }
            return new WP_Error( 
                'missing_location', 
                __( 'Location ID is required for subscription sync. Please set a default location in Settings.', 'cube-payment-portal' ) 
            );
        }
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Subscription Sync: Starting sync with location ID: ' . $location_id );
        }

        // First, get all subscription plans to have plan names available.
        // NOTE: Subscriptions use plan_variation_id, not plan_id.
        // We need to build a lookup map keyed by VARIATION ID.
        $plans = $catalog->get_subscription_plans( array( 'include_inactive' => true ) );
        $plan_names = array();
        if ( is_wp_error( $plans ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: Failed to get plans - ' . $plans->get_error_message() );
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: Found ' . count( $plans ) . ' subscription plans.' );
            }
            foreach ( $plans as $plan ) {
                // Get variations from raw data.
                $variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();
                
                foreach ( $variations as $variation ) {
                    $var_id = $variation['id'];
                    $var_data = $variation['subscription_plan_variation_data'] ?? array();
                    $var_phases = $var_data['phases'] ?? array();
                    
                    // Get price from first phase.
                    $var_price = 0;
                    $var_currency = 'USD';
                    $var_cadence = 'MONTHLY';
                    if ( ! empty( $var_phases[0] ) ) {
                        $var_cadence = $var_phases[0]['cadence'] ?? 'MONTHLY';
                        if ( isset( $var_phases[0]['pricing']['price'] ) ) {
                            $var_price = ( $var_phases[0]['pricing']['price']['amount'] ?? 0 ) / 100;
                            $var_currency = $var_phases[0]['pricing']['price']['currency'] ?? 'USD';
                        }
                    }
                    
                    $plan_names[ $var_id ] = array(
                        'name'     => $plan['name'] . ' - ' . ( $var_data['name'] ?? 'Default' ),
                        'amount'   => $var_price,
                        'currency' => $var_currency,
                        'cadence'  => $var_cadence,
                        'plan_id'  => $plan['id'],
                    );
                    
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'SPP Subscription Sync: Mapped variation ' . $var_id . ' to plan ' . $plan['name'] );
                    }
                }
                
                // Also add the plan ID itself as a fallback.
                $plan_names[ $plan['id'] ] = array(
                    'name'     => $plan['name'],
                    'amount'   => $plan['price_amount'] / 100,
                    'currency' => $plan['price_currency'],
                    'cadence'  => $plan['cadence'],
                );
            }
        }

        // Search for all subscriptions with location filter (required by Square API).
        $cursor = null;
        $all_subscriptions = array();

        do {
            $body = array(
                'query' => array(
                    'filter' => array(
                        'location_ids' => array( $location_id ),
                    ),
                ),
                'limit' => 100,
            );

            if ( $cursor ) {
                $body['cursor'] = $cursor;
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: Calling SearchSubscriptions with body: ' . wp_json_encode( $body ) );
            }

            $response = $this->client->post( 'subscriptions/search', $body );

            if ( is_wp_error( $response ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Subscription Sync: API error - ' . $response->get_error_message() );
                }
                return $response;
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: API response: ' . wp_json_encode( $response ) );
            }

            $subscriptions = $response['subscriptions'] ?? array();
            $all_subscriptions = array_merge( $all_subscriptions, $subscriptions );

            $cursor = $response['cursor'] ?? null;
        } while ( $cursor );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Subscription Sync: Found ' . count( $all_subscriptions ) . ' subscriptions from Square.' );
        }

        // Process each subscription.
        foreach ( $all_subscriptions as $subscription ) {
            $result = $this->process_subscription( $subscription, $plan_names );
            if ( $result ) {
                $synced++;
            } else {
                $errors++;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Subscription Sync: Failed to process subscription: ' . wp_json_encode( $subscription ) );
                }
            }
        }

        // Update last sync time.
        update_option( 'spp_subscriptions_last_sync', time() );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Subscription Sync: Completed. Synced: ' . $synced . ', Errors: ' . $errors );
        }

        return array(
            'synced' => $synced,
            'errors' => $errors,
            'total'  => count( $all_subscriptions ),
        );
    }

    /**
     * Sync plans from WordPress to Square (placeholder).
     */
    public function sync_to_square() {
        // Plans are managed in Square Dashboard, so this is a placeholder.
        // Future: Could implement plan creation from WordPress.
    }

    /**
     * Perform bidirectional sync.
     */
    public function sync_bidirectional() {
        return $this->sync_from_square();
    }

    /**
     * Process a single subscription from Square.
     *
     * @param array $subscription Square subscription data.
     * @param array $plan_names   Plan ID to name mapping.
     * @return bool Success status.
     */
    private function process_subscription( $subscription, $plan_names ) {
        // Get customer to find user_id.
        $user_id = 0;
        if ( ! empty( $subscription['customer_id'] ) ) {
            $customer = SPP_Database::get_customer_by_square_id( $subscription['customer_id'] );
            if ( $customer ) {
                $user_id = $customer['wp_user_id'] ?? 0;
            }
        }

        // Get plan info - Square API returns plan_variation_id (not plan_id).
        $plan_variation_id = $subscription['plan_variation_id'] ?? $subscription['plan_id'] ?? '';
        $plan_info = $plan_names[ $plan_variation_id ] ?? array();
        
        // Debug: Log if variation was found or not.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( empty( $plan_info ) ) {
                error_log( 'SPP Subscription Sync: WARNING - Variation ID "' . $plan_variation_id . '" NOT FOUND in plan_names map!' );
                error_log( 'SPP Subscription Sync: Available variation IDs: ' . implode( ', ', array_keys( $plan_names ) ) );
            } else {
                error_log( 'SPP Subscription Sync: Found variation "' . $plan_variation_id . '" with price ' . ( $plan_info['amount'] ?? 0 ) );
            }
        }

        // Get price - check multiple sources.
        $amount = $plan_info['amount'] ?? 0;
        $currency = $plan_info['currency'] ?? 'USD';
        
        // 1. Check for price_override_money on the subscription itself.
        if ( ! empty( $subscription['price_override_money'] ) ) {
            $amount = ( $subscription['price_override_money']['amount'] ?? 0 ) / 100;
            $currency = $subscription['price_override_money']['currency'] ?? 'USD';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: Using price_override_money: ' . $amount );
            }
        }
        // 2. If still 0, try to get price from order_template (for RELATIVE pricing).
        elseif ( $amount == 0 && ! empty( $subscription['phases'][0]['order_template_id'] ) ) {
            $order_template_id = $subscription['phases'][0]['order_template_id'];
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Subscription Sync: Fetching order template ' . $order_template_id . ' for RELATIVE pricing' );
            }
            
            $order = $this->client->get( 'orders/' . $order_template_id );
            if ( ! is_wp_error( $order ) && ! empty( $order['order']['total_money'] ) ) {
                $amount = ( $order['order']['total_money']['amount'] ?? 0 ) / 100;
                $currency = $order['order']['total_money']['currency'] ?? 'USD';
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Subscription Sync: Got price from order template: ' . $amount . ' ' . $currency );
                }
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Subscription Sync: Could not fetch order template: ' . ( is_wp_error( $order ) ? $order->get_error_message() : 'Empty response' ) );
                }
            }
        }

        // Build subscription data.
        $data = array(
            'user_id'                => $user_id,
            'square_subscription_id' => $subscription['id'],
            'square_customer_id'     => $subscription['customer_id'] ?? '',
            'square_plan_id'         => $plan_variation_id,
            'plan_name'              => $plan_info['name'] ?? '',
            'amount'                 => $amount,
            'currency'               => $currency,
            'cadence'                => $plan_info['cadence'] ?? 'MONTHLY',
            'status'                 => strtolower( $subscription['status'] ?? 'active' ),
            'start_date'             => $this->format_date( $subscription['start_date'] ?? null ),
            'next_billing_date'      => $this->format_date( $subscription['charged_through_date'] ?? null ),
            'canceled_at'            => $this->format_date( $subscription['canceled_date'] ?? null ),
        );

        $result = SPP_Database::upsert_subscription( $data );

        return (bool) $result;
    }

    /**
     * Format date from Square format to MySQL format.
     *
     * @param string|null $date Date string.
     * @return string|null Formatted date or null.
     */
    private function format_date( $date ) {
        if ( empty( $date ) ) {
            return null;
        }

        $timestamp = strtotime( $date );
        if ( $timestamp ) {
            return gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        return null;
    }

    /**
     * Sync plans from Square Catalog.
     *
     * @return array|WP_Error Plans data or error.
     */
    public function sync_plans_from_square() {
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $plans = $catalog->get_subscription_plans();

        if ( is_wp_error( $plans ) ) {
            return $plans;
        }

        // Cache plans in transient.
        set_transient( 'spp_subscription_plans', $plans, HOUR_IN_SECONDS );

        return $plans;
    }

    /**
     * Handle subscription webhook events.
     *
     * @param string $event_type Event type.
     * @param array  $data       Event data.
     * @return bool Success status.
     */
    public function handle_webhook( $event_type, $data ) {
        $subscription = $data['object']['subscription'] ?? null;

        if ( ! $subscription ) {
            return false;
        }

        // Get plan names for the sync.
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $plans = $catalog->get_subscription_plans();
        $plan_names = array();

        if ( ! is_wp_error( $plans ) ) {
            foreach ( $plans as $plan ) {
                $plan_names[ $plan['id'] ] = array(
                    'name'     => $plan['name'],
                    'amount'   => $plan['price_amount'] / 100,
                    'currency' => $plan['price_currency'],
                    'cadence'  => $plan['cadence'],
                );
            }
        }

        return $this->process_subscription( $subscription, $plan_names );
    }

    /**
     * Sync a single subscription from webhook data.
     *
     * @param array $subscription Subscription data from Square.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function sync_single_subscription( array $subscription ) {
        if ( empty( $subscription['id'] ) ) {
            return new WP_Error( 'missing_id', __( 'Subscription ID is missing.', 'cube-payment-portal' ) );
        }

        // Get plan names for the sync.
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $plans = $catalog->get_subscription_plans();
        $plan_names = array();

        if ( ! is_wp_error( $plans ) ) {
            foreach ( $plans as $plan ) {
                // Map parent plan ID.
                $plan_names[ $plan['id'] ] = array(
                    'name'     => $plan['name'],
                    'amount'   => ( $plan['price_amount'] ?? 0 ) / 100,
                    'currency' => $plan['price_currency'] ?? 'USD',
                    'cadence'  => $plan['cadence'] ?? 'MONTHLY',
                );

                // Also map all variation IDs (subscriptions use variation IDs).
                $variations = $plan['raw']['subscription_plan_data']['subscription_plan_variations'] ?? array();
                foreach ( $variations as $variation ) {
                    $var_id = $variation['id'];
                    $var_data = $variation['subscription_plan_variation_data'] ?? array();
                    $var_phases = $var_data['phases'] ?? array();

                    $var_amount = 0;
                    $var_currency = 'USD';
                    $var_cadence = 'MONTHLY';

                    if ( ! empty( $var_phases[0] ) ) {
                        $phase = $var_phases[0];
                        $var_cadence = $phase['cadence'] ?? 'MONTHLY';

                        // Get price from phase.
                        if ( isset( $phase['pricing']['price']['amount'] ) ) {
                            $var_amount = $phase['pricing']['price']['amount'] / 100;
                            $var_currency = $phase['pricing']['price']['currency'] ?? 'USD';
                        } elseif ( isset( $phase['recurring_price_money']['amount'] ) ) {
                            $var_amount = $phase['recurring_price_money']['amount'] / 100;
                            $var_currency = $phase['recurring_price_money']['currency'] ?? 'USD';
                        }
                    }

                    $plan_names[ $var_id ] = array(
                        'name'     => $var_data['name'] ?? $plan['name'],
                        'amount'   => $var_amount,
                        'currency' => $var_currency,
                        'cadence'  => $var_cadence,
                    );
                }
            }
        }

        $result = $this->process_subscription( $subscription, $plan_names );

        if ( $result ) {
            return true;
        }

        return new WP_Error( 'sync_failed', __( 'Failed to sync subscription.', 'cube-payment-portal' ) );
    }
}
