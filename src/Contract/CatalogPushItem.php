<?php
/**
 * One item of an inbound catalogue, price and stock batch.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * One article of a catalogue push, as it arrived.
 *
 * ---------------------------------------------------------------------------
 * THE LIST OF ACCEPTED KEYS IS ENUMERATED, AND ANYTHING ELSE IS REJECTED
 * ---------------------------------------------------------------------------
 * This is the strictest rule of the inbound half, and it is strict on purpose.
 * An unknown key is not a datum to ignore politely: it is a CONTRACT THAT
 * CHANGED. Accepting it silently means the platform believes it published
 * something the shop never applied, and in a catalogue that difference is a
 * price or a quantity - the merchant finds out by selling at the wrong price.
 *
 * The cost is written down rather than hidden: the day the sender adds a field,
 * shops that have not updated the plugin reject their batches. That is a
 * visible, reversible failure with a message; the alternative is a silent loss
 * of data. It is mitigated by documenting the contract in this repository and by
 * the REST namespace being versioned since the first release, which is what that
 * version is for.
 *
 * ---------------------------------------------------------------------------
 * TWO KINDS OF UNACCEPTED KEY, AND THEY DO NOT FAIL THE SAME WAY
 * ---------------------------------------------------------------------------
 * The distinction is why {@see self::internal_keys_in()} and
 * {@see self::unknown_keys_in()} are separate and public:
 *
 *  - An UNKNOWN key rejects THE WHOLE BATCH as malformed (the spec's
 *    unknown-field scenario and task 13.3): an unknown field is a contract that
 *    changed, not a datum to skip, and a sender whose contract moved has to
 *    hear it once and loudly rather than as a trickle of per-item rejections
 *    on a 200. `CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED` is the
 *    per-item reason a WRITER answers when a declared catalogue field is one
 *    it cannot write - a guard that is unreachable today by construction.
 *  - An INTERNAL key - cost, supplier, physical balance, committed quantity,
 *    reorder threshold - rejects THE WHOLE BATCH as malformed too, but for a
 *    different reason that the receiver must be able to NAME apart: these are
 *    not fields this receiver has not learnt yet, they are figures that must
 *    never leave the platform, and one of them arriving means something
 *    upstream is leaking. Applying the rest of that batch would leave the leak
 *    without a witness.
 *
 * ---------------------------------------------------------------------------
 * THE KEYS, AND WHY EACH IS SHAPED THE WAY IT IS
 * ---------------------------------------------------------------------------
 *   remote_id          string, optional - the identity this plugin already
 *                      registered for the article. ONE value and not a
 *                      product/variation pair: this side resolves with the
 *                      WooCommerce product handle, which addresses a variation
 *                      by its own identifier, so a parent identifier would be a
 *                      second value to keep in step for nothing.
 *   sku                string, required - the reference. It is the only way to
 *                      resolve an article whose identity is not registered yet,
 *                      so an item without one cannot be applied to anything.
 *   name               string, optional - catalogue field.
 *   description        string, optional - catalogue field, rich content.
 *   active             bool,   optional - catalogue field.
 *   price              STRING, optional - see below.
 *   available_quantity int,    optional - see below.
 *
 * `price` is a STRING for the same reason the refunded amount is: a float
 * cannot hold most two-decimal amounts exactly, and `WC_Product::set_regular_price()`
 * accepts a string and persists it verbatim.
 *
 * A price sent as a NUMBER instead of a string makes the item unreadable here:
 * it is a type the contract does not declare, and casting it is the very loss
 * the string exists to avoid. Whether the STRING is a readable decimal is a
 * different question and is NOT asked here: it is asked by the price writer,
 * which rejects that one item with a typed reason and leaves its previous price
 * alone. Failing it here would make the whole batch malformed over one bad
 * amount and lose the other four articles of it. The pattern both use is the one
 * {@see InboundRefundNotice::is_decimal_string()} owns, read from there rather
 * than written twice.
 *
 * There is no `sale_price` key. The inbound contract publishes A PRICE, not a
 * campaign: a sale price the merchant set in their own shop is theirs, and a
 * receiver that cleared it would be undoing a decision nobody asked it to
 * revisit.
 *
 * There is no `manage_stock` key either, and its absence is the enforcement of a
 * decision: an article that does not manage stock is REJECTED as unmanaged and
 * does not have stock management switched on for it. Switching it on is a
 * decision about the merchant's shop, and taking it from a webhook changes how
 * their catalogue behaves without anyone asking.
 *
 * `available_quantity` is named in full on the wire on purpose. It is the
 * AVAILABLE quantity - the platform already subtracted the live commitment
 * before publishing - and never the physical balance. A key called `quantity`
 * would let the two be confused, and the cost of confusing them is offering
 * units that are already sold.
 */
final class CatalogPushItem {

	/**
	 * Item key holding the remote identity already registered for the article.
	 */
	public const FIELD_REMOTE_ID = 'remote_id';

	/**
	 * Item key holding the reference the article is reconciled by.
	 */
	public const FIELD_SKU = 'sku';

