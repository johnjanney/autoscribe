<?php
/**
 * Worst-case cost of the one step a lost worker was inside.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Cost;

use AutoScribe\Pipeline\Pipeline;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Generate_Body;
use AutoScribe\Prompts\Prompt;

defined( 'ABSPATH' ) || exit;

/**
 * Prices a single interrupted step, rather than a whole run.
 *
 * When the stall sweeper releases a claim, the worker holding it may have
 * completed a paid provider call whose token counts never reached the database.
 * Nothing afterwards can discover that, so the run keeps a floor under what it
 * will settle for. Until 1.13.3 that floor was the run's entire reservation —
 * two proposals, a body, a repair, and an image — which is a bound on the whole
 * pipeline and not on the accident. A run interrupted once and then completing
 * normally reported roughly three times what it spent, for ever, and held that
 * much of the monthly cap.
 *
 * A worker can only ever be inside one step, and the claim it left behind names
 * which. This prices that step alone, so the floor becomes what the run has
 * actually recorded plus what the interruption could have hidden. It still
 * cannot understate: an unrecorded charge costs the site its cap, so where the
 * step cannot be identified or the prompt is gone the caller falls back to the
 * whole reservation.
 *
 * @since 1.13.3
 */
final class Step_Allowance {

	/**
	 * Returns what the step after this one could have cost, in cents.
	 *
	 * The claim records the last step the run *completed*, so the step at risk is
	 * the one after it — the one the lost worker was performing.
	 *
	 * @since 1.13.3
	 *
	 * @param Run    $run       Run whose worker was lost.
	 * @param string $completed Last step the run completed, from the claim.
	 * @return int|null Cents, or null when the interruption cannot be bounded.
	 */
	public static function cents( Run $run, string $completed ): ?int {
		$next = self::next_step( $completed );

		if ( null === $next ) {
			return null;
		}

		if ( '' === $next ) {
			// Past the last step: finalisation, which buys nothing.
			return 0;
		}

		$prompt = Prompt::load( $run->prompt_id() );

		if ( null === $prompt ) {
			return null;
		}

		$pricing = $run->pricing_table();
		$text    = $run->resolved_model( 'text' );

		switch ( $next ) {
			case 'propose_topic':
				/*
				 * One call, whether it was the proposal, its repair, or the
				 * re-ask after a collision: a worker can only be inside one at a
				 * time. The allowances are the same ones the reservation is built
				 * from, so this can never exceed the reservation it sits under.
				 */
				return $pricing->cost_cents(
					$text,
					Budget_Guard::PROPOSAL_INPUT_ALLOWANCE,
					Budget_Guard::PROPOSAL_OUTPUT_ALLOWANCE
				);

			case 'generate_body':
				return $pricing->cost_cents(
					$text,
					Budget_Guard::BODY_INPUT_ALLOWANCE,
					Step_Generate_Body::output_ceiling( $prompt ),
					'',
					0,
					$prompt->grounding_enabled() ? 1 : 0
				);

			case 'generate_image':
				return 'none' === $prompt->image_mode()
					? 0
					: $pricing->cost_cents( $text, 0, 0, $run->resolved_model( 'image' ), 1 );

			default:
				// budget_check and assemble_post make no provider call.
				return 0;
		}
	}

	/**
	 * Returns the step that follows a completed one.
	 *
	 * @since 1.13.3
	 *
	 * @param string $completed Last completed step; an empty string before the first.
	 * @return string|null The next step, an empty string when the sequence is
	 *                     finished, or null when the position is unrecognised.
	 */
	private static function next_step( string $completed ): ?string {
		if ( '' === $completed ) {
			return Pipeline::STEPS[0];
		}

		$position = array_search( $completed, Pipeline::STEPS, true );

		if ( false === $position ) {
			return null;
		}

		return Pipeline::STEPS[ $position + 1 ] ?? '';
	}
}
