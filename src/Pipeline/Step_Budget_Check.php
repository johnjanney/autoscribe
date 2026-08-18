<?php
/**
 * Budget check step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Prompts\Prompt;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a run before it spends anything.
 *
 * Section 7.4 puts this first, before any paid call, and requires the run to be
 * abandoned rather than partially executed on a breach.
 *
 * On success the estimate is written onto the run row straight away. That row is
 * the reservation: Action Scheduler runs actions concurrently, so without it a
 * batch of prompts armed for the same minute would each read a month-to-date
 * total that none of them had contributed to yet, all pass, and all spend.
 *
 * @since 0.5.0
 */
final class Step_Budget_Check {

	/**
	 * Budget guard.
	 *
	 * @since 0.5.0
	 * @var Budget_Guard
	 */
	private Budget_Guard $guard;

	/**
	 * Builds the step.
	 *
	 * @since 0.5.0
	 *
	 * @param Budget_Guard|null $guard Guard, or null to build a default.
	 */
	public function __construct( ?Budget_Guard $guard = null ) {
		$this->guard = $guard instanceof Budget_Guard ? $guard : new Budget_Guard();
	}

	/**
	 * Checks the caps and reserves the estimated cost.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt $prompt Prompt about to run.
	 * @param Run    $run    Run recording progress.
	 * @return true|WP_Error True when the run may proceed.
	 */
	public function run( Prompt $prompt, Run $run ): bool|WP_Error {
		$estimate = $this->guard->estimate_cents( $prompt );
		$verdict  = $this->guard->check( $prompt, $estimate );

		if ( is_wp_error( $verdict ) ) {
			$run->skip( Run::STATUS_SKIPPED_BUDGET, $verdict->get_error_message() );

			return $verdict;
		}

		// Reserve before any paid call so concurrent runs can see this one.
		$run->reserve_cost( $estimate );

		/*
		 * Reading the total and writing the reservation are two statements, and a
		 * concurrent worker can read between them. Confirming afterwards, counting
		 * only rows up to this one, resolves that race in row order: whichever run
		 * reserved first proceeds and the other stands down. Releasing the
		 * reservation before skipping keeps the row from holding budget it will
		 * never spend.
		 */
		$confirmed = $this->guard->confirm_reservation( $prompt, $run->id() );

		if ( is_wp_error( $confirmed ) ) {
			$run->reserve_cost( 0 );
			$run->skip( Run::STATUS_SKIPPED_BUDGET, $confirmed->get_error_message() );

			return $confirmed;
		}

		return true;
	}

	/**
	 * Returns the guard, so callers can send the section 7.4 warning email.
	 *
	 * @since 0.5.0
	 *
	 * @return Budget_Guard
	 */
	public function guard(): Budget_Guard {
		return $this->guard;
	}
}
