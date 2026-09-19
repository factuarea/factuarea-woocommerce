<?php
/**
 * Integration tests for the inbound delivery ledger, against a real database.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Integration;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Inbound\InboundDeliveryLedger;
use Factuarea\WooCommerce\Inbound\InboundDeliveryTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What this file pins down, and why it cannot be a unit test.
 *
 * The whole guarantee of the inbound half is a guarantee of the DATABASE and
 * not of the PHP around it: a UNIQUE index collapses the second delivery of a
 * key, and everything the two receivers promise - a redelivery is discarded,
 * two simultaneous deliveries apply the effect once - rests on that index doing
 * its job. A double of `$wpdb` can only assert that a certain string was passed
 * to it, which proves that the query was written and nothing at all about
 * whether it does what it was written for. "The second claim of the same key
 * loses" is meaningless without a real engine underneath.
 *
 * So this suite talks to the real WordPress database of the containerised
 * environment. It runs where WordPress is already loaded by the runner, and if
 * that is not the case it SKIPS NAMING THE REASON rather than passing: a check
 * that cannot see what it checks and reports green is the ghost guardrail this
 * repository spends most of its effort avoiding, and a skip is visibly not a
 * pass in the report.
 *
 * The retention WINDOW is not measured here. Its length is a constant of the
 * contract and lives in `tests/Unit/InboundDeliveryLedgerRetentionTest.php` and
 * `tests/Unit/InboundContractTest.php`; what this file measures is that the
 * prune deletes by the cut-off it is handed, and on the instant of RECEIPT.
 */
final class InboundDeliveryLedgerTest extends TestCase {

	/**
	 * A key shaped like the one the STATUS notice of an order carries.
	 *
	 * The shapes below are copied from the frozen inbound contract
	 * (`store-order-status-receiver-contract.md`, section 4.3) because they are
	 * what this table will really hold. Nothing in the ledger validates them -
	 * the contract forbids that on purpose - so they are here to be realistic,
	 * never to be parsed.
	 */
	private const STATUS_KEY = 'wc-1042-shipped-20260919T100000Z';

	/**
	 * A key shaped like the one the REFUND notice of the SAME order carries.
	 *
	 * Same order, different delivery: the ledger is a ledger of DELIVERIES, and
	 * these two must not collide with each other.
	 */
	private const REFUND_KEY = 'wc-refund-019e0000-0000-7000-8000-000000000042';

	/**
	 * A key shaped like the one a CATALOGUE PUSH batch carries. It belongs to
	 * no order at all, which is half the reason this is a table and not an
	 * order meta.
	 */
	private const CATALOG_KEY = 'wc-catalog-push-019e0000-0000-7000-8000-0000000000aa';

	/**
	 * The ledger under test.
	 *
	 * @var InboundDeliveryLedger
	 */
	private InboundDeliveryLedger $ledger;

	/**
	 * Makes sure WordPress is there, and that the table is.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			/*
			 * The containerised environment loads WordPress for the runner.
			 * When something else runs this file - a bare `composer test` on a
			 * laptop - a bootstrap of this suite may still put the handle in
			 * place, so it is given the chance before giving up.
			 */
			$bootstrap = dirname( __DIR__ ) . '/bootstrap-integration.php';

			if ( is_file( $bootstrap ) ) {
				require_once $bootstrap;
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! defined( 'ABSPATH' ) ) {
			self::markTestSkipped(
				'SKIPPED, NOT PASSED: WordPress is not loaded, so the inbound ledger table cannot be created and '
				. 'none of the guarantees below were checked. Run this suite inside the containerised WordPress '
				. 'environment.'
			);
		}

