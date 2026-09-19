<?php
/**
 * Unit test of the inbound catalogue, price and stock receiver.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;
use Factuarea\WooCommerce\Exception\InboundPayloadNotReadableException;
use Factuarea\WooCommerce\Inbound\CatalogPushEndpoint;
use Factuarea\WooCommerce\Inbound\InboundDeliveryLedger;
use Factuarea\WooCommerce\Inbound\InboundDeliveryTable;
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
 * a second file declaring the same class unguarded is a fatal error at load
 * time. The class is therefore declared under a name of its own - distinct from
 * `FakeStatusRestResponse` and `FakeRefundRestResponse`, which would collide
 * with each other instead - and whichever file is loaded first installs the
 * alias. All of them answer `get_status()` and `get_data()`, which is all any of
 * these cases reads.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( FakeCatalogPushRestResponse::class, 'WP_REST_Response' );
}

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE ROUTE. Hooked on `rest_api_init`, at the frozen namespace and path,
 *     for POST only, with a permission callback present and NO declared `args`,
 *     exactly as the two sibling receivers.
 *
 *  2. THE ENVELOPE, which is the one part of this contract this phase chose.
 *     Its two keys are asserted against the names published in
 *     `docs/inbound-contract.md` §7, and the idempotency key is asserted to be
 *     spelled the way BOTH notices of this lane spell it: one lane, one name,
 *     or a sender has to carry two.
 *
 *  3. THE ORDER OF THE CHECKS, which is the contract and not an implementation
 *     detail. Signature, then switch, then the whole batch read and validated,
 *     then the claim, then the articles. EVERY permanent refusal is asserted to
 *     have claimed NOTHING: a key claimed for an effect that never landed turns
 *     the sender's next delivery of the same fact into a `409` it reads as
 *     PUBLISHED, and the batch would be lost with both sides believing it
 *     arrived.
 *
 *  4. THE TWO KINDS OF UNACCEPTED KEY, which reject the same way and are named
 *     APART. An internal figure is not a field this receiver has not learnt
 *     yet: it is a datum that must never have left the platform, and a batch
 *     carrying one is a leak that has to have a witness.
 *
 *  5. THE PER-ARTICLE OUTCOME. Three applied and two rejected out of five, with
 *     the three really applied and the two really untouched, each named the way
 *     the SENDER named it and never by an identifier of this shop.
 *
 *  6. THE LOOP, CLOSED. Applying a batch writes nothing through the database
 *     handle, which is the mechanical statement of «the outbox gained nothing».
 */
