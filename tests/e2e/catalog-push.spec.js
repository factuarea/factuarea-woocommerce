// @ts-check

/**
 * The receiver of the catalogue, price and stock push, exercised against a real
 * WooCommerce catalogue.
 *
 * WHAT ONLY THIS FILE CAN PROVE
 *
 * The unit suite drives the three writers against doubles, and a double will
 * happily report that a price was written. What it cannot show is what
 * WooCommerce then CHARGES, which is a different number the moment an article
 * is on sale, nor that an article the batch names cannot be told apart from one
 * that merely looks similar, nor that a batch refused as malformed left the
 * catalogue exactly as it found it.
 *
 * The bodies are the frozen fixtures of `tests/fixtures/inbound/catalog-push/`,
 * read as raw bytes and POSTed as those bytes: the signature covers them
 * exactly as they are on disk, final newline included.
 *
 * WHY THE ARTICLES ARE BUILT FROM SCRATCH BEFORE EVERY SCENARIO
 *
 * Because every assertion here is about what the push CHANGED. An article left
 * behind by the previous scenario already carries the name, the price and the
 * stock the batch declares, and the same assertions would pass against a
 * receiver that did nothing at all.
 */

const { test, expect } = require( '@playwright/test' );

const {
	CATALOG_PUSH_RECEIVER_OPTION,
	createProduct,
	deleteProducts,
	fieldOf,
	inboundFixture,
	ledger,
	productBySku,
	publishInbound,
	purgeLedger,
	resetSite,
	setOptions,
} = require( './support/site' );

/** Five articles, of which this shop can apply three. */
const BATCH = inboundFixture( 'catalog-push/batch-of-five-with-two-unresolvable.json' );

/** Two articles, the second carrying an amount that is not a number. */
const UNREADABLE = inboundFixture( 'catalog-push/batch-with-an-unreadable-amount.json' );

/** Two articles, the second carrying a datum that must never leave the platform. */
const INTERNAL = inboundFixture( 'catalog-push/batch-with-an-internal-datum.json' );

/** Two articles, the second carrying a key the contract does not enumerate. */
const UNKNOWN_KEY = inboundFixture( 'catalog-push/batch-with-an-unknown-key.json' );

/** The articles of the batch, in the order it declares them. */
const ITEMS = fieldOf( BATCH, 'items' );

/**
 * The references of the batch, spelled the way the receiver answers them: the
 * remote identity when the article carries one, and the reference otherwise.
 */
const REFERENCES = ITEMS.map( ( item ) => item.remote_id || item.sku );

/**
 * What the shop starts out with. Deliberately DIFFERENT from what the batch
 * declares in every field the batch touches.
 *
 * The fourth reference of the batch is absent on purpose - it is the article
 * the receiver cannot resolve - and the fifth exists but does not manage its
 * stock, which is the other refusal the batch has to produce. Between them they
 * are the two the frozen body is named after.
 */
const CATALOGUE = [
	{
		sku: 'SKU-0001',
		name: 'Mug, as the shop named it',
		description: '<p>Whatever the merchant wrote.</p>',
		regular_price: '5.00',
		manage_stock: true,
		stock_quantity: 0,
	},
	{
		sku: 'SKU-0002',
		name: 'Second article',
		regular_price: '1.00',
		manage_stock: true,
		stock_quantity: 99,
	},
	{
		sku: 'SKU-0003',
		name: 'Apron, as the shop named it',
		regular_price: '3.00',
		manage_stock: false,
	},
	{
		sku: 'SKU-0005',
		name: 'Article whose stock the shop does not track',
		regular_price: '7.00',
		manage_stock: false,
	},
];

/**
 * The outcome the response declares for one reference.
 *
 * @param {any} answer The decoded response of the receiver.
 * @param {string} reference Reference to look up.
 * @returns {any} The outcome, or undefined when the reference is not declared.
 */
function outcomeOf( answer, reference ) {
	return ( answer.items || [] ).find( ( item ) => item.reference === reference );
}

