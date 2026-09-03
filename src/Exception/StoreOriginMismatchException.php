<?php
/**
 * Thrown when an outbox entry belongs to a store other than the configured one.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * The entry about to be delivered was recorded under a different store origin
 * from the one currently configured.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A HARD STOP AND NOT A WARNING
 * ---------------------------------------------------------------------------
 * It is the half of the isolation guarantee that belongs to this repository.
 * The plugin runs inside ONE merchant's WordPress and never reads any Factuarea
 * table, so there is no cross-company query here to isolate; what there is, is
 * the risk of signing a sale of one store with the credentials of another.
 *
 * The situation is not hypothetical. A staging site cloned from production
 * carries the production database, so it carries the outbox, with entries whose
 * origin is the production address. If those went out signed with the staging
 * store's secret, they would either be rejected, or - worse, if both sites were
 * registered under the same account - invoice real sales twice.
 *
 * So the entry is not delivered and not silently dropped either: it is settled
 * with this reason, which stays readable for the merchant.
 *
 * The message names the two ORIGINS, which are addresses and not secrets.
 */
final class StoreOriginMismatchException extends \RuntimeException implements PluginException {

	/**
	 * The recorded origin is not the configured one.
	 *
	 * @param string $recorded_origin   Origin stored with the outbox entry.
	 * @param string $configured_origin Origin this installation is configured with.
	 * @return self
	 */
	public static function between( string $recorded_origin, string $configured_origin ): self {
		return new self(
			sprintf(
				'This order was recorded for the store at %1$s, but this installation is configured as %2$s, so it was not delivered. That normally means the database was copied from another site. Delete the pending entries that belong to the other store, or point this installation back at it.',
				$recorded_origin,
				$configured_origin,
			),
		);
	}
}
