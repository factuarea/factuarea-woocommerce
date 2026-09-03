<?php
/**
 * Unit test of the buyer's access to the invoice of their own order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\MyAccount\InvoicePdfLink;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE ACCESS APPEARS ONLY WITH A CORRELATION. An order whose invoice is
 *     known shows the access; one whose invoice is unknown shows NOTHING - the
 *     assertion is on the empty string and not on the absence of a word,
 *     because a disabled button with a promise on it would satisfy the second
 *     shape and is exactly what the requirement forbids.
 *
 *  2. OWNERSHIP GOVERNS BOTH THE MARKUP AND THE DOWNLOAD. Somebody else's
 *     order, a visitor with no session and a buyer whose shop revoked their
 *     order capability all get nothing rendered and nothing served. The
 *     download cases additionally assert that ZERO outgoing requests were made:
 *     a refusal issued after the document was fetched is one `return` away from
 *     serving it, and it also spends the merchant's API budget on a request
 *     nobody was entitled to.
 *
 *  3. WHAT IS SERVED IS WHAT CAME BACK. The bytes are relayed verbatim -
 *     asserted with a body carrying non-text bytes, so a re-encode or an escape
 *     shows up as a failure rather than as a corrupted download somebody
 *     reports months later - and an answer that is NOT a document is never
 *     relayed as one.
 *
 *  4. THE CREDENTIAL STAYS ON THE SERVER. It is asserted present in the
 *     authorisation header of the outgoing read, and absent from the markup,
 *     from the address handed to the browser and from every refusal message.
 *     Both directions matter: a key that never leaves is useless if it also
 *     never authenticates the read.
 *
 *  5. THE SWEEP. The file writes nothing to a log. This class is one of the two
 *     that hold the API key in a variable, and the first log line somebody adds
 *     while debugging a merchant's shop is always the one that carries it.
 */
