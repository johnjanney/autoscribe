<?php
/**
 * Normalised token usage.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Response;

defined( 'ABSPATH' ) || exit;

/**
 * One token-usage shape for every provider.
 *
 * Section 14 of the brief flags that the providers disagree here, and they do:
 * Anthropic reports usage.input_tokens and usage.output_tokens, OpenAI's
 * Responses API matches that while its Chat Completions API uses prompt_tokens
 * and completion_tokens, Google's Interactions API uses
 * usage.total_input_tokens and usage.total_output_tokens, and DeepSeek follows
 * the OpenAI Chat shape. Each adapter normalises into this object so section
 * 7.4 cost accounting has a single shape to read.
 *
 * @since 0.2.0
 */
final class Usage {

	/**
	 * Prompt tokens billed.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private int $input_tokens;

	/**
	 * Generated tokens billed.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private int $output_tokens;

	/**
	 * Builds a usage record.
	 *
	 * @since 0.2.0
	 *
	 * @param int $input_tokens  Prompt tokens billed.
	 * @param int $output_tokens Generated tokens billed.
	 */
	public function __construct( int $input_tokens = 0, int $output_tokens = 0 ) {
		$this->input_tokens  = max( 0, $input_tokens );
		$this->output_tokens = max( 0, $output_tokens );
	}

	/**
	 * Returns the prompt token count.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function input_tokens(): int {
		return $this->input_tokens;
	}

	/**
	 * Returns the generated token count.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function output_tokens(): int {
		return $this->output_tokens;
	}
}
