<?php
/**
 * Thrown when the body of an order cannot be produced.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * The delivery body for an order could not be built.
 *
 * The body is the representation WooCommerce itself produces for that order, so
 * this failing means the order is not there, is not readable, or WooCommerce
 * refused to produce it. It is NEVER a reason to serialise something by hand
 * instead: a hand-written body is a different document from the one the
 * receiver was built to read, and it would be accepted and then normalised
 * wrongly, which is worse than not arriving.
 *
 * Thrown by the payload builder. The caller records the reason against the
 * order and leaves the entry for the merchant to see; it does not retry
 * forever against an order that will not come back.
 */
final class OrderPayloadNotBuildableException extends \RuntimeException implements PluginException {

	/**
	 * The order does not exist, or is not readable from here.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return self
	 */
	public static function order_not_found( int $order_id ): self {
		return new self(
			sprintf(
				'Order %d could not be read, so nothing was sent to Factuarea. Check that the order still exists and has not been deleted by a cleanup plugin, then use Resend on the order.',
				$order_id,
			),
		);
	}

	/**
	 * WooCommerce produced no usable representation for the order.
	 *
	 * @param int    $order_id WooCommerce order identifier.
	 * @param string $reason   What WooCommerce reported, in English, with no credential in it.
	 * @return self
	 */
	public static function representation_failed( int $order_id, string $reason ): self {
		return new self(
			sprintf(
				'WooCommerce could not produce the order representation for order %1$d (%2$s), so nothing was sent to Factuarea. This usually means another plugin is filtering the order REST response; disable it for a moment and use Resend on the order.',
				$order_id,
				$reason,
			),
		);
	}

	/**
	 * The representation could not be encoded into the body that gets signed.
	 *
	 * @param int    $order_id WooCommerce order identifier.
	 * @param string $reason   What the encoder reported, in English.
	 * @return self
	 */
	public static function encoding_failed( int $order_id, string $reason ): self {
		return new self(
			sprintf(
				'The order representation for order %1$d could not be encoded (%2$s), so nothing was sent to Factuarea. This usually means the order carries a field with invalid text; fix the field on the order and use Resend.',
				$order_id,
				$reason,
			),
		);
	}
}
