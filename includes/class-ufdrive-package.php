<?php
/**
 * Package naming and contents.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out what a package claims to be, and what it actually is.
 *
 * The file name is what a package *claims*: it is free to read, so it drives
 * the comparison against installed plugins. The plugin header inside the
 * archive is what it *is*: reading it costs a download, so it is only checked
 * at the point of installing, when the file has been fetched anyway.
 */
class UFDRIVE_Package {

	/**
	 * Read the claimed slug and version out of a package file name.
	 *
	 * Deliberately permissive about version formats: 1.2, 1.2.3, 1.2.3.4,
	 * 2.0-beta and a leading "v" are all accepted, because package names in
	 * the wild use all of them.
	 *
	 * @param string $filename Drive file name, with or without the extension.
	 * @return array{slug:string,version:string}|null Null when unrecognised.
	 */
	public static function parse_filename( $filename ) {
		$name = preg_replace( '/\.zip$/i', '', trim( (string) $filename ) );

		if ( '' === $name ) {
			return null;
		}

		if ( ! preg_match( '#^(.+)-v?(\d+(?:\.\d+)*(?:[.-][0-9A-Za-z]+)*)$#', $name, $match ) ) {
			return null;
		}

		$slug = self::clean_slug( $match[1] );

		if ( '' === $slug ) {
			return null;
		}

		return array(
			'slug'    => $slug,
			'version' => $match[2],
		);
	}

	/**
	 * Reduce a string to the characters a plugin folder name can contain.
	 *
	 * @param string $raw Raw slug candidate.
	 * @return string
	 */
	public static function clean_slug( $raw ) {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $raw );
	}

	/**
	 * Compare two slugs the way a file system would: case-insensitively.
	 *
	 * @param string $a First slug.
	 * @param string $b Second slug.
	 * @return bool
	 */
	public static function slugs_match( $a, $b ) {
		return strtolower( (string) $a ) === strtolower( (string) $b );
	}

	/**
	 * Read the real plugin identity out of a downloaded ZIP.
	 *
	 * @param string $zip_path Absolute path to a local ZIP file.
	 * @return array{slug:string,version:string,name:string}|WP_Error
	 */
	public static function inspect( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'ufdrive_no_zip_ext',
				__( 'The PHP ZipArchive extension is required to check packages.', 'updater-from-drive' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error(
				'ufdrive_zip_open_failed',
				__( 'The downloaded package could not be opened as a ZIP archive.', 'updater-from-drive' )
			);
		}

		$result = null;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );

			if ( ! is_string( $entry ) || '.php' !== strtolower( substr( $entry, -4 ) ) ) {
				continue;
			}

			// A plugin header can only live at the archive root or one level
			// down, inside the plugin's own folder.
			if ( substr_count( trim( $entry, '/' ), '/' ) > 1 ) {
				continue;
			}

			// The header always sits in the first 8 KB of the file.
			$contents = $zip->getFromName( $entry, 8192 );

			if ( false === $contents ) {
				continue;
			}

			$headers = self::parse_headers( $contents );

			if ( '' === $headers['name'] || '' === $headers['version'] ) {
				continue;
			}

			$parts = explode( '/', trim( $entry, '/' ) );
			$slug  = count( $parts ) > 1
				? self::clean_slug( $parts[0] )
				: self::clean_slug( basename( $entry, '.php' ) );

			$result = array(
				'slug'    => $slug,
				'version' => $headers['version'],
				'name'    => $headers['name'],
			);
			break;
		}

		$zip->close();

		if ( null === $result ) {
			return new WP_Error(
				'ufdrive_no_plugin_header',
				__( 'This package does not contain a WordPress plugin: no plugin header was found inside it.', 'updater-from-drive' )
			);
		}

		return $result;
	}

	/**
	 * Pull Plugin Name and Version out of a PHP file's opening bytes.
	 *
	 * @param string $contents First bytes of a PHP file.
	 * @return array{name:string,version:string}
	 */
	protected static function parse_headers( $contents ) {
		// Normalise line endings the way get_file_data() does.
		$contents = str_replace( "\r", "\n", $contents );

		$labels = array(
			'name'    => 'Plugin Name',
			'version' => 'Version',
		);

		$found = array(
			'name'    => '',
			'version' => '',
		);

		foreach ( $labels as $key => $label ) {
			$pattern = '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi';

			if ( preg_match( $pattern, $contents, $match ) ) {
				$found[ $key ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		return $found;
	}
}
