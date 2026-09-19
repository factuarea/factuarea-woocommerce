// @ts-check

/**
 * The receivers of the fulfilment status and of the refund, exercised over the
 * wire against a real WooCommerce.
 *
 * WHAT ONLY THIS FILE CAN PROVE
 *
 * Every step of these two lanes has a unit test, and none of those tests can see
 * the three things that matter most on a merchant's shop:
 *
 *   - that the status the platform publishes lands on the order WITHOUT the
 *     native WooCommerce status moving, which is what stops the plugin from
 *     sending the fact it just received straight back out;
 *   - that the refund the platform publishes reaches the buyer's money -
 *     WooCommerce's own total refunded, not a meta field this plugin wrote -
 *     and that draining the outbox afterwards sends NOTHING back, which is the
 *     anti-loop guard actually observed instead of asserted about a double;
 *   - that the buyer reads the state and reaches the carrier from the page they
 *     bought on.
 *
 * WHY THE BODIES ARE THE FROZEN FIXTURES, BYTE FOR BYTE
 *
 * `tests/fixtures/inbound/` holds the bodies of the frozen contract, and the
 * signature covers their key order, their indentation and their final newline.
 * They are read here as raw bytes and POSTed as those bytes: a scenario that
 * decoded and re-encoded would be exercising its own serialiser rather than the
 * contract, and would be told 401 by a receiver that was perfectly right.
 *
 * That is also why the SHOP is bent to the fixture rather than the other way
 * round: the order is created under the identifier the fixtures name, and the
 * sale is worth exactly what the total refund notice declares - which is what
 * makes a total refund actually total, and therefore what makes WooCommerce
 * move the order to `refunded` and the anti-loop guard of the status listener
 * observable at all.
 */

const { test, expect } = require( '@playwright/test' );

const {
	ORDER_STATUS_RECEIVER_OPTION,
	createOrder,
	deliveries,
	drain,
	fieldOf,
	inboundFixture,
	ledger,
	merchantRefund,
	order,
	orderNotes,
	outbox,
	programReceiver,
	publishInbound,
	purgeLedger,
	resetSite,
	setOptions,
	signIn,
} = require( './support/site' );

/** The shipped notice with its tracking, exactly as the contract froze it. */
const SHIPPED = inboundFixture( 'order-status/shipped-with-tracking.json' );

/** The notice that says the parcel arrived, published after the shipped one. */
const DELIVERED = inboundFixture( 'order-status/delivered.json' );

/** The refund of the whole sale, with the reference of its corrective invoice. */
const TOTAL_REFUND = inboundFixture( 'order-refund/total-with-corrective-reference.json' );

/** The refund of part of the sale. */
const PARTIAL_REFUND = inboundFixture( 'order-refund/partial-with-corrective-reference.json' );

/** The order every one of those bodies names. */
const ORDER_ID = Number( fieldOf( SHIPPED, 'order_id' ) );

/** What the sale is worth, taken from the notice that refunds all of it. */
const ORDER_TOTAL = String( fieldOf( TOTAL_REFUND, 'amount' ) );

/** The tracking the shipped notice carries. */
const TRACKING = fieldOf( SHIPPED, 'tracking' );

/**
 * The notes this plugin wrote on an order, told apart from WooCommerce's own.
 *
 * @param {Array<{content: string}>} notes Every note of the order.
 * @returns {Array<any>} Only the ones the fulfilment writer added.
 */
function fulfilmentNotes( notes ) {
	return notes.filter( ( note ) => note.content.startsWith( 'Factuarea fulfilment status' ) );
}

