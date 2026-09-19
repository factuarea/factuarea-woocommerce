<?php
/**
 * The frozen table of statuses the inbound receivers answer with.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

use Factuarea\WooCommerce\MyAccount\InvoiceCorrelationEndpoint;

/**
 * Every answer an inbound delivery can get, and what the sender makes of it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE TABLE LIVES HERE AND NOT IN EACH RECEIVER
 * ---------------------------------------------------------------------------
 * Two receivers answer on two routes, and both are read by the SAME sender,
 * whose classification decides between ending a queue row and retrying it. If
 * each receiver wrote its own numbers, the day one of them answered 422 where
 * the other answered 409 the sender would treat an applied notice as a lost one
 * on one route and a lost one as applied on the other. One table, read by both.
 *
 * ---------------------------------------------------------------------------
 * FOUR OF THESE ARE NOT NEW, AND ARE NOT RE-TYPED
 * ---------------------------------------------------------------------------
 * `ACCEPTED`, `UNAUTHORISED`, `UNKNOWN` and `MALFORMED` are the four the plugin
 * already froze in `MyAccount\InvoiceCorrelationEndpoint` (`STATUS_ASSOCIATED`,
 * `STATUS_UNAUTHORISED`, `STATUS_UNKNOWN_KEY`, `STATUS_MALFORMED`). They are
 * REFERENCED rather than copied, for the same reason the signature header is:
 * two equal literals written in two places diverge, and the divergence here
 * would be silent - the sender would keep classifying, just wrongly.
 *
 * That endpoint is not touched by this change. Reading a constant off it is not
 * touching it; it is the opposite, it is refusing to write a second copy of a
 * value it already publishes.
 *
 * ---------------------------------------------------------------------------
 * THE THREE THAT ARE NEW, AND WHY EACH NUMBER
 * ---------------------------------------------------------------------------
 *  - `DUPLICATE_DISCARDED` (409). A redelivery whose idempotency key is already
 *    claimed. It classifies as PUBLISHED, not as a failure: the effect IS in
 *    the shop, it just got there on the first delivery. Answering anything the
 *    sender reads as a failure would have it retry an applied refund.
 *  - `RATE_LIMITED` (429). The only RETRYABLE answer this side produces. The
 *    sender comes back with the shared retry schedule.
 *  - `RECEIVER_DISABLED` (403). The receiver is installed but switched off.
 *
 * WHY 403 FOR A SWITCHED-OFF RECEIVER, measured rather than guessed. The
 * sender's translator was read on 2026-09-18
 * (`backend/app/Integration/Infrastructure/Provider/Adapters/WooCommerce/WooCommerceFeedbackResponseTranslator.php:147-151`):
 * 401 and 403 share one branch whose merchant-facing reason ends by telling
 * them to check the signing secret of this shop AND THAT THE FACTUAREA PLUGIN
 * IS STILL INSTALLED AND ACTIVE, which is exactly the sentence that sends a
 * merchant to the switch they have to flip. Every other 4xx falls into the last
 * branch and is reported as the shop having rejected the CONTENT of the notice,
 * which sends them to inspect a notice that is perfectly fine. Both classify as
 * PERMANENT, so the difference is not what the queue does - it is what the
 * merchant reads.
 *
 * The answer to a disabled receiver DECLARES itself in its body, unlike the
 * authentication refusals, which carry none: refusing to say which of the three
 * ways of failing authentication happened denies a prober information, while
 * refusing to say «this receiver is off» denies it to the one person who can
 * turn it on.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS TABLE IS NOT
 * ---------------------------------------------------------------------------
 * It is not the rejection catalogue of the OUTGOING lane. That one lives in the
 * platform, is append-only because installations of third parties classify by
 * it, and describes how OUR ingestion route rejects a shop's webhook. Nothing
 * here is added to it: the two tables describe opposite directions, and mixing
 * them would publish, to people already programming against that catalogue,
 * codes their direction never produces.
 */
final class InboundResponseStatus {

	/**
	 * The delivery was accepted and its effect applied.
	 *
	 * No body: there is nothing to say that the status does not already say, and
	 * in particular no remote reference is returned - the idempotency key is the
	 * only thing that ties the two sides together.
	 */
	public const ACCEPTED = InvoiceCorrelationEndpoint::STATUS_ASSOCIATED;

	/**
	 * The delivery is not authenticated: no signature header, a signature that
	 * does not correspond, or a shop with no signing secret configured.
	 *
	 * ONE status for the three, WITHOUT a body. Telling them apart is free
	 * information for whoever is probing and useless to a legitimate sender,
	 * which either holds the secret or does not.
	 */
	public const UNAUTHORISED = InvoiceCorrelationEndpoint::STATUS_UNAUTHORISED;

