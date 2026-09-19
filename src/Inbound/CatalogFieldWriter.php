<?php
/**
 * Writes the catalogue fields of an inbound push onto one article of the shop.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;

/**
 * Applies the CATALOGUE half of one pushed article: its name, its description
 * and whether it is on sale. Nothing else.
 *
 * ---------------------------------------------------------------------------
 * IT WRITES EXACTLY WHAT THE PUSH DECLARED, AND NOTHING THE PUSH DID NOT
 * ---------------------------------------------------------------------------
 * This is the whole rule of this class, and it is the one that is easy to get
 * wrong by writing the obvious loop. An item arrives with seven possible keys
 * and most pushes carry two or three of them: a push about a price carries a
 * price and a reference and no description at all. A writer that read
 * `$item->description` and wrote it would write NULL into the description of
 * every article a price push touched, and the merchant would find their
 * catalogue blanked by a price update - silently, because blanking a field is
 * a successful write.
 *
 * So the writer never asks the item what its properties hold. It asks it what
 * it DECLARED, through `CatalogPushItem::declared_catalog_fields()`, which
 * answers a map of the catalogue fields that actually travelled. A field that
 * is not in that map is not written, not deleted and not defaulted: it is left
 * exactly as the merchant's shop had it.
 *
 * ---------------------------------------------------------------------------
 * ONE SAVE PER ARTICLE, AND NONE WHEN THERE IS NOTHING TO WRITE
 * ---------------------------------------------------------------------------
 * The setters of a WooCommerce product change an object in memory; `save()` is
 * what writes the row and what fires `woocommerce_update_product` and the whole
 * tail of hooks behind it - search index rebuilds, cache flushes, third-party
 * synchronisers, transient deletions. Saving once per FIELD would multiply all
 * of that by however many fields the push happened to carry, so a three-field
 * push would cost three times what a one-field push costs for the same article.
 *
 * Every declared field is therefore set first and the article is saved once, at
 * the end. A push that declares no catalogue field at all - a price-only or a
 * stock-only push - saves NOTHING: an article that nobody changed must not
 * produce an update event, because every listener downstream would take it for
 * a change.
 *
 * `save()` is not wrapped in a try/catch on purpose. When it throws, the write
 * did not happen, and the receiver's own handler is the single place that turns
 * that into a released idempotency key and a retryable answer. Swallowing it
 * here would report the article as applied and let the sender believe a change
 * that is not in the database.
 *
 * ---------------------------------------------------------------------------
 * THE LIST OF FIELDS IS ENUMERATED HERE TOO, AND A KEY OUTSIDE IT REJECTS
 * ---------------------------------------------------------------------------
 * `WRITTEN_FIELDS` is this writer's half of the enumeration that
 * `CatalogPushItem::ACCEPTED_KEYS` is the contract's half of. It is not a
 * duplicate of it: the contract's list says what may TRAVEL, this one says what
 * this class knows how to WRITE, and the day the two stop coinciding is the day
 * something has to happen loudly.
 *
 * What happens is a rejection with
 * `CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED`, and the article is left
 * untouched - no setter is called and no save happens, so a batch that trips
 * this does not leave a half-written article behind. Ignoring the unknown key
 * and writing the rest would be the silent loss this whole contract exists to
 * avoid: the platform would believe it published a field that the shop never
 * applied, and in a catalogue that difference is a price or an availability.
 *
 * BY CONSTRUCTION THIS REJECTION IS UNREACHABLE TODAY, and saying so is part of
 * the design rather than an admission. `CatalogPushItem::from_array()` already
 * answers null for an item carrying a key the contract does not enumerate, so a
 * foreign key never gets as far as an item object, and
 * `declared_catalog_fields()` can only ever answer keys of
 * `CatalogPushItem::CATALOG_FIELDS`. The guard exists for the ONE way the shape
 * can change without anybody noticing: the contract growing a fourth catalogue
 * field while this writer keeps three arms. Without it that field would be
 * dropped in silence; with it the article is rejected with a typed reason the
 * sender can read. The unit suite pins the coincidence of the two lists with a
 * measurement rather than with a copy, so growing one of them turns a test red
 * instead of opening the hole.
 *
 * The dispatch below is a `match` WITHOUT a default arm for the same reason,
 * one layer deeper: an unhandled value there raises rather than falling
 * through, so no future field can be silently skipped even if somebody removes
 * the guard.
 *
 * ---------------------------------------------------------------------------
 * EACH FIELD IS SANITISED FOR ITS DESTINATION, AND THE RICH ONE STAYS RICH
 * ---------------------------------------------------------------------------
 * The three destinations are three different kinds of content, and one
 * sanitiser for all of them would be wrong twice:
 *
 *  - `name` lands in the article title, which is single-line plain text.
 *    `sanitize_text_field()` is the WordPress function for that destination: it
 *    removes tags, invalid UTF-8 and line breaks. A title that kept markup
 *    would render as markup in the shop listing, in the cart and in the
 *    merchant's order e-mails.
 *
 *  - `description` lands in the article's long description, which the contract
 *    declares as RICH CONTENT. `wp_kses_post()` is the WordPress function for
 *    that destination: it keeps the markup a post may carry and removes what it
 *    may not - scripts, event handlers, unsafe protocols. Running
 *    `sanitize_text_field()` over it instead would flatten a formatted
 *    description into one line of plain text, which is a data loss dressed up
 *    as a safety measure, and exactly what this task forbids.
 *
 *  - `active` is a boolean and carries no text to escape at all. It is applied
 *    as a product status, below.
 *
 * WHY NOT `wp_filter_post_kses()`, which is what WooCommerce's own products
 * controller uses. That one is the SLASH-AWARE variant - `addslashes( wp_kses(
 * stripslashes( $value ), 'post' ) )` - and it is right there because a body
 * that arrived through a form-encoded request is slashed by WordPress before
 * any handler sees it. This lane's body is a JSON document that the receiver
 * reads RAW, because the raw bytes are what the signature covers, and decodes
 * itself: nothing slashed it, so stripping slashes from it would eat a
 * backslash that the merchant's description legitimately contains.
 *
 * ---------------------------------------------------------------------------
 * «ACTIVE» IS THE PRODUCT STATUS, NOT THE CATALOGUE VISIBILITY
 * ---------------------------------------------------------------------------
 * An inactive article is set to `draft` and an active one to `publish`, and the
 * two alternatives are worse for opposite reasons:
 *
 *  - Catalogue visibility only hides an article from the listings and the
 *    search. The article stays PURCHASABLE through its own address, so an
 *    article the platform declared inactive would still be sold to anybody
 *    holding the link - which is the failure the flag exists to prevent.
 *  - Trashing it schedules a deletion, and a deletion is not a state a later
 *    push can undo. `draft` is reversible by the next push and by the merchant,
 *    and it keeps the article, its reviews and its order history intact.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CLASS NEVER TOUCHES
 * ---------------------------------------------------------------------------
 * The price, the sale price, the stock quantity, the stock management flag and
 * the reference. Two of those belong to `PriceWriter` and `StockWriter`, which
 * the receiver runs over the same article after this one; the reference is how
 * the article was found in the first place and rewriting it would move the
 * identity the next push resolves by.
 *
 * It does not resolve the article either - `ProductResolver` did that and hands
 * it in already found - and it sends nothing anywhere: applying what arrived
 * needs no network, and going out would turn one inbound delivery into an
 * outbound one.
 */
