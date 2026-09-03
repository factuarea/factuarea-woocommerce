<?php
/**
 * SELF-CHECK FIXTURE - the gate MUST pass on this file.
 *
 * It is the same feature as `gated-feature.php` with nothing withheld, and it
 * is deliberately written with every word that a looser gate would trip over.
 * Two things are being proved here, and only the first is obvious:
 *
 *  1. that a plugin which gates nothing comes out clean; and
 *  2. that the vocabulary does not fire on this repository's own prose.
 *
 * The second one is the reason the file reads the way it does. MEASURED on
 * 2026-09-02, a text sweep for the obvious words over `src/` produced fifteen
 * hits and all fifteen were documentation: "a paid order is lost for good", the
 * `:paid` suffix of an event identity, "no further wait to plan", and the class
 * name `RetryPlanner`. A gate that is red on a clean repository gets switched
 * off, so the words that caused those hits are repeated below ON PURPOSE. If
 * somebody widens a pattern to a bare `paid`, `plan` or `pro`, this file turns
 * red and says so before the whole repository does.
 *
 * License: GPL-2.0-or-later - present because the plugin header carries this
 * line too, and a gate that treated the word as an offence would fail the one
 * file the directory requires it in.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Fixtures\NoPaywall\Ungated;

/**
 * Delivers a paid order. Every merchant gets this, because the commercial
 * boundary of this product is the service on the other end and never the
 * plugin: there is no tier to check and nothing to unlock.
 *
 * @param int $order_id Order to deliver.
 * @return bool Whether the delivery was attempted.
 */
function deliver_order( int $order_id ): bool {
	return enqueue_delivery( $order_id );
}

/**
 * When to try again after a delivery that did not get through.
 *
 * Giving up on something transient loses a paid order for good, so the planner
 * keeps going until the table of the single retry calculator says there is no
 * further wait to plan. The class that owns the table is `RetryPlanner`.
 *
 * @param int $attempt Attempts already failed, counting from one.
 * @return int|null The wait in seconds, or null when there is none left.
 */
function next_wait( int $attempt ): ?int {
	$schedule = array( 60, 300, 1800, 7200, 43200, 86400, 259200 );

	return $schedule[ $attempt - 1 ] ?? null;
}

/**
 * Whether the order is one this plugin has anything to do with.
 *
 * @param string $status Order status as WooCommerce spells it.
 * @return bool True when the plugin acts on it.
 */
function is_processing_or_completed( string $status ): bool {
	return 'processing' === $status || 'completed' === $status;
}
