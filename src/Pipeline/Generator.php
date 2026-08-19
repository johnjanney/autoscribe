<?php
/**
 * Synchronous generation orchestrator.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Admin\Settings;
use AutoScribe\Content\Article;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one prompt from instruction to published post, in a single request.
 *
 * One of the two drivers of Pipeline, and the synchronous one. It opens the run,
 * advances every step in a loop, and finishes the post off. "Run now" and
 * Preview both want an answer in the request that asked for it, so this driver
 * stays whatever else changes.
 *
 * What it no longer contains is the order of the steps. That moved to Pipeline
 * when the queue driver arrived, because two descriptions of one sequence drift,
 * and the one that drifts is the one nobody is looking at.
 *
 * @since 0.3.0
 */
final class Generator {

	/**
	 * Error code for a run that something else closed first.
	 *
	 * @since 1.1.1
	 * @var string
	 */
	public const CLOSE_RACE_LOST = 'autoscribe_run_already_closed';

	/**
	 * The ordered generation sequence.
	 *
	 * @since 1.1.0
	 * @var Pipeline
	 */
	private Pipeline $pipeline;

	/**
	 * Budget guard, kept so the section 7.4 warning email can still be sent.
	 *
	 * @since 1.1.0
	 * @var Budget_Guard
	 */
	private Budget_Guard $guard;

	/**
	 * Article validator, used to read the finished article back off the run.
	 *
	 * @since 1.1.0
	 * @var Article_Validator
	 */
	private Article_Validator $validator;

	/**
	 * Builds the orchestrator.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Provider_Registry $registry ) {
		$this->pipeline  = new Pipeline( $registry );
		$this->guard     = new Budget_Guard();
		$this->validator = new Article_Validator();
	}

	/**
	 * Runs one prompt end to end.
	 *
	 * @since 0.3.0
	 *
	 * @param int         $prompt_id       Prompt to run.
	 * @param string|null $status_override Final post status, or null to use the prompt's mode.
	 * @param int         $attempt         Attempt number, so the run row records the real one.
	 * @return array<string, int|string>|WP_Error Summary of what was produced, or an error.
	 */
	public function run( int $prompt_id, ?string $status_override = null, int $attempt = 1 ): array|WP_Error {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			return $this->unknown_prompt( $prompt_id );
		}

		$run = $this->open( $prompt_id, $attempt );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$pricing = new Pricing_Table();

		/*
		 * The sequence itself lives in Pipeline, and this loop is one of its two
		 * drivers: it advances every step inside a single request, which is what
		 * "Run now" wants and what the tests drive. The queue driver advances the
		 * same sequence one action at a time. Neither knows the order — that is
		 * the point of there being only one list.
		 *
		 * The bound is not decoration. A driver that trusts advance() to make
		 * progress spins for ever the moment anything stops it doing so, holding
		 * the budget reservation open and re-running side effects until PHP gives
		 * up — and the queue driver would do the same as an endless chain of
		 * actions. One cause of that is fixed (a refused runs.step write is now a
		 * terminal error), and this covers the ones nobody has thought of yet: a
		 * sequence that stops advancing ends the request rather than spinning
		 * inside it.
		 */
		$completed = false;

		// One iteration per step, plus the one that finds none left and stops.
		$allowed = count( Pipeline::STEPS ) + 1;

		for ( $i = 0; $i < $allowed; $i++ ) {
			$step = $this->pipeline->advance( $prompt, $run );

			if ( null === $step ) {
				$completed = true;

				break;
			}

			if ( is_wp_error( $step ) ) {
				$this->close_failed( $run, $step, $pricing, $run->grounded_calls() );

				return $step;
			}
		}

		if ( ! $completed ) {
			$stalled = new WP_Error(
				'autoscribe_pipeline_stalled',
				__( 'The run stopped making progress through its steps and was abandoned rather than repeated indefinitely.', 'autoscribe' )
			);

			$this->close_failed( $run, $stalled, $pricing, $run->grounded_calls() );

			return $stalled;
		}

		$article = $this->pipeline_article( $run );

		if ( is_wp_error( $article ) ) {
			$this->close_failed( $run, $article, $pricing, $run->grounded_calls() );

			return $article;
		}

