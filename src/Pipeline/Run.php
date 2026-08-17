<?php
/**
 * Typed accessor over one row of the runs table.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Cost\Pricing_Table;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Records the progress and cost of a single generation run.
 *
 * Timestamps are written in UTC. Section 7.4 sums spend by calendar month in
 * the site timezone, which is only convertible from a known storage timezone,
 * and the brief does not state one.
 *
 * @since 0.3.0
 */
final class Run {

	/**
	 * Status for a run currently executing.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Status for a completed run.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const STATUS_SUCCESS = 'success';

	/**
	 * Status for a failed run.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Status for a run stopped by the budget guard before spending anything.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const STATUS_SKIPPED_BUDGET = 'skipped_budget';

	/**
	 * Status for a run abandoned because the topic was already covered.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

	/**
	 * Row ID.
	 *
	 * @since 0.3.0
	 * @var int
	 */
	private int $id;

	/**
	 * Post created by this run, when one exists.
	 *
	 * @since 0.3.0
	 * @var int|null
	 */
	private ?int $post_id = null;

	/**
	 * Grounding source URLs, once read or written.
	 *
	 * @since 0.8.0
	 * @var string[]|null
	 */
	private ?array $sources = null;

	/**
	 * Usage accumulated during this run, for the final cost calculation.
	 *
	 * @since 0.5.0
	 * @var array<string, int|string>
	 */
	private array $usage = array(
		'text_model'    => '',
		'image_model'   => '',
		'input_tokens'  => 0,
		'output_tokens' => 0,
		'image_count'   => 0,
	);

	/**
	 * Wraps an existing row ID.
	 *
	 * @since 0.3.0
	 *
	 * @param int $id Row ID.
	 */
	private function __construct( int $id ) {
		$this->id = $id;
	}

