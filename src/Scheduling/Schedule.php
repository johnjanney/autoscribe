<?php
/**
 * Schedule value object.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A validated schedule: one type plus the parameters that type needs.
 *
 * Section 4.1 defines six types. Validation happens once here so the calculator
 * can assume its inputs are sane rather than re-checking on every call.
 *
 * @since 0.4.0
 */
final class Schedule {

	/**
	 * Every day at a fixed local time.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_DAILY = 'daily';

	/**
	 * A named weekday at a fixed local time.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_WEEKLY = 'weekly';

	/**
	 * A numbered day of the month.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_MONTHLY_DATE = 'monthly_date';

	/**
	 * An ordinal weekday of the month, such as the second Tuesday.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_MONTHLY_ORDINAL = 'monthly_ordinal';

	/**
	 * A fixed number of hours after the previous run.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_INTERVAL = 'interval';

	/**
	 * A cron expression, for advanced users.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const TYPE_CRON = 'cron_expression';

	/**
	 * Weekday names accepted by the weekly type.
	 *
	 * @since 0.4.0
	 * @var string[]
	 */
	public const WEEKDAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/**
	 * Ordinals accepted by the monthly_ordinal type, per section 4.1.
	 *
	 * @since 0.4.0
	 * @var string[]
	 */
	public const ORDINALS = array( 'first', 'second', 'third', 'fourth', 'last' );

	/**
	 * Schedule type.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private string $type;

	/**
	 * Validated parameters.
	 *
	 * @since 0.4.0
	 * @var array<string, mixed>
	 */
	private array $params;

	/**
	 * Builds a schedule from already-validated parts.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $type   Schedule type.
	 * @param array<string, mixed> $params Validated parameters.
	 */
	private function __construct( string $type, array $params ) {
		$this->type   = $type;
		$this->params = $params;
	}

	/**
	 * Validates raw parameters and builds a schedule.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $type   Schedule type.
	 * @param array<string, mixed> $params Raw parameters.
	 * @return Schedule|WP_Error Schedule, or an error naming the bad parameter.
	 */
	public static function create( string $type, array $params ): Schedule|WP_Error {
		switch ( $type ) {
			case self::TYPE_DAILY:
				return self::with_time( $type, $params );

			case self::TYPE_WEEKLY:
				$weekly = self::with_time( $type, $params );

				if ( is_wp_error( $weekly ) ) {
					return $weekly;
				}

				$weekday = strtolower( (string) ( $params['weekday'] ?? '' ) );

				if ( ! in_array( $weekday, self::WEEKDAYS, true ) ) {
					return self::invalid( 'weekday', implode( ', ', self::WEEKDAYS ) );
				}

				return new self( $type, array_merge( $weekly->params, array( 'weekday' => $weekday ) ) );

			case self::TYPE_MONTHLY_DATE:
				$monthly = self::with_time( $type, $params );

				if ( is_wp_error( $monthly ) ) {
					return $monthly;
				}

				$day = (int) ( $params['day_of_month'] ?? 0 );

				if ( $day < 1 || $day > 31 ) {
					return self::invalid( 'day_of_month', '1-31' );
				}

				return new self( $type, array_merge( $monthly->params, array( 'day_of_month' => $day ) ) );

			case self::TYPE_MONTHLY_ORDINAL:
				$ordinal_schedule = self::with_time( $type, $params );

				if ( is_wp_error( $ordinal_schedule ) ) {
					return $ordinal_schedule;
				}

				$ordinal = strtolower( (string) ( $params['ordinal'] ?? '' ) );
				$weekday = strtolower( (string) ( $params['weekday'] ?? '' ) );

				if ( ! in_array( $ordinal, self::ORDINALS, true ) ) {
					return self::invalid( 'ordinal', implode( ', ', self::ORDINALS ) );
				}

				if ( ! in_array( $weekday, self::WEEKDAYS, true ) ) {
					return self::invalid( 'weekday', implode( ', ', self::WEEKDAYS ) );
				}

				return new self(
					$type,
					array_merge(
						$ordinal_schedule->params,
						array(
							'ordinal' => $ordinal,
							'weekday' => $weekday,
						)
					)
				);

			case self::TYPE_INTERVAL:
				$hours = (int) ( $params['hours'] ?? 0 );

				if ( $hours < 1 ) {
					return self::invalid( 'hours', '1 or more' );
				}

				return new self( $type, array( 'hours' => $hours ) );

			case self::TYPE_CRON:
				$expression = trim( (string) ( $params['expression'] ?? '' ) );

				if ( '' === $expression ) {
					return self::invalid( 'expression', 'a five-field cron expression' );
				}

				return new self( $type, array( 'expression' => $expression ) );
		}

		return new WP_Error(
			'autoscribe_invalid_schedule_type',
			sprintf(
				/* translators: %s: comma-separated list of valid schedule types. */
				__( 'Unknown schedule type. Valid types are: %s', 'autoscribe' ),
				implode( ', ', self::types() )
			)
		);
	}

	/**
	 * Returns every valid schedule type.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array(
			self::TYPE_DAILY,
			self::TYPE_WEEKLY,
			self::TYPE_MONTHLY_DATE,
			self::TYPE_MONTHLY_ORDINAL,
			self::TYPE_INTERVAL,
			self::TYPE_CRON,
		);
	}

	/**
	 * Returns the schedule type.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the hour component of the target local time.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function hour(): int {
		return (int) ( $this->params['hour'] ?? 0 );
	}

	/**
	 * Returns the minute component of the target local time.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function minute(): int {
		return (int) ( $this->params['minute'] ?? 0 );
	}

	/**
	 * Returns the configured weekday.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function weekday(): string {
		return (string) ( $this->params['weekday'] ?? '' );
	}

	/**
	 * Returns the configured day of month.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function day_of_month(): int {
		return (int) ( $this->params['day_of_month'] ?? 1 );
	}

	/**
	 * Returns the configured ordinal.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function ordinal(): string {
		return (string) ( $this->params['ordinal'] ?? '' );
	}

	/**
	 * Returns the configured interval in hours.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function hours(): int {
		return (int) ( $this->params['hours'] ?? 24 );
	}

	/**
	 * Returns the configured cron expression.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function expression(): string {
		return (string) ( $this->params['expression'] ?? '' );
	}

	/**
	 * Validates the shared time parameter.
	 *
	 * @since 0.4.0
	 *
	 * @param string               $type   Schedule type.
	 * @param array<string, mixed> $params Raw parameters.
	 * @return Schedule|WP_Error
	 */
	private static function with_time( string $type, array $params ): Schedule|WP_Error {
		$time = (string) ( $params['time'] ?? '' );

		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches ) ) {
			return self::invalid( 'time', 'HH:MM in 24-hour form' );
		}

		return new self(
			$type,
			array(
				'hour'   => (int) $matches[1],
				'minute' => (int) $matches[2],
			)
		);
	}

	/**
	 * Builds a consistent validation error.
	 *
	 * @since 0.4.0
	 *
	 * @param string $parameter Parameter name.
	 * @param string $expected  Human-readable description of valid input.
	 * @return WP_Error
	 */
	private static function invalid( string $parameter, string $expected ): WP_Error {
		return new WP_Error(
			'autoscribe_invalid_schedule_parameter',
			sprintf(
				/* translators: 1: parameter name, 2: description of valid values. */
				__( 'The %1$s parameter is invalid. Expected %2$s.', 'autoscribe' ),
				$parameter,
				$expected
			)
		);
	}
}
