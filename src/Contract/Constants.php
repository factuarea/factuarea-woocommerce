<?php
/**
 * Frozen values and frozen public signatures of the plugin.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

use Factuarea\WooCommerce\MyAccount\InvoiceCorrelationEndpoint;

/**
 * The single place where the values that more than one part of this plugin has
 * to agree on are written down.
 *
 * Everything here is FROZEN. A value in this file is not a default: it is one
 * half of an agreement with something outside this repository - the ingestion
 * route, the connector that reads the delivered body, the WordPress database of
 * the merchant, or another class of this plugin written by somebody else at the
 * same time. Changing one of these is changing that agreement, and the other
 * half will not be redeployed at the same moment: this plugin is DISTRIBUTED,
 * so a merchant runs whichever version they last updated to.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS MEASURED, AND WHERE
 * ---------------------------------------------------------------------------
 * Three of the values below were read off the receiver's source on 2026-09-02
 * rather than copied from a document, because a document can be stale and a
 * wrong value here is a 404, a 401 or a tax identifier that nobody reads:
 *
 *  - INGESTION_PATH - measured in the monorepo. The route is declared as
 *    `Route::post( 'webhooks/stores/{provider}', ... )` and the group that
 *    loads that file applies `->prefix( 'api' )`, so the path that actually
 *    answers is `/api/webhooks/stores/woocommerce`. The change documents of
 *    this package spell it `/webhooks/stores/woocommerce`, WITHOUT the prefix.
 *    The measured value wins, because the documented one would have shipped a
 *    plugin that posts into a 404 on every single sale.
 *
 *  - TAX_ID_META_KEY - measured. The connector's enumerated catalogue of tax
 *    identifier meta keys reserves `_factuarea_tax_id` for this plugin, and
 *    lists it FIRST by preference. It is the only key in that catalogue whose
 *    content the connector trusts without arbitration, and inventing a second
 *    one would mean the connector never reads what this plugin captures.
 *
 *  - The seven delivery header names - measured against the connector's
 *    signature header map and its normalisers, which name
 *    `X-WC-Webhook-Signature`, `X-WC-Webhook-Topic`, `X-WC-Webhook-ID` and
 *    `X-WC-Webhook-Delivery-ID` explicitly. The remaining three are the rest of
 *    the set WooCommerce sends natively.
 *
 * ---------------------------------------------------------------------------
 * FROZEN PUBLIC SIGNATURES
 * ---------------------------------------------------------------------------
 * The classes below are written by other phases, in parallel, by people who
 * cannot see each other's work. What they may NOT do is invent these
 * signatures: a caller and a callee that each pick their own shape do not fail
 * at the boundary, they fail at wiring time, after both are finished.
 *
 * The classes are concrete and final; none of them is an interface, because
 * there is exactly one implementation of each inside a plugin and an interface
 * with one implementor is a second file to keep in sync.
 *
 *   Factuarea\WooCommerce\Compatibility\HposCompatibility
 *     public static function register(): void
 *
 *   Factuarea\WooCommerce\Checkout\TaxIdOrderStorage
 *     public function write( \WC_Order $order, string $value ): void
 *     public function read( \WC_Order $order ): string
 *       - `read` returns an empty string when there is no tax identifier, never
 *         null: an order without one is a normal order that invoices as a
 *         simplified invoice.
 *
 *   Factuarea\WooCommerce\Payload\OrderPayloadBuilder
 *     public function build( int $order_id ): string
 *       - returns the RAW JSON body that will be sent and signed, not an array.
 *         Returning an array would let the caller re-encode it, and a re-encode
 *         changes bytes (key order, escaping, number notation) while the
 *         signature keeps covering the old ones.
 *       - throws OrderPayloadNotBuildableException.
 *
 *   Factuarea\WooCommerce\Transport\DeliverySigner
 *     public function sign( string $raw_body, string $secret ): string
 *
 *   Factuarea\WooCommerce\Transport\DeliveryHeaders
 *     public function for_order( string $topic, string $resource, string $event, string $source, string $signature, int $webhook_id, int $delivery_id, string $store_id = '' ): array
 *       - returns array<string, string> keyed by the seven header names below,
 *         plus HEADER_STORE_ID when - and only when - a store identifier is
 *         configured.
 *       - the eighth parameter is OPTIONAL and LAST, which is what makes the
 *         widening additive: every call written against the seven-parameter
 *         form keeps working untouched, exactly as
 *         `Outbox\RetryPlanner::next_attempt_at()` did with its third. The
 *         default is the empty string and not null because "no store
 *         identifier configured" is what `get_option()` returns for an unset
 *         option, so the absent case and the unconfigured case are one value
 *         and not two.
 *
 *   Factuarea\WooCommerce\Transport\WordPressHttpClient
 *     public function post( string $url, array $headers, string $raw_body ): HttpResponse
 *     public function get( string $url, array $headers ): HttpResponse
 *       - neither throws. Every failure comes back as an HttpResponse carrying
 *         a transport error, because the classifier is the only thing allowed
 *         to decide what a failure means.
 *
 *   Factuarea\WooCommerce\Transport\RejectionClassifier
 *     public function classify( HttpResponse $response ): DeliveryOutcome
 *
 *   Factuarea\WooCommerce\Outbox\OutboxRepository
 *     public function enqueue( string $event_id, int $order_id, string $event_type, string $raw_body, string $source ): void
 *     public function claim_due( int $limit ): array
 *     public function settle( int $entry_id, DeliveryOutcome $outcome, ?int $next_attempt_at ): void
 *       - `claim_due` returns a list of rows already claimed, so two drains
 *         never hand the same entry to both.
 *       - `settle` takes the next attempt instant as a Unix timestamp, or null
 *         when there is to be no next attempt.
 *
 *   Factuarea\WooCommerce\Outbox\RetryPlanner
 *     public function next_attempt_at( int $attempt, int $now ): ?int
 *       - `attempt` is the number of attempts ALREADY FAILED, counting from 1,
 *         exactly as the single retry calculator of the backend counts them.
 *       - returns null once the maximum number of attempts is exhausted.
 *
 *   Factuarea\WooCommerce\Outbox\OutboxDispatcher
 *     public function drain( int $limit ): int
 *       - returns how many entries were settled, so a scheduled run can say
 *         what it did.
 *
 * ---------------------------------------------------------------------------
 * FROZEN PUBLIC SIGNATURES - THE INBOUND HALF
 * ---------------------------------------------------------------------------
 * The twelve below are the classes of the INBOUND half, and the rule is the
 * same one the block above states: the class is written by its own phase, the
 * SIGNATURE is not negotiable. They are frozen here before any of them exists,
 * because the phases that write them run at the same time and cannot see each
 * other's work; a caller and a callee that each pick their own shape do not
 * fail at the boundary, they fail at wiring time, when both are finished.
 *
 * None of them is an interface, for the reason already given: there is exactly
 * one implementation of each inside a plugin.
 *
 * The data types they pass around - `Contract\InboundStatusNotice`,
 * `Contract\InboundRefundNotice`, `Contract\CatalogPushItem`,
 * `Contract\CatalogPushOutcome`, `Contract\FulfilmentState` and
 * `Contract\InboundResponseStatus` - are NOT in this list: they are immutable
 * data without behaviour and they are born whole in the same phase as this
 * block, because they are what the parallel phases hand each other.
 *
 *   Factuarea\WooCommerce\Inbound\InboundSignatureVerifier
 *     public function verify( string $raw_body, string $presented, string $secret ): bool
 *       - the ONLY place that decides whether an inbound delivery is
 *         authenticated. It delegates the computation to
 *         Transport\DeliverySigner, which already exists: a second hash_hmac in
 *         this repository would be a second place to get its fourth argument
 *         wrong, and that argument is the difference between base64 and
 *         hexadecimal.
 *       - answers false, never throws, for all three ways of failing - no
 *         header, a signature that does not correspond, and a shop with no
 *         secret configured - so that the caller cannot accidentally tell them
 *         apart in its answer.
 *
 *   Factuarea\WooCommerce\Inbound\InboundDeliveryTable
 *     public static function install(): void
 *       - idempotent, like Outbox\OutboxTable::install(). Composes the table
 *         name with the installation prefix and records its own SCHEMA_VERSION
 *         - declared on the class, exactly as Outbox\OutboxTable declares its
 *         own - in OPTION_INBOUND_SCHEMA_VERSION, WITHOUT short-circuiting on
 *         that marker: a reinstall has to recreate the marker against whatever
 *         table it finds.
 *
 *   Factuarea\WooCommerce\Inbound\InboundDeliveryLedger
 *     public function claim( string $idempotency_key, int $now ): bool
 *     public function mark_applied( string $idempotency_key, int $now ): bool
 *     public function forget( string $idempotency_key ): void
 *     public function prune( int $older_than ): int
 *     public static function prune_cutoff( int $now ): int
 *       - `claim` answers false when the key was already applied. It is an
 *         INSERT that collides with the unique index, never a select followed
 *         by an insert: the window between those two is exactly where two
 *         concurrent deliveries of the same key both get through.
 *       - `forget` is called in ONE situation only - the effect failed AFTER
 *         the key was claimed - so that the sender's retry can come back.
 *       - `mark_applied` is the success half of `forget`: the receiver calls it
 *         once the effect is applied so that `applied_at` tells a delivery that
 *         was applied apart from a receiver that died between claim and effect.
 *       - `prune` answers how many rows it removed, and prunes by age using
 *         INBOUND_RETENTION_SECONDS and no other figure; `prune_cutoff` is the
 *         ONLY place that turns that window into an instant, so callers never
 *         write the subtraction themselves.
 *
 *   Factuarea\WooCommerce\Inbound\OrderStatusStorage
 *     public function apply( \WC_Order $order, InboundStatusNotice $notice ): void
 *       - writes the fulfilment status and its tracking data into the order
 *         meta named below. NEVER calls WC_Order::set_status(); see
 *         Contract\FulfilmentState for the chain of effects that would start.
 *
 *   Factuarea\WooCommerce\Inbound\RefundApplier
 *     public function apply( \WC_Order $order, InboundRefundNotice $notice, string $idempotency_key ): void
 *       - creates the WooCommerce refund with no gateway movement and no
 *         restock, and marks it with INBOUND_REFUND_META_KEY so that both of
 *         the plugin's refund captors leave it alone.
 *
 *   Factuarea\WooCommerce\Inbound\ProductResolver
 *     public function resolve( CatalogPushItem $item ): ?\WC_Product
 *     public function last_rejection(): string
 *       - `resolve` answers null for "not found" AND for "ambiguous"; which of
 *         the two it was is read from `last_rejection()`, whose value is one of
 *         CatalogPushOutcome::REASON_NOT_FOUND or ::REASON_AMBIGUOUS.
 *       - never creates an article. That decision belongs to the publishing
 *         mode of the sending platform and travels in the push.
 *
 *   Factuarea\WooCommerce\Inbound\CatalogFieldWriter
 *     public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome
 *
 *   Factuarea\WooCommerce\Inbound\PriceWriter
 *     public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome
 *
 *   Factuarea\WooCommerce\Inbound\StockWriter
 *     public function write( \WC_Product $product, CatalogPushItem $item ): CatalogPushOutcome
 *       - the three writers share one shape so that the receiver can run them in
 *         order over the same article and collect one outcome per article. Each
 *         one applies ONLY the fields it owns and leaves undeclared ones alone.
 *
 *   Factuarea\WooCommerce\Inbound\OrderStatusEndpoint
 *     public function register(): void
 *     public function handle( \WP_REST_Request $request ): \WP_REST_Response
 *
 *   Factuarea\WooCommerce\Inbound\CatalogPushEndpoint
 *     public function register(): void
 *     public function handle( \WP_REST_Request $request ): \WP_REST_Response
 *       - both register on `rest_api_init` with `permission_callback =>
 *         '__return_true'` and verify the signature INSIDE handle(), copying
 *         the pattern and the written reason of
 *         MyAccount\InvoiceCorrelationEndpoint: the caller is a server, not a
 *         user with a session, and what authenticates it is the signature.
 *       - handle() runs in the order that IS the contract: verify the signature
 *         over the raw body, check the receiver's switch, claim the idempotency
 *         key, read the body, and only then apply. Nothing is decoded from an
 *         unverified body and nothing is applied without having claimed first.
 *
 *   Factuarea\WooCommerce\MyAccount\OrderStatusDisplay
 *     public function register(): void
 *       - hooks the same two points as MyAccount\InvoicePdfLink, so that the
 *         buyer finds the status where they already find their invoice.
 */
