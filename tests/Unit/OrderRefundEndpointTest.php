<?php
/**
 * Unit test of the inbound refund receiver.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
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
 * Stand-in for `WP_REST_Response`, guarded and aliased for the reason the two
 * sibling endpoint tests write down: the package runs as ONE PHPUnit suite, so
 * the class is declared under a name of its own and only the first file loaded
 * installs the alias. Every one of them answers `get_status()` and
 * `get_data()`, which is all any of these cases reads.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( FakeRefundRestResponse::class, 'WP_REST_Response' );
}

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE ROUTE, which is a SECOND route and not a branch inside the first. The
 *     frozen contract of the platform publishes two addresses
 *     (`store-order-status-receiver-contract.md` §2) and this case asserts that
 *     this receiver answers on the refund one.
 *
 *  2. THE SHAPE IT ACCEPTS, which is EXACTLY the refund notice. A status notice
 *     delivered here is malformed, and so is a refund notice delivered to the
 *     status receiver: one body per route, checked by the factory of that
 *     route's own contract type, so neither receiver has to guess what it is
 *     validating before it validates it.
 *
 *  3. THE SAME ORDER OF CHECKS AND THE SAME ANSWER TABLE as the status
 *     receiver. The two are asserted here side by side, because two receivers
 *     that drift apart do not fail at the boundary: they answer the same
 *     sender two different things about the same kind of refusal.
 *
 *  4. THE FAILURE CHANNEL BY TYPE, which is what the applier and this receiver
 *     agreed on: a permanent failure leaves as
 *     `InboundPayloadNotReadableException` and is answered malformed, anything
 *     else leaves as a server error the sender retries - and BOTH release the
 *     claim, so the retry that does come back is not discarded as a redelivery.
 */
final class OrderRefundEndpointTest extends TestCase {

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
	private const FIXTURE_DIRECTORY = __DIR__ . '/../fixtures/inbound/order-refund';

	/**
	 * Options the doubled `get_option` answers from.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Meta written on the order double.
	 *
	 * @var array<string, mixed>
	 */
	private array $order_meta = array();

	/**
	 * Meta written on the refund double.
	 *
	 * @var array<string, mixed>
	 */
	private array $refund_meta = array();

	/**
	 * Arguments the doubled `wc_create_refund` was called with.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $refund_arguments = null;

	/**
	 * How many refunds the doubled `wc_create_refund` created.
	 *
	 * @var int
	 */
	private int $refunds_created = 0;

	/**
	 * What the doubled `wc_create_refund` answers with; null means «the refund
	 * double», anything else is answered as it is.
	 *
	 * @var mixed
	 */
	private $refund_answer = null;

	/**
	 * What the doubled `wc_get_order` answers with.
	 *
	 * @var mixed
	 */
	private $order_found = null;

	/**
	 * Identifiers the doubled `wc_get_order` was asked for.
	 *
	 * @var list<mixed>
	 */
	private array $orders_asked_for = array();

	/**
	 * Callbacks the doubled `add_action` recorded, keyed by hook.
	 *
	 * @var array<string, list<callable>>
	 */
	private array $registered_actions = array();

	/**
	 * Installs the doubles shared by every case.
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
		);

		$this->order_meta         = array();
		$this->refund_meta        = array();
		$this->refund_arguments   = null;
		$this->refunds_created    = 0;
		$this->refund_answer      = null;
		$this->order_found        = null;
		$this->orders_asked_for   = array();
		$this->registered_actions = array();

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
				unset( $priority, $accepted_args );

				$this->registered_actions[ $hook ][] = $callback;

				return true;
			}
		);

		Functions\when( 'remove_action' )->justReturn( true );

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

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof FakeRefundRefusal;
			}
		);

		Functions\when( 'wc_create_refund' )->alias(
			function ( array $arguments ) {
				++$this->refunds_created;
				$this->refund_arguments = $arguments;

				$refund = $this->refund();

				// What WooCommerce does before it saves the refund, which is
				// where the anti-loop mark has to land.
				foreach ( $this->registered_actions[ RefundApplier::CREATE_REFUND_HOOK ] ?? array() as $marker ) {
					$marker( $refund );
				}

				return null === $this->refund_answer ? $refund : $this->refund_answer;
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
		$endpoint = new OrderRefundEndpoint();

		$endpoint->register();

		self::assertSame(
			array( 'rest_api_init' => array( array( $endpoint, 'register_route' ) ) ),
			$this->registered_actions
		);
	}

	/**
	 * The refund receiver has a ROUTE OF ITS OWN, which is the frozen one, and
	 * it is not the route of the status receiver.
	 *
	 * @return void
	 */
	public function test_the_route_is_the_frozen_refund_one_and_not_the_status_one(): void {
		$registered = $this->register_route_of( new OrderRefundEndpoint() );

		self::assertSame( Constants::INBOUND_REST_NAMESPACE, $registered['namespace'] );
		self::assertSame( '/order-refund', $registered['route'] );
		self::assertSame( Constants::INBOUND_ORDER_REFUND_ROUTE, $registered['route'] );
		self::assertNotSame( Constants::INBOUND_ORDER_STATUS_ROUTE, $registered['route'] );
		self::assertSame( 'POST', $registered['arguments']['methods'] );
		self::assertSame( '__return_true', $registered['arguments']['permission_callback'] );
		self::assertArrayNotHasKey( 'args', $registered['arguments'] );
	}

