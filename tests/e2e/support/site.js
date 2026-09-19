// @ts-check

/**
 * The single place the scenarios talk to the containerised shop through.
 *
 * Everything that is not a browser interaction goes through the harness routes
 * of the two mu-plugins - the mock receiver and the support window - so that a
 * scenario reads like the story it is testing and not like a database session.
 */

/**
 * Shared token the harness control routes require. It is declared in
 * tests/e2e/mu-plugins/factuarea-mock-receiver.php and nowhere else.
 */
const MOCK_TOKEN = 'factuarea-e2e-token';

/** Base of the harness REST namespace. */
const MOCK_ROOT = '/wp-json/factuarea-mock/v1';

/** The tax identifier the scenarios type. A real, control-letter-valid NIF. */
const VALID_TAX_ID = '12345678Z';

/** The label both checkouts render for the field, as frozen in TaxIdField. */
const TAX_ID_LABEL = /Tax ID \(NIF\/NIE\/CIF\)/;

/** The seven native delivery headers, in the order the plugin freezes them. */
const DELIVERY_HEADERS = [
	'x-wc-webhook-source',
	'x-wc-webhook-topic',
	'x-wc-webhook-resource',
	'x-wc-webhook-event',
	'x-wc-webhook-signature',
	'x-wc-webhook-id',
	'x-wc-webhook-delivery-id',
];

/**
 * Calls one harness route and fails loudly when it does not answer.
 *
 * A harness call that quietly returned undefined would turn every assertion
 * after it into a comparison against nothing, which is how a broken environment
 * reads as a broken plugin.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {string} method HTTP method.
 * @param {string} path Route path under the harness namespace.
 * @param {object} [body] JSON body, for the writes.
 * @returns {Promise<any>} The decoded answer.
 */
async function harness( request, method, path, body ) {
	const response = await request.fetch( `${ MOCK_ROOT }${ path }`, {
		method,
		headers: {
			'X-Factuarea-Mock-Token': MOCK_TOKEN,
			'Content-Type': 'application/json',
		},
		data: body === undefined ? undefined : JSON.stringify( body ),
	} );

	if ( ! response.ok() ) {
		throw new Error(
			`The harness route ${ method } ${ path } answered ${ response.status() }: ${ await response.text() }`
		);
	}

	return response.json();
}

/**
 * Clears everything one scenario can see: the recorded deliveries, the scripted
 * answers and the outbox.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function resetSite( request ) {
	await harness( request, 'POST', '/reset' );
	await harness( request, 'POST', '/outbox/purge' );
}

/**
 * Tells the mock receiver what to answer.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {{responses?: object[], fallback?: object}} plan The scripted answers.
 */
async function programReceiver( request, plan ) {
	return harness( request, 'POST', '/program', plan );
}

/**
 * Everything the mock receiver has been sent.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function deliveries( request ) {
	return harness( request, 'GET', '/deliveries' );
}

/**
 * The outbox as it stands right now, with the site's own clock alongside it so
 * that "is the next attempt in the future" is answered against the site's time
 * and not against the host's.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {boolean} [withBody] Whether to pull the delivered bodies too.
 */
async function outbox( request, withBody = false ) {
	return harness( request, 'GET', `/outbox${ withBody ? '?with_body=1' : '' }` );
}

/**
 * Fires the same action the scheduler fires, once.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function drain( request ) {
	return harness( request, 'POST', '/drain' );
}

/**
 * Puts one due Action Scheduler action on the queue for the plugin's drain hook.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function enqueueScheduledDrain( request ) {
	return harness( request, 'POST', '/scheduler/enqueue-drain' );
}

/**
 * Moves the due moment of every pending entry into the past, so that the next
 * drain considers it. It changes nothing else - not a status, not an attempt
 * count, not a message.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function fastForward( request ) {
	return harness( request, 'POST', '/outbox/rewind' );
}

/**
 * What the plugin wrote on one order.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {number} orderId The order.
 */
async function order( request, orderId ) {
	return harness( request, 'GET', `/order/${ orderId }` );
}

/**
 * The plugin's configuration, with the two credentials reported present or
 * absent and never printed.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function settings( request ) {
	return harness( request, 'GET', '/settings' );
}

/**
 * What the seed left behind: product, the two checkout addresses and the
 * versions actually running.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function fixtures( request ) {
	return harness( request, 'GET', '/fixtures' );
}

/**
 * Puts the seeded product in the cart of this browser context.
 *
 * Through the shop's own add-to-cart URL rather than by driving the product
 * page, because what is being tested starts at the checkout and a scenario that
 * spent thirty seconds on a product page would be slower without proving more.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {number} productId The product to add.
 */
async function addProductToCart( page, productId ) {
	await page.goto( `/?add-to-cart=${ productId }` );
}