final class Constants {

	/**
	 * Human-readable plugin name. Also the name the directory listing carries.
	 */
	public const PLUGIN_NAME = 'Factuarea for WooCommerce';

	/**
	 * Plugin slug. Directory name, artefact name and option prefix root.
	 */
	public const PLUGIN_SLUG = 'factuarea-for-woocommerce';

	/**
	 * Translation text domain. It has to equal the slug: WordPress.org builds
	 * the language packs from the slug and a text domain that differs from it
	 * receives no translation at all, silently.
	 */
	public const TEXT_DOMAIN = 'factuarea-for-woocommerce';

	/**
	 * Minimum PHP version.
	 *
	 * DECIDED HERE, and said out loud because the change documents of this
	 * package do not name a number: they fix the minimum WooCommerce version by
	 * a capability (the additional checkout fields interface) and say nothing
	 * about PHP. 8.1 is the floor that the language features this plugin's
	 * frozen contracts use require - enumerations and readonly properties - and
	 * the oldest PHP branch still receiving security fixes. It must stay equal
	 * to `require.php` in composer.json, to `testVersion` in phpcs.xml.dist and
	 * to the `Requires PHP` header of the plugin file.
	 */
	public const MINIMUM_PHP_VERSION = '8.1';

	/**
	 * Minimum WordPress version announced by the plugin.
	 */
	public const MINIMUM_WORDPRESS_VERSION = '6.5';

