<?php
/**
 * Activation, deactivation, capabilities, and schema management.
 *
 * @package AutoScribe
 */

namespace AutoScribe;

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
	public const DB_VERSION = '2';

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
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			error text DEFAULT NULL,
			payload longtext DEFAULT NULL,
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY prompt_started (prompt_id, started_at),
			KEY status_idx (status),
			KEY topic_key_idx (topic_key),
			KEY started_at_idx (started_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
