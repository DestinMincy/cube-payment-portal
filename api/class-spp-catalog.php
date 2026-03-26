<?php
/**
 * Square Catalog API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Catalog
 *
 * Handles catalog item retrieval for invoice line items.
 */
class SPP_Catalog {

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
	 * Batch retrieve catalog objects.
	 *
	 * @param array $object_ids Array of object IDs to retrieve.
	 * @param bool  $include_related Whether to include related objects.
	 * @return array|WP_Error Response with objects or error.
	 */
	public function batch_retrieve_objects( array $object_ids, $include_related = false ) {
		if ( empty( $object_ids ) ) {
			return array( 'objects' => array(), 'related_objects' => array() );
		}

		// Ensure IDs are unique and filter empty.
		$object_ids = array_unique( array_filter( $object_ids ) );
		
		// Split into chunks of 100 (Square limit).
		$chunks = array_chunk( $object_ids, 100 );
		$all_objects = array();
		$all_related = array();

		foreach ( $chunks as $chunk ) {
			$body = array(
				'object_ids' => $chunk,
				'include_related_objects' => $include_related,
			);

			$response = $this->client->post( 'catalog/batch-retrieve', $body );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! empty( $response['objects'] ) ) {
				$all_objects = array_merge( $all_objects, $response['objects'] );
			}
			
			if ( ! empty( $response['related_objects'] ) ) {
				$all_related = array_merge( $all_related, $response['related_objects'] );
			}
		}

