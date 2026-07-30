<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ufdrive_settings' );
delete_option( 'ufdrive_log' );
delete_option( 'ufdrive_last_run' );
delete_option( 'ufdrive_skipped_fingerprint' );
delete_transient( 'ufdrive_self_update' );
delete_option( 'ufdrive_discovered_aliases' );
delete_option( 'ufdrive_inspected_packages' );
delete_option( 'ufdrive_unmatched' );

wp_clear_scheduled_hook( 'ufdrive_daily_check' );
