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
 * refused again. Only transient transport-level failures are retried.
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
	 * Error codes that will never succeed on a retry.
	 *
	 * @since 0.4.0
	 * @var string[]
	 */
	private const PERMANENT = array(
		'autoscribe_provider_auth',
		'autoscribe_provider_model_not_found',
		'autoscribe_provider_refusal',
		'autoscribe_key_missing',
		'autoscribe_key_stale',
		'autoscribe_key_corrupt',
		'autoscribe_unknown_provider',
		'autoscribe_unknown_prompt',
		'autoscribe_missing_model',
		'autoscribe_unsafe_body',
		'autoscribe_empty_body',
		'autoscribe_invalid_schedule_parameter',
		'autoscribe_invalid_schedule_type',
		'autoscribe_grounding_unsupported',
		'autoscribe_run_not_recorded',

		/*
		 * These three are outcomes, not faults, and retrying each one costs money
		 * to reach the same answer.
		 *
		 * A duplicate topic has already paid for the two proposal calls section
		 * 7.2 allows; a queue retry pays for two more and the topic is no less
		 * covered than it was. A budget breach will still be a breach in five
		 * minutes, because the total only ever climbs within a month. And a body
		 * that fails validation has already had the single repair section 5.1
		 * permits — letting the queue retry it twice more turns a documented
		 * one-repair limit into six paid calls.
		 */
		'autoscribe_duplicate_topic',
		'autoscribe_budget_exceeded',
		'autoscribe_empty_payload',
		'autoscribe_invalid_json',
		'autoscribe_missing_fields',
		'autoscribe_wrong_types',
		'autoscribe_empty_fields',
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

		return ! in_array( $error->get_error_code(), self::PERMANENT, true );
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
	 * Returns the codes that are never retried.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	public function permanent_codes(): array {
		return self::PERMANENT;
	}
}
