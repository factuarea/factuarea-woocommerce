<?php
/**
 * Captures the order status transitions the receiver knows how to bill.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Events;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Payload\OrderPayloadBuilder;

/**
 * Records an order in the outbox when its status changes into one the receiver
 * turns into a document, and records NOTHING when it changes into one that does
 * not.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS LISTENER EXISTS AT ALL, NEXT TO THE PAYMENT ONE
 * ---------------------------------------------------------------------------
 * `woocommerce_payment_complete` only fires when a payment gateway reports a
 * payment. A shop that takes bank transfers or cash on delivery collects money
 * without any gateway saying so: the merchant marks the order Processing or
 * Completed by hand, and no payment hook ever runs. Those are sales, and they
 * are invoiced from here.
 *
 * The two capture routes are NOT a duplicate to be deduplicated later. They see
 * different facts through the same door, and the door is the outbox: both
 * compose the identity of "this order has been paid for" in ONE place
 * ({@see EventIdentity::for_paid_order()}), so an order that arrives by both
 * routes collapses into ONE entry on the UNIQUE constraint of `event_id`, not
 * because either of them checked whether the other had been there. Nothing in
 * this file asks the outbox what it already holds.
 *
 * ---------------------------------------------------------------------------
 * THE CATALOGUE OF RELEVANT STATUSES IS READ FROM THE RECEIVER, NOT INVENTED
 * ---------------------------------------------------------------------------
 * Which statuses matter is not this plugin's opinion. It is a fact of the
 * receiver, and it was read there rather than guessed, on 2026-09-02, in the
 * monorepo files that decide it - which this package only ever READS:
 *
 *   backend/app/Integration/Domain/ValueObject/WooCommerceOrderStatus.php
 *     `isPaid()` is true for `processing` and `completed`, AND FOR NOTHING ELSE.
 *     `on-hold` is deliberately outside it: it is the status of a bank transfer
 *     or a cash-on-delivery order still waiting to be confirmed, which is
 *     precisely the case where the money has not arrived.
 *     `isRefunded()` is true only for `refunded`.
 *
 *   backend/app/Integration/Infrastructure/Provider/Adapters/WooCommerce/WooCommerceOrderNormalizer.php
 *     `classify()` sends `processing` and `completed` to "order paid",
 *     `refunded` to "order refunded", `cancelled` and `trash` to "order
 *     cancelled", and `pending`, `on-hold` and `failed` to "order updated". The
 *     last two groups produce NO document.
 *
 * So the two lists below are ENUMERATED, one status at a time, and are never
 * derived from a pattern, a prefix or a "not one of the bad ones" test. The
 * reason is the same one this repository writes everywhere it enumerates: a
 * pattern silently adopts whatever appears next. WooCommerce order statuses are
 * extensible - any plugin registers its own - and a rule shaped like "anything
 * that is not pending or failed is a sale" would deliver, the day a shop
 * installs a subscription or pre-order plugin, orders whose money has not
 * arrived. The receiver would not save us either: a status outside its closed
 * catalogue makes it THROW, so the delivery would land in the merchant's error
 * tray instead of doing nothing quietly.
 *
 * A status this plugin does not recognise is therefore not an error and not a
 * warning. It is a transition with no billing effect, and the right amount of
 * work to do about it is none.
 *
 * ---------------------------------------------------------------------------
 * A TRANSITION INTO `refunded` ENQUEUES ONE ENTRY PER REFUND
 * ---------------------------------------------------------------------------
 * The receiver does not bill a refund from the status. It bills it from the
 * refunds that travel INSIDE the order body (`refunds[]`), one corrective
 * document per refund, and it identifies each one by the REFUND
 * ({@see EventIdentity::for_refund()}). So this listener enqueues one entry per
 * refund of the order, all of them carrying the SAME body - the body of the
 * ORDER, which is what the receiver reads and what
 * {@see OrderPayloadBuilder::build()} produces. It never tries to build a body
 * for a `WC_Order_Refund`; that builder takes an order and would refuse.
 *
 * Those identities are the same ones the refund listener composes on
 * `woocommerce_order_refunded`, so the two converge into one entry per refund
 * exactly as the two paid routes converge into one entry per order.
 *
 * One kind of refund is left out of that count: the one this plugin created
 * itself out of an inbound notice. It carries a mark, both captors read it, and
 * neither sends it back. The reason the guard has to be in BOTH - and why the
 * zero-refund fallback below may not swallow the filtered case - is written at
 * {@see self::refund_identities()}, which is where the filtering happens.
 *
 * The zero-refund case is handled and it is not hypothetical: a merchant can
 * set an order to Refunded by hand without recording any money back. The
 * receiver has a measured fallback for a body whose `refunds[]` is empty - see
 * `WooCommerceRefundTriggerDetector::trimmedTotalRefund()`, which declares a
 * TOTAL refund identified by the ORDER identifier, because that is the only
 * identifier such a body carries. This listener composes that same identity,
 * `for_refund( $order_id )`, so the entry converges with what the receiver
 * derives instead of being a second, unrelated fact. Sending nothing there
 * would leave a fully refunded order without its corrective document, which is
 * the silence this package exists to remove.
 *
 * ---------------------------------------------------------------------------
 * IN PROCESS, SYNCHRONOUS, AND WITHOUT A SINGLE NETWORK CALL
 * ---------------------------------------------------------------------------
 * Everything below happens inside the request that changed the status. The
 * event is RECORDED there, which is what makes it independent of whether the
 * site ever runs a scheduled task again; DELIVERING it is the dispatcher's job
 * and happens later. No HTTP call is made from here, so a slow or unreachable
 * receiver can never slow down a merchant saving an order.
 *
 * For the same reason the delivery switch is NOT consulted here. Recording is
 * unconditional; the dispatcher is the one that declines to send while delivery
 * is off, and it leaves the entries pending so nothing is lost while a merchant
 * finishes filling in the settings screen.
 */
