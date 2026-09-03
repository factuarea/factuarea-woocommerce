<?php
/**
 * Capture of a paid order into the outbox.
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
use Throwable;
use WC_Order;

/**
 * Records a paid order in the outbox, in the same request that took the money.
 *
 * Three steps and nothing else: build the body with the payload builder, derive
 * the identity of the event from the order, write one row. No network call, no
 * signature, no decision about whether the receiver is reachable - all of that
 * belongs to the drain, which runs later and can afford to wait.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS HOOK AND NOT ANOTHER
 * ---------------------------------------------------------------------------
 * `woocommerce_payment_complete` fires IN PROCESS and SYNCHRONOUSLY, inside the
 * request that completed the payment - the gateway callback, the returning
 * buyer, the manual "mark as paid" in the admin. Whatever else happens to that
 * site afterwards, this hook has already run by the time the response is sent.
 *
 * That is the whole reason it is the capture point. The alternative capture
 * points all share one property: they need the site to run something LATER.
 * WordPress's scheduled execution is driven by traffic, so a shop with a quiet
 * night, a page cache in front, `DISABLE_WP_CRON` set and no system timer - a
 * combination that is ordinary rather than exotic - runs nothing scheduled for
 * hours or never. A capture that depended on that would lose the sale, silently,
 * on exactly the shops that are quietest.
 *
 * So the split is: the RECORD does not depend on deferred execution; only the
 * DELIVERY does. A sale that is written here is delivered whenever the drain
 * next runs, and until it does the row sits in the outbox where the stalled
 * outbox notice of the settings screen counts it. Nothing is lost, and nothing
 * is silent.
 *
 * The cost of capturing here is that the work happens while a buyer is waiting,
 * which is why this class does no network. Building the body is a local read of
 * an order; writing the row is one INSERT.
 *
 * ---------------------------------------------------------------------------
 * THE IDENTITY COMES FROM THE ORDER AND NEVER FROM THE DELIVERY
 * ---------------------------------------------------------------------------
 * The identity of the event is `Contract\EventIdentity::for_paid_order()`, which
 * is composed from the order identifier and from nothing else. No delivery
 * identifier, no attempt number, no timestamp, no random value takes part in it,
 * and that prohibition is written in the composer itself, with a sweep in its
 * test that reads the file looking for exactly those.
 *
 * What goes wrong otherwise is not subtle and is not recoverable. A redelivery
 * carries a NEW delivery identifier - WooCommerce numbers every attempt - so an
 * identity taken from there would make the second attempt of one sale look like
 * a second sale, and the receiver would issue a SECOND invoice for it. Under
 * verifiable invoicing an invoice is not deleted, it is corrected with another
 * document, so the merchant's way out of one duplicated attempt is a corrective
 * invoice and an explanation to their customer.
 *
 * This is also why the composition lives in ONE file rather than here. Three
 * listeners see the same facts by three different routes - this one, the status
 * change and the refund - and a paid order that arrives through two of them must
 * collapse into ONE outbox row. They converge because they all ask the same
 * composer, not because three files were written to agree; two of them agreeing
 * today and drifting apart in a later edit is how one sale becomes two invoices.
 *
 * The collapse itself is done by the database: `OutboxRepository::enqueue()`
 * inserts and lets the UNIQUE constraint on the identity absorb the duplicate,
 * with no read in between. This class therefore does not check whether the event
 * is already queued, and must not start: between a check that found nothing and
 * the write that followed it there is a window, and the two hooks that see the
 * same paid order fire microseconds apart in the same checkout.
 *
 * ---------------------------------------------------------------------------
 * IT QUEUES EVEN WHEN DELIVERY IS SWITCHED OFF
 * ---------------------------------------------------------------------------
 * This class never reads `Constants::OPTION_DELIVERY_ENABLED`, deliberately. The
 * outbox is the RECORD of what happened in the shop; the switch decides whether
 * the record is SENT, and it is read by the drain, which is the piece that
 * sends. A merchant who is still filling in the settings screen, or who paused
 * delivery for an afternoon, keeps selling in the meantime - and those sales are
 * the ones that most need to be waiting in the outbox when delivery is switched
 * back on.
 *
 * Reading the switch here would turn "paused" into "lost", and the loss would be
 * invisible: there would be no row, so no notice would count it and no drain
 * would ever find it.
 *
 * ---------------------------------------------------------------------------
 * WHEN THE BODY CANNOT BE BUILT: THE FAILURE IS LOGGED, NOT RAISED
 * ---------------------------------------------------------------------------
 * `OrderPayloadNotBuildableException` is caught here and NOTHING is queued. Two
 * halves, and both of them are decisions:
 *
 *  - It is caught because an exception escaping this hook is a fatal error in
 *    the middle of a payment. The buyer sees a broken page after being charged,
 *    the gateway may retry the callback or mark the payment as failed, and the
 *    order may never reach a complete state. Trading a paid order for a
 *    diagnostic is not a trade worth making, and the diagnostic is available by
 *    other means.
 *  - Nothing is queued because the entry requires a body. The outbox row stores
 *    the exact bytes that will be signed and sent, so there is no marker to
 *    enqueue instead: a row without a body would be a row the drain could only
 *    fail on, forever.
 *
 * THE DECLARED LIMITATION, written here rather than discovered later: the
 * receiver has no reconciliation read for WooCommerce - it pulls nothing back
 * from the shop - so a sale whose body could not be built ends with NO invoice
 * and is visible only in this log line. That is a real hole, it is narrow (the
 * builder fails when the order cannot be read at all), and it is preferred over
 * the alternative, which would be delivering a body this plugin composed by
 * hand: a document the receiver was not built to read would be accepted and
 * normalised wrongly, and a wrong invoice is corrected with a second document
 * rather than deleted.
 *
 * WHAT REACHES THE LOG, AND WHAT NEVER DOES. The line carries the order
 * identifier and the reason. It never carries the body, and that is not
 * incidental: the body holds the buyer's tax identifier and postal address, and
 * a shop's log file is read by support staff, shipped to hosting dashboards and
 * downloaded into tickets. This class also holds no credential - it never reads
 * the signing secret or the API key - so there is nothing of that kind to
 * redact here.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS CLASS IS NOT final
 * ---------------------------------------------------------------------------
 * Same reason, and same precedent, as `Outbox\OutboxRepository`, which is not
 * final because the settings screen's test has to stand in for it.
 * `Payload\OrderPayloadBuilder` IS final, so no double of it can be handed to
 * the constructor: a parameter typed as a final class accepts nothing but that
 * class. The seam is therefore `build_body()`, which the unit test overrides.
 *
 * The alternative would be a unit test that runs the real builder, which needs
 * `WP_REST_Request`, `WP_REST_Response`, `rest_do_request` and `rest_get_server`
 * doubled - stubs that already exist in the builder's own test file, under names
 * aliased into the global namespace. Sharing those across two test files makes
 * each one depend on which file the runner loaded first, and it would mean the
 * listener's test spends most of its assertions on the builder's dispatch chain,
 * which is tested where it belongs.
 *
 * Being open is NOT an extension point. Nothing in this plugin subclasses it,
 * and `build_body()` is protected rather than public so that no caller outside
 * the class can reach it.
 */
