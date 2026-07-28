<?php
/**
 * Keeps this plugin itself up to date from a plain directory of ZIPs.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teaches WordPress where to find new versions of this plugin.
 *
 * The source is an ordinary web directory with listing enabled. The version
 * lives only in the package file name, so there is no manifest to keep in
 * step with the packages, and therefore nothing that can fall out of step.
 */
class UFDRIVE_Self_Updater {

	const CACHE_KEY   = 'ufdrive_self_update';
	const CACHE_TTL   = 12 * HOUR_IN_SECONDS;
	const DEFAULT_URL = 'https://potencia.pro/own-plugins/';

	/**
	 * Register the hooks WordPress uses to offer and apply updates.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'verify_source' ), 10, 4 );
	}

	/**
	 * Where to look for packages.
	 *
	 * Overridable so a site can point at its own mirror rather than depending
	 * on someone else's server.
	 *
	 * @return string
	 */
	public function source_url() {
		$url = defined( 'UFDRIVE_UPDATE_URL' ) ? (string) UFDRIVE_UPDATE_URL : self::DEFAULT_URL;

		/**
		 * Filters the directory this plugin looks in for its own updates.
		 *
		 * @param string $url Directory URL, with a trailing slash.
		 */
		$url = (string) apply_filters( 'ufdrive_update_source_url', $url );

		return trailingslashit( $url );
	}

	/**
	 * The newest package available, or null when there is nothing to report.
	 *
	 * @param bool $force Skip the cache.
	 * @return array{version:string,package:string}|null
	 */
	public function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}

		$found = $this->fetch_latest();

		// Cache misses too, so a slow or unreachable server is not contacted
		// on every single admin page load.
		set_transient( self::CACHE_KEY, null === $found ? array() : $found, self::CACHE_TTL );

		return $found;
	}

	/**
	 * Read the directory listing and pick the highest version in it.
	 *
	 * @return array{version:string,package:string}|null
	 */
	protected function fetch_latest() {
		$base = $this->source_url();

		$response = wp_remote_get(
			$base,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// Match the links a directory listing puts around each file. Anchored
		// on the file name rather than on any particular listing layout, so a
		// change of server template does not break it.
		$pattern = '#href="([^"]*' . preg_quote( 'updater-from-drive-', '#' ) . '(\d+(?:\.\d+)*(?:[.-][0-9A-Za-z]+)*)\.zip)"#i';

		if ( ! preg_match_all( $pattern, $body, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		$best = null;

		foreach ( $matches as $match ) {
			$version = $match[2];

			if ( null !== $best && ! version_compare( $version, $best['version'], '>' ) ) {
				continue;
			}

			$best = array(
				'version' => $version,
				'package' => $this->absolute_url( $match[1], $base ),
			);
		}

		return $best;
	}

	/**
	 * Turn a possibly relative href from the listing into a full URL.
	 *
	 * @param string $href Raw href attribute.
	 * @param string $base Directory URL the listing came from.
	 * @return string
	 */
	protected function absolute_url( $href, $base ) {
		$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );

		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		$parts = wp_parse_url( $base );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $href;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		// Server-relative: "/own-plugins/foo.zip".
		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}

		return $base . ltrim( $href, './' );
	}

	/**
	 * Add this plugin to the set of available updates when one exists.
	 *
	 * @param object $transient The update_plugins site transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$latest = $this->latest();

		if ( null === $latest || ! version_compare( $latest['version'], UFDRIVE_VERSION, '>' ) ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'updater-from-drive',
			'slug'         => 'updater-from-drive',
			'plugin'       => UFDRIVE_PLUGIN_BASENAME,
			'new_version'  => $latest['version'],
			'url'          => 'https://github.com/materron/updater-from-drive',
			'package'      => $latest['package'],
			'tested'       => '',
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ UFDRIVE_PLUGIN_BASENAME ] = $item;

		return $transient;
	}

	/**
	 * Supply the details WordPress shows in the plugin information dialog.
	 *
	 * Without this the "View details" link produces an error, because there is
	 * no wordpress.org entry to look up.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Arguments for the request.
	 * @return false|object|array
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || 'updater-from-drive' !== $args->slug ) {
			return $result;
		}

		$latest  = $this->latest();
		$version = null === $latest ? UFDRIVE_VERSION : $latest['version'];

		return (object) array(
			'name'          => 'Updater from Drive',
			'slug'          => 'updater-from-drive',
			'version'       => $version,
			'requires'      => '6.3',
			'requires_php'  => '7.4',
			'homepage'      => 'https://github.com/materron/updater-from-drive',
			'download_link' => null === $latest ? '' : $latest['package'],
			'sections'      => array(
				'description' => __( 'Updates your plugins from ZIP packages kept in a Google Drive folder that you own and control.', 'updater-from-drive' ),
			),
		);
	}

	/**
	 * Refuse a downloaded package that is not this plugin.
	 *
	 * Same rule the plugin applies to the packages it installs from Drive: the
	 * archive has been extracted by now, so looking inside costs nothing, and
	 * a wrong package must never be written over a working install.
	 *
	 * @param string      $source        Path to the extracted package.
	 * @param string      $remote_source Path to the downloaded archive.
	 * @param WP_Upgrader $upgrader      The upgrader running the install.
	 * @param array       $hook_extra    Extra arguments describing the update.
	 * @return string|WP_Error
	 */
	public function verify_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || UFDRIVE_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$main_file = trailingslashit( $source ) . 'updater-from-drive.php';

		if ( ! file_exists( $main_file ) ) {
			return new WP_Error(
				'ufdrive_bad_self_package',
				__( 'The downloaded package does not contain Updater from Drive, so nothing was installed.', 'updater-from-drive' )
			);
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $main_file, false, false );

		if ( empty( $data['Version'] ) || ! version_compare( $data['Version'], UFDRIVE_VERSION, '>' ) ) {
			return new WP_Error(
				'ufdrive_self_package_not_newer',
				sprintf(
					/* translators: 1: version inside the package, 2: version currently installed. */
					__( 'The downloaded package contains version %1$s, which is not newer than the installed version %2$s. Nothing was installed.', 'updater-from-drive' ),
					empty( $data['Version'] ) ? '?' : $data['Version'],
					UFDRIVE_VERSION
				)
			);
		}

		return $source;
	}

	/**
	 * Forget the cached lookup.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