/**
 * Pulls the order number out of the address the shop redirected to after the
 * order was placed.
 *
 * @param {import('@playwright/test').Page} page The page, sitting on the received page.
 * @returns {number} The order number.
 */
function orderIdFromUrl( page ) {
	const match = page.url().match( /order-received\/(\d+)/ );

	if ( ! match ) {
		throw new Error(
			`The shop did not end up on an order-received address; it is on ${ page.url() }. No order was placed.`
		);
	}

	return Number( match[ 1 ] );
}

/**
 * The container the block checkout paints the billing fields into.
 *
 * Assertions about a field's validation message are scoped to it, because
 * WooCommerce also announces the same sentence in the page-wide accessibility
 * live region. Both are correct and wanted; an unscoped locator simply matches
 * two elements and fails on strict mode, which says nothing about the plugin.
 */
const BLOCK_BILLING_FIELDS = '#billing-fields';

/**
 * Fills a Spanish billing address on the BLOCK checkout.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function fillBlockAddress( page ) {
	await page.locator( '#email' ).fill( 'ada@example.com' );
	await page.getByLabel( /First name/i ).fill( 'Ada' );
	await page.getByLabel( /Last name/i ).fill( 'Lovelace' );
	await page.getByLabel( /^Address/i ).first().fill( 'Calle Mayor 1' );
	await page.getByLabel( /City/i ).fill( 'Madrid' );
	await page.getByLabel( /Postal code|Postcode|ZIP/i ).fill( '28001' );
}

/**
 * Places the order on the BLOCK checkout and waits for the shop to confirm it.
 *
 * WAITING FOR THE CHECKOUT TO SETTLE BEFORE CLICKING IS NOT PADDING.
 *
 * The block checkout pushes every address edit to the shop and recalculates the
 * cart. While that is in flight, "Place Order" does nothing at all - no error,
 * no notice, no request - so a click sent immediately after the last keystroke
 * is simply swallowed. MEASURED: with the click sent straight away, no POST to
 * the checkout endpoint was made at all; with the field blurred and the network
 * idle first, the same code placed the order.
 *
 * That is a property of the checkout under test, not a flake, which is why it is
 * handled by waiting for a real signal rather than by a retry or a sleep.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @returns {Promise<number>} The order number.
 */
async function placeBlockOrder( page ) {
	await page.locator( 'body' ).click( { position: { x: 0, y: 0 } } );
	await page.waitForLoadState( 'networkidle' );

	await page.getByRole( 'button', { name: /place order/i } ).click();
	await page.waitForURL( /order-received/, { timeout: 60_000 } );

	return orderIdFromUrl( page );
}

/**
 * Fills what a Spanish billing address needs on the CLASSIC checkout and places
 * the order.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string|null} taxId The tax identifier to type, or null to leave it empty.
 * @returns {Promise<number>} The order number.
 */
async function placeClassicOrder( page, taxId ) {
	await page.fill( '#billing_first_name', 'Ada' );
	await page.fill( '#billing_last_name', 'Lovelace' );
	await page.fill( '#billing_address_1', 'Calle Mayor 1' );
	await page.fill( '#billing_city', 'Madrid' );
	await page.fill( '#billing_postcode', '28001' );
	await page.fill( '#billing_email', 'ada@example.com' );

	if ( null !== taxId ) {
		await page.fill( '#factuarea_tax_id', taxId );
	}

	await page.locator( '#place_order' ).click();
	await page.waitForURL( /order-received/, { timeout: 60_000 } );

	return orderIdFromUrl( page );
}

/* -------------------------------------------------------------------------
 * THE INBOUND HALF
 * ------------------------------------------------------------------------- */

const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );

/** Header the plugin's own store identifier travels in, spelled as on the wire. */
const STORE_ID_HEADER = 'x-factuarea-store-id';

/** Option the merchant's store identifier is kept in. */
const STORE_ID_OPTION = 'factuarea_wc_store_id';

/** Option that switches the order status and refund receivers on. */
const ORDER_STATUS_RECEIVER_OPTION = 'factuarea_wc_order_status_receiver_enabled';

/** Option that switches the catalogue, price and stock receiver on. */
const CATALOG_PUSH_RECEIVER_OPTION = 'factuarea_wc_catalog_push_receiver_enabled';

/**
 * One of the frozen inbound bodies, as the EXACT BYTES it is on disk.
 *
 * Read with no decoding step of any kind, and this is the whole point of the
 * helper. Those files are the bodies the contract froze - `tests/fixtures/inbound/` -
 * and the signature the sender puts on them covers key order, indentation and
 * the final newline. A scenario that went through `require()` or `JSON.parse`
 * and re-encoded would send a body that means the same and IS different, and
 * every delivery would come back 401 for a reason that says nothing about the
 * plugin.
 *
 * @param {string} relative Path under tests/fixtures/inbound.
 * @returns {string} The file, byte for byte.
 */
