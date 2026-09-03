<?php
/**
 * The three operations the outbox supports.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Outbox;

use Factuarea\WooCommerce\Contract\DeliveryOutcome;
use Factuarea\WooCommerce\Contract\DeliveryOutcomeKind;

/**
 * Enqueue an event, claim what is due, settle what came back.
 *
 * ---------------------------------------------------------------------------
 * THREE OPERATIONS, AND NO READ THAT DECIDES ANYTHING
 * ---------------------------------------------------------------------------
 * Every one of the three below is a single statement whose WHERE clause carries
 * the whole decision. There is no "look first, then act" anywhere in this file,
 * and that is the property the correctness of the outbox rests on:
 *
 *  - `enqueue` does not ask whether the event is already there. It inserts and
 *    lets the UNIQUE constraint collapse the duplicate. Between a SELECT that
 *    finds nothing and the INSERT that follows it there is a window, and the
 *    two hooks that see the same paid order fire microseconds apart in the same
 *    checkout - so that window is not theoretical, it is the normal case.
 *  - `claim_due` does not select the due entries and then mark them. It marks
 *    them, with a token, and then reads back what it marked. Between a SELECT
 *    and an UPDATE, a second drain reads the same rows and both deliver them,
 *    and the receiver's own idempotency is the only thing left standing between
 *    the merchant and two invoices for one sale. Leaning on that is leaning on
 *    somebody else's guarantee to cover our race.
 *  - `settle` addresses one row by its primary key.
 *
 * ---------------------------------------------------------------------------
 * EVERY QUERY GOES THROUGH prepare(), THE TABLE NAME INCLUDED
 * ---------------------------------------------------------------------------
 * Identifiers are bound with `%i`, which `$wpdb->prepare` has understood since
 * WordPress 6.2 - below the 6.5 this plugin requires. Nothing in this file
 * concatenates a value into SQL.
 *
 * The direct-query sniffs are silenced one call at a time with the same reason:
 * this is a table this plugin owns, no core API reads or writes it, and caching
 * a queue is not an optimisation but a bug - a cached "nothing is due" is
 * exactly the silence the outbox exists to remove.
 *
 * ---------------------------------------------------------------------------
 * NOT `final`, AND THAT IS A DECISION WITH A REASON
 * ---------------------------------------------------------------------------
 * Every other concrete class of this plugin is final. This one is not, because
 * the settings screen takes it by constructor to count a stalled outbox, and the
 * test of that screen has to stand in for it: doubling a final class is not
 * possible, and the alternative - an interface with exactly one implementor -
 * would be a second file to keep in step with these signatures for no gain.
 *
 * What being open does NOT mean: it is not an extension point. Nothing in this
 * plugin subclasses it, and a subclass that overrode one of the four methods
 * would be overriding the statement that carries the guarantee, not a policy.
 */
class OutboxRepository {

	/**
	 * Waiting for its next delivery attempt. The only status `claim_due` picks
	 * up, and the only non-final one together with CLAIMED.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Taken by a drain and not yet settled. A row sits here for the duration of
	 * one delivery attempt; if the process handling it dies, `claim_due` takes
	 * it back after CLAIM_TIMEOUT_SECONDS.
	 */
	public const STATUS_CLAIMED = 'claimed';

	/**
	 * The receiver accepted it. Final: never sent again.
	 */
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * The receiver rejected it under its contract and declared no wait. Final,
	 * WITHOUT a single further attempt, with the reason kept for the merchant.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The retry budget ran out. Final in the sense that nothing schedules it
	 * again, and the status the stalled-outbox notice counts: unlike DELIVERED
	 * and FAILED, this one means the plugin stopped trying without ever getting
	 * an answer it understood, which is a fact a human has to see.
	 */
	public const STATUS_EXHAUSTED = 'exhausted';

	/**
	 * How long a claim is honoured before another drain may take the entry back,
	 * in seconds.
	 *
	 * Without this, a drain that dies mid-attempt - a fatal error, a PHP timeout
	 * on a shared host, a killed request - leaves its rows CLAIMED forever, and
	 * those sales are never delivered and never reported. The number is
	 * generously above any sane HTTP timeout of a single delivery, so a slow
	 * receiver is never mistaken for a dead drain.
	 */
	public const CLAIM_TIMEOUT_SECONDS = 300;

