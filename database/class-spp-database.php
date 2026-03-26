<?php
/**
 * Database operations.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load trait files.
require_once __DIR__ . '/traits/trait-spp-database-transactions.php';
require_once __DIR__ . '/traits/trait-spp-database-invoices.php';
require_once __DIR__ . '/traits/trait-spp-database-customers.php';
require_once __DIR__ . '/traits/trait-spp-database-subscriptions.php';
require_once __DIR__ . '/traits/trait-spp-database-orders.php';
require_once __DIR__ . '/traits/trait-spp-database-catalog.php';
require_once __DIR__ . '/traits/trait-spp-database-bookings.php';

/**
 * Class SPP_Database
 *
 * Handles database queries and operations.
 *
 * This class uses traits to organize methods by entity type:
 * - SPP_Database_Transactions: Transaction-related queries
 * - SPP_Database_Invoices: Invoice-related queries
 * - SPP_Database_Customers: Customer-related queries
 * - SPP_Database_Subscriptions: Subscription-related queries
 * - SPP_Database_Orders: Order-related queries
 * - SPP_Database_Catalog: Catalog-related queries
 * - SPP_Database_Bookings: Booking-related queries
 */
class SPP_Database {

    use SPP_Database_Transactions;
    use SPP_Database_Invoices;
    use SPP_Database_Customers;
    use SPP_Database_Subscriptions;
    use SPP_Database_Orders;
    use SPP_Database_Catalog;
    use SPP_Database_Bookings;
}
