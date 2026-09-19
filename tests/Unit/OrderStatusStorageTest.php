<?php
/**
 * Unit tests for the inbound fulfilment status storage.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;
use Factuarea\WooCommerce\Inbound\OrderStatusStorage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case is here.
 *
 * The order is a double that BEHAVES like an order rather than merely recording
 * calls: writes land in an array, reads come back out of it and deletes remove
 * from it. `WC_Order` does not exist in a Unit run - there is no WordPress and
 * no WooCommerce - so it is built by name. A double that only counted calls
 * would let a storage that writes one key and deletes another pass every case
 * below.
 *
 * The double declares `set_status` and `update_status` as calls it must NOT
 * receive. That is the mechanical half of the prohibition the class block
 * holds: writing the native status fires the hook this plugin listens on and
 * sends a delivery back out to Factuarea, plus every third-party automation the
 * merchant installed. The written half is the class block; this is the half
 * that goes red.
 *
 * Instants are ATOM strings because that is what the frozen contract publishes
 * (`store-order-status-receiver-contract.md` §4.1), and the staleness cases use
 * hours an hour apart so that a comparison accidentally written on the STRING
 * is not what makes them pass.
 */
final class OrderStatusStorageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Meta written on the order double, keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * How many times the order double has been saved.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Order notes written on the double, in the order they were written.
	 *
	 * @var list<string>
	 */
	private array $notes = array();

	/**
	 * Installs the translation doubles and starts with an empty order.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->meta  = array();
		$this->saves = 0;
		$this->notes = array();
	}

	/**
	 * Removes the doubles so the next test starts from nothing.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * The four states are stored as the literal that arrived on the wire.
	 *
	 * The literal matters: what sits in the database has to be the same string
	 * the notice carried, because the buyer's screen and the merchant's note are
	 * read against it, and a state spelled differently here would be a state
	 * nothing recognises.
	 *
	 * @return void
	 */
	public function test_the_four_states_are_stored_as_the_wire_literal(): void {
		foreach ( FulfilmentState::ALL as $index => $state ) {
			$occurred_at = sprintf( '2026-09-18T1%d:00:00+00:00', $index );

			$order = $this->order();

			( new OrderStatusStorage() )->apply( $order, $this->notice( $state, $occurred_at ) );

			self::assertSame(
				$state,
				$this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null,
				sprintf( 'The state "%s" must be stored as it arrived.', $state )
			);
			self::assertSame(
				$occurred_at,
				$this->meta[ Constants::FULFILMENT_APPLIED_AT_META_KEY ] ?? null,
				'The instant OF THE FACT is what orders the notices, so it is what gets stored.'
			);
		}
	}

	/**
	 * None of the four writes the native order status.
	 *
	 * Guarded twice: the double refuses both `set_status` and `update_status`,
	 * and every key the order came out with belongs to this plugin.
	 *
	 * @return void
	 */
	public function test_the_native_order_status_is_never_written_for_any_of_the_four(): void {
		foreach ( FulfilmentState::ALL as $index => $state ) {
			$order = $this->order();

			( new OrderStatusStorage() )->apply(
				$order,
				$this->notice( $state, sprintf( '2026-09-18T1%d:00:00+00:00', $index ) )
			);

			self::assertNotSame( array(), $this->meta, 'The notice was supposed to be applied.' );

			foreach ( array_keys( $this->meta ) as $key ) {
				self::assertStringStartsWith(
					Constants::META_PREFIX,
					(string) $key,
					sprintf( 'Applying "%s" wrote a key that is not this plugin\'s own.', $state )
				);
			}
		}
	}

	/**
	 * A shipped notice stores the three tracking data it declares.
	 *
	 * @return void
	 */
	public function test_the_declared_tracking_is_stored(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
					'url'     => 'https://track.example/es?n=TRK-000123&lang=es',
				)
			)
		);

		self::assertSame( 'TRK-000123', $this->meta[ Constants::TRACKING_NUMBER_META_KEY ] ?? null );
		self::assertSame( 'Correos Express', $this->meta[ Constants::TRACKING_CARRIER_META_KEY ] ?? null );
		self::assertSame(
			'https://track.example/es?n=TRK-000123&lang=es',
			$this->meta[ Constants::TRACKING_URL_META_KEY ] ?? null,
			'The tracking address is stored byte for byte as it arrived, query string included.'
		);
	}

	/**
	 * A later notice that no longer declares tracking deletes what was stored.
	 *
	 * This is the case the meta exists to avoid: a delivered parcel still
	 * showing the buyer a tracking address, as if the shipment were on its way.
	 *
	 * @return void
	 */
	public function test_a_later_notice_without_tracking_deletes_the_previous_one(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		$storage->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
					'url'     => 'https://track.example/es?n=TRK-000123',
				)
			)
		);

		$storage->apply( $order, $this->notice( FulfilmentState::DELIVERED, '2026-09-20T09:00:00+00:00' ) );

		self::assertSame( FulfilmentState::DELIVERED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_NUMBER_META_KEY, $this->meta );
		self::assertArrayNotHasKey( Constants::TRACKING_CARRIER_META_KEY, $this->meta );
		self::assertArrayNotHasKey( Constants::TRACKING_URL_META_KEY, $this->meta );
	}

	/**
	 * A second shipment without tracking does not inherit the first one's.
	 *
	 * Same rule as the case above, on the state that carries tracking: it is the
	 * one where a stale number is most believable, because the state on screen
	 * did not change.
	 *
	 * @return void
	 */
	public function test_a_second_shipment_without_tracking_does_not_inherit_the_first(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		$storage->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array( 'number' => 'TRK-000123' )
			)
		);

		$storage->apply( $order, $this->notice( FulfilmentState::SHIPPED, '2026-09-19T18:00:00+00:00' ) );

		self::assertSame( FulfilmentState::SHIPPED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_NUMBER_META_KEY, $this->meta );
	}

	/**
	 * Carrier and number without an address store no address, composed or not.
	 *
	 * The platform derives the address from its own carrier template and does
	 * not persist it; composing one here would give two different addresses for
	 * the same shipment the day that template changes.
	 *
	 * @return void
	 */
	public function test_the_tracking_address_is_not_composed_from_the_carrier_and_the_number(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
				)
			)
		);

		self::assertSame( 'TRK-000123', $this->meta[ Constants::TRACKING_NUMBER_META_KEY ] ?? null );
		self::assertSame( 'Correos Express', $this->meta[ Constants::TRACKING_CARRIER_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_URL_META_KEY, $this->meta );

		foreach ( $this->meta as $key => $value ) {
			self::assertStringNotContainsString(
				'http',
				(string) $value,
				sprintf( 'Nothing composed an address into "%s".', (string) $key )
			);
		}

		foreach ( $this->notes as $note ) {
			self::assertStringNotContainsString( 'http', $note, 'The note does not compose an address either.' );
		}
	}

	/**
	 * A state that does not carry tracking stores none of it.
	 *
	 * Measured on 2026-09-18: the emitter's `StoreOrderStatusPublisher` answers
	 * null tracking unless the stage carries it, and only `shipped` does - so no
	 * legitimate notice reaches this branch today. It is pinned because the
	 * receiver is a public endpoint and a body that reached it with tracking on
	 * «prepared» would announce a shipment that has not left.
	 *
	 * @return void
	 */
	public function test_a_state_that_does_not_carry_tracking_stores_none(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply(
			$order,
			$this->notice(
				FulfilmentState::PREPARED,
				'2026-09-19T08:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
					'url'     => 'https://track.example/es',
				)
			)
		);

		self::assertSame( FulfilmentState::PREPARED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_NUMBER_META_KEY, $this->meta );
		self::assertArrayNotHasKey( Constants::TRACKING_CARRIER_META_KEY, $this->meta );
		self::assertArrayNotHasKey( Constants::TRACKING_URL_META_KEY, $this->meta );
	}

	/**
	 * A notice older than the one already applied changes nothing at all.
	 *
	 * Not the state, not the tracking, not the instant, and not a second note or
	 * a second save: a superseded fact is dropped whole.
	 *
	 * @return void
	 */
	public function test_an_older_notice_does_not_overwrite_a_more_recent_one(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		$storage->apply( $order, $this->notice( FulfilmentState::DELIVERED, '2026-09-19T11:00:00+00:00' ) );

		$saves_after_the_first = $this->saves;

		$storage->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array( 'number' => 'TRK-000123' )
			)
		);

		self::assertSame(
			FulfilmentState::DELIVERED,
			$this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null,
			'A notice published an hour earlier arrived later and must not win.'
		);
		self::assertSame( '2026-09-19T11:00:00+00:00', $this->meta[ Constants::FULFILMENT_APPLIED_AT_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_NUMBER_META_KEY, $this->meta );
		self::assertSame( $saves_after_the_first, $this->saves, 'A dropped notice saves nothing.' );
		self::assertCount( 1, $this->notes, 'A dropped notice writes no note.' );
	}

	/**
	 * Two notices stamped at the same instant: the later arrival applies.
	 *
	 * A redelivery of the SAME notice never reaches this class - the idempotency
	 * ledger claims its key before the body is read - so two bodies sharing an
	 * instant are two distinct facts.
	 *
	 * @return void
	 */
	public function test_a_notice_at_the_very_same_instant_is_applied(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		$storage->apply( $order, $this->notice( FulfilmentState::PREPARED, '2026-09-19T10:00:00+00:00' ) );
		$storage->apply( $order, $this->notice( FulfilmentState::SHIPPED, '2026-09-19T10:00:00+00:00' ) );

		self::assertSame( FulfilmentState::SHIPPED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertCount( 2, $this->notes );
	}

	/**
	 * The comparison reads the instant, not the string.
	 *
	 * The same moment written in two time zones is one moment, and an order that
	 * received it in one of them must not reject the other.
	 *
	 * @return void
	 */
	public function test_the_staleness_comparison_is_made_on_the_instant_and_not_on_the_text(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		// 09:00 UTC, written as 11:00 in a +02:00 offset.
		$storage->apply( $order, $this->notice( FulfilmentState::PREPARED, '2026-09-19T11:00:00+02:00' ) );

		// 10:00 UTC: later in time, earlier as text.
		$storage->apply( $order, $this->notice( FulfilmentState::SHIPPED, '2026-09-19T10:00:00+00:00' ) );

		self::assertSame(
			FulfilmentState::SHIPPED,
			$this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null,
			'10:00+00:00 is AFTER 11:00+02:00, and a text comparison would have dropped it.'
		);
	}

	/**
	 * An unreadable stored instant does not freeze the order for good.
	 *
	 * @return void
	 */
	public function test_an_unreadable_stored_instant_does_not_block_a_new_notice(): void {
		$order = $this->order(
			array(
				Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::PREPARED,
				Constants::FULFILMENT_APPLIED_AT_META_KEY => 'not-an-instant',
			)
		);

		( new OrderStatusStorage() )->apply( $order, $this->notice( FulfilmentState::SHIPPED, '2026-09-19T10:00:00+00:00' ) );

		self::assertSame( FulfilmentState::SHIPPED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertSame( '2026-09-19T10:00:00+00:00', $this->meta[ Constants::FULFILMENT_APPLIED_AT_META_KEY ] ?? null );
	}

	/**
	 * An unreadable incoming instant is applied rather than silently dropped.
	 *
	 * @return void
	 */
	public function test_an_unreadable_incoming_instant_is_still_applied(): void {
		$order = $this->order(
			array(
				Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::PREPARED,
				Constants::FULFILMENT_APPLIED_AT_META_KEY => '2026-09-19T10:00:00+00:00',
			)
		);

		( new OrderStatusStorage() )->apply( $order, $this->notice( FulfilmentState::SHIPPED, 'not-an-instant' ) );

		self::assertSame( FulfilmentState::SHIPPED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
	}

	/**
	 * An order with no fulfilment history takes the first notice it gets.
	 *
	 * @return void
	 */
	public function test_the_first_notice_of_an_order_is_applied(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply( $order, $this->notice( FulfilmentState::PREPARED, '2026-09-19T08:00:00+00:00' ) );

		self::assertSame( FulfilmentState::PREPARED, $this->meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertSame( 1, $this->saves );
	}

	/**
	 * Every applied notice leaves one order note, with its state.
	 *
	 * @return void
	 */
	public function test_every_applied_notice_writes_one_order_note_naming_its_state(): void {
		$order   = $this->order();
		$storage = new OrderStatusStorage();

		$storage->apply( $order, $this->notice( FulfilmentState::PREPARED, '2026-09-19T08:00:00+00:00' ) );
		$storage->apply( $order, $this->notice( FulfilmentState::DELIVERED, '2026-09-20T09:00:00+00:00' ) );

		self::assertCount( 2, $this->notes );
		self::assertStringContainsString( FulfilmentState::PREPARED, $this->notes[0] );
		self::assertStringContainsString( FulfilmentState::DELIVERED, $this->notes[1] );
	}

	/**
	 * The note of a shipment names its carrier, its number and its address.
	 *
	 * The note is the record the merchant reads when a shipment is disputed, and
	 * it is also where the tracking of a superseded shipment survives after the
	 * meta stopped mirroring it.
	 *
	 * @return void
	 */
	public function test_the_note_of_a_shipment_carries_its_tracking(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
					'url'     => 'https://track.example/es?n=TRK-000123',
				)
			)
		);

		self::assertCount( 1, $this->notes );
		self::assertStringContainsString( FulfilmentState::SHIPPED, $this->notes[0] );
		self::assertStringContainsString( 'Correos Express', $this->notes[0] );
		self::assertStringContainsString( 'TRK-000123', $this->notes[0] );
		self::assertStringContainsString( 'https://track.example/es?n=TRK-000123', $this->notes[0] );
	}

	/**
	 * A notice without tracking writes a note that does not mention any.
	 *
	 * @return void
	 */
	public function test_a_notice_without_tracking_writes_a_note_without_tracking(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply( $order, $this->notice( FulfilmentState::SHIPPED, '2026-09-19T10:00:00+00:00' ) );

		self::assertCount( 1, $this->notes );
		self::assertStringNotContainsString( 'Carrier', $this->notes[0] );
		self::assertStringNotContainsString( 'Tracking', $this->notes[0] );
	}

	/**
	 * One applied notice saves the order exactly once.
	 *
	 * Five meta writes and one save, not one save per key: each save of an order
	 * fires the shop's own hooks and hits the database.
	 *
	 * @return void
	 */
	public function test_an_applied_notice_saves_the_order_exactly_once(): void {
		$order = $this->order();

		( new OrderStatusStorage() )->apply(
			$order,
			$this->notice(
				FulfilmentState::SHIPPED,
				'2026-09-19T10:00:00+00:00',
				array(
					'number'  => 'TRK-000123',
					'carrier' => 'Correos Express',
					'url'     => 'https://track.example/es',
				)
			)
		);

		self::assertSame( 1, $this->saves );
	}

	/**
	 * An order double whose meta really is written, read back and deleted.
	 *
	 * @param array<string, mixed> $existing_meta Meta the order already carries.
	 * @return MockInterface
	 */
	private function order( array $existing_meta = array() ): MockInterface {
		$this->meta  = $existing_meta;
		$this->saves = 0;
		$this->notes = array();

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);

		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		);

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);

		$order->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				++$this->saves;

				return 1042;
			}
		);

		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;

				return count( $this->notes );
			}
		);

		// The prohibition, made mechanical. Either of these would fire
		// `woocommerce_order_status_changed` and send a delivery back out.
		$order->shouldNotReceive( 'set_status' );
		$order->shouldNotReceive( 'update_status' );

		return $order;
	}

	/**
	 * Builds a notice through the frozen factory, never by hand.
	 *
	 * Going through `from_array()` means these cases exercise the same reading
	 * the receiver does: a body this test could build but the factory would
	 * reject is a body that never reaches the storage in production.
	 *
	 * @param string                    $status      One of the four states.
	 * @param string                    $occurred_at Instant of the fact, ATOM.
	 * @param array<string, mixed>|null $tracking    Tracking block, when the notice declares one.
	 * @return InboundStatusNotice
	 */
	private function notice( string $status, string $occurred_at, ?array $tracking = null ): InboundStatusNotice {
		$document = array(
			InboundStatusNotice::FIELD_ORDER_ID        => '1042',
			InboundStatusNotice::FIELD_STATUS          => $status,
			InboundStatusNotice::FIELD_OCCURRED_AT     => $occurred_at,
			InboundStatusNotice::FIELD_IDEMPOTENCY_KEY => sprintf( 'wc-1042-%s-%s', $status, $occurred_at ),
		);

		if ( null !== $tracking ) {
			$document[ InboundStatusNotice::FIELD_TRACKING ] = $tracking;
		}

		$notice = InboundStatusNotice::from_array( $document );

		self::assertNotNull( $notice, 'The body of this case has to be a readable notice.' );

		return $notice;
	}
}
