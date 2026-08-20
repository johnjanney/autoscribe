<?php
/**
 * Tests for money spent by a worker that never came back.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Tests\Support\Mocks_Provider;
use AutoScribe\Tests\Support\Refuses_Writes;
use WP_UnitTestCase;

/**
 * Covers the cost floor a released claim leaves behind.
 *
 * A worker killed inside a paid call has been charged for it and has recorded
 * nothing. Version 1.2.0 kept the reservation when a sweep gave up on such a
 * run, which covered the run nobody minds losing and not the one everybody wants
 * to succeed: the restart. Settlement after a successful restart measured only
 * what the replacement spent, so the first call left the month-to-date total
 * that the section 7.4 cap reads.
 *
 * @since 1.3.0
 */
final class Interrupted_ChargeTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;
	use Refuses_Writes;

	/**
	 * Gives the providers keys so runs reach their paid calls.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Re-arms the tripwire between tests.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Releasing a claim records that this run may have spent more than it shows.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_releasing_a_claim_records_a_cost_floor(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->reserve_cost( 250 ) );
		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertSame( 0, Run::load( $run_id )->cost_floor(), 'Nothing is floored until a claim is lost.' );

		$observed = Run::load( $run_id )->raw_step();

		$this->assertTrue( Run::load( $run_id )->release_claim( $observed ) );
		$this->assertSame(
			250,
			Run::load( $run_id )->cost_floor(),
			'The reservation standing when the claim was released is the floor.'
		);
	}

	/**
	 * A run that succeeds after a restart still settles at its floor.
	 *
	 * This is the sequence 1.2.0 left open: the interrupted call is invisible to
	 * everything that runs afterwards, so the only way it can be counted is for
	 * the release to record that it happened.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_successful_restart_cannot_settle_below_the_floor(): void {
		global $wpdb;

		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Budget check, then the topic step: the first paid call.
		$handler->handle_step( $run_id );

		$reserved = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		// A worker claims the paid step, is charged, and never comes back.
		$killed = Run::load( $run_id );

		$this->assertTrue( $killed->claim_step( $killed->step() ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		// The queue lost the action along with the worker, which is how a stalled
		// run is told apart from a slow one.
		( new Scheduler() )->cancel_step_actions( $run_id );

		$sweeper = new Stall_Sweeper( new Scheduler(), $handler );

		$this->assertTrue( $sweeper->recover( $run_id, Run::load( $run_id )->raw_step() ) );
		$this->assertSame( $reserved, Run::load( $run_id )->cost_floor() );

		// The replacement finishes the run normally.
		for ( $i = 0; $i < 8; $i++ ) {
			$row = Run::latest_for_prompt( $prompt_id );

			if ( Run::STATUS_RUNNING !== $row['status'] ) {
				break;
			}

			$handler->handle_step( $run_id );
		}

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );
		$this->assertGreaterThanOrEqual(
			$reserved,
			(int) $row['cost_cents'],
			'A run interrupted inside a paid call must not settle below what it had reserved.'
		);
	}

	/**
	 * Two workers on one run add their spending rather than overwriting it.
	 *
	 * The counters used to be read, added to in PHP, and written back whole, so a
	 * stale worker and its replacement each wrote a total computed before the
	 * other's — and one call's tokens disappeared from the figure the cap reads.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_two_workers_both_have_their_spending_counted(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$first  = Run::load( $run_id );
		$second = Run::load( $run_id );

		// Both read the row before either has written to it.
		$this->assertFalse( $first->has_usage() );
		$this->assertFalse( $second->has_usage() );

		$this->assertTrue( $first->record_text_usage( 'claude-opus-5', 100, 400 ) );
		$this->assertTrue( $second->record_text_usage( 'claude-opus-5', 100, 400 ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 200, (int) $row['input_tokens'] );
		$this->assertSame( 800, (int) $row['output_tokens'] );
	}

	/**
	 * Two images bought for one run are counted as two.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_two_images_are_counted_twice(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$this->assertTrue( Run::load( $run->id() )->record_image( 'gpt-image-2' ) );
		$this->assertTrue( Run::load( $run->id() )->record_image( 'gpt-image-2' ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 2, (int) $row['image_count'], 'A picture billed twice is not one picture.' );
	}
	/**
	 * Usage that arrives after the run closed still reaches the monthly cap.
	 *
	 * The counters are unfenced on purpose — a billed call is billed whoever made
	 * it — but the figure the cap reads is `cost_cents`, which a closed run
	 * computed before the late counters existed. Recording the spending in the run
	 * log and not in the total that enforces the cap is only half a mechanism.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function test_late_usage_raises_a_closed_run_s_settled_cost(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'claude-opus-5' ) ) ) );

		$this->assertTrue( $run->reserve_cost( 100 ) );
		$this->assertTrue( $run->claim_step( '' ) );

		// A sweep gives up on the run while this worker is still inside its call.
		$observed = Run::load( $run->id() )->raw_step();

		$this->assertTrue( Run::load( $run->id() )->fail( 'Given up on.', null, 0, $observed )->ended() );

		$closed = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		// The worker returns, having been charged for a large call.
		$this->assertTrue( $run->record_text_usage( 'claude-opus-5', 10000000, 10000000 ) );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 10000000, (int) $row['input_tokens'] );
		$this->assertGreaterThan(
			$closed,
			(int) $row['cost_cents'],
			'A closed run whose counters grew must settle for more than it did before.'
		);
		$this->assertSame(
			(int) $row['cost_cents'],
			( new Budget_Guard() )->month_to_date_cents( $prompt_id ),
			'What the run log shows and what the cap reads have to be the same number.'
		);
	}

	/**
	 * An image bought after the run closed is priced into the closed run.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function test_a_late_image_raises_the_settled_cost(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'gpt-image-2' ) ) ) );

		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertTrue(
			Run::load( $run->id() )->fail( 'Given up on.', null, 0, Run::load( $run->id() )->raw_step() )->ended()
		);
		$this->assertSame( 0, (int) Run::latest_for_prompt( $prompt_id )['cost_cents'] );

		$this->assertTrue( $run->record_image( 'gpt-image-2' ) );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 1, (int) $row['image_count'] );
		$this->assertGreaterThan( 0, (int) $row['cost_cents'], 'A picture that was billed for costs something.' );
	}

	/**
	 * A grounded call made after the run closed is charged for too.
	 *
	 * The surcharge used to live in the payload document, which is fenced by the
	 * claim and by the run being open — so a worker whose run had been closed
	 * under it could not record the search it had just paid for at all.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function test_a_late_grounded_call_is_recorded_and_priced(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'claude-opus-5' ) ) ) );

		$this->assertTrue( $run->claim_step( '' ) );
		// Large enough that the surcharge is visible past the rounding: the table
		// rounds a run up to whole cents, and a search costs one.
		$this->assertTrue( $run->record_text_usage( 'claude-opus-5', 1000000, 1000000 ) );
		$this->assertTrue(
			Run::load( $run->id() )->fail( 'Given up on.', null, 0, Run::load( $run->id() )->raw_step() )->ended()
		);

		$closed = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		$this->assertTrue( $run->record_grounded_call() );
		$this->assertSame( 1, Run::load( $run->id() )->grounded_calls() );
		$this->assertGreaterThan(
			$closed,
			(int) Run::latest_for_prompt( $prompt_id )['cost_cents'],
			'A search the provider billed for is part of what the run cost.'
		);
	}

	/**
	 * Two late increments both end up in the settled figure.
	 *
	 * Each reconciliation measures the row as it stands and raises the cost with
	 * GREATEST, so whichever lands second carries both.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function test_two_late_increments_are_both_counted(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'gpt-image-2' ) ) ) );

		$first  = Run::load( $run->id() );
		$second = Run::load( $run->id() );

		$this->assertTrue( $run->fail( 'Given up on.' )->ended() );

		$this->assertTrue( $first->record_image( 'gpt-image-2' ) );

		$after_one = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		$this->assertTrue( $second->record_image( 'gpt-image-2' ) );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 2, (int) $row['image_count'] );
		$this->assertGreaterThan(
			$after_one,
			(int) $row['cost_cents'],
			'Two pictures billed is two pictures counted.'
		);
	}
	/**
	 * A reconciliation that never ran leaves the row saying so, and is repaired.
	 *
	 * Recording money and pricing it are two statements, and a process can die
	 * between them. What makes that survivable is that the first statement flags
	 * the row: the money is on the run either way, and something later prices it.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function test_an_interrupted_reconciliation_is_repaired_later(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'claude-opus-5' ) ) ) );

		$this->assertTrue( $run->fail( 'Given up on.' )->ended() );

		$closed = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		// The counter lands and the pricing statement does not, which is what a
		// process killed between the two leaves behind.
		$this->with_refused(
			'SET cost_cents = GREATEST',
			fn() => $run->record_text_usage( 'claude-opus-5', 1000000, 1000000 )
		);

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 1000000, (int) $row['input_tokens'], 'The money is recorded whatever happens next.' );
		$this->assertSame( $closed, (int) $row['cost_cents'], 'And its price has not been worked out yet.' );
		$this->assertSame( 1, (int) $row['cost_stale'], 'The row says it owes a reconciliation.' );
		$this->assertSame( array( $run->id() ), Run::unsettled() );

		// Any later pass — a budget check, a sweep — finishes the job.
		$this->assertSame( 1, Run::settle_unsettled() );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 0, (int) $row['cost_stale'] );
		$this->assertGreaterThan( $closed, (int) $row['cost_cents'] );
		$this->assertSame(
			(int) $row['cost_cents'],
			( new Budget_Guard() )->month_to_date_cents( $prompt_id ),
			'The cap reads the repaired figure.'
		);
	}

	/**
	 * The budget guard repairs before it sums, so a cap cannot be beaten by lag.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function test_the_budget_guard_prices_late_usage_before_summing(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run->merge_payload( array( 'rates' => ( new Pricing_Table() )->snapshot( array( 'claude-opus-5' ) ) ) );

		$this->assertTrue( $run->fail( 'Given up on.' )->ended() );

		$this->with_refused(
			'SET cost_cents = GREATEST',
			fn() => $run->record_text_usage( 'claude-opus-5', 10000000, 10000000 )
		);

		// A cap the unpriced usage exceeds and the priced figure does not.
		update_option( Budget_Guard::GLOBAL_CAP_OPTION, 100 );

		$verdict = ( new Budget_Guard() )->check( Prompt::load( $prompt_id ), 1 );

		delete_option( Budget_Guard::GLOBAL_CAP_OPTION );

		$this->assertWPError( $verdict, 'The guard has to see what the run really spent.' );
		$this->assertSame( 'autoscribe_budget_exceeded', $verdict->get_error_code() );
		$this->assertSame( 0, (int) Run::latest_for_prompt( $prompt_id )['cost_stale'] );
	}

	/**
	 * An open run is never flagged, because settlement has not happened yet.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function test_usage_on_an_open_run_does_not_flag_it(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->record_text_usage( 'claude-opus-5', 1000, 1000 ) );

		$this->assertSame( 0, (int) Run::latest_for_prompt( $prompt_id )['cost_stale'] );
		$this->assertSame( array(), Run::unsettled() );
	}

}
