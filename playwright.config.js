// @ts-check

/**
 * End-to-end configuration for Factuarea for WooCommerce.
 *
 * The site under test is the containerised WordPress that `npm run e2e:up`
 * starts from .wp-env.json: WordPress 6.5, WooCommerce 8.9 - the MINIMUM this
 * plugin supports - and PHP 8.1. Testing the minimum rather than the latest is
 * the point: the tax identifier field is registered through an interface that
 * was born in 8.9, and a suite that only ever ran on the newest WooCommerce
 * would not notice the day the plugin started needing something newer than it
 * promises merchants.
 *
 * `readme.txt` also declares a version this environment does NOT pin - the
 * `Tested up to` claim - and the suite can be pointed at it without editing a
 * single pinned file: `tests/e2e/declared-pair.js` writes the override wp-env
 * reads, from the pair `scripts/check-tested-up-to.php --emit` measures.
 *
 * The suite covers BOTH directions of the connector. What leaves the shop -
 * the two checkouts, the delivery and its outbox - and what arrives at it: the
 * fulfilment status, the refund and the catalogue push, published at the
 * plugin's own receivers by the sender half of the mock plugin, signed the way
 * the platform signs and over real HTTP.
 */

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',

	/*
	 * ZERO RETRIES, AND THIS IS THE DECISION RATHER THAN A DEFAULT.
	 *
	 * A retry hides intermittence, and intermittence is exactly what is worth
	 * seeing here: a checkout field that paints on the second attempt is a
	 * checkout field that did not paint. The whole reason this suite exists is
	 * that the failures it hunts - a tax identifier field silently missing from
	 * one of the two checkouts, a received refund quietly travelling back out
	 * again - are invisible unless something looks; a green that a retry
	 * produced would put that invisibility back.
	 */
	retries: 0,

	/*
	 * One worker, and no parallelism inside a file. The scenarios share one
	 * WordPress: the outbox, the mock receiver's scripted answers, the recorded
	 * deliveries, the inbound idempotency ledger, the order the frozen bodies
	 * name and the articles the batches name are all single global state, and
	 * two scenarios running at once would each be asserting about the other
	 * one's traffic.
	 */
	workers: 1,
	fullyParallel: false,

	/*
	 * Nothing may be marked `.only` in a committed file: it turns the rest of
	 * the suite off and reports success.
	 */
	forbidOnly: !! process.env.CI,

	timeout: 120_000,
	expect: { timeout: 20_000 },

	reporter: 'list',

	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		actionTimeout: 30_000,
		navigationTimeout: 60_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
