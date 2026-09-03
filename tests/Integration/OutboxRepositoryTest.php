<?php
/**
 * Integration tests for the outbox repository, against a real database.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Integration;

use Factuarea\WooCommerce\Contract\DeliveryOutcome;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Outbox\OutboxTable;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why it cannot be a unit test.
 *
 * Every guarantee of the outbox is a guarantee of the DATABASE, not of the PHP
 * around it: a UNIQUE constraint collapses the second capture, a conditional
 * UPDATE makes two drains disjoint, an index decides what "due" means. A double
 * of `$wpdb` can only assert that a certain string was passed to it, which
 * proves that the query was written and nothing at all about whether it does
 * what it was written for. The one assertion worth making here - "two drains
 * never get the same entry" - is meaningless without a real engine underneath.
 *
 * So this suite talks to the real WordPress database of the containerised
 * environment. It runs where WordPress is already loaded by the runner, and if
 * that is not the case it SKIPS NAMING THE REASON rather than passing: a check
 * that cannot see what it checks and reports green is the ghost guardrail this
 * repository spends most of its effort avoiding, and a skip is visibly not a
 * pass in the report.
 */
final class OutboxRepositoryTest extends TestCase {

	/**
	 * Store address every entry of this file is captured for.
	 */
	private const SOURCE = 'https://shop.example/';

	/**
	 * The repository under test.
	 *
	 * @var OutboxRepository
	 */
	private OutboxRepository $repository;

	/**
	 * Makes sure WordPress is there, and that the table is.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			/*
			 * The containerised environment loads WordPress for the runner. When
			 * something else runs this file - a bare `composer test` on a laptop
			 * - a bootstrap of this suite may still put the handle in place, so
			 * it is given the chance before giving up.
			 */
			$bootstrap = dirname( __DIR__ ) . '/bootstrap-integration.php';

			if ( is_file( $bootstrap ) ) {
				require_once $bootstrap;
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! defined( 'ABSPATH' ) ) {
			self::markTestSkipped(
				'SKIPPED, NOT PASSED: WordPress is not loaded, so the outbox table cannot be created and none of '
				. 'the guarantees below were checked. Run this suite inside the containerised WordPress environment.'
			);
		}

