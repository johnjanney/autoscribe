<?php
/**
 * The ordered generation sequence.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Diagnostics\Debug_Log;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\SEO\SEO_Adapter_Factory;
use AutoScribe\Security\Content_Sanitizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the step order, and executes exactly one step at a time.
 *
 * Section 5 lists five steps and requires each to be its own queued action. That
 * needs two things the pipeline did not have: a definition of the order that is
 * not a sequence of statements inside one method, and a way to work out where a
 * run has got to from the run row alone, because a queued action arrives knowing
 * only a run ID.
 *
 * This class is both. It is the single ordered list, and every step's input is
 * derived from the Run rather than from a local variable, which is what phases 1
 * and 2 of docs/PIPELINE-SPLIT.md were building towards: the topic, the article,
 * and the image outcome are all on the run by the time the next step needs them.
 *
 * There are two callers and there is deliberately one sequence. Generator drives
 * it in a loop inside one request, which is what "Run now" and the tests use;
 * the queue driver will drive it one action at a time. Two sequencers would be
 * two descriptions of the same order, and the second one to change is the one
 * nobody notices.
 *
 * @since 1.1.0
 */
final class Pipeline {

	/**
	 * Returned when another worker holds the claim on this run's next step.
	 *
	 * Distinct from null, which means the sequence is finished. Reporting a lost
	 * claim as "nothing left to do" made the losing worker publish the run: early
	 * on it closed a run that had no article, and at the image step it could
	 * publish before the worker holding the claim had attached the picture.
	 *
	 * @since 1.1.1
	 * @var string
	 */
	public const CLAIM_LOST = 'claim_lost';

	/**
	 * The steps, in order, named as they are recorded in runs.step.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	public const STEPS = array(
		'budget_check',
		'propose_topic',
		'generate_body',
		'assemble_post',
		'generate_image',
	);

	/**
	 * Budget check step.
	 *
	 * @since 1.1.0
	 * @var Step_Budget_Check
	 */
	private Step_Budget_Check $budget_step;

	/**
	 * Topic proposal step.
	 *
	 * @since 1.1.0
	 * @var Step_Propose_Topic
	 */
	private Step_Propose_Topic $topic_step;

	/**
	 * Body generation step.
	 *
	 * @since 1.1.0
	 * @var Step_Generate_Body
	 */
	private Step_Generate_Body $body_step;

	/**
	 * Post assembly step.
	 *
	 * @since 1.1.0
	 * @var Step_Assemble_Post
	 */
	private Step_Assemble_Post $assemble_step;

	/**
	 * Featured image step.
	 *
	 * @since 1.1.0
	 * @var Step_Generate_Image
	 */
	private Step_Generate_Image $image_step;

	/**
	 * Article validator, used to rebuild the stored article between steps.
	 *
	 * @since 1.1.0
	 * @var Article_Validator
	 */
	private Article_Validator $validator;

	/**
	 * Builds the sequence.
	 *
	 * @since 1.1.0
	 *
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Provider_Registry $registry ) {
		$this->validator     = new Article_Validator();
		$this->budget_step   = new Step_Budget_Check();
		$this->topic_step    = new Step_Propose_Topic( $registry, new Topic_Deduplicator() );
		$this->body_step     = new Step_Generate_Body( $registry, $this->validator );
		$this->image_step    = new Step_Generate_Image( $registry );
		$this->assemble_step = new Step_Assemble_Post(
			new Content_Sanitizer(),
			new SEO_Adapter_Factory(),
			new Taxonomy_Applier()
		);
	}

	/**
	 * Returns the step a run should perform next, or null when none remain.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run to place in the sequence.
	 * @return string|null
	 */
	public function next_step( Run $run ): ?string {
		$completed = $run->step();

		if ( '' === $completed ) {
			return self::STEPS[0];
		}

		$position = array_search( $completed, self::STEPS, true );

		if ( false === $position ) {
			/*
			 * An unrecognised value, which a Preview writes: it records "preview"
			 * and is not part of this sequence. Treating it as finished is the
			 * safe reading — the alternative is starting a paid pipeline over a
			 * run somebody else's code opened.
			 */
			return null;
		}

		return self::STEPS[ $position + 1 ] ?? null;
	}

