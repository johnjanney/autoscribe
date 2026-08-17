<?php
/**
 * OpenAI image provider.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Image;

use AutoScribe\Providers\Http;
use AutoScribe\Providers\Image_Provider_Interface;
use AutoScribe\Providers\Response\Image_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the OpenAI image generations API.
 *
 * @since 0.2.0
 */
final class OpenAI_Image implements Image_Provider_Interface {

	/**
	 * Image generations endpoint.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const ENDPOINT = 'https://api.openai.com/v1/images/generations';

	/**
	 * Models endpoint, used for connection tests.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const MODELS_ENDPOINT = 'https://api.openai.com/v1/models/';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'openai_image';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'OpenAI Images', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'gpt-image-2', 'gpt-image-1.5', 'gpt-image-1-mini' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model identifier to check.
	 * @return bool|WP_Error
	 */
	public function test_connection( string $api_key, string $model ): bool|WP_Error {
		$result = Http::get_json(
			self::MODELS_ENDPOINT . rawurlencode( $model ),
			$this->headers( $api_key ),
			Http::TIMEOUT_DEFAULT
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model identifier.
	 * @param string $prompt  Fully assembled image prompt.
	 * @return Image_Result|WP_Error
	 */
	public function generate_image( string $api_key, string $model, string $prompt ): Image_Result|WP_Error {
		$decoded = Http::post_json(
			self::ENDPOINT,
			$this->headers( $api_key ),
			array(
				'model'  => $model,
				'prompt' => $prompt,
				'n'      => 1,
			),
			Http::TIMEOUT_GENERATION
		);

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( isset( $decoded['data'][0]['b64_json'] ) ) {
			$bytes = base64_decode( (string) $decoded['data'][0]['b64_json'], true );

			if ( false === $bytes ) {
				return new WP_Error(
					'autoscribe_invalid_image',
					__( 'OpenAI returned image data that could not be decoded.', 'autoscribe' )
				);
			}

			return new Image_Result( $bytes, null, 'image/png', $model );
		}

		if ( isset( $decoded['data'][0]['url'] ) ) {
			return new Image_Result( null, (string) $decoded['data'][0]['url'], 'image/png', $model );
		}

		return new WP_Error(
			'autoscribe_empty_response',
			__( 'OpenAI returned no image.', 'autoscribe' )
		);
	}

	/**
	 * Builds the request headers.
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Provider API key.
	 * @return array<string, string>
	 */
	private function headers( string $api_key ): array {
		return array(
			'authorization' => 'Bearer ' . $api_key,
		);
	}
}
