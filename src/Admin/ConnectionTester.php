<?php
/**
 * The connection test of the settings screen.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Admin;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\HttpResponse;
use Factuarea\WooCommerce\Transport\WordPressHttpClient;

/**
 * Answers one question for the merchant - "is this shop actually connected?" -
 * and, when the answer is no, which of the four settings is the one to fix.
 *
 * ---------------------------------------------------------------------------
 * WHY IT READS A STORE INSTEAD OF SENDING A TEST DELIVERY
 * ---------------------------------------------------------------------------
 * The obvious probe would be to post a sample delivery to the ingestion route
 * and report what came back. It cannot work, and the reason is in the route's
 * own contract rather than in anything this plugin does.
 *
 * That route answers 200 both when it has queued the event AND when it does not
 * know the store the delivery claims to come from. The second 200 is
 * deliberate: a provider that got a 4xx for an unknown store would retry the
 * same rejected delivery for as long as its own backoff allowed, and a store
 * that was disconnected on purpose would generate that traffic forever. So the
 * receiver accepts and discards.
 *
 * The consequence for a probe is total: "correctly configured" and "nobody over
 * there has ever heard of you" produce the SAME answer, which is the one
 * distinction the merchant pressing this button is trying to make. A test that
 * turns green in both cases is worse than no test, because it is believed.
 *
 * So the test READS instead. It asks the public v1 for the store that is
 * configured, with the API key that is configured, and every failure mode of
 * that read maps to a setting the merchant can act on. It also sends nothing:
 * the ingestion route never sees a fabricated order, so no probe can ever be
 * mistaken for a sale.
 *
 * ---------------------------------------------------------------------------
 * THE READ GOES TO THE API ADDRESS, WHICH IS NOT THE DELIVERY ADDRESS
 * ---------------------------------------------------------------------------
 * Deliveries are posted to the application host and the v1 is served from the
 * API host; the two are separate names. See `Constants::OPTION_API_BASE_URL`
 * for the measurement. Pointing this read at the delivery address is a mistake
 * a merchant can make with one field, so an answer that does not look like the
 * v1 at all is reported as an unexpected response that names that possibility,
 * never as "your store does not exist".
 *
 * ---------------------------------------------------------------------------
 * THE REQUIRED PERMISSION IS NOT WRITTEN DOWN HERE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * The v1 requires a permission - a scope - to read stores, and the name of that
 * scope is still being settled between the two changes that publish this
 * surface. This plugin is DISTRIBUTED: a name frozen into a released version
 * outlives the decision that produced it, and correcting it means waiting for
 * every merchant to update.
 *
 * So no scope name is frozen anywhere in this class or in the documentation of
 * this package. A refusal for lack of permission is a diagnostic in its own
 * right, and it is useful whatever the permission ends up being called; when
 * the answer happens to name the scope, that name is lifted out of the answer
 * and repeated back, which is the only way it can be right by construction.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IT RETURNS EVER CARRIES A CREDENTIAL
 * ---------------------------------------------------------------------------
 * The API key travels in a header and appears in nothing this class produces:
 * not in the message, not in the next step, not truncated, not as a length.
 * The one piece of the receiver's answer that is repeated back is a token
 * shaped like a permission name; the receiver's own message is NOT echoed,
 * partly because it is written in the language of the account and this package
 * is English-only, and partly because a message this class did not write is a
 * message whose contents it cannot promise anything about.
 *
 * This class is not `final` for the same reason `OutboxRepository` is not: the
 * settings screen takes it by constructor so that the screen's own test can
 * hand it a double, and a final class cannot be doubled.
 */
class ConnectionTester {

	/**
	 * Everything is configured and the receiver recognised both the key and the
	 * store.
	 */
	public const DIAGNOSTIC_OK = 'ok';

	/**
	 * A setting the test needs is empty, so no request was made at all.
	 *
	 * The phase that specified this class names five outcomes and this is a
	 * sixth, which is a decision rather than an oversight: the five describe
	 * what a request came back with, and this one is the case where there is
	 * nothing to send. Reporting it as one of the other five would send the
	 * merchant to check a credential that is not the problem.
	 */
	public const DIAGNOSTIC_NOT_CONFIGURED = 'not_configured';

	/**
	 * The receiver refused the API key.
	 */
	public const DIAGNOSTIC_INVALID_CREDENTIAL = 'invalid_credential';

	/**
	 * The receiver accepted the API key and refused what it was being asked to
	 * do with it.
	 */
	public const DIAGNOSTIC_INSUFFICIENT_PERMISSION = 'insufficient_permission';

