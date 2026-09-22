<?php
/**
 * Frozen values and frozen public signatures of the plugin.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

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
 *     public function for_order( string $topic, string $resource, string $event, string $source, string $signature, int $webhook_id, int $delivery_id ): array
 *       - returns array<string, string> keyed by the seven header names below.
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
	 *
	 * TWO placeholders, and the ORDER is the contract: the company first, the
	 * store second. Every resource of the public v1 that belongs to a company
	 * now hangs off a company segment, and the store is one of them; the flat
	 * form this constant used to hold - `/v1/stores/%s` - no longer exists and
	 * answers 404. See OPTION_COMPANY_ID for where the company identifier comes
	 * from and for why the plugin stores it instead of resolving it.
	 *
	 * It stays a TEMPLATE. A constant that already carried a company in it
	 * would be a per-installation value frozen into a released version.
	 */
	public const STORE_QUERY_PATH = '/v1/companies/%s/stores/%s';

	/**
	 * Path of the invoice PDF download, consumed with the API key on the
	 * merchant's behalf and never exposed to the buyer's browser.
	 *
	 * TWO placeholders, company first and invoice second, for the same reason
	 * as STORE_QUERY_PATH above; the `/pdf` suffix is unchanged, and it is what
	 * separates this route from the neighbouring `…/pdf-link`, which answers
	 * with a document carrying a link instead of with the bytes.
	 */
	public const INVOICE_PDF_PATH = '/v1/companies/%s/invoices/%s/pdf';

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
	 * Name of the outbox table, WITHOUT the WordPress table prefix. Whoever
	 * builds the real name prepends `$wpdb->prefix`: a hard-coded `wp_` breaks
	 * every installation that chose another prefix, which is most of the ones
	 * that were ever migrated.
	 */
	public const OUTBOX_TABLE = 'factuarea_outbox';

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
	 * Option holding the identifier of the COMPANY the v1 reads are made
	 * against. It is the segment every company-scoped route of the public v1
	 * now carries, and it is NOT the store identifier above.
	 *
	 * WHY THE MERCHANT PASTES AN IDENTIFIER INSTEAD OF A TAX ID
	 *
	 * MEASURED, not preferred. A lookup by tax identifier does exist, and it is
	 * `GET /v1/accounts/{account}/companies?tax_id=…`: a filter of the company
	 * LISTING, which hangs off the ACCOUNT axis and needs both an account
	 * identifier and permission to read companies. A shop's read credential has
	 * neither, so resolving the tax identifier from here would mean asking the
	 * merchant for an accountancy-wide permission in order to build a store
	 * URL. Storing the identifier the segment actually wants costs one field.
	 *
	 * WHAT MUST NEVER HAPPEN WHEN IT IS EMPTY
	 *
	 * An empty value is an incomplete configuration, and it is reported as one.
	 * It is NEVER resolved, silently or otherwise, to whichever company the
	 * credential happens to belong to: that would issue against the wrong tax
	 * identifier and answer 200 while doing it, which is the one failure the
	 * whole company segment exists to make impossible. No route is built
	 * without it.
	 */
	public const OPTION_COMPANY_ID = 'factuarea_wc_company_id';

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
		self::OPTION_COMPANY_ID,
		self::OPTION_SIGNING_SECRET,
		self::OPTION_DELIVERY_ENABLED,
		self::OPTION_SECRET_REVEALED,
		self::OPTION_OUTBOX_SCHEMA_VERSION,
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
