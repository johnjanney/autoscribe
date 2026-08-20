<?php
/**
 * The tabbed prompt editor meta box.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Activation;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Fields;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Content_Sanitizer;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves every prompt setting, per section 9.2.
 *
 * Both passes read Prompt_Fields, so a field cannot be rendered without also
 * being saved.
 *
 * @since 0.7.0
 */
final class Prompt_Meta_Box {

	/**
	 * Nonce action prefix.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const NONCE_ACTION = 'autoscribe_save_prompt_';

	/**
	 * Nonce field name.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const NONCE_NAME = 'autoscribe_prompt_nonce';

	/**
	 * Provider registry, for capability checks on save.
	 *
	 * @since 1.0.1
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Builds the meta box.
	 *
	 * @since 1.0.1
	 *
	 * @param Provider_Registry|null $providers Provider registry, or null to build one.
	 */
	public function __construct( ?Provider_Registry $providers = null ) {
		$this->providers = $providers instanceof Provider_Registry ? $providers : new Provider_Registry();
	}

	/**
	 * Registers the meta box and its save handler.
	 *
	 * The save handler runs at priority 5, ahead of the queue re-arm that
	 * Plugin registers at the default priority. Re-arming reads the schedule
	 * meta, so if it ran first it would arm the previous schedule and the change
	 * the user just made would not take effect until the following save.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, array( $this, 'save' ), 5 );
	}

	/**
	 * Adds the meta box to the prompt editor.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function add(): void {
		add_meta_box(
			'autoscribe-prompt',
			__( 'Prompt configuration', 'autoscribe' ),
			array( $this, 'render' ),
			Prompt_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the tabbed editor.
	 *
	 * @since 0.7.0
	 *
	 * @param WP_Post $post Prompt being edited.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$values = $this->current_values( (int) $post->ID );
		$tabs   = Prompt_Fields::tabs();
		$fields = Prompt_Fields::all();

		wp_nonce_field( self::NONCE_ACTION . (int) $post->ID, self::NONCE_NAME );

		echo '<div class="autoscribe-tabs">';

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="#autoscribe-tab-%1$s" class="nav-tab" data-autoscribe-tab="%1$s">%2$s</a>',
				esc_attr( $tab ),
				esc_html( $label )
			);
		}

		echo '</nav>';

		foreach ( $tabs as $tab => $label ) {
			printf(
				'<section id="autoscribe-tab-%1$s" class="autoscribe-tab-panel" aria-label="%2$s"><table class="form-table" role="presentation"><tbody>',
				esc_attr( $tab ),
				esc_attr( $label )
			);

			foreach ( $fields as $key => $field ) {
				if ( $tab !== $field['tab'] ) {
					continue;
				}

				$this->render_row( $key, $field, $values[ $key ] ?? $field['default'] );
			}

			echo '</tbody></table></section>';
		}

		echo '</div>';

		$this->render_footer( (int) $post->ID );
	}

	/**
	 * Renders one labelled field row.
	 *
	 * @since 0.7.0
	 *
	 * @param string               $key   Field key.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Current value.
	 * @return void
	 */
	private function render_row( string $key, array $field, $value ): void {
		$id = 'autoscribe-field-' . $key;

		echo '<tr>';

		printf(
			'<th scope="row"><label for="%1$s">%2$s</label></th><td>',
			esc_attr( $id ),
			esc_html( (string) $field['label'] )
		);

		$this->render_control( $key, $field, $value, $id );

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $field['description'] ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Renders the input for one field.
	 *
	 * @since 0.7.0
	 *
	 * @param string               $key   Field key.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Current value.
	 * @param string               $id    Element ID.
	 * @return void
	 */
	private function render_control( string $key, array $field, $value, string $id ): void {
		$name = Prompt_Fields::INPUT_NAME . '[' . $key . ']';

		switch ( (string) $field['type'] ) {
			case 'bool':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				break;

			case 'int':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) (int) $value ),
					isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '',
					isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : ''
				);
				break;

			case 'time':
				printf(
					'<input type="time" id="%1$s" name="%2$s" value="%3$s" /> <span class="description">%4$s</span>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_html( wp_timezone_string() )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="6" class="large-text code">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'select':
				$this->render_select( $name, $id, Prompt_Fields::choices( $field ), (string) $value );
				break;

			case 'terms':
				$this->render_terms( $name, $id, is_array( $value ) ? $value : array() );
				break;

			case 'csv':
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( is_array( $value ) ? implode( ', ', $value ) : (string) $value )
				);
				break;

			case 'model':
				$this->render_model( $name, $id, (string) $value, Prompt_Fields::suggestions( $field ) );
				break;

