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
}
