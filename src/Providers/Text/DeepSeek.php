<?php
/**
 * DeepSeek text provider.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Text;

use AutoScribe\Providers\Http;
use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Response\Generation_Result;
use AutoScribe\Providers\Response\Usage;
use AutoScribe\Providers\Text_Provider_Interface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the DeepSeek chat completions API.
 *
 * DeepSeek exposes an OpenAI-compatible surface, so this adapter uses the Chat
 * Completions request and response shape, including that API's prompt_tokens
 * and completion_tokens usage names. Section 2.1 establishes that DeepSeek
 * offers neither image generation nor web search grounding.
 *
 * @since 0.2.0
 */
final class DeepSeek implements Text_Provider_Interface {

	/**
	 * Chat completions endpoint.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

	/**
	 * Models endpoint, used for connection tests.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const MODELS_ENDPOINT = 'https://api.deepseek.com/models';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'deepseek';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'DeepSeek', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The older deepseek-chat and deepseek-reasoner names were retired in July
	 * 2026 and are not suggested.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array( 'deepseek-v4-flash', 'deepseek-v4-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_web_search(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * DeepSeek offers a JSON output mode but not schema enforcement, so the
	 * pipeline falls back to prompt-and-validate per section 5.1.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
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
			self::MODELS_ENDPOINT,
			$this->headers( $api_key ),
			Http::TIMEOUT_DEFAULT
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$available = array();

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$available[] = (string) $entry['id'];
				}
			}
		}

		if ( array() !== $available && ! in_array( $model, $available, true ) ) {
			return new WP_Error(
				'autoscribe_provider_model_not_found',
				sprintf(
					/* translators: %s: comma-separated list of model identifiers. */
					__( 'DeepSeek does not offer that model. Available models: %s', 'autoscribe' ),
					implode( ', ', $available )
				)
			);
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
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $request->system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => $request->user_prompt(),
				),
			),
		);

		if ( $request->wants_json() ) {
			$body['response_format'] = array( 'type' => 'json_object' );
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
	 * Converts a decoded chat completion into a result object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded Decoded response body.
	 * @param string               $model   Requested model identifier.
	 * @return Generation_Result|WP_Error
	 */
	private function to_result( array $decoded, string $model ): Generation_Result|WP_Error {
		$text = '';

		if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
			$text = (string) $decoded['choices'][0]['message']['content'];
		}

		$usage = new Usage(
			(int) ( $decoded['usage']['prompt_tokens'] ?? 0 ),
			(int) ( $decoded['usage']['completion_tokens'] ?? 0 )
		);

		/*
		 * finish_reason "length" means the completion stopped at max_tokens. The
		 * text is a fragment and comes back with the reason attached, so the
		 * caller records what it cost and stops rather than paying for a repair
		 * of something that was never finished.
		 */
		if ( isset( $decoded['choices'][0]['finish_reason'] ) && 'length' === $decoded['choices'][0]['finish_reason'] ) {
			return new Generation_Result(
				$text,
				$usage,
				isset( $decoded['model'] ) ? (string) $decoded['model'] : $model,
				array(),
				'length'
			);
		}

		if ( '' === $text ) {
			return new WP_Error(
				'autoscribe_empty_response',
				__( 'DeepSeek returned no text content.', 'autoscribe' )
			);
		}

		return new Generation_Result(
			$text,
			$usage,
			isset( $decoded['model'] ) ? (string) $decoded['model'] : $model
		);
	}
}
