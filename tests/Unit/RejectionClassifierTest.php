<?php
/**
 * Unit tests for the rejection classifier.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\DeliveryOutcomeKind;
use Factuarea\WooCommerce\Contract\HttpResponse;
use Factuarea\WooCommerce\Transport\RejectionClassifier;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case earns its place.
 *
 * This class decides between retrying a sale and losing it, so the cases here
 * are not a sample of the branches: they are the branches, plus the ones that
 * only exist because the wire is not a clean pipe.
 *
 * The six the acceptance criterion names:
 *
 *  1. A published permanent rejection, never retried.
 *  2. A published retryable rejection, retried after the wait it declared.
 *  3. A code INVENTED after this plugin shipped, with no wait - permanent,
 *     without this plugin knowing anything about the code.
 *  4. A 502 from a proxy with no contract body - transient. This is the case
 *     that stops a paid order from being lost, and it is the reason a rejection
 *     is recognised by the shape of its body rather than by its status code.
 *  5. A timeout - transient.
 *  6. An acceptance.
 *
 * The rest pin decisions that a reading of the code alone would not preserve:
 * that a code nobody has heard of can also be RETRYABLE, that the header wins
 * the number while the body can still rescue a delivery whose header did not
 * survive an intermediary, and that a body which is not this contract's is not
 * this contract's however JSON-shaped it looks.
 *
 * Every rejection is built the way the receiver's responder actually writes one
 * - four body fields, and the wait header if and only if there is a wait - so
 * that a test passing here means something about production and not about a
 * fixture somebody invented.
 */
final class RejectionClassifierTest extends TestCase {

	/**
	 * The codes the receiver's catalogue publishes today, frozen here as a
	 * literal.
	 *
	 * They live in the TEST because the plugin must not know them. The sweep at
	 * the bottom of this file is what keeps that true.
	 *
	 * @var list<string>
	 */
	private const PUBLISHED_CODES = array(
		'unknown_provider',
		'invalid_signature',
		'malformed_payload',
		'ingestion_unavailable',
		'store_budget_exhausted',
		'store_limit_reached',
		'burst_detected',
		'vat_residual_out_of_tolerance',
		'simplified_absolute_limit_exceeded',
		'simplified_threshold_exceeded_without_recipient',
		'store_requires_tax_id',
		'order_line_amount_exceeds_column',
		'exchange_rate_unavailable',
	);

	/**
	 * Case 1: a rejection the catalogue publishes as permanent closes the entry
	 * without a single further attempt.
	 *
	 * @return void
	 */
	public function test_a_published_permanent_rejection_is_never_retried(): void {
		$outcome = $this->classify(
			$this->as_the_receiver_writes_it(
				401,
				'invalid_signature',
				'La firma de la entrega no es válida. Vuelve a copiar la clave secreta desde los ajustes de la tienda en Factuarea.',
				null
			)
		);

		$this->assertSame( DeliveryOutcomeKind::PERMANENT_REJECTION, $outcome->kind );
		$this->assertFalse( $outcome->should_retry() );
		$this->assertTrue( $outcome->is_contract_rejection() );
		$this->assertSame( 'invalid_signature', $outcome->rejection_code );
		$this->assertNull( $outcome->retry_after_seconds );
	}

