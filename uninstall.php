<?php
/**
 * Uninstall routine of Factuarea for WooCommerce.
 *
 * WordPress includes this file when the merchant DELETES the plugin, and at no
 * other moment. Two properties of that moment shape everything below:
 *
 *  1. The plugin is NOT loaded. Its main file never ran, so no autoloader, no
 *     constant and no hook of this plugin exists yet. Anything this file needs,
 *     it loads itself.
 *  2. It is the last chance. After it returns, nothing of this plugin will ever
 *     run on this installation again, so an option left behind here is left
 *     behind for good - and two of the options hold credentials.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELETED
 * ---------------------------------------------------------------------------
 * Two things, and the second one only because of what it is NOT.
 *
 * FIRST, exactly the options enumerated in `Constants::ALL_OPTIONS`: the base
 * address, the API key, the store identifier, the signing secret, whether
 * delivery is switched on, whether the secret has already been revealed once,
 * the two switches that govern the inbound receivers, and the schema marker of
 * each of the two tables the plugin creates. Two of them are credentials, and
 * the requirement this file answers is theirs: after an uninstall the stored
 * credentials stop existing. The signing secret is ONE credential for both
 * directions - the outgoing deliveries are signed with it and the incoming ones
 * are verified against it - so deleting it leaves neither direction with
 * anything to trust, which is the point of deleting it.
 *
 * The switches of the receivers are options like any other and need no special
 * handling here: the list is what this file walks, and a phase that adds an
 * option adds it to the list. That is the whole reason the list exists.
 *
 * SECOND, the inbound delivery table. It is the one table this plugin creates
 * that is NOT the merchant's record, and the section below is about exactly
 * that distinction.
 *
 * They are deleted ONE BY ONE, from that list, and never by matching the
 * `factuarea_wc_` prefix with a database query. A prefix match deletes whatever
 * a future version of anything happens to name that way - including an option
 * that belongs to a different plugin whose author picked a colliding prefix -
 * and it deletes it without anybody having decided to. The list is the decision;
 * the prefix would be a guess that runs every time.
 *
 * Whoever adds an option to this plugin adds it to `Constants::ALL_OPTIONS` in
 * the same change. That is not a convention to remember: `tests/Unit/UninstallTest.php`
 * reads the source of `src/` looking for calls to the WordPress option API and
 * fails naming any option name that this list does not carry, which is how the
 * outbox schema version option was found the day the outbox table landed.
 *
 * The remedy is always in the frozen contract and never here. Reading a second
 * list out of `src/` would put the plugin's options in two places, which is the
 * divergence the single list exists to prevent; and this file runs with no
 * autoloader, so reaching into `src/` for a class constant is one missing file
 * away from a fatal error that aborts a deletion halfway through - after some
 * options are gone and before the credentials are.
 *
 * ---------------------------------------------------------------------------
 * THE PLUGIN CREATES TWO TABLES AND THIS FILE DROPS ONE OF THEM
 * ---------------------------------------------------------------------------
 * The difference between the two is not their size nor their age: it is whose
 * data they hold.
 *
 *  - The INBOUND DELIVERY table (`Constants::INBOUND_TABLE`) IS dropped. It
 *    holds the idempotency keys of the deliveries this installation already
 *    applied, and nothing else - no order, no amount, no payload. It is
 *    internal deduplication bookkeeping: it says nothing to the merchant,
 *    nobody can read a sale out of it, and the moment the plugin is gone
 *    nothing will ever consult it again. Left behind it would be a table
 *    carrying the plugin's name in a database that no longer runs the plugin;
 *    and if the merchant ever reinstalls, a table full of stale keys is worse
 *    than no table at all, because the first delivery of every sender would
 *    collide with a key from the previous installation and be discarded as a
 *    duplicate that nobody ever applied here.
 *
 *  - The OUTBOX table and its rows are NOT dropped. Delivered rows are the
 *    evidence of which sales reached Factuarea and when, which is the first
 *    thing anybody looks at when an invoice is missing. Pending rows are sales
 *    whose event has not been delivered yet; dropping the table would destroy
 *    them silently, and the merchant would never learn which sales were lost.
 *    Both are the merchant's data, held in the merchant's database.
 *
 * ---------------------------------------------------------------------------
 * WHAT ELSE IS DELIBERATELY NOT DELETED, AND WHY
 * ---------------------------------------------------------------------------
 * None of these is an oversight, and none is deferred work.
 *
 *  - The tax identifier written on the orders (`Constants::TAX_ID_META_KEY` and
 *    its mirror). It is part of the order, and the order is the merchant's
 *    accounting record. Spanish invoicing law obliges a merchant to keep the
 *    fiscal data of an issued sale for years, and an already issued invoice
 *    carries that identifier: deleting it here would leave the merchant holding
 *    documents they can no longer justify, because somebody removed a plugin.
 *    Uninstalling an integration is not a request to alter the books.
 *
 *  - Everything the INBOUND half wrote on an order: the fulfilment status and
 *    its tracking number, carrier, link and instant; the mark that says a refund
 *    was born of an inbound notice; the reference of the corrective invoice
 *    behind it and the scope of that refund. THE DECISION IS THAT THEY STAY,
 *    and it is the same decision, for the same reason, as the correlated invoice
 *    identifier the plugin already leaves on the order. They are order data. The
 *    merchant reads them in the order screen, the buyer has read them in their
 *    account, a refund that exists in WooCommerce was justified by them, and
 *    they are what says why an order was refunded at all. Deleting a plugin is a
 *    statement about the connection, never a licence to edit orders that have
 *    already been paid, shipped and accounted for - and unlike a credential,
 *    nothing bad happens if they outlive the integration. The routine below
 *    cannot even reach them: it calls no metadata API at all, and
 *    `tests/Unit/UninstallTest.php` reads the source of this file to make sure
 *    it never starts.
 *
 * The asymmetry is deliberate: credentials belong to the connection and the
 * connection is what is being removed, so they go; internal bookkeeping belongs
 * to the plugin and goes with it; business records belong to the merchant and
 * outlive any plugin, so they stay. A merchant who genuinely wants the outbox
 * gone can drop it; a merchant who loses their delivery history to an uninstall
 * has no way back.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT WRITTEN ANYWHERE
 * ---------------------------------------------------------------------------
 * This file produces no output, writes no log line and raises no notice. The
 * values it deletes include a signing secret and an API key, and the plugin's
 * standing prohibition is that neither ever reaches a message, a notice or a
 * log - not whole, not truncated. The safe way to honour that during a deletion
 * is to read none of them: the routine below deletes options by NAME and never
 * fetches a value, and the one statement it sends to the database names a table
 * and carries no value of any kind. Database errors are therefore left visible
 * rather than suppressed: there is nothing in that statement to leak, and a
 * drop that failed in silence would be the worse of the two outcomes.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

use Factuarea\WooCommerce\Contract\Constants;

/*
 * Context guard.
 *
 * WordPress defines WP_UNINSTALL_PLUGIN right before including this file, and
 * nothing else ever defines it. Without the constant, this file was reached by
 * some other route - a direct request to its URL, a stray include - and the
 * only correct thing to do is nothing at all.
 *
 * It returns rather than exits, and the difference is worth a sentence because
 * the two are NOT interchangeable here. WordPress reaches this file with an
 * `include`, so a `return` at file scope ends it exactly as an `exit` would,
 * with the same effect: no option is deleted and no code below runs. What the
 * `return` additionally allows is for the guard itself to be exercised - a test
 * can include this file with the constant absent and assert that the option API
 * was never called, which is the only way to know that the guard works. An
 * `exit` would end the test runner instead of proving anything, so the strictly
 * stronger of the two, in this file, is the one that can be verified.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

/*
 * The list of options lives in the plugin's frozen contract, and the plugin is
 * not loaded at this point. The published artefact carries no `vendor/`
 * directory - this plugin declares no runtime dependency on purpose, so that it
 * cannot collide with a library another plugin already loaded in the same
 * process - which means there is no composer autoloader to fall back on. The
 * file is required directly, guarded so that a context which DID already load
 * the class (the test suite, or a future main file that ran first) does not
 * redeclare it. `class_exists` is asked not to autoload, because triggering an
 * autoloader that may not exist is the failure this guard exists to avoid.
 */
