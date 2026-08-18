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
		foreach ( $this->policy->permanent_codes() as $code ) {
			$this->assertFalse(
				$this->policy->should_retry( new WP_Error( $code, 'x' ), 1 ),
				$code
			);
		}
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