	/**
	 * Minimum WooCommerce version, and the reason is a capability rather than a
	 * preference: below it the additional checkout fields interface does not
	 * exist, so the tax identifier field cannot be registered for the block
	 * checkout at all, and the block checkout is the half of the problem this
	 * plugin exists to solve.
	 */
	public const MINIMUM_WOOCOMMERCE_VERSION = '8.9';

	/**
	 * Provider identifier the receiver knows this store by. It is the value the
	 * ingestion route resolves its verifier with, and the prefix of every event
	 * identity.
	 */
	public const PROVIDER = 'woocommerce';

	/**
	 * Path of the ingestion route, appended to the configured base address.
	 *
	 * MEASURED, not copied. See the note at the top of this file: the route is
	 * declared without the prefix and registered under a group that adds `api`,
	 * so this is the path that answers.
	 */
	public const INGESTION_PATH = '/api/webhooks/stores/woocommerce';

	/**
	 * Path of the store query the connection test uses. It is a READ with the
	 * API key, and it is deliberately not the ingestion route: that one answers
	 * 200 whether the store is known or not, so a probe against it cannot tell
	 * "configured correctly" from "nobody here knows you".
	 */
	public const STORE_QUERY_PATH = '/v1/stores/%s';

	/**
	 * Path of the invoice PDF download, consumed with the API key on the
	 * merchant's behalf and never exposed to the buyer's browser.
	 */
	public const INVOICE_PDF_PATH = '/v1/invoices/%s/pdf';

	/**
	 * REST namespace the INBOUND receivers are registered under.
	 *
	 * REFERENCED, not re-typed. It is the namespace this plugin already
	 * publishes (`MyAccount\InvoiceCorrelationEndpoint::REST_NAMESPACE`), and
	 * writing `'factuarea/v1'` a second time here would create two literals that
	 * are equal today and diverge the day one of them is edited - at which point
	 * half of this plugin's routes would answer on a namespace the other half
	 * does not know about.
	 *
	 * The version in it is not decoration: this is a PUBLISHED surface of a
	 * DISTRIBUTED plugin, so the day the shape has to change there will be shops
	 * running every version released until then.
	 */
	public const INBOUND_REST_NAMESPACE = InvoiceCorrelationEndpoint::REST_NAMESPACE;

