<?php
/**
 * Builds the delivery body of an order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Payload;

use Factuarea\WooCommerce\Exception\OrderPayloadNotBuildableException;

/**
 * Produces the RAW JSON body that this plugin delivers for one order.
 *
 * The body is not composed here. It is the representation WooCommerce itself
 * produces for that order through its own orders REST route, taken whole. Not
 * one field is written by this class, and that is the point: the receiver was
 * built to read the WooCommerce order document, so a body assembled by hand
 * would be a DIFFERENT document that the receiver would accept and then
 * normalise wrongly - which is worse than one that never arrives, because a
 * wrong invoice under verifiable invoicing is not deleted, it is corrected with
 * a second document.
 *
 * ---------------------------------------------------------------------------
 * WHICH OF THE TWO WAYS, AND WHY - MEASURED 2026-09-02
 * ---------------------------------------------------------------------------
 * The design left two ways open and asked for a measurement rather than a
 * preference. Both were measured against the SOURCES of WooCommerce 8.9.0, the
 * minimum version this plugin supports, on 2026-09-02. The end-to-end phase
 * confirms the same conclusion in execution, against a running WooCommerce;
 * what is written below is what the source says, which is what decides the
 * shape of this class.
 *
 * Way A - call the response preparer of the orders controller directly:
 *   `( new \WC_REST_Orders_Controller() )->prepare_object_for_response( $order, $request )`
 *   and turn the response into data.
 *
 * Way B - dispatch the orders REST request internally:
 *   `rest_do_request( new \WP_REST_Request( 'GET', '/wc/v3/orders/{id}' ) )`
 *   and turn the response into data.
 *
 * WAY B WINS, and the reason is not a judgement call - it is that way B is
 * LITERALLY what a native WooCommerce delivery does. Measured chain:
 *
 *   `WC_Webhook::deliver()`            sends `trim( wp_json_encode( $payload ) )`
 *     -> `WC_Webhook::build_payload()`
 *       -> `WC_Webhook::get_wp_api_payload()`
 *         -> `WC_API::get_endpoint_data( "/wc/v3/orders/{$id}" )`
 *            which is, verbatim, three lines:
 *              `$response = rest_do_request( new \WP_REST_Request( 'GET', $endpoint ) );`
 *              `$json     = wp_json_encode( rest_get_server()->response_to_data( $response, false ) );`
 *              `return json_decode( $json, true );`
 *
 * That chain is reproduced here step by step, including the encode/decode round
 * trip, which is NOT decoration: `json_decode( $json, true )` turns any empty
 * object in the representation into an empty array, so re-encoding after it
 * produces `[]` where a single encode would have produced `{}`. Skipping the
 * round trip would give a body that differs from a native one by bytes, and the
 * signature covers bytes.
 *
 * WHY WAY A IS DISCARDED. It is not that it produces a wrong document on a bare
 * installation - given the same three follow-up steps it produces the same
 * array. It is discarded because it SKIPS the four extension points that the
 * dispatcher runs and a native delivery therefore also runs: `rest_pre_dispatch`,
 * `rest_request_before_callbacks`, `rest_request_after_callbacks` and
 * `rest_post_dispatch`. A store with a plugin hooked into any of them would get
 * one document in its native webhooks and a different one from us - a divergence
 * that is invisible on every development machine and wrong on exactly the shops
 * that have such a plugin. "Indistinguishable from a native delivery" is the
 * whole premise of this package; way A is indistinguishable only where nobody
 * has extended anything.
 *
 * The cost of way B is the permission check, and it is paid explicitly below.
 *
 * ---------------------------------------------------------------------------
 * WHY `WC_API::get_endpoint_data()` IS NOT CALLED, EVEN THOUGH IT IS PUBLIC
 * ---------------------------------------------------------------------------
 * Because it returns only the DATA, never the status. When the route answers
 * 404 - order deleted between the hook and the drain, orders route not
 * registered, another plugin short-circuiting `rest_pre_dispatch` - that helper
 * hands back the error document (`{"code":...,"message":"Invalid ID.","data":{"status":404}}`)
 * with no way to tell it apart from an order. Delivering that as if it were an
 * order is a silent failure with a 200 on the other side. So the three lines are
 * reproduced with the status inspected in between, which is the one thing the
 * helper cannot do.
 *
 * The filter `woocommerce_webhook_payload` is deliberately NOT applied. It is
 * keyed by webhook identifier and this plugin owns no webhook record, so any
 * identifier passed to it would be a lie; and the published requirement is that
 * the body equals the REST representation of the order, not whatever a third
 * party rewrites a webhook payload into.
 *
 * ---------------------------------------------------------------------------
 * THE CAPABILITY ELEVATION, SCOPED AND ALWAYS RESTORED
 * ---------------------------------------------------------------------------
 * The dispatcher runs the route's permission callback, and the measured chain is
 * `WC_REST_Orders_V2_Controller::get_item_permissions_check()` ->
 * `WC_REST_Posts_Controller::get_item_permissions_check()` ->
 * `wc_rest_check_post_permissions( 'shop_order', 'read', $id )` ->
 * `current_user_can( 'read_private_shop_orders', $id )`.
 *
 * At `woocommerce_payment_complete` the current user is the BUYER, and a guest
 * checkout has no user at all, so that check fails and the order body would
 * never be built. WooCommerce solves the same problem in `build_payload()` by
 * calling `wp_set_current_user( $webhook_owner )` and restoring afterwards. This
 * plugin has no webhook record and therefore no owner to borrow, and switching
 * the current user to an administrator for the duration of a dispatch would give
 * an administrator identity to every filter that runs inside it.
 *
 * So the grant is made through WooCommerce's own documented filter,
 * `woocommerce_rest_check_permissions`, narrowed to the single decision this
 * class needs - READ, on the post type `shop_order`, for THIS order identifier -
 * and removed in `finally`, so it is gone whether the dispatch returned, errored
 * or threw. Everything else the filter is asked about is passed through
 * untouched, so a permission decision this class did not ask for is decided by
 * whoever it was already being decided by.
 *
 * ---------------------------------------------------------------------------
 * DOES THE TAX IDENTIFIER SURVIVE INTO THE BODY? MEASURED 2026-09-02: YES
 * ---------------------------------------------------------------------------
 * This is the measurement that settles the mirror decision of the storage
 * (branch A or branch B of the delivered-body decision), and the answer is
 * BRANCH A: no mirror is needed, and the mirror stays switched off.
 *
 * The belief it was measured against was that WooCommerce hides meta whose key
 * begins with an underscore from the REST representation, which would have meant
 * that `_factuarea_tax_id` - the key the connector reserves for this plugin - was
 * captured and then never read. Measured on the WooCommerce 8.9.0 sources, that
 * is FALSE. The order-level `meta_data` of the representation is built by
 * `WC_REST_Orders_V2_Controller::get_formatted_item_data()` as exactly three
 * steps, and none of them looks at the underscore:
 *
 *   1. `$order->get_meta_data()`, whose contents the data store filtered on read
 *      with `WC_Data_Store_WP::exclude_internal_meta_keys()`. That method drops a
 *      key only when it is in WooCommerce's ENUMERATED list of internal keys
 *      (`_order_key`, `_billing_first_name`, `_payment_method`, ...) or when it
 *      starts with `wp_`. A leading underscore is not a criterion.
 *   2. `get_meta_data_for_response()`, which applies only the `include_meta` and
 *      `exclude_meta` request parameters - absent here.
 *   3. `filter_internal_meta_keys()`, which under the modern order storage drops
 *      the same enumerated internal keys and nothing else.
 *
 * `is_protected_meta()` is called ZERO times anywhere on that path. So
 * `_factuarea_tax_id`, which is in no enumerated internal list and does not start
 * with `wp_`, travels in the body - and so does `_wc_billing/factuarea/tax-id`,
 * the key the additional checkout fields interface writes
 * (`CheckoutFields::BILLING_FIELDS_PREFIX . $field_id`, measured in the same
 * release).
 *
 * The receiver's own reference bodies agree, which is what turns a reading into
 * a measurement: they carry `_billing_nif` and `_new_order_email_sent` inside
 * `meta_data`, and six of the eight keys of the connector's enumerated catalogue
 * begin with an underscore. A catalogue built to walk the REST body that lists
 * underscore keys was written by people who had already seen them there.
 *
 * The consequence is written where it has to be: the mirror copy of the tax
 * identifier is NOT written, because writing it would duplicate a personal datum
 * in the merchant's database with nothing asking for it and create a second
 * source for one value. The unit test of this class asserts the measured fact
 * against the delivered body rather than against the storage, so the day a
 * WooCommerce release starts hiding the key, the test goes red here instead of
 * every B2B sale of every shop going quietly simplified.
 *
 * Deliberately NOT final (orchestrator decision, 2026-09-02): the three capture
 * listeners receive this class through a typed constructor parameter and their
 * unit tests replace it with a Mockery double, which PHP cannot do with a final
 * class. Same reason and same precedent as OutboxRepository. Opening the class is
 * not an extension point: build() is the single way a delivery body is produced.
 */
