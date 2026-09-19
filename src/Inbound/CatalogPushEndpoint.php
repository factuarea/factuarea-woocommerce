<?php
/**
 * The signed receiver of the catalogue, price and stock push.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The door the platform publishes a batch of articles through, and the
 * verification that guards it.
 *
 * ---------------------------------------------------------------------------
 * THE THIRD RECEIVER, AND THE ONE WHOSE UNIT IS NOT THE DELIVERY
 * ---------------------------------------------------------------------------
 * Its two siblings - {@see OrderStatusEndpoint} and {@see OrderRefundEndpoint} -
 * each accept ONE notice about ONE order, so their delivery either landed or it
 * did not. This one accepts a BATCH, and a batch of five in which two articles
 * cannot be resolved is not a failed delivery: three articles were applied and
 * they stay applied. So the answer of this route carries the outcome of EVERY
 * article, and the sender reads the batch by reading that list rather than by
 * reading one number for the whole thing.
 *
 * That is the one visible difference. Everything else - the open permission
 * callback with the signature checked inside the handler, the absence of a
 * declared argument schema, the order of the checks, the frozen table of
 * refusals, and the rule that nothing goes out - is shared with the siblings,
 * and is shared deliberately: a shop that answered one lane by one set of rules
 * and the other by another would be two integrations wearing one name.
 *
 * ---------------------------------------------------------------------------
 * THE ENVELOPE, WHICH THIS RECEIVER CHOSE AND THEREFORE HAS TO PUBLISH
 * ---------------------------------------------------------------------------
 * The ITEM was frozen before any receiver was written: its keys, their types
 * and the rules that reject it live in {@see CatalogPushItem}. The ENVELOPE was
 * not, because at the time it was written there was no sender to measure. The
 * platform's outbound catalogue lane writes through the NATIVE WooCommerce REST
 * API (`wc/v3` `products/batch`, a body of `{"update": [...], "create": [...]}`)
 * and names no address of this plugin, so there was no upstream shape to copy
 * and nothing to be compatible with.
 *
 * The two names below were therefore chosen here and published in
 * `docs/inbound-contract.md` §7, which is the whole point of naming them in one
 * place: a value in a document that the code does not use is worse than a gap
 * that says it is one.
 *
 *   `items`            the list of articles. The word the platform's own batch
 *                      objects already use for the same collection
 *                      (`StoreCatalogBatch::$items`), so the two sides of the
 *                      wire call one thing by one name.
 *   `idempotency_key`  the key of the WHOLE batch. Spelled exactly as the two
 *                      notices of this lane spell it
 *                      ({@see \Factuarea\WooCommerce\Contract\InboundStatusNotice::FIELD_IDEMPOTENCY_KEY},
 *                      {@see \Factuarea\WooCommerce\Contract\InboundRefundNotice::FIELD_IDEMPOTENCY_KEY}),
 *                      because one lane with two spellings of one idea makes
 *                      every sender carry both. A test asserts the three are
 *                      the same string rather than trusting that they look it.
 *
 * The batch is ONE delivery: one key, claimed once, for the whole list. Keying
 * each article separately would let a retry re-apply four articles to fix the
 * fifth, and would need a row per article of every resynchronisation.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER OF THE CHECKS IS THE CONTRACT
 * ---------------------------------------------------------------------------
 *   1. Verify the signature over the RAW body. Nothing is decoded before this.
 *   2. Read the receiver's switch. Off answers 403 and claims NOTHING.
 *   3. Read the WHOLE batch and validate it: the envelope, the length of the
 *      key, the two kinds of unaccepted item key, and every item's shape. Any
 *      failure answers 422 having claimed NOTHING.
 *   4. Claim the key of the batch. Already claimed answers 409.
 *   5. Resolve and apply, article by article, and record the key as applied.
 *
 * WHY EVERYTHING THAT REFUSES COMES BEFORE THE CLAIM. A key claimed for an
 * effect that never landed answers the sender's NEXT delivery of that same
 * batch with a 409, which it classifies as PUBLISHED - the batch would be lost
 * with both sides believing it arrived. It is the same harm the switched-off
 * receiver avoids by not claiming, and the decision that a batch carrying an
 * internal figure or an unknown key is refused "without recording the delivery"
 * makes it explicit for this route. It also keeps
 * {@see InboundDeliveryLedger::forget()} honest: that method documents itself
 * as called in ONE situation - the effect failed AFTER the key was claimed -
 * because releasing a key that was never claimed would delete somebody else's
 * row on a collision.
 *
 * WHY THE ARTICLES ARE RESOLVED AFTER THE CLAIM AND NOT BEFORE, which is where
 * this receiver differs from its siblings, and the difference is forced. The
 * siblings resolve the ORDER before claiming because an order that is not of
 * this shop is a refusal of the delivery (404), and a refusal must not claim.
 * Here an article that does not resolve is NOT a refusal - it is one of the
 * per-article outcomes inside a perfectly good answer - so resolving early
 * would avoid no refusal at all, and it would cost the guarantee that a
 * REDELIVERY touches nothing: resolving WRITES. The first time an article is
 * found by its reference, {@see ProductResolver} records the remote identity on
 * it and saves it, so a redelivery that resolved before claiming would write to
 * the merchant's articles before answering "already applied". The contract says
 * a redelivery does not write, so the claim goes first.
 *
 * ---------------------------------------------------------------------------
 * THE TWO KINDS OF UNACCEPTED KEY REJECT THE SAME, AND ARE NAMED APART
 * ---------------------------------------------------------------------------
 * Both refuse the WHOLE batch as malformed, with nothing applied and no key
 * recorded, and the answer names each under its own heading:
 *
 *  - An INTERNAL figure - cost, supplier, physical balance, committed quantity,
 *    reorder threshold - is not a field this receiver has not learnt yet. It is
 *    a figure that must never have left the platform, and one of them arriving
 *    means something upstream is leaking. Applying the rest of that batch would
 *    leave the leak without a witness.
 *  - An UNKNOWN key is a CONTRACT THAT CHANGED. Accepting it silently means the
 *    platform believes it published something the shop never applied, and in a
 *    catalogue that difference is a price or a quantity: the merchant finds out
 *    by selling at the wrong price.
 *
 * They are told apart with {@see CatalogPushItem::internal_keys_in()} and
 * {@see CatalogPushItem::unknown_keys_in()}, which exist as two public
 * questions precisely so that this receiver can answer "which of the two was
 * it" - the factory answers `null` for both and cannot say which.
 *
 * `CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED` is NOT what either of these
 * produces: it is the per-article reason a WRITER answers when a declared
 * catalogue field is one it cannot write, a guard that is unreachable from this
 * route today because the batch never gets that far.
 *
 * The same rule governs the ENVELOPE. A key of the envelope that this contract
 * does not enumerate refuses the batch too, for the reason that governs the
 * item: the receiver would otherwise drop, in silence, something the platform
 * believes it published.
 *
 * ---------------------------------------------------------------------------
 * WHAT COMES BACK, AND HOW THE SENDER READS IT
 * ---------------------------------------------------------------------------
 *   200  per-article outcome -> the batch was READ. The body carries one
 *                               outcome per article, applied or rejected with
 *                               its typed reason.
 *   401  unauthenticated     -> PERMANENT. NO BODY, and no hint of which of the
 *                               three ways of failing it was.
 *   403  receiver disabled   -> PERMANENT. Body, so the one person who can turn
 *                               it on reads what to do.
 *   422  malformed           -> PERMANENT. Body. Nothing was applied.
 *   409  redelivery discarded -> PUBLISHED. Body. Nothing was written twice.
 *   5xx  an exception escaped -> RETRYABLE. WordPress renders it.
 *
 * WHY A BATCH ANSWERS 200 AND NEVER THE 204 ITS SIBLINGS ANSWER. The accepted
 * status of the frozen table is bodiless on purpose - there is nothing a status
 * notice needs to say back - and a 204 that carried a body would not be one.
 * Here the per-article outcome IS the answer, so there has to be a body, and a
 * batch whose articles were not all applied must not be reported as accepted
 * either. One status for both cases and not two, so that the sender parses one
 * shape and reads the list rather than branching on a number that cannot carry
 * what it needs anyway.
 *
 * This does not loosen the frozen table, which governs the REFUSALS: those are
 * the answers the sender classifies BY NUMBER, and the fallback the table exists
 * to keep this side away from - "any other 4xx is a malformed notice" - is a
 * fallback for 4xx. Every refusal of this route is read from
 * {@see InboundResponseStatus} and none is typed here.
 *
 * `InboundResponseStatus::RATE_LIMITED` (429) and `::UNKNOWN` (404) are in that
 * table and this receiver never answers either: nothing here limits a rate, and
 * an article this shop does not have is a per-article `not_found`, not a
 * refusal of the delivery. Written down rather than left to be inferred,
 * because a reader who assumed otherwise would look for code that does not
 * exist.
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
 * Applying a batch makes no HTTP request and enqueues no delivery: a receiver
 * that answered by talking to the network would turn one delivery into two and
 * a remote failure into a failure of this answer. Writing a price or a quantity
 * fires the ordinary product hooks of WooCommerce, and this plugin has no
 * captor on them - its captors are on orders - so nothing of a catalogue push
 * comes back out.
 *
 * No answer of this class carries the signing secret, the expected signature or
 * the presented one. This class logs nothing at all. It DOES name the offending
 * keys of a refused batch in its answer, which is not a credential and is the
 * whole point: a leak has to have a witness, and a sender whose contract moved
 * has to be told which key moved it.
 */
