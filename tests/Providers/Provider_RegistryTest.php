<?php
/**
 * Provider registry tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Providers\Image_Provider_Interface;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Text_Provider_Interface;
use AutoScribe\Tests\Support\Provider_Test_Case;

/**
 * Covers slug resolution and the text/image separation.
 *
 * @since 0.2.0
 */
final class Provider_RegistryTest extends Provider_Test_Case {

	/**
	 * All four text providers from section 2.1 resolve by slug.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_every_text_provider_resolves(): void {
		$registry = new Provider_Registry();

		foreach ( array( 'anthropic', 'openai', 'google', 'deepseek' ) as $slug ) {
			$this->assertInstanceOf( Text_Provider_Interface::class, $registry->text_provider( $slug ) );
		}

		$this->assertCount( 4, $registry->text_providers() );
	}

	/**
	 * All three image providers resolve by slug.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_every_image_provider_resolves(): void {
		$registry = new Provider_Registry();

		foreach ( array( 'openai_image', 'google_image', 'none' ) as $slug ) {
			$this->assertInstanceOf( Image_Provider_Interface::class, $registry->image_provider( $slug ) );
		}

		$this->assertCount( 3, $registry->image_providers() );
	}

	/**
	 * Section 2.1: neither Anthropic nor DeepSeek can generate images.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_text_only_providers_are_absent_from_the_image_map(): void {
		$registry = new Provider_Registry();

		$this->assertNull( $registry->image_provider( 'anthropic' ) );
		$this->assertNull( $registry->image_provider( 'deepseek' ) );
	}

	/**
	 * An unknown slug resolves to null rather than throwing.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_unknown_slug_returns_null(): void {
		$registry = new Provider_Registry();

		$this->assertNull( $registry->text_provider( 'not-a-provider' ) );
		$this->assertNull( $registry->image_provider( 'not-a-provider' ) );
	}
}
