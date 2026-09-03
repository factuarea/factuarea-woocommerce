<?php
/**
 * Records a refund in the outbox, the moment it is created.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Events;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Factuarea\WooCommerce\Exception\PluginException;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Payload\OrderPayloadBuilder;

/**
 * Writes ONE outbox entry for ONE refund, in the same request that created it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS LISTENER EXISTS AT ALL, NEXT TO THE ONE THAT WATCHES STATUS
 * ---------------------------------------------------------------------------
 * A PARTIAL REFUND DOES NOT CHANGE THE ORDER STATUS. An order of 84.97 with
 * 18.72 given back stays `processing`, exactly as it was, and nothing else about
 * it moves either. So a plugin that noticed refunds by watching the status
 * transition would see every FULL refund and NOT ONE partial - and it would look
 * correct on a development shop, where refunds are tested by refunding the whole
 * order.
 *
 * What that costs is not a missing notification. Under verifiable invoicing the
 * sale has already been invoiced in a consecutive series filed with the tax
 * authority; money given back with no corrective document is an invoice that
 * overstates what the merchant charged, and it cannot be deleted afterwards -
 * only corrected with a second document, later, by hand, once somebody notices.
 * Partial refunds are also the COMMON kind: one returned item out of three.
 *
 * That is the whole reason for this file. It is not a second route to the same
 * facts as the status listener; it is the only route to a class of facts the
 * status never mentions.
 *
 * The consequence is a rule about this file rather than an intention: NOTHING
 * HERE READS THE ORDER STATUS. Not to filter, not to decide the kind of refund,
 * not to skip anything. The unit test sweeps this file for a status read and
 * fails naming it, because the tempting edit - "only enqueue when the order is
 * refunded" - restores exactly the blindness this listener was written to
 * remove.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS HOOK, AND WHY IT IS ENOUGH ON ITS OWN
 * ---------------------------------------------------------------------------
 * `woocommerce_order_refunded` is fired by `wc_create_refund()`, which is the
 * single funnel every refund goes through - the admin screen, a payment gateway
 * refunding through the API, the REST route, WP-CLI - and it is fired at the end
 * of it, AFTER the refund exists and has been saved, with the order identifier
 * and the refund identifier. It is fired for a partial refund and for a full one
 * alike: the two branches above it decide whether `woocommerce_order_partially_refunded`
 * or `woocommerce_order_fully_refunded` also runs, and this one runs in both.
 *
 * It also runs IN PROCESS and SYNCHRONOUSLY, inside the request that created the
 * refund, which is the property the whole outbox rests on: by the time the
 * request ends the fact is already recorded in a table of this plugin, so a shop
 * whose scheduled execution never runs again loses nothing - it delivers late.
 * A capture that depended on scheduled execution would be a capture that can be
 * lost, and a lost refund is a corrective invoice that never happens.
 *
 * ONE FIRING, ONE REFUND, ONE ENTRY. This class does not walk the `refunds[]`
 * list of the order looking for the ones it has not seen: the hook already
 * names the refund that was just created, and two refunds of one order are two
 * firings. Working out WHICH refunds in a body are new is the RECEIVER's job -
 * it keeps the ones it has already invoiced and this plugin does not - and doing
 * it here as well would be a second criterion for one fact, which is how the
 * same refund ends up corrected twice or not at all.
 *
 * ---------------------------------------------------------------------------
 * THE IDENTITY IS THE REFUND'S, AND THAT IS WHAT MAKES THE ROUTES CONVERGE
 * ---------------------------------------------------------------------------
 * `EventIdentity::for_refund( $refund_id )` - the refund, never the order and
 * never the delivery. Two refunds of one order are two facts with two
 * identities, so they produce two entries and two corrective documents, one for
 * each amount given back.
 *
 * The listener that watches status transitions composes the identity the SAME
 * way for the same refund, so when a full refund is seen by both routes the two
 * enqueues carry one identity and the outbox collapses them on its UNIQUE
 * constraint into a single entry. They converge because they both compose it
 * here, not because they agreed on anything.
 *
 * ---------------------------------------------------------------------------
 * THE BODY IS THE PARENT ORDER'S, ALWAYS
 * ---------------------------------------------------------------------------
 * The body built and delivered is the order's, with its `refunds[]` inside it,
 * and never a document of the refund on its own. Two reasons, and the first one
 * is mechanical: `OrderPayloadBuilder::build()` takes an ORDER identifier and
 * refuses a refund identifier outright, because `wc_get_order()` hands back a
 * `WC_Order_Refund` for one and that is not a `WC_Order`. Handing it the refund
 * identifier produces "order not found" on every single refund.
 *
 * The second is the receiver's shape: it finds refunds by reading `refunds[]`
 * out of the order body it was given, resolves each one's scope from that
 * refund's OWN amount against the order total, and rectifies for that amount.
 * A partial refund therefore produces a partial corrective document even though
 * the order status did not move - which is the behaviour this listener exists to
 * feed.
 *
 * ---------------------------------------------------------------------------
 * THE REASON OF THE REFUND IS FREE TEXT, AND IT TRAVELS UNTOUCHED
 * ---------------------------------------------------------------------------
 * In WooCommerce the reason of a refund is typed by hand by the merchant, in
 * their own language and their own words: "returned", "wrong size", "changed
 * their mind", or nothing at all. It travels inside the delivered body, in
 * `refunds[].reason`, EXACTLY as WooCommerce stored it: not trimmed, not
 * case-folded, not translated, not mapped.
 *
 * Mapping it to a fiscal correction code is FORBIDDEN here, and not out of
 * caution. A corrective invoice is numbered in a consecutive series and filed
 * with the tax authority under a specific legal ground; a wrong ground is not
 * deleted, it is corrected with yet another document. The receiver owns that
 * decision, it has the catalogue to make it, and it REFUSES with a typed reason
 * what it does not recognise instead of falling back to a catch-all - so some
 * refunds wait for a human to choose the ground, which is the correct outcome.
 * Free text cannot make that choice, and a plugin guessing it in the merchant's
 * own shop would be guessing on the tax authority's behalf.
 *
 * ---------------------------------------------------------------------------
 * A FAILURE HERE NEVER BREAKS THE MERCHANT'S REFUND
 * ---------------------------------------------------------------------------
 * This code runs inside the request in which the merchant refunds a customer.
 * An exception escaping it would abort that request halfway through
 * `wc_create_refund()`, so the failure of an invoicing integration would become
 * a failure of the refund itself - the customer's money not moving because our
 * body could not be built. So a failure to build the body is caught, recorded
 * without a single credential in it (see `Exception\PluginException` for why
 * that prohibition is absolute), and nothing is enqueued: no entry is better
 * than an entry with no body, which would be retried eight times against a
 * receiver that cannot do anything with it.
 *
 * What is NOT done here is a network call. Not one, ever, on any path. The
 * delivery belongs to the drain, which runs outside this request; making the
 * merchant's refund wait for the receiver's answer is how a slow receiver turns
 * into a slow shop.
 *
 * Recording also does NOT depend on the plugin being configured or on delivery
 * being switched on. The drain checks both before it sends anything, and it
 * leaves the entries where they are when they are missing, so a merchant who
 * fills the settings in a week from now delivers the refunds of this week.
 * Skipping the capture instead would lose them for good.
 */
