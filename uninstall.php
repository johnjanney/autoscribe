<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's own data only. Generated posts and generated media are
 * deliberately left in place: they are the site owner's content.
 *
 * Three meta keys survive with them, on purpose. Section 6 adds
 * _autoscribe_generated to every generated attachment precisely so a human can
 * find and bulk-delete AI images later, and section 10 adds _autoscribe_run_id
 * to every generated post so the content stays auditable. Deleting content but
 * keeping the flags that identify it is coherent. Keeping the content while
 * destroying the only means of identifying it is not, and that is what a
 * wildcard sweep over _autoscribe_% does.
 *
 * So the sweep is explicit rather than wildcard. If a new prompt setting is
 * added later it must be added to the list below, which is the cost of getting
 * this the right way round.
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

foreach (
	array(
		'autoscribe_db_version',
		'autoscribe_settings',
		'autoscribe_keys',
		'autoscribe_pricing',
		'autoscribe_global_budget_cents',
		'autoscribe_budget_notice_month',
		'autoscribe_debug_log',
		'autoscribe_sweep_cursor',
		'autoscribe_schedule_cursor',
		'autoscribe_grounded_migration_cursor',
	) as $autoscribe_option
) {
	delete_option( $autoscribe_option );
}

/*
 * The 80-percent warning claims one option per month it fires in, named for that
 * month, so the set of them is not known in advance and cannot be listed above.
 * Matched by their exact prefix rather than by anything broader: this file
 * deliberately leaves the three meta keys on generated content behind, and a
 * sweeping autoscribe_% deletion is how that intent gets undone by accident.
 */
$autoscribe_notice_prefix = $wpdb->esc_like( 'autoscribe_budget_notice_month_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Option names are not known in advance, and there is no API for finding them by prefix.
$autoscribe_notice_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$autoscribe_notice_prefix
	)
);

// Found by query and removed by API: a direct DELETE would leave the options
// cache holding rows that are no longer there.
foreach ( (array) $autoscribe_notice_options as $autoscribe_notice_option ) {
	delete_option( (string) $autoscribe_notice_option );
}

/*
 * Meta this plugin owns outright: prompt configuration, and the retry counter.
 * Everything here lives on prompt posts, which are removed with the post type.
 * The three keys that live on generated content are deliberately absent.
 */
$autoscribe_meta_keys = array(
	'_autoscribe_text_provider',
	'_autoscribe_text_model',
	'_autoscribe_system_prompt',
	'_autoscribe_user_prompt',
	'_autoscribe_target_word_count',
	'_autoscribe_post_status_mode',
	'_autoscribe_post_type',
	'_autoscribe_category_ids',
	'_autoscribe_author_id',
	'_autoscribe_image_mode',
	'_autoscribe_image_provider',
	'_autoscribe_image_model',
	'_autoscribe_image_style_suffix',
	'_autoscribe_fallback_image_id',
	'_autoscribe_grounding_enabled',
	'_autoscribe_append_sources',
	'_autoscribe_monthly_budget_cents',
	'_autoscribe_dedupe_lookback',
	'_autoscribe_tag_mode',
	'_autoscribe_fixed_tags',
	'_autoscribe_schedule_type',
	'_autoscribe_schedule_params',
	'_autoscribe_next_run_ts',
	'_autoscribe_enabled',
	'_autoscribe_attempt',
);

foreach ( $autoscribe_meta_keys as $autoscribe_meta_key ) {
	delete_post_meta_by_key( $autoscribe_meta_key );
}

// Prompt posts themselves. The post type is not registered during uninstall, so
// they are found by the stored post_type value rather than through WP_Query.
$autoscribe_prompt_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		'autoscribe_prompt'
	)
);

foreach ( $autoscribe_prompt_ids as $autoscribe_prompt_id ) {
	wp_delete_post( (int) $autoscribe_prompt_id, true );
}

if ( class_exists( '\\AutoScribe\\Activation' ) ) {
	$autoscribe_roles = wp_roles();

	foreach ( $autoscribe_roles->role_objects as $autoscribe_role ) {
		foreach ( \AutoScribe\Activation::capabilities() as $autoscribe_capability ) {
			$autoscribe_role->remove_cap( $autoscribe_capability );
		}
	}
}
