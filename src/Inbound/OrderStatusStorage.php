<?php
/**
 * Writes the fulfilment status the platform published onto the merchant's order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;

/**
 * Stores one inbound fulfilment notice on one order, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT WRITES
 * ---------------------------------------------------------------------------
 * Five order meta keys, all of them declared in `Contract\Constants` and all of
 * them the plugin's OWN:
 *
 *   FULFILMENT_STATUS_META_KEY     the state, as the wire literal
 *   FULFILMENT_APPLIED_AT_META_KEY the instant OF THE FACT the notice declared
 *   TRACKING_NUMBER_META_KEY       written when the notice declares it, deleted when it does not
 *   TRACKING_CARRIER_META_KEY      idem
 *   TRACKING_URL_META_KEY          idem, and stored exactly as it arrived
 *
 * Plus one order note per applied notice, which is the record the merchant
 * actually reads.
 *
 * It does not resolve the order - the receiver did that and hands it in - it
 * does not decode anything, it does not answer a status, and it does not decide
 * whether the delivery was authenticated or already applied. Those belong to
 * the receiver, to `InboundSignatureVerifier` and to `InboundDeliveryLedger`.
 *
 * ---------------------------------------------------------------------------
 * PROHIBITION: THIS CLASS NEVER TOUCHES THE NATIVE ORDER STATUS
 * ---------------------------------------------------------------------------
 * `WC_Order::set_status()` and `WC_Order::update_status()` MUST NOT be called
 * from here, and no future change may add them. This is a prohibition and not
 * advice, because the cost is not paid by whoever adds the call:
 *
 * 1. IT SENDS A DELIVERY BACK OUT. Writing the native status fires
 *    `woocommerce_order_status_changed`, which is the hook this very plugin
 *    listens on with `Events\OrderStatusChangedListener` (`HOOK`, `:124`), and
 *    that listener ENQUEUES AN OUTBOX ENTRY towards Factuarea. A notice that
 *    the platform published into the shop would come straight back to the
 *    platform as if the merchant had moved the order - one inbound fact turned
 *    into an outbound write, on every single notice.
 * 2. IT FIRES EVERY THIRD-PARTY AUTOMATION THE MERCHANT INSTALLED. That same
 *    hook drives shipping plugins, customer e-mails, accounting bridges and
 *    whatever else the shop runs. An informational notice would send buyers
 *    e-mails nobody asked for, about a change that did not happen in the shop.
 * 3. THERE IS NO HONEST TRANSLATION ANYWAY. WooCommerce has no «prepared» and
 *    no «delivered»; `Contract\FulfilmentState` holds that reasoning in full.
 *
 * So the state lives next to the native one, never instead of it, and the
 * merchant's own workflow keeps owning the order.
 *
 * ---------------------------------------------------------------------------
 * THE META MIRRORS THE LAST NOTICE; THE NOTES HOLD THE HISTORY
 * ---------------------------------------------------------------------------
 * Every applied notice rewrites the three tracking keys: the ones it declares
 * are written and the ones it does not are DELETED. Keeping a value the current
 * notice no longer declares would show the buyer the tracking of a PREVIOUS
 * shipment as if it were the current one - a parcel that already arrived, or
 * one that went back - and the buyer has no way of telling that it is stale.
 *
 * Nothing is lost by deleting, because the order note written for each notice
 * keeps the tracking it carried. The meta answers «where is this order now»;
 * the notes answer «what happened to it», and conflating the two is how a
 * screen ends up lying.
 *
 * A state that does not carry tracking stores none of it, per
 * `FulfilmentState::carries_tracking()`. Measured on 2026-09-18 against the
 * emitter that feeds this receiver: `StoreOrderStatusPublisher::trackingFor()`
 * answers null unless `StoreOrderFeedbackStage::carriesTracking()` does, and
 * that is true for `shipped` alone - so today no legitimate notice reaches this
 * branch with the other three states. It is written down because the day one
 * does, publishing a tracking number next to «prepared» would announce a
 * shipment that has not left.
 *
 * ---------------------------------------------------------------------------
 * THE TRACKING ADDRESS IS STORED AS IT ARRIVED
 * ---------------------------------------------------------------------------
 * It is never composed here out of the carrier and the number. The platform
 * derives it from its own carrier template and does not persist it in any
 * column, so deriving it a second time in this package would give two different
 * addresses for the same shipment the day that template changes - and the one
 * the buyer clicks would be the wrong one, with nothing in either system saying
 * so. A notice that brings a carrier and a number and NO address stores no
 * address: the absence is stored as an absence.
 *
 * ---------------------------------------------------------------------------
 * A LATE NOTICE DOES NOT OVERWRITE A MORE RECENT ONE
 * ---------------------------------------------------------------------------
 * Deliveries are queued and retried on a shared schedule that spans days, so
 * «shipped» published at 10:00 can legitimately arrive AFTER «delivered»
 * published at 11:00. Applying them in arrival order would tell the buyer their
 * parcel is on its way after telling them it arrived.
 *
 * The comparison is on the instant OF THE FACT, which each notice carries and
 * this class stores, and never on the instant of the write: the write order is
 * precisely the one that is wrong. A notice strictly older than the stored one
 * is dropped whole - no meta, no note, no save - and the receiver still answers
 * accepted, because the delivery WAS processed: its fact was simply superseded,
 * and asking the sender to retry would only bring the same stale notice back.
 *
 * When either instant cannot be read the notice IS applied. Refusing would let
 * one unreadable value freeze an order's fulfilment status for good, and the
 * newest notice is still the best information available.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT
 * ---------------------------------------------------------------------------
 * No HTTP request, no outbox entry, no scheduled task. Applying what arrived
 * does not need the network, and going out would turn one delivery into two.
 */
