<?php
/**
 * Concurrency tests that use two database connections.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Concurrency;

use AutoScribe\Concurrency\Named_Lock;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Cost\Spend_Lock;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Schedule_Sweeper;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Two_Connection_Test_Case;

/**
 * What one connection cannot show.
 *
 * The guards this plugin relies on are of two kinds. A compare-and-swap excludes
 * a second *session*, and on one connection there is no second session to
 * exclude — the statement always matches, so the test passes whether or not the
 * condition is doing anything. A named lock is held by a connection, so taking
 * it twice on one connection succeeds twice, and a test written that way proves
 * the opposite of what it claims.
 *
 * These tests run two real sessions. They are slower and they commit, which is
 * why they live apart from the rest of the suite rather than replacing it: the
 * single-connection tests still describe the intended orderings clearly and run
 * in milliseconds. These say whether the orderings are enforced.
 *
 * @since 1.12.0
 */
final class Two_ConnectionTest extends Two_Connection_Test_Case {

	use Creates_Prompts;

	/**
	 * A lock one worker holds is a lock the other cannot take.
	 *
	 * The foundation the rest of this rests on. `GET_LOCK` is per connection, so
	 * this is the one property a single-process test cannot even approximate.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_a_named_lock_excludes_a_second_worker(): void {
		$first  = $this->worker();
		$second = $this->worker();

		$mine   = new Named_Lock( 'test-scope' );
		$theirs = new Named_Lock( 'test-scope' );

		$this->assertTrue( $first->run( fn() => $mine->acquire() ) );
		$this->assertFalse(
			$second->run( fn() => $theirs->acquire() ),
			'A second session must not be handed a lock the first is holding.'
		);

		$first->run( fn() => $mine->release() );

		$this->assertTrue(
			$second->run( fn() => $theirs->acquire() ),
			'And it must get the lock once the first lets go.'
		);

		$second->run( fn() => $theirs->release() );
	}

	/**
	 * Locks over different scopes do not wait for each other.
	 *
	 * Arming one prompt must not be delayed by arming another, which is the whole
	 * reason the lock is scoped per prompt rather than taken once for the plugin.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_locks_over_different_scopes_are_independent(): void {
		$first  = $this->worker();
		$second = $this->worker();

		$one = new Named_Lock( 'prompt_1' );
		$two = new Named_Lock( 'prompt_2' );

		$this->assertTrue( $first->run( fn() => $one->acquire() ) );
		$this->assertTrue( $second->run( fn() => $two->acquire() ) );

		$first->run( fn() => $one->release() );
		$second->run( fn() => $two->release() );
	}

	/**
	 * Two workers reaching one prompt open one run between them.
	 *
	 * The reproduction for the twelfth review's high finding, on two sessions.
	 * Both workers see the same prompt, both are dispatched an action for the
	 * same occurrence, and only one may spend.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_two_workers_open_one_run_between_them(): void {
		$prompt_id = $this->create_prompt();

		$this->worker()->run(
			function () use ( $prompt_id ) {
				$this->handler()->handle( $prompt_id );
			}
		);

		$this->worker()->run(
			function () use ( $prompt_id ) {
				$this->handler()->handle( $prompt_id );
			}
		);

		$this->assertSame(
			1,
			$this->run_count( $prompt_id ),
			'One occurrence opens one run, however many workers are dispatched it.'
		);
	}

	/**
	 * A second worker finds the prompt the first one queued.
	 *
	 * This is the check rather than the lock: the two workers run one after the
	 * other, so what it proves is that the second sees the first's action across
	 * connections and stands down. What stops them doing it at the same instant is
	 * the lock, and that is tested above, where its exclusivity is the assertion.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_a_second_worker_finds_the_prompt_the_first_queued(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		( new Scheduler() )->cancel( $prompt_id );

		$armed = array();

		foreach ( array( $this->worker(), $this->worker() ) as $worker ) {
			$armed[] = $worker->run(
				fn() => ( new Schedule_Sweeper( new Scheduler() ) )->rearm( $prompt_id )
			);
		}

		$this->assertSame(
			array( true, false ),
			$armed,
			'The first worker queues the prompt and the second finds it queued.'
		);
		$this->assertSame( 1, $this->queued_actions( $prompt_id ) );
	}

	/**
	 * Only one of two workers can claim a step.
	 *
	 * A compare-and-swap on one connection always matches. This is the same
	 * assertion made where it means something.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_only_one_worker_claims_a_step(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();
		$claims = array();

		foreach ( array( $this->worker(), $this->worker() ) as $worker ) {
			$claims[] = $worker->run(
				static fn() => Run::load( $run_id )->claim_step( '' )
			);
		}

		$this->assertSame(
			array( true, false ),
			$claims,
			'Two sessions asking for the same step get one yes and one no.'
		);
	}

	/**
	 * A worker whose claim was taken cannot write over the one that took it.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_a_replaced_worker_cannot_write_across_connections(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();
		$slow   = $this->worker();
		$sweep  = $this->worker();

		$claimed = $slow->run(
			static function () use ( $run_id ) {
				$loaded = Run::load( $run_id );

				return $loaded->claim_step( '' ) ? $loaded : null;
			}
		);

		$this->assertNotNull( $claimed );

		// A sweep on another connection judges the worker gone and replaces it.
		$sweep->run(
			static function () use ( $run_id ) {
				$observed = Run::load( $run_id )->raw_step();

				Run::load( $run_id )->release_claim( $observed );

				Run::load( $run_id )->claim_step( '' );
			}
		);

		$wrote = $slow->run(
			static fn() => $claimed->merge_payload( array( 'topic' => array( 'title' => 'Stale' ) ) )
		);

		$this->assertFalse( $wrote, 'A worker that lost its claim cannot write, on any connection.' );
		$this->assertArrayNotHasKey( 'topic', Run::load( $run_id )->payload() );
	}

	/**
	 * A charge on a closed run waits for the books; one on an open run does not.
	 *
	 * The contract that closes the late-charge race: money arriving after a run
	 * has finished takes the same lock the budget check holds from its final
	 * check through the reservation, and money arriving during a run does not
	 * wait for anything.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_a_late_charge_takes_the_spend_lock_and_an_ordinary_one_does_not(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertSame(
			0,
			$this->lock_attempts( fn() => Run::load( $run_id )->record_image( 'gpt-image-2' ) ),
			'A charge on a run that is still going waits for nothing.'
		);

		$this->assertTrue( Run::load( $run_id )->fail( 'Given up on.' )->ended() );

		$this->assertGreaterThan(
			0,
			$this->lock_attempts( fn() => Run::load( $run_id )->record_image( 'gpt-image-2' ) ),
			'A charge on a closed run waits for whoever is counting the money.'
		);
	}

	/**
	 * A late charge cannot land while a budget check holds the lock.
	 *
	 * With the wait shortened, the second worker gives up rather than blocking,
	 * which is the observable form of "it could not get in".
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function test_a_late_charge_cannot_enter_a_held_spend_lock(): void {
		$guard = $this->worker();
		$late  = $this->worker();
		$lock  = new Spend_Lock();

		$this->assertTrue(
			$guard->run( static fn() => $lock->acquire() ),
			'The budget check takes the lock it holds through its reservation.'
		);

		$blocked = $late->run(
			static function () {
				$theirs = new Spend_Lock();

				return $theirs->acquire();
			}
		);

		$guard->run( static fn() => $lock->release() );

		$this->assertFalse( $blocked, 'A late charge cannot balance the books under somebody else.' );
	}

	/**
	 * Counts GET_LOCK attempts made while running a callable.
	 *
	 * @since 1.12.0
	 *
	 * @param callable $work What to watch.
	 * @return int
	 */
	private function lock_attempts( callable $work ): int {
		$attempts = 0;
		$watch    = static function ( $query ) use ( &$attempts ) {
			if ( str_contains( (string) $query, 'GET_LOCK' ) ) {
				++$attempts;
			}

			return $query;
		};

		add_filter( 'query', $watch );

		$work();

		remove_filter( 'query', $watch );

		return $attempts;
	}

	/**
	 * Counts a prompt's pending or running queue actions.
	 *
	 * @since 1.12.0
	 *
	 * @param int $prompt_id Prompt to count for.
	 * @return int
	 */
	private function queued_actions( int $prompt_id ): int {
		$active = ( new Scheduler() )->active_prompt_actions();

		if ( null === $active ) {
			return null === ( new Scheduler() )->next_scheduled( $prompt_id ) ? 0 : 1;
		}

		return isset( $active[ $prompt_id ] ) ? 1 : 0;
	}

	/**
	 * Builds the queued handler.
	 *
	 * @since 1.12.0
	 *
	 * @return Queued_Run_Handler
	 */
	private function handler(): Queued_Run_Handler {
		return new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);
	}
}
