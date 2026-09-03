<?php
/**
 * Puts the containerised shop into the one state every scenario starts from.
 *
 * Run by `wp eval-file` from the `afterStart` lifecycle script of .wp-env.json,
 * so that `npm run e2e:up` leaves a site the scenarios can use rather than a
 * site they each have to finish setting up.
 *
 * IDEMPOTENT. It is run again on every start, and a merchant-facing setting that
 * was already right is left alone rather than rewritten.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: it never touches the plugin under test. The
 * outbox table, the checkout field, the listeners and the schedule are all put
 * in place by the plugin's own activation and wiring; a seed that created the
 * table itself would hide the day activation stopped creating it.
 *
 * @package Factuarea\WooCommerce\Tests\E2E
 */

/*
 * NO `declare( strict_types = 1 )` HERE, AND IT IS NOT AN OVERSIGHT.
 *
 * `wp eval-file` reads this file and runs its contents through `eval()`, and a
 * strict-types declaration has to be the first statement of a SCRIPT - inside an
 * eval it is a fatal error before a single line runs. Measured: the first start
 * of this environment died exactly there.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active on this site, so there is no shop to seed.' );
}

$notes = array();

/*
 * 1. Pretty permalinks. WooCommerce endpoints - order-received above all - are
 *    what the scenarios read the order number out of, and the plain structure
 *    spells them differently.
 */
