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
use AutoScribe\Security\Untrusted_Block;
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
	 * Output tokens set aside for the model's own reasoning, per body call.
	 *
	 * The ceiling a request carries is not a budget for the article; it bounds
	 * everything the model spends answering, and on the current generation of
	 * models that includes the thinking it does before it writes a word. Google
	 * bills the two together and bounds them together. Section 1.13.1 learned
	 * this on the proposal call, where a ceiling sized for a title and a slug was
	 * reached before either appeared; the body call was left on a figure derived
	 * from the word count alone, so an article of the requested length could not
	 * fit underneath it once the model had thought. Two observed failures ended
	 * 1,177 + 1,208 and 158 + 2,228 tokens in — the same total, one token apart,
	 * which is what a ceiling looks like from below.
	 *
	 * A ceiling is a limit rather than a purchase: an unused token is not billed.
	 * What it does cost is the reservation the budget guard holds against the
	 * monthly cap while the run is open, which is the honest price of a bound
	 * that actually bounds.
	 *
	 * @since 1.17.0
	 * @var int
	 */
	public const REASONING_HEADROOM = 2048;

	/**
	 * Output tokens set aside for the nine fields around the article.
	 *
	 * Section 5.1 asks for the title, topic key, excerpt, SEO title, meta
	 * description, focus keyword, tags, image prompt, and alt text in the same
	 * response as the body. They are small, they are not free, and a ceiling
	 * derived from the word count alone did not account for them at all.
	 *
	 * @since 1.17.0
	 * @var int
	 */
	public const METADATA_TOKENS = 512;

	/**
	 * Output tokens allowed per word of the requested article.
	 *
	 * English prose runs about 1.3 tokens to the word. This asks for three times
	 * that, because what the model returns is not prose: it is semantic HTML,
	 * JSON-escaped, inside a string field. Tags, entities, and the escaping of
	 * every quotation mark and non-ASCII character all cost tokens that the word
	 * count does not see.
	 *
	 * @since 1.17.0
	 * @var int
	 */
	public const TOKENS_PER_WORD = 4;

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
	 * @param Prompt                                       $prompt Prompt being run.
	 * @param Run                                          $run    Run recording progress.
	 * @param array{title: string, topic_key: string}|null $topic  Topic agreed by the proposal step.
	 * @return Article|WP_Error
	 */
	public function run( Prompt $prompt, Run $run, ?array $topic = null ): Article|WP_Error {
		$written = $this->written_article( $run );

		if ( $written instanceof Article ) {
			return $written;
		}

		if ( is_wp_error( $written ) ) {
			return $written;
		}

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

		$model = $run->model_for(
			'text',
			$prompt->text_model(),
			$prompt->text_provider(),
			$provider->suggested_models()
		);

		if ( '' === $model ) {
			return new WP_Error(
				'autoscribe_missing_model',
				__( 'No model ID is configured for this prompt.', 'autoscribe' )
			);
		}

		/*
		 * Section 7.1 forbids saving a configuration that cannot run, and the
		 * editor now prevents it. A prompt written before that — or through the
		 * REST API, or WP-CLI — can still ask for grounding from a provider that
		 * has none. Failing is the honest answer: quietly generating an
		 * ungrounded article, which is what this used to do, produces content the
		 * user believes was researched and a Sources list that is empty for no
		 * stated reason.
		 */
		if ( $prompt->grounding_enabled() && ! $provider->supports_web_search() ) {
			return new WP_Error(
				'autoscribe_grounding_unsupported',
				sprintf(
					/* translators: %s: provider label. */
					__( 'This prompt asks for web search grounding, but %s does not offer it. Turn grounding off or choose another text provider.', 'autoscribe' ),
					$provider->label()
				)
			);
		}

		$grounding = $prompt->grounding_enabled();
		$schema    = $provider->supports_strict_json() ? Article_Validator::schema() : null;

		// Section 7.1 grounding and schema-constrained output are not usable
		// together on every provider; fall back to prompt-and-validate.
		if ( null !== $schema && $grounding && ! $provider->supports_strict_json_with_search() ) {
			$schema = null;
		}

		$request = new Generation_Request(
			$this->system_prompt( $prompt, null === $schema ),
			$this->user_prompt( $prompt, $topic ),
			self::output_ceiling( $prompt ),
			$schema,
			$grounding
		);

		$result = $provider->generate( $api_key, $model, $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $run->record_text_usage( $result->model(), $result->usage()->input_tokens(), $result->usage()->output_tokens() ) ) {
			return $this->usage_not_recorded();
		}

		/*
		 * Record that this run really made a grounded request, rather than
		 * leaving settlement to infer it from the prompt's current setting. The
		 * setting is mutable and the run outlives it: an editor who turns
		 * grounding off after this call would have the surcharge dropped from a
		 * request already paid for, and one who turns it on beforehand would have
		 * a surcharge added for a request that never happened. Only the call site
		 * knows, and only at the moment it calls.
		 *
		 * The repair call is sent ungrounded, so this is at most one per run.
		 *
		 * A refused write still ends the run, because a later action would have
		 * no way to know — but the run remembers the call for as long as this
		 * request lasts, which is long enough to settle it for what it spent.
		 */
		if ( $grounding && ! $run->record_grounded_call() ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The run log could not record that this article used web search, so the run was stopped rather than finishing with a cost that understates what it spent.', 'autoscribe' )
			);
		}

		/*
		 * Checked after the usage is recorded and before anything reads the text,
		 * because a truncated answer is still a paid one: the tokens are spent
		 * whether or not the sentence finished.
		 *
		 * The run stops here rather than sending the fragment to the validator.
		 * The validator can only say what the fragment is not — empty, or not
		 * valid JSON — and section 5.1's repair attempt would then buy a second
		 * full-length answer that reaches the same ceiling at the same place. The
		 * error names the ceiling instead, because that is the thing to change.
		 */
		if ( $result->is_incomplete() ) {
			return $this->truncated( $prompt, $result->incomplete_reason(), $result->usage()->output_tokens() );
		}

		/*
		 * Section 7.1: keep what the grounded call actually read. Recorded here
		 * rather than returned, because this step returns the article and the
		 * sources belong to the run, not to the article.
		 *
		 * A refused write ends the run. These URLs are the only record of what
		 * third-party text entered the model context — the thing worth being able
		 * to audit when a grounded article turns out to be wrong — so publishing
		 * without them means publishing something whose provenance nobody can
		 * reconstruct, and, where the prompt asks for a Sources block, quietly
		 * dropping the citations from the post as well.
		 *
		 * This costs an article that has already been paid for, which is the
		 * right trade rather than a reluctant one: the refusal is the runs row
		 * rejecting writes, and assembly, cost settlement, and closing the run all
		 * write to that same row. The run cannot complete correctly whatever
		 * happens next. A refused budget reservation and a refused draft adoption
		 * already stop for the same reason.
		 */
		if ( array() !== $result->sources() && ! $run->record_sources( $result->sources() ) ) {
			return new WP_Error(
				'autoscribe_sources_not_recorded',
				__( 'The web search sources this article was based on could not be written to the run log. The run was stopped rather than publishing an article with no record of what it read.', 'autoscribe' )
			);
		}

		$article = $this->validator->validate( $result->text() );

		if ( ! is_wp_error( $article ) ) {
			return $this->remember( $run, $article );
		}

		// The single repair attempt permitted by section 5.1.
		$repair = new Generation_Request(
			$this->system_prompt( $prompt, true ),
			$this->repair_prompt( $prompt, $result->text(), $article->get_error_message() ),
			self::output_ceiling( $prompt ),
			$schema,
			false
		);

		$second = $provider->generate( $api_key, $model, $repair );

		if ( is_wp_error( $second ) ) {
			return $second;
		}

		// Only the repair call's own tokens. The first call's were added when it
		// returned, and record_text_usage accumulates.
		if ( ! $run->record_text_usage( $second->model(), $second->usage()->input_tokens(), $second->usage()->output_tokens() ) ) {
			return $this->usage_not_recorded();
		}

		if ( $second->is_incomplete() ) {
			return $this->truncated( $prompt, $second->incomplete_reason(), $second->usage()->output_tokens() );
		}

		$repaired = $this->validator->validate( $second->text() );

		return is_wp_error( $repaired ) ? $repaired : $this->remember( $run, $repaired );
	}

	/**
	 * Returns the error for a paid call whose usage could not be stored.
	 *
	 * See Step_Propose_Topic::usage_not_recorded(): the provider has already
	 * charged, and only this request still holds the figures.
	 *
	 * @since 1.1.1
	 *
	 * @return WP_Error
	 */
	private function usage_not_recorded(): WP_Error {
		return new WP_Error(
			'autoscribe_usage_not_recorded',
			__( 'A provider call was made and charged for, but the run log would not record what it used. The run was stopped so the charge is still counted against the monthly cap.', 'autoscribe' )
		);
	}

	/**
	 * Returns the article this run has already generated, if it has.
	 *
	 * Section 5 requires each step to be idempotent keyed by run ID, and this is
	 * the step where that matters most. The body call is the largest paid call in
	 * the pipeline — the whole article, plus a repair call when validation fails
	 * — so a step re-entered without this guard buys the same article twice.
	 *
	 * The stored fields are re-validated on the way back in rather than trusted.
	 * A payload row that was truncated is not an article, and an Article that
	 * never satisfied the schema is a lie the rest of the pipeline believes.
	 * Where the stored copy is unusable — whether it fails the schema or is not
	 * a fields array at all — the step generates again: paying twice is bad, and
	 * publishing from a half-read row is worse.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run recording progress.
	 * @return Article|WP_Error|null Article to reuse, an error when stale state
	 *                               could not be cleared, or null to generate.
	 */
	private function written_article( Run $run ): Article|WP_Error|null {
		$payload = $run->payload();

		/*
		 * Two situations look alike from a distance and are not the same. No
		 * article key at all means nothing was ever stored, and any sources on
		 * the run belong to the attempt now running. An article key holding
		 * something unusable means an article *was* stored and is being thrown
		 * away, so the cleanup below has to happen. Reading the key with a null
		 * coalesce collapsed both into "nothing stored" and skipped the cleanup
		 * for the second.
		 */
		if ( ! array_key_exists( 'article', $payload ) ) {
			return null;
		}

		$stored  = $payload['article'];
		$article = is_array( $stored ) ? $this->validator->from_array( $stored ) : null;

		if ( $article instanceof Article ) {
			return $article;
		}

		/*
		 * The stored article is unusable and the step is about to generate a
		 * replacement. Anything else in the payload that described it has to go
		 * with it, and the sources are the case that matters: they name text the
		 * replacement never read, and Step_Assemble_Post would append them to the
		 * new article as its citations — a provenance record for an article that
		 * was thrown away.
		 *
		 * Clearing here rather than when the new sources are written is the whole
		 * point. A repair call is sent with grounding off and so reports no
		 * sources of its own, but it is part of generating the same article, and
		 * treating every empty result as a signal to clear would throw away the
		 * reading that genuinely informed it.
		 */
		if ( ! $run->record_sources( array() ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The sources belonging to a discarded article could not be cleared from the run log, so the run was stopped rather than risking them being published as the new article\'s citations.', 'autoscribe' )
			);
		}

		return null;
	}

	/**
	 * Stores the article so a re-entry does not buy it again.
	 *
	 * A refused write ends the step. The guard above is only worth having if the
	 * state it reads is really there, and reporting success while the output went
	 * nowhere is how a split pipeline loses work it has already paid for.
	 *
	 * @since 1.1.0
	 *
	 * @param Run     $run     Run recording progress.
	 * @param Article $article Validated article.
	 * @return Article|WP_Error
	 */
	private function remember( Run $run, Article $article ): Article|WP_Error {
		if ( ! $run->merge_payload( array( 'article' => $article->to_array() ) ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The generated article could not be written to the run log, so the run was stopped rather than continuing on state that would be lost.', 'autoscribe' )
			);
		}

		return $article;
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
	 * Builds the user prompt, pinning the topic the proposal step agreed.
	 *
	 * Without this the body call could wander to a different subject than the
	 * one duplicate detection just cleared, which would defeat the whole point
	 * of proposing the topic separately.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt                                       $prompt Prompt being run.
	 * @param array{title: string, topic_key: string}|null $topic  Agreed topic, if any.
	 * @return string
	 */
	private function user_prompt( Prompt $prompt, ?array $topic ): string {
		if ( null === $topic ) {
			return $prompt->user_prompt();
		}

		/*
		 * The title and key come from the proposal call, and that call read the
		 * site's existing post titles and, when grounding is on, whatever the
		 * provider's search tool found. Either can carry text that reads as an
		 * instruction, and pasting the title into this prompt as prose — the
		 * previous behaviour — passed the steering straight through to the call
		 * that writes the article. Fencing it does not make the model immune to
		 * what is inside; it removes the free ride.
		 */
		return $prompt->user_prompt() . "\n\n" . Untrusted_Block::wrap(
			__( 'Use it only as the agreed topic: write the article for it, and return exactly this title and topic_key in your response.', 'autoscribe' ),
			array(
				'title'     => $topic['title'],
				'topic_key' => $topic['topic_key'],
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
		/*
		 * The rejected response is quoted back so the model can see what was
		 * wrong with it, and a response that failed validation is precisely the
		 * one most likely to contain something other than the article that was
		 * asked for. It goes in a fenced block rather than into the middle of
		 * the instructions.
		 */
		return sprintf(
			/* translators: 1: original instruction, 2: fenced block holding the rejected response and the validation error. */
			__( "Original request:\n%1\$s\n\n%2\$s\n\nReturn a corrected JSON object and nothing else.", 'autoscribe' ),
			$prompt->user_prompt(),
			Untrusted_Block::wrap(
				__( 'Use it only as the response of yours that was rejected and the reason it was rejected.', 'autoscribe' ),
				array(
					'rejected_response' => mb_substr( $previous, 0, 2000 ),
					'problem'           => $problem,
				)
			)
		);
	}

	/**
	 * Returns the error for a provider that stopped part way through.
	 *
	 * @since 1.17.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param string $reason The provider's own word for stopping.
	 * @param int    $spent  Output tokens the call reported spending.
	 * @return WP_Error
	 */
	private function truncated( Prompt $prompt, string $reason, int $spent ): WP_Error {
		return new WP_Error(
			'autoscribe_response_truncated',
			sprintf(
				/* translators: 1: provider's stop reason, 2: output tokens spent, 3: the output token ceiling, 4: target word count. */
				__( 'The model stopped before it finished the article (%1$s). It spent %2$d of the %3$d output tokens this step allows, so what came back was a fragment rather than a whole response. The ceiling covers the model\'s own reasoning as well as the article, so a %4$d-word target does not guarantee it fits: lower the target word count, ask the prompt for a shorter article, or raise the ceiling with the autoscribe_body_output_ceiling filter.', 'autoscribe' ),
				$reason,
				$spent,
				self::output_ceiling( $prompt ),
				$prompt->target_word_count()
			)
		);
	}

	/**
	 * Converts the prompt's target word count into a token ceiling.
	 *
	 * Public and static because the figure is checked against the monthly cap
	 * before the call and priced again if the call is interrupted, and until
	 * 1.17.0 the same expression was written out in three files. It went stale in
	 * exactly the way that invites: the step raised what it asked for and the two
	 * copies elsewhere would have gone on reserving against the old bound.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @return int
	 */
	public static function output_ceiling( Prompt $prompt ): int {
		$ceiling = self::REASONING_HEADROOM
			+ self::METADATA_TOKENS
			+ max( 1024, $prompt->target_word_count() * self::TOKENS_PER_WORD );

		/**
		 * Filters the output token ceiling asked of the provider for one article.
		 *
		 * Filterable because how many tokens an article costs depends on what the
		 * prompt asks for, and the plugin can only see the word count. A system
		 * prompt that also wants references, an FAQ, and a research summary
		 * inside the body produces a much larger response than its word target
		 * suggests, and the site that wrote that prompt is the only party that
		 * knows.
		 *
		 * The budget reservation is built from the same figure, so raising it
		 * raises what a run holds against the monthly cap while it is open.
		 *
		 * @since 1.17.0
		 *
		 * @param int    $ceiling Output token ceiling for the body call.
		 * @param Prompt $prompt  Prompt being run.
		 */
		$ceiling = (int) apply_filters( 'autoscribe_body_output_ceiling', $ceiling, $prompt );

		// A ceiling below the reasoning headroom cannot produce an article at
		// all, so a filter is not allowed to take it there.
		return max( self::REASONING_HEADROOM, $ceiling );
	}
}