if ( ! class_exists( Constants::class, false ) ) {
	require_once __DIR__ . '/src/Contract/Constants.php';
}

if ( ! function_exists( 'factuarea_wc_remove_site_state' ) ) {
	/**
	 * Removes what this plugin owns on whichever site is current: its options,
	 * and its inbound delivery table.
	 *
	 * It is ONE function and not two, and it is a function rather than inline
	 * code, for the same reason: a multisite network runs it once per site, and
	 * what has to be impossible is a future edit that walks the network deleting
	 * the options and leaves the table of every site but one behind. There is a
	 * single thing to call per site, so there is a single thing that can be
	 * forgotten - and forgetting it leaves nothing at all, which is loud.
	 *
	 * The options go FIRST. They are the part that must not survive - two of
	 * them are credentials - and putting them ahead of the statement that can
	 * fail means no database problem can end this routine with the API key and
	 * the signing secret still in the table.
	 *
	 * @return void
	 */
	function factuarea_wc_remove_site_state(): void {
		global $wpdb;

		foreach ( Constants::ALL_OPTIONS as $option_name ) {
			delete_option( $option_name );
		}

		/*
		 * The real table name is the bare name of the frozen contract with the
		 * prefix of THIS site in front of it, read at call time. Never a
		 * hard-coded `wp_`: most installations that were ever migrated between
		 * hosts chose another prefix, and on a network each site has its own -
		 * which is why this is read here, after the caller switched, and not
		 * once at the top of the file.
		 *
		 * The name is composed here rather than asked of
		 * `Inbound\InboundDeliveryTable::table_name()`, which is the class that
		 * creates the table and composes it the same way. The reason is the
		 * context this file runs in: there is no autoloader, so using that class
		 * means a second `require` of a second file, and a second file that can
		 * be missing from a packaged artefact is a second way for a deletion to
		 * die halfway through - after some options are gone and before the
		 * credentials are. One line of concatenation is not worth that, and the
		 * line is not left to trust: `tests/Unit/UninstallTest.php` runs this
		 * file against a non-default prefix and reads the statement it sends.
		 *
		 * `%i` is the identifier placeholder, so the name is quoted by WordPress
		 * instead of being interpolated by hand. `DROP TABLE` accepts no value
		 * placeholder - there is no value here to place - and `IF EXISTS` is
		 * what makes this safe to run on an installation whose table was never
		 * created, which is every installation that never activated the inbound
		 * half.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping a table this plugin created is the one thing no core API does, and an uninstall has nothing left to cache for.
		$wpdb->query(
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . Constants::INBOUND_TABLE )
		);
	}
}

/*
 * On a single site there is one set of options, one table, and one pass.
 *
 * On a multisite network there is one set PER SITE, because these are options
 * and not network options: each store in the network configures its own base
 * address, its own store identifier and its own credentials. WordPress runs an
 * uninstall routine ONCE, in the context of a single site, so deleting only the
 * current site's options would leave the API key and the signing secret of every
 * other store in the network sitting in the database after the merchant deleted
 * the plugin - which is precisely the outcome this file exists to prevent, just
 * harder to notice.
 *
 * The inbound delivery table is per site for the same reason, and it is the
 * reason the pass is a single call: each site has its own table because each
 * site has its own table prefix, and `$wpdb->prefix` only becomes that site's
 * prefix after `switch_to_blog`. A drop composed before the walk, or a walk
 * that only deleted options, would leave one table per store behind.
 *
 * Nothing here touches network options. The plugin stores none: every name in
 * `Constants::ALL_OPTIONS` is written with the per-site option API, so calling
 * the network option API would be deleting something that was never created.
 */
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$factuarea_wc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $factuarea_wc_site_ids as $factuarea_wc_site_id ) {
		switch_to_blog( (int) $factuarea_wc_site_id );
		factuarea_wc_remove_site_state();
		restore_current_blog();
	}

	unset( $factuarea_wc_site_ids, $factuarea_wc_site_id );
} else {
	factuarea_wc_remove_site_state();
}
