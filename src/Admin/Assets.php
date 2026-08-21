<?php
/**
 * Admin styles and behaviour.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;

use const AutoScribe\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the small amount of CSS and JavaScript the editor needs.
 *
 * Registered against the plugin's own screens only. Enqueuing globally would
 * put the tab script on every admin page in the site for no reason.
 *
 * @since 0.7.0
 */
final class Assets {

	/**
	 * Handle shared by the style and the script.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const HANDLE = 'autoscribe-admin';

	/**
	 * Provider registry, for the grounding capability map.
	 *
	 * @since 1.0.1
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Builds the asset loader.
	 *
	 * @since 1.0.1
	 *
	 * @param Provider_Registry|null $providers Provider registry, or null to build one.
	 */
	public function __construct( ?Provider_Registry $providers = null ) {
		$this->providers = $providers instanceof Provider_Registry ? $providers : new Provider_Registry();
	}

	/**
	 * Registers the enqueue hook.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the assets on prompt screens.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_prompt_editor( $hook_suffix ) ) {
			return;
		}

		/*
		 * The fallback image field is a media picker, and the frame it opens is
		 * core's. Enqueuing it here rather than depending on it being present is
		 * the difference between a button that opens the library and a button
		 * that throws in the console: nothing else on the prompt editor loads
		 * the media scripts.
		 */
		wp_enqueue_media();

		wp_register_style( self::HANDLE, false, array(), VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $this->css() );

