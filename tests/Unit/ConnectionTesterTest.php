<?php
/**
 * Unit test of the connection test.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Admin\ConnectionTester;
use Factuarea\WooCommerce\Contract\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down.
 *
 * The five outcomes the phase asks for, each with its own diagnostic, plus the
 * two the implementation adds and had to: the case where nothing is configured
 * yet, and the case where an answer came back and was none of the five.
 *
 * They are asserted as a SET as well as one by one. A test written per case
 * passes just as happily against a class that answers "unexpected response" to
 * four of them, and "unexpected response" is precisely the answer the merchant
 * cannot act on - which is the whole reason this class exists instead of a
 * green tick.
 *
 * The last group is the sweep: every message and every next step of every
 * outcome is searched for the stored API key, whole and in fragments. The
 * connection test is the surface most likely to grow a helpful "we sent key
 * fk_live_abc..." while somebody debugs a merchant's shop, and the sweep is
 * what makes that a red instead of a support ticket six months later.
 */
final class ConnectionTesterTest extends TestCase {

	/**
	 * Stored API key. Recognisable on sight so that a sweep failure names
	 * something a reader can find.
	 */
	private const STORED_API_KEY = 'fk_live_APIKEYVALUE0123456789';

	/**
	 * Stored store identifier.
	 */
	private const STORE_ID = '0192f4c3-6f2b-7a1e-9b6d-2c4f8a0b1d3e';

	/**
	 * Address the v1 reads are made against in these tests.
	 */
	private const API_BASE_URL = 'https://api.factuarea.example';

	/**
	 * Address deliveries are posted to. It is here to be asserted ABSENT from
	 * the read: the two are different hosts, and reading from the delivery one
	 * is the mistake this setting was split to prevent.
	 */
	private const DELIVERY_BASE_URL = 'https://app.factuarea.example';

	/**
	 * The options as the double of `get_option` sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Address the last doubled request was made to.
	 *
	 * @var string
	 */
	private string $requested_url = '';

	/**
	 * Arguments the last doubled request was made with.
	 *
	 * @var array<string, mixed>
	 */
	private array $requested_arguments = array();

	/**
	 * How many outgoing requests were made.
	 *
	 * @var int
	 */
	private int $requests_made = 0;

	/**
	 * What the doubled WordPress HTTP API answers with next.
	 *
	 * The double is installed ONCE, in setUp, and each case moves this field
	 * instead of installing another one: two doubles of the same function stack
	 * up as two expectations, and the first one keeps answering, so the second
	 * case of a test that installed two would silently be running the first
	 * case's answer.
	 *
	 * @var mixed
	 */
	private $next_answer = null;

