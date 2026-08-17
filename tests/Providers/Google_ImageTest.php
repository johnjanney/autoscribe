<?php
/**
 * Google image adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Image\Google_Image;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the Gemini image request shape and its failure handling.
 *
 * @since 0.2.0
 */
final class Google_ImageTest extends Provider_Test_Case {

	/**
	 * The outgoing request targets the model-scoped generateContent endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_image_sends_expected_request_shape(): void {
		$this->mock_json(
			200,
			array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'inlineData' => array(
										'mimeType' => 'image/png',
										'data'     => base64_encode( 'fake-png-bytes' ),
									),
								),
							),
						),
					),
				),
			)
		);

		$provider = new Google_Image();
		$result   = $provider->generate_image( 'goog-test', 'gemini-3.1-flash-image', 'A rainy street' );

		$this->assertNotWPError( $result );
		$this->assertSame(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent',
			$this->captured_url()
		);

		$headers = $this->captured_headers();
		$this->assertSame( 'goog-test', $headers['x-goog-api-key'] );

		$body = $this->captured_body();
		$this->assertSame( 'A rainy street', $body['contents'][0]['parts'][0]['text'] );

		$this->assertSame( 'fake-png-bytes', $result->bytes() );
		$this->assertSame( 'image/png', $result->mime_type() );
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

		$provider = new Google_Image();
		$result   = $provider->generate_image( 'goog-wrong', 'gemini-3.1-flash-image', 'anything' );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_provider_auth', $result->get_error_code() );
	}

	/**
	 * No Imagen identifier is suggested, since Imagen 4 is being shut down.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_imagen_models_are_not_suggested(): void {
		foreach ( ( new Google_Image() )->suggested_models() as $model ) {
			$this->assertStringNotContainsString( 'imagen', $model );
		}
	}
}
