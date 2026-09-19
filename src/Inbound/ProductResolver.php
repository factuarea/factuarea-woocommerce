<?php
/**
 * Resolution of the article an inbound catalogue push is addressed to.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\Constants;

/**
 * Finds the article of this shop that one pushed item is talking about.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER, AND WHY IT IS THAT ORDER
 * ---------------------------------------------------------------------------
 * 1. THE REGISTERED IDENTITY. When the push carries the identity the platform
 *    uses for the article, and some article of this shop already has that
 *    identity written on it, that article is the answer and no search happens.
 *    It wins over the reference on purpose: a merchant who renames a SKU has
 *    renamed a reference, not swapped the article, and a resolution that led
 *    with the reference would lose the link the day they tidy their catalogue
 *    up - and then find a DIFFERENT article that inherited the old reference.
 * 2. THE REFERENCE. With no identity registered here, the SKU is the only way
 *    in. A push whose reference resolves to exactly one article is applied to
 *    it, and the identity is written on it so that the next push does not
 *    search again.
 * 3. A TYPED REJECTION. Nothing else. There is no third attempt and there is no
 *    creation.
 *
 * ---------------------------------------------------------------------------
 * IT NEVER CREATES AN ARTICLE
 * ---------------------------------------------------------------------------
 * A reference that matches nothing is rejected as NOT FOUND and the shop is
 * left exactly as it was. Creating belongs to the publishing mode of the
 * sending platform - the merchant chose it THERE, and it travels in the push -
 * so a receiver that created on its own initiative would turn one
 * mis-addressed batch into a duplicated catalogue inside a third party's shop,
 * with no way of telling the new articles from the ones the merchant wrote.
 *
 * ---------------------------------------------------------------------------
 * TWO CANDIDATES ARE NEVER ONE: AMBIGUITY REJECTS, IT DOES NOT PICK
 * ---------------------------------------------------------------------------
 * A reference that two articles of the shop share is rejected as AMBIGUOUS and
 * NOTHING is applied to either of them: choosing one is choosing blindly which
 * of the merchant's products changes price, and the merchant finds out by
 * selling at the other one's price. The same holds for an identity that two
 * articles carry - a state this plugin cannot produce, but one a restored
 * backup or a duplicated article can - because the harm of guessing is
 * identical and the sender is told the same thing: your reference matches more
 * than one of my articles, tell me which.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SEARCHES GO THROUGH WooCommerce AND NOT THROUGH THE META TABLE
 * ---------------------------------------------------------------------------
 * The reference is searched with `wc_get_product_id_by_sku()` and the ambiguity
 * is asked of `wc_product_has_unique_sku()`. Measured on 2026-09-18 against
 * WooCommerce 8.9.5, the floor this package declares as supported, both land in
 * `WC_Product_Data_Store_CPT` (`class-wc-product-data-store-cpt.php:988` and
 * `:1018`) and both:
 *
 *   - join `wc_product_meta_lookup`, which is the maintained index of the SKU
 *     and NOT the meta table;
 *   - cover `product` AND `product_variation`, so a variation is found;
 *   - leave trashed articles out.
 *
 * A query written by hand over `_sku` postmeta reproduces none of those three
 * for free, and the way it fails is the expensive way: it finds LESS than there
 * is, so a duplicate stays invisible and an article that exists looks missing.
 * That is also why this class writes no SQL at all - the same reason
 * `Compatibility\HposCompatibility` exists for the orders half, where the
 * storage moved out from under every hand-written query in the ecosystem.
 *
 * The honest limit of `wc_product_has_unique_sku()` is that it passes through a
 * filter of its own name, which shops that deliberately allow duplicate
 * references switch off. Such a shop has told WooCommerce that duplicates are
 * legitimate, and this receiver cannot see past a filter the shop installed to
 * answer that very question. What it can do, and does, is never choose when it
 * DOES see two.
 *
 * ---------------------------------------------------------------------------
 * THE IDENTITY LOOKUP IS A WooCommerce QUERY, AND IT ASKS FOR VARIATIONS
 * ---------------------------------------------------------------------------
 * There is no WooCommerce function for «the article carrying this value of MY
 * meta key», so the identity is looked up with `wc_get_products()`, which is
 * the query API of the same data store and not a hand-written statement.
 *
 * Its default set of types has to be widened here, and that is measured rather
 * than assumed: `WC_Product_Query::get_default_query_vars()`
 * (`class-wc-product-query.php:29`, WooCommerce 8.9.5) sets
 * `'type' => array_merge( array_keys( wc_get_product_types() ) )` with no
 * `variation` in it, so the default query never returns one - and a variation
 * is exactly the kind of article a catalogue push addresses, because that is
 * what carries its own reference, its own price and its own stock. The types
 * are read from `wc_get_product_types()` instead of being listed, so a shop
 * with a product type this package has never heard of is searched too.
 *
 * Two candidates are enough to know that there is more than one, so the query
 * is bounded to two rather than to a page of results.
 *
 * ---------------------------------------------------------------------------
 * WHY THE IDENTITY IS WRITTEN, AND WHY IT COSTS ONE SAVE
 * ---------------------------------------------------------------------------
 * When the reference resolved and the push carried an identity, the identity is
 * written on the article and the article is saved. That save is paid ONCE per
 * article in its lifetime: from the next push on, the identity resolves it and
 * nothing is written. It is paid here, by this class, and not left for the
 * writers to persist along with their own changes, because an article can be
 * resolved and then rejected by all three of them - an article that does not
 * manage stock, say - and the link would be lost exactly for the articles that
 * need it most, the ones whose pushes keep failing for another reason.
 *
 * An identity that is already written with the same value is not written again:
 * the read is free and the save is not.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT
 * ---------------------------------------------------------------------------
 * No HTTP request and no outbox entry. Resolving is a question about this
 * shop's own catalogue, and the answer is already here.
 */
