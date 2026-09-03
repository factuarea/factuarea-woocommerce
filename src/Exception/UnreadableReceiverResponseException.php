<?php
/**
 * Thrown when a response from the receiver cannot be read as one.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * Something came back from the configured address, and it is not a response
 * this plugin can make sense of.
 *
 * ---------------------------------------------------------------------------
 * WHERE THIS IS AND IS NOT USED
 * ---------------------------------------------------------------------------
 * NOT on the delivery path. There, an unreadable response is a TRANSIENT
 * outcome and the entry is retried: throwing on it would be the expensive
 * mistake, because a proxy or a maintenance page in front of the receiver
 * returns unreadable bodies for minutes at a time, and every one of those
 * bodies would otherwise take a paid sale with it.
 *
 * This exception is for the READ side, where a human is waiting: the connection
 * test and the invoice download. There an unreadable answer is a diagnosis to
 * show, not something to retry in the background, and it has to be told apart
 * from a wrong credential - which is the whole reason the connection test
 * exists.
 *
 * The message names the ADDRESS, which is a setting and not a secret, and never
 * the API key.
 */
final class UnreadableReceiverResponseException extends \RuntimeException implements PluginException {

	/**
	 * The body is not the document shape that was expected.
	 *
	 * @param string $url         Address that was called.
	 * @param int    $status_code Status code received.
	 * @return self
	 */
	public static function malformed_body( string $url, int $status_code ): self {
		return new self(
			sprintf(
				'The answer from %1$s (status %2$d) was not readable, so nothing could be confirmed. Check that the base address points at your Factuarea installation and not at a redirect, a maintenance page or a security plugin, then run the connection test again.',
				$url,
				$status_code,
			),
		);
	}

	/**
	 * A response arrived with a status this operation has no reading for.
	 *
	 * @param string $url         Address that was called.
	 * @param int    $status_code Status code received.
	 * @return self
	 */
	public static function unexpected_status( string $url, int $status_code ): self {
		return new self(
			sprintf(
				'%1$s answered with status %2$d, which this plugin has no reading for. Check the base address and whether anything in front of your Factuarea installation is answering for it, then run the connection test again.',
				$url,
				$status_code,
			),
		);
	}

	/**
	 * Nothing came back at all.
	 *
	 * @param string $url    Address that was called.
	 * @param string $reason Transport reason, in English, with no credential in it.
	 * @return self
	 */
	public static function no_response( string $url, string $reason ): self {
		return new self(
			sprintf(
				'%1$s could not be reached (%2$s). Check the base address, and whether this server is allowed to make outgoing requests; some hosts block them by default.',
				$url,
				$reason,
			),
		);
	}
}
