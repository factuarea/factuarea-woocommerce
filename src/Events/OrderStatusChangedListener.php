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
	 * The identities of the refunds this order carries.
	 *
	 * @param int   $order_id Order that reached the refunded status.
	 * @param mixed $order    The order object, when the caller passed one.
	 * @return array<int, string> One identity per refund, never empty.
	 */
	private static function refund_identities( int $order_id, $order ): array {
		$identities = array();

		foreach ( self::refunds_of( $order_id, $order ) as $refund ) {
			$refund_id = (int) $refund->get_id();

			if ( $refund_id > 0 ) {
				$identities[] = EventIdentity::for_refund( $refund_id );
			}
		}

		if ( array() === $identities ) {
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
