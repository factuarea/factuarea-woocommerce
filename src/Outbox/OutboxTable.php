<?php
/**
 * Definition of the outbox table inside the merchant's WordPress database.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Outbox;

use Factuarea\WooCommerce\Contract\Constants;

/**
 * The one table this plugin creates, and the routine that creates or updates it
 * on activation.
 *
 * ---------------------------------------------------------------------------
 * WHY A TABLE OF OUR OWN AND NOT A POST TYPE, AN OPTION OR A TRANSIENT
 * ---------------------------------------------------------------------------
 * The outbox is a queue with a unique key, an ordered due date and concurrent
 * readers. WordPress offers three tempting places to keep it and all three are
 * wrong for it:
 *
 *  - An option is one row holding a serialised array. Two requests that enqueue
 *    at the same moment read it, both append, both write, and one of the two
 *    orders vanishes with no error anywhere. There is no unique key to lean on.
 *  - A transient can be evicted. A queue whose entries the object cache is free
 *    to drop is not a queue.
 *  - A custom post type gets a unique key only through a meta lookup, orders by
 *    a joined meta table, and puts sales into the same place the merchant's
 *    content lives.
 *
 * A table gives the two things this design rests on: a UNIQUE constraint that
 * collapses a double capture in the database instead of in PHP, and an index on
 * (status, next_attempt_at) that the drain reads without scanning.
 *
 * ---------------------------------------------------------------------------
 * THE COLUMNS, AND WHAT EACH ONE IS FOR
 * ---------------------------------------------------------------------------
 *  - `event_id`      The identity composed by `EventIdentity` from the domain
 *                    object. It carries the UNIQUE constraint, and that
 *                    constraint IS the idempotency of this plugin: see the note
 *                    below on why this column and not a digest.
 *  - `order_id`      The WooCommerce order the entry belongs to, so that the
 *                    merchant can be shown what a failure was about.
 *  - `event_type`    Which fact it is, in the receiver's vocabulary, so the
 *                    dispatcher composes the delivery headers without parsing
 *                    the identity back apart.
 *  - `raw_body`      The exact bytes to send, built and stored at capture time.
 *                    Storing the body rather than rebuilding it at drain time
 *                    is deliberate: an order edited after the sale would
 *                    otherwise be delivered as it looks LATER, and the invoice
 *                    would not describe the sale that happened.
 *  - `source`        The store address the entry was captured for. The
 *                    dispatcher refuses to deliver an entry whose source is not
 *                    the configured one; without the column there would be
 *                    nothing to compare.
 *  - `attempts`      How many delivery attempts have already failed. It is the
 *                    input of the retry planner, so it is stored and not
 *                    inferred.
 *  - `next_attempt_at` Unix timestamp of the earliest moment the entry may be
 *                    sent. Half of the drain index.
 *  - `status`        The vocabulary of `OutboxRepository`. Other half of the
 *                    drain index.
 *  - `last_status_code`, `last_message`  What came back last time, kept for the
 *                    merchant, who reads it long after the response is gone.
 *  - `claimed_token`, `claimed_at`  How two simultaneous drains avoid handing
 *                    the same entry to both. See `OutboxRepository::claim_due`.
 *  - `created_at`, `updated_at`  Unix timestamps, for diagnosis.
 *
 * ---------------------------------------------------------------------------
 * WHY THE UNIQUE KEY IS THE EVENT IDENTITY AND NOT THE RECEIVER'S DIGEST
 * ---------------------------------------------------------------------------
 * The receiver derives its idempotency key as
 * `sha256( "{provider}:{externalStoreId}:{eventId}" )`. Two of those three
 * parts - the provider and this store's identifier at the receiver - are
 * constant for one installation of this plugin, so within this database
 * uniqueness on `event_id` and uniqueness on that digest are the SAME
 * constraint. Storing the digest instead would buy nothing and cost something
 * real: the digest needs the configured store identifier, which means the
 * enqueue path would have to read a setting, and an entry captured before the
 * merchant filled that setting in would get a different key than the same event
 * captured after - so the double capture this constraint exists to collapse
 * would produce two rows and two invoices for one sale.
 *
 * The digest is still computed, by `EventIdentity::idempotency_key`, and stored
 * against the ORDER under `Constants::IDEMPOTENCY_KEY_META_KEY` so that the
 * correlation callback can find the order later. That is a different job in a
 * different place.
 *
 * ---------------------------------------------------------------------------
 * THE TABLE NAME CARRIES THE INSTALLATION PREFIX AND IS NEVER HARD-CODED
 * ---------------------------------------------------------------------------
 * `Constants::OUTBOX_TABLE` holds the bare name. The prefix comes from
 * `$wpdb->prefix` at call time, because a hard-coded `wp_` breaks every
 * installation that chose another prefix - which is most of the ones that were
 * ever migrated between hosts, and all of the multisite ones.
 */
