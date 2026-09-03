<?php
/**
 * Self-check fixture: the SAME surfaces as its Spanish twin, written in
 * English, plus the four things a careless gate reports by mistake.
 *
 * This file is DATA, like its twin: never included, never autoloaded, never
 * executed, and declaring neither function nor class.
 *
 * The four traps it carries on purpose, because a gate that only proves it can
 * shout is half a gate:
 *
 *   1. The Spanish tax acronyms and the name of the electronic invoicing
 *      regime, which are the vocabulary this package cannot avoid.
 *   2. The product name, which is Spanish-flavoured and appears everywhere.
 *   3. An English sentence carrying the negation and the word for a mistake,
 *      which are both Spanish words too. A gate that merely counted words would
 *      report this line, and every contributor would learn to ignore it.
 *   4. The official Spanish name of the tax authority, accented, which is the
 *      one thing the allowlist inside the gate exists for.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

// Surface: translation function literal.
$label = __( 'The invoice for this order is ready', 'factuarea-for-woocommerce' );

// Surface: translation function literal, plural form.
$plural = _n( 'One order is still undelivered', 'Several orders are still undelivered', 3, 'factuarea-for-woocommerce' );

// Surface: settings or checkout field text. Trap 1: the tax acronyms.
$field = array(
	'label'       => 'Tax identifier (NIF, NIE or CIF)',
	'description' => 'Needed to issue a full invoice with IVA instead of a simplified one, and to report it through VeriFactu.',
	'placeholder' => 'B12345678',
);

// Surface: administration notice through the settings error API. Trap 2: the product name.
add_settings_error( 'factuarea', 'factuarea_credentials', 'The API key is not valid. Generate a new one in Factuarea and paste it here.' );

// Surface: administration notice written as markup.
$notice = '<div class="notice notice-error"><p>Review the store settings before enabling delivery.</p></div>';

// Surface: exception message. Trap 3: the negation and the word for a mistake, in an English sentence.
throw new \RuntimeException( 'The order body could not be built and there is no error code in the response.' );

// Trap 4: the accented official name of the tax authority, which the allowlist whitens.
$authority = 'Filed with the Agencia Estatal de Administración Tributaria (AEAT) by Factuarea, never by this plugin.';
