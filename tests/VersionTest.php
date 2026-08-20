<?php
/**
 * Version single-sourcing tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests;

use WP_UnitTestCase;

use const AutoScribe\VERSION;

/**
 * Keeps the plugin header and the VERSION constant in agreement.
 *
 * Section 12 asks for a single source of truth for the version. The header is
 * that source: WordPress reads it, the update system compares it, and the build
 * script names the zip from it. The constant exists because the User-Agent needs
 * the version at runtime and parsing the plugin file on every request to get it
 * would be a real cost for no benefit.
 *
 * Two copies of a value can drift, so rather than pay that runtime cost this
 * test makes the drift impossible to commit: releasing with a bumped header and
 * a stale constant fails the suite.
 *
 * @since 0.6.0
 */
final class VersionTest extends WP_UnitTestCase {

	/**
	 * The header version and the constant are the same string.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public function test_header_and_constant_agree(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( \AutoScribe\PLUGIN_FILE, false, false );

		$this->assertSame(
			VERSION,
			$data['Version'],
			'The plugin header version and AutoScribe\VERSION have drifted apart.'
		);
	}

	/**
	 * The version is a plain dotted release number.
	 *
	 * The build script derives the zip filename from the header, so a version
	 * carrying stray whitespace or a comment would produce a broken artefact.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public function test_version_is_well_formed(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', VERSION );
	}

	/**
	 * The README states the version that is actually shipping.
	 *
	 * The release checklist has always asked for it and it drifted anyway: 1.13.3
	 * shipped with a README still claiming 1.11.0, which is the first thing a
	 * reader checks and the one number they cannot verify for themselves. Two
	 * copies of a value drift unless something compares them, so this compares
	 * them.
	 *
	 * @since 1.13.4
	 *
	 * @return void
	 */
	public function test_the_readme_states_the_shipping_version(): void {
		$readme = (string) file_get_contents( dirname( \AutoScribe\PLUGIN_FILE ) . '/README.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from the plugin directory in a test.

		$this->assertStringContainsString(
			'| **Version** | ' . VERSION . ' |',
			$readme,
			'The README summary table names a different version from the plugin header.'
		);
		$this->assertStringContainsString(
			'Version ' . VERSION . '.',
			$readme,
			'The README status section names a different version from the plugin header.'
		);
	}
}
