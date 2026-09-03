// @ts-check

/**
 * The tax identifier field on the BLOCK checkout.
 *
 * THIS IS THE SCENARIO THE WHOLE PACKAGE EXISTS FOR.
 *
 * A plugin hooked only into the classic checkout's template filters registers a
 * field that the block checkout never paints. Nothing errors: the merchant's
 * shop works, the order completes, and every B2B sale silently invoices as a
 * simplified invoice because the buyer was never asked for a tax identifier.
 * There is no log line, no notice and no failing unit test - the classic
 * checkout's own tests stay green - so the only thing that can see it is a
 * browser opening the block checkout and looking for the field.
 *
 * That is what this file does, and it is why it asserts on the RENDERED field
 * rather than on the registration call: a field can be registered and still not
 * be painted, and the merchant only cares about the second one.
 *
 * MUTATION-CHECKED. With `BlockCheckoutTaxIdField::register_field` unhooked -
 * which is exactly what a classic-only plugin looks like - this scenario fails
 * on the visibility assertion below while the classic one stays green.
 */

const { test, expect } = require( '@playwright/test' );

const {
	BLOCK_BILLING_FIELDS,
	VALID_TAX_ID,
	TAX_ID_LABEL,
	addProductToCart,
	fillBlockAddress,
	fixtures,
	order,
	placeBlockOrder,
	resetSite,
} = require( './support/site' );

test.describe( 'Block checkout', () => {
	test.beforeEach( async ( { request } ) => {
		await resetSite( request );
	} );

	test( 'shows the tax ID field, validates it, and writes the value on the order', async ( { page, request } ) => {
		const seeded = await fixtures( request );

		await addProductToCart( page, seeded.product_id );
		await page.goto( seeded.block_checkout_url );

		/*
		 * The block checkout is a React application: the markup arrives empty
		 * and is filled in after hydration. Waiting for a field that is part of
		 * the form - rather than for a timeout - is what tells the difference
		 * between "the field is missing" and "the page has not finished".
		 */
		await expect( page.locator( '#email' ) ).toBeVisible();

		const taxId = page.getByLabel( TAX_ID_LABEL );

		await expect(
			taxId,
			'The tax ID field is not on the block checkout. A plugin wired only to the classic checkout fails exactly here, and nowhere else.'
		).toBeVisible();

		/*
		 * Registered as OPTIONAL. A shop that made it required would reject
		 * every consumer sale, which is worse than the problem being solved: an
		 * order without a tax identifier is a normal order that invoices as a
		 * simplified invoice.
		 */
		await expect( taxId ).not.toHaveAttribute( 'required', /.*/ );

		await fillBlockAddress( page );

		const validationError = page
			.locator( BLOCK_BILLING_FIELDS )
			.getByText( /That tax ID is not valid/i );

		/*
		 * Empty is valid, and it is asserted before anything is typed: a buyer
		 * without a Spanish tax identifier still has to be able to buy.
		 */
		await expect( validationError ).toHaveCount( 0 );

		/*
		 * 12345678A is the right SHAPE and the wrong control letter. It is
		 * caught by the check digit and not by a pattern, which is the half of
		 * the validation a regular expression would have missed.
		 */
		await taxId.fill( '12345678A' );
		await taxId.blur();

		await expect(
			validationError,
			'A tax ID with a wrong control letter was accepted. The check digit is what tells a typo from a real identifier.'
		).toBeVisible();

		await taxId.fill( VALID_TAX_ID );
		await taxId.blur();

		await expect( validationError ).toHaveCount( 0 );

		const orderId = await placeBlockOrder( page );
		const placed = await order( request, orderId );

		expect(
			placed.tax_id,
			'The order was placed but the tax ID did not reach it. The field painted and its value was dropped between the block checkout and the order.'
		).toBe( VALID_TAX_ID );

		/*
		 * On the meta key the connector reads, and not merely somewhere on the
		 * order. The connector walks an enumerated catalogue of meta keys over
		 * the delivered body; a value stored under any other key is captured and
		 * never read, which is indistinguishable from not capturing it.
		 */
		expect( Object.keys( placed.meta ) ).toContain( '_factuarea_tax_id' );
		expect( placed.meta[ '_factuarea_tax_id' ] ).toBe( VALID_TAX_ID );
	} );
} );
