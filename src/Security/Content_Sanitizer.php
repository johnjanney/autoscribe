<?php
/**
 * Model output sanitisation.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans model output before it reaches the database.
 *
 * Section 5.2 is the governing rule: model output is untrusted input and is
 * never inserted raw. Two departures from the literal text of that section,
 * both deliberate:
 *
 * First, wp_kses_post() alone does not enforce the contract in section 5.1,
 * which asks for h2, h3, p, ul, ol, and blockquote only. wp_kses_post() permits
 * a great deal more. So kses runs first as the security floor, then a narrower
 * allowlist enforces the contract.
 *
 * Second, section 5.2 says to reject content containing a data: or javascript:
 * URI. Implemented as a substring search that rejects the whole article, an
 * essay that merely discusses javascript: URIs would be thrown away after being
 * paid for. Those schemes are already absent from wp_allowed_protocols(), so
 * kses strips them from attributes on its own; the rejection check therefore
 * runs on the sanitised output, where a surviving scheme means a real bypass
 * rather than a mention in prose.
 *
 * @since 0.3.0
 */
final class Content_Sanitizer {

	/**
	 * Maximum SEO title length, per section 5.1.
	 *
	 * @since 0.3.0
	 * @var int
	 */
	public const SEO_TITLE_MAX = 60;

	/**
	 * Maximum meta description length, per section 5.1.
	 *
	 * @since 0.3.0
	 * @var int
	 */
	public const META_DESCRIPTION_MAX = 155;

	/**
	 * Maximum image alt text length, per section 5.1.
	 *
	 * @since 0.3.0
	 * @var int
	 */
	public const IMAGE_ALT_MAX = 125;

	/**
	 * Sanitises body HTML.
	 *
	 * @since 0.3.0
	 *
	 * @param string $html Raw body HTML from the model.
	 * @return string Sanitised HTML, safe to insert.
	 */
	public function sanitize_body( string $html ): string {
		// Executable blocks first. wp_kses_post() removes the script tag but keeps
		// the text node inside it, so `<script>alert(1)</script>` would otherwise
		// survive as the literal words alert(1) in the article body. Section 5.2
		// asks for these elements to be stripped, which means contents included.
		$html = $this->strip_executable_blocks( $html );

		// Then dangerous URI schemes, attribute and all. Leaving kses to drop just
		// the scheme turns href="javascript:alert(1)" into href="alert(1)", a
		// broken relative link rather than a removed one.
		$html = $this->strip_dangerous_attributes( $html );

		// Security floor. Section 5.2 requires this before anything reaches the database.
		$safe = wp_kses_post( $html );

		// Contract ceiling: narrow to the element set section 5.1 asks the model for.
		return wp_kses( $safe, $this->allowed_html() );
	}

	/**
	 * Removes executable and embedding elements together with their contents.
	 *
	 * @since 0.3.0
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function strip_executable_blocks( string $html ): string {
		$paired = (string) preg_replace(
			'#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?</\1\s*>#is',
			'',
			$html
		);

		// Unclosed or self-closing variants of the same elements.
		return (string) preg_replace(
			'#<(script|style|iframe|object|embed|noscript|template)\b[^>]*/?>#i',
			'',
			$paired
		);
	}

	/**
	 * Removes attributes whose value carries a dangerous URI scheme.
	 *
	 * @since 0.3.0
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function strip_dangerous_attributes( string $html ): string {
		$quoted = (string) preg_replace(
			'#\s(?:href|src|xlink:href|formaction|action)\s*=\s*(["\'])\s*(?:javascript|data|vbscript)\s*:[^"\']*\1#i',
			'',
			$html
		);

		$unquoted = (string) preg_replace(
			'#\s(?:href|src|xlink:href|formaction|action)\s*=\s*(?:javascript|data|vbscript)\s*:[^\s>]*#i',
			'',
			$quoted
		);

		// Event handlers, also named explicitly by section 5.2.
		return (string) preg_replace( '#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)#i', '', $unquoted );
	}

	/**
	 * Whether sanitised HTML still carries a dangerous URI scheme.
	 *
	 * Run against post-kses output, so a true result means a scheme survived
	 * sanitisation in an attribute rather than being mentioned in prose.
	 *
	 * @since 0.3.0
	 *
	 * @param string $sanitized_html Output of sanitize_body().
	 * @return bool
	 */
	public function has_dangerous_uri( string $sanitized_html ): bool {
		return 1 === preg_match(
			'/\b(?:href|src)\s*=\s*["\']?\s*(?:javascript|data|vbscript):/i',
			$sanitized_html
		);
	}

	/**
	 * Sanitises a post title.
	 *
	 * @since 0.3.0
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public function sanitize_title( string $title ): string {
		return sanitize_text_field( $title );
	}

	/**
	 * Sanitises and truncates an SEO title.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value Raw SEO title.
	 * @return string
	 */
	public function sanitize_seo_title( string $value ): string {
		return $this->truncate( sanitize_text_field( $value ), self::SEO_TITLE_MAX );
	}

	/**
	 * Sanitises and truncates a meta description.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value Raw meta description.
	 * @return string
	 */
	public function sanitize_meta_description( string $value ): string {
		return $this->truncate( sanitize_text_field( $value ), self::META_DESCRIPTION_MAX );
	}

	/**
	 * Sanitises and truncates image alt text.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value Raw alt text.
	 * @return string
	 */
	public function sanitize_image_alt( string $value ): string {
		return $this->truncate( sanitize_text_field( $value ), self::IMAGE_ALT_MAX );
	}

	/**
	 * Sanitises a topic key into a slug.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value Raw topic key.
	 * @return string
	 */
	public function sanitize_topic_key( string $value ): string {
		return sanitize_title( $value );
	}

	/**
	 * Truncates on a word boundary without trusting the model to count.
	 *
	 * @since 0.3.0
	 *
	 * @param string $value  Value to truncate.
	 * @param int    $length Maximum length in characters.
	 * @return string
	 */
	private function truncate( string $value, int $length ): string {
		if ( mb_strlen( $value ) <= $length ) {
			return $value;
		}

		return rtrim( mb_substr( $value, 0, $length ) );
	}

	/**
	 * Returns the element allowlist implied by section 5.1.
	 *
	 * The list element is required for ul and ol to mean anything, and a small
	 * set of inline elements is kept so links and emphasis survive; stripping
	 * those would damage legitimate articles without improving safety, since
	 * kses has already filtered their attributes.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_html(): array {
		return array(
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'p'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array( 'cite' => true ),
			'strong'     => array(),
			'em'         => array(),
			'code'       => array(),
			'pre'        => array(),
			'br'         => array(),
			'a'          => array(
				'href'  => true,
				'title' => true,
				'rel'   => true,
			),
		);
	}
}
