<?php
/**
 * The signed receiver of the fulfilment status of an order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The door the platform publishes the fulfilment status of an order through,
 * and the verification that guards it.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE ARE TWO RECEIVERS AND NOT ONE WITH A DISCRIMINATOR
 * ---------------------------------------------------------------------------
 * This receiver accepts EXACTLY the status notice; its sibling
 * {@see OrderRefundEndpoint} accepts EXACTLY the refund one. That is measured
 * and not chosen: the frozen contract of the platform,
 * `backend/docs/architecture/store-order-status-receiver-contract.md` §2,
 * publishes TWO addresses - `WooCommerceEndpoints::orderStatus()` and
 * `::orderRefund()` - and the sender is already deployed against them, so a
 * single entry point here would be a 404 on half of every shop's notices.
 *
 * The contract also writes down WHY the platform split them, and the reason
 * holds on this side of the wire too: the two notices have different bodies,
 * different risks and different permissions, so one door would force this
 * plugin to branch on the CONTENT before it knows what it is validating, and
 * the day the status notice changes shape it would drag the one about money
 * with it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PERMISSION CALLBACK LETS EVERYONE IN
 * ---------------------------------------------------------------------------
 * The route is registered with a permission callback that always says yes, and
 * that is not a hole: the caller is a SERVER, not a logged-in user of this
 * WordPress, so there is no cookie, no nonce and no capability to check. What
 * authenticates it is the signature, and the signature is checked HERE, inside
 * the handler, before anything is read out of the body and long before anything
 * is written. It is the same pattern, and the same written reason, that
 * `MyAccount\InvoiceCorrelationEndpoint` already froze for this plugin.
 *
 * Putting the verification in the permission callback instead would look tidier
 * and would be wrong in one specific way: WordPress turns a permission callback
 * that returns false into a 401 with a body that names the route and the
 * reason, which is a more talkative answer than this route is willing to give.
 *
 * NO ARGUMENT SCHEMA IS DECLARED either, and that is the same decision seen
 * from the other end. A route with `args` makes WordPress validate the body
 * first and refuse it with ITS OWN 400 and its own message - and the sender
 * classifies any other 4xx as «the shop rejected the content of the notice»,
 * which sends the merchant to inspect a notice that is perfectly fine. Every
 * refusal of this lane has to be one of the statuses below, decided here.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER OF THE CHECKS IS THE CONTRACT
 * ---------------------------------------------------------------------------
 *   1. Verify the signature over the RAW body. Nothing is decoded before this.
 *   2. Read the receiver's switch. Off answers 403 and claims NOTHING.
 *   3. Read the body into a notice, and check the length of its idempotency key
 *      against the column that stores it. Either failure answers 422.
 *   4. Resolve the order this shop has under the identifier the notice carries.
 *      None answers 404.
 *   5. Claim the idempotency key. Already claimed answers 409.
 *   6. Apply, and record the key as applied.
 *
 * WHY STEPS 3 AND 4 COME BEFORE THE CLAIM, which is the one place this
 * implementation is tighter than the numbered list of
 * `docs/inbound-contract.md` §8.1. Everything before the claim is a refusal
 * about the delivery ITSELF, and a key claimed for an effect that never landed
 * answers the sender's NEXT delivery of that same fact with a 409 - which it
 * classifies as PUBLISHED. The notice would then be lost with both sides
 * believing it arrived. That is exactly the harm the switched-off receiver
 * avoids by not claiming, and it is the same harm here. The observable answer
 * table is identical either way; what changes is whether a permanent refusal
 * leaves a claimed key behind, and it must not.
 *
 * It also keeps `InboundDeliveryLedger::forget()` honest. That method documents
 * itself as called in ONE situation - the effect failed AFTER the key was
 * claimed - precisely because releasing a key that was never claimed would
 * delete SOMEBODY ELSE'S row if the two happened to collide. With the claim
 * placed here, the only path that needs to release is the failing effect.
 *
 * ---------------------------------------------------------------------------
 * WHAT COMES BACK, AND HOW THE SENDER READS IT
 * ---------------------------------------------------------------------------
 *   204  accepted          -> PUBLISHED.  The effect is in the shop. NO BODY.
 *   401  unauthenticated   -> PERMANENT.  NO BODY, and no hint of which of the
 *                                         three ways of failing it was.
 *   403  receiver disabled -> PERMANENT.  Body, so the one person who can turn
 *                                         it on reads what to do.
 *   404  unknown order     -> PERMANENT.  Body.
 *   422  malformed         -> PERMANENT.  Body.
 *   409  redelivery discarded -> PUBLISHED. Body. Nothing was written twice.
 *   5xx  an exception escaped -> RETRYABLE. WordPress renders it.
 *
 * The numbers are not typed here: they are read from
 * {@see InboundResponseStatus}, which is the one table both receivers answer
 * from, so that the sender cannot be told 422 by one route and 409 by the other
 * about the same kind of refusal.
 *
 * `InboundResponseStatus::RATE_LIMITED` (429, the only RETRYABLE answer of this
 * side) is in that table and this receiver never emits it: nothing here limits
 * a rate. It is written down rather than left to be inferred, because a reader
 * who assumed otherwise would look for a limiter that does not exist.
 *
 * NOTHING IS ADDED TO THE REJECTION CATALOGUE OF THE PLATFORM. That catalogue
 * describes how OUR ingestion route rejects a shop's webhook - the OPPOSITE
 * direction - it is append-only because installations of third parties classify
 * by it, and publishing a code there that this direction produces would hand
 * those installations a value their lane never sends.
 *
 * ---------------------------------------------------------------------------
 * NOTHING GOES OUT, AND NO CREDENTIAL COMES BACK
 * ---------------------------------------------------------------------------
 * Applying a notice makes no HTTP request and enqueues no delivery: a receiver
 * that answered by talking to the network would turn one delivery into two and
 * a remote failure into a failure of this answer. The status is stored as this
 * plugin's OWN fulfilment state and the native order status is never written,
 * which is what keeps this shop from sending the fact straight back out.
 *
 * No answer, notice or log line of this class carries the signing secret, the
 * expected signature or the presented one. This class logs nothing at all.
 */
