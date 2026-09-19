<?php
/**
 * Plugin Name: Factuarea E2E mock receiver
 * Description: Stands in for Factuarea inside the containerised WordPress - for the ingestion route it offers the shop, so the scenarios can look at what the plugin actually sent and can dictate what comes back, and for the SENDER that publishes signed inbound notices at the shop, so the two receivers can be exercised without leaving the environment. Never published: it lives under tests/ and .distignore excludes the whole directory.
 * Version: 0.2.0
 * License: GPL-2.0-or-later
 *
 * WHY THE RECEIVER IS INSIDE THE SITE
 *
 * The plugin POSTs a delivery to `{base address}/api/webhooks/stores/woocommerce`.
 * Pointing that address at anything outside the container would make the suite
 * depend on a network it cannot control, so the address points at this very
 * WordPress and this file answers that path. Nothing leaves the machine.
 *
 * WHY THE PATH IS INTERCEPTED RATHER THAN REWRITTEN
 *
 * `/api/webhooks/stores/woocommerce` is not a WordPress REST path and no rewrite
 * rule can be relied on before permalinks have been flushed. So the surface is a
 * genuine REST route - `factuarea-mock/v1/ingest`, registered like any other -
 * and a dispatcher on `init` hands it the request that arrived on the ingestion
 * path and emits its answer verbatim. One implementation, reachable two ways:
 * the route can be exercised directly at /wp-json/, and the plugin under test
 * reaches exactly the same code through the address it was configured with.
 *
 * WHY THE ANSWER IS EMITTED BY HAND
 *
 * The scenarios need answers the REST server would not produce: a 200 with a
 * genuinely EMPTY body (which is what the real receiver sends when it discards
 * an event), a 502 with no body at all, and a rejection document with a
 * `Retry-After` header present or absent. Those are bytes, so the dispatcher
 * writes the bytes.
 */

declare( strict_types = 1 );

/*
 * This file only ever runs inside the end-to-end environment. `.wp-env.json`
 * defines the constant; a copy of this file that somehow reached a real site
 * would define no routes and intercept nothing.
 */
if ( ! defined( 'FACTUAREA_E2E' ) || ! FACTUAREA_E2E ) {
	return;
}

/**
 * Shared secret the control surface requires.
 *
 * The ingest route is deliberately open - the receiver it models is open, and
 * the plugin under test knows nothing about this token. Everything that PROGRAMS
 * or READS the harness is closed, so a stray request cannot rewrite what the
 * next assertion is about to read.
 */
const FACTUAREA_MOCK_TOKEN = 'factuarea-e2e-token';

/**
 * REST namespace of the harness.
 */
const FACTUAREA_MOCK_NAMESPACE = 'factuarea-mock/v1';

/**
 * Path the plugin under test POSTs its deliveries to.
 *
 * Kept as a literal rather than read from `Constants::INGESTION_PATH`, on
 * purpose: if somebody changed that constant, this harness would follow the
 * change and keep passing, and the scenario would stop proving that the plugin
 * posts where the receiver listens.
 */
const FACTUAREA_MOCK_INGESTION_PATH = '/api/webhooks/stores/woocommerce';

/**
 * Option holding the scripted answers.
 */
const FACTUAREA_MOCK_PLAN_OPTION = 'factuarea_mock_plan';

/**
 * Option holding what has been received so far.
 */
const FACTUAREA_MOCK_DELIVERIES_OPTION = 'factuarea_mock_deliveries';

/**
 * Most deliveries kept. A scenario that needs more than this has stopped being
 * a scenario.
 */
const FACTUAREA_MOCK_DELIVERIES_LIMIT = 50;

/**
 * The answer given when the scripted queue is empty and nothing was programmed.
 *
 * 200 with an empty body, because that is what the real receiver sends: its two
 * "accepted" paths and its two "discarded without effect" paths all answer 200,
 * and the discard paths carry no body at all.
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function factuarea_mock_default_response(): array {
	return array(
		'status'  => 200,
		'body'    => '',
		'headers' => array(),
	);
}

/**
 * Reads the plan, in the shape the rest of this file expects.
 *
 * @return array{queue: list<array<string, mixed>>, fallback: array<string, mixed>}
 */
