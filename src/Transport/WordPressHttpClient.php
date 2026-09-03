<?php
/**
 * Every outgoing request of this plugin, made through the WordPress HTTP API.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Transport;

use Factuarea\WooCommerce\Contract\HttpResponse;

/**
 * The only place in this plugin that talks to the network.
 *
 * It does exactly two things: it sends what it is given through the WordPress
 * HTTP API, and it reports what came back in the one uniform shape of
 * HttpResponse. It decides nothing about the answer.
 *
 * ---------------------------------------------------------------------------
 * WHY NO SDK AND NO THIRD-PARTY HTTP CLIENT IS BUNDLED
 * ---------------------------------------------------------------------------
 * This plugin ships no HTTP library, no PSR-18 client and no Factuarea SDK,
 * even though the sibling PHP SDK exists and would be more comfortable to call.
 * There are two reasons and neither is taste.
 *
 * The first is a collision that has no fix once it happens. Every plugin
 * installed in a WordPress site runs inside ONE PHP process and shares ONE
 * class namespace. Two plugins that each bundle their own copy of the same
 * library - a different major version of Guzzle, of a PSR-7 implementation, of
 * the SDK itself - do not get one copy each: the autoloader that ran first wins,
 * and the second plugin silently executes against a version it was never tested
 * against. The symptom lands on the merchant, in a shop neither author can see,
 * as a fatal error or as a method that quietly behaves differently. Prefixing a
 * bundled library moves the problem rather than removing it, and it doubles the
 * bytes shipped to fix a problem the platform already solved.
 *
 * The second is that wordpress.org's plugin review expects outgoing requests to
 * go through this API rather than through curl or fopen directly, precisely so
 * that a site owner's proxy settings, blocked-host list, request filters and
 * offline mode keep applying to every plugin. Going around it makes this plugin
 * the one that ignores the merchant's own configuration.
 *
 * What the WordPress HTTP API costs us is control over the transport, and the
 * answer is that we do not want any: a shop behind a corporate proxy, a host
 * that forces requests through a gateway, a site with an outgoing-request
 * firewall - all of those are settings the merchant made, and all of them keep
 * working here for free.
 *
 * ---------------------------------------------------------------------------
 * THIS CLASS REPORTS. IT DOES NOT JUDGE
 * ---------------------------------------------------------------------------
 * Nothing here looks at a status code, at a body or at an error message to
 * decide what any of it means. Whether a delivery is to be retried, retried
 * after a declared wait, or never sent again is one decision that lives in one
 * place, the rejection classifier, and this class exists partly to keep it
 * there.
 *
 * The failure that rule prevents is specific and expensive. A proxy in front of
 * the receiver answers 502 with an HTML error page: no rejection code, no wait
 * header. If this class were allowed a shortcut - "5xx without a body is
 * hopeless, drop it" - that paid order would never be delivered and never be
 * invoiced, and nothing would say so. Reported as it came off the wire, the
 * classifier sees a response that is not a rejection of the contract, calls it
 * transient, and the order goes out on the next drain.
 *
 * The same reasoning is why neither method ever throws. Every failure - no
 * route to the host, a dropped connection, a timeout, a certificate that did
 * not verify, an answer with no status code at all - comes back as an
 * HttpResponse carrying a transport error. An exception here would have to be
 * caught by every caller, and the one caller that forgot would turn "the
 * network blinked" into a fatal error inside a scheduled drain, taking every
 * other pending entry of that run with it.
 */
final class WordPressHttpClient {

	/**
	 * Seconds to wait for a request to complete, connection included.
	 *
	 * DECLARED HERE rather than in the frozen constants, and said out loud
	 * because the reader deserves to know it was a decision: the frozen
	 * contract of this package names no timeout, so this is the transport's own
	 * and nothing outside this class agrees on it.
	 *
	 * Fifteen seconds is a bound with two sides. WordPress defaults to five,
	 * which is short enough that an ordinary receiver under load looks like a
	 * broken one, and every one of those false timeouts costs a retry cycle
	 * whose first wait is a minute. Going much higher is worse in a different
	 * way: the invoice download and the connection test are made while a person
	 * is looking at a screen, and PHP's own execution limit is often thirty
	 * seconds, so a request allowed to run longer than half of it can end the
	 * whole page instead of returning a diagnosis.
	 *
	 * It is bounded on purpose in both directions: a request with no timeout on
	 * the delivery path holds a scheduled drain open for as long as a silent
	 * host keeps the socket alive, and that drain is what the rest of the
	 * merchant's queue is waiting for.
	 */
	public const REQUEST_TIMEOUT_SECONDS = 15;

