<?php
/**
 * Writer of the available quantity an inbound catalogue push declares.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;

/**
 * Applies the quantity of one pushed item to one article of this shop.
 *
 * ---------------------------------------------------------------------------
 * THE QUANTITY THAT ARRIVES IS THE AVAILABLE ONE, AND NEVER THE PHYSICAL
 * BALANCE
 * ---------------------------------------------------------------------------
 * The platform publishes what is AVAILABLE TO SELL. It has already subtracted
 * the live commitment - the units of orders that are taken and not yet shipped,
 * the units held by a reservation - before it emitted the push. The number that
 * arrives is therefore the shop's shelf, not the warehouse's shelf, and this
 * class writes it as it is.
 *
 * What happens if the two are confused is the whole reason this paragraph
 * exists. Suppose the warehouse holds ten units and two of them belong to an
 * order that is already sold and not yet shipped: the availability is eight.
 * A receiver that wrote the physical balance would put ten back on the shelf,
 * and the shop would sell the two units that are already somebody else's. The
 * merchant learns about it when the second buyer's order cannot be served, and
 * the reconciliation of that is a refund, a message and a lost customer - for a
 * plugin that was asked to keep the shelves right.
 *
 * That is also why the wire name is `available_quantity` spelled out in full
 * and not `quantity`, and why the physical balance, the committed quantity and
 * the reorder threshold are three of the five INTERNAL figures that reject the
 * batch (below): the way this failure gets into a shop is by a receiver that
 * accepted a figure it should never have been sent, and applied it to the field
 * that looked closest.
 *
 * ---------------------------------------------------------------------------
 * IT ASSIGNS. IT NEVER ADDS AND IT NEVER SUBTRACTS
 * ---------------------------------------------------------------------------
 * `WC_Product::set_stock_quantity()` with the number that arrived, and nothing
 * else. There is no path through this class that reads the quantity the article
 * already had, which is the mechanical guarantee that it cannot be composing
 * the new one out of the old one.
 *
 * WooCommerce does offer a relative write, and this is the class that must not
 * use it: measured against WooCommerce 8.9.5, `wc_update_product_stock()`
 * (`includes/wc-stock-functions.php:26`) takes an `increase` or a `decrease`
 * operation that lands in `WC_Product_Data_Store_CPT::update_product_stock()`
 * (`includes/data-stores/class-wc-product-data-store-cpt.php:1400`) as
 * `UPDATE ... SET meta_value = meta_value %+f`. Relative arithmetic is right for
 * a shop subtracting its OWN sale, and wrong for a receiver: the platform
 * already did the subtraction, and doing it twice puts the sold units back on
 * sale. An assignment is also idempotent, which is what makes a redelivery that
 * the idempotency ledger did not catch cost nothing.
 *
 * The same function is why the quantity is not written through it even for the
 * `set` operation: for a variation whose stock is managed by its parent it
 * redirects the write to the PARENT (`get_stock_managed_by_id()`), and one
 * variation's availability applied to the parent is that availability applied
 * to every sibling variation of the article. This class answers that case
 * instead of silently spreading it.
 *
 * ---------------------------------------------------------------------------
 * AN ARTICLE THAT DOES NOT MANAGE STOCK IS REJECTED, NOT CONVERTED
 * ---------------------------------------------------------------------------
 * The receiver does NOT switch stock management on. Whether an article's
 * quantity is counted at all is a decision about the merchant's catalogue -
 * it changes what the storefront shows, what the checkout refuses and what the
 * merchant's reports mean - and taking it from a webhook changes how their shop
 * behaves without anybody asking. The article is rejected with
 * `CatalogPushOutcome::REASON_STOCK_NOT_MANAGED`, which tells the sender
 * exactly what to do about it, and the article is left untouched: not written,
 * not even saved, because a save with no changes still fires every automation
 * the merchant installed.
 *
 * The question asked is `managing_stock()` and not `get_manage_stock()`, and
 * the difference is measured: `managing_stock()`
 * (`includes/abstracts/abstract-wc-product.php:1710`, WooCommerce 8.9.5)
 * answers false for every article of a shop whose `woocommerce_manage_stock`
 * option is off, however the article itself is configured. Writing a quantity
 * into a shop that has decided not to use quantities, and then answering
 * «applied», is the same silent lie as writing it into an article that does not
 * manage stock.
 *
 * Its answer is not always a boolean, and the exception is the one that
 * matters: `WC_Product_Variation::get_manage_stock()`
 * (`includes/class-wc-product-variation.php:318`) answers the STRING `parent`
 * for a variation whose stock lives on its parent, and `managing_stock()` hands
 * that string straight back. A truthy check would read it as «yes». It is a no:
 * that variation has no quantity of its own, and the number the platform
 * published for it belongs to that variation and not to its siblings. Anything
 * else that is not exactly `true` is a shop answering this question in a shape
 * this receiver cannot read, and the safe reading of an unreadable answer about
 * stock is «do not write»: a rejection is visible and typed, a guess is not.
 *
 * ---------------------------------------------------------------------------
 * AN INTERNAL FIGURE REJECTS THE WHOLE BATCH, AND NOT ONLY THE ARTICLE
 * ---------------------------------------------------------------------------
 * Cost, supplier, physical balance, committed quantity and reorder threshold
 * are enumerated by `Contract\CatalogPushItem::INTERNAL_KEYS`, and their
 * presence rejects the ENTIRE batch as malformed - not the item, and certainly
 * not the key.
 *
 * They are not fields this receiver has not learnt yet, which is what an
 * unknown key is. They are figures that must never leave the platform: a cost
 * price and a commitment are the merchant's margin and the merchant's order
 * book, and the shop they would land in is a WordPress database that a
 * storefront, a theme and every installed plugin can read. One of them arriving
 * means something upstream is leaking, and the leak is not fixed by the
 * receiver ignoring it gracefully - it is HIDDEN by that, because a batch that
 * applies cleanly is a batch nobody looks at twice. Rejecting the whole batch
 * is the only answer that reaches a human on the emitting side while the
 * figures are still only in a log.
 *
 * Three of those five are stock figures and would have been aimed at this
 * class, which is why the reason is written here. The ENFORCEMENT is not here
 * and must not be: `CatalogPushItem::from_array()` answers null for an item
 * carrying one, `CatalogPushItem::internal_keys_in()` tells the receiver which
 * figure leaked, and the receiver rejects the batch before a single article is
 * touched. By the time a `CatalogPushItem` exists, the batch has already been
 * cleared of them - so this class never sees one, and a second check here would
 * be a second place to get the list wrong.
 *
 * ---------------------------------------------------------------------------
 * THE STATUS FOLLOWS THE QUANTITY, IN THE SAME PASS
 * ---------------------------------------------------------------------------
 * An article with seven units and a status of «out of stock» is an article
 * nobody can buy. WooCommerce keeps the two aligned itself - `save()`
 * (`includes/abstracts/abstract-wc-product.php:1413`) calls `validate_props()`
 * (`:1384`), which derives the status from the quantity, from
 * `absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) )` and from
 * whether the article allows backorders. This class reads the same three inputs
 * and applies the same rule, so it is not a second policy: it cannot disagree
 * with WooCommerce, because it is asking WooCommerce the same questions.
 *
 * It is set here anyway, and the reason is what an inherited side effect costs.
 * This class promises the sending platform an outcome per article, and the
 * promise is that the shop is now offering what the platform said it would
 * offer. Leaving that to a side effect of another call means the promise holds
 * for exactly as long as `save()` keeps producing it, and the day it stops
 * nothing goes red here: the shop simply shows «out of stock» over seven units,
 * and that is a failure found by a customer who did not buy.
 *
 * The comparison is STRICT - in stock only ABOVE the threshold - because that is
 * WooCommerce's: a shop that declares five as its floor has told WooCommerce
 * that five units are not enough to sell, and this receiver does not overrule a
 * setting of the shop. The quantity is still written in that case; only the
 * status follows the floor.
 *
 * A negative quantity - an oversold article - is written exactly as it arrived
 * and leaves the article out of stock. Clamping it to zero would hide the
 * oversell from the very report the merchant uses to fix it; what the shop must
 * not do is keep offering the article, and that is what the status is for.
 *
 * ---------------------------------------------------------------------------
 * ONLY THE STOCK, AND ONLY ONE SAVE
 * ---------------------------------------------------------------------------
 * The three writers of the inbound contract share one shape so that the
 * receiver can run them in order over the same article and collect one outcome
 * per article. Each applies ONLY the fields it owns: an item that carries a
 * name, a description and a price goes past this one untouched in all three. An
 * item that declares no quantity is not a stock push at all, and the article is
 * left exactly as it was - a writer that read an absent quantity as zero would
 * empty the shelves of every article whose name was corrected.
 *
 * Nothing goes out. No HTTP request and no outbox entry: applying a push is the
 * end of an inbound delivery, not the start of an outbound one, and a writer
 * that queued one would send the platform back the quantity the platform had
 * just sent.
 */
