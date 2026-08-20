<?php
/**
 * Write-refusal helper for tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Makes selected database writes fail, so failure paths can be driven.
 *
 * A refused write is the whole subject of several defects — money spent and not
 * recorded, a run that finishes and does not close — and none of them can be
 * reproduced by any amount of ordinary use. Rewriting the matching statement to
 * one that names a table that does not exist is the closest a test can get to a
 * database that will not take a write, without breaking the connection the rest
 * of the test needs.
 *
 * @since 1.2.0
 */
trait Refuses_Writes {

	/**
	 * Runs a callback with matching UPDATE statements refused.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $needle   Fragment identifying the write to refuse.
	 * @param callable $callback Work to run while it is refused.
	 * @return mixed
	 */
	protected function with_refused( string $needle, callable $callback ) {
		return $this->with_all_refused( array( $needle ), $callback );
	}

	/**
	 * Runs a callback with every matching UPDATE statement refused.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $needles  Fragments identifying the writes to refuse.
	 * @param callable $callback Work to run while they are refused.
	 * @return mixed
	 */
	protected function with_all_refused( array $needles, callable $callback ) {
		global $wpdb;

		$break = static function ( $query ) use ( $needles ) {
			$sql = (string) $query;

			if ( ! str_starts_with( ltrim( $sql ), 'UPDATE' ) ) {
				return $query;
			}

			foreach ( $needles as $needle ) {
				if ( str_contains( $sql, $needle ) ) {
					return 'UPDATE autoscribe_no_such_table SET id = 1 WHERE id = 1';
				}
			}

			return $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$result = $callback();

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		return $result;
	}
}
