<?php
/**
 * Thrown when an authenticated inbound delivery carries a body this plugin
 * cannot read at all.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * The body of an inbound delivery could not be read.
 *
 * ---------------------------------------------------------------------------
 * WHEN THIS IS THROWN, AND WHEN IT IS EMPHATICALLY NOT
 * ---------------------------------------------------------------------------
 * It is thrown for a body that cannot be DECODED or that is not the kind of
 * document any inbound route accepts - a payload that is not JSON, a JSON scalar
 * or list where an object belongs, a batch whose items are not items.
 *
 * It is NOT thrown for a well-formed document of the wrong shape. A notice
 * missing its idempotency key, a status outside the four, an amount that is not
 * a decimal string: those are ordinary, expected outcomes with a frozen status
 * of their own (`Contract\InboundResponseStatus::MALFORMED`), and the factories
 * of `Contract\InboundStatusNotice`, `Contract\InboundRefundNotice` and
 * `Contract\CatalogPushItem` answer `null` for them rather than throwing.
 *
 * The line is drawn there because of what an exception costs INSIDE a REST
 * callback: an uncaught one is rendered by WordPress as a 500, the sender
 * classifies a 5xx as RETRYABLE, and a permanently malformed notice comes back
 * seven more times on the shared retry schedule. A body that is merely of the
 * wrong shape must never take that path.
 *
 * ===========================================================================
 * NO MESSAGE OF THIS CLASS MAY NAME A SECRET, A SIGNATURE OR A BODY
 * ===========================================================================
 * This is narrower and harder than the prohibition its interface already
 * carries, and it is written here because this is the one exception of the
 * catalogue that is raised while holding all three.
 *
 * FORBIDDEN, whole or truncated: the signing secret; the EXPECTED signature;
 * the PRESENTED signature; the raw body; any excerpt, prefix, length or hash of
 * any of them.
 *
 *  - The secret, for the reason the interface gives: it is the one thing
 *    standing between anyone on the internet and a forged sale delivered into
 *    the merchant's books, and these strings end up in debug logs that get
 *    pasted into support tickets.
 *  - The EXPECTED signature, because publishing it is publishing an oracle: a
 *    prober who can make this plugin compute and then show the expected
 *    signature of a body they chose does not need the secret any more - they
 *    have a valid delivery.
 *  - The PRESENTED one, because echoing back attacker-controlled bytes into a
 *    merchant's log or admin notice is how an injected payload gets rendered
 *    somewhere that interprets it.
 *  - The body, because an inbound delivery carries the buyer's order data. A
 *    log line is not the place for it, and a support ticket even less.
 *
 * WHAT TO SAY INSTEAD. Name the FIELD and the ROUTE - which field was missing or
 * of the wrong kind, on which receiver - and say what to do next. That is what
 * whoever is debugging actually needs, and none of it is a secret.
 */
final class InboundPayloadNotReadableException extends \RuntimeException implements PluginException {

	/**
	 * The delivery body is not a JSON document at all, or decodes to something
	 * that is not an object.
	 *
	 * The message deliberately says nothing about WHAT arrived. "Expected a JSON
	 * object" is the whole of the useful information; quoting the first bytes of
	 * the body to be helpful is quoting an unverified payload into a log.
	 *
	 * @param string $route Route that received it, as published by this plugin.
	 * @return self
	 */
	public static function body_is_not_an_object( string $route ): self {
		return new self(
			sprintf(
				'The delivery to %1$s did not carry a JSON object, so nothing in it could be read. The receiver expects a single JSON object as the request body, sent with a Content-Type of application/json.',
				$route
			)
		);
	}

	/**
	 * A required field is missing.
	 *
	 * @param string $route Route that received it.
	 * @param string $field Name of the field, as the contract spells it.
	 * @return self
	 */
	public static function missing_field( string $route, string $field ): self {
		return new self(
			sprintf(
				'The delivery to %1$s did not declare the required field "%2$s", so it was not applied. Check the inbound contract published with this plugin for the fields each notice has to carry.',
				$route,
				$field
			)
		);
	}

	/**
	 * A field is present and is not of the kind the contract declares.
	 *
	 * Only the EXPECTED kind is named, never the value that arrived: the value
	 * is part of an unverified body.
	 *
	 * @param string $route    Route that received it.
	 * @param string $field    Name of the field, as the contract spells it.
	 * @param string $expected Kind the contract declares, in plain words.
	 * @return self
	 */
	public static function field_of_wrong_kind( string $route, string $field, string $expected ): self {
		return new self(
			sprintf(
				'The delivery to %1$s declared the field "%2$s" as something other than %3$s, so it was not applied. Amounts and identifiers travel as strings in this contract on purpose: a number loses the last decimal of amounts that a string keeps exactly.',
				$route,
				$field,
				$expected
			)
		);
	}

	/**
	 * A batch is not a list of items.
	 *
	 * @param string $route Route that received it.
	 * @param string $field Name of the field that should have held the list.
	 * @return self
	 */
	public static function batch_is_not_a_list( string $route, string $field ): self {
		return new self(
			sprintf(
				'The delivery to %1$s did not carry "%2$s" as a list of articles, so no article of the batch was applied. The receiver expects a list, even when it holds a single article.',
				$route,
				$field
			)
		);
	}
}
