<?php
/**
 * Plugin Name:       Prime SMTP
 * Plugin URI:        https://primeitsewa.com
 * Description:       Simple, clean, and safer WordPress SMTP mailer with reliable SMTP settings, PHPMailer reset, and test email delivery.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Tested up to:      6.9.1
 * Requires PHP:      7.0
 * Author:            Prime IT Sewa
 * Author URI:        https://primeitsewa.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prime-smtp
 * Domain Path:       /languages
 *
 * @package Prime_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRIME_SMTP_VERSION', '1.0.0' );
define( 'PRIME_SMTP_OPTION', 'prime_smtp_settings' );
define( 'PRIME_SMTP_MIGRATION_OPTION', 'prime_smtp_legacy_migration_done' );

if ( ! class_exists( 'Prime_SMTP' ) ) {
	/**
	 * Main plugin class.
	 */
	final class Prime_SMTP {

		/**
		 * SMTP debug output captured during test emails.
		 *
		 * @var string
		 */
		private $smtp_debug_log = '';

		/**
		 * Default plugin settings.
		 *
		 * @return array
		 */
		private function defaults() {
			return array(
				'host'               => '',
				'port'               => 587,
				'username'           => '',
				'password'           => '',
				'encryption'         => 'tls',
				'from_email'         => get_option( 'admin_email' ),
				'from_name'          => get_bloginfo( 'name' ),
				'force_from'         => 1,
				'return_path'        => 1,
				'allow_insecure_ssl' => 0,
				'force_ipv4'         => 0,
			);
		}

		/**
		 * Boot hooks.
		 */
		public function __construct() {
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_legacy_settings' ), 1 );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_post_prime_smtp_send_test', array( $this, 'send_test_email' ) );
			add_action( 'admin_post_prime_smtp_connection_test', array( $this, 'run_connection_test' ) );
			add_action( 'admin_post_prime_smtp_reset_settings', array( $this, 'reset_settings' ) );
			add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 999 );
			add_filter( 'wp_mail_from', array( $this, 'filter_mail_from' ), 20 );
			add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ), 20 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
			add_action( 'update_option_' . PRIME_SMTP_OPTION, array( $this, 'clear_mailer_cache' ), 10, 0 );
			add_action( 'add_option_' . PRIME_SMTP_OPTION, array( $this, 'clear_mailer_cache' ), 10, 0 );
		}

		/**
		 * Add a Settings link to the plugin actions row.
		 *
		 * @param array $links Existing plugin action links.
		 * @return array
		 */
		public function add_plugin_action_links( $links ) {
			$settings_link = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'options-general.php?page=prime-smtp' ) ),
				esc_html__( 'Settings', 'prime-smtp' )
			);

			array_unshift( $links, $settings_link );

			return $links;
		}

		/**
		 * Add a settings link beside the plugin version/author/site metadata.
		 *
		 * @param array  $links Existing plugin row meta links.
		 * @param string $file  Plugin file path.
		 * @return array
		 */
		public function add_plugin_row_meta( $links, $file ) {
			if ( plugin_basename( __FILE__ ) !== $file ) {
				return $links;
			}

			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'options-general.php?page=prime-smtp' ) ),
				esc_html__( 'SMTP Settings', 'prime-smtp' )
			);

			return $links;
		}

		/**
		 * Read settings with defaults.
		 *
		 * @return array
		 */
		private function get_settings() {
			$settings = get_option( PRIME_SMTP_OPTION, array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			return wp_parse_args( $settings, $this->defaults() );
		}

		/**
		 * Legacy option keys from the previous plugin.
		 *
		 * @return array
		 */
		private function legacy_option_keys() {
			return array(
				'smtp_host',
				'smtp_port',
				'smtp_username',
				'smtp_password',
				'smtp_encryption',
				'smtp_from_email',
				'smtp_from_name',
			);
		}

		/**
		 * Clean stale mailer state when the plugin is activated.
		 */
		public function activate() {
			$this->clear_mailer_cache();
			delete_transient( 'prime_smtp_last_error' );
			delete_transient( 'prime_smtp_last_debug' );
			delete_transient( 'prime_smtp_test_mode' );
			delete_transient( 'prime_smtp_ssl_prefix_mode' );
			delete_transient( 'prime_smtp_tls_587_retry_mode' );
			delete_transient( 'prime_smtp_connection_test_result' );
		}

		/**
		 * Migrate the previous plugin's unprefixed option names.
		 */
		public function maybe_migrate_legacy_settings() {
			if ( get_option( PRIME_SMTP_MIGRATION_OPTION, false ) ) {
				return;
			}

			if ( false !== get_option( PRIME_SMTP_OPTION, false ) ) {
				update_option( PRIME_SMTP_MIGRATION_OPTION, 1, false );
				return;
			}

			$legacy_keys = array(
				'smtp_host'       => 'host',
				'smtp_port'       => 'port',
				'smtp_username'   => 'username',
				'smtp_password'   => 'password',
				'smtp_encryption' => 'encryption',
				'smtp_from_email' => 'from_email',
				'smtp_from_name'  => 'from_name',
			);

			$found    = false;
			$settings = $this->defaults();

			foreach ( $legacy_keys as $legacy_key => $new_key ) {
				$value = get_option( $legacy_key, null );

				if ( null !== $value && false !== $value && '' !== $value ) {
					$settings[ $new_key ] = $value;
					$found                = true;
				}
			}

			if ( $found ) {
				update_option( PRIME_SMTP_OPTION, $this->normalize_settings( $settings ), false );
				$this->clear_mailer_cache();
			}

			update_option( PRIME_SMTP_MIGRATION_OPTION, 1, false );
		}

		/**
		 * Register Settings API fields.
		 */
		public function register_settings() {
			register_setting(
				'prime_smtp_settings_group',
				PRIME_SMTP_OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->defaults(),
				)
			);
		}

		/**
		 * Sanitize settings before saving.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$settings = $this->normalize_settings( $input );

			add_settings_error(
				'prime_smtp_messages',
				'prime_smtp_saved',
				__( 'Prime SMTP settings saved. Mailer cache was refreshed.', 'prime-smtp' ),
				'updated'
			);

			return $settings;
		}

		/**
		 * Normalize settings without creating admin notices.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		private function normalize_settings( $input ) {
			$defaults = $this->defaults();
			$old      = $this->get_settings();
			$input    = is_array( $input ) ? $input : array();

			$host = isset( $input['host'] ) ? sanitize_text_field( wp_unslash( $input['host'] ) ) : '';
			$host = $this->normalize_host( $host );

			$settings = array(
				'host'               => $host['host'],
				'port'               => isset( $input['port'] ) ? absint( $input['port'] ) : $defaults['port'],
				'username'           => isset( $input['username'] ) ? sanitize_text_field( wp_unslash( $input['username'] ) ) : '',
				'password'           => isset( $input['password'] ) ? trim( (string) wp_unslash( $input['password'] ) ) : '',
				'encryption'         => isset( $input['encryption'] ) ? sanitize_key( wp_unslash( $input['encryption'] ) ) : $defaults['encryption'],
				'from_email'         => isset( $input['from_email'] ) ? sanitize_email( wp_unslash( $input['from_email'] ) ) : '',
				'from_name'          => isset( $input['from_name'] ) ? sanitize_text_field( wp_unslash( $input['from_name'] ) ) : '',
				'force_from'         => empty( $input['force_from'] ) ? 0 : 1,
				'return_path'        => empty( $input['return_path'] ) ? 0 : 1,
				'allow_insecure_ssl' => empty( $input['allow_insecure_ssl'] ) ? 0 : 1,
				'force_ipv4'         => empty( $input['force_ipv4'] ) ? 0 : 1,
			);

			if ( empty( $settings['port'] ) && ! empty( $host['port'] ) ) {
				$settings['port'] = $host['port'];
			}

			if ( ! empty( $host['encryption'] ) ) {
				$settings['encryption'] = $host['encryption'];
			}

			if ( '' === $settings['password'] && ! empty( $old['password'] ) ) {
				$settings['password'] = $old['password'];
			}

			if ( ! in_array( $settings['encryption'], array( 'tls', 'ssl', 'none' ), true ) ) {
				$settings['encryption'] = 'tls';
			}

			if ( empty( $settings['port'] ) ) {
				$settings['port'] = ( 'ssl' === $settings['encryption'] ) ? 465 : 587;
			}

			if ( empty( $settings['from_email'] ) || ! is_email( $settings['from_email'] ) ) {
				$settings['from_email'] = $defaults['from_email'];
			}

			if ( empty( $settings['from_name'] ) ) {
				$settings['from_name'] = $defaults['from_name'];
			}

			return $settings;
		}

		/**
		 * Normalize host values copied from SMTP tools.
		 *
		 * @param string $host Raw host value.
		 * @return array
		 */
		private function normalize_host( $host ) {
			$host = trim( $host );
			$data = array(
				'host'       => $host,
				'port'       => 0,
				'encryption' => '',
			);

			if ( '' === $host ) {
				return $data;
			}

			if ( 0 === stripos( $host, 'ssl://' ) ) {
				$data['encryption'] = 'ssl';
				$host               = substr( $host, 6 );
			} elseif ( 0 === stripos( $host, 'tls://' ) ) {
				$data['encryption'] = 'tls';
				$host               = substr( $host, 6 );
			} elseif ( preg_match( '#^https?://#i', $host ) ) {
				$parts = wp_parse_url( $host );
				$host  = isset( $parts['host'] ) ? $parts['host'] : $host;

				if ( ! empty( $parts['port'] ) ) {
					$data['port'] = absint( $parts['port'] );
				}
			}

			if ( false !== strpos( $host, ':' ) && ! preg_match( '/^\[.*\]$/', $host ) ) {
				$parts = explode( ':', $host );
				$port  = array_pop( $parts );

				if ( is_numeric( $port ) ) {
					$data['port'] = absint( $port );
					$host         = implode( ':', $parts );
				}
			}

			$data['host'] = trim( $host, " \t\n\r\0\x0B/" );

			return $data;
		}

		/**
		 * Get the host PHPMailer should open a socket to.
		 *
		 * @param array $settings SMTP settings.
		 * @return string
		 */
		private function get_connection_host( $settings ) {
			if ( empty( $settings['force_ipv4'] ) || empty( $settings['host'] ) || filter_var( $settings['host'], FILTER_VALIDATE_IP ) ) {
				return $settings['host'];
			}

			if ( ! function_exists( 'dns_get_record' ) ) {
				return $settings['host'];
			}

			$records = dns_get_record( $settings['host'], DNS_A );

			if ( empty( $records ) || empty( $records[0]['ip'] ) ) {
				return $settings['host'];
			}

			return $records[0]['ip'];
		}

		/**
		 * Clear option/object cache and reset the global mailer instance.
		 */
		public function clear_mailer_cache() {
			global $phpmailer;

			$phpmailer = null;

			wp_cache_delete( PRIME_SMTP_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );

			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( 'options' );
			}
		}

		/**
		 * Add admin settings page.
		 */
		public function add_settings_page() {
			add_options_page(
				__( 'Prime SMTP', 'prime-smtp' ),
				__( 'Prime SMTP', 'prime-smtp' ),
				'manage_options',
				'prime-smtp',
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Render admin settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$settings      = $this->get_settings();
			$test_email    = isset( $_GET['prime_smtp_test_email'] ) ? sanitize_email( wp_unslash( $_GET['prime_smtp_test_email'] ) ) : '';
			$notice        = isset( $_GET['prime_smtp_notice'] ) ? sanitize_key( wp_unslash( $_GET['prime_smtp_notice'] ) ) : '';
			$error_message = isset( $_GET['prime_smtp_error'] ) ? sanitize_text_field( wp_unslash( $_GET['prime_smtp_error'] ) ) : '';
			$debug_message = get_transient( 'prime_smtp_last_debug' );
			$connection_test = get_transient( 'prime_smtp_connection_test_result' );

			if ( $debug_message ) {
				delete_transient( 'prime_smtp_last_debug' );
			}

			if ( $connection_test ) {
				delete_transient( 'prime_smtp_connection_test_result' );
			}
			?>
			<div class="wrap prime-smtp-wrap">
				<h1><?php esc_html_e( 'Prime SMTP', 'prime-smtp' ); ?></h1>

				<?php settings_errors( 'prime_smtp_messages' ); ?>
				<?php $this->render_conflict_notice(); ?>

				<?php if ( 'sent' === $notice && $test_email ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php echo esc_html( sprintf( __( 'Test email sent successfully to %s.', 'prime-smtp' ), $test_email ) ); ?></p>
					</div>
				<?php elseif ( 'reset' === $notice ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Prime SMTP settings, legacy SMTP options, debug data, and mailer cache were reset. Old SMTP values will not be auto-migrated again. Enter your SMTP settings fresh and save.', 'prime-smtp' ); ?></p>
					</div>
				<?php elseif ( 'connection_test' === $notice && $connection_test ) : ?>
					<div class="notice <?php echo ! empty( $connection_test['success'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
						<p><strong><?php esc_html_e( 'SMTP connection test result', 'prime-smtp' ); ?></strong></p>
						<pre style="white-space:pre-wrap;max-height:260px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:12px;"><?php echo esc_html( $connection_test['message'] ); ?></pre>
					</div>
				<?php elseif ( 'failed' === $notice ) : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'Prime SMTP could not send the test email.', 'prime-smtp' ); ?></p>
						<?php if ( $error_message ) : ?>
							<p><code><?php echo esc_html( $error_message ); ?></code></p>
						<?php endif; ?>
						<?php if ( $debug_message ) : ?>
							<p><strong><?php esc_html_e( 'Connection details', 'prime-smtp' ); ?></strong></p>
							<pre style="white-space:pre-wrap;max-height:260px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:12px;"><?php echo esc_html( $debug_message ); ?></pre>
						<?php endif; ?>
						<?php $this->render_connection_help( $error_message, $debug_message ); ?>
					</div>
				<?php endif; ?>

				<form method="post" action="options.php">
					<?php settings_fields( 'prime_smtp_settings_group' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="prime-smtp-host"><?php esc_html_e( 'SMTP Host', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-host" type="text" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[host]" value="<?php echo esc_attr( $settings['host'] ); ?>" class="regular-text" placeholder="smtp.example.com" autocomplete="off" required>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-encryption"><?php esc_html_e( 'Encryption', 'prime-smtp' ); ?></label></th>
								<td>
									<select id="prime-smtp-encryption" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[encryption]">
										<option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS', 'prime-smtp' ); ?></option>
										<option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'prime-smtp' ); ?></option>
										<option value="none" <?php selected( $settings['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'prime-smtp' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-port"><?php esc_html_e( 'SMTP Port', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-port" type="number" min="1" max="65535" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[port]" value="<?php echo esc_attr( $settings['port'] ); ?>" class="small-text" required>
									<p class="description"><?php esc_html_e( 'Auto-filled from encryption: SSL uses 465, TLS uses 587, None uses 25. You can edit it if your provider uses a custom port.', 'prime-smtp' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-username"><?php esc_html_e( 'SMTP Username', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-username" type="text" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" class="regular-text" autocomplete="username">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-password"><?php esc_html_e( 'SMTP Password', 'prime-smtp' ); ?></label></th>
								<td>
									<?php if ( ! empty( $settings['password'] ) ) : ?>
										<p style="margin-top:0;">
											<strong><?php esc_html_e( 'Password saved.', 'prime-smtp' ); ?></strong>
											<?php esc_html_e( 'It is hidden for security.', 'prime-smtp' ); ?>
										</p>
										<label>
											<input id="prime-smtp-change-password" type="checkbox" value="1">
											<?php esc_html_e( 'Change password', 'prime-smtp' ); ?>
										</label>
										<br>
										<input id="prime-smtp-password" type="password" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Enter new SMTP password', 'prime-smtp' ); ?>" disabled>
										<p class="description"><?php esc_html_e( 'Leave Change password unchecked to keep the saved password.', 'prime-smtp' ); ?></p>
									<?php else : ?>
										<input id="prime-smtp-password" type="password" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Enter SMTP password', 'prime-smtp' ); ?>">
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-from-email"><?php esc_html_e( 'From Email', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-from-email" type="email" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[from_email]" value="<?php echo esc_attr( $settings['from_email'] ); ?>" class="regular-text" required>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="prime-smtp-from-name"><?php esc_html_e( 'From Name', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-from-name" type="text" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[from_name]" value="<?php echo esc_attr( $settings['from_name'] ); ?>" class="regular-text" required>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Sender Controls', 'prime-smtp' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[force_from]" value="1" <?php checked( $settings['force_from'], 1 ); ?>>
										<?php esc_html_e( 'Force the configured From Email and From Name on all WordPress emails.', 'prime-smtp' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[return_path]" value="1" <?php checked( $settings['return_path'], 1 ); ?>>
										<?php esc_html_e( 'Set return path to the From Email to reduce delivery issues.', 'prime-smtp' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Connection Compatibility', 'prime-smtp' ); ?></th>
								<td>
									<p style="margin-top:0;"><strong><?php esc_html_e( 'Recommended: leave both options unchecked.', 'prime-smtp' ); ?></strong></p>
									<?php if ( ! empty( $settings['force_ipv4'] ) || ! empty( $settings['allow_insecure_ssl'] ) ) : ?>
										<div class="notice notice-warning inline">
											<p><?php esc_html_e( 'Advanced compatibility options are enabled. If your error is only "Connection refused", save once with both options unchecked, then test again. Use IPv4 only as the next step.', 'prime-smtp' ); ?></p>
										</div>
									<?php endif; ?>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Connection Compatibility', 'prime-smtp' ); ?></legend>
										<label>
											<input type="checkbox" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[force_ipv4]" value="1" <?php checked( $settings['force_ipv4'], 1 ); ?>>
											<?php esc_html_e( 'Use IPv4 only', 'prime-smtp' ); ?>
										</label>
										<p class="description"><?php esc_html_e( 'Leave off unless your host has an IPv6/DNS routing problem. This does not fix wrong passwords.', 'prime-smtp' ); ?></p>

										<label>
											<input type="checkbox" name="<?php echo esc_attr( PRIME_SMTP_OPTION ); ?>[allow_insecure_ssl]" value="1" <?php checked( $settings['allow_insecure_ssl'], 1 ); ?>>
											<?php esc_html_e( 'Relax SSL certificate checks', 'prime-smtp' ); ?>
										</label>
										<p class="description"><?php esc_html_e( 'Leave off unless the error mentions SSL, certificate, verify, or peer name. This is not needed for normal SSL on port 465.', 'prime-smtp' ); ?></p>
									</fieldset>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Settings', 'prime-smtp' ) ); ?>
				</form>

				<hr>

				<h2><?php esc_html_e( 'Send Test Email', 'prime-smtp' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="prime_smtp_send_test">
					<?php wp_nonce_field( 'prime_smtp_send_test', 'prime_smtp_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="prime-smtp-test-email"><?php esc_html_e( 'Send To', 'prime-smtp' ); ?></label></th>
								<td>
									<input id="prime-smtp-test-email" type="email" name="prime_smtp_test_email" value="" class="regular-text" placeholder="recipient@example.com" required>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Send Test Email', 'prime-smtp' ), 'secondary' ); ?>
				</form>

				<hr>

				<h2><?php esc_html_e( 'Tools', 'prime-smtp' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
					<input type="hidden" name="action" value="prime_smtp_connection_test">
					<?php wp_nonce_field( 'prime_smtp_connection_test', 'prime_smtp_connection_nonce' ); ?>
					<?php submit_button( __( 'Test SMTP Connection Only', 'prime-smtp' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Reset Prime SMTP settings and old SMTP options? You will need to enter the SMTP password again.', 'prime-smtp' ) ); ?>');">
					<input type="hidden" name="action" value="prime_smtp_reset_settings">
					<?php wp_nonce_field( 'prime_smtp_reset_settings', 'prime_smtp_reset_nonce' ); ?>
					<?php submit_button( __( 'Reset SMTP Settings', 'prime-smtp' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
			<script>
				(function () {
					var toggle = document.getElementById('prime-smtp-change-password');
					var password = document.getElementById('prime-smtp-password');
					var encryption = document.getElementById('prime-smtp-encryption');
					var port = document.getElementById('prime-smtp-port');
					var suggestedPorts = {
						ssl: '465',
						tls: '587',
						none: '25'
					};

					if (!toggle || !password) {
						toggle = null;
						password = null;
					}

					if (toggle && password) {
						toggle.addEventListener('change', function () {
							password.disabled = !toggle.checked;

							if (!toggle.checked) {
								password.value = '';
							} else {
								password.focus();
							}
						});
					}

					if (encryption && port) {
						encryption.addEventListener('change', function () {
							var currentPort = port.value;
							var nextPort = suggestedPorts[encryption.value] || '';
							var knownPorts = Object.keys(suggestedPorts).map(function (key) {
								return suggestedPorts[key];
							});

							if (!currentPort || knownPorts.indexOf(currentPort) !== -1) {
								port.value = nextPort;
							}
						});
					}
				}());
			</script>
			<?php
		}

		/**
		 * Configure PHPMailer before each WordPress send.
		 *
		 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
		 */
		public function configure_phpmailer( $phpmailer ) {
			$settings = $this->get_settings();

			if ( empty( $settings['host'] ) || empty( $settings['port'] ) || empty( $settings['from_email'] ) ) {
				return;
			}

			$phpmailer->isSMTP();
			$connection_host       = $this->get_connection_host( $settings );
			$use_ssl_prefix        = ( 'ssl' === $settings['encryption'] && get_transient( 'prime_smtp_ssl_prefix_mode' ) );
			$use_tls_587_retry     = ( 'ssl' === $settings['encryption'] && get_transient( 'prime_smtp_tls_587_retry_mode' ) );
			$phpmailer->Host       = $use_ssl_prefix ? 'ssl://' . $connection_host : $connection_host;
			$phpmailer->SMTPAuth   = ! empty( $settings['username'] );
			$phpmailer->Port       = $use_tls_587_retry ? 587 : absint( $settings['port'] );
			$phpmailer->Username   = $settings['username'];
			$phpmailer->Password   = $settings['password'];
			$phpmailer->SMTPSecure = $use_tls_587_retry ? 'tls' : ( ( $use_ssl_prefix || 'none' === $settings['encryption'] ) ? '' : $settings['encryption'] );
			$phpmailer->From       = $settings['from_email'];
			$phpmailer->FromName   = $settings['from_name'];
			$phpmailer->Sender     = ! empty( $settings['return_path'] ) ? $settings['from_email'] : $phpmailer->Sender;
			$phpmailer->Timeout    = 20;
			$phpmailer->SMTPAutoTLS = $use_tls_587_retry || ( 'tls' === $settings['encryption'] );
			$phpmailer->SMTPKeepAlive = false;

			if ( ! empty( $settings['allow_insecure_ssl'] ) || $connection_host !== $settings['host'] ) {
				$phpmailer->SMTPOptions = array( 'ssl' => array() );

				if ( $connection_host !== $settings['host'] ) {
					$phpmailer->SMTPOptions['ssl']['peer_name'] = $settings['host'];
				}

				if ( ! empty( $settings['allow_insecure_ssl'] ) ) {
					$phpmailer->SMTPOptions['ssl']['verify_peer']       = false;
					$phpmailer->SMTPOptions['ssl']['verify_peer_name']  = false;
					$phpmailer->SMTPOptions['ssl']['allow_self_signed'] = true;
				}
			}

			if ( get_transient( 'prime_smtp_test_mode' ) ) {
				$this->smtp_debug_log   = '';
				$phpmailer->SMTPDebug   = 1;
				$phpmailer->Debugoutput = function ( $message, $level ) {
					$this->smtp_debug_log .= sprintf( "[%s] %s\n", absint( $level ), $this->sanitize_debug_line( $message ) );
				};
			}
		}

		/**
		 * Show a warning when common SMTP plugins are active at the same time.
		 */
		private function render_conflict_notice() {
			$active_plugins = (array) get_option( 'active_plugins', array() );
			$known_plugins  = array(
				'wp-mail-smtp/wp_mail_smtp.php'      => 'WP Mail SMTP',
				'easy-wp-smtp/easy-wp-smtp.php'      => 'Easy WP SMTP',
				'post-smtp/postman-smtp.php'         => 'Post SMTP',
				'fluent-smtp/fluent-smtp.php'        => 'FluentSMTP',
				'wp-smtp/wp-smtp.php'                => 'WP SMTP',
				'gosmtp/gosmtp.php'                  => 'GoSMTP',
				'smtp-mailer/main.php'               => 'SMTP Mailer',
				'mail-bank/wp-mail-bank.php'         => 'Mail Bank',
			);
			$conflicts      = array();

			foreach ( $known_plugins as $plugin_file => $plugin_name ) {
				if ( in_array( $plugin_file, $active_plugins, true ) ) {
					$conflicts[] = $plugin_name;
				}
			}

			if ( empty( $conflicts ) ) {
				return;
			}
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Possible SMTP plugin conflict:', 'prime-smtp' ); ?></strong>
					<?php echo esc_html( implode( ', ', $conflicts ) ); ?>
				</p>
				<p><?php esc_html_e( 'Only one SMTP plugin should control WordPress email at a time. Deactivate other SMTP/mail plugins before testing Prime SMTP.', 'prime-smtp' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Remove sensitive SMTP auth content from debug output.
		 *
		 * @param string $message Debug message.
		 * @return string
		 */
		private function sanitize_debug_line( $message ) {
			$message = wp_strip_all_tags( (string) $message );

			if ( false !== stripos( $message, 'AUTH' ) ) {
				return '[authentication command hidden]';
			}

			return $message;
		}

		/**
		 * Show practical help for common SMTP connection failures.
		 *
		 * @param string $error_message Mail error.
		 * @param string $debug_message Debug/preflight output.
		 */
		private function render_connection_help( $error_message, $debug_message ) {
			$text                  = strtolower( $error_message . ' ' . $debug_message );
			$is_connection_refused = false !== strpos( $text, 'connection refused' );
			$is_timeout            = false !== strpos( $text, 'timed out' ) || false !== strpos( $text, 'timeout' );
			$is_certificate        = false !== strpos( $text, 'certificate' ) || false !== strpos( $text, 'verify' ) || false !== strpos( $text, 'ssl' );
			$is_auth               = false !== strpos( $text, 'authentication' ) || false !== strpos( $text, 'username' ) || false !== strpos( $text, 'password' );
			?>
			<div style="margin-top:12px;padding:12px;border-left:4px solid #72aee6;background:#f6f7f7;">
				<p style="margin-top:0;"><strong><?php esc_html_e( 'What this usually means', 'prime-smtp' ); ?></strong></p>

				<?php if ( $is_connection_refused ) : ?>
					<p><?php esc_html_e( 'The SMTP server refused the connection before WordPress could log in. This is not a password check yet. It usually means the website server IP is blocked, the SMTP port is blocked from PHP, or the mail server is refusing this host.', 'prime-smtp' ); ?></p>
				<?php elseif ( $is_timeout ) : ?>
					<p><?php esc_html_e( 'WordPress could not reach the SMTP server in time. This is usually a hosting firewall, blocked outbound SMTP port, DNS route, or IPv6/IPv4 issue.', 'prime-smtp' ); ?></p>
				<?php elseif ( $is_certificate ) : ?>
					<p><?php esc_html_e( 'PHP reported an SSL/TLS problem. The SMTP certificate may be self-signed, expired, mismatched, or missing a trusted CA chain on this server.', 'prime-smtp' ); ?></p>
				<?php elseif ( $is_auth ) : ?>
					<p><?php esc_html_e( 'The SMTP server was reachable, but login failed. Check the username, password, app password, and whether the account is allowed to send SMTP mail.', 'prime-smtp' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'The SMTP settings may be correct, but the local WordPress/PHP server still cannot complete the SMTP connection. The points below are the most common causes.', 'prime-smtp' ); ?></p>
				<?php endif; ?>

				<ul style="list-style:disc;margin-left:20px;">
					<li><?php esc_html_e( 'fail2ban or firewall block: repeated wrong password tests can ban the website server IP on the mail server. Some bans are permanent or much longer than one day. Unban or whitelist the website server IP, then test again.', 'prime-smtp' ); ?></li>
					<li><?php esc_html_e( 'Wrong encryption for the port: use SSL with port 465, or TLS/STARTTLS with port 587.', 'prime-smtp' ); ?></li>
					<li><?php esc_html_e( 'Outbound SMTP blocked by hosting: some hosts block ports 25, 465, or 587 from PHP even when an online SMTP tester works from another server.', 'prime-smtp' ); ?></li>
					<li><?php esc_html_e( 'IPv6 or DNS route problem: try Use IPv4 only, save settings, then test again.', 'prime-smtp' ); ?></li>
					<li><?php esc_html_e( 'SSL certificate issue: relax SSL checks only when the error mentions SSL, certificate, verify, or peer name. It normally does not help for Connection refused.', 'prime-smtp' ); ?></li>
					<li><?php esc_html_e( 'Server-side mail limits: some providers temporarily refuse SMTP after too many failed login attempts or too many messages.', 'prime-smtp' ); ?></li>
				</ul>

				<?php if ( $is_connection_refused && false !== strpos( $text, 'explicit ssl:// retry: yes' ) ) : ?>
					<p><strong><?php esc_html_e( 'Prime SMTP already retried with the SSL connection style used by many SMTP test tools. Because the server still refused the socket, ask the mail/server administrator to check firewall, fail2ban, and outbound SMTP access for this website server.', 'prime-smtp' ); ?></strong></p>
				<?php endif; ?>

				<?php if ( $is_connection_refused && false !== strpos( $text, 'tls 587 retry: yes' ) ) : ?>
					<p><strong><?php esc_html_e( 'Prime SMTP also retried with port 587 and TLS/STARTTLS. If that still failed, the web server likely cannot reach this SMTP service on common SMTP submission ports.', 'prime-smtp' ); ?></strong></p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Force sender email when enabled.
		 *
		 * @param string $email Existing sender email.
		 * @return string
		 */
		public function filter_mail_from( $email ) {
			$settings = $this->get_settings();

			if ( ! empty( $settings['force_from'] ) && ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ) {
				return $settings['from_email'];
			}

			return $email;
		}

		/**
		 * Force sender name when enabled.
		 *
		 * @param string $name Existing sender name.
		 * @return string
		 */
		public function filter_mail_from_name( $name ) {
			$settings = $this->get_settings();

			if ( ! empty( $settings['force_from'] ) && ! empty( $settings['from_name'] ) ) {
				return $settings['from_name'];
			}

			return $name;
		}

		/**
		 * Send a test email from the admin page.
		 */
		public function send_test_email() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to send test emails.', 'prime-smtp' ) );
			}

			check_admin_referer( 'prime_smtp_send_test', 'prime_smtp_nonce' );

			$this->clear_mailer_cache();

			$to = isset( $_POST['prime_smtp_test_email'] ) ? sanitize_email( wp_unslash( $_POST['prime_smtp_test_email'] ) ) : '';

			if ( ! is_email( $to ) ) {
				$this->redirect_with_notice( 'failed', $to, __( 'Please enter a valid recipient email address.', 'prime-smtp' ) );
			}

			set_transient( 'prime_smtp_test_mode', 1, MINUTE_IN_SECONDS );

			$settings = $this->get_settings();
			$subject  = sprintf(
				/* translators: %s: Site name. */
				__( 'Prime SMTP test from %s', 'prime-smtp' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);
			$message  = $this->build_test_email_html( $settings );
			$headers  = array(
				'Content-Type: text/html; charset=UTF-8',
			);
			$retry_used     = false;
			$tls_retry_used = false;

			add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
			$sent = wp_mail( $to, $subject, $message, $headers );

			if ( ! $sent && 'ssl' === $settings['encryption'] && $this->is_connection_failure( get_transient( 'prime_smtp_last_error' ) ) ) {
				$this->clear_mailer_cache();
				delete_transient( 'prime_smtp_last_error' );
				set_transient( 'prime_smtp_ssl_prefix_mode', 1, MINUTE_IN_SECONDS );

				$sent = wp_mail( $to, $subject, $message, $headers );
				$retry_used = true;

				delete_transient( 'prime_smtp_ssl_prefix_mode' );
			}

			if ( ! $sent && 'ssl' === $settings['encryption'] && 465 === absint( $settings['port'] ) && $this->is_connection_failure( get_transient( 'prime_smtp_last_error' ) ) ) {
				$this->clear_mailer_cache();
				delete_transient( 'prime_smtp_last_error' );
				set_transient( 'prime_smtp_tls_587_retry_mode', 1, MINUTE_IN_SECONDS );

				$sent = wp_mail( $to, $subject, $message, $headers );
				$tls_retry_used = true;

				delete_transient( 'prime_smtp_tls_587_retry_mode' );
			}

			remove_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
			delete_transient( 'prime_smtp_test_mode' );

			$error = get_transient( 'prime_smtp_last_error' );
			delete_transient( 'prime_smtp_last_error' );

			if ( ! empty( $this->smtp_debug_log ) ) {
				set_transient( 'prime_smtp_last_debug', $this->build_debug_summary( $settings, $retry_used, $tls_retry_used ) . "\n" . $this->smtp_debug_log, MINUTE_IN_SECONDS );
			} elseif ( ! $sent ) {
				set_transient( 'prime_smtp_last_debug', $this->build_debug_summary( $settings, $retry_used, $tls_retry_used ), MINUTE_IN_SECONDS );
			}

			if ( $sent ) {
				$this->redirect_with_notice( 'sent', $to );
			}

			$this->redirect_with_notice( 'failed', $to, $error ? $error : __( 'Unknown mail error. Please check the SMTP host, port, encryption, username, and password.', 'prime-smtp' ) );
		}

		/**
		 * Run a socket-level SMTP connection test without login or sending.
		 */
		public function run_connection_test() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to test SMTP connections.', 'prime-smtp' ) );
			}

			check_admin_referer( 'prime_smtp_connection_test', 'prime_smtp_connection_nonce' );

			$settings = $this->get_settings();
			$result   = $this->test_smtp_socket( $settings );

			set_transient( 'prime_smtp_connection_test_result', $result, MINUTE_IN_SECONDS );
			$this->redirect_with_notice( 'connection_test' );
		}

		/**
		 * Reset Prime SMTP and legacy settings.
		 */
		public function reset_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to reset SMTP settings.', 'prime-smtp' ) );
			}

			check_admin_referer( 'prime_smtp_reset_settings', 'prime_smtp_reset_nonce' );

			delete_option( PRIME_SMTP_OPTION );
			delete_option( PRIME_SMTP_MIGRATION_OPTION );
			delete_transient( 'prime_smtp_last_error' );
			delete_transient( 'prime_smtp_last_debug' );
			delete_transient( 'prime_smtp_test_mode' );
			delete_transient( 'prime_smtp_ssl_prefix_mode' );
			delete_transient( 'prime_smtp_tls_587_retry_mode' );
			delete_transient( 'prime_smtp_connection_test_result' );
			delete_site_transient( 'prime_smtp_last_error' );
			delete_site_transient( 'prime_smtp_last_debug' );
			delete_site_transient( 'prime_smtp_test_mode' );
			delete_site_transient( 'prime_smtp_ssl_prefix_mode' );
			delete_site_transient( 'prime_smtp_tls_587_retry_mode' );
			delete_site_transient( 'prime_smtp_connection_test_result' );

			foreach ( $this->legacy_option_keys() as $option_key ) {
				delete_option( $option_key );
			}

			update_option( PRIME_SMTP_MIGRATION_OPTION, 1, false );
			$this->clear_mailer_cache();
			$this->redirect_with_notice( 'reset' );
		}

		/**
		 * Test the SMTP socket from WordPress/PHP without authentication.
		 *
		 * @param array $settings SMTP settings.
		 * @return array
		 */
		private function test_smtp_socket( $settings ) {
			if ( empty( $settings['host'] ) || empty( $settings['port'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'SMTP host and port are required before running the connection test.', 'prime-smtp' ),
				);
			}

			$connection_host = $this->get_connection_host( $settings );
			$scheme          = ( 'ssl' === $settings['encryption'] ) ? 'ssl' : 'tcp';
			$target          = sprintf( '%s://%s:%d', $scheme, $connection_host, absint( $settings['port'] ) );
			$errno           = 0;
			$errstr          = '';
			$context_options = array();

			if ( 'ssl' === $settings['encryption'] && $connection_host !== $settings['host'] ) {
				$context_options['ssl']['peer_name'] = $settings['host'];
			}

			if ( 'ssl' === $settings['encryption'] && ! empty( $settings['allow_insecure_ssl'] ) ) {
				$context_options['ssl']['verify_peer']       = false;
				$context_options['ssl']['verify_peer_name']  = false;
				$context_options['ssl']['allow_self_signed'] = true;
			}

			$context = stream_context_create( $context_options );
			$start   = microtime( true );
			$socket  = @stream_socket_client( $target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context );
			$elapsed = round( ( microtime( true ) - $start ) * 1000 );
			$summary = $this->build_debug_summary( $settings, false );

			if ( $socket ) {
				fclose( $socket );

				return array(
					'success' => true,
					'message' => sprintf(
						"Connection OK: PHP opened %1\$s in %2\$dms.\n\n%3\$s",
						$target,
						$elapsed,
						$summary
					),
				);
			}

			return array(
				'success' => false,
				'message' => sprintf(
					"Connection failed: PHP could not open %1\$s after %2\$dms.\nError %3\$d: %4\$s\n\n%5\$s",
					$target,
					$elapsed,
					absint( $errno ),
					$errstr ? $errstr : 'Unknown socket error',
					$summary
				),
			);
		}

		/**
		 * Determine whether a failure happened before SMTP auth.
		 *
		 * @param string $error Error message.
		 * @return bool
		 */
		private function is_connection_failure( $error ) {
			$error = strtolower( (string) $error );

			return false !== strpos( $error, 'could not connect' )
				|| false !== strpos( $error, 'failed to connect' )
				|| false !== strpos( $error, 'connection refused' )
				|| false !== strpos( $error, 'timed out' );
		}

		/**
		 * Build non-sensitive connection summary for failed tests.
		 *
		 * @param array $settings SMTP settings.
		 * @return string
		 */
		private function build_debug_summary( $settings, $retry_used = false, $tls_retry_used = false ) {
			$connection_host = $this->get_connection_host( $settings );
			$server_addr     = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'unknown';
			$remote_addr     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

			return sprintf(
				"Settings used by WordPress:\nHost: %1\$s\nConnection host: %2\$s\nPort: %3\$d\nEncryption: %4\$s\nIPv4 only: %5\$s\nRelax SSL checks: %6\$s\nExplicit ssl:// retry: %7\$s\nTLS 587 retry: %8\$s\nPHP: %9\$s\nOpenSSL loaded: %10\$s\nWeb server address: %11\$s\nAdmin browser address: %12\$s",
				$settings['host'],
				$connection_host,
				absint( $settings['port'] ),
				strtoupper( $settings['encryption'] ),
				empty( $settings['force_ipv4'] ) ? 'no' : 'yes',
				empty( $settings['allow_insecure_ssl'] ) ? 'no' : 'yes',
				$retry_used ? 'yes' : 'no',
				$tls_retry_used ? 'yes' : 'no',
				PHP_VERSION,
				extension_loaded( 'openssl' ) ? 'yes' : 'no',
				$server_addr,
				$remote_addr
			);
		}

		/**
		 * Capture wp_mail_failed details for admin feedback.
		 *
		 * @param WP_Error $error Mail error.
		 */
		public function capture_mail_error( $error ) {
			if ( is_wp_error( $error ) ) {
				set_transient( 'prime_smtp_last_error', $error->get_error_message(), MINUTE_IN_SECONDS );
			}
		}

		/**
		 * Build a clean HTML test email.
		 *
		 * @param array $settings SMTP settings.
		 * @return string
		 */
		private function build_test_email_html( $settings ) {
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$site_url  = home_url();

			ob_start();
			?>
			<!doctype html>
			<html>
			<head>
				<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1.0">
			</head>
			<body style="margin:0;padding:0;background:#f6f7f7;color:#1d2327;font-family:Arial,Helvetica,sans-serif;">
				<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f7;margin:0;padding:24px 0;">
					<tr>
						<td align="center">
							<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dcdcde;border-radius:6px;">
								<tr>
									<td style="padding:28px 32px 12px 32px;">
										<h1 style="margin:0 0 12px 0;font-size:24px;line-height:1.3;color:#1d2327;">Prime SMTP test email</h1>
										<p style="margin:0;font-size:15px;line-height:1.6;color:#3c434a;">Your WordPress site sent this message through the saved Prime SMTP settings.</p>
									</td>
								</tr>
								<tr>
									<td style="padding:8px 32px 28px 32px;">
										<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.5;border-collapse:collapse;">
											<tr>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#646970;">Site</td>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#1d2327;text-align:right;"><?php echo esc_html( $site_name ); ?></td>
											</tr>
											<tr>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#646970;">URL</td>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#1d2327;text-align:right;"><?php echo esc_html( $site_url ); ?></td>
											</tr>
											<tr>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#646970;">SMTP host</td>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#1d2327;text-align:right;"><?php echo esc_html( $settings['host'] ); ?></td>
											</tr>
											<tr>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#646970;">Encryption</td>
												<td style="padding:10px 0;border-top:1px solid #f0f0f1;color:#1d2327;text-align:right;"><?php echo esc_html( strtoupper( $settings['encryption'] ) ); ?></td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</body>
			</html>
			<?php
			return (string) ob_get_clean();
		}

		/**
		 * Redirect back to settings page with a status notice.
		 *
		 * @param string $notice Notice key.
		 * @param string $email  Recipient email.
		 * @param string $error  Optional error.
		 */
		private function redirect_with_notice( $notice, $email = '', $error = '' ) {
			$args = array(
				'page'              => 'prime-smtp',
				'prime_smtp_notice' => $notice,
			);

			if ( $email ) {
				$args['prime_smtp_test_email'] = $email;
			}

			if ( $error ) {
				$args['prime_smtp_error'] = $error;
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
			exit;
		}
	}
}

new Prime_SMTP();
