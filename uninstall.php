<?php
/**
 * Uninstall Prime SMTP.
 *
 * @package Prime_SMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'prime_smtp_settings' );
delete_option( 'prime_smtp_legacy_migration_done' );
delete_transient( 'prime_smtp_last_error' );
delete_transient( 'prime_smtp_last_debug' );
delete_transient( 'prime_smtp_test_mode' );
delete_transient( 'prime_smtp_ssl_prefix_mode' );
delete_transient( 'prime_smtp_tls_587_retry_mode' );
delete_transient( 'prime_smtp_connection_test_result' );
