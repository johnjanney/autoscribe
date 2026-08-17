<?php
/**
 * Next-run calculator tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Scheduling;

use AutoScribe\Scheduling\Next_Run_Calculator;
use AutoScribe\Scheduling\Schedule;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Covers section 4 scheduling, the highest-risk logic in the plugin.
 *
 * Every assertion is an exact UTC instant rather than a range. A range would
 * pass while an hour out, which is the specific failure daylight-saving bugs
 * produce, and the one this suite exists to catch.
 *
 * America/Chicago is used throughout because it observes daylight saving with
 * both a skipped hour and a repeated hour, and because section 4.2 names it.
 *
 * @since 0.4.0
 */
final class Next_Run_CalculatorTest extends WP_UnitTestCase {

	/**
	 * Timezone every case is evaluated in.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const TZ = 'America/Chicago';

	/**
	 * Builds a calculator pinned to the test timezone.
	 *
	 * @since 0.4.0
	 *
	 * @return Next_Run_Calculator
	 */
	private function calculator(): Next_Run_Calculator {
		return new Next_Run_Calculator( new DateTimeZone( self::TZ ) );
	}

	/**
	 * Builds a local reference instant.
	 *
	 * @since 0.4.0
	 *
	 * @param string $local Local wall-clock time.
	 * @return DateTimeImmutable
	 */
	private function local( string $local ): DateTimeImmutable {
		return new DateTimeImmutable( $local, new DateTimeZone( self::TZ ) );
	}

	/**
	 * Builds a schedule, failing the test if the parameters are rejected.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $type   Schedule type.
	 * @param array<string, mixed> $params Parameters.
	 * @return Schedule
	 */
	private function schedule( string $type, array $params ): Schedule {
		$schedule = Schedule::create( $type, $params );

		$this->assertNotWPError( $schedule );

		return $schedule;
	}

	/**
	 * Asserts the calculated run lands on an exact UTC instant.
	 *
	 * @since 0.4.0
	 *
	 * @param string $expected_utc Expected instant as Y-m-d H:i:s in UTC.
	 * @param mixed  $result       Calculator return value.
	 * @return void
	 */
	private function assertRunsAtUtc( string $expected_utc, $result ): void {
		$this->assertNotWPError( $result );
		$this->assertSame(
			$expected_utc,
			$result->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Type 1 of 6: daily, later the same day.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_daily_later_today(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '06:00' ) ),
			$this->local( '2026-05-10 05:00:00' )
		);

