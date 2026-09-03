// @ts-check

/**
 * What actually leaves the shop, and what the outbox does when it is refused for
 * a reason that is not the shop's fault.
 *
 * The first scenario is the one that proves the delivery is indistinguishable
 * from a native WooCommerce webhook: the seven native headers, a signature over
 * the bytes that were sent, and the REST representation of the order carrying
 * the tax identifier in its meta. The receiver in the monorepo needs no line of
 * its own for this plugin precisely because of that, so anything that drifts
 * here is a receiver change nobody asked for.
 *
 * The second is the one that protects a paid sale. A 502 from a proxy refuses
 * nothing and carries no wait header; a plugin that read it as a refusal would
 * close the sale for ever. Five of them in a row have to leave the entry alive,
 * with a next attempt planned, and the delivery switch untouched - which is the
 * exact opposite of what WooCommerce's own webhooks do, since they disable
 * themselves after five failures. That self-disabling is the silence this
 * package exists to remove.
 */

const { test, expect } = require( '@playwright/test' );

const { execFileSync } = require( 'node:child_process' );

const {
	DELIVERY_HEADERS,
	VALID_TAX_ID,
	addProductToCart,
	deliveries,
	drain,
	enqueueScheduledDrain,
	fastForward,
	fixtures,
	outbox,
	placeClassicOrder,
	programReceiver,
	resetSite,
	settings,
} = require( './support/site' );

/**
 * Runs one pass of the outbox drain through Action Scheduler itself.
 *
 * Used ONCE, in the first scenario, and the reason it is used at all is that
 * everything else in this file fires the drain hook directly. Firing the hook
 * proves the dispatcher works; it does not prove the plugin ever asked anybody
 * to fire it. A plugin that never scheduled its recurring action would pass
 * every direct-drain assertion in this file and deliver nothing on a real shop.
 *
 * It is not used for the retry loop below, because the recurring action is
 * scheduled every sixty seconds: a second call inside the same minute finds
 * nothing due and would silently do nothing.
 */
function drainThroughScheduler() {
	return execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'cli',
			'wp',
			'action-scheduler',
			'run',
			'--hooks=factuarea_wc_drain_outbox',
			'--force',
		],
		{ encoding: 'utf8', timeout: 120_000 }
	);
}