final class OrderRefundedListener {

	/**
	 * The hook that fires once per refund created, partial ones included.
	 */
	public const HOOK = 'woocommerce_order_refunded';

	/**
	 * Fact being reported, in the receiver's vocabulary.
	 *
	 * It is a debugging column of the outbox and not part of the contract: the
	 * delivery carries the fixed WooCommerce topic, and the receiver decides
	 * what happened by reading the body. It is written accurately anyway,
	 * because the merchant support case that starts with "which of these
	 * entries is the refund?" is answered by this column and by nothing else.
	 */
	public const EVENT_TYPE = 'order.refunded';

	/**
	 * Builder of the delivery body, or null until it is first needed.
	 *
	 * Both dependencies are optional and built on first use rather than in the
	 * constructor. The wiring builds this listener while WordPress is still
	 * loading plugins, and the outbox takes the database handle out of the
	 * globals: building it there would reach for a handle that is not
	 * necessarily set up yet, on every request, including the ones where no
	 * refund happens.
	 *
	 * @var OrderPayloadBuilder|null
	 */
	private ?OrderPayloadBuilder $payload_builder;

	/**
	 * The outbox, or null until it is first needed.
	 *
	 * @var OutboxRepository|null
	 */
	private ?OutboxRepository $outbox;

	/**
	 * Builds the listener.
	 *
	 * Both arguments exist so that a test can hand in doubles; production passes
	 * nothing.
	 *
	 * @param OrderPayloadBuilder|null $payload_builder Builder of the delivery body.
	 * @param OutboxRepository|null    $outbox          Outbox the entry is written to.
	 */
	public function __construct( ?OrderPayloadBuilder $payload_builder = null, ?OutboxRepository $outbox = null ) {
		$this->payload_builder = $payload_builder;
		$this->outbox          = $outbox;
	}

