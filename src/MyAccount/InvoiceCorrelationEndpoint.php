<?php
/**
 * The signed callback that tells this plugin which invoice belongs to an order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\MyAccount;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The one door through which an order-to-invoice association may enter, and the
 * verification that guards it.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR
 * ---------------------------------------------------------------------------
 * The buyer wants the PDF of the invoice for the order they just paid for. The
 * plugin can only show it if it knows WHICH invoice that is, and it has no way
 * of working that out on its own: it sends an order and gets a 200 back, with
 * no invoice identifier in it. So the identity has to arrive, and this is the
 * surface it arrives through.
 *
 * The pair that arrives is (idempotency key, invoice identifier). The key is
 * the one this plugin derived for that order and stored against it - see
 * `Contract\EventIdentity::idempotency_key()` and
 * `Constants::IDEMPOTENCY_KEY_META_KEY` - so the sender does not need to know
 * anything about WooCommerce order numbering, and the plugin does not need to
 * guess anything about invoice numbering. One value, derived on both sides from
 * the same three parts, joins the two records.
 *
 * ---------------------------------------------------------------------------
 * THE DECLARED LIMITATION: NOBODY SENDS THIS TODAY
 * ---------------------------------------------------------------------------
 * As of the writing of this file NO sender calls this route, and that is a
 * measured fact rather than an oversight. The published v1 invoice
 * representation does not emit the idempotency key, the channel or the source
 * store identifier, and the order lane is forbidden from claiming the
 * `external_id` field, so nothing on the Factuarea side currently holds the
 * value this route needs to be given. The change document of this package
 * records it as its first open question.
 *
 * The surface is built anyway, verified and complete, because the alternative
 * was not "wait" - it was to correlate an order with an invoice by amount and
 * date, which is a rule that works for months and then hands one customer the
 * invoice of another. Closing the loop end to end needs ONE of two things, and
 * both of them belong to the backend and to another change: the v1 invoice
 * representation emitting the idempotency key, or a v1 query by that key.
 *
 * Until then this route exists, answers, and associates nothing, because
 * nothing valid ever arrives at it. That is the intended state. What it is NOT
 * is a stub: every rule below is implemented and tested, so the day a sender
 * appears there is no second round of work between "it is called" and "it is
 * safe".
 *
 * ---------------------------------------------------------------------------
 * WHY THE PERMISSION CALLBACK LETS EVERYONE IN
 * ---------------------------------------------------------------------------
 * The route is registered with a permission callback that always says yes, and
 * that is not a hole: the caller is a SERVER, not a logged-in user of this
 * WordPress, so there is no cookie, no nonce and no capability to check. What
 * authenticates it is the signature, and the signature is checked HERE, inside
 * the handler, before anything is read out of the body and long before anything
 * is written.
 *
 * Putting the verification in the permission callback instead would look
 * tidier and would be wrong in one specific way: WordPress turns a permission
 * callback that returns false into a 401 with a body that names the route and
 * the reason, which is a slightly more talkative answer than this route is
 * willing to give. Here every refusal is a bare status code.
 *
 * ---------------------------------------------------------------------------
 * WHAT COMES BACK, AND WHY IT SAYS SO LITTLE
 * ---------------------------------------------------------------------------
 *   204  associated - the order was found and now carries the invoice identity.
 *   401  the signature was absent, or did not match, or no secret is configured.
 *   404  the signature was good and no order carries that idempotency key.
 *   422  the signature was good and the body is not the expected pair.
 *
 * The three refusals carry NO body at all. In particular the 401 is the same
 * answer for "you sent no signature", "you sent a wrong signature" and "this
 * store has no signing secret configured": telling those apart is free
 * information for whoever is probing, and useless to a legitimate sender, which
 * either has the secret or does not.
 *
 * The 404 does distinguish itself from the 401, and deliberately: reaching it
 * requires having already proved possession of the secret, so the only party
 * that can observe the difference is the merchant's own Factuarea, for which
 * "I do not know that key" is exactly the diagnosis it needs.
 *
 * NOTHING here writes the signing secret, the expected signature or the
 * received one to a response, an admin notice or a log line. This class logs
 * nothing at all; a sweep in its test reads this file to keep it that way.
 *
 * ---------------------------------------------------------------------------
 * THE SIGNATURE IS OVER THE RAW BODY, NOT OVER THE PARSED DOCUMENT
 * ---------------------------------------------------------------------------
 * The material is `WP_REST_Request::get_body()`: the bytes as they arrived.
 * Verifying against a re-encoding of the decoded document - `json_encode(
 * json_decode( $body ) )` - would compare a signature over one byte sequence
 * with a digest over a different one, because key order, spacing, escaping and
 * number notation all survive the round trip only by luck. Every legitimate
 * caller would be rejected, and the symptom would be an unexplained 401 rather
 * than an error.
 *
 * This is the same rule, in the opposite direction, that `Transport\
 * DeliverySigner` states for outgoing deliveries, and it uses that same class
 * to compute the expected value. One scheme, one implementation, both
 * directions: a second HMAC written here would be a second place to get the
 * fourth argument of `hash_hmac` wrong.
 *
 * The comparison is `hash_equals`, which takes the same time whatever the two
 * strings are. A `===` returns on the first differing byte, and the timing of
 * those returns is enough, over enough attempts, to recover the expected
 * signature one byte at a time.
 */
