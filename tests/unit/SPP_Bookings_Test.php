<?php
/**
 * Test case for SPP_Bookings class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_Bookings_Test
 */
class SPP_Bookings_Test extends TestCase {

    /**
     * @var SPP_Square_Client|Mockery\MockInterface
     */
    protected $client;

    /**
     * @var SPP_Bookings
     */
    protected $bookings;

    /**
     * Set up test environment.
     */
    protected function set_up() {
        parent::set_up();
        $this->client = Mockery::mock( 'SPP_Square_Client' );
        $this->bookings = new SPP_Bookings( $this->client );
    }

    /**
     * Tear down test environment.
     */
    protected function tear_down() {
        Mockery::close();
        parent::tear_down();
    }

    /**
     * Test list_bookings calls correct endpoint.
     */
    public function test_list_bookings() {
        $this->client->shouldReceive( 'get' )
            ->once()
            ->with( 'bookings' )
            ->andReturn( array( 'bookings' => array() ) );

        $result = $this->bookings->list_bookings();

        $this->assertIsArray( $result );
    }

    /**
     * Test create_booking calls correct endpoint.
     */
    public function test_create_booking() {
        $data = array( 'customer_id' => 'cust_123' );
        
        $this->client->shouldReceive( 'post' )
            ->once()
            ->with( 'bookings', Mockery::on( function( $body ) use ( $data ) {
                return isset( $body['idempotency_key'] ) && $body['booking'] === $data;
            }))
            ->andReturn( array( 'booking' => $data ) );

        $result = $this->bookings->create_booking( $data );

        $this->assertEquals( $data, $result );
    }

    /**
     * Test cancel_booking calls correct endpoint.
     */
    public function test_cancel_booking() {
        $id = 'book_123';
        $version = 5;
        
        $this->client->shouldReceive( 'post' )
            ->once()
            ->with( 'bookings/' . $id . '/cancel', Mockery::on( function( $body ) use ( $version ) {
                return isset( $body['idempotency_key'] ) && $body['booking_version'] === $version;
            }))
            ->andReturn( array( 'booking' => array( 'id' => $id, 'status' => 'CANCELLED_BY_SELLER' ) ) );

        $result = $this->bookings->cancel_booking( $id, $version );

        $this->assertEquals( 'CANCELLED_BY_SELLER', $result['status'] );
    }
}