	/**
	 * The receiver has no such store under the account the API key belongs to.
	 */
	public const DIAGNOSTIC_STORE_NOT_IN_ACCOUNT = 'store_not_in_account';

	/**
	 * Nothing answered: no route to the host, a dropped connection, a timeout, a
	 * certificate that did not verify.
	 */
	public const DIAGNOSTIC_ADDRESS_UNREACHABLE = 'address_unreachable';

	/**
	 * Something answered and it was not any of the above.
	 */
	public const DIAGNOSTIC_UNEXPECTED_RESPONSE = 'unexpected_response';

	/**
	 * The closed catalogue of diagnostics.
	 *
	 * Closed on purpose and enumerated here rather than derived from whatever
	 * the method happens to return: the settings screen renders one of these and
	 * the tests assert against them, so a seventh outcome invented in a branch
	 * would reach the merchant as an empty notice instead of failing here.
	 *
	 * @var list<string>
	 */
	public const DIAGNOSTICS = array(
		self::DIAGNOSTIC_OK,
		self::DIAGNOSTIC_NOT_CONFIGURED,
		self::DIAGNOSTIC_INVALID_CREDENTIAL,
		self::DIAGNOSTIC_INSUFFICIENT_PERMISSION,
		self::DIAGNOSTIC_STORE_NOT_IN_ACCOUNT,
		self::DIAGNOSTIC_ADDRESS_UNREACHABLE,
		self::DIAGNOSTIC_UNEXPECTED_RESPONSE,
	);

	/**
	 * Shape of a permission name, used to lift one out of an answer that
	 * mentions it.
	 *
	 * Deliberately a shape and not a list: this class is forbidden from knowing
	 * which permission the read needs, so all it can recognise is that something
	 * in the answer looks like the name of one.
	 *
	 * The shape is also the guard on what gets repeated back. Only a lower-case
	 * pair joined by a colon leaves this class, so no matter what a receiver -
	 * or something pretending to be one - puts in that field, what reaches the
	 * merchant is a permission name and never a sentence, a link or a secret.
	 */
	private const SCOPE_PATTERN = '/\b([a-z][a-z0-9_]*:[a-z][a-z0-9_]*)\b/';

	/**
	 * The transport. Every request of this plugin goes through it.
	 *
	 * @var WordPressHttpClient
	 */
	private WordPressHttpClient $client;

	/**
	 * Constructor.
	 *
	 * @param WordPressHttpClient|null $client Transport to read with; the plugin's
	 *                                         own when not given.
	 */
	public function __construct( ?WordPressHttpClient $client = null ) {
		$this->client = $client ?? new WordPressHttpClient();
	}

