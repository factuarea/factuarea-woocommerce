<?php
/**
 * Verification of the signature of an INCOMING delivery.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Inbound;

use Factuarea\WooCommerce\Transport\DeliverySigner;

/**
 * The only place in this plugin that decides whether an inbound delivery is
 * authenticated.
 *
 * ---------------------------------------------------------------------------
 * IT DOES NOT COMPUTE THE HMAC. IT ASKS THE SIGNER THAT ALREADY EXISTS
 * ---------------------------------------------------------------------------
 * `Transport\DeliverySigner::sign()` computes `base64_encode( hash_hmac(
 * 'sha256', $raw_body, $secret, true ) )` for the deliveries this plugin SENDS,
 * and the incoming lane is signed with that exact scheme. So the expected value
 * is obtained by calling it, not by writing `hash_hmac` a second time in this
 * repository.
 *
 * The reason is the fourth argument. Without `true`, `hash_hmac` returns the
 * digest in HEXADECIMAL, and the base64 of that hexadecimal string is a
 * perfectly well-formed value that never matches. Nothing throws, nothing warns,
 * no static analysis sees it: the sender emits, this receiver answers 401 to
 * every attempt of every notice, and the merchant reads "your shop rejects our
 * signature" while the secret is correct. One place to get that argument wrong
 * is a bug; two places is a bug plus a reason to disagree about which of them is
 * right.
 *
 * ---------------------------------------------------------------------------
 * THE THREE WAYS OF FAILING LEAVE THROUGH THE SAME RETURN
 * ---------------------------------------------------------------------------
 * No header, a signature that does not correspond, and a shop with no secret
 * configured all answer `false`, and this class offers no way to tell which one
 * happened. It is the same branch that `MyAccount\InvoiceCorrelationEndpoint`
 * already writes, for the same reason: distinguishing them is free information
 * for whoever is probing the route and worth nothing to a legitimate sender,
 * which either holds the secret or does not.
 *
 * The empty secret is checked FIRST, and that order is mechanical rather than
 * stylistic: `DeliverySigner::sign()` REFUSES an empty secret by throwing, so
 * that no outgoing delivery is ever signed with nothing. Reaching it with an
 * empty secret from here would turn a misconfigured shop - a merchant who has
 * not pasted the secret yet - into an uncaught exception inside a REST callback,
 * which WordPress renders as a 500, which the sender classifies as RETRYABLE,
 * which burns the whole retry budget on a setting nobody was told to fill in.
 * The answer for an unconfigured shop is the same 401 as a wrong signature, and
 * it is permanent, which is what makes the sender stop and surface it.
 *
 * ---------------------------------------------------------------------------
 * THE COMPARISON IS hash_equals, AND NOT ===
 * ---------------------------------------------------------------------------
 * `===` on strings returns as soon as it finds a differing byte, so how long the
 * comparison takes depends on how many leading bytes were right. Measured over
 * enough probes, that difference reconstructs the expected signature one byte at
 * a time without ever knowing the secret. `hash_equals` compares the whole
 * length regardless, which is the entire reason it exists in PHP.
 *
 * ---------------------------------------------------------------------------
 * IT WRITES NOTHING AND SAYS NOTHING
 * ---------------------------------------------------------------------------
 * No log line, no admin notice, no exception message, no output. A rejected
 * delivery must not leave a trace that carries the secret, the expected
 * signature or the presented one: exception messages and debug logs end up in
 * support tickets, and a log line holding the expected signature of a known body
 * hands over the secret's effect for that body. There is also nothing to record:
 * the receiver's own response already tells the sender everything the contract
 * promises it.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS VERIFIED IS THE BODY AS IT ARRIVED
 * ---------------------------------------------------------------------------
 * The caller hands over `WP_REST_Request::get_body()` untouched. This class
 * never decodes it, never normalises it and never re-encodes it:
 * `json_encode( json_decode( $body ) )` gives back an equivalent document with
 * DIFFERENT bytes - key order, spacing, the escaping of `/` and of non-ASCII
 * characters, the notation of numbers - and hashing those bytes would reject
 * every legitimate sender with an inexplicable 401.
 */
final class InboundSignatureVerifier {

	/**
	 * Computes the expected signature of an incoming body.
	 *
	 * @var DeliverySigner|null
	 */
	private ?DeliverySigner $signer;

	/**
	 * Constructor.
	 *
	 * @param DeliverySigner|null $signer Signer used to compute the expected
	 *                                    signature; built on first use when not
	 *                                    given.
	 */
	public function __construct( ?DeliverySigner $signer = null ) {
		$this->signer = $signer;
	}

	/**
	 * Whether the presented signature is the one this shop's secret produces
	 * over these exact bytes.
	 *
	 * Answers, never throws. A caller that has to decide between applying an
	 * effect and refusing it gets a boolean and no third outcome to forget.
	 *
	 * @param string $raw_body  Bytes of the request body exactly as they arrived. Never decoded, normalised or re-encoded here.
	 * @param string $presented Value of the inbound signature header, an empty string when the header is absent.
	 * @param string $secret    Signing secret of this shop, an empty string when it is not configured yet.
	 *
	 * @return bool True only when the signature corresponds. False for an absent header, a signature that does not correspond and a shop with no secret, without distinguishing them.
	 */
	public function verify( string $raw_body, string $presented, string $secret ): bool {
		if ( '' === $secret || '' === $presented ) {
			return false;
		}

		return hash_equals( $this->signer()->sign( $raw_body, $secret ), $presented );
	}

	/**
	 * The signer, built on first use.
	 *
	 * @return DeliverySigner
	 */
	private function signer(): DeliverySigner {
		if ( null === $this->signer ) {
			$this->signer = new DeliverySigner();
		}

		return $this->signer;
	}
}