	/**
	 * The only HTTP method the inbound receivers answer, referenced from the
	 * precedent for the same reason as the namespace.
	 */
	public const INBOUND_REST_METHOD = InvoiceCorrelationEndpoint::REST_METHOD;

	/**
	 * Route of the ORDER STATUS receiver, relative to the namespace above. The
	 * full address a sender posts to is
	 * `<site>/wp-json/factuarea/v1/order-status`.
	 *
	 * MEASURED, not chosen. Read on 2026-09-18 off the frozen contract the
	 * platform publishes for this lane,
	 * `backend/docs/architecture/store-order-status-receiver-contract.md` §2, and
	 * off the constructor behind it,
	 * `App\Integration\Infrastructure\Provider\Adapters\WooCommerce\WooCommerceEndpoints::orderStatus()`,
	 * which composes `{baseUrl}` + `/wp-json/factuarea/v1/` + `order-status`.
	 * The sender exists and is deployed: a route spelled otherwise here is a 404
	 * on every notice.
	 */
	public const INBOUND_ORDER_STATUS_ROUTE = '/order-status';

	/**
	 * Route of the REFUND receiver, relative to the same namespace.
	 *
	 * MEASURED on the same day and from the same two places: §2 of that contract
	 * lists TWO routes, and `WooCommerceEndpoints::orderRefund()` composes
	 * `order-refund` exactly as its sibling composes `order-status`.
	 *
	 * IT IS A SEPARATE ROUTE, and the contract says why in its own words: the
	 * two notices have different bodies, different risks and different
	 * permissions in the receiver, so a single entry point would force this
	 * plugin to branch on the content before knowing what it is validating, and
	 * the day the status notice changes shape it would drag the one about money
	 * with it.
	 *
	 * The planning documents of THIS change describe a single receiver that
	 * tells the two notices apart by their shape. The measured contract wins,
	 * because the sender is already written against it; the divergence is
	 * reported rather than resolved in silence.
	 */
	public const INBOUND_ORDER_REFUND_ROUTE = '/order-refund';

	/**
	 * Route of the CATALOGUE, PRICE and STOCK push receiver, relative to the
	 * same namespace.
	 *
	 * DECIDED HERE, and said out loud because it is the one inbound address that
	 * is NOT measured. The outbound catalogue lane of the platform was read on
	 * 2026-09-18 (`WooCommerceCatalogWriter`, `WooCommercePriceWriter`,
	 * `WooCommerceInventoryWriter` and the route table they all use): today it
	 * writes through the NATIVE WooCommerce REST API - `wc/v3/products/batch`
	 * and `wc/v3/products/{id}/variations/batch` - with the store's key/secret
	 * pair, and names no address under this plugin's namespace at all.
	 *
	 * So this receiver is born without a sender, on purpose and with its
	 * contract published first, which is the opposite of what happened with the
	 * invoice correlation route. `catalog-push` is chosen to match the vocabulary
	 * the platform already uses for that lane (`store-outbound-catalog-push`,
	 * `CatalogPushMode`, `StoreOutboundPushKind::catalog()`), so that whoever
	 * writes the sender finds the name they would have guessed.
	 */
	public const INBOUND_CATALOG_PUSH_ROUTE = '/catalog-push';

	/**
	 * Header carrying the signature of an INBOUND delivery.
	 *
	 * REFERENCED from `MyAccount\InvoiceCorrelationEndpoint::SIGNATURE_HEADER`
	 * rather than written again. It is the same header name, because it is the
	 * same plugin receiving on its own routes, and the scheme is the same too -
	 * base64 of the HMAC-SHA256 digest of the RAW body, keyed with the store
	 * signing secret.
	 *
	 * DO NOT CONFUSE IT WITH THE OUTGOING ONE. Deliveries this plugin SENDS sign
	 * themselves into `HEADER_SIGNATURE` (`X-WC-Webhook-Signature`), because they
	 * impersonate a native WooCommerce webhook down to the header names. And the
	 * platform has a THIRD lane - its outbound webhooks to an endpoint the
	 * customer configures - which uses this very name with a DIFFERENT scheme:
	 * hexadecimal over `timestamp.'.'.body`. The name says nothing about the
	 * scheme; the receiver does. Unifying them because they look alike would
	 * reject every delivery of this lane.
	 */
	public const INBOUND_SIGNATURE_HEADER = InvoiceCorrelationEndpoint::SIGNATURE_HEADER;

	/**
	 * Delivery header carrying the address of the store the event came from.
	 */
	public const HEADER_SOURCE = 'X-WC-Webhook-Source';

	/**
	 * Delivery header carrying the topic, as `resource.event`.
	 */
	public const HEADER_TOPIC = 'X-WC-Webhook-Topic';

	/**
	 * Delivery header carrying the resource half of the topic.
	 */
	public const HEADER_RESOURCE = 'X-WC-Webhook-Resource';

	/**
	 * Delivery header carrying the event half of the topic.
	 */
	public const HEADER_EVENT = 'X-WC-Webhook-Event';

	/**
	 * Delivery header carrying the signature: the base64 encoding of the
	 * HMAC-SHA256 digest of the RAW body, keyed with the store secret. This is
	 * the header the receiver's verifier reads; no other name is accepted.
	 */
	public const HEADER_SIGNATURE = 'X-WC-Webhook-Signature';

	/**
	 * Delivery header carrying the identifier of the webhook that sent it.
	 *
	 * TRACING ONLY. It must never take part in composing an event identity: it
	 * belongs to the delivery, not to the order, and a redelivery carries a
	 * different one. See EventIdentity.
	 */
	public const HEADER_WEBHOOK_ID = 'X-WC-Webhook-ID';

