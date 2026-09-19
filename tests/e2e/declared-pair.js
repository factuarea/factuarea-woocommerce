#!/usr/bin/env node
// @ts-check

/**
 * Points the containerised environment at the pair of versions the package
 * DECLARES it is tested against, without writing that pair down anywhere.
 *
 * ---------------------------------------------------------------------------
 * THE PROBLEM THIS SOLVES
 * ---------------------------------------------------------------------------
 * `.wp-env.json` pins the FLOOR: WordPress 6.5, WooCommerce 8.9.5, PHP 8.1 -
 * the minimum the plugin promises merchants, and the version the suite runs
 * against by default, because a suite that only ever saw the newest release
 * would not notice the day the plugin quietly started needing something newer
 * than it advertises.
 *
 * `readme.txt` declares a CEILING as well: `Tested up to` and `WC tested up to`
 * are claims made to strangers about versions this environment does not pin.
 * `docs/publishing.md` forbids raising either without running the suite against
 * it, and running it means starting the environment somewhere else - which is
 * what this script prepares, and only that. It starts nothing and runs nothing.
 *
 * ---------------------------------------------------------------------------
 * WHY IT DOES NOT READ `readme.txt` ITSELF
 * ---------------------------------------------------------------------------
 * Because a second reader of those four declarations is a second declaration.
 * The whole point of `scripts/check-tested-up-to.php` is that the versions live
 * in exactly one place and every consumer asks THAT: it is the gate that knows
 * a minimum has three homes, that a tested version has two, and that a
 * contradiction between them is a failure rather than a preference. So the pair
 * arrives here already measured:
 *
 *     php scripts/check-tested-up-to.php --emit
 *     FACTUAREA_E2E_WORDPRESS=7.1
 *     FACTUAREA_E2E_WOOCOMMERCE=8.9
 *
 * and this script refuses to guess when it is not given one.
 *
 * ---------------------------------------------------------------------------
 * THE TRAP THIS SCRIPT EXISTS TO CLOSE
 * ---------------------------------------------------------------------------
 * `WC tested up to` is a SERIES - `8.9` - and there is no such download:
 * `https://downloads.wordpress.org/plugin/woocommerce.8.9.zip` is a 404. The
 * newest release INSIDE the declared series is resolved from the plugin
 * directory rather than typed here, because a release typed by hand would be a
 * fifth declaration of a version, drifting silently, which is the exact defect
 * the version gate exists to prevent. WordPress, unlike WooCommerce, does
 * publish its series as a download, so `core` takes the declaration as it is.
 *
 * ---------------------------------------------------------------------------
 * WHY THE WHOLE `plugins` ARRAY IS WRITTEN
 * ---------------------------------------------------------------------------
 * wp-env merges `.wp-env.override.json` over `.wp-env.json` by top-level key,
 * and an array REPLACES rather than extends. An override that named only
 * WooCommerce would start a WordPress with no plugin under test at all, and the
 * suite would fail at its first assertion about a plugin that was never there.
 * So `"."` - the working tree - is written alongside it, every time.
 *
 * ---------------------------------------------------------------------------
 * USE
 * ---------------------------------------------------------------------------
 *     eval "$( docker run --rm -v "$PWD":/app -w /app <php image> \
 *         php scripts/check-tested-up-to.php --emit | sed 's/^/export /' )"
 *     node tests/e2e/declared-pair.js
 *     npm run e2e:down && npx wp-env destroy && npm run e2e:up
 *     npm run e2e
 *     node tests/e2e/declared-pair.js --remove
 *     npm run e2e:down && npx wp-env destroy && npm run e2e:up
 *
 * The two environment variables are read by `tests/e2e/global-setup.js`, which
 * refuses to run the suite against anything that is not the pair DECLARED - so
 * a run that wrote this file and forgot to export them stops at the guard
 * instead of quietly revalidating the floor a second time.
 */

const { writeFileSync, unlinkSync, existsSync } = require( 'node:fs' );
const { join } = require( 'node:path' );

/** Where wp-env looks for the override, which is beside `.wp-env.json`. */
const OVERRIDE = join( __dirname, '..', '..', '.wp-env.override.json' );

/** Where the plugin directory answers what a series actually contains. */
const DIRECTORY = 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json';

/**
 * Compares two dotted versions numerically.
 *
 * A string comparison would put `8.9.10` before `8.9.5`, which is how a
 * resolver ends up choosing the second newest release of a series and nobody
 * notices until that release is the one with the bug.
 *
 * @param {string} left One version.
 * @param {string} right The other.
 * @returns {number} Negative when left is older.
 */