function factuarea_mock_plan(): array {
	$stored = get_option( FACTUAREA_MOCK_PLAN_OPTION, null );

	if ( ! is_array( $stored ) ) {
		return array(
			'queue'    => array(),
			'fallback' => factuarea_mock_default_response(),
		);
	}

	return array(
		'queue'    => isset( $stored['queue'] ) && is_array( $stored['queue'] ) ? array_values( $stored['queue'] ) : array(),
		'fallback' => isset( $stored['fallback'] ) && is_array( $stored['fallback'] )
			? factuarea_mock_normalise_response( $stored['fallback'] )
			: factuarea_mock_default_response(),
	);
}

/**
 * Turns whatever a scenario programmed into a response this file can emit.
 *
 * A body given as an array is encoded; a body given as a string is sent as the
 * exact bytes it is. That distinction matters: rule 3 of the classifier turns on
 * whether the body carries a readable rejection code, and a scenario has to be
 * able to send a body that is not JSON at all.
 *
 * @param array<string, mixed> $response What the scenario asked for.
 * @return array{status: int, body: string, headers: array<string, string>, times: int}
 */
function factuarea_mock_normalise_response( array $response ): array {
	$status = isset( $response['status'] ) ? (int) $response['status'] : 200;
	$body   = $response['body'] ?? '';

	if ( is_array( $body ) ) {
		$body = (string) wp_json_encode( $body );
	} elseif ( null === $body ) {
		$body = '';
	} else {
		$body = (string) $body;
	}

	$headers = array();

	if ( isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
		foreach ( $response['headers'] as $name => $value ) {
			$headers[ (string) $name ] = (string) $value;
		}
	}

	$times = isset( $response['times'] ) ? (int) $response['times'] : 1;

	return array(
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
		'times'   => max( 1, $times ),
	);
}

/**
 * Takes the next scripted answer, consuming one of its repetitions.
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function factuarea_mock_next_response(): array {
	$plan = factuarea_mock_plan();

	if ( array() === $plan['queue'] ) {
		return $plan['fallback'];
	}

	$head = factuarea_mock_normalise_response( (array) $plan['queue'][0] );

	if ( 1 >= $head['times'] ) {
		array_shift( $plan['queue'] );
	} else {
		--$head['times'];
		$plan['queue'][0] = $head;
	}

	update_option( FACTUAREA_MOCK_PLAN_OPTION, $plan, false );

	return array(
		'status'  => $head['status'],
		'body'    => $head['body'],
		'headers' => $head['headers'],
	);
}

/**
 * Every header of the request that arrived, keyed in lower case.
 *
 * @return array<string, string>
 */
function factuarea_mock_request_headers(): array {
	$headers = array();

	foreach ( $_SERVER as $key => $value ) {
		if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
			continue;
		}

		if ( 0 === strpos( $key, 'HTTP_' ) ) {
			$name = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );

			$headers[ $name ] = (string) $value;

			continue;
		}

		if ( 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ) {
			$headers[ strtolower( str_replace( '_', '-', $key ) ) ] = (string) $value;
		}
	}

	return $headers;
}

/**
 * The names of the request headers IN THE ORDER THEY ARRIVED, in lower case.
 *
 * WHY THE ORDER IS RECORDED AT ALL. One claim of this package is that the
 * delivery carries the seven native WooCommerce headers and, when the merchant
 * has declared a store identifier, an eighth one AFTER them and before the
 * content type. "Present" is not that claim; a scenario that only checked for
 * presence would stay green the day the identity header was emitted first, and
 * a receiver that reads positionally would break in production.
 *
 * The order of `$_SERVER` is used only as a last resort. PHP fills its `HTTP_*`
 * entries in the order the web server handed the headers over, which under
 * mod_php is the wire order - but that is an implementation detail rather than
 * a promise, so the two functions that DO promise it are asked first.
 *
 * An EMPTY answer means "this could not be established here", and the scenario
 * that needs the order asserts on it being non-empty: a silent empty list would
 * turn a positional assertion into a loop over nothing, which passes.
 *
 * @return list<string>
 */
