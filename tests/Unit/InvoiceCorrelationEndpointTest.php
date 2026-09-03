<?php
/**
 * Unit test of the signed invoice correlation callback.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\MyAccount\InvoiceCorrelationEndpoint;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use Factuarea\WooCommerce\Contract\EventIdentity;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/*
 * Minimal stand-ins for the two WordPress REST classes this endpoint builds by
 * itself.
 *
 * They are declared behind `class_exists` guards, and aliased rather than
 * declared under the real name, for the reason the HTTP client test already
 * writes down: the whole package runs as ONE PHPUnit suite, so a second file
 * declaring the same class unguarded is a fatal error at load time. Neither is
 * final, so a later phase can build a Mockery double of it.
 *
 * The request is doubled with Mockery in the cases below - it is only ever
 * READ, so answering two getters is enough. The response cannot be: the code
 * under test constructs it with `new`, so there has to be a real class of that
 * name to construct.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( FakeWpRestResponse::class, 'WP_REST_Response' );
}

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE ROUTE. Registered on `rest_api_init`, at the frozen namespace and
 *     path, for POST only, with a permission callback present. The last one is
 *     asserted because WordPress serves a route registered WITHOUT a permission
 *     callback and merely complains into a log: an omission there would not
 *     close the door, it would leave it open with a note about it.
 *
 *  2. THE VERIFICATION. A correctly signed callback associates; an unsigned
 *     one, a tampered one and one arriving at a store with no secret
 *     configured do not - and all three come back with the SAME status, which
 *     is asserted rather than assumed, because "do not tell an attacker which
 *     half they got right" is a property of the answers and not of the code
 *     paths.
 *
 *  3. WHAT IS SIGNED. The signature covers the bytes that arrived, not a
 *     re-encoding of the parsed document. The case that proves it uses a body
 *     whose re-encoding differs from itself - extra spacing, fields in another
 *     order - signed over the ORIGINAL bytes, and requires it to be accepted;
 *     its twin signs the re-encoding and requires a refusal. An implementation
 *     that verified against `json_encode( json_decode( $body ) )` would pass
 *     neither, and would reject every legitimate caller in production with an
 *     unexplained 401.
 *
 *  4. WHAT IS ASSOCIATED. The invoice lands on the order that carries the key,
 *     under the frozen meta key, and the lookup that found it went through
 *     `wc_get_orders` with that key and a limit of one. An unknown key writes
 *     nothing, and neither does a malformed body.
 *
 *  5. THE SWEEP. The file logs nothing and prints nothing. It is the cheapest
 *     way to keep the prohibition on writing a credential into a log from
 *     eroding, because the first log line somebody adds "to debug this" is
 *     always the one that carries the signature.
 */
