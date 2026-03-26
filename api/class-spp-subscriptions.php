<?php
/**
 * Square Subscriptions API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Subscriptions
 *
 * Handles subscription management via Square Subscriptions API.
 */
class SPP_Subscriptions {

    /**
     * Square API client.
     *
     * @var SPP_Square_Client
     */
    private $client;

    /**
     * Constructor.
     *
     * @param SPP_Square_Client $client Optional API client instance.
     */
    public function __construct( ?SPP_Square_Client $client = null ) {
        $this->client = $client ?: new SPP_Square_Client();
    }

    /**
     * Create a subscription.
     *
     * @param array $data       Subscription data.
     * @param array $local_data Optional local data for logging (e.g., plan_name, user_id).
     * @return array|WP_Error Subscription data or error.
     */
    public function create_subscription( array $data, array $local_data = array() ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'location_id'     => $data['location_id'] ?? $this->client->get_location_id(),
            'customer_id'     => $data['customer_id'],
        );

        // Square API uses plan_variation_id (not plan_id).
        // Accept either for backward compatibility.
        if ( ! empty( $data['plan_variation_id'] ) ) {
            $body['plan_variation_id'] = $data['plan_variation_id'];
        } elseif ( ! empty( $data['plan_id'] ) ) {
            $body['plan_variation_id'] = $data['plan_id'];
        }

        if ( ! empty( $data['card_id'] ) ) {
            $body['card_id'] = $data['card_id'];
        }

        if ( ! empty( $data['start_date'] ) ) {
            $body['start_date'] = $data['start_date'];
        }

        // Support price override for RELATIVE pricing plans.
        if ( ! empty( $data['price_override_money'] ) ) {
            $body['price_override_money'] = array(
                'amount'   => intval( $data['price_override_money']['amount'] ),
                'currency' => $data['price_override_money']['currency'] ?? 'USD',
            );
        }

        // Support phases for RELATIVE pricing plans.
        // For RELATIVE pricing, phases must include order_template_id (a draft order ID).
        if ( ! empty( $data['phases'] ) && is_array( $data['phases'] ) ) {
            $body['phases'] = array();
            foreach ( $data['phases'] as $phase ) {
                $phase_data = array(
                    'ordinal' => intval( $phase['ordinal'] ?? 0 ),
                );
                
                // Add order_template_id for RELATIVE pricing (required by Square API).
                if ( ! empty( $phase['order_template_id'] ) ) {
                    $phase_data['order_template_id'] = $phase['order_template_id'];
                }
                
                // Add plan_phase_uid if provided.
                if ( ! empty( $phase['plan_phase_uid'] ) ) {
                    $phase_data['plan_phase_uid'] = $phase['plan_phase_uid'];
                }
                
                $body['phases'][] = $phase_data;
            }
        }

        $response = $this->client->post( 'subscriptions', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $subscription = $response['subscription'] ?? $response;

        // Log locally.
        $this->log_subscription( $subscription, $local_data );

        return $subscription;
    }

