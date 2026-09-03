<?php
/**
 * The delivery signature, measured against vectors produced outside this class.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Exception\CredentialsNotConfiguredException;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What this file measures, and why every case is where it is.
 *
 * The signer is four lines of PHP, and that is exactly why it needs vectors
 * rather than assertions written from the same understanding that wrote it. All
 * four ways of getting it wrong produce a well-formed base64 string:
 *
 *   - hexadecimal instead of the binary digest (the missing fourth argument);
 *   - the wrong hash function;
 *   - the secret and the body swapped;
 *   - the body normalised before hashing.
 *
 * None of them throws, and every one of them turns every sale of every merchant
 * into a 401 that reads like a wrong secret. So the expected values come from
 * `tests/fixtures/signature-vectors.json`, whose first two entries are the same
 * golden vectors the receiver's own test asserts, in the monorepo, at
 * `backend/tests/Unit/Integration/Infrastructure/Provider/WooCommerce/WooCommerceWebhookVerifierTest.php`.
 * Anchoring both ends to one shared number is what makes this a round-trip check
 * instead of two implementations agreeing with each other.
 *
 * The last case sweeps the source rather than the behaviour, and both are
 * needed: the behavioural case proves that today's code path signs the bytes it
 * was given, and the sweep catches a normalisation added tomorrow on a branch
 * the behavioural case does not walk.
 *
 * Unit suite: no WordPress, no database, no doubles. The signer is plain PHP and
 * the vectors are read from disk.
 */
final class DeliverySignerTest extends TestCase {

	/**
	 * Every vector the fixture is expected to carry, by name.
	 *
	 * Declared here so that deleting a vector is a red test rather than a
	 * quietly smaller one: a suite that silently stops checking the
	 * re-serialisation case still passes, and passing is what it would be used
	 * as evidence of.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_VECTORS = array(
		'rfc-4231-test-case-2',
		'store-order-body',
		'store-order-body-signature-altered-by-one-character',
		'reserialization-sensitive-body',
	);

	/**
	 * The fixture is present, readable and complete.
	 *
	 * It runs first because every other case in this file reads from it, and a
	 * fixture that moved would otherwise show up as four confusing failures
	 * about signatures instead of one clear one about a missing file.
	 *
	 * @return void
	 */
	public function test_the_fixture_carries_the_four_vectors_these_cases_rely_on(): void {
		$names = array_map(
			static fn( array $vector ): string => $vector['name'],
			$this->vectors(),
		);

		$this->assertSame(
			self::EXPECTED_VECTORS,
			$names,
			'The signature vectors of tests/fixtures/signature-vectors.json are not the four this file '
				. 'measures against. A vector that disappears takes its case with it and the suite still '
				. 'passes, so the set is asserted rather than assumed.',
		);
	}

	/**
	 * VECTOR PUBLISHED OUTSIDE THIS PROJECT.
	 *
	 * RFC 4231 section 4.3, Test Case 2, whose digest the IETF publishes in
	 * hexadecimal; the scheme of WooCommerce transports those same bytes in
	 * base64. It is the only expected value here that nobody on this project
	 * chose, and it is what makes the algorithm - rather than this repository's
	 * opinion of the algorithm - the thing being checked.
	 *
	 * @return void
	 */
	public function test_it_produces_the_signature_published_by_rfc_4231(): void {
		$vector = $this->vector( 'rfc-4231-test-case-2' );

		$this->assertSame(
			$vector['signature'],
			( new DeliverySigner() )->sign( $vector['body'], $vector['secret'] ),
			'The signer did not reproduce the published HMAC-SHA256 vector of RFC 4231 encoded in base64. '
				. 'Either the algorithm is not HMAC-SHA256, or the encoding is the base64 of the HEXADECIMAL '
				. 'digest instead of the binary one: hash_hmac without its fourth argument returns hexadecimal, '
				. 'and the base64 of that string is a perfectly well-formed value that no receiver accepts.',
		);
	}

	/**
	 * VECTOR SHARED WITH THE RECEIVER, over a body shaped like a store webhook.
	 *
	 * This is the one that pins the round trip: the same triple is asserted from
	 * the other side by the verifier's test in the monorepo, so a change on
	 * either end that broke the agreement turns one of the two red.
	 *
	 * @return void
	 */
	public function test_it_produces_the_signature_the_receiver_verifies_for_a_store_body(): void {
		$vector = $this->vector( 'store-order-body' );

		$this->assertSame(
			$vector['signature'],
			( new DeliverySigner() )->sign( $vector['body'], $vector['secret'] ),
			'The signer did not produce the signature the receiver verifies for this exact body and secret. '
				. 'If the RFC vector passes and this one fails, the algorithm is right and something is '
				. 'touching the body before it is hashed.',
		);
	}

