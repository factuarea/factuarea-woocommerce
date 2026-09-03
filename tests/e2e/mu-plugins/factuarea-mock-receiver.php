<?php
/**
 * Plugin Name: Factuarea E2E mock receiver
 * Description: Stands in for the Factuarea ingestion route inside the containerised WordPress, so the end-to-end scenarios can look at what the plugin actually sent and can dictate what comes back. Never published: it lives under tests/ and .distignore excludes the whole directory.
 * Version: 0.1.0
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
