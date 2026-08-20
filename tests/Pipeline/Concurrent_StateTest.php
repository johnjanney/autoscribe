<?php
/**
 * Tests for state written by two workers at once.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the payload document and the restart counter under concurrency.
 *
 * The payload column holds every step's output, and every writer reads it whole
 * and writes it whole. Two writers with overlapping views therefore do not
 * merge: the later write erases whatever the other one stored. Until 1.2.0 the
 * sweeper was one of those writers — it kept its restart count in the same
 * document — so counting a restart could remove a topic, an article, its
 * sources, or an image outcome that a worker had recorded in between.
 *
 * The interleavings here run inside one process, which is not the same as two
 * connections. What they do exercise is the ordering the guards depend on: a
 * stale view, a conditional write, and which of the two writers wins.
 *
 * @since 1.2.0
 */
final class Concurrent_StateTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * A sweeper no longer writes the payload at all.
	 *
	 * The restart count lives in its own column, so recording one cannot carry a
	 * stale copy of the document back over a worker's output.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_counting_a_restart_leaves_the_payload_alone(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		// A sweeper reads the run, and the worker records its topic afterwards.
		$sweeper = Run::load( $run_id );

		$this->assertSame( array(), $sweeper->payload() );

		Run::load( $run_id )->merge_payload( array( 'topic' => array( 'title' => 'Water' ) ) );

		$this->assertTrue( $sweeper->record_sweep( 0 ) );

		$payload = Run::load( $run_id )->payload();

		$this->assertSame(
			array( 'title' => 'Water' ),
			$payload['topic'] ?? null,
			'A restart count must not remove state recorded after the sweeper read the run.'
		);
		$this->assertSame( 1, Run::load( $run_id )->sweeps() );
	}

	/**
	 * Only one of two sweeps holding the same view restarts a run.
	 *
	 * A candidate scan can be many pages old by the time it is acted on, so two
	 * sweeps can both find the same idle run. Before the count was a
	 * compare-and-swap, both armed a restart and the run was left with two workers
	 * racing for its next step.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_two_sweeps_share_one_restart(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$first  = Run::load( $run->id() );
		$second = Run::load( $run->id() );

		// Both read the same count before either has written one.
		$this->assertSame( 0, $first->sweeps() );
		$this->assertSame( 0, $second->sweeps() );

		$this->assertTrue( $first->record_sweep( 0 ) );
		$this->assertFalse( $second->record_sweep( 0 ), 'The second sweep must find its view stale.' );

		$this->assertSame( 1, Run::load( $run->id() )->sweeps() );
	}

	/**
	 * A restart is not counted against a run that has already closed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_closed_run_takes_no_further_restarts(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->fail( 'done' )->ended() );
		$this->assertFalse( Run::load( $run->id() )->record_sweep( 0 ) );
	}

	/**
	 * A worker that has been swept and replaced cannot write over its successor.
	 *
	 * This is the interleaving that made the old payload race expensive rather
	 * than merely untidy. A worker slow enough to be judged gone is not
	 * necessarily gone: it can return from its provider call after the sweeper has
	 * released its claim and a replacement has recorded real state.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_replaced_worker_cannot_overwrite_its_successor(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();
		$slow   = Run::load( $run_id );

		$this->assertTrue( $slow->claim_step( '' ) );

		// A sweep decides the worker is gone and releases what it saw.
		$observed = Run::load( $run_id )->raw_step();

		$this->assertTrue( Run::load( $run_id )->release_claim( $observed ) );

		// The replacement claims the same position and records its work.
		$replacement = Run::load( $run_id );

		$this->assertTrue( $replacement->claim_step( '' ) );
		$this->assertTrue( $replacement->merge_payload( array( 'topic' => array( 'title' => 'Grind' ) ) ) );

		// The slow worker finally returns.
		$this->assertFalse(
			$slow->merge_payload( array( 'topic' => array( 'title' => 'Water' ) ) ),
			'A worker whose claim was taken away must not be able to write.'
		);
		$this->assertFalse(
			$slow->record_step( 'propose_topic' ),
			'Nor may it move the position, which would free a live claim.'
		);

		$payload = Run::load( $run_id )->payload();

		$this->assertSame( array( 'title' => 'Grind' ), $payload['topic'] ?? null );
		$this->assertTrue(
			$replacement->holds_claim(),
			'The replacement still owns the position it claimed.'
		);
	}

	/**
	 * Re-writing the same payload under a held claim still counts as written.
	 *
	 * A conditional update that matches its own row without changing anything
	 * reports no affected rows, which is indistinguishable from a lost claim
	 * unless the claim is checked. Reading it as a failure would stop runs for
	 * recording state they had already recorded.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_an_unchanged_payload_write_is_not_a_failure(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertTrue( $run->merge_payload( array( 'topic' => array( 'title' => 'Water' ) ) ) );
		$this->assertTrue( $run->merge_payload( array( 'topic' => array( 'title' => 'Water' ) ) ) );
	}

	/**
	 * A stalled run holding a claim is not settled below its reservation.
	 *
	 * A run interrupted mid-step may have paid for work whose usage write never
	 * landed. Settling it from the counters alone writes a figure that is known to
	 * be incomplete, and the monthly cap then has real spending missing from it
	 * for the rest of the month.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_an_interrupted_claim_keeps_its_reservation(): void {
		global $wpdb;

		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->reserve_cost( 250 ) );
		$this->assertTrue( $run->claim_step( '' ) );

		Run::load( $run_id )->merge_payload( array( 'sweeps' => Stall_Sweeper::MAX_RESTARTS ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$sweeper = new Stall_Sweeper(
			new Scheduler(),
			new Queued_Run_Handler( new Generator( new Provider_Registry() ), new Scheduler(), new Retry_Policy() )
		);

		$this->assertTrue( $sweeper->recover( $run_id, Run::load( $run_id )->raw_step() ) );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame(
			250,
			(int) $row['cost_cents'],
			'A run killed while it held a claim keeps its estimate rather than settling to an unverified zero.'
		);
	}

	/**
	 * A stalled run that was not mid-step settles to what it really spent.
	 *
	 * The conservative floor above applies to interrupted work only. A run with no
	 * claim outstanding has nothing unaccounted for, and holding its estimate
	 * would fill the monthly cap with money nobody spent — which is the failure
	 * the sweeper exists to prevent.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_stalled_run_between_steps_releases_its_reservation(): void {
		global $wpdb;

		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->reserve_cost( 250 ) );
		$this->assertTrue( $run->record_step( 'budget_check' ) );

		Run::load( $run_id )->merge_payload( array( 'sweeps' => Stall_Sweeper::MAX_RESTARTS ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$sweeper = new Stall_Sweeper(
			new Scheduler(),
			new Queued_Run_Handler( new Generator( new Provider_Registry() ), new Scheduler(), new Retry_Policy() )
		);

		$this->assertTrue( $sweeper->recover( $run_id, 'budget_check' ) );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( 0, (int) $row['cost_cents'], 'Nothing was spent, so nothing is held.' );
	}
}