	/**
	 * Delivery header carrying the identifier of this particular delivery
	 * attempt.
	 *
	 * TRACING ONLY, for the same reason as the previous one, and more sharply:
	 * this one changes on every single attempt.
	 */
	public const HEADER_DELIVERY_ID = 'X-WC-Webhook-Delivery-ID';

	/**
	 * The seven native delivery headers, in the order a native WooCommerce
	 * delivery sends them.
	 *
	 * The set is complete on purpose. A delivery that carried six of them would
	 * still be accepted by the receiver today, and would stop being
	 * indistinguishable from a native one the moment anything downstream starts
	 * reading the seventh.
	 *
	 * @var list<string>
	 */
	public const DELIVERY_HEADERS = array(
		self::HEADER_SOURCE,
		self::HEADER_TOPIC,
		self::HEADER_RESOURCE,
		self::HEADER_EVENT,
		self::HEADER_SIGNATURE,
		self::HEADER_WEBHOOK_ID,
		self::HEADER_DELIVERY_ID,
	);

	/**
	 * EIGHTH delivery header, this plugin's own, carrying the identifier of the
	 * store as it was configured.
	 *
	 * It is deliberately NOT in DELIVERY_HEADERS above. That list is «the seven
	 * a native WooCommerce delivery sends», it is asserted as such, and a
	 * receiver that only knows those seven has to keep accepting the delivery
	 * without changing a line. This one is ADDITIVE: it travels only when a
	 * store identifier is configured, and its absence is an ordinary delivery.
	 *
	 * WHY THE IDENTIFIER AND NOT THE ORIGIN ADDRESS. The address already travels
	 * in HEADER_SOURCE (`home_url()`), and it does NOT tell two shops registered
	 * under the same connection apart. The identifier is the value the merchant
	 * pasted from Factuarea, so it is exactly the key an identity extractor on
	 * the other side would need, and guessing which of two shops sent a sale is
	 * guessing which company it invoices against.
	 */
	public const HEADER_STORE_ID = 'X-Factuarea-Store-Id';

	/**
	 * Name of the outbox table, WITHOUT the WordPress table prefix. Whoever
	 * builds the real name prepends `$wpdb->prefix`: a hard-coded `wp_` breaks
	 * every installation that chose another prefix, which is most of the ones
	 * that were ever migrated.
	 */
	public const OUTBOX_TABLE = 'factuarea_outbox';

	/**
	 * Name of the INBOUND delivery table, WITHOUT the WordPress table prefix,
	 * exactly like the outbox one above: whoever builds the real name prepends
	 * `$wpdb->prefix`, because a hard-coded `wp_` breaks every installation that
	 * chose another prefix.
	 *
	 * It holds the idempotency keys of the deliveries that have been applied,
	 * with a UNIQUE index on the key. A TABLE and not an option or an order
	 * meta, and the reason is atomicity: an option is one row read and written
	 * whole, so two simultaneous deliveries overwrite each other and the claim
	 * stops being atomic; and an order meta only serves the notices that already
	 * resolved their order, while a catalogue push has no order at all.
	 *
	 * The claim is an INSERT that either goes in or collides with the index.
	 * There is no window between checking and acting, which is exactly the
	 * window two concurrent deliveries walk through.
	 *
	 * ON UNINSTALL THIS TABLE IS DROPPED, unlike the outbox one. The outbox
	 * holds deliveries that happened and is the merchant's record; this one is
	 * internal deduplication bookkeeping and says nothing to anybody.
	 */
	public const INBOUND_TABLE = 'factuarea_inbound';

	/**
	 * How long an applied idempotency key is kept, in seconds. THIRTY DAYS.
	 *
	 * THE NUMBER IS NOT A PREFERENCE, IT IS A FLOOR WITH A MARGIN. It has to be
	 * STRICTLY GREATER than the total span of the shared retry schedule,
	 * because a retry that arrives with its key already purged is taken for a
	 * new delivery and its effect is applied a SECOND time - a second refund on
	 * an order, a second corrective invoice behind it.
	 *
	 * Measured against `RetrySchedule::INTERVALS` on 2026-09-18: the seven waits
	 * are 60, 300, 1800, 7200, 43200, 86400 and 259200 seconds, which add up to
	 * 398,160 seconds - 4 days, 14 hours and 36 minutes. With the +/-10 per cent
	 * of `RetrySchedule::JITTER_RATIO` the worst case is 437,976 seconds, a bit
	 * over 5 days. Thirty days is 2,592,000, so the margin is roughly six times
	 * the whole schedule, which absorbs a sender rescued late from a queue that
	 * was stopped.
	 *
	 * The test that guards it compares this value with the SUM of
	 * `RetrySchedule::INTERVALS` and not with a number typed by hand, so that
	 * widening the schedule turns the test red instead of opening the hole in
	 * silence.
	 *
	 * The prune runs on the SAME scheduled pass that already drains the outbox.
	 * A second scheduled task would be a second thing that can be disabled in a
	 * shop, and the one that got disabled would be this one.
	 */
	public const INBOUND_RETENTION_SECONDS = 2592000;

	/**
	 * Prefix shared by every option this plugin stores. It is what the uninstall
	 * routine and the diagnostic redaction both select by.
	 */
	public const OPTION_PREFIX = 'factuarea_wc_';