			case 'text':
			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}
	}

	/**
	 * Renders a select control.
	 *
	 * @since 0.7.0
	 *
	 * @param string                $name    Field name.
	 * @param string                $id      Element ID.
	 * @param array<string, string> $choices Value to label.
	 * @param string                $current Selected value.
	 * @return void
	 */
	private function render_select( string $name, string $id, array $choices, string $current ): void {
		printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $current, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Renders the category multi-select.
	 *
	 * @since 0.7.0
	 *
	 * @param string $name    Field name.
	 * @param string $id      Element ID.
	 * @param int[]  $current Selected term IDs.
	 * @return void
	 */
	private function render_terms( string $name, string $id, array $current ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			printf(
				'<input type="hidden" name="%1$s[]" value="" /><p class="description">%2$s</p>',
				esc_attr( $name ),
				esc_html__( 'No categories exist yet.', 'autoscribe' )
			);

			return;
		}

		printf(
			'<select id="%1$s" name="%2$s[]" multiple size="6">',
			esc_attr( $id ),
			esc_attr( $name )
		);

		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $term->term_id,
				in_array( (int) $term->term_id, array_map( 'intval', $current ), true ) ? ' selected' : '',
				esc_html( $term->name )
			);
		}

		echo '</select>';
	}

	/**
	 * Renders an editable model field with suggestions.
	 *
	 * Section 2.2 requires the model ID to stay editable text. A select would
	 * make a newly released model unusable until the plugin shipped an update,
	 * and a retired one unfixable, so the suggestions are a datalist the user is
	 * free to ignore.
	 *
	 * @since 0.7.0
	 *
	 * @param string   $name        Field name.
	 * @param string   $id          Element ID.
	 * @param string   $current     Current value.
	 * @param string[] $suggestions Suggested model IDs.
	 * @return void
	 */
	private function render_model( string $name, string $id, string $current, array $suggestions ): void {
		$list_id = $id . '-suggestions';

		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" list="%4$s" autocomplete="off" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $current ),
			esc_attr( $list_id )
		);

		printf( '<datalist id="%s">', esc_attr( $list_id ) );

		foreach ( $suggestions as $model ) {
			printf( '<option value="%s"></option>', esc_attr( (string) $model ) );
		}

		echo '</datalist>';
	}

	/**
	 * Renders the next-run readout and the immediate-run control.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Prompt being edited.
	 * @return void
	 */
	private function render_footer( int $post_id ): void {
		echo '<hr />';

		printf(
			'<p><strong>%1$s</strong> <span id="autoscribe-next-run">%2$s</span></p>',
			esc_html__( 'Next run:', 'autoscribe' ),
			esc_html( Next_Run_Readout::describe( $post_id ) )
		);

		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			return;
		}

		printf(
			'<p><a class="button button-secondary" href="%1$s">%2$s</a> <a class="button button-secondary" href="%3$s">%4$s</a></p>',
			esc_url( Actions::url( Actions::ACTION_RUN_NOW, $post_id ) ),
			esc_html__( 'Run now', 'autoscribe' ),
			esc_url( Actions::url( Actions::ACTION_PREVIEW, $post_id ) ),
			esc_html__( 'Preview', 'autoscribe' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Run now queues an immediate run and creates a post. Preview generates the article and shows it without creating one. Both are charged against the budget and appear in the run log.', 'autoscribe' )
		);

		$this->render_preview();
	}

	/**
	 * Shows the most recent preview, then discards it.
	 *
	 * Section 9.2 asks the Preview control to show its output. It previously
	 * generated the article, charged it against the budget, wrote it to a
	 * transient, and then told the user it was shown below — while nothing ever
	 * read the transient back. The user paid for output the screen never
	 * displayed.
	 *
	 * The body goes through the same sanitiser the pipeline applies before
	 * wp_insert_post(). Preview output is model output, and rendering it into an
	 * admin page unfiltered would make the preview a softer target than the post
	 * it is previewing.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	private function render_preview(): void {
		$key     = Actions::PREVIEW_TRANSIENT . get_current_user_id();
		$preview = get_transient( $key );

		if ( ! is_array( $preview ) || empty( $preview['title'] ) ) {
			return;
		}

		// Shown once. Leaving it in place would redisplay a stale article on
		// every subsequent load of the editor.
		delete_transient( $key );

		$sanitizer = new Content_Sanitizer();

		echo '<hr /><h3>' . esc_html__( 'Preview', 'autoscribe' ) . '</h3>';

		printf(
			'<p><strong>%s</strong></p>',
			esc_html( $sanitizer->sanitize_title( (string) $preview['title'] ) )
		);

		if ( ! empty( $preview['excerpt'] ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html( $sanitizer->sanitize_meta_description( (string) $preview['excerpt'] ) )
			);
		}

		printf(
			'<div class="autoscribe-preview">%s</div>',
			wp_kses_post( $sanitizer->sanitize_body( (string) ( $preview['content'] ?? '' ) ) )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No post was created. This preview is not saved and disappears when you leave the page.', 'autoscribe' )
		);
	}

	/**
	 * Reads the current value of every field.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Prompt post ID.
	 * @return array<string, mixed>
	 */
	private function current_values( int $post_id ): array {
		$prompt = Prompt::load( $post_id );
		$params = null === $prompt ? array() : $prompt->schedule_params();
		$values = array();

		foreach ( Prompt_Fields::all() as $key => $field ) {
			if ( ! empty( $field['param'] ) ) {
				$values[ $key ] = $params[ $key ] ?? $field['default'];

				continue;
			}

			$stored = get_post_meta( $post_id, Prompt_Fields::PREFIX . $key, true );

			$values[ $key ] = ( '' === $stored || null === $stored ) ? $field['default'] : $stored;
		}

		return $values;
	}

	/**
	 * Persists the submitted configuration.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Prompt being saved.
	 * @return void
	 */
	public function save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		/*
		 * Both checks matter and neither substitutes for the other. The nonce
		 * proves the request came from the prompt editor rather than from
		 * somewhere else; the capability proves this user is allowed to make the
		 * change. Section 8.2 is explicit that menu visibility is not a
		 * permission check.
		 *
		 * They are inline rather than delegated because the coding standard
		 * checks for nonce verification within the same function that reads the
		 * superglobal, and a helper would leave that verification invisible to it.
		 */
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			return;
		}

		$name   = Prompt_Fields::INPUT_NAME;
		$params = array();

		foreach ( Prompt_Fields::all() as $key => $field ) {
			$type = (string) $field['type'];

			if ( 'bool' === $type ) {
				$submitted = isset( $_POST[ $name ][ $key ] );
			} elseif ( 'terms' === $type ) {
				$submitted = isset( $_POST[ $name ][ $key ] )
					? array_map( 'absint', (array) wp_unslash( $_POST[ $name ][ $key ] ) )
					: array();
			} elseif ( 'textarea' === $type ) {
				$submitted = isset( $_POST[ $name ][ $key ] )
					? sanitize_textarea_field( wp_unslash( $_POST[ $name ][ $key ] ) )
					: (string) $field['default'];
			} else {
				$submitted = isset( $_POST[ $name ][ $key ] )
					? sanitize_text_field( wp_unslash( $_POST[ $name ][ $key ] ) )
					: $field['default'];
			}

			$value = Prompt_Fields::sanitize( $field, $submitted );

			if ( ! empty( $field['param'] ) ) {
				$params[ $key ] = $value;

				continue;
			}

			update_post_meta( $post_id, Prompt_Fields::PREFIX . $key, $value );
		}

		$this->enforce_grounding_capability( $post_id );
		$this->enforce_fallback_image( $post_id );

		update_post_meta( $post_id, Prompt_Fields::PREFIX . 'schedule_params', $params );
	}

	/**
	 * Refuses to store fallback image mode without a fallback image.
	 *
	 * Fallback mode is a promise that every post gets a picture. The promise is
	 * only keepable if the ID names an image attachment that still exists, and
	 * nothing prevented saving the mode with the default of zero — so the mode
	 * read like a guarantee in the editor and behaved like "publish without one"
	 * at run time.
	 *
	 * The mode is stored as required instead, which is what an unattachable
	 * fallback amounts to: generate the image, and if that cannot be done, leave
	 * the post as a draft for a person rather than publishing it bare. Same
	 * reasoning as the grounding check above — a configuration that cannot do what
	 * it says is not saved, whether it arrives from the editor, the REST API,
	 * WP-CLI, or an import.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Prompt being saved.
	 * @return void
	 */
	private function enforce_fallback_image( int $post_id ): void {
		if ( 'fallback' !== (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', true ) ) {
			return;
		}

		if ( self::is_usable_fallback( (int) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'fallback_image_id', true ) ) ) {
			return;
		}

		update_post_meta( $post_id, Prompt_Fields::PREFIX . 'image_mode', 'required' );

		set_transient(
			'autoscribe_notice_' . get_current_user_id(),
			array(
				'type'    => 'error',
				'message' => __( 'The featured image mode was changed from "fallback" to "required", because the fallback image ID does not name an image in this site\'s media library. Set a valid attachment ID and choose fallback again.', 'autoscribe' ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Whether an attachment ID names an image this site can attach.
	 *
	 * @since 1.2.0
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
	 * Refuses to store grounding for a provider that cannot do it.
	 *
	 * Section 7.1: never let a user save a configuration that cannot run. The
	 * editor disables the control, but a disabled control is a courtesy — the
	 * REST API, WP-CLI, and an imported prompt all reach this save path without
	 * ever seeing it, so the capability is checked again here.
	 *
	 * @since 1.0.1
	 *
	 * @param int $post_id Prompt being saved.
	 * @return void
	 */
	private function enforce_grounding_capability( int $post_id ): void {
		if ( '' === (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'grounding_enabled', true ) ) {
			return;
		}

		$slug     = (string) get_post_meta( $post_id, Prompt_Fields::PREFIX . 'text_provider', true );
		$provider = $this->providers->text_provider( $slug );

		if ( null !== $provider && ! $provider->supports_web_search() ) {
			update_post_meta( $post_id, Prompt_Fields::PREFIX . 'grounding_enabled', false );
		}
	}
}