final class CatalogPushEndpoint {

	/**
	 * Envelope key holding the list of articles.
	 *
	 * Chosen by this receiver and published in `docs/inbound-contract.md` §7;
	 * the class block says why this word and not another.
	 */
	public const FIELD_ITEMS = 'items';

	/**
	 * Envelope key holding the idempotency key of the WHOLE batch.
	 *
	 * The same spelling the two notices of this lane use. It is declared here
	 * rather than read off one of them because the batch is a document of its
	 * own and reads better standing alone; a test asserts the three strings are
	 * identical, so the two ways of getting it wrong - a typo and a later
	 * rename - are both mechanical failures.
	 */
	public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';

	/**
	 * Every key the envelope may carry, and there is no other.
	 *
	 * @var list<string>
	 */
	public const ENVELOPE_KEYS = array(
		self::FIELD_ITEMS,
		self::FIELD_IDEMPOTENCY_KEY,
	);

	/**
	 * The answer of a batch that was READ: one outcome per article in the body.
	 *
	 * Not part of {@see InboundResponseStatus}, and the class block says why:
	 * that table is the frozen set of REFUSALS, which the sender classifies by
	 * number, while this is the 2xx that carries the per-article outcome the
	 * bodiless accepted status cannot.
	 */
	public const STATUS_PER_ITEM_OUTCOME = 200;

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
	 * Constructor.
	 *
	 * Both collaborators are optional and built on first use, exactly as the
	 * sibling receivers build theirs: production wires this receiver with
	 * `new CatalogPushEndpoint()` and nothing else, while a test hands in a
	 * ledger double instead of reaching a database that a bare PHP run does not
	 * have.
	 *
	 * The resolver and the three writers are NOT constructor arguments. They
	 * are built once per batch inside the handler, because the resolver carries
	 * the reason of the LAST article it refused: an instance shared between
	 * deliveries in a long-lived process would carry one batch's rejection into
	 * the next.
	 *
	 * @param InboundSignatureVerifier|null $verifier Verifier of the inbound signature.
	 * @param InboundDeliveryLedger|null    $ledger   Record of the deliveries already claimed.
	 */
	public function __construct(
		?InboundSignatureVerifier $verifier = null,
		?InboundDeliveryLedger $ledger = null
	) {
		$this->verifier = $verifier;
		$this->ledger   = $ledger;
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
			Constants::INBOUND_CATALOG_PUSH_ROUTE,
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
	 * Verifies the delivery and, if it holds up, applies the batch of articles
	 * it carries to the catalogue of this shop.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response The per-article outcome, or one of the frozen refusals.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = (string) $request->get_body();

		if ( ! $this->signature_holds( $raw_body, (string) $request->get_header( Constants::INBOUND_SIGNATURE_HEADER ) ) ) {
			return $this->bare( InboundResponseStatus::UNAUTHORISED );
		}

		if ( ! SettingsPage::receiver_enabled( Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ) ) {
			return $this->explained(
				InboundResponseStatus::RECEIVER_DISABLED,
				'The catalogue, price and stock receiver of this shop is switched off. Nothing was applied and no '
				. 'delivery was recorded, so publishing this batch again once it is switched on will apply it.'
			);
		}

		try {
			$batch = $this->read( $raw_body );
		} catch ( InboundPayloadNotReadableException $unreadable ) {
			return $this->explained( InboundResponseStatus::MALFORMED, $unreadable->getMessage() );
		}

		if ( ! $this->ledger()->claim( $batch['key'], time() ) ) {
			return $this->explained(
				InboundResponseStatus::DUPLICATE_DISCARDED,
				'This batch had already been applied. No article was written a second time.'
			);
		}

		return $this->apply( $batch['key'], $batch['items'] );
	}

