<?php
/**
 * Verified post meta writes.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Writes post meta and confirms it is really there.
 *
 * `update_post_meta()` returns false for two unrelated situations: the write was
 * refused, and the value stored already matched. Code that treats its return
 * value as an answer therefore either ignores real failures or invents imaginary
 * ones, so every adapter here reads the value back instead.
 *
 * Shared rather than repeated because all four adapters write the same three
 * values the same way, and a verification each of them implements separately is
 * a verification three of them will eventually stop doing.
 *
 * @since 1.2.0
 */
final class Meta_Writer {

	/**
	 * Writes several meta values and returns whether all of them landed.
	 *
	 * @since 1.2.0
	 *
	 * @param int                   $post_id Post to write to.
	 * @param array<string, string> $values  Meta values keyed by meta key.
	 * @return bool
	 */
	public static function write( int $post_id, array $values ): bool {
		$written = true;

		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, $value );

			if ( (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
				$written = false;
			}
		}

		return $written;
	}
}
