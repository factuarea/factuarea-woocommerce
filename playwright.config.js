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
	 * that the failure it hunts - a tax identifier field silently missing from
	 * one of the two checkouts - is invisible unless something looks; a green
	 * that a retry produced would put that invisibility back.
	 */
	retries: 0,

	/*
	 * One worker, and no parallelism inside a file. The scenarios share one
	 * WordPress: the outbox, the mock receiver's scripted answers and the
	 * recorded deliveries are all single global state, and two scenarios running
	 * at once would each be asserting about the other one's traffic.
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