	/**
	 * Resolves and applies every article of a claimed batch, releasing the
	 * claim if the batch fails.
	 *
	 * The three writers run in ORDER over each resolved article - catalogue
	 * fields, price, stock - and the first one that refuses ends that article
	 * with its typed reason. A refusal by a later writer does NOT undo what an
	 * earlier one wrote, and the answer says so rather than pretending
	 * otherwise: each writer persists what it owns, so an article whose name
	 * and price were applied and whose quantity was refused keeps the name and
	 * the price it was just given. Undoing them would need this receiver to
	 * remember and rewrite values it never read, which is a worse failure than
	 * the honest one.
	 *
	 * A rejected article does not stop the batch. A RAISED failure does, and it
	 * is not the same event: a rejection is this shop answering, and a raise is
	 * this shop unable to. Whatever was applied before the raise stays applied -
	 * the writers already saved it - and the claim is released so that the
	 * sender's retry can come back to the rest. Every write of this lane is an
	 * assignment and not an increment, so applying an article twice leaves it
	 * exactly as applying it once does.
	 *
	 * The two arms are ordered, not interchangeable:
	 * `InboundPayloadNotReadableException` extends `RuntimeException`, so it has
	 * to be caught FIRST or the permanent case leaves as a retryable one and the
	 * sender comes back seven more times for a body that will never be readable.
	 *
	 * @param string                      $idempotency_key Key of the batch, already claimed.
	 * @param array<int, CatalogPushItem> $items    Articles of the batch, already read.
	 * @return WP_REST_Response The per-article outcome, or the permanent refusal.
	 *
	 * @throws \Throwable Whatever a writer raised for a failure that is not permanent, after the claim is released.
	 */
	private function apply( string $idempotency_key, array $items ): WP_REST_Response {
		$resolver = new ProductResolver();
		$catalog  = new CatalogFieldWriter();
		$price    = new PriceWriter();
		$stock    = new StockWriter();

		$outcomes = array();

		try {
			foreach ( $items as $item ) {
				$outcomes[] = $this->outcome_of( $resolver, $catalog, $price, $stock, $item );
			}
		} catch ( InboundPayloadNotReadableException $unreadable ) {
			unset( $unreadable );

			$this->ledger()->forget( $idempotency_key );

			return $this->explained(
				InboundResponseStatus::MALFORMED,
				'The batch could not be read far enough to apply it, and repeating the same body would fail in the '
				. 'same way. Whatever had already been written stays written; nothing else was.'
			);
		} catch ( \Throwable $failure ) {
			$this->ledger()->forget( $idempotency_key );

			throw $failure;
		}

		$this->ledger()->mark_applied( $idempotency_key, time() );

		$body = array();

		foreach ( $outcomes as $outcome ) {
			$body[] = $outcome->to_array();
		}

		return new WP_REST_Response(
			array( self::FIELD_ITEMS => $body ),
			self::STATUS_PER_ITEM_OUTCOME
		);
	}

