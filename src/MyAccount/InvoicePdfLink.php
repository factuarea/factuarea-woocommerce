<?php
/**
 * The buyer's access to the invoice of their own order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\MyAccount;

use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\HttpResponse;
use Factuarea\WooCommerce\Transport\WordPressHttpClient;
use WC_Order;

/**
 * Shows a buyer the invoice of an order, and serves it, when - and only when -
 * this plugin knows which invoice that is and the buyer is the one who bought.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS SHOWN UNLESS THE CORRELATION IS KNOWN
 * ---------------------------------------------------------------------------
 * The one signal that an order has an invoice is the meta key
 * `Constants::INVOICE_ID_META_KEY` carrying a non-empty value, and the only
 * thing that ever writes it is `InvoiceCorrelationEndpoint`, after verifying a
 * signed callback. There is no second source, and in particular there is no
 * derivation: this plugin does not look for an invoice by the amount of the
 * order, by its date, or by any other coincidence.
 *
 * That prohibition is the whole point rather than caution. A correlation by
 * amount and date works for months on a small shop and then, on the day two
 * customers buy the same thing on the same morning, hands one of them the
 * invoice of the other - a document carrying somebody else's name, address and
 * tax identifier. There is no version of that failure that is acceptable, and
 * no amount of testing that finds it before a customer does.
 *
 * When the correlation is unknown, nothing at all is rendered. Not a disabled
 * button, not a "coming soon" notice, not a link to a page that explains why
 * there is no link: an element that promises a document the shop cannot produce
 * generates a support request per order, and the buyer cannot act on any of it.
 *
 * ---------------------------------------------------------------------------
 * THE API KEY NEVER REACHES THE BROWSER
 * ---------------------------------------------------------------------------
 * The document lives behind the Factuarea public API and is read with the
 * MERCHANT's API key. That key is a credential for the merchant's whole
 * account - every invoice, every client, every store - so it never appears in
 * the markup, never in a query argument, and never in a redirect.
 *
 * So the buyer is not sent to Factuarea at all. They are sent to this site,
 * which checks who they are, then makes the read itself, from the server, and
 * relays the bytes back. What the browser ever sees is an address of this shop
 * carrying an order identifier and a nonce.
 *
 * This is also why the answer is relayed rather than redirected to. Answering
 * the buyer with a redirect to an address that carries the credential - in a
 * header, in a query argument, in a signed link built from it - would put the
 * credential in the browser's history, in the referrer of whatever the buyer
 * clicks next, and in the access log of every hop in between.
 *
 * ---------------------------------------------------------------------------
 * OWNERSHIP IS CHECKED BEFORE THE NETWORK IS TOUCHED
 * ---------------------------------------------------------------------------
 * `resolve_download()` establishes who is asking and what they own BEFORE it
 * builds a URL, before it reads the API key out of the options and before it
 * makes any request. The order matters for two separate reasons: a request that
 * is going to be refused must not spend the merchant's API budget or leave a
 * download recorded against their account, and a check performed after the
 * document is already in memory is one `return` away from serving it.
 *
 * Two conditions have to hold, and both of them:
 *
 *  1. The order's customer is the logged-in user. This one does not depend on
 *     WooCommerce's capability mapping being loaded, and it is the condition
 *     this plugin can reason about on its own.
 *  2. `current_user_can( 'view_order', … )` agrees. This one honours whatever
 *     the shop itself decided - a role plugin that revoked order access, a
 *     membership that expired - and refusing when WooCommerce refuses is the
 *     only way this plugin does not become the hole in somebody else's policy.
 *
 * A guest order - one with no customer account - therefore shows nothing and
 * serves nothing. It has no logged-in owner to compare against, and the only
 * thing that could stand in for one is the order key in the address bar, which
 * travels in referrers, in shared links and in mail clients. The invoice of a
 * guest order is available to the merchant, who can send it.
 *
 * ---------------------------------------------------------------------------
 * WHAT A REFUSAL SAYS, AND WHAT IT DOES NOT
 * ---------------------------------------------------------------------------
 *   200  the bytes of the PDF, as a download.
 *   403  the request was not made by the owner of an existing order.
 *   404  the order is the buyer's and no invoice is known for it yet.
 *   503  the shop's connection to Factuarea did not produce the document.
 *
 * The 403 is one answer for four different situations - no such order, an order
 * belonging to somebody else, a visitor who is not logged in, and a request
 * whose nonce did not hold. Telling them apart would let anybody walk the order
 * identifiers of the shop and learn which ones exist, and would tell a
 * legitimate buyer nothing they can act on. It is the same reasoning, and the
 * same shape, as the single 401 of `InvoiceCorrelationEndpoint`.
 *
 * The 404 is reachable ONLY by somebody who has already proved they own the
 * order, so it reveals nothing to anybody else.
 *
 * The 503 never carries the reason. Whether Factuarea refused the key, answered
 * something that was not a document, or did not answer at all is a diagnosis
 * for the MERCHANT, and the merchant has one on the settings screen - the
 * connection test, which says which of those it was and what to do about it.
 * Relaying it to the buyer would put details of the shop's configuration on a
 * public page in exchange for nothing the buyer can use.
 *
 * Nothing here writes to a log. Not the API key, obviously, but not the URL,
 * the transport error or the response either: this class is one of the few that
 * holds the credential in a variable, and the cheapest way for it never to
 * leak is for this file to contain no way of writing anything anywhere.
 */
