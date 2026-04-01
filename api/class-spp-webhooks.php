<?php
/**
 * Square Webhooks handler.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Webhooks
 *
 * Handles incoming webhooks from Square.
 */
class SPP_Webhooks {

    /**
     * Webhook signature key.
     *
     * @var string
     */
    private $signature_key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->signature_key = get_option( 'spp_webhook_signature_key', '' );
    }

    /**
     * Register REST API routes for webhooks.
     */
    public function register_routes() {
        register_rest_route(
            'spp/v1',
            '/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle incoming webhook.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function handle_webhook( $request ) {
        // Rate limiting: 100 requests per minute per IP.
        $rate_limit_result = $this->check_rate_limit();
        if ( is_wp_error( $rate_limit_result ) ) {
            return new WP_REST_Response( array( 'error' => 'Rate limit exceeded' ), 429 );
        }

        $body = $request->get_body();
        $signature = $request->get_header( 'x-square-hmacsha256-signature' );

        // Verify signature.
        if ( ! $this->verify_signature( $body, $signature ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }

        $payload = json_decode( $body, true );

        if ( empty( $payload['type'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
        }

        // Process by event type.
        $result = $this->process_event( $payload );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Check rate limit for webhook requests.
     *
     * Limits requests to 100 per minute per IP address.
     *
     * @return bool|WP_Error True if within limit, error if exceeded.
     */
    private function check_rate_limit() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rate_key = 'spp_webhook_rate_' . md5( $ip );
        $count = (int) get_transient( $rate_key );

        if ( $count >= 100 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook Security: Rate limit exceeded for IP: ' . $ip );
            }
            return new WP_Error( 'rate_limited', __( 'Rate limit exceeded.', 'cube-payment-portal' ) );
        }

        set_transient( $rate_key, $count + 1, 60 );
        return true;
    }

    /**
     * Verify webhook signature.
     *
     * @param string $body      Request body.
     * @param string $signature Signature from header.
     * @return bool True if valid.
     */
    public function verify_signature( $body, $signature ) {
        // Signature key configured: always verify regardless of environment.
        if ( ! empty( $this->signature_key ) ) {
            if ( empty( $signature ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Webhook Security: Rejecting webhook - no signature provided.' );
                }
                return false;
            }
            return $this->verify_actual_signature( $body, $signature );
        }

        // No signature key configured at all.
        $environment = get_option( 'spp_environment', 'production' );

        // In production, always reject if no signature key is set.
        if ( 'production' === $environment ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook Security: Rejecting webhook - signature key not configured for production.' );
            }
            return false;
        }

        // Sandbox with no signature key: allow only if no signature is present either
        // (i.e. local dev tunnels that don't send signatures). Log for visibility.
        error_log( 'SPP Webhook Warning: Processing unsigned webhook in sandbox mode (no signature key configured). IP: ' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' ) );

        return true;
    }

    /**
     * Perform actual signature verification.
     *
     * @param string $body      Request body.
     * @param string $signature Signature from header.
     * @return bool True if valid.
     */
    private function verify_actual_signature( $body, $signature ) {
        if ( empty( $this->signature_key ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook Security: Cannot verify signature - no signature key configured.' );
            }
            return false;
        }

        $webhook_url = rest_url( 'spp/v1/webhook' );
        $to_sign = $webhook_url . $body;
        $expected = base64_encode( hash_hmac( 'sha256', $to_sign, $this->signature_key, true ) );

        $is_valid = hash_equals( $expected, $signature );

        if ( ! $is_valid && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook Security: Rejecting webhook - signature mismatch.' );
        }

        return $is_valid;
    }

    /**
     * Process webhook event.
     *
     * @param array $payload Webhook payload.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function process_event( array $payload ) {
        $type = $payload['type'];
        $data = $payload['data']['object'] ?? array();

        switch ( $type ) {
            case 'payment.created':
                return $this->handle_payment_created( $data );

            case 'payment.updated':
                return $this->handle_payment_updated( $data );

            case 'payment.completed':
                return $this->handle_payment_completed( $data );

            case 'payment.failed':
                return $this->handle_payment_failed( $data );

            case 'refund.created':
            case 'refund.updated':
                return $this->handle_refund_created( $data );

            case 'subscription.created':
                return $this->handle_subscription_created( $data );

            case 'subscription.updated':
                return $this->handle_subscription_updated( $data );

            case 'invoice.payment_made':
                return $this->handle_invoice_paid( $data );

            case 'invoice.created':
                return $this->handle_invoice_created( $data );

            case 'invoice.updated':
                return $this->handle_invoice_updated( $data );

            case 'invoice.published':
                return $this->handle_invoice_published( $data );

            case 'invoice.canceled':
                return $this->handle_invoice_canceled( $data );

            case 'invoice.deleted':
                return $this->handle_invoice_deleted( $data );

            case 'catalog.version.updated':
                return $this->handle_catalog_updated( $data );

            case 'order.created':
                return $this->handle_order_created( $data );

            case 'order.updated':
                return $this->handle_order_updated( $data );

            case 'inventory.count.updated':
                return $this->handle_inventory_updated( $data );

            case 'customer.created':
                return $this->handle_customer_created( $data );

            case 'customer.updated':
                return $this->handle_customer_updated( $data );

            case 'customer.deleted':
                return $this->handle_customer_deleted( $data );

            case 'loyalty.account.created':
            case 'loyalty.account.updated':
                return $this->handle_loyalty_account_event( $data, $type );

            case 'loyalty.event.created':
                return $this->handle_loyalty_event( $data );

            case 'gift_card.created':
            case 'gift_card.updated':
                return $this->handle_gift_card_event( $data, $type );

            case 'gift_card.activity.created':
                return $this->handle_gift_card_activity( $data );

            case 'dispute.created':
            case 'dispute.updated':
                return $this->handle_dispute_event( $data, $type );

            case 'booking.created':
            case 'booking.updated':
                return $this->handle_booking_event( $data, $type );

            default:
                // Unknown event type - log and ignore.
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'SPP Webhook: Unknown event type: ' . esc_html( $type ) );
                }
                return true;
        }
    }

    /**
     * Handle payment.created event.
     *
     * @param array $data Payment data.
     * @return bool True on success.
     */
    private function handle_payment_created( array $data ) {
        $payment = $data['payment'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
        }

        // Sync full payment data from Square to local database.
        $sync = new SPP_Sync_Transactions();
        $result = $sync->sync_single_payment( $payment );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync created payment: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_payment_created', $payment['id'], $payment );

        return true;
    }

    /**
     * Handle payment.updated event.
     *
     * @param array $data Payment data.
     * @return bool True on success.
     */
    private function handle_payment_updated( array $data ) {
        $payment = $data['payment'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
        }

        // Sync full payment data from Square to local database.
        $sync = new SPP_Sync_Transactions();
        $result = $sync->sync_single_payment( $payment );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync updated payment: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_payment_updated', $payment['id'], $payment );

        return true;
    }

    /**
     * Handle payment.completed event.
     *
     * @param array $data Payment data.
     * @return bool True on success.
     */
    private function handle_payment_completed( array $data ) {
        $payment = $data['payment'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
        }

        // Sync full payment data from Square to local database.
        $sync = new SPP_Sync_Transactions();
        $result = $sync->sync_single_payment( $payment );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync completed payment: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_payment_completed', $payment['id'], $payment );

        return true;
    }

    /**
     * Handle payment.failed event.
     *
     * @param array $data Payment data.
     * @return bool True on success.
     */
    private function handle_payment_failed( array $data ) {
        $payment = $data['payment'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Transactions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-transactions.php';
        }

        // Sync full payment data from Square to local database.
        $sync = new SPP_Sync_Transactions();
        $result = $sync->sync_single_payment( $payment );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync failed payment: ' . $result->get_error_message() );
            }
        }

        // Notify admin.
        if ( get_option( 'spp_failed_payment_alerts', true ) ) {
            $admin_email = get_option( 'spp_admin_email', get_option( 'admin_email' ) );
            wp_mail(
                $admin_email,
                __( 'Payment Failed', 'cube-payment-portal' ),
                sprintf(
                    /* translators: %s: payment ID */
                    __( 'A payment has failed. Payment ID: %s', 'cube-payment-portal' ),
                    $payment['id']
                )
            );
        }

        do_action( 'spp_payment_failed', $payment['id'], $payment );

        return true;
    }

    /**
     * Handle refund.created event.
     *
     * @param array $data Refund data.
     * @return bool True on success.
     */
    private function handle_refund_created( array $data ) {
        $refund = $data['refund'] ?? $data;

        // Update the original payment status to refunded.
        global $wpdb;
        $table = $wpdb->prefix . 'spp_transactions';

        $wpdb->update(
            $table,
            array( 'status' => 'refunded' ),
            array( 'square_payment_id' => $refund['payment_id'] ),
            array( '%s' ),
            array( '%s' )
        );

        do_action( 'spp_refund_created', $refund['id'], $refund );

        return true;
    }

    /**
     * Handle subscription.created event.
     *
     * @param array $data Subscription data.
     * @return bool True on success.
     */
    private function handle_subscription_created( array $data ) {
        $subscription = $data['subscription'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Subscriptions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
        }

        // Sync subscription from Square to local database.
        $sync = new SPP_Sync_Subscriptions();
        $result = $sync->sync_single_subscription( $subscription );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync created subscription: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_subscription_created', $subscription['id'], $subscription );

        return true;
    }

    /**
     * Handle subscription.updated event.
     *
     * @param array $data Subscription data.
     * @return bool True on success.
     */
    private function handle_subscription_updated( array $data ) {
        $subscription = $data['subscription'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Subscriptions' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-subscriptions.php';
        }

        // Sync subscription from Square to local database.
        $sync = new SPP_Sync_Subscriptions();
        $result = $sync->sync_single_subscription( $subscription );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync updated subscription: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_subscription_updated', $subscription['id'], $subscription );

        return true;
    }

    /**
     * Handle invoice.payment_made event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_paid( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        global $wpdb;
        $table = $wpdb->prefix . 'spp_invoices';

        $wpdb->update(
            $table,
            array(
                'status'  => 'paid',
                'paid_at' => current_time( 'mysql' ),
            ),
            array( 'square_invoice_id' => $invoice['id'] ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        do_action( 'spp_invoice_paid', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle invoice.created event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_created( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
        }

        // Sync invoice from Square.
        $sync = new SPP_Sync_Invoices();
        $result = $sync->sync_single_invoice( $invoice );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync created invoice: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_invoice_created', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle invoice.updated event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_updated( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Invoices' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-invoices.php';
        }

        // Sync invoice from Square.
        $sync = new SPP_Sync_Invoices();
        $result = $sync->sync_single_invoice( $invoice );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync updated invoice: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_invoice_updated', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle invoice.published event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_published( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        global $wpdb;
        $table = $wpdb->prefix . 'spp_invoices';

        $wpdb->update(
            $table,
            array( 'status' => 'unpaid' ),
            array( 'square_invoice_id' => $invoice['id'] ),
            array( '%s' ),
            array( '%s' )
        );

        do_action( 'spp_invoice_published', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle invoice.canceled event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_canceled( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        global $wpdb;
        $table = $wpdb->prefix . 'spp_invoices';

        $wpdb->update(
            $table,
            array( 'status' => 'canceled' ),
            array( 'square_invoice_id' => $invoice['id'] ),
            array( '%s' ),
            array( '%s' )
        );

        do_action( 'spp_invoice_canceled', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle invoice.deleted event.
     *
     * @param array $data Invoice data.
     * @return bool True on success.
     */
    private function handle_invoice_deleted( array $data ) {
        $invoice = $data['invoice'] ?? $data;

        global $wpdb;
        $table = $wpdb->prefix . 'spp_invoices';

        // Soft delete by setting status.
        $wpdb->update(
            $table,
            array( 'status' => 'deleted' ),
            array( 'square_invoice_id' => $invoice['id'] ),
            array( '%s' ),
            array( '%s' )
        );

        do_action( 'spp_invoice_deleted', $invoice['id'], $invoice );

        return true;
    }

    /**
     * Handle catalog.version.updated event.
     *
     * @param array $data Catalog data.
     * @return bool True on success.
     */
    private function handle_catalog_updated( array $data ) {
        // Trigger subscription plan sync.
        do_action( 'spp_catalog_updated', $data );

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-catalog.php';
        }

        // Sync catalog from Square.
        $sync = new SPP_Sync_Catalog();
        $result = $sync->sync_from_square();

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync catalog: ' . $result->get_error_message() );
            }
        }

        // Schedule subscription plan sync.
        if ( ! wp_next_scheduled( 'spp_subscription_sync' ) ) {
            wp_schedule_single_event( time() + 60, 'spp_subscription_sync' );
        }

        return true;
    }

    /**
     * Handle customer.created event.
     *
     * @param array $data Customer data.
     * @return bool True on success.
     */
    private function handle_customer_created( array $data ) {
        $customer = $data['customer'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
        }

        // Sync customer from Square to local database.
        $sync = new SPP_Sync_Customers();
        $result = $sync->sync_single_customer( $customer );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync created customer: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_customer_created', $customer['id'], $customer );

        return true;
    }

    /**
     * Handle customer.updated event.
     *
     * @param array $data Customer data.
     * @return bool True on success.
     */
    private function handle_customer_updated( array $data ) {
        $customer = $data['customer'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-customers.php';
        }

        // Sync customer from Square to local database.
        $sync = new SPP_Sync_Customers();
        $result = $sync->sync_single_customer( $customer );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync updated customer: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_customer_updated', $customer['id'], $customer );

        return true;
    }

    /**
     * Handle customer.deleted event.
     *
     * @param array $data Customer data.
     * @return bool True on success.
     */
    private function handle_customer_deleted( array $data ) {
        $customer = $data['customer'] ?? $data;

        global $wpdb;
        $table = $wpdb->prefix . 'spp_customers';

        // Soft delete - mark as deleted but keep the record.
        $wpdb->update(
            $table,
            array( 'sync_status' => 'deleted' ),
            array( 'square_customer_id' => $customer['id'] ),
            array( '%s' ),
            array( '%s' )
        );

        do_action( 'spp_customer_deleted', $customer['id'], $customer );

        return true;
    }

    /**
     * Handle inventory.count.updated event.
     *
     * @param array $data Inventory data.
     * @return bool True on success.
     */
    private function handle_inventory_updated( array $data ) {
        $inventory_counts = $data['inventory_counts'] ?? array();

        // Check for low stock alerts.
        $low_stock_threshold = get_option( 'spp_low_stock_threshold', 10 );
        $low_stock_alerts_enabled = get_option( 'spp_low_stock_alerts', true );

        if ( $low_stock_alerts_enabled && ! empty( $inventory_counts ) ) {
            foreach ( $inventory_counts as $count ) {
                $quantity = (int) ( $count['quantity'] ?? 0 );
                $state = $count['state'] ?? '';

                if ( 'IN_STOCK' === $state && $quantity <= $low_stock_threshold && $quantity > 0 ) {
                    // Low stock alert.
                    $admin_email = get_option( 'spp_admin_email', get_option( 'admin_email' ) );
                    wp_mail(
                        $admin_email,
                        __( 'Low Stock Alert', 'cube-payment-portal' ),
                        sprintf(
                            /* translators: %1$s: item ID, %2$d: quantity */
                            __( 'Item %1$s is low on stock. Current quantity: %2$d', 'cube-payment-portal' ),
                            $count['catalog_object_id'] ?? 'Unknown',
                            $quantity
                        )
                    );
                }
            }
        }

        do_action( 'spp_inventory_updated', $inventory_counts );

        return true;
    }

    /**
     * Handle order.created event.
     *
     * @param array $data Order data.
     * @return bool True on success.
     */
    private function handle_order_created( array $data ) {
        $order = $data['order'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
        }

        // Sync order from Square to local database.
        $sync = new SPP_Sync_Orders();
        $result = $sync->sync_single_order( $order );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync created order: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_order_created', $order['id'], $order );

        return true;
    }

    /**
     * Handle order.updated event.
     *
     * @param array $data Order data.
     * @return bool True on success.
     */
    private function handle_order_updated( array $data ) {
        $order = $data['order'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Orders' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-orders.php';
        }

        // Sync order from Square to local database.
        $sync = new SPP_Sync_Orders();
        $result = $sync->sync_single_order( $order );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync updated order: ' . $result->get_error_message() );
            }
        }

        do_action( 'spp_order_updated', $order['id'], $order );

        return true;
    }

    /**
     * Handle loyalty account events.
     *
     * @param array  $data Event data.
     * @param string $type Event type.
     * @return bool True on success.
     */
    private function handle_loyalty_account_event( array $data, string $type ) {
        $account = $data['loyalty_account'] ?? $data;

        // Fire a hook so other plugins can respond.
        do_action( 'spp_loyalty_account_' . str_replace( '.', '_', $type ), $account['id'] ?? '', $account );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Loyalty account event: ' . $type . ' - Account ID: ' . ( $account['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Handle loyalty event (points earned/redeemed).
     *
     * @param array $data Event data.
     * @return bool True on success.
     */
    private function handle_loyalty_event( array $data ) {
        $event = $data['loyalty_event'] ?? $data;

        // Fire a hook so other plugins can respond.
        do_action( 'spp_loyalty_event_created', $event['id'] ?? '', $event );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Loyalty event: ' . ( $event['type'] ?? 'unknown' ) . ' - Event ID: ' . ( $event['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Handle gift card events.
     *
     * @param array  $data Event data.
     * @param string $type Event type.
     * @return bool True on success.
     */
    private function handle_gift_card_event( array $data, string $type ) {
        $gift_card = $data['gift_card'] ?? $data;

        // Fire a hook so other plugins can respond.
        do_action( 'spp_gift_card_' . str_replace( '.', '_', $type ), $gift_card['id'] ?? '', $gift_card );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Gift card event: ' . $type . ' - GC ID: ' . ( $gift_card['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Handle gift card activity (loads, redemptions, etc).
     *
     * @param array $data Event data.
     * @return bool True on success.
     */
    private function handle_gift_card_activity( array $data ) {
        $activity = $data['gift_card_activity'] ?? $data;

        // Fire a hook so other plugins can respond.
        do_action( 'spp_gift_card_activity_created', $activity['id'] ?? '', $activity );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Gift card activity: ' . ( $activity['type'] ?? 'unknown' ) . ' - Activity ID: ' . ( $activity['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Handle dispute events.
     *
     * @param array  $data Event data.
     * @param string $type Event type.
     * @return bool True on success.
     */
    private function handle_dispute_event( array $data, string $type ) {
        $dispute = $data['dispute'] ?? $data;

        // Fire a hook so other plugins can respond.
        do_action( 'spp_dispute_' . str_replace( '.', '_', $type ), $dispute['id'] ?? '', $dispute );

        // Send admin notification for new disputes.
        if ( 'dispute.created' === $type ) {
            $notify_disputes = get_option( 'spp_dispute_alerts', true );
            
            if ( $notify_disputes ) {
                $admin_email = get_option( 'spp_admin_email', get_option( 'admin_email' ) );
                $amount = isset( $dispute['amount_money']['amount'] ) ? number_format( $dispute['amount_money']['amount'] / 100, 2 ) : '0.00';
                $currency = $dispute['amount_money']['currency'] ?? 'USD';
                $reason = $dispute['reason'] ?? 'Unknown';
                $due_at = ! empty( $dispute['due_at'] ) ? wp_date( get_option( 'date_format' ), strtotime( $dispute['due_at'] ) ) : 'Not specified';

                wp_mail(
                    $admin_email,
                    __( 'New Payment Dispute', 'cube-payment-portal' ),
                    sprintf(
                        /* translators: %1$s: amount, %2$s: currency, %3$s: reason, %4$s: due date */
                        __( "A new payment dispute has been filed.\n\nAmount: %1\$s %2\$s\nReason: %3\$s\nResponse Due: %4\$s\n\nPlease log in to your Square Dashboard or WordPress admin to review and respond.", 'cube-payment-portal' ),
                        $amount,
                        $currency,
                        $reason,
                        $due_at
                    )
                );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Dispute event: ' . $type . ' - Dispute ID: ' . ( $dispute['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Handle booking events.
     *
     * @param array  $data Event data.
     * @param string $type Event type.
     * @return bool True on success.
     */
    private function handle_booking_event( array $data, string $type ) {
        $booking = $data['booking'] ?? $data;

        // Load sync class if not already loaded.
        if ( ! class_exists( 'SPP_Sync_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'sync/class-spp-sync-bookings.php';
        }

        // Sync booking from Square to local database.
        $sync = new SPP_Sync_Bookings();
        $result = $sync->sync_single_booking( $booking );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SPP Webhook: Failed to sync booking: ' . $result->get_error_message() );
            }
        }

        // Fire a hook so other plugins can respond.
        do_action( 'spp_booking_' . str_replace( '.', '_', $type ), $booking['id'] ?? '', $booking );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SPP Webhook: Booking event: ' . $type . ' - Booking ID: ' . ( $booking['id'] ?? 'unknown' ) );
        }

        return true;
    }

    /**
     * Get the webhook URL.
     *
     * @return string Webhook URL.
     */
    public static function get_webhook_url() {
        return rest_url( 'spp/v1/webhook' );
    }
}
