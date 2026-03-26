<?php
/**
 * Square Customers API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Customers
 *
 * Handles customer creation and management via Square Customers API.
 */
class SPP_Customers {

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
     * Create a new customer in Square.
     *
     * @param array $data Customer data.
     * @return array|WP_Error Customer data or error.
     */
    public function create_customer( array $data ) {
        $body = array(
            'idempotency_key' => wp_generate_uuid4(),
        );

        if ( ! empty( $data['first_name'] ) ) {
            $body['given_name'] = $data['first_name'];
        }
        if ( ! empty( $data['last_name'] ) ) {
            $body['family_name'] = $data['last_name'];
        }
        if ( ! empty( $data['email'] ) ) {
            $body['email_address'] = $data['email'];
        }
        if ( ! empty( $data['phone'] ) ) {
            $body['phone_number'] = $data['phone'];
        }
        if ( ! empty( $data['company_name'] ) ) {
            $body['company_name'] = $data['company_name'];
        }

        if ( ! empty( $data['reference_id'] ) ) {
            $body['reference_id'] = $data['reference_id'];
        }

        if ( ! empty( $data['note'] ) ) {
            $body['note'] = $data['note'];
        }

        // Address fields.
        if ( ! empty( $data['address_line_1'] ) || ! empty( $data['city'] ) ) {
            $body['address'] = array();
            
            if ( ! empty( $data['address_line_1'] ) ) {
                $body['address']['address_line_1'] = $data['address_line_1'];
            }
            if ( ! empty( $data['address_line_2'] ) ) {
                $body['address']['address_line_2'] = $data['address_line_2'];
            }
            if ( ! empty( $data['city'] ) ) {
                $body['address']['locality'] = $data['city'];
            }
            if ( ! empty( $data['state'] ) ) {
                $body['address']['administrative_district_level_1'] = $data['state'];
            }
            if ( ! empty( $data['postal_code'] ) ) {
                $body['address']['postal_code'] = $data['postal_code'];
            }
            if ( ! empty( $data['country'] ) ) {
                $body['address']['country'] = $data['country'];
            }
        }

        $response = $this->client->post( 'customers', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['customer'] ?? $response;
    }

    /**
     * Get a customer by ID.
     *
     * @param string $customer_id Square customer ID.
     * @return array|WP_Error Customer data or error.
     */
    public function get_customer( $customer_id ) {
        $response = $this->client->get( 'customers/' . $customer_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['customer'] ?? $response;
    }

    /**
     * Update a customer.
     *
     * @param string $customer_id Square customer ID.
     * @param array  $data        Customer data to update.
     * @return array|WP_Error Updated customer data or error.
     */
    public function update_customer( $customer_id, array $data ) {
        $body = array();

        if ( ! empty( $data['first_name'] ) ) {
            $body['given_name'] = $data['first_name'];
        }
        if ( ! empty( $data['last_name'] ) ) {
            $body['family_name'] = $data['last_name'];
        }
        if ( ! empty( $data['email'] ) ) {
            $body['email_address'] = $data['email'];
        }
        if ( ! empty( $data['phone'] ) ) {
            $body['phone_number'] = $data['phone'];
        }
        if ( ! empty( $data['company_name'] ) ) {
            $body['company_name'] = $data['company_name'];
        }
        if ( ! empty( $data['birthday'] ) ) {
            $body['birthday'] = $data['birthday'];
        }
        if ( ! empty( $data['address'] ) && is_array( $data['address'] ) ) {
            $body['address'] = $data['address'];
        }

        if ( empty( $body ) ) {
            return new WP_Error( 'no_data', __( 'No data to update.', 'cube-payment-portal' ) );
        }

        $response = $this->client->put( 'customers/' . $customer_id, $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['customer'] ?? $response;
    }

    /**
     * Search for a customer by email.
     *
     * @param string $email Email address.
     * @return array|WP_Error Customer data or error.
     */
    public function get_customer_by_email( $email ) {
        $body = array(
            'query' => array(
                'filter' => array(
                    'email_address' => array(
                        'exact' => $email,
                    ),
                ),
            ),
        );

        $response = $this->client->post( 'customers/search', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['customers'] ) ) {
            return new WP_Error( 'customer_not_found', __( 'Customer not found.', 'cube-payment-portal' ) );
        }

        return $response['customers'][0];
    }

    /**
     * Link a WordPress user to a Square customer.
     *
     * @param int    $user_id     WordPress user ID.
     * @param string $customer_id Square customer ID.
     * @return bool Success.
     */
    public function link_wp_user( $user_id, $customer_id ) {
        return update_user_meta( $user_id, 'spp_square_customer_id', $customer_id );
    }

    /**
     * Get Square customer ID for WordPress user.
     *
     * @param int $user_id WordPress user ID.
     * @return string|false Customer ID or false.
     */
    public function get_customer_id_for_user( $user_id ) {
        return get_user_meta( $user_id, 'spp_square_customer_id', true );
    }

    /**
     * Create customer for WordPress user if not exists.
     *
     * @param int $user_id WordPress user ID.
     * @return string|WP_Error Square customer ID or error.
     */
    public function ensure_customer_for_user( $user_id ) {
        $existing_id = $this->get_customer_id_for_user( $user_id );

        if ( ! empty( $existing_id ) ) {
            return $existing_id;
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'user_not_found', __( 'User not found.', 'cube-payment-portal' ) );
        }

        $customer = $this->create_customer(
            array(
                'first_name'   => $user->first_name,
                'last_name'    => $user->last_name,
                'email'        => $user->user_email,
                'reference_id' => 'wp_user_' . $user_id,
            )
        );

        if ( is_wp_error( $customer ) ) {
            return $customer;
        }

        $this->link_wp_user( $user_id, $customer['id'] );

        return $customer['id'];
    }

    /**
     * Get list of all customers for dropdown.
     * 
     * @param int    $limit  Number of customers to retrieve.
     * @param string $cursor Pagination cursor.
     * @return array|WP_Error Customer list or error.
     */
    public function get_customers_list( $limit = 100, $cursor = null ) {
        $query_params = array( 'limit' => $limit );
        
        if ( ! empty( $cursor ) ) {
            $query_params['cursor'] = $cursor;
        }
        
        $query_string = http_build_query( $query_params );
        $response = $this->client->get( 'customers?' . $query_string );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return $response;
    }

    /**
     * Search customers by query string.
     * 
     * Searches across email, phone, and reference_id fields.
     * Note: Square API doesn't support fuzzy search. This searches
     * for exact matches in multiple fields.
     * 
     * @param string $query Search query (email, phone, or reference_id).
     * @param string $field Optional specific field to search (email, phone, reference).
     * @return array|WP_Error Search results or error.
     */
    public function search_customers( $query, $field = null ) {
        $body = array(
            'limit' => 100,
        );

        // If specific field provided, search that field.
        if ( ! empty( $field ) ) {
            switch ( $field ) {
                case 'email':
                    $body['query'] = array(
                        'filter' => array(
                            'email_address' => array(
                                'fuzzy' => $query,
                            ),
                        ),
                    );
                    break;
                    
                case 'phone':
                    $body['query'] = array(
                        'filter' => array(
                            'phone_number' => array(
                                'fuzzy' => $query,
                            ),
                        ),
                    );
                    break;
                    
                case 'reference':
                    $body['query'] = array(
                        'filter' => array(
                            'reference_id' => array(
                                'exact' => $query,
                            ),
                        ),
                    );
                    break;
            }
        } else {
            // Try to determine which field to search based on query format.
            if ( filter_var( $query, FILTER_VALIDATE_EMAIL ) ) {
                // Looks like email.
                $body['query'] = array(
                    'filter' => array(
                        'email_address' => array(
                            'fuzzy' => $query,
                        ),
                    ),
                );
            } elseif ( preg_match( '/^\+?[0-9\s\-\(\)]+$/', $query ) ) {
                // Looks like phone number.
                $body['query'] = array(
                    'filter' => array(
                        'phone_number' => array(
                            'fuzzy' => $query,
                        ),
                    ),
                );
            } else {
                // Search by reference_id as fallback.
                $body['query'] = array(
                    'filter' => array(
                        'reference_id' => array(
                            'exact' => $query,
                        ),
                    ),
                );
            }
        }
        
        $response = $this->client->post( 'customers/search', $body );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return $response;
    }

    /**
     * Verify customer exists in Square (not just in meta).
     * 
     * @param string $customer_id Square customer ID.
     * @return bool True if exists, false otherwise.
     */
    public function verify_customer_exists( $customer_id ) {
        $customer = $this->get_customer( $customer_id );
        return ! is_wp_error( $customer );
    }

    /**
     * Ensure customer exists with verification.
     * Enhanced version that verifies Square customer still exists.
     * 
     * @param int $user_id WordPress user ID.
     * @return string|WP_Error Square customer ID or error.
     */
    public function ensure_customer_verified( $user_id ) {
        $existing_id = $this->get_customer_id_for_user( $user_id );
        
        // If we have an ID, verify it still exists in Square.
        if ( ! empty( $existing_id ) ) {
            if ( $this->verify_customer_exists( $existing_id ) ) {
                return $existing_id;
            }
            // Customer was deleted from Square, need to recreate.
            delete_user_meta( $user_id, 'spp_square_customer_id' );
        }
        
        // Create new customer.
        return $this->ensure_customer_for_user( $user_id );
    }
}
