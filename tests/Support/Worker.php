<?php
/**
 * A second database connection, for tests that need two workers.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Runs plugin code against a database connection of its own.
 *
 * Every concurrency test in this suite until now has interleaved two objects on
 * one connection. That proves the ordering of statements, and it cannot prove
 * the things that make the ordering safe: a compare-and-swap only excludes a
 * second writer if the second writer is a second session, and `GET_LOCK` is held
 * by a connection, so on one connection it is a no-op that always succeeds.
 * Three of the four findings in the twelfth review were concurrency defects. A
 * harness that can run two workers is the thing that would have found them
 * first.
 *
 * The plugin reaches the database through the `$wpdb` global, so a worker is a
 * second `wpdb` with the global swapped for the duration of a callable. That is
 * a real second session: its own transaction, its own locks, its own view of
 * uncommitted data. WordPress's object cache is process-wide and would hide the
 * other worker's writes behind a cached read, so it is flushed on entry.
 *
 * @since 1.12.0
 */
final class Worker {

	/**
	 * This worker's connection.
	 *
	 * @since 1.12.0
	 * @var wpdb
	 */
	private wpdb $connection;

	/**
	 * Opens a connection for this worker.
	 *
	 * @since 1.12.0
	 */
	public function __construct() {
		global $wpdb;

		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		$this->connection->set_prefix( $wpdb->prefix );
		$this->connection->suppress_errors( $wpdb->suppress_errors() );

		/*
		 * Table properties other code has registered on the global connection —
		 * Action Scheduler adds its own, and reads them off $wpdb rather than
		 * building the names itself. A fresh connection has none of them, and the
		 * failure is a PHP notice from deep inside somebody else's store.
		 */
		foreach ( get_object_vars( $wpdb ) as $property => $value ) {
			if ( is_string( $value ) && str_starts_with( $value, $wpdb->prefix ) && ! isset( $this->connection->$property ) ) {
				$this->connection->$property = $value;
			}
		}
	}

	/**
	 * Runs a callable as this worker, on this worker's connection.
	 *
	 * @since 1.12.0
	 *
	 * @param callable $work What this worker does.
	 * @return mixed Whatever the callable returns.
	 */
	public function run( callable $work ) {
		global $wpdb;

		$theirs = $wpdb;

		// A read served from the object cache is a read that never reaches this
		// connection, which would make one worker blind to the other's writes.
		wp_cache_flush();

		$wpdb = $this->connection; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Being a second worker is the whole point.

		try {
			return $work();
		} finally {
			$wpdb = $theirs; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring what was there.

			wp_cache_flush();
		}
	}

	/**
	 * Returns this worker's connection, for a test that wants to look directly.
	 *
	 * @since 1.12.0
	 *
	 * @return wpdb
	 */
	public function connection(): wpdb {
		return $this->connection;
	}

	/**
	 * Closes the connection.
	 *
	 * @since 1.12.0
	 *
	 * @return void
	 */
	public function close(): void {
		$this->connection->close();
	}
}