final class CatalogFieldWriter {

	/**
	 * The catalogue fields this writer knows how to write, in the order the
	 * contract enumerates them.
	 *
	 * Built out of the contract's own field constants rather than out of
	 * literals, so that renaming one of them here and there is impossible; what
	 * this list can still do, and what the unit suite measures, is fall BEHIND
	 * `CatalogPushItem::CATALOG_FIELDS` when that one grows.
	 *
	 * @var list<string>
	 */
	public const WRITTEN_FIELDS = array(
		CatalogPushItem::FIELD_NAME,
		CatalogPushItem::FIELD_DESCRIPTION,
		CatalogPushItem::FIELD_ACTIVE,
	);

	/**
	 * Product status of an article the push declares as active.
	 */
	private const ACTIVE_PRODUCT_STATUS = 'publish';

	/**
	 * Product status of an article the push declares as inactive. See the class
	 * block for why it is this one and not a hidden visibility or the bin.
	 */
	private const INACTIVE_PRODUCT_STATUS = 'draft';

	/**
	 * Applies the catalogue fields the push declared to one article of the shop.
	 *
	 * The article arrives already resolved. This method neither looks one up nor
	 * checks that it is the right one: the receiver resolved it, and resolving it
	 * twice in two places is how the two resolutions end up disagreeing.
	 *
	 * @param \WC_Product     $product Article of this shop, already resolved.
	 * @param CatalogPushItem $item    Item as it arrived, already read.
	 * @return CatalogPushOutcome Applied, or rejected with a typed reason.
	 */
	public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome {
		$declared = $item->declared_catalog_fields();

		if ( array() !== self::unwritable_fields_in( $declared ) ) {
			return CatalogPushOutcome::rejected(
				$item->sender_reference(),
				CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED
			);
		}

		if ( array() === $declared ) {
			return CatalogPushOutcome::applied( $item->sender_reference() );
		}

		foreach ( $declared as $field => $value ) {
			// No default arm: an unhandled field raises instead of being skipped.
			// The guard above has already turned that case into a typed
			// rejection, so this is the second lock and not the first.
			match ( (string) $field ) {
				CatalogPushItem::FIELD_NAME        => $product->set_name( sanitize_text_field( (string) $value ) ),
				CatalogPushItem::FIELD_DESCRIPTION => $product->set_description( wp_kses_post( (string) $value ) ),
				CatalogPushItem::FIELD_ACTIVE      => $product->set_status( true === $value ? self::ACTIVE_PRODUCT_STATUS : self::INACTIVE_PRODUCT_STATUS ),
			};
		}

		$product->save();

		return CatalogPushOutcome::applied( $item->sender_reference() );
	}

