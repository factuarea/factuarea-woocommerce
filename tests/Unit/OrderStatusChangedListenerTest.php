<?php
/**
 * Unit tests for the order status transition listener.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Factuarea\WooCommerce\Events\OrderStatusChangedListener;
use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Payload\OrderPayloadBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What this file pins down, and why each case earns its place.
 *
 * The three facts the phase asks for are here, and each of them is the answer to
 * a failure that costs money rather than a box to tick:
 *
 *  1. A transition into a paid status records the SAME identity the payment hook
 *     records, so an order seen by both routes collapses into ONE outbox entry.
 *     The two capture routes exist because a shop taking bank transfers never
 *     fires a payment hook, and the collapse is what stops a shop that fires
 *     both from invoicing one sale twice - in a consecutive series registered
 *     with the tax authority, where the only cure is a second document.
 *  2. A transition with no billing effect records NOTHING. Not an entry with a
 *     harmless type, not a body built and thrown away: nothing, and no order
 *     body is even asked for.
 *  3. A transition into refunded records one entry per refund, with the identity
 *     the refund listener composes for the same refund, and with the body of the
 *     ORDER - which is where the receiver reads the refunds from.
 *  4. A refund this plugin created from an INBOUND notice is recorded by NOBODY.
 *     A total refund changes the order status, so it arrives here as well as at
 *     the refund listener, and sending it back would ask the platform to correct
 *     an invoice it has already corrected - a second corrective document in a
 *     series filed with the tax authority, which is not deleted but corrected
 *     with a third one, weeks later, by hand.
 *
 * The identity is never spelled out as a literal in this file. Every assertion
 * asks `EventIdentity` for it, which is the single place all three capture
 * routes compose it: a test that hard-coded the string would keep passing on the
 * day the routes stopped agreeing, which is precisely the day it matters. What
 * IS spelled out, in the sweep at the end, is that the listener does not compose
 * an identity of its own.
 *
 * The outbox double behaves like the table rather than merely recording calls -
 * a second record of one event leaves the first row untouched, exactly as the
 * UNIQUE constraint on `event_id` leaves it - because the collapse is the thing
 * under test and a double that only counted calls could not show it happening.
 */