		return array( 'objects' => $all_objects, 'related_objects' => $all_related );
	}

	/**
	 * Get appointment services.
	 * 
	 * @param array $args Query arguments.
	 * @return array|WP_Error Services (items) or error.
	 */
	public function get_appointment_services( $args = array() ) {
		$defaults = array(
			'limit'  => 100,
			'cursor' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$body = array(
			'product_types' => array( 'APPOINTMENTS_SERVICE' ),
			'limit'         => $args['limit'],
		);

		if ( ! empty( $args['cursor'] ) ) {
			$body['cursor'] = $args['cursor'];
		}

		$response = $this->client->post( 'catalog/search-catalog-items', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Normalize response to match get_catalog_items somewhat (items -> objects)
		// SearchCatalogItems returns 'items', ListCatalog returns 'objects'.
		if ( isset( $response['items'] ) ) {
			$response['objects'] = $response['items'];
			unset( $response['items'] );
		}

		return $response;
	}

	/**
	 * Get all catalog items.
	 * 
	 * @param array $args Query arguments.
	 * @return array|WP_Error Catalog items or error.
	 */
	public function get_catalog_items( $args = array() ) {
		$defaults = array(
			'types'  => 'ITEM',
			'limit'  => 100,
			'cursor' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		// If requesting APPOINTMENT_SERVICE, redirect to search method
		if ( 'APPOINTMENT_SERVICE' === $args['types'] || 'APPOINTMENTS_SERVICE' === $args['types'] ) {
			return $this->get_appointment_services( $args );
		}

		$query_params = array(
			'types' => $args['types'],
		);

		if ( ! empty( $args['cursor'] ) ) {
			$query_params['cursor'] = $args['cursor'];
		}

		if ( ! empty( $args['archived_state'] ) ) {
			$query_params['archived_state'] = $args['archived_state'];
		}

		$query_string = http_build_query( $query_params );
		$response = $this->client->get( 'catalog/list?' . $query_string );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Search catalog items.
	 * 
	 * Uses the SearchCatalogItems endpoint which is specifically designed
	 * for searching ITEM and ITEM_VARIATION object types.
	 * 
	 * @param string $query Search query.
	 * @param array  $args  Additional search parameters.
	 * @return array|WP_Error Search results or error.
	 */
	public function search_catalog( $query, $args = array() ) {
		$defaults = array(
			'limit'              => 100,
			'enabled_location_ids' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$body = array(
			'text_filter' => array(
				'keywords' => array( $query ),
			),
		);

		if ( ! empty( $args['limit'] ) ) {
			$body['limit'] = $args['limit'];
		}

		if ( ! empty( $args['cursor'] ) ) {
			$body['cursor'] = $args['cursor'];
		}

		if ( ! empty( $args['enabled_location_ids'] ) ) {
			$body['enabled_location_ids'] = $args['enabled_location_ids'];
		}

		// Use the correct endpoint for searching items and variations.
		$response = $this->client->post( 'catalog/search-catalog-items', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Get catalog item details with variations.
	 * 
	 * @param string $object_id Catalog object ID.
	 * @return array|WP_Error Item details or error.
	 */
	public function get_item_details( $object_id ) {
		$response = $this->client->get( 'catalog/object/' . $object_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['object'] ?? $response;
	}

	/**
	 * Get pricing for a specific item variation.
	 * 
	 * @param array $variation Variation data from catalog.
	 * @return array Price info (amount in cents, currency).
	 */
	public function get_variation_pricing( $variation ) {
		if ( empty( $variation['item_variation_data']['price_money'] ) ) {
			return array(
				'amount'   => 0,
				'currency' => 'USD',
			);
		}

		return array(
			'amount'   => $variation['item_variation_data']['price_money']['amount'],
			'currency' => $variation['item_variation_data']['price_money']['currency'],
		);
	}

	/**
	 * Format catalog item for line item use.
	 * 
	 * Converts catalog item to invoice line item format.
	 * 
	 * @param array  $item     Catalog item object.
	 * @param string $var_id   Variation ID to use.
	 * @param string $quantity Quantity as string.
	 * @return array|WP_Error Line item data or error.
	 */
	public function format_as_line_item( $item, $var_id, $quantity = '1' ) {
		// Find the variation.
		$variation = null;
		foreach ( $item['item_data']['variations'] ?? array() as $var ) {
			if ( $var['id'] === $var_id ) {
				$variation = $var;
				break;
			}
		}

		if ( ! $variation ) {
			return new WP_Error(
				'variation_not_found',
				__( 'Item variation not found.', 'cube-payment-portal' )
			);
		}

		$pricing = $this->get_variation_pricing( $variation );

		return array(
			'name'              => $item['item_data']['name'],
			'quantity'          => (string) $quantity,
			'base_price_money'  => array(
				'amount'   => $pricing['amount'],
				'currency' => $pricing['currency'],
			),
			'catalog_object_id' => $var_id,
			'variation_name'    => $variation['item_variation_data']['name'] ?? '',
		);
	}

	/**
	 * Get all subscription plans from catalog.
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error Array of subscription plans or error.
	 */
	public function get_subscription_plans( $args = array() ) {
		$defaults = array(
			'include_deleted'  => false,
			'include_inactive' => false, // Plans not present at any locations.
		);

		$args = wp_parse_args( $args, $defaults );

		// List catalog objects of type SUBSCRIPTION_PLAN.
		$query_params = array(
			'types' => 'SUBSCRIPTION_PLAN',
		);

		$query_string = http_build_query( $query_params );
		$response = $this->client->get( 'catalog/list?' . $query_string );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$plans = array();
		$objects = $response['objects'] ?? array();

		foreach ( $objects as $object ) {
			if ( 'SUBSCRIPTION_PLAN' !== $object['type'] ) {
				continue;
			}

			// Skip deleted plans unless requested.
			if ( ! $args['include_deleted'] && ! empty( $object['is_deleted'] ) ) {
				continue;
			}

			// Skip inactive plans (not present at any locations) unless requested.
			// A plan is inactive if present_at_all_locations is false AND present_at_location_ids is empty.
			$is_active = true;
			if ( ! $args['include_inactive'] ) {
				$present_at_all = $object['present_at_all_locations'] ?? false;
				$present_at_locations = $object['present_at_location_ids'] ?? array();
				
				// If not present at all locations and not present at any specific locations, it's inactive.
				if ( ! $present_at_all && empty( $present_at_locations ) ) {
					$is_active = false;
				}
			}
			
			if ( ! $is_active ) {
				continue;
			}

			$plan_data = $object['subscription_plan_data'] ?? array();
			$phases = $plan_data['phases'] ?? array();

			// Extract pricing from first phase (most common case).
			$price_amount = 0;
			$price_currency = 'USD';
			$cadence = 'MONTHLY';
			$pricing_type = 'STATIC'; // Default to STATIC.

			// First, try the old structure with phases directly on plan_data.
			if ( ! empty( $phases[0] ) ) {
				$first_phase = $phases[0];
				$cadence = $first_phase['cadence'] ?? 'MONTHLY';
				$pricing_type = $first_phase['pricing']['type'] ?? 'STATIC';

				if ( ! empty( $first_phase['recurring_price_money'] ) ) {
					$price_amount = $first_phase['recurring_price_money']['amount'] ?? 0;
					$price_currency = $first_phase['recurring_price_money']['currency'] ?? 'USD';
				}
			}

			// If no pricing found, try the newer structure with subscription_plan_variations.
			if ( 0 === $price_amount && ! empty( $plan_data['subscription_plan_variations'] ) ) {
				$first_variation = $plan_data['subscription_plan_variations'][0] ?? array();
				$var_data = $first_variation['subscription_plan_variation_data'] ?? array();
				$var_phases = $var_data['phases'] ?? array();

				if ( ! empty( $var_phases[0] ) ) {
					$first_var_phase = $var_phases[0];
					$cadence = $first_var_phase['cadence'] ?? $cadence;
					$pricing_type = $first_var_phase['pricing']['type'] ?? 'STATIC';

					// Check for recurring_price_money (older format).
					if ( ! empty( $first_var_phase['recurring_price_money'] ) ) {
						$price_amount = $first_var_phase['recurring_price_money']['amount'] ?? 0;
						$price_currency = $first_var_phase['recurring_price_money']['currency'] ?? 'USD';
					}
					// Check for pricing object (newer format with STATIC/RELATIVE).
					elseif ( ! empty( $first_var_phase['pricing'] ) ) {
						$pricing = $first_var_phase['pricing'];
						// STATIC pricing has the price directly.
						if ( ! empty( $pricing['price'] ) ) {
							$price_amount = $pricing['price']['amount'] ?? 0;
							$price_currency = $pricing['price']['currency'] ?? 'USD';
						}
						// RELATIVE pricing uses price_money.
						elseif ( ! empty( $pricing['price_money'] ) ) {
							$price_amount = $pricing['price_money']['amount'] ?? 0;
							$price_currency = $pricing['price_money']['currency'] ?? 'USD';
						}
					}
				}
			}

			$plans[] = array(
				'id'             => $object['id'],
				'name'           => $plan_data['name'] ?? __( 'Unnamed Plan', 'cube-payment-portal' ),
				'description'    => $plan_data['subscription_plan_variations'][0]['subscription_plan_variation_data']['subscription_description'] ?? '',
				'price_amount'   => $price_amount,
				'price_currency' => $price_currency,
				'pricing_type'   => $pricing_type,
				'cadence'        => $cadence,
				'phases'         => $phases,
				'is_deleted'     => ! empty( $object['is_deleted'] ),
				'created_at'     => $object['created_at'] ?? null,
				'updated_at'     => $object['updated_at'] ?? null,
				'raw'            => $object,
			);
		}

		return $plans;
	}

	/**
	 * Get subscription plan details by ID.
	 *
	 * @param string $plan_id Subscription plan ID.
	 * @return array|WP_Error Plan details or error.
	 */
	public function get_plan_details( $plan_id ) {
		$response = $this->client->get( 'catalog/object/' . $plan_id . '?include_related_objects=true' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$object = $response['object'] ?? null;

		if ( ! $object || 'SUBSCRIPTION_PLAN' !== $object['type'] ) {
			return new WP_Error(
				'invalid_plan',
				__( 'Subscription plan not found or invalid type.', 'cube-payment-portal' )
			);
		}

		$plan_data = $object['subscription_plan_data'] ?? array();
		$phases = $plan_data['phases'] ?? array();

		// Extract pricing from phases.
		$pricing_phases = array();
		foreach ( $phases as $phase ) {
			$pricing_phases[] = array(
				'cadence'     => $phase['cadence'] ?? 'MONTHLY',
				'periods'     => $phase['periods'] ?? null,
				'amount'      => $phase['recurring_price_money']['amount'] ?? 0,
				'currency'    => $phase['recurring_price_money']['currency'] ?? 'USD',
				'ordinal'     => $phase['ordinal'] ?? 0,
			);
		}

		// Get first phase for primary pricing.
		$primary_price = 0;
		$primary_currency = 'USD';
		$primary_cadence = 'MONTHLY';

		if ( ! empty( $pricing_phases[0] ) ) {
			$primary_price = $pricing_phases[0]['amount'];
			$primary_currency = $pricing_phases[0]['currency'];
			$primary_cadence = $pricing_phases[0]['cadence'];
		}

		return array(
			'id'              => $object['id'],
			'name'            => $plan_data['name'] ?? __( 'Unnamed Plan', 'cube-payment-portal' ),
			'description'     => $plan_data['subscription_plan_variations'][0]['subscription_plan_variation_data']['subscription_description'] ?? '',
			'price_amount'    => $primary_price,
			'price_currency'  => $primary_currency,
			'cadence'         => $primary_cadence,
			'phases'          => $pricing_phases,
			'is_deleted'      => ! empty( $object['is_deleted'] ),
			'created_at'      => $object['created_at'] ?? null,
			'updated_at'      => $object['updated_at'] ?? null,
			'related_objects' => $response['related_objects'] ?? array(),
			'raw'             => $object,
		);
	}

	/**
	 * Format cadence for display.
	 *
	 * @param string $cadence Square cadence value.
	 * @return string Human-readable cadence.
	 */
	public static function format_cadence( $cadence ) {
		$cadences = array(
			'DAILY'     => __( 'Daily', 'cube-payment-portal' ),
			'WEEKLY'    => __( 'Weekly', 'cube-payment-portal' ),
			'EVERY_TWO_WEEKS' => __( 'Every 2 Weeks', 'cube-payment-portal' ),
			'MONTHLY'   => __( 'Monthly', 'cube-payment-portal' ),
			'EVERY_TWO_MONTHS' => __( 'Every 2 Months', 'cube-payment-portal' ),
			'QUARTERLY' => __( 'Quarterly', 'cube-payment-portal' ),
			'EVERY_FOUR_MONTHS' => __( 'Every 4 Months', 'cube-payment-portal' ),
			'EVERY_SIX_MONTHS' => __( 'Every 6 Months', 'cube-payment-portal' ),
			'ANNUAL'    => __( 'Yearly', 'cube-payment-portal' ),
			'EVERY_TWO_YEARS' => __( 'Every 2 Years', 'cube-payment-portal' ),
		);

		return $cadences[ $cadence ] ?? $cadence;
	}

	/**
	 * Create a new subscription plan with a default variation.
	 *
	 * @param array $data Plan data including name, price, currency, cadence.
	 * @return array|WP_Error Created plan or error.
	 */
	public function create_subscription_plan( array $data ) {
		$plan_id = '#plan_' . wp_generate_uuid4();
		$variation_id = '#variation_' . wp_generate_uuid4();

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object' => array(
				'type' => 'SUBSCRIPTION_PLAN',
				'id'   => $plan_id,
				'present_at_all_locations' => true,
				'subscription_plan_data' => array(
					'name' => $data['name'],
					'subscription_plan_variations' => array(
						array(
							'type' => 'SUBSCRIPTION_PLAN_VARIATION',
							'id'   => $variation_id,
							'present_at_all_locations' => true,
							'subscription_plan_variation_data' => array(
								'name' => $data['variation_name'] ?? $data['name'],
								'phases' => array(
									array(
										'cadence' => $data['cadence'] ?? 'MONTHLY',
										'ordinal' => 0,
										'pricing' => array(
											'type'  => 'STATIC',
											'price' => array(
												'amount'   => intval( $data['price'] * 100 ),
												'currency' => $data['currency'] ?? 'USD',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		// Add all_items if specified.
		if ( ! empty( $data['all_items'] ) ) {
			$body['object']['subscription_plan_data']['all_items'] = true;
		}

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Update an existing subscription plan.
	 *
	 * @param string $plan_id Square plan ID.
	 * @param array  $data    Updated plan data.
	 * @return array|WP_Error Updated plan or error.
	 */
	public function update_subscription_plan( $plan_id, array $data ) {
		// First get the current plan to get its version.
		$current = $this->client->get( 'catalog/object/' . $plan_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$object = $current['object'] ?? array();
		$version = $object['version'] ?? 0;

		// Build updated object.
		$updated_object = array(
			'type'    => 'SUBSCRIPTION_PLAN',
			'id'      => $plan_id,
			'version' => $version,
			'present_at_all_locations' => $object['present_at_all_locations'] ?? true,
			'subscription_plan_data' => array(
				'name' => $data['name'] ?? $object['subscription_plan_data']['name'],
			),
		);

		// Preserve existing variations.
		if ( ! empty( $object['subscription_plan_data']['subscription_plan_variations'] ) ) {
			$updated_object['subscription_plan_data']['subscription_plan_variations'] = 
				$object['subscription_plan_data']['subscription_plan_variations'];
		}

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object' => $updated_object,
		);

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Delete a subscription plan.
	 *
	 * @param string $plan_id Square plan ID.
	 * @return array|WP_Error Result or error.
	 */
	public function delete_subscription_plan( $plan_id ) {
		$response = $this->client->delete( 'catalog/object/' . $plan_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response;
	}

	/**
	 * Create a plan variation for an existing plan.
	 *
	 * @param string $plan_id Square plan ID.
	 * @param array  $data    Variation data.
	 * @return array|WP_Error Created variation or error.
	 */
	public function create_plan_variation( $plan_id, array $data ) {
		$variation_id = '#variation_' . wp_generate_uuid4();

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object' => array(
				'type' => 'SUBSCRIPTION_PLAN_VARIATION',
				'id'   => $variation_id,
				'present_at_all_locations' => true,
				'subscription_plan_variation_data' => array(
					'name' => $data['name'],
					'subscription_plan_id' => $plan_id,
					'phases' => array(
						array(
							'cadence' => $data['cadence'] ?? 'MONTHLY',
							'ordinal' => 0,
							'pricing' => array(
								'type'  => 'STATIC',
								'price' => array(
									'amount'   => intval( $data['price'] * 100 ),
									'currency' => $data['currency'] ?? 'USD',
								),
							),
						),
					),
				),
			),
		);

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Update a plan variation.
	 *
	 * Note: Square API does NOT allow modifying phases (pricing/cadence) on existing variations.
	 * Only the name can be updated. To change pricing, create a new variation.
	 *
	 * @param string $variation_id Square variation ID.
	 * @param array  $data         Updated variation data (only 'name' is supported).
	 * @return array|WP_Error Updated variation or error.
	 */
	public function update_plan_variation( $variation_id, array $data ) {
		// Get current variation.
		$current = $this->client->get( 'catalog/object/' . $variation_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$object = $current['object'] ?? array();
		$version = $object['version'] ?? 0;
		$variation_data = $object['subscription_plan_variation_data'] ?? array();

		// Square API restriction: phases CANNOT be modified on existing variations.
		// We must preserve the existing phases exactly as they are.
		$existing_phases = $variation_data['phases'] ?? array();

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object' => array(
				'type'    => 'SUBSCRIPTION_PLAN_VARIATION',
				'id'      => $variation_id,
				'version' => $version,
				'present_at_all_locations' => $object['present_at_all_locations'] ?? true,
				'subscription_plan_variation_data' => array(
					'name'                 => $data['name'] ?? $variation_data['name'],
					'subscription_plan_id' => $variation_data['subscription_plan_id'],
					'phases'               => $existing_phases, // Keep original phases unchanged.
				),
			),
		);

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Get image URL by Image ID.
	 *
	 * @param string $image_id Square Image ID.
	 * @return string|null Image URL or null.
	 */
	public function get_image_url( $image_id ) {
		$response = $this->client->get( 'catalog/object/' . $image_id );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return $response['object']['image_data']['url'] ?? null;
	}

	/**
	 * Delete a plan variation.
	 *
	 * @param string $variation_id Square variation ID.
	 * @return array|WP_Error Result or error.
	 */
	public function delete_plan_variation( $variation_id ) {
		$response = $this->client->delete( 'catalog/object/' . $variation_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clear cache.
		delete_transient( 'spp_subscription_plans' );

		return $response;
	}

	/**
	 * Create a new catalog item with a default variation.
	 *
	 * @param array $data Item data including name, description, price, currency, category_id.
	 * @return array|WP_Error Created item or error.
	 */
	public function create_item( array $data ) {
		$item_id = '#item_' . wp_generate_uuid4();
		$variation_id = '#variation_' . wp_generate_uuid4();

		// Build item object.
		$item_object = array(
			'type' => 'ITEM',
			'id'   => $item_id,
			'present_at_all_locations' => true,
			'item_data' => array(
				'name'         => $data['name'],
				'description'  => $data['description'] ?? '',
				'variations'   => array(
					array(
						'type' => 'ITEM_VARIATION',
						'id'   => $variation_id,
						'present_at_all_locations' => true,
						'item_variation_data' => array(
							'item_id'      => $item_id,
							'name'         => $data['variation_name'] ?? 'Regular',
							'pricing_type' => 'FIXED_PRICING',
							'price_money'  => array(
								'amount'   => intval( floatval( $data['price'] ) * 100 ),
								'currency' => $data['currency'] ?? 'USD',
							),
							'track_inventory' => ! empty( $data['track_inventory'] ),
						),
					),
				),
			),

		);

		// Handle available_for_booking
		if ( ! empty( $data['available_for_booking'] ) ) {
			$item_object['item_data']['variations'][0]['item_variation_data']['available_for_booking'] = true;
		}

		// Handle product_type (only if set).
		if ( ! empty( $data['product_type'] ) ) {
			$item_object['item_data']['product_type'] = $data['product_type'];
		}

		// Handle Appointments Service Duration.
		if ( ( $data['product_type'] ?? '' ) === 'APPOINTMENTS_SERVICE' && ! empty( $data['service_duration'] ) ) {
			// service_duration is in milliseconds. Input is usually minutes.
			$duration_ms = intval( $data['service_duration'] ) * 60 * 1000;
			$item_object['item_data']['variations'][0]['item_variation_data']['service_duration'] = $duration_ms;
		}

		// Add category if provided.
		if ( ! empty( $data['category_id'] ) ) {
			$item_object['item_data']['category_id'] = $data['category_id'];
		}

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object'          => $item_object,
		);

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Create an appointment service.
	 *
	 * Wrapper for create_item that enforces APPOINTMENTS_SERVICE type.
	 *
	 * @param array $data Service data (name, duration_minutes, price, currency, description).
	 * @return array|WP_Error Created item or error.
	 */
	public function create_appointment_service( array $data ) {
		// Enforce product type.
		$data['product_type'] = 'APPOINTMENTS_SERVICE';
		
		// Prepare multiple variations (if provided) or single variation (backward compat)
		$variations = array();
		$item_id = '#svc_' . uniqid(); // Temporary ID for new catalog object
		
		$input_variations = $data['variations'] ?? array();
		
		// If no variations array, check for single-level params for backward compatibility
		if ( empty( $input_variations ) ) {
			$input_variations[] = array(
				'name'                  => $data['variation_name'] ?? 'Regular',
				'pricing_type'          => $data['pricing_type'] ?? 'FIXED_PRICING',
				'price_money'  			=> array(
					'amount'   => intval( floatval( $data['price'] ?? 0 ) * 100 ),
					'currency' => $data['currency'] ?? 'USD',
				),
				'service_duration'      => ! empty( $data['duration_minutes'] ) ? intval( $data['duration_minutes'] ) * 60 * 1000 : 0,
				'available_for_booking' => isset( $data['available_for_booking'] ) ? (bool) $data['available_for_booking'] : true,
				'team_member_ids'       => $data['team_member_ids'] ?? array(),
			);
		} else {
			// Process the variations array
			foreach ( $input_variations as &$v ) {
				// Normalize keys if needed (e.g. price -> price_money structure)
				if ( isset( $v['price'] ) && ! isset( $v['price_money'] ) ) {
					$v['price_money'] = array(
						'amount'   => intval( floatval( $v['price'] ) * 100 ),
						'currency' => $v['currency'] ?? 'USD',
					);
				}
				if ( isset( $v['duration_minutes'] ) && ! isset( $v['service_duration'] ) ) {
					$v['service_duration'] = intval( $v['duration_minutes'] ) * 60 * 1000;
				}
				// Default bookings
				if ( ! isset( $v['available_for_booking'] ) ) {
					$v['available_for_booking'] = true;
				}
			}
			unset($v);
		}

		// Build variation objects
		foreach ( $input_variations as $var_data ) {
			$var_obj = array(
				'type' => 'ITEM_VARIATION',
				'id' => '#var_' . uniqid(),
				'item_variation_data' => array(
					'item_id' => $item_id,
					'name' => $var_data['name'] ?? 'Regular',
					'pricing_type' => $var_data['pricing_type'] ?? 'FIXED_PRICING',
					'price_money' => $var_data['price_money'],
					'service_duration' => $var_data['service_duration'],
					'track_inventory' => false, // Services don't track inventory
					'available_for_booking' => (bool) $var_data['available_for_booking'],
				),
			);
			
			if ( ! empty( $var_data['team_member_ids'] ) ) {
				$var_obj['item_variation_data']['team_member_ids'] = $var_data['team_member_ids'];
			}

			$variations[] = $var_obj;
		}

		$item_object = array(
			'type' => 'ITEM',
			'id' => $item_id,
			'item_data' => array(
				'name' => $data['name'],
				'description' => $data['description'] ?? '',
				'product_type' => 'APPOINTMENTS_SERVICE',
				'variations' => $variations,
			),
		);

		return $this->create_item_raw( $item_object );
	}
	
	/**
	 * Helper to create item raw (bypassing the simplified create_item wrapper).
	 */
	private function create_item_raw( $object ) {
		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object' => $object,
		);
		$response = $this->client->post( 'catalog/object', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		// Trigger Sync
		if ( ! empty( $response['catalog_object'] ) ) {
			if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
				require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
			}
			$sync = new SPP_Sync_Catalog( $this->client );
			$sync->sync_single_item( $response['catalog_object'] );
		}
		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Update an appointment service.
	 *
	 * @param string $item_id Service Item ID.
	 * @param array  $data    Updated data.
	 * @return array|WP_Error Updated item.
	 */
	public function update_appointment_service( $item_id, array $data ) {
		// Get current item
		$current = $this->get_item_details( $item_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$version = $current['version'];
		$item_data = $current['item_data'];
		
		// 1. Update Item Fields (Name, Description)
		$item_data['name'] = $data['name'] ?? $item_data['name'];
		$item_data['description'] = $data['description'] ?? $item_data['description'];

		// 2. Handle Variations (Diff Logic)
		$existing_variations = $item_data['variations'] ?? array();
		$input_variations = $data['variations'] ?? array();
		
		// Backward compatibility: If no 'variations' array, map flat fields to the first existing variation
		if ( empty( $input_variations ) && ! empty( $data['duration_minutes'] ) ) { // Assume flat update if duration_minutes present
			$input_variations[] = array(
				'id' => $existing_variations[0]['id'] ?? null, // Use ID of first variation if update
				'name' => 'Regular',
				'price' => $data['price'],
				'pricing_type' => $data['pricing_type'] ?? 'FIXED_PRICING',
				'duration_minutes' => $data['duration_minutes'],
				'available_for_booking' => $data['available_for_booking'] ?? true,
				'team_member_ids' => $data['team_member_ids'] ?? array(),
			);
		}

		$final_variations = array();
		$processed_ids = array();

		foreach ( $input_variations as $input_var ) {
			$var_id = $input_var['id'] ?? null;
			
			// Normalize input data
			$price_money = array(
				'amount' => intval( floatval( $input_var['price'] ?? 0 ) * 100 ),
				'currency' => $input_var['currency'] ?? 'USD',
			);
			$duration = intval( $input_var['duration_minutes'] ?? 0 ) * 60 * 1000;
			$pricing_type = $input_var['pricing_type'] ?? 'FIXED_PRICING';
			$team_ids = $input_var['team_member_ids'] ?? array();
			$bookable = isset( $input_var['available_for_booking'] ) ? (bool) $input_var['available_for_booking'] : true;
			$name = $input_var['name'] ?? 'Regular';

			if ( $var_id && strpos( $var_id, '#' ) !== 0 ) {
				// Updating EXISTING variation
				// Find existing version
				$existing_var = null;
				foreach ( $existing_variations as $ev ) {
					if ( $ev['id'] === $var_id ) {
						$existing_var = $ev;
						break;
					}
				}

				if ( $existing_var ) {
					$var_obj = array(
						'type' => 'ITEM_VARIATION',
						'id' => $var_id,
						'version' => $existing_var['version'],
						'item_variation_data' => array_merge( $existing_var['item_variation_data'], array(
							'name' => $name,
							'pricing_type' => $pricing_type,
							'price_money' => $price_money,
							'service_duration' => $duration,
							'available_for_booking' => $bookable,
							'team_member_ids' => $team_ids,
						) ),
					);
					$final_variations[] = $var_obj;
					$processed_ids[] = $var_id;
				}
			} else {
				// CREATING NEW variation (or if ID was temp '#...')
				$final_variations[] = array(
					'type' => 'ITEM_VARIATION',
					'id' => '#new_' . uniqid(),
					'item_variation_data' => array(
						'item_id' => $item_id,
						'name' => $name,
						'pricing_type' => $pricing_type,
						'price_money' => $price_money,
						'service_duration' => $duration,
						'track_inventory' => false,
						'available_for_booking' => $bookable,
						'team_member_ids' => $team_ids,
					),
				);
			}
		}

		// 3. Mark deleted variations
		foreach ( $existing_variations as $ev ) {
			if ( ! in_array( $ev['id'], $processed_ids ) ) {
				// Variation exists in Square but not in input -> DELETE IT
				// To delete a variation in an Upsert, we usually need "delete_object" endpoint or simply omit it?
				// Square Catalog Batch Upsert: "If you do not include a variation that currently exists... it is NOT deleted."
				// We MUST explicitly delete objects using batch-delete or delete endpoints.
				
				// ACTUALLY: For batch upsert (post catalog/object), omitting variations doesn't delete them.
				// We need to call delete_item (or delete_object) for these specific Variation IDs.
				$this->delete_item( $ev['id'] ); // Using delete_item which works for any object ID
			}
		}

		// 4. Construct Payload
		$item_object = array(
			'type' => 'ITEM',
			'id' => $item_id,
			'version' => $version,
			'item_data' => array(
				'name' => $item_data['name'],
				'description' => $item_data['description'],
				'variations' => $final_variations,
			),
		);

		return $this->create_item_raw( $item_object ); // Reuse raw create which handles updates too
	}

	/**
	 * Update an existing catalog item.
	 *
	 * @param string $item_id Square item ID.
	 * @param array  $data    Updated item data.
	 * @return array|WP_Error Updated item or error.
	 */
	public function update_item( $item_id, array $data ) {
		// Get current item to preserve version and existing data.
		$current = $this->client->get( 'catalog/object/' . $item_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$object = $current['object'] ?? array();
		$version = $object['version'] ?? 0;
		$item_data = $object['item_data'] ?? array();

		// Start with existing item_data to preserve image_ids, tax_ids, etc.
		$new_item_data = $item_data;

		// Update fields if provided.
		if ( isset( $data['name'] ) ) {
			$new_item_data['name'] = $data['name'];
		}
		if ( isset( $data['description'] ) ) {
			$new_item_data['description'] = $data['description'];
		}
		if ( isset( $data['product_type'] ) ) {
			$new_item_data['product_type'] = $data['product_type'];
		}
		if ( isset( $data['category_id'] ) ) {
			if ( ! empty( $data['category_id'] ) ) {
				$new_item_data['category_id'] = $data['category_id'];
			} else {
				// To unset, we might need to be careful, but empty string usually means remove.
				// Square requires omitting the field to remove it? Or setting null?
				// For now, if empty, we remove key.
				unset( $new_item_data['category_id'] );
			}
		}

		// Handle variations.
		$variations = $new_item_data['variations'] ?? array();
		$variation_prices = $data['variation_prices'] ?? array();

		if ( ! empty( $variations ) ) {
			foreach ( $variations as &$variation ) {
				$var_id = $variation['id'];
				
				// Update name if provided.
				if ( isset( $data['variation_names'][ $var_id ] ) ) {
					$variation['item_variation_data']['name'] = sanitize_text_field( $data['variation_names'][ $var_id ] );
				}

				// Update price: Priority 1: Specific variation price, Priority 2: Generic price if set.
				$new_price = null;
				if ( isset( $variation_prices[ $var_id ] ) ) {
					$new_price = $variation_prices[ $var_id ];
				} elseif ( isset( $data['price'] ) ) {
					$new_price = $data['price'];
				}

				if ( null !== $new_price ) {
					$variation['item_variation_data']['price_money'] = array(
						'amount'   => intval( floatval( $new_price ) * 100 ),
						'currency' => $data['currency'] ?? ( $variation['item_variation_data']['price_money']['currency'] ?? 'USD' ),
					);
				}

				// Update tracking status (still bulk for simplicity unless requested otherwise).
				if ( isset( $data['track_inventory'] ) ) {
					$variation['item_variation_data']['track_inventory'] = ! empty( $data['track_inventory'] );
				}
				
				// Ensure version is present.
				$variation['version'] = $variation['version'] ?? 0;
			}
			unset( $variation );
		}
		$new_item_data['variations'] = $variations;

		// Build updated object.
		$updated_object = array(
			'type'    => 'ITEM',
			'id'      => $item_id,
			'version' => $version,
			'present_at_all_locations' => $object['present_at_all_locations'] ?? true,
			'item_data' => $new_item_data,
		);

		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object'          => $updated_object,
		);

		$response = $this->client->post( 'catalog/object', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['catalog_object'] ?? $response;
	}

	/**
	 * Delete a catalog item.
	 *
	 * @param string $item_id Square item ID.
	 * @return array|WP_Error Result or error.
	 */
	public function delete_item( $item_id ) {
		$item_id = trim( $item_id );
		$response = $this->client->delete( 'catalog/object/' . $item_id );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status_code = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$error_msg = $response->get_error_message();

			// If item is already gone from Square (404), or message says not found, still delete locally.
			if ( 404 === $status_code || false !== stripos( $error_msg, 'not found' ) ) {
				SPP_Database::delete_catalog_item( $item_id );
				return array( 'deleted_object_ids' => array( $item_id ), 'local_only' => true );
			}
			return $response;
		}

		// Also delete from local database.
		$db_deleted = SPP_Database::delete_catalog_item( $item_id );

		if ( ! $db_deleted && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SPP Catalog Deletion Warning: Local database record for ' . $item_id . ' was not found or could not be deleted.' );
		}

		return $response;
	}

	/**
	 * Toggle archive status of a catalog item.
	 *
	 * @param string $item_id  Square item ID.
	 * @param bool   $archived Whether to archive or unarchive.
	 * @return array|WP_Error  Result or error.
	 */
	public function archive_item( $item_id, $archived = true ) {
		$item_id = trim( $item_id );
		
		// Get current item details first.
		$item = $this->get_item_details( $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		// Set archived status in both places for maximum compatibility.
		$item['is_archived'] = $archived;
		$item['item_data']['is_archived'] = $archived;

		// Set archived status on variations if they exist.
		if ( ! empty( $item['item_data']['variations'] ) ) {
			foreach ( $item['item_data']['variations'] as &$variation ) {
				$variation['item_variation_data']['is_archived'] = $archived;
				// Clean up variation read-only fields.
				unset( $variation['updated_at'], $variation['created_at'] );
			}
		}

		// Clean up read-only fields not allowed in update (KEEP version).
		unset( $item['updated_at'], $item['created_at'], $item['image_url'] );

		$data = array(
			'idempotency_key' => uniqid(),
			'object' => $item,
		);

		$response = $this->client->post( 'catalog/object', $data );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SPP Catalog Archival Error: ' . $response->get_error_message() );
			}
			return $response;
		}

		if ( ! empty( $response['catalog_object'] ) ) {
			// Ensure sync class is loaded.
			if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
				require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
			}
			// Use the actual object returned by Square for the DB update to ensure consistency.
			$sync = new SPP_Sync_Catalog( $this->client );
			$sync->sync_single_item( $response['catalog_object'] );
		}

		return $response;
	}

	/**
	 * Duplicate a catalog item.
	 *
	 * @param string $item_id Square item ID.
	 * @return array|WP_Error Created item or error.
	 */
	public function duplicate_item( $item_id ) {
		$item_id = trim( $item_id );
		
		// Get current item details.
		$item = $this->get_item_details( $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		// Strip unique Square IDs to create a copy.
		unset( $item['id'], $item['version'], $item['updated_at'], $item['created_at'], $item['image_id'], $item['image_url'] );
		
		// Append " (Copy)" to the name.
		$item['item_data']['name'] .= ' ' . __( '(Copy)', 'cube-payment-portal' );
		
		// Re-map as a new temporary ID for the batch.
		$new_item_temp_id = '#new_item_' . uniqid();
		$item['id'] = $new_item_temp_id;

		// Strip IDs from variations too.
		if ( ! empty( $item['item_data']['variations'] ) ) {
			foreach ( $item['item_data']['variations'] as &$v ) {
				unset( $v['id'], $v['version'], $v['location_overrides'], $v['inventory_alert_type'], $v['inventory_alert_threshold'] );
				$v['id'] = '#new_copy_' . uniqid();
				
				// Ensure variation points to the new parent ID.
				if ( isset( $v['item_variation_data'] ) ) {
					$v['item_variation_data']['item_id'] = $new_item_temp_id;
				}
			}
		}

		$data = array(
			'idempotency_key' => uniqid(),
			'object' => $item,
		);

		$response = $this->client->post( 'catalog/object', $data );

		if ( ! is_wp_error( $response ) && ! empty( $response['catalog_object']['id'] ) ) {
			// Ensure sync class is loaded.
			if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
				require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
			}
			// Trigger a sync for the new item.
			$sync = new SPP_Sync_Catalog( $this->client );
			$sync->sync_single_item( $response['catalog_object'] );
		}

		return $response;
	}

	/**
	 * Get all categories from catalog.
	 *
	 * @return array|WP_Error Categories or error.
	 */
	public function get_categories() {
		$query_params = array( 'types' => 'CATEGORY' );
		$query_string = http_build_query( $query_params );
		$response = $this->client->get( 'catalog/list?' . $query_string );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = array();
		$objects = $response['objects'] ?? array();

		foreach ( $objects as $object ) {
			if ( 'CATEGORY' === $object['type'] && empty( $object['is_deleted'] ) ) {
				$categories[] = array(
					'id'   => $object['id'],
					'name' => $object['category_data']['name'] ?? __( 'Unnamed', 'cube-payment-portal' ),
				);
			}
		}

		return $categories;
	}

	/**
	 * Upload an image to a catalog item.
	 *
	 * Uses the new centralized request_multipart method for file uploads.
	 *
	 * @param string $item_id    Square item ID.
	 * @param string $file_path  Absolute path to the image file.
	 * @return array|WP_Error    Created image object or error.
	 */
	public function upload_item_image( $item_id, $file_path ) {
		// Validation.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Image file not found.', 'cube-payment-portal' ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_not_readable', __( 'Image file is not readable by the server.', 'cube-payment-portal' ) );
		}

		// Check file size (limit to 14MB to be safe, Square limit is 15MB).
		$file_size = filesize( $file_path );
		if ( $file_size > 14 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'Image size exceeds the 14MB limit.', 'cube-payment-portal' ) );
		}

		// Check mime type.
		$file_info = wp_check_filetype( $file_path );
		$allowed_types = array( 'jpg', 'jpeg', 'png', 'gif' );
		if ( ! in_array( $file_info['ext'], $allowed_types, true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Invalid file type. Only JPG, PNG, and GIF are allowed.', 'cube-payment-portal' ) );
		}

		// Prepare JSON data for the request part.
		$image_id = '#image_' . wp_generate_uuid4();
		$json_data = array(
			'idempotency_key' => wp_generate_uuid4(),
			'object_id'       => $item_id,
			'image'           => array(
				'type' => 'IMAGE',
				'id'   => $image_id,
				'present_at_all_locations' => true,
				'image_data' => array(
					'caption' => basename( $file_path ),
				),
			),
		);

		// Use the centralized request_multipart method.
		$response = $this->client->request_multipart(
			'catalog/images',
			$json_data,
			$file_path,
			'image' // Catalog images use 'image' as the field name.
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['image'] ?? $response;
	}
}

