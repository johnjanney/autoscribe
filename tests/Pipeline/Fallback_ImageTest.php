<?php
/**
 * Tests for section 6's fallback image mode.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Prompts\Prompt_Validator;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Generate_Image;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers what fallback mode does when the fallback cannot be attached.
 *
 * Section 6 defines the mode as "attach fallback_image_id, continue and
 * publish", which is a promise that a post in this mode always has a picture.
 * Falling through to no image when the fallback will not attach turns it into
 * optional mode without saying so, in exactly the case the mode was chosen for.
 *
 * @since 1.2.0
 */
final class Fallback_ImageTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Re-arms the tripwire between tests.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A fallback ID of zero fails the run rather than publishing bare.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_missing_fallback_id_fails_the_run(): void {
		$result = $this->attach_with( 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_fallback_image_missing', $result->get_error_code() );
	}

	/**
	 * A fallback naming an attachment that has been deleted does the same.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_deleted_fallback_attachment_fails_the_run(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		wp_delete_post( $attachment, true );

		$result = $this->attach_with( (int) $attachment );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_fallback_image_missing', $result->get_error_code() );
	}

	/**
	 * A thumbnail write WordPress refuses does the same.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_refused_thumbnail_write_fails_the_run(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$refuse = static function ( $value, $object_id, $meta_key ) {
			return '_thumbnail_id' === $meta_key ? false : $value;
		};

		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		$result = $this->attach_with( (int) $attachment );

		remove_filter( 'update_post_metadata', $refuse, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_fallback_image_missing', $result->get_error_code() );
	}

	/**
	 * A usable fallback is attached and the run carries on, as section 6 says.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_usable_fallback_is_attached(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$post_id = self::factory()->post->create();
		$result  = $this->attach_with( (int) $attachment, $post_id );

		$this->assertSame( (int) $attachment, $result );
		$this->assertSame( (int) $attachment, get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * Fallback mode cannot be saved without an image to fall back to.
	 *
	 * The editor's control is a courtesy; the REST API, WP-CLI, and an import all
	 * reach the save path without seeing it. A mode that cannot do what it says is
	 * stored as the strictest thing it can do instead, which is required mode.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_fallback_mode_is_not_stored_without_a_usable_image(): void {
		$this->assertFalse( Prompt_Validator::is_usable_fallback( 0 ) );
		$this->assertFalse( Prompt_Validator::is_usable_fallback( 999999 ) );

		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertTrue( Prompt_Validator::is_usable_fallback( (int) $attachment ) );

		$post = self::factory()->post->create();

		$this->assertFalse(
			Prompt_Validator::is_usable_fallback( (int) $post ),
			'A post is not an image, however valid its ID.'
		);
	}

	/**
	 * Runs the image step in fallback mode with the provider refusing to answer.
	 *
	 * Generation failing is the precondition for fallback mode doing anything at
	 * all, so every test here starts from a provider that will not produce an
	 * image.
	 *
	 * @since 1.2.0
	 *
	 * @param int $fallback_id Attachment to fall back to.
	 * @param int $post_id     Post to attach to, or 0 to create one.
	 * @return int|\WP_Error
	 */
	private function attach_with( int $fallback_id, int $post_id = 0 ) {
		Key_Store::set( 'openai', 'test-key' );
		$this->mock_provider_failure( 500 );

		$prompt_id = $this->create_prompt(
			array(
				'image_mode'        => 'fallback',
				'image_provider'    => 'openai',
				'image_model'       => 'gpt-image-2',
				'fallback_image_id' => $fallback_id,
			)
		);

		$run = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$article = ( new Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$step = new Step_Generate_Image( new Provider_Registry() );

		return $step->attach(
			Prompt::load( $prompt_id ),
			$article,
			$run,
			$post_id > 0 ? $post_id : (int) self::factory()->post->create()
		);
	}
}
