<?php
/**
 * Structured output validation.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Content;

use AutoScribe\Diagnostics\Debug_Log;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw model response into a validated Article.
 *
 * Section 5.1 instructs the model to return one JSON object and nothing else,
 * then says to strip any fences defensively, decode, and validate. All three
 * happen here. The error messages are written to be fed back to the model as
 * the single repair request, so they name the specific problem rather than
 * saying the response was invalid.
 *
 * @since 0.3.0
 */
final class Article_Validator {

	/**
	 * Fields the contract requires, mapped to their expected type.
	 *
	 * @since 0.3.0
	 * @var array<string, string>
	 */
	private const REQUIRED = array(
		'title'            => 'string',
		'topic_key'        => 'string',
		'excerpt'          => 'string',
		'content_html'     => 'string',
		'seo_title'        => 'string',
		'meta_description' => 'string',
		'focus_keyword'    => 'string',
		'suggested_tags'   => 'array',
		'image_prompt'     => 'string',
		'image_alt'        => 'string',
	);

	/**
	 * Returns the JSON Schema describing the contract.
	 *
	 * Handed to providers that support schema-constrained output so the shape is
	 * enforced upstream rather than only checked after the fact.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$properties = array();

		foreach ( self::REQUIRED as $field => $type ) {
			$properties[ $field ] = 'array' === $type
				? array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				)
				: array( 'type' => 'string' );
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( self::REQUIRED ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Validates a raw model response.
	 *
	 * @since 0.3.0
	 *
	 * @param string $raw Raw text returned by the provider.
	 * @return Article|WP_Error Article on success, error naming the fault otherwise.
	 */
	public function validate( string $raw ): Article|WP_Error {
		$article = $this->parse( $raw );

		/*
		 * The message this returns is written for the repair request and for the
		 * run log, so it names the fault and quotes at most a fragment of what
		 * caused it. That is the right size for both and it is not enough to
		 * diagnose a model that has started answering in the wrong shape, where
		 * the question is what it actually said. Debug_Log keeps that text when an
		 * administrator has asked for it, and keeps nothing otherwise.
		 *
		 * It is the extracted model text rather than the provider envelope Http
		 * records, which is both shorter and the thing the schema was applied to.
		 */
		if ( is_wp_error( $article ) ) {
			Debug_Log::record(
				Debug_Log::CHANNEL_CONTENT,
				$article->get_error_message(),
				$raw,
				array( 'code' => $article->get_error_code() )
			);
		}

		return $article;
	}

	/**
	 * Strips fences, decodes, and validates, without recording anything.
	 *
	 * @since 1.16.0
	 *
	 * @param string $raw Raw text returned by the provider.
	 * @return Article|WP_Error Article on success, error naming the fault otherwise.
	 */
	private function parse( string $raw ): Article|WP_Error {
		$json = $this->strip_fences( $raw );

		if ( '' === $json ) {
			return new WP_Error(
				'autoscribe_empty_payload',
				__( 'The response was empty. Return one JSON object and nothing else.', 'autoscribe' )
			);
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'autoscribe_invalid_json',
				sprintf(
					/* translators: %s: JSON decoding error message. */
					__( 'The response was not valid JSON (%s). Return one JSON object and nothing else, with no Markdown fences.', 'autoscribe' ),
					json_last_error_msg()
				)
			);
		}

		return $this->from_array( $decoded );
	}

	/**
	 * Validates already-decoded fields and builds an article from them.
	 *
	 * Split out of validate() so that an article can be rebuilt from storage
	 * without going back through a JSON string. The queue steps in section 5
	 * hand their work to each other through runs.payload, so the article has to
	 * survive a round trip out of the database — and the class invariant has to
	 * survive it too. An Article exists only where the schema was satisfied, and
	 * a payload row that was truncated, hand-edited, or written by an older
	 * version of this plugin is exactly the case where that stops being true on
	 * its own. Rebuilding therefore re-validates rather than trusting the store.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $decoded Decoded payload fields.
	 * @return Article|WP_Error Article on success, error naming the fault otherwise.
	 */
	public function from_array( array $decoded ): Article|WP_Error {
		$missing = array();
		$wrong   = array();

		foreach ( self::REQUIRED as $field => $type ) {
			if ( ! array_key_exists( $field, $decoded ) ) {
				$missing[] = $field;
				continue;
			}

			if ( 'array' === $type && ! is_array( $decoded[ $field ] ) ) {
				$wrong[] = $field;
			}

			if ( 'string' === $type && ! is_string( $decoded[ $field ] ) ) {
				$wrong[] = $field;
			}
		}

		if ( array() !== $missing ) {
			return new WP_Error(
				'autoscribe_missing_fields',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'These required fields were missing: %s. Return the complete object.', 'autoscribe' ),
					implode( ', ', $missing )
				)
			);
		}

		if ( array() !== $wrong ) {
			return new WP_Error(
				'autoscribe_wrong_types',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'These fields had the wrong type: %s. suggested_tags must be an array of strings; every other field must be a string.', 'autoscribe' ),
					implode( ', ', $wrong )
				)
			);
		}

		if ( '' === trim( (string) $decoded['title'] ) || '' === trim( (string) $decoded['content_html'] ) ) {
			return new WP_Error(
				'autoscribe_empty_fields',
				__( 'title and content_html must not be empty.', 'autoscribe' )
			);
		}

		return new Article( $decoded );
	}

	/**
	 * Removes Markdown code fences and surrounding prose.
	 *
	 * Section 5.1 asks for bare JSON but also says to strip fences defensively,
	 * because models add them even when told not to. If a fenced block is
	 * present its contents win; otherwise the outermost braces are taken.
	 *
	 * @since 0.3.0
	 *
	 * @param string $raw Raw response text.
	 * @return string Candidate JSON, or an empty string when none was found.
	 */
	private function strip_fences( string $raw ): string {
		$text = trim( $raw );

		if ( '' === $text ) {
			return '';
		}

		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $text, $matches ) ) {
			$text = trim( $matches[1] );
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return '';
		}

		return substr( $text, $start, ( $end - $start ) + 1 );
	}
}
