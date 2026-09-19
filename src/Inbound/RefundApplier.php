<?php
/**
 * Applies an inbound refund notice as a real refund of this shop.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
use RuntimeException;

/**
 * Records one refund the platform already issued, as a WooCommerce refund.
 *
 * ---------------------------------------------------------------------------
 * A REAL REFUND OBJECT, AND NOT A NOTE SAYING THAT ONE HAPPENED
 * ---------------------------------------------------------------------------
 * `wc_create_refund()` is called, so the order of the shop stops claiming it
 * was paid in full: its total, its remaining refundable amount, its reports and
 * its status all come from refund OBJECTS, and nothing else moves them.
 *
 * The alternative - recording the refund only as meta - leaves the shop saying
 * the merchant kept money they gave back, and leaves them retyping the refund
 * by hand in the admin screen. That retyping is the exact work the inbound
 * notice exists to remove, and it is also how the two systems end up with two
 * different refunds for one return.
 *
 * ---------------------------------------------------------------------------
 * NO GATEWAY MOVEMENT, NO RESTOCK - BOTH PASSED EXPLICITLY
 * ---------------------------------------------------------------------------
 * `refund_payment => false` and `restock_items => false` travel in the call
 * even though they are also WooCommerce's defaults today. A default is not an
 * agreement: it is a value somebody may change in a release this plugin does
 * not ship with, and the two things it governs here are money and inventory.
 *
 *  - THE MONEY IS ALREADY BACK. It was returned outside this shop and a
 *    corrective invoice already backs it. Ordering the payment gateway to
 *    refund it again returns it a SECOND time, to a real buyer, out of the
 *    merchant's real balance, and no part of this plugin would notice.
 *  - THE STOCK IS ALREADY BACK. Inventory is governed by the platform, which
 *    restocked the returned goods when it accepted the return. Restocking here
 *    as well would count every returned item twice, and an overstated stock
 *    level sells goods the merchant does not have.
 *
 * ---------------------------------------------------------------------------
 * THE ANTI-LOOP MARK IS WRITTEN BEFORE THE REFUND IS SAVED
 * ---------------------------------------------------------------------------
 * `wc_create_refund()` fires the hooks that this plugin's own two refund
 * captors listen on, synchronously, inside this very call. If the mark
 * {@see Constants::INBOUND_REFUND_META_KEY} were written AFTER the call
 * returned, both captors would already have run, would have seen an unmarked
 * refund, and would have enqueued the refund BACK to the platform - which
 * would issue a SECOND corrective invoice over the same sale, in a consecutive
 * series filed with the tax authority, where a document is not deleted but
 * corrected with a third one.
 *
 * So the mark is attached from inside the creation itself. Measured on
 * 2026-09-18 against WooCommerce 8.9.5, the version `.wp-env.json` pins,
 * `includes/wc-order-functions.php`:
 *
 *   :620  do_action( 'woocommerce_create_refund', $refund, $args )   <- here
 *   :622  if ( $refund->save() ) {                                   <- the INSERT
 *   :651  do_action( 'woocommerce_order_partially_refunded', ... )
 *   :653  do_action( 'woocommerce_order_fully_refunded', ... )
 *   :657  $order->update_status( $parent_status )   fires woocommerce_order_status_changed
 *   :667  do_action( 'woocommerce_order_refunded', ... )
 *
 * `woocommerce_create_refund` is documented by WooCommerce as the hook to
 * "adjust refund before save", and it hands over the refund object BEFORE the
 * statement that creates the row. Writing the meta there costs no second write:
 * the very `save()` on the next line persists it, because
 * `Abstract_WC_Order_Data_Store_CPT::create()` calls `save_meta_data()` on the
 * object it has just inserted, and the high-performance order store does the
 * same. There is therefore NO instant at which a refund of this plugin exists
 * in the database without its mark - not even one statement's worth - and that
 * is what makes both captors safe:
 *
 *  1. `Events\OrderStatusChangedListener` runs at :657, on the status change a
 *     TOTAL refund causes, and filters marked refunds out of its list.
 *  2. `Events\OrderRefundedListener` runs at :667, re-reads the refund through
 *     `wc_get_order()` and returns without enqueueing when it is marked.
 *
 * Both read the mark's PRESENCE, as a non-empty scalar. The value written is
 * the idempotency key of the delivery that produced the refund, which is the
 * only handle that ties this refund back to the notice that ordered it; an
 * empty value is not a mark at all, so the key claimed by the ledger is used
 * and the notice's own key is the fallback when the caller hands over an empty
 * one. A mark that reads as absent opens the loop this whole block exists to
 * close.
 *
 * THE HOOK IS REMOVED AGAIN, ALWAYS. It is added immediately before the call
 * and removed in a `finally`, so a shop that refunds through its own admin
 * screen in the same request never gets a Factuarea mark on a refund the
 * merchant made - which would silence a refund that has to travel.
 *
 * ---------------------------------------------------------------------------
 * THE AMOUNT IS A STRING FROM THE WIRE TO THE DATABASE
 * ---------------------------------------------------------------------------
 * `$notice->amount` is handed to `wc_create_refund()` exactly as it arrived and
 * is never cast, compared as a number, rounded or formatted by this class.
 * `WC_Order_Refund::set_amount()` runs it through `wc_format_decimal()`, which
 * also takes and keeps a string, so the digits stored are the digits the
 * platform published. A single `(float)` on this path turns a refund of 12.34
 * into one of 12.339999999999999, and nothing downstream can tell which of the
 * two was meant.
 *
 * The shape is checked once more here, with the contract's own
 * {@see InboundRefundNotice::is_decimal_string()} and no second pattern. It is
 * defence in depth and not a duplicate decision: the factory already refuses a
 * body whose amount is not a decimal string, so a notice that fails here was
 * built by something that bypassed the factory, and creating a refund for an
 * amount nobody can read would move money on a guess.
 *
 * WHAT WOOCOMMERCE ITSELF DOES WITH IT is measured and left alone: at :605 it
 * derives the refund's negative total arithmetically. That derivation is
 * WooCommerce's, it happens inside a shop that already runs it on every manual
 * refund, and this plugin neither performs it nor corrects it.
 *
 * ---------------------------------------------------------------------------
 * THE SCOPE IS RECORDED, NOT RE-DERIVED
 * ---------------------------------------------------------------------------
 * `total` or `partial` is written down as the platform declared it. It is NOT
 * recomputed here by comparing the amount against the order total, because the
 * platform decided it against the INVOICE it rectified, and an invoice and a
 * shop order legitimately differ - shipping refunded separately, a coupon
 * settled outside the order, a rounding of the last cent. Re-deriving it would
 * produce a second opinion about one fact, and the one on the fiscal document
 * is the one that counts.
 *
 * What the scope does NOT do is drive the order status. WooCommerce decides
 * whether the order becomes `refunded` by measuring the amount against what is
 * still refundable (:651-:657), and that decision is its own; this class does
 * not call `set_status()` and does not override it.
 *
 * ---------------------------------------------------------------------------
 * NO LINE ITEMS, BECAUSE THE NOTICE CARRIES NONE
 * ---------------------------------------------------------------------------
 * The frozen inbound contract publishes an amount and no line detail
 * ({@see InboundRefundNotice}), so the refund created is an amount-level one.
 * Distributing the amount over the order's lines here would be inventing which
 * goods came back, and those invented lines are what a restock - the one this
 * class refuses - would then act on.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT WRITES ON THE ORDER, AND WHERE THE HISTORY LIVES
 * ---------------------------------------------------------------------------
 * Two order meta keys - the public reference of the corrective invoice and the
 * scope - so that the merchant's screen and the buyer's screen can show under
 * which document the money came back. They mirror the LAST inbound refund, the
 * same way the fulfilment meta mirrors the last notice.
 *
 * The per-refund history is not lost by that: the reason stored ON EACH REFUND
 * names its own corrective invoice, and one order note is written per applied
 * notice. The meta answers "under which document was this order last refunded";
 * the refunds and the notes answer "what happened to it".
 *
 * ---------------------------------------------------------------------------
 * HOW A FAILURE LEAVES THIS CLASS
 * ---------------------------------------------------------------------------
 * The signature answers nothing, so a failure travels as an exception and the
 * TYPE is the whole message to the receiver, exactly as
 * {@see InboundDeliveryLedger} already does:
 *
 *  - `InboundPayloadNotReadableException` - the notice cannot be read. It is
 *    PERMANENT: retrying the same body fails identically, so the receiver
 *    answers malformed and the sender stops.
 *  - `RuntimeException` - the shop refused to record the refund. It is not an
 *    answer about the notice, so it escapes as a 500, which the sender
 *    classifies as retryable and brings back.
 *
 * Nothing is swallowed. A refund the platform published and this shop dropped
 * in silence is money the two systems disagree about, found while squaring a
 * quarter.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT
 * ---------------------------------------------------------------------------
 * No HTTP request and no outbox entry. Every hook fired by the creation that
 * would have produced one is silenced by the mark, which is the point.
 */
