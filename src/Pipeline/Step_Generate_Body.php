<?php
/**
 * Body generation step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Security\Key_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the text provider for the article and validates the payload.
 *
 * Section 5.1 allows exactly one repair attempt: on a validation failure a
 * single follow-up request is sent that names the decode error, and a second
 * failure ends the run. Retrying further would spend money on a response that
 * has already failed twice.
 *
 * @since 0.3.0
 */
final class Step_Generate_Body {

	/**
	 * Provider registry.
	 *
	 * @since 0.3.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $registry;

	/**
	 * Payload validator.
	 *
	 * @since 0.3.0
	 * @var Article_Validator
	 */
	private Article_Validator $validator;

	/**
	 * Builds the step.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry $registry  Provider registry.
	 * @param Article_Validator $validator Payload validator.
	 */
	public function __construct( Provider_Registry $registry, Article_Validator $validator ) {
		$this->registry  = $registry;
		$this->validator = $validator;
	}

	/**
	 * Generates and validates the article.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return Article|WP_Error
	 */
	public function run( Prompt $prompt, Run $run ): Article|WP_Error {
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

		$grounding = $prompt->grounding_enabled() && $provider->supports_web_search();
		$schema    = $provider->supports_strict_json() ? Article_Validator::schema() : null;

		// Section 7.1 grounding and schema-constrained output are not usable
		// together on every provider; fall back to prompt-and-validate.
		if ( null !== $schema && $grounding && ! $provider->supports_strict_json_with_search() ) {
			$schema = null;
		}

		$request = new Generation_Request(
			$this->system_prompt( $prompt, null === $schema ),
			$prompt->user_prompt(),
			$this->max_output_tokens( $prompt ),
			$schema,
			$grounding
		);

		$result = $provider->generate( $api_key, $model, $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$run->record_text_usage( $result->model(), $result->usage()->input_tokens(), $result->usage()->output_tokens() );

		$article = $this->validator->validate( $result->text() );

		if ( ! is_wp_error( $article ) ) {
			return $article;
		}

		// The single repair attempt permitted by section 5.1.
		$repair = new Generation_Request(
			$this->system_prompt( $prompt, true ),
			$this->repair_prompt( $prompt, $result->text(), $article->get_error_message() ),
			$this->max_output_tokens( $prompt ),
			$schema,
			false
		);

		$second = $provider->generate( $api_key, $model, $repair );

		if ( is_wp_error( $second ) ) {
			return $second;
		}

		$run->record_text_usage(
			$second->model(),
			$result->usage()->input_tokens() + $second->usage()->input_tokens(),
			$result->usage()->output_tokens() + $second->usage()->output_tokens()
		);

		return $this->validator->validate( $second->text() );
	}

	/**
	 * Builds the system prompt, adding the JSON contract when unenforced.
	 *
	 * @since 0.3.0
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
			$system . "\n\n" . sprintf(
				/* translators: %s: JSON schema describing the required response. */
				__( 'Return one JSON object and nothing else. No prose, no Markdown fences. It must satisfy this JSON Schema: %s', 'autoscribe' ),
				(string) wp_json_encode( Article_Validator::schema() )
			)
		);
	}

	/**
	 * Builds the repair request body.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt $prompt   Prompt being run.
	 * @param string $previous The response that failed validation.
	 * @param string $problem  Why it failed.
	 * @return string
	 */
	private function repair_prompt( Prompt $prompt, string $previous, string $problem ): string {
		return sprintf(
			/* translators: 1: original instruction, 2: previous response, 3: validation error. */
			__( "Original request:\n%1\$s\n\nYour previous response could not be used:\n%2\$s\n\nThe problem was: %3\$s\n\nReturn a corrected JSON object and nothing else.", 'autoscribe' ),
			$prompt->user_prompt(),
			mb_substr( $previous, 0, 2000 ),
			$problem
		);
	}

	/**
	 * Converts the prompt's target word count into a token ceiling.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @return int
	 */
	private function max_output_tokens( Prompt $prompt ): int {
		return max( 1024, $prompt->target_word_count() * 3 );
	}
}
