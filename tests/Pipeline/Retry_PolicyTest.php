<?php
/**
 * Retry policy tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Retry_Policy;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers the retry behaviour section 2.4 wrongly attributes to Action Scheduler.
 *
 * @since 0.4.0
 */
final class Retry_PolicyTest extends WP_UnitTestCase {

	/**
	 * Policy under test.
	 *
	 * @since 0.4.0
	 * @var Retry_Policy
	 */
	private Retry_Policy $policy;

	/**
	 * Builds the policy.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->policy = new Retry_Policy();
	}

	/**
	 * A transient failure is retried.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_transient_failures_are_retried(): void {
		foreach ( array( 'autoscribe_provider_rate_limited', 'autoscribe_provider_unavailable', 'autoscribe_transport_error' ) as $code ) {
			$this->assertTrue(
				$this->policy->should_retry( new WP_Error( $code, 'x' ), 1 ),
				$code
			);
		}
	}

	/**
	 * A permanent failure is never retried.
	 *
	 * A revoked key is just as revoked in five minutes, and retrying a refusal
	 * spends money to be refused again.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_permanent_failures_are_not_retried(): void {
		$codes = array(
			'autoscribe_provider_auth',
			'autoscribe_provider_model_not_found',
			'autoscribe_provider_refusal',
			'autoscribe_provider_error',
			'autoscribe_key_missing',
			'autoscribe_key_stale',
			'autoscribe_key_unsafe',
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
			'autoscribe_reservation_failed',
			'autoscribe_image_too_large',
			'autoscribe_image_invalid',
			'autoscribe_upload_failed',
		);

		foreach ( $codes as $code ) {
			$this->assertFalse(
				$this->policy->should_retry( new WP_Error( $code, 'x' ), 1 ),
				$code
			);
		}
	}

	/**
	 * A code nobody has classified is permanent, not retryable.
	 *
	 * This is the whole point of the 1.0.2 change from a denylist to an
	 * allowlist. Under the old list an error code that had not been thought of
	 * yet — including every code added by a later release, and every code a
	 * provider starts returning without warning — was retried three times,
	 * paying for the same answer each time.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_an_unknown_code_is_not_retried(): void {
		$this->assertFalse(
			$this->policy->should_retry( new WP_Error( 'autoscribe_something_nobody_has_written_yet', 'x' ), 1 )
		);
	}

	/**
	 * A site can add a transient code without waiting for a release.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_the_transient_list_is_filterable(): void {
		$filter = static function ( array $codes ): array {
			$codes[] = 'autoscribe_something_nobody_has_written_yet';

			return $codes;
		};

		add_filter( 'autoscribe_transient_error_codes', $filter );

		$retried = $this->policy->should_retry( new WP_Error( 'autoscribe_something_nobody_has_written_yet', 'x' ), 1 );

		remove_filter( 'autoscribe_transient_error_codes', $filter );

		$this->assertTrue( $retried );
	}

	/**
	 * Outcomes that cost money to repeat are never retried.
	 *
	 * These three used to fall through to the transient branch. A duplicate topic
	 * paid for two more proposals per retry; an exhausted validation repair paid
	 * for two more full calls, turning section 5.1's documented one-repair limit
	 * into six paid calls; and a budget breach re-ran a check whose answer only
	 * ever moves one way within a month.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_paid_outcomes_are_not_retried(): void {
		$codes = array(
			'autoscribe_duplicate_topic',
			'autoscribe_budget_exceeded',
			'autoscribe_invalid_json',
			'autoscribe_missing_fields',
			'autoscribe_wrong_types',
			'autoscribe_empty_fields',
			'autoscribe_empty_payload',
			'autoscribe_grounding_unsupported',
		);

		foreach ( $codes as $code ) {
			$this->assertFalse(
				$this->policy->should_retry( new WP_Error( $code, 'x' ), 1 ),
				$code . ' must not be retried'
			);
		}
	}

	/**
	 * Attempts stop at the section 5 cap of three.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_attempts_are_capped(): void {
		$error = new WP_Error( 'autoscribe_provider_unavailable', 'x' );

		$this->assertTrue( $this->policy->should_retry( $error, 1 ) );
		$this->assertTrue( $this->policy->should_retry( $error, 2 ) );
		$this->assertFalse( $this->policy->should_retry( $error, Retry_Policy::MAX_ATTEMPTS ) );
		$this->assertFalse( $this->policy->should_retry( $error, 4 ) );
	}

	/**
	 * Backoff grows between attempts.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function test_backoff_increases(): void {
		$first  = $this->policy->delay_seconds( 1 );
		$second = $this->policy->delay_seconds( 2 );

		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( $first, $second );
	}
}
