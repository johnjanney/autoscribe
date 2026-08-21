<?php
/**
 * Content sanitiser tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Security;

use AutoScribe\Security\Content_Sanitizer;
use WP_UnitTestCase;

/**
 * Covers section 5.2 sanitisation of untrusted model output.
 *
 * @since 0.3.0
 */
final class Content_SanitizerTest extends WP_UnitTestCase {

	/**
	 * Sanitiser under test.
	 *
	 * @since 0.3.0
	 * @var Content_Sanitizer
	 */
	private Content_Sanitizer $sanitizer;

	/**
	 * Builds the sanitiser.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->sanitizer = new Content_Sanitizer();
	}

	/**
	 * Script contents are removed, not just the surrounding tag.
	 *
	 * The kses pass strips the tag but preserves the text node inside it, which
	 * would leave the script source sitting in the article as plain prose.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_script_contents_are_removed_not_just_tags(): void {
		$output = $this->sanitizer->sanitize_body( '<p>Fine.</p><script>alert("xss")</script>' );

		$this->assertStringNotContainsString( 'alert', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '<p>Fine.</p>', $output );
	}

	/**
	 * Style and iframe contents go the same way.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_style_and_iframe_blocks_are_removed_whole(): void {
		$output = $this->sanitizer->sanitize_body(
			'<style>body{display:none}</style><iframe src="https://evil.example">fallback</iframe><p>Kept.</p>'
		);

		$this->assertStringNotContainsString( 'display:none', $output );
		$this->assertStringNotContainsString( 'evil.example', $output );
		$this->assertStringNotContainsString( 'fallback', $output );
		$this->assertSame( '<p>Kept.</p>', $output );
	}

	/**
	 * A dangerous scheme takes the whole attribute with it.
	 *
	 * Dropping only the scheme would turn href="javascript:alert(1)" into
	 * href="alert(1)", a broken relative link left in published content.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_dangerous_scheme_removes_the_whole_attribute(): void {
		$output = $this->sanitizer->sanitize_body( '<p><a href="javascript:alert(1)">click</a></p>' );

		$this->assertStringNotContainsString( 'javascript', $output );
		$this->assertStringNotContainsString( 'alert(1)', $output );
		$this->assertStringNotContainsString( 'href=', $output );
		$this->assertStringContainsString( 'click', $output );
	}

	/**
	 * Legitimate links survive intact.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_safe_links_survive(): void {
		$output = $this->sanitizer->sanitize_body( '<p><a href="https://example.com">ok</a></p>' );

		$this->assertStringContainsString( 'href="https://example.com"', $output );
	}

	/**
	 * Event handler attributes are removed.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_event_handlers_are_removed(): void {
		$output = $this->sanitizer->sanitize_body( '<p onclick="steal()">text</p>' );

		$this->assertStringNotContainsString( 'onclick', $output );
		$this->assertStringNotContainsString( 'steal', $output );
	}

	/**
	 * Elements outside the section 5.1 contract are dropped.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_elements_outside_the_contract_are_dropped(): void {
		$output = $this->sanitizer->sanitize_body(
			'<h2>Head</h2><table><tr><td>cell</td></tr></table><img src="https://example.com/a.png" alt="x" />'
		);

		$this->assertStringContainsString( '<h2>Head</h2>', $output );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertStringNotContainsString( '<img', $output );
	}

	/**
	 * The contract's own elements are preserved.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_contract_elements_are_preserved(): void {
		$html   = '<h2>A</h2><h3>B</h3><p>C</p><ul><li>D</li></ul><ol><li>E</li></ol><blockquote>F</blockquote>';
		$output = $this->sanitizer->sanitize_body( $html );

		$this->assertSame( $html, $output );
	}

	/**
	 * Length limits are enforced rather than trusted to the model.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_length_limits_are_enforced(): void {
		$long = str_repeat( 'a', 400 );

		$this->assertLessThanOrEqual(
			Content_Sanitizer::SEO_TITLE_MAX,
			mb_strlen( $this->sanitizer->sanitize_seo_title( $long ) )
		);
		$this->assertLessThanOrEqual(
			Content_Sanitizer::META_DESCRIPTION_MAX,
			mb_strlen( $this->sanitizer->sanitize_meta_description( $long ) )
		);
		$this->assertLessThanOrEqual(
			Content_Sanitizer::IMAGE_ALT_MAX,
			mb_strlen( $this->sanitizer->sanitize_image_alt( $long ) )
		);
	}

	/**
	 * A topic key is cut to the width of the column that has to hold it.
	 *
	 * The other limits come from section 5.1 and are about presentation: a meta
	 * description longer than 155 characters is merely truncated by a search
	 * engine. This one is structural. wpdb refuses an over-long value outright
	 * rather than storing a shortened one, and it refuses the whole write, so an
	 * uncapped key cost the run log both the topic and the title, and failed the
	 * run at its final step with the article and the image already paid for.
	 *
	 * @since 1.15.1
	 *
	 * @return void
	 */
	public function test_a_long_topic_key_is_cut_to_the_column_width(): void {
		$key = $this->sanitizer->sanitize_topic_key( str_repeat( 'a-very-long-topic-key-', 20 ) );

		$this->assertLessThanOrEqual(
			Content_Sanitizer::TOPIC_KEY_MAX,
			mb_strlen( $key ),
			'A key wider than the runs table column cannot be recorded at all.'
		);
		$this->assertStringEndsNotWith(
			'-',
			$key,
			'A key cut mid-word must not keep the hyphen that was joining it to the rest.'
		);
	}

	/**
	 * A key that already fits is left exactly as it was.
	 *
	 * @since 1.15.1
	 *
	 * @return void
	 */
	public function test_a_short_topic_key_is_untouched(): void {
		$this->assertSame(
			'how-to-pull-a-good-espresso-shot',
			$this->sanitizer->sanitize_topic_key( 'How to Pull a Good Espresso Shot' ),
			'Slugification is all that a key within the limit needs.'
		);
	}

	/**
	 * The dangerous-URI check reports clean output as clean.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_dangerous_uri_check_is_not_triggered_by_prose(): void {
		$output = $this->sanitizer->sanitize_body(
			'<p>Never use a javascript: URI in an href attribute.</p>'
		);

		$this->assertFalse( $this->sanitizer->has_dangerous_uri( $output ) );
		$this->assertStringContainsString( 'javascript:', $output );
	}
}
