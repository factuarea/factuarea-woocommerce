<?php
/**
 * The seven native delivery headers of an outgoing delivery, and the package's
 * own eighth when a store identifier is configured.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Transport;

use Factuarea\WooCommerce\Contract\Constants;

/**
 * Composes the header set of one delivery, so that what this plugin sends is
 * indistinguishable from what the native WooCommerce webhook mechanism sends.
 *
 * That indistinguishability is the whole point of the package's delivery
 * design: the receiver normalises ONE shape of WooCommerce delivery, resolves
 * the store by the same header either way, and its cassettes serve both routes.
 * A header set that differed here would force a branch in the connector - which
 * this package is forbidden from writing - or, worse, would be accepted today
 * and stop being accepted the moment anything downstream starts reading the
 * header we left out.
 *
 * ---------------------------------------------------------------------------
 * SEVEN, AND THE NAMES ARE NOT WRITTEN HERE
 * ---------------------------------------------------------------------------
 * Every name comes from `Constants`, which measured them against the receiver's
 * signature header map and its normalisers. None is spelled out as a literal in
 * this file, and a test sweeps the file to keep it that way: two copies of a
 * header name diverge the day somebody fixes one of them, and the failure is
 * silent - a delivery whose signature travels in a header nobody reads is a 401
 * that looks like a bad secret.
 *
 * The returned array is keyed by header name, in `Constants::DELIVERY_HEADERS`
 * order, which is the order a native delivery sends them.
 *
 * ---------------------------------------------------------------------------
 * THE EIGHTH HEADER, AND WHY THERE IS ONE AT ALL
 * ---------------------------------------------------------------------------
 * `Constants::HEADER_STORE_ID` carries the identifier of this store in
 * Factuarea, and it is the ONE header of this package's own that a delivery
 * ever grows. It exists because the seven native ones cannot answer the
 * question the receiver has to answer first:
 *
 * TWO SHOPS REGISTERED UNDER THE SAME CONNECTION ARE NOT TOLD APART BY THE
 * ORIGIN ADDRESS. The address travels in `HEADER_SOURCE` and it is
 * `home_url()`, which is where the shop lives and not which shop it is to
 * Factuarea; a merchant who registered two of them under one account sends two
 * addresses the receiver has no mapping for. Guessing which of the two sent a
 * sale is guessing WHICH COMPANY IT INVOICES AGAINST - and an invoice issued
 * against the wrong company lands in a correlative series that nobody is
 * allowed to delete.
 *
 * The identifier is the value the merchant PASTED FROM FACTUAREA into the
 * settings screen, so it is exactly the key an identity extractor on the other
 * side would look up. It is never derived from the order, from the body, or
 * from the site address: a value derived from any of those would be this
 * plugin's guess about an identity that is registered elsewhere.
 *
 * IT IS ADDITIVE, AND THAT IS ENFORCED RATHER THAN INTENDED. It is deliberately
 * not part of `Constants::DELIVERY_HEADERS` - that list is «the seven a native
 * WooCommerce delivery sends» and is asserted as such - it is appended AFTER
 * the seven, it renames and replaces none of them, and it travels only when an
 * identifier is configured. A receiver that knows only the native set keeps
 * accepting the delivery without changing a line, and an installation that
 * never configured an identifier sends exactly what it sent before.
 *
 * THE EMPTY STRING IS THE ABSENT VALUE, and it is one value rather than two on
 * purpose: `get_option()` answers an unset option with it, so «not configured»
 * and «not passed» are the same case here. Whoever reads the option trims it -
 * every read site of this package does, see the outbox dispatcher - so a value
 * that is only whitespace never reaches this method as one that is configured.
 * Sending a header with a blank value would be worse than sending none: the
 * other side would have to decide what an empty identity means.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT HERE
 * ---------------------------------------------------------------------------
 * The content type and the user agent are NOT part of this set. They belong to
 * every request the plugin makes, not to a delivery, and the HTTP client is the
 * one place that adds them; putting them here too would give the same header
 * two sources and let them disagree.
 *
 * ---------------------------------------------------------------------------
 * TWO INVARIANTS THE CALLER OWNS, WRITTEN DOWN SO THEY ARE NOT REDISCOVERED
 * ---------------------------------------------------------------------------
 * 1. THE TOPIC IS `resource.event`. The receiver reads the topic to decide what
 *    kind of fact it is being told about, and the resource and event halves are
 *    sent alongside it because a native delivery sends all three. Passing three
 *    values that do not agree produces a delivery that is well-formed and means
 *    something different from what the sender intended.
 *
 * 2. THE SOURCE IS THE ADDRESS OF THE CONFIGURED STORE. Measured against the
 *    receiver (2026-09-02): it does NOT resolve the store by this header - it
 *    reads only the signature header, tries the signing secret of each
 *    candidate integration and disambiguates by the external store id of the
 *    already-verified body. The source header is sent for parity with native
 *    WooCommerce deliveries and for tracing. An origin the receiver does not
 *    recognise is answered with a 200 - deliberately, so that an unknown sender
 *    does not retry forever. That means a wrong source does not look like a
 *    failure from here: it looks like a success that never produced an invoice,
 *    which is why the LOCAL origin guard matters. The dispatcher
 *    refuses to deliver an entry whose origin is not the configured one before
 *    it ever gets here (see the origin guard of the outbox dispatcher); this
 *    class composes, it does not police.
 *
 * ---------------------------------------------------------------------------
 * THE TWO IDENTIFIERS ARE FOR TRACING AND FOR NOTHING ELSE
 * ---------------------------------------------------------------------------
 * `X-WC-Webhook-ID` and `X-WC-Webhook-Delivery-ID` belong to the DELIVERY, not
 * to the order, and the second one changes on every attempt. Neither may take
 * part in composing an event identity: an identity taken from them would make
 * the second attempt of the same notification look like a new event, and the
 * receiver would issue a SECOND invoice for one sale, in a consecutive series
 * that verifiable invoicing does not allow anyone to delete. The identity is
 * composed in `Contract\EventIdentity`, from the order, and it never travels in
 * a header.
 */