final class OrderStatusChangedListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Address the store publishes, which is what the source of an entry has to
	 * carry.
	 */
	private const STORE_ORIGIN = 'https://shop.example';

	/**
	 * Order identifier every case works with.
	 */
	private const ORDER_ID = 4271;

	/**
	 * The bytes the payload builder hands back, stood in for by a marker that
	 * could not be produced by accident.
	 *
	 * What the body CONTAINS is the subject of the payload builder's own tests.
	 * What matters here is that whatever it hands back reaches the outbox
	 * unchanged: the signature covers bytes, so a listener that re-encoded the
	 * body on the way would have every delivery rejected for a bad signature
	 * with nothing failing locally.
	 */
	private const RAW_BODY = '{"id":4271,"status":"processing","refunds":[]}';

	/**
	 * Path of the file the sweep reads, relative to the repository root.
	 */
	private const SOURCE_RELATIVE_PATH = 'src/Events/OrderStatusChangedListener.php';

	/**
	 * What the anti-loop mark holds on a refund this plugin created from an
	 * inbound notice: the idempotency key of the notice that produced it.
	 *
	 * The VALUE is never read by this listener - only its presence is - and that
	 * is on purpose: the listener has no way to tell a well-formed key from a
	 * malformed one, and a mark it refused to believe would put the loop back.
	 */
	private const INBOUND_MARK = 'shop-7a1c:refund:9102';

	/**
	 * Rows the outbox holds, keyed by event identity so that a repeat of the
	 * same event collapses exactly as the UNIQUE constraint collapses it.
	 *
	 * @var array<string, array{event_id: string, order_id: int, event_type: string, raw_body: string, source: string}>
	 */
	private array $rows = array();

	/**
	 * Every call the outbox received, collapsed or not.
	 *
	 * Kept apart from the rows because the collapse is the thing under test: a
	 * listener that asked the outbox first and then skipped the insert would
	 * leave one row too, and only the call count tells the two apart.
	 *
	 * @var array<int, string>
	 */
	private array $enqueue_calls = array();

	/**
	 * Order identifiers the payload builder was asked to build a body for.
	 *
	 * @var array<int, int>
	 */
	private array $build_calls = array();

	/**
	 * What the payload builder throws instead of building, when a case asks it
	 * to.
	 *
	 * @var OrderPayloadNotBuildableException|null
	 */
	private ?OrderPayloadNotBuildableException $build_failure = null;

	/**
	 * Order doubles the double of `wc_get_order` hands back, keyed by
	 * identifier.
	 *
	 * @var array<int, object>
	 */
	private array $orders = array();

	/**
	 * What the logger was told, in the order it was told.
	 *
	 * @var array<int, array{message: string, context: array<string, mixed>}>
	 */
	private array $logged = array();

	/**
	 * Installs the doubles every case shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->rows          = array();
		$this->enqueue_calls = array();
		$this->build_calls   = array();
		$this->build_failure = null;
		$this->orders        = array();
		$this->logged        = array();

		$this->orders[ self::ORDER_ID ] = $this->order_double( array() );

		Functions\when( 'home_url' )->justReturn( self::STORE_ORIGIN );

		Functions\when( 'wc_get_order' )->alias(
			function ( $order_id ) {
				return $this->orders[ (int) $order_id ] ?? false;
			}
		);

		Functions\when( 'wc_get_logger' )->alias(
			function () {
				return $this->logger_double();
			}
		);
	}

	/**
	 * Removes the doubles and verifies the expectations set on them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The listener subscribes to the transition hook asking for all four
	 * arguments.
	 *
	 * The fourth is the order object, and it is what lets a refunded transition
	 * enumerate the refunds without reading the order again. A subscription that
	 * accepted one argument would receive only the identifier, and WordPress
	 * would hand the rest as missing rather than as an error.
	 *
	 * @return void
	 */
	public function test_register_subscribes_to_the_transition_hook_with_the_four_arguments(): void {
		$registered = array();

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted = 1 ) use ( &$registered ): bool {
				$registered[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
					'accepted' => $accepted,
				);

				return true;
			}
		);

		$listener = $this->listener();

		$listener->register();

		$this->assertCount( 1, $registered, 'register() has to subscribe exactly once: a second subscription would record every transition twice.' );
		$this->assertSame( 'woocommerce_order_status_changed', $registered[0]['hook'] );
		$this->assertSame( 10, $registered[0]['priority'] );
		$this->assertSame(
			4,
			$registered[0]['accepted'],
			'The subscription has to accept four arguments. With fewer, the order object never arrives and a refunded transition cannot enumerate the refunds it has to record.'
		);
		$this->assertSame( array( $listener, 'handle' ), $registered[0]['callback'] );
	}

	/**
	 * A transition into a status the receiver bills records the paid identity,
	 * with the body, the source and the type the outbox expects.
	 *
	 * @param string $status Status the order moved into.
	 * @return void
	 */
	#[DataProvider( 'paid_statuses' )]
	public function test_a_transition_into_a_paid_status_records_the_paid_identity( string $status ): void {
		$this->listener()->handle( self::ORDER_ID, 'on-hold', $status, $this->orders[ self::ORDER_ID ] );

		$identity = EventIdentity::for_paid_order( self::ORDER_ID );

		$this->assertSame( array( $identity ), array_keys( $this->rows ), sprintf( 'A transition into "%s" is a sale for the receiver and has to be recorded.', $status ) );
		$this->assertSame( self::ORDER_ID, $this->rows[ $identity ]['order_id'] );
		$this->assertSame( 'order.paid', $this->rows[ $identity ]['event_type'] );
		$this->assertSame(
			self::STORE_ORIGIN,
			$this->rows[ $identity ]['source'],
			'The source has to be the address the store publishes, unchanged: the dispatcher compares it against that same value before it delivers anything.'
		);
		$this->assertSame(
			self::RAW_BODY,
			$this->rows[ $identity ]['raw_body'],
			'The body has to reach the outbox as the builder produced it. The signature covers bytes, so a re-encode anywhere between here and the wire invalidates it with no local error.'
		);
		$this->assertSame( array( self::ORDER_ID ), $this->build_calls, 'The body has to be built for the order that changed status.' );
	}

	/**
	 * The statuses the receiver reads as a sale.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function paid_statuses(): array {
		return array(
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
		);
	}

	/**
	 * The identity of a paid order is the one BOTH capture routes compose, so an
	 * order seen by the payment hook and by the transition collapses into one
	 * entry.
	 *
	 * The payment listener is not driven here, and it does not need to be: what
	 * makes the two converge is that neither of them composes an identity of its
	 * own, and the value below is asked for exactly the way that listener asks
	 * for it. The second record is that listener's, played by hand against the
	 * same outbox.
	 *
	 * @return void
	 */
	public function test_the_two_capture_routes_of_a_paid_order_collapse_into_one_entry(): void {
		$outbox   = $this->outbox_double();
		$listener = $this->listener( $outbox );
		$identity = EventIdentity::for_paid_order( self::ORDER_ID );

		$listener->handle( self::ORDER_ID, 'pending', 'processing', $this->orders[ self::ORDER_ID ] );

		// What the payment-complete capture does with the same order: the same
		// identity, composed in the same single place.
		$outbox->enqueue( $identity, self::ORDER_ID, 'order.paid', self::RAW_BODY, self::STORE_ORIGIN );

		$this->assertSame(
			array( $identity, $identity ),
			$this->enqueue_calls,
			'Both routes have to reach the outbox. A listener that looked first and skipped would leave one row too, and would lose the race between two hooks that fire microseconds apart in the same checkout.'
		);
		$this->assertCount(
			1,
			$this->rows,
			'The two records have to collapse into one entry on the identity, which is what stops one sale becoming two invoices.'
		);
	}

	/**
	 * A transition with no billing effect records nothing at all, and does not
	 * even ask for the order body.
	 *
	 * Building the body dispatches an internal REST request. Doing it for a
	 * transition that will be discarded puts that cost inside every order save
	 * of the shop, for nothing.
	 *
	 * @param string $status Status the order moved into.
	 * @return void
	 */
	#[DataProvider( 'statuses_without_billing_effect' )]
	public function test_a_transition_without_billing_effect_records_nothing( string $status ): void {
		$this->listener()->handle( self::ORDER_ID, 'processing', $status, $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array(),
			$this->rows,
			sprintf(
				'A transition into "%s" produces no document at the receiver, so nothing may be recorded for it. Recording it anyway spends a delivery, and on a status outside the receiver\'s closed catalogue it lands in the merchant error tray.',
				$status
			)
		);
		$this->assertSame( array(), $this->build_calls, 'No order body may be built for a transition that is not going to be recorded.' );
	}

	/**
	 * Statuses that reach no document at the receiver.
	 *
	 * The first five are the ones its closed catalogue maps to "cancelled" or to
	 * "updated"; the last two are outside that catalogue altogether - the draft
	 * of an unfinished block checkout, and a status some third-party plugin
	 * registered - and those are the ones a rule shaped as a pattern would have
	 * swept up.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function statuses_without_billing_effect(): array {
		return array(
			'pending'                => array( 'pending' ),
			'on-hold'                => array( 'on-hold' ),
			'cancelled'              => array( 'cancelled' ),
			'failed'                 => array( 'failed' ),
			'trash'                  => array( 'trash' ),
			'checkout draft'         => array( 'checkout-draft' ),
			'registered by a plugin' => array( 'awaiting-shipment' ),
		);
	}

	/**
	 * A transition into refunded records one entry per refund of the order, each
	 * under the identity of ITS refund.
	 *
	 * @return void
	 */
	public function test_a_transition_into_refunded_records_one_entry_per_refund(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9101, 9102 ) );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array( EventIdentity::for_refund( 9101 ), EventIdentity::for_refund( 9102 ) ),
			array_keys( $this->rows ),
			'The receiver issues one corrective document per refund and identifies each by the REFUND, so the outbox has to carry one entry per refund and not one per order.'
		);

		foreach ( $this->rows as $row ) {
			$this->assertSame( 'order.refunded', $row['event_type'] );
			$this->assertSame( self::ORDER_ID, $row['order_id'] );
			$this->assertSame(
				self::RAW_BODY,
				$row['raw_body'],
				'Every refund entry carries the body of the ORDER, refunds included: that is the document the receiver reads its refunds from, and the payload builder takes an order identifier and would refuse a refund.'
			);
		}
	}

	/**
	 * The order body is built ONCE for the whole transition, however many
	 * refunds it records.
	 *
	 * Building it dispatches an internal REST request, and doing that once per
	 * refund would put four dispatches inside the request that is saving one
	 * order.
	 *
	 * @return void
	 */
	public function test_the_order_body_is_built_once_for_all_the_refunds(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9101, 9102, 9103 ) );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertCount( 3, $this->rows );
		$this->assertSame(
			array( self::ORDER_ID ),
			$this->build_calls,
			'The body is one document for the whole order, so it has to be built once and shared by every entry of the transition.'
		);
	}

	/**
	 * A refund seen by the transition and by the refund hook collapses into one
	 * entry, for the same reason a paid order does.
	 *
	 * @return void
	 */
	public function test_a_refund_seen_by_both_capture_routes_collapses_into_one_entry(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9101 ) );

		$outbox   = $this->outbox_double();
		$listener = $this->listener( $outbox );
		$identity = EventIdentity::for_refund( 9101 );

		$listener->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		// What the refund capture does for the same refund.
		$outbox->enqueue( $identity, self::ORDER_ID, 'order.refunded', self::RAW_BODY, self::STORE_ORIGIN );

		$this->assertSame( array( $identity, $identity ), $this->enqueue_calls );
		$this->assertSame( array( $identity ), array_keys( $this->rows ) );
	}

	/**
	 * An order set to refunded with no refund recorded against it still records
	 * one entry, under the identity the receiver derives for that body.
	 *
	 * A merchant can flip the status by hand without giving any money back. The
	 * body then carries an empty list of refunds, and the receiver falls back to
	 * declaring a TOTAL refund identified by the ORDER, because that is the only
	 * identifier such a body has. Composing the same identity here is what makes
	 * the two sides agree; recording nothing would leave a fully refunded order
	 * without its corrective document, which nobody would notice.
	 *
	 * @return void
	 */
	public function test_a_refunded_order_with_no_recorded_refund_records_the_identity_the_receiver_derives(): void {
		$identity = EventIdentity::for_refund( self::ORDER_ID );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame( array( $identity ), array_keys( $this->rows ) );
		$this->assertSame( 'order.refunded', $this->rows[ $identity ]['event_type'] );
	}

	/**
	 * A refunded transition whose only refund came from an inbound notice records
	 * NOTHING - and, in particular, does not fall back to the total identity.
	 *
	 * This is the case the guard exists for, and it is also the case a careless
	 * guard gets wrong. Filtering the marked refund out leaves the list of
	 * identities empty, and the method already has a fallback for an empty list:
	 * the TOTAL refund of the order. Letting that fallback fire here would send
	 * back, under a different identity, the very refund that was just filtered
	 * out - so the loop would still close, only now disguised as a different
	 * event. The receiver would then correct an invoice it has already corrected,
	 * in a consecutive series filed with the tax authority.
	 *
	 * The fallback has a meaning, and the meaning does not hold here: the
	 * receiver derives a total refund from a body whose `refunds[]` is EMPTY, and
	 * the body of this order carries the inbound refund. There is nothing for the
	 * two sides to agree about.
	 *
	 * @return void
	 */
	public function test_a_refunded_order_whose_only_refund_came_from_an_inbound_notice_records_nothing(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9102 ), array( 9102 => self::INBOUND_MARK ) );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array(),
			$this->rows,
			'A refund this plugin created from an inbound notice may not be sent back. The platform corrected the invoice already; sending it back asks for a second corrective document over the same sale.'
		);
		$this->assertArrayNotHasKey(
			EventIdentity::for_refund( self::ORDER_ID ),
			$this->rows,
			'The total-refund fallback may not fire here. It exists for an order that records NO refund, whose body reaches the receiver with an empty refunds[]; this order records one, so the fallback would be the inbound refund coming back under another name.'
		);
		$this->assertSame(
			array(),
			$this->build_calls,
			'The guard has to run BEFORE the body is built: building it dispatches an internal REST request, and doing it to throw the result away puts that cost inside the request that is applying the refund.'
		);
	}

	/**
	 * An order carrying refunds of both kinds records the merchant's ones, in
	 * their order, and none of the inbound ones.
	 *
	 * The mixed order is the realistic one and the one that tells a guard from a
	 * blanket rule: a merchant who refunds a second item after the platform
	 * published the first refund has to keep getting their corrective document.
	 * Skipping the whole transition because one of its refunds is marked would
	 * lose money back with no document, which is the silence this package exists
	 * to remove.
	 *
	 * @return void
	 */
	public function test_an_order_with_refunds_of_both_kinds_records_only_the_ones_the_merchant_made(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double(
			array( 9101, 9102, 9103 ),
			array( 9102 => self::INBOUND_MARK )
		);

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array( EventIdentity::for_refund( 9101 ), EventIdentity::for_refund( 9103 ) ),
			array_keys( $this->rows ),
			'Only the refunds the merchant made travel, and they travel in the order the order lists them. The inbound one is invisible to this listener, and the two around it are not affected by it being there.'
		);
		$this->assertSame(
			array( self::ORDER_ID ),
			$this->build_calls,
			'One body for the transition, built once, exactly as without the guard.'
		);
	}

	/**
	 * The mark is read by its PRESENCE and not by whether it looks true.
	 *
	 * `'0'` is falsy in PHP, so a guard written with `empty()` or with a bare
	 * truth test would declare a marked refund unmarked and send it back. The
	 * mark holds the idempotency key of the notice that created the refund, and
	 * this listener is in no position to judge its shape: any value it finds
	 * there was written by the inbound half of this same plugin.
	 *
	 * @return void
	 */
	public function test_a_mark_that_looks_falsy_still_stops_the_refund(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9102 ), array( 9102 => '0' ) );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array(),
			$this->rows,
			'A mark of "0" is a mark. Reading it with empty() or with a truth test reopens the loop for every refund whose key happens to be falsy.'
		);
	}

	/**
	 * A mark that is not a scalar still stops the refund, and reading it casts
	 * nothing.
	 *
	 * It cannot be written by this plugin - the inbound half stores the
	 * idempotency key, a string - so it can only appear when something else
	 * writes under our key. Which way to be wrong about it is a decision and not
	 * an accident: casting an array to a string would raise a PHP warning inside
	 * the request that is saving a merchant's order, and treating the value as
	 * absent would send back a refund that may well be ours. The corrective
	 * document that has to be reissued is visible; the duplicated one, filed in
	 * a series registered with the tax authority, is found weeks later.
	 *
	 * @return void
	 */
	public function test_a_mark_that_is_not_a_scalar_still_stops_the_refund(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double(
			array( 9102 ),
			array( 9102 => array( 'key' => self::INBOUND_MARK ) )
		);

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array(),
			$this->rows,
			'A value of any shape under the anti-loop key is a mark. Casting it to compare would also raise an array-to-string warning in the request that saves the order.'
		);
	}

	/**
	 * The listener reads the mark under the frozen constant, never under a
	 * literal of its own.
	 *
	 * The key is written by `Inbound\RefundApplier` and read by BOTH captors of
	 * this plugin. Three copies of one string is three chances for one of them to
	 * drift, and the drift does not fail: the reader simply finds nothing, the
	 * refund looks like the merchant's, and the loop reopens silently.
	 *
	 * @return void
	 */
	public function test_the_anti_loop_mark_is_read_under_the_frozen_constant(): void {
		$code = self::source_without_comments();

		$this->assertStringContainsString(
			'Constants::INBOUND_REFUND_META_KEY',
			$code,
			'The anti-loop mark has to be asked of Constants, which is where the inbound half writes it.'
		);
		$this->assertStringNotContainsString(
			"'_factuarea_inbound_refund'",
			$code,
			'The key of the mark may not be spelled out here. A literal keeps working until the day the constant changes, and then it stops matching without failing anything.'
		);
	}

	/**
	 * With no order object handed over - which third-party code firing this
	 * action does - the order is read and the refunds are recorded all the same.
	 *
	 * @return void
	 */
	public function test_the_refunds_are_recorded_when_the_hook_hands_over_no_order_object(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9101 ) );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded' );

		$this->assertSame( array( EventIdentity::for_refund( 9101 ) ), array_keys( $this->rows ) );
	}

	/**
	 * A status that still carries the prefix WordPress stores it under is
	 * recognised.
	 *
	 * WooCommerce strips it before firing the transition, so this never arrives
	 * from core. The action is public, though, and code that fires it by hand
	 * with the stored form would otherwise match nothing and lose the sale in
	 * silence, which is the one failure this listener may not have.
	 *
	 * @return void
	 */
	public function test_a_status_that_still_carries_the_storage_prefix_is_recognised(): void {
		$this->listener()->handle( self::ORDER_ID, 'wc-pending', 'wc-completed', $this->orders[ self::ORDER_ID ] );

		$this->assertSame( array( EventIdentity::for_paid_order( self::ORDER_ID ) ), array_keys( $this->rows ) );
	}

	/**
	 * Nothing is recorded for something that is not an order identifier.
	 *
	 * @return void
	 */
	public function test_nothing_is_recorded_without_an_order_identifier(): void {
		$this->listener()->handle( 0, 'pending', 'processing', null );

		$this->assertSame( array(), $this->rows );
		$this->assertSame( array(), $this->build_calls );
	}

	/**
	 * A body that cannot be built does not break the status transition: nothing
	 * is recorded, nothing is thrown, and the reason is written where the
	 * merchant can find it.
	 *
	 * A fatal error here would leave the merchant unable to save an order, which
	 * is far worse than a sale that has to be resent.
	 *
	 * @return void
	 */
	public function test_a_body_that_cannot_be_built_does_not_break_the_transition(): void {
		$this->build_failure = OrderPayloadNotBuildableException::order_not_found( self::ORDER_ID );

		$this->listener()->handle( self::ORDER_ID, 'pending', 'processing', $this->orders[ self::ORDER_ID ] );

		$this->assertSame(
			array(),
			$this->rows,
			'Nothing may be recorded when there is no body to deliver: an entry with no body would be retried eight times against an order that is not coming back.'
		);
		$this->assertCount(
			1,
			$this->logged,
			'The reason has to be reported. A capture that swallows its own failure is the silence this package exists to remove.'
		);
		$this->assertStringContainsString( (string) self::ORDER_ID, $this->logged[0]['message'] );
		$this->assertSame( array( 'source' => Constants::PLUGIN_SLUG ), $this->logged[0]['context'] );
	}

	/**
	 * A refunded transition whose body cannot be built records none of its
	 * refunds, rather than some of them.
	 *
	 * @return void
	 */
	public function test_a_refunded_transition_records_nothing_when_the_body_cannot_be_built(): void {
		$this->orders[ self::ORDER_ID ] = $this->order_double( array( 9101, 9102 ) );
		$this->build_failure            = OrderPayloadNotBuildableException::order_not_found( self::ORDER_ID );

		$this->listener()->handle( self::ORDER_ID, 'processing', 'refunded', $this->orders[ self::ORDER_ID ] );

		$this->assertSame( array(), $this->rows );
		$this->assertCount( 1, $this->logged, 'One failure of one transition is one report, not one per refund.' );
	}

	/**
	 * The listener never composes an identity of its own, and no delivery
	 * artefact reaches it.
	 *
	 * This is the sweep that keeps the convergence of the three capture routes
	 * true. Neither of the two ways of breaking it fails at runtime: an identity
	 * built here with the same shape would pass every case above and drift the
	 * day the shape changed, and an identity that mixed in a delivery identifier
	 * would make the SECOND attempt of one notification look like a new sale and
	 * produce a second invoice.
	 *
	 * It reads TOKENS with comments removed, because the documentation of the
	 * file has to stay free to name the forbidden things - which is exactly what
	 * it does - without the sweep mistaking an explanation for the code it
	 * explains.
	 *
	 * @return void
	 */
	public function test_the_listener_composes_no_identity_and_names_no_delivery_artefact(): void {
		$code = self::source_without_comments();

		foreach ( array( 'woocommerce:', 'Delivery-ID', 'X-WC-Webhook', 'delivery_id' ) as $forbidden ) {
			$this->assertStringNotContainsStringIgnoringCase(
				$forbidden,
				$code,
				sprintf(
					'"%s" appears in the code of the listener. The identity of an event is composed in EventIdentity and nowhere else, out of the domain object and out of nothing that belongs to the delivery: an identity built here would stop matching the one the payment and refund captures build, and one that borrowed a delivery identifier would invoice the same sale again on every retry.',
					$forbidden
				)
			);
		}

		$this->assertStringContainsString(
			'EventIdentity::for_paid_order',
			$code,
			'The paid identity has to be asked for, not built.'
		);
		$this->assertStringContainsString(
			'EventIdentity::for_refund',
			$code,
			'The refund identity has to be asked for, not built.'
		);
	}

	/**
	 * The listener writes nothing on the order.
	 *
	 * The idempotency key that the correlation callback later looks an order up
	 * by is written by the paid capture, ONCE, under a single meta key. A second
	 * writer here would overwrite it with the key of a refund and leave the
	 * invoice of that order unreachable - so the order doubles of this file
	 * answer for their refunds and for nothing else, and a listener that tried
	 * to write would fail on an unexpected call rather than pass quietly.
	 *
	 * @return void
	 */
	public function test_the_listener_writes_nothing_on_the_order(): void {
		$code = self::source_without_comments();

		foreach ( array( 'update_meta_data', 'IDEMPOTENCY_KEY_META_KEY', '->save(' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$code,
				sprintf( '"%s" appears in the code of the listener, which only records events. Writing on the order from here would overwrite what the paid capture writes.', $forbidden )
			);
		}
	}

	/**
	 * The catalogue of statuses that matter is the receiver's, enumerated one at
	 * a time.
	 *
	 * Measured in the monorepo on 2026-09-02, in
	 * `WooCommerceOrderStatus::isPaid()` - true for `processing` and `completed`
	 * and for nothing else - and in `isRefunded()`, true only for `refunded`.
	 * The point of pinning it here is that changing either list becomes a
	 * deliberate act with a red test in front of it, instead of a line somebody
	 * widened while adding an unrelated status.
	 *
	 * @return void
	 */
	public function test_the_catalogue_of_relevant_statuses_is_the_one_measured_at_the_receiver(): void {
		$this->assertSame(
			array( 'processing', 'completed' ),
			OrderStatusChangedListener::PAID_STATUSES,
			'These are the two statuses the receiver reads as a sale. Adding one delivers orders whose money has not arrived; removing one leaves real sales uninvoiced. Read them at the receiver before changing this.'
		);
		$this->assertSame( 'refunded', OrderStatusChangedListener::REFUNDED_STATUS );
	}

	/**
	 * The listener under test, on doubles of its two collaborators.
	 *
	 * @param OutboxRepository|null $outbox Outbox to record into, or null for a fresh double.
	 * @return OrderStatusChangedListener
	 */
	private function listener( ?OutboxRepository $outbox = null ): OrderStatusChangedListener {
		return new OrderStatusChangedListener( $this->payload_builder_double(), $outbox ?? $this->outbox_double() );
	}

	/**
	 * A payload builder double that records what it was asked for and hands back
	 * the marker body, or throws when the case asked it to.
	 *
	 * @return OrderPayloadBuilder
	 */
	private function payload_builder_double(): OrderPayloadBuilder {
		$this->refuse_final( OrderPayloadBuilder::class, 'The transition listener receives the payload builder by constructor so that this suite can drive it without a WordPress REST stack.' );

		$builder = Mockery::mock( OrderPayloadBuilder::class );

		$builder->shouldReceive( 'build' )->andReturnUsing(
			function ( int $order_id ): string {
				$this->build_calls[] = $order_id;

				if ( null !== $this->build_failure ) {
					throw $this->build_failure;
				}

				return self::RAW_BODY;
			}
		);

		return $builder;
	}

	/**
	 * An outbox double that behaves like the table: an event already recorded
	 * does not change the row that is there.
	 *
	 * @return OutboxRepository
	 */
	private function outbox_double(): OutboxRepository {
		$this->refuse_final( OutboxRepository::class, 'The transition listener receives the outbox by constructor, and the settings screen doubles it too.' );

		$outbox = Mockery::mock( OutboxRepository::class );

		$outbox->shouldReceive( 'enqueue' )->andReturnUsing(
			function ( string $event_id, int $order_id, string $event_type, string $raw_body, string $source ): void {
				$this->enqueue_calls[] = $event_id;

				if ( isset( $this->rows[ $event_id ] ) ) {
					return;
				}

				$this->rows[ $event_id ] = array(
					'event_id'   => $event_id,
					'order_id'   => $order_id,
					'event_type' => $event_type,
					'raw_body'   => $raw_body,
					'source'     => $source,
				);
			}
		);

		return $outbox;
	}

	/**
	 * Fails with an explanation rather than with a Mockery error when a
	 * collaborator has been made final.
	 *
	 * @param string $class_name Class this suite needs to double.
	 * @param string $reason     Why it is received by constructor.
	 * @return void
	 */
	private function refuse_final( string $class_name, string $reason ): void {
		if ( ( new ReflectionClass( $class_name ) )->isFinal() ) {
			$this->fail(
				sprintf(
					'%s is declared final, so this suite cannot double it. %s Drop the `final` keyword, or give it a small interface this test can double instead.',
					$class_name,
					$reason
				)
			);
		}
	}

	/**
	 * An order double that answers for its refunds and for nothing else.
	 *
	 * Each refund answers `get_meta()` BY KEY rather than handing the same value
	 * back whatever it is asked for. That is what makes the anti-loop cases prove
	 * something: a listener that read the mark under a key of its own invention
	 * would be told the refund is unmarked, would enqueue it, and would fail here
	 * instead of passing and reopening the loop in production.
	 *
	 * @param array<int, int>   $refund_ids Identifiers of the refunds the order carries, in order.
	 * @param array<int, mixed> $inbound    Marks of the refunds this plugin created from an inbound notice, keyed by refund identifier. A refund absent from this map answers the empty string, which is what WooCommerce answers for a key it never wrote - that is, what a refund made by the merchant looks like.
	 * @return object
	 */
	private function order_double( array $refund_ids, array $inbound = array() ) {
		$order = Mockery::mock( 'WC_Order' );

		$refunds = array();

		foreach ( $refund_ids as $refund_id ) {
			$mark = $inbound[ $refund_id ] ?? '';

			$refund = Mockery::mock( 'WC_Order_Refund' );
			$refund->shouldReceive( 'get_id' )->andReturn( $refund_id );
			$refund->shouldReceive( 'get_meta' )->andReturnUsing(
				function ( string $key ) use ( $mark ) {
					return Constants::INBOUND_REFUND_META_KEY === $key ? $mark : '';
				}
			);

			$refunds[] = $refund;
		}

		$order->shouldReceive( 'get_refunds' )->andReturn( $refunds );

		return $order;
	}

	/**
	 * A logger double that keeps what it was told.
	 *
	 * @return object
	 */
	private function logger_double() {
		$logger = Mockery::mock( 'FactuareaOrderStatusChangedLoggerDouble' );

		$logger->shouldReceive( 'error' )->andReturnUsing(
			function ( string $message, array $context = array() ): void {
				$this->logged[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		);

		return $logger;
	}

	/**
	 * The code of the listener with every comment removed.
	 *
	 * @return string
	 */
	private static function source_without_comments(): string {
		$path = dirname( __DIR__, 2 ) . '/' . self::SOURCE_RELATIVE_PATH;

		$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $source ) {
			self::fail( sprintf( 'The listener could not be read at %s, so the sweep proved nothing.', $path ) );
		}

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$code .= $token[1];

				continue;
			}

			$code .= $token;
		}

		return $code;
	}
}