function inboundFixture( relative ) {
	return readFileSync( join( __dirname, '..', '..', 'fixtures', 'inbound', relative ), 'utf8' );
}

/**
 * A field of a frozen body, for the scenario to read.
 *
 * Reading is not sending: the decoded copy is used to know WHICH order or key
 * the body names, while the bytes that travel are always the ones above.
 *
 * @param {string} raw One of the frozen bodies.
 * @param {string} field Field to read.
 * @returns {any} Whatever that field holds.
 */
function fieldOf( raw, field ) {
	return JSON.parse( raw )[ field ];
}

/**
 * Publishes one inbound notice at the shop, the way the platform would.
 *
 * The harness SIGNS inside the container with the shop's own secret and POSTs
 * the bytes at the published address over real HTTP; what comes back is the
 * receiver's own answer, status and body.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {{route: string, body: string, signOver?: string, omitSignature?: boolean}} notice What to publish.
 * @returns {Promise<{status: number, raw: string, body: any, address: string, sent_bytes: number}>}
 */
async function publishInbound( request, notice ) {
	const payload = {
		route: notice.route,
		body: notice.body,
	};

	if ( notice.signOver !== undefined ) {
		payload.sign_over = notice.signOver;
	}

	if ( notice.omitSignature ) {
		payload.omit_signature = true;
	}

	return harness( request, 'POST', '/inbound/deliver', payload );
}

/**
 * The inbound idempotency ledger as it stands.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function ledger( request ) {
	return harness( request, 'GET', '/ledger' );
}

/**
 * Empties the inbound idempotency ledger.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 */
async function purgeLedger( request ) {
	return harness( request, 'POST', '/ledger/purge' );
}

/**
 * Writes options of the plugin under test, and only of the plugin under test.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {Record<string, string>} options What to write.
 */
async function setOptions( request, options ) {
	return harness( request, 'POST', '/options', { options } );
}

/**
 * Builds the paid order the frozen bodies name, under the identifier they name.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {number} id Identifier the fixture names.
 * @param {string} total Total of the sale.
 */
async function createOrder( request, id, total ) {
	return harness( request, 'POST', '/order/create', { id, total } );
}

/**
 * The notes of one order, the merchant's private ones included.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {number} orderId The order.
 */
async function orderNotes( request, orderId ) {
	return harness( request, 'GET', `/order/${ orderId }/notes` );
}

/**
 * A refund made by the MERCHANT in WooCommerce, carrying no mark of inbound
 * origin: the control of the anti-loop guard.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {number} orderId The order.
 * @param {string} amount Amount to refund.
 */
async function merchantRefund( request, orderId, amount ) {
	return harness( request, 'POST', `/order/${ orderId }/refund`, { amount } );
}

/**
 * Creates one article of the catalogue scenarios from scratch.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {object} article What the article starts out as.
 */
async function createProduct( request, article ) {
	return harness( request, 'POST', '/product/create', article );
}

/**
 * What the shop holds about one article.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {string} sku Reference of the article.
 */
async function productBySku( request, sku ) {
	return harness( request, 'GET', `/product/by-sku/${ sku }` );
}

/**
 * Removes articles by reference, so a scenario can start from "this one does
 * not exist".
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
 * @param {string[]} skus References to remove.
 */
async function deleteProducts( request, skus ) {
	return harness( request, 'POST', '/products/delete', { skus } );
}

/**
 * Signs the buyer in, through the shop's own login form.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} login The account.
 * @param {string} password Its password.
 */
async function signIn( page, login, password ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin|my-account/ );
}

module.exports = {
	MOCK_TOKEN,
	MOCK_ROOT,
	VALID_TAX_ID,
	TAX_ID_LABEL,
	DELIVERY_HEADERS,
	STORE_ID_HEADER,
	STORE_ID_OPTION,
	ORDER_STATUS_RECEIVER_OPTION,
	CATALOG_PUSH_RECEIVER_OPTION,
	BLOCK_BILLING_FIELDS,
	fillBlockAddress,
	placeBlockOrder,
	harness,
	resetSite,
	programReceiver,
	deliveries,
	outbox,
	drain,
	enqueueScheduledDrain,
	fastForward,
	order,
	settings,
	fixtures,
	addProductToCart,
	orderIdFromUrl,
	placeClassicOrder,
	inboundFixture,
	fieldOf,
	publishInbound,
	ledger,
	purgeLedger,
	setOptions,
	createOrder,
	orderNotes,
	merchantRefund,
	createProduct,
	productBySku,
	deleteProducts,
	signIn,
};
