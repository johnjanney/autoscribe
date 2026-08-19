<?php
/**
 * Cross-process lock for the spend check.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Cost;

defined( 'ABSPATH' ) || exit;

/**
 * Serialises the read-check-reserve sequence that enforces the monthly cap.
 *
 * Section 7.4 asks for a cap and says nothing about how to make it hold when
 * more than one worker is running, which Action Scheduler guarantees: it claims
 * and executes a batch of actions at a time, and a site can also run several
 * queue runners at once.
 *
 * Reading the month-to-date total and writing this run's reservation are two
 * separate statements. Version 1.0.1 tried to close the window between them by
 * re-reading afterwards and counting only rows up to the reserving run's own ID,
 * on the reasoning that the auto-increment gives a total order. It does not give
 * the order that matters. An ID is assigned when the run row is inserted, which
 * is before the reservation is written, so a later run can read, see nothing
 * from an earlier run that has not reserved yet, and pass — and the earlier run
 * then reserves, re-reads with an upper bound that excludes the later run, and
 * passes too. Both spend.
 *
 * A named MySQL lock closes it properly. GET_LOCK is held by the connection
 * rather than by a row, so it works across processes and needs no schema, and it
 * costs one round trip. The whole check-and-reserve happens inside it, so a
 * concurrent worker reads a total that already includes every reservation made
 * before it.
 *
 * Where the lock cannot be taken — a database that does not implement GET_LOCK,
 * or a wait that times out — the caller falls back to the 1.0.1 ordering pass.
 * That fallback is weaker than a lock and is documented as such rather than
 * presented as an equivalent.
 *
 * @since 1.0.2
 */
final class Spend_Lock {

	/**
	 * Seconds to wait for a competing worker to finish its check.
	 *
	 * The work inside the lock is two SELECTs and one UPDATE, so a wait this long
	 * means something is badly wrong rather than merely busy. Waiting is still
	 * better than proceeding: the caller degrades to the weaker check instead of
	 * failing the run.
	 *
	 * @since 1.0.2
	 * @var int
	 */
	public const WAIT_SECONDS = 10;

	/**
	 * Whether this instance currently holds the lock.
	 *
	 * @since 1.0.2
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Takes the lock, waiting briefly for any worker already holding it.
	 *
	 * @since 1.0.2
	 *
	 * @return bool True when the lock is held, false when it could not be taken.
	 */
	public function acquire(): bool {
		global $wpdb;

		if ( $this->held ) {
			return true;
		}

		// A NULL result means the server refused or failed the request rather
		// than that the lock is busy, so it is treated the same as a timeout.
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name(), self::WAIT_SECONDS )
		);

		$this->held = '1' === (string) $result;

		return $this->held;
	}

	/**
	 * Releases the lock if this instance holds it.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function release(): void {
		global $wpdb;

		if ( ! $this->held ) {
			return;
		}

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name() ) );

		$this->held = false;
	}

	/**
	 * Whether the lock is currently held by this instance.
	 *
	 * @since 1.0.2
	 *
	 * @return bool
	 */
	public function held(): bool {
		return $this->held;
	}

	/**
	 * Returns the lock name, scoped to this installation.
	 *
	 * Named locks are global to the MySQL server, so several WordPress sites on
	 * one server would otherwise serialise against each other. The database name
	 * and table prefix identify the installation; they are hashed because the
	 * name has a 64-character limit.
	 *
	 * @since 1.0.2
	 *
	 * @return string
	 */
	private function name(): string {
		global $wpdb;

		$scope = ( defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '' ) . '|' . $wpdb->prefix;

		return 'autoscribe_spend_' . substr( hash( 'sha256', $scope ), 0, 32 );
	}
}