	/**
	 * THE NEGATIVE HALF, asserted next to its positive half.
	 *
	 * On its own, "the signer produced X" is satisfied by a signer that returns
	 * a constant. Asserting in the same case that it does NOT produce the
	 * one-character alteration is what makes the first assertion say something
	 * about the input.
	 *
	 * @return void
	 */
	public function test_it_does_not_produce_a_signature_altered_by_one_character(): void {
		$vector   = $this->vector( 'store-order-body-signature-altered-by-one-character' );
		$produced = ( new DeliverySigner() )->sign( $vector['body'], $vector['secret'] );

		$this->assertSame(
			$vector['correct_signature'],
			$produced,
			'The body and secret of the altered vector have to sign to the correct signature; if they do not, '
				. 'the rejection below proves nothing.',
		);

		$this->assertNotSame(
			$vector['signature'],
			$produced,
			'The signer produced the signature that was altered by a single character. A digest changes '
				. 'completely with any input bit, so this can only happen if the signer is not deriving its '
				. 'output from the body and the secret at all.',
		);
	}

	/**
	 * THE CASE THIS FILE EXISTS FOR: the material signed is the string that will
	 * be sent, and never a re-serialisation of it.
	 *
	 * The vector's raw body and its re-encoded twin are the same JSON document
	 * and different byte sequences - the spacing collapses and the slash comes
	 * back escaped. Three things are asserted, and the third is the one with
	 * teeth:
	 *
	 *   1. re-encoding really does change the bytes, or the case distinguishes
	 *      nothing and would pass for the wrong reason;
	 *   2. the signer over the raw bytes gives the recorded signature;
	 *   3. the signer over the re-encoded bytes gives a DIFFERENT recorded
	 *      signature.
	 *
	 * A signer that decoded the body and re-encoded it before hashing - which is
	 * what "signing the object instead of the body" means in practice - would
	 * return the second value in step 2 and go red here. In production it would
	 * return no error at all: the delivery would leave carrying a signature over
	 * bytes that were not sent, and every attempt of every sale would come back
	 * 401.
	 *
	 * @return void
	 */
	public function test_it_signs_the_bytes_it_was_given_and_never_a_re_serialisation(): void {
		$vector = $this->vector( 'reserialization-sensitive-body' );
		$signer = new DeliverySigner();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit suite: WordPress is not loaded, so wp_json_encode does not exist. The plain function is also the point of the case: it is what a signer that normalised its input would be doing.
		$reserialized = json_encode(
			json_decode( $vector['raw_body'], true, 512, JSON_THROW_ON_ERROR ),
			JSON_THROW_ON_ERROR,
		);

		$this->assertSame(
			$vector['reserialized_body'],
			$reserialized,
			'Re-encoding the raw body of the vector no longer produces the byte sequence recorded in the '
				. 'fixture. Regenerate the vector together with its two signatures; do not edit one of them '
				. 'on its own.',
		);

		$this->assertNotSame(
			$vector['raw_body'],
			$reserialized,
			'The raw body and its re-encoded form are the same bytes, so this case cannot tell the two apart. '
				. 'The vector needs a body whose spacing, key order or escaping changes when it is re-encoded.',
		);

		$this->assertSame(
			$vector['signature'],
			$signer->sign( $vector['raw_body'], $vector['secret'] ),
			'The signer did not sign the raw bytes it was handed. If it returned the signature of the '
				. 'RE-ENCODED body instead, it is normalising the payload before hashing, and every delivery '
				. 'it produces will be rejected by the receiver with no local error of any kind.',
		);

		$this->assertSame(
			$vector['signature_of_reserialized_body'],
			$signer->sign( $reserialized, $vector['secret'] ),
			'Signing the re-encoded bytes did not give the recorded signature for them, so the two halves of '
				. 'this case are no longer comparable.',
		);

		$this->assertNotSame(
			$signer->sign( $vector['raw_body'], $vector['secret'] ),
			$signer->sign( $reserialized, $vector['secret'] ),
			'The signer gave the same signature for two different byte sequences. That is only possible if it '
				. 'is normalising its input, which is the defect this whole vector exists to catch.',
		);
	}

