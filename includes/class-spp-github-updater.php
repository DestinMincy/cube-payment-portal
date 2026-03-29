<?php
/**
 * GitHub-based plugin updater with WordPress.org fallback priority.
 *
 * Checks WordPress.org first; if the plugin is not listed there,
 * falls back to GitHub Releases for update information.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SPP_GitHub_Updater
 */
class SPP_GitHub_Updater {

	/**
	 * GitHub repository owner/name.
	 *
	 * @var string
	 */
	private $repo = 'DestinMincy/cube-payment-portal';

	/**
	 * Plugin basename (e.g. cube-payment-portal/square-payment-portal.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Cached GitHub release data.
	 *
	 * @var object|null|false  null = not fetched yet, false = fetch failed.
	 */
	private $github_release = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_basename = SPP_PLUGIN_BASENAME;
		$this->plugin_slug     = dirname( SPP_PLUGIN_BASENAME );
		$this->current_version = SPP_VERSION;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
	}

	/**
	 * Inject GitHub release into the update transient when WordPress.org
	 * does not already provide an update for this plugin.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// WordPress.org already found an update — respect it and skip GitHub.
		if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release->tag_name, 'vV' );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$download_url = $this->get_download_url( $release );

			if ( ! $download_url ) {
				return $transient;
			}

			$plugin_data = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote_version,
				'url'         => 'https://github.com/' . $this->repo,
				'package'     => $download_url,
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires'    => SPP_MINIMUM_WP_VERSION,
				'requires_php'=> SPP_MINIMUM_PHP_VERSION,
			);

			$transient->response[ $this->plugin_basename ] = $plugin_data;
		}

		return $transient;
	}

	/**
	 * Supply plugin information for the WordPress "View Details" popup
	 * when the update originates from GitHub.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object Plugin info or false to let WordPress handle it.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		// If WordPress.org already returned info, use that.
		if ( false !== $result ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release->tag_name, 'vV' );
		$download_url   = $this->get_download_url( $release );

		$info = (object) array(
			'name'            => 'Cube Payment Portal',
			'slug'            => $this->plugin_slug,
			'version'         => $remote_version,
			'author'          => '<a href="https://destinlmincy.com">Destin L. Mincy</a>',
			'homepage'        => 'https://github.com/' . $this->repo,
			'requires'        => SPP_MINIMUM_WP_VERSION,
			'requires_php'    => SPP_MINIMUM_PHP_VERSION,
			'downloaded'      => 0,
			'last_updated'    => $release->published_at,
			'sections'        => array(
				'description'  => 'A comprehensive payment portal integrating Square for client payments, subscriptions, and invoicing.',
				'changelog'    => nl2br( esc_html( $release->body ) ),
			),
			'download_link'   => $download_url,
		);

		return $info;
	}

	/**
	 * Fix the extracted directory name so WordPress can match it to the
	 * installed plugin directory.
	 *
	 * GitHub zip archives are named "repo-tag" by default, which won't
	 * match the expected plugin directory name.
	 *
	 * @param string      $source        Extracted source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra hook data.
	 * @return string|WP_Error Corrected source path.
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		$expected_dir = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

		if ( $source === $expected_dir ) {
			return $source;
		}

		// Rename the extracted directory.
		global $wp_filesystem;
		if ( $wp_filesystem->move( $source, $expected_dir ) ) {
			return $expected_dir;
		}

		return $source;
	}

	/**
	 * Fetch the latest GitHub release (cached for 6 hours via transient).
	 *
	 * @return object|false Release object or false on failure.
	 */
	private function get_latest_release() {
		if ( null !== $this->github_release ) {
			return $this->github_release;
		}

		$transient_key = 'spp_github_release';
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			$this->github_release = $cached;
			return $this->github_release;
		}

		$url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'CubePaymentPortal/' . $this->current_version,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->github_release = false;
			// Cache failures for 1 hour to avoid repeated API calls.
			set_transient( $transient_key, false, HOUR_IN_SECONDS );
			return false;
		}

		$body    = wp_remote_retrieve_body( $response );
		$release = json_decode( $body );

		if ( empty( $release ) || ! isset( $release->tag_name ) ) {
			$this->github_release = false;
			set_transient( $transient_key, false, HOUR_IN_SECONDS );
			return false;
		}

		$this->github_release = $release;
		set_transient( $transient_key, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Determine the best download URL from a GitHub release.
	 *
	 * Prefers an uploaded .zip asset; falls back to the source zipball.
	 *
	 * @param object $release GitHub release object.
	 * @return string|false Download URL or false.
	 */
	private function get_download_url( $release ) {
		// Prefer an uploaded .zip asset (e.g. cube-payment-portal.zip).
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->browser_download_url ) && '.zip' === substr( $asset->name, -4 ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback to the source code zipball.
		if ( ! empty( $release->zipball_url ) ) {
			return $release->zipball_url;
		}

		return false;
	}
}