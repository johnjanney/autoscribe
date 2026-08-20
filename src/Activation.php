<?php
/**
 * Activation, deactivation, capabilities, and schema management.
 *
 * @package AutoScribe
 */

namespace AutoScribe;

use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Run_Retention;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Scheduling\Scheduler;
use WP_Role;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and maintains the plugin's persistent state.
 *
 * @since 0.1.0
 */
final class Activation {

	/**
	 * Option holding the installed schema version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'autoscribe_db_version';

	/**
	 * Schema version this build expects.
	 *
	 * Bump whenever the runs table changes so maybe_upgrade() re-runs dbDelta.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const DB_VERSION = '8';

	/**
	 * How many rows one pass of a data migration reads at a time.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const MIGRATION_BATCH = 200;

	/**
	 * How many of those pages one request works through before leaving the rest.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const MIGRATION_PAGES = 50;

	/**
	 * Capability gating the settings screens.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const MANAGE_CAPABILITY = 'autoscribe_manage_prompts';

	/**
	 * Returns the prefixed name of the runs table.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'autoscribe_runs';
	}

	/**
	 * Returns every capability the plugin registers.
	 *
	 * Section 8.2 of the brief names a single capability, but a custom post type
	 * consults a whole generated family. Registering only the settings
	 * capability would leave the post type on the default post capabilities,
	 * which lets any Author edit prompts. Both sets are granted together.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public static function capabilities(): array {
		return array(
			self::MANAGE_CAPABILITY,
			'edit_autoscribe_prompt',
			'read_autoscribe_prompt',
			'delete_autoscribe_prompt',
			'edit_autoscribe_prompts',
			'edit_others_autoscribe_prompts',
			'edit_private_autoscribe_prompts',
			'edit_published_autoscribe_prompts',
			'publish_autoscribe_prompts',
			'read_private_autoscribe_prompts',
			'delete_autoscribe_prompts',
			'delete_others_autoscribe_prompts',
			'delete_private_autoscribe_prompts',
			'delete_published_autoscribe_prompts',
		);
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::add_capabilities();
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Deliberately non-destructive: the table, the options, and the capabilities
	 * all survive so a deactivate/reactivate cycle loses nothing. Removal
	 * belongs to uninstall.php. Phase 4 adds queue teardown here once Action
	 * Scheduler exists, so a deactivated plugin leaves no armed actions behind.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Cancel every queued run. Without this a deactivated plugin leaves armed
		// Action Scheduler actions behind, which the queue keeps picking up and
		// failing for as long as the site runs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			/*
			 * By hook alone, and deliberately. as_unschedule_all_actions() only
			 * takes its cancel-everything-for-this-hook shortcut when the group
			 * is left out; given a hook *and* a group with empty args it falls
			 * through to matching actions whose args are exactly empty, and every
			 * action this plugin arms carries a prompt or run ID. Passing the
			 * group — which this call did until 1.1.0 — therefore cancelled
			 * nothing at all, and deactivating the plugin left its whole queue
			 * armed. Both hook names are plugin-specific, so cancelling by hook
			 * cannot reach another plugin's actions.
			 *
			 * The step actions matter as much as the prompt ones. They are keyed
			 * by run rather than by prompt, so nothing else clears them: left
			 * behind, they either strand their runs — Action Scheduler can be
			 * supplied by another active plugin and go on consuming actions whose
			 * callback this plugin no longer registers — or sit in the queue and
			 * resume a half-finished run whenever the plugin is switched back on.
			 */
			as_unschedule_all_actions( Scheduler::HOOK_RUN_PROMPT );
			as_unschedule_all_actions( Scheduler::HOOK_RUN_STEP );
		}

		Run_Retention::unschedule();
		Stall_Sweeper::unschedule();

		flush_rewrite_rules();
	}

	/**
	 * Re-runs the schema when the stored version is behind this build.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Creates or updates the runs table.
	 *
	 * The column list follows section 3.2 of the brief. Two additions the brief
	 * omits: the table charset and collation, without which a host defaulting to
	 * latin1 mangles non-ASCII titles, and a stored schema version so later
	 * column changes can migrate without a reactivation.
	 *
	 * Timestamps are stored in UTC. Section 7.4 sums spend by calendar month in
	 * the site timezone, which needs a known storage timezone to convert from.
	 *
	 * A third addition since 1.2.0: sweeps, the number of times the stall sweeper
	 * has restarted a run. It is a column rather than a key in the payload
	 * document because the sweeper and the run's own steps write concurrently, and
	 * a shared JSON document cannot be updated by two writers without one of them
	 * losing what the other stored.
	 *
	 * A seventh since 1.7.0: usage_revision, which every write that records money
	 * raises. It is what lets a terminal transition tell whether the counters it
	 * priced are still the counters the row has — a charge landing in the moment
	 * between measuring a run's cost and closing it belongs to that run, and
	 * without the revision nothing afterwards could tell it had arrived.
	 *
	 * A sixth since 1.6.0: cost_stale, marking a closed run whose settled cost does
	 * not yet include everything its counters do. It is written by the same
	 * statement that raises a counter, which is what makes the accounting durable:
	 * a process that dies between recording money and pricing it leaves a row that
	 * says so, and something later fixes it. See Run::reconcile_cost().
	 *
	 * A fifth since 1.5.0: grounded_calls, the number of grounded requests a run
	 * paid the search surcharge for. It moved out of the payload document for the
	 * same reason sweeps did — a counter that costs money cannot live behind a
	 * fence meant for state, because the write that records it has to land even
	 * when the run it belongs to has closed.
	 *
	 * A fourth since 1.3.0: cost_floor, the least a run may settle for. A run
	 * whose worker was killed inside a paid call cannot show what that call cost,
	 * and the floor is what stops the settlement that follows from reporting less
	 * than the site was charged. See Run::release_claim().
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * started_at carries an index of its own as well as being the second
		 * column of prompt_started. Site-wide spend is summed by month across
		 * every prompt, so it filters on started_at alone, and a composite index
		 * cannot serve a query that does not constrain its leading column. That
		 * left the monthly total scanning the whole table — unnoticeable on a new
		 * site, and steadily less so on one generating daily for a year.
		 *
		 * dbDelta parses this string with its own regexes, so it stays free of
		 * SQL comments and keeps one definition per line.
		 */
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			prompt_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL,
			step varchar(40) DEFAULT NULL,
			topic_key varchar(191) DEFAULT NULL,
			title text DEFAULT NULL,
			text_model varchar(100) DEFAULT NULL,
			image_model varchar(100) DEFAULT NULL,
			input_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			image_count smallint(5) unsigned NOT NULL DEFAULT 0,
			cost_cents int(10) unsigned NOT NULL DEFAULT 0,
			cost_floor int(10) unsigned NOT NULL DEFAULT 0,
			grounded_calls smallint(5) unsigned NOT NULL DEFAULT 0,
			cost_stale tinyint(1) unsigned NOT NULL DEFAULT 0,
			usage_revision int(10) unsigned NOT NULL DEFAULT 0,
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			sweeps smallint(5) unsigned NOT NULL DEFAULT 0,
			error text DEFAULT NULL,
			payload longtext DEFAULT NULL,
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY prompt_started (prompt_id, started_at),
			KEY status_idx (status),
			KEY topic_key_idx (topic_key),
			KEY started_at_idx (started_at),
			KEY cost_stale_idx (cost_stale)
		) {$charset_collate};";

		dbDelta( $sql );

		/*
		 * The version is recorded only when the data migration finished as well as
		 * the schema one. A migration that ran out of pages, or met a write it
		 * could not make, leaves the version behind so the next request carries on
		 * — repeating it is safe, because a migrated row no longer matches.
		 */
		if ( self::migrate_grounded_calls() && self::has_column( 'usage_revision' ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Whether the runs table really has a column this build depends on.
	 *
	 * WordPress reports nothing useful about what dbDelta() did, and a schema
	 * version recorded for a schema change that did not happen is a site that will
	 * never try again. Asking the table is one query at upgrade time.
	 *
	 * @since 1.8.0
	 *
	 * @param string $column Column to look for.
	 * @return bool
	 */
	private static function has_column( string $column ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				self::table_name(),
				$column
			)
		);

		return null !== $found;
	}

	/**
	 * Moves grounded-call counts out of the payload and adds them to their column.
	 *
	 * Version 1.5.0 moved the count from the payload document to a column and read
	 * the larger of the two, which loses a call: a run with one recorded before
	 * the upgrade and one after has one in each place, and the larger of one and
	 * one is one. Two searches billed, one counted.
	 *
	 * The two numbers count different periods, so they are added rather than
	 * compared — and the legacy key is removed in the same write, which is what
	 * makes the move safe to repeat. A row that still carries the key has not been
	 * migrated; a row without it has. The write is conditional on the exact
	 * payload it was read from, so a document a worker changed in between is left
	 * for the next pass rather than being written back stale.
	 *
	 * Closed rows are migrated too, and flagged for repricing. Their money was
	 * settled under the old reading, which is precisely why the surcharge it
	 * omitted has to be added now; GREATEST means repricing a row that owes
	 * nothing changes nothing.
	 *
	 * The write raises usage_revision like any other change to a cost-bearing
	 * counter, so a run being closed while this migration touches it is marked
	 * rather than quietly priced without the surcharge.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when nothing carrying the legacy key is left.
	 */
	private static function migrate_grounded_calls(): bool {
		global $wpdb;

		/*
		 * An ID cursor rather than a repeated LIMIT over the same predicate. The
		 * candidate query matches a substring of the JSON, so it also matches a row
		 * whose payload merely contains those characters — a title, a source URL —
		 * and such a row has nothing to move. Re-reading from the start meant the
		 * same one came back on every page and on every later request, because the
		 * schema version is only recorded when the migration finishes: one odd
		 * payload could put a scan and a dbDelta() on every front-end request the
		 * site served. The cursor moves past every row it has looked at, whether or
		 * not there was anything in it.
		 */
		$after = 0;

		for ( $page = 0; $page < self::MIGRATION_PAGES; $page++ ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, status, payload FROM %i WHERE id > %d AND payload LIKE %s ORDER BY id ASC LIMIT %d',
					self::table_name(),
					$after,
					'%grounded_calls%',
					self::MIGRATION_BATCH
				),
				ARRAY_A
			);

			/*
			 * An empty result and a failed query look identical here, and they mean
			 * opposite things: one says the work is done and the other says nothing
			 * is known. Reading the error is the only way to tell, and treating a
			 * failure as completion is how an install records a migration it never
			 * performed and never tries again.
			 */
			if ( '' !== $wpdb->last_error ) {
				return false;
			}

			if ( ! is_array( $rows ) || array() === $rows ) {
				return true;
			}

			foreach ( $rows as $row ) {
				$after = max( $after, (int) $row['id'] );

				if ( ! self::move_grounded_calls( $row ) ) {
					// A write that would not land. The next request starts again
					// from the beginning and meets it, or not, as the case may be.
					return false;
				}
			}
		}

		$remaining = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d AND payload LIKE %s LIMIT 1',
				self::table_name(),
				$after,
				'%grounded_calls%'
			)
		);

		return '' === $wpdb->last_error && array() === (array) $remaining;
	}

	/**
	 * Adds one row's legacy grounded count to its column and drops the old key.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $row Row with id, status, and payload.
	 * @return bool True when this row no longer carries the legacy key.
	 */
	private static function move_grounded_calls( array $row ): bool {
		global $wpdb;

		$payload = (string) $row['payload'];
		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || ! array_key_exists( 'grounded_calls', $decoded ) ) {
			/*
			 * The match was on a substring of the JSON, so it can be something else
			 * entirely — a topic key, a source URL — or the payload can be
			 * unreadable. Either way there is no key to move, which is a row
			 * successfully dealt with rather than a row left behind. Completion is
			 * decided by the decoded keys and the cursor, not by whether the raw
			 * document still contains those characters.
			 */
			return true;
		}

		$legacy = max( 0, (int) $decoded['grounded_calls'] );

		unset( $decoded['grounded_calls'] );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET grounded_calls = grounded_calls + %d, payload = %s,
				usage_revision = usage_revision + 1,
				cost_stale = IF( status <> %s AND %d > 0, 1, cost_stale )
				WHERE id = %d AND payload = %s',
				self::table_name(),
				$legacy,
				(string) wp_json_encode( $decoded ),
				Run::STATUS_RUNNING,
				$legacy,
				(int) $row['id'],
				$payload
			)
		);

		return is_numeric( $updated ) && (int) $updated > 0;
	}

	/**
	 * Grants the plugin's capabilities to the administrator role.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function add_capabilities(): void {
		$role = get_role( 'administrator' );

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( self::capabilities() as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