	/**
	 * And the file does not even carry the tools that would allow it.
	 *
	 * The case above measures behaviour on one path; this one measures that the
	 * path cannot be added. The source is read with its comments removed,
	 * because the signer's own docblock spells out `json_encode( json_decode(
	 * $body ) )` in order to forbid it, and a naive search would read the warning
	 * as the offence it warns about.
	 *
	 * @return void
	 */
	public function test_the_signer_never_decodes_or_re_encodes_the_body(): void {
		$code      = $this->signerCodeWithoutComments();
		$offenders = array();

		foreach ( array( 'json_decode', 'json_encode', 'json_validate', 'serialize', 'unserialize' ) as $forbidden ) {
			if ( str_contains( $code, $forbidden . '(' ) ) {
				$offenders[] = $forbidden;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			sprintf(
				'The signer calls %s. The body is signed byte for byte: decoding it and encoding it again '
					. 'changes the key order, the spacing and the escaping, and with them the digest - with no '
					. 'error anywhere, only a 401 nobody can explain.',
				implode( ', ', $offenders ),
			),
		);
	}

	/**
	 * An empty secret is refused by name, and the message shows no credential.
	 *
	 * Signing with an empty key SUCCEEDS - hash_hmac returns a perfectly
	 * computable digest - so without this guard the delivery would go out, come
	 * back 401, be classified as transient (a 401 is not a rejection of the
	 * ingestion contract) and spend the whole retry budget on a misconfiguration
	 * whose cure is one field on the settings screen.
	 *
	 * The second half of the case is the prohibition of the exception catalogue:
	 * the message names the SETTING and never its value.
	 *
	 * @return void
	 */
	public function test_it_refuses_an_empty_signing_secret_and_names_the_setting_not_its_value(): void {
		$body = '{"id":727}';

		try {
			( new DeliverySigner() )->sign( $body, '' );
		} catch ( CredentialsNotConfiguredException $refusal ) {
			$this->assertStringContainsString(
				'the signing secret',
				$refusal->getMessage(),
				'The refusal has to name which setting is empty. A merchant who is only told that delivery '
					. 'failed opens a support ticket, and the ticket arrives after the sale it broke.',
			);

			$this->assertStringNotContainsString(
				$body,
				$refusal->getMessage(),
				'The refusal message carries the body it was asked to sign. Exception messages end up in '
					. 'debug logs and support tickets, and an order body is personal data.',
			);

			return;
		}

		$this->fail(
			'The signer accepted an empty signing secret and returned a signature for it. That signature is '
				. 'well-formed and unverifiable, so the delivery leaves, comes back 401 and burns every retry '
				. 'on a setting nobody was told to fill in.',
		);
	}

	/**
	 * One named vector, or a failure that says which one is missing.
	 *
	 * @param string $name Name of the vector.
	 *
	 * @return array<string, string> The vector.
	 */
	private function vector( string $name ): array {
		foreach ( $this->vectors() as $vector ) {
			if ( $name === $vector['name'] ) {
				return $vector;
			}
		}

		$this->fail(
			sprintf(
				'The vector "%s" is not in tests/fixtures/signature-vectors.json. It was not removed by this '
					. 'test, so either it was deleted or it was renamed; either way the case that used it is no '
					. 'longer measuring anything.',
				$name,
			),
		);
	}

	/**
	 * Every vector of the fixture, in file order.
	 *
	 * A missing or unreadable fixture throws rather than returning an empty
	 * list: an empty list would make the loops above iterate zero times and the
	 * suite would pass without having compared a single signature.
	 *
	 * @return list<array<string, string>>
	 *
	 * @throws RuntimeException When the fixture is missing, unreadable or not shaped as expected.
	 */
	private function vectors(): array {
		$path = dirname( __DIR__ ) . '/fixtures/signature-vectors.json';

		if ( ! is_file( $path ) ) {
			throw new RuntimeException(
				'tests/fixtures/signature-vectors.json is missing. The expected signatures live there and '
					. 'nowhere else, so without it these cases cannot check anything.',
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture on disk, not a remote URL; wp_remote_get would be the wrong tool and WordPress is not loaded in this suite.
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( 'tests/fixtures/signature-vectors.json could not be read.' );
		}

		$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $decoded ) || ! isset( $decoded['vectors'] ) || ! is_array( $decoded['vectors'] ) ) {
			throw new RuntimeException(
				'tests/fixtures/signature-vectors.json has no "vectors" list. Its shape is part of what these '
					. 'cases read; a fixture that decodes to something else is a broken fixture, not an empty one.',
			);
		}

		return array_values( $decoded['vectors'] );
	}

	/**
	 * The source of the signer with its comments replaced by their own newlines,
	 * so that the sweep reads what the file DOES and the reported line numbers
	 * still point at the real lines.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the file of the signer cannot be located or read.
	 */
	private function signerCodeWithoutComments(): string {
		$path = ( new ReflectionClass( DeliverySigner::class ) )->getFileName();

		if ( false === $path || ! is_file( $path ) ) {
			throw new RuntimeException( 'The file of DeliverySigner could not be located on disk.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file on disk, not a remote URL; WordPress is not loaded in this suite.
		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new RuntimeException( 'The file of DeliverySigner could not be read.' );
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
