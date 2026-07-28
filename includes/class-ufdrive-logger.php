<?php
/**
 * Bounded activity log.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores a capped list of recent events in a non-autoloaded option.
 */
class UFDRIVE_Logger {

	const OPTION   = 'ufdrive_log';
	const MAX_ROWS = 100;

	/**
	 * Append an entry to the log.
	 *
	 * @param string $message Human readable message. Already translated.
	 * @param string $level   One of 'info', 'warning', 'error'.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		$entries = self::entries();

		$entries[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => (string) $message,
		);

		if ( count( $entries ) > self::MAX_ROWS ) {
			$entries = array_slice( $entries, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * All stored entries, oldest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Remove every stored entry.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
