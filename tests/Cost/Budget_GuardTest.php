<?php
/**
 * Budget guard tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Cost;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the section 7.4 spend cap.
 *
 * The central test does not assert a status string. It runs the real pipeline
 * with no HTTP mock registered, so the bootstrap tripwire throws on any request
 * that reaches the network. The test passing is therefore positive evidence
 * that no provider was contacted, which is the property section 7.4 actually
 * demands: the guard runs "before any paid call", not merely before the post is
 * created.
 *
 * @since 0.5.0
 */
final class Budget_GuardTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Clears budget state between tests.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Budget_Guard::GLOBAL_CAP_OPTION );
		delete_option( Budget_Guard::NOTICE_SENT_OPTION );
		delete_option( Pricing_Table::OPTION );

		parent::tear_down();
	}

	/**
	 * A capped prompt stops the run before any provider is contacted.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_per_prompt_cap_blocks_before_any_provider_call(): void {
		$prompt_id = $this->create_prompt( array( 'monthly_budget_cents' => 100 ) );

		// Consume the cap with an earlier run this month.
		Run::start( $prompt_id )->reserve_cost( 100 );

		// No mock is registered. Any outbound request now throws.
		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_budget_exceeded', $result->get_error_code() );
	}

	/**
	 * The global cap does the same, and wins over an uncapped prompt.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_global_cap_blocks_before_any_provider_call(): void {
		update_option( Budget_Guard::GLOBAL_CAP_OPTION, 50 );

		$prompt_id = $this->create_prompt( array( 'monthly_budget_cents' => 0 ) );

		Run::start( $this->create_prompt() )->reserve_cost( 50 );

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_budget_exceeded', $result->get_error_code() );
	}

	/**
	 * The blocked run is recorded as skipped_budget and costs nothing.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_blocked_run_is_recorded_as_skipped(): void {
		$prompt_id = $this->create_prompt( array( 'monthly_budget_cents' => 100 ) );
		Run::start( $prompt_id )->reserve_cost( 100 );

		( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_SKIPPED_BUDGET, $row['status'] );
		$this->assertSame( '0', (string) $row['cost_cents'] );
	}

	/**
	 * A run within budget is not blocked.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_run_within_budget_passes_the_guard(): void {
		$prompt = \AutoScribe\Prompts\Prompt::load( $this->create_prompt( array( 'monthly_budget_cents' => 100000 ) ) );
		$guard  = new Budget_Guard();

		$this->assertTrue( $guard->check( $prompt, $guard->estimate_cents( $prompt ) ) );
	}

	/**
	 * Skipped runs do not count toward the month's spend.
	 *
	 * Counting them would let a run blocked for being over budget push the
	 * total further over, which would make the cap self-reinforcing.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_skipped_runs_are_excluded_from_the_total(): void {
		$prompt_id = $this->create_prompt();

		$counted = Run::start( $prompt_id );
		$counted->reserve_cost( 250 );

		$skipped = Run::start( $prompt_id );
		$skipped->reserve_cost( 999 );
		$skipped->skip( Run::STATUS_SKIPPED_BUDGET, 'over' );

		$this->assertSame( 250, ( new Budget_Guard() )->month_to_date_cents( $prompt_id ) );
	}

	/**
	 * The reservation is visible to a concurrent run.
	 *
	 * Action Scheduler executes a batch concurrently, so a guard that only read
	 * completed runs would let every prompt in the batch pass the same cap.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_a_reservation_counts_before_the_run_finishes(): void {
		$prompt_id = $this->create_prompt();

		$in_flight = Run::start( $prompt_id );
		$in_flight->reserve_cost( 400 );

		$this->assertSame( 400, ( new Budget_Guard() )->month_to_date_cents( $prompt_id ) );
	}

	/**
	 * The warning email fires once per month, not once per run.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_warning_is_sent_only_once_per_month(): void {
		update_option( Budget_Guard::GLOBAL_CAP_OPTION, 1000 );
		Run::start( $this->create_prompt() )->reserve_cost( 900 );

		$guard = new Budget_Guard();

		$this->assertTrue( $guard->should_send_warning() );
		$this->assertFalse( $guard->should_send_warning() );
	}

	/**
	 * Costs round up, so a cap cannot be beaten by fractions of a cent.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_cost_rounds_up(): void {
		$pricing = new Pricing_Table();

		// One token at $5 per million is $0.000005, which must not round to zero.
		$this->assertSame( 1, $pricing->cost_cents( 'claude-opus-5', 1, 0 ) );
	}

	/**
	 * An unknown model still costs something, so the cap keeps working.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_unknown_model_falls_back_to_a_nonzero_rate(): void {
		$this->assertGreaterThan(
			0,
			( new Pricing_Table() )->cost_cents( 'some-model-released-next-year', 100000, 100000 )
		);
	}
}
