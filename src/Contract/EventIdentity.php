<?php
/**
 * Composition of the identity of an order event.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * The identity of an event, composed from the domain object and from nothing
 * else.
 *
 * ---------------------------------------------------------------------------
 * NO DELIVERY HEADER MAY TAKE PART IN THIS
 * ---------------------------------------------------------------------------
 * This is the rule the whole idempotency of the integration rests on, so it is
 * written as a prohibition rather than as advice: `X-WC-Webhook-Delivery-ID`,
 * `X-WC-Webhook-ID`, the sending timestamp, a random nonce and anything else
 * that belongs to the DELIVERY are forbidden here. A sweep in the tests reads
 * this file looking for them.
 *
 * What goes wrong otherwise is not subtle. WooCommerce numbers every attempt
 * with a different delivery identifier, so an identity taken from there would
 * make the second attempt of the SAME notification look like a new event, and
 * the receiver would issue a SECOND invoice for one sale - in a consecutive
 * series registered with the tax authority, where a document is not deleted,
 * only corrected with another document.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS LIVES IN ONE PLACE
 * ---------------------------------------------------------------------------
 * Three listeners see the same facts by three different routes: payment
 * completed, order status changed and order refunded. They converge on ONE
 * outbox entry because they all compose the identity here, not because they
 * agreed on a format. The receiver derives the same string on its own, from the
 * same order, so the two sides match without either sending it.
 *
 * The paid order carries the `:paid` suffix and the refund does not. The suffix
 * reserves the namespace: another billable fact of the same order can be added
 * later without changing the identity of the one that already exists, and
 * changing that one would re-invoice the whole history. A refund identifier
 * already names a single, unrepeatable fact.
 */
final class EventIdentity {

	/**
	 * Identity of the fact "this order has been paid for".
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return string Identity, as `woocommerce:order:{orderId}:paid`.
	 */
	public static function for_paid_order( int $order_id ): string {
		return sprintf( '%s:order:%d:paid', Constants::PROVIDER, $order_id );
	}

	/**
	 * Identity of the fact "this amount has been refunded".
	 *
	 * A partial refund gets its own identity, which is why a refund is
	 * identified by the REFUND and not by the order: several refunds of one
	 * order are several facts, and the order status does not even change for a
	 * partial one.
	 *
	 * @param int $refund_id WooCommerce refund identifier.
	 * @return string Identity, as `woocommerce:refund:{refundId}`.
	 */
	public static function for_refund( int $refund_id ): string {
		return sprintf( '%s:refund:%d', Constants::PROVIDER, $refund_id );
	}

	/**
	 * The idempotency key the receiver derives from an identity, recomputed
	 * here so that the plugin can store it against the order and recognise it
	 * later in the correlation callback.
	 *
	 * It is a DERIVATION, not a second identity: the receiver computes the same
	 * digest from the same three parts, and this plugin never sends it.
	 *
	 * @param string $external_store_id Identifier of this store at the receiver.
	 * @param string $event_id          Identity produced by one of the methods above.
	 * @return string Hex digest, 64 characters.
	 */
	public static function idempotency_key( string $external_store_id, string $event_id ): string {
		return hash( 'sha256', sprintf( '%s:%s:%s', Constants::PROVIDER, $external_store_id, $event_id ) );
	}

	/**
	 * Not instantiable: composing an identity needs no state.
	 */
	private function __construct() {
	}
}