function factuarea_mock_wire_header_order(): array {
	$raw = array();

	if ( function_exists( 'apache_request_headers' ) ) {
		$raw = apache_request_headers();
	} elseif ( function_exists( 'getallheaders' ) ) {
		$raw = getallheaders();
	}

	if ( is_array( $raw ) && array() !== $raw ) {
		return array_map(
			static function ( $name ): string {
				return strtolower( (string) $name );
			},
			array_keys( $raw )
		);
	}

	$names = array();

	foreach ( $_SERVER as $key => $value ) {
		if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
			continue;
		}

		if ( 0 === strpos( $key, 'HTTP_' ) ) {
			$names[] = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );

			continue;
		}

		if ( 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ) {
			$names[] = strtolower( str_replace( '_', '-', $key ) );
		}
	}

	return $names;
}

/**
 * Records one delivery and answers it.
 *
 * The signature is verified HERE, with the same algorithm the real verifier
 * uses, rather than handed to the scenario to check: a scenario that recomputed
 * the signature in JavaScript would be comparing this harness against itself.
 * What it gets back is a verdict computed over the bytes that arrived.
 *
 * @param string                $raw_body Bytes of the request body, exactly as they arrived.
 * @param array<string, string> $headers  Request headers, keyed in lower case.
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function factuarea_mock_receive( string $raw_body, array $headers ): array {
	$secret    = (string) get_option( 'factuarea_wc_signing_secret', '' );
	$signature = (string) ( $headers['x-wc-webhook-signature'] ?? '' );
	$expected  = '' === $secret ? '' : base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) );

	$decoded = json_decode( $raw_body, true );

	$delivery = array(
		'received_at'      => time(),
		'headers'          => $headers,
		'header_order'     => factuarea_mock_wire_header_order(),
		'raw_body'         => $raw_body,
		'raw_body_length'  => strlen( $raw_body ),
		'body'             => is_array( $decoded ) ? $decoded : null,
		'body_is_json'     => is_array( $decoded ),
		'signature_valid'  => '' !== $secret && '' !== $signature && hash_equals( $expected, $signature ),
		'signature_absent' => '' === $signature,
	);

	$response = factuarea_mock_next_response();

	$delivery['answered_status'] = $response['status'];

	$deliveries   = get_option( FACTUAREA_MOCK_DELIVERIES_OPTION, array() );
	$deliveries   = is_array( $deliveries ) ? array_values( $deliveries ) : array();
	$deliveries[] = $delivery;

	if ( count( $deliveries ) > FACTUAREA_MOCK_DELIVERIES_LIMIT ) {
		$deliveries = array_slice( $deliveries, -FACTUAREA_MOCK_DELIVERIES_LIMIT );
	}

	update_option( FACTUAREA_MOCK_DELIVERIES_OPTION, $deliveries, false );

	return $response;
}

/**
 * Is the caller allowed to program or read the harness?
 *
 * @param WP_REST_Request $request The request.
 * @return bool|WP_Error True when the shared token matched.
 */
