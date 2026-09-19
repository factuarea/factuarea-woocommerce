<?php
/**
 * The four fulfilment states the platform publishes into this shop.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * The closed set of fulfilment states an inbound notice may declare, and there
 * is no fifth.
 *
 * ---------------------------------------------------------------------------
 * MEASURED, NOT CHOSEN
 * ---------------------------------------------------------------------------
 * The four literals below were read on 2026-09-18 off the platform's own value
 * object, `App\Integration\Domain\ValueObject\StoreOrderFeedbackStage`
 * (`PREPARED`, `SHIPPED`, `DELIVERED`, `RETURNED`, and its `VALID_STAGES` list),
 * and off the frozen receiver contract that publishes them
 * (`backend/docs/architecture/store-order-status-receiver-contract.md` §4.1).
 * They are the wire values, not labels: a state this plugin spelled differently
 * would be rejected as malformed on every single notice.
 *
 * A DELIVERY FAILURE is deliberately not a fifth value, and the reason is
 * written down in that value object: neither commerce platform models the fact,
 * and translating it to the nearest state would tell the buyer something false
 * - `shipped` says the parcel is still on its way when the carrier already
 * brought it back, `returned` says their money is moving when it is not.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO TRANSLATION TO A NATIVE WOOCOMMERCE ORDER STATUS
 * ---------------------------------------------------------------------------
 * This is the decision this class exists to hold, because it is the one a
 * reader will want to undo.
 *
 * 1. THERE IS NO HONEST TRANSLATION. WooCommerce ships `pending`, `processing`,
 *    `on-hold`, `completed`, `cancelled`, `refunded` and `failed`. It has no
 *    «prepared» and no «delivered». Forcing `prepared` into `processing` and
 *    `delivered` into `completed` does not preserve the datum: `processing`
 *    already means «paid, being dealt with» for every order in the shop, so
 *    after the translation nothing distinguishes an order sitting in a queue
 *    from one packed and waiting for the courier, and `completed` in WooCommerce
 *    means «the shop is done with it», which it already was when it shipped.
 *    The state the notice carried would be unrecoverable from the order.
 * 2. WRITING THE NATIVE STATUS FIRES A CHAIN OF EFFECTS. `WC_Order::set_status()`
 *    fires `woocommerce_order_status_changed`, which is the hook this very
 *    plugin listens on (`Events\OrderStatusChangedListener`) to ENQUEUE A
 *    DELIVERY BACK to Factuarea. An informational notice would become a write
 *    that travels back out. It also fires every third-party automation the
 *    merchant installed, which would send e-mails nobody asked for, to buyers,
 *    about a state change that did not happen in the shop.
 * 3. THE NATIVE STATUS BELONGS TO THE MERCHANT. It drives their own workflow,
 *    their reports and their integrations. A receiver that moves it is a
 *    receiver that runs the merchant's shop for them.
 *
 * So the state is stored as the plugin's OWN fulfilment status, in order meta
 * (`Constants::FULFILMENT_STATUS_META_KEY`), shown next to the native status
 * rather than instead of it, and accompanied by an order note - the record the
 * merchant actually reads.
 */
final class FulfilmentState {

	/**
	 * Packed in the warehouse and not yet gone.
	 */
	public const PREPARED = 'prepared';

	/**
	 * The goods left, with their carrier and tracking number when the shipment
	 * has them. A shipment WITHOUT tracking is still a shipment and is published
	 * just the same.
	 */
	public const SHIPPED = 'shipped';

	/**
	 * The goods reached the recipient.
	 */
	public const DELIVERED = 'delivered';

	/**
	 * The sale came back. It travels with the refund notice when the return is
	 * refunded, as a SEPARATE delivery: one notice per fact.
	 */
	public const RETURNED = 'returned';

	/**
	 * The four publishable states, in the order of an order's life.
	 *
	 * Enumerated one by one rather than derived from anything: this list is what
	 * a notice is validated against, and a list that derived itself from the
	 * class would accept whatever constant somebody added next.
	 *
	 * @var list<string>
	 */
	public const ALL = array(
		self::PREPARED,
		self::SHIPPED,
		self::DELIVERED,
		self::RETURNED,
	);

	/**
	 * Is this one of the four?
	 *
	 * A value outside them is MALFORMED and is never approximated to the nearest
	 * one: approximating is how a buyer gets told their parcel arrived because
	 * the sender used a word this version had not heard of.
	 *
	 * @param string $state Candidate state, as it arrived on the wire.
	 * @return bool
	 */
	public static function is_valid( string $state ): bool {
		return in_array( $state, self::ALL, true );
	}

	/**
	 * Does a notice of this state carry tracking data when the shipment has it?
	 *
	 * Only the shipped one. Publishing a tracking number next to «prepared»
	 * would announce a shipment that has not left.
	 *
	 * @param string $state One of the four.
	 * @return bool
	 */
	public static function carries_tracking( string $state ): bool {
		return self::SHIPPED === $state;
	}

	/**
	 * Not instantiable: this is a closed set of frozen values, not an object.
	 */
	private function __construct() {
	}
}