final class OrderStatusChangedListener {

	/**
	 * The WooCommerce hook that reports a status transition.
	 *
	 * It hands over four arguments: the order identifier, the status it came
	 * from, the status it went to and the order object.
	 */
	public const HOOK = 'woocommerce_order_status_changed';

	/**
	 * The statuses the receiver reads as "this order has been paid for".
	 *
	 * Enumerated from `WooCommerceOrderStatus::isPaid()`. See the class
	 * docblock for why this is a list and never a pattern.
	 *
	 * @var array<int, string>
	 */
	public const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * The status the receiver reads as "this order has been refunded".
	 *
	 * Enumerated from `WooCommerceOrderStatus::isRefunded()`, which is true for
	 * this one status and no other. Partial refunds never reach it, and that is
	 * why the refund listener exists alongside this one.
	 */
	public const REFUNDED_STATUS = 'refunded';

	/**
	 * Value written to the `event_type` column of the outbox for a paid order.
	 *
	 * That column is for whoever debugs the outbox. It is not what the receiver
	 * reads - the receiver decides from the `status` inside the body - and it
	 * takes no part in the identity.
	 */
	private const EVENT_TYPE_ORDER_PAID = 'order.paid';

	/**
	 * Value written to the `event_type` column of the outbox for a refund.
	 */
	private const EVENT_TYPE_ORDER_REFUNDED = 'order.refunded';

	/**
	 * Builder of the delivery body, or null until it is first needed.
	 *
	 * @var OrderPayloadBuilder|null
	 */
	private ?OrderPayloadBuilder $payloads;

	/**
	 * The outbox, or null until it is first needed.
	 *
	 * It is resolved lazily because building it takes the WordPress database
	 * handle, and this listener is constructed while plugins load - before any
	 * transition has happened and possibly before anything needs a database at
	 * all.
	 *
	 * @var OutboxRepository|null
	 */
	private ?OutboxRepository $outbox;

	/**
	 * Builds the listener on its two collaborators.
	 *
	 * Both arguments exist so that a test can hand in doubles; production
	 * passes nothing and gets the real ones on first use.
	 *
	 * @param OrderPayloadBuilder|null $payloads Builder of the delivery body.
	 * @param OutboxRepository|null    $outbox   Outbox the events are recorded in.
	 */
	public function __construct( ?OrderPayloadBuilder $payloads = null, ?OutboxRepository $outbox = null ) {
		$this->payloads = $payloads;
		$this->outbox   = $outbox;
	}

	/**
	 * Subscribes to the status transition hook.
	 *
	 * Four accepted arguments, because the fourth - the order object - is what
	 * lets a refunded transition enumerate the refunds without loading the
	 * order again.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle' ), 10, 4 );
	}

	/**
	 * Records the transition when it has a billing effect, and does nothing
	 * when it does not.
	 *
	 * The order argument is untyped and optional on purpose. WooCommerce always
	 * passes it, but this action is public and third-party code fires it with
	 * three arguments; a required typed parameter would turn that into a fatal
	 * error in the merchant's shop, on a hook whose whole job is to be
	 * harmless when it has nothing to do.
	 *
	 * @param int    $order_id    Identifier of the order that changed status.
	 * @param string $from_status Status it came from. Unused: what the receiver bills is where it ARRIVED.
	 * @param string $to_status   Status it went to.
	 * @param mixed  $order       The order object, when the caller passed one.
	 * @return void
	 */
	public function handle( int $order_id, string $from_status, string $to_status, $order = null ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		$to = self::normalised_status( $to_status );

		if ( in_array( $to, self::PAID_STATUSES, true ) ) {
			$this->record( $order_id, self::EVENT_TYPE_ORDER_PAID, array( EventIdentity::for_paid_order( $order_id ) ) );

			return;
		}

		if ( self::REFUNDED_STATUS === $to ) {
			$this->record( $order_id, self::EVENT_TYPE_ORDER_REFUNDED, self::refund_identities( $order_id, $order ) );
		}
	}

