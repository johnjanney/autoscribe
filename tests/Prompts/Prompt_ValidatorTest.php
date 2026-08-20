<?php
/**
 * Tests for cross-field prompt validation.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Prompts;

use AutoScribe\Prompts\Prompt_Fields;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Prompts\Prompt_Validator;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the rules that stop a prompt claiming something it cannot do.
 *
 * They used to live inside the editor's save handler, behind its nonce check, so
 * they applied to the one write path that has a person in front of it and to
 * none of the others. A prompt written by WP-CLI, an importer, or another plugin
 * reached the database with fallback mode set and nothing to fall back to.
 *
 * @since 1.3.0
 */
final class Prompt_ValidatorTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Fallback mode without a usable image is stored as required mode.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_fallback_without_an_image_becomes_required(): void {
		$prompt_id = $this->create_prompt(
			array(
				'image_mode'        => 'fallback',
				'fallback_image_id' => 0,
			)
		);

		$corrected = ( new Prompt_Validator() )->validate( $prompt_id );

		$this->assertCount( 1, $corrected );
		$this->assertSame(
			'required',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', true ),
			'A mode that cannot keep its promise is stored as the strictest one that can.'
		);
	}

	/**
	 * Fallback mode with a usable image is left exactly as it is.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_usable_fallback_is_left_alone(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$prompt_id = $this->create_prompt(
			array(
				'image_mode'        => 'fallback',
				'fallback_image_id' => $attachment,
			)
		);

		$this->assertSame( array(), ( new Prompt_Validator() )->validate( $prompt_id ) );
		$this->assertSame(
			'fallback',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', true )
		);
	}

	/**
	 * Grounding is switched off for a provider that cannot search.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_grounding_is_switched_off_for_a_provider_without_search(): void {
		$prompt_id = $this->create_prompt(
			array(
				'text_provider'     => 'deepseek',
				'grounding_enabled' => 1,
			)
		);

		$corrected = ( new Prompt_Validator() )->validate( $prompt_id );

		$this->assertCount( 1, $corrected );
		$this->assertSame(
			'',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'grounding_enabled', true ),
			'DeepSeek has no search tool, so the setting cannot be stored as on.'
		);
	}

	/**
	 * A prompt written programmatically is corrected when the request ends.
	 *
	 * This is the path the 1.2.0 response claimed was covered and was not: meta
	 * written directly, with no editor and no nonce anywhere in sight.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_direct_meta_write_is_corrected_at_the_end_of_the_request(): void {
		$validator = new Prompt_Validator();

		$validator->register();

		$prompt_id = $this->create_prompt( array( 'image_mode' => 'optional' ) );

		// What `wp post meta update` does, and nothing else.
		update_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', 'fallback' );

		$this->assertSame(
			'fallback',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', true ),
			'Nothing is corrected mid-request, so a writer can set its fields in any order.'
		);

		$validator->validate_pending();

		$this->assertSame(
			'required',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', true )
		);

		remove_action( 'added_post_meta', array( $validator, 'note_meta_write' ), 20 );
		remove_action( 'updated_post_meta', array( $validator, 'note_meta_write' ), 20 );
		remove_action( 'shutdown', array( $validator, 'validate_pending' ), 20 );
		remove_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, array( $validator, 'validate' ), 20 );
	}

	/**
	 * A writer that sets its fields in the natural order is not fought.
	 *
	 * Correcting on each meta write rather than at the end of the request would
	 * downgrade the mode in the moment between setting it and setting the image
	 * it refers to — which is the order the editor's own save loop writes in.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_configuration_written_field_by_field_survives(): void {
		$validator = new Prompt_Validator();

		$validator->register();

		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$prompt_id = $this->create_prompt( array( 'image_mode' => 'optional' ) );

		update_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', 'fallback' );
		update_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'fallback_image_id', $attachment );

		$validator->validate_pending();

		$this->assertSame(
			'fallback',
			(string) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'image_mode', true ),
			'The rules judge the configuration a writer finished, not one it was half way through.'
		);

		remove_action( 'added_post_meta', array( $validator, 'note_meta_write' ), 20 );
		remove_action( 'updated_post_meta', array( $validator, 'note_meta_write' ), 20 );
		remove_action( 'shutdown', array( $validator, 'validate_pending' ), 20 );
		remove_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, array( $validator, 'validate' ), 20 );
	}

	/**
	 * Nothing outside the prompt post type is touched.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_other_post_types_are_left_alone(): void {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', 'fallback' );

		$this->assertSame( array(), ( new Prompt_Validator() )->validate( $post_id ) );
		$this->assertSame(
			'fallback',
			(string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', true )
		);
	}
}
