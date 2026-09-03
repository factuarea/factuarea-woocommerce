<?php
/**
 * The only place in this plugin that decides whether a delivery is tried again.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Transport;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\DeliveryOutcome;
use Factuarea\WooCommerce\Contract\HttpResponse;

/**
 * Turns one HTTP response into one delivery outcome.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE ONLY PIECE THAT DECIDES WHETHER A DELIVERY IS RETRIED
 * ---------------------------------------------------------------------------
 * Not the HTTP client, which reports what happened and judges nothing. Not the
 * dispatcher, which acts on the outcome. Not the outbox, which stores it. Here,
 * and nowhere else.
 *
 * The reason is that the two possible mistakes are not symmetrical and both are
 * expensive:
 *
 *  - Retrying something permanent burns a finite retry queue against a response
 *    that will never change.
 *  - Giving up on something transient LOSES A PAID ORDER for good, and the
 *    merchant finds out weeks later when the tax return does not add up.
 *
 * A second place allowed to make this call would disagree with this one the day
 * a response arrives that neither anticipated, and the disagreement would be
 * silent. So the decision has one home, and this file is it.
 *
 * ---------------------------------------------------------------------------
 * IF THE RECEIVER EVER PUBLISHES AN EXPLICIT CLASS HEADER, PREFER IT
 * ---------------------------------------------------------------------------
 * Today the receiver publishes the class of a rejection in two places at once
 * (see the measurement below) and neither of them is a header that says
 * "permanent" or "retryable" in so many words. If it ever publishes one, that
 * header becomes the primary signal and the rules below become its fallback -
 * and THAT CHANGE HAPPENS IN THIS FILE AND IN NO OTHER. Nothing outside reads a
 * response: the client hands over an HttpResponse, this class hands back a
 * DeliveryOutcome, and the four cases of that outcome are the whole vocabulary
 * the rest of the plugin has for the question.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE RECEIVER ACTUALLY WRITES - MEASURED 2026-09-02
 * ---------------------------------------------------------------------------
 * Read off the receiver's source in the monorepo rather than off a document,
 * because the rules below are a reading of a contract and reading it wrong is
 * how a sale is lost:
 *
 *  - `backend/app/Integration/Infrastructure/Http/Response/StoreIngestionRejectionResponder.php`
 *    is the ONLY producer of a rejection. Every rejection it puts on the wire
 *    carries a JSON body with FOUR fields - the code, the merchant message, a
 *    boolean class and the wait in seconds - and it sets the `Retry-After`
 *    header if and only if the rejection is retryable AND declares a wait.
 *  - The header value is written as `(string) $retryAfter`, so it is ALWAYS an
 *    integer number of seconds. The receiver never sends the HTTP-date form
 *    that the HTTP specification also allows, so this class parses seconds and
 *    only seconds. An unparseable header is treated as "no usable wait here"
 *    and the body is consulted, never as a reason to give up.
 *  - `StoreIngestionRejectionCode` declares the invariant that there is a wait
 *    if and only if the code is retryable, so the header and the body field
 *    agree by construction at the source.
 *  - Its status codes are 400, 401, 404, 422, 429 and 503. NONE is a 2xx.
 *
 * ---------------------------------------------------------------------------
 * PRECEDENCE BETWEEN THE HEADER AND THE BODY, AND WHY IT IS NOT THE HEADER ALONE
 * ---------------------------------------------------------------------------
 * The header is preferred: when it carries a usable number, that number is the
 * wait, full stop. The body's own class field is a FALLBACK that can only ever
 * ADD a retry, never cancel one.
 *
 * That asymmetry is deliberate and it is the whole point:
 *
 *  - The header can be dropped, rewritten or synthesised by anything between
 *    the two ends - a reverse proxy, a CDN, a corporate appliance in front of
 *    the shop. The body cannot: it is what the receiver wrote, end to end.
 *  - The receiver's own source says the class travels in the body ON PURPOSE
 *    and calls it the requirement, precisely so that a plugin distributed
 *    before a code existed can still classify it.
 *  - So if the two ever disagree, one direction costs wasted attempts and the
 *    other loses a paid order. Reading a wait from the body when the header did
 *    not survive the trip is the cheap mistake; declaring a rejection permanent
 *    because a proxy ate its header is the expensive one.
 *
 * A rejection that the receiver marks retryable while declaring NO usable wait
 * cannot be built as a retryable rejection - that outcome requires a number -
 * and it is not treated as permanent either. It comes back as a transient
 * failure, which retries on the plugin's own backoff. The receiver's invariant
 * says this combination cannot happen; the branch exists so that the permanent
 * one can never absorb it if it ever does.
 *
 * ---------------------------------------------------------------------------
 * NO LIST OF KNOWN CODES LIVES HERE, AND THAT IS THE FEATURE
 * ---------------------------------------------------------------------------
 * This plugin is DISTRIBUTED. A merchant runs whichever version they last
 * updated to, and an installation from eight months ago cannot be handed a new
 * table of codes. A `match` over known codes would send every code born after
 * the release into whatever branch happens to be last - and in this file the
 * last branch decides between retrying a sale and losing it.
 *
 * So a rejection is recognised by its SHAPE - a readable rejection code in the
 * body - and classified by what the response DECLARES about waiting, never by
 * the code itself and never by the status code on its own. A code invented
 * tomorrow classifies correctly today. `RejectionClassifierTest` sweeps this
 * file for the published code literals to keep it that way.
 */
