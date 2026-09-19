<?php
/**
 * Unit tests for the inbound refund applier.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
use Factuarea\WooCommerce\Inbound\RefundApplier;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What this file pins down, and why each case is here.
 *
 * ---------------------------------------------------------------------------
 * THE CREATION IS SIMULATED IN THE ORDER WOOCOMMERCE REALLY USES
 * ---------------------------------------------------------------------------
 * `wc_create_refund()` is doubled, and the double does what the real function
 * does in the order the real one does it - measured on 2026-09-18 against
 * WooCommerce 8.9.5, the version `.wp-env.json` pins,
 * `includes/wc-order-functions.php`:
 *
 *   :620  do_action( 'woocommerce_create_refund', $refund, $args )
 *   :622  if ( $refund->save() ) {                      <- the INSERT
 *   :657  $order->update_status( 'refunded' )           <- fires the status captor
 *   :667  do_action( 'woocommerce_order_refunded', ... ) <- fires the refund captor
 *
 * So the double fires the marking callback BEFORE it answers, which is the only
 * arrangement under which these tests can tell a mark that rides the creating
 * save apart from one written after the call returned. The second is the bug
 * this whole phase exists to avoid, and it passes every test that only looks at
 * the end state.
 *
 * The order and the refund are doubles that BEHAVE - writes land in an array
 * and reads come back out of it - rather than doubles that count calls, because
 * a counter would let an applier that writes the mark onto the wrong object
 * pass. `WC_Order` and `WC_Order_Refund` do not exist in a Unit run, so they are
 * built by name.
 *
 * The order double declares `set_status` and `update_status` as calls it must
 * NOT receive. Whether the order becomes `refunded` is WooCommerce's decision,
 * taken inside the creation by measuring the amount; an applier that forced it
 * would be overriding that decision from outside, and it would do so with the
 * scope the platform declared against a DIFFERENT document.
 */
