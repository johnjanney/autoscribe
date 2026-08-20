<?php
/**
 * Outcome of an attempt to write a run's terminal state.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * What happened when a caller tried to close a run.
 *
 * Closing used to answer a Boolean, and the two ways of answering false are not
 * the same thing at all. A lost race means somebody else closed this run and has
 * already reported it, so the caller should stand down and stay quiet. A refused
 * write means the run is still open with nobody looking after it, so the caller
 * must not announce, retry, or arm anything — the row it would be announcing
 * about does not say what the caller thinks it says.
 *
 * Collapsing the two made a database fault behave like a won race: the queue
 * mailed the failure, armed the next occurrence, and left an open run whose
 * in-memory usage disappeared at the end of the request. A later sweep then
 * settled it from the counters that did reach the row, so a paid call vanished
 * from the monthly total.
 *
 * @since 1.2.0
 */
enum Close_Result: string {

	/**
	 * This caller performed the transition.
	 *
	 * @since 1.2.0
	 */
	case Closed = 'closed';

	/**
	 * The run was already closed, so this caller did nothing.
	 *
	 * @since 1.2.0
	 */
	case Already_Closed = 'already_closed';

	/**
	 * The write was refused. The run is still open.
	 *
	 * @since 1.2.0
	 */
	case Write_Failed = 'write_failed';

	/**
	 * Key under which a close result travels on a WP_Error.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const ERROR_KEY = 'autoscribe_close';

	/**
	 * Whether this outcome means the caller may report the run as ended.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function ended(): bool {
		return self::Closed === $this;
	}

	/**
	 * Records a close result on the error the closing caller is returning.
	 *
	 * A run can be closed by the step that produced the failure, by the driver
	 * that received it, or by nobody at all. Only the caller that made the
	 * attempt knows which, and the code that decides what happens next is several
	 * returns away from it. Carrying the answer on the error keeps that decision
	 * in one place instead of duplicating it at every ending.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Error     $error  Failure being returned.
	 * @param Close_Result $result What the close attempt did.
	 * @return WP_Error The same error, annotated.
	 */
	public static function annotate( WP_Error $error, Close_Result $result ): WP_Error {
		$error->add_data(
			array_merge(
				is_array( $error->get_error_data() ) ? $error->get_error_data() : array(),
				array( self::ERROR_KEY => $result )
			)
		);

		return $error;
	}

	/**
	 * Reads the close result an error carries, if it carries one.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Error|null $error Failure to inspect.
	 * @return Close_Result|null
	 */
	public static function from_error( ?WP_Error $error ): ?Close_Result {
		if ( ! $error instanceof WP_Error ) {
			return null;
		}

		$data = $error->get_error_data();

		if ( ! is_array( $data ) || ! isset( $data[ self::ERROR_KEY ] ) ) {
			return null;
		}

		return $data[ self::ERROR_KEY ] instanceof self ? $data[ self::ERROR_KEY ] : null;
	}
}
