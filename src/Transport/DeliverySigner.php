<?php
/**
 * Signature of an outgoing delivery.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Transport;

use Factuarea\WooCommerce\Exception\CredentialsNotConfiguredException;

/**
 * The signature that travels in `X-WC-Webhook-Signature`: the base64 encoding
 * of the HMAC-SHA256 digest of the RAW body, keyed with this store's secret.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS SIGNED IS THE EXACT STRING THAT IS SENT
 * ---------------------------------------------------------------------------
 * The material of the signature is the byte sequence that goes out as the
 * request body, character for character. Not an array, not a document, not "the
 * same JSON": the bytes.
 *
 * RE-SERIALISING THE BODY AFTER SIGNING BREAKS THE SIGNATURE AT THE RECEIVER
 * AND PRODUCES NO LOCAL ERROR WHATSOEVER. `json_encode( json_decode( $body ) )`
 * gives back an equivalent JSON document with DIFFERENT bytes - key order,
 * spacing, the escaping of `/` and of non-ASCII characters, the notation of
 * numbers - so the HMAC would cover a string that is not the one the receiver
 * hashes. Nothing throws, nothing warns, nothing looks wrong in the outbox: the
 * delivery simply comes back 401, every time, on every retry, and the symptom
 * is an inexplicable rejection of perfectly legitimate sales.
 *
 * This is why the builder of the body hands back a raw JSON string rather than
 * an array (see the frozen signature of `OrderPayloadBuilder::build`), and why
 * that same string must travel untouched from the builder through this signer
 * into the HTTP client. Anything in between that decodes it "to check something"
 * and re-encodes it has broken the delivery.
 *
 * The receiver is
 * `backend/app/Integration/Infrastructure/Provider/Adapters/WooCommerce/WooCommerceWebhookVerifier.php`
 * in the monorepo, and it computes exactly `base64_encode( hash_hmac( 'sha256',
 * $raw_payload, $secret, true ) )` over the bytes it received, comparing with
 * `hash_equals`. The vectors in `tests/fixtures/signature-vectors.json` are the
 * SAME values that verifier's own test asserts, so the two ends of the round
 * trip are anchored to one shared number instead of to each other.
 *
 * ---------------------------------------------------------------------------
 * THE FOURTH ARGUMENT OF hash_hmac IS NOT OPTIONAL HERE
 * ---------------------------------------------------------------------------
 * Without `true`, `hash_hmac` returns the digest in HEXADECIMAL, and the base64
 * of that hexadecimal string is a perfectly well-formed value that is not the
 * signature WooCommerce sends and that the receiver will never accept. It is
 * the one mistake in this file that still produces a plausible-looking string.
 *
 * ---------------------------------------------------------------------------
 * NO LIBRARY
 * ---------------------------------------------------------------------------
 * `hash_hmac` and `base64_encode` are PHP built-ins, always available, with no
 * extension to require. A plugin distributed through the public directory shares
 * a single PHP process with every other plugin the merchant has installed, so
 * bundling a crypto helper here would be a version collision waiting for the
 * first merchant who installs two plugins that bundle different ones.
 */
final class DeliverySigner {

	/**
	 * Human-readable name of the setting the secret comes from, for the message
	 * of the exception below. It names the SETTING and never its value.
	 */
	private const SECRET_SETTING_LABEL = 'the signing secret';

	/**
	 * Signs the raw body with this store's secret.
	 *
	 * @param string $raw_body Exact bytes that will be sent as the request body. Signed as given; never decoded, normalised or re-encoded.
	 * @param string $secret   Signing secret of this store, as the merchant pasted it into Factuarea.
	 *
	 * @return string Base64 encoding of the HMAC-SHA256 digest of $raw_body, ready for the `X-WC-Webhook-Signature` header.
	 *
	 * @throws CredentialsNotConfiguredException When the signing secret is empty.
	 */
	public function sign( string $raw_body, string $secret ): string {
		/*
		 * An empty secret is refused instead of signed, and the reason is that
		 * signing with it succeeds. `hash_hmac` with an empty key returns a
		 * perfectly computable digest, so the delivery would go out carrying a
		 * signature the receiver cannot match, come back 401, and be classified
		 * as transient - because a 401 is not a rejection of the ingestion
		 * contract - and then burn the entire retry budget on a misconfiguration
		 * whose cure is one field on the settings screen. Refusing here turns
		 * that silence into a message that names the field.
		 */
		if ( '' === $secret ) {
			/*
			 * The message is composed from a constant of this file and nothing
			 * else: no user input reaches it, and it is read in a log line or an
			 * admin notice rather than printed into a page. Escaping it would put
			 * HTML entities into that log line and make it harder to read without
			 * making anything safer.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Constant message, never rendered; see above.
			throw CredentialsNotConfiguredException::missing( self::SECRET_SETTING_LABEL );
		}

		/*
		 * Base64 here is not obfuscation, which is what the sniff below is
		 * looking for: it is the encoding the delivery signature is specified
		 * in. The receiver decodes nothing else, and the alternative - the
		 * hexadecimal digest - is precisely the mistake this file warns about.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signature encoding, not obfuscation; see above.
		return base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) );
	}
}
