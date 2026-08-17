<?php
/**
 * Null image adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Image\Null_Image;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers the no-op image provider.
 *
 * This adapter makes no HTTP request, so the goal's "a 401 returns a WP_Error"
 * case does not apply to it. What matters instead is that it never reaches the
 * network at all: because the bootstrap tripwire throws on any unmocked
 * request, these tests passing without a registered mock is itself the proof.
 *
 * @since 0.2.0
 */
final class Null_ImageTest extends Provider_Test_Case {

	/**
	 * Generation returns the distinct skip code, without any HTTP call.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_generate_image_skips_without_touching_the_network(): void {
		$result = ( new Null_Image() )->generate_image( '', '', 'anything' );

		$this->assertWPError( $result );
		$this->assertSame( Null_Image::SKIPPED, $result->get_error_code() );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * The skip code is distinguishable from a genuine provider failure.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_skip_code_differs_from_failure_codes(): void {
		$this->assertNotSame( 'autoscribe_provider_auth', Null_Image::SKIPPED );
		$this->assertNotSame( 'autoscribe_empty_response', Null_Image::SKIPPED );
	}

	/**
	 * There is nothing to misconfigure, so a connection test always succeeds.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_connection_always_succeeds(): void {
		$this->assertTrue( ( new Null_Image() )->test_connection( '', '' ) );
	}
}