	/**
	 * Executes the run's next step, and records it when it succeeds.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return string|null|WP_Error The step performed, null when none remained,
	 *                              or the step's error.
	 */
	public function advance( Prompt $prompt, Run $run ): string|null|WP_Error {
		$completed = $run->step();
		$step      = $this->next_step( $run );

		if ( null === $step ) {
			return null;
		}

		/*
		 * Claim the position before doing anything that costs money. Two workers
		 * can otherwise both read the same position and both pay for the same
		 * step — the per-step guards are reads, and a read cannot exclude anyone.
		 * The loser stops here having spent nothing.
		 */
		if ( ! $run->claim_step( $completed ) ) {
			return self::CLAIM_LOST;
		}

		/*
		 * Everything a step records from here belongs to this run and this
		 * position in it. Http is static and knows nothing about the pipeline by
		 * design, so a captured exchange would otherwise be a bare URL, and a log
		 * covering several runs could not be read apart. Cleared afterwards so a
		 * later capture on the same worker — a connection test, say — is not
		 * filed under a run that has finished.
		 */
		Debug_Log::set_context( $run->id(), $run->prompt_id(), $step );

		try {
			$result = $this->perform( $step, $prompt, $run );
		} finally {
			Debug_Log::clear_context();
		}

		/*
		 * A claim can be taken away mid-step. The stall sweeper releases one when
		 * a run has nothing queued or running to advance it, which is a judgement
		 * about a worker it cannot see rather than a fact about one — a worker
		 * slow enough to be judged gone can still be inside a provider call.
		 *
		 * Everything such a worker does from that moment belongs to somebody else:
		 * its payload writes are refused by the same condition, and the error that
		 * refusal produces would otherwise close a run that a live worker is part
		 * way through. Standing down is the only correct answer, whatever the
		 * error says.
		 */
		if ( is_wp_error( $result ) ) {
			return $run->lost_claim() ? self::CLAIM_LOST : $result;
		}

		/*
		 * Everything downstream reads runs.step to know where the run has got to,
		 * so a refused write here does not merely lose a log entry — it leaves
		 * the run pointing at the step that just ran. A driver told the step
		 * succeeded would read the same position back and run it again, and keep
		 * running it: for as long as PHP allows in the synchronous loop, or as an
		 * endless chain of actions under the queue driver, with the budget
		 * reservation held open throughout.
		 */
		if ( ! $run->record_step( $step ) ) {
			if ( $run->lost_claim() ) {
				// Swept and replaced. The replacement owns the position now.
				return self::CLAIM_LOST;
			}

			return new WP_Error(
				'autoscribe_step_not_recorded',
				sprintf(
					/* translators: %s: step name. */
					__( 'The run log could not record that the %s step finished, so the run was stopped rather than repeating it.', 'autoscribe' ),
					$step
				)
			);
		}

		return $step;
	}

	/**
	 * Runs one named step, taking its inputs from the run.
	 *
	 * @since 1.1.0
	 *
	 * @param string $step   Step to perform.
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return true|WP_Error
	 */
	private function perform( string $step, Prompt $prompt, Run $run ): bool|WP_Error {
		switch ( $step ) {
			case 'budget_check':
				// Section 7.4: first, and before any paid call.
				$result = $this->budget_step->run( $prompt, $run );
				break;

			case 'propose_topic':
				/*
				 * Section 7.2: a cheap proposal call, so a duplicate is caught
				 * before paying to write an article that would be discarded. The
				 * adopted draft, if the run has one, is excluded from duplicate
				 * detection — see Run::adoptable_draft().
				 */
				$result = $this->topic_step->run( $prompt, $run, (int) $run->post_id() );
				break;

			case 'generate_body':
				$result = $this->body_step->run( $prompt, $run, $this->agreed_topic( $run ) );
				break;

			case 'assemble_post':
				$article = $this->article( $run );

				$result = is_wp_error( $article )
					? $article
					: $this->assemble_step->run( $prompt, $article, $run );
				break;

			case 'generate_image':
				$article = $this->article( $run );

				$result = is_wp_error( $article )
					? $article
					: $this->image_step->attach( $prompt, $article, $run, (int) $run->post_id() );
				break;

			default:
				$result = new WP_Error(
					'autoscribe_unknown_step',
					sprintf(
						/* translators: %s: step name. */
						__( 'The run asked for a step called %s, which does not exist.', 'autoscribe' ),
						$step
					)
				);
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Returns the topic the proposal step agreed, if there is one.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run recording progress.
	 * @return array{title: string, topic_key: string}|null
	 */
	private function agreed_topic( Run $run ): ?array {
		$stored = $run->payload()['topic'] ?? null;

		if ( ! is_array( $stored ) || ! isset( $stored['title'], $stored['topic_key'] ) ) {
			return null;
		}

		return array(
			'title'     => (string) $stored['title'],
			'topic_key' => (string) $stored['topic_key'],
		);
	}

	/**
	 * Rebuilds the article the body step produced.
	 *
	 * Later steps used to receive it as an argument, which only works while the
	 * whole sequence is one call stack. It comes back off the run instead, and
	 * re-validates on the way — see Article_Validator::from_array() for why the
	 * store is not trusted.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run recording progress.
	 * @return Article|WP_Error
	 */
	private function article( Run $run ): Article|WP_Error {
		$stored = $run->payload()['article'] ?? null;

		if ( ! is_array( $stored ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'This run has no generated article to work from, so it cannot continue.', 'autoscribe' )
			);
		}

		return $this->validator->from_array( $stored );
	}
}
