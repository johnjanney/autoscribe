<?php
/**
 * Shared base for provider adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

use WP_UnitTestCase;

/**
 * Captures the outgoing request and serves a canned response.
 *
 * @since 0.2.0
 */
abstract class Provider_Test_Case extends WP_UnitTestCase {

	/**
	 * The most recent captured request.
	 *
	 * @since 0.2.0
	 * @var array<string, mixed>
	 */
	protected array $captured = array();

	/**
	 * The mock filter currently registered, so it can be removed cleanly.
	 *
	 * @since 0.2.0
	 * @var callable|null
	 */
	private $mock;

	/**
	 * Resets capture state before each test.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->captured = array();
		$this->mock     = null;
	}

	/**
	 * Removes the mock so the bootstrap tripwire is armed again.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );
			$this->mock = null;
		}

		parent::tear_down();
	}

	/**
	 * Registers a canned JSON response and captures the outgoing request.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $status HTTP status to return.
	 * @param array<string, mixed> $body   Body to JSON-encode.
	 * @return void
	 */
	protected function mock_json( int $status, array $body ): void {
		$this->mock = function ( $preempt, $args, $url ) use ( $status, $body ) {
			unset( $preempt );

			$this->captured = array(
				'url'  => $url,
				'args' => $args,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	/**
	 * Returns the URL of the captured request.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	protected function captured_url(): string {
		return isset( $this->captured['url'] ) ? (string) $this->captured['url'] : '';
	}

	/**
	 * Returns the decoded JSON body of the captured request.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected function captured_body(): array {
		$raw = $this->captured['args']['body'] ?? '';

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Returns the headers of the captured request.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string>
	 */
	protected function captured_headers(): array {
		$headers = $this->captured['args']['headers'] ?? array();

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Returns the HTTP method of the captured request.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	protected function captured_method(): string {
		return isset( $this->captured['args']['method'] ) ? (string) $this->captured['args']['method'] : '';
	}

	/**
	 * Returns a body shaped like a provider authentication failure.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected function auth_error_body(): array {
		return array(
			'error' => array(
				'type'    => 'authentication_error',
				'message' => 'invalid x-api-key',
			),
		);
	}
}