	/**
	 * What happened to ONE article of the batch.
	 *
	 * The rejection reason of an unresolved article is read from the resolver
	 * IMMEDIATELY, before the next article is looked at: the resolver keeps the
	 * reason of its last answer, and asking it one article later would label
	 * this rejection with somebody else's cause.
	 *
	 * @param ProductResolver    $resolver Resolver of this batch.
	 * @param CatalogFieldWriter $catalog Catalogue field writer of this batch.
	 * @param PriceWriter        $price    Price writer of this batch.
	 * @param StockWriter        $stock    Stock writer of this batch.
	 * @param CatalogPushItem    $item     Article as it arrived, already read.
	 * @return CatalogPushOutcome Applied, or rejected with its typed reason.
	 */
	private function outcome_of(
		ProductResolver $resolver,
		CatalogFieldWriter $catalog,
		PriceWriter $price,
		StockWriter $stock,
		CatalogPushItem $item
	): CatalogPushOutcome {
		$product = $resolver->resolve( $item );

		if ( null === $product ) {
			return CatalogPushOutcome::rejected( $item->sender_reference(), $resolver->last_rejection() );
		}

		$outcome = $catalog->write( $product, $item );

		if ( ! $outcome->is_applied() ) {
			return $outcome;
		}

		$outcome = $price->write( $product, $item );

		if ( ! $outcome->is_applied() ) {
			return $outcome;
		}

		return $stock->write( $product, $item );
	}

