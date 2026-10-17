<?php
/**
 * Unit tests for the WordPress HTTP client.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\HttpResponse;
use Factuarea\WooCommerce\Transport\WordPressHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

/*
 * A minimal stand-in for the WordPress error object.
 *
 * It is declared behind a guard rather than unconditionally, because the whole
 * package runs as ONE PHPUnit suite: a second file that declares the same class
 * without a guard is a fatal error at load time, and the guard means whichever
 * file is loaded first wins instead. It is deliberately NOT final so that a
 * later phase can build a Mockery double of it if it needs one.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class_alias( FakeWpError::class, 'WP_Error' );
}

/**
 * What this file pins down.
 *
 * Every WordPress function the client touches is doubled, so these run on bare
 * PHP with nothing but composer installed. The four cases the phase asks for -
 * a good response, a transport error, a timeout and an empty body - are here,
 * and the point being made by having all four is not that each works: it is
 * that all four leave by the SAME door. A client that returned an HttpResponse
 * for two of them and threw for the other two would pass a test written per
 * case and still bring down a scheduled drain in a merchant's shop.
 *
 * So each case asserts three things: the type that comes back, the shape it
 * carries, and - through the `expectNotToPerformAssertions`-free style of these
 * tests - that reaching the assertion at all means nothing was thrown.
 */
final class WordPressHttpClientTest extends TestCase {

	/**
	 * The client under test.
	 *
	 * @var WordPressHttpClient
	 */
	private WordPressHttpClient $client;

	/**
	 * Arguments the last doubled request was called with.
	 *
	 * @var array<string, mixed>
	 */
	private array $captured_arguments = array();

	/**
	 * Installs the function doubles shared by every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->client             = new WordPressHttpClient();
		$this->captured_arguments = array();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof WP_Error;
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['response']['code'] )
					? $response['response']['code']
					: '';
			}
		);

		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['headers'] )
					? $response['headers']
					: array();
			}
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string {
				return is_array( $response ) && isset( $response['body'] )
					? (string) $response['body']
					: '';
			}
		);
	}

	/**
	 * Removes the doubles.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Doubles the outgoing request, recording its arguments and answering with
	 * whatever the case wants.
	 *
	 * @param string $function_name Either wp_remote_post or wp_remote_get.
	 * @param mixed  $answer        What the WordPress HTTP API is to return.
	 * @return void
	 */
	private function double_request( string $function_name, $answer ): void {
		Functions\expect( $function_name )
			->once()
			->andReturnUsing(
				function ( string $url, array $arguments ) use ( $answer ) {
					$this->captured_arguments        = $arguments;
					$this->captured_arguments['url'] = $url;

					return $answer;
				}
			);
	}