	/**
	 * Installs the doubles of the WordPress functions the code under test
	 * reaches for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();

		$this->options = array(
			Constants::OPTION_BASE_URL     => self::DELIVERY_BASE_URL,
			Constants::OPTION_API_BASE_URL => self::API_BASE_URL,
			Constants::OPTION_API_KEY      => self::STORED_API_KEY,
			Constants::OPTION_STORE_ID     => self::STORE_ID,
		);

		$this->requested_url       = '';
		$this->requested_arguments = array();
		$this->requests_made       = 0;
		$this->next_answer         = null;

		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $arguments ) {
				$this->requested_url       = $url;
				$this->requested_arguments = $arguments;
				++$this->requests_made;

				return $this->next_answer;
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ): string {
				return trim( wp_strip_all_tags_stand_in( (string) $value ) );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof FakeTransportError;
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
				return is_array( $response ) && isset( $response['headers'] ) ? $response['headers'] : array();
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string {
				return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
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
	 * A key the receiver accepts and a store it knows is a working connection.
	 *
	 * @return void
	 */
	public function test_a_known_store_reports_a_working_connection(): void {
		$this->answer_with(
			200,
			array(
				'id'       => self::STORE_ID,
				'object'   => 'store',
				'provider' => 'woocommerce',
				'name'     => 'Demo Shop',
			)
		);

		$result = ( new ConnectionTester() )->test();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_OK, $result['diagnostic'] );
		$this->assertStringContainsString(
			'Demo Shop',
			$result['message'],
			'Naming the store is what turns "it answered" into "it answered about the shop you meant": a merchant with several stores can paste the wrong identifier and get a perfectly valid answer about somebody else\'s shop.'
		);
		$this->assertNotSame( '', $result['next_step'] );
	}

	/**
	 * A refused key is its own diagnostic.
	 *
	 * @return void
	 */
	public function test_a_refused_key_is_reported_as_an_invalid_credential(): void {
		$this->answer_with( 401, $this->receiver_error( 'invalid_api_key', 'La clave de API no es valida.' ) );

		$result = ( new ConnectionTester() )->test();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_INVALID_CREDENTIAL, $result['diagnostic'] );
		$this->assertStringContainsString( 'API key', $result['next_step'] );
	}

	/**
	 * A key without the permission is a different diagnostic from a key that is
	 * not valid, and the next step names the permission when the answer named
	 * it.
	 *
	 * The name is lifted out of the answer rather than written down here or in
	 * the plugin: which permission this read needs is still being settled, and a
	 * plugin that is distributed cannot correct a name it froze.
	 *
	 * @return void
	 */
	public function test_a_key_without_the_permission_is_reported_apart_and_repeats_the_permission_back(): void {
		$this->answer_with(
			403,
			$this->receiver_error(
				'insufficient_scope',
				"Esta operacion requiere el scope 'stores:read'. Tu API key solo tiene los scopes: invoices:read."
			)
		);

		$result = ( new ConnectionTester() )->test();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_INSUFFICIENT_PERMISSION, $result['diagnostic'] );
		$this->assertStringContainsString(
			'stores:read',
			$result['next_step'],
			'The permission the answer named has to reach the merchant: "your key lacks a permission" without saying which one leaves them clicking through every switch in the account.'
		);
		$this->assertStringNotContainsString(
			'Esta operacion requiere',
			$result['next_step'],
			'Only the name of the permission is repeated back, never the sentence around it: that sentence is written in the language of the account, and this package is English-only.'
		);
	}

	/**
	 * A refusal for lack of permission that names no permission still reports
	 * the same diagnostic, with a next step that works without the name.
	 *
	 * @return void
	 */
	public function test_a_permission_refusal_without_a_name_still_reports_the_same_diagnostic(): void {
		$this->answer_with( 403, $this->receiver_error( 'insufficient_scope', 'No tienes permiso.' ) );

		$result = ( new ConnectionTester() )->test();

		$this->assertSame( ConnectionTester::DIAGNOSTIC_INSUFFICIENT_PERMISSION, $result['diagnostic'] );
		$this->assertStringContainsString( 'read stores', $result['next_step'] );
	}

	/**
	 * A store the account does not hold is its own diagnostic.
	 *
	 * @return void
	 */
	public function test_a_store_outside_the_account_is_reported_as_such(): void {
		$this->answer_with( 404, $this->receiver_error( 'store_not_found', 'La tienda no existe en tu empresa.' ) );

		$result = ( new ConnectionTester() )->test();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_STORE_NOT_IN_ACCOUNT, $result['diagnostic'] );
		$this->assertStringContainsString( 'store identifier', $result['next_step'] );
	}

	/**
	 * Nothing answering at all is a fourth diagnostic, and it names the address
	 * rather than a credential.
	 *
	 * @return void
	 */
	public function test_an_address_that_does_not_answer_is_reported_apart_from_a_credential_problem(): void {
		$this->answer_with_transport_error( 'cURL error 6: Could not resolve host: api.factuarea.example' );

		$result = ( new ConnectionTester() )->test();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_ADDRESS_UNREACHABLE, $result['diagnostic'] );
		$this->assertStringContainsString( 'Could not resolve host', $result['message'] );
	}

	/**
	 * Anything else that comes back is the fifth diagnostic and says so, instead
	 * of being forced into one of the other four.
	 *
	 * @param int    $status Status code of the answer.
	 * @param string $body   Body of the answer.
	 * @return void
	 */
	#[DataProvider( 'unexpected_answers' )]
	public function test_anything_else_is_reported_as_an_unexpected_response( int $status, string $body ): void {
		$this->answer_with_raw_body( $status, $body );

		$result = ( new ConnectionTester() )->test();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( ConnectionTester::DIAGNOSTIC_UNEXPECTED_RESPONSE, $result['diagnostic'] );
		$this->assertStringContainsString( (string) $status, $result['message'] );
	}

	/**
	 * The answers that are none of the four recognised ones.
	 *
	 * The 404 without one of the receiver's error documents is the one that
	 * matters most: it is what an address pointing at the application instead of
	 * at the API answers, and calling it "your store is not in this account"
	 * would send the merchant to change the one setting that is right.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function unexpected_answers(): array {
		return array(
			'a 404 that is not one of the receiver\'s error documents' => array( 404, '<!doctype html><title>Not found</title>' ),
			'a server error with no readable body'                     => array( 502, '<html><body>Bad gateway</body></html>' ),
			'a redirect, which is never followed'                      => array( 301, '' ),
			'a 200 that is not the store document'                     => array( 200, '{"data":{"whatever":true}}' ),
			'a 200 whose body cannot be read at all'                   => array( 200, 'not json' ),
		);
	}

	/**
	 * A 404 that is not one of the receiver's own error documents points at the
	 * address, not at the store identifier.
	 *
	 * @return void
	 */
	public function test_a_404_from_something_that_is_not_the_receiver_points_at_the_address(): void {
		$this->answer_with_raw_body( 404, '<!doctype html><title>Not found</title>' );

		$result = ( new ConnectionTester() )->test();

		$this->assertStringContainsString(
			'API address',
			$result['next_step'],
			'A 404 is also what any host answers for a path it does not serve. Since the delivery address and the API address are two different hosts, the likely cause of this one is the wrong address in that field - not a store that went missing.'
		);
	}

	/**
	 * With a setting missing, nothing is sent and the answer says which setting.
	 *
	 * @return void
	 */
	public function test_nothing_is_requested_while_a_setting_is_missing(): void {
		$this->options[ Constants::OPTION_API_KEY ] = '';
		$this->answer_with( 200, array( 'id' => self::STORE_ID ) );

		$result = ( new ConnectionTester() )->test();

		$this->assertSame( ConnectionTester::DIAGNOSTIC_NOT_CONFIGURED, $result['diagnostic'] );
		$this->assertStringContainsString( 'the API key', $result['message'] );
		$this->assertStringNotContainsString(
			'the store identifier',
			$result['message'],
			'The message names the settings that are actually empty. Naming all of them whenever one is missing is a message the merchant has to check against the screen to use.'
		);

		$this->options[ Constants::OPTION_STORE_ID ] = '';
		$both = ( new ConnectionTester() )->test();

		$this->assertStringContainsString( 'the API key', $both['message'] );
		$this->assertStringContainsString( 'the store identifier', $both['message'] );

		$this->assertSame(
			0,
			$this->requests_made,
			'A test with nothing to send must not send anything: a request with an empty credential comes back 401, and reporting an invalid key when no key was configured sends the merchant to fix a setting that is merely empty.'
		);
	}

	/**
	 * The read goes to the API address with the API key, and never to the
	 * delivery address.
	 *
	 * @return void
	 */
	public function test_the_read_goes_to_the_api_address_with_the_api_key(): void {
		$this->answer_with( 200, array( 'id' => self::STORE_ID ) );

		( new ConnectionTester() )->test();

		$this->assertSame(
			self::API_BASE_URL . '/v1/stores/' . self::STORE_ID,
			$this->requested_url,
			'The v1 is served from its own host. A read aimed at the delivery address answers 404 on every well-configured shop, which is the failure the second address setting exists to remove.'
		);
		$this->assertStringNotContainsString( self::DELIVERY_BASE_URL, $this->requested_url );

		$headers = is_array( $this->requested_arguments['headers'] ?? null ) ? $this->requested_arguments['headers'] : array();

		$this->assertSame(
			'Bearer ' . self::STORED_API_KEY,
			$headers['Authorization'] ?? null,
			'The API key authenticates the read, and it travels in the header rather than in the address: an address ends up in access logs, in browser history and in error reports.'
		);
		$this->assertStringNotContainsString(
			self::STORED_API_KEY,
			$this->requested_url,
			'The key must never be part of the address.'
		);
	}

	/**
	 * With the API address left empty, the read still goes to the default.
	 *
	 * @return void
	 */
	public function test_an_empty_api_address_falls_back_to_the_default(): void {
		$this->options[ Constants::OPTION_API_BASE_URL ] = '';
		$this->answer_with( 200, array( 'id' => self::STORE_ID ) );

		$result = ( new ConnectionTester() )->test();

		$this->assertSame( ConnectionTester::DIAGNOSTIC_OK, $result['diagnostic'] );
		$this->assertSame(
			Constants::DEFAULT_API_BASE_URL . '/v1/stores/' . self::STORE_ID,
			$this->requested_url,
			'The API address is the one setting a merchant of the hosted product never has to know about, so an empty one is the default and not a missing setting.'
		);
	}

	/**
	 * Every outcome carries a diagnostic from the closed catalogue, and the five
	 * distinguishable failures really are five different values.
	 *
	 * @return void
	 */
	public function test_every_outcome_carries_a_distinct_diagnostic_from_the_closed_catalogue(): void {
		$diagnostics = array();

		foreach ( $this->every_outcome() as $label => $result ) {
			$this->assertContains(
				$result['diagnostic'],
				ConnectionTester::DIAGNOSTICS,
				sprintf( 'The outcome "%s" answered with a diagnostic outside the closed catalogue, which the settings screen renders nothing for.', $label )
			);

			$diagnostics[] = $result['diagnostic'];
		}

		$this->assertSame(
			$diagnostics,
			array_values( array_unique( $diagnostics ) ),
			'Two outcomes that share a diagnostic are one outcome as far as the merchant is concerned, and the point of this class is that they are told apart.'
		);
		$this->assertCount( count( ConnectionTester::DIAGNOSTICS ), $diagnostics );
	}

	/**
	 * No message and no next step of any outcome carries the API key, whole or
	 * in a fragment.
	 *
	 * @return void
	 */
	public function test_no_outcome_carries_any_fragment_of_the_api_key(): void {
		$fragments = array(
			self::STORED_API_KEY,
			substr( self::STORED_API_KEY, 0, 8 ),
			substr( self::STORED_API_KEY, -8 ),
		);

		foreach ( $this->every_outcome() as $label => $result ) {
			$haystack = $result['message'] . ' ' . $result['next_step'];

			foreach ( $fragments as $fragment ) {
				$this->assertStringNotContainsString(
					$fragment,
					$haystack,
					sprintf(
						'The outcome "%s" carries "%s", a fragment of the stored API key. A connection test result is read aloud in support threads and pasted into tickets; truncating a credential does not make it safe there, only shorter.',
						$label,
						$fragment
					)
				);
			}
		}
	}

	/**
	 * One result per outcome the class can produce, keyed by a readable label.
	 *
	 * @return array<string, array{ok: bool, diagnostic: string, message: string, next_step: string}>
	 */
	private function every_outcome(): array {
		$outcomes = array();

		$this->answer_with( 200, array( 'id' => self::STORE_ID, 'name' => 'Demo Shop' ) );
		$outcomes['a working connection'] = ( new ConnectionTester() )->test();

		$this->answer_with( 401, $this->receiver_error( 'invalid_api_key', 'Clave no valida.' ) );
		$outcomes['a refused key'] = ( new ConnectionTester() )->test();

		$this->answer_with( 403, $this->receiver_error( 'insufficient_scope', "Requiere el scope 'stores:read'." ) );
		$outcomes['a key without the permission'] = ( new ConnectionTester() )->test();

		$this->answer_with( 404, $this->receiver_error( 'store_not_found', 'No existe.' ) );
		$outcomes['a store outside the account'] = ( new ConnectionTester() )->test();

		$this->answer_with_transport_error( 'Operation timed out after 15001 milliseconds' );
		$outcomes['an address that does not answer'] = ( new ConnectionTester() )->test();

		$this->answer_with_raw_body( 502, 'Bad gateway' );
		$outcomes['an unexpected answer'] = ( new ConnectionTester() )->test();

		$stored_key = $this->options[ Constants::OPTION_API_KEY ];
		$this->options[ Constants::OPTION_API_KEY ] = '';
		$outcomes['nothing configured yet']         = ( new ConnectionTester() )->test();
		$this->options[ Constants::OPTION_API_KEY ] = $stored_key;

		return $outcomes;
	}

	/**
	 * One of the receiver's error documents.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Message as the receiver writes it.
	 * @return array<string, mixed>
	 */
	private function receiver_error( string $code, string $message ): array {
		return array(
			'error' => array(
				'type'       => 'api_error',
				'code'       => $code,
				'message'    => $message,
				'doc_url'    => 'https://docs.factuarea.example/guides/errors#' . $code,
				'request_id' => 'req_0123456789',
			),
		);
	}

	/**
	 * Answers the next request with a JSON document.
	 *
	 * @param int                  $status   Status code to answer with.
	 * @param array<string, mixed> $document Body, encoded as JSON.
	 * @return void
	 */
	private function answer_with( int $status, array $document ): void {
		$this->answer_with_raw_body( $status, (string) wp_json_encode_stand_in( $document ) );
	}

	/**
	 * Answers the next request with a body exactly as given.
	 *
	 * @param int    $status Status code to answer with.
	 * @param string $body   Body to answer with.
	 * @return void
	 */
	private function answer_with_raw_body( int $status, string $body ): void {
		$this->double_request(
			array(
				'response' => array( 'code' => $status ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => $body,
			)
		);
	}

	/**
	 * Answers the next request with a transport failure.
	 *
	 * @param string $reason Reason the request did not complete.
	 * @return void
	 */
	private function answer_with_transport_error( string $reason ): void {
		$this->double_request( new FakeTransportError( $reason ) );
	}

	/**
	 * Sets what the doubled read answers with next.
	 *
	 * @param mixed $answer What the WordPress HTTP API is to return.
	 * @return void
	 */
	private function double_request( $answer ): void {
		$this->next_answer = $answer;
	}
}

/**
 * A stand-in for the WordPress error object, recognised by the doubled
 * `is_wp_error` of this file.
 *
 * It is NOT named `WP_Error` and it is not aliased to it: another file of this
 * one suite already owns that alias, and two files claiming the same class name
 * is a fatal error at load time rather than a failure anybody can read.
 */
final class FakeTransportError {

	/**
	 * The message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $message Reason the request did not complete.
	 */
	public function __construct( string $message ) {
		$this->message = $message;
	}

	/**
	 * The message, as the WordPress error object exposes it.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * JSON encoding, without depending on the WordPress wrapper being loaded.
 *
 * @param mixed $value Anything encodable.
 * @return string
 */
function wp_json_encode_stand_in( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is test scaffolding, not plugin code: the WordPress wrapper is not loaded in the Unit suite.
}

/**
 * Tag stripping, standing in for the part of `sanitize_text_field` this test
 * needs.
 *
 * @param string $value Anything.
 * @return string
 */
function wp_strip_all_tags_stand_in( string $value ): string {
	return (string) preg_replace( '/<[^>]*>/', '', $value );
}
