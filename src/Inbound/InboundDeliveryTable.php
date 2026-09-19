<?php
/**
 * Definition of the inbound delivery table inside the merchant's WordPress
 * database.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Contract\Constants;

/**
 * The second table this plugin creates, and the routine that creates or updates
 * it on activation.
 *
 * ---------------------------------------------------------------------------
 * WHY A TABLE OF ITS OWN, AND NOT AN OPTION NOR AN ORDER META
 * ---------------------------------------------------------------------------
 * This table holds the idempotency keys of the inbound deliveries that have
 * already been claimed, so that a redelivery is discarded instead of applying
 * its effect a second time. WordPress offers two tempting places for that and
 * both of them break the one property the ledger rests on:
 *
 *  - An option is a single row that is read and written WHOLE. Two deliveries
 *    arriving at the same moment both read it, both append their key and both
 *    write it back, and one of the two keys disappears with no error anywhere.
 *    There is no unique constraint to lean on, so the claim stops being atomic
 *    exactly when it matters.
 *  - An order meta only serves the notices that already resolved their order,
 *    and the catalogue push has no order at all. It would also make the ledger
 *    a property of the order rather than of the DELIVERY, and the same order
 *    receives several deliveries - a status notice, a refund notice - whose
 *    keys must not collide with one another.
 *
 * A table gives the single thing the claim needs: a UNIQUE constraint that
 * collapses the duplicate in the database, not in PHP. See
 * `InboundDeliveryLedger::claim()` for why that difference is the whole design.
 *
 * ---------------------------------------------------------------------------
 * THE COLUMNS, AND WHAT EACH ONE IS FOR
 * ---------------------------------------------------------------------------
 *  - `idempotency_key`  The key the sender put in the body of the delivery. It
 *                       carries the UNIQUE constraint, and that constraint IS
 *                       the idempotency of the inbound half.
 *  - `received_at`      Unix timestamp of the moment the key was claimed, which
 *                       is the moment the delivery arrived. It is what the
 *                       prune measures age against, and the only column the
 *                       retention window looks at.
 *  - `applied_at`       Unix timestamp of the moment the effect of that
 *                       delivery was confirmed applied, or
 *                       `NOT_YET_APPLIED` while it has not been. See the note
 *                       below: the two instants are not the same instant and
 *                       the difference is worth keeping.
 *
 * There is deliberately NO column for the route, the order or the payload. The
 * ledger answers exactly one question - "has this key been claimed?" - and a
 * column that nothing reads is a column that the next schema change has to
 * carry.
 *
 * ---------------------------------------------------------------------------
 * WHY BOTH INSTANTS, AND WHAT A ROW WITH NO APPLICATION INSTANT MEANS
 * ---------------------------------------------------------------------------
 * The claim happens BEFORE the effect is applied - that order is the contract -
 * so between the two there is a window in which the receiving process can die:
 * a fatal error, a PHP timeout on a shared host, a killed request. When that
 * happens the row stays with `applied_at` at `NOT_YET_APPLIED`, and that is a
 * fact worth being able to read: it is the difference between "this delivery
 * was applied" and "this delivery was taken and nobody ever said it landed".
 *
 * The receiver releases the key with `InboundDeliveryLedger::forget()` when the
 * effect fails in a way it can observe, and confirms it with
 * `InboundDeliveryLedger::mark_applied()` when the effect lands. A row left
 * unconfirmed is therefore a crash, not a rejection, and it is NOT re-claimable
 * on purpose - see `InboundDeliveryLedger::claim()` for why preferring a
 * missing confirmation over a possible double effect is the safe side of that
 * trade.
 *
 * ---------------------------------------------------------------------------
 * THE TABLE NAME CARRIES THE INSTALLATION PREFIX AND IS NEVER HARD-CODED
 * ---------------------------------------------------------------------------
 * `Constants::INBOUND_TABLE` holds the bare name. The prefix comes from
 * `$wpdb->prefix` at call time, because a hard-coded `wp_` breaks every
 * installation that chose another prefix - which is most of the ones that were
 * ever migrated between hosts, and all of the multisite ones. It is the same
 * rule, for the same reason, as `Outbox\OutboxTable`.
 *
 * ---------------------------------------------------------------------------
 * ON UNINSTALL THIS TABLE IS DROPPED, UNLIKE THE OUTBOX ONE
 * ---------------------------------------------------------------------------
 * The outbox holds deliveries that happened and is the merchant's record of
 * what was invoiced. This one is internal deduplication bookkeeping and says
 * nothing to anybody, so it goes with the options. The routine that drops it
 * lives in `uninstall.php`, which is the one file that enumerates what leaving
 * means.
 */
final class InboundDeliveryTable {

	/**
	 * Version of the schema this file describes.
	 *
	 * It is recorded in an option after every successful install so that a
	 * future release can tell what shape the merchant's table currently has and
	 * run whatever data migration `dbDelta` cannot express. `dbDelta` adds
	 * columns and indexes; it does not backfill them.
	 *
	 * It is declared HERE and not in `Contract\Constants`, exactly as
	 * `Outbox\OutboxTable::SCHEMA_VERSION` is: the number describes the shape
	 * this file writes, so it belongs next to the description it versions.
	 * Putting it in the contract file would be the only schema version of the
	 * package living away from its table.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Option that records the installed schema version.
	 *
	 * It is `Constants::OPTION_INBOUND_SCHEMA_VERSION`, listed in
	 * `Constants::ALL_OPTIONS`, so the uninstall routine deletes it with the
	 * rest. The alias exists so that callers of this class do not need to know
	 * which contract constant backs it, and it is the same alias
	 * `Outbox\OutboxTable::SCHEMA_VERSION_OPTION` publishes.
	 */
	public const SCHEMA_VERSION_OPTION = Constants::OPTION_INBOUND_SCHEMA_VERSION;