final class ProductResolver {

	/**
	 * How many candidates are fetched when looking an identity up.
	 *
	 * Two, because the question is «is there more than one», and the answer to
	 * that needs one more than one. Fetching a page of results to count them
	 * would read rows nobody looks at.
	 */
	private const CANDIDATE_LIMIT = 2;

	/**
	 * Why the last resolution failed, or an empty string when it did not.
	 *
	 * @var string
	 */
	private string $last_rejection = '';

	/**
	 * Resolves the article one pushed item is addressed to.
	 *
	 * @param CatalogPushItem $item Item as it arrived, already read.
	 * @return \WC_Product|null The article, or null when it was not resolved.
	 */
	public function resolve( CatalogPushItem $item ): ?\WC_Product {
		// Cleared on every call: a reason left over from the previous item of
		// the batch would label THIS item's rejection with somebody else's
		// cause, and the batch answers one outcome per article.
		$this->last_rejection = '';

		if ( null !== $item->remote_id ) {
			$registered = $this->resolve_by_identity( $item->remote_id );

			if ( null !== $registered ) {
				return $registered;
			}

			if ( '' !== $this->last_rejection ) {
				return null;
			}
		}

		return $this->resolve_by_reference( $item );
	}

	/**
	 * Why the last call to {@see self::resolve()} answered null.
	 *
	 * One of `CatalogPushOutcome::REASON_NOT_FOUND` or `::REASON_AMBIGUOUS`,
	 * and an empty string when the last resolution succeeded. It is read only
	 * after a null, which is what the receiver does: the two reasons are the
	 * whole difference between «this reference is not mine» and «this reference
	 * is two of mine», and the sender does different things with each.
	 *
	 * @return string
	 */
	public function last_rejection(): string {
		return $this->last_rejection;
	}

	/**
	 * Resolves by the identity this plugin already registered for an article.
	 *
	 * Answers null in two different situations that the caller tells apart by
	 * the rejection: no article carries the identity - which is not a failure,
	 * it is an article that has not been reconciled yet and whose reference is
	 * the way in - and more than one does, which is.
	 *
	 * @param string $remote_id Identity the push declared.
	 * @return \WC_Product|null The article, or null.
	 */
	private function resolve_by_identity( string $remote_id ): ?\WC_Product {
		$candidates = $this->articles_carrying( Constants::REMOTE_PRODUCT_ID_META_KEY, $remote_id );

		if ( array() === $candidates ) {
			return null;
		}

		if ( 1 < count( $candidates ) ) {
			$this->last_rejection = CatalogPushOutcome::REASON_AMBIGUOUS;

			return null;
		}

		$product = $this->article( (int) $candidates[0] );

		if ( null === $product ) {
			// The identity IS registered in this shop, on an article that
			// cannot be built any more. Falling through to the reference would
			// apply the push to a different article while the identity stayed
			// on the broken one, and the shop would end up with two articles
			// claiming to be the same remote one.
			$this->last_rejection = CatalogPushOutcome::REASON_NOT_FOUND;

			return null;
		}

		return $product;
	}

