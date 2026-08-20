<?php
/**
 * Grounding source URL extraction.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the source URLs out of a grounded provider response.
 *
 * Section 7.1 requires the URLs a grounded call used to be recorded. The three
 * providers that offer grounding each report them differently, and nest them at
 * different depths:
 *
 * - Anthropic returns web_search_result blocks, and web_search_result_location
 *   citations attached to the text blocks that cite them.
 * - OpenAI returns url_citation annotations on the output message content.
 * - Google returns url_citation annotations inside the steps of an Interactions
 *   response, and groundingChunks with a web.uri on the older generateContent
 *   shape. Both are recognised: the legacy shape is kept because a site can be
 *   pointed at either surface, and because dropping a parser costs source URLs
 *   silently.
 *
 * Rather than three parsers that each break the next time a provider adds a
 * wrapper level, this walks the decoded body and picks up any node that both
 * carries a URL and identifies itself as a citation or search result. Being
 * tolerant is the right trade here: a source that goes unrecognised costs an
 * incomplete audit list, while a parser that hard-fails on an unexpected shape
 * would cost the whole article.
 *
 * @since 0.8.0
 */
final class Source_Extractor {

	/**
	 * Type-value fragments that mark a node as a citation or search result.
	 *
	 * @since 0.8.0
	 * @var string[]
	 */
	private const TYPE_MARKERS = array( 'citation', 'web_search_result' );

	/**
	 * Parent keys whose children hold sources even without a type field.
	 *
	 * @since 0.8.0
	 * @var string[]
	 */
	private const SOURCE_PARENTS = array( 'web', 'citations', 'annotations', 'groundingChunks' );

	/**
	 * Returns every source URL found in a decoded response body.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $decoded Decoded response body.
	 * @return string[] Unique URLs, in the order they were found.
	 */
	public static function from( array $decoded ): array {
		$urls = array();

		self::walk( $decoded, null, $urls );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Recursively collects source URLs.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $node       Node being inspected.
	 * @param string|null          $parent_key Nearest named ancestor key.
	 * @param string[]             $urls       Accumulator, by reference.
	 * @return void
	 */
	private static function walk( array $node, ?string $parent_key, array &$urls ): void {
		if ( self::is_source_node( $node, $parent_key ) ) {
			$url = (string) ( $node['url'] ?? $node['uri'] ?? '' );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			// A numeric key is a list index, so the meaningful name is the one
			// above it. Without this, items inside groundingChunks would be seen
			// as having parent "0" rather than "groundingChunks".
			self::walk( $value, is_string( $key ) ? $key : $parent_key, $urls );
		}
	}

	/**
	 * Whether a node represents a cited source.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $node       Node being inspected.
	 * @param string|null          $parent_key Nearest named ancestor key.
	 * @return bool
	 */
	private static function is_source_node( array $node, ?string $parent_key ): bool {
		if ( ! isset( $node['url'] ) && ! isset( $node['uri'] ) ) {
			return false;
		}

		$type = isset( $node['type'] ) && is_string( $node['type'] ) ? $node['type'] : '';

		foreach ( self::TYPE_MARKERS as $marker ) {
			if ( '' !== $type && str_contains( $type, $marker ) ) {
				return true;
			}
		}

		return null !== $parent_key && in_array( $parent_key, self::SOURCE_PARENTS, true );
	}
}
