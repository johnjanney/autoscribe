<?php
/**
 * Debug capture tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Diagnostics;

use AutoScribe\Admin\Settings;
use AutoScribe\Diagnostics\Debug_Log;
use AutoScribe\Providers\Http;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers what the diagnostic capture keeps and what it refuses to keep.
 *
 * Three of these matter more than the rest. Capture must be inert until it is
 * asked for, because it is on every provider call the plugin makes. It must not
 * hold a credential, because the log exists to be pasted somewhere else. And it
 * must not grow without bound, because the option it lives in is loaded whole
 * every time it is written.
 *
 * @since 1.16.0
 */
final class Debug_LogTest extends WP_UnitTestCase {

	use Mocks_Provider;

	/**
	 * Starts each test with capture on and the log empty.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Debug_Log::clear();
		Debug_Log::clear_context();

		Settings::save( array( 'debug_mode' => true ) );
	}

	/**
	 * Restores the default and removes any mock.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		Settings::save( array( 'debug_mode' => false ) );

		Debug_Log::clear();
		Debug_Log::clear_context();

		parent::tear_down();
	}

	/**
	 * Nothing is captured while the setting is off.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_capture_is_off_by_default(): void {
		Settings::save( array( 'debug_mode' => false ) );

		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'https://example.test/v1', 'a body' );

		$this->assertSame( array(), Debug_Log::entries() );
	}

	/**
	 * The shipped default is off, so an upgrade does not start capturing.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_default_setting_is_off(): void {
		$this->assertFalse( Settings::defaults()['debug_mode'] );
	}

	/**
	 * A failed provider call keeps the status, the URL, and the body.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_http_failure_is_captured(): void {
		$this->mock_provider_failure( 400, '{"error":{"message":"max_tokens: must be <= 8192"}}' );

		Http::post_json( 'https://api.example.test/v1/messages', array(), array( 'model' => 'x' ), 30 );

		$entries = Debug_Log::entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( Debug_Log::CHANNEL_HTTP, $entries[0]['channel'] );
		$this->assertSame( 400, $entries[0]['status'] );
		$this->assertSame( 'https://api.example.test/v1/messages', $entries[0]['subject'] );
		$this->assertStringContainsString( 'must be <= 8192', $entries[0]['body'] );
	}

	/**
	 * A rejected request keeps what was sent, because that is half the answer.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_rejected_request_keeps_the_request_body(): void {
		$this->mock_provider_failure( 400 );

		Http::post_json( 'https://api.example.test/v1/messages', array(), array( 'model' => 'a-model-id' ), 30 );

		$entries = Debug_Log::entries();

		$this->assertStringContainsString( 'request sent', $entries[0]['body'] );
		$this->assertStringContainsString( 'a-model-id', $entries[0]['body'] );
	}

	/**
	 * A successful request keeps only the response, not the prompt sent with it.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_successful_request_does_not_keep_the_request_body(): void {
		$this->mock_provider_success();

		Http::post_json( 'https://api.example.test/v1/messages', array(), array( 'model' => 'a-model-id' ), 30 );

		$entries = Debug_Log::entries();

		$this->assertCount( 1, $entries );
		$this->assertStringNotContainsString( 'request sent', $entries[0]['body'] );
		$this->assertStringNotContainsString( 'a-model-id', $entries[0]['body'] );
	}

	/**
	 * A request that never reached a provider is filed as a transport failure.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_transport_failure_is_captured(): void {
		$this->install_responder(
			static function ( $args ) {
				unset( $args );

				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
		);

		Http::post_json( 'https://api.example.test/v1/messages', array(), array(), 30 );

		$entries = Debug_Log::entries();

		$this->assertSame( Debug_Log::CHANNEL_TRANSPORT, $entries[0]['channel'] );
		$this->assertStringContainsString( 'Operation timed out', $entries[0]['body'] );
	}

	/**
	 * A key quoted back inside a response body is blanked.
	 *
	 * Headers are never passed to the capture, so no key should reach it at all.
	 * This covers the case that defeats that reasoning: a provider that echoes
	 * the offending request into its own error message.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_credentials_are_scrubbed(): void {
		Debug_Log::record(
			Debug_Log::CHANNEL_HTTP,
			'https://api.example.test/v1',
			'{"error":"bad key sk-ant-api03-ZZZZZZZZZZZZZZZZZZZZZZZZ and AIzaSyDZZZZZZZZZZZZZZZZZZZZZZ",'
				. '"authorization":"Bearer eyJhbGciOiJIUzI1NiJ9zzzz","api_key":"secret-value-here"}'
		);

		$body = (string) Debug_Log::entries()[0]['body'];

		$this->assertStringNotContainsString( 'sk-ant-api03-ZZZZ', $body );
		$this->assertStringNotContainsString( 'AIzaSyDZZZZ', $body );
		$this->assertStringNotContainsString( 'eyJhbGciOiJIUzI1NiJ9zzzz', $body );
		$this->assertStringNotContainsString( 'secret-value-here', $body );
		$this->assertStringContainsString( 'redacted', $body );
	}

	/**
	 * An inline image is dropped rather than stored.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_base64_payloads_are_omitted(): void {
		$blob = str_repeat( 'QUJDREVG', 200 );

		Debug_Log::record(
			Debug_Log::CHANNEL_HTTP,
			'https://api.example.test/v1/images',
			'{"data":[{"b64_json":"' . $blob . '"}]}'
		);

		$body = (string) Debug_Log::entries()[0]['body'];

		$this->assertStringNotContainsString( $blob, $body );
		$this->assertStringContainsString( 'binary omitted', $body );
	}

	/**
	 * The original size is recorded even though the body is not kept whole.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_oversized_body_is_truncated_and_its_length_noted(): void {
		$long = str_repeat( 'the model rambled. ', 2000 );

		Debug_Log::record( Debug_Log::CHANNEL_CONTENT, 'too long', $long );

		$entry = Debug_Log::entries()[0];

		$this->assertSame( strlen( $long ), $entry['bytes'] );
		$this->assertLessThanOrEqual( Debug_Log::MAX_BODY_BYTES + 20, strlen( (string) $entry['body'] ) );
		$this->assertStringContainsString( 'truncated', (string) $entry['body'] );
	}

	/**
	 * The log keeps the newest entries and discards the rest.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_log_is_capped(): void {
		for ( $i = 0; $i < Debug_Log::MAX_ENTRIES + 5; $i++ ) {
			Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'entry-' . $i, 'body' );
		}

		$entries = Debug_Log::entries();

		$this->assertCount( Debug_Log::MAX_ENTRIES, $entries );
		$this->assertSame( 'entry-5', $entries[0]['subject'] );
		$this->assertSame( 'entry-' . ( Debug_Log::MAX_ENTRIES + 4 ), $entries[ Debug_Log::MAX_ENTRIES - 1 ]['subject'] );
	}

	/**
	 * The option does not autoload, so a log left on costs nothing per request.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_option_does_not_autoload(): void {
		global $wpdb;

		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'https://example.test/v1', 'body' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Debug_Log::OPTION
			)
		);

		$this->assertNotSame( 'yes', $autoload );
	}

	/**
	 * The current run and step are attached to whatever is captured.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_context_is_attached(): void {
		Debug_Log::set_context( 42, 7, 'generate_body' );

		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'https://example.test/v1', 'body' );

		$entry = Debug_Log::entries()[0];

		$this->assertSame( 42, $entry['run'] );
		$this->assertSame( 7, $entry['prompt'] );
		$this->assertSame( 'generate_body', $entry['step'] );
	}

	/**
	 * Clearing empties the log.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_clear_empties_the_log(): void {
		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'https://example.test/v1', 'body' );

		Debug_Log::clear();

		$this->assertSame( array(), Debug_Log::entries() );
		$this->assertSame( '', Debug_Log::as_text() );
	}

	/**
	 * The rendered text puts the newest entry first.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public function test_text_rendering_is_newest_first(): void {
		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'older-call', 'body' );
		Debug_Log::record( Debug_Log::CHANNEL_HTTP, 'newer-call', 'body' );

		$text = Debug_Log::as_text();

		$this->assertLessThan( strpos( $text, 'older-call' ), strpos( $text, 'newer-call' ) );
	}
}