final class RejectionClassifier {

	/**
	 * What happened to this delivery.
	 *
	 * The order of the rules is part of the contract:
	 *
	 *  1. Nothing came back at all -> transient.
	 *  2. A 2xx -> accepted, whatever the body says.
	 *  3. Not a rejection of the ingestion contract -> transient. This is the
	 *     rule that keeps a proxy's 502 from being read as a refusal.
	 *  4. A rejection of the contract that declares a wait -> retryable, with
	 *     that wait.
	 *  5. A rejection of the contract that declares none -> permanent, even
	 *     when its code is one this plugin has never heard of.
	 *
	 * @param HttpResponse $response The response as the HTTP client reported it.
	 * @return DeliveryOutcome
	 */
	public function classify( HttpResponse $response ): DeliveryOutcome {
		if ( $response->is_transport_failure() ) {
			return DeliveryOutcome::transient_failure(
				null,
				sprintf(
					'Factuarea could not be reached: %s. The order is kept and the delivery is tried again.',
					(string) $response->transport_error
				)
			);
		}

		$status_code = (int) $response->status_code;

		// A 2xx is an acceptance and nothing else. The receiver's only producer
		// of rejections answers 400, 401, 404, 422, 429 or 503 - never a 2xx -
		// and its two "discarded without effects" paths answer 200 with an EMPTY
		// body. So a 2xx that happened to carry a field named like a rejection
		// code did not come from there, and reading it as a refusal would either
		// drop an order the receiver already took or send it a second time.
		if ( 200 <= $status_code && 300 > $status_code ) {
			return DeliveryOutcome::accepted( $status_code );
		}

		$body           = $response->decoded_body() ?? array();
		$rejection_code = $this->rejection_code( $body );

		// Not a rejection of the contract: a gateway error, an HTML error page,
		// a body that is not JSON, a body that is JSON but says nothing this
		// contract publishes. It is retried, and this is THE rule that stops a
		// 502 from a proxy - which carries no wait header and refuses nothing -
		// from closing a paid order for good.
		if ( null === $rejection_code ) {
			return DeliveryOutcome::transient_failure(
				$status_code,
				sprintf(
					'Factuarea answered %d without a rejection of its ingestion contract. The order is kept and the delivery is tried again.',
					$status_code
				)
			);
		}

		$merchant_message = $this->merchant_message( $body, $rejection_code, $status_code );
		$declared_wait    = $this->declared_wait( $response, $body );

		if ( null !== $declared_wait ) {
			return DeliveryOutcome::retryable_rejection( $status_code, $rejection_code, $declared_wait, $merchant_message );
		}

		// The receiver called it retryable and gave no usable number. Its own
		// invariant says this cannot happen; if it ever does, the delivery is
		// retried on this plugin's backoff rather than closed. See the note on
		// precedence above.
		if ( true === ( $body[ Constants::REJECTION_BODY_RETRYABLE_FIELD ] ?? null ) ) {
			return DeliveryOutcome::transient_failure( $status_code, $merchant_message );
		}

		return DeliveryOutcome::permanent_rejection( $status_code, $rejection_code, $merchant_message );
	}