	/**
	 * A WordPress response array, as the HTTP API builds one.
	 *
	 * @param int                         $status_code Status code.
	 * @param array<string, string|mixed> $headers     Response headers.
	 * @param string                      $body        Response body.
	 * @return array<string, mixed>
	 */
	private function wp_response( int $status_code, array $headers = array(), string $body = '' ): array {
		return array(
			'response' => array(
				'code'    => $status_code,
				'message' => '',
			),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	// -----------------------------------------------------------------------
	// The four outcomes the phase names, and the one door they all leave by.
	// -----------------------------------------------------------------------

	/**
	 * A good response arrives whole: status, headers and the body untouched.
	 *
	 * @return void
	 */
	public function test_a_successful_response_comes_back_whole(): void {
		$this->double_request(
			'wp_remote_post',
			$this->wp_response( 200, array( 'Content-Type' => 'application/json' ), '{"status":"queued"}' )
		);

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{"id":7}' );

		$this->assertInstanceOf( HttpResponse::class, $response );
		$this->assertFalse( $response->is_transport_failure() );
		$this->assertSame( 200, $response->status_code );
		$this->assertSame( '{"status":"queued"}', $response->raw_body );
		$this->assertSame( 'application/json', $response->header( 'content-type' ) );
		$this->assertNull( $response->transport_error );
		$this->assertSame(
			'https://app.example.test/api/webhooks/stores/woocommerce',
			$this->captured_arguments['url']
		);
	}

	/**
	 * A transport error leaves by the same door: an HttpResponse carrying the
	 * reason, not an exception. This is the case that would otherwise take a
	 * whole scheduled drain down with it.
	 *
	 * @return void
	 */
	public function test_a_transport_error_comes_back_as_a_response_and_not_as_an_exception(): void {
		$this->double_request(
			'wp_remote_post',
			new WP_Error( 'http_request_failed', 'Could not resolve host: app.example.test' )
		);

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{"id":7}' );

		$this->assertInstanceOf( HttpResponse::class, $response );
		$this->assertTrue( $response->is_transport_failure() );
		$this->assertNull( $response->status_code );
		$this->assertSame( 'Could not resolve host: app.example.test', $response->transport_error );
		$this->assertSame( '', $response->raw_body );
		$this->assertSame( array(), $response->headers );
	}

	/**
	 * A timeout is a transport error like any other, and it matters that it is
	 * reported rather than raised: it is the single most common failure on the
	 * delivery path, and the classifier is the one that must decide it is
	 * transient.
	 *
	 * @return void
	 */
	public function test_a_timeout_comes_back_as_a_transport_failure(): void {
		$this->double_request(
			'wp_remote_post',
			new WP_Error( 'http_request_failed', 'Operation timed out after 15000 milliseconds' )
		);

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{"id":7}' );

		$this->assertTrue( $response->is_transport_failure() );
		$this->assertSame( 'Operation timed out after 15000 milliseconds', $response->transport_error );
	}

	/**
	 * An empty body is a response, not a failure. A 502 from a proxy with
	 * nothing in it has to reach the classifier AS a 502 with an empty body:
	 * that is the case that, mistaken for a rejection, loses a paid order for
	 * good.
	 *
	 * @return void
	 */
	public function test_an_empty_body_is_still_a_response(): void {
		$this->double_request( 'wp_remote_post', $this->wp_response( 502, array(), '' ) );

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{"id":7}' );

		$this->assertInstanceOf( HttpResponse::class, $response );
		$this->assertFalse( $response->is_transport_failure() );
		$this->assertSame( 502, $response->status_code );
		$this->assertSame( '', $response->raw_body );
		$this->assertNull( $response->decoded_body() );
	}

	/**
	 * The read side takes the same four roads. Written as one case because what
	 * is being pinned is that `get` shares the whole reporting path with `post`
	 * and does not grow a second one.
	 *
	 * @return void
	 */
	public function test_the_read_side_reports_through_the_same_shape(): void {
		$this->double_request( 'wp_remote_get', $this->wp_response( 401, array(), '{"code":"unauthenticated"}' ) );

		$response = $this->client->get( 'https://app.example.test/v1/companies/9f2c1d40-0000-4000-8000-0000000000aa/stores/7', array( 'Authorization' => 'Bearer x' ) );

		$this->assertInstanceOf( HttpResponse::class, $response );
		$this->assertSame( 401, $response->status_code );
		$this->assertSame( array( 'code' => 'unauthenticated' ), $response->decoded_body() );
	}

	/**
	 * A read that never completes reports, like a write does.
	 *
	 * @return void
	 */
	public function test_a_read_that_never_completes_reports_rather_than_throws(): void {
		$this->double_request( 'wp_remote_get', new WP_Error( 'http_request_failed', 'Connection refused' ) );

		$response = $this->client->get( 'https://app.example.test/v1/companies/9f2c1d40-0000-4000-8000-0000000000aa/stores/7', array() );

		$this->assertTrue( $response->is_transport_failure() );
		$this->assertSame( 'Connection refused', $response->transport_error );
	}

	// -----------------------------------------------------------------------
	// The request this client makes.
	// -----------------------------------------------------------------------

	/**
	 * The timeout is bounded and redirects are not followed. Both are asserted
	 * on the arguments actually handed to the WordPress HTTP API, because a
	 * constant declared and never passed is a guardrail that exists only in the
	 * file that declares it.
	 *
	 * @return void
	 */
	public function test_the_request_is_bounded_and_follows_no_redirect(): void {
		$this->double_request( 'wp_remote_post', $this->wp_response( 200 ) );

		$this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertSame( WordPressHttpClient::REQUEST_TIMEOUT_SECONDS, $this->captured_arguments['timeout'] );
		$this->assertSame( 0, $this->captured_arguments['redirection'] );
		$this->assertTrue( $this->captured_arguments['sslverify'] );
		$this->assertTrue( $this->captured_arguments['blocking'] );
	}

	/**
	 * The same bounds apply to a read: a connection test that hung for as long
	 * as the host kept the socket open would hang the settings screen with it.
	 *
	 * @return void
	 */
	public function test_a_read_is_bounded_and_follows_no_redirect_either(): void {
		$this->double_request( 'wp_remote_get', $this->wp_response( 200 ) );

		$this->client->get( 'https://app.example.test/v1/companies/9f2c1d40-0000-4000-8000-0000000000aa/stores/7', array() );

		$this->assertSame( WordPressHttpClient::REQUEST_TIMEOUT_SECONDS, $this->captured_arguments['timeout'] );
		$this->assertSame( 0, $this->captured_arguments['redirection'] );
	}

	/**
	 * The body leaves as the exact string it came in as, and is declared as a
	 * raw body. Anything else - form encoding, re-encoding, a trailing newline -
	 * changes the bytes the signature covers and the receiver rejects the
	 * delivery as forged, with nothing failing on this side.
	 *
	 * @return void
	 */
	public function test_the_body_is_sent_byte_for_byte_and_never_re_encoded(): void {
		$raw_body = '{"id":7,"total":"12.30","note":"café \\/ tea"}';

		$this->double_request( 'wp_remote_post', $this->wp_response( 200 ) );

		$this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), $raw_body );

		$this->assertSame( $raw_body, $this->captured_arguments['body'] );
		$this->assertSame( 'body', $this->captured_arguments['data_format'] );
	}

