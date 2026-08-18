<?php
/**
 * Monthly spend cap.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Cost;

use AutoScribe\Activation;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Model_Resolver;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces the per-prompt and global monthly caps from section 7.4.
 *
 * Two things the brief leaves open are settled here.
 *
 * The month boundary is computed in the site timezone and then converted to UTC
 * before querying, because the runs table stores UTC. Section 7.4 asks for
 * "the current calendar month, in the site timezone" without saying what the
 * stored values are, and comparing the two directly would put the first and
 * last hours of every month in the wrong bucket.
 *
 * The cap is enforced by reservation rather than by a read-only check. Action
 * Scheduler runs a batch of actions concurrently, so ten prompts armed for the
 * same minute would all read a month-to-date total that none of them had yet
 * contributed to, all pass, and all spend. Writing the estimate onto the run row
 * before the paid call means later runs in the same batch can see it. The run
 * row is the reservation; no extra storage is needed.
 *
 * @since 0.5.0
 */
final class Budget_Guard {

	/**
	 * Option holding the global monthly cap in cents.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const GLOBAL_CAP_OPTION = 'autoscribe_global_budget_cents';

	/**
	 * Option recording which month has already sent an 80 percent warning.
	 *
	 * Section 7.4 requires exactly one email per month, which needs somewhere to
	 * remember that it went. Section 3.2 provides nowhere, so this is new.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const NOTICE_SENT_OPTION = 'autoscribe_budget_notice_month';

	/**
	 * Fraction of the global cap that triggers the warning email.
	 *
	 * @since 0.5.0
	 * @var float
	 */
	public const WARNING_FRACTION = 0.8;

	/**
	 * Output token ceiling assumed for one topic proposal call.
	 *
	 * Matches the ceiling Step_Propose_Topic actually requests.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const PROPOSAL_OUTPUT_ALLOWANCE = 512;

	/**
	 * Input token allowance assumed for one topic proposal call.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const PROPOSAL_INPUT_ALLOWANCE = 2000;

	/**
	 * Input token allowance assumed for one body call.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const BODY_INPUT_ALLOWANCE = 2000;

	/**
	 * Pricing table.
	 *
	 * @since 0.5.0
	 * @var Pricing_Table
	 */
	private Pricing_Table $pricing;

	/**
	 * Builds the guard.
	 *
	 * @since 0.5.0
	 *
	 * @param Pricing_Table|null $pricing Pricing table, or null to build a default.
	 */
	public function __construct( ?Pricing_Table $pricing = null ) {
		$this->pricing = $pricing instanceof Pricing_Table ? $pricing : new Pricing_Table();
	}