final class OrderStatusEndpoint {

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
	 * Writes the fulfilment state onto the order.
	 *
	 * @var OrderStatusStorage|null
	 */
	private ?OrderStatusStorage $storage;

	/**
	 * Constructor.
	 *
	 * Every collaborator is optional and built on first use, exactly as
	 * `MyAccount\InvoiceCorrelationEndpoint` builds its signer: production wires
	 * this receiver with `new OrderStatusEndpoint()` and nothing else, while a
	 * test hands in a ledger double instead of reaching a database that a bare
	 * PHP run does not have.
	 *
	 * @param InboundSignatureVerifier|null $verifier Verifier of the inbound signature.
	 * @param InboundDeliveryLedger|null    $ledger   Record of the deliveries already claimed.
	 * @param OrderStatusStorage|null       $storage  Writer of the fulfilment state.
	 */
	public function __construct(
		?InboundSignatureVerifier $verifier = null,
		?InboundDeliveryLedger $ledger = null,
		?OrderStatusStorage $storage = null
	) {
		$this->verifier = $verifier;
		$this->ledger   = $ledger;
		$this->storage  = $storage;
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
			Constants::INBOUND_ORDER_STATUS_ROUTE,
			array(
				'methods'             => Constants::INBOUND_REST_METHOD,
				'callback'            => array( $this, 'handle' ),

				/*
				 * Always yes, and the signature check inside `handle()` is what
				 * actually guards the route; the reasoning is in the class
				 * block. What matters here is that the callback is PRESENT:
				 * WordPress serves a route registered without one, warning into
				 * a log the merchant never opens - so an omission is not a
				 * closed door, it is an open one with a note about it.
				 */
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies the delivery and, if it holds up, applies the fulfilment status
	 * it carries to the order of this shop it names.
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
				'The order status receiver of this shop is switched off. Nothing was applied and no delivery was recorded, '
				. 'so publishing this notice again once it is switched on will apply it.'
			);
		}

