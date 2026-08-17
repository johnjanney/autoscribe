<?php
/**
 * Provider mocking helper for tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Answers provider HTTP calls with controllable payloads.
 *
 * The suite installs a tripwire that throws on any unmocked request, so every
 * test touching the pipeline needs one of these. Keeping it in one place stops
 * each test file inventing its own slightly different Anthropic response shape,
 * which is how a mock ends up asserting against something the real adapter
 * would never accept.
 *
 * @since 0.8.0
 */
trait Mocks_Provider {

	/**
	 * The registered mock, so it can be removed cleanly.
	 *
	 * @since 0.8.0
	 * @var callable|null
	 */
	private $provider_mock = null;

	/**
	 * Every request captured while the mock was active.
	 *
	 * @since 0.8.0
	 * @var array<int, array<string, mixed>>
	 */
	private array $provider_requests = array();

	/**
	 * Removes the mock, re-arming the tripwire.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	protected function remove_provider_mock(): void {
		if ( null !== $this->provider_mock ) {
			remove_filter( 'pre_http_request', $this->provider_mock, 10 );

			$this->provider_mock = null;
		}
	}

	/**
	 * Returns the requests captured so far.
	 *
	 * @since 0.8.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function captured_requests(): array {
		return $this->provider_requests;
	}

	/**
	 * Returns a complete, valid article payload.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	protected function article_payload( array $overrides = array() ): array {
		return array_merge(
			array(
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
			),
			$overrides
		);
	}

	/**
	 * Answers the proposal call and the body call with valid payloads.
	 *
	 * The two are told apart by the token ceiling: the proposal call asks for
	 * 512 and the body call asks for more, which is the same distinction the
	 * pipeline itself relies on.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $article  Article payload, or empty for the default.
	 * @param array<string, mixed> $extra    Extra top-level keys merged into the response body.
	 * @return void
	 */
	protected function mock_provider_success( array $article = array(), array $extra = array() ): void {
		$article  = array() === $article ? $this->article_payload() : $article;
		$proposal = array(
			'title'     => (string) $article['title'],
			'topic_key' => (string) $article['topic_key'],
		);

		$this->install_responder(
			function ( $args ) use ( $article, $proposal, $extra ) {
				$body    = json_decode( (string) $args['body'], true );
				$payload = ( isset( $body['max_tokens'] ) && 512 === (int) $body['max_tokens'] )
					? $proposal
					: $article;

				return $this->anthropic_response( $payload, $extra );
			}
		);
	}

	/**
	 * Answers every call with an HTTP failure.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $code Status code to return.
	 * @param string $body Response body.
	 * @return void
	 */
	protected function mock_provider_failure( int $code, string $body = '{"error":{"message":"upstream"}}' ): void {
		$this->install_responder(
			static function ( $args ) use ( $code, $body ) {
				unset( $args );

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => 'Error',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	/**
	 * Builds an Anthropic-shaped success response.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $payload The JSON object the model "returned".
	 * @param array<string, mixed> $extra   Extra top-level keys.
	 * @return array<string, mixed>
	 */
	protected function anthropic_response( array $payload, array $extra = array() ): array {
		$content = array(
			array(
				'type' => 'text',
				'text' => (string) wp_json_encode( $payload ),
			),
		);

		if ( isset( $extra['content'] ) && is_array( $extra['content'] ) ) {
			$content = array_merge( $extra['content'], $content );

			unset( $extra['content'] );
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array_merge(
					array(
						'content' => $content,
						'usage'   => array(
							'input_tokens'  => 100,
							'output_tokens' => 400,
						),
					),
					$extra
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

	/**
	 * Registers a responder against pre_http_request.
	 *
	 * Public to the test rather than private, so a test needing a shape this
	 * trait does not model can supply its own responder and still get the
	 * request capture and teardown.
	 *
	 * @since 0.8.0
	 *
	 * @param callable $responder Receives the request args, returns a response array.
	 * @return void
	 */
	protected function install_responder( callable $responder ): void {
		$this->remove_provider_mock();

		$this->provider_mock = function ( $preempt, $args, $url ) use ( $responder ) {
			unset( $preempt );

			$this->provider_requests[] = array(
				'url'  => $url,
				'body' => json_decode( (string) $args['body'], true ),
			);

			return $responder( $args );
		};

		add_filter( 'pre_http_request', $this->provider_mock, 10, 3 );
	}
}