	/**
	 * Option holding the address deliveries are POSTED to: the address of the
	 * Factuarea APPLICATION, the one the merchant logs in to.
	 *
	 * It is the delivery half of a SPLIT pair. See OPTION_API_BASE_URL below for
	 * the other half and for the measurement that forced the two apart.
	 */
	public const OPTION_BASE_URL = 'factuarea_wc_base_url';

	/**
	 * Option holding the address the v1 reads are made against.
	 *
	 * MEASURED on 2026-09-02, and the measurement is the whole reason this
	 * option exists instead of one address serving both directions. The
	 * ingestion route hangs off the APPLICATION domain, while every `/v1/*`
	 * route is mounted under a domain constraint on the API host - in production
	 * `api.factuarea.com`. So the host this plugin POSTS an order to and the
	 * host it READS a store or an invoice PDF from are two different names, and
	 * one setting would make one of the two answer 404 whichever value the
	 * merchant typed.
	 *
	 * Two addresses is the honest shape of that fact. The alternative - deriving
	 * the API host from the application host by putting `api.` in front of it -
	 * guesses a naming convention on the merchant's behalf, and gets a
	 * self-hosted or staging installation wrong with no field for them to
	 * correct it in.
	 *
	 * DEFAULT_API_BASE_URL below is what it holds until somebody changes it,
	 * which is the right answer for every merchant of the hosted product.
	 */
	public const OPTION_API_BASE_URL = 'factuarea_wc_api_base_url';

	/**
	 * Address the v1 reads go to unless the merchant configured another one.
	 *
	 * A default rather than a frozen address, so that a self-hosted installation
	 * can be pointed elsewhere; and a default rather than an empty field, so
	 * that the merchant of the hosted product never has to learn that this
	 * address exists.
	 */
	public const DEFAULT_API_BASE_URL = 'https://api.factuarea.com';

	/**
	 * Option holding the v1 API key. READ credential: connection test and
	 * invoice PDF. It never signs anything.
	 */
	public const OPTION_API_KEY = 'factuarea_wc_api_key';

	/**
	 * Option holding the identifier of this store in Factuarea.
	 */
	public const OPTION_STORE_ID = 'factuarea_wc_store_id';

	/**
	 * Option holding the signing secret. WRITE credential: it signs outgoing
	 * deliveries and verifies the incoming correlation callback. It never
	 * authenticates a read.
	 */
	public const OPTION_SIGNING_SECRET = 'factuarea_wc_signing_secret';

	/**
	 * Option holding whether delivery is switched on.
	 */
	public const OPTION_DELIVERY_ENABLED = 'factuarea_wc_delivery_enabled';

	/**
	 * Option recording that the signing secret has already been shown once, so
	 * the settings screen never shows it a second time.
	 */
	public const OPTION_SECRET_REVEALED = 'factuarea_wc_secret_revealed';

	/**
	 * Option recording the installed schema version of the outbox table.
	 *
	 * Written by `Outbox\OutboxTable::install()` on activation. It IS deleted on
	 * uninstall like every other option, even though the outbox table itself is
	 * kept (delivered rows are the merchant's record): `install()` is idempotent
	 * and never short-circuits on this marker, so a reinstall recreates the marker
	 * against whatever table it finds. Leaving it behind would be the one option
	 * this plugin writes that the uninstall routine does not know about.
	 */
	public const OPTION_OUTBOX_SCHEMA_VERSION = 'factuarea_wc_outbox_schema_version';

	/**
	 * Option holding whether the ORDER STATUS receiver is switched on.
	 *
	 * IT IS BORN DISABLED, and that is the point of it existing at all: updating
	 * the plugin must not change the behaviour of a shop that touched nothing.
	 * It is the same rule the outgoing lane applies with OPTION_DELIVERY_ENABLED.
	 *
	 * A delivery to a disabled receiver is answered declaring it disabled and
	 * its idempotency key is NOT recorded. Recording it would mean that, after
	 * the merchant switched the receiver on, the sender's retry would get a
	 * "redelivery discarded" and take as published a notice that was never
	 * applied.
	 */
	public const OPTION_ORDER_STATUS_RECEIVER_ENABLED = 'factuarea_wc_order_status_receiver_enabled';

	/**
	 * Option holding whether the CATALOGUE, PRICE and STOCK push receiver is
	 * switched on. Born disabled, for the same reasons as the one above.
	 *
	 * The two receivers get one switch each and NOT one switch between them: a
	 * merchant who wants their prices published from Factuarea does not
	 * necessarily want anything written into their orders, and one switch would
	 * make them take both or neither.
	 *
	 * Neither switch has a second SECRET next to it. The signing secret is ONE -
	 * the one that already signs the outgoing deliveries - because a second
	 * field is a second place for the merchant to get it wrong and a second
	 * value to rotate.
	 */
	public const OPTION_CATALOG_PUSH_RECEIVER_ENABLED = 'factuarea_wc_catalog_push_receiver_enabled';

	/**
	 * Option recording the installed schema version of the INBOUND delivery
	 * table.
	 *
	 * Written by `Inbound\InboundDeliveryTable::install()` on activation, and
	 * deleted on uninstall like every other option. Unlike the outbox marker,
	 * here the TABLE goes too; see INBOUND_TABLE for why the two tables are
	 * treated differently.
	 */
	public const OPTION_INBOUND_SCHEMA_VERSION = 'factuarea_wc_inbound_schema_version';

