<?php
/**
 * Renders the admin screens under WP-CLI and reports on the output.
 *
 * Development tool, not shipped. Two things are being checked. First, that the
 * settings page and the prompt meta box render without emitting a notice,
 * warning, or fatal. Second, that a stored API key never reaches the markup,
 * which section 8.1 requires and which is the kind of leak that is invisible
 * until someone views source.
 *
 * Run with:
 *   wp eval 'require "/var/www/html/wp-content/plugins/autoscribe/dev/render-check.php";'
 *
 * @package AutoScribe
 */

use AutoScribe\Admin\Prompt_Meta_Box;
use AutoScribe\Admin\Settings_Page;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;

/*
 * A key that must never appear in any rendered screen.
 *
 * Deliberately stored against a provider that has no wp-config constant. With
 * a constant present the stored value is never the one in play, and the check
 * would pass without ever exercising the path it is meant to cover.
 */
$autoscribe_secret = 'sk-render-check-SECRET-4f9a2c';

Key_Store::set( 'deepseek', $autoscribe_secret );

$autoscribe_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

wp_set_current_user( (int) ( $autoscribe_admin[0] ?? 1 ) );

WP_CLI::log( 'Acting as user ' . get_current_user_id() . ' (' . ( current_user_can( 'autoscribe_manage_prompts' ) ? 'has' : 'LACKS' ) . ' autoscribe_manage_prompts)' );

$autoscribe_prompt_id = wp_insert_post(
	array(
		'post_type'   => Prompt_Post_Type::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Render check prompt',
	)
);

update_post_meta( $autoscribe_prompt_id, '_autoscribe_enabled', 1 );
update_post_meta( $autoscribe_prompt_id, '_autoscribe_schedule_type', 'monthly_ordinal' );
update_post_meta(
	$autoscribe_prompt_id,
	'_autoscribe_schedule_params',
	array(
		'ordinal' => 'second',
		'weekday' => 'tuesday',
		'time'    => '06:00',
	)
);

// Settings page.
ob_start();
( new Settings_Page( new Provider_Registry(), new Scheduler() ) )->render();
$autoscribe_settings_html = (string) ob_get_clean();

// Prompt meta box.
ob_start();
( new Prompt_Meta_Box() )->render( get_post( $autoscribe_prompt_id ) );
$autoscribe_metabox_html = (string) ob_get_clean();

WP_CLI::log( '' );
WP_CLI::log( 'Settings page rendered:  ' . strlen( $autoscribe_settings_html ) . ' bytes' );
WP_CLI::log( 'Prompt meta box rendered: ' . strlen( $autoscribe_metabox_html ) . ' bytes' );

$autoscribe_all_html = $autoscribe_settings_html . $autoscribe_metabox_html;

WP_CLI::log( '' );
WP_CLI::log( 'Key source for deepseek: ' . Key_Store::source( 'deepseek' ) . ' (must be "stored" for this check to mean anything)' );
WP_CLI::log( 'Stored key present in rendered HTML: ' . ( str_contains( $autoscribe_all_html, $autoscribe_secret ) ? 'YES - LEAK' : 'no' ) );

/*
 * Notices and warnings are not trapped here. WP_DEBUG_LOG sends them to
 * wp-content/debug.log, and reading that afterwards is better evidence than a
 * handler this script installs for itself: it catches anything raised anywhere
 * in the request, including inside WordPress.
 */
WP_CLI::log( 'Errors, if any, are in wp-content/debug.log — check it after this run.' );

WP_CLI::log( '' );
WP_CLI::log( '--- settings page markers ---' );

foreach ( array( 'Force human review', 'Global monthly cap', 'System health', 'Keep run history', 'Pricing' ) as $autoscribe_marker ) {
	WP_CLI::log( sprintf( '%-24s %s', $autoscribe_marker, str_contains( $autoscribe_settings_html, $autoscribe_marker ) ? 'present' : 'MISSING' ) );
}

WP_CLI::log( '' );
WP_CLI::log( '--- meta box tabs and field count ---' );

foreach ( array_keys( \AutoScribe\Prompts\Prompt_Fields::tabs() ) as $autoscribe_tab ) {
	WP_CLI::log( sprintf( '%-12s %s', $autoscribe_tab, str_contains( $autoscribe_metabox_html, 'autoscribe-tab-' . $autoscribe_tab ) ? 'present' : 'MISSING' ) );
}

$autoscribe_rendered = 0;
$autoscribe_missing  = array();

foreach ( \AutoScribe\Prompts\Prompt_Fields::all() as $autoscribe_key => $autoscribe_field ) {
	if ( str_contains( $autoscribe_metabox_html, 'name="autoscribe_prompt[' . $autoscribe_key . ']' ) ) {
		++$autoscribe_rendered;
	} else {
		$autoscribe_missing[] = $autoscribe_key;
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Fields rendered: ' . $autoscribe_rendered . ' of ' . count( \AutoScribe\Prompts\Prompt_Fields::all() ) );

if ( ! empty( $autoscribe_missing ) ) {
	WP_CLI::log( 'Missing: ' . implode( ', ', $autoscribe_missing ) );
}

WP_CLI::log( '' );
WP_CLI::log( 'Next run readout: ' . wp_strip_all_tags( \AutoScribe\Admin\Next_Run_Readout::describe( (int) $autoscribe_prompt_id ) ) );

Key_Store::forget( 'deepseek' );

wp_delete_post( $autoscribe_prompt_id, true );
