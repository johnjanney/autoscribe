<?php
/**
 * Anthropic adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Text\Anthropic;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the Anthropic Messages request shape and its failure handling.
 *
 * @since 0.2.0
 */
final class AnthropicTest extends Provider_Test_Case {

	/**
	 * The outgoing request matches the Messages API contract.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'model'   => 'claude-opus-5',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Generated body.',
					),
				),
				'usage'   => array(
					'input_tokens'  => 11,
					'output_tokens' => 22,
				),
			)
		);

		$provider = new Anthropic();
		$result   = $provider->generate(
			'test-key',
			'claude-opus-5',
			new Generation_Request( 'You are a writer.', 'Write about coffee.', 1024 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $this->captured_url() );

		$headers = $this->captured_headers();
		$this->assertSame( 'test-key', $headers['x-api-key'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );

		$body = $this->captured_body();
		$this->assertSame( 'claude-opus-5', $body['model'] );
		$this->assertSame( 1024, $body['max_tokens'] );
		$this->assertSame( 'You are a writer.', $body['system'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertSame( 'Write about coffee.', $body['messages'][0]['content'] );

		// Anthropic rejects these with an HTTP 400, so they must never be sent.
		$this->assertArrayNotHasKey( 'temperature', $body );
		$this->assertArrayNotHasKey( 'top_p', $body );
		$this->assertArrayNotHasKey( 'top_k', $body );
		$this->assertArrayNotHasKey( 'budget_tokens', $body );

		$this->assertSame( 'Generated body.', $result->text() );
		$this->assertSame( 11, $result->usage()->input_tokens() );
		$this->assertSame( 22, $result->usage()->output_tokens() );
	}

	/**
	 * A 401 becomes a WP_Error rather than a fatal or an exception.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_unauthorized_returns_wp_error(): void {
		$this->mock_json( 401, $this->auth_error_body() );

		$provider = new Anthropic();
		$result   = $provider->generate(
			'wrong-key',
			'claude-opus-5',
			new Generation_Request( 'system', 'user', 256 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
		$this->assertStringContainsString( 'invalid x-api-key', $result->get_error_message() );
	}

	/**
	 * A schema request adds output_config and grounding adds the search tool.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_json_and_grounding_options_are_translated(): void {
		$this->mock_json(
			200,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => '{"ok":true}',
					),
				),
				'usage'   => array(
					'input_tokens'  => 1,
					'output_tokens' => 2,
				),
			)
		);

		$schema   = array( 'type' => 'object' );
		$provider = new Anthropic();
		$provider->generate(
			'test-key',
			'claude-opus-5',
			new Generation_Request( 'system', 'user', 256, $schema, true )
		);

		$body = $this->captured_body();
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( $schema, $body['output_config']['format']['schema'] );
		$this->assertSame( 'web_search_20260209', $body['tools'][0]['type'] );
	}
}