final class CatalogPushEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Signing secret of the shop in every case that has one.
	 *
	 * @var string
	 */
	private const SECRET = 'a-signing-secret-of-this-shop';

	/**
	 * Directory of the published example bodies of this receiver.
	 *
	 * @var string
	 */
	private const FIXTURE_DIRECTORY = __DIR__ . '/../fixtures/inbound/catalog-push';

	/**
	 * Identity the first article of the published batch carries.
	 *
	 * @var string
	 */
	private const FIRST_IDENTITY = '019e0000-0000-7000-8000-000000000101';

	/**
	 * Identity the fifth article of the published batch carries.
	 *
	 * @var string
	 */
	private const FIFTH_IDENTITY = '019e0000-0000-7000-8000-000000000105';

	/**
	 * Options the doubled `get_option` answers from.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Articles of the shop, keyed by identifier.
	 *
	 * @var array<int, MockInterface>
	 */
	private array $articles = array();

	/**
	 * What each article of the shop has been written with, keyed by identifier.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = array();

	/**
	 * How many times each article was saved, keyed by identifier.
	 *
	 * @var array<int, int>
	 */
	private array $saves = array();

	/**
	 * Whether each article manages its stock, keyed by identifier.
	 *
	 * @var array<int, bool>
	 */
	private array $manages_stock = array();

	/**
	 * Which article carries each registered identity.
	 *
	 * @var array<string, list<int>>
	 */
	private array $identity_matches = array();

	/**
	 * Which article each reference resolves to; zero means none.
	 *
	 * @var array<string, int>
	 */
	private array $reference_matches = array();

	/**
	 * Whether each reference is unique in the shop.
	 *
	 * @var array<string, bool>
	 */
	private array $unique_references = array();

	/**
	 * References the reference search was asked about, in order.
	 *
	 * @var list<string>
	 */
	private array $reference_searches = array();

	/**
	 * Identities the identity search was asked about, in order.
	 *
	 * @var list<string>
	 */
	private array $identity_searches = array();

	/**
	 * Identifier of the article whose save must throw, and what it throws.
	 *
	 * @var array{id: int, failure: \Throwable}|null
	 */
	private ?array $failing_save = null;

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
		Functions\stubs(
			array(
				'sanitize_text_field' => null,
				'wp_kses_post'        => null,
			)
		);

		$this->options = array(
			Constants::OPTION_SIGNING_SECRET => self::SECRET,
			Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED => SettingsPage::RECEIVER_ENABLED,
			Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED => SettingsPage::RECEIVER_ENABLED,
		);

		$this->articles           = array();
		$this->written            = array();
		$this->saves              = array();
		$this->manages_stock      = array();
		$this->identity_matches   = array();
		$this->reference_matches  = array();
		$this->unique_references  = array();
		$this->reference_searches = array();
		$this->identity_searches  = array();
		$this->failing_save       = null;
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
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);

		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);

		Functions\when( 'wc_get_product_types' )->justReturn(
			array(
				'simple'   => 'Simple product',
				'variable' => 'Variable product',
			)
		);

		Functions\when( 'wc_get_products' )->alias(
			function ( array $arguments ): array {
				$value = (string) ( $arguments['meta_value'] ?? '' );

				$this->identity_searches[] = $value;

				return array_slice(
					$this->identity_matches[ $value ] ?? array(),
					0,
					(int) ( $arguments['limit'] ?? 1 )
				);
			}
		);

		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function ( string $sku ): int {
				$this->reference_searches[] = $sku;

				return $this->reference_matches[ $sku ] ?? 0;
			}
		);

		Functions\when( 'wc_product_has_unique_sku' )->alias(
			function ( int $product_id, string $sku ): bool {
				unset( $product_id );

				return $this->unique_references[ $sku ] ?? true;
			}
		);

		Functions\when( 'wc_get_product' )->alias(
			function ( int $id ) {
				return $this->articles[ $id ] ?? false;
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
		$endpoint = new CatalogPushEndpoint();

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

		$endpoint = new CatalogPushEndpoint();
		$endpoint->register_route();

		self::assertSame( 'factuarea/v1', $registered['namespace'] );
		self::assertSame( Constants::INBOUND_REST_NAMESPACE, $registered['namespace'] );
		self::assertSame( '/catalog-push', $registered['route'] );
		self::assertSame( Constants::INBOUND_CATALOG_PUSH_ROUTE, $registered['route'] );
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
			'A declared argument schema makes WordPress refuse the batch with its own 400 before this receiver can answer its own status.'
		);
	}

	/**
	 * The envelope this phase chose is the one the contract publishes, and its
	 * idempotency key is spelled the way both notices of this lane spell it.
	 *
	 * @return void
	 */
	public function test_the_envelope_is_the_published_one_and_names_the_key_as_both_notices_do(): void {
		self::assertSame( 'items', CatalogPushEndpoint::FIELD_ITEMS );
		self::assertSame( 'idempotency_key', CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY );
		self::assertSame(
			InboundStatusNotice::FIELD_IDEMPOTENCY_KEY,
			CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY,
			'One lane, one name for the idempotency key, or a sender has to carry two spellings of one idea.'
		);
		self::assertSame(
			InboundRefundNotice::FIELD_IDEMPOTENCY_KEY,
			CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY
		);
		self::assertSame(
			array( CatalogPushEndpoint::FIELD_ITEMS, CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY ),
			CatalogPushEndpoint::ENVELOPE_KEYS,
			'The envelope enumerates its keys for the same reason the item does.'
		);
	}

	/**
	 * A tampered signature comes back as a bare 401, and no article of the shop
	 * is even looked up.
	 *
	 * @return void
	 */
	public function test_a_signature_that_does_not_correspond_answers_a_bare_unauthorised_and_changes_no_article(): void {
		$this->shop_of_the_published_batch();

		$body     = $this->body( 'batch-of-five-with-two-unresolvable.json' );
		$response = $this->handle( $body, $this->signature( $body . ' ' ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
		self::assertNull( $response->get_data(), 'The authentication refusal carries no body at all.' );
		self::assertSame( array(), $this->reference_searches, 'Nothing is resolved out of an unverified body.' );
		self::assertSame( array(), $this->identity_searches );
		self::assertSame( array(), array_filter( $this->saves ), 'No article of the shop was written.' );
	}

	/**
	 * The three ways of failing authentication are one answer, and it is the
	 * same bare 401 for all of them.
	 *
	 * @return void
	 */
	public function test_the_three_ways_of_failing_authentication_are_indistinguishable(): void {
		$body = $this->body( 'batch-of-five-with-two-unresolvable.json' );

		$absent = $this->handle( $body, null, $this->ledger_that_is_never_touched() );
		$wrong  = $this->handle( $body, 'not-the-signature', $this->ledger_that_is_never_touched() );

		unset( $this->options[ Constants::OPTION_SIGNING_SECRET ] );
		$unconfigured = $this->handle( $body, $this->signature( $body, 'whatever-the-sender-used' ), $this->ledger_that_is_never_touched() );

		foreach ( array( $absent, $wrong, $unconfigured ) as $response ) {
			self::assertSame( InboundResponseStatus::UNAUTHORISED, $response->get_status() );
			self::assertNull( $response->get_data() );
		}
	}

	/**
	 * A switched-off receiver declares itself in a body and claims nothing, so
	 * that the same delivery applies once it is switched on.
	 *
	 * @return void
	 */
	public function test_a_switched_off_receiver_declares_itself_and_claims_nothing(): void {
		$this->shop_of_the_published_batch();
		$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::RECEIVER_DISABLED, $response->get_status() );
		self::assertIsArray( $response->get_data() );
		self::assertArrayHasKey( Constants::REJECTION_BODY_MESSAGE_FIELD, $response->get_data() );
		self::assertSame( array(), $this->reference_searches, 'A switched-off receiver resolves nothing.' );
		self::assertSame( array(), array_filter( $this->saves ) );
	}

	/**
	 * Only the catalogue switch governs this receiver: the one of the other
	 * lane being on does not open this door.
	 *
	 * @return void
	 */
	public function test_the_switch_of_the_other_receiver_does_not_open_this_one(): void {
		$this->shop_of_the_published_batch();
		$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::RECEIVER_DISABLED, $response->get_status() );
	}

	/**
	 * A body that is not a JSON object is a malformed delivery, and nothing is
	 * claimed for it.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_a_json_object_is_malformed(): void {
		foreach ( array( 'not json at all', '"a string"', '[1, 2, 3]', 'null' ) as $body ) {
			$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

			self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status(), $body );
			self::assertNotSame( '', $this->message_of( $response ) );
		}
	}

	/**
	 * A batch without an idempotency key is malformed, never a new delivery.
	 *
	 * @return void
	 */
	public function test_a_batch_without_an_idempotency_key_is_malformed(): void {
		$body = $this->encode(
			array(
				CatalogPushEndpoint::FIELD_ITEMS => array( array( CatalogPushItem::FIELD_SKU => 'SKU-0001' ) ),
			)
		);

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertStringContainsString( CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY, $this->message_of( $response ) );
	}

	/**
	 * An idempotency key of the wrong kind, or empty, is malformed too.
	 *
	 * @return void
	 */
	public function test_an_idempotency_key_that_is_not_a_non_empty_string_is_malformed(): void {
		foreach ( array( 42, '', null, array( 'k' ) ) as $key ) {
			$body = $this->batch_body( array( array( CatalogPushItem::FIELD_SKU => 'SKU-0001' ) ), $key );

			$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

			self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		}
	}

	/**
	 * A key longer than the column that records it is refused BEFORE the claim:
	 * a key that does not fit cannot tell two deliveries apart.
	 *
	 * @return void
	 */
	public function test_an_idempotency_key_longer_than_the_column_is_malformed_before_claiming(): void {
		$body = $this->batch_body(
			array( array( CatalogPushItem::FIELD_SKU => 'SKU-0001' ) ),
			str_repeat( 'k', InboundDeliveryTable::KEY_MAX_LENGTH + 1 )
		);

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
	}

	/**
	 * A batch whose items are not a list - an object keyed by reference, say -
	 * is malformed as a whole.
	 *
	 * @return void
	 */
	public function test_a_batch_whose_items_are_not_a_list_is_malformed(): void {
		$body = $this->batch_body( array( 'SKU-0001' => array( CatalogPushItem::FIELD_SKU => 'SKU-0001' ) ) );

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertStringContainsString( CatalogPushEndpoint::FIELD_ITEMS, $this->message_of( $response ) );
	}

	/**
	 * A batch carrying no article at all is malformed: the sender composed
	 * nothing and shipped it, and claiming its key would answer the corrected
	 * redelivery of that same key as already published.
	 *
	 * @return void
	 */
	public function test_a_batch_with_no_articles_is_malformed(): void {
		$body = $this->batch_body( array() );

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
	}

	/**
	 * An envelope key the contract does not enumerate rejects the batch, for
	 * the same reason an unknown item key does.
	 *
	 * @return void
	 */
	public function test_an_envelope_key_the_contract_does_not_enumerate_rejects_the_batch(): void {
		$body = $this->encode(
			array(
				CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY => 'wc-catalog-0001',
				CatalogPushEndpoint::FIELD_ITEMS           => array( array( CatalogPushItem::FIELD_SKU => 'SKU-0001' ) ),
				'sent_at'                                  => '2026-09-19T10:00:00+00:00',
			)
		);

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
		self::assertStringContainsString( 'sent_at', $this->message_of( $response ) );
	}

	/**
	 * An unknown item key rejects the WHOLE batch, and the answer names it as
	 * an unknown key.
	 *
	 * @return void
	 */
	public function test_an_unknown_item_key_rejects_the_whole_batch_and_is_named_as_unknown(): void {
		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-with-an-unknown-key.json', $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );

		$message = $this->message_of( $response );

		self::assertStringContainsString( 'weight_grams', $message );
		self::assertStringContainsString( 'does not enumerate', $message );
		self::assertSame( array(), $this->reference_searches, 'No article is resolved out of a batch that is not read.' );
		self::assertSame( array(), array_filter( $this->saves ) );
	}

	/**
	 * An internal figure rejects the WHOLE batch too, and the answer names it
	 * APART from an unknown key: one is a contract that changed, the other is a
	 * leak.
	 *
	 * @return void
	 */
	public function test_an_internal_figure_rejects_the_whole_batch_and_is_named_apart(): void {
		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-with-an-internal-datum.json', $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );

		$message = $this->message_of( $response );

		self::assertStringContainsString( 'cost', $message );
		self::assertStringContainsString( 'supplier', $message );
		self::assertStringContainsString( 'never leave', $message );
		self::assertStringNotContainsString(
			'does not enumerate',
			$message,
			'An internal figure is not an unknown key, and the answer must not report it as one.'
		);
		self::assertSame( array(), array_filter( $this->saves ) );
	}

	/**
	 * A batch carrying both kinds of unaccepted key names each of them under
	 * its own heading.
	 *
	 * @return void
	 */
	public function test_a_batch_carrying_both_kinds_of_unaccepted_key_names_each_apart(): void {
		$body = $this->batch_body(
			array(
				array(
					CatalogPushItem::FIELD_SKU => 'SKU-0001',
					'cost'                     => '18.10',
					'weight_grams'             => 480,
				),
			)
		);

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );

		$message = $this->message_of( $response );

		self::assertStringContainsString( 'cost', $message );
		self::assertStringContainsString( 'weight_grams', $message );
		self::assertStringContainsString( 'never leave', $message );
		self::assertStringContainsString( 'does not enumerate', $message );
	}

	/**
	 * An item whose shape this contract cannot read - a missing reference, a
	 * price sent as a number - rejects the whole batch: it is not one of the
	 * five typed per-article reasons, it is a batch that could not be read.
	 *
	 * @return void
	 */
	public function test_an_item_that_cannot_be_read_rejects_the_whole_batch(): void {
		$unreadable = array(
			array( array( CatalogPushItem::FIELD_NAME => 'A mug with no reference' ) ),
			array(
				array(
					CatalogPushItem::FIELD_SKU   => 'SKU-0001',
					CatalogPushItem::FIELD_PRICE => 12.40,
				),
			),
			array( 'not an object at all' ),
		);

		foreach ( $unreadable as $items ) {
			$body = $this->batch_body( $items );

			$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_is_never_touched() );

			self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
			self::assertNotSame( '', $this->message_of( $response ) );
		}
	}

	/**
	 * A signed batch applies the catalogue fields, the price and the stock of
	 * an article, answers the per-article outcome and records its key.
	 *
	 * @return void
	 */
	public function test_a_signed_batch_applies_catalogue_fields_price_and_stock(): void {
		$this->shop_of_the_published_batch();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->expects( self::once() )->method( 'claim' )
			->with( 'wc-catalog-20260919T100000Z-0001', self::isType( 'int' ) )->willReturn( true );
		$ledger->expects( self::once() )->method( 'mark_applied' )
			->with( 'wc-catalog-20260919T100000Z-0001', self::isType( 'int' ) )->willReturn( true );
		$ledger->expects( self::never() )->method( 'forget' );

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $ledger );

		self::assertSame( CatalogPushEndpoint::STATUS_PER_ITEM_OUTCOME, $response->get_status() );

		self::assertSame( 'Blue enamel mug', $this->written[1]['name'] );
		self::assertSame( '<p>Half a litre, dishwasher safe.</p>', $this->written[1]['description'] );
		self::assertSame( 'publish', $this->written[1]['status'] );
		self::assertSame( '12.40', $this->written[1]['regular_price'] );
		self::assertSame( 7, $this->written[1]['stock_quantity'] );
		self::assertSame( 'instock', $this->written[1]['stock_status'] );
	}

	/**
	 * The answer of a batch is the list of per-article outcomes, under the same
	 * key the request named its articles with.
	 *
	 * @return void
	 */
	public function test_the_answer_is_the_list_of_per_article_outcomes(): void {
		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_applies() );

		$data = $response->get_data();

		self::assertIsArray( $data );
		self::assertSame( array( CatalogPushEndpoint::FIELD_ITEMS ), array_keys( $data ) );
		self::assertCount( 5, $data[ CatalogPushEndpoint::FIELD_ITEMS ] );
	}

	/**
	 * A batch of five with two unresolvable articles answers three applied and
	 * two rejected with their reason, and the three really were applied while
	 * the two really were not.
	 *
	 * @return void
	 */
	public function test_a_batch_of_five_with_two_unresolvable_answers_three_applied_and_two_rejected(): void {
		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_applies() );

		self::assertSame(
			array(
				array(
					CatalogPushOutcome::FIELD_REFERENCE => self::FIRST_IDENTITY,
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_APPLIED,
				),
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0002',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_REJECTED,
					CatalogPushOutcome::FIELD_REASON    => CatalogPushOutcome::REASON_NOT_FOUND,
				),
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0003',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_APPLIED,
				),
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0004',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_REJECTED,
					CatalogPushOutcome::FIELD_REASON    => CatalogPushOutcome::REASON_AMBIGUOUS,
				),
				array(
					CatalogPushOutcome::FIELD_REFERENCE => self::FIFTH_IDENTITY,
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_APPLIED,
				),
			),
			$response->get_data()[ CatalogPushEndpoint::FIELD_ITEMS ]
		);

		// The three really were applied.
		self::assertSame( '12.40', $this->written[1]['regular_price'] );
		self::assertSame( 'Linen apron', $this->written[3]['name'] );
		self::assertSame( 'draft', $this->written[3]['status'] );
		self::assertSame( 42, $this->written[5]['stock_quantity'] );

		// And the two really were not: neither candidate of the ambiguous
		// reference was even built.
		self::assertArrayNotHasKey( 4, $this->written );
		self::assertSame( 0, $this->saves[4] ?? 0 );
	}

	/**
	 * Each outcome names the article the way the SENDER named it: the remote
	 * identity when the push carried one, the reference otherwise, and never an
	 * identifier of this shop.
	 *
	 * @return void
	 */
	public function test_each_outcome_names_the_article_the_way_the_sender_named_it(): void {
		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_applies() );

		$references = array_column(
			$response->get_data()[ CatalogPushEndpoint::FIELD_ITEMS ],
			CatalogPushOutcome::FIELD_REFERENCE
		);

		self::assertSame(
			array( self::FIRST_IDENTITY, 'SKU-0002', 'SKU-0003', 'SKU-0004', self::FIFTH_IDENTITY ),
			$references
		);

		foreach ( $references as $reference ) {
			self::assertIsString( $reference );
			self::assertStringNotContainsString(
				'post_id',
				$reference,
				'An answer keyed by an identifier of this shop answers a question nobody asked.'
			);
		}
	}

	/**
	 * A rejected article does not stop the rest of the batch: an unreadable
	 * amount rejects only its own article and leaves its previous price alone.
	 *
	 * @return void
	 */
	public function test_an_unreadable_amount_rejects_only_its_own_article(): void {
		$this->article( 1, array( 'regular_price' => '9.99' ) );
		$this->article( 2, array( 'regular_price' => '19.99' ) );
		$this->reference_matches = array(
			'SKU-0001' => 1,
			'SKU-0002' => 2,
		);

		$response = $this->handle_signed( 'batch-with-an-unreadable-amount.json', $this->ledger_that_applies() );

		self::assertSame( CatalogPushEndpoint::STATUS_PER_ITEM_OUTCOME, $response->get_status() );
		self::assertSame(
			array(
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0001',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_APPLIED,
				),
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0002',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_REJECTED,
					CatalogPushOutcome::FIELD_REASON    => CatalogPushOutcome::REASON_UNREADABLE_AMOUNT,
				),
			),
			$response->get_data()[ CatalogPushEndpoint::FIELD_ITEMS ]
		);
		self::assertSame( '12.40', $this->written[1]['regular_price'] );
		self::assertSame( '19.99', $this->written[2]['regular_price'], 'The previous price is left exactly as it was.' );
	}

	/**
	 * An article that does not manage stock is rejected with its typed reason,
	 * AFTER its catalogue fields and its price were applied and saved: each
	 * writer applies what it owns, and the answer says so.
	 *
	 * @return void
	 */
	public function test_an_article_that_does_not_manage_stock_is_rejected_after_its_price_was_written(): void {
		$this->article( 7, array(), false );
		$this->reference_matches = array( 'SKU-0007' => 7 );

		$body = $this->batch_body(
			array(
				array(
					CatalogPushItem::FIELD_SKU   => 'SKU-0007',
					CatalogPushItem::FIELD_NAME  => 'A service, sold by the hour',
					CatalogPushItem::FIELD_PRICE => '45.00',
					CatalogPushItem::FIELD_AVAILABLE_QUANTITY => 3,
				),
			)
		);

		$response = $this->handle( $body, $this->signature( $body ), $this->ledger_that_applies() );

		self::assertSame(
			array(
				array(
					CatalogPushOutcome::FIELD_REFERENCE => 'SKU-0007',
					CatalogPushOutcome::FIELD_STATUS    => CatalogPushOutcome::STATUS_REJECTED,
					CatalogPushOutcome::FIELD_REASON    => CatalogPushOutcome::REASON_STOCK_NOT_MANAGED,
				),
			),
			$response->get_data()[ CatalogPushEndpoint::FIELD_ITEMS ]
		);
		self::assertSame( 'A service, sold by the hour', $this->written[7]['name'] );
		self::assertSame( '45.00', $this->written[7]['regular_price'] );
		self::assertArrayNotHasKey( 'stock_quantity', $this->written[7] );
	}

	/**
	 * A redelivery is discarded without touching a single article.
	 *
	 * @return void
	 */
	public function test_a_redelivery_is_discarded_without_touching_any_article(): void {
		$this->shop_of_the_published_batch();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->expects( self::once() )->method( 'claim' )->willReturn( false );
		$ledger->expects( self::never() )->method( 'mark_applied' );
		$ledger->expects( self::never() )->method( 'forget' );

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $ledger );

		self::assertSame( InboundResponseStatus::DUPLICATE_DISCARDED, $response->get_status() );
		self::assertNotSame( '', $this->message_of( $response ) );
		self::assertSame( array(), $this->reference_searches, 'A redelivery resolves nothing.' );
		self::assertSame( array(), $this->identity_searches );
		self::assertSame( array(), array_filter( $this->saves ), 'A redelivery writes nothing a second time.' );
	}

	/**
	 * A claim that raises - the database refusing - is left to escape as the
	 * retryable failure it is, and nothing is released.
	 *
	 * @return void
	 */
	public function test_a_ledger_that_raises_while_claiming_is_left_to_escape(): void {
		$this->shop_of_the_published_batch();

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willThrowException( new RuntimeException( 'The ledger refused the write.' ) );
		$ledger->expects( self::never() )->method( 'forget' );

		$this->expectException( RuntimeException::class );

		$this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $ledger );
	}

	/**
	 * A permanent failure raised while applying releases the claim and answers
	 * malformed: repeating the same body would fail in the same way.
	 *
	 * @return void
	 */
	public function test_a_permanent_failure_while_applying_releases_the_claim_and_answers_malformed(): void {
		$this->shop_of_the_published_batch();
		$this->failing_save = array(
			'id'      => 1,
			'failure' => InboundPayloadNotReadableException::body_is_not_an_object( Constants::INBOUND_CATALOG_PUSH_ROUTE ),
		);

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'forget' )->with( 'wc-catalog-20260919T100000Z-0001' );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $ledger );

		self::assertSame( InboundResponseStatus::MALFORMED, $response->get_status() );
	}

	/**
	 * Any other failure while applying releases the claim and is re-thrown, so
	 * that WordPress renders the 5xx the sender comes back from.
	 *
	 * @return void
	 */
	public function test_a_failure_while_applying_releases_the_claim_and_escapes(): void {
		$this->shop_of_the_published_batch();
		$this->failing_save = array(
			'id'      => 1,
			'failure' => new RuntimeException( 'The product store refused the write.' ),
		);

		$ledger = $this->createMock( InboundDeliveryLedger::class );
		$ledger->method( 'claim' )->willReturn( true );
		$ledger->expects( self::once() )->method( 'forget' )->with( 'wc-catalog-20260919T100000Z-0001' );
		$ledger->expects( self::never() )->method( 'mark_applied' );

		$this->expectException( RuntimeException::class );

		$this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $ledger );
	}

	/**
	 * Every status this receiver answers with is one of the frozen table, and
	 * the one it answers a read batch with is the only 2xx that can carry the
	 * per-article outcome.
	 *
	 * @return void
	 */
	public function test_every_answer_is_a_status_of_the_frozen_table_or_the_outcome_status(): void {
		$this->shop_of_the_published_batch();

		$answered = array();

		$answered[] = $this->handle( 'not json', $this->signature( 'not json' ), $this->ledger_that_is_never_touched() )->get_status();
		$answered[] = $this->handle( 'x', 'wrong', $this->ledger_that_is_never_touched() )->get_status();
		$answered[] = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_applies() )->get_status();

		$duplicate = $this->createMock( InboundDeliveryLedger::class );
		$duplicate->method( 'claim' )->willReturn( false );
		$answered[] = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $duplicate )->get_status();

		$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
		$answered[] = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_is_never_touched() )->get_status();

		foreach ( $answered as $status ) {
			self::assertTrue(
				CatalogPushEndpoint::STATUS_PER_ITEM_OUTCOME === $status
					|| null !== InboundResponseStatus::classification_of( $status ),
				sprintf( 'The receiver answered %d, which is outside the frozen table.', $status )
			);
		}

		self::assertNotContains(
			InboundResponseStatus::ACCEPTED,
			$answered,
			'A batch never answers the bodiless accepted status: the per-article outcome IS the answer.'
		);
	}

	/**
	 * THE LOOP, CLOSED. Applying a batch writes nothing through the database
	 * handle, which is how «the outbox gained nothing» is stated without a
	 * database: every row of the outbox is written through that handle.
	 *
	 * @return void
	 */
	public function test_applying_a_batch_adds_nothing_to_the_outbox(): void {
		$database = Mockery::mock( 'wpdb' );
		$database->shouldNotReceive( 'insert' );
		$database->shouldNotReceive( 'replace' );
		$database->shouldNotReceive( 'update' );
		$database->shouldNotReceive( 'query' );
		$database->shouldNotReceive( 'prepare' );
		$database->shouldNotReceive( 'get_results' );
		$database->shouldNotReceive( 'get_row' );

		$GLOBALS['wpdb'] = $database;

		$this->shop_of_the_published_batch();

		$response = $this->handle_signed( 'batch-of-five-with-two-unresolvable.json', $this->ledger_that_applies() );

		unset( $GLOBALS['wpdb'] );

		self::assertSame( CatalogPushEndpoint::STATUS_PER_ITEM_OUTCOME, $response->get_status() );
	}

	/**
	 * Runs the receiver over one of the published example bodies, signed.
	 *
	 * @param string                           $file   Name of the example file.
	 * @param InboundDeliveryLedger&MockObject $ledger Ledger double.
	 * @return object The response, answering get_status() and get_data().
	 */
	private function handle_signed( string $file, $ledger ): object {
		$body = $this->body( $file );

		return $this->handle( $body, $this->signature( $body ), $ledger );
	}

	/**
	 * Runs the receiver over a body and a presented signature.
	 *
	 * @param string                           $raw_body  Bytes of the body.
	 * @param string|null                      $signature Value of the signature header, null when absent.
	 * @param InboundDeliveryLedger&MockObject $ledger    Ledger double.
	 * @return object The response, answering get_status() and get_data().
	 */
	private function handle( string $raw_body, ?string $signature, $ledger ): object {
		return ( new CatalogPushEndpoint( null, $ledger ) )->handle( $this->request( $raw_body, $signature ) );
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
	 * A ledger that grants the claim and records the delivery as applied.
	 *
	 * @return InboundDeliveryLedger&MockObject
	 */
	private function ledger_that_applies() {
		$ledger = $this->createMock( InboundDeliveryLedger::class );

		$ledger->method( 'claim' )->willReturn( true );
		$ledger->method( 'mark_applied' )->willReturn( true );
		$ledger->expects( self::never() )->method( 'forget' );

		return $ledger;
	}

	/**
	 * The message of a refusal body.
	 *
	 * @param object $response Response answering get_data().
	 * @return string
	 */
	private function message_of( object $response ): string {
		$data = $response->get_data();

		self::assertIsArray( $data );
		self::assertArrayHasKey( Constants::REJECTION_BODY_MESSAGE_FIELD, $data );

		return (string) $data[ Constants::REJECTION_BODY_MESSAGE_FIELD ];
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
	 * One value encoded as the bytes of a request body.
	 *
	 * A plain `json_encode` and not the WordPress wrapper, which does not exist
	 * in a bare PHP run.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private function encode( $value ): string {
		return (string) json_encode( $value );
	}

	/**
	 * A batch body built around a list of items.
	 *
	 * @param mixed $items The items, in whatever shape the case is about.
	 * @param mixed $key   The idempotency key, in whatever shape the case is about.
	 * @return string
	 */
	private function batch_body( $items, $key = 'wc-catalog-20260919T120000Z-0009' ): string {
		return $this->encode(
			array(
				CatalogPushEndpoint::FIELD_IDEMPOTENCY_KEY => $key,
				CatalogPushEndpoint::FIELD_ITEMS           => $items,
			)
		);
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
	 * The shop the published batch of five is addressed to.
	 *
	 * Article 1 carries the first identity, article 3 and article 4 answer to
	 * their reference - the second of them shared with another article - and
	 * article 5 carries the fifth identity. The reference of the second item
	 * belongs to nothing.
	 *
	 * @return void
	 */
	private function shop_of_the_published_batch(): void {
		$this->article( 1 );
		$this->article( 3 );
		$this->article( 5 );

		$this->identity_matches = array(
			self::FIRST_IDENTITY => array( 1 ),
			self::FIFTH_IDENTITY => array( 5 ),
		);

		$this->reference_matches = array(
			'SKU-0002' => 0,
			'SKU-0003' => 3,
			'SKU-0004' => 4,
		);

		$this->unique_references = array( 'SKU-0004' => false );
	}

	/**
	 * An article of the shop whose writes really are recorded and read back.
	 *
	 * @param int                  $id             Identifier of the article.
	 * @param array<string, mixed> $existing       Properties the article already has.
	 * @param bool                 $manages_stock  Whether the article manages its stock.
	 * @return MockInterface
	 */
	private function article( int $id, array $existing = array(), bool $manages_stock = true ): MockInterface {
		$this->written[ $id ]       = $existing;
		$this->saves[ $id ]         = 0;
		$this->manages_stock[ $id ] = $manages_stock;

		$product = Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'get_id' )->andReturn( $id );

		foreach ( array( 'name', 'description', 'status', 'regular_price', 'stock_quantity', 'stock_status' ) as $property ) {
			$product->shouldReceive( 'set_' . $property )->andReturnUsing(
				function ( $value ) use ( $id, $property ): void {
					$this->written[ $id ][ $property ] = $value;
				}
			);
		}

		$product->shouldReceive( 'managing_stock' )->andReturnUsing(
			function () use ( $id ): bool {
				return $this->manages_stock[ $id ];
			}
		);

		$product->shouldReceive( 'get_backorders' )->andReturn( 'no' );

		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) use ( $id ) {
				return $this->written[ $id ][ $key ] ?? '';
			}
		);

		$product->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( $id ): void {
				$this->written[ $id ][ $key ] = $value;
			}
		);

		$product->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $id ): int {
				if ( null !== $this->failing_save && $id === $this->failing_save['id'] ) {
					throw $this->failing_save['failure'];
				}

				++$this->saves[ $id ];

				return $id;
			}
		);

		// The prohibitions, made mechanical: this lane assigns the available
		// quantity and never adds to it, and it never switches stock management
		// on for an article that does not have it.
		$product->shouldNotReceive( 'set_manage_stock' );
		$product->shouldNotReceive( 'set_sale_price' );
		$product->shouldNotReceive( 'set_price' );

		$this->articles[ $id ] = $product;

		return $product;
	}
}

/**
 * Minimal stand-in for `WP_REST_Response`.
 *
 * Not final, so a later phase can double it; it answers only what the receivers
 * of this package put into a response and what these cases read back.
 */
class FakeCatalogPushRestResponse {

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
