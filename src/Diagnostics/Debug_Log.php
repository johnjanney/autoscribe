<?php
/**
 * Diagnostic capture of what providers actually returned.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Diagnostics;

use AutoScribe\Admin\Settings;
use AutoScribe\Concurrency\Named_Lock;

defined( 'ABSPATH' ) || exit;

/**
 * A short, self-trimming record of the exchanges behind a failed run.
 *
 * The run log says why a run stopped in the plugin's own words. That is the
 * right thing for it to say and it is not always enough to act on: "the provider
 * returned HTTP 400" does not name the field the provider objected to, and "the
 * response was not valid JSON" does not show what arrived instead. Both answers
 * are in the raw response, and until now the raw response was read once, turned
 * into a sentence, and dropped.
 *
 * Keeping it needs somewhere to put it, and the usual answer — error_log() and
 * debug.log — assumes a shell. On the managed hosting this plugin is aimed at
 * there frequently is not one, and the runs that fail are the scheduled ones,
 * which happen in an Action Scheduler worker nobody is watching. So the capture
 * goes to an option and is read back in wp-admin, where the person debugging
 * already is.
 *
 * Three properties matter more than completeness, because this holds provider
 * traffic rather than the plugin's own summary of it:
 *
 * - It is off by default. Nothing is captured until an administrator turns it
 *   on, and the settings screen says so while it is on.
 * - It never holds a credential. Keys travel in headers, and headers are never
 *   passed in here; on top of that every recorded string is run through a
 *   scrubber that blanks key-shaped tokens, in case a provider quotes one back
 *   inside an error message.
 * - It cannot grow without bound. The option keeps the most recent
 *   MAX_ENTRIES exchanges and stays under MAX_OPTION_BYTES, and it does not
 *   autoload, so a log left switched on costs nothing on ordinary requests.
 *
 * @since 1.16.0
 */
final class Debug_Log {

	/**
	 * Option holding the captured entries.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	public const OPTION = 'autoscribe_debug_log';

	/**
	 * How many exchanges are kept.
	 *
	 * A failed run makes a handful of provider calls, and what is being debugged
	 * is nearly always the most recent one or two. Enough for several runs, few
	 * enough to read on one screen.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	public const MAX_ENTRIES = 30;

	/**
	 * Largest recorded body, in bytes, after scrubbing.
	 *
	 * A provider error body is a few hundred bytes and an article payload is a
	 * few thousand. This keeps a whole error and the beginning of an article,
	 * which is where a malformed one goes wrong.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	public const MAX_BODY_BYTES = 4000;

	/**
	 * Largest text the scrubber is asked to process, in bytes.
	 *
	 * Http permits a response of eight megabytes, and an image response is mostly
	 * one enormous base64 field. Running the scrubbing patterns over that risks
	 * PCRE's backtrack limit, which makes preg_replace() return null rather than
	 * a shortened string. The text is cut to this first: still far more than the
	 * kept body, and small enough that the patterns cannot run away.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	private const SCRUB_CEILING_BYTES = 65536;

	/**
	 * Ceiling on the whole option, in bytes.
	 *
	 * @since 1.16.0
	 * @var int
	 */
	private const MAX_OPTION_BYTES = 262144;

	/**
	 * A completed HTTP exchange with a provider.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	public const CHANNEL_HTTP = 'http';

	/**
	 * A request that never reached a provider.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	public const CHANNEL_TRANSPORT = 'transport';

	/**
	 * Model output that failed the schema.
	 *
	 * @since 1.16.0
	 * @var string
	 */
	public const CHANNEL_CONTENT = 'content';

	/**
	 * Which run and step the current worker is executing.
	 *
	 * Http is static and deliberately knows nothing about the pipeline, so the
	 * pipeline tells this class where it is instead. Without it every entry would
	 * be a bare URL, and a log covering several runs could not be read apart.
	 *
	 * @since 1.16.0
	 * @var array<string, int|string>
	 */
	private static array $context = array();

	/**
	 * Whether capture is switched on.
	 *
	 * @since 1.16.0
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Settings::debug_mode();
	}

	/**
	 * Names the run and step that subsequent entries belong to.
	 *
	 * @since 1.16.0
	 *
	 * @param int    $run_id    Run being executed.
	 * @param int    $prompt_id Prompt it was opened for.
	 * @param string $step      Step about to be performed.
	 * @return void
	 */
	public static function set_context( int $run_id, int $prompt_id, string $step ): void {
		self::$context = array(
			'run'    => $run_id,
			'prompt' => $prompt_id,
			'step'   => $step,
		);
	}

	/**
	 * Forgets the current run and step.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public static function clear_context(): void {
		self::$context = array();
	}

	/**
	 * Appends one entry, when capture is on.
	 *
	 * Nothing here throws and nothing here reports failure. This is a diagnostic
	 * that runs inside a paid pipeline step, and a run must not fail because the
	 * record of why it was failing could not be written.
	 *
	 * @since 1.16.0
	 *
	 * @param string                    $channel One of the CHANNEL_ constants.
	 * @param string                    $subject What the entry is about — a URL, or a label.
	 * @param string                    $body    The raw text to keep.
	 * @param array<string, int|string> $facts   Short named values shown above the body.
	 * @return void
	 */
	public static function record( string $channel, string $subject, string $body, array $facts = array() ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$original = strlen( $body );
		$kept     = self::scrub( $body );

		$entry = array_merge(
			array(
				'time'    => current_time( 'mysql' ),
				'channel' => $channel,
				'subject' => self::scrub( $subject ),
			),
			self::$context,
			$facts,
			array(
				'bytes' => $original,
				'body'  => $kept,
			)
		);

		self::store( $entry );
	}

