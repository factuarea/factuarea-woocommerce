<?php
/**
 * The seven native delivery headers, and where each of them comes from.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Transport\DeliveryHeaders;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What this file measures.
 *
 * The header set is the half of "indistinguishable from a native delivery" that
 * a reviewer cannot check by reading: six of the seven headers are accepted by
 * the receiver today whether they are there or not, so a set that quietly lost
 * one would look perfectly healthy until something downstream started reading
 * it. The count and the names are therefore asserted against
 * `Constants::DELIVERY_HEADERS`, which was measured against the receiver.
 *
 * The source sweep at the end is not decoration either. If a header name were
 * spelled out here as a literal as well as in `Constants`, the two copies would
 * be one careless fix away from disagreeing, and a signature travelling in a
 * header nobody reads is a 401 that looks exactly like a wrong secret.
 *
 * Unit suite: no WordPress, no database, no doubles. Composing headers is
 * plain PHP.
 */
final class DeliveryHeadersTest extends TestCase {

	/**
	 * The address of the store this plugin is installed on. It is what the
	 * source header must carry, because the receiver resolves the store by it.
	 */
	private const CONFIGURED_STORE_URL = 'https://shop.example.test';

	/**
	 * The address of the RECEIVER. It appears here only so that the source can
	 * be asserted NOT to be it: sending the destination as the origin is the
	 * confusion this pair of constants exists to make impossible to miss, and
	 * the receiver answers an unknown origin with a 200, so the mistake would
	 * look like a success that never produced an invoice.
	 */
	private const RECEIVER_BASE_URL = 'https://app.factuarea.test';

	/**
	 * All seven arrive, under the measured names, in the native order.
	 *
	 * The order is asserted as well as the set. It costs nothing here and it is
	 * the cheapest way to keep the delivery byte-comparable with a native one
	 * when somebody diffs the two while debugging.
	 *
	 * @return void
	 */
	public function test_it_sends_the_seven_native_delivery_headers_in_native_order(): void {
		$headers = $this->headers();

		$this->assertSame(
			Constants::DELIVERY_HEADERS,
			array_keys( $headers ),
			'The delivery no longer carries exactly the seven native headers, in the order a native '
				. 'WooCommerce delivery sends them. Six of them are accepted today whether they are present '
				. 'or not, so a missing one does not fail: it stops being indistinguishable from a native '
				. 'delivery, silently, until something downstream reads the one that went.',
		);

		$this->assertCount(
			7,
			$headers,
			'The header set is not seven entries long.',
		);
	}

	/**
	 * The source is the configured store, and not the receiver.
	 *
	 * @return void
	 */
	public function test_the_source_is_the_address_of_the_configured_store(): void {
		$headers = $this->headers();

		$this->assertSame(
			self::CONFIGURED_STORE_URL,
			$headers[ Constants::HEADER_SOURCE ],
			'The source header does not carry the address of the store the delivery comes from. It is what '
				. 'the receiver resolves the store by, so a wrong value there is not an error: the ingestion '
				. 'route answers 200 to an origin it does not know - deliberately, so an unknown sender does '
				. 'not retry forever - and the sale is never invoiced while the outbox records a success.',
		);

		$this->assertNotSame(
			self::RECEIVER_BASE_URL,
			$headers[ Constants::HEADER_SOURCE ],
			'The source header carries the address of the RECEIVER instead of the store. Origin and '
				. 'destination are two different settings and only one of them belongs in this header.',
		);
	}

	/**
	 * The signature travels in the header the receiver reads, and carries what
	 * the signer produced.
	 *
	 * @return void
	 */
	public function test_the_signature_travels_in_the_header_the_receiver_reads(): void {
		$raw_body  = '{"id":727,"status":"processing"}';
		$secret    = 'whsec_delivery_headers_case';
		$signature = ( new DeliverySigner() )->sign( $raw_body, $secret );

		$headers = ( new DeliveryHeaders() )->for_order(
			'order.updated',
			'order',
			'updated',
			self::CONFIGURED_STORE_URL,
			$signature,
			14,
			3081,
		);

		$this->assertArrayHasKey(
			'X-WC-Webhook-Signature',
			$headers,
			'The signature is not under the header name the receiver reads. The verifier of the connector '
				. 'looks up exactly this name and accepts no other, so a delivery whose signature travels '
				. 'elsewhere is rejected as unsigned.',
		);

		$this->assertSame(
			$signature,
			$headers['X-WC-Webhook-Signature'],
			'The signature header does not carry what the signer produced for this body and secret.',
		);
	}