	/**
	 * The delivery is authenticated and names something this shop does not have:
	 * an order identifier that resolves to no order here.
	 *
	 * Nothing similar is looked for. There is no search by amount, by date or by
	 * proximity: a near miss would hand one buyer another buyer's order.
	 */
	public const UNKNOWN = InvoiceCorrelationEndpoint::STATUS_UNKNOWN_KEY;

	/**
	 * The delivery is authenticated and its body is not one this contract can
	 * read: a shape that is neither notice, a status outside the four, a missing
	 * idempotency key, an amount that is not a decimal string, a catalogue batch
	 * declaring a key the contract does not enumerate or an internal datum that
	 * should never have left the platform.
	 */
	public const MALFORMED = InvoiceCorrelationEndpoint::STATUS_MALFORMED;

	/**
	 * The delivery repeats one whose idempotency key is already claimed.
	 *
	 * The sender reads this as PUBLISHED. Nothing is written a second time.
	 */
	public const DUPLICATE_DISCARDED = 409;

	/**
	 * Too many requests. The only answer of this side the sender retries.
	 */
	public const RATE_LIMITED = 429;

	/**
	 * The receiver exists and is switched off in this shop's settings.
	 *
	 * Its idempotency key is deliberately NOT claimed when answering this; see
	 * the class block and the receiving endpoints.
	 */
	public const RECEIVER_DISABLED = 403;

	/**
	 * The sender ends the queue row: the effect is in the shop.
	 */
	public const CLASSIFICATION_PUBLISHED = 'published';

	/**
	 * The sender ends the queue row without applying anything: repeating the
	 * delivery would produce the same answer.
	 */
	public const CLASSIFICATION_PERMANENT = 'permanent';

	/**
	 * The sender comes back with the shared retry schedule.
	 */
	public const CLASSIFICATION_RETRYABLE = 'retryable';

	/**
	 * Status to the classification the sender makes of it.
	 *
	 * The map is CLOSED. A receiver that answered a status outside it would be
	 * classified by the sender's fallback - «any other 4xx is a malformed
	 * notice» - which is a correct default for an unknown shop and a wrong
	 * answer for ours, so this side never relies on it.
	 *
	 * @var array<int, string>
	 */
	public const CLASSIFICATIONS = array(
		self::ACCEPTED            => self::CLASSIFICATION_PUBLISHED,
		self::DUPLICATE_DISCARDED => self::CLASSIFICATION_PUBLISHED,
		self::UNAUTHORISED        => self::CLASSIFICATION_PERMANENT,
		self::RECEIVER_DISABLED   => self::CLASSIFICATION_PERMANENT,
		self::UNKNOWN             => self::CLASSIFICATION_PERMANENT,
		self::MALFORMED           => self::CLASSIFICATION_PERMANENT,
		self::RATE_LIMITED        => self::CLASSIFICATION_RETRYABLE,
	);

	/**
	 * The statuses the receivers answer with, in the order of the table above.
	 *
	 * @return list<int>
	 */
	public static function all(): array {
		return array_keys( self::CLASSIFICATIONS );
	}

	/**
	 * What the sender makes of a status, or null when the status is not one this
	 * side answers with.
	 *
	 * Null is a real answer and not an oversight: it is what a test asserts on
	 * to prove that no receiver invented a status outside the frozen table.
	 *
	 * @param int $status Status code answered by a receiver.
	 * @return string|null One of the three classifications, or null.
	 */
	public static function classification_of( int $status ): ?string {
		return self::CLASSIFICATIONS[ $status ] ?? null;
	}

	/**
	 * Does this status tell the sender the effect is in the shop?
	 *
	 * @param int $status Status code answered by a receiver.
	 * @return bool
	 */
	public static function is_published( int $status ): bool {
		return self::CLASSIFICATION_PUBLISHED === self::classification_of( $status );
	}

	/**
	 * Does the answer carry a body?
	 *
	 * Only the authentication refusal is bodiless, and it is bodiless on
	 * purpose. Everything else may explain itself.
	 *
	 * @param int $status Status code answered by a receiver.
	 * @return bool
	 */
	public static function carries_body( int $status ): bool {
		return self::UNAUTHORISED !== $status && self::ACCEPTED !== $status;
	}

	/**
	 * Not instantiable: this is a frozen table of statuses, not an object.
	 */
	private function __construct() {
	}
}