	/**
	 * Every option this plugin writes. The uninstall routine deletes exactly
	 * this list and nothing else, enumerated one by one rather than matched by
	 * prefix: a prefix match would happily delete an option a future version of
	 * something else happened to name the same way.
	 *
	 * @var list<string>
	 */
	public const ALL_OPTIONS = array(
		self::OPTION_BASE_URL,
		self::OPTION_API_BASE_URL,
		self::OPTION_API_KEY,
		self::OPTION_STORE_ID,
		self::OPTION_SIGNING_SECRET,
		self::OPTION_DELIVERY_ENABLED,
		self::OPTION_SECRET_REVEALED,
		self::OPTION_OUTBOX_SCHEMA_VERSION,
		self::OPTION_ORDER_STATUS_RECEIVER_ENABLED,
		self::OPTION_CATALOG_PUSH_RECEIVER_ENABLED,
		self::OPTION_INBOUND_SCHEMA_VERSION,
	);

	/**
	 * The two options that hold a credential. They are the ones that must never
	 * appear in a message, an admin notice, a log line or a diagnostic dump.
	 *
	 * @var list<string>
	 */
	public const CREDENTIAL_OPTIONS = array(
		self::OPTION_API_KEY,
		self::OPTION_SIGNING_SECRET,
	);

	/**
	 * Prefix of every meta key this plugin writes on an order.
	 */
	public const META_PREFIX = '_factuarea_';

	/**
	 * Meta key of the buyer tax identifier.
	 *
	 * MEASURED on 2026-09-02 against the connector's enumerated catalogue of
	 * WooCommerce tax identifier meta keys, which reserves exactly this literal
	 * for this plugin and places it first by preference. It is not a choice this
	 * package gets to make: the connector reads the delivered body by walking
	 * that catalogue, so a different key means the tax identifier is captured
	 * and then never read, and every B2B sale invoices as a simplified invoice
	 * anyway - which is the whole failure this plugin exists to remove.
	 */
	public const TAX_ID_META_KEY = '_factuarea_tax_id';

	/**
	 * Meta key of the mirror copy, written ONLY if the measurement of the
	 * delivered body shows that the primary key does not appear in it.
	 *
	 * It is taken from the connector's catalogue and not invented: the plugin is
	 * forbidden from writing a key the catalogue does not enumerate, because a
	 * key nobody reads is the same as no key at all. The property that makes a
	 * key usable here is the absence of a leading underscore, which is what lets
	 * it survive into a representation that hides protected meta.
	 *
	 * MEASURED on 2026-09-02 against that catalogue: `billing_nif` is NOT the
	 * only key without the underscore - `vat_number` has none either. The choice
	 * between the two is therefore made on what the key MEANS and not on its
	 * shape, and it does not change: `billing_nif` holds the Spanish tax
	 * identifier, which is the datum this plugin captures, while `vat_number`
	 * belongs to the intra-community VAT number extension, whose value carries a
	 * country prefix this plugin never writes. Mirroring into `vat_number` would
	 * put a tax identifier where the connector expects a VAT number; it also
	 * ranks lower in the catalogue's order of preference, so the connector would
	 * read `billing_nif` first anyway whenever a third-party plugin had filled
	 * it in.
	 *
	 * TWO RULES FOR WHOEVER WIRES IT, and both come from the fact that this key
	 * is shared ground rather than ours:
	 *
	 *  1. The mirror is written only when the measurement says it is needed.
	 *     Writing it "just in case" duplicates a personal datum in the
	 *     merchant's database and creates a second source for the same value,
	 *     which diverges the day somebody edits one of them.
	 *  2. The mirror never overwrites a non-empty existing value. A store that
	 *     already runs a third-party tax identifier plugin may be writing this
	 *     key itself, and overwriting it would replace a value that store has
	 *     been invoicing with.
	 *
	 * The clean way out of the sharing is for the connector to enumerate a
	 * non-protected key of ours, `factuarea_tax_id`. Until it does, this is the
	 * key that both exists in the catalogue and survives the representation.
	 */
	public const TAX_ID_MIRROR_META_KEY = 'billing_nif';

	/**
	 * Identifier of the checkout field as the additional checkout fields
	 * interface names it: namespace and key, separated by a slash.
	 */
	public const ADDITIONAL_FIELD_ID = 'factuarea/tax-id';

	/**
	 * Meta key under which the additional checkout fields interface persists the
	 * billing-group field above.
	 *
	 * DECLARED, NOT MEASURED - the measurement needs a running WooCommerce and
	 * belongs to the phase that builds the delivered body. Whoever runs it
	 * corrects this literal here if the installed WooCommerce writes another
	 * one, and nowhere else: this is the single place the rest reads it from.
	 * The value is the shape that interface produces for a billing field,
	 * `_wc_{group}/{namespace}/{key}`.
	 */
	public const ADDITIONAL_FIELD_META_KEY = '_wc_billing/factuarea/tax-id';

	/**
	 * Meta key holding the idempotency key this plugin derived for an order, so
	 * that a later correlation callback can find the order it belongs to.
	 */
	public const IDEMPOTENCY_KEY_META_KEY = '_factuarea_idempotency_key';

	/**
	 * Meta key holding the identifier of the invoice correlated with an order.
	 * Its PRESENCE is what makes the invoice access appear for the buyer; its
	 * absence means nothing is shown, never a broken link.
	 */
	public const INVOICE_ID_META_KEY = '_factuarea_invoice_id';