if ( '/%postname%/' !== (string) get_option( 'permalink_structure' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
	$notes[] = 'permalinks set to /%postname%/';
}

/*
 * A HARD flush, and the difference is the whole environment.
 *
 * The soft one only rewrites the rules stored in the database. It is `.htaccess`
 * that makes Apache hand an unknown path to WordPress, and without that file the
 * ingestion path below never reaches PHP at all: Apache answers 404 itself and
 * every delivery scenario fails with a transport error that looks like a plugin
 * defect. MEASURED: the first run of this seed left no `.htaccess` and
 * `/wp-json/` was a hard 404 while WordPress happily reported pretty permalinks.
 */
flush_rewrite_rules( true );

if ( ! file_exists( ABSPATH . '.htaccess' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	save_mod_rewrite_rules();
}

if ( ! file_exists( ABSPATH . '.htaccess' ) ) {
	WP_CLI::error(
		'WordPress could not write ' . ABSPATH . '.htaccess, so Apache will not route the ingestion path to PHP. '
		. 'The delivery scenarios would all fail with a transport error.'
	);
}

/*
 * 2. A shop with as few moving parts as a shop can have. Every one of these
 *    removes a field or a branch from the checkout that has nothing to do with
 *    what is being tested: one country, no tax lines, no shipping at all (the
 *    product is virtual), and no account creation.
 */
$shop_settings = array(
	'woocommerce_currency'                          => 'EUR',
	'woocommerce_default_country'                   => 'ES:M',
	'woocommerce_allowed_countries'                 => 'specific',
	'woocommerce_specific_allowed_countries'        => array( 'ES' ),
	'woocommerce_ship_to_countries'                 => 'disabled',
	'woocommerce_calc_taxes'                        => 'no',
	'woocommerce_enable_guest_checkout'             => 'yes',
	'woocommerce_enable_checkout_login_reminder'    => 'no',
	'woocommerce_enable_signup_and_login_from_checkout' => 'no',
	'woocommerce_enable_coupons'                    => 'no',
	'woocommerce_checkout_company_field'            => 'hidden',
	'woocommerce_checkout_address_2_field'          => 'hidden',
	'woocommerce_checkout_phone_field'              => 'hidden',
	'woocommerce_task_list_hidden'                  => 'yes',
	'woocommerce_show_marketplace_suggestions'      => 'no',
);

foreach ( $shop_settings as $option => $value ) {
	if ( get_option( $option ) !== $value ) {
		update_option( $option, $value );
	}
}

update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
delete_transient( '_wc_activation_redirect' );

/*
 * 3. One payment gateway, and it has to be one that puts the order into a PAID
 *    state without leaving the site.
 *
 * MEASURED, and this is the reason the choice is not free: cash on delivery
 * moves the order to `processing`, which the status listener treats as paid,
 * while cheque moves it to `on-hold`, which the listener deliberately ignores.
 * A scenario built on cheque would complete a checkout and then find an empty
 * outbox, and would look like a plugin defect.
 *
 * `enable_for_virtual` is set explicitly because it defaults to `no`, and the
 * product below is virtual: left alone, the only gateway on the shop would be
 * unavailable at checkout and nothing could be ordered at all.
 */
update_option(
	'woocommerce_cod_settings',
	array(
		'enabled'            => 'yes',
		'title'              => 'Cash on delivery',
		'description'        => 'Pay when the order arrives.',
		'instructions'       => 'Pay when the order arrives.',
		'enable_for_methods' => array(),
		'enable_for_virtual' => 'yes',
	)
);

foreach ( array( 'cheque', 'bacs', 'paypal' ) as $gateway_id ) {
	$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	if ( 'no' !== ( $settings['enabled'] ?? '' ) ) {
		$settings['enabled'] = 'no';
		update_option( 'woocommerce_' . $gateway_id . '_settings', $settings );
	}
}

/*
 * 4. WooCommerce's own pages. Created by its activation, asserted here rather
 *    than assumed, because everything below hangs off them.
 */
if ( 0 >= (int) wc_get_page_id( 'checkout' ) ) {
	WC_Install::create_pages();
	$notes[] = 'WooCommerce pages were missing and have been created';
}

/*
 * 5. One virtual product. Virtual so that the checkout carries no shipping
 *    address: with `location: address` the tax identifier field is rendered in
 *    every address form there is, and a shop with shipping would paint it twice
 *    for reasons that have nothing to do with the classic checkout guard the
 *    fourth scenario is about.
 */
$product_id = (int) get_option( 'factuarea_e2e_product_id', 0 );
$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

if ( ! $product instanceof WC_Product ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Factuarea end-to-end product' );
	$product->set_slug( 'factuarea-e2e-product' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10.00' );
	$product->set_price( '10.00' );
	$product->set_virtual( true );
	$product->set_sold_individually( true );
	$product->set_manage_stock( false );
	$product_id = (int) $product->save();

	update_option( 'factuarea_e2e_product_id', $product_id, false );
	$notes[] = 'product created (#' . $product_id . ')';
}

/*
 * 6. The two checkouts.
 *
 * The block checkout is WooCommerce's OWN checkout page, left where its
 * installer put it, because "the default page of a fresh WooCommerce 8.9" is
 * exactly what the third scenario is about. It is CHECKED rather than trusted:
 * if this WooCommerce did not make the default a block page, the seed says so
 * and converts it, so that the scenario keeps meaning what it says instead of
 * silently exercising the shortcode twice.
 */
$checkout_page_id = (int) wc_get_page_id( 'checkout' );
$checkout_page    = get_post( $checkout_page_id );
$block_markup     = '<!-- wp:woocommerce/checkout -->' . "\n" . '<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"></div>' . "\n" . '<!-- /wp:woocommerce/checkout -->';

if ( $checkout_page instanceof WP_Post && false === strpos( (string) $checkout_page->post_content, 'wp:woocommerce/checkout' ) ) {
	wp_update_post(
		array(
			'ID'           => $checkout_page_id,
			'post_content' => $block_markup,
		)
	);

	$notes[] = 'the default checkout page was NOT block-based on this WooCommerce and has been converted';
}

update_option( 'factuarea_e2e_block_checkout_url', get_permalink( $checkout_page_id ), false );

/*
 * The classic checkout is a page of our own carrying the shortcode. It has to be
 * a second page: the shortcode and the block cannot both be the checkout, and
 * the fourth scenario needs the one WooCommerce renders with the template hooks.
 */
$classic_page = get_page_by_path( 'classic-checkout' );

if ( ! $classic_page instanceof WP_Post ) {
	$classic_page_id = wp_insert_post(
		array(
			'post_title'     => 'Classic checkout',
			'post_name'      => 'classic-checkout',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_content'   => '[woocommerce_checkout]',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		)
	);

	$notes[] = 'classic checkout page created (#' . (int) $classic_page_id . ')';
} else {
	$classic_page_id = (int) $classic_page->ID;

	if ( false === strpos( (string) $classic_page->post_content, '[woocommerce_checkout]' ) ) {
		wp_update_post(
			array(
				'ID'           => $classic_page_id,
				'post_content' => '[woocommerce_checkout]',
			)
		);
	}
}

update_option( 'factuarea_e2e_classic_checkout_url', get_permalink( $classic_page_id ), false );

/*
 * 7. The address deliveries are POSTed to.
 *
 * MEASURED rather than written down. The drain runs in whichever container
 * happens to be executing - the web container on a scheduled pass, the CLI
 * container when a scenario triggers one - and `http://localhost:8888` is a
 * mapping that exists on the HOST and in neither of them. So the candidates are
 * probed, in order, against the mock receiver's own health route, and the first
 * one that answers is the one that is stored.
 *
 * If none answers, this FAILS the start. A base address that cannot be reached
 * would let every delivery scenario fail with a transport error that looked like
 * a plugin defect, which is the most expensive kind of red there is.
 */
$candidates = array( 'http://wordpress', home_url(), 'http://localhost' );
$reachable  = null;
$tried      = array();

foreach ( $candidates as $candidate ) {
	$candidate = rtrim( (string) $candidate, '/' );

	if ( '' === $candidate || in_array( $candidate, $tried, true ) ) {
		continue;
	}

	$tried[] = $candidate;

	/*
	 * The probe is a GET against the INGESTION PATH ITSELF, not against a
	 * convenient health route somewhere else. That path is the one thing that
	 * has to work, and it is the one that depends on Apache, on `.htaccess`, on
	 * the permalink structure and on the mock receiver's dispatcher all being
	 * right at once. A health route answering 200 would prove none of that.
	 *
	 * A GET is used rather than a POST because a POST would be recorded as a
	 * delivery, and the first scenario counts deliveries. The dispatcher answers
	 * a GET with 405 and a body naming itself, which is a stronger signal than
	 * 200 anyway: it can only have come from the mock receiver.
	 */
	$probe = wp_remote_get(
		$candidate . '/api/webhooks/stores/woocommerce',
		array(
			'timeout'     => 5,
			'redirection' => 0,
		)
	);

	if ( is_wp_error( $probe ) ) {
		continue;
	}

	if ( 405 === (int) wp_remote_retrieve_response_code( $probe )
		&& false !== strpos( (string) wp_remote_retrieve_body( $probe ), 'method_not_allowed' ) ) {
		$reachable = $candidate;
		break;
	}
}

if ( null === $reachable ) {
	WP_CLI::error(
		'None of the candidate delivery addresses answered the mock receiver: ' . implode( ', ', $tried )
		. '. The delivery scenarios would all fail with a transport error, so the environment is not ready.'
	);
}

/*
 * 8. The plugin's own settings. The signing secret is a fixed value rather than
 *    a generated one so that a failing run can be reproduced; it is a test
 *    secret on a container, and the settings screen's generator is covered by
 *    its own unit tests.
 */
$plugin_settings = array(
	'factuarea_wc_base_url'         => $reachable,
	'factuarea_wc_api_base_url'     => $reachable,
	'factuarea_wc_api_key'          => 'factuarea-e2e-api-key',
	'factuarea_wc_store_id'         => 'factuarea-e2e-store',
	'factuarea_wc_signing_secret'   => 'e2e0000000000000000000000000000000000000000000000000000000000001',
	'factuarea_wc_delivery_enabled' => '1',
);

foreach ( $plugin_settings as $option => $value ) {
	update_option( $option, $value );
}

WP_CLI::success(
	'Shop seeded. ' . wp_json_encode(
		array(
			'wordpress'        => get_bloginfo( 'version' ),
			'woocommerce'      => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			'php'              => PHP_VERSION,
			'home_url'         => home_url(),
			'delivery_address' => $reachable . '/api/webhooks/stores/woocommerce',
			'product_id'       => $product_id,
			'block_checkout'   => get_permalink( $checkout_page_id ),
			'classic_checkout' => get_permalink( $classic_page_id ),
			'notes'            => $notes,
		)
	)
);