	/**
	 * Reads a verified body into the batch it carries, or raises naming what
	 * made it unreadable.
	 *
	 * EVERYTHING is checked here, before the caller claims anything: the
	 * envelope, the key, the list, the two kinds of unaccepted item key and
	 * every item's shape. That is what makes "a refused batch records no
	 * delivery" a property of the code and not of the order somebody wrote the
	 * checks in.
	 *
	 * It raises rather than answering, because the failures it finds are the
	 * ones `InboundPayloadNotReadableException` was frozen for - a body that is
	 * not an object, a batch whose items are not items - and its four factories
	 * already publish the wording. The caller turns the raise into the 422 of
	 * the frozen table; nothing of this leaves as a 5xx.
	 *
	 * @param string $raw_body Verified body.
	 * @return array{key: string, items: array<int, CatalogPushItem>}
	 *
	 * @throws InboundPayloadNotReadableException When the body is not a batch this contract can read.
	 */
	private function read( string $raw_body ): array {
		$document = $this->document_of( $raw_body );

		$this->refuse_unenumerated_envelope_keys( $document );

		$key = $this->idempotency_key_of( $document );

		$raw_items = $this->raw_items_of( $document );

		$this->refuse_unaccepted_item_keys( $raw_items );

		$items = array();

		foreach ( $raw_items as $position => $raw_item ) {
			$item = CatalogPushItem::from_array( $raw_item );

			if ( null === $item ) {
				throw $this->unreadable(
					sprintf(
						'Article %1$d of the batch delivered to %2$s is not one this contract can read, so no article '
						. 'of the batch was applied. Every article declares its reference as a string, and its price '
						. 'as a decimal STRING rather than a number: a number loses the last decimal that a string '
						. 'keeps exactly.',
						(int) $position + 1,
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
						Constants::INBOUND_CATALOG_PUSH_ROUTE
					)
				);
			}

			$items[] = $item;
		}

		return array(
			'key'   => $key,
			'items' => $items,
		);
	}

	/**
	 * The decoded body, when it is a JSON object.
	 *
	 * A JSON list is refused here rather than falling through to "the envelope
	 * has keys it should not": `[1, 2, 3]` is not an envelope with three
	 * unknown keys, it is not an envelope.
	 *
	 * @param string $raw_body Verified body.
	 * @return array<array-key, mixed>
	 *
	 * @throws InboundPayloadNotReadableException When the body is not a JSON object.
	 */
	private function document_of( string $raw_body ): array {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) || ( array() !== $decoded && array_is_list( $decoded ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
			throw InboundPayloadNotReadableException::body_is_not_an_object( Constants::INBOUND_CATALOG_PUSH_ROUTE );
		}

		return $decoded;
	}

