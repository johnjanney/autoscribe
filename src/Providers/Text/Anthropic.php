<?php
/**
 * Anthropic text provider.
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
 * Talks to the Anthropic Messages API.
 *
 * Anthropic's current models reject temperature, top_p, top_k, and
 * budget_tokens with an HTTP 400, and reject assistant-turn prefill. None of
 * those are sent. Depth is controlled through output_config.effort instead.
 *
 * @since 0.2.0
 */
final class Anthropic implements Text_Provider_Interface {

	/**
	 * Messages endpoint.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Models endpoint, used for connection tests.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models/';

	/**
	 * Wire version pinned by the Anthropic API.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const API_VERSION = '2023-06-01';

	/**
	 * Server-side web search tool type.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const WEB_SEARCH_TOOL = 'web_search_20260209';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Anthropic (Claude)', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verified against Anthropic's model documentation. These identifiers carry
	 * no date suffix; appending one produces a 404.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5' );
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
		$body = array(
			'model'      => $model,
			'max_tokens' => $request->max_output_tokens(),
			'system'     => $request->system_prompt(),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $request->user_prompt(),
				),
			),
		);

		if ( $request->wants_json() ) {
			$body['output_config'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $request->json_schema(),
				),
			);
		}

		if ( $request->wants_grounding() ) {
			$body['tools'] = array(
				array(
					'type' => self::WEB_SEARCH_TOOL,
					'name' => 'web_search',
				),
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
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * Converts a decoded Messages response into a result object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded Decoded response body.
	 * @param string               $model   Requested model identifier.
	 * @return Generation_Result|WP_Error
	 */
	private function to_result( array $decoded, string $model ): Generation_Result|WP_Error {
		if ( isset( $decoded['stop_reason'] ) && 'refusal' === $decoded['stop_reason'] ) {
			return new WP_Error(
				'autoscribe_provider_refusal',
				__( 'Anthropic declined to answer this prompt.', 'autoscribe' )
			);
		}

		$text = '';

		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= (string) $block['text'];
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error(
				'autoscribe_empty_response',
				__( 'Anthropic returned no text content.', 'autoscribe' )
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
