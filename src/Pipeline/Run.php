<?php
/**
 * Typed accessor over one row of the runs table.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Cost\Pricing_Table;

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
