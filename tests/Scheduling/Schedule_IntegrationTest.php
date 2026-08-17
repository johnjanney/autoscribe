<?php
/**
 * End-to-end schedule tests across three prompts.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Scheduling;

use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Next_Run_Calculator;
use AutoScribe\Tests\Support\Creates_Prompts;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Section 11's Phase 4 completion criterion.
 *
 * The brief asks that three prompts on three different schedule types all fire
 * at the correct local times across a month boundary and a daylight saving
 * transition. Taken literally that is a month of waiting, so it is expressed
 * here as fixed dates driven through the real prompt objects rather than
 * through the calculator alone: prompts are created, their stored meta is read
 * back, Schedule::create validates it, and the calculator answers from there.
 * That is the whole chain the queue uses.
 *
 * Every case asserts an exact UTC instant. A range would pass even if the
 * offset were wrong by an hour, which is the specific bug daylight saving
 * causes.
 *
 * @since 0.8.0
 */
final class Schedule_IntegrationTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Site timezone used throughout, per section 4.2.
	 *
	 * @since 0.8.0
	 * @var string
	 */
	private const TZ = 'America/Chicago';

	/**
	 * Puts the site in a timezone that observes daylight saving.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', self::TZ );
	}

	/**
	 * Returns the next run for a prompt, evaluated from a fixed instant.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $prompt_id Prompt to evaluate.
	 * @param string $after     Local datetime to evaluate from.
	 * @return DateTimeImmutable
	 */
	private function next_run( int $prompt_id, string $after ): DateTimeImmutable {
		$prompt = Prompt::load( $prompt_id );

		$this->assertNotNull( $prompt );

		$schedule = $prompt->schedule();

		$this->assertNotWPError( $schedule, 'the stored schedule did not validate' );

		$next = ( new Next_Run_Calculator() )->next(
			$schedule,
			new DateTimeImmutable( $after, new DateTimeZone( self::TZ ) )
		);

		$this->assertNotWPError( $next );

		return $next;
	}

	/**
	 * Asserts a run lands on an exact UTC instant.
	 *
	 * @since 0.8.0
	 *
	 * @param string            $expected_utc Expected instant as Y-m-d H:i:s in UTC.
	 * @param DateTimeImmutable $actual       Computed next run.
	 * @param string            $message      Failure message.
	 * @return void
	 */
	private function assertUtc( string $expected_utc, DateTimeImmutable $actual, string $message = '' ): void {
		$this->assertSame(
			$expected_utc,
			$actual->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$message
		);
	}

	/**
	 * Creates the three prompts the brief asks for.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, int> Type to prompt ID.
	 */
	private function three_prompts(): array {
		return array(
			'daily'           => $this->create_prompt(
				array(
					'schedule_type'   => 'daily',
					'schedule_params' => array( 'time' => '06:00' ),
				)
			),
			'weekly'          => $this->create_prompt(
				array(
					'schedule_type'   => 'weekly',
					'schedule_params' => array(
						'weekday' => 'monday',
						'time'    => '06:00',
					),
				)
			),
			'monthly_ordinal' => $this->create_prompt(
				array(
					'schedule_type'   => 'monthly_ordinal',
					'schedule_params' => array(
						'ordinal' => 'second',
						'weekday' => 'tuesday',
						'time'    => '06:00',
					),
				)
			),
		);
	}

	/**
	 * All three prompts cross a month boundary correctly.
	 *
	 * Evaluated from late May 2026, so each has to roll into June rather than
	 * returning a date in the month it started from.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_three_prompts_cross_a_month_boundary(): void {
		$prompts = $this->three_prompts();

		// 31 May 2026 is a Sunday. 06:00 CDT is 11:00 UTC.
		$this->assertUtc(
			'2026-06-01 11:00:00',
			$this->next_run( $prompts['daily'], '2026-05-31 12:00:00' ),
			'daily did not roll into June'
		);

		// The next Monday after Thursday 28 May 2026 is 1 June.
		$this->assertUtc(
			'2026-06-01 11:00:00',
			$this->next_run( $prompts['weekly'], '2026-05-28 12:00:00' ),
			'weekly did not roll into June'
		);

		// September's second Tuesday is the 8th, so from the 9th the next is
		// October's, the 13th.
		$this->assertUtc(
			'2026-10-13 11:00:00',
			$this->next_run( $prompts['monthly_ordinal'], '2026-09-09 12:00:00' ),
			'monthly ordinal did not roll into October'
		);
	}

	/**
	 * All three prompts keep the right offset across spring forward.
	 *
	 * Daylight saving begins in America/Chicago on 8 March 2026. A schedule set
	 * for 06:00 must stay at 06:00 local, which means the UTC instant moves by
	 * an hour. Asserting UTC is what makes that visible; asserting local time
	 * would pass even if the offset were wrong.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_three_prompts_survive_spring_forward(): void {
		$prompts = $this->three_prompts();

		// The day before: 06:00 CST is 12:00 UTC.
		$this->assertUtc(
			'2026-03-07 12:00:00',
			$this->next_run( $prompts['daily'], '2026-03-06 12:00:00' )
		);

		// The transition day itself: 06:00 CDT is 11:00 UTC.
		$this->assertUtc(
			'2026-03-08 11:00:00',
			$this->next_run( $prompts['daily'], '2026-03-07 12:00:00' ),
			'daily did not follow the clock forward'
		);

		// Monday 9 March 2026, the day after the change.
		$this->assertUtc(
			'2026-03-09 11:00:00',
			$this->next_run( $prompts['weekly'], '2026-03-03 12:00:00' )
		);

		// March's second Tuesday is the 10th, already on daylight time.
		$this->assertUtc(
			'2026-03-10 11:00:00',
			$this->next_run( $prompts['monthly_ordinal'], '2026-03-01 12:00:00' )
		);
	}

	/**
	 * All three prompts keep the right offset across fall back.
	 *
	 * Daylight saving ends on 1 November 2026.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_three_prompts_survive_fall_back(): void {
		$prompts = $this->three_prompts();

		// The day before: 06:00 CDT is 11:00 UTC.
		$this->assertUtc(
			'2026-10-31 11:00:00',
			$this->next_run( $prompts['daily'], '2026-10-30 12:00:00' )
		);

		// The transition day: 06:00 CST is 12:00 UTC.
		$this->assertUtc(
			'2026-11-01 12:00:00',
			$this->next_run( $prompts['daily'], '2026-10-31 12:00:00' ),
			'daily did not follow the clock back'
		);

		// Monday 2 November 2026, on standard time.
		$this->assertUtc(
			'2026-11-02 12:00:00',
			$this->next_run( $prompts['weekly'], '2026-10-27 12:00:00' )
		);

		// November's second Tuesday is the 10th, on standard time.
		$this->assertUtc(
			'2026-11-10 12:00:00',
			$this->next_run( $prompts['monthly_ordinal'], '2026-11-01 12:00:00' )
		);
	}

	/**
	 * A time that does not exist on the spring-forward day moves forward.
	 *
	 * Section 4.2 requires this. 02:30 simply does not occur on 8 March 2026:
	 * the clock jumps from 02:00 to 03:00. The run must happen at 03:30 local
	 * rather than being skipped or landing a day late.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_nonexistent_local_time_moves_forward(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '02:30' ),
			)
		);

		// 03:30 CDT is 08:30 UTC.
		$this->assertUtc(
			'2026-03-08 08:30:00',
			$this->next_run( $prompt_id, '2026-03-07 12:00:00' )
		);
	}

	/**
	 * A time that occurs twice on the fall-back day takes the first.
	 *
	 * Section 4.2 requires the earlier of the two. 01:30 happens once on
	 * daylight time and again an hour later on standard time; taking the first
	 * means 06:30 UTC, not 07:30.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_an_ambiguous_local_time_takes_the_first(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '01:30' ),
			)
		);

		$this->assertUtc(
			'2026-11-01 06:30:00',
			$this->next_run( $prompt_id, '2026-10-31 12:00:00' )
		);
	}

	/**
	 * A schedule whose stored next run is long past still returns a future run.
	 *
	 * Section 4.3 forbids backfilling: a site offline for a week runs once and
	 * moves on rather than queueing seven articles.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_stale_schedule_returns_a_single_future_run(): void {
		$prompts = $this->three_prompts();

		$prompt = Prompt::load( $prompts['daily'] );

		$prompt->set_next_run_ts( strtotime( '2020-01-01 06:00:00 UTC' ) );

		$next = $this->next_run( $prompts['daily'], '2026-06-15 12:00:00' );

		$this->assertUtc( '2026-06-16 11:00:00', $next );
	}
}
