<?php
/**
 * OpenAI text provider.
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
 * Talks to the OpenAI Responses API.
 *
 * Section 14 of the brief left the endpoint choice open between /v1/responses
 * and /v1/chat/completions. Responses is used here because it is the surface
 * that carries the built-in web search tool section 7.1 depends on. Its usage
 * object also reports input_tokens and output_tokens directly, rather than the
 * prompt_tokens and completion_tokens of Chat Completions.
 *
 * @since 0.2.0
 */
final class OpenAI implements Text_Provider_Interface {

	/**
	 * Responses endpoint.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const ENDPOINT = 'https://api.openai.com/v1/responses';

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
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'OpenAI', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'gpt-5.6-terra', 'gpt-5.6-sol', 'gpt-5.6-luna' );
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
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json_with_search(): bool {
		return true;
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
		/*
		 * store is false because nothing here needs a stored response. The
		 * Responses API keeps every response by default and the plugin never
		 * fetches one back: each generation is independent, and the pipeline
		 * carries its own state in the runs table rather than in
		 * previous_response_id. Leaving the default in place would have left the
		 * prompt, the site's recent post titles, and any rejected model output
		 * sitting in provider-side storage to support a feature this plugin does
		 * not use. Confirmed against OpenAI's Responses reference on 20 August
		 * 2026: the field defaults to true, and false is the documented stateless
		 * mode.
		 */
		$body = array(
			'model'             => $model,
			'instructions'      => $request->system_prompt(),
			'input'             => $request->user_prompt(),
			'max_output_tokens' => $request->max_output_tokens(),
			'store'             => false,
		);

		if ( $request->wants_json() ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'autoscribe_article',
					'schema' => $request->json_schema(),
					'strict' => true,
				),
			);
		}

		if ( $request->wants_grounding() ) {
			$body['tools'] = array(
				array( 'type' => 'web_search' ),
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
			'authorization' => 'Bearer ' . $api_key,
		);
	}

	/**
	 * Converts a decoded Responses payload into a result object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded Decoded response body.
	 * @param string               $model   Requested model identifier.
	 * @return Generation_Result|WP_Error
	 */
	private function to_result( array $decoded, string $model ): Generation_Result|WP_Error {
		$text = '';

		if ( isset( $decoded['output'] ) && is_array( $decoded['output'] ) ) {
			foreach ( $decoded['output'] as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}

				foreach ( $item['content'] as $block ) {
					if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'output_text' === $block['type'] ) {
						$text .= (string) $block['text'];
					}
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error(
				'autoscribe_empty_response',
				__( 'OpenAI returned no text content.', 'autoscribe' )
			);
		}

		return new Generation_Result(
			$text,
			new Usage(
				(int) ( $decoded['usage']['input_tokens'] ?? 0 ),
				(int) ( $decoded['usage']['output_tokens'] ?? 0 )
			),
			isset( $decoded['model'] ) ? (string) $decoded['model'] : $model,
			Source_Extractor::from( $decoded )
		);
	}
}
