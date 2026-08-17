<?php
/**
 * Key store tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Security;

use AutoScribe\Security\Key_Store;
use WP_UnitTestCase;

/**
 * Covers encrypted storage and the salt-rotation failure mode.
 *
 * @since 0.2.0
 */
final class Key_StoreTest extends WP_UnitTestCase {

	/**
	 * Clears stored keys between tests.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Key_Store::OPTION );

		parent::tear_down();
	}

	/**
	 * A key round-trips through encryption unchanged.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_key_round_trips(): void {
		$this->assertTrue( Key_Store::set( 'anthropic', 'sk-ant-secret-value' ) );
		$this->assertSame( Key_Store::SOURCE_STORED, Key_Store::source( 'anthropic' ) );
		$this->assertSame( 'sk-ant-secret-value', Key_Store::get( 'anthropic' ) );
	}

	/**
	 * The plaintext key never appears in the stored option.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_plaintext_is_not_persisted(): void {
		Key_Store::set( 'openai', 'sk-plaintext-must-not-appear' );

		$raw = wp_json_encode( get_option( Key_Store::OPTION ) );

		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'sk-plaintext-must-not-appear', $raw );
	}

	/**
	 * A missing key reports itself rather than returning an empty string.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_missing_key_returns_wp_error(): void {
		$this->assertSame( Key_Store::SOURCE_MISSING, Key_Store::source( 'deepseek' ) );

		$result = Key_Store::get( 'deepseek' );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_key_missing', $result->get_error_code() );
	}

	/**
	 * A key encrypted under rotated salts is reported as stale, not as corrupt.
	 *
	 * Section 8.1 does not cover salt rotation, but WordPress rotates these on
	 * "log out everywhere" and hosts rotate them during migrations. Without the
	 * stored fingerprint the failure is indistinguishable from a wrong key, and
	 * the user is told the connection failed instead of being told to re-enter
	 * the key.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_rotated_salts_are_reported_as_stale(): void {
		Key_Store::set( 'google', 'goog-secret' );

		$records = get_option( Key_Store::OPTION );
		$this->assertIsArray( $records );

		$records['google']['fingerprint'] = 'fingerprint-from-old-salts';
		update_option( Key_Store::OPTION, $records, false );

		$this->assertSame( Key_Store::SOURCE_STALE, Key_Store::source( 'google' ) );

		$result = Key_Store::get( 'google' );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_key_stale', $result->get_error_code() );
	}

	/**
	 * Forgetting a key removes it entirely.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_forget_removes_the_key(): void {
		Key_Store::set( 'openai', 'sk-temp' );
		Key_Store::forget( 'openai' );

		$this->assertSame( Key_Store::SOURCE_MISSING, Key_Store::source( 'openai' ) );
	}

	/**
	 * Constant names follow the pattern documented in section 8.1.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function test_constant_name_pattern(): void {
		$this->assertSame( 'AUTOSCRIBE_ANTHROPIC_KEY', Key_Store::constant_name( 'anthropic' ) );
		$this->assertSame( 'AUTOSCRIBE_OPENAI_IMAGE_KEY', Key_Store::constant_name( 'openai_image' ) );
	}
}
