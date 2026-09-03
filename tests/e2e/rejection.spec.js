// @ts-check

/**
 * A permanent rejection carrying a code that did not exist when the plugin was
 * published.
 *
 * This plugin is DISTRIBUTED: a merchant runs whichever version they last
 * updated to, and an installation from eight months ago cannot be handed a new
 * table of rejection codes. So there is no list of known codes anywhere in it. A
 * rejection is recognised by its SHAPE - a readable code in the body - and
 * classified by what the response says about waiting: a wait means retry, no
 * wait means permanent, whatever the code happens to be called.
 *
 * The code used below is invented on the spot for that reason. Nothing in the
 * plugin has ever seen it, and it still has to be classified correctly and
 * closed without a single retry, with the receiver's own message kept where the
 * merchant reads it.
 */

const { test, expect } = require( '@playwright/test' );

const {
	VALID_TAX_ID,
	addProductToCart,
	deliveries,
	drain,
	fastForward,
	fixtures,
	outbox,
	placeClassicOrder,
	programReceiver,
	resetSite,
} = require( './support/site' );

/** What the receiver says to the merchant. Relayed verbatim, Spanish included. */
const MERCHANT_MESSAGE = 'La tienda no está conectada a ninguna integración de WooCommerce en Factuarea.';

test.describe( 'Permanent rejection', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request );
	} );

	test( 'closes the entry with no retry at all when the receiver refuses with an unknown code and no wait', async ( { page, request } ) => {
		const seeded = await fixtures( request );

		await programReceiver( request, {
			fallback: {
				status: 422,
				body: {
					code: 'invented_code',
					message: MERCHANT_MESSAGE,
					retryable: false,
					retry_after: null,
				},
				/*
				 * NO Retry-After header, and that absence is the whole signal.
				 * The receiver sets it if and only if the rejection is
				 * retryable, so a rejection without it is one that retrying
				 * cannot fix.
				 */
			},
		} );

		await addProductToCart( page, seeded.product_id );
		await page.goto( seeded.classic_checkout_url );
		await expect( page.locator( '#billing_first_name' ) ).toBeVisible();

		await placeClassicOrder( page, VALID_TAX_ID );

		await drain( request );

		const afterFirst = await outbox( request );
		const entry = afterFirst.rows[ 0 ];

		expect(
			entry.status,
			'A rejection with no wait was not treated as permanent. A code the plugin has never seen still has to classify correctly, because a distributed plugin cannot be handed a new list of codes.'
		).toBe( 'failed' );
		expect( entry.attempts ).toBe( 1 );
		expect( entry.last_status_code ).toBe( 422 );
		expect(
			entry.next_attempt_at,
			'A permanently rejected entry has a next attempt planned, so it will be sent again to be refused again.'
		).toBe( 0 );

		/*
		 * The reason the merchant reads. Relayed word for word from the
		 * receiver, in the language the receiver wrote it in: it is a datum this
		 * plugin transports, not a string it authors, and paraphrasing it would
		 * leave the merchant looking for a message that does not exist in
		 * Factuarea.
		 */
		expect(
			entry.last_message,
			'The receiver\'s reason did not reach the outbox, so the merchant has a failed sale and nothing to act on.'
		).toBe( MERCHANT_MESSAGE );

		/*
		 * NOT ONE RETRY. Moving the clock forward and draining again must change
		 * nothing: a permanently rejected entry is terminal, and an entry that
		 * came back to life here would hammer the receiver for ever with a
		 * document it has already refused.
		 */
		await fastForward( request );
		await drain( request );

		const afterSecond = await outbox( request );

		expect(
			await deliveries( request ),
			'The permanently rejected entry was sent a second time. "No retry" means none, not fewer.'
		).toHaveLength( 1 );
		expect( afterSecond.rows[ 0 ].attempts ).toBe( 1 );
		expect( afterSecond.rows[ 0 ].status ).toBe( 'failed' );
		expect( afterSecond.rows[ 0 ].last_message ).toBe( MERCHANT_MESSAGE );
	} );
} );