final class OrderStatusStorage {

	/**
	 * Applies one fulfilment notice to one order of this shop.
	 *
	 * The order arrives already resolved by the receiver, so this method neither
	 * looks one up nor checks that the notice's remote identifier matches: doing
	 * it twice in two places is how the two checks end up disagreeing.
	 *
	 * @param \WC_Order           $order  Order of this shop, already resolved.
	 * @param InboundStatusNotice $notice Notice as it arrived, already read.
	 * @return void
	 */
	public function apply( \WC_Order $order, InboundStatusNotice $notice ): void {
		if ( $this->is_stale( $order, $notice ) ) {
			return;
		}

		$carries_tracking = FulfilmentState::carries_tracking( $notice->status );

		$number  = $carries_tracking ? $notice->tracking_number : null;
		$carrier = $carries_tracking ? $notice->tracking_carrier : null;
		$url     = $carries_tracking ? $notice->tracking_url : null;

		$order->update_meta_data( Constants::FULFILMENT_STATUS_META_KEY, $notice->status );
		$order->update_meta_data( Constants::FULFILMENT_APPLIED_AT_META_KEY, $notice->occurred_at );

		$this->write_or_delete( $order, Constants::TRACKING_NUMBER_META_KEY, $number );
		$this->write_or_delete( $order, Constants::TRACKING_CARRIER_META_KEY, $carrier );
		$this->write_or_delete( $order, Constants::TRACKING_URL_META_KEY, $url );

		// Saved BEFORE the note is written, and the order matters: an order note
		// is a row of its own that `save()` does not carry, so a note written
		// first would survive a save that failed and would tell the merchant a
		// state that is not on the order.
		$order->save();

		$order->add_order_note( $this->note_for( $notice, $number, $carrier, $url ) );
	}

