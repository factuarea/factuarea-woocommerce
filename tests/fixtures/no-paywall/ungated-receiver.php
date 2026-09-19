<?php
/**
 * SELF-CHECK FIXTURE - the gate MUST pass on this file.
 *
 * It is the same receiver as `gated-receiver.php` with nothing withheld: the
 * only condition in front of it is the merchant's own toggle, which is off
 * until the merchant turns it on and costs nobody anything. That distinction is
 * the whole point of this pair. A gate that reported every condition would
 * report the two toggles this change introduces, and a gate red on correct code
 * is a gate somebody switches off.
 *
 * It is also written with the words a looser vocabulary would trip over, IN
 * CODE and not only in the documentation, because the scanner drops comments
 * before it matches: a near miss hidden in a docblock proves nothing.
 *
 * The near misses it carries ON PURPOSE:
 *
 *   - `is_paid()`, which WooCommerce itself defines on an order;
 *   - the word `subscription` on its own, which this package already writes
 *     about a merchant who installs a subscriptions plugin;
 *   - the word `plan` on its own, and `planner`, which name the retry schedule;
 *   - `processing`, which carries `pro` inside it;
 *   - `unlocked`, which is what the toggle honestly is.
 *
 * If somebody widens a pattern until one of those fires, this file turns red
 * and says so before the whole repository does.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Fixtures\NoPaywall\UngatedReceiver;

/**
 * Registers the order status receiver. Every installation gets it, because the
 * commercial boundary of this product is the service on the other end and never
 * the plugin: there is no tier to check and nothing to buy.
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
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Whether the merchant has switched the receiver on.
 *
 * This is the ONLY condition in front of it. It is a setting of the shop, it
 * starts switched off so that an installation never accepts a delivery the
 * merchant did not ask for, and the merchant who switches it on has unlocked it
 * by clicking a checkbox.
 *
 * @return bool True when the merchant has switched it on.
 */
function receiver_is_enabled(): bool {
	return 'yes' === get_option( 'factuarea_wc_order_status_receiver_enabled', 'no' );
}

/**
 * Answers a delivery.
 *
 * @param \WP_REST_Request $request Inbound delivery.
 * @return \WP_REST_Response
 */
function handle( \WP_REST_Request $request ): \WP_REST_Response {
	if ( ! receiver_is_enabled() ) {
		return new \WP_REST_Response(
			array(
				'message' => 'This receiver is switched off in the shop settings. Switch it on under the Factuarea settings to accept deliveries.',
				'reason'  => 'receiver_disabled',
			),
			403
		);
	}

	return apply_inbound_notice( $request );
}

/**
 * When to try again after a delivery that did not get through.
 *
 * @param int $attempt Attempts already failed, counting from one.
 * @return int|null The wait in seconds, or null when there is none left.
 */
function next_wait( int $attempt ): ?int {
	$planner_table = array( 60, 300, 1800, 7200, 43200, 86400, 259200 );

	return $planner_table[ $attempt - 1 ] ?? null;
}

/**
 * Whether the order is one the receivers have anything to say about.
 *
 * @param \WC_Order $order Order as WooCommerce hands it over.
 * @return bool True when it is.
 */
function is_processing_and_paid( \WC_Order $order ): bool {
	$note = 'A paid order whose buyer runs a subscription or pre-order plugin still reaches the plan of the retry planner.';

	unset( $note );

	return $order->is_paid() && 'processing' === $order->get_status();
}