	/**
	 * Case 2: a rejection that declares a wait is retried after that wait, and
	 * the wait travels with the outcome so that the dispatcher does not have to
	 * guess it.
	 *
	 * @return void
	 */
	public function test_a_rejection_with_a_declared_wait_is_retried_after_that_wait(): void {
		$outcome = $this->classify(
			$this->as_the_receiver_writes_it(
				429,
				'store_budget_exhausted',
				'Se ha agotado el volumen de pedidos que admite tu cuenta en esta ventana.',
				300
			)
		);

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $outcome->kind );
		$this->assertTrue( $outcome->should_retry() );
		$this->assertTrue( $outcome->has_declared_wait() );
		$this->assertSame( 300, $outcome->retry_after_seconds );
	}

	/**
	 * Case 3: the one the whole design exists for. A code that did not exist on
	 * the day this plugin was published, arriving without a wait, is permanent -
	 * and the plugin classifies it correctly without being updated, because it
	 * reads the declaration and not the code.
	 *
	 * @return void
	 */
	public function test_a_code_invented_after_this_plugin_shipped_and_without_a_wait_is_permanent(): void {
		$outcome = $this->classify(
			$this->as_the_receiver_writes_it(
				422,
				'store_fiscal_regime_not_supported_yet',
				'Ese régimen fiscal todavía no se puede facturar desde una tienda.',
				null
			)
		);

		$this->assertSame( DeliveryOutcomeKind::PERMANENT_REJECTION, $outcome->kind );
		$this->assertSame( 'store_fiscal_regime_not_supported_yet', $outcome->rejection_code );
		$this->assertFalse( $outcome->should_retry() );
	}

	/**
	 * The other half of case 3, and it is not decoration: a code nobody has
	 * heard of that DOES declare a wait has to come out retryable. A classifier
	 * with a hidden list of known codes would pass the permanent half of this
	 * pair and fail here.
	 *
	 * @return void
	 */
	public function test_a_code_invented_after_this_plugin_shipped_with_a_wait_is_retried(): void {
		$outcome = $this->classify(
			$this->as_the_receiver_writes_it(
				429,
				'shard_migration_in_progress',
				'Estamos moviendo tu cuenta. Vuelve a intentarlo en un minuto.',
				60
			)
		);

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $outcome->kind );
		$this->assertSame( 60, $outcome->retry_after_seconds );
	}

	/**
	 * Case 4: THE case that keeps a paid order from being lost.
	 *
	 * A gateway between the shop and the receiver answers 502 with an HTML error
	 * page. It carries no wait header, because it is not declaring anything - it
	 * is not the receiver. Classifying it by its status code, or treating "no
	 * wait header" as permanent without first asking whether this is a rejection
	 * of the contract at all, closes the entry for good and the sale never
	 * reaches an invoice.
	 *
	 * @return void
	 */
	public function test_a_proxy_gateway_error_without_a_contract_body_is_transient(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				502,
				array( 'Content-Type' => 'text/html' ),
				'<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>'
			)
		);

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $outcome->kind );
		$this->assertTrue( $outcome->should_retry() );
		$this->assertFalse( $outcome->is_contract_rejection() );
		$this->assertSame( 502, $outcome->status_code );
		$this->assertNull( $outcome->rejection_code );
	}

	/**
	 * Case 5: nothing came back at all. There is no status code to reason about
	 * and no body to read, so it is transient and the outcome says so without a
	 * status code rather than inventing one.
	 *
	 * @return void
	 */
	public function test_a_timeout_is_transient(): void {
		$outcome = $this->classify(
			HttpResponse::transport_failure( 'cURL error 28: Operation timed out after 10001 milliseconds' )
		);

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $outcome->kind );
		$this->assertTrue( $outcome->should_retry() );
		$this->assertNull( $outcome->status_code );
		$this->assertStringContainsString( 'timed out', $outcome->merchant_message );
	}

	/**
	 * Case 6: the receiver took it. The entry closes and is never sent again.
	 *
	 * The body is empty on purpose: that is what the ingestion route answers,
	 * both when it queues the event and when it discards it without effects.
	 *
	 * @return void
	 */
	public function test_an_accepted_delivery_is_accepted(): void {
		$outcome = $this->classify( HttpResponse::received( 200, array(), '' ) );

		$this->assertSame( DeliveryOutcomeKind::ACCEPTED, $outcome->kind );
		$this->assertFalse( $outcome->should_retry() );
		$this->assertSame( 200, $outcome->status_code );
	}

	/**
	 * A 2xx is an acceptance whatever its body looks like.
	 *
	 * The receiver's rejections are 400, 401, 404, 422, 429 and 503, so a 2xx
	 * carrying a field named `code` did not come from its rejection responder.
	 * Reading it as a refusal would either close an order the receiver already
	 * accepted or send it a second time.
	 *
	 * @return void
	 */
	public function test_a_success_carrying_a_rejection_shaped_body_is_still_accepted(): void {
		$outcome = $this->classify(
			HttpResponse::received( 202, array(), (string) json_encode( array( 'code' => 'queued' ) ) )
		);

		$this->assertSame( DeliveryOutcomeKind::ACCEPTED, $outcome->kind );
		$this->assertNull( $outcome->rejection_code );
	}

	/**
	 * When both sources declare a wait, the header is the one that is obeyed.
	 *
	 * @return void
	 */
	public function test_the_header_wait_wins_over_the_one_in_the_body(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				429,
				array( Constants::RETRY_AFTER_HEADER => '30' ),
				$this->rejection_body( 'burst_detected', 'Demasiadas entregas seguidas.', true, 300 )
			)
		);

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $outcome->kind );
		$this->assertSame( 30, $outcome->retry_after_seconds );
	}

	/**
	 * A rejection whose wait header did not survive the trip is still retried.
	 *
	 * The receiver writes the class into the body as well as into the header,
	 * and it says in its own source that it does so on purpose. The header is
	 * the half an intermediary can drop, rewrite or synthesise; the body is what
	 * the receiver wrote, end to end. So the body can add a retry that the
	 * header lost - never cancel one - because the two mistakes are not worth
	 * the same: a wasted attempt costs a request, and a rejection wrongly called
	 * permanent costs the order.
	 *
	 * @return void
	 */
	public function test_a_rejection_whose_wait_header_did_not_survive_the_trip_is_still_retried(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				503,
				array(),
				$this->rejection_body( 'ingestion_unavailable', 'No podemos aceptar la entrega ahora mismo.', true, 60 )
			)
		);

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $outcome->kind );
		$this->assertSame( 60, $outcome->retry_after_seconds );
	}

	/**
	 * An unparseable wait header does not end the reading, it moves it on.
	 *
	 * The receiver writes whole seconds and only whole seconds - measured - so
	 * an HTTP-date here came from something else. Treating it as "the header is
	 * present, therefore retryable" would leave the outcome without a number to
	 * wait for; treating it as "no header, therefore permanent" would lose the
	 * order. It is neither: the body is asked next.
	 *
	 * @return void
	 */
	public function test_an_unparseable_wait_header_falls_through_to_the_body(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				503,
				array( Constants::RETRY_AFTER_HEADER => 'Wed, 21 Oct 2026 07:28:00 GMT' ),
				$this->rejection_body( 'ingestion_unavailable', 'Vuelve a intentarlo.', true, 60 )
			)
		);

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $outcome->kind );
		$this->assertSame( 60, $outcome->retry_after_seconds );
	}

	/**
	 * A rejection the receiver calls retryable while declaring no number is
	 * retried on this plugin's own backoff, not closed.
	 *
	 * The receiver's invariant says there is a wait if and only if the code is
	 * retryable, so this combination cannot arrive today. The branch exists so
	 * that the permanent one can never absorb it if it ever does.
	 *
	 * @return void
	 */
	public function test_a_rejection_called_retryable_without_a_number_is_transient_not_permanent(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				503,
				array(),
				$this->rejection_body( 'ingestion_unavailable', 'Vuelve a intentarlo más tarde.', true, null )
			)
		);

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $outcome->kind );
		$this->assertTrue( $outcome->should_retry() );
	}

	/**
	 * A body that cannot be read is not a rejection of anything.
	 *
	 * @return void
	 */
	public function test_an_unreadable_body_is_transient(): void {
		$outcome = $this->classify( HttpResponse::received( 503, array(), 'Service Unavailable' ) );

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $outcome->kind );
		$this->assertTrue( $outcome->should_retry() );
	}

	/**
	 * JSON is not enough: an appliance answering `{"code": 403}` is describing
	 * itself, and every code this contract publishes is a string.
	 *
	 * @return void
	 */
	public function test_a_body_whose_code_is_not_a_string_is_not_a_rejection_of_this_contract(): void {
		$outcome = $this->classify(
			HttpResponse::received(
				403,
				array(),
				(string) json_encode( array( 'code' => 403, 'message' => 'Forbidden' ) )
			)
		);

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $outcome->kind );
		$this->assertNull( $outcome->rejection_code );
	}

	/**
	 * The receiver's message reaches the merchant word for word.
	 *
	 * It is the only text that knows what this particular rejection needs, and
	 * by the time the merchant reads it the response is long gone. Replacing it
	 * with a generic sentence of our own would throw away the next step.
	 *
	 * @return void
	 */
	public function test_the_receivers_message_is_relayed_verbatim(): void {
		$message = 'Este pedido supera el importe máximo que la ley admite en una factura simplificada.';

		$outcome = $this->classify(
			$this->as_the_receiver_writes_it( 422, 'simplified_absolute_limit_exceeded', $message, null )
		);

		$this->assertSame( $message, $outcome->merchant_message );
	}

	/**
	 * A rejection that arrives without a message still leaves the merchant
	 * something to act on, naming the code and the status.
	 *
	 * @return void
	 */
	public function test_a_rejection_without_a_message_still_names_the_code(): void {
		$outcome = $this->classify(
			HttpResponse::received( 422, array(), (string) json_encode( array( 'code' => 'store_limit_reached' ) ) )
		);

		$this->assertSame( DeliveryOutcomeKind::PERMANENT_REJECTION, $outcome->kind );
		$this->assertStringContainsString( 'store_limit_reached', $outcome->merchant_message );
		$this->assertStringContainsString( '422', $outcome->merchant_message );
	}

	/**
	 * The sweep: not one published rejection code is written into the
	 * classifier.
	 *
	 * This is the mechanical half of "no list of known codes lives in the
	 * plugin". A `match` over today's codes would pass every behaviour test in
	 * this file and still send every code born after the release into whatever
	 * branch happens to be last - which, in this class, is the one that gives up
	 * on a sale.
	 *
	 * Comments are stripped before looking, and deliberately: the file is
	 * allowed to NAME a code while explaining itself, and a reader who cannot
	 * see an example loses more than the sweep gains. What may not exist is a
	 * code the CODE reads.
	 *
	 * @return void
	 */
	public function test_no_published_rejection_code_is_written_into_the_classifier(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Transport/RejectionClassifier.php' );

		$this->assertIsString( $source, 'The classifier source has to be readable for this sweep to mean anything.' );

		$code_only = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code_only .= is_array( $token ) ? $token[1] : $token;
		}

		foreach ( self::PUBLISHED_CODES as $published_code ) {
			$this->assertStringNotContainsString(
				$published_code,
				$code_only,
				sprintf(
					'The classifier names the rejection code "%s". It must classify by what the response declares about waiting, never by the code: a plugin published today has to classify a code invented tomorrow.',
					$published_code
				)
			);
		}
	}

	/**
	 * A rejection built exactly the way the receiver's responder builds one:
	 * four body fields, and the wait header if and only if there is a wait.
	 *
	 * @param int         $status_code Status code the catalogue declares for the code.
	 * @param string      $code        Rejection code.
	 * @param string      $message     Merchant-facing message, as the receiver writes it.
	 * @param int|null    $retry_after Declared wait in seconds, or null when the rejection is permanent.
	 * @return HttpResponse
	 */
	private function as_the_receiver_writes_it( int $status_code, string $code, string $message, ?int $retry_after ): HttpResponse {
		$headers = array();

		if ( null !== $retry_after ) {
			$headers[ Constants::RETRY_AFTER_HEADER ] = (string) $retry_after;
		}

		return HttpResponse::received(
			$status_code,
			$headers,
			$this->rejection_body( $code, $message, null !== $retry_after, $retry_after )
		);
	}

	/**
	 * The JSON body of a rejection, with the four fields the responder writes.
	 *
	 * @param string   $code        Rejection code.
	 * @param string   $message     Merchant-facing message.
	 * @param bool     $retryable   Class of the rejection as the receiver declares it.
	 * @param int|null $retry_after Declared wait in seconds, or null.
	 * @return string
	 */
	private function rejection_body( string $code, string $message, bool $retryable, ?int $retry_after ): string {
		return (string) json_encode(
			array(
				Constants::REJECTION_BODY_CODE_FIELD        => $code,
				Constants::REJECTION_BODY_MESSAGE_FIELD     => $message,
				Constants::REJECTION_BODY_RETRYABLE_FIELD   => $retryable,
				Constants::REJECTION_BODY_RETRY_AFTER_FIELD => $retry_after,
			)
		);
	}

	/**
	 * A fresh classifier per call: it holds no state and nothing here should
	 * depend on it holding any.
	 *
	 * @param HttpResponse $response The response to classify.
	 * @return \Factuarea\WooCommerce\Contract\DeliveryOutcome
	 */
	private function classify( HttpResponse $response ): \Factuarea\WooCommerce\Contract\DeliveryOutcome {
		return ( new RejectionClassifier() )->classify( $response );
	}
}