	/**
	 * Content type applied to a POST when the caller named none.
	 *
	 * The body this plugin posts is JSON, and WordPress adds no content type of
	 * its own for a string body, so without this the receiver would be handed a
	 * JSON document with nothing saying so. It is a default and not a policy:
	 * a caller that sets its own content type keeps it, because the caller is
	 * the one that knows what it built.
	 */
	private const DEFAULT_POST_CONTENT_TYPE = 'application/json; charset=utf-8';

	/**
	 * Sends a body to an address and reports what came back.
	 *
	 * The body goes out as the exact string that is passed in. That is not an
	 * implementation detail: it is the string the signature covers, and a
	 * transport that re-encoded it - re-ordering keys, changing escaping, or
	 * form-encoding an array - would produce a delivery whose signature no
	 * longer matches its bytes, which the receiver rejects as forged with no
	 * error visible on this side.
	 *
	 * @param string                $url      Address to post to.
	 * @param array<string, string> $headers  Headers to send, as name => value.
	 * @param string                $raw_body Body to send, byte for byte.
	 * @return HttpResponse Always. This method never throws.
	 */
	public function post( string $url, array $headers, string $raw_body ): HttpResponse {
		$response = wp_remote_post(
			$url,
			$this->request_arguments(
				$this->with_default_content_type( $headers ),
				$raw_body
			)
		);

		return $this->to_http_response( $response );
	}

	/**
	 * Reads from an address and reports what came back.
	 *
	 * No content type and no accept header are added here. The two reads this
	 * plugin makes want different documents - the connection test wants JSON,
	 * the invoice download wants a PDF - so declaring one for both would make
	 * the transport lie about one of them.
	 *
	 * @param string                $url     Address to read.
	 * @param array<string, string> $headers Headers to send, as name => value.
	 * @return HttpResponse Always. This method never throws.
	 */
	public function get( string $url, array $headers ): HttpResponse {
		$response = wp_remote_get( $url, $this->request_arguments( $headers, null ) );

		return $this->to_http_response( $response );
	}

	/**
	 * The arguments every request of this plugin is made with.
	 *
	 * @param array<string, string> $headers  Headers to send.
	 * @param string|null           $raw_body Body to send, or null for a read.
	 * @return array<string, mixed>
	 */
	private function request_arguments( array $headers, ?string $raw_body ): array {
		$arguments = array(
			'timeout'     => self::REQUEST_TIMEOUT_SECONDS,

			/*
			 * NO REDIRECTS ARE FOLLOWED. Not "only within the same host" -
			 * none, because the WordPress HTTP API has no way to express the
			 * former: `redirection` is a hop count, and once it is above zero
			 * the next hop is whatever the Location header names.
			 *
			 * Following one would re-send, to an address this plugin never
			 * checked, a body carrying the buyer's tax identifier together with
			 * the signature made with the merchant's secret - and, on a read,
			 * the API key in its authorisation header. A redirect is set by
			 * whoever controls the answering host, which after a hijacked
			 * domain or a misconfigured proxy is not necessarily the merchant.
			 *
			 * A receiver that redirects therefore comes back here as an ordinary
			 * 3xx response with no contract body, which the classifier reads as
			 * transient and the connection test reports as an unexpected answer.
			 * Both are honest, and both are visible; silently delivering
			 * elsewhere would be neither.
			 */
			'redirection' => 0,

			/*
			 * TLS certificates are verified. Stated rather than left to the
			 * default so that switching it off is an edit somebody has to make
			 * and defend: what travels here is a personal tax identifier and a
			 * credential.
			 */
			'sslverify'   => true,

			/*
			 * The answer is waited for. A non-blocking request cannot report a
			 * status code, so every delivery would settle as "sent, outcome
			 * unknown" and the outbox would have nothing to decide with.
			 */
			'blocking'    => true,

			'headers'     => $headers,
		);

		if ( null !== $raw_body ) {
			$arguments['body'] = $raw_body;

			/*
			 * Send the body as it is. With `data_format` left alone, an array
			 * body would be form-encoded; declaring it here says, at the one
			 * place it can be read, that the bytes leaving this process are the
			 * bytes that were signed.
			 */
			$arguments['data_format'] = 'body';
		}

		return $arguments;
	}

