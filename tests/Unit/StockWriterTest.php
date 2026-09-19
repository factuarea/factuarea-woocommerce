<?php
/**
 * Unit tests for the writer of the available quantity of an inbound push.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Inbound\StockWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case is here.
 *
 * The article is a `WC_Product` double built by name - there is no WordPress
 * and no WooCommerce in a Unit run - and it BEHAVES like an article rather than
 * recording expectations: the quantity and the status written on it are read
 * back out of it, its saves are counted, and the order of every call it
 * received is kept. That order is not decoration: «the status was set before
 * the save» is the difference between a shop that offers seven units and one
 * that saves seven units while still telling its customers they are gone.
 *
 * Three promises of this class cannot be tested by looking at the article
 * afterwards, so they are tested as the ABSENCE of a call:
 *
 *   - the quantity is ASSIGNED, never added to. Asserted as «the article was
 *     never asked what it already had»: a writer that never reads the previous
 *     quantity cannot compose the new one out of it, and a writer that reads it
 *     would pass a test that only looked at the final number whenever the
 *     fixture happened to start at zero.
 *   - `wc_update_product_stock()` is never called. It is the ONE function of
 *     WooCommerce that can write a RELATIVE quantity - measured in 8.9.5, its
 *     `increase`/`decrease` operations reach
 *     `WC_Product_Data_Store_CPT::update_product_stock()`
 *     (`includes/data-stores/class-wc-product-data-store-cpt.php:1400`), whose
 *     SQL is `meta_value = meta_value %+f` - and this receiver must never use
 *     it. The day somebody switches to it «because it is the WooCommerce way»,
 *     this goes red.
 *   - stock management is never switched on. Asserted as «`set_manage_stock()`
 *     was never called», because an article that ends up managing stock looks
 *     exactly like one that always did.
 *
 * The items always travel through `CatalogPushItem::from_array()` and are never
 * built by hand: an item this test could build but the frozen factory would
 * reject is an item that never reaches this writer in production. That is also
 * how the batch-wide rejection of an internal figure is asserted here - the
 * writer is not the piece that rejects it, and the case exists to prove that
 * such an item cannot even be BUILT, let alone applied.
 */
