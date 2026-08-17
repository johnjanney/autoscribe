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
}
