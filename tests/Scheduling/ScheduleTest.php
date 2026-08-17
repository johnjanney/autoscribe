<?php
/**
 * Schedule validation tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Scheduling;

use AutoScribe\Scheduling\Schedule;
use WP_UnitTestCase;

/**
 * Covers parameter validation for the six types in section 4.1.
 *
 * @since 0.4.0
 */
final class ScheduleTest extends WP_UnitTestCase {

	/**
	 * All six types from section 4.1 are recognised, in order.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_six_types_are_defined(): void {
		$this->assertSame(
			array( 'daily', 'weekly', 'monthly_date', 'monthly_ordinal', 'interval', 'cron_expression' ),
			Schedule::types()
		);
	}

	/**
	 * A valid daily schedule parses its time.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_valid_daily_schedule(): void {
		$schedule = Schedule::create( Schedule::TYPE_DAILY, array( 'time' => '06:30' ) );

		$this->assertNotWPError( $schedule );
		$this->assertSame( 6, $schedule->hour() );
		$this->assertSame( 30, $schedule->minute() );
	}

	/**
	 * A malformed time is rejected rather than silently defaulted.
	 *
	 * @since 0.4.0
	 *
	 * @param string $time Candidate time string.
	 * @return void
	 *
	 * @dataProvider bad_times
	 */
	public function test_bad_time_is_rejected( string $time ): void {
		$result = Schedule::create( Schedule::TYPE_DAILY, array( 'time' => $time ) );

		$this->assertWPError( $result, $time );
		$this->assertSame( 'autoscribe_invalid_schedule_parameter', $result->get_error_code() );
	}

	/**
	 * Times that must not be accepted.
	 *
	 * @since 0.4.0
	 *
	 * @return array<int, string[]>
	 */
	public function bad_times(): array {
		return array(
			array( '' ),
			array( '6:00' ),
			array( '24:00' ),
			array( '06:60' ),
			array( 'morning' ),
			array( '06:00:00' ),
		);
	}

	/**
	 * An unknown weekday is rejected.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_unknown_weekday_is_rejected(): void {
		$result = Schedule::create(
			Schedule::TYPE_WEEKLY,
			array(
				'time'    => '06:00',
				'weekday' => 'funday',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Weekday matching is case-insensitive.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_weekday_is_case_insensitive(): void {
		$schedule = Schedule::create(
			Schedule::TYPE_WEEKLY,
			array(
				'time'    => '06:00',
				'weekday' => 'MONDAY',
			)
		);

		$this->assertNotWPError( $schedule );
		$this->assertSame( 'monday', $schedule->weekday() );
	}

	/**
	 * A day of month outside 1-31 is rejected.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_day_of_month_bounds(): void {
		$this->assertWPError(
			Schedule::create(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 0,
				)
			)
		);
		$this->assertWPError(
			Schedule::create(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 32,
				)
			)
		);
		$this->assertNotWPError(
			Schedule::create(
				Schedule::TYPE_MONTHLY_DATE,
				array(
					'time'         => '06:00',
					'day_of_month' => 31,
				)
			)
		);
	}

	/**
	 * Section 4.1 lists exactly five ordinals.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_ordinals_match_the_brief(): void {
		$this->assertSame(
			array( 'first', 'second', 'third', 'fourth', 'last' ),
			Schedule::ORDINALS
		);

		$this->assertWPError(
			Schedule::create(
				Schedule::TYPE_MONTHLY_ORDINAL,
				array(
					'time'    => '06:00',
					'ordinal' => 'fifth',
					'weekday' => 'tuesday',
				)
			)
		);
	}

	/**
	 * An interval must be at least one hour.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_interval_must_be_positive(): void {
		$this->assertWPError( Schedule::create( Schedule::TYPE_INTERVAL, array( 'hours' => 0 ) ) );
		$this->assertNotWPError( Schedule::create( Schedule::TYPE_INTERVAL, array( 'hours' => 72 ) ) );
	}

	/**
	 * An empty cron expression is rejected.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_empty_cron_expression_is_rejected(): void {
		$this->assertWPError( Schedule::create( Schedule::TYPE_CRON, array( 'expression' => '   ' ) ) );
	}

	/**
	 * An unknown type names the valid ones.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_unknown_type_is_rejected(): void {
		$result = Schedule::create( 'fortnightly', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_invalid_schedule_type', $result->get_error_code() );
		$this->assertStringContainsString( 'monthly_ordinal', $result->get_error_message() );
	}
}
