<?php
/**
 * The AutoScribe settings screen.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Activation;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the site-wide settings, per section 9.4.
 *
 * @since 0.7.0
 */
final class Settings_Page {

	/**
	 * Menu slug.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const SLUG = 'autoscribe-settings';

	/**
	 * Nonce action.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const NONCE_ACTION = 'autoscribe_settings';

	/**
	 * Provider registry.
	 *
	 * @since 0.7.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Queue wrapper, for the health panel.
	 *
	 * @since 0.7.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Problems raised while saving, shown above the form.
	 *
	 * @since 1.0.1
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Builds the page.
	 *
	 * @since 0.7.0
	 *
	 * @param Provider_Registry $providers Provider registry.
	 * @param Scheduler         $scheduler Queue wrapper.
	 */
	public function __construct( Provider_Registry $providers, Scheduler $scheduler ) {
		$this->providers = $providers;
		$this->scheduler = $scheduler;
	}

	/**
	 * Renders the page, handling a submission first.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage AutoScribe settings.', 'autoscribe' ),
				'',
				array( 'response' => 403 )
			);
		}

		$saved = $this->maybe_save();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AutoScribe Settings', 'autoscribe' ) . '</h1>';

		if ( $saved && array() === $this->errors ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'autoscribe' ) . '</p></div>';
		}

		foreach ( $this->errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form method="post">';

		wp_nonce_field( self::NONCE_ACTION );

		$this->render_credentials();
		$this->render_review_and_budget();
		$this->render_pricing();
		$this->render_housekeeping();

		submit_button();

		echo '</form>';

		$this->render_health();

		echo '</div>';
	}

	/**
	 * Persists a submission when one is present and valid.
	 *
	 * @since 0.7.0
	 *
	 * @return bool Whether anything was saved.
	 */
	private function maybe_save(): bool {
		if ( ! isset( $_POST['autoscribe_settings_submit'] ) ) {
			return false;
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			return false;
		}

		$models = array();

		foreach ( $this->all_provider_slugs() as $slug ) {
			$models[ $slug ] = isset( $_POST['autoscribe_default_model'][ $slug ] )
				? sanitize_text_field( wp_unslash( $_POST['autoscribe_default_model'][ $slug ] ) )
				: '';

			/*
			 * An empty key field means "leave the stored key alone". Treating a
			 * blank as an instruction to clear would wipe every key the moment
			 * anyone saved the page, since the fields are never populated with
			 * the existing value.
			 */
			$submitted_key = isset( $_POST['autoscribe_key'][ $slug ] )
				? sanitize_text_field( wp_unslash( $_POST['autoscribe_key'][ $slug ] ) )
				: '';

			if ( '' !== $submitted_key ) {
				$stored = Key_Store::set( $slug, $submitted_key );

				// A refusal here means the key was not saved. Silently discarding
				// it would leave the administrator believing it had been.
				if ( is_wp_error( $stored ) ) {
					$this->errors[] = $stored->get_error_message();
				}
			}
		}

		Settings::save(
			Settings::sanitize(
				array(
					'notification_email' => isset( $_POST['autoscribe_notification_email'] )
						? sanitize_email( wp_unslash( $_POST['autoscribe_notification_email'] ) )
						: '',
					'force_review'       => isset( $_POST['autoscribe_force_review'] ),
					'retention_days'     => isset( $_POST['autoscribe_retention_days'] )
						? absint( wp_unslash( $_POST['autoscribe_retention_days'] ) )
						: Settings::DEFAULT_RETENTION_DAYS,
					'default_models'     => $models,
				)
			)
		);

		if ( isset( $_POST['autoscribe_global_cap'] ) ) {
			update_option(
				Budget_Guard::GLOBAL_CAP_OPTION,
				absint( wp_unslash( $_POST['autoscribe_global_cap'] ) )
			);
		}

		$this->save_pricing();

		return true;
	}

