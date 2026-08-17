<?php
/**
 * Prompt custom post type.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type that stores saved prompts.
 *
 * Section 3.2 of the brief chooses a custom post type over a custom table: the
 * row count stays small, and CRUD, the list table, revisions, and nonce
 * handling all come for free.
 *
 * @since 0.1.0
 */
final class Prompt_Post_Type {

	/**
	 * Post type key.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const POST_TYPE = 'autoscribe_prompt';

	/**
	 * Singular capability base for the generated capability family.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const CAPABILITY_SINGULAR = 'autoscribe_prompt';

	/**
	 * Plural capability base for the generated capability family.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const CAPABILITY_PLURAL = 'autoscribe_prompts';

	/**
	 * Registers the post type with WordPress.
	 *
	 * Must run on init or later. The labels call translation functions, and
	 * WordPress 6.7 and newer warn when translations are requested earlier.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type( self::POST_TYPE, $this->arguments() );
	}

	/**
	 * Builds the register_post_type() argument array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function arguments(): array {
		return array(
			'labels'              => $this->labels(),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-edit-large',
			'menu_position'       => 30,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'supports'            => array( 'title', 'revisions' ),
			'capability_type'     => array( self::CAPABILITY_SINGULAR, self::CAPABILITY_PLURAL ),
			'map_meta_cap'        => true,
		);
	}

	/**
	 * Builds the post type's label set.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function labels(): array {
		return array(
			'name'                  => __( 'Prompts', 'autoscribe' ),
			'singular_name'         => __( 'Prompt', 'autoscribe' ),
			'menu_name'             => __( 'AutoScribe', 'autoscribe' ),
			'add_new'               => __( 'Add New', 'autoscribe' ),
			'add_new_item'          => __( 'Add New Prompt', 'autoscribe' ),
			'edit_item'             => __( 'Edit Prompt', 'autoscribe' ),
			'new_item'              => __( 'New Prompt', 'autoscribe' ),
			'view_item'             => __( 'View Prompt', 'autoscribe' ),
			'search_items'          => __( 'Search Prompts', 'autoscribe' ),
			'not_found'             => __( 'No prompts found.', 'autoscribe' ),
			'not_found_in_trash'    => __( 'No prompts found in Trash.', 'autoscribe' ),
			'all_items'             => __( 'Prompts', 'autoscribe' ),
			'archives'              => __( 'Prompt Archives', 'autoscribe' ),
			'insert_into_item'      => __( 'Insert into prompt', 'autoscribe' ),
			'uploaded_to_this_item' => __( 'Uploaded to this prompt', 'autoscribe' ),
			'filter_items_list'     => __( 'Filter prompts list', 'autoscribe' ),
			'items_list_navigation' => __( 'Prompts list navigation', 'autoscribe' ),
			'items_list'            => __( 'Prompts list', 'autoscribe' ),
			'item_published'        => __( 'Prompt saved.', 'autoscribe' ),
			'item_updated'          => __( 'Prompt updated.', 'autoscribe' ),
		);
	}
}
