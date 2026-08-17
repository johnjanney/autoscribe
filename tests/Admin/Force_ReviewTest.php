<?php
/**
 * Global human-review override tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Admin;

use AutoScribe\Admin\Settings;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers section 10's global override.
 *
 * The brief calls this the safety catch: one switch that stops every prompt
 * publishing, for the moment a provider changes behaviour or a prompt starts
 * producing garbage. A catch that only applies to prompts already set to review
 * would protect nothing, so these tests drive a prompt explicitly configured to
 * publish and assert it does not.
 *
 * @since 0.7.0
 */
final class Force_ReviewTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * The registered mock, so it can be removed cleanly.
	 *
	 * @since 0.7.0
	 * @var callable|null
	 */
	private $mock;

	/**
	 * Provides the API key and clears the override.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->mock = null;

		Key_Store::set( 'anthropic', 'test-key' );

		delete_option( Settings::OPTION );
	}

	/**
	 * Removes the mock so the tripwire is armed again.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );

			$this->mock = null;
		}

		parent::tear_down();
	}

	/**
	 * Answers the proposal call and the body call with valid payloads.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function mock_provider(): void {
		$proposal = array(
			'title'     => 'Water Hardness And Extraction',
			'topic_key' => 'water-hardness-and-extraction',
		);

		$article = array(
			'title'            => 'Water Hardness And Extraction',
			'topic_key'        => 'water-hardness-and-extraction',
			'excerpt'          => 'How mineral content changes what ends up in the cup.',
			'content_html'     => '<h2>Minerals</h2><p>Magnesium pulls sweetness forward.</p>',
			'seo_title'        => 'Water Hardness And Extraction',
			'meta_description' => 'How mineral content changes extraction.',
			'focus_keyword'    => 'water hardness',
			'suggested_tags'   => array( 'water' ),
			'image_prompt'     => 'A glass of water beside a portafilter.',
			'image_alt'        => 'Water and a portafilter.',
		);

		$this->mock = static function ( $preempt, $args, $url ) use ( $proposal, $article ) {
			unset( $preempt, $url );

			$body = json_decode( (string) $args['body'], true );

			// The proposal call asks for 512 tokens; the body call asks for more.
			$payload = ( isset( $body['max_tokens'] ) && 512 === (int) $body['max_tokens'] )
				? $proposal
				: $article;

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => (string) wp_json_encode( $payload ),
							),
						),
						'usage'   => array(
							'input_tokens'  => 100,
							'output_tokens' => 400,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	/**
	 * Without the override, an auto prompt publishes.
	 *
	 * This is the control. Without it the override test would pass even if the
	 * pipeline had stopped publishing for some unrelated reason.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_auto_publishes_when_the_override_is_off(): void {
		$this->mock_provider();

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'post_status_mode' => 'auto' ) )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'publish', get_post_status( (int) $result['post_id'] ) );
	}

	/**
	 * With the override on, the same prompt produces a draft.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_override_turns_an_auto_prompt_into_a_draft(): void {
		Settings::save( array( 'force_review' => true ) );

		$this->assertTrue( Settings::force_review() );

		$this->mock_provider();

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'post_status_mode' => 'auto' ) )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post_status( (int) $result['post_id'] ) );
	}

	/**
	 * The override also beats an explicit caller-supplied status.
	 *
	 * The WP-CLI command accepts a status argument. If that could step around
	 * the override then the safety catch would be one command away from being
	 * bypassed, which is not a safety catch.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_override_beats_an_explicit_status_override(): void {
		Settings::save( array( 'force_review' => true ) );

		$this->mock_provider();

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'post_status_mode' => 'auto' ) ),
			'publish'
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'draft', get_post_status( (int) $result['post_id'] ) );
	}
}