final class OutboxTable {

	/**
	 * Version of the schema this file describes.
	 *
	 * It is recorded in an option after every successful install so that a
	 * future release can tell what shape the merchant's table currently has and
	 * run whatever data migration `dbDelta` cannot express. `dbDelta` adds
	 * columns and indexes; it does not backfill them.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Option that records the installed schema version.
	 *
	 * It is `Constants::OPTION_OUTBOX_SCHEMA_VERSION`, listed in
	 * `Constants::ALL_OPTIONS`, so the uninstall routine deletes it with the rest.
	 * The outbox table itself is NOT dropped on uninstall (delivered rows are the
	 * merchant's record of what was invoiced), and that is fine: `install()` is
	 * idempotent and never short-circuits on this marker, so a later activation
	 * rewrites it against whatever table it finds. The alias exists so callers of
	 * this class do not need to know which contract constant backs it.
	 */
	public const SCHEMA_VERSION_OPTION = Constants::OPTION_OUTBOX_SCHEMA_VERSION;

	/**
	 * Full name of the outbox table in this installation, prefix included.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Constants::OUTBOX_TABLE;
	}

	/**
	 * Creates the table, or brings an existing one up to this schema.
	 *
	 * Run on activation, and safe to run at any other moment: `dbDelta` compares
	 * the description below with what is actually there and issues only the
	 * differences.
	 *
	 * The installed version is NOT used to skip the work, on purpose. An option
	 * that says "installed" while the table is gone - a partially restored
	 * backup, a table dropped by hand, a migration between hosts that missed one
	 * table - would make every later enqueue write into nothing, and the plugin
	 * would go quiet in exactly the way this whole package exists to prevent.
	 * `dbDelta` on an unchanged table is a handful of `DESCRIBE` queries once per
	 * activation, which is not a price worth that risk.
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
	 * Is the outbox table actually there?
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
	 * `dbDelta` does not parse SQL: it compares this text, line by line, against
	 * what `DESCRIBE` returns, so its formatting rules are load-bearing and not
	 * style. One field or key per line, lower-case types, `KEY` rather than
	 * `INDEX`, and TWO spaces between `PRIMARY KEY` and its parenthesis. Get one
	 * of those wrong and `dbDelta` decides the column differs from itself and
	 * issues an `ALTER TABLE` on every single run.
	 *
	 * `varchar(191)` for the unique column, and not more: with `utf8mb4` an index
	 * is limited to 767 bytes on the MySQL versions still found under WordPress,
	 * which is 191 four-byte characters. An event identity is around thirty
	 * characters, so the limit costs nothing and exceeding it would make the
	 * table fail to create on those servers - and the plugin would then have no
	 * outbox at all.
	 *
	 * @param string $table_name      Full table name, prefix included.
	 * @param string $charset_collate Character set and collation clause of this installation.
	 * @return string
	 */
	private static function schema_sql( string $table_name, string $charset_collate ): string {
		$pending = OutboxRepository::STATUS_PENDING;

		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id varchar(191) NOT NULL,
	order_id bigint(20) unsigned NOT NULL,
	event_type varchar(64) NOT NULL,
	raw_body longtext NOT NULL,
	source varchar(255) NOT NULL,
	attempts smallint(5) unsigned NOT NULL DEFAULT 0,
	next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(32) NOT NULL DEFAULT '{$pending}',
	last_status_code smallint(5) unsigned DEFAULT NULL,
	last_message text NOT NULL,
	claimed_token varchar(32) NOT NULL DEFAULT '',
	claimed_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY event_id (event_id),
	KEY status_next_attempt_at (status,next_attempt_at),
	KEY claimed_token (claimed_token)
) {$charset_collate};";
	}

	/**
	 * Not instantiable: this is a schema and a routine, not an object.
	 */
	private function __construct() {
	}
}