	/**
	 * Persists the pricing table overrides.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function save_pricing(): void {
		if ( ! isset( $_POST['autoscribe_price'] ) || ! is_array( $_POST['autoscribe_price'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$pricing = new Pricing_Table();
		$models  = array_keys( $pricing->all() );

		foreach ( $models as $model ) {
			$row = array();

			foreach ( array( Pricing_Table::INPUT_PER_MILLION, Pricing_Table::OUTPUT_PER_MILLION, Pricing_Table::PER_IMAGE, Pricing_Table::PER_GROUNDED_REQUEST ) as $component ) {
				$row[ $component ] = isset( $_POST['autoscribe_price'][ $model ][ $component ] )
					? (float) sanitize_text_field( wp_unslash( $_POST['autoscribe_price'][ $model ][ $component ] ) )
					: 0.0;
			}

			$pricing->set( (string) $model, $row );
		}
	}

	/**
	 * Renders the provider credential fields.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_credentials(): void {
		$results = $this->test_results();

		echo '<h2>' . esc_html__( 'Providers', 'autoscribe' ) . '</h2>';
		echo '<p>' . esc_html__( 'A key set in wp-config.php is used in preference to a stored one, never enters the database, and cannot be overwritten here.', 'autoscribe' ) . '</p>';
		echo '<p>' . esc_html__( 'The default model is used by any prompt that leaves its own model field blank. Test connection probes that model, so set it before testing.', 'autoscribe' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $this->all_providers() as $slug => $label ) {
			printf(
				'<tr><th scope="row"><label for="autoscribe-key-%1$s">%2$s</label></th><td>',
				esc_attr( $slug ),
				esc_html( $label )
			);

			printf(
				'<input type="password" class="regular-text" id="autoscribe-key-%1$s" name="autoscribe_key[%1$s]" value="" autocomplete="new-password" placeholder="%2$s" />',
				esc_attr( $slug ),
				esc_attr( $this->key_placeholder( $slug ) )
			);

			printf(
				'<br /><label>%1$s <input type="text" class="regular-text" name="autoscribe_default_model[%2$s]" value="%3$s" /></label>',
				esc_html__( 'Default model', 'autoscribe' ),
				esc_attr( $slug ),
				esc_attr( Settings::default_model( $slug ) )
			);

			printf( '<p class="description">%s</p>', esc_html( $this->key_status( $slug ) ) );

			/*
			 * A link rather than a submit button, because this form posts the
			 * whole settings screen and a test must not depend on the
			 * administrator having saved first — nor should it save on their
			 * behalf. The handler checks its own nonce and capability.
			 */
			printf(
				'<p><a class="button button-secondary" href="%1$s">%2$s</a>%3$s</p>',
				esc_url( Actions::test_url( $slug ) ),
				esc_html__( 'Test connection', 'autoscribe' ),
				isset( $results[ $slug ] )
					? ' <strong>' . esc_html( (string) $results[ $slug ] ) . '</strong>'
					: ''
			);

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Returns the results of the most recent connection test, then clears them.
	 *
	 * @since 1.0.1
	 *
	 * @return array<string, string>
	 */
	private function test_results(): array {
		$key     = Actions::TEST_TRANSIENT . get_current_user_id();
		$results = get_transient( $key );

		if ( ! is_array( $results ) ) {
			return array();
		}

		delete_transient( $key );

		return $results;
	}