final class StockWriter {

	/**
	 * Option the shop keeps its «no stock» threshold in.
	 *
	 * The same one `WC_Product::validate_props()` reads, named here rather than
	 * inlined so that the rule of this class and the rule of WooCommerce are
	 * visibly reading the same setting.
	 */
	private const NO_STOCK_THRESHOLD_OPTION = 'woocommerce_notify_no_stock_amount';

	/**
	 * Stock status of an article that is on sale.
	 *
	 * The three literals are the keys of `wc_get_product_stock_status_options()`
	 * (`includes/wc-product-functions.php:871`, WooCommerce 8.9.5). They are
	 * declared rather than read from it because `WC_Product::set_stock_status()`
	 * (`includes/abstracts/abstract-wc-product.php:977`) silently falls back to
	 * «in stock» for a status it does not know: a typo here would not fail, it
	 * would put a sold-out article back on sale.
	 */
	private const STATUS_IN_STOCK = 'instock';

	/**
	 * Stock status of an article that cannot be bought.
	 */
	private const STATUS_OUT_OF_STOCK = 'outofstock';

	/**
	 * Stock status of an article that can be bought without units in hand.
	 */
	private const STATUS_ON_BACKORDER = 'onbackorder';

	/**
	 * Backorder setting that means «no backorders».
	 *
	 * The question is asked this way round - «is it anything other than no» -
	 * because WooCommerce has two ways of saying yes, `yes` and `notify`, and
	 * `validate_props()` tests exactly this inequality.
	 */
	private const BACKORDERS_NOT_ALLOWED = 'no';

