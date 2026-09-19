<?php
/**
 * The signed receiver of a refund the platform already issued.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The door a refund the platform already issued is published through, and the
 * verification that guards it.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE SECOND ADDRESS OF THE SAME LANE, AND IT IS MEASURED
 * ---------------------------------------------------------------------------
 * The frozen contract of the platform,
 * `backend/docs/architecture/store-order-status-receiver-contract.md` §2,
 * publishes TWO addresses for this lane -
 * `WooCommerceEndpoints::orderStatus()` and `::orderRefund()` - and the sender
 * is already deployed against both. A plugin that published one route and told
 * the two notices apart by their shape would answer 404 to every refund the
 * platform publishes.
 *
 * The contract writes down why the platform split them, and the reason holds on
 * this side too: the two notices have different bodies, different risks and
 * different permissions, so one door would force this plugin to branch on the
 * CONTENT before it knows what it is validating, and the day the status notice
 * changed shape it would drag the one about money with it.
 *
 * What the two receivers DO share is everything that must not diverge: the
 * verifier ({@see InboundSignatureVerifier}), the record of claimed deliveries
 * ({@see InboundDeliveryLedger}), the switch of the lane
 * ({@see SettingsPage::receiver_enabled()}), the answer table
 * ({@see InboundResponseStatus}) and the order of the checks. None of those is
 * re-implemented here; what this class holds that its sibling does not is the
 * SHAPE it accepts and the effect it applies.
 *
 * ---------------------------------------------------------------------------
 * ONE SWITCH FOR THE TWO ADDRESSES
 * ---------------------------------------------------------------------------
 * `Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED` governs both, and the
 * settings screen says so in its own words: it is the switch of «the order
 * status and refund receiver», and its help text names the refund explicitly.
 * There is no second option, and inventing one here would leave a shop that
 * switched the lane on still silently discarding half of it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PERMISSION CALLBACK LETS EVERYONE IN, AND WHY THERE IS NO SCHEMA
 * ---------------------------------------------------------------------------
 * Same two reasons as the sibling receiver and as the precedent
 * `MyAccount\InvoiceCorrelationEndpoint`. The caller is a SERVER, not a
 * logged-in user, so there is no cookie, no nonce and no capability to check;
 * what authenticates it is the signature, checked INSIDE the handler before
 * anything is read out of the body. A permission callback that returned false
 * would make WordPress answer a 401 with a body naming the route and the
 * reason, which is more than this route is willing to say. And a declared
 * argument schema would have WordPress refuse a body with its own 400, which
 * the sender reports to the merchant as «the shop rejected the content of the
 * notice» - sending them to inspect a notice that is perfectly fine.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER OF THE CHECKS IS THE CONTRACT, AND IT IS THE SIBLING'S ORDER
 * ---------------------------------------------------------------------------
 *   1. Verify the signature over the RAW body. Nothing is decoded before this.
 *   2. Read the switch of the lane. Off answers 403 and claims NOTHING.
 *   3. Read the body into a refund notice, and check the length of its
 *      idempotency key against the column that records it. Either failure
 *      answers 422.
 *   4. Resolve the order this shop has under the identifier the notice carries.
 *      None answers 404.
 *   5. Claim the idempotency key. Already claimed answers 409, and creates no
 *      second refund: a refund applied twice is money reflected twice.
 *   6. Apply, and record the key as applied.
 *
 * Steps 3 and 4 sit before the claim for the reason written in full in
 * {@see OrderStatusEndpoint}: a key claimed for an effect that never landed
 * answers the sender's next delivery of that same fact with a 409, which it
 * classifies as PUBLISHED, and the notice is lost with both sides believing it
 * arrived.
 *
 * ---------------------------------------------------------------------------
 * HOW A FAILURE OF THE EFFECT IS ANSWERED: BY THE TYPE IT ARRIVES AS
 * ---------------------------------------------------------------------------
 * {@see RefundApplier::apply()} answers nothing and reports by exception, and
 * the TYPE is the whole message:
 *
 *  - `InboundPayloadNotReadableException` - the notice cannot be read. It is
 *    PERMANENT: the same body would fail identically, so the claim is released
 *    and the answer is 422. Answering a 5xx would have the sender burn its
 *    whole retry budget on a body that will never become readable.
 *  - anything else - the shop refused to record the refund. That is not an
 *    answer about the notice, so the claim is released and the failure is
 *    re-thrown: WordPress renders it as a 500 and the sender comes back with
 *    the shared retry schedule.
 *
 * The two arms are ordered narrowest first, and that order is load-bearing:
 * `InboundPayloadNotReadableException` extends `RuntimeException`, so a
 * `RuntimeException` arm written above it would swallow the permanent case and
 * turn every unreadable body into a delivery the sender retries eight times.
 *
 * BOTH arms release the claim, and nothing else in this class does. A delivery
 * refused for its signature, for a switched-off lane, for a malformed body or
 * for an unknown order never reached the claim; a delivery that was applied
 * must KEEP its row, because that row is what turns the redelivery into a 409
 * instead of a second refund; and a redelivery that was discarded claimed
 * nothing either.
 *
 * ---------------------------------------------------------------------------
 * WHAT COMES BACK, AND HOW THE SENDER READS IT
 * ---------------------------------------------------------------------------
 *   204  accepted          -> PUBLISHED.  The refund is recorded. NO BODY.
 *   401  unauthenticated   -> PERMANENT.  NO BODY, and no hint of which of the
 *                                         three ways of failing it was.
 *   403  receiver disabled -> PERMANENT.  Body.
 *   404  unknown order     -> PERMANENT.  Body.
 *   422  malformed         -> PERMANENT.  Body.
 *   409  redelivery discarded -> PUBLISHED. Body. No second refund was created.
 *   5xx  an exception escaped -> RETRYABLE. WordPress renders it.
 *
 * The numbers are read from {@see InboundResponseStatus} and not typed here, so
 * that the two receivers of this lane cannot tell the same sender two different
 * things about the same kind of refusal.
 *
 * `InboundResponseStatus::RATE_LIMITED` (429) is in that table and this receiver
 * never emits it: nothing here limits a rate. Said out loud so that a reader
 * does not go looking for a limiter that does not exist.
 *
 * NOTHING IS ADDED TO THE REJECTION CATALOGUE OF THE PLATFORM: it describes how
 * OUR ingestion route rejects a shop's webhook, the OPPOSITE direction, and it
 * is append-only because installations of third parties classify by it.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT, AND NO CREDENTIAL COMES BACK
 * ---------------------------------------------------------------------------
 * Applying a refund makes no HTTP request and enqueues no delivery. The refund
 * created carries the anti-loop mark that both of this plugin's refund captors
 * read, so the money that came back does not travel out again and produce a
 * SECOND corrective invoice over the same sale, in a series filed with the tax
 * authority where a document is not deleted but corrected with another one.
 *
 * No answer, notice or log line of this class carries the signing secret, the
 * expected signature or the presented one. This class logs nothing at all.
 */
