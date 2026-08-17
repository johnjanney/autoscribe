<?php
/**
 * DeepSeek adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Text\DeepSeek;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the DeepSeek chat completions request shape and failure handling.
 *
 * @since 0.2.0
 */
final class DeepSeekTest extends Provider_Test_Case {

	/**
	 * The outgoing request matches the chat completions contract.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'model'   => 'deepseek-v4-flash',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Generated body.',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 3,
					'completion_tokens' => 4,
				),
			)
		);

		$provider = new DeepSeek();
		$result   = $provider->generate(
			'ds-test',
			'deepseek-v4-flash',
			new Generation_Request( 'You are a writer.', 'Write about snow.', 512 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://api.deepseek.com/chat/completions', $this->captured_url() );

		$headers = $this->captured_headers();
		$this->assertSame( 'Bearer ds-test', $headers['authorization'] );

		$body = $this->captured_body();
		$this->assertSame( 'deepseek-v4-flash', $body['model'] );
		$this->assertSame( 512, $body['max_tokens'] );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertSame( 'You are a writer.', $body['messages'][0]['content'] );
		$this->assertSame( 'user', $body['messages'][1]['role'] );
		$this->assertSame( 'Write about snow.', $body['messages'][1]['content'] );

		// DeepSeek offers no grounding, so a tools array must never be sent.
		$this->assertArrayNotHasKey( 'tools', $body );

		$this->assertSame( 'Generated body.', $result->text() );
		$this->assertSame( 3, $result->usage()->input_tokens() );
		$this->assertSame( 4, $result->usage()->output_tokens() );
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

		$provider = new DeepSeek();
		$result   = $provider->generate(
			'ds-wrong',
			'deepseek-v4-flash',
			new Generation_Request( 'system', 'user', 256 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
	}

	/**
	 * Retired model names are not offered as suggestions.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_retired_model_names_are_not_suggested(): void {
		$suggested = ( new DeepSeek() )->suggested_models();

		$this->assertNotContains( 'deepseek-chat', $suggested );
		$this->assertNotContains( 'deepseek-reasoner', $suggested );
		$this->assertContains( 'deepseek-v4-flash', $suggested );
	}
}