	/**
	 * Value of `applied_at` for a delivery whose effect has not been confirmed
	 * applied.
	 *
	 * Zero and not NULL, for the same reason the outbox table keeps its
	 * timestamps zero-defaulted: every other value in the column is a Unix
	 * timestamp, and a column that is sometimes an integer and sometimes NULL
	 * makes every read have to handle two shapes for one fact. No delivery can
	 * ever be applied at the epoch, so zero cannot be mistaken for a real
	 * instant.
	 */
	public const NOT_YET_APPLIED = 0;

	/**
	 * Width of the key column, in characters, and therefore the longest key
	 * this ledger can store faithfully.
	 *
	 * It is declared here, next to the description it comes from, and read by
	 * `InboundDeliveryLedger::claim()`: the guard there and the column here have
	 * to be the same number or the guard is decoration. See `schema_sql()` for
	 * why the number is 191 and not more, and `InboundDeliveryLedger::claim()`
	 * for what a longer key would do if it were stored truncated.
	 */
	public const KEY_MAX_LENGTH = 191;

	/**
	 * Full name of the inbound delivery table in this installation, prefix
	 * included.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Constants::INBOUND_TABLE;
	}

	/**
	 * Creates the table, or brings an existing one up to this schema.
	 *
	 * Run on activation, and safe to run at any other moment: `dbDelta`
	 * compares the description below with what is actually there and issues
	 * only the differences.
	 *
	 * The installed version is NOT used to skip the work, on purpose. An option
	 * that says "installed" while the table is gone - a partially restored
	 * backup, a table dropped by hand, a migration between hosts that missed
	 * one table - would make every later claim write into nothing. And for THIS
	 * table the consequence is worse than for the outbox: a claim that cannot
	 * write cannot deduplicate, so every retry of the sender would be taken for
	 * a new delivery and applied again. `dbDelta` on an unchanged table is a
	 * handful of `DESCRIBE` queries once per activation, which is not a price
	 * worth that risk.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema_sql( self::table_name(), $wpdb->get_charset_collate() ) );

		/*
		 * Not autoloaded: it is read on activation and on upgrade checks, never
		 * on a front-end request, and every autoloaded option is paid for on
		 * every page view of the merchant's shop.
		 */
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Is the inbound delivery table actually there?
	 *
	 * Answers from the database rather than from the version option, for the
	 * reason written in `install()`: the option records an intention and the
	 * table is the fact.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::table_name();

		/*
		 * `SHOW TABLES LIKE` takes a pattern, so the name is escaped for LIKE
		 * before being bound. There is no WordPress API that answers this, and
		 * caching the answer would mean caching "the table exists" across the
		 * very event - a restore, a drop - this method is asked about.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; no core API answers this and the answer must not be cached.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * The table description, in the dialect `dbDelta` understands.
	 *
	 * `dbDelta` does not parse SQL: it compares this text, line by line,
	 * against what `DESCRIBE` returns, so its formatting rules are load-bearing
	 * and not style. One field or key per line, lower-case types, `KEY` rather
	 * than `INDEX`, and TWO spaces between `PRIMARY KEY` and its parenthesis.
	 * Get one of those wrong and `dbDelta` decides the column differs from
	 * itself and issues an `ALTER TABLE` on every single run.
	 *
	 * `varchar(191)` for the unique column, and not more: with `utf8mb4` an
	 * index is limited to 767 bytes on the MySQL versions still found under
	 * WordPress, which is 191 four-byte characters. The inbound contract caps
	 * the key at 64 characters, so the limit costs nothing and exceeding it
	 * would make the table fail to create on those servers - and the plugin
	 * would then have no ledger at all, which is the one failure that turns
	 * every retry into a second refund.
	 *
	 * The key column is NOT validated in shape anywhere in this package, and
	 * that is the contract talking: a receiver that demanded "sixty-four
	 * hexadecimal characters" would start rejecting legitimate deliveries the
	 * day the sender changed how it derives them, in every installation already
	 * published and with no cure but an update each merchant has to accept.
	 *
	 * The index on `received_at` is what the prune deletes by. Without it the
	 * monthly cleanup scans the whole table on a shop that receives a lot, in
	 * the same request that drains the outbox.
	 *
	 * @param string $table_name      Full table name, prefix included.
	 * @param string $charset_collate Character set and collation clause of this installation.
	 * @return string
	 */
	private static function schema_sql( string $table_name, string $charset_collate ): string {
		$not_yet_applied = self::NOT_YET_APPLIED;
		$key_max_length  = self::KEY_MAX_LENGTH;

		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	idempotency_key varchar({$key_max_length}) NOT NULL,
	received_at bigint(20) unsigned NOT NULL DEFAULT 0,
	applied_at bigint(20) unsigned NOT NULL DEFAULT {$not_yet_applied},
	PRIMARY KEY  (id),
	UNIQUE KEY idempotency_key (idempotency_key),
	KEY received_at (received_at)
) {$charset_collate};";
	}

	/**
	 * Not instantiable: this is a schema and a routine, not an object.
	 */
	private function __construct() {
	}
}