final class RefundApplier {

	/**
	 * The WooCommerce action that hands over the refund BEFORE it is saved.
	 *
	 * Public so that the test asserts against this constant rather than against
	 * a second copy of the literal: the hook name is the hinge of the anti-loop
	 * mark, and a typo in it fails nowhere and duplicates corrective invoices
	 * everywhere.
	 */
	public const CREATE_REFUND_HOOK = 'woocommerce_create_refund';

	/**
	 * Priority the marking callback is attached at.
	 *
	 * The default one. Priority is irrelevant here - the callback is the only
	 * thing this plugin attaches to that hook and it is removed again before
	 * the call returns - and picking an unusual number would suggest an
	 * ordering agreement with somebody that does not exist.
	 */
	private const MARK_PRIORITY = 10;

	/**
	 * Arguments the marking callback takes.
	 *
	 * One: the refund. WooCommerce also passes the arguments of the call, and
	 * they are deliberately not taken - everything this callback needs about
	 * the notice it already closed over.
	 */
	private const MARK_ACCEPTED_ARGS = 1;

	/**
	 * Records one inbound refund notice against one order of this shop.
	 *
	 * The order arrives already resolved by the receiver, so this method does
	 * not look one up and does not re-check that the notice names it: two
	 * checks of one fact in two places is how the two end up disagreeing.
	 *
	 * It also lets a `RuntimeException` out of the creation below when the shop
	 * refused to record the refund. That one is not declared as a tag because it
	 * is not thrown here but by `create_marked_refund()`, which declares it; it
	 * is written down because the receiver has to tell the two apart, and the
	 * class block above says what each of them means for the answer.
	 *
	 * @param \WC_Order           $order           Order of this shop, already resolved.
	 * @param InboundRefundNotice $notice          Notice as it arrived, already read.
	 * @param string              $idempotency_key Key the ledger claimed for this delivery.
	 * @return void
	 *
	 * @throws InboundPayloadNotReadableException When the amount is not a decimal string, which is
	 *                                           permanent and must be answered as malformed.
	 */
	public function apply( \WC_Order $order, InboundRefundNotice $notice, string $idempotency_key ): void {
		if ( ! InboundRefundNotice::is_decimal_string( $notice->amount ) ) {
			throw InboundPayloadNotReadableException::field_of_wrong_kind(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message that reaches a log or a failing test and never a page; same trade as `Contract\CatalogPushOutcome::rejected()`. Escaping it would make this class call a WordPress function, and it also runs under a bare PHP unit suite with no WordPress loaded.
				Constants::INBOUND_ORDER_REFUND_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The frozen field name of the contract, declared in `Contract\InboundRefundNotice`; see the note on the argument above.
				InboundRefundNotice::FIELD_AMOUNT,
				'a decimal string'
			);
		}

		$this->create_marked_refund( $order, $notice, self::mark_for( $notice, $idempotency_key ) );

		$this->record_on_the_order( $order, $notice );
	}