		OutboxTable::install();
	}

	/**
	 * Starts every test with an empty outbox.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$table = OutboxTable::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		$this->repository = new OutboxRepository( $wpdb );
	}

	/**
	 * The table the plugin describes is the table the database has.
	 *
	 * @return void
	 */
	public function test_the_install_routine_creates_the_table_and_is_repeatable(): void {
		$this->assertTrue( OutboxTable::exists() );

		OutboxTable::install();

		$this->assertTrue( OutboxTable::exists() );
		$this->assertSame( OutboxTable::SCHEMA_VERSION, get_option( OutboxTable::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * The double capture of one paid order leaves ONE entry.
	 *
	 * This is the whole reason the unique constraint is on the identity: the
	 * payment-complete hook and the status-changed hook both fire for the same
	 * sale, microseconds apart, in the same checkout request.
	 *
	 * @return void
	 */
	public function test_enqueueing_the_same_event_twice_leaves_one_row(): void {
		$this->repository->enqueue( 'woocommerce:order:41:paid', 41, 'order.updated', '{"id":41}', self::SOURCE );
		$this->repository->enqueue( 'woocommerce:order:41:paid', 41, 'order.updated', '{"id":41}', self::SOURCE );

		$rows = $this->all_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'woocommerce:order:41:paid', $rows[0]['event_id'] );
		$this->assertSame( 0, (int) $rows[0]['attempts'] );
	}

	/**
	 * A repeat capture does not resurrect an entry that was already delivered.
	 *
	 * The insert collapses onto a no-op update precisely so that this cannot
	 * happen: sending an already-invoiced sale again would produce a second
	 * document in a consecutive series registered with the tax authority.
	 *
	 * @return void
	 */
	public function test_enqueueing_again_does_not_reopen_a_delivered_entry(): void {
		$this->repository->enqueue( 'woocommerce:order:42:paid', 42, 'order.updated', '{"id":42}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );
		$this->repository->settle( $claimed[0]['id'], DeliveryOutcome::accepted( 200 ), null );

		$this->repository->enqueue( 'woocommerce:order:42:paid', 42, 'order.updated', '{"id":42}', self::SOURCE );

		$rows = $this->all_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( OutboxRepository::STATUS_DELIVERED, $rows[0]['status'] );
		$this->assertSame( array(), $this->repository->claim_due( 10 ) );
	}

	/**
	 * The claim hands back the entry with the body and the source it was stored
	 * with, typed as the dispatcher declares them.
	 *
	 * @return void
	 */
	public function test_a_claimed_row_carries_the_stored_body_and_source(): void {
		$this->repository->enqueue( 'woocommerce:order:43:paid', 43, 'order.updated', '{"id":43,"total":"12.00"}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );

		$this->assertCount( 1, $claimed );
		$this->assertIsInt( $claimed[0]['id'] );
		$this->assertSame( 43, $claimed[0]['order_id'] );
		$this->assertSame( 'order.updated', $claimed[0]['event_type'] );
		$this->assertSame( '{"id":43,"total":"12.00"}', $claimed[0]['raw_body'] );
		$this->assertSame( self::SOURCE, $claimed[0]['source'] );
		$this->assertSame( 0, $claimed[0]['attempts'] );
		$this->assertNull( $claimed[0]['last_status_code'] );
	}

	/**
	 * Only what is due gets claimed.
	 *
	 * @return void
	 */
	public function test_only_due_entries_are_claimed(): void {
		$this->repository->enqueue( 'woocommerce:order:44:paid', 44, 'order.updated', '{"id":44}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );
		$this->repository->settle(
			$claimed[0]['id'],
			DeliveryOutcome::transient_failure( 503, 'Factuarea did not answer. The order will be sent again.' ),
			time() + 3600
		);

		$this->assertSame( array(), $this->repository->claim_due( 10 ), 'An entry scheduled for the future must not be claimed.' );

		$this->repository->settle(
			$claimed[0]['id'],
			DeliveryOutcome::transient_failure( 503, 'Factuarea did not answer. The order will be sent again.' ),
			time() - 1
		);

		$due = $this->repository->claim_due( 10 );

		$this->assertCount( 1, $due );
		$this->assertSame( 2, $due[0]['attempts'], 'Each settled attempt increments the counter the retry planner reads.' );
	}

	/**
	 * Two drains running at the same moment never get the same entry.
	 *
	 * The two claims are made one after the other on purpose: that is the worst
	 * case for the mechanism, because the second drain runs when the first has
	 * already written its token and could still see those rows if the claim were
	 * a SELECT followed by an UPDATE.
	 *
	 * @return void
	 */
	public function test_two_claims_do_not_overlap(): void {
		foreach ( range( 51, 54 ) as $order_id ) {
			$this->repository->enqueue( "woocommerce:order:{$order_id}:paid", $order_id, 'order.updated', '{"id":' . $order_id . '}', self::SOURCE );
		}

		$first  = $this->repository->claim_due( 2 );
		$second = $this->repository->claim_due( 2 );

		$first_ids  = array_column( $first, 'id' );
		$second_ids = array_column( $second, 'id' );

		$this->assertCount( 2, $first_ids );
		$this->assertCount( 2, $second_ids );
		$this->assertSame( array(), array_intersect( $first_ids, $second_ids ), 'Two drains must never be handed the same entry.' );

		$all = array_merge( $first_ids, $second_ids );
		sort( $all );

		$this->assertCount( 4, array_unique( $all ), 'Between them the two drains must cover every due entry exactly once.' );
		$this->assertSame( array(), $this->repository->claim_due( 10 ), 'Nothing is left to claim, and a fresh claim is not re-handed.' );
	}

	/**
	 * A claim held by a drain that died is taken back, and only after the
	 * timeout.
	 *
	 * Without this, one fatal error in the middle of a drain silences those
	 * sales forever, which is the exact failure the whole outbox exists to
	 * remove - just moved one layer down.
	 *
	 * @return void
	 */
	public function test_a_stale_claim_is_recovered(): void {
		global $wpdb;

		$this->repository->enqueue( 'woocommerce:order:55:paid', 55, 'order.updated', '{"id":55}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );
		$this->assertCount( 1, $claimed );

		$table = OutboxTable::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claimed_at = %d WHERE id = %d",
				time() - OutboxRepository::CLAIM_TIMEOUT_SECONDS - 1,
				$claimed[0]['id']
			)
		);

		$recovered = $this->repository->claim_due( 10 );

		$this->assertCount( 1, $recovered );
		$this->assertSame( $claimed[0]['id'], $recovered[0]['id'] );
		$this->assertSame( 0, $recovered[0]['attempts'], 'A claim that never completed did not consume an attempt.' );
	}

	/**
	 * An accepted delivery closes the entry for good.
	 *
	 * @return void
	 */
	public function test_settling_with_acceptance_closes_the_entry(): void {
		$this->repository->enqueue( 'woocommerce:order:61:paid', 61, 'order.updated', '{"id":61}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );
		$this->repository->settle( $claimed[0]['id'], DeliveryOutcome::accepted( 200 ), null );

		$row = $this->all_rows()[0];

		$this->assertSame( OutboxRepository::STATUS_DELIVERED, $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 0, (int) $row['next_attempt_at'] );
		$this->assertSame( 200, (int) $row['last_status_code'] );
		$this->assertSame( array(), $this->repository->claim_due( 10 ) );
	}

	/**
	 * A failure that is to be retried leaves the entry alive with its next
	 * attempt, its reason and its status code.
	 *
	 * @return void
	 */
	public function test_settling_with_a_failure_leaves_the_entry_with_its_next_attempt(): void {
		$this->repository->enqueue( 'woocommerce:order:62:paid', 62, 'order.updated', '{"id":62}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );
		$next    = time() + 300;

		$this->repository->settle(
			$claimed[0]['id'],
			DeliveryOutcome::retryable_rejection( 429, 'store_budget_exhausted', 300, 'Factuarea asked to wait. The order will be sent again.' ),
			$next
		);

		$row = $this->all_rows()[0];

		$this->assertSame( OutboxRepository::STATUS_PENDING, $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( $next, (int) $row['next_attempt_at'] );
		$this->assertSame( 429, (int) $row['last_status_code'] );
		$this->assertSame( 'Factuarea asked to wait. The order will be sent again.', $row['last_message'] );
	}

	/**
	 * A permanent rejection closes the entry WITHOUT a single further attempt,
	 * and keeps the reason for the merchant.
	 *
	 * @return void
	 */
	public function test_a_permanent_rejection_is_never_retried(): void {
		$this->repository->enqueue( 'woocommerce:order:63:paid', 63, 'order.updated', '{"id":63}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );

		$this->repository->settle(
			$claimed[0]['id'],
			DeliveryOutcome::permanent_rejection( 422, 'store_not_released', 'This store is not enabled in Factuarea yet.' ),
			null
		);

		$row = $this->all_rows()[0];

		$this->assertSame( OutboxRepository::STATUS_FAILED, $row['status'] );
		$this->assertSame( 0, (int) $row['next_attempt_at'] );
		$this->assertSame( 'This store is not enabled in Factuarea yet.', $row['last_message'] );
		$this->assertSame( array(), $this->repository->claim_due( 10 ) );
	}

	/**
	 * A retryable failure with no next attempt left is the spent budget, and it
	 * gets its own status because it is the one a human has to look at.
	 *
	 * @return void
	 */
	public function test_a_retryable_failure_without_a_next_attempt_exhausts_the_entry(): void {
		$this->repository->enqueue( 'woocommerce:order:64:paid', 64, 'order.updated', '{"id":64}', self::SOURCE );

		$claimed = $this->repository->claim_due( 10 );

		$this->repository->settle(
			$claimed[0]['id'],
			DeliveryOutcome::transient_failure( null, 'Factuarea could not be reached.' ),
			null
		);

		$row = $this->all_rows()[0];

		$this->assertSame( OutboxRepository::STATUS_EXHAUSTED, $row['status'] );
		$this->assertNull( $row['last_status_code'], 'Nothing came back, which is not the same as a status code of zero.' );
		$this->assertSame( array(), $this->repository->claim_due( 10 ) );
	}

	/**
	 * The stalled count separates a spent budget from an entry nobody is
	 * draining, and counts neither a healthy queue nor a delivered one.
	 *
	 * @return void
	 */
	public function test_the_stalled_count_separates_exhausted_from_overdue(): void {
		$this->repository->enqueue( 'woocommerce:order:71:paid', 71, 'order.updated', '{"id":71}', self::SOURCE );
		$this->repository->enqueue( 'woocommerce:order:72:paid', 72, 'order.updated', '{"id":72}', self::SOURCE );
		$this->repository->enqueue( 'woocommerce:order:73:paid', 73, 'order.updated', '{"id":73}', self::SOURCE );

		$now     = time();
		$claimed = $this->repository->claim_due( 10 );
		$by_order = array();

		foreach ( $claimed as $row ) {
			$by_order[ $row['order_id'] ] = $row['id'];
		}

		$this->repository->settle( $by_order[71], DeliveryOutcome::transient_failure( null, 'Factuarea could not be reached.' ), null );
		$this->repository->settle( $by_order[72], DeliveryOutcome::transient_failure( 503, 'Factuarea did not answer.' ), $now - 7200 );
		$this->repository->settle( $by_order[73], DeliveryOutcome::accepted( 200 ), null );

		$this->assertSame(
			array(
				'exhausted' => 1,
				'overdue'   => 1,
			),
			$this->repository->count_stalled( 3600, $now )
		);

		$this->assertSame(
			array(
				'exhausted' => 1,
				'overdue'   => 0,
			),
			$this->repository->count_stalled( 86400, $now ),
			'An entry overdue by two hours is not stalled when the window is a day.'
		);
	}

	/**
	 * A limit below one claims nothing, and claims it without touching the
	 * database.
	 *
	 * @return void
	 */
	public function test_a_limit_below_one_claims_nothing(): void {
		$this->repository->enqueue( 'woocommerce:order:81:paid', 81, 'order.updated', '{"id":81}', self::SOURCE );

		$this->assertSame( array(), $this->repository->claim_due( 0 ) );
		$this->assertSame( OutboxRepository::STATUS_PENDING, $this->all_rows()[0]['status'] );
	}

	/**
	 * Every row of the outbox, oldest first, exactly as stored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all_rows(): array {
		global $wpdb;

		$table = OutboxTable::table_name();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}
}
