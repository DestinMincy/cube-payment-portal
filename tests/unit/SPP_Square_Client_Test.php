<?php
/**
 * Test case for SPP_Square_Client class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_Square_Client_Test
 */
class SPP_Square_Client_Test extends TestCase {

    /**
     * Test get_api_url returns sandbox URL when sandbox mode.
     */
    public function test_get_api_url_sandbox() {
        $client = new SPP_Square_Client( 'token', true );
        
        $this->assertEquals( 'https://connect.squareupsandbox.com', $client->get_api_url() );
    }

    /**
     * Test get_api_url returns production URL when not sandbox.
     */
    public function test_get_api_url_production() {
        $client = new SPP_Square_Client( 'token', false );
        
        $this->assertEquals( 'https://connect.squareup.com', $client->get_api_url() );
    }

    /**
     * Test is_connected returns true when token present.
     */
    public function test_is_connected_with_token() {
        $client = new SPP_Square_Client( 'test_token', true );
        
        $this->assertTrue( $client->is_connected() );
    }

    /**
     * Test is_connected returns false when no token.
     */
    public function test_is_connected_without_token() {
        $client = new SPP_Square_Client( '', true );
        
        $this->assertFalse( $client->is_connected() );
    }

    /**
     * Test set_access_token updates token.
     */
    public function test_set_access_token() {
        $client = new SPP_Square_Client( '', true );
        
        $this->assertFalse( $client->is_connected() );
        
        $client->set_access_token( 'new_token' );
        
        $this->assertTrue( $client->is_connected() );
    }
}
