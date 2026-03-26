<?php
/**
 * Test case for SPP_OAuth class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_OAuth_Test
 */
class SPP_OAuth_Test extends TestCase {

    /**
     * OAuth instance.
     *
     * @var SPP_OAuth
     */
    private $oauth;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();
        $this->oauth = new SPP_OAuth( 'test_app_id', 'test_app_secret' );
    }

    /**
     * Test get_authorization_url returns valid URL.
     */
    public function test_get_authorization_url() {
        $redirect_uri = 'https://example.com/callback';
        $url = $this->oauth->get_authorization_url( $redirect_uri );

        $this->assertStringStartsWith( 'https://connect.squareup.com/oauth2/authorize', $url );
        $this->assertStringContainsString( 'client_id=test_app_id', $url );
        $this->assertStringContainsString( 'redirect_uri=' . urlencode( $redirect_uri ), $url );
    }

    /**
     * Test get_authorization_url includes state parameter when provided.
     */
    public function test_get_authorization_url_with_state() {
        $redirect_uri = 'https://example.com/callback';
        $state = 'abc123';
        $url = $this->oauth->get_authorization_url( $redirect_uri, $state );

        $this->assertStringContainsString( 'state=' . $state, $url );
    }

    /**
     * Test get_required_scopes returns array.
     */
    public function test_get_required_scopes() {
        $scopes = $this->oauth->get_required_scopes();

        $this->assertIsArray( $scopes );
        $this->assertContains( 'PAYMENTS_READ', $scopes );
        $this->assertContains( 'PAYMENTS_WRITE', $scopes );
        $this->assertContains( 'CUSTOMERS_READ', $scopes );
        $this->assertContains( 'SUBSCRIPTIONS_READ', $scopes );
    }

    /**
     * Test token encryption produces different output than input.
     */
    public function test_encrypt_token() {
        $token = 'test_access_token_12345';
        $encrypted = $this->oauth->encrypt_token( $token );

        $this->assertNotEquals( $token, $encrypted );
        $this->assertNotEmpty( $encrypted );
    }

    /**
     * Test token decryption returns original value.
     */
    public function test_decrypt_token() {
        $token = 'test_access_token_12345';
        $encrypted = $this->oauth->encrypt_token( $token );
        $decrypted = $this->oauth->decrypt_token( $encrypted );

        $this->assertEquals( $token, $decrypted );
    }

    /**
     * Test is_connected returns false when no token.
     */
    public function test_is_connected_returns_false_when_no_token() {
        $this->assertFalse( $this->oauth->is_connected() );
    }
}
