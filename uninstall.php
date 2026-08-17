<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's own data only. Generated posts and generated media are
 * deliberately left in place: they are the site owner's content, and the
 * _autoscribe_generated flag from section 6 of the brief exists so a human can
 * find and remove them on purpose.
 *
 * @package AutoScribe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

global $wpdb;

$autoscribe_table = $wpdb->prefix . 'autoscribe_runs';

// %i is an identifier placeholder, supported since WordPress 6.2. A table name
// cannot be passed as %s because that would quote it as a string literal.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $autoscribe_table ) );

delete_option( 'autoscribe_db_version' );
delete_option( 'autoscribe_settings' );
delete_option( 'autoscribe_keys' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_autoscribe_' ) . '%'
	)
);

if ( class_exists( '\\AutoScribe\\Activation' ) ) {
	$autoscribe_roles = wp_roles();

	foreach ( $autoscribe_roles->role_objects as $autoscribe_role ) {
		foreach ( \AutoScribe\Activation::capabilities() as $autoscribe_capability ) {
			$autoscribe_role->remove_cap( $autoscribe_capability );
		}
	}
}
