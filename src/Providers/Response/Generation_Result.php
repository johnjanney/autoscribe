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
	 * Builds a result.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $text    Generated text.
	 * @param Usage    $usage   Token usage.
	 * @param string   $model   Model that served the request.
	 * @param string[] $sources Grounding source URLs.
	 */
	public function __construct( string $text, Usage $usage, string $model, array $sources = array() ) {
		$this->text    = $text;
		$this->usage   = $usage;
		$this->model   = $model;
		$this->sources = $sources;
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
}
