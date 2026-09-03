<?php
/**
 * Self-check fixture: EVERY surface of task 21.2, each one written in Spanish.
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
 * one thing task 21.3 forbids.
 *
 * Comentario en español con tildes, para que la regla de la letra acentuada
 * tenga algo que encontrar: configuración, número, año.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

// Surface: translation function literal.
$label = __( 'La factura del pedido ya está disponible', 'factuarea-for-woocommerce' );

// Surface: translation function literal, plural form, where the user-facing text is the SECOND argument.
$plural = _n( 'One order', 'Hay varios pedidos que todavía no se han entregado', 3, 'factuarea-for-woocommerce' );

// Surface: settings or checkout field text.
$field = array(
	'label'       => 'Identificador fiscal de la empresa',
	'description' => 'Se necesita para emitir una factura completa en lugar de una simplificada',
	'placeholder' => 'Escribe el NIF de la empresa',
);

// Surface: administration notice through the settings error API.
add_settings_error( 'factuarea', 'factuarea_credentials', 'La clave de API no es correcta' );

// Surface: administration notice written as markup.
$notice = '<div class="notice notice-error"><p>Revisa la configuración de la tienda</p></div>';

// Surface: exception message.
throw new \RuntimeException( 'No se pudo construir el cuerpo del pedido' );

// Surface: exception message through a static factory.
$exception = \RuntimeException::class;

// Inverted opening marks, which no other language of this project writes.
$question = '¿Seguro que quieres desactivar la entrega?';