	/**
	 * Hooks the capture.
	 *
	 * Two accepted arguments, and they are both needed: the first names the
	 * order the body is built from and the second names the refund the identity
	 * is composed from. A registration that forgot to ask for two would receive
	 * only the order identifier, and every refund of an order would then get the
	 * identity of the same non-existent refund zero.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'record_refund' ), 10, 2 );
	}

	/**
	 * Records one refund. Runs on the hook registered above, never directly.
	 *
	 * The two parameters are deliberately untyped. WooCommerce passes two
	 * integers, but an action is a public surface: a third-party plugin or a
	 * migration script firing the same hook with strings would, against declared
	 * integer parameters and `strict_types`, raise a TypeError inside
	 * `wc_create_refund()` and take the merchant's refund down with it. They are
	 * normalised below instead, and anything that is not a usable identifier
	 * ends the call without enqueueing.
	 *
	 * @param mixed $order_id  Identifier of the refunded order, as the hook passes it.
	 * @param mixed $refund_id Identifier of the refund that was just created, as the hook passes it.
	 * @return void
	 */
	public function record_refund( $order_id, $refund_id ): void {
		$order  = self::identifier( $order_id );
		$refund = self::identifier( $refund_id );

		if ( null === $order || null === $refund ) {
			$this->report(
				sprintf(
					'The %s hook fired without a usable order and refund identifier, so no refund was recorded for Factuarea. Check whether another plugin is firing this hook itself.',
					self::HOOK
				)
			);

			return;
		}

		$event_id = EventIdentity::for_refund( $refund );

		try {
			$raw_body = $this->payload_builder()->build( $order );
		} catch ( PluginException $exception ) {
			/*
			 * The message carries a cause and a next step and, by the rule of
			 * the exception catalogue, no credential of any kind - so it can be
			 * written straight to the shop log, which is where a merchant or
			 * their host will look.
			 */
			$this->report( $exception->getMessage() );

			return;
		}

		/*
		 * The address of the shop, raw, exactly as WordPress reports it. It is
		 * the origin the delivery declares and the value the drain compares
		 * against the configured one before sending anything, so normalising it
		 * here - stripping a trailing slash, forcing a scheme - would compare a
		 * tidied string against an untidied one and refuse to deliver its own
		 * shop's refunds.
		 */
		$this->outbox()->enqueue( $event_id, $order, self::EVENT_TYPE, $raw_body, home_url() );

		$this->remember_idempotency_key( $refund, $event_id );
	}

