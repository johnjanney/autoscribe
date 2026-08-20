<?php
/**
 * Tests that let Action Scheduler dispatch a complete chain.
 *
 * @package AutoScribe;
 */

namespace AutoScribe\Tests\Scheduling;

use ActionScheduler_QueueRunner;
use ActionScheduler_Store;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * The queue, running the plugin, rather than the plugin pretending to be the queue.
 *
 * Every other test of the pipeline advances a run by calling the handler
 * directly, one step at a time. That is a faithful description of what the queue
 * does and it takes the queue's word for everything: that the hooks are
 * registered, that the arguments survive being encoded into an action row and
 * read back, that a step arming its successor from inside itself produces an
 * action the runner will pick up in the same pass, and that the chain ends with
 * the next occurrence armed rather than with a prompt nobody will run again.
 *
 * None of that was covered, and one of them — a prompt left unqueued — is the
 * defect that was reported from a live site in 1.10.0.
 *
 * These tests hand the work to `ActionScheduler_QueueRunner` and assert on what
 * comes out. They are slower than the direct tests and they are not a substitute
 * for them: a failure here says "the chain did not complete" without saying
 * where, which is what the step-by-step tests are for.
 *
 * @since 1.13.0
 */
final class Queue_DispatchTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * How many times the runner is asked to work before the test gives up.
	 *
	 * One call processes actions until it runs out, including ones armed while it
	 * is running, so a healthy chain finishes in one. The rest is headroom for a
	 * batch limit rather than an expectation.
	 *
	 * @since 1.13.0
	 * @var int
	 */
	private const PASSES = 8;

	/**
	 * The longest a drain waits for an action to come due, in seconds.
	 *
	 * Long enough for the second the plugin puts between arming an action and
	 * running it, and far short of the next scheduled occurrence.
	 *
	 * @since 1.13.0
	 * @var int
	 */
	private const MAX_WAIT = 3;

	/**
	 * Gives the providers keys so the chain reaches its paid calls.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );

		$this->empty_the_queue();

		add_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'generous_time_limit' ) );
	}

	/**
	 * Re-arms the tripwire between tests.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'generous_time_limit' ) );

		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Stops the runner counting the rest of the suite against its time limit.
	 *
	 * Action Scheduler's runner is a singleton, and it measures elapsed time from
	 * when it was *constructed* rather than from when a batch started. In a web
	 * request those are the same instant. In a test suite the object is built
	 * once and reached hundreds of tests later, so the runner opens a batch,
	 * decides it is nearly out of its thirty seconds, and stops — leaving a
	 * half-finished chain and a run still marked running.
	 *
	 * That is a property of the harness rather than of the plugin, and it fails
	 * by suite position: these tests passed alone and failed in the full run on
	 * the day a file was added ahead of them. The limit is lifted for the
	 * duration, so what the drain observes is the chain rather than the clock.
	 *
	 * @since 1.13.1
	 *
	 * @return int Seconds.
	 */
	public function generous_time_limit(): int {
		return HOUR_IN_SECONDS;
	}

	/**
	 * The queue runs a prompt from an armed action to a published post.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function test_the_queue_runs_a_prompt_to_a_published_post(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$scheduler = new Scheduler();

		// What "Run now" queues: this occurrence, immediately.
		$this->assertIsInt( $scheduler->schedule_retry( $prompt_id, 0 ) );

		$this->drain();

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row, 'The queue opened a run.' );
		$this->assertSame(
			Run::STATUS_SUCCESS,
			$row['status'],
			'The whole chain ran: every step armed the next one and the queue picked it up.'
		);

		$post_id = (int) $row['post_id'];

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame(
			(string) $row['id'],
			(string) get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true )
		);
	}

	/**
	 * The chain leaves the prompt queued for its next occurrence.
	 *
	 * The half of section 4.3 that nothing end-to-end had ever checked, and the
	 * half a live site noticed was missing: a prompt that runs once and is never
	 * armed again looks exactly like a prompt that works.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function test_a_dispatched_run_leaves_the_next_occurrence_armed(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$scheduler = new Scheduler();

		$scheduler->cancel( $prompt_id );
		$this->assertIsInt( $scheduler->schedule_retry( $prompt_id, 0 ) );

		$this->drain();

		$this->assertSame( Run::STATUS_SUCCESS, Run::latest_for_prompt( $prompt_id )['status'] );

		$next = $scheduler->next_scheduled( $prompt_id );

		$this->assertIsInt( $next, 'A run that finishes arms the one after it.' );
		$this->assertGreaterThan( time(), $next );
		$this->assertSame(
			$next,
			Prompt::load( $prompt_id )->next_run_ts(),
			'And the editor is told the same time the queue holds.'
		);
	}

	/**
	 * Nothing the queue ran was left failed.
	 *
	 * Action Scheduler records an uncaught error as a failed action rather than
	 * letting it escape, so a chain can complete on paper while the store is full
	 * of failures. This asks the store.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function test_the_queue_completes_its_actions_without_failures(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->assertIsInt( ( new Scheduler() )->schedule_retry( $prompt_id, 0 ) );

		$this->drain();

		$this->assertSame( 0, $this->count_actions( ActionScheduler_Store::STATUS_FAILED ) );
		$this->assertGreaterThan(
			0,
			$this->count_actions( ActionScheduler_Store::STATUS_COMPLETE ),
			'The actions really ran rather than being cancelled or ignored.'
		);
	}

	/**
	 * A provider failure ends the chain rather than leaving it going round.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function test_a_failing_provider_ends_the_chain(): void {
		$this->mock_provider_failure( 500 );

		$prompt_id = $this->create_prompt();

		$this->assertIsInt( ( new Scheduler() )->schedule_retry( $prompt_id, 0 ) );

		$this->drain();

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertNotSame(
			Run::STATUS_RUNNING,
			$row['status'],
			'A chain that cannot continue closes rather than sitting open.'
		);
	}

	/**
	 * The sweep the plugin schedules for itself is one the queue will run.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function test_the_recurring_sweep_is_dispatched(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$scheduler = new Scheduler();

		// A prompt that has fallen out of the queue, and nothing else queued.
		$scheduler->cancel( $prompt_id );

		as_schedule_single_action( time(), 'autoscribe_sweep_runs', array(), Scheduler::GROUP );

		$this->drain();

		$this->assertIsInt(
			$scheduler->next_scheduled( $prompt_id ),
			'The sweep ran and put the prompt back in the queue.'
		);
	}

	/**
	 * Lets the queue runner work until it has nothing left to do.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	private function drain(): void {
		$runner = ActionScheduler_QueueRunner::instance();

		for ( $pass = 0; $pass < self::PASSES; $pass++ ) {
			if ( $runner->run( 'AutoScribe tests' ) > 0 ) {
				continue;
			}

			if ( ! $this->wait_for_the_next_action() ) {
				return;
			}
		}
	}

	/**
	 * Waits for an action that is armed a moment from now.
	 *
	 * The queue runs what is due, and the plugin never arms anything for the
	 * current second: a retry and a "Run now" are both a second ahead, because an
	 * action armed for the instant it is created can be claimed by a runner that
	 * is already mid-pass. So a drain that only ever asks once finds an empty
	 * queue and reports success without having run anything, which is how the
	 * first version of these tests passed while doing nothing.
	 *
	 * Waiting a second or two is the honest way to test the real arming path.
	 * Anything further out is the *next* occurrence — hours away by design — and
	 * the drain stops rather than sleeping through it.
	 *
	 * @since 1.13.0
	 *
	 * @return bool True when something became due, false when the queue is done.
	 */
	private function wait_for_the_next_action(): bool {
		$due = $this->next_due();

		if ( null === $due ) {
			return false;
		}

		$wait = $due - time();

		if ( $wait > self::MAX_WAIT ) {
			return false;
		}

		if ( $wait > 0 ) {
			sleep( $wait );
		}

		return true;
	}

	/**
	 * Returns when this plugin's earliest pending action comes due.
	 *
	 * @since 1.13.0
	 *
	 * @return int|null Unix timestamp, or null when nothing is pending.
	 */
	private function next_due(): ?int {
		global $wpdb;

		$earliest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN( a.scheduled_date_gmt ) FROM {$wpdb->prefix}actionscheduler_actions a
				INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				WHERE g.slug = %s AND a.status = %s",
				Scheduler::GROUP,
				ActionScheduler_Store::STATUS_PENDING
			)
		);

		if ( ! is_string( $earliest ) || '' === $earliest ) {
			return null;
		}

		// Already GMT, so it is read as GMT rather than through the site's zone.
		return (int) strtotime( $earliest . ' +0000' );
	}

	/**
	 * Empties the action store so a count means this test and nothing else.
	 *
	 * The two-connection tests commit, so their actions outlive them, and an
	 * assertion about what the queue completed would otherwise be answered by
	 * somebody else's leftovers. The rollback at the end of this test puts them
	 * back.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	private function empty_the_queue(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_claims" );
	}

	/**
	 * Counts this plugin's actions in one state.
	 *
	 * @since 1.13.0
	 *
	 * @param string $status Action Scheduler status.
	 * @return int
	 */
	private function count_actions( string $status ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a
				INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				WHERE g.slug = %s AND a.status = %s",
				Scheduler::GROUP,
				$status
			)
		);
	}
}
