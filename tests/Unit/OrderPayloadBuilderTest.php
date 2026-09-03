<?php
/**
 * Unit tests for the order payload builder.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;
use Factuarea\WooCommerce\Payload\OrderPayloadBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/*
 * Stand-ins for the two REST classes of WordPress the builder names by their
 * global names: it constructs a request and it checks that what came back is a
 * response.
 *
 * Two rules are obeyed here, and the second one was learned the hard way, by a
 * fatal error at load time while this phase and the correlation callback phase
 * were being written at the same time.
 *
 * FIRST, the global name is taken behind a `class_exists` guard, so that
 * whichever file of this ONE suite is loaded first wins and the others use what
 * it left. SECOND, the classes of this file are named after this file rather
 * than after WordPress. A guard protects the ALIAS; it does nothing for the
 * declaration underneath it, so two files that both declare a class called
 * `FakeWpRestResponse` in this namespace bring the whole suite down no matter
 * how carefully each of them guarded its alias.
 *
 * The response double EXTENDS whatever the global name turned out to be instead
 * of being it, which is what makes this file work in either load order: it adds
 * the two questions the builder asks and that a neighbouring double has no
 * reason to answer - whether the status is an error one, and what error is
 * behind it - on top of a base it did not have to be the one to provide.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class_alias( OrderPayloadRestRequestDouble::class, 'WP_REST_Request' );
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( OrderPayloadRestResponseBase::class, 'WP_REST_Response' );
}

/**
 * What this file pins down, and why each case earns its place.
 *
 * The subject of every assertion is the DELIVERED BODY, never the order object
 * and never the storage. That is the whole point of the phase: what the receiver
 * reads is the body, so a test that asserted against an order would go green on
 * a plugin that captures a tax identifier the receiver never sees - which is the
 * exact failure this package exists to remove.
 *
 * Three things are pinned:
 *
 *  1. The body is the reference document, field by field, and the builder does
 *     not touch a single field on the way. Decoding what it returns has to give
 *     back the fixture as it stands, and the raw string has to be the bytes a
 *     native delivery would sign - not an equivalent document with another key
 *     order or another escaping, because the signature covers bytes.
 *  2. The tax identifier travels IN THE META DATA OF THE BODY under the key the
 *     connector reserves for this plugin. This is the measured fact of
 *     2026-09-02 written down as a test: WooCommerce does NOT hide meta whose
 *     key begins with an underscore from the order representation, so no mirror
 *     copy is written. The day a WooCommerce release changes that, this case
 *     goes red here instead of every business sale of every shop quietly
 *     invoicing as a simplified invoice.
 *  3. An order without a tax identifier delivers no key of this plugin at all,
 *     empty or otherwise. An empty key is worse than an absent one on the
 *     receiving side: the connector walks its catalogue taking the first key
 *     present with a non-empty value, so an empty key of ours is one it has to
 *     skip, and it reads like a captured-and-lost identifier rather than like a
 *     buyer who did not give one.
 *
 * Two more cases cover the capability grant, because the phase requires it to be
 * scoped and released in `finally` and an untested `finally` is a guardrail
 * nobody has ever seen run. And two cover the refusals, of which the second is
 * the one that matters: a 404 from the orders route converts into a perfectly
 * well-formed array, and delivering THAT as an order is a silent failure that
 * the receiver answers with a 200.
 */