	/**
	 * Returns every kept entry, oldest first.
	 *
	 * @since 1.16.0
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public static function entries(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Discards everything captured so far.
	 *
	 * @since 1.16.0
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Renders the log as plain text, newest first.
	 *
	 * Newest first because the entry being looked for is the one that just
	 * happened, and a textarea opens at the top.
	 *
	 * @since 1.16.0
	 *
	 * @return string
	 */
	public static function as_text(): string {
		$entries = array_reverse( self::entries() );

		if ( array() === $entries ) {
			return '';
		}

		$blocks = array();

		foreach ( $entries as $entry ) {
			$blocks[] = self::format_entry( is_array( $entry ) ? $entry : array() );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Formats one entry as a header line, a fact line, and the body.
	 *
	 * @since 1.16.0
	 *
	 * @param array<string, int|string> $entry Stored entry.
	 * @return string
	 */
	private static function format_entry( array $entry ): string {
		$body  = (string) ( $entry['body'] ?? '' );
		$facts = array();

		foreach ( $entry as $name => $value ) {
			if ( in_array( $name, array( 'time', 'channel', 'subject', 'body' ), true ) ) {
				continue;
			}

			$facts[] = $name . '=' . $value;
		}

		return sprintf(
			"[%s] %s %s\n%s%s",
			(string) ( $entry['time'] ?? '' ),
			strtoupper( (string) ( $entry['channel'] ?? '' ) ),
			(string) ( $entry['subject'] ?? '' ),
			array() === $facts ? '' : '  ' . implode( '  ', $facts ) . "\n",
			'' === $body ? '  (no body)' : $body
		);
	}

	/**
	 * Writes an entry, trimming the log back inside its limits.
	 *
	 * Reading the log, appending to it, and writing it back is three statements
	 * that have to look like one: Action Scheduler runs a batch of actions at a
	 * time and a site can run several queue runners, so two workers can otherwise
	 * both read the same log and each write back a copy missing the other's
	 * entry. Losing entries is exactly what makes a diagnostic not worth
	 * consulting, so the sequence takes the same named lock the paid sequences
	 * take. Where the lock cannot be had the write still happens: a raced entry
	 * is better than none, and this is a log rather than an accounting record.
	 *
	 * @since 1.16.0
	 *
	 * @param array<string, int|string> $entry Entry to append.
	 * @return void
	 */
	private static function store( array $entry ): void {
		$lock = new Named_Lock( 'debug_log' );

		$lock->acquire();

		try {
			$entries   = self::entries();
			$entries[] = $entry;

			update_option( self::OPTION, self::trim( $entries ), false );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Drops the oldest entries until the log fits both of its limits.
	 *
	 * @since 1.16.0
	 *
	 * @param array<int, array<string, int|string>> $entries Entries, oldest first.
	 * @return array<int, array<string, int|string>>
	 */
	private static function trim( array $entries ): array {
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		// One long entry can exceed the byte ceiling on its own, so the newest is
		// never dropped: a log holding only the exchange just captured is still
		// the exchange being looked for.
		$remaining = count( $entries );
		$size      = strlen( (string) maybe_serialize( $entries ) );

		while ( $remaining > 1 && $size > self::MAX_OPTION_BYTES ) {
			array_shift( $entries );

			--$remaining;

			$size = strlen( (string) maybe_serialize( $entries ) );
		}

		return $entries;
	}

	/**
	 * Removes credentials and binary from a string, then shortens it.
	 *
	 * The order is deliberate. Base64 goes first because an image response is
	 * almost entirely one such field, and blanking it is what leaves room for the
	 * part worth reading. Key-shaped tokens go next: none should be here, since
	 * headers are never recorded, but a provider that echoes a request back inside
	 * an error message would put one in a body, and a log an administrator may
	 * paste into a support thread is the wrong place to find out.
	 *
	 * Every pattern is checked for a null return. PCRE gives back null rather
	 * than a string when it hits a limit, and a scrubber that fails open would
	 * write the unscrubbed text.
	 *
	 * @since 1.16.0
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function scrub( string $text ): string {
		$patterns = array(
			// A long unbroken run of the base64 alphabet is never prose.
			'/[A-Za-z0-9+\/]{300,}={0,2}/'       => '[binary omitted]',
			'/\bsk-(?:ant-)?[A-Za-z0-9_-]{10,}/' => 'sk-[redacted]',
			'/\bAIza[A-Za-z0-9_-]{10,}/'         => 'AIza[redacted]',
			'/(Bearer\s+)[A-Za-z0-9._-]{10,}/i'  => '$1[redacted]',
			'/("(?:api[_-]?key|authorization|x-api-key|x-goog-api-key)"\s*:\s*")[^"]*"/i' => '$1[redacted]"',
		);

		$scrubbed = substr( $text, 0, self::SCRUB_CEILING_BYTES );

		foreach ( $patterns as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $scrubbed );

			if ( null === $result ) {
				return '[omitted: this response could not be scrubbed of credentials, so none of it was kept]';
			}

			$scrubbed = $result;
		}

		if ( strlen( $scrubbed ) <= self::MAX_BODY_BYTES ) {
			return $scrubbed;
		}

		return substr( $scrubbed, 0, self::MAX_BODY_BYTES ) . "\n… [truncated]";
	}
}
