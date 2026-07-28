<?php
/**
 * Plugin bootstrap: wiring, cron and request handlers.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single entry point for the plugin.
 */
class UFDRIVE_Plugin {

	const CRON_HOOK = 'ufdrive_daily_check';

	/**
	 * Singleton instance.
	 *
	 * @var UFDRIVE_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Return the shared instance.
	 *
	 * @return UFDRIVE_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	protected function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( 'admin_post_ufdrive_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'update_option_' . UFDRIVE_Settings::OPTION, array( $this, 'sync_schedule' ), 10, 0 );

		if ( is_admin() ) {
			$admin = new UFDRIVE_Admin();
			$admin->hooks();
		}
	}

	/**
	 * Build an updater bound to the current settings.
	 *
	 * @return UFDRIVE_Updater
	 */
	protected function updater() {
		$credentials = new UFDRIVE_Api_Key_Credentials( UFDRIVE_Settings::api_key() );

		return new UFDRIVE_Updater( new UFDRIVE_Drive_Client( $credentials ) );
	}

	/**
	 * Activation: seed the defaults without touching anything else.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( UFDRIVE_Settings::OPTION, false ) ) {
			UFDRIVE_Settings::save( UFDRIVE_Settings::defaults() );
		}
	}

	/**
	 * Deactivation: drop the scheduled check.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule or unschedule the daily check to match the setting.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$wanted    = (bool) UFDRIVE_Settings::get( 'auto_update' );
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled_check() {
		if ( ! UFDRIVE_Settings::get( 'auto_update' ) || ! UFDRIVE_Settings::is_configured() ) {
			return;
		}

		$this->updater()->run();
	}

	/**
	 * Run a check on demand.
	 *
	 * @return void
	 */
	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'updater-from-drive' ) );
		}

		check_admin_referer( 'ufdrive_run_now' );

		$result = $this->updater()->run();

		if ( ! empty( $result['errors'] ) ) {
			// Problems with individual packages are warnings, not failures:
			// the rest of the run may well have succeeded.
			$type = empty( $result['updated'] ) ? 'error' : 'warning';
			set_transient( 'ufdrive_notice_' . $type, implode( ' ', $result['errors'] ), MINUTE_IN_SECONDS );
		} else {
			set_transient(
				'ufdrive_notice_success',
				sprintf(
					/* translators: 1: number of packages found, 2: number of plugins updated. */
					__( 'Found %1$d packages and updated %2$d plugins.', 'updater-from-drive' ),
					(int) $result['checked'],
					count( $result['updated'] )
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . UFDRIVE_Admin::PAGE_SLUG ) );
		exit;
	}
}