	/**
	 * Builds the body once and records one outbox entry per identity.
	 *
	 * The body is built ONCE for the whole transition and shared by every
	 * entry, which is both correct and the only affordable option: building it
	 * dispatches an internal REST request, and an order with four refunds would
	 * otherwise dispatch four identical ones inside the request that is saving
	 * the order.
	 *
	 * @param int                $order_id    Order the entries belong to.
	 * @param string             $event_type  Debugging label for the outbox column.
	 * @param array<int, string> $event_ids   Identities to record, composed by `EventIdentity`.
	 * @return void
	 */
	private function record( int $order_id, string $event_type, array $event_ids ): void {
		if ( array() === $event_ids ) {
			return;
		}

		try {
			$raw_body = $this->payloads()->build( $order_id );
		} catch ( OrderPayloadNotBuildableException $exception ) {
			self::report_unbuildable_body( $order_id, $exception );

			return;
		}

		$source = (string) home_url();

		foreach ( $event_ids as $event_id ) {
			$this->outbox()->enqueue( $event_id, $order_id, $event_type, $raw_body, $source );
		}
	}

	/**
	 * The identities of the refunds this order carries, EXCEPT the ones this
	 * plugin created from an inbound notice.
	 *
	 * ---------------------------------------------------------------------
	 * THE ANTI-LOOP GUARD LIVES IN BOTH CAPTORS, AND HERE IS WHY IN THIS ONE
	 * ---------------------------------------------------------------------
	 * A refund the platform publishes reaches this shop as an inbound notice,
	 * and {@see \Factuarea\WooCommerce\Inbound\RefundApplier} records it as a
	 * REAL WooCommerce refund - which is the point: the merchant has to see the
	 * money given back in their own order. Creating it fires
	 * `woocommerce_order_refunded`, and the refund captor guards against that.
	 *
	 * That guard alone is not enough, and the gap is this method. A refund for
	 * the whole amount ALSO moves the order into `refunded`, which is the hook
	 * of THIS listener, and this method composes one identity per refund of the
	 * order - the inbound one included. So a guard placed only in the refund
	 * captor would still send the refund back through the status door.
	 *
	 * What comes back is not a duplicate notification. Factuarea would read a
	 * return of the shop for money it had already returned, and issue a SECOND
	 * corrective document over the same sale, in a consecutive series filed with
	 * the tax authority - where a document is never deleted, only corrected with
	 * a third one. Nothing fails while it happens: the symptom is a corrective
	 * invoice nobody ordered, found weeks later while squaring a quarter.
	 *
	 * The mark is {@see Constants::INBOUND_REFUND_META_KEY}, written on the
	 * refund object itself before it is saved. Its PRESENCE is the whole test -
	 * its value is the idempotency key of the notice, and this listener is in no
	 * position to judge whether a key is well formed.
	 *
	 * ---------------------------------------------------------------------
	 * WHY THE TOTAL-REFUND FALLBACK IS NOT ALLOWED TO CATCH THE FILTERED CASE
	 * ---------------------------------------------------------------------
	 * The fallback below answers ONE situation: the order records no refund at
	 * all, so the body reaches the receiver with an empty `refunds[]` and the
	 * receiver derives a TOTAL refund identified by the ORDER. Composing the
	 * same identity here is what makes both sides name the same fact.
	 *
	 * When the list is empty because every refund carried the mark, that
	 * situation does not hold: the order DOES record refunds and the body
	 * carries them, so the receiver derives nothing. Firing the fallback there
	 * would send the inbound refund back under a different identity - the loop
	 * this method just closed, reopened and disguised. Hence the flag: an empty
	 * list means "nothing to say" when something was filtered out, and "the
	 * receiver will infer a total refund" only when nothing was.
	 *
	 * @param int   $order_id Order that reached the refunded status.
	 * @param mixed $order    The order object, when the caller passed one.
	 * @return array<int, string> One identity per refund of the merchant's own making, empty when every refund of the order came from an inbound notice.
	 */
	private static function refund_identities( int $order_id, $order ): array {
		$identities   = array();
		$inbound_seen = false;

		foreach ( self::refunds_of( $order_id, $order ) as $refund ) {
			if ( self::came_from_an_inbound_notice( $refund ) ) {
				$inbound_seen = true;

				continue;
			}

			$refund_id = (int) $refund->get_id();

			if ( $refund_id > 0 ) {
				$identities[] = EventIdentity::for_refund( $refund_id );
			}
		}

		if ( array() === $identities && ! $inbound_seen ) {
			// No refund was recorded against the order, so the body will carry
			// an empty `refunds[]` and the receiver will fall back to declaring
			// a TOTAL refund identified by the order. Composing that same
			// identity here is what makes the two sides agree; see the class
			// docblock.
			$identities[] = EventIdentity::for_refund( $order_id );
		}

		return $identities;
	}

