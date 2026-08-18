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

		wp_register_style( self::HANDLE, false, array(), VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $this->css() );

		wp_register_script( self::HANDLE, '', array(), VERSION, true );
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
		return '.autoscribe-tab-panel{display:none}'
			. '.autoscribe-tab-panel.is-active{display:block}'
			. '.autoscribe-tabs .nav-tab{cursor:pointer}';
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
