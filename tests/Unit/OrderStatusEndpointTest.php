<?php
/**
 * Unit test of the inbound order status receiver.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;
use Factuarea\WooCommerce\Inbound\InboundDeliveryLedger;
use Factuarea\WooCommerce\Inbound\InboundDeliveryTable;
use Factuarea\WooCommerce\Inbound\OrderRefundEndpoint;
use Factuarea\WooCommerce\Inbound\OrderStatusEndpoint;
use Factuarea\WooCommerce\Inbound\RefundApplier;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/*
 * Stand-in for the one WordPress REST class the receiver builds by itself.
 *
 * Declared behind a `class_exists` guard and ALIASED rather than declared under
 * the real name, for the reason the other endpoint test already writes down:
 * the whole package runs as ONE PHPUnit suite, so a second file declaring the
 * same class unguarded is a fatal error at load time. The class this file
 * declares therefore has a name of its own - two files declaring
 * `FakeWpRestResponse` would collide with each other instead - and whichever of
 * them is loaded first is the one the alias points at. Both answer
 * `get_status()` and `get_data()`, which is all any of these cases reads.
 *
 * The request is doubled with Mockery in the cases below: it is only ever READ,
 * so answering two getters is enough. The response cannot be, because the code
 * under test constructs it with `new`.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( FakeStatusRestResponse::class, 'WP_REST_Response' );
}

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE ROUTE. Hooked on `rest_api_init`, at the frozen namespace and path,
 *     for POST only, with a permission callback present and NO declared `args`.
 *     The last two are asserted rather than assumed: WordPress serves a route
 *     registered without a permission callback and merely complains into a log
 *     nobody reads, and a declared argument schema would make WordPress refuse a
 *     body with its own talkative 400 before this receiver ever sees it.
 *
 *  2. THE ORDER OF THE CHECKS, which is the contract and not an implementation
 *     detail. Signature, then switch, then read, then resolve, then claim, then
 *     apply. Every refusal before the claim is asserted to have claimed NOTHING,
 *     because a key claimed for an effect that never landed turns the sender's
 *     next delivery of the same fact into a `409` it reads as PUBLISHED.
 *
 *  3. THE ANSWERS, against the frozen table of `Contract\InboundResponseStatus`
 *     and not against numbers typed here. The three ways of failing
 *     authentication come back as the SAME bare `401`, which is asserted rather
 *     than assumed: "do not tell a prober which half they got right" is a
 *     property of the answers, not of the code paths.
 *
 *  4. THE PUBLISHED EXAMPLE BODIES. The five files of
 *     `tests/fixtures/inbound/order-status/` are signed as the BYTES they are
 *     and fed to the receiver. They are the bodies the contract publishes, so a
 *     receiver that stopped reading one of them is a receiver that stopped
 *     reading what the platform sends.
 *
 *  5. THE LOOP, CLOSED. After a status notice and a refund notice have been
 *     applied, nothing was written to the database outside the ledger and the
 *     order never received a native status write - the two ways an entry could
 *     have reached the outbox.
 */
