// @ts-check

/**
 * The tax identifier field on the CLASSIC checkout, and the guard that keeps it
 * from being painted twice.
 *
 * The classic checkout still serves an enormous share of the installed shops, so
 * a plugin that only supported the block checkout would leave them exactly where
 * they were. But the two mechanisms overlap: the additional checkout fields
 * interface owns the value, and the template filters paint the classic form, and
 * a WooCommerce that ever started painting the additional fields on the classic
 * checkout itself would produce TWO inputs for one value.
 *
 * Two inputs is not a cosmetic problem. Whichever one the buyer does not type
 * into submits empty, and the last write wins, so the field the merchant can see
 * is not necessarily the one that reaches the order.
 */

const { test, expect } = require( '@playwright/test' );

const {
	VALID_TAX_ID,
	TAX_ID_LABEL,
	addProductToCart,
	fixtures,
	order,
	orderIdFromUrl,
	resetSite,
} = require( './support/site' );

test.describe( 'Classic checkout', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request );
	} );

	test( 'shows the tax ID field exactly once, validates it, and writes the value on the order', async ( { page, request } ) => {
		const seeded = await fixtures( request );

		await addProductToCart( page, seeded.product_id );
		await page.goto( seeded.classic_checkout_url );

		await expect( page.locator( '#billing_first_name' ) ).toBeVisible();

		await expect(
			page.locator( '#factuarea_tax_id' ),
			'The tax ID field is not on the classic checkout, which is still what most installed shops serve.'
		).toBeVisible();

		/*
		 * ONCE. Counted over the whole form by LABEL rather than by our own id,
		 * because a second control painted by the additional fields interface
		 * would carry a different id and an id-based count would not see it -
		 * which is precisely the duplicate this guard exists to catch.
		 */
		await expect(
			page.locator( 'form.checkout' ).getByLabel( TAX_ID_LABEL ),
			'The tax ID field is painted more than once on the classic checkout. Two inputs for one value means the one the buyer typed into is not necessarily the one that is submitted.'
		).toHaveCount( 1 );

		await page.fill( '#billing_first_name', 'Ada' );
		await page.fill( '#billing_last_name', 'Lovelace' );
		await page.fill( '#billing_address_1', 'Calle Mayor 1' );
		await page.fill( '#billing_city', 'Madrid' );
		await page.fill( '#billing_postcode', '28001' );
		await page.fill( '#billing_email', 'ada@example.com' );

		/*
		 * The wrong control letter, first. The classic checkout validates on the
		 * server, so the assertion that matters is that the order was NOT
		 * placed: a validation that shows a message and lets the order through
		 * has not validated anything.
		 */
		await page.fill( '#factuarea_tax_id', '12345678A' );
		await page.locator( '#place_order' ).click();

		await expect(
			page.getByText( /That tax ID is not valid/i ),
			'The classic checkout accepted a tax ID with a wrong control letter.'
		).toBeVisible();

		expect( page.url() ).not.toMatch( /order-received/ );

		await page.fill( '#factuarea_tax_id', VALID_TAX_ID );
		await page.locator( '#place_order' ).click();
		await page.waitForURL( /order-received/, { timeout: 60_000 } );

		const placed = await order( request, orderIdFromUrl( page ) );

		expect( placed.tax_id ).toBe( VALID_TAX_ID );
		expect( placed.meta[ '_factuarea_tax_id' ] ).toBe( VALID_TAX_ID );
	} );
} );