final class StockWriterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Option name WooCommerce keeps the «no stock» threshold in.
	 *
	 * Read from WooCommerce rather than invented: it is the same option
	 * `WC_Product::validate_props()` reads
	 * (`includes/abstracts/abstract-wc-product.php:1384`, WooCommerce 8.9.5) to
	 * align the status of an article it is about to save.
	 */
	private const NO_STOCK_THRESHOLD_OPTION = 'woocommerce_notify_no_stock_amount';

	/**
	 * The article double of the shop.
	 *
	 * @var MockInterface|null
	 */
	private ?MockInterface $article = null;

	/**
	 * Quantity currently written on the article double.
	 *
	 * @var int|null
	 */
	private ?int $quantity = null;

	/**
	 * Stock status currently written on the article double.
	 *
	 * @var string
	 */
	private string $status = '';

	/**
	 * How many times the article double was saved.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Every call the article double received, in order.
	 *
	 * @var list<string>
	 */
	private array $calls_in_order = array();

	/**
	 * Options of the shop, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * How many times each doubled function was called.
	 *
	 * @var array<string, int>
	 */
	private array $calls = array();

	/**
	 * Installs the doubles of the functions this writer may reach.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		$this->article        = null;
		$this->quantity       = null;
		$this->status         = '';
		$this->saves          = 0;
		$this->calls_in_order = array();
		$this->options        = array( self::NO_STOCK_THRESHOLD_OPTION => 0 );
		$this->calls          = array(
			'get_option'              => 0,
			'wc_update_product_stock' => 0,
			'wp_remote_post'          => 0,
			'wp_remote_request'       => 0,
		);

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				++$this->calls['get_option'];

				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);

		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);

		// The relative write of WooCommerce, doubled only so that its absence
		// is measurable. A call to it is a failure of this suite, not a path.
		Functions\when( 'wc_update_product_stock' )->alias(
			function () {
				++$this->calls['wc_update_product_stock'];

				return 0;
			}
		);

		Functions\when( 'wp_remote_post' )->alias(
			function () {
				++$this->calls['wp_remote_post'];

				return array();
			}
		);

		Functions\when( 'wp_remote_request' )->alias(
			function () {
				++$this->calls['wp_remote_request'];

				return array();
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
	 * The quantity that arrived is the quantity the article ends up with.
	 *
	 * @return void
	 */
	public function test_the_quantity_is_assigned_exactly_as_it_arrived(): void {
		$this->article( array( 'quantity' => 3 ) );

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertTrue( $outcome->is_applied() );
		self::assertSame( 7, $this->quantity, 'Seven units arrived and seven units were written.' );
	}

	/**
	 * The quantity is never composed out of the one the article already had.
	 *
	 * The platform already subtracted the live commitment before publishing, so
	 * adding here would offer units that are already sold. It is asserted as
	 * «the article was never asked what it had»: a writer that does not read the
	 * previous number cannot add to it, while a test that only compared the
	 * final number would pass for a writer that added to a fixture of zero.
	 *
	 * @return void
	 */
	public function test_a_quantity_is_never_added_to_the_one_the_article_already_had(): void {
		$this->article( array( 'quantity' => 3 ) );

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame( 7, $this->quantity, 'Not ten: the available quantity replaces, it does not accumulate.' );
		self::assertNotContains(
			'get_stock_quantity',
			$this->calls_in_order,
			'The previous quantity was never read, so it cannot have been added to.'
		);
	}

	/**
	 * The one WooCommerce helper that can write a relative quantity is never
	 * used.
	 *
	 * @return void
	 */
	public function test_the_relative_stock_helper_of_woocommerce_is_never_called(): void {
		$this->article();

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame(
			0,
			$this->calls['wc_update_product_stock'],
			'Its increase and decrease operations write meta_value = meta_value %+f.'
		);
	}

	/**
	 * The article is saved once, and after both props were set.
	 *
	 * One save, because a save of a product fires every automation the merchant
	 * installed; after both, because the save is what persists them and what
	 * WooCommerce itself uses to align the article.
	 *
	 * @return void
	 */
	public function test_the_article_is_saved_once_and_after_both_props_were_written(): void {
		$this->article();

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame( 1, $this->saves );
		self::assertSame(
			array( 'set_stock_quantity', 'set_stock_status', 'save' ),
			$this->writes(),
			'The quantity, then the status it implies, then the single save that persists both.'
		);
	}

	/**
	 * An article that does not manage stock is rejected as unmanaged.
	 *
	 * @return void
	 */
	public function test_an_article_that_does_not_manage_stock_is_rejected_as_unmanaged(): void {
		$this->article( array( 'managing_stock' => false ) );

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertFalse( $outcome->is_applied() );
		self::assertSame( CatalogPushOutcome::REASON_STOCK_NOT_MANAGED, $outcome->reason );
	}

	/**
	 * A rejected article keeps its configuration exactly as it was.
	 *
	 * Switching stock management on is a decision about the merchant's shop, and
	 * taking it from a webhook changes how their catalogue behaves without
	 * anybody asking. Nothing is written and nothing is saved: an article that
	 * was saved without changes still fires every save hook of the shop.
	 *
	 * @return void
	 */
	public function test_a_rejected_article_keeps_its_configuration_and_is_not_saved(): void {
		$this->article(
			array(
				'managing_stock' => false,
				'quantity'       => 3,
			)
		);

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame( array(), $this->writes(), 'Nothing was written on the article.' );
		self::assertSame( 3, $this->quantity, 'The quantity it already had is untouched.' );
		self::assertSame( 0, $this->saves );
	}

	/**
	 * The question asked is `managing_stock()`, which carries the shop-wide
	 * switch, and not `get_manage_stock()`, which does not.
	 *
	 * Measured in WooCommerce 8.9.5
	 * (`includes/abstracts/abstract-wc-product.php:1710`): `managing_stock()`
	 * answers false for EVERY article of a shop whose `woocommerce_manage_stock`
	 * option is off, however the article itself is configured. Writing a
	 * quantity into a shop that has decided not to use quantities and answering
	 * «applied» is the same silent lie as writing it into an article that does
	 * not manage stock.
	 *
	 * @return void
	 */
	public function test_the_shop_wide_switch_is_honoured_and_not_only_the_flag_of_the_article(): void {
		$this->article(
			array(
				'managing_stock'   => false,
				'get_manage_stock' => true,
			)
		);

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertFalse( $outcome->is_applied() );
		self::assertSame( CatalogPushOutcome::REASON_STOCK_NOT_MANAGED, $outcome->reason );
		self::assertSame( array(), $this->writes() );
	}

	/**
	 * A variation whose stock is managed by its parent is rejected as unmanaged.
	 *
	 * Measured in WooCommerce 8.9.5: `WC_Product_Variation::get_manage_stock()`
	 * (`includes/class-wc-product-variation.php:318`) answers the STRING
	 * `'parent'` for such a variation, and `managing_stock()` hands that string
	 * straight back, so a truthy check would take it for «yes, it manages its
	 * own». It does not: its quantity lives on the parent
	 * (`get_stock_managed_by_id()`, `:82`), and applying one variation's
	 * availability there would set it for every sibling variation of the
	 * article. The receiver says so instead of guessing.
	 *
	 * @return void
	 */
	public function test_a_variation_whose_stock_is_managed_by_its_parent_is_rejected_as_unmanaged(): void {
		$this->article( array( 'managing_stock' => 'parent' ) );

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertFalse( $outcome->is_applied() );
		self::assertSame( CatalogPushOutcome::REASON_STOCK_NOT_MANAGED, $outcome->reason );
		self::assertSame( array(), $this->writes(), 'Neither the variation nor its parent was written to.' );
	}

	/**
	 * An item that declares no quantity leaves the article exactly as it was.
	 *
	 * A push of catalogue fields or of a price is not a push of stock, and a
	 * writer that treated an absent quantity as zero would empty the shelves of
	 * every article whose name was corrected.
	 *
	 * @return void
	 */
	public function test_an_item_that_declares_no_quantity_leaves_the_article_alone(): void {
		$this->article( array( 'quantity' => 3 ) );

		$outcome = ( new StockWriter() )->write(
			$this->article,
			$this->item( array( 'name' => 'A name this writer must not write' ) )
		);

		self::assertTrue( $outcome->is_applied() );
		self::assertSame( array(), $this->writes() );
		self::assertSame( 3, $this->quantity );
		self::assertSame( 0, $this->saves );
	}

	/**
	 * An article that does not manage stock is NOT rejected when no quantity
	 * travelled.
	 *
	 * The rejection answers «you sent me a quantity for an article that has no
	 * quantity». With no quantity there is no such question, and rejecting
	 * anyway would fail the catalogue push of every article of a shop that sells
	 * services.
	 *
	 * @return void
	 */
	public function test_an_unmanaged_article_is_not_rejected_when_no_quantity_travels(): void {
		$this->article( array( 'managing_stock' => false ) );

		$outcome = ( new StockWriter() )->write(
			$this->article,
			$this->item( array( 'price' => '19.90' ) )
		);

		self::assertTrue( $outcome->is_applied() );
		self::assertSame( array(), $this->writes() );
	}

	/**
	 * A quantity above the threshold of the shop leaves the article in stock.
	 *
	 * @return void
	 */
	public function test_a_quantity_above_the_threshold_leaves_the_article_in_stock(): void {
		$this->article();

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame( 'instock', $this->status, 'Seven units are not "out of stock".' );
	}

	/**
	 * Zero units with no backorders allowed leave the article out of stock.
	 *
	 * @return void
	 */
	public function test_zero_units_leave_the_article_out_of_stock(): void {
		$this->article( array( 'backorders' => 'no' ) );

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 0 ) ) );

		self::assertSame( 0, $this->quantity );
		self::assertSame( 'outofstock', $this->status );
	}

	/**
	 * Zero units in a shop that allows backorders leave the article on
	 * backorder.
	 *
	 * The two backorder answers WooCommerce treats as «allowed» are `yes` and
	 * `notify`, and the rule reads the article rather than a preference of this
	 * plugin: the merchant decided it, per article.
	 *
	 * @param string $backorders Backorder setting of the article.
	 * @return void
	 *
	 * @dataProvider provide_allowed_backorder_settings
	 */
	public function test_zero_units_are_on_backorder_when_the_article_allows_them( string $backorders ): void {
		$this->article( array( 'backorders' => $backorders ) );

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 0 ) ) );

		self::assertSame( 'onbackorder', $this->status );
	}

	/**
	 * The two backorder settings WooCommerce treats as allowed.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_allowed_backorder_settings(): array {
		return array(
			'silently'  => array( 'yes' ),
			'notifying' => array( 'notify' ),
		);
	}

	/**
	 * The status follows the threshold the SHOP configured, with the same
	 * strict comparison WooCommerce uses.
	 *
	 * Measured in `WC_Product::validate_props()`
	 * (`includes/abstracts/abstract-wc-product.php:1384`, WooCommerce 8.9.5):
	 * the article is in stock when its quantity is STRICTLY GREATER than
	 * `absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) )`. A shop
	 * that declares five as its floor is telling WooCommerce that five is not
	 * enough to sell, and this receiver does not overrule it.
	 *
	 * @return void
	 */
	public function test_the_status_follows_the_threshold_the_shop_configured(): void {
		$this->options[ self::NO_STOCK_THRESHOLD_OPTION ] = 5;

		$this->article( array( 'backorders' => 'no' ) );
		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 5 ) ) );
		self::assertSame( 'outofstock', $this->status, 'Five is not strictly above five.' );
		self::assertSame( 5, $this->quantity, 'The quantity is still written: only the status follows the floor.' );

		$this->article( array( 'backorders' => 'no' ) );
		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 6 ) ) );
		self::assertSame( 'instock', $this->status );
	}

	/**
	 * A negative quantity is written as it arrived and leaves the article out of
	 * stock.
	 *
	 * An oversold article is a fact of the platform, not a number to clamp: the
	 * receiver that clamped it to zero would hide the oversell from the very
	 * report the merchant uses to fix it. What it must not do is keep offering
	 * the article, and that is the status.
	 *
	 * @return void
	 */
	public function test_a_negative_quantity_is_written_as_it_arrived_and_the_article_stops_being_offered(): void {
		$this->article( array( 'backorders' => 'no' ) );

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => -3 ) ) );

		self::assertTrue( $outcome->is_applied() );
		self::assertSame( -3, $this->quantity );
		self::assertSame( 'outofstock', $this->status );
	}

	/**
	 * The threshold is read from the shop on the way, and its absence is zero.
	 *
	 * @return void
	 */
	public function test_the_threshold_is_read_from_the_shop_and_defaults_to_zero(): void {
		$this->options = array();

		$this->article( array( 'backorders' => 'no' ) );

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 1 ) ) );

		self::assertSame( 1, $this->calls['get_option'], 'The floor is asked of the shop, once.' );
		self::assertSame( 'instock', $this->status, 'With no floor configured, one unit is one unit.' );
	}

	/**
	 * This writer writes stock, and nothing else of the item.
	 *
	 * The three writers of the inbound contract share one shape and each applies
	 * ONLY the fields it owns. An item carrying a name and a price goes past
	 * this one untouched in both.
	 *
	 * @return void
	 */
	public function test_it_writes_neither_the_catalogue_fields_nor_the_price(): void {
		$this->article();

		( new StockWriter() )->write(
			$this->article,
			$this->item(
				array(
					'name'               => 'A name another writer owns',
					'description'        => 'A description another writer owns',
					'active'             => true,
					'price'              => '19.90',
					'available_quantity' => 7,
				)
			)
		);

		self::assertSame( array( 'set_stock_quantity', 'set_stock_status', 'save' ), $this->writes() );
	}

	/**
	 * An internal figure of the platform cannot even be READ into an item.
	 *
	 * This is the batch-wide half of the contract, and it is asserted here
	 * because three of the five figures it names are stock figures - the
	 * physical balance, the committed quantity and the reorder threshold - and
	 * this is the writer they would have been aimed at. It is NOT this class
	 * that rejects them, and that is the point: the factory answers null, the
	 * receiver reads `internal_keys_in()` and rejects THE WHOLE BATCH, and no
	 * item carrying one of them ever reaches a writer.
	 *
	 * They are not fields the receiver has not learnt yet: they are figures that
	 * must never leave the platform. Ignoring them gracefully and applying the
	 * rest would leave the leak without a witness - and the leak is a cost price
	 * or a commitment sitting in the database of somebody else's shop.
	 *
	 * @param string $key   Internal key of the platform.
	 * @param mixed  $value Value it would arrive with.
	 * @return void
	 *
	 * @dataProvider provide_internal_figures
	 */
	public function test_an_internal_figure_in_the_body_rejects_the_whole_batch( string $key, $value ): void {
		$document = array(
			'sku'                => 'REF-001',
			'available_quantity' => 7,
			$key                 => $value,
		);

		self::assertContains( $key, CatalogPushItem::INTERNAL_KEYS, 'The figure is enumerated as internal.' );
		self::assertSame(
			array( $key ),
			CatalogPushItem::internal_keys_in( $document ),
			'The receiver is told which figure leaked, so the batch rejection can name it.'
		);
		self::assertNull(
			CatalogPushItem::from_array( $document ),
			'No item is built, so no writer can be handed one.'
		);
	}

	/**
	 * The five figures that must never travel, with the shape they would travel
	 * in.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function provide_internal_figures(): array {
		return array(
			'the cost price'        => array( 'cost', '11.40' ),
			'the supplier'          => array( 'supplier', 'Acme Supplies Ltd' ),
			'the physical balance'  => array( 'physical_quantity', 9 ),
			'the committed units'   => array( 'committed_quantity', 2 ),
			'the reorder threshold' => array( 'reorder_threshold', 4 ),
		);
	}

	/**
	 * The three STOCK figures of the platform are the ones this writer would
	 * have been fed, and all three are enumerated as internal.
	 *
	 * Pinned here and not only in the contract's own suite: the day one of them
	 * is dropped from the list, the hole opens in the stock half of the push,
	 * and this is the file whose name says so.
	 *
	 * @return void
	 */
	public function test_the_three_stock_figures_of_the_platform_are_enumerated_as_internal(): void {
		self::assertSame(
			array( 'physical_quantity', 'committed_quantity', 'reorder_threshold' ),
			array_values(
				array_intersect(
					CatalogPushItem::INTERNAL_KEYS,
					array( 'physical_quantity', 'committed_quantity', 'reorder_threshold' )
				)
			)
		);
	}

	/**
	 * The available quantity is the only quantity the contract accepts.
	 *
	 * The wire name is spelled out in full on purpose: a key called `quantity`
	 * would let the AVAILABLE quantity and the physical balance be confused, and
	 * confusing them offers units that a sold order has already committed.
	 *
	 * @return void
	 */
	public function test_the_only_quantity_the_contract_accepts_is_the_available_one(): void {
		$quantities = array_values(
			array_filter(
				CatalogPushItem::ACCEPTED_KEYS,
				static function ( string $key ): bool {
					return str_contains( $key, 'quantity' );
				}
			)
		);

		self::assertSame( array( 'available_quantity' ), $quantities );
	}

	/**
	 * The outcome names the article the way the SENDER named it.
	 *
	 * The identity when the push carried one, the reference otherwise, never an
	 * internal identifier of this shop.
	 *
	 * @return void
	 */
	public function test_the_outcome_names_the_article_the_way_the_sender_named_it(): void {
		$this->article();

		$identified = ( new StockWriter() )->write(
			$this->article,
			$this->item(
				array(
					'remote_id'          => 'PRD-8841',
					'sku'                => 'REF-001',
					'available_quantity' => 7,
				)
			)
		);

		self::assertSame( 'PRD-8841', $identified->sender_reference );

		$this->article();

		$unidentified = ( new StockWriter() )->write(
			$this->article,
			$this->item(
				array(
					'sku'                => 'REF-001',
					'available_quantity' => 7,
				)
			)
		);

		self::assertSame( 'REF-001', $unidentified->sender_reference );
		self::assertSame( 424242, $this->article->get_id(), 'And never the identifier of this shop.' );
	}

	/**
	 * The rejection this writer emits is one of the frozen five.
	 *
	 * The receiver hands it straight to `CatalogPushOutcome::rejected()`, which
	 * THROWS on a reason outside the list. A reason invented here would reach the
	 * sender as a 500 over an article that simply does not manage stock.
	 *
	 * @return void
	 */
	public function test_the_rejection_is_one_of_the_frozen_reasons(): void {
		$this->article( array( 'managing_stock' => false ) );

		$outcome = ( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertContains( $outcome->reason, CatalogPushOutcome::VALID_REASONS );
		self::assertSame(
			array(
				'reference' => 'REF-001',
				'status'    => 'rejected',
				'reason'    => CatalogPushOutcome::REASON_STOCK_NOT_MANAGED,
			),
			$outcome->to_array()
		);
	}

	/**
	 * Writing the stock of an article sends nothing out of the shop.
	 *
	 * Applying a push is the end of an inbound delivery, not the start of an
	 * outbound one. A writer that queued an outbound delivery would send the
	 * platform back the quantity the platform had just sent.
	 *
	 * @return void
	 */
	public function test_writing_the_stock_sends_nothing_out(): void {
		$this->article();

		( new StockWriter() )->write( $this->article, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertSame( 0, $this->calls['wp_remote_post'] );
		self::assertSame( 0, $this->calls['wp_remote_request'] );
	}

	/**
	 * The same writer applied twice over the same article lands on the same
	 * number.
	 *
	 * An assignment is idempotent and an addition is not, so a redelivery the
	 * ledger did not catch - a shop restored from a backup, a key pruned early -
	 * costs nothing. This is the behavioural half of «it never adds».
	 *
	 * @return void
	 */
	public function test_applying_the_same_push_twice_lands_on_the_same_quantity(): void {
		$this->article( array( 'quantity' => 3 ) );

		$writer = new StockWriter();
		$item   = $this->item( array( 'available_quantity' => 7 ) );

		$writer->write( $this->article, $item );
		$writer->write( $this->article, $item );

		self::assertSame( 7, $this->quantity );
		self::assertSame( 2, $this->saves, 'Two pushes, two saves; one number.' );
	}

	/**
	 * Registers the article double of the shop.
	 *
	 * @param array{quantity?: int, managing_stock?: bool|string, get_manage_stock?: bool|string, backorders?: string} $state State the article starts in.
	 * @return MockInterface
	 */
	private function article( array $state = array() ): MockInterface {
		$this->quantity       = $state['quantity'] ?? null;
		$this->status         = '';
		$this->saves          = 0;
		$this->calls_in_order = array();

		$managing   = $state['managing_stock'] ?? true;
		$flag       = $state['get_manage_stock'] ?? $managing;
		$backorders = $state['backorders'] ?? 'no';

		$product = Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'managing_stock' )->andReturnUsing(
			function () use ( $managing ) {
				$this->calls_in_order[] = 'managing_stock';

				return $managing;
			}
		);

		$product->shouldReceive( 'get_manage_stock' )->andReturnUsing(
			function () use ( $flag ) {
				$this->calls_in_order[] = 'get_manage_stock';

				return $flag;
			}
		);

		$product->shouldReceive( 'get_backorders' )->andReturnUsing(
			function () use ( $backorders ): string {
				$this->calls_in_order[] = 'get_backorders';

				return $backorders;
			}
		);

		$product->shouldReceive( 'set_stock_quantity' )->andReturnUsing(
			function ( $quantity ): void {
				$this->calls_in_order[] = 'set_stock_quantity';
				$this->quantity         = $quantity;
			}
		);

		$product->shouldReceive( 'set_stock_status' )->andReturnUsing(
			function ( string $status ): void {
				$this->calls_in_order[] = 'set_stock_status';
				$this->status           = $status;
			}
		);

		$product->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				$this->calls_in_order[] = 'save';
				++$this->saves;

				return 424242;
			}
		);

		$product->shouldReceive( 'get_id' )->andReturn( 424242 );

		// The calls that would mean this writer had started doing somebody
		// else's job, or the one job it must never do.
		$product->shouldNotReceive( 'set_manage_stock' );
		$product->shouldNotReceive( 'set_backorders' );
		$product->shouldNotReceive( 'set_low_stock_amount' );
		$product->shouldNotReceive( 'get_stock_quantity' );
		$product->shouldNotReceive( 'set_regular_price' );
		$product->shouldNotReceive( 'set_sale_price' );
		$product->shouldNotReceive( 'set_price' );
		$product->shouldNotReceive( 'set_name' );
		$product->shouldNotReceive( 'set_description' );
		$product->shouldNotReceive( 'set_status' );
		$product->shouldNotReceive( 'set_catalog_visibility' );

		$this->article = $product;

		return $product;
	}

	/**
	 * The calls that WROTE on the article, in order, with the reads left out.
	 *
	 * @return list<string>
	 */
	private function writes(): array {
		return array_values(
			array_filter(
				$this->calls_in_order,
				static function ( string $call ): bool {
					return str_starts_with( $call, 'set_' ) || 'save' === $call;
				}
			)
		);
	}

	/**
	 * Builds an item through the frozen factory, never by hand.
	 *
	 * The reference is filled in when the case does not care about it: it is the
	 * one required field of the contract, and an item without it is not an item.
	 *
	 * @param array<string, mixed> $document Item as it would arrive in a batch.
	 * @return CatalogPushItem
	 */
	private function item( array $document ): CatalogPushItem {
		if ( ! array_key_exists( 'sku', $document ) ) {
			$document['sku'] = 'REF-001';
		}

		$item = CatalogPushItem::from_array( $document );

		self::assertNotNull( $item, 'The body of this case has to be a readable item.' );

		return $item;
	}
}