final class InvoiceCorrelationEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Signing secret of the store in every case that has one.
	 *
	 * @var string
	 */
	private const SECRET = 'a-signing-secret-of-this-store';

	/**
	 * The idempotency key a listener stored against the order.
	 *
	 * It is derived rather than typed out, so the test is anchored to the same
	 * composition the plugin uses instead of to a copy of one of its outputs.
	 *
	 * @var string
	 */
	private string $idempotency_key;

	/**
	 * Identifier of the invoice the callback carries.
	 *
	 * @var string
	 */
	private const INVOICE_ID = '0f4d4d0b-6c7b-4a35-9e2e-6a1b0a3d1c77';

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
	 * How many times the order double has been saved.
	 *
	 * @var int
	 */
	private int $order_saves = 0;

	/**
	 * Arguments the doubled `wc_get_orders` was last called with.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $order_query = null;

	/**
	 * Orders the doubled `wc_get_orders` answers with.
	 *
	 * @var array<int, mixed>
	 */
	private array $orders_found = array();

	/**
	 * Actions the doubled `add_action` recorded.
	 *
	 * @var list<array{hook: string, callback: mixed}>
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

		$this->idempotency_key = EventIdentity::idempotency_key(
			'store_01HZ',
			EventIdentity::for_paid_order( 4321 )
		);

		$this->options            = array( Constants::OPTION_SIGNING_SECRET => self::SECRET );
		$this->order_meta         = array();
		$this->order_saves        = 0;
		$this->order_query        = null;
		$this->orders_found       = array();
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

		Functions\when( 'wc_get_orders' )->alias(
			function ( array $query ): array {
				$this->order_query = $query;

				return $this->orders_found;
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
	 * The route registration is hooked where the REST server exists.
	 *
	 * @return void
	 */
	public function test_the_route_registration_is_hooked_on_rest_api_init(): void {
		$endpoint = new InvoiceCorrelationEndpoint();

		$endpoint->register();

		$this->assertSame(
			array(
				array(
					'hook'     => 'rest_api_init',
					'callback' => array( $endpoint, 'register_route' ),
				),
			),
			$this->registered_actions,
			'The route has to be registered on rest_api_init and on nothing else: register_rest_route() called before the REST server exists is discarded.'
		);
	}

	/**
	 * The route is the frozen one, it answers POST only, and it carries a
	 * permission callback.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered_for_post_with_a_permission_callback(): void {
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

		$endpoint = new InvoiceCorrelationEndpoint();
		$endpoint->register_route();

		$this->assertSame( 'factuarea/v1', $registered['namespace'] );
		$this->assertSame( '/invoice-correlation', $registered['route'] );
		$this->assertSame( 'POST', $registered['arguments']['methods'] );
		$this->assertSame( array( $endpoint, 'handle' ), $registered['arguments']['callback'] );
		$this->assertArrayHasKey(
			'permission_callback',
			$registered['arguments'],
			'A route without a permission callback is served anyway, so its absence is an open door with a note about it.'
		);
		$this->assertNotNull( $registered['arguments']['permission_callback'] );
	}

	/**
	 * The frozen names of the surface. They are read by whoever writes the
	 * sender, which lives outside this repository, so a rename here is a broken
	 * contract there rather than a refactor.
	 *
	 * @return void
	 */
	public function test_the_surface_names_are_the_frozen_ones(): void {
		$this->assertSame( 'X-Factuarea-Signature', InvoiceCorrelationEndpoint::SIGNATURE_HEADER );
		$this->assertSame( 'idempotency_key', InvoiceCorrelationEndpoint::IDEMPOTENCY_KEY_FIELD );
		$this->assertSame( 'invoice_id', InvoiceCorrelationEndpoint::INVOICE_ID_FIELD );
	}

	/**
	 * A correctly signed callback associates the invoice with the order that
	 * carries the key.
	 *
	 * @return void
	 */
	public function test_a_signed_callback_associates_the_invoice_with_its_order(): void {
		$body  = $this->body( $this->idempotency_key, self::INVOICE_ID );
		$order = $this->order();

		$this->orders_found = array( $order );

		$response = $this->handle( $body, $this->signature( $body ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( self::INVOICE_ID, $this->order_meta[ Constants::INVOICE_ID_META_KEY ] ?? null );
		$this->assertSame( 1, $this->order_saves );
	}

	/**
	 * The order was looked up by its idempotency key, through the order API and
	 * not through a post query, with a limit of one.
	 *
	 * @return void
	 */
	public function test_the_order_is_looked_up_by_its_idempotency_key_with_a_limit_of_one(): void {
		$body = $this->body( $this->idempotency_key, self::INVOICE_ID );

		$this->orders_found = array( $this->order() );

		$this->handle( $body, $this->signature( $body ) );

		$this->assertSame(
			array(
				'meta_key'   => Constants::IDEMPOTENCY_KEY_META_KEY,
				'meta_value' => $this->idempotency_key,
				'limit'      => 1,
			),
			$this->order_query
		);
	}

	/**
	 * A callback with no signature header associates nothing.
	 *
	 * @return void
	 */
	public function test_a_callback_without_a_signature_associates_nothing(): void {
		$body  = $this->body( $this->idempotency_key, self::INVOICE_ID );
		$order = $this->order();

		$this->orders_found = array( $order );

		$response = $this->handle( $body, null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * A callback whose signature was altered by one character associates
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_tampered_signature_associates_nothing(): void {
		$body      = $this->body( $this->idempotency_key, self::INVOICE_ID );
		$signature = $this->signature( $body );
		$tampered  = ( 'A' === $signature[0] ? 'B' : 'A' ) . substr( $signature, 1 );

		$this->orders_found = array( $this->order() );

		$response = $this->handle( $body, $tampered );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * A callback whose body was altered after signing associates nothing: the
	 * signature covers the bytes, so changing them invalidates it.
	 *
	 * @return void
	 */
	public function test_a_body_altered_after_signing_associates_nothing(): void {
		$signed = $this->body( $this->idempotency_key, self::INVOICE_ID );
		$sent   = $this->body( $this->idempotency_key, 'another-invoice-entirely' );

		$this->orders_found = array( $this->order() );

		$response = $this->handle( $sent, $this->signature( $signed ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * A store with no signing secret configured refuses, with the same answer
	 * as a wrong signature.
	 *
	 * Two things are asserted, and the second is the point: that the status is
	 * 401, and that it is the SAME 401 an unsigned callback gets. If an
	 * unconfigured store answered differently - a 500 from signing with an
	 * empty key, a 503, anything - the difference would tell whoever is probing
	 * which shops are worth coming back to.
	 *
	 * @return void
	 */
	public function test_a_store_without_a_signing_secret_refuses_indistinguishably(): void {
		$body = $this->body( $this->idempotency_key, self::INVOICE_ID );

		$this->orders_found = array( $this->order() );

		$unsigned = $this->handle( $body, null );

		$this->options = array();

		$unconfigured = $this->handle( $body, $this->signature( $body, self::SECRET ) );

		$this->assertSame( 401, $unconfigured->get_status() );
		$this->assertSame( $unsigned->get_status(), $unconfigured->get_status() );
		$this->assertSame( $unsigned->get_data(), $unconfigured->get_data() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * No refusal carries a body, so none of them can carry a fragment of the
	 * secret or of the expected signature.
	 *
	 * @return void
	 */
	public function test_no_refusal_carries_a_body(): void {
		$body = $this->body( $this->idempotency_key, self::INVOICE_ID );

		$this->orders_found = array();

		$refusals = array(
			'unsigned'  => $this->handle( $body, null ),
			'unknown'   => $this->handle( $body, $this->signature( $body ) ),
			'malformed' => $this->handle( '{', $this->signature( '{' ) ),
		);

		foreach ( $refusals as $case => $response ) {
			$this->assertNull( $response->get_data(), sprintf( 'The %s refusal has to answer with no body.', $case ) );
		}
	}

	/**
	 * An idempotency key no order carries associates nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_idempotency_key_associates_nothing(): void {
		$body = $this->body( 'a-key-no-order-of-this-shop-carries', self::INVOICE_ID );

		$this->orders_found = array();

		$response = $this->handle( $body, $this->signature( $body ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * A verified callback whose body is not the expected pair associates
	 * nothing, and does not even look for an order.
	 *
	 * @param string $raw_body Body that is not the expected pair.
	 * @return void
	 */
	#[DataProvider( 'malformed_bodies' )]
	public function test_a_malformed_body_associates_nothing( string $raw_body ): void {
		$this->orders_found = array( $this->order() );

		$response = $this->handle( $raw_body, $this->signature( $raw_body ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertNull( $this->order_query, 'A body that is not the expected pair must not reach the order lookup.' );
		$this->assertNothingWasAssociated();
	}

	/**
	 * The bodies that are not the expected pair.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_bodies(): array {
		return array(
			'not JSON at all'                => array( 'this is not a document' ),
			'a truncated document'           => array( '{"idempotency_key": "abc"' ),
			'a scalar'                       => array( '"just a string"' ),
			'the key alone'                  => array( '{"idempotency_key":"abc"}' ),
			'the invoice alone'              => array( '{"invoice_id":"abc"}' ),
			'an empty key'                   => array( '{"idempotency_key":"","invoice_id":"abc"}' ),
			'a blank invoice'                => array( '{"idempotency_key":"abc","invoice_id":"   "}' ),
			'a number where a string is due' => array( '{"idempotency_key":"abc","invoice_id":42}' ),
		);
	}

	/**
	 * The signature covers the bytes that arrived, not a re-encoding of the
	 * document they decode to.
	 *
	 * The body used here decodes to the same document as its own re-encoding
	 * and does NOT equal it byte for byte: the fields are in the other order and
	 * there is spacing around them. An implementation that verified the
	 * signature against `json_encode( json_decode( $body ) )` would refuse this
	 * perfectly legitimate callback, in production, on every attempt, with a
	 * 401 that names nothing.
	 *
	 * @return void
	 */
	public function test_the_signature_covers_the_bytes_that_arrived(): void {
		$as_sent = sprintf(
			'{ "invoice_id": "%s", "idempotency_key": "%s" }',
			self::INVOICE_ID,
			$this->idempotency_key
		);

		$this->assertNotSame(
			(string) re_encoded_document( $as_sent ),
			$as_sent,
			'The body of this case has to differ from its own re-encoding, or it proves nothing.'
		);

		$this->orders_found = array( $this->order() );

		$response = $this->handle( $as_sent, $this->signature( $as_sent ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( self::INVOICE_ID, $this->order_meta[ Constants::INVOICE_ID_META_KEY ] ?? null );
	}

	/**
	 * The twin of the case above: a signature computed over the re-encoding is
	 * refused, because it is not a signature of what was sent.
	 *
	 * @return void
	 */
	public function test_a_signature_over_the_re_encoded_document_is_refused(): void {
		$as_sent = sprintf(
			'{ "invoice_id": "%s", "idempotency_key": "%s" }',
			self::INVOICE_ID,
			$this->idempotency_key
		);

		$this->orders_found = array( $this->order() );

		$response = $this->handle( $as_sent, $this->signature( (string) re_encoded_document( $as_sent ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNothingWasAssociated();
	}

	/**
	 * The same callback arriving twice writes once. A sender that retries until
	 * it is acknowledged is the only kind worth having, and a full order save
	 * per retry fires every action the rest of the shop listens to.
	 *
	 * @return void
	 */
	public function test_the_same_association_arriving_twice_writes_once(): void {
		$body = $this->body( $this->idempotency_key, self::INVOICE_ID );

		$this->orders_found = array( $this->order() );

		$first  = $this->handle( $body, $this->signature( $body ) );
		$second = $this->handle( $body, $this->signature( $body ) );

		$this->assertSame( 204, $first->get_status() );
		$this->assertSame( 204, $second->get_status() );
		$this->assertSame( 1, $this->order_saves );
	}

	/**
	 * A corrected invoice replaces the one the order carried. An invoice can be
	 * superseded by a corrective document, and the last identity the merchant's
	 * own Factuarea sends is the one the buyer is to be shown.
	 *
	 * @return void
	 */
	public function test_a_different_invoice_replaces_the_one_already_associated(): void {
		$this->orders_found = array( $this->order( array( Constants::INVOICE_ID_META_KEY => 'the-superseded-one' ) ) );

		$body     = $this->body( $this->idempotency_key, self::INVOICE_ID );
		$response = $this->handle( $body, $this->signature( $body ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( self::INVOICE_ID, $this->order_meta[ Constants::INVOICE_ID_META_KEY ] );
		$this->assertSame( 1, $this->order_saves );
	}

	/**
	 * The file writes nothing to a log and prints nothing.
	 *
	 * A sweep rather than a behavioural case, because the failure it guards
	 * against is not a wrong answer: it is the debugging line somebody adds
	 * later, which is always the one that carries the signature it could not
	 * work out.
	 *
	 * @return void
	 */
	public function test_the_endpoint_neither_logs_nor_prints(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/MyAccount/InvoiceCorrelationEndpoint.php'
		);

		foreach ( array( 'error_log(', 'wc_get_logger(', 'var_dump(', 'print_r(', 'trigger_error(' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$source,
				sprintf( 'The endpoint must not call %s: the first thing such a line ever carries is the signature.', $forbidden )
			);
		}
	}

	/**
	 * Runs the handler with a body and a signature header.
	 *
	 * @param string      $raw_body  Bytes of the request body.
	 * @param string|null $signature Value of the signature header, or null when
	 *                               the header is absent.
	 * @return object The response, answering get_status() and get_data().
	 */
	private function handle( string $raw_body, ?string $signature ): object {
		$request = Mockery::mock( 'WP_REST_Request' );

		$request->shouldReceive( 'get_body' )->andReturn( $raw_body );
		$request->shouldReceive( 'get_header' )->with( InvoiceCorrelationEndpoint::SIGNATURE_HEADER )->andReturn( $signature );

		return ( new InvoiceCorrelationEndpoint() )->handle( $request );
	}

	/**
	 * The body of a well-formed callback.
	 *
	 * @param string $idempotency_key Key of the order.
	 * @param string $invoice_id      Identifier of the invoice.
	 * @return string
	 */
	private function body( string $idempotency_key, string $invoice_id ): string {
		return (string) json_encode(
			array(
				InvoiceCorrelationEndpoint::IDEMPOTENCY_KEY_FIELD => $idempotency_key,
				InvoiceCorrelationEndpoint::INVOICE_ID_FIELD => $invoice_id,
			)
		);
	}

	/**
	 * The signature of a body, computed with the plugin's own signer so that the
	 * test is anchored to the scheme rather than to a copy of it.
	 *
	 * @param string      $raw_body Bytes to sign.
	 * @param string|null $secret   Secret to sign with; the store's own by default.
	 * @return string
	 */
	private function signature( string $raw_body, ?string $secret = null ): string {
		return ( new DeliverySigner() )->sign( $raw_body, $secret ?? self::SECRET );
	}

	/**
	 * An order double whose meta really is written and read back.
	 *
	 * @param array<string, mixed> $existing_meta Meta the order already carries.
	 * @return MockInterface
	 */
	private function order( array $existing_meta = array() ): MockInterface {
		$this->order_meta = $existing_meta;

		$order = Mockery::mock( 'WC_Order' );

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

				return 4321;
			}
		);

		return $order;
	}

	/**
	 * Nothing was written onto the order and nothing was saved.
	 *
	 * @return void
	 */
	private function assertNothingWasAssociated(): void {
		$this->assertArrayNotHasKey(
			Constants::INVOICE_ID_META_KEY,
			$this->order_meta,
			'A refused callback must not associate an invoice with an order.'
		);
		$this->assertSame( 0, $this->order_saves, 'A refused callback must not save the order.' );
	}
}

/**
 * The re-encoding of a JSON body, used only to prove that a body differs from
 * it.
 *
 * It is a function of this test file and not a helper of the plugin on purpose:
 * the plugin must never perform this operation on a body it is about to verify,
 * so the only place it may exist is in the test that proves the plugin does not
 * do it.
 *
 * @param string $raw_body Body to re-encode.
 * @return string|false
 */
function re_encoded_document( string $raw_body ) {
	return json_encode( json_decode( $raw_body, true ) );
}

/**
 * A minimal stand-in for the WordPress REST response object.
 *
 * Only what this endpoint uses: a status and a payload, both readable back.
 * It is deliberately not final so that a later phase can double it.
 */
class FakeWpRestResponse {

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
