<?php
/**
 * Translation template coverage tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests;

use WP_UnitTestCase;

/**
 * Keeps languages/autoscribe.pot in step with the strings in the plugin.
 *
 * Section 12 asks for every user-facing string to be wrapped and for the .pot to
 * be generated with WP-CLI. Both were done, and then the template went stale
 * anyway: regenerating it lives in the release checklist in CONTRIBUTING.md, and
 * a checklist is only as good as the last person to read it. By 1.0.5 the
 * template still carried the 1.0.0 string set — twenty-nine strings added across
 * four releases were missing, including every notification email, the whole
 * health panel, all the image validation errors, and the untrusted-data blocks,
 * and two strings that no longer exist were still asking to be translated.
 *
 * A localised site showed those twenty-nine in English with no way for a
 * translator to fix it, because the string was not in the template they work
 * from.
 *
 * So the checklist is not the guard any more; this is. It reads every
 * translation call in src/ and asserts the literal appears as a msgid. Nothing
 * here regenerates anything or asserts the file is byte-identical to a fresh
 * build — the header carries a timestamp and the plugin version, so that test
 * would fail on every release for no reason. It asserts the only property that
 * matters to a translator: if the plugin can say it, the template contains it.
 *
 * @since 1.0.5
 */
final class TranslationTemplateTest extends WP_UnitTestCase {

	/**
	 * Translation functions whose first argument is a translatable literal.
	 *
	 * _n() and _nx() are absent deliberately: they take two literals and the
	 * plugin does not currently use either, so supporting them here would be
	 * untested code guarding against nothing.
	 *
	 * @since 1.0.5
	 * @var string[]
	 */
	private const FUNCTIONS = array(
		'__',
		'_e',
		'_x',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
	);

	/**
	 * Every translatable string in the plugin is in the template.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function test_every_translatable_string_is_in_the_template(): void {
		$template = $this->template_msgids();
		$missing  = array();

		foreach ( $this->source_strings() as $string => $where ) {
			if ( ! isset( $template[ $string ] ) ) {
				$missing[] = $where . ': ' . $string;
			}
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			"These strings are translatable in the code but absent from languages/autoscribe.pot.\n"
			. "Regenerate it — see CONTRIBUTING.md — and commit the result:\n\n"
			. implode( "\n", $missing ) . "\n"
		);
	}

	/**
	 * The template declares the plugin's own text domain.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public function test_the_template_declares_the_text_domain(): void {
		$this->assertStringContainsString(
			'"X-Domain: autoscribe\\n"',
			$this->template(),
			'The template must be generated for the autoscribe text domain.'
		);
	}

	/**
	 * Returns every translatable literal in src/, mapped to where it came from.
	 *
	 * Literals broken across lines by string concatenation are skipped rather
	 * than half-read: a partial literal would be reported as missing and the
	 * failure would be a lie. The plugin has none, and the assertion below keeps
	 * it that way by failing loudly if the whole scan ever comes back empty.
	 *
	 * @since 1.0.5
	 *
	 * @return array<string, string> Literal mapped to "file:line".
	 */
	private function source_strings(): array {
		$pattern = '/\b(?:' . implode( '|', self::FUNCTIONS ) . ")\(\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/";
		$found   = array();

		foreach ( $this->source_files() as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES );

			foreach ( $lines as $number => $line ) {
				if ( ! preg_match_all( $pattern, $line, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $literal ) {
					$found[ $this->unquote( $literal ) ] = basename( dirname( $file ) ) . '/' . basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertNotEmpty( $found, 'The scanner found no translatable strings at all, which means it is broken.' );

		return $found;
	}

	/**
	 * Returns every PHP file under src/, plus the bootstrap.
	 *
	 * @since 1.0.5
	 *
	 * @return string[]
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/autoscribe.php', $root . '/uninstall.php' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( 'php' === $entry->getExtension() ) {
				$files[] = $entry->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Returns every msgid in the template, as a set.
	 *
	 * Handles the multi-line form gettext uses for long strings, where the msgid
	 * opens with an empty string and continues on the lines below.
	 *
	 * @since 1.0.5
	 *
	 * @return array<string, true>
	 */
	private function template_msgids(): array {
		$msgids  = array();
		$current = null;

		foreach ( explode( "\n", $this->template() ) as $line ) {
			$line = trim( $line );

			if ( str_starts_with( $line, 'msgid ' ) ) {
				$current = $this->unquote( substr( $line, 6 ) );

				continue;
			}

			if ( null !== $current && str_starts_with( $line, '"' ) ) {
				$current .= $this->unquote( $line );

				continue;
			}

			if ( null !== $current ) {
				$msgids[ $current ] = true;
				$current            = null;
			}
		}

		if ( null !== $current ) {
			$msgids[ $current ] = true;
		}

		return $msgids;
	}

	/**
	 * Returns the template's contents.
	 *
	 * @since 1.0.5
	 *
	 * @return string
	 */
	private function template(): string {
		$path = dirname( __DIR__ ) . '/languages/autoscribe.pot';

		$this->assertFileExists( $path, 'The translation template is missing.' );

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Strips the quotes from a PHP or gettext string literal and unescapes it.
	 *
	 * @since 1.0.5
	 *
	 * @param string $literal Quoted literal.
	 * @return string
	 */
	private function unquote( string $literal ): string {
		$literal = trim( $literal );
		$quote   = $literal[0] ?? '"';
		$inner   = substr( $literal, 1, -1 );

		if ( "'" === $quote ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
		}

		// \$ matters as much as \n here: a double-quoted PHP literal escapes the
		// dollar in a placeholder like %1\$s, and the extracted msgid does not.
		return str_replace(
			array( '\\"', '\\n', '\\t', '\\$', '\\\\' ),
			array( '"', "\n", "\t", '$', '\\' ),
			$inner
		);
	}
}