function compareVersions( left, right ) {
	const a = left.split( '.' ).map( Number );
	const b = right.split( '.' ).map( Number );

	for ( let index = 0; index < Math.max( a.length, b.length ); index++ ) {
		const difference = ( a[ index ] || 0 ) - ( b[ index ] || 0 );

		if ( 0 !== difference ) {
			return difference;
		}
	}

	return 0;
}

/**
 * The newest release published inside a declared WooCommerce series.
 *
 * @param {string} series The declared series, such as `8.9`.
 * @returns {Promise<string>} A release, such as `8.9.5`.
 */
async function newestReleaseOf( series ) {
	const response = await fetch( DIRECTORY );

	if ( ! response.ok ) {
		throw new Error(
			`The plugin directory answered ${ response.status } and the releases of the ${ series } series could not be listed.`
		);
	}

	const listing = await response.json();
	const inside = Object.keys( listing.versions || {} ).filter( ( version ) =>
		new RegExp( `^${ series.replace( /\./g, '\\.' ) }(\\.\\d+)?$` ).test( version )
	);

	if ( 0 === inside.length ) {
		throw new Error(
			`The declared WooCommerce series ${ series } has no release in the plugin directory, so the suite cannot be run `
			+ 'against what `readme.txt` promises. Either the declaration is wrong or the series was never published.'
		);
	}

	return inside.sort( compareVersions )[ inside.length - 1 ];
}

/**
 * Refuses a declaration that cannot be downloaded.
 *
 * A 404 here is a claim the package makes to strangers about a version that
 * does not exist, which is worth a loud failure rather than a wp-env that dies
 * half way through with a download error.
 *
 * @param {string} address What the environment would fetch.
 * @returns {Promise<void>}
 */
async function refuseIfMissing( address ) {
	const response = await fetch( address, { method: 'HEAD' } );

	if ( ! response.ok ) {
		throw new Error( `${ address } answered ${ response.status }, so the declared version cannot be installed.` );
	}
}

/**
 * Writes the override, or removes it.
 *
 * @returns {Promise<void>}
 */
async function main() {
	if ( process.argv.includes( '--remove' ) ) {
		if ( existsSync( OVERRIDE ) ) {
			unlinkSync( OVERRIDE );
			process.stdout.write( `Removed ${ OVERRIDE }. Recreate the environment to go back to the pinned floor.\n` );

			return;
		}

		process.stdout.write( 'There was no override to remove; the environment already runs the pinned floor.\n' );

		return;
	}

	const wordpress = process.env.FACTUAREA_E2E_WORDPRESS || '';
	const woocommerce = process.env.FACTUAREA_E2E_WOOCOMMERCE || '';

	if ( '' === wordpress || '' === woocommerce ) {
		throw new Error(
			'The declared pair was not given. It is not guessed here, because the package declares it in `readme.txt` and '
			+ 'exactly one thing reads those declarations: run `php scripts/check-tested-up-to.php --emit`, export the two '
			+ 'lines it prints, and run this again.'
		);
	}

	const release = await newestReleaseOf( woocommerce );
	const core = `https://wordpress.org/wordpress-${ wordpress }.zip`;
	const plugin = `https://downloads.wordpress.org/plugin/woocommerce.${ release }.zip`;

	await refuseIfMissing( core );
	await refuseIfMissing( plugin );

	writeFileSync(
		OVERRIDE,
		`${ JSON.stringify(
			{
				core,

				/*
				 * The plugin under test travels as `"."`, and it is written
				 * every time: wp-env replaces this array wholesale.
				 */
				plugins: [ plugin, '.' ],
			},
			null,
			'\t'
		) }\n`,
		'utf8'
	);

	process.stdout.write(
		`Wrote ${ OVERRIDE } for WordPress ${ wordpress } and WooCommerce ${ woocommerce } (release ${ release }).\n`
		+ 'Recreate the environment (`npm run e2e:down && npx wp-env destroy && npm run e2e:up`) and run the suite with '
		+ `FACTUAREA_E2E_WORDPRESS=${ wordpress } FACTUAREA_E2E_WOOCOMMERCE=${ woocommerce } exported.\n`
	);
}

main().catch( ( failure ) => {
	process.stderr.write( `${ failure.message }\n` );
	process.exitCode = 1;
} );