final class InvoicePdfLink {

	/**
	 * Name of the `admin-post.php` action that serves the document.
	 *
	 * The download hangs off `admin-post.php` and not off a REST route or a
	 * query argument of the shop front, because it is the one entry point
	 * WordPress already gives to an authenticated action that answers with
	 * something other than a page: it runs with the user loaded, it dispatches
	 * by this name, and it has a companion hook for visitors who are not logged
	 * in, which is what lets those be refused rather than silently served the
	 * home page.
	 */
	public const DOWNLOAD_ACTION = 'factuarea_wc_invoice_pdf';

	/**
	 * Query argument carrying the order the buyer is asking about.
	 */
	public const ORDER_ID_ARGUMENT = 'order_id';

	/**
	 * Capability asked of the current user, in addition to being the customer
	 * of the order. It is WooCommerce's own meta capability for reading one
	 * order, so a shop that changed who may read orders changes this too.
	 */
	public const VIEW_ORDER_CAPABILITY = 'view_order';

	/**
	 * Answer carrying the document.
	 */
	public const STATUS_OK = 200;

	/**
	 * Answer to anybody who is not the owner of an existing order. One status
	 * for the four ways of not being one; see the class block.
	 */
	public const STATUS_REFUSED = 403;

	/**
	 * Answer when the order is the buyer's and no invoice is known for it.
	 */
	public const STATUS_NO_INVOICE = 404;

	/**
	 * Answer when the shop's connection to Factuarea did not produce the
	 * document.
	 */
	public const STATUS_UNAVAILABLE = 503;

	/**
	 * Content type of the document, and the only one this class relays.
	 */
	public const PDF_CONTENT_TYPE = 'application/pdf';

	/**
	 * Content type of every answer that is not the document.
	 */
	public const TEXT_CONTENT_TYPE = 'text/plain; charset=utf-8';

	/**
	 * Pattern of the file name offered to the browser.
	 *
	 * It names the ORDER and not the invoice, on purpose. The buyer knows their
	 * order number and does not know the identifier Factuarea gives the
	 * invoice, so an order number is the name that means something in a
	 * downloads folder - and keeping the invoice identifier out of the file name
	 * keeps it out of the one place a downloaded document tends to end up
	 * mirrored to, backed up and shared from.
	 *
	 * The only substitution is an integer, so the value needs no escaping to be
	 * safe inside the header that carries it.
	 */
	public const FILENAME_PATTERN = 'invoice-order-%d.pdf';

	/**
	 * Key this class adds to the actions of a row of the orders list.
	 */
	private const ORDERS_LIST_ACTION_KEY = 'factuarea-invoice';

	/**
	 * Reads the document from the Factuarea API on the merchant's behalf.
	 *
	 * @var WordPressHttpClient|null
	 */
	private ?WordPressHttpClient $client;

	/**
	 * Constructor.
	 *
	 * @param WordPressHttpClient|null $client Transport used to read the
	 *                                         document; built on first use when
	 *                                         not given.
	 */
	public function __construct( ?WordPressHttpClient $client = null ) {
		$this->client = $client;
	}