final class OrderPayloadBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Filter through which the read of the order is granted.
	 */
	private const PERMISSION_FILTER = 'woocommerce_rest_check_permissions';

	/**
	 * Post type the orders route checks permissions against.
	 */
	private const ORDER_POST_TYPE = 'shop_order';

	/**
	 * Meta key the connector reserves for this plugin.
	 */
	private const TAX_ID_META_KEY = '_factuarea_tax_id';

	/**
	 * The builder under test.
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $builder;

	/**
	 * Filters added while the last build ran, in order.
	 *
	 * @var list<array{hook: string, callback: callable, priority: int, accepted: int}>
	 */
	private array $added_filters = array();

	/**
	 * Filters removed while the last build ran, in order.
	 *
	 * @var list<array{hook: string, callback: callable, priority: int}>
	 */
	private array $removed_filters = array();

	/**
	 * Installs the doubles every case shares.
	 *
	 * `wp_json_encode` is aliased to the plain encoder with no options, which is
	 * what WordPress does with it: the default escapes forward slashes and
	 * non-ASCII characters, and those escapes are part of the bytes the signature
	 * covers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->builder         = new OrderPayloadBuilder();
		$this->added_filters   = array();
		$this->removed_filters = array();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return wp_json_encode_double( $data );
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof OrderPayloadRestErrorDouble;
			}
		);

		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted = 1 ): bool {
				$this->added_filters[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
					'accepted' => $accepted,
				);

				return true;
			}
		);

		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ): bool {
				$this->removed_filters[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);

				return true;
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
	 * The body is the reference document, field by field, for both reference
	 * orders.
	 *
	 * The comparison is `assertSame` over the decoded arrays, so it is order
	 * sensitive as well as value sensitive: a builder that rebuilt the document
	 * with the same fields in another order would pass a loose comparison and
	 * still send different bytes.
	 *
	 * @param string $fixture Name of the reference body.
	 * @return void
	 */
	#[DataProvider( 'reference_bodies' )]
	public function test_the_built_body_is_the_reference_document_field_by_field( string $fixture ): void {
		$reference = $this->reference( $fixture );

		$body = $this->build_from( $reference );

		$this->assertSame(
			$reference,
			json_decode( $body, true ),
			'The delivered body has to be the order representation as WooCommerce produced it. Any difference here is a field this builder wrote, rewrote or dropped, and the receiver was built to read the document WooCommerce produces.'
		);
	}

	/**
	 * The body is the exact bytes a native delivery would sign.
	 *
	 * This is a separate case from the one above and not a duplicate of it: two
	 * documents can decode to the same array and still be different strings, and
	 * the signature covers the string. A builder that re-encoded with pretty
	 * printing, or with unescaped slashes, would pass the field-by-field case and
	 * have every delivery rejected by the receiver for a bad signature.
	 *
	 * @param string $fixture Name of the reference body.
	 * @return void
	 */
	#[DataProvider( 'reference_bodies' )]
	public function test_the_built_body_is_the_bytes_a_native_delivery_would_send( string $fixture ): void {
		$reference = $this->reference( $fixture );

		$body = $this->build_from( $reference );

		$this->assertSame(
			trim( wp_json_encode_double( json_decode( wp_json_encode_double( $reference ), true ) ) ),
			$body,
			'The body has to be encoded the way a native WooCommerce delivery encodes it: encode, decode back into an array, encode again, trim. The middle step is not decoration - it turns an empty object into an empty array, so skipping it changes bytes the signature covers.'
		);
	}

	/**
	 * The tax identifier is visible in the meta data OF THE BODY, under the key
	 * the connector reserves for this plugin.
	 *
	 * @return void
	 */
	public function test_the_tax_identifier_travels_in_the_meta_data_of_the_delivered_body(): void {
		$reference = $this->reference( 'order-paid-with-tax-id.json' );

		$delivered = json_decode( $this->build_from( $reference ), true );

		$this->assertSame(
			'B45712098',
			$this->meta_value( $delivered, self::TAX_ID_META_KEY ),
			sprintf(
				'The key "%s" has to arrive in the meta data of the delivered body with the captured value. Measured on the WooCommerce sources on 2026-09-02: the order representation does NOT hide meta whose key starts with an underscore - it drops only WooCommerce\'s own enumerated internal keys and anything starting with "wp_" - so the key survives and no mirror copy is written. If this ever goes red, the mirror is what fixes it.',
				self::TAX_ID_META_KEY
			)
		);
	}

	/**
	 * An order whose buyer gave no tax identifier delivers no key of this plugin,
	 * empty or otherwise.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_tax_identifier_carries_no_key_of_this_plugin(): void {
		$reference = $this->reference( 'order-paid-without-tax-id.json' );

		$delivered = json_decode( $this->build_from( $reference ), true );

		$this->assertNull(
			$this->meta_value( $delivered, self::TAX_ID_META_KEY ),
			sprintf( 'An order without a tax identifier must not carry "%s" at all. An empty key reads, on the receiving side, like an identifier that was captured and lost rather than like a buyer who did not give one.', self::TAX_ID_META_KEY )
		);

		foreach ( $delivered['meta_data'] as $meta ) {
			$this->assertStringNotContainsString(
				'factuarea',
				(string) $meta['key'],
				sprintf( 'The delivered body carries "%s", a key of this plugin, on an order with no tax identifier. No key of ours belongs in that body.', $meta['key'] )
			);
		}
	}

	/**
	 * The read is granted through WooCommerce's own permission filter, narrowed
	 * to reading THIS order, and everything else is passed through untouched.
	 *
	 * The last two assertions are the ones that matter: while the filter is
	 * installed it is consulted for every REST permission decision that happens,
	 * so a grant that said yes to anything would hand read access to every order
	 * of the shop to whatever else asked during that dispatch.
	 *
	 * @return void
	 */
	public function test_the_read_grant_is_narrowed_to_reading_this_one_order(): void {
		$reference = $this->reference( 'order-paid-with-tax-id.json' );

		$this->build_from( $reference );

		$this->assertCount( 1, $this->added_filters, 'The builder has to install exactly one grant.' );
		$this->assertSame( self::PERMISSION_FILTER, $this->added_filters[0]['hook'] );

		$grant = $this->added_filters[0]['callback'];

		$this->assertTrue(
			$grant( false, 'read', 7412, self::ORDER_POST_TYPE ),
			'Reading the order being built has to be granted: at the payment hook the current user is the buyer, who cannot read orders, so without this the body is never built.'
		);
		$this->assertTrue(
			$grant( false, 'read', 0, self::ORDER_POST_TYPE ),
			'WooCommerce asks the same question with no identifier when it is asking about the post type rather than about one order.'
		);
		$this->assertFalse(
			$grant( false, 'read', 9999, self::ORDER_POST_TYPE ),
			'Another order must keep the decision it already had. The filter is consulted for every permission decision taken while it is installed.'
		);
		$this->assertFalse(
			$grant( false, 'edit', 7412, self::ORDER_POST_TYPE ),
			'Only reading is granted. Building a body never writes.'
		);
		$this->assertFalse(
			$grant( false, 'read', 7412, 'product' ),
			'Only the order post type is granted.'
		);
	}

	/**
	 * The grant is released even when the dispatch blows up.
	 *
	 * A grant left installed by a failed build outlives the request that made it,
	 * and the next thing to ask that filter anything gets an answer meant for a
	 * dispatch that is long over.
	 *
	 * @return void
	 */
	public function test_the_read_grant_is_released_even_when_the_dispatch_throws(): void {
		Functions\when( 'wc_get_order' )->justReturn( Mockery::mock( 'WC_Order' ) );
		Functions\when( 'rest_do_request' )->alias(
			static function (): void {
				throw new \RuntimeException( 'another plugin exploded inside the dispatch' );
			}
		);

		try {
			$this->builder->build( 7412 );
			$this->fail( 'The exception of the dispatch has to reach the caller.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'another plugin exploded inside the dispatch', $exception->getMessage() );
		}

		$this->assertCount( 1, $this->added_filters );
		$this->assertCount( 1, $this->removed_filters, 'The grant has to be removed in `finally`, so that it is gone whether the dispatch returned, errored or threw.' );
		$this->assertSame( self::PERMISSION_FILTER, $this->removed_filters[0]['hook'] );
		$this->assertSame(
			$this->added_filters[0]['callback'],
			$this->removed_filters[0]['callback'],
			'The grant has to be removed by the same instance it was added with: WordPress identifies a closure by its object, so removing "a callback like it" removes nothing.'
		);
	}

	/**
	 * An order that cannot be read is reported as such, and nothing is dispatched.
	 *
	 * @return void
	 */
	public function test_an_order_that_cannot_be_read_is_reported_as_missing(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\expect( 'rest_do_request' )->never();

		$this->expectException( OrderPayloadNotBuildableException::class );
		$this->expectExceptionMessageMatches( '/Order 7412 could not be read/' );

		$this->builder->build( 7412 );
	}

	/**
	 * An error document is never delivered as if it were an order.
	 *
	 * This is the case that justifies not calling WooCommerce's own endpoint
	 * helper: that helper returns only the data, so a 404 comes back as a
	 * well-formed array that would be signed and sent, and the receiver would
	 * answer 200 to a body that is not an order.
	 *
	 * @return void
	 */
	public function test_an_error_document_is_never_delivered_as_an_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( Mockery::mock( 'WC_Order' ) );
		Functions\when( 'rest_do_request' )->justReturn(
			new OrderPayloadRestResponse(
				array(
					'code'    => 'woocommerce_rest_shop_order_invalid_id',
					'message' => 'Invalid ID.',
					'data'    => array( 'status' => 404 ),
				),
				404
			)
		);
		Functions\when( 'rest_get_server' )->justReturn( new OrderPayloadRestServerDouble( array() ) );

		$this->expectException( OrderPayloadNotBuildableException::class );
		$this->expectExceptionMessageMatches( '/HTTP 404 Invalid ID\./' );

		$this->builder->build( 7412 );
	}

	/**
	 * The two reference bodies.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function reference_bodies(): array {
		return array(
			'paid order of a business buyer, with tax identifier' => array( 'order-paid-with-tax-id.json' ),
			'paid order of a private buyer, without tax identifier' => array( 'order-paid-without-tax-id.json' ),
		);
	}

	/**
	 * Runs a build whose REST dispatch produces the given representation.
	 *
	 * @param array<string, mixed> $representation Order representation the route answers with.
	 * @return string The raw body the builder produced.
	 */
	private function build_from( array $representation ): string {
		Functions\when( 'wc_get_order' )->justReturn( Mockery::mock( 'WC_Order' ) );
		Functions\when( 'rest_do_request' )->justReturn( new OrderPayloadRestResponse( $representation, 200 ) );
		Functions\when( 'rest_get_server' )->justReturn( new OrderPayloadRestServerDouble( $representation ) );

		return $this->builder->build( (int) $representation['id'] );
	}

	/**
	 * Reads one reference body off disk.
	 *
	 * @param string $name File name inside the reference directory.
	 * @return array<string, mixed>
	 */
	private function reference( string $name ): array {
		$path     = __DIR__ . '/../fixtures/order-payload/' . $name;
		$contents = file_get_contents( $path );

		$this->assertIsString( $contents, sprintf( 'The reference body "%s" has to be readable.', $name ) );

		$decoded = json_decode( (string) $contents, true );

		$this->assertIsArray( $decoded, sprintf( 'The reference body "%s" has to be a JSON document.', $name ) );

		return $decoded;
	}

	/**
	 * Value of one meta key inside a delivered body, or null when the key is not
	 * there at all.
	 *
	 * @param array<string, mixed> $delivered Delivered body, decoded.
	 * @param string               $key       Meta key to look for.
	 * @return string|null
	 */
	private function meta_value( array $delivered, string $key ): ?string {
		foreach ( $delivered['meta_data'] as $meta ) {
			if ( $key === $meta['key'] ) {
				return (string) $meta['value'];
			}
		}

		return null;
	}
}

