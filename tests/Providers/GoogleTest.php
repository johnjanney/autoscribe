<?php
/**
 * Google adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Text\Google;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the Gemini Interactions request shape and its failure handling.
 *
 * @since 0.2.0
 */
final class GoogleTest extends Provider_Test_Case {

	/**
	 * The outgoing request matches the Interactions API contract.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'model' => 'gemini-3.7-flash',
				'steps' => array(
					array(
						'type'    => 'model_output',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Generated body.',
							),
						),
					),
				),
				'usage' => array(
					'total_input_tokens'  => 5,
					'total_output_tokens' => 6,
				),
			)
		);

		$provider = new Google();
		$result   = $provider->generate(
			'goog-test',
			'gemini-3.7-flash',
			new Generation_Request( 'You are a writer.', 'Write about rain.', 4096 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame(
			'https://generativelanguage.googleapis.com/v1beta/interactions',
			$this->captured_url()
		);

		$headers = $this->captured_headers();
		$this->assertSame( 'goog-test', $headers['x-goog-api-key'] );

		$body = $this->captured_body();
		$this->assertSame( 'gemini-3.7-flash', $body['model'] );
		$this->assertSame( 'Write about rain.', $body['input'] );
		$this->assertSame( 'You are a writer.', $body['system_instruction'] );
		$this->assertSame( 4096, $body['generation_config']['max_output_tokens'] );

		$this->assertSame( 'Generated body.', $result->text() );
		$this->assertSame( 5, $result->usage()->input_tokens() );
		$this->assertSame( 6, $result->usage()->output_tokens() );
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

		$provider = new Google();
		$result   = $provider->generate(
			'goog-wrong',
			'gemini-3.7-flash',
			new Generation_Request( 'system', 'user', 256 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
	}

	/**
	 * Schema and grounding are not advertised as usable together.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_schema_with_search_is_not_advertised(): void {
		$provider = new Google();

		$this->assertTrue( $provider->supports_web_search() );
		$this->assertTrue( $provider->supports_strict_json() );
		$this->assertFalse( $provider->supports_strict_json_with_search() );
	}
}
