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
	 * Prefix marking a step as claimed by a worker that is performing it.
	 *
	 * Kept in the same column as the completed step so the claim and the position
	 * move together in one atomic update. Anything reading the position strips it
	 * with completed_step().
	 *
	 * @since 1.1.1
	 * @var string
	 */
	public const CLAIM_PREFIX = 'doing:';

	/**
	 * Separator between a claimed step and the token identifying the claim.
	 *
	 * @since 1.1.1
	 * @var string
	 */
	public const CLAIM_SEPARATOR = '#';

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
	 * Cached payload document, or null when it has not been read yet.
	 *
	 * @since 1.1.0
	 * @var array<string, mixed>|null
	 */
	private ?array $payload = null;

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
	 * Whether the usage above has been read back from the row yet.
	 *
	 * @since 1.1.0
	 * @var bool
	 */
	private bool $usage_loaded = false;

	/**
	 * Grounded requests this object has seen made, whether or not they persisted.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	private int $grounded_calls = 0;

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
	 * Reopens an existing run by ID.
	 *
	 * A queued action carries a run ID and nothing else, so the queue driver has
	 * to be able to pick a run back up from that alone. Returns null when the row
	 * is gone — the retention job in section 3.2 prunes old rows, and an action
	 * can outlive the run it was armed for.
	 *
	 * @since 1.1.0
	 *
	 * @param int $id Row ID.
	 * @return Run|null
	 */
	public static function load( int $id ): ?Run {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d',
				Activation::table_name(),
				$id
			)
		);

		return null === $found ? null : new self( $id );
	}

	/**
	 * Returns the prompt this run belongs to.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function prompt_id(): int {
		return (int) $this->column( 'prompt_id' );
	}

	/**
	 * Returns the attempt number this run represents.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function attempt(): int {
		return (int) $this->column( 'attempt' );
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
		if ( null !== $this->post_id ) {
			return $this->post_id;
		}

		/*
		 * Read the row when nothing is held in memory. Returning the property
		 * alone was correct while a run only ever existed inside the request that
		 * opened it; a run advanced one queued action at a time is a fresh object
		 * each time, and every one of them would have reported "no post" for a
		 * run that plainly had one.
		 */
		$stored = (int) $this->column( 'post_id' );

		if ( $stored > 0 ) {
			$this->post_id = $stored;
		}

		return $this->post_id;
	}

	/**
	 * Records the last completed step.
	 *
	 * @since 0.3.0
	 *
	 * @param string $step Step name.
	 * @return bool True when the write reached the database.
	 */
	public function record_step( string $step ): bool {
		return $this->update( array( 'step' => $step ), array( '%s' ) );
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
	 * @return bool True when the usage reached the database.
	 */
	public function record_text_usage( string $model, int $input_tokens, int $output_tokens ): bool {
		$this->load_usage();

		$this->usage['text_model']    = $model;
		$this->usage['input_tokens']  = (int) $this->usage['input_tokens'] + max( 0, $input_tokens );
		$this->usage['output_tokens'] = (int) $this->usage['output_tokens'] + max( 0, $output_tokens );

		/*
		 * The counters are kept in memory whether or not the write lands, and the
		 * caller is told. A provider that answered has charged for it, so the
		 * charge is real even when the row will not take it — the object that
		 * settles this run is the object that made the call, so stopping here
		 * still books the money. Reporting success and carrying on would lose it:
		 * the next queued action loads a fresh run and reads the row.
		 */
		return $this->update(
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
		$this->load_usage();

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
	 * @return bool True when the usage reached the database.
	 */
	public function record_image( string $model ): bool {
		$this->load_usage();

		$this->usage['image_model'] = $model;
		$this->usage['image_count'] = 1;

		// See record_text_usage(): a picture the provider billed for is billed
		// for whether or not the row accepts the counter.
		return $this->update(
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
	 * Stored under the sources key of the payload column, which section 3.2
	 * reserves for a run's intermediate state.
	 *
	 * @since 0.8.0
	 *
	 * @param string[] $urls Source URLs.
	 * @return bool True when the write reached the database.
	 */
	public function record_sources( array $urls ): bool {
		$clean = array();

		foreach ( $urls as $url ) {
			$candidate = esc_url_raw( (string) $url );

			if ( '' !== $candidate ) {
				$clean[] = $candidate;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		if ( ! $this->merge_payload( array( 'sources' => $clean ) ) ) {
			return false;
		}

		$this->sources = $clean;

		return true;
	}

	/**
	 * Merges a patch into the run's payload document.
	 *
	 * Every write to the payload column goes through here, and that is the whole
	 * point of the method existing. Until 1.1.0 the column had exactly one
	 * writer — record_sources() — which encoded a fresh single-key object over
	 * whatever was there. That was correct while it was the only writer and
	 * silently destructive the moment it was not, which is the state section 5's
	 * split pipeline puts it in: each step reads its input from the payload and
	 * writes its output back, so a second writer is the design rather than an
	 * accident.
	 *
	 * Top-level keys are replaced rather than merged recursively. A step owns its
	 * key outright and rewrites it whole; merging into a step's own output would
	 * mean a retry could leave half of the previous attempt's data underneath the
	 * new one.
	 *
	 * @since 1.1.0
	 *
	 * The cache is assigned only once the write has been accepted. Assigning it
	 * first — the 1.1.0 groundwork as first written — left a refused write with
	 * the merged document still in memory, so the object went on reporting keys
	 * the row did not contain. That is worse than it sounds under section 5: the
	 * idempotency guards read this document to decide whether a step has already
	 * run, so a key that exists only in memory means a step skips work that was
	 * never persisted, and the run continues on state nothing can recover.
	 *
	 * On a refusal the cache is dropped rather than rolled back to its previous
	 * value. Rolling back would assert what the row contains, and a write that
	 * just failed is the worst moment to start making assertions about the
	 * database; dropping it means the next read goes and looks.
	 *
	 * @param array<string, mixed> $patch Keys to write.
	 * @return bool True when the write reached the database.
	 */
	public function merge_payload( array $patch ): bool {
		$payload = array_merge( $this->payload(), $patch );

		$written = $this->update(
			array( 'payload' => (string) wp_json_encode( $payload ) ),
			array( '%s' )
		);

		if ( ! $written ) {
			$this->payload = null;
			$this->sources = null;

			return false;
		}

		$this->payload = $payload;

		return true;
	}

	/**
	 * Returns the run's payload document.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		if ( null !== $this->payload ) {
			return $this->payload;
		}

		global $wpdb;

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT payload FROM %i WHERE id = %d',
				Activation::table_name(),
				$this->id
			)
		);

		$decoded = json_decode( (string) $stored, true );

		$this->payload = is_array( $decoded ) ? $decoded : array();

		return $this->payload;
	}

	/**
	 * Whether force review was on when this run opened.
	 *
	 * Recorded at the start because the setting is global and mutable, and a run
	 * now spans several requests. Section 10 makes the difference between draft
	 * and published the whole safety model, and a model that can be switched off
	 * halfway through an article is not one.
	 *
	 * @since 1.1.1
	 *
	 * @return bool
	 */
	public function started_under_review(): bool {
		return ! empty( $this->payload()['force_review'] );
	}

	/**
	 * Returns how many grounded requests this run actually made.
	 *
	 * Recorded by the step that makes the request, because nothing else can know.
	 * Section 7.1's surcharge is not part of the usage providers report, so it
	 * has to be carried separately, and deriving it from the prompt's current
	 * setting is wrong in both directions once a run outlives an edit: the
	 * surcharge is dropped from a request already paid for, or added to one that
	 * never happened.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function grounded_calls(): int {
		return max( $this->grounded_calls, (int) ( $this->payload()['grounded_calls'] ?? 0 ) );
	}

	/**
	 * Records that this run made a grounded request.
	 *
	 * The in-memory count is set whether or not the write lands, which is the
	 * opposite of how the payload cache behaves — and deliberately so. That cache
	 * is a claim about what the database contains, and a claim the database just
	 * refused is a lie. This is a statement about what the run did, and a refused
	 * write does not un-make a request the provider has already answered and
	 * charged for.
	 *
	 * So the persisted value is what a later action reads, and the in-memory one
	 * is a floor for the action that made the call — which is the action that
	 * will settle the run when the write failure ends it.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the marker also reached the database.
	 */
	public function record_grounded_call(): bool {
		++$this->grounded_calls;

		return $this->merge_payload( array( 'grounded_calls' => $this->grounded_calls ) );
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

		$stored = $this->payload()['sources'] ?? array();

		$this->sources = is_array( $stored ) ? array_map( 'strval', $stored ) : array();

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
	 * @return bool True when the write reached the database.
	 */
	public function record_post( int $post_id ): bool {
		$this->post_id = $post_id;

		return $this->update( array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Takes ownership of a draft left behind by the previous attempt.
	 *
	 * Recording the post on the run row is only half of adoption. The other half
	 * is the post's own run link, and until 1.0.3 nothing moved it: only
	 * Step_Assemble_Post writes that meta, and a retry that adopted a draft and
	 * then fell over on the topic or body call never reached assembly. The run
	 * row pointed at the draft while the draft still named the attempt before it,
	 * and adoptable_draft() — which asks precisely whether those two agree —
	 * refused it on the next attempt. A later successful attempt then created the
	 * second draft the mechanism exists to prevent.
	 *
	 * Both halves move together here, so the invariant holds from the moment of
	 * adoption rather than only after a successful assembly: the post's run link
	 * names the run that currently owns it.
	 *
	 * Updating meta does not touch post_modified, so the human-edit guard in
	 * adoptable_draft() is unaffected by this write.
	 *
	 * Two writes make a state that can be half-reached, so this is all or
	 * nothing. Version 1.0.3 discarded both results and bound the run row first,
	 * which meant a refused meta write — a database error, or a filter on
	 * update_post_metadata short-circuiting it — reproduced the very state 1.0.3
	 * had been written to fix.
	 *
	 * The order is now the other way round, because the cheaper failure is the
	 * one that changes nothing. The ownership write goes first and is verified by
	 * reading it back rather than by trusting its return value, since
	 * update_post_meta() also returns false when the stored value already matches.
	 * Only then is the run row bound, and if that write fails the ownership is
	 * put back where it was.
	 *
	 * @since 1.0.3
	 *
	 * @param int $post_id Draft being adopted.
	 * @return bool True when the draft now belongs to this run, and nothing
	 *              changed at all when it does not.
	 */
	public function adopt_post( int $post_id ): bool {
		$previous_owner = get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true );

		update_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, $this->id );

		if ( (int) get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true ) !== $this->id ) {
			// The run row was never touched, so the draft is exactly as it was.
			return false;
		}

		if ( $this->record_post( $post_id ) ) {
			return true;
		}

		update_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, $previous_owner );

		$this->post_id = null;

		return false;
	}

	/**
	 * Returns the last step this run completed.
	 *
	 * Empty when the run has not completed one yet. The sequencer reads this to
	 * work out what to do next, which is why it is a column rather than a
	 * variable held across a single request: a run that is being advanced one
	 * queued action at a time has nowhere else to keep its place.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function step(): string {
		return self::completed_step( (string) $this->column( 'step' ) );
	}

	/**
	 * Returns the step column as stored, claim marker and all.
	 *
	 * @since 1.1.1
	 *
	 * @return string
	 */
	public function raw_step(): string {
		return (string) $this->column( 'step' );
	}

	/**
	 * Writes a terminal state, and only while the run is at a known position.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $data          Columns to write, with finished_at.
	 * @param string               $expected_step Position the caller observed.
	 * @return bool True when this call is the one that closed the run.
	 */
	private function close_at( array $data, string $expected_step ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Activation::table_name(),
			$data,
			array(
				'id'     => $this->id,
				'status' => self::STATUS_RUNNING,
				'step'   => '' === $expected_step ? null : $expected_step,
			),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d', '%s', '%s' )
		);

		return is_numeric( $updated ) && (int) $updated > 0;
	}

	/**
	 * Claims the right to perform one step of this run.
	 *
	 * The idempotency guards each step carries are reads followed by a paid call:
	 * two workers can both find no stored article and both buy one. Action
	 * Scheduler will not hand one action row to two workers, but nothing stopped
	 * two rows existing for the same run, and nothing stopped a sweeper restart
	 * arriving beside a slow original.
	 *
	 * This is a compare-and-swap on the run's position. The winner moves the row
	 * from the step it expected to a claim marker; the loser's update matches no
	 * row and it stands down. Both then behave correctly rather than both
	 * spending.
	 *
	 * @since 1.1.1
	 *
	 * @param string $expected The last completed step this worker read.
	 * @return bool True when this worker holds the claim.
	 */
	public function claim_step( string $expected ): bool {
		global $wpdb;

		/*
		 * Every claim carries a token, so no two claims are the same string. That
		 * is what lets the sweeper release the claim it saw rather than whatever
		 * claim happens to be there when its update lands: without it, a claim
		 * released and immediately retaken produces an identical marker, and a
		 * second sweeper still holding a stale view would release the new
		 * worker's live claim and let a third worker perform the same paid step
		 * beside it.
		 */
		$claim = self::CLAIM_PREFIX . $expected . self::CLAIM_SEPARATOR . bin2hex( random_bytes( 4 ) );

		/*
		 * A run that has completed nothing has step NULL rather than an empty
		 * string, and NULL matches nothing in SQL — including itself. Comparing
		 * against the empty string alone would make every first claim fail, and
		 * every run would stop before its first step.
		 */
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET step = %s WHERE id = %d AND status = %s AND COALESCE( step, %s ) = %s',
				Activation::table_name(),
				$claim,
				$this->id,
				self::STATUS_RUNNING,
				'',
				$expected
			)
		);

		return is_numeric( $claimed ) && (int) $claimed > 0;
	}

	/**
	 * Gives up a claim left behind by a worker that never came back.
	 *
	 * A claim is deliberately not self-expiring: while a worker holds it, nothing
	 * else should take the step. But a worker killed mid-step leaves the marker
	 * in place, and the next worker reads the position with the marker stripped —
	 * so it asks to claim a value the column no longer holds and fails, every
	 * time. Left alone, that turns the guard into a trap: a run interrupted at any
	 * point after claiming can never resume, and is given up on instead.
	 *
	 * The claim to release is passed in rather than read here, and that is the
	 * whole of the guard. Reading it would find whatever claim exists at this
	 * instant — including one a restart took a moment ago, which is live and must
	 * not be freed. Naming the claim observed before the run was judged idle makes
	 * the check and the release one conditional update: a claim taken since
	 * carries a different token, so the update matches nothing and the live worker
	 * keeps its step.
	 *
	 * Only the stall sweeper calls this, and only for a run it found with nothing
	 * queued or running — which is as close to "the worker is gone" as this side
	 * of the system can get.
	 *
	 * @since 1.1.1
	 *
	 * @param string $observed The claim seen when the run was judged idle.
	 * @return bool|null True when a claim was released, false when the release
	 *                   was refused, and null when there was no claim to release.
	 */
	public function release_claim( string $observed ): ?bool {
		global $wpdb;

		if ( ! str_starts_with( $observed, self::CLAIM_PREFIX ) ) {
			return null;
		}

		$raw = $observed;

		$released = $wpdb->update(
			Activation::table_name(),
			array( 'step' => self::completed_step( $raw ) ),
			array(
				'id'     => $this->id,
				'step'   => $raw,
				'status' => self::STATUS_RUNNING,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		return is_numeric( $released ) && (int) $released > 0;
	}

	/**
	 * Returns the last completed step, ignoring any claim marker on it.
	 *
	 * @since 1.1.1
	 *
	 * @param string $step Raw column value.
	 * @return string
	 */
	public static function completed_step( string $step ): string {
		if ( ! str_starts_with( $step, self::CLAIM_PREFIX ) ) {
			return $step;
		}

		$claimed = substr( $step, strlen( self::CLAIM_PREFIX ) );
		$token   = strrpos( $claimed, self::CLAIM_SEPARATOR );

		return false === $token ? $claimed : substr( $claimed, 0, $token );
	}

	/**
	 * Returns the run's current status.
	 *
	 * @since 1.1.0
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		return (string) $this->column( 'status' );
	}

	/**
	 * Reads the recorded usage back off the row, once.
	 *
	 * The counters are accumulated in memory and written out whole, which is
	 * correct only while one object sees every call a run makes. A run advanced
	 * one queued action at a time is a fresh object each time, so without this
	 * each step's tokens overwrote the last step's, and the object that settles
	 * the cost saw no usage at all — replacing the reservation with zero, so a
	 * scheduled run reported spending nothing, the month-to-date total never
	 * moved, and the section 7.4 cap could not fire.
	 *
	 * Read once and then trusted: within a single action this object is the only
	 * writer, and re-reading before every accumulation would cost a query per
	 * call to save nothing.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function load_usage(): void {
		if ( $this->usage_loaded ) {
			return;
		}

		// Set first: the read below must not recurse through a caller of this.
		$this->usage_loaded = true;

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT text_model, image_model, input_tokens, output_tokens, image_count FROM %i WHERE id = %d',
				Activation::table_name(),
				$this->id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$this->usage = array(
			'text_model'    => (string) $row['text_model'],
			'image_model'   => (string) $row['image_model'],
			'input_tokens'  => (int) $row['input_tokens'],
			'output_tokens' => (int) $row['output_tokens'],
			'image_count'   => (int) $row['image_count'],
		);
	}

	/**
	 * Reads one column from the run row.
	 *
	 * Deliberately not cached. Both callers ask about state a step may have
	 * changed a moment ago, and a stale answer would have the sequencer running
	 * a step twice or closing a run that has already closed itself.
	 *
	 * @since 1.1.0
	 *
	 * @param string $column Column name.
	 * @return string|null
	 */
	private function column( string $column ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT %i FROM %i WHERE id = %d',
				$column,
				Activation::table_name(),
				$this->id
			)
		);

		return null === $value ? null : (string) $value;
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
	 * Returns a draft left behind by the attempt immediately before this one.
	 *
	 * A retry re-runs the whole pipeline, and post assembly happens before image
	 * generation so that section 6's "required" mode has a draft to leave behind.
	 * Those two facts together used to produce one draft per attempt: a transient
	 * image failure retried three times left three drafts, none of them linked to
	 * the others. Adopting the previous attempt's draft means the retry updates it
	 * rather than adding another.
	 *
	 * Version 1.0.1 asked only for "the newest failed run of this prompt that has
	 * a post", which is a much wider net than a retry. Once retries were exhausted
	 * that failed draft stayed adoptable for ever, so the next ordinary scheduled
	 * occurrence — a different article, days later — overwrote it, and so did the
	 * one after that. A reviewer who had started editing the draft lost the edit.
	 *
	 * Five conditions now have to hold, and every one of them exists to keep a
	 * later run from overwriting work that is not its own:
	 *
	 * - this is a retry, not a first attempt;
	 * - the candidate is the row immediately before this one for this prompt, so
	 *   an unrelated run in between ends the series;
	 * - that row failed, and its attempt number is exactly one lower than this
	 *   one, so the two belong to the same retry series;
	 * - the post still carries that row's ID in its run meta, so a post relinked
	 *   or created by something else is left alone;
	 * - the post has not been touched since that run finished, so a human edit
	 *   ends adoption even while the post is still a draft.
	 *
	 * @since 1.0.1
	 *
	 * @param int $prompt_id Prompt being run.
	 * @param int $run_id    Run currently executing.
	 * @param int $attempt   Attempt number of the run currently executing.
	 * @return int|null Post ID, or null when there is nothing to adopt.
	 */
	public static function adoptable_draft( int $prompt_id, int $run_id, int $attempt ): ?int {
		global $wpdb;

		if ( $attempt < 2 ) {
			return null;
		}

		$previous = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, post_id, status, attempt, finished_at FROM %i
				WHERE prompt_id = %d AND id < %d
				ORDER BY id DESC LIMIT 1',
				Activation::table_name(),
				$prompt_id,
				$run_id
			),
			ARRAY_A
		);

		if ( ! is_array( $previous ) ) {
			return null;
		}

		if ( self::STATUS_FAILED !== (string) $previous['status'] ) {
			return null;
		}

		if ( (int) $previous['attempt'] !== $attempt - 1 ) {
			return null;
		}

		$post_id = (int) $previous['post_id'];

		if ( $post_id <= 0 || 'draft' !== get_post_status( $post_id ) ) {
			return null;
		}

		if ( (int) get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true ) !== (int) $previous['id'] ) {
			return null;
		}

		return self::untouched_since( $post_id, (string) $previous['finished_at'] ) ? $post_id : null;
	}

	/**
	 * Whether a post has stood still since the given UTC timestamp.
	 *
	 * The failed run wrote the draft and then closed itself, so its finished_at
	 * is always at or after the draft's own last modification. Anything later
	 * than that came from somewhere else, and the only somewhere else is a
	 * person.
	 *
	 * @since 1.0.2
	 *
	 * @param int    $post_id     Post to inspect.
	 * @param string $finished_at UTC MySQL timestamp the run closed at.
	 * @return bool
	 */
	private static function untouched_since( int $post_id, string $finished_at ): bool {
		if ( '' === $finished_at || '0000-00-00 00:00:00' === $finished_at ) {
			return false;
		}

		$modified = (string) get_post_field( 'post_modified_gmt', $post_id );

		if ( '' === $modified ) {
			return false;
		}

		return strtotime( $modified . ' UTC' ) <= strtotime( $finished_at . ' UTC' );
	}

	/**
	 * Returns the step column for a set of runs, in one query.
	 *
	 * The sweeper reads these when it judges a page of runs, so it can name the
	 * exact claim it saw when it later asks for that claim to be released. One
	 * query rather than one per run, for the same reason the action lookup is
	 * batched.
	 *
	 * @since 1.1.1
	 *
	 * @param int[] $run_ids Runs to read.
	 * @return array<int, string> Step values keyed by run ID.
	 */
	public static function steps_for( array $run_ids ): array {
		global $wpdb;

		$run_ids = array_values( array_filter( array_map( 'intval', $run_ids ) ) );

		if ( array() === $run_ids ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, step FROM %i WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $run_ids ), '%d' ) ) . ' )',
				array_merge( array( Activation::table_name() ), $run_ids )
			),
			ARRAY_A
		);

		$steps = array();

		foreach ( (array) $rows as $row ) {
			$steps[ (int) $row['id'] ] = (string) $row['step'];
		}

		return $steps;
	}

	/**
	 * Returns the IDs of runs that are open and older than the given moment.
	 *
	 * Age alone does not make a run stalled — a long one is still working — so
	 * the caller pairs this with a check for whether anything is queued to
	 * advance it. Age is the guard against acting on a run that has simply not
	 * been picked up yet.
	 *
	 * @since 1.1.0
	 *
	 * @param string $before_utc UTC MySQL timestamp; runs started before this.
	 * @param int    $limit      Most rows to return.
	 * @param int    $after_id   Only rows above this ID, so a caller can page on.
	 * @return int[]
	 */
	public static function open_before( string $before_utc, int $limit = 50, int $after_id = 0 ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND started_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
				Activation::table_name(),
				self::STATUS_RUNNING,
				$before_utc,
				max( 0, $after_id ),
				max( 1, $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Returns how many times a sweeper has re-dispatched this run.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function sweeps(): int {
		return max( 0, (int) ( $this->payload()['sweeps'] ?? 0 ) );
	}

	/**
	 * Records another sweeper re-dispatch.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the count reached the database.
	 */
	public function record_sweep(): bool {
		return $this->merge_payload( array( 'sweeps' => $this->sweeps() + 1 ) );
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
	 * @return bool True when the reservation reached the database.
	 */
	public function reserve_cost( int $cents ): bool {
		return $this->update( array( 'cost_cents' => $cents ), array( '%d' ) );
	}

	/**
	 * Replaces the reservation with the measured cost.
	 *
	 * @since 0.5.0
	 *
	 * @param int $cents Actual cost.
	 * @return bool True when the write reached the database.
	 */
	public function record_cost( int $cents ): bool {
		return $this->update( array( 'cost_cents' => $cents ), array( '%d' ) );
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
	 * @return bool True when this call is the one that closed the run.
	 */
	public function skip( string $status, string $reason, ?Pricing_Table $pricing = null ): bool {
		return $this->close(
			array(
				'status'     => $status,
				'error'      => $reason,
				'cost_cents' => $this->measured_cents( $pricing ),
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
		$this->load_usage();

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
	public function settle_cost( Pricing_Table $pricing, int $grounded_calls = 0 ): int|WP_Error {
		$cents = $this->measured_cents( $pricing, $grounded_calls );

		if ( ! $this->record_cost( $cents ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The cost of this run could not be written to the run log, so the reservation it made against the monthly cap still stands. The run was not reported as finished.', 'autoscribe' )
			);
		}

		return $cents;
	}

	/**
	 * Closes the run as successful.
	 *
	 * @since 0.3.0
	 *
	 * @return bool True when this call is the one that closed the run.
	 */
	public function succeed(): bool {
		return $this->close(
			array( 'status' => self::STATUS_SUCCESS ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Writes a terminal state, and only for a run that is still open.
	 *
	 * Every ending goes through one conditional update. The condition is what
	 * makes it a transition rather than a write: a second worker, a stall sweep
	 * that has already given up, and a duplicate queued action all lose the race
	 * instead of closing a run twice — and closing twice means a second review
	 * email, a second re-arm, and a settled cost overwritten by a later one.
	 *
	 * Returning whether it happened is the other half. The callers that send mail
	 * and arm the next occurrence should do so because this run ended, not
	 * because they asked it to.
	 *
	 * @since 1.1.1
	 *
	 * @param array<string, mixed> $data          Columns to write, without finished_at.
	 * @param string[]             $formats       Formats, including one for finished_at.
	 * @param string|null          $expected_step Close only while the run is at
	 *                                            this position, or null for any.
	 * @return bool True when this call is the one that closed the run.
	 */
	private function close( array $data, array $formats, ?string $expected_step = null ): bool {
		global $wpdb;

		$data['finished_at'] = current_time( 'mysql', true );

		/*
		 * A caller acting on something it observed earlier can tie the close to
		 * that observation. The stall sweeper does: it decides to give up on a run
		 * from a scan that may be many pages old, and between the decision and
		 * this write another sweep can have armed a restart whose worker is
		 * already claiming the step. Requiring the position to be unchanged means
		 * that worker's claim beats the stale close, and a queued worker that has
		 * not claimed yet simply finds the run closed and stands down without
		 * spending anything.
		 */
		if ( null !== $expected_step ) {
			return $this->close_at( $data, $expected_step );
		}

		/*
		 * A multi-column WHERE rather than hand-built SQL: wpdb::update() takes
		 * one, and it gives the conditional transition without a string this file
		 * would have to assemble and prepare itself.
		 *
		 * Zero affected rows is unambiguous here, in a way it is not for an
		 * ordinary update. A status transition always changes the status, so a
		 * row that matched would always have been written; nothing written means
		 * nothing matched, which means the run is no longer open.
		 */
		$updated = $wpdb->update(
			Activation::table_name(),
			$data,
			array(
				'id'     => $this->id,
				'status' => self::STATUS_RUNNING,
			),
			$formats,
			array( '%d', '%s' )
		);

		return is_numeric( $updated ) && (int) $updated > 0;
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
	 * @param string|null        $expected_step  Close only while the run is still
	 *                                           at this position, or null for any.
	 * @return bool True when this call is the one that closed the run.
	 */
	public function fail( string $message, ?Pricing_Table $pricing = null, int $grounded_calls = 0, ?string $expected_step = null ): bool {
		return $this->close(
			array(
				'status'     => self::STATUS_FAILED,
				'error'      => $message,
				'cost_cents' => $this->measured_cents( $pricing, $grounded_calls ),
			),
			array( '%s', '%s', '%d', '%s' ),
			$expected_step
		);
	}

	/**
	 * Applies a partial update to this row.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $data    Column values.
	 * @param string[]             $formats Column formats.
	 * @return bool True when the write succeeded.
	 */
	private function update( array $data, array $formats ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Activation::table_name(),
			$data,
			array( 'id' => $this->id ),
			$formats,
			array( '%d' )
		);

		/*
		 * update() returns the number of affected rows, and zero is ambiguous: it
		 * means either that the row is missing or that the values were already
		 * what was written. Only an explicit false is a failed write, and only the
		 * reservation currently acts on it, because that is the write whose loss
		 * costs money rather than a log entry.
		 */
		return false !== $updated;
	}
}
