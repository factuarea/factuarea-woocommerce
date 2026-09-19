<?php
/**
 * Applies the price the platform published onto one article of the shop.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;

/**
 * Writes the regular price of one article, and nothing else.
 *
 * One of the three writers the catalogue push receiver runs, in order, over the
 * same article: the catalogue fields, then this, then the stock. Each of them
 * owns its own fields and leaves every other one alone, which is what lets the
 * receiver collect ONE outcome per article out of three hands.
 *
 * ---------------------------------------------------------------------------
 * THE AMOUNT IS A STRING FROM END TO END, AND THAT IS NOT A STYLE CHOICE
 * ---------------------------------------------------------------------------
 * It arrives as text, it is checked as text, and it is handed to WooCommerce as
 * text. There is no conversion to a floating point number at any point of this
 * file, and the reason is that most two-decimal amounts CANNOT be held exactly
 * in one.
 *
 * A single intermediate conversion is enough to change the last decimal of an
 * amount that is perfectly representable as text, and the damage is silent: no
 * error, no log line, no failing test. It is found in a REAL ORDER - a line
 * that totals one cent away from what the platform invoiced, weeks later, by a
 * merchant who has to prove which of the two systems is wrong. A test that
 * compares two floats would not even have noticed, because it would be
 * comparing the same drifted value to itself.
 *
 * The conversion also destroys the SCALE, which is part of the amount and not
 * of its presentation: a shop holding `19.9` where the platform published
 * `19.90` holds a different string from the one the other side believes it
 * published, and every later reconciliation reads a discrepancy that is not
 * one.
 *
 * `WC_Product::set_regular_price()` accepts a string and persists it, so
 * nothing in the path from the wire to the database needs a number at all.
 *
 * ---------------------------------------------------------------------------
 * NO TAX IS ADDED, TAKEN OFF OR RECOMPOSED - NOT EVEN ON REQUEST
 * ---------------------------------------------------------------------------
 * The amount was resolved by the price list of the sending platform, which is
 * the party that knows the customer, the agreed rate and the rules that produced
 * it. This class applies that figure and asks the shop nothing.
 *
 * In particular it does NOT read whether the shop declares that its prices
 * include tax, and does not branch on it. That setting describes how the
 * merchant TYPES prices into their own shop; it is not a statement about an
 * amount that arrived already resolved from somewhere else. Reinterpreting the
 * figure against it would give two different prices for the same article
 * depending on who is looking - and would change the price of every article in
 * the shop the day a merchant flips a checkbox, without a single push having
 * been sent.
 *
 * The tax class and the tax status of the article are not read and not written
 * either: they belong to the merchant's own catalogue configuration, and this
 * push is about an amount.
 *
 * ---------------------------------------------------------------------------
 * AN UNREADABLE AMOUNT REJECTS THE ARTICLE AND LEAVES ITS PRICE STANDING
 * ---------------------------------------------------------------------------
 * The shape of an amount is asked of
 * {@see InboundRefundNotice::is_decimal_string()}, which owns that pattern for
 * the whole package. A second pattern written here would be a second place to
 * disagree about what a decimal is, and the two would drift the first time one
 * of them learned about a currency with three decimals.
 *
 * What is NOT done is the tempting thing: casting the amount and storing
 * whatever comes out. Every unreadable amount casts to zero, so that writer
 * publishes a price of zero - and a shop selling at zero is discovered by its
 * customers, not by its owner. The article is rejected with
 * `CatalogPushOutcome::REASON_UNREADABLE_AMOUNT`, the price it already had
 * stays exactly as it was, and the rest of the batch still applies: the
 * rejection is of ONE article and not of the delivery.
 *
 * The check is the package's pattern and is not narrowed here. That pattern
 * allows a sign, so a negative amount is applied like any other: it is
 * READABLE, and answering a readable amount with the reason that says
 * «unreadable» would hand the sender one of five closed reasons carrying the
 * wrong meaning. Whether a negative price may be published at all is a question
 * for the contract and for the emitter, where it can be answered honestly, and
 * not something a receiver should settle by mislabelling it.
 *
 * ---------------------------------------------------------------------------
 * THE OFFER IS THE MERCHANT'S, AND THE EFFECTIVE PRICE IS WOOCOMMERCE'S
 * ---------------------------------------------------------------------------
 * The sale price is never written and never cleared. The inbound contract
 * publishes A PRICE, not a campaign - there is no `sale_price` key in
 * {@see CatalogPushItem} - and clearing an offer the merchant set in their own
 * shop would be taking a decision nobody asked this receiver to take, silently,
 * on every push of a regular price.
 *
 * For the same reason the EFFECTIVE price is not written either. Only the
 * regular price is set, and which of the two the shop charges is derived by
 * WooCommerce from the regular price, the sale price and the window of the
 * offer. Writing the effective price here would publish the regular price over
 * a live offer, which is clearing the offer by another route.
 *
 * What WooCommerce's own product data store does with an offer once the regular
 * price moves - whether it keeps deriving from it, and what it does when a new
 * regular price lands below a standing sale price - is ITS rule about the
 * merchant's shop and not a decision of this receiver. It is not asserted here
 * and is not asserted in the Unit suite either, deliberately: WooCommerce is
 * not a development dependency of this repository (measured 2026-09-18:
 * `vendor/woocommerce/` holds the coding standards and no plugin source), so
 * claiming it from a double would be claiming something nothing measured. It is
 * observed against a real shop by the end-to-end suite, which is the only place
 * in this package where a real WooCommerce runs.
 *
 * ---------------------------------------------------------------------------
 * WHO SAVES, AND WHEN
 * ---------------------------------------------------------------------------
 * The writer that changed a value is the one that persists it, exactly once,
 * and a writer that changed nothing does not save at all. A setter that is
 * never persisted is a write that disappears without a trace, and each save
 * fires the update hooks of the shop, so a push that only carries a price must
 * fire them once and a push that carries no price must not fire them.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT
 * ---------------------------------------------------------------------------
 * No HTTP request, no outbox entry, no scheduled task. Applying what arrived
 * does not need the network, and going out would turn one delivery into two.
 */
final class PriceWriter {

	/**
	 * Applies the price of one item of a catalogue push to one article.
	 *
	 * The article arrives already resolved by the receiver, and the item already
	 * read by its contract: this method neither looks an article up nor decides
	 * what a key of the push means. It answers the outcome OF THIS ARTICLE, so
	 * that the receiver can collect one per article across the three writers.
	 *
	 * @param \WC_Product     $product Article of this shop, already resolved.
	 * @param CatalogPushItem $item    Item as it arrived, already read.
	 * @return CatalogPushOutcome Applied, or rejected naming the amount.
	 */
	public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome {
		$reference = $item->sender_reference();

		if ( ! $item->declares_price() ) {
			// An absent price means «this push is not about the price», which is
			// the ordinary case for a push of stock. The question is asked of the
			// contract and never of the property, so that what an absent price
			// MEANS stays in one place; reading a null here is how a writer ends
			// up blanking the price of an article whose stock was synchronised.
			return CatalogPushOutcome::applied( $reference );
		}

		// `declares_price()` is exactly «the price is not null», so this narrows a
		// type and changes no value: the text that arrived is the text that goes on.
		$amount = (string) $item->price;

		if ( ! InboundRefundNotice::is_decimal_string( $amount ) ) {
			return CatalogPushOutcome::rejected( $reference, CatalogPushOutcome::REASON_UNREADABLE_AMOUNT );
		}

		$product->set_regular_price( $amount );
		$product->save();

		return CatalogPushOutcome::applied( $reference );
	}
}
