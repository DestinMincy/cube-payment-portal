<?php
/**
 * Test case for SPP_Team class.
 *
 * @package CubePaymentPortal
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class SPP_Team_Test
 */
class SPP_Team_Test extends TestCase {

    /**
     * @var SPP_Square_Client|Mockery\MockInterface
     */
    protected $client;

    /**
     * @var SPP_Team
     */
    protected $team;

    /**
     * Set up test environment.
     */
    protected function set_up() {
        parent::set_up();
        $this->client = Mockery::mock( 'SPP_Square_Client' );
        $this->team = new SPP_Team( $this->client );
    }

    /**
     * Tear down test environment.
     */
    protected function tear_down() {
        Mockery::close();
        parent::tear_down();
    }

    /**
     * Test create_team_member calls correct endpoint.
     */
    public function test_create_team_member() {
        $data = array( 'given_name' => 'John', 'family_name' => 'Doe' );
        
        $this->client->shouldReceive( 'post' )
            ->once()
            ->with( 'team-members', Mockery::on( function( $body ) use ( $data ) {
                return isset( $body['idempotency_key'] ) && $body['team_member'] === $data;
            }))
            ->andReturn( array( 'team_member' => $data ) );

        $result = $this->team->create_team_member( $data );

        $this->assertEquals( $data, $result );
    }

    /**
     * Test search_team_members calls correct endpoint.
     */
    public function test_search_team_members() {
        $query = array( 'filter' => array( 'status' => 'ACTIVE' ) );
        
        $this->client->shouldReceive( 'post' )
            ->once()
            ->with( 'team-members/search', Mockery::on( function( $body ) use ( $query ) {
                return $body['query'] === $query;
            }))
            ->andReturn( array( 'team_members' => array() ) );

        $result = $this->team->search_team_members( $query );

        $this->assertIsArray( $result );
    }

    /**
     * Test get_team_member calls correct endpoint.
     */
    public function test_get_team_member() {
        $id = 'tm_123';
        
        $this->client->shouldReceive( 'get' )
            ->once()
            ->with( 'team-members/' . $id )
            ->andReturn( array( 'team_member' => array( 'id' => $id ) ) );

        $result = $this->team->get_team_member( $id );

        $this->assertEquals( $id, $result['id'] );
    }
}