class PaymentCompleteListener {

	/**
	 * The hook this listener captures on.
	 *
	 * Public because the main plugin file and the tests both name it, and a hook
	 * name written twice is a hook name that can be wrong in one of the two
	 * places without anything failing.
	 */
	public const HOOK = 'woocommerce_payment_complete';

	/**
	 * Value written into the `event_type` column of the outbox row.
	 *
	 * That column is for the merchant and for whoever reads the queue while
	 * diagnosing something; nothing routes on it. What the receiver acts on is
	 * the identity of the event and the body, and the topic headers of the
	 * delivery are composed by the drain.
	 */
	public const EVENT_TYPE = 'order.paid';

	/**
	 * Priority the capture runs at. The default, on purpose: this listener has
	 * no reason to run before or after anybody else's, and a non-default number
	 * is a claim about other plugins that nobody can check.
	 */
	private const HOOK_PRIORITY = 10;

	/**
	 * Arguments taken from the hook.
	 *
	 * One, although WooCommerce passes two - the order identifier and the
	 * transaction identifier of the gateway. The second one is deliberately not
	 * received: it belongs to the PAYMENT and would be one more thing in scope
	 * of a class whose job is to record that the order was paid.
	 */
	private const HOOK_ACCEPTED_ARGS = 1;

	/**
	 * Source of the delivery body.
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $payload_builder;

	/**
	 * The outbox this listener writes into.
	 *
	 * @var OutboxRepository
	 */
	private OutboxRepository $outbox;

	/**
	 * Builds the listener on its two collaborators.
	 *
	 * Both arguments exist so that a caller can hand in something else; the main
	 * plugin file passes nothing and gets the real pair. Neither default touches
	 * WordPress while being constructed, so `new PaymentCompleteListener()` is
	 * safe from wherever the plugin loads.
	 *
	 * @param OrderPayloadBuilder|null $payload_builder Builder of the delivery body.
	 * @param OutboxRepository|null    $outbox          Outbox to record the event in.
	 */
	public function __construct( ?OrderPayloadBuilder $payload_builder = null, ?OutboxRepository $outbox = null ) {
		$this->payload_builder = $payload_builder ?? new OrderPayloadBuilder();
		$this->outbox          = $outbox ?? new OutboxRepository();
	}