class OrderPayloadBuilder {

	/**
	 * Route of the single order, with the identifier still to be inserted.
	 *
	 * Version three, and not by preference: the receiver normalises the version
	 * three document, the reference bodies of its harness are version three, and
	 * a native delivery configured for the same API version asks for this exact
	 * path. Two versions of the same order document are two shapes for the
	 * connector to handle, which the design forbids.
	 */
	private const ORDER_ROUTE = '/wc/v3/orders/%d';

	/**
	 * Post type the orders route checks permissions against.
	 */
	private const ORDER_POST_TYPE = 'shop_order';

	/**
	 * Permission filter of WooCommerce through which the read is granted.
	 */
	private const PERMISSION_FILTER = 'woocommerce_rest_check_permissions';

	/**
	 * Builds the RAW JSON body of one order.
	 *
	 * The return value is the string that gets signed and sent, never an array:
	 * an array would let the caller encode it again, and a second encode changes
	 * bytes - key order, escaping, number notation - while the signature keeps
	 * covering the first ones. The receiver would then reject a body that is
	 * correct in every field.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return string The raw JSON body to sign and send.
	 * @throws OrderPayloadNotBuildableException When the order cannot be read, when
	 *                                           WooCommerce produces no usable
	 *                                           representation, or when that
	 *                                           representation cannot be encoded.
	 */
	public function build( int $order_id ): string {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping at the end of this class.
			throw OrderPayloadNotBuildableException::order_not_found( $order_id );
		}

