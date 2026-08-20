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
	 * Structured output goes in a top-level response_format object.
	 *
	 * The Interactions API removed generateContent's
	 * generation_config.response_mime_type and response_schema pair. The adapter
	 * sent that pair for the first release and nothing noticed, because no test
	 * asserted anything about the structured-output fields at all — which is the
	 * gap this test exists to close, as much as the shape itself.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_structured_output_uses_top_level_response_format(): void {
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
								'text' => '{"title":"A"}',
							),
						),
					),
				),
				'usage' => array(
					'total_input_tokens'  => 1,
					'total_output_tokens' => 1,
				),
			)
		);

		$schema = array(
			'type'       => 'object',
			'properties' => array( 'title' => array( 'type' => 'string' ) ),
			'required'   => array( 'title' ),
		);

		( new Google() )->generate(
			'goog-test',
			'gemini-3.7-flash',
			new Generation_Request( 'system', 'user', 512, $schema )
		);

		$body = $this->captured_body();

		$this->assertArrayHasKey( 'response_format', $body );
		$this->assertSame( 'text', $body['response_format']['type'] );
		$this->assertSame( 'application/json', $body['response_format']['mime_type'] );
		$this->assertSame( $schema, $body['response_format']['schema'] );

		$this->assertArrayNotHasKey(
			'response_mime_type',
			$body['generation_config'],
			'response_mime_type was removed from this API and must not be sent'
		);
		$this->assertArrayNotHasKey( 'response_schema', $body['generation_config'] );
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
	/**
	 * The suggestion a blank configuration falls through to is the documented one.
	 *
	 * A prompt with no model and no site default resolves to the first suggestion,
	 * so that string is the plugin's real default however much section 2.2 says
	 * model IDs are configuration. Naming it in a test means changing it is a
	 * deliberate act with the adapter's recorded catalog date beside it, rather
	 * than a reordering nobody notices.
	 *
	 * The check is offline by design: it asserts what this build claims, not what
	 * Google currently serves. Confirming the claim against the catalog is a
	 * release step, because a test that calls a provider is a test that fails when
	 * a network does.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_the_default_suggestion_is_the_documented_stable_model(): void {
		$suggestions = ( new Google() )->suggested_models();

		$this->assertSame(
			'gemini-3.7-flash',
			$suggestions[0],
			'Verified against ai.google.dev/gemini-api/docs/models on 19 August 2026.'
		);
		$this->assertContains(
			'gemini-3.6-flash',
			$suggestions,
			'The previous stable release stays reachable for a site that pins it.'
		);
	}
}