	/**
	 * Resolves by the reference the push declared, and registers the identity.
	 *
	 * The uniqueness of the reference is asked BEFORE the article is loaded and
	 * before anything is written, so that an ambiguous reference leaves both
	 * candidates untouched - not even read into memory, let alone saved.
	 *
	 * @param CatalogPushItem $item Item as it arrived.
	 * @return \WC_Product|null The article, or null.
	 */
	private function resolve_by_reference( CatalogPushItem $item ): ?\WC_Product {
		$id = (int) wc_get_product_id_by_sku( $item->sku );

		if ( 0 >= $id ) {
			$this->last_rejection = CatalogPushOutcome::REASON_NOT_FOUND;

			return null;
		}

		if ( ! wc_product_has_unique_sku( $id, $item->sku ) ) {
			$this->last_rejection = CatalogPushOutcome::REASON_AMBIGUOUS;

			return null;
		}

		$product = $this->article( $id );

		if ( null === $product ) {
			$this->last_rejection = CatalogPushOutcome::REASON_NOT_FOUND;

			return null;
		}

		if ( null !== $item->remote_id ) {
			$this->register_identity( $product, $item->remote_id );
		}

		return $product;
	}

	/**
	 * The articles of this shop carrying one value of one meta key.
	 *
	 * Bounded to {@see self::CANDIDATE_LIMIT}, asked of every product type the
	 * shop declares plus the variations, and returned as identifiers because
	 * the object of the single answer is built afterwards and the objects of an
	 * ambiguous answer are never built at all.
	 *
	 * @param string $meta_key Meta key of this plugin to look the value up in.
	 * @param string $value    Value to look up.
	 * @return list<int> Identifiers of the matching articles, at most two.
	 */
	private function articles_carrying( string $meta_key, string $value ): array {
		$found = wc_get_products(
			array(
				'limit'      => self::CANDIDATE_LIMIT,
				'return'     => 'ids',
				'type'       => $this->every_article_type(),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The query is by the plugin's OWN meta key, on at most two rows, once per pushed article. WooCommerce offers no function for "the article carrying this value of my meta key", and the alternative - a statement written by hand against the meta table - is the one this class refuses to write, because it finds less than there is.
				'meta_key'   => $meta_key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The value being looked up; see the note on the key above.
				'meta_value' => $value,
			)
		);

		if ( ! is_array( $found ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $found ) );
	}

	/**
	 * Every type of article a push can address in this shop.
	 *
	 * Read from the shop rather than listed, so that a product type installed
	 * by another plugin is searched too, and widened with the variations, which
	 * the WooCommerce default leaves out.
	 *
	 * @return list<string>
	 */
	private function every_article_type(): array {
		return array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) );
	}

	/**
	 * Builds one article by its identifier.
	 *
	 * `wc_get_product()` answers `false` or `null` for an identifier that no
	 * longer resolves - a deleted article whose row survived in a lookup table,
	 * a post of another type - and neither of those is an article this receiver
	 * may write to.
	 *
	 * @param int $id Identifier of the article.
	 * @return \WC_Product|null The article, or null when it cannot be built.
	 */
	private function article( int $id ): ?\WC_Product {
		$product = wc_get_product( $id );

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Writes the resolved identity on the article, so the next push finds it.
	 *
	 * @param \WC_Product $product   Article the reference resolved to.
	 * @param string      $remote_id Identity the push declared.
	 * @return void
	 */
	private function register_identity( \WC_Product $product, string $remote_id ): void {
		$stored = $product->get_meta( Constants::REMOTE_PRODUCT_ID_META_KEY, true );

		if ( is_scalar( $stored ) && (string) $stored === $remote_id ) {
			return;
		}

		$product->update_meta_data( Constants::REMOTE_PRODUCT_ID_META_KEY, $remote_id );
		$product->save();
	}
}