	/**
	 * Returns the global monthly cap in cents, or 0 when uncapped.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function global_cap_cents(): int {
		return max( 0, (int) get_option( self::GLOBAL_CAP_OPTION, 0 ) );
	}

	/**
	 * Returns month-to-date spend in cents.
	 *
	 * Every status counts. Skipped runs used to be excluded on the reasoning that
	 * they cost nothing, which is true of a budget skip — it stops before any
	 * paid call and settles to zero — but false of a duplicate skip, which has
	 * already paid for one or two proposal calls. Excluding the row hid that
	 * money from the cap, so a prompt stuck proposing repeats could spend
	 * indefinitely while the reported total stood still. Rows now carry their
	 * real settled cost, so summing all of them is both simpler and correct.
	 *
	 * @since 0.5.0
	 *
	 * @param int|null $prompt_id  Restrict to one prompt, or null for the whole site.
	 * @param int      $max_run_id Only count runs up to this row ID, or 0 for all.
	 * @return int
	 */
	public function month_to_date_cents( ?int $prompt_id = null, int $max_run_id = 0 ): int {
		global $wpdb;

		$bounds = $this->month_bounds_utc();
		$table  = Activation::table_name();

		// %i is an identifier placeholder, supported since WordPress 6.2. A table
		// name cannot be passed as %s because that would quote it as a string.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(cost_cents) FROM %i
				WHERE ( %d = 0 OR prompt_id = %d )
					AND ( %d = 0 OR id <= %d )
					AND started_at >= %s AND started_at < %s',
				$table,
				(int) $prompt_id,
				(int) $prompt_id,
				$max_run_id,
				$max_run_id,
				$bounds['start'],
				$bounds['end']
			)
		);

		return (int) $total;
	}

	/**
	 * Re-checks the caps once this run's reservation is on the table.
	 *
	 * The check() method reads the month total and returns; the caller then
	 * writes its reservation. Between those two statements a concurrent worker reads the
	 * same total, so both pass and both spend. Action Scheduler runs a batch of
	 * actions at once, which makes that window ordinary rather than theoretical.
	 *
	 * This second pass closes it without a lock or a transaction. It counts only
	 * rows up to and including this run's own ID, so two runs that reserved
	 * concurrently reach different answers: the earlier row sees only itself and
	 * proceeds, the later row sees both and stands down. The ordering is total
	 * and comes from the database's own auto-increment, so there is no tie.
	 *
	 * The remaining overshoot bound is one run's estimate: a run already past
	 * this point cannot be recalled. No client-side cap can do better, and none
	 * replaces a provider-side spending limit.
	 *
	 * @since 1.0.1
	 *
	 * @param Prompt $prompt Prompt about to run.
	 * @param int    $run_id Row ID of this run's reservation.
	 * @return true|WP_Error True when the run may proceed.
	 */
	public function confirm_reservation( Prompt $prompt, int $run_id ): bool|WP_Error {
		$prompt_cap = $prompt->monthly_budget_cents();

		if ( $prompt_cap > 0 ) {
			$prompt_total = $this->month_to_date_cents( $prompt->id(), $run_id );

			if ( $prompt_total > $prompt_cap ) {
				return $this->over_budget( $prompt_total, $prompt_cap, true );
			}
		}

		$global_cap = $this->global_cap_cents();

		if ( $global_cap > 0 ) {
			$global_total = $this->month_to_date_cents( null, $run_id );

			if ( $global_total > $global_cap ) {
				return $this->over_budget( $global_total, $global_cap, false );
			}
		}

		return true;
	}

	/**
	 * Checks a projected cost against both caps.
	 *
	 * Section 7.4: the per-prompt cap is checked first, then the global cap, and
	 * the global cap wins.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt $prompt          Prompt about to run.
	 * @param int    $projected_cents Estimated cost of the run.
	 * @return true|WP_Error True when the run may proceed.
	 */
	public function check( Prompt $prompt, int $projected_cents ): bool|WP_Error {
		$prompt_cap = $prompt->monthly_budget_cents();

		if ( $prompt_cap > 0 ) {
			$prompt_total = $this->month_to_date_cents( $prompt->id() );

			if ( $prompt_total + $projected_cents > $prompt_cap ) {
				return $this->over_budget( $prompt_total, $prompt_cap, true );
			}
		}

		$global_cap = $this->global_cap_cents();

		if ( $global_cap > 0 ) {
			$global_total = $this->month_to_date_cents();

			if ( $global_total + $projected_cents > $global_cap ) {
				return $this->over_budget( $global_total, $global_cap, false );
			}
		}

		return true;
	}

	/**
	 * Whether the global cap has crossed the warning threshold this month.
	 *
	 * Returns true only once per calendar month, so the caller can send section
	 * 7.4's single warning email rather than one per run.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function should_send_warning(): bool {
		$cap = $this->global_cap_cents();

		if ( $cap <= 0 ) {
			return false;
		}

		if ( $this->month_to_date_cents() < (int) floor( $cap * self::WARNING_FRACTION ) ) {
			return false;
		}

		$month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m' );

		if ( get_option( self::NOTICE_SENT_OPTION, '' ) === $month ) {
			return false;
		}

		update_option( self::NOTICE_SENT_OPTION, $month, false );

		return true;
	}

	/**
	 * Estimates what a run will cost before it happens.
	 *
	 * The estimate has to bound the worst case, not the likely one: under-
	 * estimating lets a run slip past a cap it was going to breach. The previous
	 * version priced a single body call, while the pipeline can make four paid
	 * text calls — two topic proposals when the first collides, then the body and
	 * its one repair — so a run could cost roughly twice what was reserved for it.
	 *
	 * Every call the pipeline can make is now counted:
	 *
	 * - two proposal calls, at the 512-token ceiling section 7.2's cheap call uses;
	 * - the body call, at the same token ceiling the body step requests;
	 * - one repair call, whose prompt also carries the rejected response back;
	 * - one image, and one grounded request.
	 *
	 * @since 0.5.0
	 *
	 * @param Prompt $prompt Prompt about to run.
	 * @return int
	 */
	public function estimate_cents( Prompt $prompt ): int {
		$body_output = max( 1024, $prompt->target_word_count() * 3 );

		// Two proposals, the body, and one repair. The repair prompt quotes the
		// failed response back, so its input allowance is the larger one.
		$input_tokens  = ( 2 * self::PROPOSAL_INPUT_ALLOWANCE ) + self::BODY_INPUT_ALLOWANCE + ( 2 * self::BODY_INPUT_ALLOWANCE );
		$output_tokens = ( 2 * self::PROPOSAL_OUTPUT_ALLOWANCE ) + ( 2 * $body_output );

		$images   = 'none' === $prompt->image_mode() ? 0 : 1;
		$grounded = $prompt->grounding_enabled() ? 1 : 0;

		return $this->pricing->cost_cents(
			Model_Resolver::resolve( $prompt->text_model(), $prompt->text_provider() ),
			$input_tokens,
			$output_tokens,
			Model_Resolver::resolve( $prompt->image_model(), $prompt->image_provider() ),
			$images,
			$grounded
		);
	}

	/**
	 * Returns the UTC bounds of the current site-local calendar month.
	 *
	 * @since 0.5.0
	 *
	 * @return array{start: string, end: string}
	 */
	private function month_bounds_utc(): array {
		$site_tz = wp_timezone();
		$now     = new DateTimeImmutable( 'now', $site_tz );
		$start   = new DateTimeImmutable( $now->format( 'Y-m-01 00:00:00' ), $site_tz );
		$end     = $start->modify( '+1 month' );
		$utc     = new DateTimeZone( 'UTC' );

		return array(
			'start' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'   => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Builds the over-budget error.
	 *
	 * @since 0.5.0
	 *
	 * @param int  $spent     Month-to-date spend in cents.
	 * @param int  $cap       Cap in cents.
	 * @param bool $is_prompt Whether this is the per-prompt cap.
	 * @return WP_Error
	 */
	private function over_budget( int $spent, int $cap, bool $is_prompt ): WP_Error {
		return new WP_Error(
			'autoscribe_budget_exceeded',
			sprintf(
				/* translators: 1: which cap was hit, 2: spend so far in dollars, 3: cap in dollars. */
				__( 'The %1$s monthly budget would be exceeded. Estimated spend so far is $%2$s against a cap of $%3$s.', 'autoscribe' ),
				$is_prompt ? __( 'per-prompt', 'autoscribe' ) : __( 'global', 'autoscribe' ),
				number_format( $spent / 100, 2 ),
				number_format( $cap / 100, 2 )
			),
			array(
				'spent_cents' => $spent,
				'cap_cents'   => $cap,
			)
		);
	}
}