	/**
	 * Item key holding the article name.
	 */
	public const FIELD_NAME = 'name';

	/**
	 * Item key holding the article description, as rich content.
	 */
	public const FIELD_DESCRIPTION = 'description';

	/**
	 * Item key holding whether the article is on sale in the shop.
	 */
	public const FIELD_ACTIVE = 'active';

	/**
	 * Item key holding the price, as a decimal string.
	 */
	public const FIELD_PRICE = 'price';

	/**
	 * Item key holding the AVAILABLE quantity.
	 */
	public const FIELD_AVAILABLE_QUANTITY = 'available_quantity';

	/**
	 * The catalogue fields, as opposed to the price and the quantity, which
	 * travel in the same item and are written by other hands.
	 *
	 * The split is not cosmetic: the three writers of this contract each own
	 * their fields, and a field list that mixed them would have each writer
	 * deciding which of the keys are not its business.
	 *
	 * @var list<string>
	 */
	public const CATALOG_FIELDS = array(
		self::FIELD_NAME,
		self::FIELD_DESCRIPTION,
		self::FIELD_ACTIVE,
	);

	/**
	 * Every key an item may carry. Enumerated one by one; anything outside this
	 * list rejects.
	 *
	 * @var list<string>
	 */
	public const ACCEPTED_KEYS = array(
		self::FIELD_REMOTE_ID,
		self::FIELD_SKU,
		self::FIELD_NAME,
		self::FIELD_DESCRIPTION,
		self::FIELD_ACTIVE,
		self::FIELD_PRICE,
		self::FIELD_AVAILABLE_QUANTITY,
	);

	/**
	 * Keys that must NEVER travel, and whose presence rejects the whole batch.
	 *
	 * Enumerated rather than matched by a prefix or a heuristic, because the
	 * check has to be answerable: «does this batch carry a figure that should
	 * not have left the platform» is a yes or a no, and a pattern would answer
	 * «maybe» for a field named `supplier_reference` that is nobody's cost.
	 *
	 * @var list<string>
	 */
	public const INTERNAL_KEYS = array(
		'cost',
		'supplier',
		'physical_quantity',
		'committed_quantity',
		'reorder_threshold',
	);

	/**
	 * Use {@see self::from_array()}.
	 *
	 * @param string|null $remote_id          Registered remote identity, or null.
	 * @param string      $sku                Reference of the article.
	 * @param string|null $name               Article name, or null when not declared.
	 * @param string|null $description        Article description, or null when not declared.
	 * @param bool|null   $active             Whether the article is on sale, or null when not declared.
	 * @param string|null $price              Price as a decimal string, or null when not declared.
	 * @param int|null    $available_quantity Available quantity, or null when not declared.
	 */
	private function __construct(
		public readonly ?string $remote_id,
		public readonly string $sku,
		public readonly ?string $name,
		public readonly ?string $description,
		public readonly ?bool $active,
		public readonly ?string $price,
		public readonly ?int $available_quantity,
	) {
	}

	/**
	 * Reads one already decoded item, or answers null when it is not one.
	 *
	 * Null covers every way of not being readable - a missing reference, a value
	 * of the wrong type, a key outside the enumerated list. The CALLER is the
	 * one that has to say WHY, and it has
	 * {@see self::internal_keys_in()} and {@see self::unknown_keys_in()} to say
	 * it with: the two failures have different blast radii and must not be
	 * reported as the same thing.
	 *
	 * @param array<string, mixed> $item Item already decoded as an associative array.
	 * @return self|null The item, or null when the shape is not the expected one.
	 */
	public static function from_array( array $item ): ?self {
		if ( array() !== self::internal_keys_in( $item ) || array() !== self::unknown_keys_in( $item ) ) {
			return null;
		}

		$sku = $item[ self::FIELD_SKU ] ?? null;

		if ( ! is_string( $sku ) || '' === $sku ) {
			return null;
		}

		$remote_id   = self::optional_string( $item, self::FIELD_REMOTE_ID );
		$name        = self::optional_string( $item, self::FIELD_NAME );
		$description = self::optional_string( $item, self::FIELD_DESCRIPTION );

		if ( false === $remote_id || false === $name || false === $description ) {
			return null;
		}

		// The type is checked before reading, because `false` is a legitimate
		// value of this field and cannot double as "malformed" the way it does
		// for the string and integer readers.
		if ( array_key_exists( self::FIELD_ACTIVE, $item ) && ! is_bool( $item[ self::FIELD_ACTIVE ] ) ) {
			return null;
		}

		$active = self::optional_bool( $item, self::FIELD_ACTIVE );

		$quantity = self::optional_int( $item, self::FIELD_AVAILABLE_QUANTITY );

		if ( false === $quantity ) {
			return null;
		}

		$price = self::optional_string( $item, self::FIELD_PRICE );

		if ( false === $price ) {
			return null;
		}

		return new self( $remote_id, $sku, $name, $description, $active, $price, $quantity );
	}