function factuarea_mock_authorise( WP_REST_Request $request ) {
	$token = (string) $request->get_header( 'x-factuarea-mock-token' );

	if ( hash_equals( FACTUAREA_MOCK_TOKEN, $token ) ) {
		return true;
	}

	return new WP_Error(
		'factuarea_mock_forbidden',
		'The harness token is missing or wrong.',
		array( 'status' => 403 )
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return array(
						'ok'             => true,
						'ingestion_path' => FACTUAREA_MOCK_INGESTION_PATH,
						'home_url'       => home_url(),
					);
				},
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/ingest',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					/*
					 * `WP_REST_Request::get_headers()` keys its answer the way
					 * PHP keys $_SERVER - lower case with underscores - and its
					 * values are LISTS, because a header may legitimately appear
					 * more than once. Both are normalised here, once, so that
					 * every assertion downstream can name a header the way the
					 * wire spells it.
					 */
					$headers = array();

					foreach ( $request->get_headers() as $name => $values ) {
						$headers[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = is_array( $values )
							? implode( ', ', $values )
							: (string) $values;
					}

					$answer = factuarea_mock_receive( (string) $request->get_body(), $headers );

					$response = new WP_REST_Response( array( 'raw' => $answer['body'] ), $answer['status'] );

					foreach ( $answer['headers'] as $name => $value ) {
						$response->header( $name, $value );
					}

					return $response;
				},
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/program',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'factuarea_mock_authorise',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload  = $request->get_json_params();
					$payload  = is_array( $payload ) ? $payload : array();
					$queue    = isset( $payload['responses'] ) && is_array( $payload['responses'] ) ? $payload['responses'] : array();
					$fallback = isset( $payload['fallback'] ) && is_array( $payload['fallback'] )
						? factuarea_mock_normalise_response( $payload['fallback'] )
						: factuarea_mock_default_response();

					$plan = array(
						'queue'    => array_map(
							static function ( $response ): array {
								return factuarea_mock_normalise_response( is_array( $response ) ? $response : array() );
							},
							array_values( $queue )
						),
						'fallback' => $fallback,
					);

					update_option( FACTUAREA_MOCK_PLAN_OPTION, $plan, false );

					return $plan;
				},
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/deliveries',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'factuarea_mock_authorise',
				'callback'            => static function () {
					$deliveries = get_option( FACTUAREA_MOCK_DELIVERIES_OPTION, array() );

					return is_array( $deliveries ) ? array_values( $deliveries ) : array();
				},
			)
		);

		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'factuarea_mock_authorise',
				'callback'            => static function () {
					delete_option( FACTUAREA_MOCK_DELIVERIES_OPTION );
					delete_option( FACTUAREA_MOCK_PLAN_OPTION );

					return array( 'ok' => true );
				},
			)
		);
	}
);

/**
 * Hands the request that arrived on the ingestion path to the REST route, and
 * writes the answer out byte for byte.
 *
 * On `init` rather than later, because WordPress would otherwise resolve that
 * path to a 404 page; and after WooCommerce has loaded, so that the REST server
 * this builds is the same one the rest of the site would build.
 *
 * @return void
 */