	/**
	 * Is this notice older than the one already applied to the order?
	 *
	 * Strictly older, so an equal instant applies. Two notices of the same
	 * instant are two distinct facts - a redelivery of the SAME one never
	 * reaches this class, because the receiver claims its key in the idempotency
	 * ledger before applying anything and a key already claimed is answered 409
	 * without ever reaching a writer - and between two facts stamped at the same
	 * second the later arrival is the one the sender resolved last.
	 *
	 * The claim is NOT the first thing the receiver does: the body is read, the
	 * key length is checked and the order is resolved first, so that no
	 * permanent refusal leaves a key claimed for an effect that never landed
	 * (`docs/inbound-contract.md` section 8.1).
	 *
	 * @param \WC_Order           $order  Order to read the last applied instant from.
	 * @param InboundStatusNotice $notice Notice being applied.
	 * @return bool True when the notice must be dropped whole.
	 */
	private function is_stale( \WC_Order $order, InboundStatusNotice $notice ): bool {
		$applied_at = $this->meta_string( $order, Constants::FULFILMENT_APPLIED_AT_META_KEY );

		if ( '' === $applied_at ) {
			return false;
		}

		$previous = strtotime( $applied_at );
		$incoming = strtotime( $notice->occurred_at );

		if ( false === $previous || false === $incoming ) {
			return false;
		}

		return $incoming < $previous;
	}

	/**
	 * Writes one tracking meta key, or deletes it when the notice has no value.
	 *
	 * The delete is unconditional rather than guarded by a read: deleting a key
	 * that is not there is free, and a guard would be a second place where the
	 * rule «the meta mirrors the last notice» could be got wrong.
	 *
	 * @param \WC_Order   $order Order being written.
	 * @param string      $key   Meta key to write or delete.
	 * @param string|null $value Value the notice declared, or null when it did not.
	 * @return void
	 */
	private function write_or_delete( \WC_Order $order, string $key, ?string $value ): void {
		if ( null === $value ) {
			$order->delete_meta_data( $key );

			return;
		}

		$order->update_meta_data( $key, $value );
	}

	/**
	 * Composes the order note for one applied notice.
	 *
	 * Private to the merchant - `add_order_note()` writes a private note unless
	 * asked otherwise - because the buyer has their own place to read this, in
	 * «My account», and a customer note would e-mail them a second time about a
	 * state they can already see.
	 *
	 * The state is written as the WIRE LITERAL and not as a label. The note is
	 * what a merchant greps when a shipment is disputed, and it has to be the
	 * same string that sits in the meta and that the platform published; the
	 * readable labels belong to the buyer's screen, where they are translated.
	 *
	 * @param InboundStatusNotice $notice  Notice being applied.
	 * @param string|null         $number  Tracking number being stored, if any.
	 * @param string|null         $carrier Carrier being stored, if any.
	 * @param string|null         $url     Tracking address being stored, if any.
	 * @return string The note, in English and translatable.
	 */
	private function note_for( InboundStatusNotice $notice, ?string $number, ?string $carrier, ?string $url ): string {
		$note = sprintf(
			/* translators: %s: fulfilment state published by Factuarea - prepared, shipped, delivered or returned. */
			__( 'Factuarea fulfilment status: %s.', 'factuarea-for-woocommerce' ),
			$notice->status
		);

		if ( null !== $carrier ) {
			$note .= ' ' . sprintf(
				/* translators: %s: name of the carrier handling the shipment. */
				__( 'Carrier: %s.', 'factuarea-for-woocommerce' ),
				$carrier
			);
		}

		if ( null !== $number ) {
			$note .= ' ' . sprintf(
				/* translators: %s: tracking number of the shipment. */
				__( 'Tracking number: %s.', 'factuarea-for-woocommerce' ),
				$number
			);
		}

		if ( null !== $url ) {
			$note .= ' ' . sprintf(
				/* translators: %s: tracking address of the shipment, as the platform composed it. */
				__( 'Tracking address: %s.', 'factuarea-for-woocommerce' ),
				$url
			);
		}

		return $note;
	}

	/**
	 * Reads one meta value as a trimmed string.
	 *
	 * Order meta is not typed and this key can have been written by an older
	 * version of this plugin, so anything that is not a scalar reads as absent:
	 * an instant that is not text is not an instant, and treating it as one
	 * would compare a value against an array.
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
