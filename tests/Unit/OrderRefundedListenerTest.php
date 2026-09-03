<?php
/**
 * Unit test of the refund capture.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Factuarea\WooCommerce\Events\OrderRefundedListener;
use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Payload\OrderPayloadBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case earns its place.
 *
 * The listener is small, and every decision in it is invisible until it costs a
 * corrective invoice. So the cases are chosen by the mistake they catch, not by
 * the method they exercise:
 *
 *  1. TWO REFUNDS OF ONE ORDER ARE TWO ENTRIES WITH TWO IDENTITIES. A partial
 *     refund of 18.72 and another of 10.00 on one order are two facts. An
 *     identity taken from the ORDER would collapse them into one, and the
 *     merchant would give back 28.72 and correct 18.72.
 *
 *  2. THE SAME REFUND SEEN TWICE IS ONE ENTRY. Asserted on the outbox contents
 *     and not on the number of calls, because the collapse is the outbox's job:
 *     the enqueue is an insert that lets a UNIQUE constraint absorb the repeat,
 *     and the double below reproduces exactly that rule - first write wins, and
 *     nothing about the existing row changes. What THIS file is responsible for
 *     is the precondition: both captures present the SAME identity and the same
 *     material. That the database really collapses them is pinned against a real
 *     database in the outbox's own phase.
 *
 *  3. A PARTIAL REFUND IS RECORDED THOUGH THE ORDER STATUS DOES NOT MOVE. This
 *     is the reason this listener exists next to the one that watches status
 *     transitions, and it is asserted twice: once on the behaviour, with an
 *     order sitting in `processing` throughout, and once as a SWEEP over the
 *     source, which fails if this class ever learns to read a status. The sweep
 *     is there because the behaviour case would keep passing after somebody
 *     added "only when the order is refunded", as long as they wrote the fixture
 *     to match.
 *
 *  4. THE BODY IS BUILT FROM THE PARENT ORDER. The refund identifier is a
 *     perfectly valid argument for `wc_get_order()`, which is what makes the
 *     mistake easy to make and impossible to spot by reading: the builder is
 *     handed a refund, refuses it because a refund is not an order, and every
 *     refund of every shop fails with "order not found".
 *
 *  5. THE BYTES REACH THE OUTBOX UNCHANGED, the free-text reason of the refund
 *     included. The receiver maps that reason to a legal ground for the
 *     corrective document or refuses and asks a human; a plugin that tidied it
 *     up on the way past would be editing the evidence for that decision. (That
 *     the reason is in the body at all is the payload builder's guarantee: it
 *     takes WooCommerce's own representation whole. What is asserted here is
 *     that nothing between the builder and the outbox touches it.)
 *
 *  6. A BODY THAT CANNOT BE BUILT BREAKS NOTHING. No exception escapes into
 *     `wc_create_refund()`, nothing is enqueued, and the reason is logged. An
 *     escaping exception would turn a failure of the invoicing integration into
 *     a failure of the merchant's refund - the customer's money not moving.
 *
 *  7. NOT ONE NETWORK CALL HAPPENS INSIDE THE HOOK. The whole point of an
 *     outbox: the delivery belongs to the drain, outside this request.
 *
 * The identity composer is used FOR REAL, because it is the piece that makes the
 * three capture routes converge and doubling it would turn every identity
 * assertion into an assertion about this file's own idea of one. What is doubled
 * is the payload builder, which needs a WordPress REST stack under it and has
 * its own phase, the outbox, which needs a database and has its own too, and the
 * WordPress functions.
 */