	/**
	 * The headers the caller gives are the headers that go out. The delivery
	 * headers are composed elsewhere and signed over there; a transport that
	 * edited them would invalidate work it cannot see.
	 *
	 * @return void
	 */
	public function test_the_callers_headers_are_sent_unchanged(): void {
		$headers = array(
			'X-WC-Webhook-Source'    => 'https://shop.example.test/',
			'X-WC-Webhook-Topic'     => 'order.updated',
			'X-WC-Webhook-Signature' => 'ZGVhZGJlZWY=',
		);

		$this->double_request( 'wp_remote_post', $this->wp_response( 200 ) );

		$this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', $headers, '{}' );

		foreach ( $headers as $name => $value ) {
			$this->assertSame( $value, $this->captured_arguments['headers'][ $name ] );
		}
	}

	/**
	 * A POST with no content type declared gets the JSON one, because WordPress
	 * adds none for a string body and the receiver would otherwise be handed a
	 * JSON document with nothing saying so.
	 *
	 * @return void
	 */
	public function test_a_post_without_a_content_type_is_labelled_as_json(): void {
		$this->double_request( 'wp_remote_post', $this->wp_response( 200 ) );

		$this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertSame( 'application/json; charset=utf-8', $this->captured_arguments['headers']['Content-Type'] );
	}

	/**
	 * A caller that names its own content type keeps it, and no second one is
	 * added alongside - not even under a different capitalisation, which would
	 * send two content types and leave the choice between them to the receiver.
	 *
	 * @return void
	 */
	public function test_a_caller_that_names_its_own_content_type_keeps_it(): void {
		$this->double_request( 'wp_remote_post', $this->wp_response( 200 ) );

		$this->client->post(
			'https://app.example.test/api/webhooks/stores/woocommerce',
			array( 'content-type' => 'application/x-ndjson' ),
			'{}'
		);

		$content_types = array_filter(
			array_keys( $this->captured_arguments['headers'] ),
			static function ( $name ): bool {
				return 'content-type' === strtolower( (string) $name );
			}
		);

		$this->assertCount( 1, $content_types );
		$this->assertSame( 'application/x-ndjson', $this->captured_arguments['headers']['content-type'] );
	}

	/**
	 * A read carries no body and no data format: passing an empty body would
	 * turn a GET into a request with content, which some receivers and most
	 * caches treat differently.
	 *
	 * @return void
	 */
	public function test_a_read_carries_no_body(): void {
		$this->double_request( 'wp_remote_get', $this->wp_response( 200 ) );

		$this->client->get( 'https://app.example.test/v1/companies/9f2c1d40-0000-4000-8000-0000000000aa/stores/7', array() );

		$this->assertArrayNotHasKey( 'body', $this->captured_arguments );
		$this->assertArrayNotHasKey( 'data_format', $this->captured_arguments );
		$this->assertArrayNotHasKey( 'Content-Type', $this->captured_arguments['headers'] );
	}

	// -----------------------------------------------------------------------
	// Reporting what came back, without interpreting it.
	// -----------------------------------------------------------------------

	/**
	 * Headers arrive as a case-insensitive dictionary on a real request. They
	 * are unpacked here rather than at each reader, so the classifier can ask
	 * for `retry-after` without knowing how the server capitalised it.
	 *
	 * @return void
	 */
	public function test_headers_given_as_a_case_insensitive_dictionary_are_unpacked(): void {
		/*
		 * A real object with a real `getAll`, and not a Mockery double, because
		 * the client asks `method_exists` before calling it - and MEASURED on
		 * 2026-09-02: `method_exists` is FALSE on a Mockery double of a class
		 * that does not exist, since those answer through `__call` instead of
		 * declaring methods. A double here would take the array road, and this
		 * test would pass while proving the opposite of what it claims.
		 */
		$dictionary = new class() {

			/**
			 * Every header, the way the real dictionary hands them over.
			 *
			 * @return array<string, string>
			 */
			public function getAll(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return array(
					'Retry-After'  => '120',
					'Content-Type' => 'application/json',
				);
			}
		};

		$this->double_request( 'wp_remote_post', $this->wp_response( 429, array(), '{"code":"burst"}' ) );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( $dictionary );

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertTrue( $response->has_header( 'Retry-After' ) );
		$this->assertSame( '120', $response->header( 'retry-after' ) );
		$this->assertSame( 'application/json', $response->header( 'CONTENT-TYPE' ) );
	}

