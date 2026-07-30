<?php
/**
 * Settings screen.
 *
 * @package UpdaterFromDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the options page and registers the settings.
 */
class UFDRIVE_Admin {

	const PAGE_SLUG = 'ufdrive-settings';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . UFDRIVE_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Updater from Drive', 'updater-from-drive' ),
			__( 'Drive Updater', 'updater-from-drive' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'ufdrive_settings_group',
			UFDRIVE_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'UFDRIVE_Settings', 'sanitize' ),
				'default'           => UFDRIVE_Settings::defaults(),
			)
		);

		add_settings_section(
			'ufdrive_connection',
			__( 'Your Drive folder', 'updater-from-drive' ),
			array( $this, 'render_connection_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_key',
			__( 'Google API key', 'updater-from-drive' ),
			array( $this, 'render_api_key' ),
			self::PAGE_SLUG,
			'ufdrive_connection'
		);

		add_settings_field(
			'folder_id',
			__( 'Folder address', 'updater-from-drive' ),
			array( $this, 'render_folder_id' ),
			self::PAGE_SLUG,
			'ufdrive_connection'
		);

		add_settings_section(
			'ufdrive_behaviour',
			__( 'Behaviour', 'updater-from-drive' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'auto_update',
			__( 'Automatic updates', 'updater-from-drive' ),
			array( $this, 'render_auto_update' ),
			self::PAGE_SLUG,
			'ufdrive_behaviour'
		);

		add_settings_field(
			'allowed_slugs',
			__( 'Limit to certain plugins', 'updater-from-drive' ),
			array( $this, 'render_allowed_slugs' ),
			self::PAGE_SLUG,
			'ufdrive_behaviour'
		);

		add_settings_field(
			'slug_aliases',
			__( 'Name overrides', 'updater-from-drive' ),
			array( $this, 'render_slug_aliases' ),
			self::PAGE_SLUG,
			'ufdrive_behaviour'
		);
	}

	/**
	 * Load the stylesheet only on this plugin's screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ufdrive-admin',
			plugins_url( 'assets/css/admin.css', UFDRIVE_PLUGIN_FILE ),
			array(),
			UFDRIVE_VERSION
		);
	}

	/**
	 * Show queued notices.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( array( 'error', 'success', 'warning' ) as $type ) {
			$message = get_transient( 'ufdrive_notice_' . $type );

			if ( ! $message ) {
				continue;
			}

			delete_transient( 'ufdrive_notice_' . $type );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Setup instructions above the fields.
	 *
	 * @return void
	 */
	public function render_connection_intro() {
		echo '<p>' . esc_html__( 'This plugin reads a Google Drive folder that you have shared publicly. Setting it up takes two steps, once.', 'updater-from-drive' ) . '</p>';

		echo '<ol>';
		echo '<li>' . esc_html__( 'In Google Drive, share the folder so that anyone with the link can view it, and copy its address.', 'updater-from-drive' ) . '</li>';
		echo '<li>';
		printf(
			/* translators: %s: link to the Google Cloud Console credentials page. */
			esc_html__( 'In the %s, create a project, enable the Google Drive API, and create an API key. It is free and no billing account is needed.', 'updater-from-drive' ),
			'<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>'
		);
		echo '</li>';
		echo '</ol>';

		echo '<p>' . esc_html__( 'Name each package in the folder after the plugin folder it updates, followed by its version: for example contact-form-7-6.0.1.zip.', 'updater-from-drive' ) . '</p>';
	}

	/**
	 * API key field. The stored value is never rendered back.
	 *
	 * @return void
	 */
	public function render_api_key() {
		$is_constant = UFDRIVE_Settings::api_key_is_constant();
		$has_key     = '' !== (string) UFDRIVE_Settings::get( 'api_key' );

		printf(
			'<input type="password" class="regular-text" name="%1$s[api_key]" value="" autocomplete="off"%2$s />',
			esc_attr( UFDRIVE_Settings::OPTION ),
			$is_constant ? ' disabled="disabled"' : ''
		);

		echo '<p class="description">';

		if ( $is_constant ) {
			esc_html_e( 'The key is being read from wp-config.php, so this field is ignored.', 'updater-from-drive' );
		} elseif ( $has_key ) {
			esc_html_e( 'A key is saved. Leave this blank to keep it, or type a new one to replace it.', 'updater-from-drive' );
		} else {
			esc_html_e( 'No key saved yet.', 'updater-from-drive' );
		}

		echo '</p>';
	}

	/**
	 * Drive folder field.
	 *
	 * @return void
	 */
	public function render_folder_id() {
		printf(
			'<input type="text" class="large-text code" name="%1$s[folder_id]" value="%2$s" placeholder="https://drive.google.com/drive/folders/..." />',
			esc_attr( UFDRIVE_Settings::OPTION ),
			esc_attr( UFDRIVE_Settings::folder_id() )
		);
		echo '<p class="description">'
			. esc_html__( 'Paste the whole folder address from your browser, or just the folder ID.', 'updater-from-drive' )
			. '</p>';
	}

	/**
	 * Automatic update toggle.
	 *
	 * @return void
	 */
	public function render_auto_update() {
		printf(
			'<label><input type="checkbox" name="%1$s[auto_update]" value="1" %2$s /> %3$s</label>',
			esc_attr( UFDRIVE_Settings::OPTION ),
			checked( (bool) UFDRIVE_Settings::get( 'auto_update' ), true, false ),
			esc_html__( 'Check the folder once a day and install newer packages automatically', 'updater-from-drive' )
		);
	}

	/**
	 * Optional allow list field.
	 *
	 * @return void
	 */
	public function render_allowed_slugs() {
		printf(
			'<textarea class="large-text code" rows="5" name="%1$s[allowed_slugs]">%2$s</textarea>',
			esc_attr( UFDRIVE_Settings::OPTION ),
			esc_textarea( implode( "\n", UFDRIVE_Settings::allowed_slugs() ) )
		);
		echo '<p class="description">'
			. esc_html__( 'Optional. One plugin folder name per line. Leave empty to consider every installed plugin.', 'updater-from-drive' )
			. '</p>';
	}

	/**
	 * Alias map field.
	 *
	 * @return void
	 */
	public function render_slug_aliases() {
		$lines = array();

		foreach ( UFDRIVE_Settings::slug_aliases() as $from => $to ) {
			$lines[] = $from . ' = ' . $to;
		}

		printf(
			'<textarea class="large-text code" rows="4" name="%1$s[slug_aliases]">%2$s</textarea>',
			esc_attr( UFDRIVE_Settings::OPTION ),
			esc_textarea( implode( "\n", $lines ) )
		);
		echo '<p class="description">'
			. esc_html__( 'Optional. One "installed-folder = package-name" pair per line, for plugins whose package is named differently from the folder it installs into.', 'updater-from-drive' )
			. '</p>';
	}

	/**
	 * Render the whole options page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_status(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ufdrive_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<?php $this->render_actions(); ?>
			<?php $this->render_unmatched(); ?>
			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Configuration status.
	 *
	 * @return void
	 */
	protected function render_status() {
		$configured = UFDRIVE_Settings::is_configured();
		$last_run   = (int) get_option( 'ufdrive_last_run', 0 );
		?>
		<div class="ufdrive-box">
			<h2><?php esc_html_e( 'Status', 'updater-from-drive' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Configuration:', 'updater-from-drive' ); ?></strong>
				<?php if ( $configured ) : ?>
					<span class="ufdrive-ok"><?php esc_html_e( 'Ready', 'updater-from-drive' ); ?></span>
				<?php else : ?>
					<span class="ufdrive-bad"><?php esc_html_e( 'An API key and a folder address are still needed', 'updater-from-drive' ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( $last_run > 0 ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last check:', 'updater-from-drive' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human readable time difference, e.g. "5 mins". */
							__( '%s ago', 'updater-from-drive' ),
							human_time_diff( $last_run )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Manual run button.
	 *
	 * @return void
	 */
	protected function render_actions() {
		if ( ! UFDRIVE_Settings::is_configured() ) {
			return;
		}
		?>
		<div class="ufdrive-box">
			<h2><?php esc_html_e( 'Check now', 'updater-from-drive' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufdrive_run_now' ); ?>
				<input type="hidden" name="action" value="ufdrive_run_now" />
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Check the folder and update', 'updater-from-drive' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Anything that could not be paired up, and a way to pair it by hand.
	 *
	 * Package names do not always resemble the folder a plugin installs into,
	 * and some pairings cannot be guessed at all. Showing the leftovers turns
	 * a silent "nothing to update" into something the site owner can act on.
	 *
	 * @return void
	 */
	protected function render_unmatched() {
		$report = get_option( 'ufdrive_unmatched', array() );

		if ( ! is_array( $report ) || empty( $report['plugins'] ) ) {
			return;
		}

		$packages = isset( $report['packages'] ) && is_array( $report['packages'] ) ? $report['packages'] : array();
		?>
		<div class="ufdrive-box">
			<h2><?php esc_html_e( 'Not paired up', 'updater-from-drive' ); ?></h2>
			<p>
				<?php esc_html_e( 'These installed plugins have no package in your folder that matches their name. If one of them is in there under a different name, pair them here and it will be remembered.', 'updater-from-drive' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufdrive_save_mapping' ); ?>
				<input type="hidden" name="action" value="ufdrive_save_mapping" />
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Installed plugin', 'updater-from-drive' ); ?></th>
							<th><?php esc_html_e( 'Folder', 'updater-from-drive' ); ?></th>
							<th><?php esc_html_e( 'Package in your Drive folder', 'updater-from-drive' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['plugins'] as $plugin ) : ?>
							<tr>
								<td><?php echo esc_html( $plugin['name'] ); ?></td>
								<td><code><?php echo esc_html( $plugin['slug'] ); ?></code></td>
								<td>
									<select name="mapping[<?php echo esc_attr( $plugin['slug'] ); ?>]">
										<option value=""><?php esc_html_e( '— not in my folder —', 'updater-from-drive' ); ?></option>
										<?php foreach ( $packages as $package ) : ?>
											<option value="<?php echo esc_attr( $package['slug'] ); ?>">
												<?php echo esc_html( $package['filename'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save pairings', 'updater-from-drive' ), 'secondary' ); ?>
			</form>

			<?php if ( ! empty( $packages ) ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of packages. */
							_n(
								'%d package in your folder does not correspond to anything installed on this site. That is normal if you keep packages for other sites in the same folder.',
								'%d packages in your folder do not correspond to anything installed on this site. That is normal if you keep packages for other sites in the same folder.',
								count( $packages ),
								'updater-from-drive'
							),
							count( $packages )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Recent activity.
	 *
	 * @return void
	 */
	protected function render_log() {
		$entries = array_reverse( UFDRIVE_Logger::entries() );
		?>
		<div class="ufdrive-box">
			<h2><?php esc_html_e( 'Activity', 'updater-from-drive' ); ?></h2>
			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'Nothing logged yet.', 'updater-from-drive' ); ?></p>
			<?php else : ?>
				<ul class="ufdrive-log">
					<?php foreach ( array_slice( $entries, 0, 30 ) as $entry ) : ?>
						<li class="ufdrive-log-<?php echo esc_attr( $entry['level'] ); ?>">
							<code><?php echo esc_html( $entry['time'] ); ?></code>
							<?php echo esc_html( $entry['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add a settings shortcut to the plugins list.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'updater-from-drive' ) . '</a>'
		);

		return $links;
	}
}
