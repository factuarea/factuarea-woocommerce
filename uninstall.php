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
 * Exactly the options enumerated in `Constants::ALL_OPTIONS`: the base address,
 * the API key, the store identifier, the signing secret, whether delivery is
 * switched on, and whether the secret has already been revealed once. Two of
 * them are credentials, and the requirement this file answers is theirs: after
 * an uninstall the stored credentials stop existing.
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
 * WHAT IS DELIBERATELY NOT DELETED, AND WHY
 * ---------------------------------------------------------------------------
 * Neither of the two is an oversight, and neither is deferred work.
 *
 *  - The tax identifier written on the orders (`Constants::TAX_ID_META_KEY` and
 *    its mirror). It is part of the order, and the order is the merchant's
 *    accounting record. Spanish invoicing law obliges a merchant to keep the
 *    fiscal data of an issued sale for years, and an already issued invoice
 *    carries that identifier: deleting it here would leave the merchant holding
 *    documents they can no longer justify, because somebody removed a plugin.
 *    Uninstalling an integration is not a request to alter the books.
 *
 *  - The outbox table and its rows. Delivered rows are the evidence of which
 *    sales reached Factuarea and when, which is the first thing anybody looks at
 *    when an invoice is missing. Pending rows are sales whose event has not been
 *    delivered yet; dropping the table would destroy them silently, and the
 *    merchant would never learn which sales were lost. Both are the merchant's
 *    data, held in the merchant's database.
 *
 * The asymmetry is deliberate: credentials belong to the connection and the
 * connection is what is being removed, so they go. Business records belong to
 * the merchant and outlive any plugin, so they stay. A merchant who genuinely
 * wants the table gone can drop it; a merchant who loses their delivery history
 * to an uninstall has no way back.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT WRITTEN ANYWHERE
 * ---------------------------------------------------------------------------
 * This file produces no output, writes no log line and raises no notice. The
 * values it deletes include a signing secret and an API key, and the plugin's
 * standing prohibition is that neither ever reaches a message, a notice or a
 * log - not whole, not truncated. The safe way to honour that during a deletion
 * is to read none of them: the loop below deletes options by NAME and never
 * fetches a value.
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

if ( ! function_exists( 'factuarea_wc_delete_stored_options' ) ) {
	/**
	 * Deletes every option this plugin stores, on whichever site is current.
	 *
	 * It is a function and not an inline loop because a multisite network runs
	 * it once per site, and the alternative is the same loop written twice.
	 *
	 * @return void
	 */
	function factuarea_wc_delete_stored_options(): void {
		foreach ( Constants::ALL_OPTIONS as $option_name ) {
			delete_option( $option_name );
		}
	}
}

/*
 * On a single site there is one set of options and one deletion.
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
		factuarea_wc_delete_stored_options();
		restore_current_blog();
	}

	unset( $factuarea_wc_site_ids, $factuarea_wc_site_id );
} else {
	factuarea_wc_delete_stored_options();
}