	/**
	 * The internal keys this item carries, if any.
	 *
	 * A non-empty answer rejects the WHOLE batch as malformed.
	 *
	 * @param array<string, mixed> $item Item as decoded, before reading it.
	 * @return list<string> The offending keys, in the order they are enumerated.
	 */
	public static function internal_keys_in( array $item ): array {
		$found = array();

		foreach ( self::INTERNAL_KEYS as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$found[] = $key;
			}
		}

		return $found;
	}

	/**
	 * The keys of this item that the contract does not enumerate and that are
	 * not internal figures either.
	 *
	 * A non-empty answer rejects THE WHOLE BATCH as malformed: an unknown key
	 * is a contract that changed, and the receiver names it apart from an
	 * internal figure (see the class block above).
	 *
	 * @param array<string, mixed> $item Item as decoded, before reading it.
	 * @return list<string> The offending keys, in the order they arrived.
	 */
	public static function unknown_keys_in( array $item ): array {
		$found = array();

		foreach ( array_keys( $item ) as $key ) {
			$key = (string) $key;

			if ( in_array( $key, self::ACCEPTED_KEYS, true ) || in_array( $key, self::INTERNAL_KEYS, true ) ) {
				continue;
			}

			$found[] = $key;
		}

		return $found;
	}

	/**
	 * Was the item's price declared at all?
	 *
	 * Asked rather than inferred from a null, so that the price writer never has
	 * to decide what an absent price means. Absent means «this push is not about
	 * the price», and a push of stock must not blank one.
	 *
	 * @return bool
	 */
	public function declares_price(): bool {
		return null !== $this->price;
	}

	/**
	 * Was the item's available quantity declared at all?
	 *
	 * @return bool
	 */
	public function declares_quantity(): bool {
		return null !== $this->available_quantity;
	}

	/**
	 * The catalogue fields this item actually declares, keyed by their wire name.
	 *
	 * This is what the catalogue writer applies, and the reason it is computed
	 * here: a field the push did not declare must not be written, and the writer
	 * asking each property whether it is null one by one is how one of them ends
	 * up blanking a description because a price push did not carry it.
	 *
	 * @return array<string, string|bool>
	 */
	public function declared_catalog_fields(): array {
		$fields = array();

		if ( null !== $this->name ) {
			$fields[ self::FIELD_NAME ] = $this->name;
		}

		if ( null !== $this->description ) {
			$fields[ self::FIELD_DESCRIPTION ] = $this->description;
		}

		if ( null !== $this->active ) {
			$fields[ self::FIELD_ACTIVE ] = $this->active;
		}

		return $fields;
	}

	/**
	 * How the sender identifies this item, for the per-item outcome.
	 *
	 * The remote identity when it travelled, the reference otherwise. NEVER an
	 * internal identifier of this shop: the sender asked about something it
	 * named, and answering with a number of ours is answering a question nobody
	 * asked - and it would publish this shop's internal identifiers to a
	 * platform that has no use for them.
	 *
	 * @return string
	 */
	public function sender_reference(): string {
		return null !== $this->remote_id ? $this->remote_id : $this->sku;
	}

	/**
	 * An optional field that, WHEN PRESENT, has to be a non-empty string.
	 *
	 * Three answers: the string, `null` when absent, `false` when present and
	 * malformed. Collapsing the last two would accept a price sent as a number
	 * and quietly apply nothing.
	 *
	 * @param array<string, mixed> $item  Decoded item.
	 * @param string               $field Field name.
	 * @return string|null|false The value, null when absent, false when malformed.
	 */
	private static function optional_string( array $item, string $field ) {
		if ( ! array_key_exists( $field, $item ) ) {
			return null;
		}

		$value = $item[ $field ];

		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		return $value;
	}

	/**
	 * An optional integer field. Same three answers as
	 * {@see self::optional_string()}.
	 *
	 * A numeric string is NOT accepted: a quantity is a count, the contract
	 * declares it as an integer, and accepting both shapes is how a field ends
	 * up meaning two things.
	 *
	 * @param array<string, mixed> $item  Decoded item.
	 * @param string               $field Field name.
	 * @return int|null|false The value, null when absent, false when malformed.
	 */
	private static function optional_int( array $item, string $field ) {
		if ( ! array_key_exists( $field, $item ) ) {
			return null;
		}

		$value = $item[ $field ];

		if ( ! is_int( $value ) ) {
			return false;
		}

		return $value;
	}

	/**
	 * An optional boolean field.
	 *
	 * It returns only the value or null, and the caller checks the type
	 * separately: `false` cannot double as «malformed» for a boolean field,
	 * because `false` is one of its legitimate values.
	 *
	 * @param array<string, mixed> $item  Decoded item.
	 * @param string               $field Field name.
	 * @return bool|null The value, or null when absent or not a boolean.
	 */
	private static function optional_bool( array $item, string $field ): ?bool {
		if ( ! array_key_exists( $field, $item ) ) {
			return null;
		}

		$value = $item[ $field ];

		return is_bool( $value ) ? $value : null;
	}
}
