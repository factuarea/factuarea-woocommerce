// @ts-check

/**
 * Refuses to start the suite against a site that is not the one it was written
 * for.
 *
 * Everything checked here is something that, when wrong, makes a scenario fail
 * for a reason that has nothing to do with the plugin: a WordPress that is not
 * up, a WooCommerce that is not the minimum, a seed that did not run. A suite
 * that discovered those inside its first scenario would report them as a broken
 * checkout, and somebody would go and read the plugin.
 *
 * It also PRINTS the versions it found. The whole claim of this suite is "the
 * two checkouts work on the minimum supported WooCommerce", and a run that does
 * not say which WooCommerce it exercised is not evidence of that claim.
 */

const { request } = require( '@playwright/test' );

const { MOCK_TOKEN, MOCK_ROOT } = require( './support/site' );

/**
 * What .wp-env.json pins, and therefore what a run has to have found.
 *
 * The defaults are the MINIMUM the plugin promises, and running against the
 * minimum is the point of this suite: the tax identifier field is registered
 * through an interface born in WooCommerce 8.9, and a suite that only ever saw
 * the newest release would not notice the day the plugin quietly started
 * needing something newer than it advertises.
 *
 * The three can be overridden by environment, and that exists for exactly one
 * job: `readme.txt` carries `Tested up to` and `WC tested up to` lines, which
 * are claims made to strangers about versions this suite does NOT pin.
 * `docs/publishing.md` forbids raising either without running the suite against
 * it, and before these overrides existed "running it" meant editing pinned
 * files by hand and remembering to put them back - a procedure nobody performs
 * twice the same way. Now it is:
 *
 *     eval "$( php scripts/check-tested-up-to.php --emit | sed 's/^/export /' )"
 *     node tests/e2e/declared-pair.js
 *     npm run e2e:down && npx wp-env destroy && npm run e2e:up
 *     npm run e2e
 *     node tests/e2e/declared-pair.js --remove
 *
 * WRITING THAT OVERRIDE BY HAND IS THE TRAP, which is why there is a script for
 * it. wp-env merges the override by top-level key and an array REPLACES rather
 * than extends, so an override naming only `core` leaves the `plugins` array of
 * `.wp-env.json` in place while an override naming WooCommerce silently drops
 * the working tree - starting a WordPress with no plugin under test at all. And
 * `WC tested up to` is a SERIES, which the plugin directory does not publish as
 * a download: the release inside it has to be resolved, never typed.
 *
 * The guard is not weakened by any of this: it still refuses a site that is not
 * the one DECLARED, and the run still prints the three versions it actually
 * found, which is the evidence the claim rests on. What changes is that the
 * declaration can be made on the command line instead of in a commit.
 */
const EXPECTED = {
	wordpress: process.env.FACTUAREA_E2E_WORDPRESS || '6.5',
	woocommerce: process.env.FACTUAREA_E2E_WOOCOMMERCE || '8.9',
	php: process.env.FACTUAREA_E2E_PHP || '8.1',
};

/**
 * Does `found` start with the `expected` series?
 *
 * A prefix comparison rather than an equality one: the pins are series - 6.5.x,
 * 8.9.x, 8.1.x - and a patch release inside the pinned series is the same
 * promise. Anything outside it is not.
 *
 * @param {string} found What is running.
 * @param {string} expected The series that was pinned.
 * @returns {boolean} Whether the found version belongs to the pinned series.
 */
function inSeries( found, expected ) {
	return found === expected || found.startsWith( `${ expected }.` );
}

module.exports = async function globalSetup( config ) {
	const baseURL = config.projects[ 0 ].use.baseURL;
	const context = await request.newContext( { baseURL } );

	let fixtures;

	try {
		const response = await context.get( `${ MOCK_ROOT }/fixtures`, {
			headers: { 'X-Factuarea-Mock-Token': MOCK_TOKEN },
		} );

		if ( ! response.ok() ) {
			throw new Error( `${ response.status() } ${ await response.text() }` );
		}

		fixtures = await response.json();
	} catch ( error ) {
		await context.dispose();

		throw new Error(
			`The harness at ${ baseURL } did not answer (${ error.message }). Start the environment with \`npm run e2e:up\`, which also seeds the shop; nothing in this suite creates the site it runs against.`
		);
	}

	const problems = [];

	if ( ! inSeries( String( fixtures.wordpress_version ), EXPECTED.wordpress ) ) {
		problems.push( `WordPress ${ fixtures.wordpress_version }, expected the ${ EXPECTED.wordpress } series` );
	}

	if ( ! fixtures.woocommerce_version ) {
		problems.push( 'WooCommerce is not active at all' );
	} else if ( ! inSeries( String( fixtures.woocommerce_version ), EXPECTED.woocommerce ) ) {
		problems.push( `WooCommerce ${ fixtures.woocommerce_version }, expected the ${ EXPECTED.woocommerce } series` );
	}

	if ( ! inSeries( String( fixtures.php_version ), EXPECTED.php ) ) {
		problems.push( `PHP ${ fixtures.php_version }, expected the ${ EXPECTED.php } series` );
	}

	if ( ! fixtures.product_id ) {
		problems.push( 'the seeded product is missing, so no order can be placed' );
	}

	if ( ! fixtures.classic_checkout_url ) {
		problems.push( 'the classic checkout page is missing' );
	}

	await context.dispose();

	if ( problems.length > 0 ) {
		throw new Error(
			`The environment is not the one this suite was written for: ${ problems.join( '; ' ) }. `
			+ 'Recreate it with `npm run e2e:down && npx wp-env destroy && npm run e2e:up`.'
		);
	}

	// eslint-disable-next-line no-console
	console.log(
		`Running against WordPress ${ fixtures.wordpress_version }, WooCommerce ${ fixtures.woocommerce_version }, `
		+ `PHP ${ fixtures.php_version } at ${ fixtures.home_url }.`
	);
};