	/**
	 * Hooks the capture up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'capture' ), self::HOOK_PRIORITY, self::HOOK_ACCEPTED_ARGS );
	}

	/**
	 * Records one paid order in the outbox.
	 *
	 * The argument is untyped on purpose, and this is the one place where the
	 * strict typing of this file has to be held back. WooCommerce passes an
	 * integer, but a hook is a public square: a payment plugin firing
	 * `do_action( 'woocommerce_payment_complete', '4711' )` with the identifier
	 * as a string is enough for a declared `int` to raise a TypeError under
	 * `strict_types`, and that TypeError would be an uncaught fatal in the
	 * middle of a payment. So the value is narrowed here instead, and anything
	 * that is not a usable identifier leaves without doing anything.
	 *
	 * @param mixed $order_id Order identifier, as whatever fired the hook passed it.
	 * @return void
	 */
	public function capture( $order_id ): void {
		$order_id = is_scalar( $order_id ) ? (int) $order_id : 0;

		if ( $order_id < 1 ) {
			return;
		}

		try {
			$raw_body = $this->build_body( $order_id );
		} catch ( OrderPayloadNotBuildableException $not_buildable ) {
			$this->log(
				sprintf(
					'Order %1$d was paid but no delivery body could be built for it, so nothing was queued for Factuarea and this sale will not be invoiced. %2$s',
					$order_id,
					$not_buildable->getMessage()
				)
			);

			return;
		}

		$event_id = EventIdentity::for_paid_order( $order_id );

		$this->outbox->enqueue(
			$event_id,
			$order_id,
			self::EVENT_TYPE,
			$raw_body,
			$this->origin()
		);

		$this->remember_idempotency_key( $order_id, $event_id );
	}

	/**
	 * The delivery body of one order.
	 *
	 * The seam. See the note on this class for why it is protected and why the
	 * class is not final.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return string The raw JSON body, exactly as it will be signed and sent.
	 * @throws OrderPayloadNotBuildableException When WooCommerce produces no usable body.
	 */
	protected function build_body( int $order_id ): string {
		return $this->payload_builder->build( $order_id );
	}

	/**
	 * The origin recorded against the entry.
	 *
	 * `home_url()` RAW, with whatever scheme, host, port and path this
	 * installation answers on, and with no normalisation applied here. The drain
	 * normalises both sides when it compares them, and doing it twice - once on
	 * the way in and once on the way out - would mean the guard compares two
	 * values that were tidied by two versions of the same idea.
	 *
	 * @return string Address of this installation.
	 */
	private function origin(): string {
		return (string) home_url();
	}

	/**
	 * Stores against the order the idempotency key the receiver will derive for
	 * this event, so that the correlation callback can find the order later.
	 *
	 * The key is not sent anywhere. It is the digest both sides compute from the
	 * same three parts - provider, store, identity - and it is written here
	 * because it is the only value that joins an order of this shop to an invoice
	 * of Factuarea without anybody guessing from an amount and a date.
	 *
	 * NOTHING IS WRITTEN WHEN THE STORE IDENTIFIER IS NOT CONFIGURED YET, and
	 * that is the point of the guard rather than an economy. A digest derived
	 * from an empty store identifier is not the digest the receiver derives, so
	 * it could never match a callback; what it would do is sit on the order
	 * looking like a correlation key long after the merchant filled the setting
	 * in, and the first person to debug a missing invoice would be reading a
	 * value that was never right.
	 *
	 * The write is skipped when the order already carries this exact key, so the
	 * second hook that sees the same paid order does not cost a second save.
	 *
	 * Everything here is wrapped, because everything here is optional: the event
	 * is ALREADY in the outbox by the time this runs, so the sale is recorded
	 * whatever happens next. Letting a failed order save escape would undo that
	 * - it would break the payment request over a convenience that only matters
	 * once an invoice exists.
	 *
	 * @param int    $order_id WooCommerce order identifier.
	 * @param string $event_id Identity composed for this event.
	 * @return void
	 */
	private function remember_idempotency_key( int $order_id, string $event_id ): void {
		$external_store_id = trim( (string) get_option( Constants::OPTION_STORE_ID, '' ) );

		if ( '' === $external_store_id ) {
			return;
		}

		$idempotency_key = EventIdentity::idempotency_key( $external_store_id, $event_id );

		try {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( (string) $order->get_meta( Constants::IDEMPOTENCY_KEY_META_KEY ) === $idempotency_key ) {
				return;
			}

			$order->update_meta_data( Constants::IDEMPOTENCY_KEY_META_KEY, $idempotency_key );
			$order->save();
		} catch ( Throwable $write_failure ) {
			$this->log(
				sprintf(
					'Order %1$d was queued for Factuarea, but its correlation key could not be stored, so the invoice will not be linked from the order. %2$s',
					$order_id,
					$write_failure->getMessage()
				)
			);
		}
	}

	/**
	 * Writes one line to the WooCommerce log, under this plugin's own source.
	 *
	 * The source is the plugin slug so that the merchant finds these lines under
	 * the plugin's name in WooCommerce's log viewer rather than mixed into the
	 * shop's general log.
	 *
	 * `wc_get_logger()` is called without a guard because this method is only
	 * reachable from a WooCommerce hook, where WooCommerce is loaded by
	 * definition.
	 *
	 * @param string $message Line to write, in English and without any body or credential in it.
	 * @return void
	 */
	private function log( string $message ): void {
		wc_get_logger()->error( $message, array( 'source' => Constants::PLUGIN_SLUG ) );
	}
}