final class OrderRefundedListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The order every refund below belongs to.
	 */
	private const ORDER_ID = 4711;

	/**
	 * First refund of that order.
	 */
	private const FIRST_REFUND_ID = 9001;

	/**
	 * Second refund of the same order. A different fact, so a different
	 * identity.
	 */
	private const SECOND_REFUND_ID = 9002;

	/**
	 * Identifier of this shop at the receiver, as the settings hold it.
	 */
	private const STORE_ID = 'str_9f2c41';

	/**
	 * Address of the shop, exactly as `home_url()` reports it.
	 */
	private const SHOP_URL = 'https://shop.example.test';

	/**
	 * Reason of the first refund, exactly as a merchant would type it: their own
	 * language, their own punctuation, accents and all. Nothing about it is a
	 * fiscal code, which is why the case that asserts it survives untouched
	 * matters.
	 */
	private const FIRST_REASON = 'Devuelto por el cliente — talla mal';

	/**
	 * Options as the double of `get_option` sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * What `home_url()` answers.
	 *
	 * @var string
	 */
	private string $home_url = self::SHOP_URL;

	/**
	 * The body the doubled payload builder hands back.
	 *
	 * @var string
	 */
	private string $raw_body = '';

	/**
	 * What the doubled payload builder throws instead of answering, or null when
	 * it answers.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $build_failure = null;

	/**
	 * Identifiers the payload builder was asked to build a body for, in order.
	 *
	 * @var array<int, int>
	 */
	private array $built_for = array();

	/**
	 * Refund objects the doubled `wc_get_order()` knows about, by identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $refunds = array();

	/**
	 * Every enqueue call, in order, whether or not it changed anything.
	 *
	 * @var array<int, array{event_id: string, order_id: int, event_type: string, raw_body: string, source: string}>
	 */
	private array $enqueue_calls = array();

	/**
	 * The outbox contents, keyed by event identity.
	 *
	 * The double writes the first capture of an identity and leaves a repeat
	 * alone, which is what the real table does with `ON DUPLICATE KEY UPDATE id
	 * = id` on its UNIQUE index over the identity.
	 *
	 * @var array<string, array{event_id: string, order_id: int, event_type: string, raw_body: string, source: string}>
	 */
	private array $outbox_rows = array();

	/**
	 * Meta written onto refunds, by refund identifier and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $refund_meta = array();

	/**
	 * Refund identifiers that were saved, in order.
	 *
	 * @var array<int, int>
	 */
	private array $refunds_saved = array();

	/**
	 * The shop logger.
	 *
	 * @var RefundListenerLoggerSpy
	 */
	private RefundListenerLoggerSpy $logger;

	/**
	 * Actions the listener registered.
	 *
	 * @var array<int, array{hook: string, callback: mixed, priority: int, accepted: int}>
	 */
	private array $added_actions = array();

	/**
	 * Installs the doubles of the WordPress functions the listener reaches for.
	 * There are five, and that is the measure of the class: it knows the outbox,
	 * the identity and the shop, and nothing about HTTP.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			Constants::OPTION_STORE_ID => self::STORE_ID,
		);

		$this->home_url      = self::SHOP_URL;
		$this->raw_body      = $this->order_body();
		$this->build_failure = null;
		$this->built_for     = array();
		$this->enqueue_calls = array();
		$this->outbox_rows   = array();
		$this->refund_meta   = array();
		$this->refunds_saved = array();
		$this->added_actions = array();
		$this->logger        = new RefundListenerLoggerSpy();

		$this->refunds = array(
			self::FIRST_REFUND_ID  => $this->refund_double( self::FIRST_REFUND_ID ),
			self::SECOND_REFUND_ID => $this->refund_double( self::SECOND_REFUND_ID ),
		);

		Functions\when( 'get_option' )->alias(
			function ( string $option, $default_value = false ) {
				return $this->options[ $option ] ?? $default_value;
			}
		);

		Functions\when( 'home_url' )->alias(
			function (): string {
				return $this->home_url;
			}
		);

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
				$this->added_actions[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
					'accepted' => $accepted_args,
				);

				return true;
			}
		);

		Functions\when( 'wc_get_logger' )->alias(
			function (): RefundListenerLoggerSpy {
				return $this->logger;
			}
		);

		Functions\when( 'wc_get_order' )->alias(
			function ( $order_id ) {
				return $this->refunds[ (int) $order_id ] ?? false;
			}
		);
	}

	/**
	 * Removes the doubles.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------------

	/**
	 * The capture hangs off the hook that fires once per refund, and it asks for
	 * BOTH arguments.
	 *
	 * The second one is not optional detail: without it WordPress hands the
	 * callback only the order identifier, and every refund of an order would be
	 * given the identity of refund zero - one entry for all of them, and after
	 * the first, none at all.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_refund_creation_asking_for_both_arguments(): void {
		$this->listener()->register();

		$this->assertCount( 1, $this->added_actions, 'The listener registered something other than exactly one action.' );

		$action = $this->added_actions[0];

		$this->assertSame( 'woocommerce_order_refunded', $action['hook'], 'The capture is not on the hook that fires once per refund created.' );
		$this->assertSame( OrderRefundedListener::HOOK, $action['hook'], 'The published hook constant and the hook actually registered have drifted apart.' );
		$this->assertSame( 2, $action['accepted'], 'The callback did not ask for the refund identifier, so every refund would be recorded as refund zero.' );
		$this->assertIsCallable( $action['callback'], 'The registered callback is not callable.' );
	}

	// ---------------------------------------------------------------------
	// One refund, one entry.
	// ---------------------------------------------------------------------

	/**
	 * Two different refunds of the same order produce two entries, with two
	 * identities, each naming its own refund.
	 *
	 * @return void
	 */
	public function test_two_refunds_of_one_order_are_two_entries_with_distinct_identities(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, self::SECOND_REFUND_ID );

		$this->assertCount( 2, $this->outbox_rows, 'Two refunds of one order did not leave two entries in the outbox.' );

		$this->assertSame(
			array(
				'woocommerce:refund:' . self::FIRST_REFUND_ID,
				'woocommerce:refund:' . self::SECOND_REFUND_ID,
			),
			array_keys( $this->outbox_rows ),
			'The two refunds were not recorded under their own identities.'
		);

		foreach ( $this->outbox_rows as $row ) {
			$this->assertSame( self::ORDER_ID, $row['order_id'], 'The entry does not belong to the refunded order.' );
			$this->assertSame( 'order.refunded', $row['event_type'], 'The entry is not typed as a refund, so nobody can tell it apart in the outbox.' );
			$this->assertSame( self::SHOP_URL, $row['source'], 'The entry does not declare the address of this shop, raw, as its origin.' );
		}
	}

	/**
	 * The identity is composed by the shared composer and by nothing else, so
	 * the listener that watches status transitions arrives at the same string
	 * for the same refund and the two collapse.
	 *
	 * @return void
	 */
	public function test_the_identity_is_the_one_the_shared_composer_produces(): void {
		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertArrayHasKey(
			EventIdentity::for_refund( self::FIRST_REFUND_ID ),
			$this->outbox_rows,
			'The identity recorded is not the one the shared composer produces, so the other capture routes will not converge on it.'
		);
	}

	/**
	 * The same refund captured twice leaves ONE entry.
	 *
	 * Both captures happen - the listener does not remember what it has seen,
	 * and it must not: the memory that matters is the UNIQUE index of the outbox
	 * table, which survives a restart and a second PHP process. What is asserted
	 * here is that both present the same material, which is the condition under
	 * which that index collapses them, and that the second capture changes
	 * nothing about the row the first one wrote.
	 *
	 * @return void
	 */
	public function test_the_same_refund_seen_twice_leaves_one_entry(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertCount( 2, $this->enqueue_calls, 'The second capture did not reach the outbox, so the collapse is happening somewhere it cannot be relied on.' );
		$this->assertCount( 1, $this->outbox_rows, 'The same refund seen twice left two entries, which is two corrective documents for one refund.' );

		$this->assertSame(
			$this->enqueue_calls[0],
			$this->enqueue_calls[1],
			'The two captures of one refund did not present identical material, so the unique index would not collapse them.'
		);
	}

	// ---------------------------------------------------------------------
	// The partial refund, which is the reason this listener exists.
	// ---------------------------------------------------------------------

	/**
	 * A partial refund is recorded although the order stays exactly where it
	 * was.
	 *
	 * The body used here is the one WooCommerce produces for that situation:
	 * status `processing`, order total 84.97, one refund of 18.72 inside
	 * `refunds[]`. Nothing in it says "refunded", and that is precisely the case
	 * a status-watching capture cannot see.
	 *
	 * @return void
	 */
	public function test_a_partial_refund_is_recorded_though_the_order_status_does_not_change(): void {
		$this->raw_body = $this->order_body(
			array(
				'status'  => 'processing',
				'total'   => '84.97',
				'refunds' => array(
					array(
						'id'     => self::FIRST_REFUND_ID,
						'reason' => self::FIRST_REASON,
						'total'  => '-18.72',
					),
				),
			)
		);

		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertCount( 1, $this->outbox_rows, 'A partial refund was not recorded, so the sale keeps an invoice for money the merchant gave back.' );

		$body = json_decode( $this->outbox_rows[ EventIdentity::for_refund( self::FIRST_REFUND_ID ) ]['raw_body'], true );

		$this->assertSame( 'processing', $body['status'], 'The order status moved, so this case is no longer the partial refund it was written to cover.' );
		$this->assertSame( '-18.72', $body['refunds'][0]['total'], 'The delivered body does not carry the amount of the partial refund.' );
	}

	/**
	 * The class never reads an order status, and this is asserted on the source
	 * rather than on behaviour.
	 *
	 * The behaviour case above would go on passing if somebody added "only when
	 * the order is refunded" and adjusted the fixture to match, which is exactly
	 * how a plugin ends up blind to partial refunds with a green suite. The
	 * sweep reads the CODE with the comments removed, so the prose of the file -
	 * which has to be free to name what the code must not do - cannot trip it,
	 * and cannot excuse it either.
	 *
	 * @return void
	 */
	public function test_it_never_reads_the_order_status(): void {
		$code = $this->source_without_comments();

		foreach ( array( 'get_status', 'has_status', 'is_paid', 'get_date_paid', 'wc-refunded' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$code,
				sprintf(
					'The refund capture reads the order state through `%s`. A partial refund does not change the status, so every partial refund would stop being recorded.',
					$forbidden
				)
			);
		}
	}

	// ---------------------------------------------------------------------
	// The body.
	// ---------------------------------------------------------------------

	/**
	 * The body is built from the PARENT order, never from the refund.
	 *
	 * @return void
	 */
	public function test_the_body_is_built_from_the_parent_order_and_never_from_the_refund(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, self::SECOND_REFUND_ID );

		$this->assertSame(
			array( self::ORDER_ID, self::ORDER_ID ),
			$this->built_for,
			'The delivery body was built from something other than the parent order. Handed a refund identifier, the builder refuses it and every refund fails with "order not found".'
		);
	}

	/**
	 * The bytes the builder produced reach the outbox untouched, the free-text
	 * reason of the refund included.
	 *
	 * The reason is typed by hand by the merchant, in their language, and the
	 * receiver needs it as written: it maps it to a legal ground for the
	 * corrective document, or refuses and asks a human. Trimming it, folding its
	 * case or translating it here would be editing the evidence for a decision
	 * this plugin is not allowed to take - and any re-encoding at all would also
	 * change the bytes the signature is going to cover.
	 *
	 * @return void
	 */
	public function test_the_bytes_reach_the_outbox_untouched_reason_included(): void {
		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$row = $this->outbox_rows[ EventIdentity::for_refund( self::FIRST_REFUND_ID ) ];

		$this->assertSame( $this->raw_body, $row['raw_body'], 'The body was rewritten between the builder and the outbox, so the signature would cover bytes the receiver never sees.' );

		$body = json_decode( $row['raw_body'], true );

		$this->assertSame( self::FIRST_REASON, $body['refunds'][0]['reason'], 'The reason of the refund was altered on its way into the outbox.' );
		$this->assertSame( '', $body['refunds'][1]['reason'], 'A refund with no reason did not arrive with no reason.' );
	}

	/**
	 * Not one network call happens inside the hook.
	 *
	 * The merchant is refunding a customer in this request. A delivery here
	 * would put the receiver's latency - and its outages - inside that request.
	 *
	 * @return void
	 */
	public function test_it_makes_no_network_call_inside_the_hook(): void {
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_request' )->never();

		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertCount( 1, $this->outbox_rows, 'The refund was not recorded, so this case proved nothing about how it was delivered.' );
	}

	// ---------------------------------------------------------------------
	// Failure never reaches the merchant's refund.
	// ---------------------------------------------------------------------

	/**
	 * An order whose body cannot be built records nothing, logs the reason and
	 * lets the refund finish.
	 *
	 * @return void
	 */
	public function test_a_body_that_cannot_be_built_records_nothing_and_lets_the_refund_finish(): void {
		$this->build_failure = OrderPayloadNotBuildableException::order_not_found( self::ORDER_ID );

		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertSame( array(), $this->enqueue_calls, 'An entry was written with no body, which would be retried eight times against a receiver that can do nothing with it.' );
		$this->assertSame( array(), $this->refunds_saved, 'The refund was written to even though nothing was recorded for it.' );
		$this->assertCount( 1, $this->logger->errors, 'The failure was swallowed without a word, so the merchant has no way of finding out.' );

		$logged = $this->logger->errors[0];

		$this->assertStringContainsString( (string) self::ORDER_ID, $logged['message'], 'The logged failure does not name the order it happened on.' );
		$this->assertSame( Constants::PLUGIN_SLUG, $logged['context']['source'], 'The failure was not filed under this plugin in the shop log.' );
		$this->assertStringNotContainsString( self::STORE_ID, $logged['message'], 'The log line carries configured values that have no business being in it.' );
	}

	/**
	 * A hook fired with something that is not a pair of identifiers records
	 * nothing and raises nothing.
	 *
	 * @return void
	 */
	public function test_an_unusable_identifier_records_nothing(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, 0 );
		$listener->record_refund( 0, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, null );
		$listener->record_refund( self::ORDER_ID, 'not-an-identifier' );

		$this->assertSame( array(), $this->enqueue_calls, 'An unusable identifier produced an entry, and every one of them would carry the identity of refund zero.' );
		$this->assertSame( array(), $this->built_for, 'A body was built for a call that had no usable identifiers.' );
		$this->assertCount( 4, $this->logger->errors, 'The refusals were silent, so nobody can find out why the refunds are not arriving.' );
	}

	/**
	 * A hook fired with the identifiers as strings of digits, which is what
	 * other code re-firing it may well do, works exactly the same.
	 *
	 * @return void
	 */
	public function test_identifiers_arriving_as_strings_are_recorded(): void {
		$this->listener()->record_refund( (string) self::ORDER_ID, (string) self::FIRST_REFUND_ID );

		$this->assertArrayHasKey(
			EventIdentity::for_refund( self::FIRST_REFUND_ID ),
			$this->outbox_rows,
			'Identifiers passed as strings of digits were refused, so a refund created by other code would never be invoiced.'
		);
	}

	// ---------------------------------------------------------------------
	// The idempotency key kept against the refund.
	// ---------------------------------------------------------------------

	/**
	 * The key the receiver will derive for this event is kept on the REFUND, and
	 * never on the parent order, whose own key belongs to its payment.
	 *
	 * @return void
	 */
	public function test_it_keeps_the_idempotency_key_on_the_refund(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, self::SECOND_REFUND_ID );

		foreach ( array( self::FIRST_REFUND_ID, self::SECOND_REFUND_ID ) as $refund_id ) {
			$this->assertSame(
				EventIdentity::idempotency_key( self::STORE_ID, EventIdentity::for_refund( $refund_id ) ),
				$this->refund_meta[ $refund_id ][ Constants::IDEMPOTENCY_KEY_META_KEY ] ?? null,
				'The refund does not carry the key the receiver will derive for it, so its corrective document can never be matched back.'
			);
		}

		$this->assertSame(
			array( self::FIRST_REFUND_ID, self::SECOND_REFUND_ID ),
			$this->refunds_saved,
			'Each refund was not saved exactly once.'
		);
	}

	/**
	 * Capturing the same refund twice writes the key once: the second pass finds
	 * it already there and saves nothing.
	 *
	 * @return void
	 */
	public function test_it_writes_the_idempotency_key_once_for_a_repeated_capture(): void {
		$listener = $this->listener();

		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );
		$listener->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertSame(
			array( self::FIRST_REFUND_ID ),
			$this->refunds_saved,
			'A repeated capture saved the refund again, writing a value that was already there.'
		);
	}

	/**
	 * With no store configured yet there is no key to derive - and the refund is
	 * recorded all the same.
	 *
	 * Recording must not depend on the settings being filled in: the drain
	 * refuses to send while they are missing and leaves the entries where they
	 * are, so a merchant who finishes configuring next week still invoices this
	 * week's refunds. Skipping the capture would lose them for good.
	 *
	 * @return void
	 */
	public function test_a_shop_with_no_store_configured_still_records_the_refund(): void {
		$this->options = array();

		$this->listener()->record_refund( self::ORDER_ID, self::FIRST_REFUND_ID );

		$this->assertCount( 1, $this->outbox_rows, 'The refund went unrecorded because the settings were empty, which loses it for good.' );
		$this->assertSame( array(), $this->refunds_saved, 'A key was written with no store identifier to derive it from.' );
	}

	// ---------------------------------------------------------------------
	// Fixtures and helpers.
	// ---------------------------------------------------------------------

	/**
	 * The listener under test, with a doubled payload builder and a doubled
	 * outbox.
	 *
	 * @return OrderRefundedListener
	 */
	private function listener(): OrderRefundedListener {
		return new OrderRefundedListener( $this->payload_builder(), $this->outbox() );
	}

	/**
	 * The payload builder double, which records what it was asked for and hands
	 * back the body of the moment.
	 *
	 * @return OrderPayloadBuilder
	 */
	private function payload_builder(): OrderPayloadBuilder {
		$builder = Mockery::mock( OrderPayloadBuilder::class );

		$builder->shouldReceive( 'build' )->andReturnUsing(
			function ( int $order_id ): string {
				$this->built_for[] = $order_id;

				if ( null !== $this->build_failure ) {
					throw $this->build_failure;
				}

				return $this->raw_body;
			}
		);

		return $builder;
	}

	/**
	 * The outbox double, which reproduces the one rule of the real table that
	 * matters here: an identity already present is left exactly as it was.
	 *
	 * @return OutboxRepository
	 */
	private function outbox(): OutboxRepository {
		$outbox = Mockery::mock( OutboxRepository::class );

		$outbox->shouldReceive( 'enqueue' )->andReturnUsing(
			function ( string $event_id, int $order_id, string $event_type, string $raw_body, string $source ): void {
				$call = array(
					'event_id'   => $event_id,
					'order_id'   => $order_id,
					'event_type' => $event_type,
					'raw_body'   => $raw_body,
					'source'     => $source,
				);

				$this->enqueue_calls[] = $call;

				if ( isset( $this->outbox_rows[ $event_id ] ) ) {
					return;
				}

				$this->outbox_rows[ $event_id ] = $call;
			}
		);

		return $outbox;
	}

	/**
	 * A refund object, with the three things the listener asks of one.
	 *
	 * @param int $refund_id Identifier of the refund.
	 * @return Mockery\MockInterface
	 */
	private function refund_double( int $refund_id ) {
		$refund = Mockery::mock( 'WC_Order_Refund' );

		$refund->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) use ( $refund_id ) {
				return $this->refund_meta[ $refund_id ][ $key ] ?? '';
			}
		);

		$refund->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( $refund_id ): void {
				$this->refund_meta[ $refund_id ][ $key ] = $value;
			}
		);

		$refund->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $refund_id ): int {
				$this->refunds_saved[] = $refund_id;

				return $refund_id;
			}
		);

		return $refund;
	}

	/**
	 * The body WooCommerce produces for the order, trimmed to the parts that
	 * matter here and keeping the shape of the real one: two refunds inside
	 * `refunds[]`, one with a free-text reason and one without.
	 *
	 * Encoded with the plain encoder rather than through a double of the
	 * WordPress wrapper, because what is wanted is the document that would be on
	 * the wire and not one produced by a stub of this file's own writing.
	 *
	 * @param array<string, mixed> $overrides Values to change.
	 * @return string
	 */
	private function order_body( array $overrides = array() ): string {
		$representation = array_merge(
			array(
				'id'        => self::ORDER_ID,
				'status'    => 'processing',
				'currency'  => 'EUR',
				'total'     => '121.00',
				'meta_data' => array(
					array(
						'id'    => 9,
						'key'   => Constants::TAX_ID_META_KEY,
						'value' => 'B12345674',
					),
				),
				'refunds'   => array(
					array(
						'id'     => self::FIRST_REFUND_ID,
						'reason' => self::FIRST_REASON,
						'total'  => '-18.72',
					),
					array(
						'id'     => self::SECOND_REFUND_ID,
						'reason' => '',
						'total'  => '-10.00',
					),
				),
			),
			$overrides
		);

		return (string) json_encode( $representation ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The document as it would be on the wire, not one a doubled wrapper produced.
	}

	/**
	 * The source of the listener with every comment removed.
	 *
	 * Read with the lexer rather than with a regular expression, for the reason
	 * this repository has already measured elsewhere: the prose of a file has to
	 * be free to name what its code must not do, and a text sweep would find
	 * those words in the explanation and go red on the file that is right.
	 *
	 * @return string
	 */
	private function source_without_comments(): string {
		$path   = dirname( __DIR__, 2 ) . '/src/Events/OrderRefundedListener.php';
		$source = file_get_contents( $path );

		$this->assertIsString( $source, 'The source of the refund capture could not be read, so the sweep proved nothing.' );

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}
}

/**
 * The shop logger, recording what it was told instead of writing it.
 *
 * A plain class and not a Mockery double on purpose: the listener guards its
 * logging with `method_exists()`, and that answers FALSE on a Mockery double of
 * a class that does not exist - which is measured, and which would make this
 * file assert on a log line the listener had quietly decided not to write.
 */
class RefundListenerLoggerSpy {

	/**
	 * Everything logged at error level, in order.
	 *
	 * @var array<int, array{message: string, context: array<string, mixed>}>
	 */
	public array $errors = array();

	/**
	 * Records one error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->errors[] = array(
			'message' => $message,
			'context' => $context,
		);
	}
}