final class InvoiceCorrelationEndpoint {

	/**
	 * REST namespace of the route.
	 *
	 * `factuarea/v1` is versioned from the first version on purpose: this is a
	 * PUBLISHED surface of a DISTRIBUTED plugin, so the day its shape has to
	 * change there will be shops running every version released until then, and
	 * a namespace without a version leaves no room to serve both.
	 */
	public const REST_NAMESPACE = 'factuarea/v1';

	/**
	 * Route, relative to the namespace. The full path a sender posts to is
	 * `<site>/wp-json/factuarea/v1/invoice-correlation`, or the equivalent
	 * `?rest_route=` form on a site with plain permalinks.
	 */
	public const REST_ROUTE = '/invoice-correlation';

	/**
	 * The only HTTP method the route answers. Written as the literal rather
	 * than as `WP_REST_Server::CREATABLE` so that the value can be asserted
	 * without loading WordPress.
	 */
	public const REST_METHOD = 'POST';

	/**
	 * Header carrying the signature of the incoming callback.
	 *
	 * NAMED HERE, and named differently from the outgoing one on purpose. The
	 * deliveries this plugin SENDS sign themselves into
	 * `X-WC-Webhook-Signature`, because they impersonate a native WooCommerce
	 * webhook down to the header names. This one is not pretending to be
	 * anything: it is this plugin's own route, so it carries this plugin's own
	 * header, and reusing the WooCommerce name would suggest to a reader that
	 * WooCommerce has something to do with it.
	 *
	 * The SCHEME is the same as the outgoing one - base64 of the HMAC-SHA256
	 * digest of the raw body, keyed with the store signing secret - because a
	 * sender that already implements the verification of our deliveries can
	 * produce this one with the code it already has.
	 *
	 * No sender emits it today; see the declared limitation in the class block.
	 * The name is fixed now so that whoever writes that sender has a value to
	 * write against instead of a decision to make.
	 */
	public const SIGNATURE_HEADER = 'X-Factuarea-Signature';

	/**
	 * Field of the request body holding the idempotency key this plugin derived
	 * for the order.
	 */
	public const IDEMPOTENCY_KEY_FIELD = 'idempotency_key';

	/**
	 * Field of the request body holding the identifier of the invoice.
	 */
	public const INVOICE_ID_FIELD = 'invoice_id';

	/**
	 * Answer when the invoice is now associated with its order. No body: there
	 * is nothing to say that the status does not already say.
	 */
	public const STATUS_ASSOCIATED = 204;

