<?php
/**
 * Works out which package belongs to which installed plugin.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Package to plugin matching.
 *
 * Package names rarely match the folder a plugin installs into. Astra Pro is
 * published as astra-addon-plugin but installs into astra-addon; Gravity Forms
 * Quiz installs into gravityformsquiz but is published as gravity-forms-quiz.
 *
 * Matching therefore happens in three passes, cheapest first:
 *
 *   1. The name, exactly.
 *   2. The name, ignoring punctuation and decorative suffixes. Free.
 *   3. The plugin header inside the archive, for a small number of strong
 *      candidates. Costs one download each, once, and the answer is kept.
 *
 * Anything still unmatched is reported to the site owner rather than ignored,
 * because no amount of guessing turns woothemes-sensei into
 * woocommerce-paid-courses.
 */
class UFDRIVE_Matcher {

	const DISCOVERED_OPTION = 'ufdrive_discovered_aliases';
	const INSPECTED_OPTION  = 'ufdrive_inspected_packages';

	/**
	 * How many unknown packages may be opened in a single run.
	 *
	 * Keeps the first check on a large folder from running out of time. The
	 * rest are picked up on later runs, since every answer is remembered.
	 */
	const INSPECT_LIMIT = 5;

	/**
	 * Word endings that decorate a package name without identifying it.
	 *
	 * Deliberately short. "pro" and "premium" are left out because plenty of
	 * plugins genuinely install into a folder ending that way.
	 */
	const DECORATIVE_SUFFIXES = array( 'plugin', 'wp', 'wordpress', 'latest' );

