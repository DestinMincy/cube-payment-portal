<?php
/**
 * Booking Sync functionality.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Sync_Bookings
 *
 * Handles bidirectional sync of bookings between WordPress and Square.
 */
class SPP_Sync_Bookings {

    /**
     * Square API client.
     *
     * @var SPP_Square_Client
     */
    private $client;

    /**
     * Bookings API.
     *
     * @var SPP_Bookings
     */
    private $bookings_api;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client       = new SPP_Square_Client();
        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }
        $this->bookings_api = new SPP_Bookings( $this->client );
    }

    /**
     * Sync bookings for a specific period, handling pagination of time if needed.
     * Square API limits range to 31 days. This wrapper splits larger ranges into chunks.
     *
     * @param string|int $start Start time (string or timestamp).
     * @param string|int $end   End time (string or timestamp).
     * @param array      $args  Additional args.
     * @return array|WP_Error Aggregate result.
     */
    public function sync_period( $start, $end, $args = array() ) {
        $start_ts = is_numeric( $start ) ? $start : strtotime( $start );
        $end_ts   = is_numeric( $end ) ? $end : strtotime( $end );

        if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) {
            return new WP_Error( 'invalid_range', 'Invalid date range for sync.' );
        }

        $current_start = $start_ts;
        $total_synced  = 0;
        $total_errors  = 0;
        $all_details   = array();

        // Loop through 30-day chunks
        while ( $current_start < $end_ts ) {
            $chunk_end = min( $end_ts, strtotime( '+30 days', $current_start ) );
            
            $chunk_args = $args;
            $chunk_args['start_at_min'] = gmdate( 'Y-m-d\TH:i:s\Z', $current_start );
            $chunk_args['start_at_max'] = gmdate( 'Y-m-d\TH:i:s\Z', $chunk_end );

            $result = $this->sync_from_square( $chunk_args );

            if ( is_wp_error( $result ) ) {
                return $result; // Fail fast or continue? Fail fast is safer for now.
            }

            $total_synced += $result['synced'];
            $total_errors += $result['errors'];
            if ( ! empty( $result['details'] ) ) {
                $all_details = array_merge( $all_details, $result['details'] );
            }

            // Move to next chunk (add 1 second to avoid overlap if strict, but Square usually handles inclusive-exclusive well? 
            // Actually, risk of overlap: standard is start inclusive, end exclusive? 
            // Square docs: "The start of the time range, inclusive" and "The end of the time range, exclusive".
            // If so, we can use exact end as next start.
            // Let's assume inclusive start, exclusive end.
            $current_start = $chunk_end;
        }

        return array(
            'success' => true,
            'synced'  => $total_synced,
            'errors'  => $total_errors,
            'details' => $all_details,
        );
    }

    /**
     * Sync bookings from Square to WordPress.
     *
     * @param array $args Optional arguments.
     * @return array|WP_Error Sync results or error.
     */
    public function sync_from_square( array $args = array() ) {
        $defaults = array(
            'limit'        => 100,
            'use_cursor'   => true,
            'location_id'  => $this->client->get_location_id(),
        );

        $args = wp_parse_args( $args, $defaults );

        // If location_id is provided, use it. If empty, Square API returns bookings from all locations.
        // We warn if it's missing but proceed to try syncing all.
        if ( empty( $args['location_id'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Sync Bookings: No location ID configured. Attempting to sync from all locations.' );
        }

        // Get last sync cursor if enabled.
        $cursor = null;
        if ( $args['use_cursor'] ) {
            $cursor = get_option( 'spp_bookings_sync_cursor', null );
        }

        // Set time range for sync.
        // Square ListBookings has a limit of 31 days between start_at_min and start_at_max.
        // If max is not set, it defaults to min + 31 days.
        // Previously we set min to -1 year, which made max -11 months, missing future bookings.
        // We now default to syncing from TODAY to +30 days to catch upcoming appointments.
        if ( ! empty( $args['start_at_min'] ) ) {
            $start_at_min = $args['start_at_min'];
            // If user provided min but not max, we let Square default (min + 31 days).
            // If they provided both, we use both.
            if ( ! empty( $args['start_at_max'] ) ) {
                $start_at_max = $args['start_at_max'];
            } else {
                $start_at_max = null; 
            }
        } else {
            // Default: Sync upcoming 30 days.
            $start_at_min = gmdate( 'Y-m-d\TH:i:s\Z', time() ); 
            $start_at_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days' ) );
        }

        $synced_count = 0;
        $error_count  = 0;
        $errors       = array();

        try {
            do {
                // Fetch bookings from Square.
                $query_args = array(
                    'location_id'  => $args['location_id'],
                    'limit'        => $args['limit'],
                    'cursor'       => $cursor,
                    'start_at_min' => $start_at_min,
                );
                
                if ( ! empty( $start_at_max ) ) {
                    $query_args['start_at_max'] = $start_at_max;
                }

                $result = $this->bookings_api->list_bookings( $query_args );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }

                $bookings = $result['bookings'] ?? array();
                $cursor   = $result['cursor'] ?? null;

                // Collect service IDs for batch retrieval.
                $service_ids = array();
                foreach ( $bookings as $booking ) {
                    if ( ! empty( $booking['appointment_segments'][0]['service_variation_id'] ) ) {
                        $service_ids[] = $booking['appointment_segments'][0]['service_variation_id'];
                    }
                }

                // Batch fetch services to resolve names.
                $service_map = array();
                if ( ! empty( $service_ids ) ) {
                    if ( ! class_exists( 'SPP_Catalog' ) ) {
                        require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
                    }
                    $catalog = new SPP_Catalog( $this->client );
                    // Request related objects to get parent Item data (for names).
                    $catalog_response = $catalog->batch_retrieve_objects( $service_ids, true );
                    
                    if ( ! is_wp_error( $catalog_response ) ) {
                        $items_map = array();
                        // Build map of Item ID -> Item Name from related objects.
                        if ( ! empty( $catalog_response['related_objects'] ) ) {
                            foreach ( $catalog_response['related_objects'] as $rel ) {
                                if ( 'ITEM' === $rel['type'] ) {
                                    $items_map[ $rel['id'] ] = $rel['item_data']['name'] ?? '';
                                }
                            }
                        }

                        // Map Variation ID -> Full Service Name.
                        if ( ! empty( $catalog_response['objects'] ) ) {
                            foreach ( $catalog_response['objects'] as $obj ) {
                                if ( 'ITEM_VARIATION' !== $obj['type'] ) {
                                    continue;
                                }

                                $var_data = $obj['item_variation_data'];
                                $var_name = $var_data['name'] ?? '';
                                $item_id = $var_data['item_id'] ?? '';
                                $item_name = $items_map[ $item_id ] ?? '';

                                if ( ! empty( $item_name ) ) {
                                    if ( 'Regular' === $var_name || empty( $var_name ) ) {
                                        $full_name = $item_name;
                                    } else {
                                        $full_name = $item_name . ' - ' . $var_name;
                                    }
                                } else {
                                    $full_name = $var_name ?: 'Unknown Service';
                                }

                                $service_map[ $obj['id'] ] = $full_name;
                            }
                        }
                    }
                }

                // Process each booking.
                foreach ( $bookings as $booking_data ) {
                    $sync_result = $this->sync_single_booking( $booking_data, $service_map );

                    if ( is_wp_error( $sync_result ) ) {
                        $error_count++;
                        $errors[] = array(
                            'booking_id' => $booking_data['id'] ?? 'unknown',
                            'error'      => $sync_result->get_error_message(),
                        );
                    } else {
                        $synced_count++;
                    }
                }

                // Save cursor for next sync.
                if ( $cursor ) {
                    update_option( 'spp_bookings_sync_cursor', $cursor );
                }

                // Break if no more pages.
                if ( empty( $cursor ) ) {
                    break;
                }
            } while ( true );

            // Clear cursor on successful complete sync.
            delete_option( 'spp_bookings_sync_cursor' );
            update_option( 'spp_bookings_last_sync', time() );

            return array(
                'success' => true,
                'synced'  => $synced_count,
                'errors'  => $error_count,
                'details' => $errors,
            );

        } catch ( Exception $e ) {
            return new WP_Error( 'sync_exception', $e->getMessage() );
        }
    }

    /**
     * Sync a single booking from Square data.
     *
     * @param array $booking_data Booking data from Square API.
     * @param array $service_map  Optional map of service ID to name.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function sync_single_booking( array $booking_data, array $service_map = array() ) {
        if ( empty( $booking_data['id'] ) ) {
            return new WP_Error( 'invalid_booking', 'Booking ID is required' );
        }

        // Extract main service variation ID (first appointment segment).
        $service_id = '';
        $service_name = '';
        $team_member_id = '';
        
        if ( ! empty( $booking_data['appointment_segments'][0] ) ) {
            $segment = $booking_data['appointment_segments'][0];
            $service_id = $segment['service_variation_id'] ?? '';
            $team_member_id = $segment['team_member_id'] ?? '';
            
            // Resolve service name from map if available.
            if ( ! empty( $service_id ) && isset( $service_map[ $service_id ] ) ) {
                $service_name = $service_map[ $service_id ];
            }
        }

        // If still no service name, fetch from Catalog API as fallback.
        if ( empty( $service_name ) && ! empty( $service_id ) ) {
            if ( ! class_exists( 'SPP_Catalog' ) ) {
                require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
            }
            $catalog = new SPP_Catalog();
            $obj = $catalog->get_item_details( $service_id );
            
            if ( ! is_wp_error( $obj ) ) {
                $var_data = $obj['item_variation_data'] ?? array();
                $variation_name = $var_data['name'] ?? '';
                $parent_id = $var_data['item_id'] ?? '';
                
                if ( ! empty( $parent_id ) ) {
                    $parent = $catalog->get_item_details( $parent_id );
                    if ( ! is_wp_error( $parent ) ) {
                        $item_name = $parent['item_data']['name'] ?? '';
                        if ( ! empty( $item_name ) ) {
                            if ( 'Regular' === $variation_name || empty( $variation_name ) ) {
                                $service_name = $item_name;
                            } else {
                                $service_name = "$item_name - $variation_name";
                            }
                        }
                    }
                }
                
                // Fallback to variation name if parent lookup fails or name still empty
                if ( empty( $service_name ) ) {
                    $service_name = $variation_name ?: 'Unknown Service';
                }
            }
        }
        
        // Final fallback
        if ( empty( $service_name ) ) {
            $service_name = 'Unknown Service';
        }

        // Calculate end_at if not provided (Square API doesn't always return it at root level).
        $end_at = $booking_data['end_at'] ?? null;
        if ( empty( $end_at ) && ! empty( $booking_data['start_at'] ) ) {
            $duration_minutes = 0;
            if ( ! empty( $booking_data['appointment_segments'] ) ) {
                foreach ( $booking_data['appointment_segments'] as $seg ) {
                    $duration_minutes += (int) ( $seg['duration_minutes'] ?? 0 );
                    // Check intermission too? Square says duration is service duration.
                }
            }
             // Fallback default duration if 0
            if ( $duration_minutes <= 0 ) {
                $duration_minutes = 30;
            }
            
            // Add duration to start_at.
            $start_timestamp = strtotime( $booking_data['start_at'] );
            $end_at = gmdate( 'Y-m-d\TH:i:s\Z', $start_timestamp + ( $duration_minutes * 60 ) );
        }

        $data = array(
            'square_id'      => $booking_data['id'],
            'customer_id'    => $booking_data['customer_id'] ?? '',
            'service_id'     => $service_id,
            'service_name'   => $service_name,
            'team_member_id' => $team_member_id,
            'location_id'    => $booking_data['location_id'] ?? '',
            'start_at'       => $booking_data['start_at'] ?? null,
            'end_at'         => $end_at,
            'status'         => $booking_data['status'] ?? 'PENDING',
            'version'        => $booking_data['version'] ?? 0,
            'created_at'     => $booking_data['created_at'] ?? null,
            'updated_at'     => $booking_data['updated_at'] ?? null,
        );

        // We need SPP_Database class
        if ( ! class_exists( 'SPP_Database' ) ) {
            require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
        }

        $result = SPP_Database::save_booking( $data );

        if ( false === $result ) {
            return new WP_Error( 'db_save_failed', 'Failed to save booking to database' );
        }

        return true;
    }
}