	/**
	 * Hooks the two places the access appears and the two that serve it.
	 *
	 * The unauthenticated companion is registered deliberately and does not
	 * serve. An `admin-post.php` action registered only for logged-in users
	 * answers a visitor with a bare zero and a 200, which is indistinguishable
	 * from a broken download; registering it lets a visitor be told, with a
	 * status that says so, that this document is not theirs to fetch.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_invoice_access' ), 10, 1 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_orders_list_action' ), 10, 2 );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ), 10, 0 );
		add_action( 'admin_post_nopriv_' . self::DOWNLOAD_ACTION, array( $this, 'handle_unauthenticated_download' ), 10, 0 );
	}

	/**
	 * Prints the access under the order table of the order detail, or prints
	 * nothing.
	 *
	 * The parameter is not type-hinted as an order because this action is fired
	 * from more than one template and is re-fired by other plugins; a hint would
	 * turn a caller that passes something else into a fatal error on the
	 * buyer's account page. It is checked instead.
	 *
	 * @param mixed $order What the template passed, expected to be an order.
	 * @return void
	 */
	public function render_invoice_access( $order ): void {
		if ( ! $order instanceof WC_Order || ! $this->should_offer( $order ) ) {
			return;
		}

		printf(
			'<p class="factuarea-invoice-pdf"><a href="%1$s" class="button factuarea-invoice-pdf__link">%2$s</a></p>',
			esc_url( $this->download_url( $order->get_id() ) ),
			esc_html__( 'Download invoice (PDF)', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * Adds the access to one row of the orders list, or leaves the row alone.
	 *
	 * @param mixed $actions Actions of the row, as WooCommerce built them.
	 * @param mixed $order   Order of the row.
	 * @return array<string, array{url: string, name: string}> The actions, with
	 *         ours added when there is an invoice to offer.
	 */
	public function add_orders_list_action( $actions, $order ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $order instanceof WC_Order || ! $this->should_offer( $order ) ) {
			return $actions;
		}

		$actions[ self::ORDERS_LIST_ACTION_KEY ] = array(
			'url'  => $this->download_url( $order->get_id() ),
			'name' => __( 'Invoice', 'factuarea-for-woocommerce' ),
		);

		return $actions;
	}

	/**
	 * Address the access points at.
	 *
	 * It carries the order and a nonce, and nothing else. In particular it does
	 * NOT carry the identifier of the invoice: the buyer never needs it, and an
	 * address that named it would be an address that could be edited into
	 * naming another one.
	 *
	 * @param int $order_id Order the buyer is asking about.
	 * @return string Absolute address of the download.
	 */
	public function download_url( int $order_id ): string {
		return add_query_arg(
			array(
				'action'                => self::DOWNLOAD_ACTION,
				self::ORDER_ID_ARGUMENT => $order_id,
				'_wpnonce'              => wp_create_nonce( $this->nonce_action( $order_id ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Serves the download requested by a logged-in visitor.
	 *
	 * @return void
	 */
	public function handle_download(): void {
		$this->emit( $this->resolve_request() );
	}

	/**
	 * Refuses the download requested by a visitor who is not logged in.
	 *
	 * Nothing is read out of the request here, not even the order identifier:
	 * there is no visitor this answer could ever be anything else for.
	 *
	 * @return void
	 */
	public function handle_unauthenticated_download(): void {
		$this->emit( $this->refused() );
	}

	/**
	 * Decides what a buyer asking for the invoice of an order is to receive.
	 *
	 * This is the whole decision and it prints nothing, sends no header and ends
	 * no request, which is what makes it possible to assert every branch -
	 * including the one that serves a document - instead of asserting the two
	 * that happen to be reachable without a running WordPress.
	 *
	 * @param int $order_id        Order the buyer is asking about.
	 * @param int $current_user_id User making the request; 0 when nobody is
	 *                             logged in.
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	public function resolve_download( int $order_id, int $current_user_id ): array {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || ! $this->owns( $order, $current_user_id ) ) {
			return $this->refused();
		}

		$invoice_id = $this->invoice_id( $order );

		if ( '' === $invoice_id ) {
			return $this->no_invoice();
		}

		return $this->read_document( $invoice_id, $order_id );
	}

	/**
	 * Reads the request, checks its nonce and hands the rest to the decision.
	 *
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function resolve_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce this pair is checked against is built from the order identifier, so the identifier has to be read before it can be verified; the verification is the next statement, and nothing else happens in between.
		$order_id = isset( $_GET[ self::ORDER_ID_ARGUMENT ] ) ? absint( wp_unslash( $_GET[ self::ORDER_ID_ARGUMENT ] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( 0 === $order_id || ! wp_verify_nonce( $nonce, $this->nonce_action( $order_id ) ) ) {
			return $this->refused();
		}

		return $this->resolve_download( $order_id, get_current_user_id() );
	}

	/**
	 * Reads the document from the Factuarea API with the merchant's key.
	 *
	 * MEASURED on 2026-09-02 against the controller behind
	 * `Constants::INVOICE_PDF_PATH`: the route answers with the BYTES of the
	 * document under `Content-Type: application/pdf`, not with a document
	 * carrying a link to it - that is the neighbouring `…/pdf-link` route, which
	 * this plugin does not use. So the bytes are relayed, and the headers this
	 * shop sends with them are its own.
	 *
	 * TWO SETTINGS ARE REQUIRED BEFORE A REQUEST IS MADE, not one. The route
	 * now carries the identifier of the company the invoice belongs to, so a
	 * shop with a key but no configured company has nothing to ask with: the
	 * read is answered as unavailable WITHOUT going out, exactly as it already
	 * was for a missing key. It is never made against a company guessed from
	 * the credential - that would hand the buyer somebody else's document if
	 * the key ever reached more than one company, and it would do it with a
	 * 200. The merchant's own diagnosis of this is the connection test, which
	 * names the field; the buyer is told nothing about the shop's settings.
	 *
	 * The content type of the answer is checked rather than trusted, and the
	 * reason is a specific 200 that is not a document: a captive portal, a
	 * corporate proxy or a security appliance in front of the shop answers a
	 * perfectly ordinary 200 with an HTML page. Relaying that as
	 * `application/pdf` hands the buyer a file their reader refuses to open,
	 * with nothing anywhere saying why, whereas refusing it produces the same
	 * answer as every other broken connection - which is the one the merchant's
	 * connection test is about.
	 *
	 * @param string $invoice_id Identifier of the invoice, as the verified
	 *                           callback supplied it.
	 * @param int    $order_id   Order it belongs to, used only to name the file.
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function read_document( string $invoice_id, int $order_id ): array {
		$api_key = $this->api_key();

		if ( '' === $api_key ) {
			return $this->unavailable();
		}

		$company_id = SettingsPage::company_id();

		if ( '' === $company_id ) {
			return $this->unavailable();
		}

		$response = $this->client()->get(
			SettingsPage::api_base_url() . sprintf(
				Constants::INVOICE_PDF_PATH,
				rawurlencode( $company_id ),
				rawurlencode( $invoice_id )
			),
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => self::PDF_CONTENT_TYPE,
			)
		);

		if ( $response->is_transport_failure() || self::STATUS_OK !== (int) $response->status_code ) {
			return $this->unavailable();
		}

		if ( '' === $response->raw_body || ! $this->is_document( $response ) ) {
			return $this->unavailable();
		}

		return array(
			'status'       => self::STATUS_OK,
			'body'         => $response->raw_body,
			'content_type' => self::PDF_CONTENT_TYPE,
			'filename'     => sprintf( self::FILENAME_PATTERN, $order_id ),
		);
	}

	/**
	 * Whether an answer that arrived is a document.
	 *
	 * The parameters of the content type are ignored, because a header of
	 * `application/pdf; charset=binary` describes the same thing as a bare one
	 * and both are sent in the wild.
	 *
	 * @param HttpResponse $response Answer as it came off the wire.
	 * @return bool
	 */
	private function is_document( HttpResponse $response ): bool {
		$header = (string) $response->header( 'content-type' );
		$parts  = explode( ';', $header );

		return self::PDF_CONTENT_TYPE === strtolower( trim( $parts[0] ) );
	}

	/**
	 * Whether the access is to be offered for this order at all.
	 *
	 * Both halves are required, and the correlation is asked about first only
	 * because it is the cheaper question. An order with no invoice shows
	 * nothing to anybody, including its own buyer.
	 *
	 * @param WC_Order $order Order being rendered.
	 * @return bool
	 */
	private function should_offer( WC_Order $order ): bool {
		if ( '' === $this->invoice_id( $order ) ) {
			return false;
		}

		return $this->owns( $order, get_current_user_id() );
	}

	/**
	 * Whether the given user is the buyer of the given order.
	 *
	 * The identity comparison comes first and the capability second, which is
	 * the order that fails safely: the comparison is this plugin's own and
	 * holds whatever else is installed, and the capability is WooCommerce's,
	 * which can be widened by a role plugin. Requiring both means neither can
	 * be the only thing standing between a visitor and somebody else's invoice.
	 *
	 * A user identifier of zero - a visitor, or a request made outside any
	 * session - never matches, and it is refused before the comparison rather
	 * than by it, because a guest order also carries a customer of zero and the
	 * two zeroes must not be allowed to agree.
	 *
	 * @param WC_Order $order           Order being asked about.
	 * @param int      $current_user_id User asking.
	 * @return bool
	 */
	private function owns( WC_Order $order, int $current_user_id ): bool {
		if ( $current_user_id < 1 ) {
			return false;
		}

		if ( (int) $order->get_customer_id() !== $current_user_id ) {
			return false;
		}

		return (bool) current_user_can( self::VIEW_ORDER_CAPABILITY, $order->get_id() );
	}

	/**
	 * Identifier of the invoice correlated with an order, or an empty string.
	 *
	 * @param WC_Order $order Order to read.
	 * @return string
	 */
	private function invoice_id( WC_Order $order ): string {
		return trim( (string) $order->get_meta( Constants::INVOICE_ID_META_KEY ) );
	}

	/**
	 * The stored API key, or an empty string when there is none.
	 *
	 * @return string
	 */
	private function api_key(): string {
		return trim( (string) get_option( Constants::OPTION_API_KEY, '' ) );
	}

	/**
	 * Nonce action of one order.
	 *
	 * It names the order, so an address issued for one order is not an address
	 * for another. That is a second lock rather than the only one - the
	 * ownership check is what actually refuses somebody else's order - but it
	 * costs one concatenation and it means a link copied out of a page cannot
	 * be edited into a different order and still verify.
	 *
	 * @param int $order_id Order the address is for.
	 * @return string
	 */
	private function nonce_action( int $order_id ): string {
		return self::DOWNLOAD_ACTION . '_' . $order_id;
	}

	/**
	 * The answer given to anybody who is not the owner of an existing order.
	 *
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function refused(): array {
		return $this->refusal(
			self::STATUS_REFUSED,
			__( 'This invoice is not available from this account. Sign in with the account that placed the order and open the order again.', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * The answer given for an order of one's own that has no invoice yet.
	 *
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function no_invoice(): array {
		return $this->refusal(
			self::STATUS_NO_INVOICE,
			__( 'The invoice for this order is not available yet.', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * The answer given when the shop's connection did not produce the document.
	 *
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function unavailable(): array {
		return $this->refusal(
			self::STATUS_UNAVAILABLE,
			__( 'The invoice could not be downloaded right now. Try again in a few minutes, and tell the shop if it keeps happening.', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * One answer that is not a document.
	 *
	 * @param int    $status  Status to answer with.
	 * @param string $message What the buyer reads.
	 * @return array{status: int, body: ?string, content_type: ?string, filename: ?string}
	 */
	private function refusal( int $status, string $message ): array {
		return array(
			'status'       => $status,
			'body'         => $message,
			'content_type' => self::TEXT_CONTENT_TYPE,
			'filename'     => null,
		);
	}

	/**
	 * Sends one decided answer and ends the request.
	 *
	 * The document is never cached, by this site or by anything between it and
	 * the buyer. It is a personal document served from an address that differs
	 * between two buyers only by an order identifier, and a shared cache that
	 * kept one would hand it to the next person whose request looked the same.
	 *
	 * No content length is declared. WordPress serves this through whatever
	 * output buffering and compression the site has configured, and a length
	 * computed here would be the length BEFORE that - which a browser reads as a
	 * truncated file.
	 *
	 * @param array{status: int, body: ?string, content_type: ?string, filename: ?string} $result What to send.
	 * @return never
	 */
	private function emit( array $result ): never {
		nocache_headers();
		status_header( $result['status'] );
		header( 'Content-Type: ' . (string) $result['content_type'] );

		if ( null !== $result['filename'] ) {
			header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		}

		if ( self::PDF_CONTENT_TYPE === $result['content_type'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- These are the bytes of a PDF relayed verbatim; escaping them would corrupt the document, and they are only ever the body of an answer already declared as application/pdf.
			echo $result['body'];
		} else {
			echo esc_html( (string) $result['body'] );
		}

		exit;
	}

	/**
	 * The transport, built on first use.
	 *
	 * @return WordPressHttpClient
	 */
	private function client(): WordPressHttpClient {
		if ( null === $this->client ) {
			$this->client = new WordPressHttpClient();
		}

		return $this->client;
	}
}