function factuarea_mock_dispatch_ingestion(): void {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	if ( FACTUAREA_MOCK_INGESTION_PATH !== rtrim( $path, '/' ) ) {
		return;
	}

	$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

	if ( 'POST' !== $method ) {
		status_header( 405 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo (string) wp_json_encode(
			array(
				'code'    => 'method_not_allowed',
				'message' => 'The ingestion path takes POST. This is the end-to-end harness, not the real receiver.',
			)
		);
		exit;
	}

	$request = new WP_REST_Request( 'POST', '/' . FACTUAREA_MOCK_NAMESPACE . '/ingest' );

	$request->set_body( (string) file_get_contents( 'php://input' ) );

	foreach ( factuarea_mock_request_headers() as $name => $value ) {
		$request->set_header( $name, $value );
	}

	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$body     = is_array( $data ) && isset( $data['raw'] ) ? (string) $data['raw'] : '';

	status_header( $response->get_status() );

	foreach ( $response->get_headers() as $name => $value ) {
		header( $name . ': ' . $value );
	}

	if ( '' !== $body ) {
		header( 'Content-Type: application/json; charset=utf-8' );
	}

	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The scenario's own bytes, emitted verbatim on purpose.

	exit;
}

add_action( 'init', 'factuarea_mock_dispatch_ingestion', 5 );

/* -------------------------------------------------------------------------
 * THE OTHER HALF OF FACTUAREA: THE SENDER
 * -------------------------------------------------------------------------
 *
 * Everything above models the ingestion route the platform OFFERS to the shop.
 * What follows models the opposite direction: the platform PUBLISHING a signed
 * notice at one of the receivers this plugin registers, which is the half the
 * inbound scenarios exercise.
 *
 * WHY THE SENDER LIVES HERE RATHER THAN IN THE SCENARIO
 *
 * Because signing is the thing being exercised. The receiver authenticates a
 * delivery by recomputing the HMAC over the bytes that arrived with the shop's
 * own secret; a sender that lived in the scenario would have to be handed that
 * secret over HTTP to sign with it, and the harness has a gate against printing
 * credentials precisely so that never happens. Here the secret is read from the
 * shop's own option, used, and never leaves the container.
 *
 * WHY IT GOES OVER REAL HTTP RATHER THAN THROUGH `rest_do_request()`
 *
 * Dispatching internally would prove the callback works while proving nothing
 * about the route being registered at the published address, about the raw body
 * surviving the web server, or about the signature header reaching PHP under
 * the name it is sent with. Those are three of the ways this lane can be broken
 * on a real shop and invisible in a test, so the notice is POSTed at the site
 * the way the platform would POST it.
 *
 * WHY THE ADDRESS IS PROBED RATHER THAN COMPOSED
 *
 * `home_url()` is `http://localhost:8888`, which is a mapping that exists on the
 * HOST and in no container. The same measurement the seed makes for the delivery
 * address is made here, for the same reason and against the same candidates: the
 * first one that answers is the one used, and none answering FAILS loudly rather
 * than producing a 404 that would read as a receiver that is not registered.
 *
 * WHY THE ROUTES AND THE HEADER ARE LITERALS
 *
 * Same decision, and same reason, as `FACTUAREA_MOCK_INGESTION_PATH` above: if
 * somebody renamed `Constants::INBOUND_ORDER_STATUS_ROUTE`, a harness that read
 * the constant would follow the rename and keep passing, and the scenario would
 * stop proving that the plugin listens where the platform publishes.
 */

/**
 * Addresses of the three inbound receivers, spelled the way the platform spells
 * them.
 */
const FACTUAREA_MOCK_INBOUND_ROUTES = array(
	'order-status' => '/wp-json/factuarea/v1/order-status',
	'order-refund' => '/wp-json/factuarea/v1/order-refund',
	'catalog-push' => '/wp-json/factuarea/v1/catalog-push',
);

/**
 * Header the inbound signature travels in.
 */
const FACTUAREA_MOCK_INBOUND_SIGNATURE_HEADER = 'X-Factuarea-Signature';

/**
 * Option the shop keeps its signing secret in. ONE secret per shop: the same
 * value signs what the shop sends and verifies what it receives.
 */
const FACTUAREA_MOCK_SIGNING_SECRET_OPTION = 'factuarea_wc_signing_secret';

/**
 * Option this file caches the reachable address of the site in.
 */
const FACTUAREA_MOCK_SITE_ADDRESS_OPTION = 'factuarea_mock_site_address';

/**
 * The address of THIS site as reachable from inside the container.
 *
 * @return string Base address with no trailing slash.
 *
 * @throws RuntimeException When no candidate answers.
 */
function factuarea_mock_site_address(): string {
	$cached = (string) get_option( FACTUAREA_MOCK_SITE_ADDRESS_OPTION, '' );

	if ( '' !== $cached ) {
		return $cached;
	}

	$tried = array();

	foreach ( array( 'http://wordpress', home_url(), 'http://localhost' ) as $candidate ) {
		$candidate = rtrim( (string) $candidate, '/' );

		if ( '' === $candidate || in_array( $candidate, $tried, true ) ) {
			continue;
		}

		$tried[] = $candidate;

		$probe = wp_remote_get(
			$candidate . '/wp-json/' . FACTUAREA_MOCK_NAMESPACE . '/health',
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $probe ) || 200 !== (int) wp_remote_retrieve_response_code( $probe ) ) {
			continue;
		}

		/*
		 * The answer is DECODED before it is compared. WordPress escapes the
		 * forward slashes of a string it encodes, so a substring search for the
		 * ingestion path against the raw bytes never matches - measured, and it
		 * turned a perfectly reachable address into "nothing answered".
		 */
		$answer = json_decode( (string) wp_remote_retrieve_body( $probe ), true );

		if ( ! is_array( $answer ) || FACTUAREA_MOCK_INGESTION_PATH !== ( $answer['ingestion_path'] ?? '' ) ) {
			continue;
		}

		update_option( FACTUAREA_MOCK_SITE_ADDRESS_OPTION, $candidate, false );

		return $candidate;
	}

	throw new RuntimeException(
		'None of the candidate addresses of this site answered the harness: ' . implode( ', ', $tried )
		. '. The inbound notices could not be published at the receivers, so the scenario would fail for a reason '
		. 'that has nothing to do with the plugin.'
	);
}

