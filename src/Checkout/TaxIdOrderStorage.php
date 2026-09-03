<?php
/**
 * The single place that knows the tax identifier meta keys of an order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Checkout;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\TaxIdField;

/**
 * Writes the buyer tax identifier onto an order, and reads it back.
 *
 * ---------------------------------------------------------------------------
 * THE ONLY FILE ALLOWED TO NAME A TAX IDENTIFIER META KEY
 * ---------------------------------------------------------------------------
 * This class is the ONLY piece of the plugin that may write or read the meta
 * keys the buyer tax identifier lives under. No other file - neither checkout,
 * neither the payload builder, neither the settings screen, neither a listener
 * - may call `update_meta_data`, `get_meta`, `update_post_meta` or
 * `get_post_meta` with one of those keys. Both checkouts delegate here.
 *
 * That rule is not tidiness. The key this plugin writes is not ours to choose:
 * the connector reads the delivered order body by walking an ENUMERATED
 * catalogue of meta keys, so a second file that wrote "almost" the same key
 * would capture the tax identifier and have nobody read it - and every B2B sale
 * of that store would quietly invoice as a simplified invoice, which is exactly
 * the failure this plugin exists to remove. One writer means one key, and one
 * key means the value is either read by the connector or not, never sometimes.
 *
 * ---------------------------------------------------------------------------
 * WHY THE ORDER OBJECT AND NEVER POST META
 * ---------------------------------------------------------------------------
 * Everything here goes through `WC_Order::update_meta_data()`,
 * `WC_Order::get_meta()` and `WC_Order::save()`. Under the modern order storage
 * (custom order tables) an order is not a post, so `update_post_meta()` writes
 * into a row nothing reads and `get_post_meta()` comes back empty - with no
 * error at all. The order object is the one API that is correct under both
 * storages, which is the entire reason the plugin declares its compatibility.
 *
 * ---------------------------------------------------------------------------
 * AN EMPTY VALUE WRITES NOTHING
 * ---------------------------------------------------------------------------
 * The field is optional: an order without a tax identifier is a normal order
 * that invoices as a simplified invoice. So an empty value - or one that is
 * only separators, which normalises to empty - stores NO key at all rather than
 * an empty one. An empty key is worse than an absent one on the receiving side:
 * the connector's catalogue takes the first key PRESENT with a non-empty value,
 * so an empty key of ours is a key it has to skip, and an empty value delivered
 * in the body reads like a captured-and-lost identifier rather than like a
 * buyer who did not give one.
 *
 * Writing nothing also means writing nothing DESTRUCTIVE: an empty submission
 * never deletes an identifier already recorded on the order. Removing one is a
 * correction of a fiscal document, not a checkout side effect, and no surface
 * of this plugin asks for it.
 */
final class TaxIdOrderStorage {

	/**
	 * Whether the mirror copy of the tax identifier has to be written.
	 *
	 * FALSE, and it stays false until a measurement says otherwise. This is
	 * branch B of the delivered-body decision, and it is switched on by MEASURING
	 * the body this plugin actually delivers - not by writing it "just in case".
	 *
	 * The measurement belongs to the phase that builds the delivered body: it
	 * checks whether the primary key appears in the order representation that is
	 * sent. If it does, nothing else is needed and this stays false. If it does
	 * NOT - because the additional checkout fields interface persists the value
	 * under a key the representation hides - then the connector never sees the
	 * identifier, and the fix is a second copy under a key that survives into the
	 * body. Flipping this one literal, with the date and the result of the
	 * measurement written next to it, is the whole switch.
	 *
	 * Writing the mirror unconditionally was rejected for two reasons: it
	 * duplicates a personal datum in the merchant's database with nothing asking
	 * for it, and it creates a second source for one value, which diverges the
	 * day somebody edits one of the two.
	 */
	public const MIRROR_REQUIRED = false;

	/**
	 * Whether this instance writes the mirror copy.
	 *
	 * @var bool
	 */
	private bool $mirror_enabled;

	/**
	 * Builds the storage with the mirror decision it is to honour.
	 *
	 * The default is the frozen decision above, so that whoever wires the plugin
	 * gets the measured behaviour without having to know this exists. The
	 * argument is there because a test has to be able to exercise BOTH branches:
	 * a decision that could only be read from a constant could only ever be
	 * tested in whichever state it happens to be in today.
	 *
	 * @param bool $mirror_enabled Whether to write the mirror copy as well.
	 */
	public function __construct( bool $mirror_enabled = self::MIRROR_REQUIRED ) {
		$this->mirror_enabled = $mirror_enabled;
	}

	/**
	 * Records the buyer tax identifier on the order.
	 *
	 * The value is normalised first - separators removed, upper cased - so that
	 * the same identifier typed in two ways is one value on the order and not
	 * two, and so that what the connector reads is what the validation accepted.
	 *
	 * The mirror, when it is switched on, NEVER overwrites a non-empty value
	 * already sitting under the mirror key. That key is shared ground: it is the
	 * one key of the connector's catalogue without a leading underscore, and a
	 * store running a third-party tax identifier plugin may be writing it itself.
	 * Overwriting it would replace the value that store has been invoicing with.
	 *
	 * @param \WC_Order $order Order to record the identifier on.
	 * @param string    $value Tax identifier as the buyer typed it.
	 * @return void
	 */
	public function write( \WC_Order $order, string $value ): void {
		$normalised = TaxIdField::normalise( $value );

		if ( '' === $normalised ) {
			return;
		}

		$order->update_meta_data( Constants::TAX_ID_META_KEY, $normalised );

		if ( $this->mirror_enabled && '' === $this->meta_string( $order, Constants::TAX_ID_MIRROR_META_KEY ) ) {
			$order->update_meta_data( Constants::TAX_ID_MIRROR_META_KEY, $normalised );
		}

		$order->save();
	}

	/**
	 * Reads the buyer tax identifier back off the order.
	 *
	 * The primary key is the single source: the mirror is a copy written FOR the
	 * connector, and reading it back would make a stale third-party value look
	 * like ours.
	 *
	 * Returns an empty string when there is none, never null. An order without a
	 * tax identifier is an ordinary order, so the absence is a normal value and
	 * not a condition a caller has to guard against.
	 *
	 * @param \WC_Order $order Order to read the identifier from.
	 * @return string The recorded identifier, or an empty string.
	 */
	public function read( \WC_Order $order ): string {
		return $this->meta_string( $order, Constants::TAX_ID_META_KEY );
	}

	/**
	 * Reads one meta value as a trimmed string.
	 *
	 * Order meta is not typed: anything can be sitting under a key, and under the
	 * mirror key - which is shared with third-party plugins - an array or an
	 * object is a real possibility rather than a defensive fantasy. Anything that
	 * is not a scalar reads as absent, because a tax identifier that is not text
	 * is not a tax identifier.
	 *
	 * @param \WC_Order $order Order to read from.
	 * @param string    $key   Meta key to read.
	 * @return string The value as a trimmed string, or an empty string.
	 */
	private function meta_string( \WC_Order $order, string $key ): string {
		$stored = $order->get_meta( $key, true );

		if ( ! is_scalar( $stored ) ) {
			return '';
		}

		return trim( (string) $stored );
	}
}
