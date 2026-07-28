<?php
/**
 * How the plugin identifies itself to the Google Drive API.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for anything that can authorize a Drive API request.
 *
 * Only API key credentials exist today, which is all a publicly shared folder
 * needs. Supporting private folders later means adding an OAuth implementation
 * of this interface, not changing the client.
 */
interface UFDRIVE_Credentials {

	/**
	 * Whether the credentials are usable.
	 *
	 * @return bool
	 */
	public function is_ready();

	/**
	 * Extra query arguments to append to every API request.
	 *
	 * @return array<string,string>|WP_Error
	 */
	public function query_args();

	/**
	 * Extra HTTP headers to send with every API request.
	 *
	 * @return array<string,string>|WP_Error
	 */
	public function headers();
}

/**
 * Identifies the caller with a Google API key.
 *
 * An API key grants no access of its own: it only identifies the caller. The
 * folder still has to be shared publicly for the request to succeed.
 */
class UFDRIVE_Api_Key_Credentials implements UFDRIVE_Credentials {

	/**
	 * The API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Google API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Whether a key is present.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return '' !== $this->api_key;
	}

	/**
	 * The key is passed as a query argument, as Google expects.
	 *
	 * @return array<string,string>|WP_Error
	 */
	public function query_args() {
		if ( ! $this->is_ready() ) {
			return new WP_Error(
				'ufdrive_no_api_key',
				__( 'No Google API key has been set.', 'updater-from-drive' )
			);
		}

		return array( 'key' => $this->api_key );
	}

	/**
	 * No headers are needed for API key access.
	 *
	 * @return array<string,string>
	 */
	public function headers() {
		return array();
	}
}