final class OrderRefundEndpoint {

	/**
	 * Decides whether an inbound delivery is authenticated.
	 *
	 * @var InboundSignatureVerifier|null
	 */
	private ?InboundSignatureVerifier $verifier;

	/**
	 * Durable record of the deliveries already claimed.
	 *
	 * @var InboundDeliveryLedger|null
	 */
	private ?InboundDeliveryLedger $ledger;

	/**
	 * Records the refund against the order.
	 *
	 * @var RefundApplier|null
	 */
	private ?RefundApplier $applier;

	/**
	 * Constructor.
	 *
	 * Every collaborator is optional and built on first use, exactly as the
	 * sibling receiver and the precedent do: production wires this receiver with
	 * `new OrderRefundEndpoint()` and nothing else, while a test hands in a
	 * ledger double instead of reaching a database a bare PHP run does not have.
	 *
	 * @param InboundSignatureVerifier|null $verifier Verifier of the inbound signature.
	 * @param InboundDeliveryLedger|null    $ledger   Record of the deliveries already claimed.
	 * @param RefundApplier|null            $applier  Writer of the refund.
	 */
	public function __construct(
		?InboundSignatureVerifier $verifier = null,
		?InboundDeliveryLedger $ledger = null,
		?RefundApplier $applier = null
	) {
		$this->verifier = $verifier;
		$this->ledger   = $ledger;
		$this->applier  = $applier;
	}