	/**
	 * The two receivers publish two DIFFERENT routes under the same namespace
	 * and the same method.
	 *
	 * @return void
	 */
	public function test_the_two_receivers_publish_two_different_routes(): void {
		$status = $this->register_route_of( new OrderStatusEndpoint() );
		$refund = $this->register_route_of( new OrderRefundEndpoint() );

		self::assertSame( $status['namespace'], $refund['namespace'] );
		self::assertSame( $status['arguments']['methods'], $refund['arguments']['methods'] );
		self::assertNotSame( $status['route'], $refund['route'] );
	}

	/**
	 * A signed refund notice is applied and answered as accepted.
	 *
	 * @return void
	 */
	public function test_a_signed_refund_notice_is_applied_and_answered_as_accepted(): void {
		$body              = $this->body( 'partial-with-corrective-reference.json' );
		$this->order_found = $this->order();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->expects( self::once() )->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'mark_applied' )->with( $this->key_of( $body ) );
		$ledger->expects( self::never() )->method( 'forget' );

		$response = $this->handle( $body, $this->signature( $body ), $ledger );

		self::assertSame( InboundResponseStatus::ACCEPTED, $response->get_status() );
		self::assertNull( $response->get_data() );
		self::assertSame( 1, $this->refunds_created );
		self::assertSame( '12.34', $this->refund_arguments['amount'] ?? null, 'The amount travels as the string it arrived as.' );
		self::assertSame( 'R-2026-0007', $this->order_meta[ Constants::CORRECTIVE_REFERENCE_META_KEY ] ?? null );
		self::assertSame(
			$this->key_of( $body ),
			$this->refund_meta[ Constants::INBOUND_REFUND_META_KEY ] ?? null,
			'Without the anti-loop mark the refund captor delivers this refund straight back and a second corrective invoice is issued.'
		);
	}

	/**
	 * Both published example bodies are read and applied, as the bytes their
	 * files hold.
	 *
	 * @return void
	 */
	public function test_both_published_example_bodies_are_read_and_applied(): void {
		$examples = glob( self::FIXTURE_DIRECTORY . '/*.json' );

		self::assertIsArray( $examples );
		self::assertCount( 2, $examples, 'One example per scope, total and partial.' );

		$scopes = array();

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

			$scopes[] = (string) ( $this->order_meta[ Constants::REFUND_SCOPE_META_KEY ] ?? '' );
		}

		sort( $scopes );

		self::assertSame( array( InboundRefundNotice::SCOPE_PARTIAL, InboundRefundNotice::SCOPE_TOTAL ), $scopes );
	}

	/**
	 * Each receiver accepts EXACTLY its own shape: a status notice delivered to
	 * the refund route is malformed, and a refund notice delivered to the
	 * status route is malformed too.
	 *
	 * @return void
	 */
	public function test_each_receiver_accepts_exactly_its_own_shape(): void {
		$refund_body = $this->body( 'partial-with-corrective-reference.json' );
		$status_body = (string) file_get_contents( __DIR__ . '/../fixtures/inbound/order-status/shipped-with-tracking.json' );

		$this->order_found = $this->order();

		$status_at_the_refund_route = $this->handle( $status_body, $this->signature( $status_body ), $this->ledger_that_is_never_touched() );

		$refund_at_the_status_route = ( new OrderStatusEndpoint( null, $this->ledger_that_is_never_touched() ) )->handle(
			$this->request( $refund_body, $this->signature( $refund_body ) )
		);

		self::assertSame( InboundResponseStatus::MALFORMED, $status_at_the_refund_route->get_status() );
		self::assertSame( InboundResponseStatus::MALFORMED, $refund_at_the_status_route->get_status() );
		self::assertSame( 0, $this->refunds_created );
	}

	/**
	 * An amount that is not a decimal string is malformed and refunds nothing:
	 * refunding an amount nobody can read would move money on a guess.
	 *
	 * @return void
	 */
	public function test_an_amount_that_is_not_a_decimal_string_is_malformed_and_refunds_nothing(): void {
		$body              = $this->body_with( array( InboundRefundNotice::FIELD_AMOUNT => '12,34' ) );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame( 0, $this->refunds_created );
	}

	/**
	 * A scope outside the two is malformed.
	 *
	 * @return void
	 */
	public function test_a_scope_outside_the_two_is_malformed(): void {
		$body              = $this->body_with( array( InboundRefundNotice::FIELD_SCOPE => 'partially_total' ) );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame( 0, $this->refunds_created );
	}

	/**
	 * A tampered signature comes back as a bare 401, refunds nothing and claims
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_signature_that_does_not_correspond_answers_a_bare_unauthorised(): void {
		$body              = $this->body( 'partial-with-corrective-reference.json' );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body . ' ' ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
		self::assertNull( $response->get_data() );
		self::assertSame( 0, $this->refunds_created );
		self::assertSame( array(), $this->orders_asked_for );
	}

	/**
	 * The refund receiver is governed by the SAME switch as the status
	 * receiver: the two addresses of this lane are one feature, and the shop
	 * that turns the lane on turns both on.
	 *
	 * @return void
	 */
	public function test_a_disabled_receiver_refunds_nothing_and_registers_no_key(): void {
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$body              = $this->body( 'partial-with-corrective-reference.json' );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::RECEIVER_DISABLED, $response->get_status() );
		self::assertNotNull( $response->get_data() );
		self::assertSame( 0, $this->refunds_created );
		self::assertSame( array(), $this->orders_asked_for );
	}

	/**
	 * An order this shop does not have answers unknown, refunds nothing and
	 * looks for nothing similar.
	 *
	 * @return void
	 */
	public function test_an_unknown_remote_order_answers_unknown_and_refunds_nothing(): void {
		$body              = $this->body( 'partial-with-corrective-reference.json' );
		$this->order_found = null;

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNKNOWN, $response->get_status() );
		self::assertSame( array( self::ORDER_ID ), $this->orders_asked_for );
		self::assertSame( 0, $this->refunds_created );
	}

	/**
	 * The same refund twice: the second is discarded as a redelivery, no second
	 * refund is created and no claim is released.
	 *
	 * @return void
	 */
	public function test_the_same_refund_twice_creates_one_refund(): void {
		$body              = $this->body( 'partial-with-corrective-reference.json' );
		$signature         = $this->signature( $body );
		$this->order_found = $this->order();

		$first = $this->createMock( InboundDeliveryLedger::class );
		$first->method( 'claim' )->willReturn( true );

		$accepted = $this->handle( $body, $signature, $first );

		$second = $this->createMock( InboundDeliveryLedger::class );
		$second->expects( self::once() )->method( 'claim' )->willReturn( false );
		$second->expects( self::never() )->method( 'forget' );
		$second->expects( self::never() )->method( 'mark_applied' );

		$discarded = $this->handle( $body, $signature, $second );

		self::assertSame( InboundResponseStatus::ACCEPTED, $accepted->get_status() );
		self::assertSame( InboundResponseStatus::DUPLICATE_DISCARDED, $discarded->get_status() );
		self::assertSame( 1, $this->refunds_created, 'A refund applied twice is money reflected twice.' );
	}

	/**
	 * An idempotency key longer than the column is malformed before the claim.
	 *
	 * @return void
	 */
	public function test_an_idempotency_key_longer_than_the_column_is_malformed_before_claiming(): void {
		$long              = str_repeat( 'k', InboundDeliveryTable::KEY_MAX_LENGTH + 1 );
		$body              = $this->body_with( array( InboundRefundNotice::FIELD_IDEMPOTENCY_KEY => $long ) );
		$this->order_found = $this->order();

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame( 0, $this->refunds_created );
	}

	/**
	 * A shop that refused to record the refund releases the claim and leaves as
	 * a server error, which the sender classifies as retryable and brings back.
	 *
	 * @return void
	 */
	public function test_a_shop_that_refuses_the_refund_releases_the_claim_and_escapes_as_a_server_error(): void {
		$this->refund_answer = new FakeRefundRefusal();
		$this->order_found   = $this->order();

		$body = $this->body( 'partial-with-corrective-reference.json' );

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'forget' )->with( $this->key_of( $body ) );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$this->expectException( RuntimeException::class );

		$this->handle( $body, $this->signature( $body ), $ledger );
	}

	/**
	 * A PERMANENT failure of the applier releases the claim and is answered as
	 * malformed, not as a server error: repeating the same body would fail
	 * identically, and a retryable answer would burn the sender's whole retry
	 * budget on it.
	 *
	 * WHY THE EXCEPTION IS RAISED FROM THE SHOP FUNCTION. The applier's own
	 * permanent channel is its amount guard, and that guard is unreachable
	 * through this receiver by construction: the frozen factory already refuses
	 * a body whose amount is not a decimal string, which is asserted two cases
	 * above. What this receiver promises is a function of the exception TYPE, so
	 * the case raises that type from inside the call the applier makes - the one
	 * seam a `final` collaborator leaves - and asserts the promise.
	 *
	 * @return void
	 */
	public function test_a_permanent_failure_of_the_applier_releases_the_claim_and_answers_malformed(): void {
		$this->order_found = $this->order();

		$body = $this->body( 'partial-with-corrective-reference.json' );

		Functions\when( 'wc_create_refund' )->alias(
			static function ( array $arguments ): void {
				unset( $arguments );

				throw InboundPayloadNotReadableException::field_of_wrong_kind(
					Constants::INBOUND_ORDER_REFUND_ROUTE,
					InboundRefundNotice::FIELD_AMOUNT,
					'a decimal string'
				);
			}
		);

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'forget' )->with( $this->key_of( $body ) );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$response = $this->handle( $body, $this->signature( $body ), $ledger );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertSame(
			InboundResponseStatus::CLASSIFICATION_PERMANENT,
			InboundResponseStatus::classification_of( $response->get_status() )
		);
	}

	/**
	 * A ledger that cannot answer whether the delivery is new lets its failure
	 * out and releases nothing.
	 *
	 * @return void
	 */
	public function test_a_broken_ledger_escapes_without_releasing_anything(): void {
		$this->order_found = $this->order();

		$body = $this->body( 'partial-with-corrective-reference.json' );

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willThrowException( new RuntimeException( 'The ledger table is missing from this installation.' ) );
		$ledger->expects( self::never() )->method( 'forget' );

		$this->expectException( RuntimeException::class );

		$this->handle( $body, $this->signature( $body ), $ledger );
	}

	/**
	 * The two receivers answer the same status for the same kind of refusal,
	 * and every one of those statuses is in the frozen table.
	 *
	 * @return void
	 */
	public function test_the_two_receivers_answer_the_same_status_for_the_same_refusal(): void {
		$refund_body = $this->body( 'partial-with-corrective-reference.json' );
		$status_body = (string) file_get_contents( __DIR__ . '/../fixtures/inbound/order-status/shipped-with-tracking.json' );

		$unauthorised = array(
			$this->answer_of( new OrderRefundEndpoint( null, $this->ledger_that_is_never_touched() ), $refund_body, 'not-the-signature' ),
			$this->answer_of( new OrderStatusEndpoint( null, $this->ledger_that_is_never_touched() ), $status_body, 'not-the-signature' ),
		);

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$disabled = array(
			$this->answer_of( new OrderRefundEndpoint( null, $this->ledger_that_is_never_touched() ), $refund_body, $this->signature( $refund_body ) ),
			$this->answer_of( new OrderStatusEndpoint( null, $this->ledger_that_is_never_touched() ), $status_body, $this->signature( $status_body ) ),
		);

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;
		$this->order_found = null;

		$unknown = array(
			$this->answer_of( new OrderRefundEndpoint( null, $this->ledger_that_is_never_touched() ), $refund_body, $this->signature( $refund_body ) ),
			$this->answer_of( new OrderStatusEndpoint( null, $this->ledger_that_is_never_touched() ), $status_body, $this->signature( $status_body ) ),
		);

		self::assertSame( array( InboundResponseStatus::UNAUTHORISED, InboundResponseStatus::UNAUTHORISED ), $unauthorised );
		self::assertSame( array( InboundResponseStatus::RECEIVER_DISABLED, InboundResponseStatus::RECEIVER_DISABLED ), $disabled );
		self::assertSame( array( InboundResponseStatus::UNKNOWN, InboundResponseStatus::UNKNOWN ), $unknown );

		foreach ( array_merge( $unauthorised, $disabled, $unknown ) as $status ) {
			self::assertNotNull( InboundResponseStatus::classification_of( $status ) );
		}
	}

	/**
	 * No answer of this receiver carries the signing secret or either
	 * signature.
	 *
	 * @return void
	 */
	public function test_no_answer_carries_the_secret_or_either_signature(): void {
		$body      = $this->body( 'partial-with-corrective-reference.json' );
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
	 * Registers the route of one receiver and answers what it declared.
	 *
	 * @param object $endpoint Receiver to register.
	 * @return array{namespace: string, route: string, arguments: array<string, mixed>}
	 */
	private function register_route_of( object $endpoint ): array {
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

		$endpoint->register_route();

		return $registered;
	}

	/**
	 * The status one receiver answers for a body and a presented signature.
	 *
	 * @param object      $endpoint  Receiver to run.
	 * @param string      $raw_body  Bytes of the body.
	 * @param string|null $signature Value of the signature header.
	 * @return int
	 */
	private function answer_of( object $endpoint, string $raw_body, ?string $signature ): int {
		return $endpoint->handle( $this->request( $raw_body, $signature ) )->get_status();
	}

	/**
	 * Runs the refund receiver over a body and a presented signature.
	 *
	 * @param string                           $raw_body  Bytes of the body.
	 * @param string|null                      $signature Value of the signature header, null when absent.
	 * @param InboundDeliveryLedger&MockObject $ledger    Ledger double.
	 * @return object The response, answering get_status() and get_data().
	 */
	private function handle( string $raw_body, ?string $signature, $ledger ): object {
		return ( new OrderRefundEndpoint( null, $ledger ) )->handle( $this->request( $raw_body, $signature ) );
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
	 * A body built from the published partial example with some fields
	 * replaced.
	 *
	 * @param array<string, mixed> $replacements Fields to replace.
	 * @return string
	 */
	private function body_with( array $replacements ): string {
		$document = json_decode( $this->body( 'partial-with-corrective-reference.json' ), true );

		self::assertIsArray( $document );

		return (string) json_encode( array_merge( $document, $replacements ) );
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

		return (string) $decoded[ InboundRefundNotice::FIELD_IDEMPOTENCY_KEY ];
	}

	/**
	 * The signature of a body, computed with the plugin's own signer.
	 *
	 * @param string      $raw_body Bytes to sign.
	 * @param string|null $secret   Secret to sign with; the shop's own by default.
	 * @return string
	 */
	private function signature( string $raw_body, ?string $secret = null ): string {
		return ( new DeliverySigner() )->sign( $raw_body, $secret ?? self::SECRET );
	}

	/**
	 * An order double whose meta really is written and read back.
	 *
	 * @return MockInterface
	 */
	private function order(): MockInterface {
		$this->order_meta = array();

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

		$order->shouldReceive( 'save' )->andReturn( self::ORDER_ID );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		// WooCommerce owns the order status during a refund; this receiver does
		// not write it, and writing it would fire the status captor.
		$order->shouldNotReceive( 'set_status' );
		$order->shouldNotReceive( 'update_status' );

		return $order;
	}

	/**
	 * The refund double, whose meta really is written and read back.
	 *
	 * @return MockInterface
	 */
	private function refund(): MockInterface {
		$refund = Mockery::mock( 'WC_Order_Refund' );

		$refund->shouldReceive( 'get_id' )->andReturn( 9001 );

		$refund->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->refund_meta[ $key ] = $value;
			}
		);

		$refund->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) {
				return $this->refund_meta[ $key ] ?? '';
			}
		);

		$refund->shouldReceive( 'save' )->andReturn( 9001 );

		return $refund;
	}
}

/**
 * Stand-in for the error object WooCommerce answers with when it refuses to
 * record a refund. Recognised by the doubled `is_wp_error`.
 */
class FakeRefundRefusal {

	/**
	 * The merchant-facing reason.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return 'Invalid refund amount.';
	}
}

/**
 * Minimal stand-in for `WP_REST_Response`.
 *
 * Not final, so a later phase can double it; it answers only what the receivers
 * of this package put into a response and what these cases read back.
 */
class FakeRefundRestResponse {

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
