<?php
/**
 * Budget guard tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Cost;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Cost\Spend_Lock;
use AutoScribe\Pipeline\Step_Budget_Check;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
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
		$prompt = Prompt::load( $this->create_prompt( array( 'monthly_budget_cents' => 100000 ) ) );
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

	/**
	 * An image with no model set anywhere is still reserved for.
	 *
	 * Generation resolves a blank image model through the adapter's own
	 * suggestions and gets a real model. Until 1.0.2 the estimate did not, so it
	 * resolved to an empty string, Pricing_Table fell back to the text model's
	 * rates, and the seeded Claude rows carry a zero per-image rate. The result
	 * was a run that reserved nothing for an image it was about to generate — a
	 * hole in the cap that opened on the most ordinary configuration there is,
	 * a Claude article with an OpenAI picture and the model fields left alone.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_an_image_is_priced_even_when_no_image_model_is_set(): void {
		$text_only = $this->create_prompt(
			array(
				'image_mode' => 'none',
			)
		);

		$with_image = $this->create_prompt(
			array(
				'image_mode'     => 'optional',
				'image_provider' => 'openai_image',
				'image_model'    => '',
			)
		);

		$guard = new Budget_Guard();

		$this->assertGreaterThan(
			$guard->estimate_cents( Prompt::load( $text_only ) ),
			$guard->estimate_cents( Prompt::load( $with_image ) ),
			'A prompt that will generate an image must reserve more than one that will not.'
		);
	}

	/**
	 * The spend lock can be taken and released.
	 *
	 * The lock is what makes the read-check-reserve sequence atomic, and 1.0.1
	 * shipped an ordering trick in its place that did not close the race. A unit
	 * test cannot prove mutual exclusion across processes, so this asserts the
	 * one thing it can: the lock is really taken on this database rather than
	 * silently failing and leaving every run on the weaker fallback path.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_the_spend_lock_is_available(): void {
		$lock = new Spend_Lock();

		$this->assertTrue( $lock->acquire(), 'GET_LOCK should be available on the test database.' );
		$this->assertTrue( $lock->held() );

		$lock->release();

		$this->assertFalse( $lock->held() );
	}

	/**
	 * A run whose reservation cannot be written never reaches a provider.
	 *
	 * Until 1.0.2 the write result was discarded, so a failed reservation left
	 * the run spending real money against a cap that could not see the spending.
	 * The reservation UPDATE is redirected to a table that does not exist, which
	 * is the same shape of failure a corrupt or missing runs table would produce.
	 * The tripwire in the bootstrap is what proves no provider was contacted.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_failed_reservation_stops_the_run(): void {
		global $wpdb;

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'cost_cents' ) && str_starts_with( ltrim( (string) $query ), 'UPDATE' )
				? 'UPDATE autoscribe_no_such_table SET cost_cents = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$verdict = ( new Step_Budget_Check() )->run( $prompt, $run );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertWPError( $verdict );
		$this->assertSame( 'autoscribe_reservation_failed', $verdict->get_error_code() );
	}
}
