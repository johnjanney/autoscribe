<?php
/**
 * Next-run calculation.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Works out when a schedule next fires.
 *
 * Section 4.2 calls this the highest-risk logic in the plugin, and the risk is
 * not the arithmetic but the calendar: months of unequal length, leap years,
 * and the two days a year when local time is not monotonic.
 *
 * Two design rules follow from that.
 *
 * Every candidate is built from a local wall-clock string and handed to PHP to
 * resolve, rather than assembled by adding seconds. PHP already implements the
 * behaviour section 4.2 asks for: a time that does not exist on a spring-forward
 * day rolls forward an hour, and a time that occurs twice on a fall-back day
 * resolves to the first. Writing that correction by hand on top would shift
 * correct results by an extra hour.
 *
 * Month arithmetic never uses relative modification. In PHP, 31 January plus one
 * month is 3 March, because the overflow spills into the next month. Section 4.2
 * requires 31 February to become the last day of February instead, so the day is
 * clamped against the target month's own length.
 *
 * The returned time is always strictly in the future relative to the reference
 * point. Section 4.3 forbids backfilling: a site that was offline for a week
 * runs once and moves on rather than queueing seven articles.
 *
 * @since 0.4.0
 */
final class Next_Run_Calculator {

	/**
	 * How many candidate months to try before giving up.
	 *
	 * A monthly_ordinal schedule always resolves within one month, and a clamped
	 * monthly_date within one. The bound exists so a malformed schedule fails
	 * with an error rather than looping.
	 *
	 * @since 0.4.0
	 * @var int
	 */
	private const MAX_MONTHS = 60;

	/**
	 * Timezone every calculation is performed in.
	 *
	 * @since 0.4.0
	 * @var DateTimeZone
	 */
	private DateTimeZone $timezone;

	/**
	 * Builds the calculator.
	 *
	 * @since 0.4.0
	 *
	 * @param DateTimeZone|null $timezone Site timezone, or null to read it from WordPress.
	 */
	public function __construct( ?DateTimeZone $timezone = null ) {
		$this->timezone = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
	}

	/**
	 * Returns the timezone calculations are performed in.
	 *
	 * @since 0.4.0
	 *
	 * @return DateTimeZone
	 */
	public function timezone(): DateTimeZone {
		return $this->timezone;
	}

	/**
	 * Returns the next firing time strictly after the reference point.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule               $schedule Schedule to evaluate.
	 * @param DateTimeImmutable|null $after    Reference point, or null for now.
	 * @return DateTimeImmutable|WP_Error
	 */
	public function next( Schedule $schedule, ?DateTimeImmutable $after = null ): DateTimeImmutable|WP_Error {
		$reference = $after instanceof DateTimeImmutable
			? $after->setTimezone( $this->timezone )
			: new DateTimeImmutable( 'now', $this->timezone );

		switch ( $schedule->type() ) {
			case Schedule::TYPE_DAILY:
				return $this->next_daily( $schedule, $reference );

			case Schedule::TYPE_WEEKLY:
				return $this->next_weekly( $schedule, $reference );

			case Schedule::TYPE_MONTHLY_DATE:
				return $this->next_monthly_date( $schedule, $reference );

			case Schedule::TYPE_MONTHLY_ORDINAL:
				return $this->next_monthly_ordinal( $schedule, $reference );

			case Schedule::TYPE_INTERVAL:
				return $this->next_interval( $schedule, $reference );

			case Schedule::TYPE_CRON:
				return $this->next_cron( $schedule, $reference );
		}

		return new WP_Error(
			'autoscribe_invalid_schedule_type',
			__( 'This schedule type cannot be calculated.', 'autoscribe' )
		);
	}

