<?php
/**
 * Retry policy for failed runs.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a failed run is worth attempting again, and when.
 *
 * This class exists because section 2.4 of the brief is wrong about Action
 * Scheduler. It states that Action Scheduler gives you "retries with backoff".
 * It does not. When a callback throws, Action Scheduler marks the action failed,
 * logs it, and stops; there is no automatic retry and no backoff. WooCommerce
 * builds its own retry logic on top of the same library.
 *
 * Following section 5 literally would therefore produce a plugin that attempts
 * every run exactly once and then gives up, while appearing to implement three
 * attempts. Retry is built here instead, and the attempt counter is the
 * runs.attempt column section 3.2 already provides.
 *
 * Not every failure deserves a retry. A revoked API key will be just as revoked
 * in five minutes, and retrying a content-safety rejection spends money to be
 * refused again. Only transient transport-level failures are retried, and the
 * TRANSIENT list is what decides that: an unrecognised code is permanent.
 *
 * @since 0.4.0
 */
final class Retry_Policy {

	/**
	 * Maximum attempts per run, per section 5.
	 *
	 * @since 0.4.0
	 * @var int
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Error codes that are worth trying again.
	 *
	 * This is an allowlist, and the direction matters. Until 1.0.2 it was a
	 * denylist of permanent codes, so anything not yet named was retried by
	 * default — including codes that had not been written when the list was
	 * drawn up. The class had always claimed the opposite in its own comment,
	 * and the claim was the safer of the two: a new failure mode that costs a
	 * paid call is retried three times before anyone notices the omission, while
	 * a new transient one merely fails a run that was going to fail anyway.
	 *
	 * Only transport-level failures qualify. A network fault, a rate limit, and
	 * a provider outage all describe a call that never produced an answer and
	 * plausibly will in five minutes. Everything else — a rejected request, a
	 * refusal, a bad key, an unusable response, a local write failure, and every
	 * outcome that has already been paid for — is permanent, whether or not it is
	 * named here.
	 *
	 * @since 1.0.2
	 * @var string[]
	 */
	private const TRANSIENT = array(
		'autoscribe_transport_error',
		'autoscribe_provider_rate_limited',
		'autoscribe_provider_unavailable',
	);

	/**
	 * Whether a failed attempt should be tried again.
	 *
	 * @since 0.4.0
	 *
	 * @param WP_Error $error   The failure.
	 * @param int      $attempt The attempt number that just failed, starting at 1.
	 * @return bool
	 */
	public function should_retry( WP_Error $error, int $attempt ): bool {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			return false;
		}

		return in_array( $error->get_error_code(), $this->transient_codes(), true );
	}

	/**
	 * Returns the delay before the next attempt, in seconds.
	 *
	 * Exponential with a floor, so a provider that is briefly overloaded is not
	 * hammered and a transient network fault still recovers within the hour.
	 *
	 * @since 0.4.0
	 *
	 * @param int $attempt The attempt number that just failed, starting at 1.
	 * @return int
	 */
	public function delay_seconds( int $attempt ): int {
		$delays = array(
			1 => 5 * MINUTE_IN_SECONDS,
			2 => 30 * MINUTE_IN_SECONDS,
		);

		return $delays[ $attempt ] ?? HOUR_IN_SECONDS;
	}

	/**
	 * Returns the codes that are worth trying again.
	 *
	 * Filterable because a provider can start returning a transport-level
	 * failure under a code this plugin does not yet know, and waiting for a
	 * release to retry it is worse than letting a site say so itself. Adding a
	 * code here is a decision to spend money on a repeat call, so the default
	 * list stays short.
	 *
	 * @since 1.0.2
	 *
	 * @return string[]
	 */
	public function transient_codes(): array {
		/**
		 * Filters the error codes treated as transient and therefore retryable.
		 *
		 * @since 1.0.2
		 *
		 * @param string[] $codes Error codes worth retrying.
		 */
		$codes = apply_filters( 'autoscribe_transient_error_codes', self::TRANSIENT );

		return is_array( $codes ) ? array_map( 'strval', $codes ) : self::TRANSIENT;
	}
}
