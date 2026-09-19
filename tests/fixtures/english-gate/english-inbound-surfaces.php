<?php
/**
 * Self-check fixture: the SAME surfaces as its Spanish twin, written in
 * English, plus the traps a careless gate reports by mistake.
 *
 * This file is DATA, like its twin: never included, never autoloaded, never
 * executed, and declaring neither function nor class.
 *
 * Without this half the case beside it proves only that the gate can shout. A
 * gate that reported every receiver message would be worse than no gate at all
 * here, because the inbound half answers with a body on five of its seven
 * statuses and a contributor who sees the gate cry wolf on all of them learns
 * to run it with the result ignored.
 *
 * The traps it carries on purpose:
 *
 *   1. The Spanish tax acronyms and the name of the electronic invoicing
 *      regime, which are the vocabulary this package cannot avoid.
 *   2. The product name, which is Spanish-flavoured and appears everywhere.
 *   3. An English sentence carrying the negation and the word for a mistake,
 *      which are both Spanish words too.
 *   4. The official Spanish name of the tax authority, accented, which is the
 *      one thing the allowlist inside the gate exists for.
 *   5. The option names of the two receiver toggles, which are identifiers and
 *      not text a person reads.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

// Surface: receiver message, carried by a WordPress error object.
$unreadable = new \WP_Error(
	'factuarea_inbound_malformed',
	'The body of the inbound notice could not be read, so the delivery is discarded and there is no error to retry.',
	array( 'status' => 422 )
);

// Surface: receiver message, carried as the body of the REST answer. Trap 2: the product name.
$disabled = rest_ensure_response(
	array(
		'message' => 'The order status receiver is switched off in this shop. Turn it on under the Factuarea settings to accept deliveries.',
		'reason'  => 'receiver_disabled',
	)
);

// Surface: receiver message, built as an array before the answer is composed.
$duplicate = array(
	'detail' => 'This idempotency key has already been applied, so the notice is discarded without repeating its effect.',
);

// Surface: settings or checkout field text. Trap 1: the tax acronyms. Trap 5: the option names.
$section = array(
	'title'       => 'Inbound receivers',
	'label'       => 'Accept order status notices',
	'description' => 'Stored in factuarea_wc_order_status_receiver_enabled and factuarea_wc_catalog_push_receiver_enabled. The shop signing secret is what lets a receiver accept a delivery, and it is the same one the outbound half signs with. Needed so that a full invoice with IVA reaches the buyer whose NIF, NIE or CIF was captured at checkout, and so that VeriFactu reporting stays in step.',
);

// Surface: translation function literal, for the fulfilment status shown in "My account".
$status = __( 'Shipped', 'factuarea-for-woocommerce' );

// Surface: translation function literal, plural form.
$packages = _n( 'One package is on its way', 'Several packages are on their way', 3, 'factuarea-for-woocommerce' );

// Trap 4: the accented official name of the tax authority, which the allowlist whitens.
$authority = 'Reported to the Agencia Estatal de Administración Tributaria by Factuarea, never by this plugin and never by one of its receivers.';