	/**
	 * Reduce a slug to its comparable form: lower case, no punctuation.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function normalize( $slug ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $slug ) );
	}

	/**
	 * Every key a slug should be findable under.
	 *
	 * @param string $slug Raw slug.
	 * @return string[]
	 */
	public static function variants( $slug ) {
		$slug = strtolower( (string) $slug );

		$forms = array( $slug );

		foreach ( self::DECORATIVE_SUFFIXES as $suffix ) {
			$tail = '-' . $suffix;

			if ( strlen( $slug ) > strlen( $tail ) && $tail === substr( $slug, -strlen( $tail ) ) ) {
				$forms[] = substr( $slug, 0, -strlen( $tail ) );
			}
		}

		$keys = array();

		foreach ( $forms as $form ) {
			$keys[] = $form;
			$keys[] = self::normalize( $form );
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Build the loose lookup table for an index of packages.
	 *
	 * Exact names always win, so they are never overwritten by a looser form
	 * of some other package.
	 *
	 * @param array<string,array<string,string>> $index Packages keyed by exact lower case slug.
	 * @return array<string,string> Loose key => exact index key.
	 */
	public static function build_lookup( array $index ) {
		$lookup = array();

		foreach ( $index as $key => $package ) {
			foreach ( self::variants( $package['slug'] ) as $variant ) {
				if ( isset( $index[ $variant ] ) || isset( $lookup[ $variant ] ) ) {
					continue;
				}

				$lookup[ $variant ] = $key;
			}
		}

		return $lookup;
	}

	/**
	 * Find the package for an installed plugin by name alone.
	 *
	 * @param string                             $slug   Installed plugin folder.
	 * @param array<string,array<string,string>> $index  Packages keyed by exact lower case slug.
	 * @param array<string,string>               $lookup Loose lookup from build_lookup().
	 * @return array<string,string>|null
	 */
	public static function match_by_name( $slug, array $index, array $lookup ) {
		foreach ( self::variants( $slug ) as $variant ) {
			if ( isset( $index[ $variant ] ) ) {
				return $index[ $variant ];
			}

			if ( isset( $lookup[ $variant ] ) && isset( $index[ $lookup[ $variant ] ] ) ) {
				return $index[ $lookup[ $variant ] ];
			}
		}

		return null;
	}

	/**
	 * Packages worth opening to see whether they belong to an installed plugin.
	 *
	 * A candidate has to share the first word and most of the rest, so a site
	 * with WooCommerce installed does not decide that all forty WooCommerce
	 * extensions are worth downloading.
	 *
	 * @param string                             $slug  Installed plugin folder.
	 * @param array<string,array<string,string>> $index Packages keyed by exact lower case slug.
	 * @return array<string,array<string,string>> Candidates keyed by index key.
	 */
	public static function candidates( $slug, array $index ) {
		$want = array_values( array_filter( preg_split( '/[-_]/', strtolower( $slug ) ) ) );

		// A one word name is too weak a signal to act on. "woocommerce" is a
		// prefix of dozens of unrelated extensions, and opening all of them to
		// find that out would cost far more than it could ever be worth.
		if ( count( $want ) < 2 ) {
			return array();
		}

		$candidates = array();

		foreach ( $index as $key => $package ) {
			$have = array_values( array_filter( preg_split( '/[-_]/', strtolower( $package['slug'] ) ) ) );

			if ( empty( $have ) || $have[0] !== $want[0] ) {
				continue;
			}

			// Every word of the installed name has to appear in the package
			// name. Missing words mean it is something else.
			if ( count( array_intersect( $want, $have ) ) < count( $want ) ) {
				continue;
			}

			// And it may not have grown much: a couple of extra words is a
			// renamed package, a handful is a different plugin.
			if ( count( $have ) - count( $want ) > 2 ) {
				continue;
			}

			$candidates[ $key ] = $package;
		}

		return $candidates;
	}

	/**
	 * Aliases the plugin has worked out for itself by reading archives.
	 *
	 * @return array<string,string> Installed slug => package slug.
	 */
	public static function discovered() {
		$aliases = get_option( self::DISCOVERED_OPTION, array() );
		return is_array( $aliases ) ? $aliases : array();
	}

	/**
	 * Remember an alias so it never has to be worked out again.
	 *
	 * @param string $installed_slug Installed plugin folder.
	 * @param string $package_slug   Package name it corresponds to.
	 * @return void
	 */
	public static function remember_alias( $installed_slug, $package_slug ) {
		$aliases = self::discovered();

		if ( isset( $aliases[ $installed_slug ] ) && $aliases[ $installed_slug ] === $package_slug ) {
			return;
		}

		$aliases[ $installed_slug ] = $package_slug;
		update_option( self::DISCOVERED_OPTION, $aliases, false );
	}

	/**
	 * The plugin folder found inside a package, if it has been looked at.
	 *
	 * @param string $file_id  Drive file ID.
	 * @param string $modified Drive modifiedTime.
	 * @return string|null The slug, an empty string if unreadable, null if unknown.
	 */
	public static function inspected( $file_id, $modified ) {
		$seen = get_option( self::INSPECTED_OPTION, array() );

		if ( ! is_array( $seen ) || ! isset( $seen[ $file_id ] ) ) {
			return null;
		}

		$entry = $seen[ $file_id ];

		if ( ! isset( $entry['modified'] ) || $entry['modified'] !== $modified ) {
			return null;
		}

		return isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
	}

	/**
	 * Record what was inside a package, including that it was unreadable.
	 *
	 * Negative answers are kept too, so a broken archive is not downloaded
	 * again on every run.
	 *
	 * @param string $file_id  Drive file ID.
	 * @param string $modified Drive modifiedTime.
	 * @param string $slug     Plugin folder found inside, or an empty string.
	 * @return void
	 */
	public static function remember_inspection( $file_id, $modified, $slug ) {
		$seen = get_option( self::INSPECTED_OPTION, array() );

		if ( ! is_array( $seen ) ) {
			$seen = array();
		}

		$seen[ $file_id ] = array(
			'modified' => $modified,
			'slug'     => (string) $slug,
		);

		update_option( self::INSPECTED_OPTION, $seen, false );
	}
}
