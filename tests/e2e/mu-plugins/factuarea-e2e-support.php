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

						return factuarea_e2e_order_view( $order );
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

/* -------------------------------------------------------------------------
 * THE INBOUND HALF: THE LEDGER, THE ORDERS AND THE ARTICLES IT WRITES ON
 * -------------------------------------------------------------------------
 *
 * The same window, widened to the half of the plugin that RECEIVES. Three
 * claims of that half are only observable from inside the shop and never from
 * the answer a receiver gives:
 *
 *   - "no delivery was recorded" (the idempotency ledger has no row),
 *   - "the native order status did not change" and "the money came back",
 *   - "the article, its price and its stock are what the batch declared".
 *
 * The routes below read exactly those and write nothing of the plugin's own.
 * What they DO write - an order, a customer, an article, a merchant's refund -
 * is the SHOP, which is the scenery a scenario needs and never the thing under
 * test.
 */

/**
 * Fully qualified name of the inbound ledger table on this site.
 *
 * Read through the plugin's own class when it is loaded, and composed the same
 * way when it is not, exactly as the outbox one above.
 *
 * @return string
 */
function factuarea_e2e_inbound_table(): string {
	global $wpdb;

	if ( class_exists( '\\Factuarea\\WooCommerce\\Inbound\\InboundDeliveryTable' ) ) {
		return \Factuarea\WooCommerce\Inbound\InboundDeliveryTable::table_name();
	}

	return $wpdb->prefix . 'factuarea_inbound';
}

/**
 * The login the buyer scenarios sign in as.
 *
 * A FIXED credential on a throwaway container, for the same reason the signing
 * secret of the seed is fixed: a failing run has to be reproducible, and a
 * generated password would have to be printed somewhere to be usable, which is
 * the one thing the harness will not do with credentials that matter.
 */
const FACTUAREA_E2E_BUYER_LOGIN = 'factuarea-e2e-buyer';

/** Password of that login. */
const FACTUAREA_E2E_BUYER_PASSWORD = 'factuarea-e2e-buyer-password';

/** Electronic address of that login. */
const FACTUAREA_E2E_BUYER_EMAIL = 'buyer@example.test';

/**
 * The buyer account, created the first time it is asked for.
 *
 * @return int User identifier.
 *
 * @throws RuntimeException When WordPress refuses to create it.
 */
function factuarea_e2e_buyer_id(): int {
	$existing = get_user_by( 'login', FACTUAREA_E2E_BUYER_LOGIN );

	if ( $existing instanceof WP_User ) {
		return (int) $existing->ID;
	}

	$created = wp_insert_user(
		array(
			'user_login' => FACTUAREA_E2E_BUYER_LOGIN,
			'user_pass'  => FACTUAREA_E2E_BUYER_PASSWORD,
			'user_email' => FACTUAREA_E2E_BUYER_EMAIL,
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'role'       => 'customer',
		)
	);

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'The buyer account could not be created: ' . $created->get_error_message() );
	}

	return (int) $created;
}

/**
 * Clears the way for an order to be created under a CHOSEN identifier.
 *
 * WHY A SCENARIO CHOOSES THE IDENTIFIER AT ALL. The bodies the receivers are
 * exercised with are the frozen fixtures of `tests/fixtures/inbound/`, and every
 * one of them names its order. Rewriting that number in the scenario would mean
 * re-signing the body, and re-signing means the bytes under test are no longer
 * the bytes the contract froze - the one thing a signature scenario must not
 * give up. So the SHOP is bent to the fixture instead of the fixture to the
 * shop: the order is created with the identifier the fixture names.
 *
 * Everything at or above that identifier is removed first, because MySQL will
 * raise an auto-increment counter and never lower it below the largest row that
 * exists. A second run of the suite would otherwise find the counter sitting
 * above the number - refunds of the previous run consume identifiers too - and
 * would quietly create the order somewhere else, which the fixture would then
 * fail to find.
 *
 * MEASURED, AND THE REASON THE SWEEP IS WIDER THAN "ORDERS": under the post
 * storage an order, a refund and an ARTICLE all draw from the same counter, so
 * the articles the catalogue scenarios create sit above the number too and hold
 * it up on their own. The sweep therefore covers the four post types this
 * harness creates and nothing else - never a page, a product category or
 * anything WordPress or WooCommerce put there - and the caller still refuses to
 * carry on if the order did not come out under the number the fixture names.
 * Under the modern order storage the order counter is a table of its own, so
 * only that table is swept.
 *
 * @param int $desired Identifier the order has to be created under.
 * @return void
 */