	/**
	 * The rejection code of the contract, or null when this response is not a
	 * rejection of it.
	 *
	 * The code has to be a non-empty STRING. That is not pedantry: an appliance
	 * in front of the shop that answers `{"code": 403, "message": "denied"}` is
	 * describing itself, not this contract, and every code this contract
	 * publishes is a string. Reading that integer as a rejection would classify
	 * an appliance's refusal as the receiver's own.
	 *
	 * @param array<string, mixed> $body Decoded response body.
	 * @return string|null
	 */
	private function rejection_code( array $body ): ?string {
		$code = $body[ Constants::REJECTION_BODY_CODE_FIELD ] ?? null;

		if ( ! is_string( $code ) ) {
			return null;
		}

		$code = trim( $code );

		return '' === $code ? null : $code;
	}

	/**
	 * What the merchant is shown about this rejection.
	 *
	 * The receiver's own message is relayed VERBATIM when it sent one, because
	 * it is the only text that knows what this particular rejection needs, and
	 * by the time the merchant reads it the response is long gone. It is data
	 * received over the wire, not a string of this plugin: the English-only rule
	 * of this package governs what this package WRITES.
	 *
	 * @param array<string, mixed> $body           Decoded response body.
	 * @param string               $rejection_code Code already established as readable.
	 * @param int                  $status_code    Status code received.
	 * @return string
	 */
	private function merchant_message( array $body, string $rejection_code, int $status_code ): string {
		$message = $body[ Constants::REJECTION_BODY_MESSAGE_FIELD ] ?? null;

		if ( is_string( $message ) && '' !== trim( $message ) ) {
			return trim( $message );
		}

		return sprintf(
			'Factuarea rejected the delivery with code "%s" and status %d, and sent no message. Open the store integration in Factuarea to see what it needs.',
			$rejection_code,
			$status_code
		);
	}

	/**
	 * Seconds the receiver declared to wait, or null when it declared none.
	 *
	 * The header first, the body second, and the body only ever adds. See the
	 * note on precedence at the top of this file for why that direction and not
	 * the other one.
	 *
	 * @param HttpResponse         $response The response as received.
	 * @param array<string, mixed> $body     Decoded response body.
	 * @return int|null
	 */
	private function declared_wait( HttpResponse $response, array $body ): ?int {
		$from_header = $this->declared_seconds( $response->header( Constants::RETRY_AFTER_HEADER ) );

		if ( null !== $from_header ) {
			return $from_header;
		}

		if ( true !== ( $body[ Constants::REJECTION_BODY_RETRYABLE_FIELD ] ?? null ) ) {
			return null;
		}

		return $this->declared_seconds( $body[ Constants::REJECTION_BODY_RETRY_AFTER_FIELD ] ?? null );
	}

	/**
	 * A declared wait in whole seconds, or null when the value does not express
	 * one.
	 *
	 * Seconds only, because the receiver writes seconds only - measured, see the
	 * top of this file. Anything else, including the HTTP-date form and a
	 * negative number, is "no usable wait here", which sends the reading on to
	 * the next source instead of ending it.
	 *
	 * Zero counts as a declared wait. It means "come back now", which is a
	 * perfectly good instruction; treating it as an absence would turn it into a
	 * permanent rejection, and that is the direction that loses orders.
	 *
	 * @param mixed $value Header value or body field.
	 * @return int|null
	 */
	private function declared_seconds( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 <= $value ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( 1 !== preg_match( '/^\d+$/', $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