	/**
	 * The declared catalogue fields this writer cannot write, if any.
	 *
	 * A non-empty answer rejects THE ARTICLE with
	 * `CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED`, and nothing of it is
	 * written.
	 *
	 * Two ways of being unwritable, and they are one question rather than two
	 * because the answer is the same: a key this writer has no arm for, and a
	 * value whose kind does not match its destination. A name that is not text
	 * and a flag that is not a boolean are the same event as an unknown key -
	 * the contract changed - and the closed list of five rejection reasons has
	 * no sixth for «right key, wrong kind»; inventing one would make
	 * `CatalogPushOutcome::rejected()` throw.
	 *
	 * PUBLIC, and the reason is the same one that makes
	 * `CatalogPushItem::unknown_keys_in()` public: the guard has to be
	 * answerable on its own. `CatalogPushItem` is final with a private
	 * constructor and cannot be made to declare a field it does not have, so
	 * asking it through `write()` can never produce the case this guard exists
	 * for - and a guard that nobody can exercise is a guard nobody knows works.
	 *
	 * @param array<string, string|bool> $declared_fields Fields the item declared, keyed by wire name.
	 * @return list<string> The offending keys, in the order they were declared.
	 */
	public static function unwritable_fields_in( array $declared_fields ): array {
		$unwritable = array();

		foreach ( $declared_fields as $field => $value ) {
			$field = (string) $field;

			$writable = match ( $field ) {
				CatalogPushItem::FIELD_NAME, CatalogPushItem::FIELD_DESCRIPTION => is_string( $value ),
				CatalogPushItem::FIELD_ACTIVE => is_bool( $value ),
				default => false,
			};

			if ( ! $writable ) {
				$unwritable[] = $field;
			}
		}

		return $unwritable;
	}
}
