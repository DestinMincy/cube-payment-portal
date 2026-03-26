<?php
/**
 * Square Team API integration.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_Team
 *
 * Handles team member management via Square Team API.
 */
class SPP_Team {

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
	 * Create a team member.
	 *
	 * @param array $member_data Team member data.
	 * @return array|WP_Error Created team member or error.
	 */
	public function create_team_member( array $member_data ) {
		$body = array(
			'idempotency_key' => wp_generate_uuid4(),
			'team_member'     => $member_data,
		);

		$response = $this->client->post( 'team-members', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['team_member'] ?? $response;
	}

	/**
	 * Bulk create team members.
	 *
	 * @param array $team_members Map of idempotent key to member data.
	 * @return array|WP_Error Created team members or error.
	 */
	public function bulk_create_team_members( array $team_members ) {
		$body = array(
			'team_members' => $team_members,
		);

		$response = $this->client->post( 'team-members/bulk-create', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['team_members'] ?? $response;
	}

	/**
	 * Bulk update team members.
	 *
	 * @param array $team_members Map of team member ID to member data.
	 * @return array|WP_Error Updated team members or error.
	 */
	public function bulk_update_team_members( array $team_members ) {
		$body = array(
			'team_members' => $team_members,
		);

		$response = $this->client->post( 'team-members/bulk-update', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['team_members'] ?? $response;
	}

	/**
	 * Search for team members.
	 *
	 * @param array $query Search query.
	 * @param int   $limit Limit results.
	 * @param string $cursor Pagination cursor.
	 * @return array|WP_Error Team members or error.
	 */
	public function search_team_members( array $query = array(), $limit = 100, $cursor = null ) {
		$body = array();

		if ( ! empty( $query ) ) {
			$body['query'] = $query;
		}

		if ( $limit ) {
			$body['limit'] = $limit;
		}

		if ( $cursor ) {
			$body['cursor'] = $cursor;
		}

		$response = $this->client->post( 'team-members/search', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Retrieve a team member.
	 *
	 * @param string $team_member_id Team member ID.
	 * @return array|WP_Error Team member or error.
	 */
	public function get_team_member( $team_member_id ) {
		$response = $this->client->get( 'team-members/' . $team_member_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['team_member'] ?? $response;
	}

	/**
	 * Update a team member.
	 *
	 * @param string $team_member_id Team member ID.
	 * @param array  $member_data    Updated member data.
	 * @return array|WP_Error Updated team member or error.
	 */
	public function update_team_member( $team_member_id, array $member_data ) {
		$body = array(
			'team_member' => $member_data,
		);

		$response = $this->client->put( 'team-members/' . $team_member_id, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['team_member'] ?? $response;
	}

	/**
	 * Retrieve wage setting for a team member.
	 *
	 * @param string $team_member_id Team member ID.
	 * @return array|WP_Error Wage setting or error.
	 */
	public function get_wage_setting( $team_member_id ) {
		$response = $this->client->get( 'team-members/' . $team_member_id . '/wage-setting' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['wage_setting'] ?? $response;
	}

	/**
	 * Update wage setting for a team member.
	 *
	 * @param string $team_member_id Team member ID.
	 * @param array  $wage_setting   Wage setting data.
	 * @return array|WP_Error Updated wage setting or error.
	 */
	public function update_wage_setting( $team_member_id, array $wage_setting ) {
		$body = array(
			'wage_setting' => $wage_setting,
		);

		$response = $this->client->put( 'team-members/' . $team_member_id . '/wage-setting', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['wage_setting'] ?? $response;
	}
}
