<?php
/**
 * Quiet upgrader skin.
 *
 * This file extends a core class that only exists once
 * wp-admin/includes/class-wp-upgrader.php has been loaded, so it must be
 * required lazily rather than on every request.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects upgrader messages instead of printing them.
 */
class UFDRIVE_Upgrader_Skin extends WP_Upgrader_Skin {

	/**
	 * Collected feedback and error messages.
	 *
	 * @var string[]
	 */
	public $messages = array();

	/**
	 * Swallow the header output.
	 *
	 * @return void
	 */
	public function header() {}

	/**
	 * Swallow the footer output.
	 *
	 * @return void
	 */
	public function footer() {}

	/**
	 * Collect an error rather than printing it.
	 *
	 * @param string|WP_Error $errors Error to record.
	 * @return void
	 */
	public function error( $errors ) {
		if ( is_wp_error( $errors ) ) {
			$this->messages[] = $errors->get_error_message();
		} elseif ( is_string( $errors ) && '' !== $errors ) {
			$this->messages[] = $errors;
		}
	}

	/**
	 * Collect feedback rather than printing it.
	 *
	 * @param string|array|WP_Error $feedback Message or message key.
	 * @param mixed                 ...$args  Optional substitutions.
	 * @return void
	 */
	public function feedback( $feedback, ...$args ) {
		if ( is_string( $feedback ) && '' !== $feedback ) {
			$this->messages[] = $feedback;
		}
	}
}