	/**
	 * Next occurrence of a daily schedule.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable
	 */
	private function next_daily( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable {
		for ( $offset = 0; $offset <= 1; $offset++ ) {
			$date      = $reference->modify( sprintf( '+%d day', $offset ) )->format( 'Y-m-d' );
			$candidate = $this->at( $date, $schedule );

			if ( $candidate > $reference ) {
				return $candidate;
			}
		}

		return $this->at( $reference->modify( '+2 day' )->format( 'Y-m-d' ), $schedule );
	}

	/**
	 * Next occurrence of a weekly schedule.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable
	 */
	private function next_weekly( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable {
		for ( $offset = 0; $offset <= 7; $offset++ ) {
			$day = $reference->modify( sprintf( '+%d day', $offset ) );

			if ( strtolower( $day->format( 'l' ) ) !== $schedule->weekday() ) {
				continue;
			}

			$candidate = $this->at( $day->format( 'Y-m-d' ), $schedule );

			if ( $candidate > $reference ) {
				return $candidate;
			}
		}

		return $this->at( $reference->modify( '+7 day' )->format( 'Y-m-d' ), $schedule );
	}

	/**
	 * Next occurrence of a numbered day of the month.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable|WP_Error
	 */
	private function next_monthly_date( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable|WP_Error {
		$year  = (int) $reference->format( 'Y' );
		$month = (int) $reference->format( 'n' );

		for ( $step = 0; $step < self::MAX_MONTHS; $step++ ) {
			$candidate = $this->at(
				sprintf( '%04d-%02d-%02d', $year, $month, $this->clamp_day( $year, $month, $schedule->day_of_month() ) ),
				$schedule
			);

			if ( $candidate > $reference ) {
				return $candidate;
			}

			++$month;

			if ( $month > 12 ) {
				$month = 1;
				++$year;
			}
		}

		return $this->exhausted();
	}

	/**
	 * Next occurrence of an ordinal weekday of the month.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable|WP_Error
	 */
	private function next_monthly_ordinal( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable|WP_Error {
		$year  = (int) $reference->format( 'Y' );
		$month = (int) $reference->format( 'n' );

		for ( $step = 0; $step < self::MAX_MONTHS; $step++ ) {
			$phrase = sprintf(
				'%s %s of %s %d',
				$schedule->ordinal(),
				$schedule->weekday(),
				gmdate( 'F', gmmktime( 0, 0, 0, $month, 1, $year ) ),
				$year
			);

			try {
				$day = new DateTimeImmutable( $phrase, $this->timezone );
			} catch ( Exception $error ) {
				return new WP_Error( 'autoscribe_invalid_schedule_parameter', $error->getMessage() );
			}

			$candidate = $this->at( $day->format( 'Y-m-d' ), $schedule );

			if ( $candidate > $reference ) {
				return $candidate;
			}

			++$month;

			if ( $month > 12 ) {
				$month = 1;
				++$year;
			}
		}

		return $this->exhausted();
	}

	/**
	 * Next occurrence of a fixed-hour interval.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable
	 */
	private function next_interval( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable {
		return $reference->modify( sprintf( '+%d hours', $schedule->hours() ) );
	}

	/**
	 * Next occurrence of a cron expression.
	 *
	 * @since 0.4.0
	 *
	 * @param Schedule          $schedule  Schedule to evaluate.
	 * @param DateTimeImmutable $reference Reference point.
	 * @return DateTimeImmutable|WP_Error
	 */
	private function next_cron( Schedule $schedule, DateTimeImmutable $reference ): DateTimeImmutable|WP_Error {
		if ( ! CronExpression::isValidExpression( $schedule->expression() ) ) {
			return new WP_Error(
				'autoscribe_invalid_schedule_parameter',
				sprintf(
					/* translators: %s: the cron expression that could not be parsed. */
					__( 'This is not a valid cron expression: %s', 'autoscribe' ),
					$schedule->expression()
				)
			);
		}

		try {
			$next = ( new CronExpression( $schedule->expression() ) )
				->getNextRunDate( $reference, 0, false, $this->timezone->getName() );
		} catch ( Exception $error ) {
			return new WP_Error( 'autoscribe_invalid_schedule_parameter', $error->getMessage() );
		}

		return DateTimeImmutable::createFromMutable( $next )->setTimezone( $this->timezone );
	}

	/**
	 * Builds a local wall-clock time on a given date.
	 *
	 * Constructing from a wall-clock string rather than adding seconds is what
	 * lets PHP apply the daylight-saving rules described in section 4.2.
	 *
	 * @since 0.4.0
	 *
	 * @param string   $date     Date in Y-m-d form.
	 * @param Schedule $schedule Schedule supplying the time of day.
	 * @return DateTimeImmutable
	 */
	private function at( string $date, Schedule $schedule ): DateTimeImmutable {
		return new DateTimeImmutable(
			sprintf( '%s %02d:%02d:00', $date, $schedule->hour(), $schedule->minute() ),
			$this->timezone
		);
	}

	/**
	 * Clamps a requested day of month to the length of that month.
	 *
	 * Section 4.2: the 31st of February must roll to the last day of February,
	 * not into March.
	 *
	 * @since 0.4.0
	 *
	 * @param int $year  Target year.
	 * @param int $month Target month.
	 * @param int $day   Requested day.
	 * @return int
	 */
	private function clamp_day( int $year, int $month, int $day ): int {
		$days_in_month = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

		return min( $day, $days_in_month );
	}

	/**
	 * Error returned when no occurrence was found within the search bound.
	 *
	 * @since 0.4.0
	 *
	 * @return WP_Error
	 */
	private function exhausted(): WP_Error {
		return new WP_Error(
			'autoscribe_no_occurrence',
			__( 'No future occurrence of this schedule could be found.', 'autoscribe' )
		);
	}
}
