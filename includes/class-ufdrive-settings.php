<?php
/**
 * Settings storage and access.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin settings.
 *
 * The API key may also be supplied as a constant in wp-config.php, which takes
 * precedence over the database.
 */
class UFDRIVE_Settings {

	const OPTION = 'ufdrive_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'api_key'       => '',
			'folder_id'     => '',
			'auto_update'   => false,
			'allowed_slugs' => array(),
			'slug_aliases'  => array(),
		);
	}

	/**
	 * Return all settings merged with the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Return a single setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Value to use when unset.
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/**
	 * The Google API key, preferring the wp-config.php constant.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'UFDRIVE_API_KEY' ) && '' !== (string) UFDRIVE_API_KEY ) {
			return (string) UFDRIVE_API_KEY;
		}

		return (string) self::get( 'api_key' );
	}

	/**
	 * Whether the API key comes from wp-config.php rather than the database.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant() {
		return defined( 'UFDRIVE_API_KEY' ) && '' !== (string) UFDRIVE_API_KEY;
	}

	/**
	 * The Drive folder the plugin reads packages from.
	 *
	 * @return string
	 */
	public static function folder_id() {
		return (string) self::get( 'folder_id' );
	}

	/**
	 * Whether the plugin has everything it needs to talk to Drive.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key() && '' !== self::folder_id();
	}

	/**
	 * Plugin slugs the updater is restricted to.
	 *
	 * An empty list means "every installed plugin", which is the default.
	 *
	 * @return string[]
	 */
	public static function allowed_slugs() {
		$slugs = self::get( 'allowed_slugs', array() );
		return is_array( $slugs ) ? $slugs : array();
	}

	/**
	 * Map of installed plugin folder => package folder.
	 *
	 * For plugins whose package uses a different name than the folder it
	 * installs into.
	 *
	 * @return array<string,string>
	 */
	public static function slug_aliases() {
		$aliases = self::get( 'slug_aliases', array() );
		return is_array( $aliases ) ? $aliases : array();
	}

	/**
	 * Persist a full settings array.
	 *
	 * @param array<string,mixed> $settings Settings to store.
	 * @return void
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION, wp_parse_args( $settings, self::defaults() ), false );
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$clean   = self::defaults();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		// An empty key field means "keep what is stored", so the saved key
		// never has to be rendered back into the page.
		if ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$clean['api_key'] = sanitize_text_field( $input['api_key'] );
		} else {
			$clean['api_key'] = isset( $current['api_key'] ) ? $current['api_key'] : '';
		}

		$clean['folder_id'] = isset( $input['folder_id'] )
			? self::parse_folder_id( $input['folder_id'] )
			: '';

		$clean['auto_update'] = ! empty( $input['auto_update'] );

		$clean['allowed_slugs'] = isset( $input['allowed_slugs'] )
			? self::parse_slug_list( $input['allowed_slugs'] )
			: array();

		$clean['slug_aliases'] = isset( $input['slug_aliases'] )
			? self::parse_alias_list( $input['slug_aliases'] )
			: array();

		return $clean;
	}

	/**
	 * Accept either a bare folder ID or a pasted Drive URL.
	 *
	 * People copy the address bar far more often than they dig the ID out of
	 * it, so both are treated as valid input.
	 *
	 * @param string $raw Raw folder field contents.
	 * @return string The folder ID, or an empty string when unrecognised.
	 */
	public static function parse_folder_id( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $raw, $match ) ) {
			return $match[1];
		}

		// A bare ID: Drive IDs are URL-safe base64-ish strings.
		if ( preg_match( '#^[A-Za-z0-9_-]+$#', $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * Turn a newline separated textarea into a list of plugin slugs.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return string[]
	 */
	public static function parse_slug_list( $raw ) {
		$slugs = array();

		// Sanitizing must be idempotent: this runs on every update of the
		// option, including ones that pass an already-parsed array back in.
		$lines = is_array( $raw ) ? $raw : preg_split( '/[\r\n,]+/', (string) $raw );

		foreach ( $lines as $line ) {
			$slug = sanitize_key( trim( $line ) );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Turn newline separated "installed-slug = package-slug" pairs into a map.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<string,string>
	 */
	public static function parse_alias_list( $raw ) {
		$aliases = array();

		// As above: an already-parsed map must survive a second pass.
		if ( is_array( $raw ) ) {
			foreach ( $raw as $from => $to ) {
				$from = sanitize_key( $from );
				$to   = sanitize_key( $to );

				if ( '' !== $from && '' !== $to ) {
					$aliases[ $from ] = $to;
				}
			}

			return $aliases;
		}

		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $from, $to ) = array_map( 'trim', explode( '=', $line, 2 ) );

			$from = sanitize_key( $from );
			$to   = sanitize_key( $to );

			if ( '' !== $from && '' !== $to ) {
				$aliases[ $from ] = $to;
			}
		}

		return $aliases;
	}
}
