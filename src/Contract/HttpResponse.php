<?php
/**
 * The uniform shape of an HTTP response inside this plugin.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * One shape for every outcome of an outgoing request: the ones that came back
 * with a status code and the ones that never reached anybody.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL
 * ---------------------------------------------------------------------------
 * The HTTP client produces it and the rejection classifier consumes it, and
 * neither knows about the other. That separation is not tidiness: the moment
 * the client starts deciding what a failure MEANS, a proxy's 502 - which
 * carries no contract body and no wait header - starts looking like a rejection
 * that must not be retried, and a paid order is lost for good. So the client
 * reports, in one shape, and only the classifier judges.
 *
 * A transport failure is a first-class case here rather than an exception, for
 * the same reason: an exception would have to be caught by the caller, and a
 * caller that forgets to catch it turns "the network blinked" into a fatal error
 * inside a scheduled drain.
 *
 * All values are as they came off the wire. Nothing here decodes, re-encodes or
 * normalises the body: the raw string is what was signed and what the receiver
 * verified.
 */
final class HttpResponse {

	/**
	 * Headers, keyed by their LOWER-CASE name, so that a lookup does not depend
	 * on how a particular server capitalised them.
	 *
	 * @var array<string, string>
	 */
	public readonly array $headers;

	/**
	 * Use the two named constructors instead.
	 *
	 * @param int|null              $status_code     Status code, or null when nothing came back.
	 * @param array<string, string> $headers         Response headers as received.
	 * @param string                $raw_body        Body exactly as received.
	 * @param string|null           $transport_error Reason the request never completed, or null.
	 */
	private function __construct(
		public readonly ?int $status_code,
		array $headers,
		public readonly string $raw_body,
		public readonly ?string $transport_error,
	) {
		$lowercased = array();

		foreach ( $headers as $name => $value ) {
			$lowercased[ strtolower( (string) $name ) ] = (string) $value;
		}

		$this->headers = $lowercased;
	}

	/**
	 * A response that came back from the receiver, whatever its status code.
	 *
	 * @param int                   $status_code Status code as received.
	 * @param array<string, string> $headers     Response headers as received.
	 * @param string                $raw_body    Body exactly as received.
	 * @return self
	 */
	public static function received( int $status_code, array $headers, string $raw_body ): self {
		return new self( $status_code, $headers, $raw_body, null );
	}

	/**
	 * A request that never produced a response: no route to the host, a
	 * connection that dropped, a timeout, a certificate that did not verify.
	 *
	 * The reason is kept for the merchant to read, and whoever writes it is
	 * responsible for it carrying no credential.
	 *
	 * @param string $reason Why the request did not complete.
	 * @return self
	 */
	public static function transport_failure( string $reason ): self {
		return new self( null, array(), '', $reason );
	}

	/**
	 * Did the request fail before any response existed?
	 *
	 * @return bool
	 */
	public function is_transport_failure(): bool {
		return null !== $this->transport_error;
	}

	/**
	 * One header, looked up without regard to case.
	 *
	 * @param string $name Header name in any capitalisation.
	 * @return string|null The value, or null when the header is absent.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Was the header present at all?
	 *
	 * Presence is asked separately from value because the classifier's rule
	 * turns on presence: a rejection of the contract WITH a wait header is
	 * retryable and the same rejection WITHOUT one is permanent, so "absent"
	 * and "present but empty" are not the same answer.
	 *
	 * @param string $name Header name in any capitalisation.
	 * @return bool
	 */
	public function has_header( string $name ): bool {
		return array_key_exists( strtolower( $name ), $this->headers );
	}

	/**
	 * The body decoded as a JSON object, or null when it is not one.
	 *
	 * Null means "unreadable", and unreadable is a real answer that the
	 * classifier acts on: a body it cannot read is not a rejection of the
	 * contract, so the delivery is transient and gets retried rather than
	 * dropped.
	 *
	 * @return array<string, mixed>|null
	 */
	public function decoded_body(): ?array {
		if ( '' === $this->raw_body ) {
			return null;
		}

		$decoded = json_decode( $this->raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
