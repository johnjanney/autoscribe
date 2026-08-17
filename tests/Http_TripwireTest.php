<?php
/**
 * Tripwire tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests;

use AutoScribe\Providers\Text\Anthropic;
use AutoScribe\Providers\Request\Generation_Request;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Proves that the "no live API calls" guarantee is enforced, not assumed.
 *
 * Every other test in this suite registers a mock and passes. That on its own
 * would also be true if the tripwire were broken and real requests were being
 * made. This test deliberately omits the mock and asserts that the attempt is
 * intercepted, which is what makes the rest of the suite's silence meaningful.
 *
 * @since 0.2.0
 */
final class Http_TripwireTest extends WP_UnitTestCase {

	/**
	 * An unmocked provider call is intercepted before it leaves the process.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_unmocked_request_is_intercepted(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unmocked HTTP request escaped the test suite' );

		( new Anthropic() )->generate(
			'key',
			'claude-opus-5',
			new Generation_Request( 'system', 'user', 128 )
		);
	}
}