test.describe( 'Delivery', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request );
	} );

	test( 'sends the seven native headers, a valid signature and the tax ID inside the body', async ( { page, request } ) => {
		const seeded = await fixtures( request );

		await programReceiver( request, { fallback: { status: 200, body: '' } } );

		await addProductToCart( page, seeded.product_id );
		await page.goto( seeded.classic_checkout_url );
		await expect( page.locator( '#billing_first_name' ) ).toBeVisible();

		const orderId = await placeClassicOrder( page, VALID_TAX_ID );

		/*
		 * ONE entry, not two. The paid-order identity is composed from the order
		 * itself by both the payment listener and the status listener, so a shop
		 * where both fire still owes the receiver exactly one document.
		 */
		const queued = await outbox( request );
		expect( queued.rows ).toHaveLength( 1 );
		expect( queued.rows[ 0 ].order_id ).toBe( orderId );
		expect( queued.rows[ 0 ].event_id ).toBe( `woocommerce:order:${ orderId }:paid` );
		expect( queued.rows[ 0 ].status ).toBe( 'pending' );

		/*
		 * The recurring action has to be on the schedule. Asserted before the
		 * drain, because after it the answer would be "yes, sixty seconds from
		 * now" either way.
		 */
		const configured = await settings( request );
		expect(
			configured.drain_scheduled_at,
			'The recurring drain is not scheduled. The outbox would fill up and never empty, which is the silence this plugin removes.'
		).toBeTruthy();

		await enqueueScheduledDrain( request );

		drainThroughScheduler();

		const sent = await deliveries( request );

		expect(
			sent,
			'The scheduler ran and nothing arrived at the receiver.'
		).toHaveLength( 1 );

		const delivery = sent[ 0 ];

		for ( const header of DELIVERY_HEADERS ) {
			expect(
				delivery.headers[ header ],
				`The delivery is missing ${ header }. It is one of the seven a native WooCommerce webhook sends, and the point of this plugin is that the receiver cannot tell the two apart.`
			).toBeTruthy();
		}

		expect( delivery.headers[ 'x-wc-webhook-topic' ] ).toBe( 'order.updated' );
		expect( delivery.headers[ 'x-wc-webhook-resource' ] ).toBe( 'order' );
		expect( delivery.headers[ 'x-wc-webhook-event' ] ).toBe( 'updated' );
		expect( delivery.headers[ 'x-wc-webhook-source' ] ).toBe( seeded.home_url );
		expect( delivery.headers[ 'x-wc-webhook-delivery-id' ] ).toBe( String( queued.rows[ 0 ].id ) );
		expect( delivery.headers[ 'content-type' ] ).toContain( 'application/json' );

		/*
		 * The signature is verified by the receiver over the bytes that arrived,
		 * with the same algorithm the real verifier uses. Recomputing it here
		 * would be comparing the harness with itself.
		 */
		expect(
			delivery.signature_valid,
			'The signature does not verify over the delivered bytes. Something re-serialised the body after signing it, which the receiver sees as a forged delivery.'
		).toBe( true );

		expect( delivery.body_is_json ).toBe( true );
		expect( delivery.body.id ).toBe( orderId );

		/*
		 * ON THE DELIVERED BODY, and this is the assertion the whole tax
		 * identifier chain comes down to. The connector reads the body, not the
		 * shop's database: a value that is stored on the order and does not
		 * survive into the representation was captured for nothing.
		 */
		const taxIdMeta = ( delivery.body.meta_data || [] ).filter(
			( meta ) => meta.key === '_factuarea_tax_id'
		);

		expect(
			taxIdMeta,
			'The tax ID is not in the meta_data of the delivered body. The connector reads the body and would invoice this B2B sale as a simplified invoice.'
		).toHaveLength( 1 );
		expect( String( taxIdMeta[ 0 ].value ) ).toBe( VALID_TAX_ID );

		const settled = await outbox( request );
		expect( settled.rows[ 0 ].status ).toBe( 'delivered' );
		expect( settled.rows[ 0 ].attempts ).toBe( 1 );
	} );

	test( 'keeps the entry alive with a next attempt after five transient failures, and does not switch delivery off', async ( { page, request } ) => {
		const seeded = await fixtures( request );

		/*
		 * A gateway's 502: no body at all, so no rejection code, so nothing that
		 * refuses anything. Five of them, which is exactly the number at which
		 * WooCommerce's native webhooks give up and disable themselves.
		 */
		await programReceiver( request, {
			responses: [ { times: 5, status: 502, body: '' } ],
			fallback: { status: 200, body: '' },
		} );

		await addProductToCart( page, seeded.product_id );
		await page.goto( seeded.classic_checkout_url );
		await expect( page.locator( '#billing_first_name' ) ).toBeVisible();

		await placeClassicOrder( page, VALID_TAX_ID );

		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			/*
			 * The backoff is real - sixty seconds, five minutes, half an hour -
			 * so the due moment is moved into the past between attempts instead
			 * of waited out. Nothing else about the entry is touched.
			 */
			await fastForward( request );
			await drain( request );

			const state = await outbox( request );

			expect(
				state.rows,
				`After transient failure ${ attempt } the outbox no longer holds the sale.`
			).toHaveLength( 1 );
			expect( state.rows[ 0 ].attempts ).toBe( attempt );
		}

		const sent = await deliveries( request );
		expect( sent ).toHaveLength( 5 );
		expect( sent.every( ( delivery ) => 502 === delivery.answered_status ) ).toBe( true );

		const state = await outbox( request );
		const entry = state.rows[ 0 ];

		expect(
			entry.status,
			'Five gateway errors closed a paid sale. A 502 refuses nothing: read as permanent, it loses the order for good.'
		).toBe( 'pending' );
		expect( entry.attempts ).toBe( 5 );
		expect(
			entry.next_attempt_at,
			'The entry is pending with no next attempt planned, so nothing will ever pick it up again.'
		).toBeGreaterThan( state.now );
		expect( entry.last_status_code ).toBe( 502 );

		const configured = await settings( request );

		expect(
			configured.delivery_enabled,
			'Delivery switched itself off after five failures. That is what WooCommerce\'s native webhooks do, and it is the silence this plugin exists to remove.'
		).toBe( '1' );
	} );
} );
