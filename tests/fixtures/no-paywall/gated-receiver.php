<?php
/**
 * SELF-CHECK FIXTURE - the gate MUST fail on this file.
 *
 * This is not plugin code and it is never loaded. It is the companion of
 * `gated-feature.php` for the shape the inbound half introduces: a RECEIVER
 * that answers only for whoever has paid for something.
 *
 * It exists because the two receiver toggles are the first conditions this
 * plugin ever put in front of a feature, and a condition is exactly what a
 * paywall looks like from a distance. The toggles themselves are legitimate -
 * they are a merchant setting, disabled by default, and the merchant who turns
 * them on pays nobody for the privilege. The offences below are what the same
 * code looks like when the condition stops being the merchant's and becomes a
 * seller's, and every one of them is written the way somebody would actually
 * write it rather than the way the vocabulary spells it.
 *
 * One offence per family, so that a family accidentally deleted from the gate
 * turns this file green in ONE of its assertions rather than in all of them.
 *
 * It lives under `tests/`, which the gate does not scan and `.distignore` keeps
 * out of the artefact, so its presence cannot make the repository run red.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Fixtures\NoPaywall\GatedReceiver;

/**
 * Registers the order status receiver, for whoever is entitled to it.
 *
 * @return void
 */
function register_receiver(): void {
	register_rest_route(
		'factuarea/v1',
		'/order-status',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle',
			'permission_callback' => __NAMESPACE__ . '\\receiver_is_unlocked',
		)
	);
}

/**
 * Whether this installation may run the receiver at all.
 *
 * @return bool True when it may.
 */
function receiver_is_unlocked(): bool {
	if ( 'active' !== get_option( 'factuarea_wc_subscription_status' ) ) {
		return false;
	}

	return true === get_option( 'factuarea_wc_entitlement_inbound' );
}

/**
 * Answers a delivery, or explains what has to be bought first.
 *
 * @param \WP_REST_Request $request Inbound delivery.
 * @return \WP_REST_Response|\WP_Error
 */
function handle( \WP_REST_Request $request ) {
	if ( ! plan_allows_inbound() ) {
		return new \WP_Error(
			'factuarea_payment_required',
			'Receiving order status notices requires a paid plan. Upgrade your plan to switch this receiver on.',
			array( 'status' => 402 )
		);
	}

	if ( '' === get_option( 'factuarea_wc_license_key' ) ) {
		return new \WP_Error( 'factuarea_unlicensed', 'Purchase a licence to enable the catalogue receiver.', array( 'status' => 402 ) );
	}

	return apply_inbound_notice( $request );
}

/**
 * Whether the tier bought for this shop covers the inbound half.
 *
 * @return bool True when it does.
 */
function plan_allows_inbound(): bool {
	return 'paid_tier' === get_option( 'factuarea_wc_plan_level' ) && is_premium_active();
}

/**
 * Whether the paid tier is active on this installation.
 *
 * @return bool True when it is.
 */
function is_premium_active(): bool {
	return 'active' === get_option( 'factuarea_wc_license_status' );
}