test.describe( 'Inbound order status and refund', () => {
	/** What the order-building route answered, for the scenario that signs in. */
	let built;

	test.beforeEach( async ( { request } ) => {
		/*
		 * The three bodies have to be about the SAME order, or the scenario
		 * below would build one order and publish notices about another, and
		 * every 404 would read as a receiver that cannot find what is there.
		 */
		expect( Number( fieldOf( TOTAL_REFUND, 'order_id' ) ) ).toBe( ORDER_ID );
		expect( Number( fieldOf( PARTIAL_REFUND, 'order_id' ) ) ).toBe( ORDER_ID );

		await purgeLedger( request );
		await setOptions( request, { [ ORDER_STATUS_RECEIVER_OPTION ]: '1' } );

		built = await createOrder( request, ORDER_ID, ORDER_TOTAL );

		expect( built.id ).toBe( ORDER_ID );
		expect( built.total ).toBe( ORDER_TOTAL );
		expect( built.status ).toBe( 'processing' );

		/*
		 * AFTER the order is built, not before. Creating a paid sale is itself
		 * a fact this plugin queues, and every assertion below about "nothing
		 * came back out" is about the inbound notice - so the row the scenery
		 * produced is cleared here, together with the recorded deliveries and
		 * the receiver's scripted answers.
		 */
		await resetSite( request );
		await programReceiver( request, { fallback: { status: 200, body: '' } } );
	} );

	test( 'applies a shipped notice, leaves the native status alone, shows it to the buyer, and discards the redelivery', async ( { page, request } ) => {
		const published = await publishInbound( request, { route: 'order-status', body: SHIPPED } );

		expect(
			published.status,
			`The receiver at ${ published.address } did not accept the frozen notice: ${ published.raw }`
		).toBe( 204 );
		expect(
			published.raw,
			'An accepted notice answers with no body at all; anything else is a shape the sender does not read.'
		).toBe( '' );

		const applied = await order( request, ORDER_ID );

		expect( applied.meta[ '_factuarea_fulfilment_status' ] ).toBe( fieldOf( SHIPPED, 'status' ) );
		expect( applied.meta[ '_factuarea_fulfilment_applied_at' ] ).toBe( fieldOf( SHIPPED, 'occurred_at' ) );
		expect( applied.meta[ '_factuarea_tracking_number' ] ).toBe( TRACKING.number );
		expect( applied.meta[ '_factuarea_tracking_carrier' ] ).toBe( TRACKING.carrier );
		expect( applied.meta[ '_factuarea_tracking_url' ] ).toBe( TRACKING.url );

		/*
		 * THE ASSERTION THIS WHOLE LANE IS BUILT AROUND. Writing the native
		 * status would fire this plugin's own status listener, which would queue
		 * the fact for delivery back to Factuarea, and would fire every
		 * automation the merchant has installed as well.
		 */
		expect(
			applied.status,
			'The inbound notice moved the native WooCommerce status. That is a loop: the shop would send the platform back the fact the platform just published.'
		).toBe( 'processing' );

		const recorded = await ledger( request );

		expect( recorded.count ).toBe( 1 );
		expect( recorded.rows[ 0 ].idempotency_key ).toBe( fieldOf( SHIPPED, 'idempotency_key' ) );
		expect(
			recorded.rows[ 0 ].applied_at,
			'The delivery was claimed and never confirmed, so a retry would be answered as a redelivery of something that never landed.'
		).toBeGreaterThan( 0 );

		/*
		 * The note is PRIVATE. It is the merchant's record of what the platform
		 * said, and a note marked as a customer note is emailed to the buyer -
		 * which would turn every status publication into a message nobody asked
		 * for.
		 */
		const notes = fulfilmentNotes( await orderNotes( request, ORDER_ID ) );

		expect( notes ).toHaveLength( 1 );
		expect( notes[ 0 ].content ).toContain( TRACKING.number );
		expect(
			notes[ 0 ].customer_note,
			'The note about the fulfilment status is a customer note, so WooCommerce emails it to the buyer on every publication.'
		).toBe( false );

		// What the buyer sees, on the page they bought on.
		await signIn( page, built.buyer_login, built.buyer_password );
		await page.goto( built.view_order_url );

		const shipping = page.locator( 'section.factuarea-fulfilment' );

		await expect( shipping ).toBeVisible();
		await expect( shipping.locator( '.factuarea-fulfilment__title' ) ).toHaveText( 'Shipping status' );
		await expect( shipping ).toContainText( 'Shipped' );
		await expect( shipping ).toContainText( TRACKING.carrier );
		await expect( shipping ).toContainText( TRACKING.number );

		const access = shipping.locator( 'a.factuarea-fulfilment__link' );

		await expect( access ).toHaveText( 'Track your shipment' );
		await expect( access ).toHaveAttribute( 'href', TRACKING.url );
		await expect(
			access,
			'The access to the carrier opens in the shop\'s own window, so the buyer loses the order they were reading.'
		).toHaveAttribute( 'target', '_blank' );
		await expect( access ).toHaveAttribute( 'rel', /noopener/ );
		await expect( access ).toHaveAttribute( 'rel', /noreferrer/ );

		await page.goto( built.orders_url );

		const rowAction = page.locator( 'a.factuarea-tracking' );

		await expect( rowAction ).toHaveCount( 1 );
		await expect( rowAction ).toHaveText( 'Track shipment' );
		await expect( rowAction ).toHaveAttribute( 'href', TRACKING.url );

		/*
		 * THE REDELIVERY. The same bytes again, which is what the sender does
		 * when it never saw the first answer.
		 */
		const again = await publishInbound( request, { route: 'order-status', body: SHIPPED } );

		expect(
			again.status,
			'The same delivery was accepted twice. The sender reads a redelivery as PUBLISHED; anything else invites it to keep trying.'
		).toBe( 409 );
		expect( again.body.message ).toContain( 'already been applied' );

		const afterSecond = await order( request, ORDER_ID );

		expect( afterSecond.meta ).toEqual( applied.meta );
		expect( afterSecond.status ).toBe( 'processing' );

		const stillOne = await ledger( request );

		expect( stillOne.count ).toBe( 1 );
		expect( stillOne.rows[ 0 ].received_at ).toBe( recorded.rows[ 0 ].received_at );

		expect(
			fulfilmentNotes( await orderNotes( request, ORDER_ID ) ),
			'The redelivery wrote a second note, so the merchant reads the same fact twice and cannot tell it from two publications.'
		).toHaveLength( 1 );
	} );

	test( 'shows the buyer nothing before any notice, and takes the carrier access away when the parcel arrives', async ( { page, request } ) => {
		await signIn( page, built.buyer_login, built.buyer_password );
		await page.goto( built.view_order_url );

		/*
		 * NOTHING before a notice has been received. An empty section with a
		 * heading and no rows would tell the buyer their order has a shipping
		 * state that nobody has published.
		 */
		await expect( page.locator( 'section.factuarea-fulfilment' ) ).toHaveCount( 0 );
		await expect( page.locator( 'section.factuarea-refund' ) ).toHaveCount( 0 );

		expect( ( await publishInbound( request, { route: 'order-status', body: SHIPPED } ) ).status ).toBe( 204 );
		expect( ( await publishInbound( request, { route: 'order-status', body: DELIVERED } ) ).status ).toBe( 204 );

		/*
		 * The platform only sends tracking with the shipped state, so a
		 * delivered order holds none. An access built from what was there
		 * BEFORE would send the buyer to a carrier page about a parcel that has
		 * already arrived - and, once the carrier retires the reference, to
		 * nothing at all.
		 */
		const arrived = await order( request, ORDER_ID );

		expect( arrived.meta[ '_factuarea_fulfilment_status' ] ).toBe( fieldOf( DELIVERED, 'status' ) );
		expect( arrived.meta[ '_factuarea_tracking_number' ] ).toBeUndefined();
		expect( arrived.meta[ '_factuarea_tracking_carrier' ] ).toBeUndefined();
		expect( arrived.meta[ '_factuarea_tracking_url' ] ).toBeUndefined();

		await page.goto( built.view_order_url );

		const shipping = page.locator( 'section.factuarea-fulfilment' );

		await expect( shipping ).toContainText( 'Delivered' );
		await expect(
			shipping.locator( 'a.factuarea-fulfilment__link' ),
			'The order is delivered and the buyer is still offered an access to follow the parcel.'
		).toHaveCount( 0 );

		await page.goto( built.orders_url );

		await expect( page.locator( 'a.factuarea-tracking' ) ).toHaveCount( 0 );
	} );

	test( 'applies an inbound refund to the money, and draining the outbox sends nothing back', async ( { page, request } ) => {
		const published = await publishInbound( request, { route: 'order-refund', body: TOTAL_REFUND } );

		expect( published.status, `The refund notice was refused: ${ published.raw }` ).toBe( 204 );

		const refunded = await order( request, ORDER_ID );

		/*
		 * WooCommerce's OWN arithmetic, not a meta field of this plugin. A
		 * refund that were only recorded as meta would leave the buyer's order
		 * showing the full amount as still charged.
		 */
		expect(
			refunded.total_refunded,
			'WooCommerce does not consider this sale refunded, so the buyer still reads the whole amount as charged.'
		).toBe( ORDER_TOTAL );
		expect( refunded.refunds ).toHaveLength( 1 );
		expect( refunded.refunds[ 0 ].amount ).toBe( ORDER_TOTAL );
		expect(
			refunded.refunds[ 0 ].inbound_mark,
			'The refund carries no mark of inbound origin, so the shop will deliver it back to Factuarea as a return of its own.'
		).toBe( fieldOf( TOTAL_REFUND, 'idempotency_key' ) );

		expect( refunded.meta[ '_factuarea_corrective_reference' ] ).toBe(
			fieldOf( TOTAL_REFUND, 'corrective_invoice_reference' )
		);
		expect( refunded.meta[ '_factuarea_refund_scope' ] ).toBe( fieldOf( TOTAL_REFUND, 'scope' ) );

		/*
		 * The whole sale came back, so WooCommerce moves the order to
		 * `refunded` ITSELF - which is the one situation where the status
		 * listener's anti-loop guard is reachable, because a partial refund
		 * never changes the status at all.
		 */
		expect( refunded.status ).toBe( 'refunded' );

		/*
		 * DRAINED FOR REAL. A scenario that looked at the outbox without
		 * draining would be asserting that nothing has been SENT YET, which is
		 * true of a queue that is about to send it.
		 */
		await drain( request );

		expect(
			( await outbox( request ) ).rows,
			'The inbound refund queued a delivery back to Factuarea. That is the loop: the platform would receive its own refund as a return of the shop and file a second corrective invoice over the same sale.'
		).toHaveLength( 0 );
		expect( await deliveries( request ) ).toHaveLength( 0 );

		// The buyer reads the document the money came back under.
		await signIn( page, built.buyer_login, built.buyer_password );
		await page.goto( built.view_order_url );

		const refund = page.locator( 'section.factuarea-refund' );

		await expect( refund ).toBeVisible();
		await expect( refund.locator( '.factuarea-refund__title' ) ).toHaveText( 'Refund' );
		await expect( refund ).toContainText( 'Refunded in full' );
		await expect( refund ).toContainText( fieldOf( TOTAL_REFUND, 'corrective_invoice_reference' ) );
	} );

	test( 'a refund the merchant makes still leaves the shop, even on an order that already took an inbound one', async ( { request } ) => {
		const published = await publishInbound( request, { route: 'order-refund', body: PARTIAL_REFUND } );

		expect( published.status, `The partial refund notice was refused: ${ published.raw }` ).toBe( 204 );

		await drain( request );

		expect(
			( await outbox( request ) ).rows,
			'The inbound refund came back out of the shop.'
		).toHaveLength( 0 );

		/*
		 * Now the MERCHANT refunds what is left, from WooCommerce, with no mark
		 * of inbound origin on it. The guard has to be narrow: it holds back the
		 * refund the platform published and nothing else, or the shop would go
		 * silent about its own returns.
		 *
		 * The amount is what the sale has left precisely because WooCommerce
		 * refuses a refund beyond the remaining refundable amount - measured at
		 * `wc-order-functions.php:548` of the pinned 8.9.5 - so the control has
		 * to be made on a sale that still has money in it.
		 */
		const remaining = ( Number( ORDER_TOTAL ) - Number( fieldOf( PARTIAL_REFUND, 'amount' ) ) ).toFixed( 2 );

		await merchantRefund( request, ORDER_ID, remaining );

		const queued = await outbox( request );

		expect(
			queued.rows,
			'The merchant refunded a sale in WooCommerce and Factuarea was never told. The anti-loop guard is muting the shop instead of only muting the echo.'
		).toHaveLength( 1 );
		expect( queued.rows[ 0 ].order_id ).toBe( ORDER_ID );

		await drain( request );

		const sent = await deliveries( request );

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].body_is_json ).toBe( true );
		expect( sent[ 0 ].signature_valid ).toBe( true );

		const settled = await order( request, ORDER_ID );

		expect( settled.refunds ).toHaveLength( 2 );
		expect(
			settled.refunds.filter( ( entry ) => '' === entry.inbound_mark ),
			'Exactly one of the two refunds is the merchant\'s own, and it is the one that had to travel.'
		).toHaveLength( 1 );
	} );

	test( 'refuses a notice about an order this shop does not have, and records nothing', async ( { request } ) => {
		/*
		 * The frozen body with one value changed and the signature recomputed
		 * over what is actually sent - which is the difference between testing
		 * the 404 and testing the 401.
		 */
		const elsewhere = SHIPPED.replace( `"${ ORDER_ID }"`, '"999999"' );

		expect( elsewhere ).not.toBe( SHIPPED );

		const published = await publishInbound( request, { route: 'order-status', body: elsewhere } );

		expect(
			published.status,
			`A notice about an order of another shop was not refused as unknown: ${ published.raw }`
		).toBe( 404 );
		expect( published.body.message ).toContain( 'Nothing similar was looked for' );

		expect(
			( await ledger( request ) ).count,
			'A permanent refusal claimed the idempotency key, so the sender would be answered 409 - which it reads as PUBLISHED - if the order ever appeared.'
		).toBe( 0 );

		const untouched = await order( request, ORDER_ID );

		expect( untouched.meta[ '_factuarea_fulfilment_status' ] ).toBeUndefined();
		expect( untouched.status ).toBe( 'processing' );
	} );

	test( 'refuses a notice whose signature does not cover the bytes that arrived, without a word about why', async ( { request } ) => {
		/*
		 * Signed over a body that differs from the one sent by a single
		 * character, which is what a delivery altered in flight looks like. A
		 * header full of rubbish would be refused by a receiver that only
		 * checked the header was there.
		 */
		const altered = SHIPPED.replace( '"shipped"', '"delivered"' );

		expect( altered ).not.toBe( SHIPPED );

		const published = await publishInbound( request, {
			route: 'order-status',
			body: SHIPPED,
			signOver: altered,
		} );

		expect( published.status ).toBe( 401 );
		expect(
			published.raw,
			'The refusal carries a body, which tells whoever is probing which of the three ways of failing authentication they hit.'
		).toBe( '' );

		expect( ( await ledger( request ) ).count ).toBe( 0 );

		const untouched = await order( request, ORDER_ID );

		expect( untouched.meta[ '_factuarea_fulfilment_status' ] ).toBeUndefined();
	} );
} );