final class DeliveryHeaders {

	/**
	 * The header set of one order delivery.
	 *
	 * @param string $topic       Topic of the delivery, as `resource.event` - for example `order.updated`.
	 * @param string $resource    Resource half of the topic - for example `order`.
	 * @param string $event       Event half of the topic - for example `updated`.
	 * @param string $source      Address of the store the delivery comes from (parity with native deliveries and tracing; the receiver resolves the store by the signature, not by this header).
	 * @param string $signature   Signature produced by DeliverySigner over the exact body that will be sent.
	 * @param int    $webhook_id  Identifier of the webhook. Tracing only.
	 * @param int    $delivery_id Identifier of this delivery attempt. Tracing only; it changes on every attempt.
	 * @param string $store_id    Identifier of this store in Factuarea, as the merchant configured it, or the empty string when none is configured. OPTIONAL AND LAST so that every call written against the frozen seven-parameter form keeps working untouched - the same resource, and for the same reason, that `Outbox\RetryPlanner::next_attempt_at()` used for its third parameter.
	 *
	 * @return array<string, string> The seven native delivery headers, keyed by name, in native order, followed by the package header with the store identifier when one is configured.
	 */
	public function for_order(
		string $topic,
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- Name frozen in Constants; renaming it breaks every named-argument call site.
		string $resource,
		string $event,
		string $source,
		string $signature,
		int $webhook_id,
		int $delivery_id,
		string $store_id = ''
	): array {
		$headers = array(
			Constants::HEADER_SOURCE      => $source,
			Constants::HEADER_TOPIC       => $topic,
			Constants::HEADER_RESOURCE    => $resource,
			Constants::HEADER_EVENT       => $event,
			Constants::HEADER_SIGNATURE   => $signature,
			Constants::HEADER_WEBHOOK_ID  => (string) $webhook_id,
			Constants::HEADER_DELIVERY_ID => (string) $delivery_id,
		);

		/*
		 * No identifier configured: the delivery is the seven of always, which
		 * is what every installation sent before this header existed and what
		 * an installation that never fills the field keeps sending.
		 */
		if ( '' === $store_id ) {
			return $headers;
		}

		/*
		 * APPENDED, never inserted. The seven keep their names, their values
		 * and their positions, so the set stays byte-comparable with a native
		 * delivery for whoever diffs the two while debugging.
		 */
		$headers[ Constants::HEADER_STORE_ID ] = $store_id;

		return $headers;
	}
}
