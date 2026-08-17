<?php
/**
 * Google Gemini image provider.
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
 * Talks to the Gemini image models, the Nano Banana family.
 *
 * Section 2.1 is right that Imagen must not be used: Imagen 4 was deprecated in
 * June 2026 with a shutdown date of 17 August 2026.
 *
 * Unlike the text adapter, this one uses generateContent rather than the
 * Interactions API. Google documents the inline image response shape for
 * generateContent, and generateContent remains fully supported; the Interactions
 * equivalent for image output is not documented in a form that can be
 * implemented without guessing at the wire format.
 *
 * @since 0.2.0
 */
final class Google_Image implements Image_Provider_Interface {

	/**
	 * Base URL for model-scoped endpoints.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const MODELS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'google_image';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Google Images (Nano Banana)', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'gemini-3.1-flash-image', 'gemini-3-pro-image', 'gemini-3.1-flash-lite-image' );
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
			self::MODELS_ENDPOINT . rawurlencode( $model ) . ':generateContent',
			$this->headers( $api_key ),
			array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
			),
			Http::TIMEOUT_GENERATION
		);

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$parts = $decoded['candidates'][0]['content']['parts'] ?? array();

		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) || ! isset( $part['inlineData']['data'] ) ) {
					continue;
				}

				$bytes = base64_decode( (string) $part['inlineData']['data'], true );

				if ( false === $bytes ) {
					return new WP_Error(
						'autoscribe_invalid_image',
						__( 'Google returned image data that could not be decoded.', 'autoscribe' )
					);
				}

				return new Image_Result(
					$bytes,
					null,
					isset( $part['inlineData']['mimeType'] ) ? (string) $part['inlineData']['mimeType'] : 'image/png',
					$model
				);
			}
		}

		return new WP_Error(
			'autoscribe_empty_response',
			__( 'Google returned no image.', 'autoscribe' )
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
			'x-goog-api-key' => $api_key,
		);
	}
}
