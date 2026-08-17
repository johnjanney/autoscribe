<?php
/**
 * Topic proposal step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Security\Key_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the model for a title and topic key, then checks it is not a repeat.
 *
 * Section 7.2 puts this before body generation deliberately: the proposal call
 * is small and cheap, so a duplicate is caught before any money is spent
 * writing an article that would be thrown away.
 *
 * One re-ask is allowed, naming the collision explicitly. A second collision
 * ends the run as skipped_duplicate rather than continuing to pay for
 * proposals.
 *
 * @since 0.5.0
 */
final class Step_Propose_Topic {

	/**
	 * Provider registry.
	 *
	 * @since 0.5.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $registry;

	/**
	 * Duplicate detector.
	 *
	 * @since 0.5.0
	 * @var Topic_Deduplicator
	 */
	private Topic_Deduplicator $deduplicator;

	/**
	 * Builds the step.
	 *
	 * @since 0.5.0
	 *
	 * @param Provider_Registry  $registry     Provider registry.
	 * @param Topic_Deduplicator $deduplicator Duplicate detector.
	 */
	public function __construct( Provider_Registry $registry, Topic_Deduplicator $deduplicator ) {
		$this->registry     = $registry;
		$this->deduplicator = $deduplicator;
	}

	/**
	 * Proposes a topic that has not been covered.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return array{title: string, topic_key: string}|WP_Error
	 */
	public function run( Prompt $prompt, Run $run ): array|WP_Error {
		$provider = $this->registry->text_provider( $prompt->text_provider() );

		if ( null === $provider ) {
			return new WP_Error(
				'autoscribe_unknown_provider',
				sprintf(
					/* translators: %s: provider slug. */
					__( 'No text provider is registered under the slug %s.', 'autoscribe' ),
					$prompt->text_provider()
				)
			);
		}

		$api_key = Key_Store::get( $prompt->text_provider() );

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$model = '' !== $prompt->text_model() ? $prompt->text_model() : ( $provider->suggested_models()[0] ?? '' );

		if ( '' === $model ) {
			return new WP_Error(
				'autoscribe_missing_model',
				__( 'No model ID is configured for this prompt.', 'autoscribe' )
			);
		}

		$existing = $this->deduplicator->recent_topics(
			$prompt->post_type(),
			$prompt->category_ids(),
			$prompt->dedupe_lookback()
		);

		$schema = $provider->supports_strict_json() ? $this->schema() : null;
		$extra  = '';

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$request = new Generation_Request(
				$this->system_prompt( $prompt, null === $schema ),
				$this->user_prompt( $prompt, $existing, $extra ),
				512,
				$schema,
				false
			);

			$result = $provider->generate( $api_key, $model, $request );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$run->record_text_usage( $result->model(), $result->usage()->input_tokens(), $result->usage()->output_tokens() );

			$proposal = $this->decode( $result->text() );

			if ( is_wp_error( $proposal ) ) {
				return $proposal;
			}

			$reason = $this->deduplicator->collision_reason(
				$proposal['topic_key'],
				$proposal['title'],
				$existing,
				$prompt->post_type()
			);

			if ( null === $reason ) {
				return $proposal;
			}

			$extra = sprintf(
				/* translators: %s: the reason the previous proposal collided. */
				__( 'Your previous proposal was rejected: %s Propose a genuinely different topic.', 'autoscribe' ),
				$reason
			);
		}

		$run->skip( Run::STATUS_SKIPPED_DUPLICATE, $extra );

		return new WP_Error( 'autoscribe_duplicate_topic', $extra );
	}

	/**
	 * Returns the two-field schema for the proposal call.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'     => array( 'type' => 'string' ),
				'topic_key' => array( 'type' => 'string' ),
			),
			'required'             => array( 'title', 'topic_key' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the system prompt for the proposal call.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt $prompt          Prompt being run.
	 * @param bool   $describe_schema Whether to spell the contract out in prose.
	 * @return string
	 */
	private function system_prompt( Prompt $prompt, bool $describe_schema ): string {
		$system = $prompt->system_prompt();

		if ( ! $describe_schema ) {
			return $system;
		}

		return trim(
			$system . "\n\n" . __( 'Return one JSON object with exactly two string fields, title and topic_key, and nothing else. topic_key must be a lowercase hyphenated slug.', 'autoscribe' )
		);
	}

	/**
	 * Builds the user prompt, including the already-covered list.
	 *
	 * Section 7.2 injects this list so the model steers away from repeats before
	 * the deterministic check has to reject anything.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt                $prompt   Prompt being run.
	 * @param array<string, string> $existing Already-covered topics.
	 * @param string                $extra    Extra instruction after a collision.
	 * @return string
	 */
	private function user_prompt( Prompt $prompt, array $existing, string $extra ): string {
		$parts = array( $prompt->user_prompt() );

		if ( array() !== $existing ) {
			$lines = array();

			foreach ( $existing as $key => $title ) {
				$lines[] = sprintf( '- %s (%s)', $title, $key );
			}

			$parts[] = __( 'Already covered — propose something different:', 'autoscribe' ) . "\n" . implode( "\n", $lines );
		}

		if ( '' !== $extra ) {
			$parts[] = $extra;
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Decodes the proposal response.
	 *
	 * @since 0.5.0
	 *
	 * @param string $raw Raw provider text.
	 * @return array{title: string, topic_key: string}|WP_Error
	 */
	private function decode( string $raw ): array|WP_Error {
		$text = trim( $raw );

		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $text, $matches ) ) {
			$text = trim( $matches[1] );
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return new WP_Error(
				'autoscribe_invalid_json',
				__( 'The topic proposal was not JSON.', 'autoscribe' )
			);
		}

		$decoded = json_decode( substr( $text, $start, ( $end - $start ) + 1 ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['title'], $decoded['topic_key'] ) ) {
			return new WP_Error(
				'autoscribe_missing_fields',
				__( 'The topic proposal was missing title or topic_key.', 'autoscribe' )
			);
		}

		return array(
			'title'     => sanitize_text_field( (string) $decoded['title'] ),
			'topic_key' => sanitize_title( (string) $decoded['topic_key'] ),
		);
	}
}
