<?php
/**
 * Tests for the runs table migration.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the column 1.2.0 adds and the runs already in flight when it arrives.
 *
 * A schema change on a live site meets two states nobody tests by accident: a
 * table that predates the column, and a run that was opened by the previous
 * version and is part-way through its chain. The first has to migrate without a
 * reactivation, and the second has to keep the restart count it recorded in the
 * old place — otherwise an upgrade hands every stalled run a fresh set of
 * restarts, which is how a broken run keeps buying steps.
 *
 * @since 1.2.0
 */
final class Schema_UpgradeTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Restores the table and version option after each test.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Activation::maybe_upgrade();

		parent::tear_down();
	}

	/**
	 * The migration adds the sweep counter to a table that predates it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_the_sweep_counter_is_added_to_an_older_table(): void {
		global $wpdb;

		$table = Activation::table_name();

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN sweeps', $table ) );

		$this->assertSame(
			array(),
			$wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'sweeps' ) ),
			'The column has to be absent for this test to mean anything.'
		);

		update_option( Activation::DB_VERSION_OPTION, '2' );

		Activation::maybe_upgrade();

		$this->assertNotEmpty(
			$wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'sweeps' ) ),
			'A site upgrading in place must get the column without reactivating.'
		);

		$this->assertSame( Activation::DB_VERSION, get_option( Activation::DB_VERSION_OPTION ) );
	}

	/**
	 * A run in flight across the upgrade keeps the restarts it has used.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_run_from_the_previous_version_keeps_its_restart_count(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		// What 1.1.x wrote: the count in the payload document, column at zero.
		$this->assertTrue( $run->merge_payload( array( 'sweeps' => Stall_Sweeper::MAX_RESTARTS ) ) );

		$this->assertSame(
			Stall_Sweeper::MAX_RESTARTS,
			Run::load( $run->id() )->sweeps(),
			'An upgrade must not hand a stalled run a fresh set of restarts.'
		);

		// And it can still be counted past that point, rather than being stuck.
		$this->assertTrue( Run::load( $run->id() )->record_sweep( Stall_Sweeper::MAX_RESTARTS ) );
		$this->assertSame( Stall_Sweeper::MAX_RESTARTS + 1, Run::load( $run->id() )->sweeps() );
	}
	/**
	 * A grounded call recorded before the upgrade is added to, not compared with.
	 *
	 * Version 1.5.0 moved the count from the payload to a column and read the
	 * larger of the two, which is wrong the moment a run that crossed the upgrade
	 * makes another grounded request: the column goes from zero to one, the legacy
	 * value is one, and the larger of one and one is one. Two searches billed, one
	 * counted. The migration copies the legacy value into the column so that every
	 * increment adds to a count that already includes what came before.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function test_a_grounded_call_from_the_previous_version_is_carried_over(): void {
		global $wpdb;

		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		// What 1.4.x wrote: the count in the payload, and no column to hold it.
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'grounded_calls' => 0 ),
			array( 'id' => $run_id ),
			array( '%d' ),
			array( '%d' )
		);

		update_option( Activation::DB_VERSION_OPTION, '5' );

		Activation::maybe_upgrade();

		$this->assertSame(
			1,
			(int) Run::latest_for_prompt( $run->prompt_id() )['grounded_calls'],
			'The legacy count belongs in the column the increments go to.'
		);

		// The same run makes another grounded request after the upgrade.
		$this->assertTrue( Run::load( $run_id )->record_grounded_call() );
		$this->assertSame(
			2,
			Run::load( $run_id )->grounded_calls(),
			'Two searches billed is two searches counted.'
		);
	}

	/**
	 * A settled run's legacy count is carried over and its cost re-opened.
	 *
	 * This reverses what 1.6.0 did, and the reversal is the fix: a closed run's
	 * money was settled under a reading that dropped one of its searches, so
	 * leaving it alone leaves the surcharge missing for ever. Adding the count and
	 * flagging the row means the next repair pass prices it, and repricing a row
	 * that owes nothing changes nothing, because the cost only ever rises.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function test_a_settled_run_has_its_legacy_count_carried_over(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );
		$this->assertTrue( $run->fail( 'Finished before the upgrade.' )->ended() );

		update_option( Activation::DB_VERSION_OPTION, '6' );

		Activation::maybe_upgrade();

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 1, (int) $row['grounded_calls'] );
		$this->assertSame( 1, (int) $row['cost_stale'], 'A settled run that owes a surcharge says so.' );
	}

	/**
	 * A count recorded under 1.5 is added to the legacy one, not compared with it.
	 *
	 * The two numbers count different periods: the payload holds what was made
	 * before the column existed, and the column holds what was made after. Version
	 * 1.6.0 took the larger of the two, so a run with one in each place counted
	 * one search and had been billed for two.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function test_a_legacy_count_is_added_to_one_recorded_since(): void {
		global $wpdb;

		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );

		// What 1.5.x left: one call in the payload from before, one in the column
		// from after.
		$wpdb->update(
			Activation::table_name(),
			array( 'grounded_calls' => 1 ),
			array( 'id' => $run->id() ),
			array( '%d' ),
			array( '%d' )
		);

		update_option( Activation::DB_VERSION_OPTION, '6' );

		Activation::maybe_upgrade();

		$this->assertSame(
			2,
			Run::load( $run->id() )->grounded_calls(),
			'Two searches billed is two searches counted.'
		);
	}

	/**
	 * Running the migration twice does not count the same call twice.
	 *
	 * The legacy key is removed by the same write that adds its value, so a row
	 * that has been migrated no longer matches the query that finds them.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function test_the_migration_can_be_repeated_without_double_counting(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 2 ) ) );

		update_option( Activation::DB_VERSION_OPTION, '6' );

		Activation::maybe_upgrade();

		$this->assertSame( 2, Run::load( $run->id() )->grounded_calls() );

		update_option( Activation::DB_VERSION_OPTION, '6' );

		Activation::maybe_upgrade();

		$this->assertSame(
			2,
			Run::load( $run->id() )->grounded_calls(),
			'A migrated row carries no legacy key, so a second pass has nothing to add.'
		);
	}

	/**
	 * Other payload state survives the move.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function test_the_migration_leaves_the_rest_of_the_payload_alone(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue(
			$run->merge_payload(
				array(
					'grounded_calls' => 1,
					'topic'          => array( 'title' => 'Water' ),
				)
			)
		);

		update_option( Activation::DB_VERSION_OPTION, '6' );

		Activation::maybe_upgrade();

		$payload = Run::load( $run->id() )->payload();

		$this->assertSame( array( 'title' => 'Water' ), $payload['topic'] ?? null );
		$this->assertArrayNotHasKey( 'grounded_calls', $payload, 'The key moves rather than being copied.' );
	}
	/**
	 * A payload that merely mentions the key does not stall the migration.
	 *
	 * The candidate query matches a substring of the JSON, so a title or a source
	 * URL containing those characters comes back as a candidate with nothing to
	 * move. Re-reading from the start meant that row came back on every page and
	 * on every later request — the schema version is only recorded when the
	 * migration finishes, so one odd payload could put a table scan and a
	 * dbDelta() pass on every request the site served.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function test_a_payload_that_only_mentions_the_key_does_not_stall_it(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'topic' => array( 'title' => 'grounded_calls' ) ) ) );

		update_option( Activation::DB_VERSION_OPTION, '7' );

		Activation::maybe_upgrade();

		$this->assertSame(
			Activation::DB_VERSION,
			get_option( Activation::DB_VERSION_OPTION ),
			'A row with nothing to move is a row dealt with, not a row left behind.'
		);
		$this->assertSame(
			array( 'title' => 'grounded_calls' ),
			Run::load( $run->id() )->payload()['topic'] ?? null,
			'And it is left exactly as it was.'
		);
	}

	/**
	 * A failed read is not mistaken for a finished migration.
	 *
	 * An empty result and a failed query look identical, and they mean opposite
	 * things. Recording the schema version on the second is how an install claims
	 * a migration it never performed and never tries again.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function test_a_failed_read_does_not_record_the_migration(): void {
		global $wpdb;

		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );

		update_option( Activation::DB_VERSION_OPTION, '7' );

		$break = static function ( $query ) {
			$sql = (string) $query;

			return str_contains( $sql, 'payload LIKE' )
				? 'SELECT id FROM autoscribe_no_such_table'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		Activation::maybe_upgrade();

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertSame(
			'7',
			get_option( Activation::DB_VERSION_OPTION ),
			'A migration that could not read the table has not finished.'
		);

		// And the next attempt, with the table readable, completes it.
		Activation::maybe_upgrade();

		$this->assertSame( Activation::DB_VERSION, get_option( Activation::DB_VERSION_OPTION ) );
		$this->assertSame( 1, Run::load( $run->id() )->grounded_calls() );
	}

	/**
	 * Moving a count raises the revision, like any other change to money.
	 *
	 * A run being closed while the migration touches it would otherwise be priced
	 * without the surcharge and closed as settled.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function test_the_migration_raises_the_usage_revision(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );

		$before = (int) Run::latest_for_prompt( $run->prompt_id() )['usage_revision'];

		update_option( Activation::DB_VERSION_OPTION, '7' );

		Activation::maybe_upgrade();

		$this->assertGreaterThan(
			$before,
			(int) Run::latest_for_prompt( $run->prompt_id() )['usage_revision'],
			'A counter that changed is a counter a close needs to notice.'
		);
	}
	/**
	 * Progress survives a request that runs out of pages.
	 *
	 * The cursor used to be a local variable, so every request started at zero —
	 * and a request only records the schema version when the migration finishes.
	 * A table with more candidates than one request inspects therefore read the
	 * same rows for ever, and a real legacy count beyond them was never reached.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_migration_progress_survives_between_requests(): void {
		$prompt_id = $this->create_prompt();
		$ids       = array();

		// More candidates than one pass can inspect, once the page size below is
		// forced down to a single row.
		for ( $i = 0; $i < Activation::MIGRATION_PAGES + 2; $i++ ) {
			$run = Run::start( $prompt_id );

			$this->assertNotWPError( $run );
			$this->assertTrue( $run->merge_payload( array( 'grounded_calls' => 1 ) ) );

			$ids[] = $run->id();
		}

		update_option( Activation::DB_VERSION_OPTION, '7' );

		// One page of one row, so the first pass cannot finish.
		$one_page = static function ( $query ) {
			$sql = (string) $query;

			return str_contains( $sql, 'payload LIKE' ) && str_contains( $sql, 'LIMIT' )
				? preg_replace( '/LIMIT \d+$/', 'LIMIT 1', $sql )
				: $query;
		};

		add_filter( 'query', $one_page );

		Activation::maybe_upgrade();

		$first = (int) get_option( Activation::MIGRATION_CURSOR_OPTION, 0 );

		remove_filter( 'query', $one_page );

		$this->assertGreaterThan( 0, $first, 'A pass that could not finish still records where it got to.' );

		Activation::maybe_upgrade();

		$this->assertSame( Activation::DB_VERSION, get_option( Activation::DB_VERSION_OPTION ) );
		$this->assertFalse(
			(bool) get_option( Activation::MIGRATION_CURSOR_OPTION, false ),
			'A finished migration puts its cursor away.'
		);

		foreach ( $ids as $id ) {
			$this->assertSame( 1, Run::load( $id )->grounded_calls() );
		}
	}

	/**
	 * A payload mentioning the words is not even a candidate any more.
	 *
	 * The predicate matches the encoded key rather than the words in it, so a
	 * title or a source URL containing them is not read at all. The decoded check
	 * still decides; this only decides what is worth reading.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_payload_mentioning_the_words_is_not_a_candidate(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue(
			$run->merge_payload( array( 'topic' => array( 'title' => 'grounded_calls everywhere' ) ) )
		);

		update_option( Activation::DB_VERSION_OPTION, '7' );

		Activation::maybe_upgrade();

		$this->assertSame( Activation::DB_VERSION, get_option( Activation::DB_VERSION_OPTION ) );
		$this->assertSame(
			array( 'title' => 'grounded_calls everywhere' ),
			Run::load( $run->id() )->payload()['topic'] ?? null
		);
	}
}