	/**
	 * Writes onto the refund the idempotency key the receiver will derive for
	 * this event.
	 *
	 * WHY IT IS WRITTEN AT ALL. The key is the only handle that ties this refund
	 * to the corrective document the receiver issues for it. This plugin never
	 * sends it - the receiver derives the same digest from the same three parts -
	 * so recording it costs one meta value and is the only way the correlation
	 * callback could ever match a corrective invoice to the refund it corrects.
	 * Deriving it later is not possible: it depends on the store identifier
	 * configured AT CAPTURE TIME, and a merchant who re-registers the shop gets a
	 * different one.
	 *
	 * WHY ON THE REFUND AND NOT ON THE ORDER. The parent order already carries
	 * this same meta key for its own payment event, written by the listener that
	 * captures a paid order. Writing a refund's key there would overwrite the
	 * payment's - and the second refund would overwrite the first - so an order
	 * with two refunds would end up with one key and two facts. The refund is a
	 * first-class object with its own meta, under the modern order storage as
	 * well as the legacy one, and each refund keeps its own key there.
	 *
	 * THE DECLARED LIMITATION, MEASURED 2026-09-02. The correlation callback
	 * looks the key up with `wc_get_orders()`, which searches orders and not
	 * refunds, and then requires what it found to be a `WC_Order`; a
	 * `WC_Order_Refund` is neither. So a callback carrying the key of a REFUND
	 * finds nothing today and associates nothing, which is the documented "no
	 * association without verification" behaviour rather than a wrong one. It is
	 * moot in practice for now: no sender exists at all, because the published
	 * invoice representation does not emit the idempotency key. Whoever closes
	 * that end has to widen the lookup to refunds; writing the key now is what
	 * makes closing it possible instead of requiring every past refund to be
	 * re-derived.
	 *
	 * Nothing about this is allowed to affect the capture: it runs AFTER the
	 * entry is in the outbox, and it writes nothing when there is no store
	 * identifier configured yet or when the key it would write is already there.
	 *
	 * @param int    $refund_id Identifier of the refund.
	 * @param string $event_id  Identity composed for this refund.
	 * @return void
	 */
	private function remember_idempotency_key( int $refund_id, string $event_id ): void {
		$store_id = trim( (string) get_option( Constants::OPTION_STORE_ID, '' ) );

		if ( '' === $store_id ) {
			return;
		}

		$refund = wc_get_order( $refund_id );

		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$key = EventIdentity::idempotency_key( $store_id, $event_id );

		if ( $key === (string) $refund->get_meta( Constants::IDEMPOTENCY_KEY_META_KEY ) ) {
			return;
		}

		$refund->update_meta_data( Constants::IDEMPOTENCY_KEY_META_KEY, $key );
		$refund->save();
	}

	/**
	 * Records a problem where the merchant and their host can find it.
	 *
	 * WooCommerce's own logger and not `error_log()`: it writes into the shop's
	 * log screen, which is where a merchant is told to look and where a support
	 * request is answered from, and it honours the shop's log retention instead
	 * of filling a server file nobody rotates.
	 *
	 * The two guards are not defensive noise. This runs on a WooCommerce hook,
	 * so WooCommerce is loaded - but the logger is replaceable by a filter, and a
	 * shop that replaced it with something that does not answer `error()` would
	 * otherwise get a fatal error inside a refund, which is the exact outcome
	 * every path of this class is written to avoid. Losing a log line is the
	 * cheap failure.
	 *
	 * Nothing passed here may contain a credential; see `Exception\PluginException`.
	 *
	 * @param string $message What went wrong, in English, with a next step and no credential.
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

	/**
	 * The builder, built on first use.
	 *
	 * @return OrderPayloadBuilder
	 */
	private function payload_builder(): OrderPayloadBuilder {
		if ( null === $this->payload_builder ) {
			$this->payload_builder = new OrderPayloadBuilder();
		}

		return $this->payload_builder;
	}

	/**
	 * The outbox, built on first use.
	 *
	 * @return OutboxRepository
	 */
	private function outbox(): OutboxRepository {
		if ( null === $this->outbox ) {
			$this->outbox = new OutboxRepository();
		}

		return $this->outbox;
	}

	/**
	 * A positive integer identifier, or null when the value is not one.
	 *
	 * A string of digits is accepted because a hook fired by other code may pass
	 * one. Anything else - a float, a string with separators, zero, a negative -
	 * is refused with null rather than cast, because casting collapses every
	 * unusable value onto 0 and 0 would compose the identity of a refund that
	 * does not exist, identically for every one of them.
	 *
	 * @param mixed $value Value as the hook passed it.
	 * @return int|null
	 */
	private static function identifier( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$identifier = (int) $value;

			return $identifier > 0 ? $identifier : null;
		}

		return null;
	}
}
