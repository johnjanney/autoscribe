<?php
/**
 * Web search grounding tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Response\Source_Extractor;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers section 7.1: source capture, the optional Sources list, and the
 * capability flag that stops a provider being asked for search it does not have.
 *
 * @since 0.8.0
 */
final class GroundingTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Content blocks an Anthropic grounded response carries.
	 *
	 * @since 0.8.0
	 * @var array<int, array<string, mixed>>
	 */
	private const SEARCH_BLOCKS = array(
		array(
			'type'    => 'web_search_tool_result',
			'content' => array(
				array(
					'type'  => 'web_search_result',
					'url'   => 'https://example.com/water-chemistry',
					'title' => 'Water chemistry',
				),
				array(
					'type'  => 'web_search_result',
					'url'   => 'https://example.org/extraction',
					'title' => 'Extraction',
				),
			),
		),
	);

	/**
	 * Provides the API key the pipeline needs.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Removes the mock so the tripwire is armed again.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Anthropic search results are recognised.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_extractor_reads_anthropic_search_results(): void {
		$urls = Source_Extractor::from( array( 'content' => self::SEARCH_BLOCKS ) );

		$this->assertSame(
			array( 'https://example.com/water-chemistry', 'https://example.org/extraction' ),
			$urls
		);
	}

	/**
	 * Anthropic citations attached to text blocks are recognised.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_extractor_reads_anthropic_citations(): void {
		$urls = Source_Extractor::from(
			array(
				'content' => array(
					array(
						'type'      => 'text',
						'text'      => 'Body.',
						'citations' => array(
							array(
								'type' => 'web_search_result_location',
								'url'  => 'https://example.com/cited',
							),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'https://example.com/cited' ), $urls );
	}

	/**
	 * OpenAI url_citation annotations are recognised.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_extractor_reads_openai_annotations(): void {
		$urls = Source_Extractor::from(
			array(
				'output' => array(
					array(
						'content' => array(
							array(
								'type'        => 'output_text',
								'text'        => 'Body.',
								'annotations' => array(
									array(
										'type' => 'url_citation',
										'url'  => 'https://example.com/openai',
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'https://example.com/openai' ), $urls );
	}

	/**
	 * Google grounding chunks are recognised.
	 *
	 * These carry no type field at all, which is why the extractor also keys off
	 * the parent name.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_extractor_reads_google_grounding_chunks(): void {
		$urls = Source_Extractor::from(
			array(
				'candidates' => array(
					array(
						'groundingMetadata' => array(
							'groundingChunks' => array(
								array(
									'web' => array(
										'uri'   => 'https://example.com/google',
										'title' => 'Google result',
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'https://example.com/google' ), $urls );
	}

	/**
	 * An ordinary response yields no sources.
	 *
	 * The extractor walks the whole body, so this guards against it treating
	 * any URL-shaped value as a citation.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_extractor_finds_nothing_in_an_ungrounded_response(): void {
		$urls = Source_Extractor::from(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Body mentioning https://example.com/inline',
					),
				),
				'links'   => array( 'url' => 'https://example.com/unrelated' ),
			)
		);

		$this->assertSame( array(), $urls );
	}

	/**
	 * Duplicate URLs are recorded once.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_duplicate_sources_are_collapsed(): void {
		$urls = Source_Extractor::from(
			array(
				'content' => array(
					array(
						'type'    => 'web_search_tool_result',
						'content' => array(
							array(
								'type' => 'web_search_result',
								'url'  => 'https://example.com/same',
							),
							array(
								'type' => 'web_search_result',
								'url'  => 'https://example.com/same',
							),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'https://example.com/same' ), $urls );
	}

	/**
	 * A grounded run records its sources on the run row.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_sources_are_persisted_on_the_run(): void {
		$this->mock_provider_success( array(), array( 'content' => self::SEARCH_BLOCKS ) );

		$prompt_id = $this->create_prompt( array( 'grounding_enabled' => 1 ) );

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$this->assertNotWPError( $result );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );

		$payload = json_decode( (string) $row['payload'], true );

		$this->assertIsArray( $payload );
		$this->assertSame(
			array( 'https://example.com/water-chemistry', 'https://example.org/extraction' ),
			$payload['sources']
		);
	}

	/**
	 * The Sources list is appended only when the prompt asks for it.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_sources_list_is_appended_when_requested(): void {
		$this->mock_provider_success( array(), array( 'content' => self::SEARCH_BLOCKS ) );

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt(
				array(
					'grounding_enabled' => 1,
					'append_sources'    => 1,
				)
			)
		);

		$this->assertNotWPError( $result );

		$content = (string) get_post_field( 'post_content', (int) $result['post_id'] );

		$this->assertStringContainsString( 'Sources', $content );
		$this->assertStringContainsString( 'https://example.com/water-chemistry', $content );
		$this->assertStringContainsString( 'https://example.org/extraction', $content );
	}

	/**
	 * Without the setting, no Sources list appears even when sources exist.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_sources_list_is_omitted_by_default(): void {
		$this->mock_provider_success( array(), array( 'content' => self::SEARCH_BLOCKS ) );

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'grounding_enabled' => 1 ) )
		);

		$this->assertNotWPError( $result );

		$content = (string) get_post_field( 'post_content', (int) $result['post_id'] );

		$this->assertStringNotContainsString( 'Sources', $content );
		$this->assertStringNotContainsString( 'example.com', $content );
	}

	/**
	 * Asking for the list with no sources adds nothing.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_an_empty_source_list_appends_nothing(): void {
		$this->mock_provider_success();

		$result = ( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'append_sources' => 1 ) )
		);

		$this->assertNotWPError( $result );

		$this->assertStringNotContainsString(
			'Sources',
			(string) get_post_field( 'post_content', (int) $result['post_id'] )
		);
	}

	/**
	 * A grounded prompt sends the provider's search tool.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_grounded_request_carries_the_search_tool(): void {
		$this->mock_provider_success();

		( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt( array( 'grounding_enabled' => 1 ) )
		);

		$body = (string) wp_json_encode( $this->captured_requests() );

		$this->assertStringContainsString( 'web_search', $body );
	}

	/**
	 * DeepSeek is never asked for search, since it has none.
	 *
	 * Section 7.1 says the capability flag must stop a configuration that
	 * cannot run. Sending a search tool to a provider that rejects unknown
	 * tools would fail the whole article rather than degrade.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_deepseek_is_never_sent_a_search_tool(): void {
		Key_Store::set( 'deepseek', 'test-key' );

		$this->install_deepseek_mock();

		( new Generator( new Provider_Registry() ) )->run(
			$this->create_prompt(
				array(
					'text_provider'     => 'deepseek',
					'text_model'        => 'deepseek-chat',
					'grounding_enabled' => 1,
				)
			)
		);

		$this->assertNotEmpty( $this->captured_requests() );

		$body = (string) wp_json_encode( $this->captured_requests() );

		$this->assertStringNotContainsString( 'web_search', $body );
	}

	/**
	 * Answers DeepSeek's chat-completions shape.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	private function install_deepseek_mock(): void {
		$article  = $this->article_payload();
		$proposal = array(
			'title'     => $article['title'],
			'topic_key' => $article['topic_key'],
		);

		$this->install_responder(
			static function ( $args ) use ( $article, $proposal ) {
				$body = json_decode( (string) $args['body'], true );

				$payload = ( isset( $body['max_tokens'] ) && 512 === (int) $body['max_tokens'] )
					? $proposal
					: $article;

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'choices' => array(
								array( 'message' => array( 'content' => (string) wp_json_encode( $payload ) ) ),
							),
							'usage'   => array(
								'prompt_tokens'     => 100,
								'completion_tokens' => 400,
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
			}
		);
	}
}
