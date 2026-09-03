<?php
/**
 * Plugin Name: Factuarea E2E support
 * Description: The window the end-to-end scenarios look at the site through - outbox rows, order meta, the drain, and the passing of time. Never published: it lives under tests/ and .distignore excludes the whole directory.
 * Version: 0.1.0
 * License: GPL-2.0-or-later
 *
 * WHY THIS IS A SEPARATE FILE FROM THE MOCK RECEIVER
 *
 * The receiver models something OUTSIDE the shop: it may only know what arrived
 * over HTTP. This file is the opposite - it reaches inside the shop and reads
 * the plugin's own tables. Keeping the two apart is what stops a scenario from
 * quietly asserting that the receiver saw something by reading it out of the
 * database instead of out of the delivery.
 *
 * WHY TIME IS TRAVELLED RATHER THAN WAITED
 *
 * The first retry of a failed delivery is planned for sixty seconds later, the
 * second for five minutes, the third for half an hour. A scenario that proved
 * five consecutive failures by waiting would take thirty-six minutes and would
 * still be proving nothing that this does not. `outbox/rewind` moves the due
 * moment of the PENDING entries to the past and touches nothing else: it does
 * not change a status, an attempt count or a message, so every assertion the
 * scenario then makes is about what the plugin decided, not about what the
 * harness set up.
 */

declare( strict_types = 1 );

if ( ! defined( 'FACTUAREA_E2E' ) || ! FACTUAREA_E2E ) {
	return;
}

/**
 * Fully qualified name of the outbox table on this site.
 *
 * Read through the plugin's own class when it is loaded, so that a rename there
 * is followed here; composed the same way it composes it when the plugin is not
 * loaded, so that a support route answers "the plugin is not active" instead of
 * a fatal error.
 *
 * @return string
 */
function factuarea_e2e_outbox_table(): string {
	global $wpdb;

	if ( class_exists( '\\Factuarea\\WooCommerce\\Outbox\\OutboxTable' ) ) {
		return \Factuarea\WooCommerce\Outbox\OutboxTable::table_name();
	}

	return $wpdb->prefix . 'factuarea_outbox';
}

/**
 * Every outbox row, newest last, in the order the scenarios read them.
 *
 * @param bool $with_body Whether to include the delivered body, which is large.
 * @return list<array<string, mixed>>
 */
