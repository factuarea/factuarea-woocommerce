<?php
/**
 * SELF-CHECK FIXTURE - the gate MUST fail on this file.
 *
 * This is not plugin code and it is never loaded. It exists so that
 * `NoPaywallGateTest` can prove the no-paywall gate still detects something,
 * rather than proving that it runs. A gate whose only evidence is a green run
 * over a clean repository is indistinguishable from a gate that looks at the
 * wrong directory, and this package cannot afford that distinction to be
 * invisible: a paywall reaching the plugin directory means removal, and getting
 * back in means waiting for every merchant to update.
 *
 * It carries one offence from each family of the vocabulary, so that a family
 * accidentally deleted from the gate turns this file green in ONE of its
 * assertions rather than in all of them.
 *
 * It lives under `tests/`, which the gate does not scan and `.distignore` keeps
 * out of the artefact, so its presence cannot make the repository run red.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Fixtures\NoPaywall\Gated;

/**
 * Delivers a paid order, but only for whoever paid for the plugin as well.
 *
 * @param int $order_id Order to deliver.
 * @return bool Whether the delivery was attempted.
 */
function deliver_order( int $order_id ): bool {
	$license_key = get_option( 'factuarea_wc_license_key' );

	if ( '' === $license_key ) {
		add_admin_notice( 'Upgrade to Pro to deliver more than ten orders a month.' );

		return false;
	}

	if ( ! is_premium_active() ) {
		return false;
	}

	return enqueue_delivery( $order_id );
}

/**
 * Whether the paid tier is active on this installation.
 *
 * @return bool True when it is.
 */
function is_premium_active(): bool {
	return 'active' === get_option( 'factuarea_wc_license_status' );
}

/**
 * Whether the merchant plan allows the automatic refund events.
 *
 * @return bool True when it does.
 */
function refunds_allowed(): bool {
	return true === get_option( 'factuarea_wc_plan_required' );
}