	/**
	 * Renders the review override and budget cap.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_review_and_budget(): void {
		$guard = new Budget_Guard();

		echo '<h2>' . esc_html__( 'Publishing and budget', 'autoscribe' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="autoscribe_force_review" value="1"%2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Force human review', 'autoscribe' ),
			checked( Settings::force_review(), true, false ),
			esc_html__( 'Hold every generated post as a draft', 'autoscribe' ),
			esc_html__( 'Overrides the per-prompt setting on every prompt, and cannot be bypassed by a manual run.', 'autoscribe' )
		);

		printf(
			'<tr><th scope="row"><label for="autoscribe-global-cap">%1$s</label></th><td><input type="number" min="0" class="small-text" id="autoscribe-global-cap" name="autoscribe_global_cap" value="%2$d" /> <span class="description">%3$s</span></td></tr>',
			esc_html__( 'Global monthly cap (cents)', 'autoscribe' ),
			(int) $guard->global_cap_cents(),
			esc_html__( 'Zero means no global cap.', 'autoscribe' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><strong>%2$s</strong><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Spent this month', 'autoscribe' ),
			esc_html( $this->format_cents( $guard->month_to_date_cents() ) ),
			esc_html__( 'An estimate computed from reported token usage and the rates below. Your provider billing is the authority.', 'autoscribe' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Renders the editable pricing table.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_pricing(): void {
		echo '<h2>' . esc_html__( 'Pricing', 'autoscribe' ) . '</h2>';
		echo '<p>' . esc_html__( 'Prices change without notice and the plugin never fetches them. Verify these against your provider before relying on any total.', 'autoscribe' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Model', 'autoscribe' ) . '</th>';
		echo '<th>' . esc_html__( 'Input $/M', 'autoscribe' ) . '</th>';
		echo '<th>' . esc_html__( 'Output $/M', 'autoscribe' ) . '</th>';
		echo '<th>' . esc_html__( 'Per image $', 'autoscribe' ) . '</th>';
		echo '<th>' . esc_html__( 'Per grounded request $', 'autoscribe' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( ( new Pricing_Table() )->all() as $model => $rate ) {
			printf( '<tr><td><code>%s</code></td>', esc_html( (string) $model ) );

			foreach ( array( Pricing_Table::INPUT_PER_MILLION, Pricing_Table::OUTPUT_PER_MILLION, Pricing_Table::PER_IMAGE, Pricing_Table::PER_GROUNDED_REQUEST ) as $component ) {
				printf(
					'<td><input type="text" class="small-text" name="autoscribe_price[%1$s][%2$s]" value="%3$s" /></td>',
					esc_attr( (string) $model ),
					esc_attr( $component ),
					esc_attr( (string) ( $rate[ $component ] ?? 0 ) )
				);
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the notification and retention fields.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_housekeeping(): void {
		echo '<h2>' . esc_html__( 'Housekeeping', 'autoscribe' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="autoscribe-email">%1$s</label></th><td><input type="email" class="regular-text" id="autoscribe-email" name="autoscribe_notification_email" value="%2$s" /></td></tr>',
			esc_html__( 'Notification email', 'autoscribe' ),
			esc_attr( Settings::notification_email() )
		);

		printf(
			'<tr><th scope="row"><label for="autoscribe-retention">%1$s</label></th><td><input type="number" min="0" class="small-text" id="autoscribe-retention" name="autoscribe_retention_days" value="%2$d" /> <span class="description">%3$s</span></td></tr>',
			esc_html__( 'Keep run history for (days)', 'autoscribe' ),
			(int) Settings::retention_days(),
			esc_html__( 'Zero keeps everything.', 'autoscribe' )
		);

		echo '</tbody></table>';
		echo '<input type="hidden" name="autoscribe_settings_submit" value="1" />';
	}

	/**
	 * Renders the system health panel.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_health(): void {
		echo '<h2>' . esc_html__( 'System health', 'autoscribe' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		$checks = array(
			array(
				__( 'Action Scheduler', 'autoscribe' ),
				$this->scheduler->is_available()
					? __( 'Loaded.', 'autoscribe' )
					: __( 'Not loaded. Nothing will run on a schedule.', 'autoscribe' ),
			),
			array(
				__( 'Queue last processed', 'autoscribe' ),
				$this->last_processed_text(),
			),
			array(
				__( 'DISABLE_WP_CRON', 'autoscribe' ),
				defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
					? __( 'Set. Make sure a system cron entry hits wp-cron.php every minute.', 'autoscribe' )
					: __( 'Not set. On a low-traffic site schedules will drift, because WP-Cron only fires on page loads.', 'autoscribe' ),
			),
			array(
				__( 'libsodium', 'autoscribe' ),
				function_exists( 'sodium_crypto_secretbox' )
					? __( 'Available. Keys stored in the database are encrypted.', 'autoscribe' )
					: __( 'Missing. Keys cannot be stored in the database; use wp-config.php constants.', 'autoscribe' ),
			),
			array(
				__( 'Security salts', 'autoscribe' ),
				Key_Store::salts_are_usable()
					? __( 'AUTH_KEY and SECURE_AUTH_KEY are set. The stored-key encryption has a site-specific key.', 'autoscribe' )
					: __( 'AUTH_KEY or SECURE_AUTH_KEY is missing or still a placeholder. Keys cannot be stored in the database until that is fixed; use wp-config.php constants.', 'autoscribe' ),
			),
		);

		foreach ( $checks as $check ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( $check[0] ),
				esc_html( $check[1] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Describes when the queue last completed one of the plugin's actions.
	 *
	 * @since 1.0.1
	 *
	 * @return string
	 */
	private function last_processed_text(): string {
		$timestamp = $this->scheduler->last_processed();

		if ( null === $timestamp ) {
			return __( 'Nothing has completed yet. If prompts are enabled and their next run has passed, the queue is not running.', 'autoscribe' );
		}

		return sprintf(
			/* translators: 1: formatted date and time, 2: human-readable interval. */
			__( '%1$s (%2$s ago)', 'autoscribe' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
			human_time_diff( $timestamp )
		);
	}

	/**
	 * Returns every provider slug the plugin can hold a key for.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	private function all_provider_slugs(): array {
		return array_keys( $this->all_providers() );
	}

	/**
	 * Returns every provider as slug to label.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	private function all_providers(): array {
		$providers = array();

		foreach ( $this->providers->text_providers() as $provider ) {
			$providers[ $provider->slug() ] = $provider->label();
		}

		foreach ( $this->providers->image_providers() as $provider ) {
			$providers[ $provider->slug() ] = $provider->label();
		}

		return $providers;
	}

	/**
	 * Returns the placeholder shown in a key field.
	 *
	 * Never the key itself. Section 8.1 forbids echoing a stored key back into
	 * the form, so the field is always empty and only its state is described.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	private function key_placeholder( string $slug ): string {
		return Key_Store::SOURCE_MISSING === Key_Store::source( $slug )
			? __( 'Not set', 'autoscribe' )
			: '••••••••••••';
	}

	/**
	 * Describes where a provider's key comes from.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	private function key_status( string $slug ): string {
		switch ( Key_Store::source( $slug ) ) {
			case Key_Store::SOURCE_CONSTANT:
				/* translators: %s: PHP constant name. */
				return sprintf( __( 'Set by the %s constant in wp-config.php. Anything entered here is ignored.', 'autoscribe' ), Key_Store::constant_name( $slug ) );

			case Key_Store::SOURCE_STORED:
				return __( 'Stored in the database, encrypted. Enter a new key to replace it.', 'autoscribe' );

			case Key_Store::SOURCE_STALE:
				return __( 'A key is stored but can no longer be decrypted, which happens when the site salts are rotated. Enter it again.', 'autoscribe' );

			case Key_Store::SOURCE_UNSAFE:
				return __( 'A key is stored, but this site has no usable AUTH_KEY and SECURE_AUTH_KEY, so it was encrypted with a key anyone could derive. It will not be used. Generate fresh WordPress salts, then enter the key again — or set it as a wp-config.php constant instead.', 'autoscribe' );

			default:
				return __( 'No key set.', 'autoscribe' );
		}
	}

	/**
	 * Formats a cent total as currency-neutral text.
	 *
	 * @since 0.7.0
	 *
	 * @param int $cents Amount in cents.
	 * @return string
	 */
	private function format_cents( int $cents ): string {
		return number_format_i18n( $cents / 100, 2 );
	}
}