final class OrderStatusEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Signing secret of the shop in every case that has one.
	 *
	 * @var string
	 */
	private const SECRET = 'a-signing-secret-of-this-shop';

	/**
	 * Identifier of the order in this shop, as the notices name it.
	 *
	 * @var int
	 */
	private const ORDER_ID = 1042;

	/**
	 * Directory of the published example bodies of this receiver.
	 *
	 * @var string
	 */
	private const FIXTURE_DIRECTORY = __DIR__ . '/../fixtures/inbound/order-status';

	/**
	 * Options the doubled `get_option` answers from.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Meta written on the order double, keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $order_meta = array();

	/**
	 * How many times the order double was saved.
	 *
	 * @var int
	 */
	private int $order_saves = 0;

	/**
	 * Notes written on the order double.
	 *
	 * @var list<string>
	 */
	private array $order_notes = array();

	/**
	 * Identifiers the doubled `wc_get_order` was asked for.
	 *
	 * @var list<mixed>
	 */
	private array $orders_asked_for = array();

	/**
	 * What the doubled `wc_get_order` answers with.
	 *
	 * @var mixed
	 */
	private $order_found = null;

	/**
	 * Whether the order double must throw when it is saved.
	 *
	 * @var bool
	 */
	private bool $save_fails = false;

	/**
	 * Actions the doubled `add_action` recorded.
	 *
	 * @var list<array{hook: string, callback: mixed}>
	 */
	private array $registered_actions = array();

	/**
	 * Installs the doubles shared by every case.
	 *
	 * The receiver is switched ON and the secret is configured by default, so
	 * that every case states the ONE thing it is about instead of restating the
	 * happy configuration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->options = array(
			Constants::OPTION_SIGNING_SECRET                => self::SECRET,
			Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED => SettingsPage::RECEIVER_ENABLED,
			Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED => SettingsPage::RECEIVER_ENABLED,
		);

		$this->order_meta         = array();
		$this->order_saves        = 0;
		$this->order_notes        = array();
		$this->orders_asked_for   = array();
		$this->order_found        = null;
		$this->save_fails         = false;
		$this->registered_actions = array();

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
				unset( $priority, $accepted_args );

				$this->registered_actions[] = array(
					'hook'     => $hook,
					'callback' => $callback,
				);

				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return $this->options[ $name ] ?? $default_value;
			}
		);

		Functions\when( 'wc_get_order' )->alias(
			function ( $identifier ) {
				$this->orders_asked_for[] = $identifier;

				return $this->order_found;
			}
		);
	}

	/**
	 * Removes the doubles so the next case starts from nothing.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * The route registration is hooked where the REST server exists.
	 *
	 * @return void
	 */
	public function test_the_route_registration_is_hooked_on_rest_api_init(): void {
		$endpoint = new OrderStatusEndpoint();

		$endpoint->register();

		self::assertSame(
			array(
				array(
					'hook'     => 'rest_api_init',
					'callback' => array( $endpoint, 'register_route' ),
				),
			),
			$this->registered_actions,
			'register_rest_route() called before the REST server exists is discarded with a notice nobody reads.'
		);
	}

	/**
	 * The route is the frozen one, answers POST only, carries a permission
	 * callback that always says yes, and declares no argument schema.
	 *
	 * @return void
	 */
	public function test_the_route_is_the_frozen_one_for_post_with_an_open_permission_callback(): void {
		$registered = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace_name, string $route, array $arguments ) use ( &$registered ): bool {
				$registered = array(
					'namespace' => $namespace_name,
					'route'     => $route,
					'arguments' => $arguments,
				);

				return true;
			}
		);

		$endpoint = new OrderStatusEndpoint();
		$endpoint->register_route();

		self::assertSame( 'factuarea/v1', $registered['namespace'] );
		self::assertSame( Constants::INBOUND_REST_NAMESPACE, $registered['namespace'] );
		self::assertSame( '/order-status', $registered['route'] );
		self::assertSame( Constants::INBOUND_ORDER_STATUS_ROUTE, $registered['route'] );
		self::assertSame( 'POST', $registered['arguments']['methods'] );
		self::assertSame( array( $endpoint, 'handle' ), $registered['arguments']['callback'] );
		self::assertSame(
			'__return_true',
			$registered['arguments']['permission_callback'],
			'The caller is a server, not a user with a session: what authenticates it is the signature, checked inside handle().'
		);
		self::assertArrayNotHasKey(
			'args',
			$registered['arguments'],
			'A declared argument schema makes WordPress refuse a body with its own 400 and a message naming the route, before this receiver can answer its own status.'
		);
	}

	/**
	 * A tampered signature comes back as a bare 401 and nothing is written,
	 * claimed or even looked up.
	 *
	 * @return void
	 */
	public function test_a_signature_that_does_not_correspond_answers_a_bare_unauthorised_and_writes_nothing(): void {
		$body   = $this->body( 'shipped-with-tracking.json' );
		$ledger = $this->ledger_that_is_never_touched();

		$response = $this->handle( $body, $this->signature( $body . ' ' ), $ledger );

		self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
		self::assertNull( $response->get_data(), 'The authentication refusal carries no body at all.' );
		self::assertSame( array(), $this->order_meta );
		self::assertSame( 0, $this->order_saves );
		self::assertSame( array(), $this->orders_asked_for, 'Nothing is looked up out of an unverified body.' );
	}

	/**
	 * The three ways of failing authentication are one answer, and it is the
	 * same bare 401 for all of them.
	 *
	 * @return void
	 */
	public function test_the_three_ways_of_failing_authentication_are_indistinguishable(): void {
		$body = $this->body( 'shipped-with-tracking.json' );

		$absent = $this->handle( $body, null, $this->ledger_that_is_never_touched() );

		$wrong = $this->handle( $body, 'not-the-signature', $this->ledger_that_is_never_touched() );

		unset( $this->options[ Constants::OPTION_SIGNING_SECRET ] );
		$unconfigured = $this->handle( $body, $this->signature( $body, 'whatever-the-sender-used' ), $this->ledger_that_is_never_touched() );

		foreach ( array( $absent, $wrong, $unconfigured ) as $response ) {
			self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
			self::assertNull( $response->get_data() );
		}
	}

	/**
	 * A signed notice is applied and answered as accepted, with the claim
	 * confirmed as applied.
	 *
	 * @return void
	 */
	public function test_a_signed_notice_is_applied_and_answered_as_accepted(): void {
		$body              = $this->body( 'shipped-with-tracking.json' );
		$this->order_found = $this->order();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->expects( self::once() )
			->method( 'claim' )
			->with( $this->key_of( $body ), self::isType( 'int' ) )
			->willReturn( true );
		$ledger->expects( self::once() )->method( 'mark_applied' )->with( $this->key_of( $body ) );
		$ledger->expects( self::never() )->method( 'forget' );

		$response = $this->handle( $body, $this->signature( $body ), $ledger );

		self::assertSame( InboundResponseStatus::ACCEPTED, $response->get_status() );
		self::assertNull( $response->get_data(), 'An accepted delivery says nothing the status does not already say.' );
		self::assertSame( FulfilmentState::SHIPPED, $this->order_meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertSame( 'TRK-0001', $this->order_meta[ Constants::TRACKING_NUMBER_META_KEY ] ?? null );
		self::assertSame( 1, $this->order_saves );
		self::assertSame( array( self::ORDER_ID ), $this->orders_asked_for );
	}

	/**
	 * The same delivery twice: the second answers redelivery discarded, nothing
	 * is written a second time and no claim is released.
	 *
	 * @return void
	 */
	public function test_the_same_delivery_twice_is_discarded_the_second_time_and_the_order_does_not_change_twice(): void {
		$body              = $this->body( 'shipped-with-tracking.json' );
		$signature         = $this->signature( $body );
		$this->order_found = $this->order();

		$first = $this->createMock( InboundDeliveryLedger::class );
		$first->method( 'claim' )->willReturn( true );

		$accepted = $this->handle( $body, $signature, $first );

		$meta_after_first  = $this->order_meta;
		$saves_after_first = $this->order_saves;

		$second = $this->createMock( InboundDeliveryLedger::class );
		$second->expects( self::once() )->method( 'claim' )->willReturn( false );
		$second->expects( self::never() )->method( 'mark_applied' );
		$second->expects( self::never() )->method( 'forget' );

		$discarded = $this->handle( $body, $signature, $second );

		self::assertSame( InboundResponseStatus::ACCEPTED, $accepted->get_status() );
		self::assertSame( InboundResponseStatus::DUPLICATE_DISCARDED, $discarded->get_status() );
		self::assertSame(
			InboundResponseStatus::CLASSIFICATION_PUBLISHED,
			InboundResponseStatus::classification_of( $discarded->get_status() ),
			'A discarded redelivery is PUBLISHED: the effect is in the shop, it just got there on the first delivery.'
		);
		self::assertSame( $meta_after_first, $this->order_meta, 'The order must not change a second time.' );
		self::assertSame( $saves_after_first, $this->order_saves );
	}

	/**
	 * An order identifier that resolves to no order of this shop answers
	 * unknown, claims nothing and looks for nothing similar.
	 *
	 * @return void
	 */
	public function test_an_unknown_remote_order_answers_unknown_and_claims_nothing(): void {
		$body              = $this->body( 'shipped-with-tracking.json' );
		$this->order_found = false;

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNKNOWN, $response->get_status() );
		self::assertSame( array( self::ORDER_ID ), $this->orders_asked_for, 'Exactly one lookup, by the identifier the notice carried.' );
		self::assertSame( array(), $this->order_meta );
	}

	/**
	 * An order identifier that is not a plain positive integer resolves to
	 * nothing, and is NOT trimmed into one that would resolve to somebody
	 * else's order.
	 *
	 * @return void
	 */
	public function test_an_order_identifier_that_is_not_a_plain_number_is_never_trimmed_into_one(): void {
		$body = $this->body_with( array( InboundStatusNotice::FIELD_ORDER_ID => '1042-from-another-shop' ) );

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNKNOWN, $response->get_status() );
		self::assertSame(
			array(),
			$this->orders_asked_for,
			'Casting "1042-from-another-shop" down to 1042 hands the order of one buyer to another.'
		);
	}

	/**
	 * A status outside the four answers malformed, claims nothing and changes
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_status_outside_the_four_answers_malformed_and_claims_nothing(): void {
		$body              = $this->body_with( array( InboundStatusNotice::FIELD_STATUS => 'delivery_failed' ) );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame( array(), $this->order_meta );
		self::assertSame( 0, $this->order_saves );
	}

	/**
	 * A body with no idempotency key is malformed, never a new delivery.
	 *
	 * @return void
	 */
	public function test_a_body_without_an_idempotency_key_is_malformed_and_never_a_new_delivery(): void {
		$document = $this->document( 'shipped-with-tracking.json' );
		unset( $document[ InboundStatusNotice::FIELD_IDEMPOTENCY_KEY ] );

		$body = (string) json_encode( $document );

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
	}

	/**
	 * A body that is not JSON at all is malformed, and nothing is claimed.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_a_json_object_is_malformed(): void {
		$body = 'not json at all';

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
	}

	/**
	 * An idempotency key longer than the column that holds it is answered as
	 * malformed BEFORE the claim, so that the ledger never has to decide what a
	 * truncated key means.
	 *
	 * @return void
	 */
	public function test_an_idempotency_key_longer_than_the_column_is_malformed_before_claiming(): void {
		$long = str_repeat( 'k', InboundDeliveryTable::KEY_MAX_LENGTH + 1 );
		$body = $this->body_with( array( InboundStatusNotice::FIELD_IDEMPOTENCY_KEY => $long ) );

		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame( array(), $this->order_meta );
	}

	/**
	 * A disabled receiver applies nothing, registers no key and says so.
	 *
	 * The key is the half that matters: registering it would answer the
	 * sender's retry - after the merchant switched the receiver on - as a
	 * discarded redelivery, which the sender reads as PUBLISHED, and the notice
	 * would be lost with both sides believing it arrived.
	 *
	 * @return void
	 */
	public function test_a_disabled_receiver_applies_nothing_and_registers_no_key(): void {
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$body              = $this->body( 'shipped-with-tracking.json' );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::RECEIVER_DISABLED, $response->get_status() );
		self::assertNotNull( $response->get_data(), 'Refusing to say «this receiver is off» denies it to the one person who can turn it on.' );
		self::assertSame( array(), $this->order_meta );
		self::assertSame( 0, $this->order_saves );
		self::assertSame( array(), $this->orders_asked_for );
	}

	/**
	 * A receiver whose switch was never written at all is disabled: the
	 * fallback is the disabled literal and never `false`.
	 *
	 * @return void
	 */
	public function test_a_switch_that_was_never_written_is_disabled(): void {
		unset( $this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] );

		$body = $this->body( 'shipped-with-tracking.json' );

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::RECEIVER_DISABLED, $response->get_status() );
	}

	/**
	 * The switch is read AFTER the signature, so an unauthenticated probe
	 * cannot learn whether this shop has the receiver switched on.
	 *
	 * @return void
	 */
	public function test_an_unsigned_delivery_to_a_disabled_receiver_still_answers_unauthorised(): void {
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$body = $this->body( 'shipped-with-tracking.json' );

		$response = $this->handle( $body, 'not-the-signature', $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
	}

	/**
	 * A notice older than the one already applied is discarded in silence, and
	 * that is NOT a failure: it is answered as accepted and its claim stays.
	 *
	 * @return void
	 */
	public function test_a_stale_notice_is_accepted_and_its_claim_is_not_released(): void {
		$this->order_found = $this->order(
			array( Constants::FULFILMENT_APPLIED_AT_META_KEY => '2026-09-24T16:45:00+00:00' )
		);

		$body = $this->body( 'shipped-with-tracking.json' );

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'mark_applied' );
		$ledger->expects( self::never() )->method( 'forget' );

		$response = $this->handle( $body, $this->signature( $body ), $ledger );

		self::assertSame( InboundResponseStatus::ACCEPTED, $response->get_status() );
		self::assertSame( 0, $this->order_saves, 'A stale notice writes nothing at all.' );
	}

	/**
	 * An effect that fails after the key was claimed releases the claim and
	 * leaves as a server error, which the sender classifies as retryable.
	 *
	 * @return void
	 */
	public function test_a_failing_effect_releases_the_claim_and_escapes_as_a_server_error(): void {
		$this->save_fails  = true;
		$this->order_found = $this->order();

		$body = $this->body( 'shipped-with-tracking.json' );

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'forget' )->with( $this->key_of( $body ) );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$this->expectException( RuntimeException::class );

		$this->handle( $body, $this->signature( $body ), $ledger );
	}

	/**
	 * A ledger that cannot answer whether the delivery is new lets its failure
	 * out, and nothing is released: there is no claim to release, and deleting
	 * by that key would delete somebody else's row.
	 *
	 * @return void
	 */
	public function test_a_broken_ledger_escapes_without_releasing_anything(): void {
		$this->order_found = $this->order();

		$body = $this->body( 'shipped-with-tracking.json' );

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willThrowException( new RuntimeException( 'The ledger table is missing from this installation.' ) );
		$ledger->expects( self::never() )->method( 'forget' );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$this->expectException( RuntimeException::class );

		$this->handle( $body, $this->signature( $body ), $ledger );
	}

	/**
	 * Every published example body of this receiver is read and applied, as the
	 * bytes the file holds.
	 *
	 * @return void
	 */
	public function test_every_published_example_body_is_read_and_applied(): void {
		$examples = glob( self::FIXTURE_DIRECTORY . '/*.json' );

		self::assertIsArray( $examples );
		self::assertCount( 5, $examples, 'The four fulfilment states plus the shipment without tracking.' );

		$states = array();

		foreach ( $examples as $example ) {
			$body              = (string) file_get_contents( $example );
			$this->order_found = $this->order();

			$ledger = $this->createMock( InboundDeliveryLedger::class );
			$ledger->method( 'claim' )->willReturn( true );

			$response = $this->handle( $body, $this->signature( $body ), $ledger );

			self::assertSame(
				InboundResponseStatus::ACCEPTED,
				$response->get_status(),
				sprintf( 'The published example %s has to be readable by the receiver that publishes it.', basename( $example ) )
			);

			$states[] = (string) ( $this->order_meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? '' );
		}

		sort( $states );

		self::assertSame(
			array( 'delivered', 'prepared', 'returned', 'shipped', 'shipped' ),
			$states,
			'The examples have to cover the four states the contract freezes.'
		);
	}

	/**
	 * The shipment without tracking is applied and leaves no empty tracking
	 * datum behind.
	 *
	 * @return void
	 */
	public function test_a_shipment_without_tracking_is_applied_without_empty_tracking_data(): void {
		$body              = $this->body( 'shipped-without-tracking.json' );
		$this->order_found = $this->order();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );

		$response = $this->handle( $body, $this->signature( $body ), $ledger );

		self::assertSame( InboundResponseStatus::ACCEPTED, $response->get_status() );
		self::assertSame( FulfilmentState::SHIPPED, $this->order_meta[ Constants::FULFILMENT_STATUS_META_KEY ] ?? null );
		self::assertArrayNotHasKey( Constants::TRACKING_NUMBER_META_KEY, $this->order_meta );
		self::assertArrayNotHasKey( Constants::TRACKING_CARRIER_META_KEY, $this->order_meta );
		self::assertArrayNotHasKey( Constants::TRACKING_URL_META_KEY, $this->order_meta );
	}

	/**
	 * Every status this receiver answers belongs to the frozen table, and none
	 * of them is invented here.
	 *
	 * @return void
	 */
	public function test_every_status_this_receiver_answers_belongs_to_the_frozen_table(): void {
		$observed = array();

		$body = $this->body( 'shipped-with-tracking.json' );

		$observed[] = $this->handle( $body, 'not-the-signature', $this->ledger_that_is_never_touched() )->get_status();

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
		$observed[] = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() )->get_status();
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;

		$this->order_found = false;
		$observed[]        = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() )->get_status();

		$malformed  = $this->body_with( array( InboundStatusNotice::FIELD_STATUS => 'delivery_failed' ) );
		$observed[] = $this->handle( $malformed, $this->signature( $malformed ), $this->ledger_that_is_never_touched() )->get_status();

		$this->order_found = $this->order();

		$claimed = $this->createMock( InboundDeliveryLedger::class );
		$claimed->method( 'claim' )->willReturn( true );
		$observed[] = $this->handle( $body, $this->signature( $body ), $claimed )->get_status();

		$taken = $this->createMock( InboundDeliveryLedger::class );
		$taken->method( 'claim' )->willReturn( false );
		$observed[] = $this->handle( $body, $this->signature( $body ), $taken )->get_status();

		self::assertSame(
			array(
				InboundResponseStatus::UNAUTHORISED,
				InboundResponseStatus::RECEIVER_DISABLED,
				InboundResponseStatus::UNKNOWN,
				InboundResponseStatus::MALFORMED,
				InboundResponseStatus::ACCEPTED,
				InboundResponseStatus::DUPLICATE_DISCARDED,
			),
			$observed
		);

		foreach ( $observed as $status ) {
			self::assertNotNull(
				InboundResponseStatus::classification_of( $status ),
				sprintf( 'The status %d is outside the frozen table, so the sender would classify it by its fallback.', $status )
			);
		}
	}

	/**
	 * No answer of this receiver, refusal or acceptance, carries the signing
	 * secret, the expected signature or the presented one.
	 *
	 * @return void
	 */
	public function test_no_answer_carries_the_secret_or_either_signature(): void {
		$body      = $this->body( 'shipped-with-tracking.json' );
		$presented = $this->signature( $body );

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$answers = array(
			$this->handle( $body, 'not-the-signature', $this->ledger_that_is_never_touched() ),
			$this->handle( $body, $presented, $this->ledger_that_is_never_touched() ),
		);

		foreach ( $answers as $answer ) {
			$rendered = (string) json_encode( $answer->get_data() );

			self::assertStringNotContainsString( self::SECRET, $rendered );
			self::assertStringNotContainsString( $presented, $rendered );
		}
	}

	/**
	 * THE LOOP, CLOSED. After a status notice and a refund notice have been
	 * applied, the outbox has gained nothing.
	 *
	 * How that is measured without a database: the outbox is a table of this
	 * plugin and every row of it is written through the WordPress database
	 * handle, so a handle that refuses every call and is never called is the
	 * mechanical statement of «no entry was written». The two other ways an
	 * entry could have appeared are covered alongside: the order never receives
	 * a native status write - which is what fires the status captor - and the
	 * refund created carries the anti-loop mark the refund captor reads.
	 *
	 * @return void
	 */
	public function test_applying_a_status_and_a_refund_notice_adds_nothing_to_the_outbox(): void {
		$database = Mockery::mock( 'wpdb' );
		$database->shouldNotReceive( 'insert' );
		$database->shouldNotReceive( 'replace' );
		$database->shouldNotReceive( 'update' );
		$database->shouldNotReceive( 'query' );
		$database->shouldNotReceive( 'prepare' );
		$database->shouldNotReceive( 'get_results' );
		$database->shouldNotReceive( 'get_row' );

		$GLOBALS['wpdb'] = $database;

		$this->order_found = $this->order();

		$status_body   = $this->body( 'shipped-with-tracking.json' );
		$status_ledger = $this->createMock( InboundDeliveryLedger::class );
		$status_ledger->method( 'claim' )->willReturn( true );

		$status_answer = $this->handle( $status_body, $this->signature( $status_body ), $status_ledger );

		$refund_body   = (string) file_get_contents( __DIR__ . '/../fixtures/inbound/order-refund/partial-with-corrective-reference.json' );
		$refund_ledger = $this->createMock( InboundDeliveryLedger::class );
		$refund_ledger->method( 'claim' )->willReturn( true );

		$refund_mark = null;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'wc_create_refund' )->alias(
			function ( array $arguments ) use ( &$refund_mark ) {
				unset( $arguments );

				$refund = Mockery::mock( 'WC_Order_Refund' );
				$refund->shouldReceive( 'update_meta_data' )->andReturnUsing(
					function ( string $key, $value ) use ( &$refund_mark ): void {
						if ( Constants::INBOUND_REFUND_META_KEY === $key ) {
							$refund_mark = $value;
						}
					}
				);
				$refund->shouldReceive( 'get_id' )->andReturn( 9001 );
				$refund->shouldReceive( 'save' )->andReturn( 9001 );

				foreach ( $this->registered_actions as $action ) {
					if ( RefundApplier::CREATE_REFUND_HOOK === $action['hook'] ) {
						( $action['callback'] )( $refund );
					}
				}

				return $refund;
			}
		);

		$refund_answer = ( new OrderRefundEndpoint( null, $refund_ledger ) )->handle(
			$this->request( $refund_body, $this->signature( $refund_body ) )
		);

		unset( $GLOBALS['wpdb'] );

		self::assertSame( InboundResponseStatus::ACCEPTED, $status_answer->get_status() );
		self::assertSame( InboundResponseStatus::ACCEPTED, $refund_answer->get_status() );
		self::assertSame(
			'wc-refund-019e0000-0000-7000-8000-000000000042',
			$refund_mark,
			'The refund the receiver created has to carry the anti-loop mark, or the refund captor delivers it straight back.'
		);
	}

	/**
	 * Runs the receiver over a body and a presented signature.
	 *
	 * @param string                        $raw_body  Bytes of the body.
	 * @param string|null                   $signature Value of the signature header, null when absent.
	 * @param InboundDeliveryLedger&MockObject $ledger Ledger double.
	 * @return object The response, answering get_status() and get_data().
	 */
	private function handle( string $raw_body, ?string $signature, $ledger ): object {
		return ( new OrderStatusEndpoint( null, $ledger ) )->handle( $this->request( $raw_body, $signature ) );
	}

	/**
	 * A request double that answers the two getters the receiver reads.
	 *
	 * @param string      $raw_body  Bytes of the body.
	 * @param string|null $signature Value of the signature header, null when absent.
	 * @return MockInterface
	 */
	private function request( string $raw_body, ?string $signature ): MockInterface {
		$request = Mockery::mock( 'WP_REST_Request' );

		$request->shouldReceive( 'get_body' )->andReturn( $raw_body );
		$request->shouldReceive( 'get_header' )->with( Constants::INBOUND_SIGNATURE_HEADER )->andReturn( $signature );

		return $request;
	}

	/**
	 * A ledger that must not be touched at all.
	 *
	 * @return InboundDeliveryLedger&MockObject
	 */
	private function ledger_that_is_never_touched() {
		$ledger = $this->createMock( InboundDeliveryLedger::class );

		$ledger->expects( self::never() )->method( 'claim' );
		$ledger->expects( self::never() )->method( 'mark_applied' );
		$ledger->expects( self::never() )->method( 'forget' );

		return $ledger;
	}

	/**
	 * The bytes of one published example body.
	 *
	 * @param string $file Name of the example file.
	 * @return string
	 */
	private function body( string $file ): string {
		return (string) file_get_contents( self::FIXTURE_DIRECTORY . '/' . $file );
	}

	/**
	 * One published example body, decoded.
	 *
	 * @param string $file Name of the example file.
	 * @return array<string, mixed>
	 */
	private function document( string $file ): array {
		$decoded = json_decode( $this->body( $file ), true );

		self::assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * A body built from the published shipped example with some fields
	 * replaced, so that every malformed case differs from a valid one in
	 * exactly the way it names.
	 *
	 * @param array<string, mixed> $replacements Fields to replace.
	 * @return string
	 */
	private function body_with( array $replacements ): string {
		return (string) json_encode( array_merge( $this->document( 'shipped-with-tracking.json' ), $replacements ) );
	}

	/**
	 * The idempotency key a body declares.
	 *
	 * @param string $raw_body Bytes of the body.
	 * @return string
	 */
	private function key_of( string $raw_body ): string {
		$decoded = json_decode( $raw_body, true );

		self::assertIsArray( $decoded );

		return (string) $decoded[ InboundStatusNotice::FIELD_IDEMPOTENCY_KEY ];
	}

	/**
	 * The signature of a body, computed with the plugin's own signer so that
	 * these cases are anchored to the scheme rather than to a copy of it. The
	 * scheme itself is anchored to the platform by
	 * `InboundSignatureVerifierTest` against the frozen vector.
	 *
	 * @param string      $raw_body Bytes to sign.
	 * @param string|null $secret   Secret to sign with; the shop's own by default.
	 * @return string
	 */
	private function signature( string $raw_body, ?string $secret = null ): string {
		return ( new DeliverySigner() )->sign( $raw_body, $secret ?? self::SECRET );
	}

	/**
	 * An order double whose meta really is written, read back and deleted.
	 *
	 * @param array<string, mixed> $existing_meta Meta the order already carries.
	 * @return MockInterface
	 */
	private function order( array $existing_meta = array() ): MockInterface {
		$this->order_meta  = $existing_meta;
		$this->order_saves = 0;
		$this->order_notes = array();

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->order_meta[ $key ] = $value;
			}
		);

		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->order_meta[ $key ] );
			}
		);

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->order_meta[ $key ] ?? '';
			}
		);

		$order->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				if ( $this->save_fails ) {
					throw new RuntimeException( 'The order store refused the write.' );
				}

				++$this->order_saves;

				return self::ORDER_ID;
			}
		);

		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->order_notes[] = $note;

				return count( $this->order_notes );
			}
		);

		// The prohibition, made mechanical: either of these fires
		// `woocommerce_order_status_changed` and sends a delivery back out.
		$order->shouldNotReceive( 'set_status' );
		$order->shouldNotReceive( 'update_status' );

		return $order;
	}
}

/**
 * Minimal stand-in for `WP_REST_Response`.
 *
 * Not final, so a later phase can double it; it answers only what the receivers
 * of this package put into a response and what these cases read back.
 */
class FakeStatusRestResponse {

	/**
	 * Payload of the response.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Builds one the way WordPress does.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * The payload.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * The status.
	 *
	 * @return int
	 */
	public function get_status(): int {
		return $this->status;
	}
}
