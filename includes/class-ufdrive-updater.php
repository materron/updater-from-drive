<?php
/**
 * Compares installed plugins against the Drive folder and applies updates.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The comparison and update engine.
 *
 * Comparison is done from package file names alone, so a check costs nothing
 * but one listing request. The contents of a package are only ever examined
 * once it has been downloaded to be installed, at which point verifying it is
 * free.
 */
class UFDRIVE_Updater {

	/**
	 * Drive client.
	 *
	 * @var UFDRIVE_Drive_Client
	 */
	protected $drive;

	/**
	 * Constructor.
	 *
	 * @param UFDRIVE_Drive_Client $drive Drive client.
	 */
	public function __construct( UFDRIVE_Drive_Client $drive ) {
		$this->drive = $drive;
	}

	/**
	 * Build a map of claimed slug => package details from the Drive folder.
	 *
	 * @return array<string,array<string,string>>|WP_Error
	 */
	public function build_index() {
		$files = $this->drive->list_packages();

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$index   = array();
		$skipped = array();

		foreach ( $files as $file ) {
			if ( empty( $file['id'] ) || empty( $file['name'] ) ) {
				continue;
			}

			$claim = UFDRIVE_Package::parse_filename( $file['name'] );

			if ( null === $claim ) {
				$skipped[] = $file['name'];
				continue;
			}

			$key = strtolower( $claim['slug'] );

			// Keep the highest version when a folder holds several builds of
			// the same plugin.
			if ( isset( $index[ $key ] )
				&& ! version_compare( $claim['version'], $index[ $key ]['version'], '>' ) ) {
				continue;
			}

			$index[ $key ] = array(
				'file_id'  => $file['id'],
				'filename' => $file['name'],
				'slug'     => $claim['slug'],
				'version'  => $claim['version'],
				'modified' => isset( $file['modifiedTime'] ) ? $file['modifiedTime'] : '',
			);
		}

		// Folders hold the same unrecognised files run after run, so logging
		// the list every time would bury everything that actually happened.
		// It is only worth repeating when the set changes.
		$fingerprint = empty( $skipped ) ? '' : md5( implode( '|', $skipped ) );

		if ( $fingerprint !== get_option( 'ufdrive_skipped_fingerprint', '' ) ) {
			update_option( 'ufdrive_skipped_fingerprint', $fingerprint, false );

			if ( ! empty( $skipped ) ) {
				UFDRIVE_Logger::log(
					sprintf(
						/* translators: 1: number of files ignored, 2: comma separated list of up to ten file names. */
						_n(
							'Ignored %1$d file that is not named "plugin-folder-version.zip": %2$s',
							'Ignored %1$d files that are not named "plugin-folder-version.zip": %2$s',
							count( $skipped ),
							'updater-from-drive'
						),
						count( $skipped ),
						implode( ', ', array_slice( $skipped, 0, 10 ) )
					),
					'warning'
				);
			}
		}

		return $index;
	}

	/**
	 * Work out which installed plugins have a newer package in Drive.
	 *
	 * A package is never applied unless it is strictly newer than what is
	 * installed, so the plugin can never downgrade anything.
	 *
	 * @param array<string,array<string,string>> $index Package index.
	 * @return array<int,array<string,string>>
	 */
	public function find_updates( array $index ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$allowed    = UFDRIVE_Settings::allowed_slugs();
		$lookup     = UFDRIVE_Matcher::build_lookup( $index );
		$pending    = array();
		$inspections = 0;

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );

			// Single file plugins live at the plugins root and have no folder.
			if ( '.' === $slug ) {
				continue;
			}

			// An empty list means every installed plugin is fair game; a
			// non-empty one narrows it down to just those.
			if ( ! empty( $allowed ) && ! in_array( strtolower( $slug ), array_map( 'strtolower', $allowed ), true ) ) {
				continue;
			}

			$package = $this->resolve( $slug, $index, $lookup, $inspections );

			if ( null === $package ) {
				continue;
			}

			$current = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';

			if ( '' === $current || ! version_compare( $package['version'], $current, '>' ) ) {
				continue;
			}

