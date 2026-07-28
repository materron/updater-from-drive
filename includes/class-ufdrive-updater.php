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

		$allowed = UFDRIVE_Settings::allowed_slugs();
		$aliases = UFDRIVE_Settings::slug_aliases();
		$pending = array();

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

			// The package may be published under a different name than the
			// folder the plugin installs into.
			$package_slug = isset( $aliases[ $slug ] ) ? $aliases[ $slug ] : $slug;
			$key          = strtolower( $package_slug );

			if ( empty( $index[ $key ] ) ) {
				continue;
			}

			$package = $index[ $key ];
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

		foreach ( $pending as $update ) {
			$applied = $this->apply( $update );

			if ( is_wp_error( $applied ) ) {
				$result['errors'][] = $applied->get_error_message();
				UFDRIVE_Logger::log( $applied->get_error_message(), 'error' );
				continue;
			}

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
	 * @return true|WP_Error
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

		return true;
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
	 * @return true|WP_Error
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

		$aliases       = UFDRIVE_Settings::slug_aliases();
		$expected_slug = isset( $aliases[ $update['slug'] ] ) ? $aliases[ $update['slug'] ] : $update['slug'];

		if ( ! UFDRIVE_Package::slugs_match( $actual['slug'], $expected_slug ) ) {
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

		if ( ! UFDRIVE_Package::slugs_match( $actual['version'], $update['to'] ) ) {
			return new WP_Error(
				'ufdrive_wrong_version',
				sprintf(
					/* translators: 1: package file name, 2: version in the file name, 3: version inside the package. */
					__( 'Nothing was updated. The file "%1$s" says it is version %2$s, but the plugin inside it is version %3$s. Rename the file so the version matches its contents.', 'updater-from-drive' ),
					$update['filename'],
					$update['to'],
					$actual['version']
				)
			);
		}

		return true;
	}
}
