<?php
/**
 * Base class for tests that need more than one database connection.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

use AutoScribe\Activation;
use AutoScribe\Prompts\Prompt_Post_Type;
use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * A test that commits, so a second connection can see what the first one wrote.
 *
 * The ordinary WordPress test case wraps each test in a transaction and rolls it
 * back afterwards, which is what makes the suite fast and independent. It also
 * makes a second connection useless: it cannot see rows the first has not
 * committed, and it blocks on any row the first has locked — an earlier attempt
 * at one of these tests failed after waiting fifty seconds for a lock nobody was
 * ever going to release.
 *
 * So these tests commit, and clean up after themselves instead. Everything they
 * create is above a watermark taken before the test runs, and everything above
 * that watermark goes afterwards. That is slower and less forgiving than a
 * rollback, and it is the price of testing the thing that actually matters:
 * whether two sessions exclude each other.
 *
 * Nothing here belongs in the ordinary suite. A test that does not need two
 * connections should not pay for this.
 *
 * @since 1.12.0
 */
abstract class Two_Connection_Test_Case extends WP_UnitTestCase {

	/**
	 * Workers opened by this test, closed when it ends.
	 *
	 * @since 1.12.0
	 * @var Worker[]
	 */
	private array $workers = array();

	/**
	 * Highest run row that existed before this test.
	 *
	 * @since 1.12.0
	 * @var int
	 */
	private int $run_watermark = 0;

	/**
	 * Highest post that existed before this test.
	 *
	 * @since 1.12.0
	 * @var int
	 */
	private int $post_watermark = 0;

	/**
	 * Leaves the per-test transaction behind and takes a watermark instead.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		/*
		 * The parent started a transaction and installed the filters that turn
		 * CREATE TABLE into CREATE TEMPORARY TABLE. Both have to go: a temporary
		 * table belongs to one connection, and an uncommitted row may as well not
		 * exist as far as the other worker is concerned.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		// A short wait, so a test that proves two workers exclude each other does
		// not spend ten seconds finding out.
		add_filter( 'autoscribe_lock_wait_seconds', array( $this, 'short_lock_wait' ) );

		$this->run_watermark  = (int) $wpdb->get_var( 'SELECT COALESCE( MAX( id ), 0 ) FROM ' . Activation::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed identifier from Activation.
		$this->post_watermark = (int) $wpdb->get_var( "SELECT COALESCE( MAX( ID ), 0 ) FROM {$wpdb->posts}" );
	}

	/**
	 * Removes everything this test committed.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->workers as $worker ) {
			$worker->close();
		}

		$this->workers = array();

		remove_filter( 'autoscribe_lock_wait_seconds', array( $this, 'short_lock_wait' ) );

		$this->cancel_queued_actions();

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Activation::table_name() . ' WHERE id > %d', $this->run_watermark ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed identifier from Activation.

		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d", $this->post_watermark ) );

		foreach ( (array) $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id > %d", $this->post_watermark ) );

		parent::tear_down();
	}

	/**
	 * Returns a worker with a connection of its own.
	 *
	 * @since 1.12.0
	 *
	 * @return Worker
	 */
	protected function worker(): Worker {
		$worker = new Worker();

		$this->workers[] = $worker;

		return $worker;
	}

	/**
	 * Shortens the lock wait so contention is quick to observe.
	 *
	 * @since 1.12.0
	 *
	 * @return int
	 */
	public function short_lock_wait(): int {
		return 1;
	}

	/**
	 * Counts this prompt's run rows, whoever wrote them.
	 *
	 * @since 1.12.0
	 *
	 * @param int $prompt_id Prompt to count for.
	 * @return int
	 */
	protected function run_count( int $prompt_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Activation::table_name() . ' WHERE prompt_id = %d', $prompt_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed identifier from Activation.
		);
	}

	/**
	 * Cancels every action this test's prompts left in the queue.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	private function cancel_queued_actions(): void {
		global $wpdb;

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$prompts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = %s",
				$this->post_watermark,
				Prompt_Post_Type::POST_TYPE
			)
		);

		foreach ( (array) $prompts as $prompt_id ) {
			as_unschedule_all_actions( 'autoscribe_run_prompt', array( 'prompt_id' => (int) $prompt_id ), 'autoscribe' );
		}
	}
}
