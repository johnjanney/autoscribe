<?php
/**
 * Cross-field validation for a stored prompt.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Prompts;

use AutoScribe\Providers\Provider_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Corrects a stored prompt that asks for something it cannot do.
 *
 * Two rules live here, and both are about a configuration that reads like a
 * guarantee and cannot keep one: grounding switched on for a provider with no
 * search tool, and fallback image mode with nothing to fall back to. Section 7.1
 * is explicit that a configuration which cannot run must not be saved, and the
 * same reasoning covers section 6's image modes.
 *
 * They used to live inside the prompt editor's own save handler, behind its
 * nonce check — which meant they ran for exactly one of the several ways prompt
 * meta is written. Anything else reached the database with the rules unapplied:
 * `wp post meta update`, an importer, another plugin, a migration script. The
 * 1.2.0 response claimed those paths were covered. They were not, and this class
 * is that claim made true rather than withdrawn.
 *
 * The rules are applied whenever a prompt is saved, and at the end of any request
 * that writes one of the meta keys they read, so the stored configuration is
 * corrected however it arrives. Two things that still cannot promise: a write
 * that bypasses the meta API altogether — a direct SQL statement against
 * wp_postmeta answers to nothing in PHP — and a configuration split across
 * separate requests, where the first half is indistinguishable from a writer
 * that stopped. Runtime enforcement remains the backstop for both, and it is the
 * control that decides what actually gets published.
 *
 * @since 1.3.0
 */
final class Prompt_Validator {

	/**
	 * Meta keys whose value can invalidate another field.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	private const WATCHED = array(
		'image_mode',
		'fallback_image_id',
		'grounding_enabled',
		'text_provider',
	);

	/**
	 * Guard against re-entering while the corrections are being written.
	 *
	 * The corrections are meta writes, and the meta hooks are what call this, so
	 * without it a correction validates the prompt that is mid-correction.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Prompts whose meta changed in this request, to be checked when it ends.
	 *
	 * @since 1.3.0
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * Provider registry, for the grounding capability rule.
	 *
	 * @since 1.3.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Builds the validator.
	 *
	 * @since 1.3.0
	 *
	 * @param Provider_Registry|null $providers Registry, or null to build one.
	 */
	public function __construct( ?Provider_Registry $providers = null ) {
		$this->providers = $providers instanceof Provider_Registry ? $providers : new Provider_Registry();
	}