function factuarea_e2e_clear_order_identifier( int $desired ): void {
	global $wpdb;

	$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	if ( $hpos ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness sweep of orders of previous runs; the identifier is cast to an integer above.
		$identifiers = $wpdb->get_col( 'SELECT id FROM ' . $wpdb->prefix . 'wc_orders WHERE id >= ' . $desired );

		foreach ( (array) $identifiers as $identifier ) {
			$order = wc_get_order( (int) $identifier );

			if ( $order ) {
				$order->delete( true );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness reset of an auto-increment counter; the value is an integer.
		$wpdb->query( 'ALTER TABLE ' . $wpdb->prefix . 'wc_orders AUTO_INCREMENT = ' . $desired );

		return;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same sweep under the post storage; the post types are literals of this harness.
	$identifiers = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE ID >= " . $desired
		. " AND post_type IN ( 'shop_order', 'shop_order_refund', 'product', 'product_variation' )"
	);

	foreach ( (array) $identifiers as $identifier ) {
		$identifier = (int) $identifier;
		$order      = wc_get_order( $identifier );

		if ( $order ) {
			$order->delete( true );

			continue;
		}

		$product = wc_get_product( $identifier );

		if ( $product instanceof WC_Product ) {
			$product->delete( true );

			continue;
		}

		wp_delete_post( $identifier, true );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness reset of an auto-increment counter under the post storage; the value is an integer.
	$wpdb->query( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = " . $desired );
}

/**
 * What the plugin and WooCommerce hold about one order, in the shape the
 * scenarios read.
 *
 * @param WC_Order $order The order.
 * @return array<string, mixed>
 */
function factuarea_e2e_order_view( WC_Order $order ): array {
	$interesting = array();

	foreach ( $order->get_meta_data() as $meta ) {
		$key = (string) $meta->key;

		if ( 0 === strpos( $key, '_factuarea_' ) || 0 === strpos( $key, '_wc_billing/factuarea' ) || 'billing_nif' === $key ) {
			$interesting[ $key ] = $meta->value;
		}
	}

	$refunds = array();

	foreach ( $order->get_refunds() as $refund ) {
		$mark = $refund->get_meta( '_factuarea_inbound_refund' );

		$refunds[] = array(
			'id'           => (int) $refund->get_id(),
			'amount'       => (string) $refund->get_amount(),
			'reason'       => (string) $refund->get_reason(),
			'inbound_mark' => is_scalar( $mark ) ? (string) $mark : '',
		);
	}

	return array(
		'id'             => $order->get_id(),
		'status'         => $order->get_status(),
		'total'          => $order->get_total(),
		'total_refunded' => (string) $order->get_total_refunded(),
		'customer_id'    => (int) $order->get_customer_id(),
		'tax_id'         => class_exists( '\\Factuarea\\WooCommerce\\Checkout\\TaxIdOrderStorage' )
			? ( new \Factuarea\WooCommerce\Checkout\TaxIdOrderStorage() )->read( $order )
			: '',
		'meta'           => $interesting,
		'refunds'        => $refunds,
	);
}

/**
 * What the shop holds about one article, in the shape the scenarios read.
 *
 * The three prices are reported SEPARATELY and as strings. A scenario about the
 * price receiver has to be able to tell "the regular price changed" from "what
 * the shop charges changed", because an article on sale keeps charging its sale
 * price when a new regular price arrives - and a view that reported one number
 * would make that indistinguishable from the receiver having overwritten the
 * offer.
 *
 * @param WC_Product $product The article.
 * @return array<string, mixed>
 */
function factuarea_e2e_product_view( WC_Product $product ): array {
	return array(
		'id'             => (int) $product->get_id(),
		'sku'            => (string) $product->get_sku(),
		'name'           => (string) $product->get_name(),
		'description'    => (string) $product->get_description(),
		'status'         => (string) $product->get_status(),
		'price'          => (string) $product->get_price(),
		'regular_price'  => (string) $product->get_regular_price(),
		'sale_price'     => (string) $product->get_sale_price(),
		'manage_stock'   => (bool) $product->get_manage_stock(),
		'stock_quantity' => $product->get_stock_quantity(),
		'stock_status'   => (string) $product->get_stock_status(),
		'remote_id'      => (string) $product->get_meta( '_factuarea_remote_product_id' ),
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
			'/ledger',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function () {
						global $wpdb;

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness read of a plugin-owned table; the column list is a literal and the table name comes from the plugin.
						$rows = $wpdb->get_results(
							'SELECT idempotency_key, received_at, applied_at FROM ' . factuarea_e2e_inbound_table() . ' ORDER BY id ASC',
							ARRAY_A
						);

						$rows = is_array( $rows ) ? $rows : array();

						return array(
							'rows'  => array_map(
								static function ( array $row ): array {
									return array(
										'idempotency_key' => (string) $row['idempotency_key'],
										'received_at'     => (int) $row['received_at'],
										'applied_at'      => (int) $row['applied_at'],
									);
								},
								$rows
							),
							'count' => count( $rows ),
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/ledger/purge',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function () {
						global $wpdb;

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Harness reset of a plugin-owned table between scenarios.
						$wpdb->query( 'DELETE FROM ' . factuarea_e2e_inbound_table() );

						return array( 'ok' => true );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/options',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$payload = is_array( $payload ) && is_array( $payload['options'] ?? null )
							? $payload['options']
							: array();

						$written = array();

						foreach ( $payload as $name => $value ) {
							$name = (string) $name;

							/*
							 * Only the plugin's OWN options, and the guard is a
							 * literal prefix rather than a read of
							 * `Constants::ALL_OPTIONS`. A harness that could
							 * write any option at all would be one typo away
							 * from rewriting WooCommerce's own configuration in
							 * the middle of a run, and the failure would land on
							 * whichever scenario came next.
							 */
							if ( 0 !== strpos( $name, 'factuarea_wc_' ) ) {
								return new WP_Error(
									'factuarea_e2e_foreign_option',
									'The harness only writes options of the plugin under test, and "' . $name . '" is not one.',
									array( 'status' => 400 )
								);
							}

							update_option( $name, $value );

							$written[ $name ] = (string) get_option( $name, '' );
						}

						return array( 'options' => $written );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/order/create',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$payload = is_array( $payload ) ? $payload : array();

						$desired = (int) ( $payload['id'] ?? 0 );
						$total   = (string) ( $payload['total'] ?? '' );

						if ( $desired < 1 || '' === $total ) {
							return new WP_Error(
								'factuarea_e2e_order_arguments',
								'Creating the order needs the identifier the fixture names and the total the refund notice declares.',
								array( 'status' => 400 )
							);
						}

						$product_id = (int) get_option( 'factuarea_e2e_product_id', 0 );
						$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

						if ( ! $product instanceof WC_Product ) {
							return new WP_Error(
								'factuarea_e2e_product_missing',
								'The seeded article is missing, so no order can be built on it.',
								array( 'status' => 500 )
							);
						}

						try {
							$buyer = factuarea_e2e_buyer_id();
						} catch ( RuntimeException $failure ) {
							return new WP_Error(
								'factuarea_e2e_buyer_missing',
								$failure->getMessage(),
								array( 'status' => 500 )
							);
						}

						factuarea_e2e_clear_order_identifier( $desired );

						$order = new WC_Order();
						$order->set_customer_id( $buyer );
						$order->set_currency( 'EUR' );
						$order->set_payment_method( 'cod' );
						$order->set_payment_method_title( 'Cash on delivery' );
						$order->set_billing_first_name( 'Ada' );
						$order->set_billing_last_name( 'Lovelace' );
						$order->set_billing_address_1( 'Calle Mayor 1' );
						$order->set_billing_city( 'Madrid' );
						$order->set_billing_postcode( '28001' );
						$order->set_billing_country( 'ES' );
						$order->set_billing_email( FACTUAREA_E2E_BUYER_EMAIL );

						$item = new WC_Order_Item_Product();
						$item->set_product( $product );
						$item->set_quantity( 1 );
						$item->set_subtotal( $total );
						$item->set_total( $total );

						$order->add_item( $item );
						$order->calculate_totals( false );

						/*
						 * A PAID status, because a refund - inbound or the
						 * merchant's - can only be made against a sale that was
						 * paid, and because `refunded` is only reached from one
						 * of the paid statuses.
						 *
						 * MEASURED, AND THE SCENARIOS DEPEND ON KNOWING IT: an
						 * order born `processing` still goes through a status
						 * transition, so the plugin enqueues the paid sale and
						 * the outbox holds one row the moment this route
						 * answers. That row belongs to the SCENERY and not to
						 * the delivery under test, which is why every inbound
						 * scenario empties the outbox after building its order
						 * and before publishing anything at the receivers. This
						 * route deliberately does not empty it itself: a window
						 * that tidied up after the shop would hide the day the
						 * plugin stopped enqueueing a paid sale at all.
						 */
						$order->set_status( 'processing' );

						$created = (int) $order->save();

						if ( $created !== $desired ) {
							return new WP_Error(
								'factuarea_e2e_order_identifier',
								'The order was created as ' . $created . ' and the fixture names ' . $desired
								. '. The frozen bodies would look for an order that is not this one.',
								array( 'status' => 500 )
							);
						}

						$fresh = wc_get_order( $created );

						return array_merge(
							factuarea_e2e_order_view( $fresh instanceof WC_Order ? $fresh : $order ),
							array(
								'buyer_login'    => FACTUAREA_E2E_BUYER_LOGIN,
								'buyer_password' => FACTUAREA_E2E_BUYER_PASSWORD,
								'view_order_url' => wc_get_endpoint_url( 'view-order', (string) $created, wc_get_page_permalink( 'myaccount' ) ),
								'orders_url'     => wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ),
							)
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/order/(?P<id>\d+)/notes',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function ( WP_REST_Request $request ) {
						$notes = wc_get_order_notes( array( 'order_id' => (int) $request->get_param( 'id' ) ) );

						return array_map(
							static function ( $note ): array {
								return array(
									'id'            => (int) $note->id,
									'content'       => (string) $note->content,
									'customer_note' => (bool) $note->customer_note,
									'added_by'      => (string) $note->added_by,
								);
							},
							is_array( $notes ) ? $notes : array()
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/order/(?P<id>\d+)/refund',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$payload = is_array( $payload ) ? $payload : array();

						/*
						 * The MERCHANT'S own refund, made the way WooCommerce
						 * makes one from its administration screen: no mark of
						 * inbound origin anywhere on it. It is the control of
						 * the anti-loop guard - the half that still has to leave
						 * the shop - so it deliberately goes through
						 * `wc_create_refund()` rather than through the plugin.
						 */
						$refund = wc_create_refund(
							array(
								'order_id'       => (int) $request->get_param( 'id' ),
								'amount'         => (string) ( $payload['amount'] ?? '0' ),
								'reason'         => (string) ( $payload['reason'] ?? 'Refunded by the merchant in WooCommerce' ),
								'refund_payment' => false,
								'restock_items'  => false,
							)
						);

						if ( is_wp_error( $refund ) ) {
							return new WP_Error(
								'factuarea_e2e_refund_refused',
								'WooCommerce refused the refund: ' . $refund->get_error_message(),
								array( 'status' => 400 )
							);
						}

						return array(
							'refund_id' => (int) $refund->get_id(),
							'amount'    => (string) $refund->get_amount(),
						);
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/product/create',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$payload = is_array( $payload ) ? $payload : array();

						$sku = (string) ( $payload['sku'] ?? '' );

						if ( '' === $sku ) {
							return new WP_Error(
								'factuarea_e2e_sku_missing',
								'An article of the catalogue scenarios is named by its reference, and none was given.',
								array( 'status' => 400 )
							);
						}

						/*
						 * Created from scratch every time, because the catalogue
						 * scenarios assert on what the push CHANGED. An article
						 * left over from the previous run would already carry the
						 * name, the price and the stock the batch declares, and
						 * the assertions would pass without the receiver having
						 * done anything at all.
						 */
						$existing = wc_get_product_id_by_sku( $sku );

						if ( $existing > 0 ) {
							$stale = wc_get_product( $existing );

							if ( $stale instanceof WC_Product ) {
								$stale->delete( true );
							}
						}

						$product = new WC_Product_Simple();
						$product->set_sku( $sku );
						$product->set_name( (string) ( $payload['name'] ?? $sku ) );
						$product->set_description( (string) ( $payload['description'] ?? '' ) );
						$product->set_status( (string) ( $payload['status'] ?? 'publish' ) );
						$product->set_catalog_visibility( 'visible' );
						$product->set_regular_price( (string) ( $payload['regular_price'] ?? '5.00' ) );

						if ( isset( $payload['sale_price'] ) ) {
							$product->set_sale_price( (string) $payload['sale_price'] );
						}

						$manages = (bool) ( $payload['manage_stock'] ?? false );

						$product->set_manage_stock( $manages );

						if ( $manages ) {
							$product->set_stock_quantity( (int) ( $payload['stock_quantity'] ?? 0 ) );
						}

						if ( isset( $payload['remote_id'] ) ) {
							$product->update_meta_data( '_factuarea_remote_product_id', (string) $payload['remote_id'] );
						}

						$product->save();

						$fresh = wc_get_product( (int) $product->get_id() );

						return factuarea_e2e_product_view( $fresh instanceof WC_Product ? $fresh : $product );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/product/by-sku/(?P<sku>[A-Za-z0-9\-_]+)',
			array_merge(
				$guarded,
				array(
					'methods'  => 'GET',
					'callback' => static function ( WP_REST_Request $request ) {
						$id = wc_get_product_id_by_sku( (string) $request->get_param( 'sku' ) );

						if ( $id < 1 ) {
							return new WP_Error(
								'factuarea_e2e_product_missing',
								'No article of this shop carries that reference.',
								array( 'status' => 404 )
							);
						}

						$product = wc_get_product( $id );

						if ( ! $product instanceof WC_Product ) {
							return new WP_Error(
								'factuarea_e2e_product_unreadable',
								'The article of that reference could not be read.',
								array( 'status' => 500 )
							);
						}

						return factuarea_e2e_product_view( $product );
					},
				)
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/products/delete',
			array_merge(
				$guarded,
				array(
					'methods'  => 'POST',
					'callback' => static function ( WP_REST_Request $request ) {
						$payload = $request->get_json_params();
						$skus    = is_array( $payload ) && is_array( $payload['skus'] ?? null ) ? $payload['skus'] : array();
						$removed = 0;

						foreach ( $skus as $sku ) {
							$id = wc_get_product_id_by_sku( (string) $sku );

							if ( $id < 1 ) {
								continue;
							}

							$product = wc_get_product( $id );

							if ( $product instanceof WC_Product ) {
								$product->delete( true );
								++$removed;
							}
						}

						return array( 'removed' => $removed );
					},
				)
			)
		);
	}
);