final class RefundApplierTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Identifier of the order under test.
	 */
	private const ORDER_ID = 1042;

	/**
	 * Identifier the refund double answers with.
	 */
	private const REFUND_ID = 7001;

	/**
	 * Public reference of the corrective invoice used throughout.
	 */
	private const CORRECTIVE = 'FR-2026-0007';

	/**
	 * Key the ledger claimed for the delivery under test.
	 */
	private const CLAIMED_KEY = 'wc-1042-refund-9f2c';

	/**
	 * Meta written on the order double, keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $order_meta = array();

	/**
	 * Meta written on the refund double, keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $refund_meta = array();

	/**
	 * Order notes written on the order double.
	 *
	 * @var list<string>
	 */
	private array $notes = array();

	/**
	 * What happened, in the order it happened.
	 *
	 * @var list<string>
	 */
	private array $timeline = array();

	/**
	 * Arguments `wc_create_refund()` was called with, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $refund_args = null;

	/**
	 * Callback the applier attached to the creation hook, or null.
	 *
	 * @var callable|null
	 */
	private $marker = null;

	/**
	 * Priority the marking callback was attached at.
	 *
	 * @var mixed
	 */
	private $mark_priority = null;

	/**
	 * Arguments the marking callback declared.
	 *
	 * @var mixed
	 */
	private $mark_arguments = null;

	/**
	 * How many times the order double was saved.
	 *
	 * @var int
	 */
	private int $order_saves = 0;

	/**
	 * The refund double, built on first use.
	 *
	 * @var MockInterface|null
	 */
	private ?MockInterface $refund = null;

	/**
	 * The order double once built, so that one test uses one order.
	 *
	 * @var MockInterface|null
	 */
	private ?MockInterface $cached_order = null;

	/**
	 * The logger spy, when a case installed one.
	 *
	 * A real class with a real `error()` method and not a Mockery double: the
	 * applier reaches the logger through `method_exists()`, which answers false
	 * on a double whose methods live behind `__call`, and the case would then
	 * pass by never logging at all.
	 *
	 * @var RefundApplierLoggerSpy|null
	 */
	private ?RefundApplierLoggerSpy $logger = null;

	/**
	 * Installs the doubles and starts from nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->order_meta     = array();
		$this->refund_meta    = array();
		$this->notes          = array();
		$this->timeline       = array();
		$this->refund_args    = null;
		$this->marker         = null;
		$this->mark_priority  = null;
		$this->mark_arguments = null;
		$this->order_saves    = 0;
		$this->refund         = null;
		$this->cached_order   = null;
		$this->logger         = null;

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof FakeRefundFailure;
			}
		);
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
	 * A real WooCommerce refund is created, for the amount the notice declared
	 * and with a reason that names the corrective invoice behind it.
	 *
	 * The reason matters as much as the amount: the order meta only mirrors the
	 * LAST inbound refund, so an order refunded twice would otherwise have two
	 * refunds and one reference. Each refund carrying its own reason is what
	 * makes them tell apart in the admin screen.
	 *
	 * @return void
	 */
	public function test_it_creates_a_refund_for_the_declared_amount_with_a_reason_naming_the_corrective_invoice(): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );

		self::assertIsArray( $this->refund_args, 'The applier has to create a WooCommerce refund.' );
		self::assertSame( self::ORDER_ID, $this->refund_args['order_id'] );
		self::assertSame( '84.97', $this->refund_args['amount'] );
		self::assertSame(
			'Factuarea partial refund, corrective invoice ' . self::CORRECTIVE,
			$this->refund_args['reason'],
			'The reason has to name the corrective invoice that backs the refund.'
		);
	}

	/**
	 * Neither a gateway movement nor a restock is ordered, and both are declared
	 * rather than left to whatever WooCommerce defaults to.
	 *
	 * A default is not an agreement. The money was already returned outside this
	 * shop, so a gateway refund here would return it a second time out of the
	 * merchant's balance; the stock was already restocked by the platform, so
	 * restocking here would count every returned item twice.
	 *
	 * @return void
	 */
	public function test_it_orders_no_gateway_movement_and_no_restock(): void {
		$this->expect_refund_creation();

		Functions\expect( 'wc_refund_payment' )->never();
		Functions\expect( 'wc_restock_refunded_items' )->never();

		( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );

		self::assertIsArray( $this->refund_args );
		self::assertArrayHasKey( 'refund_payment', $this->refund_args, 'It has to be declared, not defaulted.' );
		self::assertArrayHasKey( 'restock_items', $this->refund_args, 'It has to be declared, not defaulted.' );
		self::assertFalse( $this->refund_args['refund_payment'], 'The money is already back.' );
		self::assertFalse( $this->refund_args['restock_items'], 'The stock is already back.' );
	}

	/**
	 * The mark reaches the refund INSIDE the creation, before the save that
	 * writes the row, and it carries the idempotency key that produced it.
	 *
	 * This is the case the whole phase turns on. Both of this plugin's refund
	 * captors run inside `wc_create_refund()`, after the save and before it
	 * returns, and they read this mark off the refund. A mark written after the
	 * call returned would arrive too late for both, the refund would travel
	 * back to the platform, and the platform would issue a SECOND corrective
	 * invoice over a sale it had already corrected - in a consecutive series
	 * filed with the tax authority, where a document is not deleted.
	 *
	 * @return void
	 */
	public function test_the_refund_is_marked_inside_the_creation_and_before_it_is_saved(): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );

		self::assertSame(
			RefundApplier::CREATE_REFUND_HOOK,
			'woocommerce_create_refund',
			'The hook is the one WooCommerce fires BEFORE it saves the refund.'
		);
		self::assertSame(
			self::CLAIMED_KEY,
			$this->refund_meta[ Constants::INBOUND_REFUND_META_KEY ] ?? null,
			'The mark carries the idempotency key of the delivery that produced the refund.'
		);
		self::assertSame(
			array( 'hook-added', 'create-refund', 'marked', 'hook-removed' ),
			$this->timeline,
			'The mark has to be written while the refund is being created, not after it exists.'
		);
		self::assertSame( 10, $this->mark_priority );
		self::assertSame( 1, $this->mark_arguments );
		self::assertArrayNotHasKey(
			Constants::INBOUND_REFUND_META_KEY,
			$this->order_meta,
			'The mark belongs on the refund; both captors read it there and never on the order.'
		);
	}

	/**
	 * The value written passes the exact test both captors apply to it.
	 *
	 * `Events\OrderRefundedListener` and `Events\OrderStatusChangedListener`
	 * both decide by PRESENCE, as a non-empty scalar after trimming. A mark
	 * that fails that expression is not a mark, and the refund travels.
	 *
	 * @return void
	 */
	public function test_the_mark_satisfies_the_expression_both_captors_apply(): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );

		$mark = $this->refund_meta[ Constants::INBOUND_REFUND_META_KEY ] ?? null;

		self::assertTrue( is_scalar( $mark ) && '' !== trim( (string) $mark ) );
	}

	/**
	 * An empty claimed key does not produce an empty mark.
	 *
	 * An empty string reads as «no mark» to both captors, so a receiver that
	 * handed over nothing would silently reopen the loop. The key the notice
	 * itself declared - which the factory guarantees is not empty - is used
	 * instead.
	 *
	 * @return void
	 */
	public function test_an_empty_claimed_key_falls_back_to_the_key_the_notice_declared(): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice(), '   ' );

		self::assertSame(
			'idem-from-the-notice',
			$this->refund_meta[ Constants::INBOUND_REFUND_META_KEY ] ?? null
		);
	}

	/**
	 * The marking callback is taken off again once the creation is over, and
	 * also when the creation blows up.
	 *
	 * A callback of this plugin left attached would mark the NEXT refund created
	 * in the same request - a refund the merchant made by hand, which has to
	 * travel to Factuarea - and silence it for good.
	 *
	 * @return void
	 */
	public function test_the_marking_callback_is_removed_even_when_the_creation_throws(): void {
		Actions\expectAdded( RefundApplier::CREATE_REFUND_HOOK )->once();
		Actions\expectRemoved( RefundApplier::CREATE_REFUND_HOOK )
			->once()
			->whenHappen(
				function (): void {
					$this->timeline[] = 'hook-removed';
				}
			);

		Functions\expect( 'wc_create_refund' )->once()->andThrow( new \LogicException( 'a third-party hook exploded' ) );

		try {
			( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );
			self::fail( 'The failure of the creation has to reach the caller.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'a third-party hook exploded', $exception->getMessage() );
		}

		self::assertSame( array( 'hook-removed' ), $this->timeline );
	}

	/**
	 * The amount reaches WooCommerce as the string that arrived, digit for
	 * digit, and the refund carries no rounded copy of it.
	 *
	 * A float cannot hold most two-decimal amounts exactly, so one cast on this
	 * path turns a refund of 12.34 into one of 12.339999999999999 and nothing
	 * downstream can tell which was meant. The scale is deliberately not two
	 * here: a currency with three decimals has to survive as well.
	 *
	 * @return void
	 */
	public function test_the_amount_travels_as_the_string_that_arrived(): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice( 'partial', '0.145' ), self::CLAIMED_KEY );

		self::assertIsArray( $this->refund_args );
		self::assertSame( '0.145', $this->refund_args['amount'] );
		self::assertIsString( $this->refund_args['amount'], 'It must not have become a number on the way.' );
	}

	/**
	 * The source of the applier never turns the amount into a number.
	 *
	 * The mechanical half of the rule the class block states. Comments are
	 * stripped before the sweep, because the block itself names the casts it
	 * forbids and a plain text search would find its own prose.
	 *
	 * @return void
	 */
	public function test_the_source_never_turns_the_amount_into_a_number(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../src/Inbound/RefundApplier.php' );
		$code   = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		foreach ( array( '(float)', '(double)', 'floatval', 'number_format', 'round(' ) as $forbidden ) {
			self::assertStringNotContainsString(
				$forbidden,
				$code,
				sprintf( 'The amount travels as a string; "%s" is how it stops doing so.', $forbidden )
			);
		}
	}

	/**
	 * The order records the corrective invoice and the scope the platform
	 * declared, for each of the two scopes, and gets one note per notice.
	 *
	 * The scope is stored as it ARRIVED and is never recomputed from the
	 * amount: the platform decided it against the invoice it rectified, and an
	 * invoice and a shop order legitimately differ.
	 *
	 * @param string $scope Scope declared by the notice.
	 * @return void
	 *
	 * @dataProvider scopes
	 */
	public function test_it_records_the_corrective_reference_and_the_scope_on_the_order( string $scope ): void {
		$this->expect_refund_creation();

		( new RefundApplier() )->apply( $this->order(), $this->notice( $scope ), self::CLAIMED_KEY );

		self::assertSame( self::CORRECTIVE, $this->order_meta[ Constants::CORRECTIVE_REFERENCE_META_KEY ] ?? null );
		self::assertSame( $scope, $this->order_meta[ Constants::REFUND_SCOPE_META_KEY ] ?? null );
		self::assertSame( 1, $this->order_saves, 'The order is saved once, and before the note is written.' );
		self::assertCount( 1, $this->notes );
		self::assertStringContainsString( '84.97', $this->notes[0] );
		self::assertStringContainsString( 'EUR', $this->notes[0] );
		self::assertStringContainsString( $scope, $this->notes[0] );
		self::assertStringContainsString( self::CORRECTIVE, $this->notes[0] );
	}

	/**
	 * The two scopes the contract publishes, and there is no third.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function scopes(): array {
		return array(
			'total'   => array( InboundRefundNotice::SCOPE_TOTAL ),
			'partial' => array( InboundRefundNotice::SCOPE_PARTIAL ),
		);
	}

	/**
	 * An amount that is not a decimal string creates nothing at all.
	 *
	 * The factory already refuses such a body, so a notice that reaches the
	 * applier with one was built by something that bypassed it - which is why
	 * this case builds it by reflection. Defence in depth rather than a second
	 * opinion: the pattern is the contract's own, read from one place, and the
	 * outcome is a PERMANENT failure, because retrying the same body would fail
	 * identically.
	 *
	 * @return void
	 */
	public function test_an_amount_that_is_not_a_decimal_string_creates_nothing(): void {
		Actions\expectAdded( RefundApplier::CREATE_REFUND_HOOK )->never();
		Functions\expect( 'wc_create_refund' )->never();

		$this->expectException( InboundPayloadNotReadableException::class );

		try {
			( new RefundApplier() )->apply( $this->order(), $this->broken_notice( 'twelve' ), self::CLAIMED_KEY );
		} finally {
			self::assertSame( array(), $this->refund_meta, 'No refund, so no mark.' );
			self::assertSame( array(), $this->order_meta, 'Nothing is recorded for a refund that did not happen.' );
			self::assertSame( array(), $this->notes );
			self::assertSame( 0, $this->order_saves );
		}
	}

	/**
	 * A shop that refuses to record the refund produces a retryable failure,
	 * a line in the shop log, and no half-written order.
	 *
	 * WooCommerce answers an error object rather than throwing, and swallowing
	 * it would leave the platform believing the refund landed while the shop
	 * still says the order was paid in full - a disagreement found while
	 * squaring a quarter. The exception leaves the REST callback, WordPress
	 * renders a 500, and the sender brings the delivery back.
	 *
	 * @return void
	 */
	public function test_a_shop_that_refuses_the_refund_raises_a_retryable_failure_and_writes_nothing(): void {
		$this->expect_refund_creation( new FakeRefundFailure( 'Invalid refund amount.' ) );
		$this->double_the_logger();

		try {
			( new RefundApplier() )->apply( $this->order(), $this->notice(), self::CLAIMED_KEY );
			self::fail( 'A refused refund must not pass for an applied one.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'Invalid refund amount.', $exception->getMessage() );
			self::assertStringContainsString( self::CORRECTIVE, $exception->getMessage() );
			self::assertStringContainsString( (string) self::ORDER_ID, $exception->getMessage() );
			self::assertNotInstanceOf( InboundPayloadNotReadableException::class, $exception, 'It is not an answer about the notice.' );
		}

		self::assertSame( array(), $this->order_meta, 'Nothing is recorded for a refund that did not happen.' );
		self::assertSame( array(), $this->notes );
		self::assertSame( 0, $this->order_saves );
		$logged = null === $this->logger ? array() : $this->logger->lines();

		self::assertCount( 1, $logged, 'The merchant has to find out why a refund did not land.' );
		self::assertStringContainsString( self::CORRECTIVE, $logged[0] );
		self::assertSame( Constants::PLUGIN_SLUG, $this->logger->last_source() );
	}

	/**
	 * The native order status is never written by the applier.
	 *
	 * Whether the order becomes `refunded` is WooCommerce's decision, taken
	 * inside the creation by measuring the amount against what is still
	 * refundable. Forcing it from here would override that with the scope the
	 * platform declared against a different document, and it would fire the
	 * status hook a second time.
	 *
	 * @return void
	 */
	public function test_it_never_writes_the_native_order_status(): void {
		$this->expect_refund_creation();

		$order = $this->order();

		( new RefundApplier() )->apply( $order, $this->notice( InboundRefundNotice::SCOPE_TOTAL ), self::CLAIMED_KEY );

		$order->shouldNotHaveReceived( 'set_status' );
		$order->shouldNotHaveReceived( 'update_status' );
		self::assertSame( 1, $this->order_saves );
	}

	/**
	 * Arranges the creation so that it happens exactly as WooCommerce does it.
	 *
	 * @param mixed $answer What `wc_create_refund()` answers, or null for the refund double.
	 * @return void
	 */
	private function expect_refund_creation( $answer = null ): void {
		Actions\expectAdded( RefundApplier::CREATE_REFUND_HOOK )
			->once()
			->whenHappen(
				function ( $callback, $priority = null, $accepted_args = null ): void {
					$this->timeline[]     = 'hook-added';
					$this->marker         = $callback;
					$this->mark_priority  = $priority;
					$this->mark_arguments = $accepted_args;
				}
			);

		Actions\expectRemoved( RefundApplier::CREATE_REFUND_HOOK )
			->once()
			->whenHappen(
				function (): void {
					$this->timeline[] = 'hook-removed';
				}
			);

		Functions\expect( 'wc_create_refund' )
			->once()
			->andReturnUsing(
				function ( array $args ) use ( $answer ) {
					$this->timeline[]  = 'create-refund';
					$this->refund_args = $args;

					// What WooCommerce does at `wc-order-functions.php:620`,
					// BEFORE the `$refund->save()` of the next line.
					$marker = $this->marker;

					if ( null !== $marker ) {
						$marker( $this->refund() );
					}

					return null === $answer ? $this->refund() : $answer;
				}
			);
	}

	/**
	 * The order double: it behaves like an order rather than counting calls.
	 *
	 * @return MockInterface
	 */
	private function order(): MockInterface {
		if ( null !== $this->cached_order ) {
			return $this->cached_order;
		}

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->order_meta[ $key ] = $value;
			}
		);

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->order_meta[ $key ] ?? '';
			}
		);

		$order->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				++$this->order_saves;

				return self::ORDER_ID;
			}
		);

		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;

				return count( $this->notes );
			}
		);

		// WooCommerce owns the order status during a refund; this plugin does
		// not write it from here.
		$order->shouldNotReceive( 'set_status' );
		$order->shouldNotReceive( 'update_status' );

		$this->cached_order = $order;

		return $order;
	}

	/**
	 * The refund double, built on first use.
	 *
	 * @return MockInterface
	 */
	private function refund(): MockInterface {
		if ( null !== $this->refund ) {
			return $this->refund;
		}

		$refund = Mockery::mock( 'WC_Order_Refund' );

		$refund->shouldReceive( 'get_id' )->andReturn( self::REFUND_ID );

		$refund->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->timeline[]          = 'marked';
				$this->refund_meta[ $key ] = $value;
			}
		);

		// The mark rides the save WooCommerce is about to do. A save here would
		// insert the row early and leave WooCommerce updating what it believed
		// it was creating.
		$refund->shouldNotReceive( 'save' );

		$this->refund = $refund;

		return $refund;
	}

	/**
	 * Doubles the shop logger and records what is written to it.
	 *
	 * @return void
	 */
	private function double_the_logger(): void {
		$logger = new RefundApplierLoggerSpy();

		Functions\when( 'wc_get_logger' )->alias(
			static function () use ( $logger ): RefundApplierLoggerSpy {
				return $logger;
			}
		);

		$this->logger = $logger;
	}

	/**
	 * Builds a notice through the frozen factory, never by hand.
	 *
	 * Going through `from_array()` means these cases exercise the same reading
	 * the receiver does: a body this test could build but the factory would
	 * reject is a body that never reaches the applier in production.
	 *
	 * @param string $scope  Scope the notice declares.
	 * @param string $amount Amount the notice declares, as a decimal string.
	 * @return InboundRefundNotice
	 */
	private function notice( string $scope = InboundRefundNotice::SCOPE_PARTIAL, string $amount = '84.97' ): InboundRefundNotice {
		$notice = InboundRefundNotice::from_array(
			array(
				InboundRefundNotice::FIELD_ORDER_ID                     => (string) self::ORDER_ID,
				InboundRefundNotice::FIELD_AMOUNT                       => $amount,
				InboundRefundNotice::FIELD_CURRENCY                     => 'EUR',
				InboundRefundNotice::FIELD_SCOPE                        => $scope,
				InboundRefundNotice::FIELD_CORRECTIVE_INVOICE_REFERENCE => self::CORRECTIVE,
				InboundRefundNotice::FIELD_IDEMPOTENCY_KEY              => 'idem-from-the-notice',
			)
		);

		self::assertNotNull( $notice, 'The body of this case has to be a readable notice.' );

		return $notice;
	}

	/**
	 * Builds a notice the factory would never build.
	 *
	 * By reflection and on purpose: the amount guard of the applier is defence
	 * in depth, and the only way to reach it is to bypass the factory exactly
	 * as a future caller that forgot it would.
	 *
	 * @param string $amount Amount that is not a decimal string.
	 * @return InboundRefundNotice
	 */
	private function broken_notice( string $amount ): InboundRefundNotice {
		$reflection = new ReflectionClass( InboundRefundNotice::class );
		$notice     = $reflection->newInstanceWithoutConstructor();

		$values = array(
			'order_id'                     => (string) self::ORDER_ID,
			'amount'                       => $amount,
			'currency'                     => 'EUR',
			'scope'                        => InboundRefundNotice::SCOPE_PARTIAL,
			'corrective_invoice_reference' => self::CORRECTIVE,
			'idempotency_key'              => 'idem-from-the-notice',
		);

		foreach ( $values as $name => $value ) {
			$reflection->getProperty( $name )->setValue( $notice, $value );
		}

		return $notice;
	}
}