	/**
	 * Reads the configured store and says what that read means.
	 *
	 * Never throws: it is called from a button in the administration screen, and
	 * a merchant who pressed "test" deserves an answer rather than a white page.
	 *
	 * @return array{ok: bool, diagnostic: string, message: string, next_step: string}
	 *         `diagnostic` is always one of self::DIAGNOSTICS.
	 */
	public function test(): array {
		$missing = $this->missing_settings();

		if ( array() !== $missing ) {
			return $this->result(
				self::DIAGNOSTIC_NOT_CONFIGURED,
				sprintf(
					/* translators: %s: comma-separated names of the settings that are still empty. */
					__( 'The connection cannot be tested until these settings are filled in: %s.', 'factuarea-for-woocommerce' ),
					implode( ', ', $missing )
				),
				__( 'Fill them in on this screen and save, then run the test again.', 'factuarea-for-woocommerce' )
			);
		}

		$response = $this->client->get(
			$this->store_url(),
			array(
				'Authorization' => 'Bearer ' . $this->api_key(),
				'Accept'        => 'application/json',
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * Turns one answer into one diagnostic.
	 *
	 * The order of the branches is the order of certainty. A refusal of the
	 * credential and a refusal of the permission are unambiguous whoever
	 * produced them, because the only credential this request carries is the API
	 * key. A 404 is NOT unambiguous - it is also what any host answers for an
	 * address it does not serve - so it only becomes "this store is not in your
	 * account" when the answer is recognisably one of the receiver's own error
	 * documents. Without that recognition it is an unexpected response whose
	 * next step names the likelier cause, which is an address pointing at the
	 * application instead of at the API.
	 *
	 * @param HttpResponse $response What came back.
	 * @return array{ok: bool, diagnostic: string, message: string, next_step: string}
	 */
	private function interpret( HttpResponse $response ): array {
		if ( $response->is_transport_failure() ) {
			return $this->result(
				self::DIAGNOSTIC_ADDRESS_UNREACHABLE,
				sprintf(
					/* translators: %s: reason the request did not complete, as the site reported it. */
					__( 'The Factuarea API address did not answer: %s', 'factuarea-for-woocommerce' ),
					(string) $response->transport_error
				),
				__( 'Check the Factuarea API address for a typo, and check that this server is allowed to make outgoing requests to it - a firewall, an offline mode or a proxy on this host will stop them.', 'factuarea-for-woocommerce' )
			);
		}

		$status = (int) $response->status_code;

		if ( 401 === $status ) {
			return $this->result(
				self::DIAGNOSTIC_INVALID_CREDENTIAL,
				__( 'Factuarea did not accept the API key.', 'factuarea-for-woocommerce' ),
				__( 'Create a new API key in Factuarea, paste it into the API key field on this screen and save, then run the test again. A key that was revoked, or one copied with a missing character, fails exactly like this.', 'factuarea-for-woocommerce' )
			);
		}

		if ( 403 === $status ) {
			return $this->result(
				self::DIAGNOSTIC_INSUFFICIENT_PERMISSION,
				__( 'The API key is valid, and it is not allowed to read stores.', 'factuarea-for-woocommerce' ),
				$this->permission_next_step( $response )
			);
		}

		if ( 404 === $status && $this->is_receiver_error_document( $response ) ) {
			return $this->result(
				self::DIAGNOSTIC_STORE_NOT_IN_ACCOUNT,
				__( 'The API key is valid, and the configured store does not belong to its account.', 'factuarea-for-woocommerce' ),
				__( 'Open the store in Factuarea, copy its identifier again into the store identifier field, and check that the API key was created in the same account as the store.', 'factuarea-for-woocommerce' )
			);
		}

		if ( $status >= 200 && $status < 300 ) {
			return $this->interpret_success( $response, $status );
		}

		return $this->unexpected( $status );
	}

	/**
	 * Reads a 2xx and decides whether it is the store document that was asked
	 * for.
	 *
	 * A 2xx is not by itself an answer: a login page, a maintenance page and a
	 * proxy's landing page are all served with 200. What settles it is the
	 * identity key of the store, so that is what is looked for.
	 *
	 * @param HttpResponse $response What came back.
	 * @param int          $status   Its status code.
	 * @return array{ok: bool, diagnostic: string, message: string, next_step: string}
	 */
	private function interpret_success( HttpResponse $response, int $status ): array {
		$document = $response->decoded_body();

		if ( null === $document || ! isset( $document['id'] ) || ! is_string( $document['id'] ) || '' === $document['id'] ) {
			return $this->unexpected( $status );
		}

		$name = isset( $document['name'] ) && is_string( $document['name'] )
			? trim( sanitize_text_field( $document['name'] ) )
			: '';

		$message = '' === $name
			? __( 'The connection works. Factuarea accepted the API key and found the configured store.', 'factuarea-for-woocommerce' )
			: sprintf(
				/* translators: %s: name of the store as it is registered in Factuarea. */
				__( 'The connection works. Factuarea accepted the API key and found the store "%s".', 'factuarea-for-woocommerce' ),
				$name
			);

		return $this->result(
			self::DIAGNOSTIC_OK,
			$message,
			__( 'Generate the signing secret if you have not already, paste it into Factuarea, and switch delivery on.', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * The next step of a refusal for lack of permission, naming the permission
	 * when the answer named it.
	 *
	 * @param HttpResponse $response What came back.
	 * @return string Next step for the merchant.
	 */
	private function permission_next_step( HttpResponse $response ): string {
		$scope = $this->scope_named_in( $response );

		if ( '' === $scope ) {
			return __( 'In Factuarea, give this API key permission to read stores - or create a new key that has it, paste it into the API key field and save - then run the test again.', 'factuarea-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: name of the permission the answer said was missing, repeated back as it was received. */
			__( 'In Factuarea, give this API key the "%s" permission - or create a new key that has it, paste it into the API key field and save - then run the test again.', 'factuarea-for-woocommerce' ),
			$scope
		);
	}

	/**
	 * The one answer that is neither a recognised refusal nor the store.
	 *
	 * @param int $status Status code that came back.
	 * @return array{ok: bool, diagnostic: string, message: string, next_step: string}
	 */
	private function unexpected( int $status ): array {
		return $this->result(
			self::DIAGNOSTIC_UNEXPECTED_RESPONSE,
			sprintf(
				/* translators: %d: HTTP status code of the answer. */
				__( 'The Factuarea API address answered with %d, which is not something this test knows how to read.', 'factuarea-for-woocommerce' ),
				$status
			),
			__( 'Check that the Factuarea API address points at the Factuarea API and not at the application you log in to: they are two different addresses, and the second one answers requests without serving this API. If the address is right, the service may be temporarily unavailable - wait and run the test again.', 'factuarea-for-woocommerce' )
		);
	}

	/**
	 * Names of the settings this test needs and does not have.
	 *
	 * The names are the labels of the fields on this screen, so that the message
	 * points at a field the merchant is looking at rather than at an option
	 * name only this code uses.
	 *
	 * The API address is NOT among them, and its absence is a decision: it has a
	 * default, so it is never empty, and a merchant of the hosted product is
	 * never meant to open that field at all. Listing a setting that cannot be
	 * missing would send them looking for a problem that is not there.
	 *
	 * @return list<string> Field labels, in the order they appear on the screen.
	 */
	private function missing_settings(): array {
		$missing = array();

		if ( '' === $this->api_key() ) {
			$missing[] = __( 'the API key', 'factuarea-for-woocommerce' );
		}

		if ( '' === $this->store_id() ) {
			$missing[] = __( 'the store identifier', 'factuarea-for-woocommerce' );
		}

		return $missing;
	}

	/**
	 * Address of the store query.
	 *
	 * The identifier is percent-encoded even though a well-formed one has
	 * nothing to encode: it is a value the merchant pasted, and a stray space or
	 * slash in it would otherwise build a different path and be reported as a
	 * missing store.
	 *
	 * @return string Absolute address of the configured store.
	 */
	private function store_url(): string {
		return $this->api_base_url() . sprintf( Constants::STORE_QUERY_PATH, rawurlencode( $this->store_id() ) );
	}

	/**
	 * The API address, without its trailing slash.
	 *
	 * @return string Address the v1 reads go to.
	 */
	private function api_base_url(): string {
		return SettingsPage::api_base_url();
	}

	/**
	 * The stored API key.
	 *
	 * @return string The key, or an empty string when none is stored.
	 */
	private function api_key(): string {
		return trim( (string) get_option( Constants::OPTION_API_KEY, '' ) );
	}

	/**
	 * The stored store identifier.
	 *
	 * @return string The identifier, or an empty string when none is stored.
	 */
	private function store_id(): string {
		return trim( (string) get_option( Constants::OPTION_STORE_ID, '' ) );
	}

	/**
	 * Is this answer one of the receiver's own error documents?
	 *
	 * The receiver answers every error with a document carrying an `error`
	 * object and a stable code inside it. Recognising that document is what
	 * separates "the receiver told me this store is not yours" from "some host
	 * answered 404", and the difference decides whether the merchant is sent to
	 * check the store identifier or the address.
	 *
	 * @param HttpResponse $response What came back.
	 * @return bool
	 */
	private function is_receiver_error_document( HttpResponse $response ): bool {
		$document = $response->decoded_body();

		if ( null === $document || ! isset( $document['error'] ) || ! is_array( $document['error'] ) ) {
			return false;
		}

		return isset( $document['error']['code'] ) && is_string( $document['error']['code'] ) && '' !== $document['error']['code'];
	}

	/**
	 * The permission the answer named, if it named one.
	 *
	 * Two places are read, in order of how much they can be trusted: the field
	 * the receiver uses to point at the offending parameter, and then the text
	 * of the message. Only the token is taken, never the sentence around it: the
	 * sentence is written in the language of the account and this package is
	 * English-only, and a message this class did not write is one whose contents
	 * it cannot vouch for.
	 *
	 * @param HttpResponse $response What came back.
	 * @return string The permission name, or an empty string when the answer did
	 *                not name one.
	 */
	private function scope_named_in( HttpResponse $response ): string {
		$document = $response->decoded_body();

		if ( null === $document || ! isset( $document['error'] ) || ! is_array( $document['error'] ) ) {
			return '';
		}

		$error = $document['error'];

		foreach ( array( 'param', 'message' ) as $field ) {
			if ( ! isset( $error[ $field ] ) || ! is_string( $error[ $field ] ) ) {
				continue;
			}

			if ( 1 === preg_match( self::SCOPE_PATTERN, $error[ $field ], $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * Builds one result.
	 *
	 * @param string $diagnostic One of self::DIAGNOSTICS.
	 * @param string $message    What happened, for the merchant.
	 * @param string $next_step  What to do about it.
	 * @return array{ok: bool, diagnostic: string, message: string, next_step: string}
	 */
	private function result( string $diagnostic, string $message, string $next_step ): array {
		return array(
			'ok'         => self::DIAGNOSTIC_OK === $diagnostic,
			'diagnostic' => $diagnostic,
			'message'    => $message,
			'next_step'  => $next_step,
		);
	}
}