	/**
	 * Hooks the route registration.
	 *
	 * `rest_api_init` and not an earlier hook: the REST server does not exist
	 * before it, and `register_rest_route()` called earlier is discarded with a
	 * notice nobody reads on a production site.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ), 10, 0 );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			Constants::INBOUND_REST_NAMESPACE,
			Constants::INBOUND_ORDER_REFUND_ROUTE,
			array(
				'methods'             => Constants::INBOUND_REST_METHOD,
				'callback'            => array( $this, 'handle' ),

				/*
				 * Always yes, and the signature check inside `handle()` is what
				 * actually guards the route; the reasoning is in the class
				 * block. What matters here is that the callback is PRESENT:
				 * WordPress serves a route registered without one, warning into
				 * a log the merchant never opens.
				 */
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies the delivery and, if it holds up, records the refund it declares
	 * against the order of this shop it names.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response One of the statuses of the frozen table.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = (string) $request->get_body();

		if ( ! $this->signature_holds( $raw_body, (string) $request->get_header( Constants::INBOUND_SIGNATURE_HEADER ) ) ) {
			return $this->bare( InboundResponseStatus::UNAUTHORISED );
		}

		if ( ! SettingsPage::receiver_enabled( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ) ) {
			return $this->explained(
				InboundResponseStatus::RECEIVER_DISABLED,
				'The order status and refund receiver of this shop is switched off. Nothing was applied and no delivery '
				. 'was recorded, so publishing this notice again once it is switched on will apply it.'
			);
		}

		$notice = $this->read( $raw_body );

		if ( null === $notice ) {
			return $this->explained(
				InboundResponseStatus::MALFORMED,
				'The body is not a refund notice this contract can read.'
			);
		}

		if ( strlen( $notice->idempotency_key ) > InboundDeliveryTable::KEY_MAX_LENGTH ) {
			return $this->explained(
				InboundResponseStatus::MALFORMED,
				'The idempotency key of the delivery is longer than the column that records it, so two different '
				. 'deliveries could not be told apart.'
			);
		}

		$order = $this->order_named_by( $notice->order_id );

		if ( null === $order ) {
			return $this->explained(
				InboundResponseStatus::UNKNOWN,
				'No order of this shop carries that identifier. Nothing similar was looked for.'
			);
		}

		if ( ! $this->ledger()->claim( $notice->idempotency_key, time() ) ) {
			return $this->explained(
				InboundResponseStatus::DUPLICATE_DISCARDED,
				'This refund had already been recorded. No second refund was created.'
			);
		}