		wp_register_script( self::HANDLE, '', array( 'media-editor' ), VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, $this->data(), 'before' );
		wp_add_inline_script( self::HANDLE, $this->js() );
	}

	/**
	 * Emits the provider capability map the grounding control reads.
	 *
	 * Section 7.1 requires the editor to disable grounding for a provider that
	 * cannot do it and to say why. The answer belongs to the adapters, so it is
	 * handed to the script rather than duplicated in JavaScript, where it would
	 * go stale the first time a provider gained the capability.
	 *
	 * @since 1.0.1
	 *
	 * @return string
	 */
	private function data(): string {
		$search = array();

		foreach ( $this->providers->text_providers() as $provider ) {
			$search[ $provider->slug() ] = $provider->supports_web_search();
		}

		return sprintf(
			'window.autoscribeCapabilities = %s;',
			(string) wp_json_encode(
				array(
					'webSearch' => $search,
					'noSearch'  => __( 'This provider has no web search, so grounding cannot be used with it.', 'autoscribe' ),
				)
			)
		);
	}

	/**
	 * Whether the current screen is the prompt editor.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return bool
	 */
	private function is_prompt_editor( string $hook_suffix ): bool {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && Prompt_Post_Type::POST_TYPE === $screen->post_type;
	}

	/**
	 * Returns the tab stylesheet.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	private function css(): string {
		/*
		 * The hiding is scoped to a class the script adds, so the tabs only become
		 * tabs once something is there to switch between them. Hiding every panel
		 * by default and revealing the first from JavaScript reads as equivalent
		 * and is not: with the script blocked, unavailable, or simply late, the
		 * whole prompt configuration form was invisible and nothing said why.
		 */
		return '.autoscribe-tabs.is-tabbed .autoscribe-tab-panel{display:none}'
			. '.autoscribe-tabs.is-tabbed .autoscribe-tab-panel.is-active{display:block}'
			. '.autoscribe-tabs .nav-tab{cursor:pointer}'
			. '.autoscribe-media-preview img{display:block;max-width:200px;height:auto;margin-bottom:.5em}'
			. '.autoscribe-media-buttons{margin:.5em 0 0}'
			. '.autoscribe-media-remove{color:#b32d2e;margin-left:.5em}';
	}

	/**
	 * Returns the tab script.
	 *
	 * Every panel is rendered; the script only chooses which is visible. With
	 * JavaScript unavailable the CSS above would hide all of them, so the script
	 * also removes that rule's effect by activating the first panel immediately.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	private function js(): string {
		return <<<'JS'
( function () {
	var wrap = document.querySelector( '.autoscribe-tabs' );

	if ( ! wrap ) {
		return;
	}

	var tabs   = wrap.querySelectorAll( '[data-autoscribe-tab]' );
	var panels = wrap.querySelectorAll( '.autoscribe-tab-panel' );

	// Only now do the panels hide: without this class every one of them is
	// visible, stacked, which is the correct fallback rather than a blank form.
	wrap.classList.add( 'is-tabbed' );

	function show( name ) {
		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.id === 'autoscribe-tab-' + name );
		} );

		tabs.forEach( function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.dataset.autoscribeTab === name );
		} );
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			show( tab.dataset.autoscribeTab );
		} );
	} );

	if ( tabs.length ) {
		show( tabs[ 0 ].dataset.autoscribeTab );
	}

	/*
	 * Grounding depends on the selected text provider. Disabling the control
	 * rather than hiding it keeps the reason visible, and clearing the checkbox
	 * means a prompt cannot be saved claiming a capability the provider does not
	 * have. The server repeats both checks; this is the courtesy, not the guard.
	 */
	/*
	 * Schedule parameters belong to some schedule types and not others. The
	 * server renders the right ones for the type as saved; this follows the
	 * control as it changes, so a prompt switched from monthly to daily stops
	 * asking which week of the month it means before the page is even saved.
	 */
	var repeats = document.getElementById( 'autoscribe-field-schedule_type' );
	var rows    = wrap.querySelectorAll( '[data-autoscribe-for]' );

	function syncSchedule() {
		rows.forEach( function ( row ) {
			var applies = row.dataset.autoscribeFor.split( ' ' );

			row.style.display = applies.indexOf( repeats.value ) === -1 ? 'none' : '';
		} );
	}

	if ( repeats && rows.length ) {
		repeats.addEventListener( 'change', syncSchedule );
		syncSchedule();
	}

	/*
	 * The fallback image was a box asking for an attachment ID — a number that
	 * exists nowhere on this screen, and that every wrong answer looks exactly
	 * like. This attaches the media frame the post editor uses for a featured
	 * image, and only then hides the number box behind it: if this script never
	 * runs, the field is still the control it always was rather than a button
	 * that does nothing. The stored value is unchanged either way.
	 */
	wrap.querySelectorAll( '[data-autoscribe-media]' ).forEach( function ( field ) {
		var input   = field.querySelector( '[data-autoscribe-media-input]' );
		var preview = field.querySelector( '[data-autoscribe-media-preview]' );
		var buttons = field.querySelector( '[data-autoscribe-media-buttons]' );
		var choose  = field.querySelector( '[data-autoscribe-media-select]' );
		var drop    = field.querySelector( '[data-autoscribe-media-remove]' );
		var frame;

		if ( ! input || ! preview || ! buttons || ! choose || ! drop ) {
			return;
		}

		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		input.type = 'hidden';
		buttons.hidden = false;

		// The row's label pointed at the number box, which is now hidden state.
		// It points at the control that replaced it instead, so clicking the
		// label still reaches something.
		if ( input.id ) {
			choose.id = input.id;
			input.removeAttribute( 'id' );
		}

		function show( url ) {
			preview.textContent = '';

			if ( url ) {
				var img = document.createElement( 'img' );

				img.src = url;
				img.alt = '';
				preview.appendChild( img );
			}

			choose.textContent = url ? field.dataset.autoscribeChange : field.dataset.autoscribeSet;
			drop.hidden        = ! url;
		}

		choose.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = wp.media( {
					title: field.dataset.autoscribeTitle,
					button: { text: field.dataset.autoscribeChoose },
					library: { type: 'image' },
					multiple: false
				} );

				// Reopening shows the image the prompt already holds as the
				// selected one, so "change" starts from what is set rather than
				// from an empty library.
				frame.on( 'open', function () {
					var selection = frame.state().get( 'selection' );
					var current   = parseInt( input.value, 10 );

					// The frame is kept between openings, and so is whatever was
					// selected in it last time. Reopening after a removal would
					// otherwise show the removed image as still chosen.
					selection.reset();

					if ( ! current ) {
						return;
					}

					var existing = wp.media.attachment( current );

					existing.fetch();
					selection.add( existing );
				} );

				frame.on( 'select', function () {
					var chosen = frame.state().get( 'selection' ).first();

					if ( ! chosen ) {
						return;
					}

					var image = chosen.toJSON();
					var sizes = image.sizes || {};
					var size  = sizes.medium || sizes.thumbnail || sizes.full;

					input.value = image.id;
					show( size ? size.url : image.url );
				} );
			}

			frame.open();
		} );

		drop.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			input.value = '0';
			show( '' );
		} );
	} );

	var caps      = window.autoscribeCapabilities || { webSearch: {}, noSearch: '' };
	var provider  = document.getElementById( 'autoscribe-field-text_provider' );
	var grounding = document.getElementById( 'autoscribe-field-grounding_enabled' );

	if ( ! provider || ! grounding ) {
		return;
	}

	var reason = document.createElement( 'span' );
	reason.className = 'description';
	reason.style.marginLeft = '0.5em';
	grounding.parentNode.appendChild( reason );

	function syncGrounding() {
		var supported = caps.webSearch[ provider.value ] !== false;

		grounding.disabled = ! supported;
		reason.textContent = supported ? '' : caps.noSearch;

		if ( ! supported ) {
			grounding.checked = false;
		}
	}

	provider.addEventListener( 'change', syncGrounding );
	syncGrounding();
}() );
JS;
	}
}
