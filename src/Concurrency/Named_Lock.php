<?php
/**
 * Cross-process named lock.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Concurrency;

defined( 'ABSPATH' ) || exit;

/**
 * A MySQL named lock, scoped to this installation and to a caller's purpose.
 *
 * Some sequences in this plugin are two or three statements that have to look
 * like one to anybody else: read the month's spend and reserve against it, ask
 * whether a prompt is queued and queue it, ask whether a run is open and open
 * one. Every one of those has been the subject of a defect where two workers
 * both read "no" and both acted.
 *
 * A named lock closes them properly. GET_LOCK is held by the connection rather
 * than by a row, so it works across processes and needs no schema, and it costs
 * one round trip. Where it cannot be taken — a database that does not implement
 * it, or a wait that times out — the caller falls back to whatever narrower
 * check it has, and says so rather than presenting the two as equivalent.
 *
 * The scope keeps unrelated sequences from serialising against each other: the
 * spend check takes one name, and each prompt's arming takes its own.
 *
 * @since 1.11.0
 */
class Named_Lock {

	/**
	 * Seconds to wait for a competing worker to finish.
	 *
	 * The work inside these locks is a handful of statements, so a wait this long
	 * means something is badly wrong rather than merely busy. Waiting is still
	 * better than proceeding: the caller degrades to the weaker check instead of
	 * failing the run.
	 *
	 * @since 1.11.0
	 * @var int
	 */
	public const WAIT_SECONDS = 10;

	/**
	 * What this lock protects, which keeps it out of other callers' way.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	private string $scope;

	/**
	 * Whether this instance currently holds the lock.
	 *
	 * @since 1.11.0
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Builds a lock over one scope.
	 *
	 * @since 1.11.0
	 *
	 * @param string $scope What is being serialised, such as spend or prompt-12.
	 */
	public function __construct( string $scope ) {
		$this->scope = $scope;
	}

	/**
	 * Takes the lock, waiting briefly for any worker already holding it.
	 *
	 * @since 1.11.0
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
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name(), self::wait_seconds() )
		);

		$this->held = '1' === (string) $result;

		return $this->held;
	}

	/**
	 * Releases the lock if this instance holds it.
	 *
	 * @since 1.11.0
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
	 * @since 1.11.0
	 *
	 * @return bool
	 */
	public function held(): bool {
		return $this->held;
	}

	/**
	 * Returns how long a caller waits for a lock somebody else holds.
	 *
	 * Filterable because the right answer depends on the database rather than on
	 * this plugin: a busy shared host may want longer, and a test that wants to
	 * prove two workers are really excluding each other wants a great deal less
	 * than ten seconds of waiting to find out.
	 *
	 * Never negative, which in MySQL means "wait for ever" and would turn a
	 * contended lock into a hung request.
	 *
	 * @since 1.12.0
	 *
	 * @return int Seconds.
	 */
	public static function wait_seconds(): int {
		/**
		 * Filters how long a worker waits for a lock another worker is holding.
		 *
		 * @since 1.12.0
		 *
		 * @param int $seconds Seconds to wait.
		 */
		return max( 0, (int) apply_filters( 'autoscribe_lock_wait_seconds', self::WAIT_SECONDS ) );
	}

	/**
	 * Returns the lock name, scoped to this installation and this purpose.
	 *
	 * Named locks are global to the MySQL server, so several WordPress sites on
	 * one server would otherwise serialise against each other. The database name
	 * and table prefix identify the installation; they are hashed because the
	 * name has a 64-character limit.
	 *
	 * @since 1.11.0
	 *
	 * @return string
	 */
	private function name(): string {
		global $wpdb;

		$scope = ( defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '' ) . '|' . $wpdb->prefix . '|' . $this->scope;

		return 'autoscribe_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}
}