    /**
     * Get a subscription by ID.
     *
     * @param string $subscription_id Subscription ID.
     * @return array|WP_Error Subscription data or error.
     */
    public function get_subscription( $subscription_id ) {
        $response = $this->client->get( 'subscriptions/' . $subscription_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['subscription'] ?? $response;
    }

    /**
     * Update a subscription.
     *
     * @param string $subscription_id Subscription ID.
     * @param array  $data            Data to update.
     * @return array|WP_Error Updated subscription or error.
     */
    public function update_subscription( $subscription_id, array $data ) {
        $body = array(
            'subscription' => array(),
        );

        if ( isset( $data['card_id'] ) ) {
            $body['subscription']['card_id'] = $data['card_id'];
        }

        $response = $this->client->put( 'subscriptions/' . $subscription_id, $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['subscription'] ?? $response;
    }

    /**
     * Pause a subscription.
     *
     * @param string $subscription_id Subscription ID.
     * @param string $pause_until     Optional date to auto-resume.
     * @return array|WP_Error Subscription data or error.
     */
    public function pause_subscription( $subscription_id, $pause_until = null ) {
        $body = array();

        if ( $pause_until ) {
            $body['pause_effective_date'] = $pause_until;
        }

        $response = $this->client->post( 'subscriptions/' . $subscription_id . '/pause', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $this->update_local_status( $subscription_id, 'paused' );

        return $response['subscription'] ?? $response;
    }

    /**
     * Resume a paused subscription.
     *
     * @param string $subscription_id Subscription ID.
     * @return array|WP_Error Subscription data or error.
     */
    public function resume_subscription( $subscription_id ) {
        $response = $this->client->post( 'subscriptions/' . $subscription_id . '/resume', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $this->update_local_status( $subscription_id, 'active' );

        return $response['subscription'] ?? $response;
    }

    /**
     * Cancel a subscription.
     *
     * @param string $subscription_id Subscription ID.
     * @return array|WP_Error Subscription data or error.
     */
    public function cancel_subscription( $subscription_id ) {
        $response = $this->client->post( 'subscriptions/' . $subscription_id . '/cancel', array() );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $this->update_local_status( $subscription_id, 'canceled' );

        return $response['subscription'] ?? $response;
    }

    /**
     * Swap subscription to a different plan.
     *
     * @param string $subscription_id Subscription ID.
     * @param string $new_plan_id     New plan ID.
     * @return array|WP_Error Subscription data or error.
     */
    public function swap_plan( $subscription_id, $new_plan_id ) {
        $body = array(
            'new_plan_id' => $new_plan_id,
        );

        $response = $this->client->post( 'subscriptions/' . $subscription_id . '/swap-plan', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Notify business owner.
        $this->notify_plan_change( $subscription_id, $new_plan_id );

        return $response['subscription'] ?? $response;
    }

    /**
     * List subscription events (billing history).
     *
     * @param string $subscription_id Subscription ID.
     * @return array|WP_Error Events array or error.
     */
    public function list_subscription_events( $subscription_id ) {
        $response = $this->client->get( 'subscriptions/' . $subscription_id . '/events' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['subscription_events'] ?? array();
    }

    /**
     * Get subscriptions for a customer.
     *
     * @param string $customer_id Customer ID.
     * @return array|WP_Error Array of subscriptions or error.
     */
    public function get_customer_subscriptions( $customer_id ) {
        $body = array(
            'query' => array(
                'filter' => array(
                    'customer_ids' => array( $customer_id ),
                ),
            ),
            'include' => array( 'actions' ),
        );

        $response = $this->client->post( 'subscriptions/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['subscriptions'] ?? array();
    }

    /**
     * Log subscription to local database.
     *
     * @param array $subscription Subscription data from Square.
     * @param array $local_data   Local data.
     */
    private function log_subscription( array $subscription, array $local_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spp_subscriptions';

        $plan_id = $subscription['plan_variation_id'] ?? $subscription['plan_id'] ?? '';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id'                => $local_data['user_id'] ?? 0,
                'square_subscription_id' => $subscription['id'],
                'square_customer_id'     => $subscription['customer_id'] ?? '',
                'square_plan_id'         => $plan_id,
                'plan_name'              => $local_data['plan_name'] ?? '',
                'amount'                 => $this->get_plan_amount( $plan_id ),
                'currency'               => get_option( 'spp_default_currency', 'USD' ),
                'cadence'                => $subscription['cadence'] ?? 'MONTHLY',
                'status'                 => strtolower( $subscription['status'] ?? 'active' ),
                'start_date'             => $subscription['start_date'] ?? current_time( 'mysql' ),
                'created_at'             => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Update local subscription status.
     *
     * @param string $subscription_id Square subscription ID.
     * @param string $status          New status.
     */
    private function update_local_status( $subscription_id, $status ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spp_subscriptions';

        $data = array( 'status' => $status );

        if ( 'canceled' === $status ) {
            $data['canceled_at'] = current_time( 'mysql' );
        }

        $wpdb->update(
            $table_name,
            $data,
            array( 'square_subscription_id' => $subscription_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );
    }

    /**
     * Get plan amount (placeholder - would fetch from catalog).
     *
     * @param string $plan_id Plan ID.
     * @return float Plan amount.
     */
    private function get_plan_amount( $plan_id ) {
        // TODO: Fetch from catalog API.
        return 0.00;
    }

    /**
     * Notify business owner of plan change.
     *
     * @param string $subscription_id Subscription ID.
     * @param string $new_plan_id     New plan ID.
     */
    private function notify_plan_change( $subscription_id, $new_plan_id ) {
        $admin_email = get_option( 'spp_admin_email', get_option( 'admin_email' ) );

        wp_mail(
            $admin_email,
            __( 'Subscription Plan Changed', 'cube-payment-portal' ),
            sprintf(
                /* translators: 1: subscription ID, 2: new plan ID */
                __( 'A client has changed their subscription plan.\n\nSubscription: %1$s\nNew Plan: %2$s', 'cube-payment-portal' ),
                $subscription_id,
                $new_plan_id
            )
        );
    }
}
