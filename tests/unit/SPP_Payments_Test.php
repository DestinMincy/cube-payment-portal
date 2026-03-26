<?php
/**
 * Test case for SPP_Payments class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_Payments_Test
 */
class SPP_Payments_Test extends TestCase {

    /**
     * Payments instance.
     *
     * @var SPP_Payments
     */
    private $payments;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();
        $client = new SPP_Square_Client( 'test_token', true );
        $this->payments = new SPP_Payments( $client );
    }

    /**
     * Test to_cents converts dollars to cents correctly.
     */
    public function test_to_cents() {
        $this->assertEquals( 1000, $this->payments->to_cents( 10.00 ) );
        $this->assertEquals( 1050, $this->payments->to_cents( 10.50 ) );
        $this->assertEquals( 999, $this->payments->to_cents( 9.99 ) );
        $this->assertEquals( 0, $this->payments->to_cents( 0 ) );
    }

    /**
     * Test from_cents converts cents to dollars correctly.
     */
    public function test_from_cents() {
        $this->assertEquals( 10.00, $this->payments->from_cents( 1000 ) );
        $this->assertEquals( 10.50, $this->payments->from_cents( 1050 ) );
        $this->assertEquals( 9.99, $this->payments->from_cents( 999 ) );
        $this->assertEquals( 0, $this->payments->from_cents( 0 ) );
    }

    /**
     * Test to_cents handles string input.
     */
    public function test_to_cents_string_input() {
        $this->assertEquals( 1000, $this->payments->to_cents( '10.00' ) );
        $this->assertEquals( 2550, $this->payments->to_cents( '25.50' ) );
    }

    /**
     * Test to_cents rounds correctly.
     */
    public function test_to_cents_rounding() {
        $this->assertEquals( 1000, $this->payments->to_cents( 9.999 ) );
        $this->assertEquals( 1001, $this->payments->to_cents( 10.005 ) );
    }
}
