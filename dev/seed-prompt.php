<?php
/**
 * Development helper: creates one fully configured prompt.
 *
 * Not shipped. Run with:
 *   wp-env run cli --env-cwd=wp-content/plugins/autoscribe wp eval-file dev/seed-prompt.php
 *
 * Image mode is deliberately set to "required", the strictest of the four
 * behaviours in section 6, so a successful run proves the hardest path rather
 * than the most forgiving one.
 *
 * @package AutoScribe
 */

defined( 'ABSPATH' ) || exit;

$autoscribe_prompt_id = wp_insert_post(
	array(
		'post_type'   => 'autoscribe_prompt',
		'post_status' => 'publish',
		'post_title'  => 'Espresso fundamentals',
	),
	true
);

if ( is_wp_error( $autoscribe_prompt_id ) ) {
	WP_CLI::error( $autoscribe_prompt_id->get_error_message() );
}

$autoscribe_meta = array(
	'text_provider'      => 'anthropic',
	'text_model'         => 'claude-opus-5',
	'system_prompt'      => 'You are a careful coffee writer. Be concrete and avoid filler.',
	'user_prompt'        => 'Explain why nine bars became the espresso standard and when to deviate.',
	'target_word_count'  => 800,
	'post_status_mode'   => 'auto',
	'post_type'          => 'post',
	'image_mode'         => 'required',
	'image_provider'     => 'openai_image',
	'image_model'        => 'gpt-image-2',
	'image_style_suffix' => 'Editorial photography, warm tones, shallow depth of field.',
	'grounding_enabled'  => 0,
	'enabled'            => 1,
);

foreach ( $autoscribe_meta as $autoscribe_key => $autoscribe_value ) {
	update_post_meta( (int) $autoscribe_prompt_id, '_autoscribe_' . $autoscribe_key, $autoscribe_value );
}

WP_CLI::log( (string) $autoscribe_prompt_id );
