<?php
/**
 * Typed accessor over one row of the runs table.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Cost\Spend_Lock;
use AutoScribe\Cost\Step_Allowance;
use AutoScribe\Providers\Model_Resolver;
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
	 * Payload value marking an ordinary generation run.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const KIND_RUN = 'run';

	/**
	 * Payload value marking a preview, which produces an article and no post.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const KIND_PREVIEW = 'preview';

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
	 * How many unpriced runs one repair pass reads at a time.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const REPAIR_BATCH = 25;

	/**
	 * How many of those pages a caller that needs a complete answer will work
	 * through before giving up and saying so.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const REPAIR_PAGES = 40;

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
	 * The claim this object took on the run's position, while it holds one.
	 *
	 * Held so that the writes a claimed step makes can be conditional on the
	 * claim still being this worker's. A claim is not permanent: the stall
	 * sweeper releases one left behind by a worker that appeared to be gone, and
	 * "appeared" is the operative word — a worker slow enough to be swept can
	 * still return from its provider call afterwards and write its output over
	 * whatever the replacement worker has since produced.
	 *
	 * Ownership is three things and not two: the row, the claim token, and the
	 * run still being open. Requiring only the token was a real hole rather than
	 * a tidiness point, because a terminal sweep closes a run *at* the claim it
	 * observed and leaves the marker where it is. The worker it closed then found
	 * the token unchanged, believed it still owned the step, and could go on
	 * writing to a row the run log had already reported as finished — including,
	 * from finalisation, publishing the post. Every claimed write and both claim
	 * questions therefore carry the whole predicate.
	 *
	 * @since 1.2.0
	 * @var string|null
	 */
	private ?string $claim = null;

	/**
	 * Whether this object is the one that closed the run.
	 *
	 * Ownership questions and write conditions want different answers once a run
	 * has ended. A write must still be refused — a closed row is finished, and
	 * that is the whole of F130-01 — but the worker that closed it has not "lost
	 * its claim" in the sense the pipeline means: nobody took the step away from
	 * it, it ended the run itself, and the error it is about to return is the
	 * run's real outcome rather than a race it lost.
	 *
	 * @since 1.4.0
	 * @var bool
	 */
	private bool $closed_here = false;

	/**
	 * The usage revision the last cost measurement was taken from.
	 *
	 * A terminal transition writes a figure worked out a few statements earlier,
	 * and a charge can land in between: a second worker returning from a provider
	 * call while this one is closing the run. The counter statement cannot mark
	 * the row for repair in that moment, because at that moment the row is still
	 * open and settlement has not happened yet — so the close carries the revision
	 * it priced and marks the row itself when the row has moved on.
	 *
	 * Null until something measures. Nothing that writes a cost closes a run
	 * without measuring first, and a close that carries no figure carries no
	 * claim about the counters either.
	 *
	 * @since 1.7.0
	 * @var int|null
	 */
	private ?int $measured_revision = null;

	/**
	 * Everything else the last usage read took from the row, in that same read.
	 *
	 * The status, the grounded count, the floor, and the revision all belong to
	 * one moment or to none: a price assembled from several statements is a price
	 * for a row that never existed. Null until something reads.
	 *
	 * @since 1.8.0
	 * @var array<string, int|string>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Whether the last attempt to read what this run cost failed outright.
	 *
	 * A price worked out from a read that did not happen is not a price. Closing
	 * on one would write a figure nothing stands behind and clear the row of any
	 * suggestion that it needs looking at, so the close marks it instead.
	 *
	 * @since 1.8.0
	 * @var bool
	 */
	private bool $measurement_failed = false;

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
	 * Records the last completed step, releasing this worker's claim with it.
	 *
	 * Conditional on the claim, when one is held. Completing a step writes the
	 * position column, and writing it unconditionally would let a worker that had
	 * already been swept and replaced overwrite the live claim of the worker that
	 * replaced it — freeing a step a third worker would then perform and pay for
	 * beside the second.
	 *
	 * @since 0.3.0
	 *
	 * @param string $step Step name.
	 * @return bool True when the write reached the database.
	 */
	public function record_step( string $step ): bool {
		if ( null === $this->claim ) {
			return $this->update( array( 'step' => $step ), array( '%s' ) );
		}

		global $wpdb;

		$updated = $wpdb->update(
			Activation::table_name(),
			array( 'step' => $step ),
			array(
				'id'     => $this->id,
				'status' => self::STATUS_RUNNING,
				'step'   => $this->claim,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( ! is_numeric( $updated ) || (int) $updated < 1 ) {
			return false;
		}

		$this->claim = null;

		return true;
	}

	/**
	 * Whether this object still holds the claim it took.
	 *
	 * Lets a caller tell a refused write from a lost claim, which are different
	 * situations with different answers: a refused write is a fault and stops the
	 * run, while a lost claim means somebody else owns the run now and the right
	 * thing to do is stand down quietly.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function holds_claim(): bool {
		return null !== $this->claim && $this->owns_row();
	}

	/**
	 * Whether the row still matches this object's complete ownership predicate.
	 *
	 * One query for the whole condition, because two reads can disagree: asking
	 * for the position and the status separately leaves a window in which the
	 * answer is assembled from two different moments, and the thing being guarded
	 * against is precisely something changing in between.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	private function owns_row(): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d AND status = %s AND step = %s',
				Activation::table_name(),
				$this->id,
				self::STATUS_RUNNING,
				(string) $this->claim
			)
		);

		return null !== $found;
	}

	/**
	 * Whether a claim this object took has since been taken away from it.
	 *
	 * Deliberately different from the negation of holds_claim(). An object that
	 * never claimed anything holds no claim and has not lost one — a preview, a
	 * step driven directly, an object that only reads. Asking "have I lost it"
	 * rather than "do I hold it" is what lets a guard sit in a step without
	 * assuming the step is only ever reached through the queue.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function lost_claim(): bool {
		return null !== $this->claim && ! $this->closed_here && ! $this->owns_row();
	}

	/**
	 * Records the article identity once the body call has returned.
	 *
	 * @since 0.3.0
	 *
	 * @param string $title     Article title.
	 * @param string $topic_key Deduplication key.
	 * @return bool True when the write reached the database.
	 */
	public function record_article( string $title, string $topic_key ): bool {
		return $this->update(
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
	 * The database does the adding, and that is the point. Accumulating in PHP and
	 * writing the total back is a read-modify-write, so two workers on one run —
	 * a slow one and the replacement that took its claim — each write a total
	 * computed from a row they read before the other wrote, and one call's tokens
	 * disappear. The counter that the monthly cap is computed from is the last
	 * place to keep a lost update.
	 *
	 * Deliberately **not** fenced by the claim, unlike every other write this
	 * object makes. A provider that answered has charged for the answer, whoever
	 * asked and whatever has happened to their claim since; refusing the write
	 * because the worker was replaced would delete the only record of real money.
	 * Adding is safe from any worker precisely because it is not an overwrite.
	 *
	 * @since 0.3.0
	 *
	 * @param string $model         Model that served the call.
	 * @param int    $input_tokens  Prompt tokens billed.
	 * @param int    $output_tokens Generated tokens billed.
	 * @return bool True when the usage reached the database.
	 */
	public function record_text_usage( string $model, int $input_tokens, int $output_tokens ): bool {
		global $wpdb;

		$this->load_usage();

		$input  = max( 0, $input_tokens );
		$output = max( 0, $output_tokens );

		$this->usage['text_model']    = $model;
		$this->usage['input_tokens']  = (int) $this->usage['input_tokens'] + $input;
		$this->usage['output_tokens'] = (int) $this->usage['output_tokens'] + $output;

		/*
		 * The counters are kept in memory whether or not the write lands, and the
		 * caller is told. A provider that answered has charged for it, so the
		 * charge is real even when the row will not take it — the object that
		 * settles this run is the object that made the call, so stopping here
		 * still books the money. Reporting success and carrying on would lose it:
		 * the next queued action loads a fresh run and reads the row.
		 */
		return $this->under_spend_lock_if_closed(
			function () use ( $wpdb, $model, $input, $output ): bool {
				$written = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET text_model = %s, input_tokens = input_tokens + %d, output_tokens = output_tokens + %d,
						usage_revision = usage_revision + 1,
						cost_stale = IF( status <> %s, 1, cost_stale ) WHERE id = %d',
						Activation::table_name(),
						$model,
						$input,
						$output,
						self::STATUS_RUNNING,
						$this->id
					)
				);

				return false !== $written;
			}
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
	 * Counts rather than sets, for the reason record_text_usage() gives. Setting
	 * it to one meant two pictures — a stale worker's and its replacement's —
	 * were billed twice and recorded once, so the cap saw half of what the site
	 * was charged. A run should not normally buy two, and the counter is not the
	 * place to assume it did not.
	 *
	 * @since 0.3.0
	 *
	 * @param string $model Image model used.
	 * @return bool True when the usage reached the database.
	 */
	public function record_image( string $model ): bool {
		global $wpdb;

		$this->load_usage();

		$this->usage['image_model'] = $model;
		$this->usage['image_count'] = (int) $this->usage['image_count'] + 1;

		// See record_text_usage(): a picture the provider billed for is billed
		// for whether or not the row accepts the counter.
		return $this->under_spend_lock_if_closed(
			function () use ( $wpdb, $model ): bool {
				$written = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET image_model = %s, image_count = image_count + 1,
						usage_revision = usage_revision + 1,
						cost_stale = IF( status <> %s, 1, cost_stale ) WHERE id = %d',
						Activation::table_name(),
						$model,
						self::STATUS_RUNNING,
						$this->id
					)
				);

				return false !== $written;
			}
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
	 * The write is conditional on this worker's claim whenever it holds one. The
	 * column is one JSON document, so every writer reads it whole and writes it
	 * whole, and two writers with overlapping views therefore do not merge — the
	 * later write simply erases whatever the other one stored. A worker that has
	 * been swept and replaced can still be in flight, and without the condition
	 * its stale document would remove the topic, article, sources, or image state
	 * its replacement had already recorded, so the next step would repeat a paid
	 * call or fail on state that is no longer there.
	 *
	 * @param array<string, mixed> $patch Keys to write.
	 * @return bool True when the write reached the database.
	 */
	public function merge_payload( array $patch ): bool {
		$payload = array_merge( $this->payload(), $patch );

		$written = $this->write_payload( (string) wp_json_encode( $payload ) );

		if ( ! $written ) {
			$this->payload = null;
			$this->sources = null;

			return false;
		}

		$this->payload = $payload;

		return true;
	}

	/**
	 * Writes the payload column, under this worker's claim when it holds one.
	 *
	 * Zero affected rows is ambiguous for this column in a way it is not for a
	 * status transition: it means either that the claim has moved or that the
	 * document written is byte-for-byte what was already there. The second is a
	 * successful write with nothing to do, and treating it as a failure would
	 * stop runs for re-recording state they had already recorded. Only the
	 * ambiguous case pays for the extra read that tells them apart.
	 *
	 * @since 1.2.0
	 *
	 * @param string $document Encoded payload.
	 * @return bool True when the column now holds this document.
	 */
	private function write_payload( string $document ): bool {
		if ( null === $this->claim ) {
			return $this->update( array( 'payload' => $document ), array( '%s' ) );
		}

		global $wpdb;

		$updated = $wpdb->update(
			Activation::table_name(),
			array( 'payload' => $document ),
			array(
				'id'     => $this->id,
				'status' => self::STATUS_RUNNING,
				'step'   => $this->claim,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0 || $this->holds_claim();
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
		return max(
			$this->grounded_calls,
			(int) $this->column( 'grounded_calls' ),
			(int) ( $this->payload()['grounded_calls'] ?? 0 )
		);
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
	 * It is a column and an atomic increment since 1.5.0, for the reason the token
	 * counters are: the payload write it used to make is fenced by the claim and
	 * by the run being open, so a surcharge incurred by a worker whose run had
	 * been closed under it could not be recorded at all. A search a provider has
	 * billed for is money, and money is not state.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the marker also reached the database.
	 */
	public function record_grounded_call(): bool {
		global $wpdb;

		++$this->grounded_calls;

		return $this->under_spend_lock_if_closed(
			function () use ( $wpdb ): bool {
				$written = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET grounded_calls = grounded_calls + 1,
						usage_revision = usage_revision + 1,
						cost_stale = IF( status <> %s, 1, cost_stale ) WHERE id = %d',
						Activation::table_name(),
						self::STATUS_RUNNING,
						$this->id
					)
				);

				return false !== $written;
			}
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
	 * Only reached from fail(), which is the one ending a caller can tie to an
	 * observed position, so the statement writes that ending's columns.
	 *
	 * A matched-nothing result covers two situations that call for the same
	 * answer: the run has already closed, or it has moved on and belongs to a
	 * worker that is still going. Both mean somebody else owns the outcome, so
	 * both are reported as an already-closed run. A refused write is not either
	 * of those and is reported separately.
	 *
	 * @since 1.1.2
	 *
	 * @param array<string, mixed> $data          Columns to write, with finished_at.
	 * @param string               $expected_step Position the caller observed.
	 * @return Close_Result What the attempt did.
	 */
	private function close_at( array $data, string $expected_step ): Close_Result {
		global $wpdb;

		/*
		 * COALESCE, matching claim_step(), because "nothing has completed yet" has
		 * two spellings in this column. A run that has never advanced holds NULL;
		 * one whose first-step claim was released holds an empty string, because
		 * that is what the completed position is at that point.
		 *
		 * Treating an observed empty position as NULL — the first version of this
		 * — matched neither, so once a run had been through one recovery no later
		 * sweep could close it, and it held its budget reservation against the
		 * monthly cap indefinitely. The recovery made the run unrecoverable.
		 *
		 * Written out rather than passed to wpdb::update(), which cannot express a
		 * function in its WHERE. One static statement with placeholders, per D-26.
		 *
		 * Two statements rather than one with defaulted columns, because the
		 * ending that carries no cost must not write one. A single statement with
		 * `cost_cents = %d` and a zero default did exactly that the moment this
		 * path stopped being fail()'s alone: a successful run, whose cost the
		 * caller had just settled, was closed with the settlement replaced by
		 * nothing.
		 *
		 * There are exactly two shapes and no more: an ending that failed or was
		 * skipped states a reason and a cost, and a successful ending states
		 * neither. Both are static statements with bound values, per D-26.
		 */
		$revision = $this->measured_revision ?? -1;
		$unpriced = $this->measurement_failed ? 1 : 0;

		$updated = array_key_exists( 'cost_cents', $data )
			? $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, error = %s, cost_cents = %d, finished_at = %s,
					cost_stale = IF( %d = 1 OR ( %d >= 0 AND usage_revision <> %d ) OR cost_floor > %d, 1, cost_stale )
					WHERE id = %d AND status = %s AND COALESCE( step, %s ) = %s',
					Activation::table_name(),
					(string) $data['status'],
					(string) ( $data['error'] ?? '' ),
					(int) $data['cost_cents'],
					(string) $data['finished_at'],
					$unpriced,
					$revision,
					$revision,
					(int) $data['cost_cents'],
					$this->id,
					self::STATUS_RUNNING,
					'',
					$expected_step
				)
			)
			: $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, finished_at = %s,
					cost_stale = IF( %d = 1 OR ( %d >= 0 AND usage_revision <> %d ) OR cost_floor > cost_cents, 1, cost_stale )
					WHERE id = %d AND status = %s AND COALESCE( step, %s ) = %s',
					Activation::table_name(),
					(string) $data['status'],
					(string) $data['finished_at'],
					$unpriced,
					$revision,
					$revision,
					$this->id,
					self::STATUS_RUNNING,
					'',
					$expected_step
				)
			);

		if ( false === $updated ) {
			return Close_Result::Write_Failed;
		}

		return (int) $updated > 0 ? Close_Result::Closed : Close_Result::Already_Closed;
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

		if ( ! is_numeric( $claimed ) || (int) $claimed < 1 ) {
			return false;
		}

		// Kept so this worker's later writes can require that it still holds it.
		$this->claim = $claim;

		return true;
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

		/*
		 * The release also raises the run's cost floor to whatever it currently
		 * has reserved, in the same statement, and that is the whole of the fix
		 * for a charge lost across a restart.
		 *
		 * A claim is only ever released because the worker holding it did not come
		 * back. That worker may have been inside a paid call: the provider
		 * answered, was billed for, and the request died before the counter
		 * reached the row. Nothing afterwards can discover that — the replacement
		 * records only its own usage, and settlement then measures a run that
		 * really did cost more than it can show. Version 1.2.0 kept the estimate
		 * only when a sweep gave up on such a run, which protected the case
		 * nobody minds losing and not the one everybody wants to succeed.
		 *
		 * The floor is what this run has recorded plus what the one interrupted
		 * step could have hidden, and never more than the reservation. Until
		 * 1.13.3 it was the whole reservation, which bounds the pipeline rather
		 * than the accident: a run interrupted once and then completing normally
		 * reported around three times what it spent and held that much of the
		 * monthly cap, for ever. A worker can only be inside one step, and the
		 * claim names which — see Step_Allowance. Where that step cannot be
		 * identified, or its prompt has gone, the reservation is still the answer:
		 * an over-estimate costs the site a little of a cap it had already set
		 * aside, and an unrecorded charge costs it the cap.
		 *
		 * GREATEST, and in the same conditional update, so two sweeps racing
		 * cannot lower it and a released claim can never be recorded without it.
		 *
		 * It raises usage_revision too, because raising a floor changes what this
		 * run is known to have cost — and anything holding a price worked out
		 * before it has a price that is now too low. The terminal statements
		 * compare that revision, so such a close marks the row instead of settling
		 * under the floor.
		 *
		 * Written out rather than passed to wpdb::update(), which cannot express a
		 * function in a SET clause. One static statement with placeholders, per
		 * D-26.
		 */
		$bounded = $this->interrupted_floor( $observed );

		if ( null === $bounded ) {
			$released = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET step = %s, cost_floor = GREATEST( cost_floor, cost_cents ),
					usage_revision = usage_revision + 1
					WHERE id = %d AND step = %s AND status = %s',
					Activation::table_name(),
					self::completed_step( $observed ),
					$this->id,
					$observed,
					self::STATUS_RUNNING
				)
			);
		} else {
			$released = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET step = %s, cost_floor = GREATEST( cost_floor, LEAST( cost_cents, %d ) ),
					usage_revision = usage_revision + 1
					WHERE id = %d AND step = %s AND status = %s',
					Activation::table_name(),
					self::completed_step( $observed ),
					$bounded,
					$this->id,
					$observed,
					self::STATUS_RUNNING
				)
			);
		}

		return is_numeric( $released ) && (int) $released > 0;
	}

	/**
	 * Returns the least this run may settle for.
	 *
	 * Zero for a run that has never been interrupted, which is every run that
	 * completes normally. See release_claim() for what raises it.
	 *
	 * @since 1.3.0
	 *
	 * @return int Cents.
	 */
	public function cost_floor(): int {
		return max( 0, (int) $this->column( 'cost_floor' ) );
	}

	/**
	 * Returns the floor a released claim should leave behind, in cents.
	 *
	 * @since 1.13.3
	 *
	 * @param string $observed The claim seen when the run was judged idle.
	 * @return int|null Cents, or null when the interruption cannot be bounded and
	 *                  the reservation should stand instead.
	 */
	private function interrupted_floor( string $observed ): ?int {
		$allowance = Step_Allowance::cents( $this, self::completed_step( $observed ) );

		if ( null === $allowance ) {
			return null;
		}

		$recorded = $this->recorded_cents();

		if ( null === $recorded ) {
			// The counters could not be read, so nothing here can be trusted to
			// bound anything. The reservation stands.
			return null;
		}

		/*
		 * Added rather than maximised. The lost worker may have recorded its
		 * usage and died before writing its step, in which case this counts one
		 * call twice — which is the direction to be wrong in, and the LEAST
		 * against the reservation keeps even that inside what was set aside.
		 */
		return $recorded + $allowance;
	}

	/**
	 * Returns the cost of the usage recorded against this run right now.
	 *
	 * The floor is deliberately not applied: this is one of the two things the
	 * floor is made of.
	 *
	 * @since 1.13.3
	 *
	 * @return int|null Cents, or null when the counters could not be read.
	 */
	private function recorded_cents(): ?int {
		$this->load_usage( true );

		if ( $this->measurement_failed ) {
			return null;
		}

		if ( ! $this->has_usage() ) {
			return 0;
		}

		return $this->pricing_table()->cost_cents(
			(string) $this->usage['text_model'],
			(int) $this->usage['input_tokens'],
			(int) $this->usage['output_tokens'],
			(string) $this->usage['image_model'],
			(int) $this->usage['image_count'],
			max( (int) ( $this->snapshot['grounded_calls'] ?? 0 ), $this->grounded_calls )
		);
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
	 * @param bool $force Read again even if this object has read once already.
	 * @return void
	 */
	private function load_usage( bool $force = false ): void {
		if ( $this->usage_loaded && ! $force ) {
			return;
		}

		// Set first: the read below must not recurse through a caller of this.
		$this->usage_loaded = true;

		global $wpdb;

		/*
		 * One statement for everything a price is made of, and that is the whole
		 * point of it. The counters used to be read here and the revision a query
		 * later, which reversed the guarantee the revision exists to give: a
		 * charge landing between the two reads produced a price computed from the
		 * old counters and stamped with the new revision, so the close compared
		 * equal revisions and left the row unmarked. The comment that used to sit
		 * here argued the ordering made that safe. It made it certain.
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, text_model, image_model, input_tokens, output_tokens, image_count,
				grounded_calls, cost_floor, usage_revision FROM %i WHERE id = %d',
				Activation::table_name(),
				$this->id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			/*
			 * The read failed, so this object cannot say what the run cost — and a
			 * price it cannot vouch for must not be allowed to close the books on
			 * the row. The flag makes any terminal transition mark the run for
			 * repair, which is the same answer an interrupted reconciliation gets.
			 */
			$this->snapshot           = null;
			$this->measurement_failed = true;

			return;
		}

		$this->measurement_failed = false;

		$this->snapshot = array(
			'status'         => (string) $row['status'],
			'grounded_calls' => (int) $row['grounded_calls'],
			'cost_floor'     => (int) $row['cost_floor'],
			'usage_revision' => (int) $row['usage_revision'],
		);

		/*
		 * The larger of the two on every counter, because both can be right and
		 * neither can be trusted alone. The row carries what other actions of this
		 * run recorded, which this object has never seen; the object carries calls
		 * a provider has already answered and charged for, whose write the row may
		 * have refused. Taking the maximum never books money twice and never
		 * settles below what is known to have been spent.
		 */
		$this->usage = array(
			'text_model'    => '' !== (string) $row['text_model'] ? (string) $row['text_model'] : (string) $this->usage['text_model'],
			'image_model'   => '' !== (string) $row['image_model'] ? (string) $row['image_model'] : (string) $this->usage['image_model'],
			'input_tokens'  => max( (int) $row['input_tokens'], (int) $this->usage['input_tokens'] ),
			'output_tokens' => max( (int) $row['output_tokens'], (int) $this->usage['output_tokens'] ),
			'image_count'   => max( (int) $row['image_count'], (int) $this->usage['image_count'] ),
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
	 * Whether this prompt has a run in flight.
	 *
	 * The schedule sweep asks before arming an occurrence: a prompt part way
	 * through a run has its next occurrence armed deliberately late, when that run
	 * concludes, so arming one now would start a second article beside the first.
	 *
	 * @since 1.10.0
	 *
	 * @param int $prompt_id Prompt to ask about.
	 * @return bool|null Null when the question could not be answered.
	 */
	public static function has_open_run( int $prompt_id ): ?bool {
		global $wpdb;

		// Cleared first, so what is read afterwards is this statement's answer.
		$wpdb->last_error = '';

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE prompt_id = %d AND status = %s LIMIT 1',
				Activation::table_name(),
				$prompt_id,
				self::STATUS_RUNNING
			)
		);

		/*
		 * Null for a failed read rather than false, because `get_var()` answers
		 * null both for "no such row" and for "the query did not run", and those
		 * are opposite answers to the question a caller is asking. The accounting
		 * guard had to learn this in 1.9.0; this is the same lesson arriving in
		 * the scheduling code, and the same three-valued answer.
		 */
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return null !== $found;
	}

	/**
	 * Returns which of these prompts have a run in flight.
	 *
	 * One statement for a page of prompts rather than one per prompt, for the
	 * reason the queue lookup is batched: a recovery pass that asks the database
	 * a question per prompt every five minutes is a cost that grows with the
	 * thing it is protecting.
	 *
	 * @since 1.11.0
	 *
	 * @param int[] $prompt_ids Prompts to ask about.
	 * @return array<int, true>|null Null when the question could not be answered.
	 */
	public static function prompts_with_open_runs( array $prompt_ids ): ?array {
		global $wpdb;

		$prompt_ids = array_values( array_filter( array_map( 'intval', $prompt_ids ) ) );

		if ( array() === $prompt_ids ) {
			return array();
		}

		$wpdb->last_error = '';

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT prompt_id FROM %i WHERE status = %s AND prompt_id IN ( '
					. implode( ', ', array_fill( 0, count( $prompt_ids ), '%d' ) ) . ' )',
				array_merge( array( Activation::table_name(), self::STATUS_RUNNING ), $prompt_ids )
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$open = array();

		foreach ( (array) $rows as $prompt_id ) {
			$open[ (int) $prompt_id ] = true;
		}

		return $open;
	}

	/**
	 * Returns how many times a sweeper has re-dispatched this run.
	 *
	 * The count lives in its own column. It used to live in the payload document,
	 * which every step also reads and rewrites whole, so counting a restart meant
	 * a sweeper writing back a document it had read some time earlier — erasing
	 * whatever a worker had recorded in between. A counter shared a column with
	 * the state it was supposed to be protecting.
	 *
	 * The payload value is still read, and only as a floor. A run opened by 1.1.x
	 * and still in flight across the upgrade carries its count there, and reading
	 * only the new column would give such a run a fresh set of restarts.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function sweeps(): int {
		return max( $this->counted_sweeps(), (int) ( $this->payload()['sweeps'] ?? 0 ) );
	}

	/**
	 * Returns the sweep count as the column holds it.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private function counted_sweeps(): int {
		return max( 0, (int) $this->column( 'sweeps' ) );
	}

	/**
	 * Claims the right to restart this run, by counting the restart.
	 *
	 * This is the sweeper's claim as well as its counter. Two sweeps can both
	 * decide the same run is idle — the scan that found it can be many pages old
	 * — and before this was a compare-and-swap both would go on to arm a restart.
	 * The caller passes the count it read, and the write only lands while the
	 * column has not moved past it, so exactly one of them proceeds and the other
	 * stands down having changed nothing.
	 *
	 * The condition is "no further on than the caller thought" rather than
	 * equality, so that the count of a run carrying the 1.1.x payload value can
	 * still be raised past it. Equality would refuse every restart of such a run
	 * for ever, and a run that cannot be restarted or counted cannot be given up
	 * on either.
	 *
	 * @since 1.1.0
	 *
	 * @param int $observed The count this caller judged the run on.
	 * @return bool True when this caller counted the restart.
	 */
	public function record_sweep( int $observed ): bool {
		global $wpdb;

		$next = max( 0, $observed ) + 1;

		$counted = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET sweeps = %d WHERE id = %d AND status = %s AND sweeps < %d',
				Activation::table_name(),
				$next,
				$this->id,
				self::STATUS_RUNNING,
				$next
			)
		);

		return is_numeric( $counted ) && (int) $counted > 0;
	}

	/**
	 * Returns how long ago this run opened, in seconds.
	 *
	 * @since 1.4.0
	 *
	 * @return int Zero when the row has no usable start time.
	 */
	public function age_seconds(): int {
		$started = (string) $this->column( 'started_at' );

		if ( '' === $started || '0000-00-00 00:00:00' === $started ) {
			return 0;
		}

		return max( 0, time() - (int) strtotime( $started . ' UTC' ) );
	}

	/**
	 * Returns what kind of run this row records.
	 *
	 * Falls back to the step column for a row opened before 1.3.0 recorded a
	 * kind. A preview has always written "preview" there, so a run in flight
	 * across the upgrade is still recognisable — which matters, because the whole
	 * point of the distinction is what recovery does with the row.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function kind(): string {
		$stored = (string) ( $this->payload()['kind'] ?? '' );

		if ( '' !== $stored ) {
			return $stored;
		}

		return self::KIND_PREVIEW === $this->step() ? self::KIND_PREVIEW : self::KIND_RUN;
	}

	/**
	 * Whether this row records a preview rather than a generation run.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function is_preview(): bool {
		return self::KIND_PREVIEW === $this->kind();
	}

	/**
	 * Returns the rate table this run was opened under.
	 *
	 * @since 1.2.0
	 *
	 * @return Pricing_Table
	 */
	public function pricing_table(): Pricing_Table {
		$stored = $this->payload()['rates'] ?? null;

		return is_array( $stored ) && array() !== $stored ? new Pricing_Table( $stored ) : new Pricing_Table();
	}

	/**
	 * Returns a model ID resolved when this run opened.
	 *
	 * A blank model on the prompt and a blank site default resolve through the
	 * adapter's suggestion list, which is code rather than configuration: a plugin
	 * upgrade can change it without changing anything a fingerprint would notice.
	 * Resolving once at the start means every paid step of one run uses the model
	 * its budget was checked against, rather than the topic being proposed by one
	 * model and the article written by another.
	 *
	 * @since 1.2.0
	 *
	 * @param string $kind Either text or image.
	 * @return string Empty when this run recorded no snapshot.
	 */
	public function resolved_model( string $kind ): string {
		$models = $this->payload()['models'] ?? array();

		return is_array( $models ) ? (string) ( $models[ $kind ] ?? '' ) : '';
	}

	/**
	 * Returns the model a paid call should use, preferring this run's snapshot.
	 *
	 * Falls back to resolving from configuration for a run that recorded no
	 * snapshot, which is a run opened by an earlier version and still in flight
	 * across the upgrade. Failing those runs to enforce a snapshot they could not
	 * have taken would be a worse answer than finishing them.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $kind         Either text or image.
	 * @param string   $prompt_model Model set on the prompt, possibly empty.
	 * @param string   $slug         Provider slug.
	 * @param string[] $suggestions  The adapter's suggested model IDs.
	 * @return string
	 */
	public function model_for( string $kind, string $prompt_model, string $slug, array $suggestions ): string {
		$recorded = $this->resolved_model( $kind );

		return '' !== $recorded ? $recorded : Model_Resolver::resolve( $prompt_model, $slug, $suggestions );
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
	 * @param Pricing_Table|null $pricing Rate table, or null to use this run's own.
	 * @return Close_Result What the attempt did.
	 */
	public function skip( string $status, string $reason, ?Pricing_Table $pricing = null ): Close_Result {
		return $this->close(
			array(
				'status'     => $status,
				'error'      => $reason,
				'cost_cents' => $this->measured_cents( $pricing ),
			)
		);
	}

	/**
	 * Records money against this run, under the spend lock when the row is closed.
	 *
	 * A charge on an *open* run needs no coordination: its cost is worked out when
	 * the run closes, and the reservation is what the monthly cap sees until then.
	 * A charge on a *closed* run is different, and this is the residual the last
	 * two rounds narrowed without closing. The budget guard repairs, sums, checks
	 * that nothing is outstanding, and then authorises — and a charge landing
	 * after that check makes the total it authorised on incomplete before the
	 * reservation is even written.
	 *
	 * The guard holds the spend lock from its check through that reservation, so
	 * a late closed-row charge that takes the same lock cannot land inside it.
	 * Which makes the rule simple: money that arrives after a run has closed waits
	 * for the books to be balanced, and money that arrives before it does not wait
	 * at all. The cost is one status read per paid call, on a path that has just
	 * spent up to two minutes talking to a provider.
	 *
	 * @since 1.11.0
	 *
	 * @param callable $write Performs the counter write and returns whether it landed.
	 * @return bool Whatever the write reported.
	 */
	private function under_spend_lock_if_closed( callable $write ): bool {
		if ( self::STATUS_RUNNING === $this->status() ) {
			$written = (bool) $write();

			$this->reconcile_cost();

			return $written;
		}

		$lock = new Spend_Lock();

		$lock->acquire();

		try {
			$written = (bool) $write();

			$this->reconcile_cost();

			return $written;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Raises a closed run's settled cost to include usage that arrived late.
	 *
	 * The counters are unfenced on purpose: a provider that answered has charged
	 * for the answer whoever asked and whatever has happened to the run since, so
	 * a worker returning after its run was closed must still be able to record
	 * what it spent. That was half a mechanism. The month-to-date total the
	 * section 7.4 cap reads sums `cost_cents`, which a closed run computed before
	 * the late counters existed — so the spending reached the run log and never
	 * reached the cap. A duplicate image bought by a superseded worker is the
	 * clearest case: two pictures billed, one counted.
	 *
	 * Pricing what a counter records cannot be done in the statement that records
	 * it without writing the rate table into SQL, so the two are separate writes
	 * and a process can die between them. What makes that survivable is that the
	 * *first* write says so: every money increment sets cost_stale on a closed
	 * row, in the same statement, so a row whose cost has not caught up carries a
	 * flag saying it has not. This method clears the flag; the budget guard and
	 * the stall sweep clear it for rows nobody came back to. An interrupted
	 * reconciliation is therefore late rather than lost.
	 *
	 * The write is a compare-and-swap on the counters this measurement was taken
	 * from. If another increment lands while the price is being worked out, the
	 * condition does not match, the row stays flagged, and that increment's own
	 * reconciliation — or a repair pass — prices both. GREATEST on the cost means
	 * a reconciliation can only ever raise the figure, so one racing a reservation
	 * floor cannot undo it.
	 *
	 * On an open run this does nothing at all. Settlement has not happened yet and
	 * will read the counters when it does.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True when nothing is owed, or when this call settled what was.
	 */
	public function reconcile_cost(): bool {
		global $wpdb;

		/*
		 * One read for the price, its revision, and the status it applies to. This
		 * used to take the status and the grounded count in a query of their own
		 * and the rest in another, which is three moments pretending to be one.
		 */
		$cents = $this->measured_cents( null );

		if ( null === $this->snapshot ) {
			// The row could not be read. Nothing is known, so nothing is claimed.
			return false;
		}

		if ( self::STATUS_RUNNING === (string) $this->snapshot['status'] ) {
			// Settlement has not happened yet and will read the counters when it
			// does. Nothing is owed, which is a different answer from "settled".
			return true;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET cost_cents = GREATEST( cost_cents, %d ), cost_stale = 0
				WHERE id = %d AND status <> %s AND usage_revision = %d',
				Activation::table_name(),
				$cents,
				$this->id,
				self::STATUS_RUNNING,
				(int) $this->measured_revision
			)
		);

		/*
		 * Zero rows is not success. It means a charge landed while the price was
		 * being worked out, so the figure just computed is already out of date and
		 * the row is still marked — which is exactly the state a retry is for.
		 * Reporting it as settled was how a caller could be told the books
		 * balanced while the row said otherwise.
		 */
		return is_numeric( $updated ) && (int) $updated > 0;
	}

	/**
	 * Returns closed runs whose settled cost has not caught up with their usage.
	 *
	 * Bounded, because this is a repair rather than a scan: a site with a large
	 * backlog of them has something wrong that a longer query will not fix, and
	 * the next pass takes the next batch.
	 *
	 * @since 1.6.0
	 *
	 * @param int                  $limit Most rows to return.
	 * @param array<string, mixed> $scope Optional prompt_id, start, and end bounds.
	 * @return int[]|null Null when the query failed.
	 */
	public static function unsettled( int $limit = 25, array $scope = array() ): ?array {
		global $wpdb;

		/*
		 * The scope exists so that repair and summation can be made to cover the
		 * same rows. A cap is computed from one prompt or one month; a repair that
		 * ranges over the whole table can fail on a row from another prompt or
		 * another year and stop a run the cap would have allowed. Each filter is
		 * disabled by its own sentinel rather than by assembling a WHERE clause,
		 * for the reason Run::query() gives.
		 */
		$prompt_id = max( 0, (int) ( $scope['prompt_id'] ?? 0 ) );
		$start     = (string) ( $scope['start'] ?? '1000-01-01 00:00:00' );
		$end       = (string) ( $scope['end'] ?? '9999-12-31 23:59:59' );

		/*
		 * Cleared first, so what is read afterwards is this statement's own answer.
		 * An error left behind by an unrelated query would otherwise make this one
		 * look like a failure.
		 */
		$wpdb->last_error = '';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i
				WHERE cost_stale = 1 AND status <> %s
					AND ( %d = 0 OR prompt_id = %d )
					AND started_at >= %s AND started_at < %s
				ORDER BY id ASC LIMIT %d',
				Activation::table_name(),
				self::STATUS_RUNNING,
				$prompt_id,
				$prompt_id,
				$start,
				$end,
				max( 1, $limit )
			)
		);

		/*
		 * Null rather than an empty array when the query failed, because those two
		 * answers mean opposite things and only one of them may authorise a run.
		 * An empty result says nothing is owed; a failed read says nothing is
		 * known, and a cap computed on "nothing is known" is not a cap.
		 */
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Prices whatever late usage is outstanding, for a bounded batch of runs.
	 *
	 * @since 1.6.0
	 *
	 * @param int $limit Most runs to settle.
	 * @return int How many were settled.
	 */
	public static function settle_unsettled( int $limit = self::REPAIR_BATCH ): int {
		$settled = 0;

		foreach ( (array) self::unsettled( $limit ) as $id ) {
			$run = self::load( $id );

			if ( $run instanceof self && $run->reconcile_cost() ) {
				++$settled;
			}
		}

		return $settled;
	}

	/**
	 * Prices every outstanding run, in bounded pages, and says whether it managed.
	 *
	 * The bounded batch is right for a background sweep and wrong for the budget
	 * guard, which is about to decide whether a run may spend: one batch left a
	 * backlog of twenty-six rows with twenty-five priced, and the guard summed a
	 * total the database itself said was short. Draining is the difference
	 * between a repair and an answer.
	 *
	 * The page count is a bound rather than a target. A site with more unpriced
	 * runs than this has something wrong that a longer loop will not fix, and the
	 * caller is told so rather than being handed a total it cannot rely on.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string, mixed> $scope Optional prompt_id, start, and end bounds.
	 * @param int                  $pages Most pages to work through.
	 * @return bool True when nothing is outstanding by the end.
	 */
	public static function settle_all_unsettled( array $scope = array(), int $pages = self::REPAIR_PAGES ): bool {
		$allowed = max( 1, $pages );

		for ( $page = 0; $page < $allowed; $page++ ) {
			$outstanding = self::unsettled( self::REPAIR_BATCH, $scope );

			if ( null === $outstanding ) {
				// The books cannot even be read, let alone balanced.
				return false;
			}

			if ( array() === $outstanding ) {
				return true;
			}

			$settled = 0;

			foreach ( $outstanding as $id ) {
				$run = self::load( $id );

				if ( $run instanceof self && $run->reconcile_cost() ) {
					++$settled;
				}
			}

			if ( 0 === $settled ) {
				// Nothing moved, so another pass would read the same rows and fail
				// the same way. A write is being refused, or a charge is arriving
				// faster than it can be priced.
				return false;
			}
		}

		return array() === self::unsettled( 1, $scope );
	}

	/**
	 * Whether anything in this scope is still waiting to be priced.
	 *
	 * Three-valued on purpose: yes, no, or unreadable. The caller that authorises
	 * spending has to tell the third from the second.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $scope Optional prompt_id, start, and end bounds.
	 * @return bool|null True when something is outstanding, null when unreadable.
	 */
	public static function has_unsettled( array $scope = array() ): ?bool {
		$outstanding = self::unsettled( 1, $scope );

		return null === $outstanding ? null : array() !== $outstanding;
	}

	/**
	 * Returns the cost of the usage actually recorded against this run.
	 *
	 * The counters are re-read here rather than taken from whatever this object
	 * happened to load earlier. Settlement is the last thing a run does and the
	 * only figure the monthly cap ever sees, so it reads the row as it stands and
	 * keeps this object's own counters as a floor under it — see load_usage().
	 *
	 * @since 1.0.1
	 *
	 * @param Pricing_Table|null $pricing        Rate table, or null to use this run's own.
	 * @param int                $grounded_calls Number of grounded requests made.
	 * @return int Cost in cents.
	 */
	private function measured_cents( ?Pricing_Table $pricing, int $grounded_calls = 0 ): int {
		$this->load_usage( true );

		// From the same read as the counters, so the revision certifies the price
		// rather than merely accompanying it.
		$this->measured_revision = (int) ( $this->snapshot['usage_revision'] ?? 0 );

		/*
		 * The floor is applied to every ending, including the one that measures
		 * nothing. A run interrupted inside its first paid call has no usage to
		 * measure and may still have been charged, which is precisely the case the
		 * floor exists for — see release_claim().
		 *
		 * From the snapshot, and only from it. Reading the column again here was
		 * the last thing left outside the one statement this measurement is
		 * supposed to be: a claim released between the two reads raised the floor
		 * after the price had been worked out, and the close then wrote a figure
		 * below the floor the row was carrying.
		 */
		$floor = (int) ( $this->snapshot['cost_floor'] ?? 0 );

		if ( ! $this->has_usage() ) {
			return $floor;
		}

		$table = $pricing instanceof Pricing_Table ? $pricing : $this->pricing_table();

		/*
		 * The larger of what the caller believes and what the row says, for the
		 * same reason the counters take the larger of the row and this object: a
		 * caller can know about a request whose write the row refused, and the row
		 * can know about one this object never saw. Neither can be trusted to be
		 * the whole of it, and only one of the two errors costs money.
		 */
		$grounded = max( $grounded_calls, (int) ( $this->snapshot['grounded_calls'] ?? 0 ), $this->grounded_calls );

		return max(
			$floor,
			$table->cost_cents(
				(string) $this->usage['text_model'],
				(int) $this->usage['input_tokens'],
				(int) $this->usage['output_tokens'],
				(string) $this->usage['image_model'],
				(int) $this->usage['image_count'],
				$grounded
			)
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
	 * The rates are the ones this run was opened under, not the ones in force
	 * when it happens to finish. A run that is checked against one price list and
	 * settled against another is not accounted for at all: an edit to the pricing
	 * table between two queued actions would change what an open reservation
	 * releases, and the difference lands on the month's total.
	 *
	 * @param Pricing_Table|null $pricing        Rate table, or null to use this run's own.
	 * @param int                $grounded_calls Number of grounded requests made.
	 * @return int Cost in cents.
	 */
	public function settle_cost( ?Pricing_Table $pricing = null, int $grounded_calls = 0 ): int|WP_Error {
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
	 * @return Close_Result What the attempt did.
	 */
	public function succeed(): Close_Result {
		return $this->close( array( 'status' => self::STATUS_SUCCESS ) );
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
	 * @param string|null          $expected_step Close only while the run is at
	 *                                            this position, or null for any.
	 * @return Close_Result What the attempt did.
	 */
	private function close( array $data, ?string $expected_step = null ): Close_Result {
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
		if ( null === $expected_step && null !== $this->claim ) {
			/*
			 * A step that ends the run — a budget skip, a duplicate topic, a
			 * failure the step itself closes on — is making a terminal decision
			 * about work it believes it is performing. If its claim has moved, the
			 * work belongs to somebody else and so does the decision: an unfenced
			 * close here let a superseded worker end a run its replacement was
			 * part way through. Defaulting to the claim makes the ordinary case
			 * safe without every call site having to remember.
			 */
			$expected_step = $this->claim;
		}

		if ( null !== $expected_step ) {
			return $this->closed_by_me( $this->close_at( $data, $expected_step ) );
		}

		/*
		 * Zero affected rows is unambiguous here, in a way it is not for an
		 * ordinary update. A status transition always changes the status, so a
		 * row that matched would always have been written; nothing written means
		 * nothing matched, which means the run is no longer open.
		 *
		 * Written out rather than passed to wpdb::update(), which cannot compare
		 * two columns — and comparing two columns is the whole of what the stale
		 * decision is. This used to close through wpdb::update() and then mark the
		 * row in a second statement whose result nothing read, so a process that
		 * stopped in between, or a database that refused the second write, closed
		 * a run and lost the only record that its price was short.
		 */
		$revision = $this->measured_revision ?? -1;
		$unpriced = $this->measurement_failed ? 1 : 0;

		$updated = array_key_exists( 'cost_cents', $data )
			? $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, error = %s, cost_cents = %d, finished_at = %s,
					cost_stale = IF( %d = 1 OR ( %d >= 0 AND usage_revision <> %d ) OR cost_floor > %d, 1, cost_stale )
					WHERE id = %d AND status = %s',
					Activation::table_name(),
					(string) $data['status'],
					(string) ( $data['error'] ?? '' ),
					(int) $data['cost_cents'],
					(string) $data['finished_at'],
					$unpriced,
					$revision,
					$revision,
					(int) $data['cost_cents'],
					$this->id,
					self::STATUS_RUNNING
				)
			)
			: $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, finished_at = %s,
					cost_stale = IF( %d = 1 OR ( %d >= 0 AND usage_revision <> %d ) OR cost_floor > cost_cents, 1, cost_stale )
					WHERE id = %d AND status = %s',
					Activation::table_name(),
					(string) $data['status'],
					(string) $data['finished_at'],
					$unpriced,
					$revision,
					$revision,
					$this->id,
					self::STATUS_RUNNING
				)
			);

		if ( false === $updated ) {
			return Close_Result::Write_Failed;
		}

		return $this->closed_by_me( (int) $updated > 0 ? Close_Result::Closed : Close_Result::Already_Closed );
	}

	/**
	 * Remembers a terminal transition this object performed.
	 *
	 * @since 1.4.0
	 *
	 * @param Close_Result $result What the close attempt did.
	 * @return Close_Result The same result.
	 */
	private function closed_by_me( Close_Result $result ): Close_Result {
		if ( $result->ended() ) {
			$this->closed_here = true;
		}

		return $result;
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
	 * @param Pricing_Table|null $pricing        Rate table, or null to use this run's own.
	 * @param int                $grounded_calls Number of grounded requests made.
	 * @param string|null        $expected_step  Close only while the run is still
	 *                                           at this position, or null for any.
	 * @param bool               $keep_estimate  Never settle below the reservation.
	 * @return Close_Result What the attempt did.
	 */
	public function fail( string $message, ?Pricing_Table $pricing = null, int $grounded_calls = 0, ?string $expected_step = null, bool $keep_estimate = false ): Close_Result {
		$measured = $this->measured_cents( $pricing, $grounded_calls );

		return $this->close(
			array(
				'status'     => self::STATUS_FAILED,
				'error'      => $message,
				'cost_cents' => $keep_estimate ? max( $measured, (int) $this->column( 'cost_cents' ) ) : $measured,
			),
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

		/*
		 * Under the claim, when this object holds one, and only while the run is
		 * still open. Every write a claimed step makes is a statement about work
		 * that step performed, so a worker whose claim has been taken away — or
		 * whose run has been closed under it — has no business making any of them.
		 * Only the usage counters are exempt, and they do not come through here:
		 * see record_text_usage() for why money is recorded whoever spent it and
		 * whatever has happened to the row since.
		 */
		$where   = null === $this->claim
			? array( 'id' => $this->id )
			: array(
				'id'     => $this->id,
				'status' => self::STATUS_RUNNING,
				'step'   => $this->claim,
			);
		$updated = $wpdb->update(
			Activation::table_name(),
			$data,
			$where,
			$formats,
			null === $this->claim ? array( '%d' ) : array( '%d', '%s', '%s' )
		);

		/*
		 * update() returns the number of affected rows, and zero is ambiguous: it
		 * means either that the row is missing or that the values were already
		 * what was written. Only an explicit false is a failed write, and only the
		 * reservation currently acts on it, because that is the write whose loss
		 * costs money rather than a log entry.
		 *
		 * With the claim in the condition there is a third reading — the claim has
		 * moved — so a claimed write that changed nothing asks the row whose claim
		 * it is before believing itself. Same reasoning as write_payload().
		 */
		if ( false === $updated ) {
			return false;
		}

		return null === $this->claim || (int) $updated > 0 || $this->holds_claim();
	}
}
