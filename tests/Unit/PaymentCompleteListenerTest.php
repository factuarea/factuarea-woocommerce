<?php
/**
 * Unit test of the paid-order capture.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Factuarea\WooCommerce\Events\PaymentCompleteListener;
use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and the mistake each case would catch.
 *
 *  1. THE ENTRY CARRIES THE IDENTITY DERIVED FROM THE ORDER. Asserted against
 *     the literal string, not against a call to the composer, so a listener that
 *     composed its own identity - or reached for a delivery identifier - fails
 *     here rather than in production, where the symptom is a second invoice for
 *     one sale.
 *
 *  2. TWO CAPTURES OF THE SAME ORDER PRODUCE THE SAME IDENTITY. That is what
 *     makes them ONE entry: the collapse is done by the UNIQUE constraint on the
 *     identity column, which is exercised against a real database in the
 *     outbox's own phase. What can be measured here, and what actually decides
 *     the outcome, is that the two writes address the same key - a listener that
 *     varied the identity between calls would insert twice and no constraint
 *     anywhere would save it.
 *
 *  3. NO NETWORK CALL HAPPENS INSIDE THE HOOK. Asserted as the two WordPress
 *     transport functions never being reached. This is the property that keeps a
 *     slow or unreachable receiver from becoming a slow checkout, and it is the
 *     one that would decay silently: a call added here still passes every other
 *     assertion in this file.
 *
 *  4. THE CORRELATION KEY IS STORED AGAINST THE ORDER, and is NOT stored while
 *     the store identifier is still empty. A digest derived from an empty store
 *     identifier is not the digest the receiver derives, so storing one would
 *     leave a value on the order that looks like a correlation key and can never
 *     match.
 *
 *  5. A BODY THAT CANNOT BE BUILT QUEUES NOTHING AND DOES NOT ESCAPE THE HOOK.
 *     Both halves are asserted, and so is what reaches the log: the reason and
 *     the order number, never the body - which is where the buyer's tax
 *     identifier and address live.
 *
 *  6. IT QUEUES WHILE DELIVERY IS SWITCHED OFF. The switch belongs to the drain;
 *     reading it here would turn "paused" into "lost", with no row for any
 *     notice to count.
 *
 * The identity composer is used FOR REAL rather than doubled, because it is pure
 * and because doubling it would turn case 1 into a test of this file's own idea
 * of what an identity looks like. What is doubled is the outbox, whose real
 * implementation talks to a database, and the WordPress functions.
 *
 * The payload builder is replaced by overriding the seam of the listener rather
 * than by a double, because the builder is `final` and a parameter typed as a
 * final class accepts nothing else. The subclass is anonymous, so this file adds
 * no class name to the single suite that the other phases could collide with.
 */