	/**
	 * Answer of `managing_stock()` for a variation whose stock lives on its
	 * parent.
	 */
	private const STOCK_MANAGED_BY_PARENT = 'parent';

	/**
	 * Applies the available quantity of one pushed item to one article.
	 *
	 * @param \WC_Product     $product Article the item resolved to.
	 * @param CatalogPushItem $item    Item as it arrived, already read.
	 * @return CatalogPushOutcome Applied, or rejected as not stock managed.
	 */
	public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome {
		$reference = $item->sender_reference();

		// Asked BEFORE the article is questioned about its stock: a push of
		// catalogue fields or of a price is not a push of stock, and an article
		// that does not manage stock has not been asked anything it cannot
		// answer. Rejecting it here would fail the catalogue push of every
		// article of a shop that sells services.
		if ( ! $item->declares_quantity() ) {
			return CatalogPushOutcome::applied( $reference );
		}

		if ( true !== $product->managing_stock() ) {
			return CatalogPushOutcome::rejected( $reference, CatalogPushOutcome::REASON_STOCK_NOT_MANAGED );
		}

		$quantity = (int) $item->available_quantity;

		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $this->status_for( $product, $quantity ) );
		$product->save();

		return CatalogPushOutcome::applied( $reference );
	}

	/**
	 * The stock status that corresponds to a quantity, for this article and
	 * this shop.
	 *
	 * The same three inputs and the same strict comparison as
	 * `WC_Product::validate_props()`; see the block of this class for why it is
	 * computed here rather than inherited from the save.
	 *
	 * @param \WC_Product $product  Article being written.
	 * @param int         $quantity Quantity just assigned to it.
	 * @return string One of the three stock statuses of WooCommerce.
	 */
	private function status_for( \WC_Product $product, int $quantity ): string {
		if ( $quantity > $this->no_stock_threshold() ) {
			return self::STATUS_IN_STOCK;
		}

		if ( self::BACKORDERS_NOT_ALLOWED !== $product->get_backorders() ) {
			return self::STATUS_ON_BACKORDER;
		}

		return self::STATUS_OUT_OF_STOCK;
	}

	/**
	 * The quantity below which this shop considers an article sold out.
	 *
	 * Read from the shop on every write and not cached: a catalogue push runs
	 * once and the read is an option lookup, while a cached floor would be the
	 * one value that survived the merchant changing it.
	 *
	 * @return int
	 */
	private function no_stock_threshold(): int {
		return absint( get_option( self::NO_STOCK_THRESHOLD_OPTION, 0 ) );
	}
}