final class InvoicePdfLinkTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Stored API key. Recognisable on sight so that a sweep failure names
	 * something a reader can find.
	 */
	private const STORED_API_KEY = 'fk_live_APIKEYVALUE0123456789';

	/**
	 * Address the v1 reads are made against in these tests.
	 */
	private const API_BASE_URL = 'https://api.factuarea.example';

	/**
	 * Address deliveries are posted to. It is here to be asserted ABSENT from
	 * the read: the two are different hosts, and reading from the delivery one
	 * is the mistake the split setting exists to prevent.
	 */
	private const DELIVERY_BASE_URL = 'https://app.factuarea.example';

	/**
	 * Identifier of the invoice correlated with the order of most cases.
	 */
	private const INVOICE_ID = '0f4d4d0b-6c7b-4a35-9e2e-6a1b0a3d1c77';

	/**
	 * Identifier of the order of most cases.
	 */
	private const ORDER_ID = 501;

	/**
	 * Identifier of the logged-in buyer of most cases.
	 */
	private const BUYER_ID = 77;

	/**
	 * Bytes of the document. They are deliberately NOT all printable: a relay
	 * that re-encoded or escaped the body would change them, and a body made of
	 * letters would survive that unchanged.
	 */
	private const PDF_BYTES = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n\xDE\xAD\xBE\xEF\n%%EOF\n";

	/**
	 * The options as the double of `get_option` sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Orders the doubled `wc_get_order` answers from, keyed by identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $orders = array();

	/**
	 * Identifier the doubled `get_current_user_id` answers with.
	 *
	 * @var int
	 */
	private int $current_user_id = self::BUYER_ID;

	/**
	 * What the doubled `current_user_can` answers.
	 *
	 * @var bool
	 */
	private bool $capability_granted = true;

	/**
	 * Capability checks the doubled `current_user_can` was asked, as pairs of
	 * capability and argument.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $capability_checks = array();

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
	 * up as two expectations and the first one keeps answering.
	 *
	 * @var mixed
	 */
	private $next_answer = null;

	/**
	 * Installs the doubles of the WordPress functions the class under test
	 * reaches for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->options = array(
			Constants::OPTION_BASE_URL     => self::DELIVERY_BASE_URL,
			Constants::OPTION_API_BASE_URL => self::API_BASE_URL,
			Constants::OPTION_API_KEY      => self::STORED_API_KEY,
		);

		$this->orders              = array();
		$this->current_user_id     = self::BUYER_ID;
		$this->capability_granted  = true;
		$this->capability_checks   = array();
		$this->requested_url       = '';
		$this->requested_arguments = array();
		$this->requests_made       = 0;
		$this->next_answer         = null;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);

		Functions\when( 'wc_get_order' )->alias(
			function ( $order_id ) {
				return $this->orders[ (int) $order_id ] ?? false;
			}
		);

		Functions\when( 'get_current_user_id' )->alias(
			function (): int {
				return $this->current_user_id;
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function ( string $capability, $argument = null ): bool {
				$this->capability_checks[] = array( $capability, $argument );

				return $this->capability_granted;
			}
		);

		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.example/wp-admin/' . $path;
			}
		);

		Functions\when( 'add_query_arg' )->alias(
			static function ( array $arguments, string $url ): string {
				return $url . '?' . http_build_query( $arguments );
			}
		);

		Functions\when( 'wp_create_nonce' )->alias(
			static function ( string $action ): string {
				return 'nonce-' . $action;
			}
		);

		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $arguments ) {
				$this->requested_url       = $url;
				$this->requested_arguments = $arguments;
				++$this->requests_made;

				return $this->next_answer;
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof FakeInvoicePdfTransportError;
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
	 * The two surfaces that show the access and the two handlers that serve it
	 * are all hooked.
	 *
	 * The unauthenticated handler is asserted because its ABSENCE is not a
	 * closed door: an `admin-post.php` action registered only for logged-in
	 * users answers a visitor with an empty 200, which reads as a broken
	 * download rather than as a refusal.
	 *
	 * @return void
	 */
	public function test_it_hooks_both_surfaces_and_both_handlers(): void {
		Actions\expectAdded( 'woocommerce_order_details_after_order_table' )->once();
		Filters\expectAdded( 'woocommerce_my_account_my_orders_actions' )->once();
		Actions\expectAdded( 'admin_post_' . InvoicePdfLink::DOWNLOAD_ACTION )->once();
		Actions\expectAdded( 'admin_post_nopriv_' . InvoicePdfLink::DOWNLOAD_ACTION )->once();

		( new InvoicePdfLink() )->register();

		$this->assertSame(
			'factuarea_wc_invoice_pdf',
			InvoicePdfLink::DOWNLOAD_ACTION,
			'The action name is the address the buyer\'s browser posts to; changing it breaks every link already printed on a page.'
		);
	}

	/**
	 * A known correlation shows the access, and the address it points at is
	 * this shop's own.
	 *
	 * @return void
	 */
	public function test_a_known_correlation_shows_the_access(): void {
		$markup = $this->render( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertStringContainsString( 'Download invoice', $markup );
		$this->assertStringContainsString( 'https://shop.example/wp-admin/admin-post.php', $markup );
		$this->assertStringContainsString( 'action=' . InvoicePdfLink::DOWNLOAD_ACTION, $markup );
		$this->assertStringContainsString( 'order_id=' . self::ORDER_ID, $markup );
		$this->assertStringContainsString(
			'_wpnonce=nonce-' . InvoicePdfLink::DOWNLOAD_ACTION . '_' . self::ORDER_ID,
			$markup,
			'The nonce names the order, so an address issued for one order does not verify for another.'
		);
	}

	/**
	 * No correlation, no markup at all.
	 *
	 * The assertion is on the empty string on purpose. A disabled control, a
	 * "your invoice is on its way" line or a link to an explanation would all
	 * pass a check for the absence of the download word, and all three are the
	 * promise the requirement forbids.
	 *
	 * @return void
	 */
	public function test_an_unknown_correlation_shows_nothing_at_all(): void {
		$this->assertSame( '', $this->render( $this->order( self::BUYER_ID, '' ) ) );
	}

	/**
	 * The order of somebody else shows nothing, even though it has an invoice.
	 *
	 * @return void
	 */
	public function test_an_order_of_another_buyer_shows_nothing(): void {
		$this->assertSame( '', $this->render( $this->order( self::BUYER_ID + 1, self::INVOICE_ID ) ) );
	}

	/**
	 * A guest order shows nothing, and the two zeroes do not agree.
	 *
	 * A guest order carries a customer identifier of zero, and a visitor with
	 * no session is also zero. An ownership check written as a bare comparison
	 * would call those two equal and show a stranger the access to somebody's
	 * guest order.
	 *
	 * @return void
	 */
	public function test_a_guest_order_shows_nothing_to_a_visitor_with_no_session(): void {
		$this->current_user_id = 0;

		$this->assertSame( '', $this->render( $this->order( 0, self::INVOICE_ID ) ) );
	}

	/**
	 * A buyer whose shop revoked the order capability sees nothing.
	 *
	 * @return void
	 */
	public function test_a_buyer_without_the_order_capability_sees_nothing(): void {
		$this->capability_granted = false;

		$this->assertSame( '', $this->render( $this->order( self::BUYER_ID, self::INVOICE_ID ) ) );
		$this->assertSame(
			array( array( InvoicePdfLink::VIEW_ORDER_CAPABILITY, self::ORDER_ID ) ),
			$this->capability_checks,
			'The capability is asked of WooCommerce for THIS order; asking it without the order would answer about orders in general.'
		);
	}

	/**
	 * The row of the orders list gains the access when there is an invoice, and
	 * is left untouched when there is not.
	 *
	 * @return void
	 */
	public function test_the_orders_list_row_gains_the_access_only_with_a_correlation(): void {
		$existing = array( 'view' => array( 'url' => 'https://shop.example/order/501', 'name' => 'View' ) );

		$with = ( new InvoicePdfLink() )->add_orders_list_action( $existing, $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertCount( 2, $with );
		$this->assertArrayHasKey( 'view', $with, 'The actions WooCommerce already built are kept.' );

		$without = ( new InvoicePdfLink() )->add_orders_list_action( $existing, $this->order( self::BUYER_ID, '' ) );

		$this->assertSame( $existing, $without );
	}

	/**
	 * Neither credential nor invoice identity reaches the page.
	 *
	 * The API key is the requirement. The invoice identifier is asserted with
	 * it because the address deliberately does not carry one: an address that
	 * named the invoice would be an address somebody could edit into naming
	 * another one, and the ownership check would then be the only thing left.
	 *
	 * @return void
	 */
	public function test_the_markup_carries_neither_the_api_key_nor_the_invoice_identity(): void {
		$markup = $this->render( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertNotSame( '', $markup, 'The sweep would be vacuous over a page with nothing on it.' );
		$this->assert_carries_no_credential( $markup, 'the order detail markup' );
		$this->assertStringNotContainsString( self::INVOICE_ID, $markup );
	}

	/**
	 * The owner of a correlated order receives the document, byte for byte.
	 *
	 * @return void
	 */
	public function test_the_owner_of_a_correlated_order_receives_the_document(): void {
		$this->answer_with_document( self::PDF_BYTES );

		$result = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( InvoicePdfLink::STATUS_OK, $result['status'] );
		$this->assertSame( self::PDF_BYTES, $result['body'], 'The bytes are relayed as they arrived; a re-encode or an escape breaks the document.' );
		$this->assertSame( InvoicePdfLink::PDF_CONTENT_TYPE, $result['content_type'] );
		$this->assertSame( 'invoice-order-' . self::ORDER_ID . '.pdf', $result['filename'] );
	}

	/**
	 * The read carries the merchant's key, asks for a document, and goes to the
	 * API address rather than to the delivery one.
	 *
	 * @return void
	 */
	public function test_the_read_is_made_against_the_api_address_with_the_merchant_key(): void {
		$this->answer_with_document( self::PDF_BYTES );

		$this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( 1, $this->requests_made );
		$this->assertSame(
			self::API_BASE_URL . '/v1/invoices/' . self::INVOICE_ID . '/pdf',
			$this->requested_url
		);
		$this->assertStringNotContainsString(
			self::DELIVERY_BASE_URL,
			$this->requested_url,
			'Reading from the address deliveries are posted to answers 404: the v1 routes are served on the API host only.'
		);
		$this->assertSame( 'Bearer ' . self::STORED_API_KEY, $this->requested_arguments['headers']['Authorization'] );
		$this->assertSame( InvoicePdfLink::PDF_CONTENT_TYPE, $this->requested_arguments['headers']['Accept'] );
		$this->assertStringNotContainsString(
			self::STORED_API_KEY,
			$this->requested_url,
			'The key travels in a header. In the address it would land in every access log between here and there.'
		);
	}

	/**
	 * An invoice identifier is escaped into the address rather than pasted into
	 * it.
	 *
	 * @return void
	 */
	public function test_the_invoice_identifier_is_escaped_into_the_address(): void {
		$this->answer_with_document( self::PDF_BYTES );

		$this->resolve( $this->order( self::BUYER_ID, 'a b/../c' ) );

		$this->assertSame(
			self::API_BASE_URL . '/v1/invoices/a%20b%2F..%2Fc/pdf',
			$this->requested_url,
			'An identifier pasted raw would let a slash inside it address another route entirely.'
		);
	}

	/**
	 * Everything that is not an owner is refused with the same answer, and
	 * before any request is made.
	 *
	 * The three cases share one answer on purpose: told apart, they let anybody
	 * walk the order identifiers of the shop and learn which ones exist. They
	 * are asserted TOGETHER rather than one by one, because "these three are
	 * indistinguishable" is a property of the set.
	 *
	 * @return void
	 */
	public function test_every_request_that_is_not_the_owner_is_refused_identically_and_without_a_request(): void {
		$answers = array();

		$answers['another buyer'] = $this->resolve( $this->order( self::BUYER_ID + 1, self::INVOICE_ID ) );

		$this->current_user_id     = 0;
		$answers['no session']     = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );
		$this->current_user_id     = self::BUYER_ID;

		$this->orders             = array();
		$answers['no such order'] = ( new InvoicePdfLink() )->resolve_download( 4242, self::BUYER_ID );

		$this->capability_granted   = false;
		$answers['capability gone'] = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		foreach ( $answers as $case => $answer ) {
			$this->assertSame( InvoicePdfLink::STATUS_REFUSED, $answer['status'], 'Refused: ' . $case );
			$this->assertNull( $answer['filename'], 'A refusal offers no file: ' . $case );
			$this->assertNotSame( InvoicePdfLink::PDF_CONTENT_TYPE, $answer['content_type'], 'Refused: ' . $case );
		}

		$this->assertCount( 1, array_unique( array_map( 'serialize', $answers ) ), 'The four refusals must be indistinguishable from outside.' );
		$this->assertSame( 0, $this->requests_made, 'Ownership is settled before the network is touched: a refused request must not spend the merchant\'s API budget.' );
	}

	/**
	 * An order of one's own with no invoice yet says exactly that, and asks
	 * nobody.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_correlation_is_answered_without_a_request(): void {
		$result = $this->resolve( $this->order( self::BUYER_ID, '' ) );

		$this->assertSame( InvoicePdfLink::STATUS_NO_INVOICE, $result['status'] );
		$this->assertNull( $result['filename'] );
		$this->assertSame( 0, $this->requests_made, 'There is no identifier to ask about, so there is nothing to ask.' );
	}

	/**
	 * A shop that has not been configured yet asks nobody either.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_api_key_is_answered_without_a_request(): void {
		$this->options[ Constants::OPTION_API_KEY ] = '';

		$result = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( InvoicePdfLink::STATUS_UNAVAILABLE, $result['status'] );
		$this->assertSame( 0, $this->requests_made, 'An unauthenticated read would be refused anyway; making it only tells the receiver about it.' );
	}

	/**
	 * A request that never completed is unavailable, not a document.
	 *
	 * @return void
	 */
	public function test_a_transport_failure_is_answered_as_unavailable(): void {
		$this->next_answer = new FakeInvoicePdfTransportError( 'cURL error 28: Operation timed out after 15000 milliseconds' );

		$result = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( InvoicePdfLink::STATUS_UNAVAILABLE, $result['status'] );
		$this->assertNull( $result['filename'] );
		$this->assertStringNotContainsString(
			'cURL',
			(string) $result['body'],
			'What went wrong between this shop and Factuarea is a diagnosis for the merchant, who has the connection test; on a public page it is a description of the shop\'s configuration handed to whoever asks.'
		);
	}

	/**
	 * Every answer that is not a document is unavailable, and none of them is
	 * relayed.
	 *
	 * The 200 cases are the ones worth having. A captive portal, a proxy or a
	 * security appliance in front of this shop answers an ordinary 200 with an
	 * HTML page, and relaying that as `application/pdf` hands the buyer a file
	 * their reader refuses to open with nothing anywhere saying why.
	 *
	 * @param int    $status       Status the receiver answered with.
	 * @param string $content_type Content type it declared.
	 * @param string $body         Body it sent.
	 * @return void
	 */
	#[DataProvider( 'answers_that_are_not_a_document' )]
	public function test_an_answer_that_is_not_a_document_is_never_relayed( int $status, string $content_type, string $body ): void {
		$this->answer_with( $status, $content_type, $body );

		$result = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( InvoicePdfLink::STATUS_UNAVAILABLE, $result['status'] );
		$this->assertNull( $result['filename'] );
		$this->assertNotSame( InvoicePdfLink::PDF_CONTENT_TYPE, $result['content_type'] );
		$this->assertNotSame( $body, $result['body'], 'Nothing that is not a document is relayed to the buyer.' );
	}

	/**
	 * The answers that must never become a download.
	 *
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function answers_that_are_not_a_document(): array {
		return array(
			'a refused key'              => array( 401, 'application/problem+json', '{"code":"invalid_api_key"}' ),
			'a key without the scope'    => array( 403, 'application/problem+json', '{"code":"insufficient_scope"}' ),
			'an invoice of another shop' => array( 404, 'application/problem+json', '{"code":"not_found"}' ),
			'a receiver that broke'      => array( 500, 'text/html', '<html><body>500</body></html>' ),
			'a proxy answering 200'      => array( 200, 'text/html; charset=utf-8', '<html><body>Sign in</body></html>' ),
			'a 200 with no document'     => array( 200, 'application/pdf', '' ),
			'a redirect'                 => array( 302, 'text/html', '' ),
		);
	}

	/**
	 * A content type carrying parameters is still a document.
	 *
	 * Both forms are sent in the wild, and refusing the parameterised one would
	 * break the download against a perfectly correct receiver.
	 *
	 * @return void
	 */
	public function test_a_document_declared_with_parameters_is_still_relayed(): void {
		$this->answer_with( 200, 'Application/PDF; charset=binary', self::PDF_BYTES );

		$result = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) );

		$this->assertSame( InvoicePdfLink::STATUS_OK, $result['status'] );
		$this->assertSame( self::PDF_BYTES, $result['body'] );
	}

	/**
	 * No refusal, of any kind, carries the credential.
	 *
	 * @return void
	 */
	public function test_no_refusal_carries_the_api_key(): void {
		$bodies = array();

		$bodies['not the owner'] = $this->resolve( $this->order( self::BUYER_ID + 1, self::INVOICE_ID ) )['body'];
		$bodies['no invoice']    = $this->resolve( $this->order( self::BUYER_ID, '' ) )['body'];

		$this->next_answer      = new FakeInvoicePdfTransportError( 'could not resolve host: api.factuarea.example' );
		$bodies['unreachable']  = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) )['body'];

		$this->answer_with( 401, 'application/problem+json', '{"code":"invalid_api_key"}' );
		$bodies['refused key'] = $this->resolve( $this->order( self::BUYER_ID, self::INVOICE_ID ) )['body'];

		foreach ( $bodies as $case => $body ) {
			$this->assertNotSame( '', (string) $body, 'A refusal with no message tells the buyer nothing: ' . $case );
			$this->assert_carries_no_credential( (string) $body, 'the refusal for ' . $case );
		}
	}

	/**
	 * The file writes nothing anywhere.
	 *
	 * It is the cheapest way to keep the prohibition on writing a credential
	 * into a log from eroding: the first log line somebody adds "to debug this"
	 * is always the one that carries the key or the address it was sent to.
	 *
	 * @return void
	 */
	public function test_the_file_writes_to_no_log(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/MyAccount/InvoicePdfLink.php' );

		foreach ( array( 'error_log', 'wc_get_logger', 'trigger_error', 'var_dump', 'print_r', 'file_put_contents' ) as $writer ) {
			$this->assertStringNotContainsString(
				$writer . '(',
				$source,
				'This class holds the API key in a variable; it must contain no way of writing anything anywhere.'
			);
		}
	}

	/**
	 * Runs the order-detail rendering and returns everything it printed.
	 *
	 * @param MockInterface $order Order double to render for.
	 * @return string Whatever was printed.
	 */
	private function render( MockInterface $order ): string {
		ob_start();

		try {
			( new InvoicePdfLink() )->render_invoice_access( $order );
		} finally {
			$printed = (string) ob_get_clean();
		}

		return $printed;
	}

	/**
	 * Runs the download decision for the buyer currently logged in.
	 *
	 * @param MockInterface $order Order double the buyer is asking about.
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function resolve( MockInterface $order ): array {
		unset( $order );

		return ( new InvoicePdfLink() )->resolve_download( self::ORDER_ID, $this->current_user_id );
	}

	/**
	 * An order double that the doubled `wc_get_order` will hand back.
	 *
	 * @param int    $customer_id Customer of the order; zero for a guest order.
	 * @param string $invoice_id  Correlated invoice, or an empty string.
	 * @return MockInterface
	 */
	private function order( int $customer_id, string $invoice_id ): MockInterface {
		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $customer_id );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $invoice_id ) {
				return Constants::INVOICE_ID_META_KEY === $key ? $invoice_id : '';
			}
		);

		$this->orders[ self::ORDER_ID ] = $order;

		return $order;
	}

	/**
	 * Sets what the doubled read answers with next.
	 *
	 * @param int    $status       Status code.
	 * @param string $content_type Content type header.
	 * @param string $body         Body.
	 * @return void
	 */
	private function answer_with( int $status, string $content_type, string $body ): void {
		$this->next_answer = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => $content_type ),
			'body'     => $body,
		);
	}

	/**
	 * Sets the doubled read to answer with a document.
	 *
	 * @param string $bytes Bytes of the document.
	 * @return void
	 */
	private function answer_with_document( string $bytes ): void {
		$this->answer_with( 200, InvoicePdfLink::PDF_CONTENT_TYPE, $bytes );
	}

	/**
	 * Fails when a haystack carries the stored API key, whole or in a fragment.
	 *
	 * The fragment matters as much as the whole value: a masked form that keeps
	 * the first or the last few characters is the shape this mistake usually
	 * takes, and it is not a safer credential, only a shorter one.
	 *
	 * @param string $haystack What the surface produced.
	 * @param string $surface  Name of the surface, for the failure message.
	 * @return void
	 */
	private function assert_carries_no_credential( string $haystack, string $surface ): void {
		$this->assertStringNotContainsString( self::STORED_API_KEY, $haystack, 'The API key appears in ' . $surface . '.' );

		foreach ( array( substr( self::STORED_API_KEY, 0, 12 ), substr( self::STORED_API_KEY, -12 ) ) as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$haystack,
				'A fragment of the API key appears in ' . $surface . '; a truncated credential is a shorter one, not a safer one.'
			);
		}
	}
}

/**
 * A stand-in for the WordPress error object, recognised by the doubled
 * `is_wp_error` of this file.
 *
 * It is NOT named `WP_Error` and it is not aliased to it: another file of this
 * one suite already owns that alias, and two files claiming the same class name
 * is a fatal error at load time rather than a failure anybody can read. The
 * name is this file's own for the same reason - a second `FakeTransportError`
 * in the shared namespace would collide with the one the connection test
 * declares.
 */
final class FakeInvoicePdfTransportError {

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
