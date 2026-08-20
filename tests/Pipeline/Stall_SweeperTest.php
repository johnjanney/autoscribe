<?php
/**
 * Stall recovery tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers recovery from a step the queue stopped advancing.
 *
 * Splitting the pipeline did not give recovery on its own: Action Scheduler
 * records a killed action as failed and stops, so a step killed by a PHP timeout
 * leaves a run open with nothing queued to advance it. The split shortened the
 * window from a whole article to one provider call; this is what closes it.
 *
 * The reservation is the part that matters most. Step_Budget_Check writes the
 * estimated cost onto the run before the first paid call so concurrent runs can
 * see it, and section 7.4's cap reads every open run's reservation — so a run
 * abandoned mid-flight holds its estimate against the cap for ever. The cap
 * silently fills with money nobody spent and prompts start skipping for no
 * visible reason. That failure mode did not exist before the split.
 *
 * See docs/PIPELINE-SPLIT.md phase 5.
 *
 * @since 1.1.0
 */
final class Stall_SweeperTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the provider a key so a run can reach its steps.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A run that is simply waiting its turn is left alone.
	 *
	 * The first thing the sweeper has to get right. Age cannot tell a stalled run
	 * from a slow one — a legitimate run takes several queue passes — so acting
	 * on age alone would cut short runs that are working perfectly well.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_run_still_queued_is_left_alone(): void {
		$run_id = $this->open_stale_run();

		( new Scheduler() )->schedule_step( $run_id );

		$this->assertSame( 0, $this->sweeper()->handle() );
		$this->assertSame( Run::STATUS_RUNNING, Run::load( $run_id )->status() );
	}

	/**
	 * A run too young to judge is left alone.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_young_run_is_left_alone(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );
		$this->assertSame( 0, $this->sweeper()->handle(), 'A run opened a moment ago cannot be stalled.' );
	}

	/**
	 * A stalled run is restarted rather than abandoned.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_stalled_run_is_restarted(): void {
		$run_id = $this->open_stale_run();

		$this->assertSame( 1, $this->sweeper()->handle() );

		$this->assertSame(
			Run::STATUS_RUNNING,
			Run::load( $run_id )->status(),
			'A first stall is a restart, not a write-off.'
		);
		$this->assertTrue(
			( new Scheduler() )->has_step_action( $run_id ),
			'The run should have an action to advance it again.'
		);
		$this->assertSame( 1, Run::load( $run_id )->sweeps() );
	}

	/**
	 * A run that keeps stalling is given up on, and gives back its reservation.
	 *
	 * The reason the sweeper exists. Until it did, an abandoned run held its
	 * estimate against the monthly cap for ever.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_repeatedly_stalled_run_is_closed_and_releases_its_reservation(): void {
		$prompt_id = $this->create_prompt();
		$run_id    = $this->open_stale_run( $prompt_id );

		Run::load( $run_id )->reserve_cost( 5000 );

		$this->assertSame(
			5000,
			( new Budget_Guard() )->month_to_date_cents( $prompt_id ),
			'The reservation should count against the cap while the run is open.'
		);

		// Two restarts, each stalling again because nothing advances the run.
		for ( $sweep = 0; $sweep < Stall_Sweeper::MAX_RESTARTS; $sweep++ ) {
			$this->sweeper()->handle();
			( new Scheduler() )->cancel_step_actions( $run_id );
			$this->age_run( $run_id );
		}

		$this->assertSame( 1, $this->sweeper()->handle() );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'stopped part-way', (string) $row['error'] );
		$this->assertSame(
			0,
			( new Budget_Guard() )->month_to_date_cents( $prompt_id ),
			'A run that spent nothing must give its whole reservation back.'
		);
	}

	/**
	 * Giving up on a run still leaves the prompt able to run again.
	 *
	 * Section 4.3 makes no exception for runs that ended badly, and a swept run
	 * ends outside every path phase 4 covered.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_giving_up_still_arms_the_next_occurrence(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$run_id = $this->open_stale_run( $prompt_id );

		for ( $sweep = 0; $sweep <= Stall_Sweeper::MAX_RESTARTS; $sweep++ ) {
			$this->sweeper()->handle();
			( new Scheduler() )->cancel_step_actions( $run_id );
			$this->age_run( $run_id );
		}

		$this->assertSame( Run::STATUS_FAILED, Run::latest_for_prompt( $prompt_id )['status'] );
		$this->assertNotEmpty(
			as_get_scheduled_actions(
				array(
					'hook'   => Scheduler::HOOK_RUN_PROMPT,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	/**
	 * A stalled run whose prompt is gone is closed rather than restarted.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_stalled_run_without_a_prompt_is_closed(): void {
		$prompt_id = $this->create_prompt();
		$run_id    = $this->open_stale_run( $prompt_id );

		Run::load( $run_id )->reserve_cost( 900 );
		wp_delete_post( $prompt_id, true );

		$this->assertSame( 1, $this->sweeper()->handle() );
		$this->assertSame( Run::STATUS_FAILED, Run::load( $run_id )->status() );
		$this->assertSame( 0, ( new Budget_Guard() )->month_to_date_cents() );
	}

	/**
	 * A stalled run is found even behind a batch of healthy ones.
	 *
	 * The scan takes the oldest open runs, and a busy site can have more healthy
	 * ones than a batch holds. Skipping them inside the loop meant the same
	 * healthy rows were re-read on every sweep and anything newer was never
	 * reached — so under a sustained backlog a stalled run could hold its
	 * reservation against the monthly cap indefinitely, which is the one thing
	 * the sweeper exists to prevent.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_stalled_run_behind_a_full_batch_is_still_found(): void {
		$scheduler = new Scheduler();

		for ( $i = 0; $i < Stall_Sweeper::BATCH; $i++ ) {
			$healthy = $this->open_stale_run();

			$scheduler->schedule_step( $healthy );
		}

		// Newest, so last in the scan order, and nothing is advancing it.
		$stalled = $this->open_stale_run();

		$this->assertSame( 1, $this->sweeper()->handle(), 'The stalled run was never reached.' );
		$this->assertSame( 1, Run::load( $stalled )->sweeps() );
	}

	/**
	 * Giving up on a disabled prompt's run does not arm it again.
	 *
	 * A disabled prompt still loads, so it takes the ordinary give-up path and
	 * gets its next occurrence armed — leaving a prompt somebody switched off
	 * with a queued action and a next-run time the editor will show. The action
	 * cancels itself when it fires, but the state is wrong until then and the
	 * readout says the opposite of what the setting says.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_giving_up_does_not_arm_a_disabled_prompt(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$run_id = $this->open_stale_run( $prompt_id );

		Run::load( $run_id )->merge_payload( array( 'sweeps' => Stall_Sweeper::MAX_RESTARTS ) );
		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		$this->assertSame( 1, $this->sweeper()->handle() );
		$this->assertSame( Run::STATUS_FAILED, Run::load( $run_id )->status() );

		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => Scheduler::HOOK_RUN_PROMPT,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			),
			'A prompt somebody switched off must not come back armed.'
		);
	}

	/**
	 * A sweep that hits its recovery cap carries on from there next time.
	 *
	 * Paging fixed starvation within one sweep and would have left it between
	 * sweeps: without a cursor every sweep restarts at the oldest row, so the
	 * runs beyond one sweep's reach are never examined and keep their
	 * reservations. The same starvation, moved further out.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_sweep_carries_on_from_where_the_last_one_stopped(): void {
		$prompt_id = $this->create_prompt();
		$stalled   = array();

		for ( $i = 0; $i <= Stall_Sweeper::BATCH; $i++ ) {
			$stalled[] = $this->open_stale_run( $prompt_id );
		}

		$this->assertSame( Stall_Sweeper::BATCH, $this->sweeper()->handle(), 'The first sweep should stop at its cap.' );

		$last = (int) end( $stalled );

		$this->assertSame( 0, Run::load( $last )->sweeps(), 'The run past the cap is not reached yet.' );
		$this->assertGreaterThan( 0, (int) get_option( Stall_Sweeper::CURSOR_OPTION ) );

		/*
		 * The restarts stall again. Without this the recovered runs would look
		 * healthy on the next sweep and be skipped anyway, so the test would pass
		 * whether or not the cursor was remembered — which is exactly what the
		 * first version of it did.
		 */
		$scheduler = new Scheduler();

		foreach ( $stalled as $run_id ) {
			$scheduler->cancel_step_actions( $run_id );
		}

		$this->assertSame( 1, $this->sweeper()->handle(), 'The next sweep should pick up where it stopped.' );
		$this->assertSame( 1, Run::load( $last )->sweeps() );
	}

	/**
	 * The cursor starts over once the scan runs out of runs.
	 *
	 * Otherwise it would climb past every ID and the sweeper would quietly stop
	 * looking at anything.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_scan_cursor_wraps_at_the_end(): void {
		$scheduler = new Scheduler();

		$scheduler->schedule_step( $this->open_stale_run() );

		$this->sweeper()->handle();

		$this->assertSame(
			0,
			(int) get_option( Stall_Sweeper::CURSOR_OPTION ),
			'A sweep that reached the end must start over next time.'
		);
	}

	/**
	 * The bulk queue read is not used when another store owns the queue.
	 *
	 * Action Scheduler picks its store through a filter, so a site part way
	 * through a migration, or running the legacy post store, can leave a perfectly
	 * good actionscheduler_actions table behind that nothing writes to any more.
	 * Reading it would report an empty active set and make every open run look
	 * unattended — and a healthy backlog would then fall back to one queue read
	 * per run, which is the two thousand round trips the bulk query removed.
	 *
	 * The store is a cached singleton with no public setter, so the swap is made
	 * through reflection and put back afterwards.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function test_a_replaced_store_falls_back_to_the_public_api(): void {
		$scheduler = new Scheduler();

		$this->assertIsArray(
			$scheduler->active_step_runs(),
			'The database store is the ordinary case, and it does use the bulk read.'
		);

		$property = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );

		$property->setAccessible( true );

		$original = $property->getValue();

		$property->setValue( null, new \ActionScheduler_wpPostStore() );

		$active = $scheduler->active_step_runs();

		$property->setValue( null, $original );

		$this->assertNull(
			$active,
			'A store that is not the database store must fall back rather than read a table it does not use.'
		);
		$this->assertIsArray(
			$scheduler->active_step_runs(),
			'And the ordinary case still works once the store is back.'
		);
	}

	/**
	 * Builds the sweeper under test.
	 *
	 * @since 1.1.0
	 *
	 * @return Stall_Sweeper
	 */
	private function sweeper(): Stall_Sweeper {
		$scheduler = new Scheduler();

		return new Stall_Sweeper(
			$scheduler,
			new Queued_Run_Handler(
				new Generator( new Provider_Registry() ),
				$scheduler,
				new Retry_Policy()
			)
		);
	}

	/**
	 * Opens a run and backdates it past the stall threshold.
	 *
	 * Nothing is queued to advance it, which is what a killed step leaves behind.
	 *
	 * @since 1.1.0
	 *
	 * @param int|null $prompt_id Prompt to run, or null to create one.
	 * @return int The run's ID.
	 */
	private function open_stale_run( ?int $prompt_id = null ): int {
		$run = Run::start( $prompt_id ?? $this->create_prompt() );

		$this->assertNotWPError( $run );

		$this->age_run( $run->id() );

		return $run->id();
	}

	/**
	 * Backdates a run past the stall threshold.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to age.
	 * @return void
	 */
	private function age_run( int $run_id ): void {
		global $wpdb;

		$wpdb->update(
			\AutoScribe\Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
	/**
	 * One sweep reads the queue's active actions once, however many pages it scans.
	 *
	 * The statement cannot filter by run — Action Scheduler stores the arguments
	 * as JSON — so it reads every pending or running step action there is. Doing
	 * that once per page meant a busy site's five-minute recovery task read the
	 * same rows again up to twenty times, on exactly the sites the paging exists
	 * for.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_one_sweep_reads_the_action_table_once(): void {
		$prompt_id = $this->create_prompt();

		// Enough open runs to fill more than one page of the scan.
		for ( $i = 0; $i < Stall_Sweeper::PAGE + 5; $i++ ) {
			$this->age_run( $this->open_stale_run( $prompt_id ) );
		}

		$reads = 0;
		$count = static function ( $query ) use ( &$reads ) {
			$sql = (string) $query;

			// The bulk read, which is the one that returns every active action's
			// arguments. The per-run freshness check is a different statement and
			// is bounded by the recovery batch rather than by the pages scanned.
			if ( str_contains( $sql, 'args FROM' ) && str_contains( $sql, 'autoscribe_run_step' ) ) {
				++$reads;
			}

			return $query;
		};

		add_filter( 'query', $count );

		$this->sweeper()->handle();

		remove_filter( 'query', $count );

		$this->assertLessThanOrEqual(
			1,
			$reads,
			'The active action set is read once for the sweep, not once per page.'
		);
	}
}