	/**
	 * Creates the WooCommerce refund with the anti-loop mark already on it.
	 *
	 * @param \WC_Order           $order  Order being refunded.
	 * @param InboundRefundNotice $notice Notice being applied.
	 * @param string              $mark   Value of the anti-loop mark, never empty.
	 * @return void
	 *
	 * @throws RuntimeException When WooCommerce answered an error object instead of a refund.
	 */
	private function create_marked_refund( \WC_Order $order, InboundRefundNotice $notice, string $mark ): void {
		$marker = static function ( $refund ) use ( $mark ): void {
			/*
			 * `instanceof` and never `method_exists()`: on a double of a class
			 * that does not otherwise exist the latter answers false, which
			 * would make every unit run skip the write silently - the one
			 * failure this callback must not have.
			 */
			if ( ! $refund instanceof \WC_Order_Refund ) {
				return;
			}

			/*
			 * No `save()` here. The statement that follows this hook inside
			 * `wc_create_refund()` is the one that inserts the refund, and it
			 * persists the meta set on the object. Saving here would insert the
			 * row early and leave WooCommerce updating what it thought it was
			 * creating.
			 */
			$refund->update_meta_data( Constants::INBOUND_REFUND_META_KEY, $mark );
		};

		add_action( self::CREATE_REFUND_HOOK, $marker, self::MARK_PRIORITY, self::MARK_ACCEPTED_ARGS );

		try {
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $notice->amount,
					'reason'         => self::reason_for( $notice ),
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
		} finally {
			/*
			 * In a `finally` and not after the call: if WooCommerce throws, a
			 * callback of this plugin would otherwise stay attached for the
			 * rest of the request and mark the NEXT refund created in it - a
			 * refund of the merchant's own, silenced for good.
			 */
			remove_action( self::CREATE_REFUND_HOOK, $marker, self::MARK_PRIORITY );
		}

		if ( ! is_wp_error( $refund ) ) {
			return;
		}

		$reason  = self::error_message( $refund );
		$message = sprintf(
			'WooCommerce did not record the refund that Factuarea published for order %1$d under corrective invoice %2$s, so the delivery was not applied%3$s',
			$order->get_id(),
			$notice->corrective_invoice_reference,
			'' === $reason ? '.' : ': ' . $reason
		);

		$this->report( $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Same trade as `Contract\CatalogPushOutcome::rejected()`: escaping would call a WordPress function from a path that also runs in the Unit suite, and the message carries no credential by the rule of `Exception\PluginException`.
		throw new RuntimeException( $message );
	}

	/**
	 * Writes on the order what the merchant and the buyer have to be able to
	 * read, and the note the merchant greps.
	 *
	 * The order object is the one the receiver resolved, and `wc_create_refund()`
	 * has meanwhile written to the order through an instance of its own. Saving
	 * this one does not undo that: WooCommerce's order store writes only the
	 * properties that CHANGED on the instance being saved, and the only thing
	 * changed here is these two meta keys. The status a full refund may have
	 * moved is not among them.
	 *
	 * @param \WC_Order           $order  Order being refunded.
	 * @param InboundRefundNotice $notice Notice being applied.
	 * @return void
	 */
	private function record_on_the_order( \WC_Order $order, InboundRefundNotice $notice ): void {
		$order->update_meta_data( Constants::CORRECTIVE_REFERENCE_META_KEY, $notice->corrective_invoice_reference );
		$order->update_meta_data( Constants::REFUND_SCOPE_META_KEY, $notice->scope );

		/*
		 * Saved BEFORE the note, and the order matters: an order note is a row
		 * of its own that `save()` does not carry, so a note written first
		 * would survive a save that failed and would tell the merchant about a
		 * reference the order does not hold.
		 */
		$order->save();

		$order->add_order_note( self::note_for( $notice ) );
	}

	/**
	 * The value the anti-loop mark carries.
	 *
	 * The key the ledger claimed wins, so that the mark on the refund and the
	 * row in the ledger name the same delivery. It falls back to the key the
	 * notice declared - which the factory guarantees is not empty - because an
	 * empty mark is not a mark: both captors test its PRESENCE, and a refund
	 * marked with an empty string travels back to the platform exactly as an
	 * unmarked one would.
	 *
	 * @param InboundRefundNotice $notice          Notice being applied.
	 * @param string              $idempotency_key Key the ledger claimed, as the receiver handed it over.
	 * @return string A non-empty mark.
	 */
	private static function mark_for( InboundRefundNotice $notice, string $idempotency_key ): string {
		$claimed = trim( $idempotency_key );

		if ( '' !== $claimed ) {
			return $claimed;
		}

		return $notice->idempotency_key;
	}

	/**
	 * The reason stored on the refund itself.
	 *
	 * It names the corrective invoice, which is what makes each refund of an
	 * order self-describing in the admin screen: the order meta only mirrors
	 * the last one, and an order refunded twice has two documents.
	 *
	 * The scope travels as the wire literal rather than as a label, for the
	 * same reason the fulfilment note does: it is what a merchant greps when a
	 * refund is disputed, and it has to be the string both systems used.
	 *
	 * @param InboundRefundNotice $notice Notice being applied.
	 * @return string The reason, in English and translatable.
	 */
	private static function reason_for( InboundRefundNotice $notice ): string {
		return sprintf(
			/* translators: 1: scope of the refund as the platform declared it, `total` or `partial`. 2: public reference of the corrective invoice that backs it. */
			__( 'Factuarea %1$s refund, corrective invoice %2$s', 'factuarea-for-woocommerce' ),
			$notice->scope,
			$notice->corrective_invoice_reference
		);
	}

	/**
	 * The order note written for one applied notice.
	 *
	 * Private to the merchant - `add_order_note()` writes a private note unless
	 * asked otherwise - because the buyer has their own place to read this and a
	 * customer note would e-mail them about money they have already been told
	 * about by the platform that returned it.
	 *
	 * The currency is named because it is what the platform declared, and a
	 * refund is created in the currency of the ORDER: naming both is what makes
	 * a mismatch visible to the merchant instead of silent.
	 *
	 * @param InboundRefundNotice $notice Notice being applied.
	 * @return string The note, in English and translatable.
	 */
	private static function note_for( InboundRefundNotice $notice ): string {
		return sprintf(
			/* translators: 1: refunded amount exactly as the platform declared it. 2: currency code. 3: scope, `total` or `partial`. 4: public reference of the corrective invoice. */
			__( 'Factuarea recorded a %3$s refund of %1$s %2$s, backed by corrective invoice %4$s. No payment was ordered through the gateway and no stock was restocked: the platform already did both.', 'factuarea-for-woocommerce' ),
			$notice->amount,
			$notice->currency,
			$notice->scope,
			$notice->corrective_invoice_reference
		);
	}

	/**
	 * The message of a WordPress error object, or an empty string.
	 *
	 * Read through `method_exists()` on purpose, which is the opposite trade
	 * from the instance check above: here the object comes from WooCommerce and
	 * the only thing wanted from it is one string, so a shop whose error object
	 * is not the usual one loses a sentence of the log line instead of turning
	 * a refund failure into a fatal error.
	 *
	 * @param mixed $error Whatever `wc_create_refund()` answered.
	 * @return string The message, trimmed, or an empty string.
	 */
	private static function error_message( $error ): string {
		if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_message' ) ) {
			return '';
		}

		return trim( (string) $error->get_error_message() );
	}

	/**
	 * Records a problem where the merchant and their host will look for it.
	 *
	 * WooCommerce's own logger and not `error_log()`, for the reason
	 * `Events\OrderRefundedListener` writes down: it lands on the shop's log
	 * screen and honours the shop's retention. The two guards are the same ones
	 * and are not defensive noise - the logger is replaceable by a filter, and
	 * losing a log line is the cheap failure next to a fatal error inside a
	 * receiver.
	 *
	 * Nothing passed here may contain a credential; see `Exception\PluginException`.
	 *
	 * @param string $message What went wrong, in English, with no credential.
	 * @return void
	 */
	private function report( string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		if ( ! is_object( $logger ) || ! method_exists( $logger, 'error' ) ) {
			return;
		}

		$logger->error( $message, array( 'source' => Constants::PLUGIN_SLUG ) );
	}
}
