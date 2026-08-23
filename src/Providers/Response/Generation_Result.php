<?php
/**
 * Result of a successful text generation call.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral generation result.
 *
 * @since 0.2.0
 */
final class Generation_Result {

	/**
	 * Generated text.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $text;

	/**
	 * Token usage for the call.
	 *
	 * @since 0.2.0
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Model identifier the provider reported serving the request.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $model;

	/**
	 * Source URLs the provider grounded against, if any.
	 *
	 * @since 0.2.0
	 * @var string[]
	 */
	private array $sources;

	/**
	 * Why the provider stopped early, or an empty string when it did not.
	 *
	 * A provider that reaches its output ceiling mid-sentence returns HTTP 200
	 * and says so in a field of its own — Google's `status`, Anthropic's
	 * `stop_reason`, OpenAI's `status`, DeepSeek's `finish_reason`. Until 1.17.0
	 * none of the four adapters read that field, so a cut-off answer arrived here
	 * as ordinary text and failed further down as "the response was empty" or
	 * "not valid JSON" — a description of the wreckage rather than of what
	 * happened. The reason travels with the result so the step that made the call
	 * can say the true thing, and so it can stop instead of paying for a repair
	 * request that will be cut off in the same place.
	 *
	 * @since 1.17.0
	 * @var string
	 */
	private string $incomplete_reason;

	/**
	 * Builds a result.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $text              Generated text.
	 * @param Usage    $usage             Token usage.
	 * @param string   $model             Model that served the request.
	 * @param string[] $sources           Grounding source URLs.
	 * @param string   $incomplete_reason Provider's own word for stopping early, or '' when it finished.
	 */
	public function __construct( string $text, Usage $usage, string $model, array $sources = array(), string $incomplete_reason = '' ) {
		$this->text              = $text;
		$this->usage             = $usage;
		$this->model             = $model;
		$this->sources           = $sources;
		$this->incomplete_reason = $incomplete_reason;
	}

	/**
	 * Returns the generated text.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Returns the token usage.
	 *
	 * @since 0.2.0
	 *
	 * @return Usage
	 */
	public function usage(): Usage {
		return $this->usage;
	}

	/**
	 * Returns the serving model identifier.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Returns the grounding source URLs.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Whether the provider stopped before it had finished answering.
	 *
	 * @since 1.17.0
	 *
	 * @return bool
	 */
	public function is_incomplete(): bool {
		return '' !== $this->incomplete_reason;
	}

	/**
	 * Returns the provider's own word for stopping early.
	 *
	 * @since 1.17.0
	 *
	 * @return string Empty when the provider reported a finished answer.
	 */
	public function incomplete_reason(): string {
		return $this->incomplete_reason;
	}
}
