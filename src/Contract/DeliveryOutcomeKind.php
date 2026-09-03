<?php
/**
 * The closed set of things that can happen to a delivery.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * The four outcomes of a delivery, and there is no fifth.
 *
 * The set is CLOSED on purpose. An open set - a status string, an integer code,
 * a nullable "maybe" - is how a response nobody anticipated ends up falling
 * into whatever branch happens to be last, and in this plugin the last branch
 * decides between retrying a sale and losing it.
 *
 * Backed by strings because the outbox stores the last outcome in a column, and
 * a stored integer that means "2" tells whoever reads that table nothing.
 */
enum DeliveryOutcomeKind: string {

	/**
	 * The receiver accepted the delivery. The entry is closed and never sent
	 * again.
	 */
	case ACCEPTED = 'accepted';

	/**
	 * The receiver rejected the delivery under its published contract and
	 * declared no wait. Retrying will never work, so the entry is closed with
	 * its reason kept for the merchant, WITHOUT a single further attempt.
	 *
	 * This applies even when the rejection code did not exist on the day this
	 * plugin was published: the absence of a declared wait is the signal, and it
	 * needs no table of known codes to read.
	 */
	case PERMANENT_REJECTION = 'permanent_rejection';

	/**
	 * The receiver rejected the delivery under its published contract and
	 * declared how long to wait. The entry is rescheduled for that wait, and not
	 * sooner: the receiver said the number, so the receiver is obeyed.
	 */
	case RETRYABLE_REJECTION = 'retryable_rejection';

	/**
	 * Something else went wrong: no route to the host, a timeout, a server
	 * error with no contract body, a body that could not be read.
	 *
	 * These are retried on the plugin's own backoff. Treating them as permanent
	 * is the expensive mistake this set exists to prevent - a proxy's 502
	 * carries no wait header and is not a rejection of anything.
	 */
	case TRANSIENT_FAILURE = 'transient_failure';
}