	/**
	 * Whether this plugin created the refund out of an inbound notice.
	 *
	 * Read by presence and not by truth: the mark holds the idempotency key of
	 * the notice, and a key of `0` is falsy in PHP. A guard written with
	 * `empty()` would call such a refund the merchant's and send it back.
	 *
	 * The read is deliberately done on the refund object the order handed over,
	 * without reloading it: the mark is written before the refund is saved, so
	 * whatever object reaches here already carries it, and a reload inside a
	 * loop would read the database once per refund of the order.
	 *
	 * A value that is not a scalar counts as a mark, and casting it is avoided
	 * rather than risked. WooCommerce answers `''` for a key it never wrote, and
	 * a refund the merchant made carries nothing under THIS key, so anything
	 * else found there came from the inbound half or from something writing in
	 * its place. Of the two ways of being wrong about it, the one taken is the
	 * one the merchant can see: a corrective document that has to be reissued,
	 * instead of a second one filed over a sale that was already corrected.
	 *
	 * @param object $refund Refund object of the order.
	 * @return bool True when the refund carries the anti-loop mark.
	 */
	private static function came_from_an_inbound_notice( $refund ): bool {
		$mark = $refund->get_meta( Constants::INBOUND_REFUND_META_KEY );

		if ( ! is_scalar( $mark ) ) {
			return array() !== $mark && null !== $mark;
		}

		return '' !== trim( (string) $mark );
	}

	/**
	 * The refund objects of an order, from the object the hook handed over or,
	 * failing that, from a fresh read.
	 *
	 * The instance check is deliberate and `method_exists()` is deliberately
	 * not used: on a test double of a class that does not otherwise exist it
	 * answers false, which would send every unit run down the reload path and
	 * hide whichever of the two branches the test meant to exercise.
	 *
	 * @param int   $order_id Order to read when the hook handed over no object.
	 * @param mixed $order    The order object, when the caller passed one.
	 * @return array<int, object> Refund objects, possibly empty.
	 */
	private static function refunds_of( int $order_id, $order ): array {
		if ( ! $order instanceof \WC_Order ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		}

		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$refunds = $order->get_refunds();

		return is_array( $refunds ) ? $refunds : array();
	}

	/**
	 * The status without the taxonomy prefix WordPress stores it under.
	 *
	 * WooCommerce strips `wc-` before it fires the transition, so the prefix
	 * never arrives from core. It is stripped again here because the action is
	 * public: code that fires it by hand with the stored form would otherwise
	 * hand over `wc-completed`, match nothing, and lose the sale silently -
	 * which is the one failure mode this listener may not have.
	 *
	 * @param string $status Status as the hook reported it.
	 * @return string Status as the receiver publishes it.
	 */
	private static function normalised_status( string $status ): string {
		$status = strtolower( trim( $status ) );

		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Writes the reason an order body could not be built where the merchant can
	 * find it, and lets the status transition finish.
	 *
	 * A transition that ends in a fatal error leaves the merchant unable to
	 * complete an order, which is far worse than a sale that has to be resent.
	 * The exception message is written for the merchant and carries no
	 * credential - the exception catalogue of this plugin forbids putting the
	 * signing secret or the API key into any message, notice or log - so it is
	 * recorded as it is.
	 *
	 * @param int                               $order_id  Order whose body failed.
	 * @param OrderPayloadNotBuildableException $exception What went wrong.
	 * @return void
	 */
	private static function report_unbuildable_body( int $order_id, OrderPayloadNotBuildableException $exception ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			sprintf( 'Order %1$d was not recorded for delivery: %2$s', $order_id, $exception->getMessage() ),
			array( 'source' => Constants::PLUGIN_SLUG )
		);
	}

	/**
	 * The body builder, built on first use.
	 *
	 * @return OrderPayloadBuilder Builder of the delivery body.
	 */
	private function payloads(): OrderPayloadBuilder {
		if ( null === $this->payloads ) {
			$this->payloads = new OrderPayloadBuilder();
		}

		return $this->payloads;
	}

	/**
	 * The outbox, built on first use.
	 *
	 * @return OutboxRepository Outbox the events are recorded in.
	 */
	private function outbox(): OutboxRepository {
		if ( null === $this->outbox ) {
			$this->outbox = new OutboxRepository();
		}

		return $this->outbox;
	}
}
