<?php
/**
 * Optional bootstrap for the Integration suite: it puts WordPress in place.
 *
 * WHY THIS FILE IS OPTIONAL, AND WHY IT MUST STAY OPTIONAL.
 *
 * `tests/Integration/OutboxRepositoryTest.php` needs two things that only a
 * real WordPress can give it: the `$wpdb` handle and the `ABSPATH` constant.
 * When the suite is executed by a runner that has already loaded WordPress,
 * both are there and this file is never reached. When it is executed by a bare
 * `vendor/bin/phpunit` inside the containerised environment - which is how the
 * closing validation runs it, and how a contributor runs it on a laptop with
 * the environment up - nothing has loaded WordPress yet, and without this file
 * the suite skips.
 *
 * A skip is not a failure and it is not a pass either: the test names the
 * reason out loud precisely so that a run which checked nothing cannot be read
 * as a run which checked everything. That property is the one this file must
 * not break. So the contract here is narrow and deliberate:
 *
 *   - if WordPress is already loaded, do nothing at all;
 *   - if WordPress can be found, load it;
 *   - if it CANNOT be found, return quietly and let the test skip naming its
 *     own reason. This file never fabricates a `$wpdb`, never defines
 *     `ABSPATH` by itself, and never throws: any of the three would turn "I
 *     could not check this" into either a green that proved nothing or a red
 *     that blames the wrong thing.
 *
 * HOW WORDPRESS IS LOCATED, in order, first hit wins:
 *
 *   1. the `WP_ROOT_DIR` environment variable, for a layout this file cannot
 *      guess (a runner that keeps the plugin outside `wp-content/plugins`);
 *   2. the standard plugin layout - this file sits at
 *      `<wp-root>/wp-content/plugins/<plugin-dir>/tests/`, so the root is four
 *      directories up. The walk is bounded and looks for `wp-load.php`, so a
 *      checkout that is NOT inside a WordPress installation simply runs out of
 *      candidates instead of loading something that happens to be there.
 *
 * WHY THE OUTPUT IS BUFFERED AND DISCARDED: `phpunit.xml.dist` turns on
 * `beStrictAboutOutputDuringTests`, and booting WordPress prints whatever a
 * plugin or a deprecation feels like printing. That output belongs to the boot,
 * not to a test, and letting it reach the runner would fail the suite for a
 * reason that has nothing to do with the outbox.
 *
 * Usage, from the repository root, with the environment up:
 *
 *     npx wp-env run tests-cli --env-cwd=wp-content/plugins/factuarea-woocommerce \
 *         vendor/bin/phpunit --testsuite Integration
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests;

/**
 * Returns the path of the `wp-load.php` to use, or null when there is none.
 *
 * @return string|null Absolute path of wp-load.php, or null when WordPress is
 *                     not reachable from here.
 */
function factuarea_wc_locate_wp_load(): ?string {
	$from_environment = getenv( 'WP_ROOT_DIR' );

	if ( is_string( $from_environment ) && '' !== $from_environment ) {
		$candidate = rtrim( $from_environment, '/\\' ) . '/wp-load.php';

		return is_file( $candidate ) ? $candidate : null;
	}

	/*
	 * Bounded walk upwards. Six levels is two more than the standard layout
	 * needs (tests -> plugin -> plugins -> wp-content -> root), which covers a
	 * symlinked or nested checkout without ever wandering as far as `/`.
	 */
	$directory = __DIR__;

	for ( $level = 0; $level < 6; $level++ ) {
		$directory = dirname( $directory );

		if ( is_file( $directory . '/wp-load.php' ) ) {
			return $directory . '/wp-load.php';
		}
	}

	return null;
}

// Already loaded by the runner: there is nothing to do, and doing it twice
// would redeclare half of WordPress.
if ( isset( $GLOBALS['wpdb'] ) && defined( 'ABSPATH' ) ) {
	return;
}

$factuarea_wc_wp_load = factuarea_wc_locate_wp_load();

if ( null === $factuarea_wc_wp_load ) {
	// Not reachable. Return quietly; the test skips naming its own reason.
	return;
}

/*
 * WordPress reads the request out of `$_SERVER`. On the command line those keys
 * are absent, and their absence makes parts of the boot emit notices about
 * undefined indexes - noise that would be reported against whichever test ran
 * first. Filling them in is what any WordPress command-line runner does.
 */
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['SERVER_PORT']    = $_SERVER['SERVER_PORT'] ?? '80';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

ob_start();

require_once $factuarea_wc_wp_load;

ob_end_clean();