		return $this->apply( $order, $notice );
	}

	/**
	 * Records the refund, releasing the claim when the effect fails.
	 *
	 * The class block holds the whole reasoning; what is mechanical here is the
	 * order of the two arms. `InboundPayloadNotReadableException` extends
	 * `RuntimeException`, so it has to be caught FIRST or the permanent case
	 * leaves as a retryable one.
	 *
	 * @param WC_Order            $order  Order of this shop, already resolved.
	 * @param InboundRefundNotice $notice Notice as it arrived, already read.
	 * @return WP_REST_Response Accepted when the refund landed, malformed when the failure is permanent.
	 *
	 * @throws \Throwable Whatever the applier raised for a failure that is not permanent, after the claim is released.
	 */
	private function apply( WC_Order $order, InboundRefundNotice $notice ): WP_REST_Response {
		try {
			$this->applier()->apply( $order, $notice, $notice->idempotency_key );
		} catch ( InboundPayloadNotReadableException $unreadable ) {
			unset( $unreadable );

			$this->ledger()->forget( $notice->idempotency_key );

			return $this->explained(
				InboundResponseStatus::MALFORMED,
				'The refund notice could not be read far enough to record it, and repeating the same body would fail '
				. 'in the same way. Nothing was recorded.'
			);
		} catch ( \Throwable $failure ) {
			$this->ledger()->forget( $notice->idempotency_key );

			throw $failure;
		}

		$this->ledger()->mark_applied( $notice->idempotency_key, time() );

		return $this->bare( InboundResponseStatus::ACCEPTED );
	}

	/**
	 * Whether the presented signature is the one this shop's secret produces
	 * over these exact bytes.
	 *
	 * The material is the body as it ARRIVED, never a re-encoding of the decoded
	 * document: `json_encode( json_decode( $body ) )` returns an equivalent
	 * document with different bytes, and verifying against it would reject every
	 * legitimate caller with an unexplained 401.
	 *
	 * @param string $raw_body  Bytes exactly as they arrived.
	 * @param string $presented Value of the signature header, empty when absent.
	 * @return bool True only when the signature corresponds.
	 */
	private function signature_holds( string $raw_body, string $presented ): bool {
		return $this->verifier()->verify( $raw_body, $presented, $this->signing_secret() );
	}

	/**
	 * The configured signing secret, or an empty string when there is none.
	 *
	 * ONE secret per shop and not one per direction: the same value that signs
	 * what this plugin sends verifies what it receives.
	 *
	 * @return string
	 */
	private function signing_secret(): string {
		$stored = get_option( Constants::OPTION_SIGNING_SECRET, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Reads a verified body into a refund notice, or answers null when it is not
	 * one.
	 *
	 * The factory is the contract's own, so the amount is validated as a decimal
	 * STRING and never cast: a single `(float)` on this path turns a refund of
	 * 12.34 into one of 12.339999999999999, and nothing downstream can tell
	 * which of the two the platform meant.
	 *
	 * @param string $raw_body Verified body.
	 * @return InboundRefundNotice|null
	 */
	private function read( string $raw_body ): ?InboundRefundNotice {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return InboundRefundNotice::from_array( $decoded );
	}

	/**
	 * The order of this shop the notice names, or null when there is none.
	 *
	 * NOTHING SIMILAR IS LOOKED FOR, and here the rule is at its most expensive
	 * to break: a refund applied to the order that merely resembles the one
	 * named takes money off the wrong sale. The identifier is accepted only when
	 * it is a plain positive integer, so that a value like
	 * `1042-from-another-shop` becomes the 404 it is instead of being cast down
	 * to a real order of somebody else's.
	 *
	 * The lookup goes through `wc_get_order()` and never through a post query:
	 * under the modern order storage an order is not a post. The result is
	 * checked with `instanceof`, because that function also answers refunds and
	 * other order types.
	 *
	 * @param string $remote_order_id Identifier of the order as the notice carries it.
	 * @return WC_Order|null
	 */
	private function order_named_by( string $remote_order_id ): ?WC_Order {
		if ( '' === $remote_order_id || 1 !== preg_match( '/^[1-9]\d*$/', $remote_order_id ) ) {
			return null;
		}

		$order = wc_get_order( (int) $remote_order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * A response carrying a status and nothing else.
	 *
	 * @param int $status One of the statuses of the frozen table.
	 * @return WP_REST_Response
	 */
	private function bare( int $status ): WP_REST_Response {
		return new WP_REST_Response( null, $status );
	}

	/**
	 * A response carrying a status and a merchant-facing sentence.
	 *
	 * The sentence never names the signing secret, the expected signature or the
	 * presented one, and the authentication refusal never gets one at all -
	 * {@see InboundResponseStatus::carries_body()} is the table that says which
	 * is which, and this method is only ever called with the statuses that do.
	 *
	 * @param int    $status  One of the statuses of the frozen table.
	 * @param string $message What happened, in English and with no credential.
	 * @return WP_REST_Response
	 */
	private function explained( int $status, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array( Constants::REJECTION_BODY_MESSAGE_FIELD => $message ),
			$status
		);
	}

	/**
	 * The signature verifier, built on first use.
	 *
	 * @return InboundSignatureVerifier
	 */
	private function verifier(): InboundSignatureVerifier {
		if ( null === $this->verifier ) {
			$this->verifier = new InboundSignatureVerifier();
		}

		return $this->verifier;
	}

	/**
	 * The delivery ledger, built on first use.
	 *
	 * @return InboundDeliveryLedger
	 */
	private function ledger(): InboundDeliveryLedger {
		if ( null === $this->ledger ) {
			$this->ledger = new InboundDeliveryLedger();
		}

		return $this->ledger;
	}

	/**
	 * The refund writer, built on first use.
	 *
	 * @return RefundApplier
	 */
	private function applier(): RefundApplier {
		if ( null === $this->applier ) {
			$this->applier = new RefundApplier();
		}

		return $this->applier;
	}
}