		$this->assertRunsAtUtc( '2026-05-10 11:00:00', $result );
	}

	/**
	 * Type 1 of 6: daily, rolling to tomorrow once today's time has passed.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_daily_rolls_to_tomorrow(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '06:00' ) ),
			$this->local( '2026-05-10 07:00:00' )
		);

		$this->assertRunsAtUtc( '2026-05-11 11:00:00', $result );
	}

	/**
	 * Type 2 of 6: weekly.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_weekly_finds_the_next_named_weekday(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_WEEKLY,
				array(
					'time'    => '06:00',
					'weekday' => 'monday',
				)
			),
			$this->local( '2026-05-13 12:00:00' )
		);

		// 13 May 2026 is a Wednesday; the next Monday is the 18th.
		$this->assertRunsAtUtc( '2026-05-18 11:00:00', $result );
	}

	/**
	 * Type 3 of 6: a numbered day of the month.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_monthly_date_moves_to_next_month(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 15,
				)
			),
			$this->local( '2026-05-20 12:00:00' )
		);

		$this->assertRunsAtUtc( '2026-06-15 11:00:00', $result );
	}

	/**
	 * Type 4 of 6, and the second-Tuesday case named by the goal.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_second_tuesday_of_the_month(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_ORDINAL,
				array(
					'time'    => '06:00',
					'ordinal' => 'second',
					'weekday' => 'tuesday',
				)
			),
			$this->local( '2026-03-01 00:00:00' )
		);

		// The second Tuesday of March 2026 is the 10th, after the spring
		// transition, so the offset is CDT rather than CST.
		$this->assertRunsAtUtc( '2026-03-10 11:00:00', $result );
	}

	/**
	 * The last-weekday case named by the goal.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_last_weekday_of_the_month(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_ORDINAL,
				array(
					'time'    => '06:00',
					'ordinal' => 'last',
					'weekday' => 'friday',
				)
			),
			$this->local( '2026-05-01 00:00:00' )
		);

		// The last Friday of May 2026 is the 29th, not the 22nd.
		$this->assertRunsAtUtc( '2026-05-29 11:00:00', $result );
	}

	/**
	 * The last ordinal lands on the fourth occurrence when there is no fifth.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_last_weekday_in_a_short_month(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_ORDINAL,
				array(
					'time'    => '06:00',
					'ordinal' => 'last',
					'weekday' => 'friday',
				)
			),
			$this->local( '2026-02-01 00:00:00' )
		);

		// February 2026 has four Fridays; the last is the 27th.
		$this->assertRunsAtUtc( '2026-02-27 12:00:00', $result );
	}

	/**
	 * Type 5 of 6: a fixed-hour interval.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_interval_adds_absolute_hours(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_INTERVAL, array( 'hours' => 72 ) ),
			$this->local( '2026-05-10 06:00:00' )
		);

		$this->assertRunsAtUtc( '2026-05-13 11:00:00', $result );
	}

	/**
	 * Type 6 of 6: a cron expression.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_cron_expression(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_CRON, array( 'expression' => '0 6 * * 1' ) ),
			$this->local( '2026-05-13 12:00:00' )
		);

		$this->assertRunsAtUtc( '2026-05-18 11:00:00', $result );
	}

	/**
	 * The 31 January case named by the goal.
	 *
	 * Adding one month to 31 January in PHP produces 3 March, because the
	 * overflow spills forward. Section 4.2 requires the day to clamp to the end
	 * of the target month instead.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_thirty_first_of_january_rolls_forward_to_end_of_february(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 31,
				)
			),
			$this->local( '2026-01-31 07:00:00' )
		);

		// 28 February 2026, not 3 March.
		$this->assertRunsAtUtc( '2026-02-28 12:00:00', $result );
	}

	/**
	 * The leap-day case named by the goal.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_leap_day_is_reachable_in_a_leap_year(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 29,
				)
			),
			$this->local( '2028-02-01 00:00:00' )
		);

		$this->assertRunsAtUtc( '2028-02-29 12:00:00', $result );
	}

	/**
	 * The same schedule clamps to the 28th in a non-leap year.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_leap_day_clamps_in_a_non_leap_year(): void {
		$result = $this->calculator()->next(
			$this->schedule(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 29,
				)
			),
			$this->local( '2026-02-01 00:00:00' )
		);

		$this->assertRunsAtUtc( '2026-02-28 12:00:00', $result );
	}

	/**
	 * Spring forward: a local time that does not exist.
	 *
	 * On 8 March 2026 America/Chicago jumps from 02:00 CST to 03:00 CDT, so
	 * 02:30 never occurs. Section 4.2 requires it to move forward an hour.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_spring_forward_skipped_hour_moves_forward(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '02:30' ) ),
			$this->local( '2026-03-08 00:00:00' )
		);

		$this->assertNotWPError( $result );

		// 03:30 CDT, which is 08:30 UTC. Had it stayed 02:30 it would be 08:00.
		$this->assertRunsAtUtc( '2026-03-08 08:30:00', $result );
		$this->assertSame( '03:30', $result->format( 'H:i' ) );
		$this->assertSame( 'CDT', $result->format( 'T' ) );
	}

	/**
	 * Fall back: a local time that occurs twice.
	 *
	 * On 1 November 2026 America/Chicago repeats 01:00 to 02:00. Section 4.2
	 * requires the first occurrence, the one still on daylight time.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_fall_back_repeated_hour_takes_the_first(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '01:30' ) ),
			$this->local( '2026-11-01 00:00:00' )
		);

		$this->assertNotWPError( $result );

		// The first 01:30 is CDT at 06:30 UTC; the second would be CST at 07:30.
		$this->assertRunsAtUtc( '2026-11-01 06:30:00', $result );
		$this->assertSame( 'CDT', $result->format( 'T' ) );
	}

	/**
	 * A daily schedule keeps its wall-clock time across the spring transition.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_wall_clock_time_is_preserved_across_the_transition(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '06:00' ) ),
			$this->local( '2026-03-07 12:00:00' )
		);

		// 06:00 local on both sides of the change, but 12:00 UTC before and
		// 11:00 UTC after. This asserts the day after the jump.
		$this->assertRunsAtUtc( '2026-03-08 11:00:00', $result );
		$this->assertSame( '06:00', $result->format( 'H:i' ) );
	}

	/**
	 * The stored-next-run-in-the-past case named by the goal.
	 *
	 * Section 4.3 forbids backfilling: a site offline for a week runs once and
	 * moves on rather than queueing every missed occurrence.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_a_stored_run_in_the_past_does_not_backfill(): void {
		$calculator = $this->calculator();
		$schedule   = $this->schedule( Schedule::TYPE_DAILY, array( 'time' => '06:00' ) );

		// The prompt last fired on 1 May and the site was down for a week.
		$stored_next_run = $this->local( '2026-05-01 06:00:00' );
		$now             = $this->local( '2026-05-08 09:00:00' );

		$this->assertLessThan( $now, $stored_next_run );

		$result = $calculator->next( $schedule, $now );

		// One occurrence, tomorrow. Not the seven that were missed.
		$this->assertRunsAtUtc( '2026-05-09 11:00:00', $result );
	}

	/**
	 * A reference given in another timezone is converted, not misread.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_reference_in_another_timezone_is_converted(): void {
		$result = $this->calculator()->next(
			$this->schedule( Schedule::TYPE_DAILY, array( 'time' => '06:00' ) ),
			new DateTimeImmutable( '2026-05-10 23:00:00', new DateTimeZone( 'UTC' ) )
		);

		// 23:00 UTC is 18:00 in Chicago on the 10th, so the next run is the 11th.
		$this->assertRunsAtUtc( '2026-05-11 11:00:00', $result );
	}

	/**
	 * Every schedule type in section 4.1 is calculable.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_every_schedule_type_resolves(): void {
		$params = array(
			Schedule::TYPE_DAILY           => array( 'time' => '06:00' ),
			Schedule::TYPE_WEEKLY          => array(
				'time'    => '06:00',
				'weekday' => 'monday',
			),
			Schedule::TYPE_MONTHLY_DATE    => array(
				'time'         => '06:00',
				'day_of_month' => 15,
			),
			Schedule::TYPE_MONTHLY_ORDINAL => array(
				'time'    => '06:00',
				'ordinal' => 'second',
				'weekday' => 'tuesday',
			),
			Schedule::TYPE_INTERVAL        => array( 'hours' => 72 ),
			Schedule::TYPE_CRON            => array( 'expression' => '0 6 * * 1' ),
		);

		$this->assertSame( Schedule::types(), array_keys( $params ) );

		$now = $this->local( '2026-05-10 12:00:00' );

		foreach ( $params as $type => $args ) {
			$result = $this->calculator()->next( $this->schedule( $type, $args ), $now );

			$this->assertNotWPError( $result, $type );
			$this->assertGreaterThan( $now, $result, $type );
		}
	}
}
