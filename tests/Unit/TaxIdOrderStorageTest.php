<?php
/**
 * Unit tests for the tax identifier order storage.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Checkout\TaxIdOrderStorage;
use Factuarea\WooCommerce\Contract\Constants;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case is here.
 *
 * The order is a double. `WC_Order` does not exist in a Unit run - there is no
 * WordPress and no WooCommerce - so the double is built by name, and it behaves
 * like an order rather than merely answering: writes land in an array, reads
 * come back out of it. A double that only records calls would let a storage that
 * writes one key and reads another pass every one of these tests.
 *
 * The mirror is exercised in BOTH states from a test, on purpose. The decision
 * of whether it is written lives in a constant that a measurement flips, so a
 * test that only used the default would stop covering half the class the day
 * that constant changed - and it would do so silently, which is the failure mode
 * this repository spends most of its effort avoiding.
 */
final class TaxIdOrderStorageTest extends TestCase {

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
	 * Starts every test with an empty order and no saves.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->meta  = array();
		$this->saves = 0;
	}

	/**
	 * An order double whose meta really is written and read back.
	 *
	 * @param array<string, mixed> $existing_meta Meta the order already carries.
	 * @return MockInterface
	 */
	private function order( array $existing_meta = array() ): MockInterface {
		$this->meta = $existing_meta;

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
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

				return 1;
			}
		);

		return $order;
	}

	/**
	 * The round trip: what goes in comes back out, under the connector's key.
	 *
	 * @return void
	 */
	public function test_writing_and_reading_gives_back_the_same_value(): void {
		$storage = new TaxIdOrderStorage( false );
		$order   = $this->order();

		$storage->write( $order, '12345678Z' );

		$this->assertSame( '12345678Z', $storage->read( $order ) );
		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_META_KEY ] );
		$this->assertSame( 1, $this->saves );
	}

	/**
	 * The value is normalised before it is stored, so the same identifier typed
	 * in two ways is one value on the order and not two.
	 *
	 * @return void
	 */
	public function test_the_value_is_normalised_before_it_is_stored(): void {
		$storage = new TaxIdOrderStorage( false );
		$order   = $this->order();

		$storage->write( $order, ' 12.345.678-z ' );

		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_META_KEY ] );
		$this->assertSame( '12345678Z', $storage->read( $order ) );
	}

	/**
	 * An empty value writes no key at all - not an empty one - and does not even
	 * save: an order without a tax identifier is an ordinary order.
	 *
	 * @return void
	 */
	public function test_an_empty_value_writes_no_key_at_all(): void {
		$storage = new TaxIdOrderStorage( true );
		$order   = $this->order();

		$storage->write( $order, '' );

		$this->assertSame( array(), $this->meta );
		$this->assertSame( 0, $this->saves );
		$this->assertSame( '', $storage->read( $order ) );
	}

	/**
	 * A value made only of separators normalises to empty, and takes the same
	 * road: nothing is written. Without this the buyer who types a space gets an
	 * empty key delivered, which reads like a lost identifier instead of like a
	 * buyer who gave none.
	 *
	 * @return void
	 */
	public function test_a_value_of_only_separators_writes_no_key_either(): void {
		$storage = new TaxIdOrderStorage( true );
		$order   = $this->order();

		$storage->write( $order, ' - . ' );

		$this->assertSame( array(), $this->meta );
		$this->assertSame( 0, $this->saves );
	}

	/**
	 * An empty submission never erases an identifier already recorded.
	 *
	 * @return void
	 */
	public function test_an_empty_value_does_not_erase_what_is_already_recorded(): void {
		$storage = new TaxIdOrderStorage( false );
		$order   = $this->order( array( Constants::TAX_ID_META_KEY => '12345678Z' ) );

		$storage->write( $order, '' );

		$this->assertSame( '12345678Z', $storage->read( $order ) );
	}

	/**
	 * Reading an order that has no tax identifier gives an empty string, never
	 * null: the frozen contract says the absence is a value, not a condition the
	 * caller has to guard against.
	 *
	 * @return void
	 */
	public function test_reading_an_order_without_a_tax_identifier_gives_an_empty_string(): void {
		$storage = new TaxIdOrderStorage( false );

		$this->assertSame( '', $storage->read( $this->order() ) );
	}

	/**
	 * Something that is not text under the key reads as absent. Order meta is not
	 * typed, and the mirror key is shared with third-party plugins, so an array
	 * under it is a real possibility.
	 *
	 * @return void
	 */
	public function test_a_value_that_is_not_text_reads_as_absent(): void {
		$storage = new TaxIdOrderStorage( false );
		$order   = $this->order( array( Constants::TAX_ID_META_KEY => array( '12345678Z' ) ) );

		$this->assertSame( '', $storage->read( $order ) );
	}

	/**
	 * With the mirror switched on, BOTH keys are written with the same value:
	 * the primary one for the record, the mirror for the delivered body.
	 *
	 * @return void
	 */
	public function test_with_the_mirror_switched_on_both_keys_are_written(): void {
		$storage = new TaxIdOrderStorage( true );
		$order   = $this->order();

		$storage->write( $order, '12345678Z' );

		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_META_KEY ] );
		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_MIRROR_META_KEY ] );
	}

	/**
	 * With the mirror switched off, only the primary key is written. Writing the
	 * copy "just in case" duplicates a personal datum with nothing asking for it.
	 *
	 * @return void
	 */
	public function test_with_the_mirror_switched_off_only_the_primary_key_is_written(): void {
		$storage = new TaxIdOrderStorage( false );
		$order   = $this->order();

		$storage->write( $order, '12345678Z' );

		$this->assertSame(
			array( Constants::TAX_ID_META_KEY => '12345678Z' ),
			$this->meta
		);
	}

	/**
	 * The mirror never overwrites a non-empty value already sitting under the
	 * shared key. That key belongs to a family of third-party tax identifier
	 * plugins as much as to this one, and replacing its value would replace the
	 * identifier the store has been invoicing with.
	 *
	 * @return void
	 */
	public function test_the_mirror_never_overwrites_a_non_empty_existing_value(): void {
		$storage = new TaxIdOrderStorage( true );
		$order   = $this->order( array( Constants::TAX_ID_MIRROR_META_KEY => 'B12345674' ) );

		$storage->write( $order, '12345678Z' );

		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_META_KEY ] );
		$this->assertSame( 'B12345674', $this->meta[ Constants::TAX_ID_MIRROR_META_KEY ] );
	}

	/**
	 * An empty value under the shared key is not a value: the mirror fills it.
	 *
	 * @return void
	 */
	public function test_the_mirror_replaces_an_empty_value_under_the_shared_key(): void {
		$storage = new TaxIdOrderStorage( true );
		$order   = $this->order( array( Constants::TAX_ID_MIRROR_META_KEY => '' ) );

		$storage->write( $order, '12345678Z' );

		$this->assertSame( '12345678Z', $this->meta[ Constants::TAX_ID_MIRROR_META_KEY ] );
	}

	/**
	 * Built with no argument, the storage honours the frozen decision. This is
	 * what the plugin wiring gets, so the measured decision - and not a habit -
	 * is what runs in a merchant's shop.
	 *
	 * @return void
	 */
	public function test_the_frozen_decision_is_what_the_default_construction_honours(): void {
		$storage = new TaxIdOrderStorage();
		$order   = $this->order();

		$storage->write( $order, '12345678Z' );

		$this->assertSame(
			TaxIdOrderStorage::MIRROR_REQUIRED,
			array_key_exists( Constants::TAX_ID_MIRROR_META_KEY, $this->meta )
		);
	}
}