/**
 * Encodes the way `wp_json_encode` does with its default options.
 *
 * It lives outside the test class because both the double installed on
 * `wp_json_encode` and the expectation the assertions compare against have to
 * use one encoder: two encoders with different options would make the byte
 * comparison compare the test against itself.
 *
 * @param mixed $data Value to encode.
 * @return string
 */
function wp_json_encode_double( $data ): string {
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

/**
 * A minimal stand-in for the WordPress REST request.
 *
 * The builder only constructs it and hands it to the dispatcher, so it only has
 * to remember what it was built with. It is deliberately not final, and it is
 * named after this file rather than after WordPress so that it can never
 * collide with the double of a neighbouring phase.
 */
class OrderPayloadRestRequestDouble {

	/**
	 * HTTP method.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Route asked for.
	 *
	 * @var string
	 */
	private string $route;

	/**
	 * Builds one the way WordPress does.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	public function __construct( string $method = '', string $route = '' ) {
		$this->method = $method;
		$this->route  = $route;
	}

	/**
	 * The method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * The route.
	 *
	 * @return string
	 */
	public function get_route(): string {
		return $this->route;
	}
}

/**
 * The base a WordPress REST response has to have for this file to work.
 *
 * It is offered for the global name only when nothing has taken it yet. When a
 * neighbouring phase already took it, this class is simply never used, and the
 * response double below extends whatever that phase left behind. A payload and
 * a status, both readable, are all any of the doubles of this suite need in
 * common - and they are the parts a caller constructs, which is why they live
 * in the base rather than in the subclass.
 */
class OrderPayloadRestResponseBase {

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

/**
 * The response this file hands the builder.
 *
 * It extends the global REST response - whichever class of this suite ended up
 * being it - and adds the two questions the builder asks that a double written
 * for another phase has no reason to answer. Extending rather than replacing is
 * what makes this file independent of which test file PHPUnit loaded first, and
 * load order is not something a suite should ever have an opinion about.
 */
class OrderPayloadRestResponse extends \WP_REST_Response {

	/**
	 * Whether the status is an error one, exactly as WordPress decides it.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return $this->get_status() >= 400;
	}

	/**
	 * The error behind an error status, built out of the error document the
	 * REST server produces for one.
	 *
	 * @return OrderPayloadRestErrorDouble
	 */
	public function as_error(): OrderPayloadRestErrorDouble {
		$data = $this->get_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return new OrderPayloadRestErrorDouble(
			isset( $data['code'] ) ? (string) $data['code'] : '',
			isset( $data['message'] ) ? (string) $data['message'] : ''
		);
	}
}

/**
 * A minimal stand-in for the WordPress error object, local to this file.
 *
 * It is NOT aliased to the global `WP_Error`: another test file of this suite
 * already owns that alias, and two files racing for one global name is how a
 * suite starts depending on load order. The builder never type-hints the error,
 * it only asks it for its message, so a local class is enough and cannot
 * collide.
 */
class OrderPayloadRestErrorDouble {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Builds one the way WordPress does.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * The code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * The message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * A minimal stand-in for the WordPress REST server.
 *
 * It answers the one call the builder makes on it, and it answers with the data
 * it was built with rather than with the response it is handed, so that a
 * builder that read the response by some other route would still be measured
 * against the same document.
 */
class OrderPayloadRestServerDouble {

	/**
	 * Data to hand back.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Builds one.
	 *
	 * @param array<string, mixed> $data Data to hand back.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Turns a response into data, links included, the way WordPress does when it
	 * is asked not to embed.
	 *
	 * @param object $response Response to convert.
	 * @param bool   $embed    Whether to embed linked resources.
	 * @return array<string, mixed>
	 */
	public function response_to_data( $response, bool $embed = false ): array {
		unset( $response, $embed );

		return $this->data;
	}
}
