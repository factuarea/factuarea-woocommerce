<?php
/**
 * Unit tests for the inbound catalogue field writer.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Inbound\CatalogFieldWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What this file pins down, and why each case is here.
 *
 * The article is a double that BEHAVES like a product rather than merely
 * recording calls: every setter lands in an array that the assertions read back,
 * and every call is also recorded by name so that «this field was not written»
 * can be asserted as an absence of a CALL and not only as an unchanged value.
 * `WC_Product` does not exist in a Unit run - there is no WordPress and no
 * WooCommerce - so it is built by name, exactly as the order double of
 * `OrderStatusStorageTest` is.
 *
 * The double refuses the price, the sale price, the stock, the stock management
 * flag, the reference, the catalogue visibility and the deletion. That is the
 * mechanical half of «each writer applies only the fields it owns»: two sibling
 * writers run over this same article right after this one, and a catalogue
 * writer that touched a price would be undoing their work or doing it twice.
 *
 * Items are always built through `CatalogPushItem::from_array()` and never by
 * hand, so every case exercises the same reading the receiver does: an item this
 * test could invent but the factory would reject is an item that never reaches
 * the writer in production.
 */
final class CatalogFieldWriterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Properties written on the article double, keyed by property name.
	 *
	 * @var array<string, mixed>
	 */
	private array $props = array();

	/**
	 * Setter names the article double received, in the order they were called.
	 *
	 * @var list<string>
	 */
	private array $calls = array();

	/**
	 * How many times the article double has been saved.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Failure the next `save()` of the double must raise, when a case asks for
	 * one.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $save_failure = null;

	/**
	 * Starts every case from an empty article and no doubles.
	 *
	 * The WordPress sanitisers are deliberately NOT stubbed here. Two cases
	 * assert WHICH function reached WHICH field, and a stub installed in the
	 * set-up would have to be replaced by an expectation on the same name, which
	 * is the one thing Brain Monkey does not allow.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		$this->props        = array();
		$this->calls        = array();
		$this->saves        = 0;
		$this->save_failure = null;
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
	 * The three catalogue fields travel together and all three are applied, with
	 * a single save for the article.
	 *
	 * @return void
	 */
	public function test_the_three_declared_catalogue_fields_are_applied_with_one_save(): void {
		$this->stub_sanitisers();

		$product = $this->product();

		$outcome = ( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'         => 'CAB-USBC-2M',
					'name'        => 'USB-C cable, 2 m',
					'description' => '<p>Braided <strong>USB-C</strong> cable.</p>',
					'active'      => true,
				)
			)
		);

		self::assertSame( 'USB-C cable, 2 m', $this->props['name'] ?? null );
		self::assertSame( '<p>Braided <strong>USB-C</strong> cable.</p>', $this->props['description'] ?? null );
		self::assertSame( 'publish', $this->props['status'] ?? null );
		self::assertSame( 1, $this->saves, 'Three fields are one article, and an article is saved once.' );
		self::assertTrue( $outcome->is_applied() );
		self::assertSame( CatalogPushOutcome::STATUS_APPLIED, $outcome->status );
		self::assertNull( $outcome->reason, 'An applied article carries no reason.' );
	}

	/**
	 * A push that declares one catalogue field writes that one and no other.
	 *
	 * @return void
	 */
	public function test_only_the_catalogue_fields_the_push_declares_are_written(): void {
		$this->stub_sanitisers();

		$product = $this->product();

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'  => 'CAB-USBC-2M',
					'name' => 'USB-C cable, 2 m',
				)
			)
		);

		self::assertSame( array( 'set_name' ), $this->calls );
		self::assertSame( 1, $this->saves );
	}

	/**
	 * The field the push did not declare keeps exactly what the shop had.
	 *
	 * This is the case the whole class exists for: a push ABOUT A PRICE carries
	 * no description, and a writer that read the property instead of the
	 * declaration would blank it.
	 *
	 * @return void
	 */
	public function test_a_field_the_push_does_not_declare_keeps_the_value_the_shop_had(): void {
		$this->stub_sanitisers();

		$product = $this->product(
			array(
				'name'        => 'Cable the merchant named',
				'description' => '<p>Description the merchant wrote.</p>',
				'status'      => 'publish',
			)
		);

		$outcome = ( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'   => 'CAB-USBC-2M',
					'price' => '19.90',
				)
			)
		);

		self::assertSame( array(), $this->calls, 'A price push declares no catalogue field, so no setter is called.' );
		self::assertSame( 'Cable the merchant named', $this->props['name'] );
		self::assertSame( '<p>Description the merchant wrote.</p>', $this->props['description'] );
		self::assertSame( 'publish', $this->props['status'] );
		self::assertTrue( $outcome->is_applied(), 'Nothing to write is nothing that failed.' );
	}

	/**
	 * An article nobody changed is not saved at all.
	 *
	 * `save()` is what fires `woocommerce_update_product` and everything hooked
	 * behind it, so saving an untouched article would announce a change that did
	 * not happen to every listener in the shop.
	 *
	 * @return void
	 */
	public function test_an_article_with_no_declared_catalogue_field_is_not_saved(): void {
		$this->stub_sanitisers();

		( new CatalogFieldWriter() )->write(
			$this->product(),
			$this->item(
				array(
					'sku'                => 'CAB-USBC-2M',
					'available_quantity' => 7,
				)
			)
		);

		self::assertSame( 0, $this->saves );
	}

	/**
	 * However many catalogue fields travel, the article is saved once.
	 *
	 * @return void
	 */
	public function test_the_article_is_saved_once_whatever_the_number_of_fields(): void {
		$pushes = array(
			1 => array( 'name' => 'One field' ),
			2 => array(
				'name'   => 'Two fields',
				'active' => false,
			),
			3 => array(
				'name'        => 'Three fields',
				'description' => '<p>Three.</p>',
				'active'      => true,
			),
		);

		foreach ( $pushes as $count => $fields ) {
			$this->stub_sanitisers();

			$product = $this->product();

			( new CatalogFieldWriter() )->write(
				$product,
				$this->item( array_merge( array( 'sku' => 'CAB-USBC-2M' ), $fields ) )
			);

			self::assertCount( $count, $this->calls, sprintf( 'A push of %d fields calls %d setters.', $count, $count ) );
			self::assertSame( 1, $this->saves, sprintf( 'A push of %d fields still saves once.', $count ) );
		}
	}

	/**
	 * The name goes through the plain-text sanitiser of WordPress, and the writer
	 * applies whatever that function answered.
	 *
	 * The expectation is on the FUNCTION and not on the resulting string: this
	 * class must not invent its own escaping, and a test that checked the output
	 * shape would pass just as well for a hand-rolled `strip_tags()`.
	 *
	 * @return void
	 */
	public function test_the_name_is_sanitised_with_the_plain_text_function_of_wordpress(): void {
		Functions\expect( 'sanitize_text_field' )
			->once()
			->with( "  <b>USB-C</b>\ncable  " )
			->andReturn( 'USB-C cable' );

		$product = $this->product();

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'  => 'CAB-USBC-2M',
					'name' => "  <b>USB-C</b>\ncable  ",
				)
			)
		);

		self::assertSame( 'USB-C cable', $this->props['name'] ?? null );
	}

	/**
	 * The description goes through the RICH CONTENT sanitiser, and is never
	 * flattened into plain text.
	 *
	 * Two halves, and both are needed: the rich function is the one that is
	 * called, and the plain-text one is the one that is not. Asserting only the
	 * first would pass for a writer that ran both, which would flatten the
	 * description anyway.
	 *
	 * @return void
	 */
	public function test_the_description_is_sanitised_as_rich_content_and_not_flattened(): void {
		$rich    = '<p>Braided <strong>USB-C</strong> cable.</p><script>alert(1)</script>';
		$cleaned = '<p>Braided <strong>USB-C</strong> cable.</p>';

		Functions\expect( 'wp_kses_post' )->once()->with( $rich )->andReturn( $cleaned );
		Functions\expect( 'sanitize_text_field' )->never();

		$product = $this->product();

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'         => 'CAB-USBC-2M',
					'description' => $rich,
				)
			)
		);

		self::assertSame( $cleaned, $this->props['description'] ?? null );
		self::assertStringContainsString(
			'<strong>',
			(string) ( $this->props['description'] ?? '' ),
			'Rich content stays rich: the contract declares this field as rich and flattening it is a data loss.'
		);
	}

	/**
	 * An article the push declares active is published.
	 *
	 * @return void
	 */
	public function test_an_active_article_is_published(): void {
		$this->stub_sanitisers();

		$product = $this->product( array( 'status' => 'draft' ) );

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'    => 'CAB-USBC-2M',
					'active' => true,
				)
			)
		);

		self::assertSame( 'publish', $this->props['status'] ?? null );
		self::assertSame( array( 'set_status' ), $this->calls );
	}

	/**
	 * An article the push declares inactive is drafted: it stops being for sale
	 * and it is neither hidden-but-purchasable nor sent to the bin.
	 *
	 * The two refusals are mechanical, in the double: `set_catalog_visibility`
	 * would leave the article purchasable through its own address, and `delete`
	 * is not a state a later push can undo.
	 *
	 * @return void
	 */
	public function test_an_inactive_article_is_drafted_and_neither_hidden_nor_binned(): void {
		$this->stub_sanitisers();

		$product = $this->product( array( 'status' => 'publish' ) );

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'    => 'CAB-USBC-2M',
					'active' => false,
				)
			)
		);

		self::assertSame( 'draft', $this->props['status'] ?? null );
		self::assertSame( array( 'set_status' ), $this->calls );
	}

	/**
	 * The price and the stock that travel in the very same item belong to the
	 * two sibling writers, and this one does not touch them.
	 *
	 * @return void
	 */
	public function test_the_price_and_the_stock_of_the_item_are_not_touched_here(): void {
		$this->stub_sanitisers();

		$product = $this->product();

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'                => 'CAB-USBC-2M',
					'name'               => 'USB-C cable, 2 m',
					'price'              => '19.90',
					'available_quantity' => 7,
				)
			)
		);

		self::assertSame( array( 'set_name' ), $this->calls );
		self::assertArrayNotHasKey( 'regular_price', $this->props );
		self::assertArrayNotHasKey( 'stock_quantity', $this->props );
	}

	/**
	 * The outcome names the article the way the SENDER named it: the remote
	 * identity when the push carried one, the reference otherwise. Never an
	 * identifier of this shop.
	 *
	 * @return void
	 */
	public function test_the_outcome_names_the_article_the_way_the_sender_named_it(): void {
		$this->stub_sanitisers();

		$with_identity = ( new CatalogFieldWriter() )->write(
			$this->product(),
			$this->item(
				array(
					'remote_id' => 'prod_9f2c',
					'sku'       => 'CAB-USBC-2M',
					'name'      => 'USB-C cable, 2 m',
				)
			)
		);

		self::assertSame( 'prod_9f2c', $with_identity->sender_reference );

		$this->stub_sanitisers();

		$without_identity = ( new CatalogFieldWriter() )->write(
			$this->product(),
			$this->item(
				array(
					'sku'  => 'CAB-USBC-2M',
					'name' => 'USB-C cable, 2 m',
				)
			)
		);

		self::assertSame( 'CAB-USBC-2M', $without_identity->sender_reference );
	}

	/**
	 * A failure of the save escapes instead of being reported as applied.
	 *
	 * The receiver is the single place that turns a failed effect into a
	 * released idempotency key and a retryable answer. Swallowing it here would
	 * tell the sender that a change it never made is in the database.
	 *
	 * @return void
	 */
	public function test_a_failing_save_escapes_instead_of_being_reported_as_applied(): void {
		$this->stub_sanitisers();

		$this->save_failure = new RuntimeException( 'The database went away.' );

		$product = $this->product();

		$this->expectException( RuntimeException::class );

		( new CatalogFieldWriter() )->write(
			$product,
			$this->item(
				array(
					'sku'  => 'CAB-USBC-2M',
					'name' => 'USB-C cable, 2 m',
				)
			)
		);
	}

	/**
	 * This writer writes EXACTLY the catalogue fields the contract enumerates.
	 *
	 * Measured against `CatalogPushItem::CATALOG_FIELDS` rather than copied from
	 * it, which is the point: the day that list grows a fourth field and this
	 * writer keeps three arms, this case goes red instead of the field being
	 * dropped in silence on every push.
	 *
	 * @return void
	 */
	public function test_this_writer_covers_exactly_the_catalogue_fields_of_the_contract(): void {
		self::assertSame(
			CatalogPushItem::CATALOG_FIELDS,
			CatalogFieldWriter::WRITTEN_FIELDS,
			'The contract enumerates the catalogue fields; this writer has to know how to write every one of them.'
		);
	}

	/**
	 * A declared key this writer has no arm for is NAMED as unwritable instead
	 * of being ignored.
	 *
	 * @return void
	 */
	public function test_a_key_outside_the_enumerated_list_is_named_unwritable(): void {
		self::assertSame(
			array( 'colour' ),
			CatalogFieldWriter::unwritable_fields_in(
				array(
					'name'   => 'USB-C cable, 2 m',
					'colour' => 'black',
				)
			)
		);
	}

	/**
	 * A declared value whose kind does not match its destination is unwritable
	 * too: a flag that is not a boolean and a name that is not text are the same
	 * event as an unknown key - the contract changed.
	 *
	 * @return void
	 */
	public function test_a_declared_value_of_the_wrong_kind_is_unwritable_too(): void {
		self::assertSame(
			array( 'active' ),
			CatalogFieldWriter::unwritable_fields_in( array( 'active' => 'yes' ) )
		);

		self::assertSame(
			array( 'name', 'description' ),
			CatalogFieldWriter::unwritable_fields_in(
				array(
					'name'        => true,
					'description' => 42,
				)
			)
		);
	}

	/**
	 * Everything the contract really declares is writable.
	 *
	 * The other half of the two cases above: a guard that answered «unwritable»
	 * for a legitimate field would reject every push, and the two cases above
	 * would still be green.
	 *
	 * @return void
	 */
	public function test_every_field_the_contract_declares_is_writable(): void {
		$item = $this->item(
			array(
				'sku'         => 'CAB-USBC-2M',
				'name'        => 'USB-C cable, 2 m',
				'description' => '<p>Braided.</p>',
				'active'      => false,
			)
		);

		self::assertSame(
			array(),
			CatalogFieldWriter::unwritable_fields_in( $item->declared_catalog_fields() )
		);
	}

	/**
	 * The rejection this writer can answer is a SECOND lock, not the first one:
	 * an item carrying a key the contract does not enumerate never becomes an
	 * item at all, so it never reaches `write()`.
	 *
	 * Written as a case because it is the boundary between this phase and the
	 * receiver: the reason code is the same one - `field_not_enumerated` - and
	 * whoever composes it from `unknown_keys_in()` must not expect this writer
	 * to have done it.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_is_stopped_by_the_contract_before_the_writer(): void {
		$item = array(
			'sku'    => 'CAB-USBC-2M',
			'colour' => 'black',
		);

		self::assertNull( CatalogPushItem::from_array( $item ) );
		self::assertSame( array( 'colour' ), CatalogPushItem::unknown_keys_in( $item ) );
		self::assertContains(
			CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED,
			CatalogPushOutcome::VALID_REASONS
		);
	}

	/**
	 * Stubs the two WordPress sanitisers so that they answer their first
	 * argument, for the cases that are not about which function was called.
	 *
	 * @return void
	 */
	private function stub_sanitisers(): void {
		Functions\stubs(
			array(
				'sanitize_text_field' => null,
				'wp_kses_post'        => null,
			)
		);
	}

	/**
	 * An article double whose properties really are written and read back.
	 *
	 * @param array<string, mixed> $existing Properties the article already has.
	 * @return MockInterface
	 */
	private function product( array $existing = array() ): MockInterface {
		$this->props = $existing;
		$this->calls = array();
		$this->saves = 0;

		$product = Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'set_name' )->andReturnUsing(
			function ( string $value ): void {
				$this->props['name'] = $value;
				$this->calls[]       = 'set_name';
			}
		);

		$product->shouldReceive( 'set_description' )->andReturnUsing(
			function ( string $value ): void {
				$this->props['description'] = $value;
				$this->calls[]              = 'set_description';
			}
		);

		$product->shouldReceive( 'set_status' )->andReturnUsing(
			function ( string $value ): void {
				$this->props['status'] = $value;
				$this->calls[]         = 'set_status';
			}
		);

		$product->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				if ( null !== $this->save_failure ) {
					throw $this->save_failure;
				}

				++$this->saves;

				return 9042;
			}
		);

		// «Each writer applies only the fields it owns», made mechanical. The
		// price and the stock of this very item belong to the two writers the
		// receiver runs next; the reference is how the article was found; the
		// catalogue visibility would leave an inactive article purchasable
		// through its own address; and a deletion is not a state a later push
		// can undo.
		$product->shouldNotReceive( 'set_regular_price' );
		$product->shouldNotReceive( 'set_sale_price' );
		$product->shouldNotReceive( 'set_price' );
		$product->shouldNotReceive( 'set_stock_quantity' );
		$product->shouldNotReceive( 'set_manage_stock' );
		$product->shouldNotReceive( 'set_stock_status' );
		$product->shouldNotReceive( 'set_sku' );
		$product->shouldNotReceive( 'set_catalog_visibility' );
		$product->shouldNotReceive( 'delete' );

		return $product;
	}

	/**
	 * Builds an item through the frozen factory, never by hand.
	 *
	 * @param array<string, mixed> $fields Item as it would have arrived.
	 * @return CatalogPushItem
	 */
	private function item( array $fields ): CatalogPushItem {
		$item = CatalogPushItem::from_array( $fields );

		self::assertInstanceOf(
			CatalogPushItem::class,
			$item,
			'The case is built on a body the frozen factory accepts, or it is testing something production never sees.'
		);

		return $item;
	}
}