final class PaymentCompleteListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The order every case is about.
	 */
	private const ORDER_ID = 4711;

	/**
	 * Identity the composer produces for that order, written out in full.
	 *
	 * It is a literal and not a call to `EventIdentity::for_paid_order()` on
	 * purpose: a test that asked the composer what the identity is would agree
	 * with any answer it gave, including a changed one, and this string is a
	 * contract the receiver derives independently from the same order.
	 */
	private const EXPECTED_IDENTITY = 'woocommerce:order:4711:paid';

	/**
	 * Identifier of this shop at the receiver.
	 */
	private const STORE_ID = 'str_9f2b';

	/**
	 * Address of this installation, with a path, because two WordPress sites
	 * under one host are told apart by nothing else.
	 */
	private const SHOP_URL = 'https://shop.example.test/tienda';

	/**
	 * Body of the order under test. Small, but a real shape: it carries the tax
	 * identifier in the meta the connector reads, which is what makes the
	 * assertion that it never reaches the log mean something.
	 */
	private const RAW_BODY = '{"id":4711,"status":"processing","total":"121.00","meta_data":[{"id":9,"key":"_factuarea_tax_id","value":"B12345674"}]}';

	/**
	 * Options as the double of `get_option` sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Every enqueued entry, in order.
	 *
	 * @var array<int, array{event_id: string, order_id: int, event_type: string, raw_body: string, source: string}>
	 */
	private array $enqueued = array();

	/**
	 * Meta the order double carries, keyed by meta key.
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
	 * What `wc_get_order()` answers. False stands for an order that is not there.
	 *
	 * @var mixed
	 */
	private $order = false;

	/**
	 * The logger double, which records rather than writes.
	 *
	 * @var object
	 */
	private object $logger;

	/**
	 * Installs the doubles of the four WordPress functions this listener
	 * reaches for. There are only four, and the shortness of the list is itself
	 * the point: the class knows an order, a queue and an identity, and nothing
	 * about transport.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options  = array( Constants::OPTION_STORE_ID => self::STORE_ID );
		$this->enqueued = array();
		$this->meta     = array();
		$this->saves    = 0;
		$this->order    = $this->order_double();

		$this->logger = new class() {
			/**
			 * Every line written, in order.
			 *
			 * @var array<int, array{message: string, context: array<string, mixed>}>
			 */
			public array $lines = array();

			/**
			 * Records one line.
			 *
			 * @param string               $message Line written.
			 * @param array<string, mixed> $context Context of the line.
			 * @return void
			 */
			public function error( string $message, array $context = array() ): void {
				$this->lines[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};

		Functions\when( 'get_option' )->alias(
			function ( string $option, $default_value = false ) {
				return $this->options[ $option ] ?? $default_value;
			}
		);

		Functions\when( 'home_url' )->justReturn( self::SHOP_URL );

		Functions\when( 'wc_get_order' )->alias(
			function () {
				return $this->order;
			}
		);

		Functions\when( 'wc_get_logger' )->alias(
			function (): object {
				return $this->logger;
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

	/**
	 * The capture writes one entry, and every column of it is what the drain and
	 * the receiver expect to find.
	 *
	 * @return void
	 */
	public function test_it_queues_the_paid_order_with_the_identity_derived_from_the_order(): void {
		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( self::EXPECTED_IDENTITY, $this->enqueued[0]['event_id'] );
		self::assertSame( self::ORDER_ID, $this->enqueued[0]['order_id'] );
		self::assertSame( 'order.paid', $this->enqueued[0]['event_type'] );
		self::assertSame( self::RAW_BODY, $this->enqueued[0]['raw_body'] );
	}

	/**
	 * The origin recorded is the address of the installation, RAW.
	 *
	 * Raw matters because the drain refuses to deliver an entry whose origin is
	 * not this installation, and it normalises both sides when it compares them.
	 * An origin tidied here would be tidied twice, by two versions of the same
	 * idea, and the day the two disagree the guard stops every delivery of a
	 * perfectly configured shop.
	 *
	 * @return void
	 */
	public function test_the_entry_records_the_address_of_this_installation(): void {
		$this->listener()->capture( self::ORDER_ID );

		self::assertSame( self::SHOP_URL, $this->enqueued[0]['source'] );
	}

	/**
	 * Two captures of the same paid order address the SAME entry.
	 *
	 * The database collapses them - the identity column is unique, and the
	 * outbox inserts without looking first - so what decides whether one sale
	 * becomes one invoice or two is precisely that both writes carry the same
	 * key, which is what is asserted here. The collapse itself is measured
	 * against a real database in the outbox's own phase.
	 *
	 * @return void
	 */
	public function test_two_captures_of_the_same_order_queue_the_same_identity(): void {
		$listener = $this->listener();

		$listener->capture( self::ORDER_ID );
		$listener->capture( self::ORDER_ID );

		self::assertCount( 2, $this->enqueued );
		self::assertSame( self::EXPECTED_IDENTITY, $this->enqueued[0]['event_id'] );
		self::assertSame( $this->enqueued[0]['event_id'], $this->enqueued[1]['event_id'] );
		self::assertSame( $this->enqueued[0]['raw_body'], $this->enqueued[1]['raw_body'] );
	}

	/**
	 * Nothing inside the hook touches the network.
	 *
	 * The buyer is waiting while this runs, and the receiver is a third party
	 * that can be slow, unreachable or in the middle of a deploy. Delivery is the
	 * drain's job, and this is the assertion that keeps it there.
	 *
	 * @return void
	 */
	public function test_no_network_call_is_made_inside_the_hook(): void {
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
	}

	/**
	 * The correlation key is stored against the order, and it is the digest the
	 * receiver derives from the same three parts.
	 *
	 * @return void
	 */
	public function test_it_stores_the_idempotency_key_against_the_order(): void {
		$this->listener()->capture( self::ORDER_ID );

		self::assertSame(
			EventIdentity::idempotency_key( self::STORE_ID, self::EXPECTED_IDENTITY ),
			$this->meta[ Constants::IDEMPOTENCY_KEY_META_KEY ] ?? null
		);
		self::assertSame( 1, $this->saves );
	}

	/**
	 * The same order captured twice is saved once.
	 *
	 * Two hooks see the same paid order in one checkout, so this is the ordinary
	 * case rather than an edge: writing the same value again would cost a second
	 * order save while the buyer waits, for nothing.
	 *
	 * @return void
	 */
	public function test_the_correlation_key_is_not_written_twice(): void {
		$listener = $this->listener();

		$listener->capture( self::ORDER_ID );
		$listener->capture( self::ORDER_ID );

		self::assertSame( 1, $this->saves );
	}

	/**
	 * With no store identifier configured there is nothing to derive a
	 * correlation key from, so none is stored - and the sale is queued anyway.
	 *
	 * @return void
	 */
	public function test_it_stores_no_correlation_key_while_the_store_identifier_is_unset(): void {
		$this->options = array();

		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
		self::assertArrayNotHasKey( Constants::IDEMPOTENCY_KEY_META_KEY, $this->meta );
		self::assertSame( 0, $this->saves );
	}

	/**
	 * A body that cannot be built queues nothing, does not escape the hook, and
	 * says so in the log without carrying the order document into it.
	 *
	 * @return void
	 */
	public function test_a_body_that_cannot_be_built_queues_nothing_and_does_not_escape_the_hook(): void {
		$listener = $this->listener(
			static function ( int $order_id ): string {
				throw OrderPayloadNotBuildableException::order_not_found( $order_id );
			}
		);

		$listener->capture( self::ORDER_ID );

		self::assertSame( array(), $this->enqueued );
		self::assertCount( 1, $this->logger->lines );
		self::assertStringContainsString( '4711', $this->logger->lines[0]['message'] );
		self::assertSame( Constants::PLUGIN_SLUG, $this->logger->lines[0]['context']['source'] );
	}

	/**
	 * The log line carries no personal datum of the buyer.
	 *
	 * A shop's log is read by support staff, shipped to hosting dashboards and
	 * pasted into tickets. The body holds the tax identifier and the address, so
	 * the assertion is on the body NOT being there rather than on the message
	 * being right.
	 *
	 * @return void
	 */
	public function test_the_log_line_never_carries_the_order_body(): void {
		$listener = $this->listener(
			static function ( int $order_id ): string {
				throw OrderPayloadNotBuildableException::order_not_found( $order_id );
			}
		);

		$listener->capture( self::ORDER_ID );

		self::assertStringNotContainsString( 'B12345674', $this->logger->lines[0]['message'] );
		self::assertStringNotContainsString( 'meta_data', $this->logger->lines[0]['message'] );
	}

	/**
	 * The sale is queued while delivery is switched off.
	 *
	 * The switch is read by the drain, which is what sends. Reading it here would
	 * turn a paused integration into lost sales, and there would be no row for
	 * the stalled-outbox notice to count.
	 *
	 * @return void
	 */
	public function test_it_queues_while_delivery_is_switched_off(): void {
		$this->options[ Constants::OPTION_DELIVERY_ENABLED ] = '0';

		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
	}

	/**
	 * A hook fired without a usable identifier does nothing at all.
	 *
	 * The value arrives from a public hook, so it is narrowed rather than typed:
	 * a `TypeError` here would be an uncaught fatal in the middle of a payment.
	 *
	 * @param mixed $order_id What the hook was fired with.
	 * @return void
	 */
	#[DataProvider( 'unusable_identifiers' )]
	public function test_it_does_nothing_when_the_hook_carries_no_usable_identifier( $order_id ): void {
		$this->listener()->capture( $order_id );

		self::assertSame( array(), $this->enqueued );
		self::assertSame( array(), $this->logger->lines );
	}

	/**
	 * Values a hook can carry that are not an order.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_identifiers(): array {
		return array(
			'zero'         => array( 0 ),
			'negative'     => array( -1 ),
			'empty string' => array( '' ),
			'not a number' => array( 'not-an-order' ),
			'null'         => array( null ),
			'an array'     => array( array( 'id' => 4711 ) ),
		);
	}

	/**
	 * A string identifier, which a third-party payment plugin can fire the hook
	 * with, is captured like the integer it names.
	 *
	 * @return void
	 */
	public function test_it_captures_an_identifier_that_arrived_as_a_string(): void {
		$this->listener()->capture( '4711' );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( self::EXPECTED_IDENTITY, $this->enqueued[0]['event_id'] );
		self::assertSame( self::ORDER_ID, $this->enqueued[0]['order_id'] );
	}

	/**
	 * An order that vanished between the queueing and the correlation write
	 * leaves the entry queued and nothing else.
	 *
	 * @return void
	 */
	public function test_a_missing_order_does_not_stop_the_sale_from_being_queued(): void {
		$this->order = false;

		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( 0, $this->saves );
	}

	/**
	 * An order whose save throws does not take the payment request down with it.
	 *
	 * By the time the correlation key is written the sale is ALREADY in the
	 * outbox, so the only thing at stake is the link to the invoice. Letting the
	 * failure escape would trade a paid order for it.
	 *
	 * @return void
	 */
	public function test_a_failing_order_save_does_not_escape_the_hook(): void {
		$this->order = $this->order_double( new \RuntimeException( 'the order could not be written' ) );

		$this->listener()->capture( self::ORDER_ID );

		self::assertCount( 1, $this->enqueued );
		self::assertCount( 1, $this->logger->lines );
	}

	/**
	 * The capture is hooked onto the payment-complete hook, at the default
	 * priority, taking one argument.
	 *
	 * This is the ONE case built on the real class rather than on the subclass
	 * of the helper below, and it has to be: Brain\Monkey records a hooked
	 * callback by name, and an anonymous class has no name it accepts. It costs
	 * nothing here, because registering touches neither the payload builder nor
	 * the outbox - it only asks WordPress to remember a callback.
	 *
	 * The argument count is asserted along with the callback, because it is a
	 * decision rather than a default: WooCommerce passes the order identifier
	 * AND the gateway's transaction identifier, and taking one is what keeps the
	 * second out of the scope of this class.
	 *
	 * @return void
	 */
	public function test_it_registers_the_capture_on_the_payment_complete_hook(): void {
		$listener = new PaymentCompleteListener( null, $this->outbox_double() );

		Actions\expectAdded( 'woocommerce_payment_complete' )
			->once()
			->with( array( $listener, 'capture' ), 10, 1 );

		$listener->register();
	}

	/**
	 * The listener under test, with its body source replaced.
	 *
	 * The replacement is a subclass rather than a double because
	 * `OrderPayloadBuilder` is final: a constructor parameter typed as a final
	 * class accepts nothing but that class. The subclass is anonymous so that
	 * this file contributes no class name to the suite.
	 *
	 * @param callable|null $build_body What the seam answers; the frozen body by default.
	 * @return PaymentCompleteListener
	 */
	private function listener( ?callable $build_body = null ): PaymentCompleteListener {
		$build_body ??= static fn( int $order_id ): string => self::RAW_BODY;

		return new class( $this->outbox_double(), $build_body ) extends PaymentCompleteListener {

			/**
			 * What the seam answers.
			 *
			 * @var callable
			 */
			private $body_source;

			/**
			 * Builds the listener on a doubled outbox and a stubbed body source.
			 *
			 * @param OutboxRepository $outbox     Doubled outbox.
			 * @param callable         $build_body What the seam answers.
			 */
			public function __construct( OutboxRepository $outbox, callable $build_body ) {
				parent::__construct( null, $outbox );

				$this->body_source = $build_body;
			}

			/**
			 * The stubbed body.
			 *
			 * @param int $order_id WooCommerce order identifier.
			 * @return string
			 */
			protected function build_body( int $order_id ): string {
				return ( $this->body_source )( $order_id );
			}
		};
	}

	/**
	 * An outbox that records what it was asked to enqueue.
	 *
	 * @return OutboxRepository
	 */
	private function outbox_double(): OutboxRepository {
		$outbox = Mockery::mock( OutboxRepository::class );

		$outbox->shouldReceive( 'enqueue' )->andReturnUsing(
			function ( string $event_id, int $order_id, string $event_type, string $raw_body, string $source ): void {
				$this->enqueued[] = array(
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
	 * An order double whose meta really is written and read back.
	 *
	 * A double that only recorded calls would let a listener that writes one key
	 * and reads another pass every case above.
	 *
	 * @param \Throwable|null $save_failure What the save throws, when it throws.
	 * @return MockInterface An order double, of the generated class named WC_Order.
	 */
	private function order_double( ?\Throwable $save_failure = null ): MockInterface {
		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);

		$order->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $save_failure ): int {
				if ( null !== $save_failure ) {
					throw $save_failure;
				}

				++$this->saves;

				return self::ORDER_ID;
			}
		);

		return $order;
	}
}
