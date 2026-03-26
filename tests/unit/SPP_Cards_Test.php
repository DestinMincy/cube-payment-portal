<?php
/**
 * Test case for SPP_Cards class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_Cards_Test
 */
class SPP_Cards_Test extends TestCase {

    /**
     * Cards instance.
     *
     * @var SPP_Cards
     */
    private $cards;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();
        $client = new SPP_Square_Client( 'test_token', true );
        $this->cards = new SPP_Cards( $client );
    }

    /**
     * Test validate_token returns false for empty token.
     */
    public function test_validate_token_empty() {
        $this->assertFalse( $this->cards->validate_token( '' ) );
    }

    /**
     * Test validate_token returns false for null.
     */
    public function test_validate_token_null() {
        $this->assertFalse( $this->cards->validate_token( null ) );
    }

    /**
     * Test validate_token returns false for short token.
     */
    public function test_validate_token_short() {
        $this->assertFalse( $this->cards->validate_token( 'abc' ) );
    }

    /**
     * Test validate_token returns true for valid token.
     */
    public function test_validate_token_valid() {
        $this->assertTrue( $this->cards->validate_token( 'cnon:card-nonce-ok' ) );
    }

    /**
     * Test format_card_for_display returns expected structure.
     */
    public function test_format_card_for_display() {
        $card = array(
            'id'              => 'card_123',
            'card_brand'      => 'VISA',
            'last_4'          => '4242',
            'exp_month'       => 12,
            'exp_year'        => 2025,
            'cardholder_name' => 'John Doe',
        );

        $formatted = $this->cards->format_card_for_display( $card );

        $this->assertEquals( 'card_123', $formatted['id'] );
        $this->assertEquals( 'VISA', $formatted['brand'] );
        $this->assertEquals( '4242', $formatted['last_four'] );
        $this->assertEquals( 12, $formatted['exp_month'] );
        $this->assertEquals( 2025, $formatted['exp_year'] );
        $this->assertEquals( 'John Doe', $formatted['cardholder'] );
    }

    /**
     * Test format_card_for_display handles missing fields.
     */
    public function test_format_card_for_display_missing_fields() {
        $card = array();

        $formatted = $this->cards->format_card_for_display( $card );

        $this->assertEquals( '', $formatted['id'] );
        $this->assertEquals( 'UNKNOWN', $formatted['brand'] );
        $this->assertEquals( '****', $formatted['last_four'] );
    }
}
