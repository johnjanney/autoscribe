<?php
/**
 * OpenAI image adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Image\OpenAI_Image;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the OpenAI image request shape and its failure handling.
 *
 * @since 0.2.0
 */
final class OpenAI_ImageTest extends Provider_Test_Case {

	/**
	 * The outgoing request matches the image generations contract.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_image_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'data' => array(
					array( 'b64_json' => base64_encode( 'fake-png-bytes' ) ),
				),
			)
		);

		$provider = new OpenAI_Image();
		$result   = $provider->generate_image( 'sk-test', 'gpt-image-2', 'A cup of coffee, studio light' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://api.openai.com/v1/images/generations', $this->captured_url() );

		$headers = $this->captured_headers();
		$this->assertSame( 'Bearer sk-test', $headers['authorization'] );

		$body = $this->captured_body();
		$this->assertSame( 'gpt-image-2', $body['model'] );
		$this->assertSame( 'A cup of coffee, studio light', $body['prompt'] );
		$this->assertSame( 1, $body['n'] );

		$this->assertSame( 'fake-png-bytes', $result->bytes() );
		$this->assertNull( $result->url() );
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

		$provider = new OpenAI_Image();
		$result   = $provider->generate_image( 'sk-wrong', 'gpt-image-2', 'anything' );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
	}

	/**
	 * A URL response is carried through instead of inline bytes.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_url_response_is_supported(): void {
		$this->mock_json(
			200,
			array(
				'data' => array(
					array( 'url' => 'https://example.com/generated.png' ),
				),
			)
		);

		$result = ( new OpenAI_Image() )->generate_image( 'sk-test', 'gpt-image-2', 'anything' );

		$this->assertNotWPError( $result );
		$this->assertNull( $result->bytes() );
		$this->assertSame( 'https://example.com/generated.png', $result->url() );
	}
}