		$notice = $this->read( $raw_body );

		if ( null === $notice ) {
			return $this->explained(
				InboundResponseStatus::MALFORMED,
				'The body is not an order status notice this contract can read.'
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
				'This delivery had already been applied. Nothing was written a second time.'
			);
		}

		$this->apply( $order, $notice );

		$this->ledger()->mark_applied( $notice->idempotency_key, time() );

		return $this->bare( InboundResponseStatus::ACCEPTED );
	}

	/**
	 * Applies the notice, releasing the claim if the effect fails.
	 *
	 * The release is the ONE thing this wrapper exists for. The claim is taken
	 * before the effect - that order is what makes it a claim - so a failure in
	 * between would otherwise leave the delivery recorded as handled while
	 * nothing was applied, and the sender's retry would be answered as a
	 * redelivery, which it classifies as PUBLISHED.
	 *
	 * The failure is RE-THROWN and not translated. It leaves the REST callback,
	 * WordPress renders it as a 500, and the sender classifies a 500 as
	 * retryable - which is exactly what a shop that could not write should get.
	 * Answering a 4xx here would end the queue row over a failure that a second
	 * attempt may well not have.
	 *
	 * A notice OLDER than the one already applied is not a failure: the storage
	 * drops it in silence, returns normally, and this delivery is accepted with
	 * its claim intact. Releasing there would invite the sender to deliver an
	 * out-of-order fact again.
	 *
	 * @param WC_Order            $order  Order of this shop, already resolved.
	 * @param InboundStatusNotice $notice Notice as it arrived, already read.
	 * @return void
	 *
	 * @throws \Throwable Whatever the storage raised, after the claim is released.
	 */
	private function apply( WC_Order $order, InboundStatusNotice $notice ): void {
		try {
			$this->storage()->apply( $order, $notice );
		} catch ( \Throwable $failure ) {
			$this->ledger()->forget( $notice->idempotency_key );

			throw $failure;
		}
	}

	/**
	 * Whether the presented signature is the one this shop's secret produces
	 * over these exact bytes.
	 *
	 * The material is the body as it ARRIVED. Verifying against a re-encoding of
	 * the decoded document would compare a signature over one byte sequence with
	 * a digest over a different one - key order, spacing, escaping and number
	 * notation all survive the round trip only by luck - and every legitimate
	 * caller would be rejected with an unexplained 401.
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
	 * Reads a verified body into a status notice, or answers null when it is
	 * not one.
	 *
	 * The decoding is guarded rather than trusted: a body that is not a JSON
	 * object at all is as malformed as one whose status is outside the four, and
	 * both leave through the same answer.
	 *
	 * @param string $raw_body Verified body.
	 * @return InboundStatusNotice|null
	 */
	private function read( string $raw_body ): ?InboundStatusNotice {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return InboundStatusNotice::from_array( $decoded );
	}

	/**
	 * The order of this shop the notice names, or null when there is none.
	 *
	 * NOTHING SIMILAR IS LOOKED FOR. There is no search by amount, by date or by
	 * proximity, because a near miss hands the order of one buyer to another.
	 *
	 * The identifier arrives as a STRING - the platform stores the remote
	 * identifier of every kind of shop in one column and does not narrow it per
	 * platform - and it is accepted here only when it is a plain positive
	 * integer. A cast would turn `1042-from-another-shop` into `1042`, which is
	 * a real order of this shop belonging to somebody else; refusing the cast
	 * turns that value into the 404 it is.
	 *
	 * The lookup goes through `wc_get_order()` and never through a post query or
	 * a direct read of the post meta table: under the modern order storage an
	 * order is not a post, and this plugin declares itself compatible with that
	 * storage. The result is checked with `instanceof`, because that function
	 * also answers refunds and other order types for identifiers that are not an
	 * order.
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
	 * The fulfilment state writer, built on first use.
	 *
	 * @return OrderStatusStorage
	 */
	private function storage(): OrderStatusStorage {
		if ( null === $this->storage ) {
			$this->storage = new OrderStatusStorage();
		}

		return $this->storage;
	}
}
