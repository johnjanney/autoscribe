<?php
/**
 * Plugin Name: AutoScribe Mock Provider
 * Description: Development-only. Intercepts outbound provider HTTP calls and returns canned responses so the pipeline can be exercised end to end without contacting a live API or spending money.
 * Version:     0.3.0
 * License:     GPL-2.0-or-later
 *
 * This file is NOT shipped. It lives in dev/ and is mounted into the wp-env
 * container by .wp-env.json. Nothing in the plugin itself knows it exists:
 * the interception happens at the WordPress HTTP layer, so the entire real
 * code path runs, including Http error mapping, validation, sanitisation, and
 * the media sideload.
 *
 * @package AutoScribe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the canned article payload the mocked text provider replies with.
 *
 * The body HTML deliberately contains a script element, an iframe, and a
 * javascript: link so that a successful run demonstrates section 5.2
 * sanitisation actually removing them rather than merely claiming to.
 *
 * @since 0.3.0
 *
 * @return string JSON encoded article.
 */
function autoscribe_mock_article_json(): string {
	$body = '<h2>Why Espresso Pressure Matters</h2>'
		. '<p>Nine bars is <strong>not</strong> a magic number.</p>'
		. '<script>alert("xss")</script>'
		. '<p><a href="javascript:alert(1)">dangerous link</a> next to a '
		. '<a href="https://example.com">legitimate link</a>.</p>'
		. '<ul><li>Grind fresh</li><li>Weigh the dose</li></ul>'
		. '<iframe src="https://evil.example/frame"></iframe>'
		. '<blockquote>Coffee is a language in itself.</blockquote>';

	return (string) wp_json_encode(
		array(
			'title'            => 'Why Espresso Pressure Matters',
			'topic_key'        => 'espresso-pressure-basics',
			'excerpt'          => 'Nine bars is a starting point, not a rule.',
			'content_html'     => $body,
			'seo_title'        => 'Espresso Pressure: Why Nine Bars Is Only A Starting Point For Great Coffee',
			'meta_description' => 'Nine bars became the espresso default for historical reasons rather than physical ones, and understanding why gives you a better shot.',
			'focus_keyword'    => 'espresso pressure',
			'suggested_tags'   => array( 'espresso', 'coffee', 'brewing' ),
			'image_prompt'     => 'A close-up of an espresso machine portafilter, steam rising',
			'image_alt'        => 'Espresso pouring from a portafilter into a white cup',
		)
	);
}

/**
 * Returns a small valid PNG, base64 encoded.
 *
 * @since 0.3.0
 *
 * @return string
 */
function autoscribe_mock_png_base64(): string {
	return 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAPElEQVR42u3RAQ0AAAgDIF'
		. '/1L23G5wZIWNJbGxsbGxsbGxsbGxsbGxsbGxsbGxsbGxsbGxsbGxsbGxs7cQFY0AABxjKu'
		. 'YQAAAABJRU5ErkJggg==';
}

/**
 * Intercepts provider HTTP calls and answers with canned responses.
 *
 * @since 0.3.0
 *
 * @param false|array<string, mixed> $preempt Short-circuit value.
 * @param array<string, mixed>       $args    Request arguments.
 * @param string                     $url     Request URL.
 * @return false|array<string, mixed>
 */
function autoscribe_mock_http_request( $preempt, $args, $url ) {
	unset( $args );

	if ( false !== $preempt ) {
		return $preempt;
	}

	$body = null;

	if ( false !== strpos( $url, 'api.anthropic.com/v1/messages' ) ) {
		$body = array(
			'model'   => 'claude-opus-5',
			'content' => array(
				array(
					'type' => 'text',
					'text' => autoscribe_mock_article_json(),
				),
			),
			'usage'   => array(
				'input_tokens'  => 812,
				'output_tokens' => 1435,
			),
		);
	}

	if ( false !== strpos( $url, 'api.openai.com/v1/images/generations' ) ) {
		$body = array(
			'data' => array(
				array( 'b64_json' => autoscribe_mock_png_base64() ),
			),
		);
	}

	if ( null === $body ) {
		return $preempt;
	}

	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/*
 * Not registered during the PHPUnit suite. tests/bootstrap.php defines
 * AUTOSCRIBE_DISABLE_MOCK before WordPress loads, because this mu-plugin is
 * mounted into the tests container as well as the development one, and an
 * always-on mock would answer the requests the suite's tripwire exists to
 * catch, quietly voiding the guarantee that no test reaches a live API.
 */
if ( ! defined( 'AUTOSCRIBE_DISABLE_MOCK' ) ) {
	add_filter( 'pre_http_request', 'autoscribe_mock_http_request', 10, 3 );
}