	/**
	 * Registers the hooks that keep stored prompts consistent.
	 *
	 * Outside the admin check that guards the editor, because the writes this
	 * exists for do not come from the editor.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, array( $this, 'validate' ), 20 );
		add_action( 'added_post_meta', array( $this, 'note_meta_write' ), 20, 3 );
		add_action( 'updated_post_meta', array( $this, 'note_meta_write' ), 20, 3 );

		/*
		 * Deleting a key changes the configuration exactly as writing one does,
		 * and it is the deletion that produces the state these rules exist to
		 * prevent: remove the fallback image ID and fallback mode is left with
		 * nothing to fall back to. The first argument is an array of meta IDs
		 * here rather than a single ID, which is why it is ignored either way.
		 */
		add_action( 'deleted_post_meta', array( $this, 'note_meta_write' ), 20, 3 );
		add_action( 'shutdown', array( $this, 'validate_pending' ), 20 );
	}

	/**
	 * Notes a meta write or deletion worth re-checking, for the end of the request.
	 *
	 * Deferred rather than immediate, and the reason is the order fields arrive
	 * in. A writer setting image mode to fallback and then setting the fallback
	 * image — which is the natural order, and the order the editor's own save loop
	 * uses — is momentarily in a state the rules would correct, and correcting it
	 * there would undo a configuration that was about to become valid one write
	 * later. Waiting until the request ends means the rules see what the writer
	 * actually meant.
	 *
	 * The residual case is a writer that spreads one configuration across separate
	 * requests, which cannot be told apart from a writer that stopped half way.
	 * That is corrected, and the run-time enforcement in Step_Generate_Image is
	 * the control that decides what gets published either way.
	 *
	 * @since 1.3.0
	 *
	 * @param int|int[] $meta_id  Meta row ID, or IDs on a deletion. Unused.
	 * @param int       $post_id  Post the meta belongs to.
	 * @param string    $meta_key Key that was written or removed.
	 * @return void
	 */
	public function note_meta_write( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );

		if ( self::$running || ! in_array( (string) $meta_key, $this->watched_keys(), true ) ) {
			return;
		}

		self::$pending[ (int) $post_id ] = true;
	}

	/**
	 * Validates every prompt whose meta was written during this request.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function validate_pending(): void {
		$pending       = self::$pending;
		self::$pending = array();

		foreach ( array_keys( $pending ) as $post_id ) {
			$this->validate( (int) $post_id );
		}
	}

	/**
	 * Applies every cross-field rule to one stored prompt.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Prompt to correct.
	 * @return string[] Human-readable descriptions of what was corrected.
	 */
	public function validate( $post_id ): array {
		$post_id = (int) $post_id;

		if ( self::$running || Prompt_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return array();
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return array();
		}

		self::$running = true;

		try {
			return array_values(
				array_filter(
					array(
						$this->enforce_grounding_capability( $post_id ),
						$this->enforce_fallback_image( $post_id ),
					)
				)
			);
		} finally {
			self::$running = false;
		}
	}

	/**
	 * Switches grounding off for a provider that cannot search.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Prompt to correct.
	 * @return string Empty when nothing was corrected.
	 */
	private function enforce_grounding_capability( int $post_id ): string {
		if ( '' === (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'grounding_enabled', true ) ) {
			return '';
		}

		$slug     = (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'text_provider', true );
		$provider = $this->providers->text_provider( $slug );

		if ( null === $provider || $provider->supports_web_search() ) {
			return '';
		}

		update_post_meta( $post_id, Prompt_Fields::PREFIX . 'grounding_enabled', false );

		return sprintf(
			/* translators: %s: provider name. */
			__( 'Web search grounding was switched off, because %s does not provide a search tool.', 'autoscribe' ),
			$provider->label()
		);
	}

	/**
	 * Refuses fallback image mode when there is nothing to fall back to.
	 *
	 * Stored as required mode, which is what an unattachable fallback amounts to:
	 * generate the image, and if that cannot be done, leave the post as a draft
	 * for a person rather than publishing it without one. Widening a publication
	 * policy on the site owner's behalf is the one correction this must never
	 * make.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Prompt to correct.
	 * @return string Empty when nothing was corrected.
	 */
	private function enforce_fallback_image( int $post_id ): string {
		if ( 'fallback' !== (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', true ) ) {
			return '';
		}

		if ( self::is_usable_fallback( (int) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'fallback_image_id', true ) ) ) {
			return '';
		}

		update_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', 'required' );

		return __( 'The featured image mode was changed from "fallback" to "required", because the fallback image ID does not name an image in this site\'s media library. Set a valid attachment ID and choose fallback again.', 'autoscribe' );
	}

	/**
	 * Whether an attachment ID names an image this site can attach.
	 *
	 * @since 1.3.0
	 *
	 * @param int $attachment_id Attachment ID from the prompt.
	 * @return bool
	 */
	public static function is_usable_fallback( int $attachment_id ): bool {
		return $attachment_id > 0
			&& 'attachment' === get_post_type( $attachment_id )
			&& wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Returns the prefixed meta keys a write of which triggers validation.
	 *
	 * @since 1.3.0
	 *
	 * @return string[]
	 */
	private function watched_keys(): array {
		return array_map(
			static fn( string $key ): string => Prompt_Fields::PREFIX . $key,
			self::WATCHED
		);
	}
}
