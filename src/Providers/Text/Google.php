<?php
/**
 * Google Gemini text provider.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Text;

use AutoScribe\Providers\Http;
use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Response\Generation_Result;
use AutoScribe\Providers\Response\Source_Extractor;
use AutoScribe\Providers\Response\Usage;
use AutoScribe\Providers\Text_Provider_Interface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Gemini Interactions API.
 *
 * Section 14 of the brief asked which Google surface to target. Google's own
 * documentation states the Interactions API became generally available in June
 * 2026 and is recommended for all new projects, and that the older
 * generateContent API is now considered legacy while remaining supported. This
 * adapter therefore targets /v1beta/interactions rather than generateContent.
 *
 * @since 0.2.0
 */
final class Google implements Text_Provider_Interface {

	/**
	 * Interactions endpoint.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Models endpoint, used for connection tests.
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
		return 'google';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Google (Gemini)', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only generally available identifiers are suggested. The Pro tier is
	 * preview-only at the time of writing, and section 2.2 warns against
	 * defaulting to a string that can disappear without notice.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_web_search(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Google historically rejected schema-constrained output combined with
	 * search grounding, and reports persist of grounding metadata coming back
	 * empty when both are requested. Treated as unsupported until section 7.1
	 * verifies it, so the pipeline degrades to prompt-and-validate rather than
	 * silently losing the source URLs it is supposed to record.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json_with_search(): bool {
		return false;
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
	 * @param string             $api_key Provider API key.
	 * @param string             $model   Model identifier.
	 * @param Generation_Request $request What to generate.
	 * @return Generation_Result|WP_Error
	 */
	public function generate( string $api_key, string $model, Generation_Request $request ): Generation_Result|WP_Error {
		$body = array(
			'model'              => $model,
			'input'              => $request->user_prompt(),
			'system_instruction' => $request->system_prompt(),
			'generation_config'  => array(
				'max_output_tokens' => $request->max_output_tokens(),
			),
		);

		/*
		 * Structured output is a top-level response_format object on the
		 * Interactions API, not the generateContent pair of
		 * generation_config.response_mime_type and response_schema. Google removed
		 * those two fields when Interactions replaced generateContent; sending
		 * them here is either rejected outright or silently ignored, and a
		 * silently ignored schema is worse — the plugin would believe output was
		 * provider-enforced when it was not.
		 */
		if ( $request->wants_json() ) {
			$body['response_format'] = array(
				'type'      => 'text',
				'mime_type' => 'application/json',
				'schema'    => $request->json_schema(),
			);
		}

		if ( $request->wants_grounding() ) {
			$body['tools'] = array(
				array( 'type' => 'google_search' ),
			);
		}

		$decoded = Http::post_json(
			self::ENDPOINT,
			$this->headers( $api_key ),
			$body,
			Http::TIMEOUT_GENERATION
		);

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return $this->to_result( $decoded, $model );
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

	/**
	 * Converts a decoded Interactions payload into a result object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded Decoded response body.
	 * @param string               $model   Requested model identifier.
	 * @return Generation_Result|WP_Error
	 */
	private function to_result( array $decoded, string $model ): Generation_Result|WP_Error {
		$text = '';

		if ( isset( $decoded['steps'] ) && is_array( $decoded['steps'] ) ) {
			foreach ( $decoded['steps'] as $step ) {
				if ( ! is_array( $step ) || ! isset( $step['content'] ) || ! is_array( $step['content'] ) ) {
					continue;
				}

				foreach ( $step['content'] as $block ) {
					if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
						$text .= (string) $block['text'];
					}
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error(
				'autoscribe_empty_response',
				__( 'Google returned no text content.', 'autoscribe' )
			);
		}

		return new Generation_Result(
			$text,
			new Usage(
				(int) ( $decoded['usage']['total_input_tokens'] ?? 0 ),
				(int) ( $decoded['usage']['total_output_tokens'] ?? 0 )
			),
			isset( $decoded['model'] ) ? (string) $decoded['model'] : $model,
			Source_Extractor::from( $decoded )
		);
	}
}