/**
 * The signature the platform puts on a body.
 *
 * The scheme is the frozen one of the inbound contract: HMAC-SHA256 of the RAW
 * body with the shop's signing secret, in base64. It is written out here rather
 * than delegated to the plugin's own signer on purpose - a sender that reused
 * the code under test would agree with it whatever it computed, including on the
 * day it started computing the WRONG thing.
 *
 * @param string $material Bytes to sign.
 * @return string Signature, base64 encoded.
 *
 * @throws RuntimeException When the shop has no secret, which would make every
 *                          delivery a 401 for a reason that is not the plugin's.
 */
function factuarea_mock_sign( string $material ): string {
	$secret = (string) get_option( FACTUAREA_MOCK_SIGNING_SECRET_OPTION, '' );

	if ( '' === $secret ) {
		throw new RuntimeException(
			'This shop has no signing secret, so nothing can be signed for it. The seed sets one; a site where it is '
			. 'missing was not seeded.'
		);
	}

	return base64_encode( hash_hmac( 'sha256', $material, $secret, true ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			FACTUAREA_MOCK_NAMESPACE,
			'/inbound/deliver',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'factuarea_mock_authorise',
				'callback'            => static function ( WP_REST_Request $request ) {
					$payload = $request->get_json_params();
					$payload = is_array( $payload ) ? $payload : array();

					$route = (string) ( $payload['route'] ?? '' );

					if ( ! isset( FACTUAREA_MOCK_INBOUND_ROUTES[ $route ] ) ) {
						return new WP_Error(
							'factuarea_mock_unknown_receiver',
							'The harness was asked to publish at "' . $route . '", which is not one of the three '
							. 'receivers this plugin registers.',
							array( 'status' => 400 )
						);
					}

					/*
					 * The body travels as a STRING and is POSTed as those exact
					 * bytes. It is never decoded and re-encoded on the way
					 * through: key order, spacing and the trailing newline are
					 * all covered by the signature, and a round trip through
					 * `json_decode`/`wp_json_encode` would change them and turn
					 * every legitimate delivery into a 401.
					 */
					$body = (string) ( $payload['body'] ?? '' );

					/*
					 * What gets SIGNED, which is the body unless the scenario
					 * asked for something else. Handing over a different string
					 * is how a scenario produces a signature that is genuinely
					 * wrong for the bytes that arrive - a byte altered in
					 * flight - rather than a header full of rubbish, which is a
					 * weaker test of the same refusal.
					 */
					$material = array_key_exists( 'sign_over', $payload )
						? (string) $payload['sign_over']
						: $body;

					$headers = array( 'Content-Type' => 'application/json' );

					if ( true !== ( $payload['omit_signature'] ?? false ) ) {
						try {
							$headers[ FACTUAREA_MOCK_INBOUND_SIGNATURE_HEADER ] = factuarea_mock_sign( $material );
						} catch ( RuntimeException $unsignable ) {
							return new WP_Error(
								'factuarea_mock_unsignable',
								$unsignable->getMessage(),
								array( 'status' => 500 )
							);
						}
					}

					try {
						$address = factuarea_mock_site_address() . FACTUAREA_MOCK_INBOUND_ROUTES[ $route ];
					} catch ( RuntimeException $unreachable ) {
						return new WP_Error(
							'factuarea_mock_site_unreachable',
							$unreachable->getMessage(),
							array( 'status' => 500 )
						);
					}

					$answer = wp_remote_post(
						$address,
						array(
							'timeout'     => 30,
							'redirection' => 0,
							'headers'     => $headers,
							'body'        => $body,
						)
					);

					if ( is_wp_error( $answer ) ) {
						return new WP_Error(
							'factuarea_mock_delivery_failed',
							'The notice could not be published at ' . $address . ': ' . $answer->get_error_message(),
							array( 'status' => 500 )
						);
					}

					$returned = (string) wp_remote_retrieve_body( $answer );
					$decoded  = json_decode( $returned, true );

					return array(
						'address'      => $address,
						'signed'       => isset( $headers[ FACTUAREA_MOCK_INBOUND_SIGNATURE_HEADER ] ),
						'sent_bytes'   => strlen( $body ),
						'signed_bytes' => strlen( $material ),
						'status'       => (int) wp_remote_retrieve_response_code( $answer ),
						'raw'          => $returned,
						'body'         => is_array( $decoded ) ? $decoded : null,
					);
				},
			)
		);
	}
);