		return $this->finalise( $prompt, $run, $article, $status_override, $pricing, $run->grounded_calls() );
	}

	/**
	 * Publishes the finished run and closes it.
	 *
	 * Split out of run() so the queue driver can reach it. It is the tail of the
	 * sequence rather than a step in it: unlike the five steps it does not read
	 * its position from runs.step, and it is the only part that decides what the
	 * post's final status should be.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt        $prompt          Prompt being run.
	 * @param Run           $run             Run recording progress.
	 * @param Article       $article         The generated article.
	 * @param string|null   $status_override Final post status, or null for the prompt's mode.
	 * @param Pricing_Table $pricing         Rate table for settling the cost.
	 * @param int           $grounded        Number of grounded requests made.
	 * @return array<string, int|string>|WP_Error
	 */
	public function finalise( Prompt $prompt, Run $run, Article $article, ?string $status_override, Pricing_Table $pricing, int $grounded ): array|WP_Error {
		$post_id       = (int) $run->post_id();
		$attachment_id = (int) ( $run->payload()['image']['attachment_id'] ?? 0 );

		$status = $this->final_status( $prompt, $status_override, $run );

		/*
		 * A refused status transition used to pass unnoticed, and the run then
		 * reported success for a post still sitting in draft. Section 10 makes
		 * the difference between draft and published the whole safety model, so
		 * the failure is surfaced rather than swallowed.
		 */
		if ( 'draft' !== $status ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$run->fail( $updated->get_error_message(), $pricing, $grounded );

				return $updated;
			}
		}

		// Section 7.4: replace the reservation with what the run actually cost,
		// now that the providers have reported their usage.
		$cost = $run->settle_cost( $pricing, $grounded );

		if ( is_wp_error( $cost ) ) {
			return $cost;
		}

		/*
		 * Nothing is announced until the run is durably closed. succeed() is a
		 * conditional transition, so it is false both when the write failed and
		 * when something else had already closed this run — a duplicate action,
		 * or a stall sweep that gave up on it. Sending the review mail and arming
		 * the next occurrence off the back of a transition that did not happen is
		 * how one finished article becomes two emails and two schedules.
		 */
		if ( ! $run->succeed() ) {
			/*
			 * Its own code, because losing this race is not a failure and must not
			 * be reported as one. Whoever won it has already sent the review mail
			 * and armed the next occurrence; a loser that reported an ordinary
			 * error would have the handler send a failure notice and re-arm on top
			 * — the duplicate announcement this check exists to prevent, arriving
			 * by the other door.
			 */
			return new WP_Error(
				self::CLOSE_RACE_LOST,
				__( 'This run was already closed by something else, so it was left alone. Whatever closed it has reported the outcome.', 'autoscribe' )
			);
		}

		if ( 'draft' === $status ) {
			$this->send_review_notice( $article, $post_id );
		}

		if ( $this->guard->should_send_warning() ) {
			$this->send_budget_warning();
		}

		return array(
			'run_id'        => $run->id(),
			'post_id'       => $post_id,
			'attachment_id' => (int) $attachment_id,
			'status'        => $status,
			'cost_cents'    => $cost,
		);
	}

	/**
	 * Opens a run and binds any draft it inherits, without advancing it.
	 *
	 * The synchronous driver goes straight on to the steps. The queue driver
	 * stops here and arms the first step as its own action, which is the whole
	 * point of section 5's split: opening a run is cheap, and everything after it
	 * is not.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt to run.
	 * @param int $attempt   Attempt number this run represents.
	 * @return Run|WP_Error
	 */
	public function open( int $prompt_id, int $attempt = 1 ): Run|WP_Error {
		$run = Run::start( $prompt_id, $attempt );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			return $this->unknown_prompt( $prompt_id );
		}

		/*
		 * Record which configuration this run was checked against. A run spread
		 * across queued actions reads the prompt again at every step, so an edit
		 * landing part-way through would apply to the rest of it — settings that
		 * were never budget-checked, and a run that began under review finishing
		 * by publishing. The queue driver compares this before each step.
		 */
		$opened = array(
			'config'       => $prompt->config_fingerprint(),
			'force_review' => Settings::force_review() ? 1 : 0,
		);

		if ( ! $run->merge_payload( $opened ) ) {
			/*
			 * A missing fingerprint is read downstream as "opened by an earlier
			 * version", which is right for an upgrade and wrong for a write that
			 * failed: the run would then accept any edit silently. Refusing to
			 * start is the only reading that does not turn the guard off with the
			 * one failure it most needs to survive.
			 */
			$error = new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The settings this run was checked against could not be written to the run log, so the run was stopped rather than starting without a record of them.', 'autoscribe' )
			);

			$run->fail( $error->get_error_message() );

			return $error;
		}

		$adopted = $this->adopt( $prompt_id, $run, $attempt );

		return is_wp_error( $adopted ) ? $adopted : $run;
	}

	/**
	 * Returns the error for a prompt that no longer exists.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt that was asked for.
	 * @return WP_Error
	 */
	public function unknown_prompt( int $prompt_id ): WP_Error {
		return new WP_Error(
			'autoscribe_unknown_prompt',
			sprintf(
				/* translators: %d: post ID. */
				__( 'No prompt exists with ID %d.', 'autoscribe' ),
				$prompt_id
			)
		);
	}

	/**
	 * Advances a run by one step, for the queue driver.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return string|null|WP_Error Step performed, null when none remained, or an error.
	 */
	public function advance( Prompt $prompt, Run $run ): string|null|WP_Error {
		return $this->pipeline->advance( $prompt, $run );
	}

	/**
	 * Closes a run that failed, unless the step closed it already.
	 *
	 * @since 1.1.0
	 *
	 * @param Run      $run   Run to close.
	 * @param WP_Error $error Why it failed.
	 * @param int      $grounded Number of grounded requests made.
	 * @return void
	 */
	public function close( Run $run, WP_Error $error, int $grounded ): void {
		$this->close_failed( $run, $error, new Pricing_Table(), $grounded );
	}

	/**
	 * Reads the finished article back off a run, for the queue driver.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run recording progress.
	 * @return Article|WP_Error
	 */
	public function article( Run $run ): Article|WP_Error {
		return $this->pipeline_article( $run );
	}

	/**
	 * Binds the previous attempt's draft to this run, if there is one to bind.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt being run.
	 * @param Run $run       Run recording progress.
	 * @param int $attempt   Attempt number this run represents.
	 * @return true|WP_Error
	 */
	private function adopt( int $prompt_id, Run $run, int $attempt ): bool|WP_Error {
		/*
		 * The attempt immediately before this one may have got as far as a draft
		 * before failing. Bind it to this run so assembly updates that draft
		 * instead of adding a second one. Run::adoptable_draft() refuses anything
		 * that is not the previous attempt of this retry series, and anything a
		 * person has touched since.
		 *
		 * This happens before the first step rather than after the body call, so
		 * that duplicate detection can be told to ignore the draft this run is
		 * about to overwrite.
		 */
		$inherited = Run::adoptable_draft( $prompt_id, $run->id(), $attempt );

		if ( null === $inherited ) {
			return true;
		}

		if ( $run->adopt_post( $inherited ) ) {
			return true;
		}

		/*
		 * Adoption is all or nothing, and a refused ownership write leaves the
		 * draft with its previous owner. There is no safe way to carry on from
		 * there: the covered list is injected precisely so the model proposes
		 * something different, so the collision check would pass, the body would
		 * be paid for, and assembly would write a second draft beside the
		 * orphaned one. The run stops here instead, before the first paid call.
		 */
		$error = new WP_Error(
			'autoscribe_adoption_failed',
			sprintf(
				/* translators: %d: post ID of the draft. */
				__( 'The draft left by the previous attempt (post %d) could not be bound to this run, so continuing would have created a second draft beside it. The run was stopped before any provider call.', 'autoscribe' ),
				$inherited
			)
		);

		$run->fail( $error->get_error_message() );

		return $error;
	}

	/**
	 * Closes a run that a step has failed, unless the step closed it already.
	 *
	 * A budget breach and a duplicate topic are outcomes rather than faults, and
	 * the steps that produce them close the run themselves — as does a refused
	 * reservation. Asking the row what state it is in is more durable than
	 * keeping a list of the error codes that mean "already dealt with", because
	 * the list is one release away from being incomplete.
	 *
	 * @since 1.1.0
	 *
	 * @param Run           $run      Run to close.
	 * @param WP_Error      $error    Why it failed.
	 * @param Pricing_Table $pricing  Rate table for settling the cost.
	 * @param int           $grounded Number of grounded requests made.
	 * @return void
	 */
	private function close_failed( Run $run, WP_Error $error, Pricing_Table $pricing, int $grounded ): void {
		if ( Run::STATUS_RUNNING !== $run->status() ) {
			return;
		}

		$run->fail( $error->get_error_message(), $pricing, $grounded );
	}

	/**
	 * Reads the finished article back off the run.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run Run recording progress.
	 * @return Article|WP_Error
	 */
	private function pipeline_article( Run $run ): Article|WP_Error {
		$stored = $run->payload()['article'] ?? null;

		if ( ! is_array( $stored ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'This run produced no article, so there is nothing to publish.', 'autoscribe' )
			);
		}

		return $this->validator->from_array( $stored );
	}

	/**
	 * Tells the notification address that a draft is waiting for review.
	 *
	 * Section 10 requires this for every review-mode draft, with the title, the
	 * opening of the article, and a direct edit link. Without it review mode is
	 * a queue nobody is told about, which is the failure that leads people to
	 * turn review off and publish unread.
	 *
	 * @since 1.0.1
	 *
	 * @param Article $article Validated article.
	 * @param int     $post_id Draft post ID.
	 * @return void
	 */
	private function send_review_notice( Article $article, int $post_id ): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		$opening = trim( wp_strip_all_tags( $article->raw_content_html() ) );

		wp_mail(
			$address,
			sprintf(
				/* translators: %s: article title. */
				__( 'AutoScribe draft ready for review: %s', 'autoscribe' ),
				$article->title()
			),
			sprintf(
				/* translators: 1: article title, 2: opening of the article, 3: edit URL. */
				__( "AutoScribe generated a draft and held it for review.\n\n%1\$s\n\n%2\$s\n\nReview and publish it here:\n%3\$s", 'autoscribe' ),
				$article->title(),
				mb_substr( $opening, 0, 200 ),
				(string) get_edit_post_link( $post_id, 'raw' )
			)
		);
	}

	/**
	 * Tells the notification address that a run failed for good.
	 *
	 * Section 5 asks for one notification after the attempts are exhausted, not
	 * one per attempt, so this is called by the queue handler once it has decided
	 * there will be no further try.
	 *
	 * @since 1.0.1
	 *
	 * @param int      $prompt_id Prompt that failed.
	 * @param WP_Error $error     The final failure.
	 * @return void
	 */
	public static function send_failure_notice( int $prompt_id, WP_Error $error ): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		wp_mail(
			$address,
			sprintf(
				/* translators: %s: prompt title. */
				__( 'AutoScribe run failed: %s', 'autoscribe' ),
				get_the_title( $prompt_id )
			),
			sprintf(
				/* translators: 1: prompt title, 2: error message, 3: edit URL. */
				__( "An AutoScribe prompt failed and will not be retried.\n\nPrompt: %1\$s\nReason: %2\$s\n\nThe run log has the detail. The prompt is here:\n%3\$s", 'autoscribe' ),
				get_the_title( $prompt_id ),
				$error->get_error_message(),
				(string) get_edit_post_link( $prompt_id, 'raw' )
			)
		);
	}

	/**
	 * Sends the single monthly warning required by section 7.4.
	 *
	 * The guard decides whether to send; this only performs the delivery, so the
	 * "one email per month, not one per run" rule lives in one place.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private function send_budget_warning(): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		wp_mail(
			$address,
			__( 'AutoScribe has reached 80% of its monthly budget', 'autoscribe' ),
			__( 'Estimated AutoScribe spend for this month has passed 80 percent of the configured global cap. Runs will stop once the cap is reached. These figures are estimates; your provider billing is the authority.', 'autoscribe' )
		);
	}

	/**
	 * Resolves the final post status.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt      $prompt   Prompt being run.
	 * @param string|null $override Explicit status, or null.
	 * @param Run|null    $run      Run being finished, when there is one.
	 * @return string
	 */
	private function final_status( Prompt $prompt, ?string $override, ?Run $run = null ): string {
		/*
		 * Section 10's global override wins over everything, including an explicit
		 * caller override. It is the safety catch for the moment a provider changes
		 * behaviour or a prompt starts producing garbage, and a catch that any
		 * caller can step around is not a catch. Checked first for that reason.
		 *
		 * It is also checked against the moment the run *started*, not only the
		 * moment it ends. A run spans several requests now, so switching the catch
		 * off part-way would publish work that began under review — the safety
		 * model turned off retrospectively for an article already being written.
		 * The stricter of the two settings wins, so turning it on mid-run still
		 * takes effect and turning it off never applies to work already under way.
		 */
		if ( Settings::force_review() || ( $run instanceof Run && $run->started_under_review() ) ) {
			return 'draft';
		}

		if ( is_string( $override ) && in_array( $override, array( 'draft', 'publish', 'pending' ), true ) ) {
			return $override;
		}

		return 'auto' === $prompt->post_status_mode() ? 'publish' : 'draft';
	}
}