	/**
	 * The topic and its two halves arrive as they were given, and the two
	 * identifiers arrive as strings.
	 *
	 * Header values are strings on the wire. Handing an integer to the HTTP
	 * layer works in some code paths and produces a type error in others, and
	 * the ones it produces it in are the ones inside a scheduled drain, where
	 * nobody is watching.
	 *
	 * @return void
	 */
	public function test_the_topic_its_halves_and_the_two_identifiers_arrive_as_strings(): void {
		$headers = $this->headers();

		$this->assertSame( 'order.updated', $headers[ Constants::HEADER_TOPIC ], 'The topic was not passed through.' );
		$this->assertSame( 'order', $headers[ Constants::HEADER_RESOURCE ], 'The resource was not passed through.' );
		$this->assertSame( 'updated', $headers[ Constants::HEADER_EVENT ], 'The event was not passed through.' );

		$this->assertSame(
			'14',
			$headers[ Constants::HEADER_WEBHOOK_ID ],
			'The webhook identifier is not a string. Header values are strings on the wire.',
		);

		$this->assertSame(
			'3081',
			$headers[ Constants::HEADER_DELIVERY_ID ],
			'The delivery identifier is not a string. Header values are strings on the wire.',
		);

		foreach ( $headers as $name => $value ) {
			$this->assertIsString(
				$value,
				sprintf( 'The value of %s is not a string.', $name ),
			);
		}
	}

	/**
	 * The topic is the two halves joined by a dot, which is what the receiver
	 * splits it on.
	 *
	 * This class composes rather than validates - it is handed all three values
	 * - so what is asserted here is that the caller of the test states the
	 * invariant and that nothing in between rewrites it. It is the shape the
	 * receiver's normaliser expects, and it is written down so the next caller
	 * does not have to rediscover it from a failing delivery.
	 *
	 * @return void
	 */
	public function test_the_topic_is_the_resource_and_the_event_joined_by_a_dot(): void {
		$headers = $this->headers();

		$this->assertSame(
			$headers[ Constants::HEADER_RESOURCE ] . '.' . $headers[ Constants::HEADER_EVENT ],
			$headers[ Constants::HEADER_TOPIC ],
			'The topic is not "resource.event". A delivery whose three topic headers disagree is well-formed '
				. 'and means something different from what the sender intended.',
		);
	}

	/**
	 * The names are never spelled out in this file: they come from the one place
	 * that measured them.
	 *
	 * The source is read with its comments removed, because the docblock of the
	 * class names the two tracing headers in order to explain what must not be
	 * done with them, and a naive search would read that explanation as the
	 * duplication it is warning about.
	 *
	 * @return void
	 */
	public function test_the_header_names_are_never_spelled_out_in_the_composer(): void {
		$code = $this->composerCodeWithoutComments();

		$this->assertStringNotContainsString(
			'X-WC-',
			$code,
			'A delivery header name is written as a literal in DeliveryHeaders instead of being taken from '
				. 'Constants. Those names were measured against the receiver and live in one place for that '
				. 'reason: a second copy is one careless fix away from disagreeing with the first, and a '
				. 'signature sent under a name nobody reads comes back as a 401 that looks like a bad secret.',
		);

		$this->assertStringContainsString(
			'Constants::HEADER_',
			$code,
			'DeliveryHeaders no longer takes its header names from Constants.',
		);
	}

	/**
	 * A delivery header set built from one set of values, used by the cases that
	 * do not need to vary them.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return ( new DeliveryHeaders() )->for_order(
			'order.updated',
			'order',
			'updated',
			self::CONFIGURED_STORE_URL,
			'W9zBRr9gdU5qBCQmCJV1x1oAPwidJzmDnexYuWTsOEM=',
			14,
			3081,
		);
	}

	/**
	 * The source of the composer with its comments replaced by their own
	 * newlines, so the sweep reads what the file DOES and reported line numbers
	 * still point at the real lines.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the file of the composer cannot be located or read.
	 */
	private function composerCodeWithoutComments(): string {
		$path = ( new ReflectionClass( DeliveryHeaders::class ) )->getFileName();

		if ( false === $path || ! is_file( $path ) ) {
			throw new RuntimeException( 'The file of DeliveryHeaders could not be located on disk.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file on disk, not a remote URL; WordPress is not loaded in this suite.
		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new RuntimeException( 'The file of DeliveryHeaders could not be read.' );
		}

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}
}
