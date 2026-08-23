<?php
/**
 * OpenAI adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Text\OpenAI;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the OpenAI Responses request shape and its failure handling.
 *
 * @since 0.2.0
 */
final class OpenAITest extends Provider_Test_Case {

	/**
	 * The outgoing request matches the Responses API contract.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'model'  => 'gpt-5.6-terra',
				'output' => array(
					array(
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => 'Generated body.',
							),
						),
					),
				),
				'usage'  => array(
					'input_tokens'  => 7,
					'output_tokens' => 9,
				),
			)
		);

		$provider = new OpenAI();
		$result   = $provider->generate(
			'sk-test',
			'gpt-5.6-terra',
			new Generation_Request( 'You are a writer.', 'Write about tea.', 2048 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://api.openai.com/v1/responses', $this->captured_url() );

		$headers = $this->captured_headers();
		$this->assertSame( 'Bearer sk-test', $headers['authorization'] );

		$body = $this->captured_body();
		$this->assertSame( 'gpt-5.6-terra', $body['model'] );
		$this->assertSame( 'You are a writer.', $body['instructions'] );
		$this->assertSame( 'Write about tea.', $body['input'] );
		$this->assertSame( 2048, $body['max_output_tokens'] );
		$this->assertFalse(
			$body['store'],
			'The Responses API stores every response by default, and this plugin never fetches one back.'
		);

		$this->assertSame( 'Generated body.', $result->text() );
		$this->assertSame( 7, $result->usage()->input_tokens() );
		$this->assertSame( 9, $result->usage()->output_tokens() );
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

		$provider = new OpenAI();
		$result   = $provider->generate(
			'sk-wrong',
			'gpt-5.6-terra',
			new Generation_Request( 'system', 'user', 256 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
	}

	/**
	 * A schema request is expressed through the text.format field.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_json_schema_is_translated(): void {
		$this->mock_json(
			200,
			array(
				'output' => array(
					array(
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => '{"ok":true}',
							),
						),
					),
				),
				'usage'  => array(
					'input_tokens'  => 1,
					'output_tokens' => 1,
				),
			)
		);

		$schema   = array( 'type' => 'object' );
		$provider = new OpenAI();
		$provider->generate(
			'sk-test',
			'gpt-5.6-terra',
			new Generation_Request( 'system', 'user', 256, $schema, true )
		);

		$body = $this->captured_body();
		$this->assertSame( 'json_schema', $body['text']['format']['type'] );
		$this->assertSame( $schema, $body['text']['format']['schema'] );
		$this->assertSame( 'web_search', $body['tools'][0]['type'] );
	}

	/**
	 * An incomplete response names the reason the Responses API gave.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_an_incomplete_response_is_reported_as_incomplete(): void {
		$this->mock_json(
			200,
			array(
				'model'              => 'gpt-5.6-terra',
				'status'             => 'incomplete',
				'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
				'output'             => array(
					array(
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => '{"title":"Half an art',
							),
						),
					),
				),
				'usage'              => array(
					'input_tokens'  => 100,
					'output_tokens' => 2048,
				),
			)
		);

		$result = ( new OpenAI() )->generate(
			'sk-test',
			'gpt-5.6-terra',
			new Generation_Request( 'system', 'user', 2048 )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result->is_incomplete() );
		$this->assertSame( 'max_output_tokens', $result->incomplete_reason() );
	}
}
