<?php
/**
 * Tests for prompts that fall out of the queue.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Scheduling;

use AutoScribe\Admin\Next_Run_Readout;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Schedule_Sweeper;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the standing question nothing used to ask.
 *
 * A prompt is armed when it is saved and when one of its runs concludes. Both
 * are events, and an event that does not happen leaves nothing behind: an action
 * killed by a PHP timeout is recorded as failed and not retried, so a prompt can
 * simply stop. If the request died before the run row existed, the stall sweep
 * cannot find it either — it looks for open runs, and there is no run.
 *
 * The editor made that worse rather than revealing it, because its next-run
 * readout was a calculation rather than a report: it showed the occurrence the
 * schedule would have had, whether or not anything was going to run it.
 *
 * @since 1.10.0
 */
final class Schedule_SweeperTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * An enabled prompt with nothing queued is armed again.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_prompt_that_fell_out_of_the_queue_is_armed_again(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		// What a killed action leaves behind: an enabled prompt, nothing queued,
		// and no run row for the stall sweep to find.
		$scheduler->cancel( $prompt_id );

		$this->assertNull( $scheduler->next_scheduled( $prompt_id ), 'Nothing is queued to begin with.' );

		$this->assertTrue( ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id ) );
		$this->assertIsInt(
			$scheduler->next_scheduled( $prompt_id ),
			'The sweep arms a prompt nothing else was going to run.'
		);
		$this->assertGreaterThan(
			0,
			Prompt::load( $prompt_id )->next_run_ts(),
			'And the readout the editor caches is brought back in step with it.'
		);
	}

	/**
	 * A prompt that is already queued is left alone.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_queued_prompt_is_left_alone(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();
		$armed     = $scheduler->next_scheduled( $prompt_id );

		$this->assertIsInt( $armed, 'Saving the prompt queued it.' );
		$this->assertFalse( ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id ) );
		$this->assertSame( $armed, $scheduler->next_scheduled( $prompt_id ), 'And it was not moved.' );
	}

	/**
	 * A prompt with a run in flight is left alone.
	 *
	 * Its next occurrence is armed when that run concludes. Arming one now would
	 * put a second article beside the one being written.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_prompt_with_a_run_in_flight_is_left_alone(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		$scheduler->cancel( $prompt_id );

		$run = Run::start( $prompt_id );

		$this->assertNotWPError( $run );
		$this->assertFalse( ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id ) );
		$this->assertNull( $scheduler->next_scheduled( $prompt_id ) );
	}

	/**
	 * A disabled prompt is not put back in the queue.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_disabled_prompt_is_not_armed(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		$scheduler->cancel( $prompt_id );
		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		$this->assertFalse( ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id ) );
		$this->assertNull( $scheduler->next_scheduled( $prompt_id ) );
	}

	/**
	 * A schedule that does not validate is left for a person.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_an_invalid_schedule_is_left_for_a_person(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		$scheduler->cancel( $prompt_id );
		update_post_meta( $prompt_id, '_autoscribe_schedule_params', array( 'time' => 'not a time' ) );

		$this->assertFalse( ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id ) );
		$this->assertStringContainsString(
			'not valid',
			Next_Run_Readout::describe( $prompt_id ),
			'And the editor says why rather than showing a time.'
		);
	}

	/**
	 * The sweep finds enabled prompts without being told which they are.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_the_sweep_finds_prompts_by_itself(): void {
		$scheduler = new Scheduler();
		$first     = $this->daily_prompt();
		$second    = $this->daily_prompt();

		$scheduler->cancel( $first );
		$scheduler->cancel( $second );

		$this->assertGreaterThanOrEqual( 2, ( new Schedule_Sweeper( $scheduler ) )->handle() );
		$this->assertIsInt( $scheduler->next_scheduled( $first ) );
		$this->assertIsInt( $scheduler->next_scheduled( $second ) );
	}

	/**
	 * The readout reports the queue rather than the calendar.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_the_readout_says_when_nothing_is_queued(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		$this->assertStringNotContainsString(
			'Not queued',
			Next_Run_Readout::describe( $prompt_id ),
			'A queued prompt reports its queued time.'
		);

		$scheduler->cancel( $prompt_id );

		$this->assertStringContainsString(
			'Not queued yet',
			Next_Run_Readout::describe( $prompt_id ),
			'A prompt nothing is going to run must not display a confident next run.'
		);
	}

	/**
	 * Creates an enabled daily prompt and lets the save path queue it.
	 *
	 * @since 1.10.0
	 *
	 * @return int
	 */
	private function daily_prompt(): int {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		( new Scheduler() )->rearm( $prompt_id, Prompt::load( $prompt_id )->schedule() );

		return $prompt_id;
	}

	/**
	 * A read that fails is not evidence that no run is in flight.
	 *
	 * The accounting guard learned this in 1.9.0 and the scheduling code repeated
	 * it: `get_var()` answers null both for "no such row" and for "the query did
	 * not run", and arming a prompt on the second reading starts a second paid run
	 * beside one that may well be working.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public function test_an_unreadable_open_run_check_does_not_arm(): void {
		global $wpdb;

		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();

		$scheduler->cancel( $prompt_id );

		$break = static function ( $query ) {
			$sql = (string) $query;

			return str_contains( $sql, 'autoscribe_runs' ) && str_contains( $sql, "status = 'running'" )
				? 'SELECT id FROM autoscribe_no_such_table'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$unknown = Run::has_open_run( $prompt_id );
		$armed   = ( new Schedule_Sweeper( $scheduler ) )->rearm( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertNull( $unknown, 'The check reports unknown rather than false.' );
		$this->assertFalse( $armed, 'And unknown must not mean absent.' );
		$this->assertNull( $scheduler->next_scheduled( $prompt_id ) );
	}

	/**
	 * Every enabled prompt gets a turn, however many there are.
	 *
	 * The first version selected a fixed first page on every pass, so a prompt
	 * beyond it was not delayed — it was excluded, permanently, until the post
	 * ordering happened to change.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public function test_a_prompt_beyond_the_first_page_is_still_reached(): void {
		$scheduler = new Scheduler();
		$prompts   = array();

		for ( $i = 0; $i < Schedule_Sweeper::BATCH + 3; $i++ ) {
			$prompts[] = $this->daily_prompt();
		}

		foreach ( $prompts as $prompt_id ) {
			$scheduler->cancel( $prompt_id );
		}

		$sweeper = new Schedule_Sweeper( $scheduler );
		$last    = (int) end( $prompts );

		// The first pass cannot reach the last prompt; a later one must.
		$sweeper->handle();

		$this->assertNull( $scheduler->next_scheduled( $last ), 'It is beyond the first page.' );

		$sweeper->handle();

		$this->assertIsInt(
			$scheduler->next_scheduled( $last ),
			'The cursor carries the scan on rather than starting it again.'
		);
	}

	/**
	 * The scan starts over once it reaches the end.
	 *
	 * @since 1.11.0
	 *
	 * @return void
	 */
	public function test_the_cursor_wraps_when_the_scan_reaches_the_end(): void {
		$scheduler = new Scheduler();
		$prompt_id = $this->daily_prompt();
		$sweeper   = new Schedule_Sweeper( $scheduler );

		$sweeper->handle();

		update_option( Schedule_Sweeper::CURSOR_OPTION, $prompt_id + 1000 );
		$scheduler->cancel( $prompt_id );

		$this->assertSame(
			1,
			$sweeper->handle(),
			'A cursor past the end wraps to the beginning rather than skipping a pass.'
		);
		$this->assertIsInt( $scheduler->next_scheduled( $prompt_id ) );
	}
}