/**
 * A stand-in for the WordPress error object, recognised by the doubled
 * `is_wp_error` of this file.
 *
 * It is NOT named `WP_Error` and it is not aliased to it: another file of this
 * one suite already owns that alias, and two files claiming the same class name
 * is a fatal error at load time rather than a failure anybody can read.
 */
final class FakeRefundFailure {

	/**
	 * The message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $message Reason WooCommerce gave for refusing the refund.
	 */
	public function __construct( string $message ) {
		$this->message = $message;
	}

	/**
	 * The message, as the WordPress error object exposes it.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * A spy standing in for the shop logger.
 *
 * A real class rather than a double, because the applier only reaches a logger
 * that answers `method_exists( $logger, 'error' )` - the guard that keeps a shop
 * with a replaced logger from turning a failed refund into a fatal error - and a
 * Mockery double, whose methods live behind `__call`, answers false to it.
 */
final class RefundApplierLoggerSpy {

	/**
	 * Messages written, in order.
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	/**
	 * Source declared by the last line written.
	 *
	 * @var string
	 */
	private string $source = '';

	/**
	 * Records one error line.
	 *
	 * @param string              $message Message written.
	 * @param array<string, mixed> $context Context written alongside it.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->lines[]  = $message;
		$this->source   = isset( $context['source'] ) ? (string) $context['source'] : '';
	}

	/**
	 * What was written.
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * The source the last line declared.
	 *
	 * @return string
	 */
	public function last_source(): string {
		return $this->source;
	}
}
