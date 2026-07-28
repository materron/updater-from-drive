<?php
/**
 * Minimal Google Drive v3 client.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and downloads ZIP packages from a single publicly shared Drive folder.
 */
class UFDRIVE_Drive_Client {

	const API_BASE = 'https://www.googleapis.com/drive/v3/';

	/**
	 * How the request identifies itself to Google.
	 *
	 * @var UFDRIVE_Credentials
	 */
	protected $credentials;

	/**
	 * Constructor.
	 *
	 * @param UFDRIVE_Credentials $credentials Credential provider.
	 */
	public function __construct( UFDRIVE_Credentials $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * List every non-trashed ZIP directly inside the configured folder.
	 *
	 * Pagination is followed so folders with more than 100 packages work.
	 *
	 * @return array<int,array<string,string>>|WP_Error List of id/name/modifiedTime.
	 */
	public function list_packages() {
		$folder_id = UFDRIVE_Settings::folder_id();

		if ( '' === $folder_id ) {
			return new WP_Error(
				'ufdrive_no_folder',
				__( 'No Google Drive folder has been configured.', 'updater-from-drive' )
			);
		}

		// Ask for the folder itself first. A listing query against a folder
		// Google cannot see succeeds and returns nothing, which would look
		// exactly like an empty folder; fetching it directly turns that
		// silence into a 404 we can explain.
		$reachable = $this->check_folder( $folder_id );

		if ( is_wp_error( $reachable ) ) {
			return $reachable;
		}

		$files      = array();
		$page_token = '';

		do {
			$params = array(
				// No mimeType filter: Drive labels uploaded ZIPs
				// inconsistently (application/zip, x-zip-compressed, or
				// octet-stream depending on how they were uploaded), so the
				// extension is a far more reliable test and it is applied
				// below instead.
				'q'                         => sprintf( "'%s' in parents and trashed = false", $folder_id ),
				'pageSize'                  => 100,
				'fields'                    => 'nextPageToken, files(id, name, mimeType, modifiedTime, size)',
				// Without this, files living in a shared drive are silently
				// absent from the results.
				'includeItemsFromAllDrives' => 'true',
			);

			if ( '' !== $page_token ) {
				$params['pageToken'] = $page_token;
			}

			$response = $this->request( 'files', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! empty( $response['files'] ) && is_array( $response['files'] ) ) {
				foreach ( $response['files'] as $file ) {
					if ( empty( $file['name'] ) || '.zip' !== strtolower( substr( $file['name'], -4 ) ) ) {
						continue;
					}

					$files[] = $file;
				}
			}

			$page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : '';
		} while ( '' !== $page_token );

		return $files;
	}

	/**
	 * Confirm the configured folder exists and is readable.
	 *
	 * @param string $folder_id Drive folder ID.
	 * @return true|WP_Error
	 */
	protected function check_folder( $folder_id ) {
		$response = $this->request( 'files/' . rawurlencode( $folder_id ), array( 'fields' => 'id, mimeType' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['mimeType'] ) && 'application/vnd.google-apps.folder' !== $response['mimeType'] ) {
			return new WP_Error(
				'ufdrive_not_a_folder',
				__( 'That Google Drive address points to a file rather than a folder. Use the address of the folder that holds your packages.', 'updater-from-drive' )
			);
		}

		return true;
	}

	/**
	 * Download a Drive file into a local path.
	 *
	 * @param string $file_id     Drive file ID.
	 * @param string $destination Absolute local path to write to.
	 * @return true|WP_Error
	 */
	public function download( $file_id, $destination ) {
		$url = $this->build_url(
			'files/' . rawurlencode( $file_id ),
			array( 'alt' => 'media' )
		);

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$headers = $this->credentials->headers();

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $destination,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			if ( file_exists( $destination ) ) {
				wp_delete_file( $destination );
			}

			return new WP_Error(
				'ufdrive_download_failed',
				sprintf(
					/* translators: %d: HTTP status code returned by Google Drive. */
					__( 'Google Drive returned HTTP %d while downloading the package.', 'updater-from-drive' ),
					$code
				)
			);
		}

		return true;
	}

	/**
	 * Build a fully qualified, credentialed API URL.
	 *
	 * @param string               $endpoint Endpoint relative to the API base.
	 * @param array<string,string> $params   Query parameters.
	 * @return string|WP_Error
	 */
	protected function build_url( $endpoint, array $params = array() ) {
		$auth_args = $this->credentials->query_args();

		if ( is_wp_error( $auth_args ) ) {
			return $auth_args;
		}

		// Shared drives are invisible to the API unless every call opts in,
		// and a folder in one answers 404 rather than saying so.
		$params['supportsAllDrives'] = 'true';

		return self::API_BASE . $endpoint . '?' . http_build_query( array_merge( $params, $auth_args ) );
	}

	/**
	 * Perform a GET against the Drive API and decode the response.
	 *
	 * @param string               $endpoint Endpoint relative to the API base.
	 * @param array<string,string> $params   Query parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function request( $endpoint, array $params = array() ) {
		$url = $this->build_url( $endpoint, $params );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$headers = $this->credentials->headers();

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'ufdrive_bad_response',
				__( 'Unreadable response from the Google Drive API.', 'updater-from-drive' )
			);
		}

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'ufdrive_drive_error', $this->explain_error( $data['error'] ) );
		}

		return $data;
	}

	/**
	 * Turn a Drive API error into something a site owner can act on.
	 *
	 * The raw messages are written for developers and say nothing about the
	 * two mistakes people actually make: an unshared folder, or a key that is
	 * not allowed to call the Drive API.
	 *
	 * @param array<string,mixed> $error Decoded error object.
	 * @return string
	 */
	protected function explain_error( $error ) {
		$message = isset( $error['message'] )
			? (string) $error['message']
			: __( 'Unknown Google Drive error.', 'updater-from-drive' );

		$code = isset( $error['code'] ) ? (int) $error['code'] : 0;

		if ( 404 === $code ) {
			return __( 'Google Drive could not find that folder. Check the folder address, and make sure it is shared so that anyone with the link can view it.', 'updater-from-drive' );
		}

		if ( 403 === $code ) {
			return sprintf(
				/* translators: %s: the raw message returned by Google. */
				__( 'Google Drive refused the request. Check that the folder is shared with "Anyone with the link", and that the Drive API is enabled for your API key. Google said: %s', 'updater-from-drive' ),
				$message
			);
		}

		if ( 400 === $code ) {
			return sprintf(
				/* translators: %s: the raw message returned by Google. */
				__( 'Google Drive rejected the request, which usually means the API key is wrong. Google said: %s', 'updater-from-drive' ),
				$message
			);
		}

		return $message;
	}
}
