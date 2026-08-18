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
use WP_Error;

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
	 * @param int $attempt   Attempt number this run represents, starting at 1.
	 * @return Run|WP_Error The open run, or an error when the row could not be written.
	 */
	public static function start( int $prompt_id, int $attempt = 1 ): Run|WP_Error {
		global $wpdb;

		$inserted = $wpdb->insert(
			Activation::table_name(),
			array(
				'prompt_id'  => $prompt_id,
				'status'     => self::STATUS_RUNNING,
				'attempt'    => max( 1, $attempt ),
				'started_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		/*
		 * A failed insert used to return a Run wrapping ID 0. Every later update
		 * then silently matched no row, the budget reservation was never written,
		 * and the run appeared to succeed while leaving no trace. Failing here
		 * instead means the caller sees the problem before anything is spent.
		 */
		if ( false === $inserted || 0 === (int) $wpdb->insert_id ) {
			return new WP_Error(
				'autoscribe_run_not_recorded',
				__( 'The run could not be recorded. Check that the AutoScribe runs table exists and is writable.', 'autoscribe' )
			);
		}

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
	 * Adds the token usage of one text call to the run's running total.
	 *
	 * Every paid call accumulates. This replaced an implementation that assigned
	 * rather than added, which meant the body call overwrote the proposal call's
	 * tokens and the settled cost silently omitted every proposal the run had
	 * paid for. A cap fed by an under-count is not a cap.
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
		$this->usage['input_tokens']  = (int) $this->usage['input_tokens'] + max( 0, $input_tokens );
		$this->usage['output_tokens'] = (int) $this->usage['output_tokens'] + max( 0, $output_tokens );

		$this->update(
			array(
				'text_model'    => $model,
				'input_tokens'  => (int) $this->usage['input_tokens'],
				'output_tokens' => (int) $this->usage['output_tokens'],
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Whether any paid provider call has been recorded against this run.
	 *
	 * @since 1.0.1
	 *
	 * @return bool
	 */
	public function has_usage(): bool {
		return (int) $this->usage['input_tokens'] > 0
			|| (int) $this->usage['output_tokens'] > 0
			|| (int) $this->usage['image_count'] > 0;
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
	 * Returns a draft left behind by this prompt's previous failed run.
	 *
	 * A retry re-runs the whole pipeline, and post assembly happens before image
	 * generation so that section 6's "required" mode has a draft to leave behind.
	 * Those two facts together used to produce one draft per attempt: a transient
	 * image failure retried three times left three drafts, none of them linked to
	 * the others. Adopting the previous attempt's draft means the retry updates it
	 * rather than adding another.
	 *
	 * Only a draft still carrying its run link is adopted. A post someone has
	 * since published, moved, or edited into shape is not the plugin's to
	 * overwrite.
	 *
	 * @since 1.0.1
	 *
	 * @param int $prompt_id      Prompt being run.
	 * @param int $exclude_run_id Run currently executing.
	 * @return int|null Post ID, or null when there is nothing to adopt.
	 */
	public static function adoptable_draft( int $prompt_id, int $exclude_run_id ): ?int {
		global $wpdb;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT post_id FROM %i
				WHERE prompt_id = %d AND id <> %d AND status = %s AND post_id IS NOT NULL AND post_id > 0
				ORDER BY id DESC LIMIT 1',
				Activation::table_name(),
				$prompt_id,
				$exclude_run_id,
				self::STATUS_FAILED
			)
		);

		if ( $post_id <= 0 || 'draft' !== get_post_status( $post_id ) ) {
			return null;
		}

		$linked = (string) get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true );

		return '' === $linked ? null : $post_id;
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
	 * Closes the run as skipped, settling whatever it actually spent.
	 *
	 * A budget skip happens before any paid call, so it settles to zero and the
	 * reservation is released as intended. A duplicate skip does not: it has
	 * already paid for one or two proposal calls. Writing a flat zero here — the
	 * previous behaviour — made that money invisible to the monthly cap, so a
	 * prompt that kept proposing repeats could spend without limit while the
	 * spend total stayed still.
	 *
	 * @since 0.5.0
	 *
	 * @param string             $status  One of the skipped status constants.
	 * @param string             $reason  Human-readable explanation.
	 * @param Pricing_Table|null $pricing Rate table, or null to build a default.
	 * @return void
	 */
	public function skip( string $status, string $reason, ?Pricing_Table $pricing = null ): void {
		$this->update(
			array(
				'status'      => $status,
				'error'       => $reason,
				'cost_cents'  => $this->measured_cents( $pricing ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Returns the cost of the usage actually recorded against this run.
	 *
	 * @since 1.0.1
	 *
	 * @param Pricing_Table|null $pricing        Rate table, or null to build a default.
	 * @param int                $grounded_calls Number of grounded requests made.
	 * @return int Cost in cents.
	 */
	private function measured_cents( ?Pricing_Table $pricing, int $grounded_calls = 0 ): int {
		if ( ! $this->has_usage() ) {
			return 0;
		}

		$table = $pricing instanceof Pricing_Table ? $pricing : new Pricing_Table();

		return $table->cost_cents(
			(string) $this->usage['text_model'],
			(int) $this->usage['input_tokens'],
			(int) $this->usage['output_tokens'],
			(string) $this->usage['image_model'],
			(int) $this->usage['image_count'],
			$grounded_calls
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
		$cents = $this->measured_cents( $pricing, $grounded_calls );

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
	 * The reservation written before the run is replaced by what the run really
	 * spent before it fell over. Leaving the estimate in place — the previous
	 * behaviour — meant a run that failed on its first provider call still
	 * counted a full article and image against the monthly cap, and a string of
	 * transport failures could exhaust the budget without generating anything.
	 *
	 * @since 0.3.0
	 *
	 * @param string             $message        Human-readable failure reason.
	 * @param Pricing_Table|null $pricing        Rate table, or null to build a default.
	 * @param int                $grounded_calls Number of grounded requests made.
	 * @return void
	 */
	public function fail( string $message, ?Pricing_Table $pricing = null, int $grounded_calls = 0 ): void {
		$this->update(
			array(
				'status'      => self::STATUS_FAILED,
				'error'       => $message,
				'cost_cents'  => $this->measured_cents( $pricing, $grounded_calls ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
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
