<?php
/**
 * The durable record of which inbound deliveries have already been claimed.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\Constants;
use RuntimeException;

/**
 * Claim a key, release it if the effect failed, confirm it when the effect
 * landed, and prune what is older than the retention window.
 *
 * ---------------------------------------------------------------------------
 * FOUR OPERATIONS, AND NOT ONE READ THAT DECIDES ANYTHING
 * ---------------------------------------------------------------------------
 * Every one of the four below is a SINGLE statement whose WHERE clause - or
 * whose unique index - carries the whole decision. There is no "look first,
 * then act" anywhere in this file, and that is the property the correctness of
 * the inbound half rests on:
 *
 *  - `claim` does not ask whether the key is already there. It inserts, and
 *    lets the UNIQUE index collapse the duplicate. Between a SELECT that finds
 *    nothing and the INSERT that follows it there is a window, and two
 *    deliveries of the same key walk straight through it: both read nothing,
 *    both apply the effect, and the merchant gets two refunds on one order.
 *    The sender's queue guarantees AT LEAST one delivery, so a redelivery is
 *    not an exotic case, it is the normal behaviour of an unstable network.
 *  - `mark_applied` addresses one row by its key and only moves it from "not
 *    yet applied" to an instant.
 *  - `forget` deletes one row by its key.
 *  - `prune` deletes by age, in one statement, with the index on `received_at`
 *    behind it.
 *
 * It is the same criterion `Outbox\OutboxRepository` applies in the opposite
 * direction, and the reason is written there at length.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER THE RECEIVER USES, AND WHY `forget` AND `mark_applied` ARE A PAIR
 * ---------------------------------------------------------------------------
 * The contract of the two receivers is: verify the signature, check the
 * receiver switch, CLAIM the key, read the body, and only then apply. The claim
 * therefore happens BEFORE the effect, which is the only order that makes it a
 * claim at all - claiming afterwards would leave the window open across the
 * whole application.
 *
 * That order leaves the key claimed while the effect is still in flight, so the
 * two ends of the flight have one method each:
 *
 *  - `forget` is called in ONE situation, and nowhere else: the application of
 *    the effect FAILED after the key was claimed. Releasing it lets the
 *    sender's retry come back and try again. It is NOT called when the delivery
 *    is rejected before the claim (a bad signature, a disabled receiver, a
 *    malformed body): none of those ever claimed anything, so there is nothing
 *    to release. And it is NOT called after a successful application, which
 *    would delete the very record that makes the redelivery a 409.
 *  - `mark_applied` is called when the effect landed. It writes the instant
 *    into `applied_at`, so that a row still at `InboundDeliveryTable::NOT_YET_APPLIED`
 *    is readable for what it is: a delivery that was taken and whose process
 *    died before saying it landed.
 *
 * A row left unconfirmed is NOT re-claimable, and that is a decision with a
 * reason rather than an oversight. Re-claiming after a timeout - the way
 * `Outbox\OutboxRepository::claim_due()` recovers a dead drain's rows - is safe
 * for an OUTBOUND queue, where the worst case is delivering twice to a receiver
 * that deduplicates. Here the worst case is the opposite: the process may well
 * have applied the refund and died before the confirmation, so handing the key
 * back would apply that refund a second time, and the second one lands behind a
 * corrective invoice in a consecutive series registered with the tax authority,
 * where nothing is deleted and the only cure is another document. Between a
 * missing confirmation and a possible double effect, this ledger takes the
 * missing confirmation.
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
 * the answer of a claim is not an optimisation but a bug - a cached "this key
 * is new" is a second refund.
 *
 * ---------------------------------------------------------------------------
 * NOT `final`, AND THAT IS A DECISION WITH A REASON
 * ---------------------------------------------------------------------------
 * The same reason `Outbox\OutboxRepository` is not final: the two receivers
 * take this ledger by constructor, and their unit tests - which run without a
 * database, with the WordPress functions doubled - have to stand in for it.
 * Doubling a final class is not possible, and an interface with exactly one
 * implementor would be a second file to keep in step with these signatures for
 * no gain.
 *
 * What being open does NOT mean: it is not an extension point. Nothing in this
 * plugin subclasses it, and a subclass that overrode one of the four methods
 * would be overriding the statement that carries the guarantee, not a policy.
 */