function factuarea_e2e_outbox_rows( bool $with_body ): array {
	global $wpdb;

	$columns = 'id, event_id, order_id, event_type, source, attempts, next_attempt_at, status, last_status_code, last_message, claimed_token, claimed_at, created_at, updated_at';

	if ( $with_body ) {
		$columns .= ', raw_body';
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness read of a plugin-owned table; the column list is a literal built above and the table name comes from the plugin.
	$rows = $wpdb->get_results( 'SELECT ' . $columns . ' FROM ' . factuarea_e2e_outbox_table() . ' ORDER BY id ASC', ARRAY_A );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	return array_map(
		static function ( array $row ): array {
			$row['id']               = (int) $row['id'];
			$row['order_id']         = (int) $row['order_id'];
			$row['attempts']         = (int) $row['attempts'];
			$row['next_attempt_at']  = (int) $row['next_attempt_at'];
			$row['last_status_code'] = null === $row['last_status_code'] ? null : (int) $row['last_status_code'];

			return $row;
		},
		$rows
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		$guarded = array(
			'permission_callback' => 'factuarea_mock_authorise',
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/outbox',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function ( WP_REST_Request $request ) {
						return array(
							'now'  => time(),
							'rows' => factuarea_e2e_outbox_rows( '1' === (string) $request->get_param( 'with_body' ) ),
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/outbox/rewind',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function () {
						global $wpdb;

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness write against a plugin-owned table; it moves the due moment of pending entries into the past and changes nothing else.
						$moved = $wpdb->query( "UPDATE " . factuarea_e2e_outbox_table() . " SET next_attempt_at = 0 WHERE status = 'pending'" );

						return array( 'moved' => is_int( $moved ) ? $moved : 0 );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/outbox/purge',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function () {
						global $wpdb;

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness reset of a plugin-owned table between scenarios.
						$wpdb->query( 'DELETE FROM ' . factuarea_e2e_outbox_table() );

						return array( 'ok' => true );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/drain',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function () {
						if ( ! class_exists( '\\Factuarea\\WooCommerce\\Plugin' ) ) {
							return new WP_Error(
								'factuarea_e2e_plugin_missing',
								'The plugin under test is not loaded on this site, so there is nothing to drain.',
								array( 'status' => 500 )
							);
						}

						/*
						 * The same action the scheduler fires, rather than the
						 * dispatcher directly. A scenario that called the
						 * dispatcher would still pass if the hook were never
						 * wired, which is one of the two ways this plugin can
						 * fall silent.
						 */
						do_action( \Factuarea\WooCommerce\Plugin::DRAIN_HOOK );

						return array(
							'hook' => \Factuarea\WooCommerce\Plugin::DRAIN_HOOK,
							'rows' => factuarea_e2e_outbox_rows( false ),
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/scheduler/enqueue-drain',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function () {
						if ( ! function_exists( 'as_schedule_single_action' ) || ! class_exists( '\\Factuarea\\WooCommerce\\Plugin' ) ) {
							return new WP_Error(
								'factuarea_e2e_scheduler_missing',
								'Action Scheduler or the plugin under test is not available on this site.',
								array( 'status' => 500 )
							);
						}

						/*
						 * Puts ONE due action on the queue for the plugin's own
						 * drain hook, in the plugin's own group.
						 *
						 * WHY A SCENARIO NEEDS THIS. The plugin schedules its
						 * drain as a RECURRING action every sixty seconds, so
						 * whether one is due when a scenario asks the scheduler
						 * to run depends on how long the scenarios before it
						 * took. MEASURED: the delivery scenario passed on a fast
						 * run and found an empty receiver on a slow one, for no
						 * reason connected to the plugin.
						 *
						 * What it does NOT do is bypass Action Scheduler. The
						 * action is dispatched by the scheduler, to the hook the
						 * plugin published, in the group the plugin owns - so a
						 * plugin that never wired that hook still fails. The
						 * separate claim, that the RECURRING action exists at
						 * all, is asserted from `/settings` instead.
						 */
						return array(
							'action_id' => as_schedule_single_action(
								time() - 1,
								\Factuarea\WooCommerce\Plugin::DRAIN_HOOK,
								array(),
								\Factuarea\WooCommerce\Plugin::SCHEDULER_GROUP
							),
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/order/(?P<id>\d+)',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function ( WP_REST_Request $request ) {
						$order = wc_get_order( (int) $request->get_param( 'id' ) );

						if ( ! $order instanceof WC_Order ) {
							return new WP_Error(
								'factuarea_e2e_order_missing',
								'No such order on this site.',
								array( 'status' => 404 )
							);
						}

						$interesting = array();

						foreach ( $order->get_meta_data() as $meta ) {
							$key = (string) $meta->key;

							if ( 0 === strpos( $key, '_factuarea_' ) || 0 === strpos( $key, '_wc_billing/factuarea' ) || 'billing_nif' === $key ) {
								$interesting[ $key ] = $meta->value;
							}
						}

						return array(
							'id'     => $order->get_id(),
							'status' => $order->get_status(),
							'total'  => $order->get_total(),
							'tax_id' => class_exists( '\\Factuarea\\WooCommerce\\Checkout\\TaxIdOrderStorage' )
								? ( new \Factuarea\WooCommerce\Checkout\TaxIdOrderStorage() )->read( $order )
								: '',
							'meta'   => $interesting,
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/settings',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function () {
						/*
						 * The two credentials are reported as present or absent
						 * and never as values. The plugin has a gate forbidding
						 * itself from printing them; a harness that printed them
						 * would be the hole that gate exists to close.
						 */
						return array(
							'delivery_enabled'   => (string) get_option( 'factuarea_wc_delivery_enabled', '' ),
							'base_url'           => (string) get_option( 'factuarea_wc_base_url', '' ),
							'api_base_url'       => (string) get_option( 'factuarea_wc_api_base_url', '' ),
							'store_id'           => (string) get_option( 'factuarea_wc_store_id', '' ),
							'has_api_key'        => '' !== (string) get_option( 'factuarea_wc_api_key', '' ),
							'has_signing_secret' => '' !== (string) get_option( 'factuarea_wc_signing_secret', '' ),
							'drain_scheduled_at' => function_exists( 'as_next_scheduled_action' ) && class_exists( '\\Factuarea\\WooCommerce\\Plugin' )
								? as_next_scheduled_action( \Factuarea\WooCommerce\Plugin::DRAIN_HOOK, null, \Factuarea\WooCommerce\Plugin::SCHEDULER_GROUP )
								: null,
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/fixtures',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function () {
						return array(
							'product_id'          => (int) get_option( 'factuarea_e2e_product_id', 0 ),
							'block_checkout_url'  => (string) get_option( 'factuarea_e2e_block_checkout_url', '' ),
							'classic_checkout_url'=> (string) get_option( 'factuarea_e2e_classic_checkout_url', '' ),
							'woocommerce_version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
							'wordpress_version'   => (string) get_bloginfo( 'version' ),
							'php_version'         => PHP_VERSION,
							'home_url'            => home_url(),
						);
					},
				)
			)
		);
	}
);