	/**
	 * Sentinel that means "no status code came back at all", used only inside
	 * the statement that settles an entry.
	 *
	 * It never reaches the column: `NULLIF` turns it into NULL before the
	 * assignment, which is what the column is supposed to hold when the delivery
	 * never got an answer. It is negative because no HTTP response can carry a
	 * negative status, so it cannot collide with a real one.
	 */
	private const NO_STATUS_CODE = -1;

	/**
	 * The WordPress database handle.
	 *
	 * Every method below opens with `$wpdb = $this->wpdb;` before using it, and
	 * that local name is not decoration: the coding standard recognises a
	 * prepared statement by the NAME of the variable the call is made on, so a
	 * query issued straight off the property reads to it as unprepared SQL. The
	 * choice is between a local alias and silencing the sniff that guards
	 * against injection on five call sites - and silencing that one, to make a
	 * naming limitation go away, is how a real unprepared query gets in later
	 * under a comment that says it was reviewed.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Builds the repository on the WordPress database handle.
	 *
	 * The argument exists so that a test can hand in a double; production passes
	 * nothing and gets the global handle. Outside WordPress there is no handle
	 * and the construction fails loudly, which is the right outcome: a
	 * repository with no database that quietly did nothing would swallow sales.
	 *
	 * @param \wpdb|null $wpdb Database handle, or null to take the global one.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Records an event in the outbox, collapsing a repeat of the same event.
	 *
	 * The insert carries `ON DUPLICATE KEY UPDATE id = id`, which is a
	 * deliberate no-op rather than a merge. A second capture of an event must
	 * change NOTHING about the row that is already there: not its attempt count,
	 * not its next attempt, and above all not its status - resurrecting a
	 * DELIVERED entry because a hook fired again would send a sale that was
	 * already invoiced and produce a second document in a consecutive series
	 * registered with the tax authority, where nothing is deleted and the only
	 * cure is another document.
	 *
	 * The body is stored as given. It is the exact byte sequence that will be
	 * signed and sent; see `DeliverySigner` for why re-encoding it anywhere
	 * between here and the wire breaks the signature with no local error.
	 *
	 * @param string $event_id   Identity composed by `EventIdentity`, from the domain object and from nothing else.
	 * @param int    $order_id   WooCommerce order the event belongs to.
	 * @param string $event_type Fact being reported, in the receiver's vocabulary.
	 * @param string $raw_body   Exact bytes to deliver.
	 * @param string $source     Address of the store the event was captured for.
	 * @return void
	 */
	public function enqueue( string $event_id, int $order_id, string $event_type, string $raw_body, string $source ): void {
		$wpdb = $this->wpdb;
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned queue table; no core API writes it and a cached queue is a lost sale.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( event_id, order_id, event_type, raw_body, source, attempts, next_attempt_at, status, last_status_code, last_message, claimed_token, claimed_at, created_at, updated_at )'
				. ' VALUES ( %s, %d, %s, %s, %s, 0, %d, %s, NULL, %s, %s, 0, %d, %d )'
				. ' ON DUPLICATE KEY UPDATE id = id',
				OutboxTable::table_name(),
				$event_id,
				$order_id,
				$event_type,
				$raw_body,
				$source,
				$now,
				self::STATUS_PENDING,
				'',
				'',
				$now,
				$now
			)
		);
	}

	/**
	 * Takes up to $limit due entries for this drain, and hands back what it took.
	 *
	 * TWO STATEMENTS, IN THIS ORDER, AND NEVER A SELECT FIRST. The UPDATE is the
	 * claim: it is conditioned on the status, so if two drains run at the same
	 * moment the database serialises them and the second one finds nothing left
	 * PENDING to mark. The SELECT that follows reads back this drain's own token
	 * and therefore cannot see the other drain's rows. Reversing the order - or
	 * inserting a SELECT to "see what is due" before claiming - reopens exactly
	 * the window the token exists to close.
	 *
	 * The claim also recovers entries whose token was written more than
	 * CLAIM_TIMEOUT_SECONDS ago. That is not the same condition as being due: it
	 * covers the drain that died holding rows, which would otherwise leave those
	 * sales claimed by a process that no longer exists, forever.
	 *
	 * @param int $limit Maximum number of entries to take.
	 * @return array<int, array<string, mixed>> Rows already claimed, oldest due first. Each row has the keys
	 *                                          `id`, `event_id`, `order_id`, `event_type`, `raw_body`, `source`,
	 *                                          `attempts`, `next_attempt_at`, `status`, `last_status_code`,
	 *                                          `last_message`, `claimed_token`, `claimed_at`, `created_at`,
	 *                                          `updated_at`, with `id`, `order_id`, `attempts`, `next_attempt_at`,
	 *                                          `claimed_at`, `created_at` and `updated_at` as int,
	 *                                          `last_status_code` as int or null, and the rest as string.
	 */
	public function claim_due( int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$now   = time();
		$table = OutboxTable::table_name();
		$token = $this->new_claim_token();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned queue table; the claim is the write that makes two drains disjoint.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, claimed_token = %s, claimed_at = %d, updated_at = %d'
				. ' WHERE ( status = %s AND next_attempt_at <= %d ) OR ( status = %s AND claimed_at <= %d )'
				. ' ORDER BY next_attempt_at ASC LIMIT %d',
				$table,
				self::STATUS_CLAIMED,
				$token,
				$now,
				$now,
				self::STATUS_PENDING,
				$now,
				self::STATUS_CLAIMED,
				$now - self::CLAIM_TIMEOUT_SECONDS,
				$limit
			)
		);

		if ( ! is_int( $claimed ) || 0 === $claimed ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned queue table; reads back this drain's own claim, which must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_id, order_id, event_type, raw_body, source, attempts, next_attempt_at, status, last_status_code, last_message, claimed_token, claimed_at, created_at, updated_at'
				. ' FROM %i WHERE claimed_token = %s ORDER BY next_attempt_at ASC',
				$table,
				$token
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'shape_row' ), $rows );
	}

	/**
	 * Records what came back for one entry and decides where it goes next.
	 *
	 * The status follows the outcome, and the two terminal ones are terminal for
	 * different reasons: ACCEPTED because there is nothing left to do, and
	 * PERMANENT_REJECTION because the receiver said so under a contract that
	 * needs no table of known codes to read - which is what lets an entry
	 * rejected with a code that did not exist on the day this plugin shipped
	 * close without burning eight attempts against a wall.
	 *
	 * For the two retryable outcomes, $next_attempt_at is what decides: an
	 * instant reschedules the entry, and null means the retry budget is spent
	 * and the entry stops being tried. Only the CALLER knows which, because only
	 * the caller consulted the retry planner.
	 *
	 * THE ATTEMPT COUNTER IS INCREMENTED HERE, and the caller must plan the next
	 * attempt for `attempts + 1` - the number of attempts that will have failed
	 * once this one is recorded - and not for the `attempts` it read off the
	 * claimed row. Planning for the stale number repeats every wait and doubles
	 * the time the merchant's sale spends undelivered.
	 *
	 * @param int             $entry_id        Primary key of the entry.
	 * @param DeliveryOutcome $outcome         What the classifier made of the response.
	 * @param int|null        $next_attempt_at Unix timestamp of the next attempt, or null when there is to be none.
	 * @return void
	 */
	public function settle( int $entry_id, DeliveryOutcome $outcome, ?int $next_attempt_at ): void {
		$wpdb   = $this->wpdb;
		$status = self::STATUS_EXHAUSTED;
		$next   = 0;

		if ( DeliveryOutcomeKind::ACCEPTED === $outcome->kind ) {
			$status = self::STATUS_DELIVERED;
		} elseif ( DeliveryOutcomeKind::PERMANENT_REJECTION === $outcome->kind ) {
			$status = self::STATUS_FAILED;
		} elseif ( null !== $next_attempt_at ) {
			$status = self::STATUS_PENDING;
			$next   = $next_attempt_at;
		}

		/*
		 * "Nothing came back at all" has to reach the column as NULL, and there
		 * is no placeholder that binds null. `%d` turns it into 0, which reads
		 * back as a status code that does not exist; `%s` turns it into the
		 * empty string, which an unsigned integer column stores as 0 in a
		 * permissive server and refuses outright in a strict one - so that
		 * spelling would settle nothing and lose the entry.
		 *
		 * `NULLIF` does bind it, in ONE statement and with no second code path:
		 * the sentinel below is not a status code any HTTP response can carry,
		 * so the comparison is true exactly when nothing came back, and the
		 * value the column receives in that case is NULL. The sentinel itself is
		 * bound too rather than written into the SQL, so the two halves of the
		 * comparison cannot drift apart.
		 */
		$status_code = $outcome->status_code ?? self::NO_STATUS_CODE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned queue table; settling one entry by primary key.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = attempts + 1, status = %s, next_attempt_at = %d,'
				. ' last_status_code = NULLIF( %d, %d ), last_message = %s, claimed_token = %s, claimed_at = 0, updated_at = %d'
				. ' WHERE id = %d',
				OutboxTable::table_name(),
				$status,
				$next,
				$status_code,
				self::NO_STATUS_CODE,
				$outcome->merchant_message,
				'',
				time(),
				$entry_id
			)
		);
	}

	/**
	 * Counts the entries that say the outbox has stopped moving.
	 *
	 * Read by the settings screen for the stalled-outbox notice. One aggregate
	 * statement rather than a fetch and a count in PHP: the notice runs on admin
	 * page loads, and a merchant with a long history would otherwise pull the
	 * whole table - bodies included - into memory to produce two integers.
	 *
	 * A row stuck CLAIMED by a dead drain is not counted here and does not need
	 * to be: `claim_due` takes it back after CLAIM_TIMEOUT_SECONDS, at which
	 * point it is PENDING again and overdue, so it appears in the second number
	 * on the next pass. Counting it in both places would report one stuck sale
	 * as two.
	 *
	 * @param int $overdue_after_seconds How long past its due moment a pending entry has to be to count as overdue.
	 * @param int $now                   Unix timestamp to measure against.
	 * @return array{exhausted: int, overdue: int} Entries whose retry budget ran out, and pending entries overdue by more than that window.
	 */
	public function count_stalled( int $overdue_after_seconds, int $now ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned queue table; a cached count would keep telling the merchant the outbox is healthy.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT'
				. ' SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS exhausted,'
				. ' SUM( CASE WHEN status = %s AND next_attempt_at <= %d THEN 1 ELSE 0 END ) AS overdue'
				. ' FROM %i',
				self::STATUS_EXHAUSTED,
				self::STATUS_PENDING,
				$now - max( 0, $overdue_after_seconds ),
				OutboxTable::table_name()
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'exhausted' => 0,
				'overdue'   => 0,
			);
		}

		return array(
			'exhausted' => (int) ( $row['exhausted'] ?? 0 ),
			'overdue'   => (int) ( $row['overdue'] ?? 0 ),
		);
	}

	/**
	 * A token that identifies one claim.
	 *
	 * Its only job is to be different from every other drain's token, so
	 * randomness is enough and no cryptographic property is needed - nothing
	 * authenticates with it and it never leaves the database. Thirty-two
	 * hexadecimal characters is what the column holds.
	 *
	 * @return string
	 */
	private function new_claim_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Gives one row of the result set the types the rest of the plugin expects.
	 *
	 * The database hands everything back as a string, so without this the
	 * dispatcher would compare an attempt count of `'7'` against an integer and
	 * a caller could pass `'404'` where an int is declared. The nullable column
	 * stays nullable: a missing status code means nothing came back, which is
	 * not the same as a status code of zero.
	 *
	 * @param array<string, mixed> $row Row exactly as the database returned it.
	 * @return array<string, mixed>
	 */
	private function shape_row( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'event_id'         => (string) $row['event_id'],
			'order_id'         => (int) $row['order_id'],
			'event_type'       => (string) $row['event_type'],
			'raw_body'         => (string) $row['raw_body'],
			'source'           => (string) $row['source'],
			'attempts'         => (int) $row['attempts'],
			'next_attempt_at'  => (int) $row['next_attempt_at'],
			'status'           => (string) $row['status'],
			'last_status_code' => null === $row['last_status_code'] ? null : (int) $row['last_status_code'],
			'last_message'     => (string) $row['last_message'],
			'claimed_token'    => (string) $row['claimed_token'],
			'claimed_at'       => (int) $row['claimed_at'],
			'created_at'       => (int) $row['created_at'],
			'updated_at'       => (int) $row['updated_at'],
		);
	}
}