class InboundDeliveryLedger {

	/**
	 * Number of rows a statement reports when it wrote the one row it addressed.
	 *
	 * It is named because it is the whole answer of `claim()`: `INSERT IGNORE`
	 * reports one row when it inserted and none when the unique index stopped
	 * it, and those two numbers ARE "this delivery is new" and "this delivery
	 * has already been claimed".
	 */
	private const ONE_ROW = 1;

	/**
	 * Number of rows reported when the statement wrote nothing - because the
	 * unique index rejected the insert, or because no row matched the update.
	 */
	private const NO_ROWS = 0;

	/**
	 * The WordPress database handle.
	 *
	 * Every method below opens with `$wpdb = $this->wpdb;` before using it, and
	 * that local name is not decoration: the coding standard recognises a
	 * prepared statement by the NAME of the variable the call is made on, so a
	 * query issued straight off the property reads to it as unprepared SQL. The
	 * choice is between a local alias and silencing the sniff that guards
	 * against injection on four call sites - and silencing that one, to make a
	 * naming limitation go away, is how a real unprepared query gets in later
	 * under a comment that says it was reviewed.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Builds the ledger on the WordPress database handle.
	 *
	 * The argument exists so that a test can hand in a double; production
	 * passes nothing and gets the global handle. Outside WordPress there is no
	 * handle and the construction fails loudly, which is the right outcome: a
	 * ledger with no database that quietly answered "new delivery" to
	 * everything would apply every retry of every notice.
	 *
	 * @param \wpdb|null $wpdb Database handle, or null to take the global one.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Takes the key for this delivery, or reports that it was already taken.
	 *
	 * ONE STATEMENT, AND THE INDEX DECIDES. `INSERT IGNORE` either writes the
	 * row or is stopped by the unique index on the key, and the number of rows
	 * it reports is the answer: one means this delivery is new and its effect
	 * may be applied, zero means the key was already claimed and the receiver
	 * has to answer "redelivery discarded" without writing anything.
	 *
	 * WHY `INSERT IGNORE` AND NOT `ON DUPLICATE KEY UPDATE`, which is what the
	 * outbox uses: the outbox never reads the number of rows back, and here
	 * that number IS the answer. With `ON DUPLICATE KEY UPDATE` the count is
	 * ambiguous by documented behaviour - a row set to its current values
	 * reports zero, but reports ONE when the connection was opened with the
	 * `CLIENT_FOUND_ROWS` flag, which a host can switch on through
	 * `MYSQL_CLIENT_FLAGS`. On such an installation a redelivery would report
	 * the same number as a first delivery and every retry would be applied
	 * again. `INSERT IGNORE` has no update branch to be counted, so the flag
	 * cannot reach it.
	 *
	 * WHY THE LENGTH IS GUARDED, when nothing else about the key is checked:
	 * the shape of the key is deliberately NOT validated anywhere in this
	 * package - the inbound contract says so, because a receiver that demanded
	 * a particular shape would start rejecting legitimate deliveries the day
	 * the sender changed how it derives them, in every installation already
	 * published. The LENGTH is different, and it is not a validation but a
	 * limit of this table: `INSERT IGNORE` downgrades a value too long for the
	 * column into a truncation warning, and two distinct keys truncated to the
	 * same characters would deduplicate two DIFFERENT deliveries - the one
	 * failure this table exists to prevent, arriving as silence. The contract
	 * caps the key at 64 characters and the column holds
	 * `InboundDeliveryTable::KEY_MAX_LENGTH`, so a key that does not fit is a
	 * broken sender and not a merchant's bad luck; the receiver is expected to
	 * answer it as malformed BEFORE claiming, and this guard is the backstop
	 * for the day it does not.
	 *
	 * A DATABASE ERROR IS NOT AN ANSWER, and this is why it throws rather than
	 * returning either value. A missing table - a partially restored backup, a
	 * migration between hosts that missed one table - would make `$wpdb->query`
	 * answer false; reading that as "already claimed" would discard every
	 * delivery for ever with a 409 the sender takes as published, and reading it
	 * as "new" would apply every retry again. The exception leaves the REST
	 * callback, WordPress renders it as a 500, and the sender classifies a 500
	 * as retryable, which is exactly what a shop whose ledger is broken should
	 * get.
	 *
	 * @param string $idempotency_key Key the sender declared for this delivery.
	 * @param int    $now             Unix timestamp of the moment the delivery arrived.
	 * @return bool True when this delivery is new and its effect may be applied; false when
	 *              the key had already been claimed and nothing must be written.
	 *
	 * @throws RuntimeException When the key does not fit the column, or when the database
	 *                          refuses the write - neither of which is an answer about
	 *                          whether the delivery is new.
	 */
	public function claim( string $idempotency_key, int $now ): bool {
		if ( strlen( $idempotency_key ) > InboundDeliveryTable::KEY_MAX_LENGTH ) {
			throw new RuntimeException(
				'The inbound delivery ledger was handed an idempotency key longer than the column that holds it, '
				. 'so it cannot guarantee that two deliveries with different keys stay apart. The delivery was not claimed.'
			);
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned ledger table; the insert IS the claim and a cached answer is a second refund.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i ( idempotency_key, received_at, applied_at ) VALUES ( %s, %d, %d )',
				InboundDeliveryTable::table_name(),
				$idempotency_key,
				$now,
				InboundDeliveryTable::NOT_YET_APPLIED
			)
		);