		$response = $this->dispatch_order_request( $order_id );
		$data     = $this->response_data( $order_id, $response );

		return $this->encode_like_a_native_delivery( $order_id, $data );
	}

	/**
	 * Dispatches the orders route internally, with the read granted for the
	 * duration of that one call and revoked afterwards no matter what.
	 *
	 * `rest_do_request()` also brings the REST server up when it is not up yet -
	 * `rest_get_server()` fires `rest_api_init` the first time it is asked for
	 * one - so this works from a front-end hook, where no REST request is being
	 * served. That is the same ground a native delivery stands on.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return mixed Whatever the dispatcher returned; the caller inspects it.
	 */
	private function dispatch_order_request( int $order_id ) {
		$grant = $this->read_grant_for( $order_id );

		add_filter( self::PERMISSION_FILTER, $grant, 10, 4 );

		try {
			return rest_do_request( new \WP_REST_Request( 'GET', sprintf( self::ORDER_ROUTE, $order_id ) ) );
		} finally {
			remove_filter( self::PERMISSION_FILTER, $grant, 10 );
		}
	}

	/**
	 * Builds the permission grant for one order.
	 *
	 * It answers only the question this class needs answered and passes every
	 * other one through with the decision it already had. The identifier is
	 * checked because the same filter is consulted for every REST permission
	 * decision taken while it is installed: a grant that said yes to anything
	 * would, for the length of one dispatch, hand read access to every order of
	 * the shop to whatever else happened to ask.
	 *
	 * Zero is accepted alongside the order identifier because WooCommerce calls
	 * the check that way when it is asking about the post type rather than about
	 * one order.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return callable The filter callback, kept so it can be removed by identity.
	 */
	private function read_grant_for( int $order_id ): callable {
		return static function ( $permission, $context = '', $object_id = 0, $post_type = '' ) use ( $order_id ) {
			if ( 'read' !== $context || self::ORDER_POST_TYPE !== $post_type ) {
				return $permission;
			}

			if ( 0 !== (int) $object_id && $order_id !== (int) $object_id ) {
				return $permission;
			}

			return true;
		};
	}

	/**
	 * Turns the dispatched response into the representation array, refusing
	 * anything that is not an order document.
	 *
	 * The status is inspected BEFORE the response is converted, which is the one
	 * thing `WC_API::get_endpoint_data()` cannot do: a 404 converts into a
	 * perfectly well-formed array that would be signed and delivered as if it
	 * were an order.
	 *
	 * @param int   $order_id WooCommerce order identifier.
	 * @param mixed $response What the dispatcher returned.
	 * @return array<string, mixed> The order representation.
	 * @throws OrderPayloadNotBuildableException When the response is not an order document.
	 */
	private function response_data( int $order_id, $response ): array {
		$problem = $this->problem_with( $response );

		if ( null !== $problem ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping at the end of this class.
			throw OrderPayloadNotBuildableException::representation_failed( $order_id, $problem );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		if ( ! is_array( $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping at the end of this class.
			throw OrderPayloadNotBuildableException::representation_failed( $order_id, 'the orders route returned no representation' );
		}

		return $data;
	}

	/**
	 * What is wrong with what the dispatcher returned, or null when it is an
	 * order document.
	 *
	 * The status is what separates the two, and it has to be read BEFORE the
	 * response is converted: an error response converts into a perfectly
	 * well-formed array, and there is nothing about that array to tell it apart
	 * from an order once it has been converted.
	 *
	 * @param mixed $response What the dispatcher returned.
	 * @return string|null The problem, in English, or null when there is none.
	 */
	private function problem_with( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		if ( ! $response instanceof \WP_REST_Response ) {
			return 'the orders route returned no response object';
		}

		if ( $response->is_error() ) {
			return sprintf( 'HTTP %1$d %2$s', $response->get_status(), $response->as_error()->get_error_message() );
		}

		if ( 200 !== $response->get_status() ) {
			return sprintf( 'the orders route answered HTTP %d instead of 200', $response->get_status() );
		}

		return null;
	}

	/**
	 * Encodes the representation exactly as a native delivery encodes it.
	 *
	 * Three steps, and each one is copied rather than improved:
	 *
	 *  - encode, which is where a value WooCommerce cannot express as JSON stops
	 *    the delivery instead of travelling as a partial body;
	 *  - decode back into an array, which is the step that normalises an empty
	 *    object into an empty array;
	 *  - encode again and trim, which is the string a native delivery signs.
	 *
	 * The trim is a copy too. It changes nothing today, because the encoder never
	 * produces surrounding whitespace, and it is here so that the two sides of the
	 * comparison stay one edit apart rather than one reading apart.
	 *
	 * @param int                  $order_id WooCommerce order identifier.
	 * @param array<string, mixed> $data     The order representation.
	 * @return string The raw JSON body.
	 * @throws OrderPayloadNotBuildableException When the representation cannot be encoded.
	 */
	private function encode_like_a_native_delivery( int $order_id, array $data ): string {
		$encoded = wp_json_encode( $data );

		if ( ! is_string( $encoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping below.
			throw OrderPayloadNotBuildableException::encoding_failed( $order_id, 'the order representation could not be encoded as JSON' );
		}

		$decoded = json_decode( $encoded, true );

		if ( ! is_array( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping below.
			throw OrderPayloadNotBuildableException::encoding_failed( $order_id, 'the encoded order representation did not decode back into an array' );
		}

		$body = wp_json_encode( $decoded );

		if ( ! is_string( $body ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the note on escaping below.
			throw OrderPayloadNotBuildableException::encoding_failed( $order_id, 'the order representation could not be re-encoded as JSON' );
		}

		return trim( $body );
	}

	/*
	 * THE NOTE ON ESCAPING, written once for the six exclusions above.
	 *
	 * `WordPress.Security.EscapeOutput.ExceptionNotEscaped` exists because an
	 * exception message thrown inside a rendered page ends up on that page, so a
	 * value that came from a request would be printed unescaped.
	 *
	 * Neither half of that applies here, and both halves matter:
	 *
	 *  - Nothing in these messages came from a request. They carry an order
	 *    identifier this plugin was handed as an integer, and text this class
	 *    wrote itself or read off a WooCommerce error object.
	 *  - This class renders nothing. It runs inside an order hook and inside the
	 *    scheduled drain, and what it throws is caught by the dispatcher and
	 *    recorded against the outbox entry. Escaping belongs where that entry is
	 *    displayed, which is the settings screen, and doing it twice is how a
	 *    merchant ends up reading `&amp;` in a diagnostic.
	 *
	 * There is a third reason that is not about style: escaping here would make
	 * this class call a WordPress function, and its unit tests run on bare PHP
	 * with no WordPress loaded. The escaping would have to be doubled to be
	 * exercised, which means it would be the double being tested and not the
	 * escaping.
	 */
}