		InboundDeliveryTable::install();
	}

	/**
	 * Starts every test with an empty ledger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$table = InboundDeliveryTable::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		$this->ledger = new InboundDeliveryLedger( $wpdb );
	}

	/**
	 * The table the plugin describes is the table the database has, and running
	 * the routine again changes nothing.
	 *
	 * @return void
	 */
	public function test_the_install_routine_creates_the_table_and_is_repeatable(): void {
		$this->assertTrue( InboundDeliveryTable::exists() );

		InboundDeliveryTable::install();

		$this->assertTrue( InboundDeliveryTable::exists() );
		$this->assertSame( InboundDeliveryTable::SCHEMA_VERSION, get_option( InboundDeliveryTable::SCHEMA_VERSION_OPTION ) );
		$this->assertSame( Constants::OPTION_INBOUND_SCHEMA_VERSION, InboundDeliveryTable::SCHEMA_VERSION_OPTION );
	}

	/**
	 * The marker never short-circuits the routine.
	 *
	 * This is the failure the whole idempotent-install design exists for: an
	 * option that says "installed" while the table is gone - a partially
	 * restored backup, a table dropped by hand, a migration between hosts that
	 * missed one table. If the routine trusted the marker, every later claim
	 * would write into nothing, the ledger would deduplicate nothing, and every
	 * retry of the sender would be applied a second time.
	 *
	 * The table is dropped here on purpose and put back by the routine under
	 * test, which is the only way to observe the property.
	 *
	 * @return void
	 */
	public function test_the_install_routine_does_not_short_circuit_on_its_own_marker(): void {
		global $wpdb;

		update_option( InboundDeliveryTable::SCHEMA_VERSION_OPTION, InboundDeliveryTable::SCHEMA_VERSION, false );

		$table = InboundDeliveryTable::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$this->assertFalse( InboundDeliveryTable::exists(), 'The table had to be gone for this to measure anything.' );

		InboundDeliveryTable::install();

		$this->assertTrue( InboundDeliveryTable::exists(), 'The marker must not stop the routine from recreating the table.' );
		$this->assertSame( InboundDeliveryTable::SCHEMA_VERSION, get_option( InboundDeliveryTable::SCHEMA_VERSION_OPTION ) );
		$this->assertTrue( $this->ledger->claim( self::STATUS_KEY, time() ), 'The recreated table has to take a claim.' );
	}

	/**
	 * The first delivery of a key is taken, the second one is not.
	 *
	 * @return void
	 */
	public function test_the_first_claim_of_a_key_enters_and_the_second_does_not(): void {
		$now = time();

		$this->assertTrue( $this->ledger->claim( self::STATUS_KEY, $now ) );
		$this->assertFalse( $this->ledger->claim( self::STATUS_KEY, $now ), 'A redelivery must not be taken as a new delivery.' );

		$rows = $this->all_rows();

		$this->assertCount( 1, $rows, 'A redelivery must not leave a second row either.' );
		$this->assertSame( self::STATUS_KEY, $rows[0]['idempotency_key'] );
		$this->assertSame( $now, (int) $rows[0]['received_at'] );
		$this->assertSame( InboundDeliveryTable::NOT_YET_APPLIED, (int) $rows[0]['applied_at'], 'A claim is not an application.' );
	}

	/**
	 * A redelivery does not move the instant of receipt.
	 *
	 * If it did, a sender retrying for days would keep pushing its key out of
	 * reach of the prune, and the row would outlive the window that was
	 * measured against the retry schedule.
	 *
	 * @return void
	 */
	public function test_a_redelivery_does_not_move_the_instant_of_receipt(): void {
		$first = time();

		$this->ledger->claim( self::STATUS_KEY, $first );
		$this->ledger->claim( self::STATUS_KEY, $first + 1 );

		$this->assertSame( $first, (int) $this->all_rows()[0]['received_at'] );
	}

	/**
	 * Two deliveries of the same key leave exactly one winner.
	 *
	 * The two claims are made one after the other, from two different ledger
	 * objects, on purpose. That is the worst case for the mechanism and the
	 * only one a single-threaded test can stage: the second claim runs when the
	 * first has already written its row and would sail straight through if the
	 * claim were a SELECT followed by an INSERT. Two connections would not
	 * observe anything more here, because the guarantee is the UNIQUE index and
	 * the index is what the second INSERT hits.
	 *
	 * @return void
	 */
	public function test_two_simultaneous_claims_leave_exactly_one_winner(): void {
		global $wpdb;

		$other = new InboundDeliveryLedger( $wpdb );
		$now   = time();

		$outcomes = array(
			$this->ledger->claim( self::STATUS_KEY, $now ),
			$other->claim( self::STATUS_KEY, $now ),
		);

		$this->assertSame(
			array( true, false ),
			$outcomes,
			'Exactly one of two deliveries of the same key may apply its effect.'
		);
		$this->assertCount( 1, $this->all_rows() );
	}

	/**
	 * Releasing a claimed key lets the sender come back.
	 *
	 * This is the one situation `forget()` exists for: the effect failed after
	 * the key was claimed, so the row must not stay behind turning the retry
	 * into a discarded redelivery the sender would take as published.
	 *
	 * @return void
	 */
	public function test_forgetting_a_key_lets_it_be_claimed_again(): void {
		$now = time();

		$this->assertTrue( $this->ledger->claim( self::STATUS_KEY, $now ) );

		$this->ledger->forget( self::STATUS_KEY );

		$this->assertSame( array(), $this->all_rows(), 'The released key leaves no row behind.' );
		$this->assertTrue( $this->ledger->claim( self::STATUS_KEY, $now + 1 ), 'The retry of the sender has to be able to come back.' );
		$this->assertSame( $now + 1, (int) $this->all_rows()[0]['received_at'], 'The retry is a new delivery, with its own instant.' );
	}

	/**
	 * Releasing a key that was never claimed touches nothing else.
	 *
	 * @return void
	 */
	public function test_forgetting_a_key_that_was_never_claimed_removes_nothing_else(): void {
		$now = time();

		$this->ledger->claim( self::STATUS_KEY, $now );
		$this->ledger->forget( self::REFUND_KEY );

		$this->assertCount( 1, $this->all_rows() );
		$this->assertSame( self::STATUS_KEY, $this->all_rows()[0]['idempotency_key'] );
	}

	/**
	 * Confirming an applied delivery dates its row, and dates it once.
	 *
	 * The first confirmation is the one that describes when the effect landed;
	 * a second one would be describing something else. And a row still at the
	 * unapplied marker is the readable trace of a receiver that died between
	 * the claim and the effect.
	 *
	 * @return void
	 */
	public function test_confirming_an_applied_delivery_dates_its_row_once(): void {
		$now = time();

		$this->ledger->claim( self::STATUS_KEY, $now );

		$this->assertTrue( $this->ledger->mark_applied( self::STATUS_KEY, $now + 1 ) );
		$this->assertSame( $now + 1, (int) $this->all_rows()[0]['applied_at'] );

		$this->assertFalse( $this->ledger->mark_applied( self::STATUS_KEY, $now + 2 ), 'Only the first confirmation dates the row.' );
		$this->assertSame( $now + 1, (int) $this->all_rows()[0]['applied_at'] );
		$this->assertCount( 1, $this->all_rows() );
	}

	/**
	 * Confirming a key nobody claimed writes nothing.
	 *
	 * @return void
	 */
	public function test_confirming_a_key_that_was_never_claimed_writes_nothing(): void {
		$this->assertFalse( $this->ledger->mark_applied( self::STATUS_KEY, time() ) );
		$this->assertSame( array(), $this->all_rows() );
	}

	/**
	 * A confirmed delivery stays claimed: the redelivery is still discarded.
	 *
	 * @return void
	 */
	public function test_a_confirmed_delivery_still_discards_its_redelivery(): void {
		$now = time();

		$this->ledger->claim( self::STATUS_KEY, $now );
		$this->ledger->mark_applied( self::STATUS_KEY, $now );

		$this->assertFalse( $this->ledger->claim( self::STATUS_KEY, $now + 1 ) );
		$this->assertCount( 1, $this->all_rows() );
	}

	/**
	 * The prune removes what is older than the cut-off and keeps the rest, and
	 * says how many it removed.
	 *
	 * @return void
	 */
	public function test_the_prune_removes_only_what_is_older_than_the_window(): void {
		$now    = time();
		$cutoff = InboundDeliveryLedger::prune_cutoff( $now );

		$this->ledger->claim( self::STATUS_KEY, $cutoff - 1 );
		$this->ledger->claim( self::REFUND_KEY, $cutoff );
		$this->ledger->claim( self::CATALOG_KEY, $now );

		$this->assertSame( 1, $this->ledger->prune( $cutoff ), 'Only the delivery received before the cut-off goes.' );

		$surviving = array_column( $this->all_rows(), 'idempotency_key' );
		sort( $surviving );

		$this->assertSame( array( self::CATALOG_KEY, self::REFUND_KEY ), $surviving );
		$this->assertTrue(
			$this->ledger->claim( self::STATUS_KEY, $now ),
			'A pruned key is a key the ledger no longer knows, which is why the window has to outlast the retry schedule.'
		);
	}

	/**
	 * The prune measures age on the instant of RECEIPT, never on the instant of
	 * application.
	 *
	 * A delivery whose effect was never confirmed - the receiver died in
	 * between - has to age out like any other. Measuring on the confirmation
	 * would keep those rows for ever, and they are precisely the rows nobody
	 * looks at.
	 *
	 * @return void
	 */
	public function test_the_prune_measures_age_on_receipt_and_not_on_application(): void {
		$now    = time();
		$cutoff = InboundDeliveryLedger::prune_cutoff( $now );

		$this->ledger->claim( self::STATUS_KEY, $cutoff - 1 );
		$this->ledger->claim( self::REFUND_KEY, $cutoff - 1 );
		$this->ledger->mark_applied( self::REFUND_KEY, $now );

		$this->assertSame( 2, $this->ledger->prune( $cutoff ), 'Both were received before the cut-off, applied or not.' );
		$this->assertSame( array(), $this->all_rows() );
	}

	/**
	 * The prune of an empty ledger removes nothing and says so.
	 *
	 * @return void
	 */
	public function test_the_prune_of_an_empty_ledger_removes_nothing(): void {
		$this->assertSame( 0, $this->ledger->prune( InboundDeliveryLedger::prune_cutoff( time() ) ) );
	}

	/**
	 * THE CASE THAT JUSTIFIES THE TABLE: the ledger is a ledger of DELIVERIES,
	 * not of orders.
	 *
	 * The status notice and the refund notice of the SAME order are two
	 * deliveries with two keys, and a catalogue push has no order at all. All
	 * three coexist, each one discards only its own redelivery, and releasing
	 * one does not release the others. An order meta could not hold the third
	 * one and would have made the first two fight for the same slot.
	 *
	 * @return void
	 */
	public function test_deliveries_of_different_receivers_coexist_without_colliding(): void {
		$now = time();

		$this->assertTrue( $this->ledger->claim( self::STATUS_KEY, $now ) );
		$this->assertTrue( $this->ledger->claim( self::REFUND_KEY, $now ), 'The refund of the same order is another delivery.' );
		$this->assertTrue( $this->ledger->claim( self::CATALOG_KEY, $now ), 'A catalogue push belongs to no order at all.' );

		$this->assertCount( 3, $this->all_rows() );

		$this->assertFalse( $this->ledger->claim( self::REFUND_KEY, $now ) );
		$this->assertCount( 3, $this->all_rows() );

		$this->ledger->forget( self::REFUND_KEY );

		$this->assertCount( 2, $this->all_rows() );
		$this->assertFalse( $this->ledger->claim( self::STATUS_KEY, $now ), 'Releasing one delivery must not release another.' );
		$this->assertFalse( $this->ledger->claim( self::CATALOG_KEY, $now ) );
	}

	/**
	 * A key that does not fit the column is refused, and nothing is written.
	 *
	 * The shape of the key is deliberately not validated anywhere in this
	 * package; its LENGTH is not a validation but a limit of this table. An
	 * `INSERT IGNORE` would downgrade a value too long into a truncation
	 * warning, and two distinct keys truncated to the same characters would
	 * deduplicate two DIFFERENT deliveries - the one failure this table exists
	 * to prevent, arriving as silence.
	 *
	 * @return void
	 */
	public function test_a_key_longer_than_the_column_is_refused_without_writing(): void {
		$too_long = str_repeat( 'k', InboundDeliveryTable::KEY_MAX_LENGTH + 1 );

		$this->expectException( RuntimeException::class );

		try {
			$this->ledger->claim( $too_long, time() );
		} finally {
			$this->assertSame( array(), $this->all_rows(), 'A refused claim writes nothing at all.' );
		}
	}

	/**
	 * Every row of the ledger, oldest receipt first.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function all_rows(): array {
		global $wpdb;

		$table = InboundDeliveryTable::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY received_at ASC, id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
