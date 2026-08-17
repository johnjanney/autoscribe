<?php
/**
 * Provider-neutral description of one text generation call.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Carries what the pipeline wants, not how any one provider expresses it.
 *
 * Deliberately holds no sampling parameters. Anthropic rejects temperature,
 * top_p, top_k, and budget_tokens with an HTTP 400 on its current models, so a
 * shared request object carrying them would make every Claude call fail. Each
 * adapter translates this object into its own wire format instead.
 *
 * @since 0.2.0
 */
final class Generation_Request {

	/**
	 * Persona and rules for the model.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $system_prompt;

	/**
	 * The instruction describing what to write.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $user_prompt;

	/**
	 * Upper bound on generated tokens.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private int $max_output_tokens;

	/**
	 * JSON Schema the response must satisfy, or null for free text.
	 *
	 * @since 0.2.0
	 * @var array<string, mixed>|null
	 */
	private ?array $json_schema;

	/**
	 * Whether the provider should ground the answer with a web search.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	private bool $grounding;

	/**
	 * Builds a request.
	 *
	 * @since 0.2.0
	 *
	 * @param string                    $system_prompt     Persona and rules.
	 * @param string                    $user_prompt       Topic instruction.
	 * @param int                       $max_output_tokens Upper bound on generated tokens.
	 * @param array<string, mixed>|null $json_schema       Schema, or null for free text.
	 * @param bool                      $grounding         Whether to request web grounding.
	 */
	public function __construct(
		string $system_prompt,
		string $user_prompt,
		int $max_output_tokens = 4096,
		?array $json_schema = null,
		bool $grounding = false
	) {
		$this->system_prompt     = $system_prompt;
		$this->user_prompt       = $user_prompt;
		$this->max_output_tokens = $max_output_tokens;
		$this->json_schema       = $json_schema;
		$this->grounding         = $grounding;
	}

	/**
	 * Returns the system prompt.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function system_prompt(): string {
		return $this->system_prompt;
	}

	/**
	 * Returns the user prompt.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function user_prompt(): string {
		return $this->user_prompt;
	}

	/**
	 * Returns the maximum number of generated tokens.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function max_output_tokens(): int {
		return $this->max_output_tokens;
	}

	/**
	 * Returns the requested JSON Schema, if any.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>|null
	 */
	public function json_schema(): ?array {
		return $this->json_schema;
	}

	/**
	 * Whether a schema was requested.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function wants_json(): bool {
		return null !== $this->json_schema;
	}

	/**
	 * Whether web grounding was requested.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function wants_grounding(): bool {
		return $this->grounding;
	}
}