	/**
	 * Opens a new run row.
	 *
	 * @since 0.3.0
	 *
	 * @param int $prompt_id Prompt being run.
	 * @return Run
	 */
	public static function start( int $prompt_id ): Run {
		global $wpdb;

		$wpdb->insert(
			Activation::table_name(),
			array(
				'prompt_id'  => $prompt_id,
				'status'     => self::STATUS_RUNNING,
				'attempt'    => 1,
				'started_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		return new self( (int) $wpdb->insert_id );
	}

	/**
	 * Returns the row ID.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Returns the post created by this run, if any.
	 *
	 * @since 0.3.0
	 *
	 * @return int|null
	 */
	public function post_id(): ?int {
		return $this->post_id;
	}

	/**
	 * Records the last completed step.
	 *
	 * @since 0.3.0
	 *
	 * @param string $step Step name.
	 * @return void
	 */
	public function record_step( string $step ): void {
		$this->update( array( 'step' => $step ), array( '%s' ) );
	}

	/**
	 * Records the article identity once the body call has returned.
	 *
	 * @since 0.3.0
	 *
	 * @param string $title     Article title.
	 * @param string $topic_key Deduplication key.
	 * @return void
	 */
	public function record_article( string $title, string $topic_key ): void {
		$this->update(
			array(
				'title'     => $title,
				'topic_key' => $topic_key,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Records token usage from a text call.
	 *
	 * @since 0.3.0
	 *
	 * @param string $model         Model that served the call.
	 * @param int    $input_tokens  Prompt tokens billed.
	 * @param int    $output_tokens Generated tokens billed.
	 * @return void
	 */
	public function record_text_usage( string $model, int $input_tokens, int $output_tokens ): void {
		$this->usage['text_model']    = $model;
		$this->usage['input_tokens']  = $input_tokens;
		$this->usage['output_tokens'] = $output_tokens;

		$this->update(
			array(
				'text_model'    => $model,
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Records that an image was generated.
	 *
	 * @since 0.3.0
	 *
	 * @param string $model Image model used.
	 * @return void
	 */
	public function record_image( string $model ): void {
		$this->usage['image_model'] = $model;
		$this->usage['image_count'] = 1;

		$this->update(
			array(
				'image_model' => $model,
				'image_count' => 1,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Records the source URLs a grounded call reported using.
	 *
	 * Section 7.1 requires these to be kept on the run. They are the only record
	 * of what third-party text entered the model context, which is the thing
	 * worth being able to audit after the fact when a grounded article turns out
	 * to be wrong.
	 *
	 * Stored in the payload column, which section 3.2 reserves for a run's
	 * intermediate state and which nothing else writes to: the pipeline runs
	 * inside a single action, so there is no inter-step state to keep there.
	 *
	 * @since 0.8.0
	 *
	 * @param string[] $urls Source URLs.
	 * @return void
	 */
	public function record_sources( array $urls ): void {
		$clean = array();

		foreach ( $urls as $url ) {
			$candidate = esc_url_raw( (string) $url );

			if ( '' !== $candidate ) {
				$clean[] = $candidate;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		$this->sources = $clean;

		$this->update(
			array( 'payload' => (string) wp_json_encode( array( 'sources' => $clean ) ) ),
			array( '%s' )
		);
	}

	/**
	 * Returns the source URLs recorded for this run.
	 *
	 * @since 0.8.0
	 *
	 * @return string[]
	 */
	public function sources(): array {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		global $wpdb;

		$payload = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT payload FROM %i WHERE id = %d',
				Activation::table_name(),
				$this->id
			)
		);

		$decoded = json_decode( (string) $payload, true );

		$this->sources = ( is_array( $decoded ) && isset( $decoded['sources'] ) && is_array( $decoded['sources'] ) )
			? array_map( 'strval', $decoded['sources'] )
			: array();

		return $this->sources;
	}

	/**
	 * Binds the created post to this run.
	 *
	 * Section 5 requires each step to be idempotent keyed by run ID, so callers
	 * check post_id() before inserting to avoid creating a second post on retry.
	 *
	 * @since 0.3.0
	 *
	 * @param int $post_id Created post ID.
	 * @return void
	 */
	public function record_post( int $post_id ): void {
		$this->post_id = $post_id;

		$this->update( array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Returns the most recent run row for a prompt.
	 *
	 * The Run Log in section 9.3 reads runs back out; this is the accessor it
	 * will use, rather than every caller writing its own query.
	 *
	 * @since 0.5.0
	 *
	 * @param int $prompt_id Prompt to look up.
	 * @return array<string, mixed>|null Row as an associative array, or null.
	 */
	public static function latest_for_prompt( int $prompt_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE prompt_id = %d ORDER BY id DESC LIMIT 1',
				Activation::table_name(),
				$prompt_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns run rows matching the given filters, newest first.
	 *
	 * Section 9.3 wants the log filterable by prompt, status, and month. Month is
	 * given as YYYY-MM and interpreted in the site timezone, matching how section
	 * 7.4 sums spend; started_at is stored in UTC, so the bounds are converted
	 * rather than compared as strings.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $args Optional prompt_id, status, month, per_page, paged.
	 * @return array<int, array<string, mixed>> Rows as associative arrays.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$filters  = self::filter_values( $args );

		/*
		 * The statement is a fixed literal and every filter is a bound value,
		 * including the ones that are switched off. Concatenating optional WHERE
		 * fragments would have meant handing prepare() a variable, which is
		 * exactly the shape that hides an injection, so each filter is instead
		 * disabled by its own sentinel comparison.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE ( %d = 0 OR prompt_id = %d )
					AND ( %s = \'\' OR status = %s )
					AND started_at >= %s AND started_at < %s
				ORDER BY id DESC
				LIMIT %d OFFSET %d',
				Activation::table_name(),
				$filters['prompt_id'],
				$filters['prompt_id'],
				$filters['status'],
				$filters['status'],
				$filters['start'],
				$filters['end'],
				$per_page,
				( $paged - 1 ) * $per_page
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns how many rows match the given filters.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $args Optional prompt_id, status, month.
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;

		$filters = self::filter_values( $args );

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE ( %d = 0 OR prompt_id = %d )
					AND ( %s = \'\' OR status = %s )
					AND started_at >= %s AND started_at < %s',
				Activation::table_name(),
				$filters['prompt_id'],
				$filters['prompt_id'],
				$filters['status'],
				$filters['status'],
				$filters['start'],
				$filters['end']
			)
		);

		return (int) $total;
	}

	/**
	 * Deletes run rows older than the given number of days.
	 *
	 * Section 3.2 requires this: the table grows without bound otherwise, and a
	 * site generating daily accumulates rows forever for no benefit.
	 *
	 * @since 0.7.0
	 *
	 * @param int $days Age in days. Zero or less deletes nothing.
	 * @return int Number of rows removed.
	 */
	public static function prune( int $days ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE started_at < %s',
				Activation::table_name(),
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Normalises the filter arguments into bindable values.
	 *
	 * A filter that is switched off gets a sentinel its clause always matches,
	 * so the same statement serves every combination of filters.
	 *
	 * The date bounds widen to the full range a DATETIME can hold rather than
	 * collapsing to an empty string. An empty string is not a datetime, and
	 * MySQL in strict mode rejects the comparison outright and returns nothing —
	 * which MariaDB tolerates, so the difference only showed up in CI.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $args Filter arguments.
	 * @return array{prompt_id: int, status: string, start: string, end: string}
	 */
	private static function filter_values( array $args ): array {
		$bounds = empty( $args['month'] ) ? null : self::month_bounds_utc( (string) $args['month'] );

		return array(
			'prompt_id' => (int) ( $args['prompt_id'] ?? 0 ),
			'status'    => (string) ( $args['status'] ?? '' ),
			'start'     => null === $bounds ? '1000-01-01 00:00:00' : $bounds['start'],
			'end'       => null === $bounds ? '9999-12-31 23:59:59' : $bounds['end'],
		);
	}

	/**
	 * Converts a YYYY-MM month in the site timezone into UTC bounds.
	 *
	 * @since 0.7.0
	 *
	 * @param string $month Month as YYYY-MM.
	 * @return array{start: string, end: string}|null Null when the input is not a month.
	 */
	private static function month_bounds_utc( string $month ): ?array {
		if ( 1 !== preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month ) ) {
			return null;
		}

		$site_tz = wp_timezone();
		$start   = new DateTimeImmutable( $month . '-01 00:00:00', $site_tz );
		$end     = $start->modify( '+1 month' );
		$utc     = new DateTimeZone( 'UTC' );

		return array(
			'start' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'   => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Writes the projected cost before any paid call.
	 *
	 * This row is the reservation that makes the section 7.4 cap safe under the
	 * concurrent execution Action Scheduler performs.
	 *
	 * @since 0.5.0
	 *
	 * @param int $cents Estimated cost.
	 * @return void
	 */
	public function reserve_cost( int $cents ): void {
		$this->update( array( 'cost_cents' => $cents ), array( '%d' ) );
	}

	/**
	 * Replaces the reservation with the measured cost.
	 *
	 * @since 0.5.0
	 *
	 * @param int $cents Actual cost.
	 * @return void
	 */
	public function record_cost( int $cents ): void {
		$this->update( array( 'cost_cents' => $cents ), array( '%d' ) );
	}

	/**
	 * Closes the run as skipped, releasing any reservation.
	 *
	 * @since 0.5.0
	 *
	 * @param string $status One of the skipped status constants.
	 * @param string $reason Human-readable explanation.
	 * @return void
	 */
	public function skip( string $status, string $reason ): void {
		$this->update(
			array(
				'status'      => $status,
				'error'       => $reason,
				'cost_cents'  => 0,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Replaces the reservation with the cost measured from actual usage.
	 *
	 * Section 7.4: every provider returns token usage, so the estimate written
	 * before the run is superseded by what was really consumed.
	 *
	 * @since 0.5.0
	 *
	 * @param Pricing_Table $pricing         Rate table.
	 * @param int           $grounded_calls  Number of grounded requests made.
	 * @return int Cost in cents.
	 */
	public function settle_cost( Pricing_Table $pricing, int $grounded_calls = 0 ): int {
		$cents = $pricing->cost_cents(
			(string) $this->usage['text_model'],
			(int) $this->usage['input_tokens'],
			(int) $this->usage['output_tokens'],
			(string) $this->usage['image_model'],
			(int) $this->usage['image_count'],
			$grounded_calls
		);

		$this->record_cost( $cents );

		return $cents;
	}

	/**
	 * Closes the run as successful.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function succeed(): void {
		$this->update(
			array(
				'status'      => self::STATUS_SUCCESS,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Closes the run as failed.
	 *
	 * @since 0.3.0
	 *
	 * @param string $message Human-readable failure reason.
	 * @return void
	 */
	public function fail( string $message ): void {
		$this->update(
			array(
				'status'      => self::STATUS_FAILED,
				'error'       => $message,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Applies a partial update to this row.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $data    Column values.
	 * @param string[]             $formats Column formats.
	 * @return void
	 */
	private function update( array $data, array $formats ): void {
		global $wpdb;

		$wpdb->update(
			Activation::table_name(),
			$data,
			array( 'id' => $this->id ),
			$formats,
			array( '%d' )
		);
	}
}
