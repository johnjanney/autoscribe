<?php
/**
 * PHPUnit bootstrap.
 *
 * @package AutoScribe
 */

$autoscribe_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $autoscribe_tests_dir ) || '' === $autoscribe_tests_dir ) {
	// wp-env mounts the core test suite here. Continuous integration has no
	// container, so it points WP_TESTS_DIR at the wp-phpunit package instead.
	$autoscribe_tests_dir = '/wordpress-phpunit';
}

/*
 * Switch off the development HTTP mock. It is mounted into the tests container
 * as well as the development one, and if it stayed active it would answer the
 * very requests the tripwire below exists to intercept.
 */
define( 'AUTOSCRIBE_DISABLE_MOCK', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $autoscribe_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/autoscribe.php';
	}
);

require $autoscribe_tests_dir . '/includes/bootstrap.php';

/*
 * Tripwire. The goal for this phase requires that no test contacts a live API,
 * so rather than trusting that every test remembers to register a mock, any
 * request that reaches the bottom of the pre_http_request chain unhandled
 * throws. A test that would have hit a real provider fails loudly instead of
 * quietly passing against real data.
 *
 * Registered at the lowest priority so mocks, which run earlier, get first
 * refusal. A non-false value means a mock already answered.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		throw new RuntimeException( 'Unmocked HTTP request escaped the test suite: ' . esc_html( (string) $url ) );
	},
	PHP_INT_MAX,
	3
);