	/**
	 * Raises when the envelope carries a key this contract does not enumerate.
	 *
	 * Same rule as the item, and for the same reason: a key nobody reads is a
	 * datum the platform believes it published and this shop never applied.
	 *
	 * @param array<array-key, mixed> $document Decoded envelope.
	 * @return void
	 *
	 * @throws InboundPayloadNotReadableException When the envelope carries an unenumerated key.
	 */
	private function refuse_unenumerated_envelope_keys( array $document ): void {
		$unenumerated = array();

		foreach ( array_keys( $document ) as $key ) {
			$key = (string) $key;

			if ( ! in_array( $key, self::ENVELOPE_KEYS, true ) ) {
				$unenumerated[] = $key;
			}
		}

		if ( array() === $unenumerated ) {
			return;
		}

		throw $this->unreadable(
			sprintf(
				'The batch delivered to %1$s declares envelope keys this contract does not enumerate: %2$s. No article '
				. 'of it was applied and no delivery was recorded: an unknown key is a contract that changed, and '
				. 'accepting it in silence would leave the change without a witness.',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Key names read off an already VERIFIED body and listed so that the leak, or the contract that changed, has a witness. They are formatted into a message this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML, so escaping them would corrupt the very names they exist to publish.
				implode( ', ', $unenumerated )
			)
		);
	}

	/**
	 * The idempotency key of the batch.
	 *
	 * Its LENGTH is checked against the column that records it, and not its
	 * shape: a key that does not fit could not tell two deliveries apart, while
	 * what a key looks like is the sender's business.
	 *
	 * @param array<array-key, mixed> $document Decoded envelope.
	 * @return string
	 *
	 * @throws InboundPayloadNotReadableException When the key is absent, of the wrong kind or too long.
	 */
	private function idempotency_key_of( array $document ): string {
		if ( ! array_key_exists( self::FIELD_IDEMPOTENCY_KEY, $document ) ) {
			throw InboundPayloadNotReadableException::missing_field(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on the argument above.
				self::FIELD_IDEMPOTENCY_KEY
			);
		}

		$key = $document[ self::FIELD_IDEMPOTENCY_KEY ];

		if ( ! is_string( $key ) || '' === $key ) {
			throw InboundPayloadNotReadableException::field_of_wrong_kind(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on the argument above.
				self::FIELD_IDEMPOTENCY_KEY,
				'a non-empty string'
			);
		}

		if ( strlen( $key ) > InboundDeliveryTable::KEY_MAX_LENGTH ) {
			throw $this->unreadable(
				'The idempotency key of the delivery is longer than the column that records it, so two different '
				. 'deliveries could not be told apart.'
			);
		}

		return $key;
	}

	/**
	 * The articles of the batch, as they arrived and before being read.
	 *
	 * A batch with NO articles is refused rather than answered with an empty
	 * list. The sender composed nothing and shipped it, which is a fault
	 * upstream; and claiming its key would answer the corrected redelivery of
	 * that same key with "already applied", losing the batch that mattered.
	 *
	 * @param array<array-key, mixed> $document Decoded envelope.
	 * @return array<int, array<array-key, mixed>>
	 *
	 * @throws InboundPayloadNotReadableException When the articles are absent, not a list, empty, or not objects.
	 */
	private function raw_items_of( array $document ): array {
		if ( ! array_key_exists( self::FIELD_ITEMS, $document ) ) {
			throw InboundPayloadNotReadableException::missing_field(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on the argument above.
				self::FIELD_ITEMS
			);
		}

		$raw_items = $document[ self::FIELD_ITEMS ];

		if ( ! is_array( $raw_items ) || ( array() !== $raw_items && ! array_is_list( $raw_items ) ) ) {
			throw InboundPayloadNotReadableException::batch_is_not_a_list(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on the argument above.
				self::FIELD_ITEMS
			);
		}

		if ( array() === $raw_items ) {
			throw $this->unreadable(
				sprintf(
					'The batch delivered to %1$s carried no article at all, so there was nothing to apply and no '
					. 'delivery was recorded. Recording it would answer the corrected redelivery of that same key as '
					. 'already published.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
					Constants::INBOUND_CATALOG_PUSH_ROUTE
				)
			);
		}

		foreach ( $raw_items as $raw_item ) {
			if ( ! is_array( $raw_item ) ) {
				throw InboundPayloadNotReadableException::batch_is_not_a_list(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
					Constants::INBOUND_CATALOG_PUSH_ROUTE,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on the argument above.
					self::FIELD_ITEMS
				);
			}
		}

		return $raw_items;
	}