test.describe( 'Inbound catalogue, price and stock push', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request );
		await purgeLedger( request );
		await setOptions( request, { [ CATALOG_PUSH_RECEIVER_OPTION ]: '1' } );

		await deleteProducts( request, REFERENCES.filter( ( reference ) => reference.startsWith( 'SKU-' ) ) );

		for ( const article of CATALOGUE ) {
			await createProduct( request, article );
		}
	} );

	test( 'applies the articles it can resolve, and declares the outcome of every one of the five', async ( { request } ) => {
		const published = await publishInbound( request, { route: 'catalog-push', body: BATCH } );

		expect(
			published.status,
			`The batch was not answered with the per-article outcome: ${ published.raw }`
		).toBe( 200 );

		const answer = published.body;

		expect(
			answer.items,
			'The response declares a different number of outcomes than the batch declared articles, so the sender cannot tell which of its articles landed.'
		).toHaveLength( ITEMS.length );

		expect( outcomeOf( answer, REFERENCES[ 0 ] ).status ).toBe( 'applied' );
		expect( outcomeOf( answer, REFERENCES[ 1 ] ).status ).toBe( 'applied' );
		expect( outcomeOf( answer, REFERENCES[ 2 ] ).status ).toBe( 'applied' );

		expect( outcomeOf( answer, REFERENCES[ 3 ] ).status ).toBe( 'rejected' );
		expect(
			outcomeOf( answer, REFERENCES[ 3 ] ).reason,
			'An article this shop does not have was rejected for the wrong reason, or created - and creating one is the platform\'s decision, never the receiver\'s.'
		).toBe( 'not_found' );

		expect( outcomeOf( answer, REFERENCES[ 4 ] ).status ).toBe( 'rejected' );
		expect(
			outcomeOf( answer, REFERENCES[ 4 ] ).reason,
			'An article whose stock the shop does not track was not rejected as such. Turning stock management on for it is a change to the merchant\'s catalogue that nobody asked for.'
		).toBe( 'stock_not_managed' );

		// The three that landed, read back out of the shop.
		const mug = await productBySku( request, ITEMS[ 0 ].sku );

		expect( mug.name ).toBe( ITEMS[ 0 ].name );
		expect( mug.description ).toContain( 'Half a litre' );
		expect( mug.status ).toBe( 'publish' );
		expect(
			mug.regular_price,
			'The price arrived as a string with two decimals and did not survive as one.'
		).toBe( ITEMS[ 0 ].price );
		expect( mug.stock_quantity ).toBe( ITEMS[ 0 ].available_quantity );
		expect(
			mug.remote_id,
			'The article was resolved by its reference and its identity was not recorded, so the next push has to guess again - and would apply to whatever article had picked up that reference in the meantime.'
		).toBe( ITEMS[ 0 ].remote_id );

		const second = await productBySku( request, ITEMS[ 1 ].sku );

		expect( second.regular_price ).toBe( ITEMS[ 1 ].price );
		expect(
			second.stock_quantity,
			'The declared quantity is the one available, and it was added to the shop\'s own count instead of replacing it.'
		).toBe( ITEMS[ 1 ].available_quantity );
		expect( second.stock_status ).toBe( 'outofstock' );

		const apron = await productBySku( request, ITEMS[ 2 ].sku );

		expect( apron.name ).toBe( ITEMS[ 2 ].name );
		expect( apron.status ).toBe( 'draft' );
		expect(
			apron.regular_price,
			'The batch declared no price for this article and one was written anyway.'
		).toBe( '3.00' );

		// The one it refused for its stock kept every one of its own values.
		const untracked = await productBySku( request, ITEMS[ 4 ].sku );

		expect( untracked.manage_stock ).toBe( false );
		expect( untracked.stock_quantity ).toBeNull();

		const recorded = await ledger( request );

		expect( recorded.count ).toBe( 1 );
		expect( recorded.rows[ 0 ].idempotency_key ).toBe( fieldOf( BATCH, 'idempotency_key' ) );
	} );

	test( 'refuses a batch whose signature does not cover the bytes that arrived, and changes no article', async ( { request } ) => {
		const before = await productBySku( request, ITEMS[ 0 ].sku );

		const altered = BATCH.replace( `"${ ITEMS[ 0 ].price }"`, '"99.99"' );

		expect( altered ).not.toBe( BATCH );

		const published = await publishInbound( request, {
			route: 'catalog-push',
			body: BATCH,
			signOver: altered,
		} );

		expect( published.status ).toBe( 401 );
		expect(
			published.raw,
			'The refusal carries a body, which tells whoever is probing which of the three ways of failing authentication they hit.'
		).toBe( '' );

		expect( await productBySku( request, ITEMS[ 0 ].sku ) ).toEqual( before );
		expect( ( await ledger( request ) ).count ).toBe( 0 );
	} );

	test( 'discards a redelivered batch without touching an article that appeared in between', async ( { request } ) => {
		expect( ( await publishInbound( request, { route: 'catalog-push', body: BATCH } ) ).status ).toBe( 200 );

		/*
		 * The article the first batch could not resolve now EXISTS. If the
		 * redelivery were applied, this is the article it would land on - so an
		 * article that stays exactly as it was created is the proof that the
		 * batch was discarded rather than merely answered 409.
		 */
		const created = await createProduct( request, {
			sku: ITEMS[ 3 ].sku,
			name: 'Appeared after the first batch',
			regular_price: '4.44',
		} );

		const again = await publishInbound( request, { route: 'catalog-push', body: BATCH } );

		expect( again.status ).toBe( 409 );
		expect( again.body.message ).toContain( 'already been applied' );

		const untouched = await productBySku( request, ITEMS[ 3 ].sku );

		expect( untouched.name ).toBe( created.name );
		expect(
			untouched.regular_price,
			'The redelivered batch was applied a second time, this time to an article that was not even there when it first arrived.'
		).toBe( '4.44' );

		expect( ( await ledger( request ) ).count ).toBe( 1 );
	} );

	test( 'refuses the whole batch when it carries an internal datum or a key the contract does not enumerate', async ( { request } ) => {
		const before = await productBySku( request, ITEMS[ 0 ].sku );

		for ( const [ name, body ] of [
			[ 'an internal datum', INTERNAL ],
			[ 'a key the contract does not enumerate', UNKNOWN_KEY ],
		] ) {
			const published = await publishInbound( request, { route: 'catalog-push', body } );

			expect(
				published.status,
				`A batch carrying ${ name } was not refused whole: ${ published.raw }`
			).toBe( 422 );

			/*
			 * The FIRST article of both bodies is perfectly applicable, and is
			 * the one that says whether the refusal was of the batch or only of
			 * the article that carried the offending key.
			 */
			expect(
				await productBySku( request, ITEMS[ 0 ].sku ),
				`A batch carrying ${ name } was applied as far as it could be read. Half a catalogue is worse than none: the sender is told the contract changed and the shop has already moved.`
			).toEqual( before );

			expect(
				( await ledger( request ) ).count,
				'A malformed batch claimed its idempotency key, so the corrected one would be answered as a redelivery and never applied.'
			).toBe( 0 );
		}
	} );

	test( 'leaves an article on sale charging its offer when a new regular price arrives', async ( { request } ) => {
		/*
		 * MEASURED IN WOOCOMMERCE, AND NOWHERE ELSE VISIBLE. What an article
		 * costs is a third value the shop derives from the regular price and the
		 * sale price together, and the receiver only ever writes the first of
		 * the three. A price writer that reached for the wrong one would take a
		 * live offer off a shop the moment the platform republished a price,
		 * and every unit test of the writer would stay green.
		 */
		await createProduct( request, {
			sku: ITEMS[ 0 ].sku,
			name: 'Article with a live offer',
			regular_price: '20.00',
			sale_price: '10.00',
		} );

		const onSale = await productBySku( request, ITEMS[ 0 ].sku );

		expect( onSale.price ).toBe( '10.00' );

		const declared = fieldOf( UNREADABLE, 'items' );

		const published = await publishInbound( request, { route: 'catalog-push', body: UNREADABLE } );

		expect( published.status ).toBe( 200 );
		expect( outcomeOf( published.body, declared[ 0 ].sku ).status ).toBe( 'applied' );

		const after = await productBySku( request, ITEMS[ 0 ].sku );

		expect( after.regular_price ).toBe( declared[ 0 ].price );
		expect(
			after.sale_price,
			'The new regular price wiped the offer the merchant had running.'
		).toBe( '10.00' );
		expect(
			after.price,
			'The shop stopped charging the offer, so the buyer pays the new regular price on an article that is still advertised as reduced.'
		).toBe( '10.00' );

		/*
		 * The second article of the same batch declares an amount that is not a
		 * number. It is rejected on its own and takes nothing else with it -
		 * which is the difference between an article the shop cannot apply and a
		 * batch the contract cannot read.
		 */
		expect( outcomeOf( published.body, declared[ 1 ].sku ).status ).toBe( 'rejected' );
		expect( outcomeOf( published.body, declared[ 1 ].sku ).reason ).toBe( 'unreadable_amount' );

		expect( ( await productBySku( request, declared[ 1 ].sku ) ).regular_price ).toBe( '1.00' );
	} );
} );