			$pending[] = array(
				'plugin_file' => $plugin_file,
				'slug'        => $slug,
				'name'        => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $slug,
				'from'        => $current,
				'to'          => $package['version'],
				'file_id'     => $package['file_id'],
				'filename'    => $package['filename'],
			);
		}

		return $pending;
	}

	/**
	 * Find the package belonging to an installed plugin.
	 *
	 * Cheapest sources first, and every answer that cost a download is kept so
	 * it is never paid for twice.
	 *
	 * @param string                             $slug        Installed plugin folder.
	 * @param array<string,array<string,string>> $index       Package index.
	 * @param array<string,string>               $lookup      Loose lookup table.
	 * @param int                                $inspections Running count of archives opened this run.
	 * @return array<string,string>|null
	 */
	protected function resolve( $slug, array $index, array $lookup, &$inspections ) {
		// What the site owner said, above anything the plugin works out.
		$aliases = UFDRIVE_Settings::slug_aliases();

		if ( isset( $aliases[ $slug ] ) ) {
			$key = strtolower( $aliases[ $slug ] );
			return isset( $index[ $key ] ) ? $index[ $key ] : null;
		}

		// What the plugin worked out on an earlier run.
		$discovered = UFDRIVE_Matcher::discovered();

		if ( isset( $discovered[ $slug ] ) ) {
			$key = strtolower( $discovered[ $slug ] );

			if ( isset( $index[ $key ] ) ) {
				return $index[ $key ];
			}
		}

		$by_name = UFDRIVE_Matcher::match_by_name( $slug, $index, $lookup );

		if ( null !== $by_name ) {
			return $by_name;
		}

		return $this->resolve_by_contents( $slug, $index, $inspections );
	}

	/**
	 * Open strong candidates and see which one really is this plugin.
	 *
	 * @param string                             $slug        Installed plugin folder.
	 * @param array<string,array<string,string>> $index       Package index.
	 * @param int                                $inspections Running count of archives opened this run.
	 * @return array<string,string>|null
	 */
	protected function resolve_by_contents( $slug, array $index, &$inspections ) {
		foreach ( UFDRIVE_Matcher::candidates( $slug, $index ) as $package ) {
			$inside = UFDRIVE_Matcher::inspected( $package['file_id'], $package['modified'] );

			if ( null === $inside ) {
				if ( $inspections >= UFDRIVE_Matcher::INSPECT_LIMIT ) {
					break;
				}

				++$inspections;
				$inside = $this->read_package_slug( $package );

				UFDRIVE_Matcher::remember_inspection( $package['file_id'], $package['modified'], $inside );
			}

			if ( '' === $inside || ! UFDRIVE_Package::slugs_match( $inside, $slug ) ) {
				continue;
			}

			UFDRIVE_Matcher::remember_alias( $slug, $package['slug'] );

			UFDRIVE_Logger::log(
				sprintf(
					/* translators: 1: installed plugin folder, 2: package file name. */
					__( 'Recognised %1$s inside the package %2$s. That pairing will be reused from now on.', 'updater-from-drive' ),
					$slug,
					$package['filename']
				)
			);

			return $package;
		}

		return null;
	}

	/**
	 * Download a package just far enough to learn which plugin it holds.
	 *
	 * @param array<string,string> $package Package descriptor.
	 * @return string The plugin folder inside, or an empty string.
	 */
	protected function read_package_slug( array $package ) {
		$temp = wp_tempnam( $package['filename'] );

		if ( ! $temp ) {
			return '';
		}

		$downloaded = $this->drive->download( $package['file_id'], $temp );

		if ( is_wp_error( $downloaded ) ) {
			wp_delete_file( $temp );
			return '';
		}

		$inside = UFDRIVE_Package::inspect( $temp );
		wp_delete_file( $temp );

		return is_wp_error( $inside ) ? '' : $inside['slug'];
	}

	/**
	 * Installed plugins and packages that could not be paired up.
	 *
	 * No amount of guessing turns woothemes-sensei into
	 * woocommerce-paid-courses, so the site owner is shown what is left over
	 * rather than being told everything is up to date.
	 *
	 * @param array<string,array<string,string>> $index Package index.
	 * @return array{plugins:array<int,array<string,string>>,packages:array<int,array<string,string>>}
	 */
	public function unmatched( array $index ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$lookup      = UFDRIVE_Matcher::build_lookup( $index );
		$inspections = UFDRIVE_Matcher::INSPECT_LIMIT; // Reporting never downloads.
		$plugins     = array();
		$used        = array();
		$elsewhere   = $this->plugins_updated_elsewhere();

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );

			if ( '.' === $slug ) {
				continue;
			}

			$package = $this->resolve( $slug, $index, $lookup, $inspections );

			if ( null === $package ) {
				// Plugins the WordPress.org directory already looks after are
				// not missing from the folder, they simply do not belong in
				// it. Listing them would bury the ones that matter.
				if ( isset( $elsewhere[ $plugin_file ] ) ) {
					continue;
				}

				$plugins[] = array(
					'slug'    => $slug,
					'name'    => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $slug,
					'version' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
				);
				continue;
			}

			$used[ strtolower( $package['slug'] ) ] = true;
		}

		$packages = array();

		foreach ( $index as $key => $package ) {
			if ( isset( $used[ $key ] ) ) {
				continue;
			}

			$packages[] = array(
				'slug'     => $package['slug'],
				'version'  => $package['version'],
				'filename' => $package['filename'],
			);
		}

		return array(
			'plugins'  => $plugins,
			'packages' => $packages,
		);
	}

	/**
	 * Plugins that already have somewhere else to get updates from.
	 *
	 * WordPress records every plugin the directory recognises, whether or not
	 * an update is pending. Anything it does not recognise is a plugin that
	 * has to be updated from somewhere else, which is exactly what this plugin
	 * is for.
	 *
	 * @return array<string,bool> Keyed by plugin file.
	 */
	protected function plugins_updated_elsewhere() {
		$state = get_site_transient( 'update_plugins' );
		$known = array();

		if ( ! is_object( $state ) ) {
			return $known;
		}

		foreach ( array( 'response', 'no_update' ) as $group ) {
			if ( empty( $state->$group ) || ! is_array( $state->$group ) ) {
				continue;
			}

			foreach ( array_keys( $state->$group ) as $plugin_file ) {
				$known[ $plugin_file ] = true;
			}
		}

		// This plugin looks after itself, so it is never "missing" either.
		$known[ UFDRIVE_PLUGIN_BASENAME ] = true;

		return $known;
	}

	/**
	 * Run a full check and apply every available update.
	 *
	 * @return array{checked:int,updated:array<int,array<string,string>>,errors:string[]}
	 */
	public function run() {
		$result = array(
			'checked' => 0,
			'updated' => array(),
			'errors'  => array(),
		);

		$index = $this->build_index();

		if ( is_wp_error( $index ) ) {
			$result['errors'][] = $index->get_error_message();
			UFDRIVE_Logger::log( $index->get_error_message(), 'error' );
			return $result;
		}

		$result['checked'] = count( $index );
		$pending           = $this->find_updates( $index );

		// Recorded so the settings screen can show what could not be paired
		// up, instead of quietly reporting that everything is up to date.
		update_option( 'ufdrive_unmatched', $this->unmatched( $index ), false );

		foreach ( $pending as $update ) {
			$applied = $this->apply( $update );

			if ( is_wp_error( $applied ) ) {
				$result['errors'][] = $applied->get_error_message();
				UFDRIVE_Logger::log( $applied->get_error_message(), 'error' );
				continue;
			}

			// The version inside the archive is the one that actually landed.
			$update['to']        = $applied;
			$result['updated'][] = $update;

			UFDRIVE_Logger::log(
				sprintf(
					/* translators: 1: plugin name, 2: old version, 3: new version. */
					__( 'Updated %1$s from %2$s to %3$s.', 'updater-from-drive' ),
					$update['name'],
					$update['from'],
					$update['to']
				)
			);
		}

		if ( empty( $pending ) && empty( $result['errors'] ) ) {
			UFDRIVE_Logger::log(
				sprintf(
					/* translators: %d: number of packages found in the Drive folder. */
					__( 'Checked %d packages. Everything is up to date.', 'updater-from-drive' ),
					$result['checked']
				)
			);
		}

		update_option( 'ufdrive_last_run', time(), false );

		return $result;
	}

	/**
	 * Download, verify and install a single package.
	 *
	 * @param array<string,string> $update Update descriptor from find_updates().
	 * @return string|WP_Error The version actually installed.
	 */
	protected function apply( array $update ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Loaded here rather than at bootstrap: it extends a core class that
		// only exists once the upgrader has been included.
		require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-upgrader-skin.php';

		$temp = wp_tempnam( $update['filename'] );

		if ( ! $temp ) {
			return new WP_Error(
				'ufdrive_no_temp_file',
				__( 'Could not create a temporary file for the package.', 'updater-from-drive' )
			);
		}

		$downloaded = $this->drive->download( $update['file_id'], $temp );

		if ( is_wp_error( $downloaded ) ) {
			wp_delete_file( $temp );
			return $downloaded;
		}

		$verified = $this->verify( $temp, $update );

		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $temp );
			return $verified;
		}

		$skin      = new UFDRIVE_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$installed = $upgrader->run(
			array(
				'package'                     => $temp,
				'destination'                 => WP_PLUGIN_DIR,
				'clear_destination'           => true,
				'clear_working'               => true,
				'abort_if_destination_exists' => false,
				'hook_extra'                  => array(
					'plugin' => $update['plugin_file'],
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		wp_delete_file( $temp );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		if ( false === $installed || null === $installed ) {
			$detail = ! empty( $skin->messages )
				? implode( ' ', $skin->messages )
				: __( 'The upgrader reported no result.', 'updater-from-drive' );

			return new WP_Error( 'ufdrive_install_failed', $detail );
		}

		wp_clean_plugins_cache();

		return $verified;
	}

	/**
	 * Check that a downloaded package really is what its name promised.
	 *
	 * This is the whole safety net for trusting file names: the archive is
	 * already on disk, so looking inside costs nothing, and it stops a
	 * mislabelled package from being written over an unrelated plugin.
	 *
	 * @param string               $zip_path Path to the downloaded package.
	 * @param array<string,string> $update   Update descriptor.
	 * @return string|WP_Error The version found inside the package.
	 */
	protected function verify( $zip_path, array $update ) {
		$actual = UFDRIVE_Package::inspect( $zip_path );

		if ( is_wp_error( $actual ) ) {
			return new WP_Error(
				'ufdrive_unreadable_package',
				sprintf(
					/* translators: 1: package file name, 2: reason. */
					__( 'Nothing was updated. The package "%1$s" could not be read: %2$s', 'updater-from-drive' ),
					$update['filename'],
					$actual->get_error_message()
				)
			);
		}

		// Whatever folder is inside the archive is the folder WordPress will
		// write to, so it has to be the plugin we set out to update.
		if ( ! UFDRIVE_Package::slugs_match( $actual['slug'], $update['slug'] ) ) {
			return new WP_Error(
				'ufdrive_wrong_plugin',
				sprintf(
					/* translators: 1: package file name, 2: plugin name it should update, 3: plugin name actually inside the package. */
					__( 'Nothing was updated, and it is worth checking your Drive folder. The file "%1$s" is named as if it were an update for %2$s, but it actually contains a different plugin (%3$s). Rename the file to match the plugin folder it belongs to.', 'updater-from-drive' ),
					$update['filename'],
					$update['name'],
					$actual['name']
				)
			);
		}

		// The real version has to be an improvement on what is installed.
		// Testing against the installed version rather than against the file
		// name is what makes an endless update loop impossible: if installing
		// this package cannot move the version forward, it is not installed.
		if ( empty( $actual['version'] ) || ! version_compare( $actual['version'], $update['from'], '>' ) ) {
			return new WP_Error(
				'ufdrive_not_newer',
				sprintf(
					/* translators: 1: package file name, 2: version in the file name, 3: version inside the package, 4: installed version. */
					__( 'Nothing was updated. The file "%1$s" says it is version %2$s, but the plugin inside it is version %3$s, which is no newer than the installed %4$s. Rename the file so the version matches its contents.', 'updater-from-drive' ),
					$update['filename'],
					$update['to'],
					empty( $actual['version'] ) ? '?' : $actual['version'],
					$update['from']
				)
			);
		}

		// A mismatch that still moves forward is worth saying out loud, but
		// not worth refusing: the contents are what actually gets installed.
		if ( 0 !== version_compare( $actual['version'], $update['to'] ) ) {
			UFDRIVE_Logger::log(
				sprintf(
					/* translators: 1: package file name, 2: version in the file name, 3: version inside the package. */
					__( 'The file "%1$s" is named as version %2$s but contains version %3$s. The contents were used. Renaming the file will keep the two in step.', 'updater-from-drive' ),
					$update['filename'],
					$update['to'],
					$actual['version']
				),
				'warning'
			);
		}

		return $actual['version'];
	}
}