		if ( self::ONE_ROW === $inserted ) {
			return true;
		}

		if ( self::NO_ROWS === $inserted ) {
			return false;
		}

		throw new RuntimeException(
			'The inbound delivery ledger could not record the delivery, so whether it had already been applied is '
			. 'unknown. The table may be missing from this installation; reactivating the plugin recreates it.'
		);
	}

	/**
	 * Records that the effect of an already claimed delivery landed.
	 *
	 * Called by the receiver right after the effect is applied, and it is the
	 * other half of `forget()`: between the two, every claimed key ends up
	 * either gone or dated. Without this call every row would sit for ever at
	 * `InboundDeliveryTable::NOT_YET_APPLIED` and the column would stop telling
	 * a crash apart from a normal delivery.
	 *
	 * It is conditioned on the row still being unconfirmed, so calling it twice
	 * for the same delivery does not move the instant a second time: the first
	 * confirmation is the one that describes when the effect actually landed,
	 * and a later one would be describing something else.
	 *
	 * A failure here is NOT propagated and NOT compensated: the effect already
	 * landed, the claim is already in place, and the redelivery will be
	 * discarded exactly as it should be. What is lost is the diagnosis, not the
	 * guarantee, and throwing would turn a delivery that fully succeeded into a
	 * 500 the sender would retry.
	 *
	 * @param string $idempotency_key Key of the delivery whose effect landed.
	 * @param int    $now             Unix timestamp of the moment the effect landed.
	 * @return bool True when this call is the one that dated the row; false when there was
	 *              no unconfirmed row to date - already confirmed, released, or pruned.
	 */
	public function mark_applied( string $idempotency_key, int $now ): bool {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned ledger table; dates one row by its key and must not be cached.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET applied_at = %d WHERE idempotency_key = %s AND applied_at = %d',
				InboundDeliveryTable::table_name(),
				$now,
				$idempotency_key,
				InboundDeliveryTable::NOT_YET_APPLIED
			)
		);

		return self::ONE_ROW === $updated;
	}

	/**
	 * Releases a claimed key so that the sender's retry can come back.
	 *
	 * THE ONE SITUATION THIS IS FOR: the effect failed AFTER the key was
	 * claimed. The claim happens before the effect - that order is what makes
	 * it a claim - so a failure in between would otherwise leave the delivery
	 * recorded as handled while nothing was applied, and the sender's retry
	 * would be answered "redelivery discarded", which it classifies as
	 * PUBLISHED. The notice would be lost with every party believing it
	 * arrived.
	 *
	 * WHY IT IS CALLED NOWHERE ELSE, path by path:
	 *
	 *  - a delivery rejected for its signature, for a disabled receiver or for
	 *    a malformed body never reached the claim, so there is nothing to
	 *    release and calling this would delete SOMEBODY ELSE'S row if the key
	 *    happened to collide with a claimed one;
	 *  - a delivery that was applied successfully must keep its row: that row
	 *    is what turns the redelivery into a 409 instead of a second refund;
	 *  - a redelivery that was discarded did not claim anything either - the
	 *    claim answered false precisely because the row was already there - and
	 *    releasing on that path would delete the record of the delivery that
	 *    DID apply, reopening the whole window for the next retry.
	 *
	 * A failure of the deletion is not propagated: there is nothing useful to
	 * do with it at the call site and the receiver is already answering an
	 * error for the effect that failed. The consequence is written down rather
	 * than hidden: the key stays claimed, and the sender's retry is answered as
	 * a redelivery.
	 *
	 * @param string $idempotency_key Key that was claimed and whose effect failed.
	 * @return void
	 */
	public function forget( string $idempotency_key ): void {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned ledger table; releases one row by its key so the sender's retry can return.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE idempotency_key = %s',
				InboundDeliveryTable::table_name(),
				$idempotency_key
			)
		);
	}

	/**
	 * Removes every delivery received before the given instant, and answers how
	 * many it removed.
	 *
	 * The cut-off is a parameter and not a figure read here, so that the caller
	 * decides WHEN the pass runs while this class decides HOW LONG the window
	 * is: `prune_cutoff()` below is the only place that turns the retention
	 * window into an instant, and it reads
	 * `Constants::INBOUND_RETENTION_SECONDS` and no other figure.
	 *
	 * Age is measured on `received_at`, never on `applied_at`. A row whose
	 * effect was never confirmed - a process that died mid-application - has to
	 * age out too, and measuring on the confirmation would keep it for ever.
	 *
	 * A FAILURE ANSWERS ZERO INSTEAD OF THROWING, and here that is the right
	 * way round, unlike in `claim()`. The prune runs inside the same scheduled
	 * pass that drains the outbox, so an exception from a housekeeping deletion
	 * would take the delivery of the merchant's sales down with it. Keeping the
	 * rows one pass longer costs disk; stopping the drain costs invoices.
	 *
	 * @param int $older_than Unix timestamp; every delivery received strictly before it goes.
	 * @return int How many rows were removed. Zero when there was nothing to remove and also
	 *             when the deletion failed, which are the same thing from the caller's side:
	 *             nothing was removed.
	 */
	public function prune( int $older_than ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned ledger table; deletes by age behind its own index and must not be cached.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE received_at < %d',
				InboundDeliveryTable::table_name(),
				$older_than
			)
		);

		return is_int( $deleted ) ? $deleted : self::NO_ROWS;
	}

	/**
	 * The instant before which a delivery is old enough to be forgotten.
	 *
	 * THE ONE PLACE THE RETENTION WINDOW BECOMES AN INSTANT. Every caller of
	 * `prune()` composes its cut-off here, so the window of the ledger is
	 * `Constants::INBOUND_RETENTION_SECONDS` and nothing else. A second
	 * subtraction written at a call site would be a second window, and the one
	 * that got shortened would be the one nobody remembered: a retry that
	 * arrives with its key already purged is taken for a new delivery and its
	 * effect is applied a SECOND time.
	 *
	 * Why the window is what it is - strictly greater than the whole span of
	 * the shared retry schedule, with a margin - is written where the number
	 * lives, in `Constants::INBOUND_RETENTION_SECONDS`, and it is guarded by a
	 * test that compares it with the SUM of `RetrySchedule::INTERVALS` instead
	 * of with a number typed by hand.
	 *
	 * @param int $now Unix timestamp of the moment the pass runs.
	 * @return int Unix timestamp; deliveries received strictly before it may be removed.
	 */
	public static function prune_cutoff( int $now ): int {
		return $now - Constants::INBOUND_RETENTION_SECONDS;
	}
}
