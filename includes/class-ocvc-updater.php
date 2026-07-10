<?php
/**
 * Self-hosted auto-updater (GitHub Releases).
 *
 * No external library required. Point OCVC_UPDATE_REPO at a GitHub repo
 * ("owner/name"); when you publish a new release whose tag is a higher version
 * than the installed plugin, every site running this plugin sees the update in
 * Dashboard → Updates and can one-click update, exactly like a wordpress.org plugin.
 *
 * Release flow: bump the Version header, commit, then create a GitHub release
 * with a tag like `v0.2.0` (or `0.2.0`). That's it.
 *
 * For a PRIVATE repo, also define OCVC_UPDATE_TOKEN (a GitHub PAT with repo read).
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Updater {

	/**
	 * "owner/name" of the GitHub repo (override with the OCVC_UPDATE_REPO constant).
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Optional GitHub token for private repos (OCVC_UPDATE_TOKEN constant).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Plugin basename, e.g. "oc-valuecard/oc-valuecard.php".
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin slug, e.g. "oc-valuecard".
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Boot the updater.
	 *
	 * @return void
	 */
	public static function init() {
		$repo = defined( 'OCVC_UPDATE_REPO' ) ? OCVC_UPDATE_REPO : 'OriginalConcepts/oc-valuecard';
		if ( ! $repo ) {
			return;
		}

		$updater           = new self();
		$updater->repo     = $repo;
		$updater->token    = defined( 'OCVC_UPDATE_TOKEN' ) ? OCVC_UPDATE_TOKEN : '';
		$updater->basename = OCVC_PLUGIN_BASENAME;
		$updater->slug     = dirname( OCVC_PLUGIN_BASENAME );
		$updater->version  = OCVC_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', array( $updater, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $updater, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $updater, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * Query GitHub for the latest release (cached to respect API limits).
	 *
	 * @return object|null Release data or null.
	 */
	private function get_latest_release() {
		$cache_key = 'ocvc_update_' . md5( $this->repo );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$headers = array( 'Accept' => 'application/vnd.github+json' );
		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, 0, 3 * HOUR_IN_SECONDS ); // Cache the miss briefly.
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
			set_transient( $cache_key, 0, 3 * HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Inject an available update into WordPress' plugin update transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$new_version = ltrim( (string) $release->tag_name, 'vV' );
		if ( ! version_compare( $new_version, $this->version, '>' ) ) {
			return $transient;
		}

		$package = $this->package_url( $release );

		$item = array(
			'id'          => $this->basename,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $new_version,
			'url'         => 'https://github.com/' . $this->repo,
			'package'     => $package,
			'tested'      => '',
			'icons'       => array(),
			'banners'     => array(),
		);

		$transient->response[ $this->basename ] = (object) $item;
		return $transient;
	}

	/**
	 * Provide the "View details" modal content.
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'OC ValueCard',
			'slug'          => $this->slug,
			'version'       => ltrim( (string) $release->tag_name, 'vV' ),
			'author'        => '<a href="https://originalconcepts.co.il/">Original Concepts</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => $this->package_url( $release ),
			'sections'      => array(
				'changelog' => wp_kses_post( wpautop( (string) ( isset( $release->body ) ? $release->body : '' ) ) ),
			),
		);
	}

	/**
	 * Resolve the download URL for a release (a uploaded .zip asset if present,
	 * otherwise the source zipball).
	 *
	 * @param object $release Release data.
	 * @return string
	 */
	private function package_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->browser_download_url ) && preg_match( '/\.zip$/i', $asset->browser_download_url ) ) {
					return $asset->browser_download_url;
				}
			}
		}
		return isset( $release->zipball_url ) ? $release->zipball_url : '';
	}

	/**
	 * GitHub's source zipball extracts to "owner-name-hash/". Rename it to the
	 * plugin slug so WordPress installs into the correct folder.
	 *
	 * @param string $source        Extracted source dir.
	 * @param string $remote_source Remote source.
	 * @param object $upgrader      Upgrader.
	 * @param array  $hook_extra    Extra.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! is_object( $GLOBALS['wp_filesystem'] ) ) {
			return $source;
		}

		$desired = trailingslashit( dirname( $source ) ) . $this->slug;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $GLOBALS['wp_filesystem']->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
