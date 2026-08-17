<?php
/**
 * Article validator tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Content;

use AutoScribe\Content\Article_Validator;
use WP_UnitTestCase;

/**
 * Covers the section 5.1 structured output contract.
 *
 * @since 0.3.0
 */
final class Article_ValidatorTest extends WP_UnitTestCase {

	/**
	 * Validator under test.
	 *
	 * @since 0.3.0
	 * @var Article_Validator
	 */
	private Article_Validator $validator;

	/**
	 * Builds the validator.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->validator = new Article_Validator();
	}

	/**
	 * Returns a complete, valid payload.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, mixed>
	 */
	private function valid_payload(): array {
		return array(
			'title'            => 'A Title',
			'topic_key'        => 'a-title',
			'excerpt'          => 'An excerpt.',
			'content_html'     => '<p>Body.</p>',
			'seo_title'        => 'SEO title',
			'meta_description' => 'Meta description.',
			'focus_keyword'    => 'keyword',
			'suggested_tags'   => array( 'one', 'two' ),
			'image_prompt'     => 'A photograph.',
			'image_alt'        => 'Alt text.',
		);
	}

	/**
	 * A well-formed payload validates.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_valid_payload_is_accepted(): void {
		$article = $this->validator->validate( (string) wp_json_encode( $this->valid_payload() ) );

		$this->assertNotWPError( $article );
		$this->assertSame( 'A Title', $article->title() );
		$this->assertSame( array( 'one', 'two' ), $article->suggested_tags() );
	}

	/**
	 * Markdown fences are stripped defensively, per section 5.1.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_fenced_json_is_accepted(): void {
		$fenced = "Here you go:\n```json\n" . wp_json_encode( $this->valid_payload() ) . "\n```\nHope that helps.";

		$article = $this->validator->validate( $fenced );

		$this->assertNotWPError( $article );
		$this->assertSame( 'A Title', $article->title() );
	}

	/**
	 * A missing field is named in the error, so the repair request can be specific.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_missing_field_is_named(): void {
		$payload = $this->valid_payload();
		unset( $payload['image_alt'] );

		$result = $this->validator->validate( (string) wp_json_encode( $payload ) );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_missing_fields', $result->get_error_code() );
		$this->assertStringContainsString( 'image_alt', $result->get_error_message() );
	}

	/**
	 * A wrongly typed field is named too.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_wrong_type_is_named(): void {
		$payload                   = $this->valid_payload();
		$payload['suggested_tags'] = 'not-an-array';

		$result = $this->validator->validate( (string) wp_json_encode( $payload ) );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_wrong_types', $result->get_error_code() );
		$this->assertStringContainsString( 'suggested_tags', $result->get_error_message() );
	}

	/**
	 * Undecodable output is reported as such.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_non_json_is_rejected(): void {
		$result = $this->validator->validate( 'I am afraid I cannot do that.' );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_empty_payload', $result->get_error_code() );
	}

	/**
	 * An empty title or body is rejected even when the shape is right.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_empty_required_values_are_rejected(): void {
		$payload          = $this->valid_payload();
		$payload['title'] = '   ';

		$result = $this->validator->validate( (string) wp_json_encode( $payload ) );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_empty_fields', $result->get_error_code() );
	}

	/**
	 * The published schema covers every required field.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function test_schema_lists_every_required_field(): void {
		$schema = Article_Validator::schema();

		foreach ( array_keys( $this->valid_payload() ) as $field ) {
			$this->assertContains( $field, $schema['required'] );
			$this->assertArrayHasKey( $field, $schema['properties'] );
		}
	}
}
