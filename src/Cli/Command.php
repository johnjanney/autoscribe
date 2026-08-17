<?php
/**
 * WP-CLI commands.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Cli;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Providers\Provider_Registry;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes AutoScribe to WP-CLI.
 *
 * @since 0.3.0
 */
final class Command {

	/**
	 * Provider registry.
	 *
	 * @since 0.3.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $registry;

	/**
	 * Builds the command.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Provider_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Runs one prompt end to end and creates a post.
	 *
	 * ## OPTIONS
	 *
	 * <prompt-id>
	 * : ID of the autoscribe_prompt post to run.
	 *
	 * [--status=<status>]
	 * : Final post status, overriding the prompt's own setting.
	 * ---
	 * options:
	 *   - draft
	 *   - pending
	 *   - publish
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp autoscribe run 42 --status=publish
	 *
	 * @since 0.3.0
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		$prompt_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$status    = isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : null;

		if ( $prompt_id <= 0 ) {
			WP_CLI::error( 'A prompt ID is required.' );
		}

		$result = ( new Generator( $this->registry ) )->run( $prompt_id, $status );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error(
				sprintf( '%s (%s)', $result->get_error_message(), $result->get_error_code() )
			);
		}

		WP_CLI::log( sprintf( 'Run ID:        %d', $result['run_id'] ) );
		WP_CLI::log( sprintf( 'Post ID:       %d', $result['post_id'] ) );
		WP_CLI::log( sprintf( 'Attachment ID: %d', $result['attachment_id'] ) );
		WP_CLI::log( sprintf( 'Post status:   %s', $result['status'] ) );

		WP_CLI::success( sprintf( 'Generated post %d.', $result['post_id'] ) );
	}
}