	/**
	 * Adds the JSON content type unless the caller already named one.
	 *
	 * The lookup ignores capitalisation because header names are
	 * case-insensitive and a caller that wrote `content-type` means the same
	 * thing as one that wrote `Content-Type`. Adding a second one would send
	 * two content types in the same request, and which of them the receiver
	 * honours is not something this plugin gets to choose.
	 *
	 * @param array<string, string> $headers Headers as the caller passed them.
	 * @return array<string, string>
	 */
	private function with_default_content_type( array $headers ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'content-type' === strtolower( (string) $name ) ) {
				return $headers;
			}
		}

		$headers['Content-Type'] = self::DEFAULT_POST_CONTENT_TYPE;

		return $headers;
	}

	/**
	 * Turns whatever the WordPress HTTP API returned into the one uniform shape.
	 *
	 * @param mixed $response What wp_remote_post or wp_remote_get returned.
	 * @return HttpResponse
	 */
	private function to_http_response( $response ): HttpResponse {
		if ( is_wp_error( $response ) ) {
			return HttpResponse::transport_failure( $this->error_message( $response ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		/*
		 * A response with no readable status code is not a response, and it is
		 * reachable: any plugin may short-circuit an outgoing request through
		 * the `pre_http_request` filter and return something that is neither a
		 * response array nor an error object. Reporting it as a transport
		 * failure keeps the promise this class makes to the classifier - a
		 * status code, when there is one, is the one that came off the wire -
		 * whereas casting the empty string to zero would hand the classifier a
		 * status 0 to interpret, and interpreting is not its job either.
		 */
		if ( ! is_int( $status_code ) && ! ( is_string( $status_code ) && '' !== $status_code && ctype_digit( $status_code ) ) ) {
			return HttpResponse::transport_failure(
				'the request produced no readable status code, which usually means another plugin on this site is intercepting outgoing requests'
			);
		}

		return HttpResponse::received(
			(int) $status_code,
			$this->to_header_array( wp_remote_retrieve_headers( $response ) ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * The message of a WordPress error object, without trusting its shape.
	 *
	 * @param mixed $error The object is_wp_error() recognised.
	 * @return string
	 */
	private function error_message( $error ): string {
		$message = is_object( $error ) && method_exists( $error, 'get_error_message' )
			? $error->get_error_message()
			: '';

		$message = is_string( $message ) ? trim( $message ) : '';

		return '' === $message ? 'the request did not complete, and no reason was given' : $message;
	}

	/**
	 * Response headers as plain lower-cased strings.
	 *
	 * WordPress hands these back as a case-insensitive dictionary object on a
	 * real request and as a plain array when something short-circuited it, so
	 * both are unpacked here rather than at each reader. Repeated headers arrive
	 * as a list of values and are folded with a comma, which is how HTTP itself
	 * says a repeated header combines - the alternative, keeping only one, would
	 * silently drop the others.
	 *
	 * @param mixed $headers Whatever wp_remote_retrieve_headers() returned.
	 * @return array<string, string>
	 */
	private function to_header_array( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		$flattened = array();

		foreach ( $headers as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( array( $this, 'to_header_value' ), $value ) );
			} else {
				$value = $this->to_header_value( $value );
			}

			$flattened[ strtolower( (string) $name ) ] = $value;
		}

		return $flattened;
	}

	/**
	 * One header value as a string, and an empty string for anything that has
	 * no string form: a header nobody can read is reported as present and
	 * empty rather than crashing the drain that was reading it.
	 *
	 * @param mixed $value Header value as received.
	 * @return string
	 */
	private function to_header_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return '';
	}
}
