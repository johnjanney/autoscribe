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
	 * A late charge that cannot take the lock is recorded but not priced.
	 *
	 * The contract this method's own comment describes was not the one it kept:
	 * it took the lock and ignored whether it got it, so a wait that timed out
	 * carried on and priced the row inside the window the budget guard holds the
	 * lock for. The charge still lands — a provider that answered has been paid —
	 * and the row is left saying it owes a price, which is a state the guard must
	 * clear before it authorises anything else.
	 *
	 * @since 1.13.4
	 *
	 * @return void
	 */
	public function test_a_late_charge_that_cannot_take_the_lock_is_recorded_but_not_priced(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->fail( 'Given up on.' )->ended() );

		$before = Run::latest_for_prompt( $prompt_id );
		$guard  = $this->worker();
		$lock   = new Spend_Lock();

		$this->assertTrue( $guard->run( static fn() => $lock->acquire() ) );

		$charged = $this->worker()->run(
			static fn() => Run::load( $run_id )->record_image( 'gpt-image-2' )
		);

		$during = Run::latest_for_prompt( $prompt_id );

		$guard->run( static fn() => $lock->release() );

		$this->assertTrue( $charged, 'A charge the provider has already billed is never dropped.' );
		$this->assertSame( 1, (int) $during['image_count'], 'The money is recorded.' );
		$this->assertSame(
			(int) $before['cost_cents'],
			(int) $during['cost_cents'],
			'And nothing is priced while somebody else is counting the money.'
		);
		$this->assertSame( 1, (int) $during['cost_stale'], 'The row says it owes a price.' );

		// What clears it: the same repair the budget check runs before it sums.
		$this->assertTrue( Run::settle_all_unsettled() );

		$after = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( 0, (int) $after['cost_stale'] );
		$this->assertGreaterThan(
			(int) $before['cost_cents'],
			(int) $after['cost_cents'],
			'The late charge reaches the figure the monthly cap reads.'
		);
	}

	/**
	 * A run closing under an open charge is not priced outside the lock either.
	 *
	 * The status is read before the write and can be stale by the time it lands.
	 * That gap used to route a closed row down the open path, which prices
	 * without coordinating with anybody.
	 *
	 * @since 1.13.4
	 *
	 * @return void
	 */
	public function test_a_run_that_closes_under_a_charge_is_not_priced_outside_the_lock(): void {
		$prompt_id = $this->create_prompt();
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id = $run->id();
		$guard  = $this->worker();
		$lock   = new Spend_Lock();

		$this->assertTrue( $guard->run( static fn() => $lock->acquire() ) );

		$closed    = false;
		$interpose = static function ( $query ) use ( &$closed, $run_id ) {
			// The run closes in the gap between the status read and the write.
			if ( ! $closed && str_contains( (string) $query, 'image_count = image_count +' ) ) {
				$closed = true;

				Run::load( $run_id )->fail( 'Closed under the charge.' );
			}

			return $query;
		};

		add_filter( 'query', $interpose );

		$charged = Run::load( $run_id )->record_image( 'gpt-image-2' );

		remove_filter( 'query', $interpose );

		$row = Run::latest_for_prompt( $prompt_id );

		$guard->run( static fn() => $lock->release() );

		$this->assertTrue( $closed, 'The interleaving must have happened for this to test anything.' );
		$this->assertTrue( $charged );
		$this->assertSame( 1, (int) $row['image_count'] );
		$this->assertSame( 1, (int) $row['cost_stale'], 'Left owing a price rather than priced unprotected.' );
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
