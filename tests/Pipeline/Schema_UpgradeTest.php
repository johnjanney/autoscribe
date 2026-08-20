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
}
