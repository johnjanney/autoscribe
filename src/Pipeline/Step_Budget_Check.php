<?php
/**
 * Budget check step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Spend_Lock;
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
 * Reading the total and writing the reservation are two statements, so both
 * happen inside a Spend_Lock. Version 1.0.1 tried to substitute a second read
 * bounded by the reserving run's own row ID for that lock, and the substitution
 * did not hold — row IDs are assigned at insert, not at reservation, so they do
 * not order the reservations. The re-check is kept only for the case where the
 * lock cannot be taken, where it is a narrowing rather than a guarantee.
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
		$lock   = new Spend_Lock();
		$locked = $lock->acquire();

		try {
			return $this->check_and_reserve( $prompt, $run, $locked );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Performs the check and the reservation, optionally under the lock.
	 *
	 * @since 1.0.2
	 *
	 * @param Prompt $prompt Prompt about to run.
	 * @param Run    $run    Run recording progress.
	 * @param bool   $locked Whether the spend lock is held.
	 * @return true|WP_Error True when the run may proceed.
	 */
	private function check_and_reserve( Prompt $prompt, Run $run, bool $locked ): bool|WP_Error {
		$estimate = $this->guard->estimate_cents( $prompt );
		$verdict  = $this->guard->check( $prompt, $estimate );

		if ( is_wp_error( $verdict ) ) {
			$run->skip( Run::STATUS_SKIPPED_BUDGET, $verdict->get_error_message() );

			return $verdict;
		}

		// Reserve before any paid call so concurrent runs can see this one.
		if ( ! $run->reserve_cost( $estimate ) ) {
			/*
			 * A reservation that was not written is not a reservation. Proceeding
			 * anyway — the 1.0.1 behaviour, because the write result was discarded —
			 * spends real money against a cap that cannot see the spending. The run
			 * stops instead, before the first paid call.
			 */
			$error = new WP_Error(
				'autoscribe_reservation_failed',
				__( 'The estimated cost of this run could not be written to the run log, so the monthly cap could not account for it. The run was stopped before any provider call.', 'autoscribe' )
			);

			$run->fail( $error->get_error_message() );

			return $error;
		}

		if ( $locked ) {
			// The read and the write were atomic. Nothing further is needed.
			return true;
		}

		/*
		 * Without the lock, a concurrent worker may have read the total between
		 * this run's own read and write. Confirming afterwards, counting only rows
		 * up to this one, narrows that window. Releasing the reservation before
		 * skipping keeps the row from holding budget it will never spend.
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