	/**
	 * A header sent more than once arrives as a list and is folded with a comma,
	 * which is what HTTP says a repeated header combines to. Keeping only one of
	 * them would drop the others without a word.
	 *
	 * @return void
	 */
	public function test_a_repeated_header_is_folded_rather_than_dropped(): void {
		$this->double_request(
			'wp_remote_post',
			$this->wp_response( 200, array( 'X-Trace' => array( 'first', 'second' ) ), '{}' )
		);

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertSame( 'first, second', $response->header( 'x-trace' ) );
	}

	/**
	 * Nothing here reads a status code to decide what it means. A 500 with a
	 * body, a 429 with a wait header and a 200 all come back the same way, and
	 * the difference between them is the classifier's business.
	 *
	 * @param int    $status_code Status code the receiver answered with.
	 * @param string $body        Body it answered with.
	 * @return void
	 */
	#[DataProvider( 'provide_statuses_the_client_does_not_judge' )]
	public function test_no_status_code_is_judged_by_this_client( int $status_code, string $body ): void {
		$this->double_request( 'wp_remote_post', $this->wp_response( $status_code, array(), $body ) );

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertFalse( $response->is_transport_failure() );
		$this->assertSame( $status_code, $response->status_code );
		$this->assertSame( $body, $response->raw_body );
	}

	/**
	 * The statuses of the previous case.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function provide_statuses_the_client_does_not_judge(): array {
		return array(
			'accepted'                    => array( 200, '{"status":"queued"}' ),
			'unknown store, still 200'    => array( 200, '' ),
			'signature rejected'          => array( 401, '{"code":"invalid_signature"}' ),
			'provider without a verifier' => array( 404, '' ),
			'rejection with a wait'       => array( 429, '{"code":"burst"}' ),
			'proxy error with no body'    => array( 502, '' ),
			'gateway page, unreadable'    => array( 503, '<html><body>Service Unavailable</body></html>' ),
		);
	}

	/**
	 * A request another plugin short-circuited into something that is neither a
	 * response nor an error is reported as a transport failure - because that is
	 * what it is - instead of becoming a status code of zero for somebody else
	 * to interpret.
	 *
	 * @return void
	 */
	public function test_an_answer_with_no_status_code_is_a_transport_failure(): void {
		$this->double_request( 'wp_remote_post', false );

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertTrue( $response->is_transport_failure() );
		$this->assertNull( $response->status_code );
		$this->assertStringContainsString( 'no readable status code', (string) $response->transport_error );
	}

	/**
	 * An error object with an empty message still yields a readable reason. The
	 * reason is stored in the outbox and shown to the merchant, and "" would be
	 * a row that says a delivery failed for no reason at all.
	 *
	 * @return void
	 */
	public function test_an_error_without_a_message_still_yields_a_reason(): void {
		$this->double_request( 'wp_remote_post', new WP_Error( 'http_request_failed', '' ) );

		$response = $this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' );

		$this->assertTrue( $response->is_transport_failure() );
		$this->assertNotSame( '', (string) $response->transport_error );
	}

	/**
	 * The whole point, asserted once over every case at the same time: not one
	 * of these roads throws.
	 *
	 * @return void
	 */
	public function test_not_one_of_the_four_outcomes_throws(): void {
		$answers = array(
			'ok'        => $this->wp_response( 200, array(), '{"status":"queued"}' ),
			'transport' => new WP_Error( 'http_request_failed', 'Could not resolve host' ),
			'timeout'   => new WP_Error( 'http_request_failed', 'Operation timed out' ),
			'empty'     => $this->wp_response( 502, array(), '' ),
			'nothing'   => null,
		);

		foreach ( $answers as $label => $answer ) {
			Functions\when( 'wp_remote_post' )->justReturn( $answer );

			$this->assertInstanceOf(
				HttpResponse::class,
				$this->client->post( 'https://app.example.test/api/webhooks/stores/woocommerce', array(), '{}' ),
				sprintf( 'The "%s" outcome did not come back as an HttpResponse.', $label )
			);
		}
	}
}

/**
 * The stand-in the alias above points at when WordPress is not loaded.
 *
 * Not final, so that a phase needing a Mockery double of WP_Error can build one.
 */
class FakeWpError {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Builds one the way WordPress does.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * The code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * The message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}
