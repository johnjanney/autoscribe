<?php
/**
 * Human-readable next-run description.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Next_Run_Calculator;

defined( 'ABSPATH' ) || exit;

/**
 * Describes when a prompt will next run, in the site timezone.
 *
 * Section 9.2 asks for a live readout that updates as the schedule controls
 * change. The calculation stays in PHP: reimplementing ordinal weekdays, month
 * ends, and daylight saving in JavaScript would duplicate the single most
 * error-prone piece of logic in the plugin, which is exactly what section 4.2
 * warns against.
 *
 * @since 0.7.0
 */
final class Next_Run_Readout {

	/**
	 * Returns a formatted description of a prompt's next run.
	 *
	 * @since 0.7.0
	 *
	 * @param int $prompt_id Prompt to describe.
	 * @return string
	 */
	public static function describe( int $prompt_id ): string {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			return __( 'Not scheduled.', 'autoscribe' );
		}

		if ( ! $prompt->enabled() ) {
			return __( 'Disabled. This prompt is not queued.', 'autoscribe' );
		}

		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			/* translators: %s: validation error message. */
			return sprintf( __( 'Schedule is not valid: %s', 'autoscribe' ), $schedule->get_error_message() );
		}

		$next = ( new Next_Run_Calculator() )->next( $schedule );

		if ( is_wp_error( $next ) ) {
			return $next->get_error_message();
		}

		return self::format( $next->getTimestamp() );
	}

	/**
	 * Formats a UTC timestamp in the site's timezone and locale.
	 *
	 * @since 0.7.0
	 *
	 * @param int $timestamp UTC Unix timestamp.
	 * @return string
	 */
	public static function format( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'Not scheduled.', 'autoscribe' );
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( (string) $format, $timestamp ) . ' ' . wp_timezone_string();
	}
}