	/**
	 * Answer when the callback is not authenticated. One status for the three
	 * ways of failing that, on purpose; see the class block.
	 */
	public const STATUS_UNAUTHORISED = 401;

	/**
	 * Answer when no order carries the idempotency key of an authenticated
	 * callback.
	 */
	public const STATUS_UNKNOWN_KEY = 404;

	/**
	 * Answer when an authenticated callback does not carry the expected pair.
	 */
	public const STATUS_MALFORMED = 422;

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
	 * Hooks the route registration.
	 *
	 * `rest_api_init` and not an earlier hook: the REST server does not exist
	 * before it, and `register_rest_route()` called earlier is discarded with a
	 * notice that nobody reads on a production site.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ), 10, 0 );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => self::REST_METHOD,
				'callback'            => array( $this, 'handle' ),

				/*
				 * Always yes, and the signature check inside `handle()` is what
				 * actually guards the route. The reasoning is in the class
				 * block; what matters here is that the callback is present.
				 * WordPress serves a route registered WITHOUT one, warning into
				 * a log the merchant never opens - so an omission is not a
				 * closed door, it is an open one with a note about it.
				 */
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verifies the callback and, if it holds up, associates the invoice with
	 * the order that carries the idempotency key.
	 *
	 * The order of the steps is the contract: verify, then read, then look up,
	 * then write. Nothing is parsed out of an unverified body and nothing is
	 * looked up with a value that came out of one.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response One of the four statuses declared above, with an
	 *                          empty body.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = (string) $request->get_body();

		if ( ! $this->signature_holds( $raw_body, (string) $request->get_header( self::SIGNATURE_HEADER ) ) ) {
			return $this->answer( self::STATUS_UNAUTHORISED );
		}

		$pair = $this->read_pair( $raw_body );

		if ( null === $pair ) {
			return $this->answer( self::STATUS_MALFORMED );
		}

		$order = $this->order_carrying( $pair[ self::IDEMPOTENCY_KEY_FIELD ] );

		if ( null === $order ) {
			return $this->answer( self::STATUS_UNKNOWN_KEY );
		}

		$this->associate( $order, $pair[ self::INVOICE_ID_FIELD ] );

		return $this->answer( self::STATUS_ASSOCIATED );
	}

	/**
	 * Whether the presented signature is the one this store's secret produces
	 * over these exact bytes.
	 *
	 * The three ways of answering no are one branch on purpose: an absent
	 * signature, a wrong signature and an unconfigured secret all leave through
	 * the same return, so the caller of this method cannot tell them apart even
	 * by accident.
	 *
	 * The unconfigured secret is checked first for a second, mechanical reason:
	 * `DeliverySigner::sign()` refuses an empty secret by throwing, precisely so
	 * that no outgoing delivery is ever signed with nothing. Reaching it with an
	 * empty secret here would turn a misconfiguration into a 500.
	 *
	 * @param string $raw_body  Bytes exactly as they arrived.
	 * @param string $presented Value of the signature header, empty when absent.
	 * @return bool True only when the signature matches.
	 */
	private function signature_holds( string $raw_body, string $presented ): bool {
		$secret = $this->signing_secret();

		if ( '' === $secret || '' === $presented ) {
			return false;
		}

		return hash_equals( $this->signer()->sign( $raw_body, $secret ), $presented );
	}

	/**
	 * The configured signing secret, or an empty string when there is none.
	 *
	 * @return string
	 */
	private function signing_secret(): string {
		$stored = get_option( Constants::OPTION_SIGNING_SECRET, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Reads the expected pair out of a body that has already been verified.
	 *
	 * What is required is structural and nothing more: an object carrying two
	 * non-empty strings under the two field names. The shape of the idempotency
	 * key itself is NOT validated - it is a hexadecimal digest today, and a
	 * plugin that hard-coded "sixty-four hexadecimal characters" would start
	 * rejecting legitimate callbacks the day the receiver changed how it
	 * derives them, on every installation already out there, with no way to fix
	 * it except an update every merchant has to accept. An unrecognised key
	 * simply matches no order and leaves through the 404, which is the correct
	 * answer for it anyway.
	 *
	 * @param string $raw_body Verified body.
	 * @return array<string, string>|null The two values keyed by field name, or
	 *                                    null when the body is not that pair.
	 */
	private function read_pair( string $raw_body ): ?array {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$idempotency_key = $this->string_field( $decoded, self::IDEMPOTENCY_KEY_FIELD );
		$invoice_id      = $this->string_field( $decoded, self::INVOICE_ID_FIELD );

		if ( '' === $idempotency_key || '' === $invoice_id ) {
			return null;
		}

		return array(
			self::IDEMPOTENCY_KEY_FIELD => $idempotency_key,
			self::INVOICE_ID_FIELD      => $invoice_id,
		);
	}

	/**
	 * One trimmed string field, or an empty string when it is missing or is not
	 * a string.
	 *
	 * A number is not accepted where a string is expected, and that is not
	 * pedantry: both values are opaque identifiers, and casting `1.0E+25` or a
	 * boolean into one produces a value that looks like an identifier and
	 * matches nothing.
	 *
	 * @param array<array-key, mixed> $decoded Decoded body.
	 * @param string                  $field   Field name.
	 * @return string
	 */
	private function string_field( array $decoded, string $field ): string {
		$value = $decoded[ $field ] ?? null;

		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( $value );
	}

	/**
	 * The order carrying that idempotency key, or null when there is none.
	 *
	 * The lookup goes through `wc_get_orders()` and never through a post query
	 * or a direct read of the post meta table. Under the modern order storage
	 * an order is not a post, so a `WP_Query` over `postmeta` finds NOTHING on
	 * a shop that has migrated - and this plugin declares itself compatible
	 * with that storage, so it is looking at the shops most likely to have.
	 *
	 * The limit is one. The key is unique by construction: it is a digest of
	 * the provider, the store and the event identity, and only the listener
	 * that enqueued that event writes it onto that one order.
	 *
	 * @param string $idempotency_key Key to match.
	 * @return WC_Order|null
	 */
	private function order_carrying( string $idempotency_key ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Meta lookup with a limit of one over a unique key; it is how an order is found by an identifier that is not its own.
				'meta_key'   => Constants::IDEMPOTENCY_KEY_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See the note on the key above.
				'meta_value' => $idempotency_key,
				'limit'      => 1,
			)
		);

		if ( ! is_array( $orders ) || array() === $orders ) {
			return null;
		}

		$order = reset( $orders );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Writes the invoice identity onto the order.
	 *
	 * A callback that repeats the association it already made writes nothing.
	 * Repetition is expected rather than exceptional - a sender that retries
	 * until it gets a success is the only kind of sender worth having - and the
	 * write it would perform is a full order save, which fires the actions
	 * every other plugin on the shop listens to.
	 *
	 * A callback that carries a DIFFERENT invoice for an order that already has
	 * one does overwrite. That is the correcting case: an invoice can be
	 * replaced by a corrective document, and the last identity the merchant's
	 * own Factuarea sends is the one it wants the buyer to be shown.
	 *
	 * @param WC_Order $order      Order found by its idempotency key.
	 * @param string   $invoice_id Identifier of the invoice.
	 * @return void
	 */
	private function associate( WC_Order $order, string $invoice_id ): void {
		if ( (string) $order->get_meta( Constants::INVOICE_ID_META_KEY ) === $invoice_id ) {
			return;
		}

		$order->update_meta_data( Constants::INVOICE_ID_META_KEY, $invoice_id );
		$order->save();
	}

	/**
	 * A response carrying a status and nothing else.
	 *
	 * @param int $status One of the four declared statuses.
	 * @return WP_REST_Response
	 */
	private function answer( int $status ): WP_REST_Response {
		return new WP_REST_Response( null, $status );
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
