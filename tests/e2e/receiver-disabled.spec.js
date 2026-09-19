// @ts-check

/**
 * What a switched-off receiver does, and what happens to the delivery it
 * refused once it is switched on.
 *
 * WHY THE SECOND HALF IS THE POINT
 *
 * "Nothing was applied" is easy to believe and easy to get wrong in a way that
 * nobody notices for months. A receiver that refused the delivery AFTER
 * claiming its idempotency key would look identical from outside: the same 403,
 * the same untouched order. The difference only shows on the day the merchant
 * switches the receiver on and the platform republishes - at which point the
 * key is already in the ledger, the delivery is answered as a redelivery, the
 * sender reads that as PUBLISHED, and the notice is lost with both sides
 * believing it arrived.
 *
 * So each scenario here publishes the SAME BYTES twice, with the switch flipped
 * in between, and the second publication has to land. That is the only
 * observation that tells the two implementations apart.
 *
 * THE ROUTES ARE REGISTERED EITHER WAY. A switched-off receiver answers 403 with
 * a body that says so - never a 404, which the sender would read as "this shop
 * has no such address" and which would send a merchant looking for a broken
 * installation instead of an unticked box.
 */

const { test, expect } = require( '@playwright/test' );

const {
	CATALOG_PUSH_RECEIVER_OPTION,
	ORDER_STATUS_RECEIVER_OPTION,
	createOrder,
	createProduct,
	fieldOf,
	inboundFixture,
	ledger,
	order,
	productBySku,
	publishInbound,
	purgeLedger,
	resetSite,
	setOptions,
} = require( './support/site' );

/** The shipped notice with its tracking, exactly as the contract froze it. */
const SHIPPED = inboundFixture( 'order-status/shipped-with-tracking.json' );

/** The refund of the whole sale, read only for the total it declares. */
const TOTAL_REFUND = inboundFixture( 'order-refund/total-with-corrective-reference.json' );

/** Five articles, of which this shop could apply three. */
const BATCH = inboundFixture( 'catalog-push/batch-of-five-with-two-unresolvable.json' );

/** The order the status notice names. */
const ORDER_ID = Number( fieldOf( SHIPPED, 'order_id' ) );

/** What the sale is worth. */
const ORDER_TOTAL = String( fieldOf( TOTAL_REFUND, 'amount' ) );

/** The first article of the batch, which is the one these scenarios watch. */
const WATCHED = fieldOf( BATCH, 'items' )[ 0 ];

test.describe( 'Switched-off receivers', () => {
	test.beforeEach( async ( { request } ) => {
		await purgeLedger( request );

		await setOptions( request, {
			[ ORDER_STATUS_RECEIVER_OPTION ]: '0',
			[ CATALOG_PUSH_RECEIVER_OPTION ]: '0',
		} );

		/*
		 * The order first and the article second, and the order is not free:
		 * building the order under the identifier the fixture names clears
		 * everything the shop created at or above that number, articles
		 * included.
		 */
		await createOrder( request, ORDER_ID, ORDER_TOTAL );

		await createProduct( request, {
			sku: WATCHED.sku,
			name: 'Mug, as the shop named it',
			regular_price: '5.00',
			manage_stock: true,
			stock_quantity: 0,
		} );

		await resetSite( request );
	} );

	test( 'a switched-off order status receiver applies nothing and records nothing, and the same notice lands once it is switched on', async ( { request } ) => {
		const refused = await publishInbound( request, { route: 'order-status', body: SHIPPED } );

		expect(
			refused.status,
			`A switched-off receiver answered ${ refused.status }. A 404 would tell the sender this shop has no such address, and would send the merchant looking for a broken installation instead of an unticked box.`
		).toBe( 403 );
		expect( refused.body.message ).toContain( 'switched off' );

		expect(
			( await ledger( request ) ).count,
			'The switched-off receiver recorded the delivery it refused. Switching it on would then answer the republished notice as a redelivery, and the notice would be lost with both sides believing it arrived.'
		).toBe( 0 );

		const untouched = await order( request, ORDER_ID );

		expect( untouched.meta[ '_factuarea_fulfilment_status' ] ).toBeUndefined();
		expect( untouched.status ).toBe( 'processing' );

		// The merchant ticks the box.
		await setOptions( request, { [ ORDER_STATUS_RECEIVER_OPTION ]: '1' } );

		const landed = await publishInbound( request, { route: 'order-status', body: SHIPPED } );

		expect(
			landed.status,
			`The republished notice was answered ${ landed.status } instead of being applied: ${ landed.raw }`
		).toBe( 204 );

		const applied = await order( request, ORDER_ID );

		expect( applied.meta[ '_factuarea_fulfilment_status' ] ).toBe( fieldOf( SHIPPED, 'status' ) );
		expect( applied.meta[ '_factuarea_tracking_number' ] ).toBe( fieldOf( SHIPPED, 'tracking' ).number );

		const recorded = await ledger( request );

		expect( recorded.count ).toBe( 1 );
		expect( recorded.rows[ 0 ].idempotency_key ).toBe( fieldOf( SHIPPED, 'idempotency_key' ) );
	} );

	test( 'a switched-off catalogue receiver changes no article and records nothing, and the same batch lands once it is switched on', async ( { request } ) => {
		const before = await productBySku( request, WATCHED.sku );

		const refused = await publishInbound( request, { route: 'catalog-push', body: BATCH } );

		expect( refused.status ).toBe( 403 );
		expect( refused.body.message ).toContain( 'switched off' );

		expect( await productBySku( request, WATCHED.sku ) ).toEqual( before );
		expect(
			( await ledger( request ) ).count,
			'The switched-off receiver recorded the batch it refused, so the republished batch would be discarded as a redelivery and never applied.'
		).toBe( 0 );

		await setOptions( request, { [ CATALOG_PUSH_RECEIVER_OPTION ]: '1' } );

		const landed = await publishInbound( request, { route: 'catalog-push', body: BATCH } );

		expect(
			landed.status,
			`The republished batch was answered ${ landed.status } instead of being applied: ${ landed.raw }`
		).toBe( 200 );

		const applied = await productBySku( request, WATCHED.sku );

		expect( applied.name ).toBe( WATCHED.name );
		expect( applied.regular_price ).toBe( WATCHED.price );
		expect( applied.stock_quantity ).toBe( WATCHED.available_quantity );

		expect( ( await ledger( request ) ).count ).toBe( 1 );
	} );

	test( 'the two switches are independent of each other', async ( { request } ) => {
		/*
		 * One switch per receiver, and the contract says so: a merchant who
		 * wants the platform to publish fulfilment states is not thereby
		 * agreeing to let it rewrite the catalogue.
		 */
		await setOptions( request, { [ ORDER_STATUS_RECEIVER_OPTION ]: '1' } );

		expect( ( await publishInbound( request, { route: 'order-status', body: SHIPPED } ) ).status ).toBe( 204 );

		const stillOff = await publishInbound( request, { route: 'catalog-push', body: BATCH } );

		expect(
			stillOff.status,
			'Switching the status receiver on switched the catalogue receiver on as well, so a merchant who wanted to be told about deliveries also handed over their prices.'
		).toBe( 403 );
	} );
} );
