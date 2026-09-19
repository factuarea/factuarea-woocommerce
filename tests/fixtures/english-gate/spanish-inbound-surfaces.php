<?php
/**
 * Self-check fixture: the surfaces the INBOUND half adds, each one written in
 * Spanish.
 *
 * This file is DATA. It is never included, never autoloaded and never executed:
 * the gate reads it as text, from a separate process, and the test asserts on
 * what the gate says about it. It declares no function and no class on purpose,
 * so that including it by accident could not collide with anything.
 *
 * It is also OUTSIDE the scope of `composer lint:english`, which sweeps `src`,
 * `scripts`, the two readme files and `docs`. That is not an oversight: a
 * fixture whose whole job is to carry Spanish would put the real gate in
 * permanent red, and the fix for that would be an allowlist entry, which is the
 * one thing the language rule of this package forbids.
 *
 * The three surfaces below are the ones this change adds, and the reason they
 * needed a fixture of their own is that the first of them was NOT named before:
 *
 *   1. The message of a receiver. It reaches the sender - and, through the
 *      delivery report the sender keeps, a person - in the body of a REST
 *      answer, so it is neither a translated label nor an exception message,
 *      and until this fixture existed the gate reported it as a plain string
 *      literal or, when it travelled inside a WordPress error object, as an
 *      exception message. Both readings send whoever fixes it to the wrong
 *      file.
 *   2. The labels of the settings section that governs the two receivers.
 *   3. The fulfilment status labels shown to the buyer in "My account".
 *
 * The fourth new surface - the inbound contract document under `docs` - is
 * prose, and prose is measured by the pair of listing fixtures beside this one.
 *
 * Comentario en español con tildes, para que la regla de la letra acentuada
 * tenga algo que encontrar: configuración, recepción, año.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

// Surface: receiver message, carried by a WordPress error object.
$unreadable = new \WP_Error(
	'factuarea_inbound_malformed',
	'El cuerpo del aviso entrante no se puede leer y la entrega se descarta',
	array( 'status' => 422 )
);

// Surface: receiver message, carried as the body of the REST answer.
$disabled = rest_ensure_response(
	array(
		'message' => 'El receptor de estado de pedido está deshabilitado en esta tienda',
		'reason'  => 'receiver_disabled',
	)
);

// Surface: receiver message, built as an array before the answer is composed.
$duplicate = array(
	'detail' => 'Esta clave de idempotencia ya se aplicó, así que el aviso se descarta sin repetir su efecto',
);

// Surface: settings or checkout field text, for the section of the two receivers.
$section = array(
	'title'       => 'Receptores de avisos entrantes',
	'label'       => 'Aceptar avisos de estado de pedido',
	'description' => 'Se necesita el secreto de firma de la tienda para que el receptor acepte una entrega',
);

// Surface: translation function literal, for the fulfilment status shown in "My account".
$status = __( 'Enviado', 'factuarea-for-woocommerce' );

// Surface: translation function literal, plural form, where the user-facing text is the SECOND argument.
$packages = _n( 'One package', 'Hay varios bultos en camino para este pedido', 3, 'factuarea-for-woocommerce' );

// Inverted opening marks, which no other language of this project writes.
$question = '¿Seguro que quieres deshabilitar el receptor de reembolsos?';
