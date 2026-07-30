<?php
/**
 * Plugin Name:       Updater from Drive
 * Plugin URI:        https://github.com/materron/updater-from-drive
 * Description:       Keeps your installed plugins up to date from ZIP packages stored in a Google Drive folder you own.
 * Version:           1.0.3
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Miguel Ángel Terrón
 * Author URI:        https://github.com/materron
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       updater-from-drive
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UFDRIVE_VERSION', '1.0.3' );
define( 'UFDRIVE_PLUGIN_FILE', __FILE__ );
define( 'UFDRIVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UFDRIVE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-settings.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-logger.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-credentials.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-drive-client.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-package.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-matcher.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-updater.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-admin.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-self-updater.php';
require_once UFDRIVE_PLUGIN_DIR . 'includes/class-ufdrive-plugin.php';

register_activation_hook( __FILE__, array( 'UFDRIVE_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UFDRIVE_Plugin', 'deactivate' ) );

UFDRIVE_Plugin::instance();
