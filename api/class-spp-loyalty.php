<?php
/**
 * Square Loyalty API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Loyalty
 *
 * Handles loyalty program management via Square Loyalty API.
 */
class SPP_Loyalty {

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
     * Retrieve the loyalty program.
     *
     * @return array|WP_Error Loyalty program or error.
     */
    public function get_program() {
        $response = $this->client->get( 'loyalty/programs/main' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['program'] ?? $response;
    }

    /**
     * Search for a loyalty account by customer ID.
     * Alias for get_account_by_customer for AJAX handler compatibility.
     *
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Loyalty account or error.
     */
    public function search_loyalty_account( $customer_id ) {
        return $this->get_account_by_customer( $customer_id );
    }

    /**
     * Retrieve a loyalty account by customer ID.
     *
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Loyalty account or error.
     */
    public function get_account_by_customer( $customer_id ) {
        $body = array(
            'query' => array(
                'customer_ids' => array( $customer_id ),
            ),
        );

        $response = $this->client->post( 'loyalty/accounts/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $accounts = $response['loyalty_accounts'] ?? array();
        
        return ! empty( $accounts ) ? $accounts[0] : null;
    }

    /**
     * Search loyalty accounts.
     *
     * @param array  $customer_ids Array of customer IDs.
     * @param string $cursor       Pagination cursor.
     * @return array|WP_Error Accounts or error.
     */
    public function search_accounts( array $customer_ids = array(), $cursor = null ) {
        $body = array();

        if ( ! empty( $customer_ids ) ) {
            $body['query'] = array(
                'customer_ids' => $customer_ids,
            );
        }

        if ( $cursor ) {
            $body['cursor'] = $cursor;
        }

        $response = $this->client->post( 'loyalty/accounts/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Create a loyalty account for a customer.
     *
     * @param string $program_id  Loyalty program ID.
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Created account or error.
     */
    public function create_account( $program_id, $customer_id, $phone_number = '' ) {
        $body = array(
            'idempotency_key'  => wp_generate_uuid4(),
            'loyalty_account' => array(
                'program_id'  => $program_id,
                'customer_id' => $customer_id,
                'mapping'     => array(),
            ),
        );

        if ( ! empty( $phone_number ) ) {
            $body['loyalty_account']['mapping']['phone_number'] = $phone_number;
        } else {
            // Try to get phone from customer.
            if ( ! class_exists( 'SPP_Customers' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
            }

            $customers_api = new SPP_Customers( $this->client );
            $customer = $customers_api->get_customer( $customer_id );

            if ( ! is_wp_error( $customer ) && ! empty( $customer['phone_number'] ) ) {
                $body['loyalty_account']['mapping']['phone_number'] = $customer['phone_number'];
            }
        }

        // If mapping is still empty, fallback to legacy type/value or error out
        if ( empty( $body['loyalty_account']['mapping'] ) ) {
             return new WP_Error( 'missing_phone', 'A phone number is required to create a loyalty account.' );
        }

        $response = $this->client->post( 'loyalty/accounts', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['loyalty_account'] ?? $response;
    }

    /**
     * Retrieve a loyalty account by ID.
     *
     * @param string $account_id Loyalty account ID.
     * @return array|WP_Error Account or error.
     */
    public function get_account( $account_id ) {
        $response = $this->client->get( 'loyalty/accounts/' . $account_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['loyalty_account'] ?? $response;
    }

    /**
     * Accumulate loyalty points.
     *
     * @param string $account_id  Loyalty account ID.
     * @param int    $points      Points to add.
     * @param string $idempotency Idempotency key.
     * @return array|WP_Error Result or error.
     */
    public function accumulate_points( $account_id, $points, $idempotency = null ) {
        $body = array(
            'idempotency_key'   => $idempotency ?: wp_generate_uuid4(),
            'accumulate_points' => array(
                'points' => $points,
            ),
        );

        $response = $this->client->post( 'loyalty/accounts/' . $account_id . '/accumulate', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['event'] ?? $response;
    }

    /**
     * Adjust loyalty points.
     *
     * @param string $account_id Loyalty account ID.
     * @param int    $points     Points adjustment (positive or negative).
     * @param string $reason     Reason for adjustment.
     * @return array|WP_Error Result or error.
     */
    public function adjust_points( $account_id, $points, $reason = '' ) {
        $body = array(
            'idempotency_key'  => wp_generate_uuid4(),
            'adjust_points'    => array(
                'points' => $points,
                'reason' => $reason,
            ),
        );

        $response = $this->client->post( 'loyalty/accounts/' . $account_id . '/adjust', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['event'] ?? $response;
    }

    /**
     * Create a loyalty reward.
     *
     * @param string $reward_tier_id Reward tier ID.
     * @param string $account_id     Loyalty account ID.
     * @return array|WP_Error Created reward or error.
     */
    public function create_reward( $reward_tier_id, $account_id ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'reward'          => array(
                'loyalty_account_id' => $account_id,
                'reward_tier_id'     => $reward_tier_id,
            ),
        );

        $response = $this->client->post( 'loyalty/rewards', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['reward'] ?? $response;
    }

    /**
     * Redeem a loyalty reward.
     *
     * @param string $reward_id   Reward ID.
     * @param string $location_id Location ID.
     * @return array|WP_Error Result or error.
     */
    public function redeem_reward( $reward_id, $location_id ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
            'location_id'     => $location_id,
        );

        $response = $this->client->post( 'loyalty/rewards/' . $reward_id . '/redeem', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['event'] ?? $response;
    }

    /**
     * List loyalty events for an account.
     *
     * @param string $account_id Loyalty account ID.
     * @param int    $limit      Number of events.
     * @return array|WP_Error Events or error.
     */
    public function list_events( $account_id, $limit = 30 ) {
        $body = array(
            'query' => array(
                'filter' => array(
                    'loyalty_account_filter' => array(
                        'loyalty_account_id' => $account_id,
                    ),
                ),
            ),
            'limit' => $limit,
        );

        $response = $this->client->post( 'loyalty/events/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['events'] ?? array();
    }


    /**
     * Search loyalty rewards.
     *
     * @param array $args Search arguments.
     * @return array|WP_Error Rewards or error.
     */
    public function search_rewards( array $args = array() ) {
        $body = array();

        if ( isset( $args['loyalty_account_id'] ) ) {
            $body['query'] = array(
                'loyalty_account_id' => $args['loyalty_account_id'],
            );
        }

        if ( isset( $args['status'] ) ) {
            $body['query']['status'] = $args['status'];
        }

        if ( isset( $args['limit'] ) ) {
            $body['limit'] = $args['limit'];
        }

        if ( isset( $args['cursor'] ) ) {
            $body['cursor'] = $args['cursor'];
        }

        $response = $this->client->post( 'loyalty/rewards/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Delete a loyalty reward.
     *
     * @param string $reward_id Reward ID.
     * @return bool|WP_Error True if deleted or error.
     */
    public function delete_reward( $reward_id ) {
        $response = $this->client->delete( 'loyalty/rewards/' . $reward_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return true;
    }
}
