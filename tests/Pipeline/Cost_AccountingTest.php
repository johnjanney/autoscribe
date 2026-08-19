<?php
/**
 * Run cost accounting tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Run;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers what a run records as having spent.
 *
 * Section 7.4 makes the monthly cap the plugin's only defence against a runaway
 * prompt, and a cap can only be as good as the arithmetic feeding it. Each test
 * here pins one way the total used to come out lower than the provider's bill.
 *
 * @since 1.0.1
 */
final class Cost_AccountingTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Clears budget and pricing state between tests.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Budget_Guard::GLOBAL_CAP_OPTION );
		delete_option( Pricing_Table::OPTION );

		parent::tear_down();
	}

	/**
	 * Token usage from successive calls adds up rather than replacing.
	 *
	 * The pipeline records usage once per provider call. Assigning instead of
	 * adding meant the body call erased the proposal call's tokens, and the
	 * settled cost silently omitted every proposal the run had paid for.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_usage_accumulates_across_calls(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run->record_text_usage( 'claude-opus-5', 1000, 200 );
		$run->record_text_usage( 'claude-opus-5', 3000, 900 );

		$row = $this->row( $run->id() );

		$this->assertSame( 4000, (int) $row['input_tokens'] );
		$this->assertSame( 1100, (int) $row['output_tokens'] );
	}

	/**
	 * A duplicate skip keeps the cost of the proposals it already paid for.
	 *
	 * Writing a flat zero made that money invisible to the cap, so a prompt stuck
	 * proposing repeats could spend without the monthly total ever moving.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_duplicate_skip_records_what_the_proposals_cost(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run->record_text_usage( 'claude-opus-5', 4000, 1024 );
		$run->skip( Run::STATUS_SKIPPED_DUPLICATE, 'Already covered.' );

		$row = $this->row( $run->id() );

		$this->assertSame( Run::STATUS_SKIPPED_DUPLICATE, $row['status'] );
		$this->assertGreaterThan( 0, (int) $row['cost_cents'] );
	}

	/**
	 * A budget skip, which spends nothing, still settles to zero.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_budget_skip_costs_nothing(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run->reserve_cost( 250 );
		$run->skip( Run::STATUS_SKIPPED_BUDGET, 'Over the cap.' );

		$this->assertSame( 0, (int) $this->row( $run->id() )['cost_cents'] );
	}

	/**
	 * A failure replaces the reservation with what was really spent.
	 *
	 * Keeping the estimate meant a run that fell over on its first call still
	 * counted a whole article and image against the cap, so a string of transport
	 * failures could exhaust the month's budget having produced nothing.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_failure_settles_rather_than_keeping_the_reservation(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run->reserve_cost( 9999 );
		$run->fail( 'The provider was unreachable.' );

		$this->assertSame( 0, (int) $this->row( $run->id() )['cost_cents'] );
	}

	/**
	 * Spend that a skipped run really incurred counts towards the cap.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_duplicate_spend_counts_towards_the_monthly_total(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->record_text_usage( 'claude-opus-5', 4000, 1024 );
		$run->skip( Run::STATUS_SKIPPED_DUPLICATE, 'Already covered.' );

		$spent = (int) $this->row( $run->id() )['cost_cents'];

		$this->assertSame( $spent, ( new Budget_Guard() )->month_to_date_cents() );
	}

	/**
	 * The preflight estimate covers every paid call the pipeline can make.
	 *
	 * It previously priced a single body call while the pipeline can make four
	 * text calls — two proposals, the body, and one repair — so a run could cost
	 * roughly twice what had been reserved for it.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_estimate_covers_proposal_and_repair_calls(): void {
		$prompt = \AutoScribe\Prompts\Prompt::load( $this->create_prompt() );

		$this->assertNotNull( $prompt );

		$pricing   = new Pricing_Table();
		$estimate  = ( new Budget_Guard( $pricing ) )->estimate_cents( $prompt );
		$body_only = $pricing->cost_cents( 'claude-opus-5', 2000, 2400, '', 0, 0 );

		$this->assertGreaterThan(
			$body_only,
			$estimate,
			'The estimate must bound the worst case, not one body call'
		);
	}

	/**
	 * Returns one run row.
	 *
	 * @since 1.0.1
	 *
	 * @param int $run_id Run row ID.
	 * @return array<string, mixed>
	 */
	private function row( int $run_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				\AutoScribe\Activation::table_name(),
				$run_id
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );

		return $row;
	}

	/**
	 * A run advanced across actions settles what it really spent.
	 *
	 * Usage is accumulated in memory and written out, which is correct only while
	 * one object sees every call. Under the queue driver each action gets a fresh
	 * Run, so the tokens of one step overwrote the last step's, and the object
	 * that settles the cost saw no usage at all and replaced the reservation with
	 * zero. Every scheduled run then reported spending nothing, the monthly total
	 * never moved, and the section 7.4 cap could not fire.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_usage_survives_being_reloaded_between_actions(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->record_text_usage( 'claude-opus-5', 1000, 2000 );

		// A later action: same row, different object, as the queue produces.
		$reloaded = Run::load( $run->id() );

		$this->assertInstanceOf( Run::class, $reloaded );

		$reloaded->record_text_usage( 'claude-opus-5', 500, 700 );

		$this->assertTrue( $reloaded->has_usage(), 'A reloaded run must see what it already spent.' );

		$settled = Run::load( $run->id() );

		$this->assertInstanceOf( Run::class, $settled );
		$this->assertTrue( $settled->has_usage(), 'The object that settles the cost must see the usage.' );

		$cost = $settled->settle_cost( new Pricing_Table() );

		$this->assertGreaterThan( 0, $cost, 'A run that made paid calls must not settle to zero.' );

		// Accumulated, not overwritten: 1500 in and 2700 out.
		$this->assertSame(
			( new Pricing_Table() )->cost_cents( 'claude-opus-5', 1500, 2700 ),
			$cost
		);
	}
}