	/**
	 * Raises when any article carries a key this contract does not accept,
	 * naming the two kinds APART.
	 *
	 * The scan runs over the RAW articles, before a single one is read,
	 * resolved or written: both failures refuse the whole batch, and a batch
	 * that is refused whole must not have applied a third of itself first.
	 *
	 * Keys are collected without repeating: a batch of fifty articles each
	 * carrying a cost is one leak of one field, and an answer naming it fifty
	 * times is harder to read, not more informative.
	 *
	 * @param array<int, array<array-key, mixed>> $raw_items Articles as they arrived.
	 * @return void
	 *
	 * @throws InboundPayloadNotReadableException When any article carries an internal or an unknown key.
	 */
	private function refuse_unaccepted_item_keys( array $raw_items ): void {
		$internal = array();
		$unknown  = array();

		foreach ( $raw_items as $raw_item ) {
			foreach ( CatalogPushItem::internal_keys_in( $raw_item ) as $key ) {
				if ( ! in_array( $key, $internal, true ) ) {
					$internal[] = $key;
				}
			}

			foreach ( CatalogPushItem::unknown_keys_in( $raw_item ) as $key ) {
				if ( ! in_array( $key, $unknown, true ) ) {
					$unknown[] = $key;
				}
			}
		}

		if ( array() === $internal && array() === $unknown ) {
			return;
		}

		$sentences = array();

		if ( array() !== $internal ) {
			$sentences[] = sprintf(
				'It carries figures that must never leave the platform: %s. These are not fields this receiver has '
				. 'not learnt yet; one of them arriving means something upstream is publishing what it should keep, '
				. 'and applying the rest of the batch would leave that without a witness.',
				implode( ', ', $internal )
			);
		}

		if ( array() !== $unknown ) {
			$sentences[] = sprintf(
				'It declares keys this contract does not enumerate: %s. An unknown key is a contract that changed, '
				. 'not a datum to ignore politely: accepting it in silence would leave the platform believing it '
				. 'published something this shop never applied.',
				implode( ', ', $unknown )
			);
		}

		throw $this->unreadable(
			sprintf(
				'The batch delivered to %1$s was refused whole: no article of it was applied and no delivery was '
				. 'recorded. %2$s',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A frozen route literal of this plugin, formatted into a message of the inbound contract that this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML. Escaping it would corrupt the very field names it exists to name, it reaches no page, and none of it is a credential or a byte of the body.
				Constants::INBOUND_CATALOG_PUSH_ROUTE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Key names read off an already VERIFIED body and listed so that the leak, or the contract that changed, has a witness. They are formatted into a message this class ALWAYS catches and answers as JSON data inside a REST response, never as HTML, so escaping them would corrupt the very names they exist to publish.
				implode( ' ', $sentences )
			)
		);
	}

	/**
	 * The refusal of a batch this contract cannot read, worded here.
	 *
	 * The four factories of {@see InboundPayloadNotReadableException} publish
	 * the wording of the failures common to the three receivers of this lane.
	 * The ones only a BATCH can have - an envelope key nobody enumerated, a
	 * batch with no articles in it, an internal figure that leaked, an article
	 * whose shape cannot be read - are worded here and raised through this one
	 * door, so that there is a single place saying why none of these messages
	 * is escaped: they travel as the message of an exception this class ALWAYS
	 * catches, and leave as JSON data inside a REST response, never as HTML.
	 * Running them through an HTML escaper would corrupt the very key names
	 * they exist to name, and none of them carries a credential or a byte of
	 * the body.
	 *
	 * @param string $message What made the batch unreadable, in English and with no credential.
	 * @return InboundPayloadNotReadableException
	 */
	private function unreadable( string $message ): InboundPayloadNotReadableException {
		return new InboundPayloadNotReadableException( $message );
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
}