	/**
	 * Meta key holding the fulfilment status this plugin RECEIVED for an order.
	 *
	 * It is the plugin's OWN status and it never replaces the native order
	 * status; `Contract\FulfilmentState` holds the three reasons. Its value is
	 * one of the four of that class, stored as the wire literal so that what is
	 * in the database is the same string the notice carried.
	 *
	 * Its PRESENCE is what makes the status section appear for the buyer; its
	 * absence means nothing is shown, exactly as the invoice access works.
	 */
	public const FULFILMENT_STATUS_META_KEY = '_factuarea_fulfilment_status';

	/**
	 * Meta key holding the tracking number of the shipment.
	 *
	 * Written only when the notice declares it, and DELETED when a later notice
	 * no longer does: a shop that kept it would show the buyer the tracking of a
	 * previous shipment as if it were the current one.
	 */
	public const TRACKING_NUMBER_META_KEY = '_factuarea_tracking_number';

	/**
	 * Meta key holding the carrier name of the shipment. Same write-and-delete
	 * rule as the number.
	 */
	public const TRACKING_CARRIER_META_KEY = '_factuarea_tracking_carrier';

	/**
	 * Meta key holding the tracking address of the shipment.
	 *
	 * Stored EXACTLY as it arrived and never recomposed from the carrier and the
	 * number: the platform derives it from its own carrier template and does not
	 * persist it in any column, so deriving it a second time here would give two
	 * different addresses for the same shipment the day that template changes.
	 */
	public const TRACKING_URL_META_KEY = '_factuarea_tracking_url';

	/**
	 * Meta key holding the instant of the LAST fulfilment notice applied to the
	 * order, as the notice declared it.
	 *
	 * It exists so that a notice that arrives LATE does not overwrite a more
	 * recent one that was already applied. Deliveries are queued and retried,
	 * so «shipped» published at 10:00 can legitimately arrive after «delivered»
	 * published at 11:00, and without this the buyer would be told their parcel
	 * is on its way after being told it arrived.
	 *
	 * It stores the instant OF THE FACT and not the instant of the write: the
	 * write order is the one that is wrong.
	 */
	public const FULFILMENT_APPLIED_AT_META_KEY = '_factuarea_fulfilment_applied_at';

	/**
	 * Meta key written ON THE REFUND OBJECT that this plugin created from an
	 * inbound notice, holding the idempotency key that produced it.
	 *
	 * THE ANTI-LOOP MARK, and it has to be read by BOTH refund captors of this
	 * plugin, not one:
	 *
	 *  1. `Events\OrderRefundedListener::record_refund()` fires on
	 *     `woocommerce_order_refunded`, which `wc_create_refund()` triggers.
	 *  2. `Events\OrderStatusChangedListener::refund_identities()` composes one
	 *     identity PER REFUND of the order, and a TOTAL refund also changes the
	 *     order status, which is that listener's hook.
	 *
	 * Guarding only the first leaves the loop open through the second, and the
	 * symptom arrives weeks later as a DUPLICATED corrective invoice in a series
	 * filed with the tax authority, where a document is not deleted but
	 * corrected with another document.
	 */
	public const INBOUND_REFUND_META_KEY = '_factuarea_inbound_refund';

	/**
	 * Meta key holding the public reference of the corrective invoice that backs
	 * an inbound refund, so that merchant and buyer can both see which document
	 * the money came back under.
	 *
	 * The PUBLIC reference - the number of the document - and never an internal
	 * identifier of the platform: the buyer may have to quote it.
	 */
	public const CORRECTIVE_REFERENCE_META_KEY = '_factuarea_corrective_reference';

	/**
	 * Meta key holding the scope of an inbound refund, `total` or `partial`, as
	 * `Contract\InboundRefundNotice` declares them.
	 */
	public const REFUND_SCOPE_META_KEY = '_factuarea_refund_scope';

	/**
	 * Meta key of the ARTICLE, holding the remote identity resolved for it.
	 *
	 * Written when a catalogue push resolved the article by its REFERENCE, so
	 * that the next push resolves it by identity and does not search again. The
	 * search is the expensive and the ambiguous half of the resolution: a
	 * reference that two articles share is not applied to either, and once the
	 * identity is recorded that question is never asked again for that article.
	 *
	 * It carries ONE value - the WooCommerce product handle - and not a
	 * product/variation pair: a variation is addressed by its own identifier.
	 */
	public const REMOTE_PRODUCT_ID_META_KEY = '_factuarea_remote_product_id';

	/**
	 * Field name of the rejection code inside a rejection body of the ingestion
	 * contract. Its presence, readable, is what tells a rejection of the
	 * contract apart from any other non-2xx response.
	 */
	public const REJECTION_BODY_CODE_FIELD = 'code';

	/**
	 * Field name of the merchant-facing message inside a rejection body.
	 */
	public const REJECTION_BODY_MESSAGE_FIELD = 'message';

	/**
	 * Field name carrying the class of the rejection inside the body. The
	 * receiver publishes it alongside the wait header precisely so that a plugin
	 * that was distributed before a code existed can still classify it.
	 */
	public const REJECTION_BODY_RETRYABLE_FIELD = 'retryable';

	/**
	 * Field name carrying the declared wait, in seconds, inside the body.
	 */
	public const REJECTION_BODY_RETRY_AFTER_FIELD = 'retry_after';

	/**
	 * Header carrying the declared wait of a retryable rejection. Its presence
	 * is the signal the classifier reads; the receiver sets it if and only if
	 * the rejection is retryable.
	 */
	public const RETRY_AFTER_HEADER = 'Retry-After';

	/**
	 * Not instantiable: this is a namespace of frozen values, not an object.
	 */
	private function __construct() {
	}
}
