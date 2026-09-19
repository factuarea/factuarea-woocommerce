<?php
/**
 * The verification of an incoming signature, measured against the vector the
 * platform froze upstream.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Inbound\InboundSignatureVerifier;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What this file measures, and why every case is where it is.
 *
 * The verifier is six lines of PHP guarding every write the inbound half of this
 * plugin performs, so it is measured against values produced OUTSIDE it rather
 * than against assertions written from the same understanding that wrote it. The
 * expected signature comes from `tests/fixtures/inbound-signature-vectors.json`,
 * whose frozen vector is copied character for character from the contract the
 * platform publishes for this lane
 * (`backend/docs/architecture/store-order-status-receiver-contract.md`, section
 * 3.2) and asserted there against the real emitter. Anchoring both ends to one
 * shared number is what makes this a round-trip check instead of two
 * implementations of the same idea agreeing with each other.
 *
 * Every way of failing has its own case, because each of them is well-formed
 * and silent in production:
 *
 *   - a body edited after its signature was captured;
 *   - a signature edited on its way in;
 *   - no header at all;
 *   - a shop whose merchant has not pasted the secret yet, where the signer
 *     throws and an unguarded verifier turns a misconfiguration into a 500 that
 *     the sender retries eight times;
 *   - the OTHER lane's scheme, hexadecimal over `timestamp.'.'.body`, arriving
 *     under the very same header name.
 *
 * The last three cases sweep the source rather than the behaviour, and both
 * kinds are needed: a behavioural case proves what today's code path does, and a
 * sweep catches the branch added tomorrow that the behavioural case does not
 * walk. They read the source with its comments removed, because this file's own
 * documentation names the functions it forbids in order to forbid them, and a
 * naive search would read the warning as the offence.
 *
 * Unit suite: no WordPress, no database, no doubles. The verifier and the signer
 * are plain PHP and the vectors are read from disk.
 */
final class InboundSignatureVerifierTest extends TestCase {

	/**
	 * Every vector the fixture is expected to carry, by name and in file order.
	 *
	 * Declared here so that deleting a vector is a red test rather than a
	 * quietly smaller one: a suite that silently stops checking the wrong-scheme
	 * case still passes, and passing is what it would be used as evidence of.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_VECTORS = array(
		'frozen-order-status-body',
		'frozen-order-status-body-with-one-byte-altered',
		'frozen-order-status-signature-altered-by-one-character',
		'outbound-webhook-scheme-hexadecimal-over-timestamp-and-body',
	);

	/**
	 * Functions that would write somewhere, say something or hand a value to
	 * anyone other than the caller.
	 *
	 * The verifier answers a boolean and does nothing else. A log line holding
	 * the expected signature of a known body hands over the effect of the secret
	 * for that body, and an exception message ends up in a support ticket.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_CALLS = array(
		'error_log',
		'wc_get_logger',
		'trigger_error',
		'syslog',
		'openlog',
		'var_dump',
		'var_export',
		'print_r',
		'printf',
		'vprintf',
		'sprintf',
		'fwrite',
		'fputs',
		'file_put_contents',
		'wp_die',
		'_doing_it_wrong',
		'do_action',
		'apply_filters',
		'update_option',
		'add_option',
		'set_transient',
		'wp_mail',
		'header',
	);

	/**
	 * Hashing and encoding this file must NOT do for itself.
	 *
	 * The expected value is asked of `Transport\DeliverySigner`, which already
	 * computes it for outgoing deliveries. A second `hash_hmac` here would be a
	 * second place to forget its fourth argument, and that argument is the whole
	 * difference between base64 and hexadecimal.
	 *
	 * `hash_equals` is deliberately absent from this list and required by its
	 * own case below; the entries are matched with their opening parenthesis, so
	 * `hash(` does not match it.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_CRYPTO = array(
		'hash_hmac',
		'hash_hkdf',
		'hash',
		'md5',
		'sha1',
		'crc32',
		'base64_encode',
		'base64_decode',
		'openssl_digest',
	);

	/**
	 * The fixture is present, readable and complete.
	 *
	 * It runs first because every other case in this file reads from it, and a
	 * fixture that moved would otherwise show up as several confusing failures
	 * about signatures instead of one clear one about a missing file.
	 *
	 * @return void
	 */
	public function test_the_fixture_carries_the_four_vectors_these_cases_rely_on(): void {
		$names = array_map(
			static fn( array $vector ): string => (string) $vector['name'],
			$this->vectors(),
		);

		$this->assertSame(
			self::EXPECTED_VECTORS,
			$names,
			'The vectors of tests/fixtures/inbound-signature-vectors.json are not the four this file measures '
				. 'against. A vector that disappears takes its case with it and the suite still passes, so the '
				. 'set is asserted rather than assumed.',
		);
	}

	/**
	 * THE VECTOR FROZEN UPSTREAM: the delivery the platform would send verifies.
	 *
	 * The triple is copied from the contract the emitter is measured against, so
	 * a change on either end that broke the agreement turns one of the two red.
	 *
	 * @return void
	 */
	public function test_it_accepts_the_signature_frozen_by_the_upstream_contract(): void {
		$vector = $this->vector( 'frozen-order-status-body' );

		$this->assertTrue(
			$this->verifier()->verify( $vector['body'], $vector['presented'], $vector['secret'] ),
			'The verifier refused the frozen vector of the upstream contract. Every notice the platform emits '
				. 'for this lane would come back 401, the sender would classify it as a rejected credential - '
				. 'which is PERMANENT - and the merchant would read that their shop rejects our signature while '
				. 'the secret is correct.',
		);
	}

	/**
	 * And the expected value it computed IS the frozen string, not merely one
	 * that happens to agree with itself.
	 *
	 * Without this case a verifier that answered true unconditionally would pass
	 * the acceptance case above. This one says which string this plugin produces
	 * for that body and that secret, and it is the string the platform published.
	 *
	 * @return void
	 */
	public function test_the_expected_signature_of_the_frozen_vector_is_exactly_the_frozen_value(): void {
		$vector = $this->vector( 'frozen-order-status-body' );

		$this->assertSame(
			$vector['presented'],
			( new DeliverySigner() )->sign( $vector['body'], $vector['secret'] ),
			'The signature this plugin computes for the frozen body and secret is not the one the contract '
				. 'publishes. Either the scheme drifted - hexadecimal instead of the binary digest, the wrong '
				. 'hash function, the body normalised before hashing - or the vector was edited on its own. Do '
				. 'not edit the expected value: it moves upstream first, in the contract and its test.',
		);
	}

	/**
	 * A body edited after its signature was captured is refused.
	 *
	 * This is the shape of the attack that matters on this route: the signature
	 * of a legitimate notice, replayed over a body that says something else.
	 *
	 * @return void
	 */
	public function test_it_rejects_a_body_altered_by_one_byte(): void {
		$vector = $this->vector( 'frozen-order-status-body-with-one-byte-altered' );

		$this->assertNotSame(
			$this->vector( 'frozen-order-status-body' )['body'],
			$vector['body'],
			'The altered vector carries the same body as the frozen one, so this case distinguishes nothing. '
				. 'Its body has to differ from the frozen one by the single byte its own note describes.',
		);

		$this->assertFalse(
			$this->verifier()->verify( $vector['body'], $vector['presented'], $vector['secret'] ),
			'The verifier accepted a body whose single altered byte no longer matches the signature presented. '
				. 'A digest changes completely with any input bit, so this can only happen if the verdict is '
				. 'not derived from the body at all - which means a captured signature would authenticate any '
				. 'notice an attacker cares to write.',
		);
	}

	/**
	 * A signature edited on its way in is refused.
	 *
	 * The mirror of the case above, from the other operand.
	 *
	 * @return void
	 */
	public function test_it_rejects_a_signature_altered_by_one_character(): void {
		$vector = $this->vector( 'frozen-order-status-signature-altered-by-one-character' );

		$this->assertNotSame(
			$this->vector( 'frozen-order-status-body' )['presented'],
			$vector['presented'],
			'The altered vector presents the same signature as the frozen one, so this case distinguishes '
				. 'nothing. Its presented value has to differ by the single character its own note describes.',
		);

		$this->assertFalse(
			$this->verifier()->verify( $vector['body'], $vector['presented'], $vector['secret'] ),
			'The verifier accepted a signature that differs from the expected one by a single character. The '
				. 'comparison is not deciding on the value it was handed.',
		);
	}

	/**
	 * A delivery with no signature header is refused, and the header being
	 * absent reaches this method as an empty string.
	 *
	 * `WP_REST_Request::get_header()` answers null for a header that is not
	 * there, and the receiver casts that to the empty string before calling; the
	 * contract of this method is written on that value.
	 *
	 * @return void
	 */
	public function test_it_rejects_a_delivery_with_no_signature_header(): void {
		$vector = $this->vector( 'frozen-order-status-body' );

		$this->assertFalse(
			$this->verifier()->verify( $vector['body'], '', $vector['secret'] ),
			'The verifier accepted a delivery that presented no signature at all. The route answers with '
				. 'permission_callback always affirmative, so the signature is the only thing guarding it: '
				. 'accepting an empty one opens every inbound write to anyone who knows the address.',
		);
	}

	/**
	 * A shop whose secret is not configured yet is refused, and the refusal is
	 * an answer rather than an exception.
	 *
	 * This is the case the order of the checks exists for. `DeliverySigner`
	 * REFUSES an empty secret by throwing - deliberately, so that no outgoing
	 * delivery is ever signed with nothing - so a verifier that reached it with
	 * an empty secret would let a `CredentialsNotConfiguredException` escape a
	 * REST callback. WordPress renders that as a 500, the sender classifies 5xx
	 * as RETRYABLE, and the whole retry budget burns on a merchant who has not
	 * pasted a setting. Running with the REAL signer is what makes this case
	 * evidence: a double would swallow exactly the throw being guarded against.
	 *
	 * @return void
	 */
	public function test_it_rejects_a_shop_with_no_secret_configured_without_throwing(): void {
		$vector = $this->vector( 'frozen-order-status-body' );

		$this->assertFalse(
			$this->verifier()->verify( $vector['body'], $vector['presented'], '' ),
			'The verifier accepted a delivery on a shop with no signing secret configured. With no secret '
				. 'there is nothing to authenticate against, so accepting is accepting anybody.',
		);
	}

	/**
	 * THE SCHEME OF THE OTHER LANE, arriving under the very same header name, is
	 * refused.
	 *
	 * The platform signs its outbound webhooks - to an endpoint the customer
	 * configures - with `X-Factuarea-Signature` as well, over
	 * `timestamp.'.'.body` and in hexadecimal
	 * (`OutboundWebhookJob.php:166`). The header name says nothing about the
	 * scheme; the receiver does. A verifier that accepted both would authenticate
	 * bodies signed by a lane whose secrets the merchant hands to third parties.
	 *
	 * The presented value is derived here from the formula of that lane rather
	 * than recorded, on purpose: it is the string that must be refused, not an
	 * expected answer, and deriving it keeps the case tracking the lane instead
	 * of a number somebody typed once.
	 *
	 * @return void
	 */
	public function test_it_rejects_the_hexadecimal_scheme_over_the_timestamp_and_the_body(): void {
		$vector = $this->vector( 'outbound-webhook-scheme-hexadecimal-over-timestamp-and-body' );

		$this->assertSame(
			'2026-09-19T10:00:00+00:00',
			gmdate( 'c', (int) $vector['timestamp'] ),
			'The timestamp of the wrong-scheme vector is no longer the instant of the worked example its own '
				. 'note claims. The vector has to keep explaining itself, or the next reader cannot tell what '
				. 'the number is.',
		);

		$other_lane = hash_hmac( 'sha256', $vector['timestamp'] . '.' . $vector['body'], $vector['secret'] );

		$this->assertMatchesRegularExpression(
			'/\A[0-9a-f]{64}\z/',
			$other_lane,
			'The value derived for the other lane is not a hexadecimal SHA-256 digest, so this case is no '
				. 'longer presenting what that lane emits.',
		);

		$this->assertFalse(
			$this->verifier()->verify( $vector['body'], $other_lane, $vector['secret'] ),
			'The verifier accepted the hexadecimal signature of the outbound webhook lane over '
				. 'timestamp.body. This receiver accepts ONE scheme; unifying two because their header happens '
				. 'to be named the same would let anything signed by the other lane in through this door.',
		);
	}

	/**
	 * The three ways of failing are the same answer, and none of them says
	 * anything.
	 *
	 * Distinguishing an absent header from a wrong signature from an
	 * unconfigured shop is free information for whoever is probing the route and
	 * worth nothing to a legitimate sender, which either holds the secret or does
	 * not. The three verdicts are asserted as identical - same type, same value -
	 * and the run is watched for output, because a stray `echo` in this file
	 * would be the very leak the class block forbids.
	 *
	 * The buffer is closed in a `finally`. Without it, a verifier that THREW on
	 * one of the three - which is exactly what dropping the empty-secret guard
	 * does - would leave the buffer open and PHPUnit would report a risky test
	 * about output buffers instead of the failure that actually happened.
	 *
	 * @return void
	 */
	public function test_the_three_ways_of_failing_are_one_indistinguishable_answer(): void {
		$vector   = $this->vector( 'frozen-order-status-body' );
		$verifier = $this->verifier();
		$verdicts = array();

		ob_start();

		try {
			$verdicts = array(
				'absent header'       => $verifier->verify( $vector['body'], '', $vector['secret'] ),
				'wrong signature'     => $verifier->verify( $vector['body'], 'not-the-signature', $vector['secret'] ),
				'secret unconfigured' => $verifier->verify( $vector['body'], $vector['presented'], '' ),
			);
		} finally {
			$printed = (string) ob_get_clean();
		}

		$this->assertSame(
			array(
				'absent header'       => false,
				'wrong signature'     => false,
				'secret unconfigured' => false,
			),
			$verdicts,
			'The three ways of failing do not answer identically. Any difference between them - a different '
				. 'value, a different type, a null - is a difference a caller can forward into its response, '
				. 'and that response tells a prober which of the three it hit.',
		);

		$this->assertSame(
			'',
			$printed,
			'The verifier printed something while rejecting deliveries. Anything it prints on a rejection is '
				. 'a value derived from the secret, the expected signature or the presented one, written where '
				. 'the caller cannot stop it.',
		);
	}

	/**
	 * The optional signer is genuinely optional, and both ways of building the
	 * verifier decide the same.
	 *
	 * The constructor takes one so that a caller can hand over the instance it
	 * already has; the lazy construction is what keeps the frozen public shape -
	 * `verify()` and nothing else - true for a caller that gives none.
	 *
	 * @return void
	 */
	public function test_it_decides_the_same_with_an_injected_signer_and_with_none(): void {
		$vector = $this->vector( 'frozen-order-status-body' );

		$this->assertTrue(
			( new InboundSignatureVerifier( new DeliverySigner() ) )->verify(
				$vector['body'],
				$vector['presented'],
				$vector['secret'],
			),
			'The verifier built with an explicit signer refused the frozen vector, so the two ways of building '
				. 'it do not agree and callers cannot choose freely between them.',
		);
	}

	/**
	 * It delegates the computation and carries no hashing of its own.
	 *
	 * The behavioural cases prove that today's path produces the frozen value;
	 * this one proves the path cannot quietly become a second implementation.
	 * The file is read with its comments removed because its own documentation
	 * names `hash_hmac` in order to forbid a second one.
	 *
	 * @return void
	 */
	public function test_it_delegates_the_computation_and_never_hashes_on_its_own(): void {
		$code      = $this->verifierCodeWithoutComments();
		$offenders = array();

		foreach ( self::FORBIDDEN_CRYPTO as $forbidden ) {
			if ( str_contains( $code, $forbidden . '(' ) ) {
				$offenders[] = $forbidden;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			sprintf(
				'The verifier computes %s for itself instead of asking Transport\\DeliverySigner. That is a '
					. 'second place to get the fourth argument of hash_hmac wrong, and that argument is the '
					. 'difference between base64 and hexadecimal - a mistake that throws nothing, warns '
					. 'nothing and answers 401 to every legitimate notice.',
				implode( ', ', $offenders ),
			),
		);

		$this->assertStringContainsString(
			'DeliverySigner',
			$code,
			'The verifier no longer names Transport\\DeliverySigner at all, so whatever it is comparing '
				. 'against is not the value this plugin already knows how to compute.',
		);
	}

	/**
	 * The comparison is constant time, and the presented value is never compared
	 * with an equality operator except against the empty string.
	 *
	 * `===` on strings returns at the first differing byte, so how long it takes
	 * depends on how many leading bytes were right; measured over enough probes
	 * that difference reconstructs the expected signature byte by byte without
	 * ever learning the secret. Comparing the presented value against `''` is
	 * exempt and is the one comparison the file makes: it asks whether the header
	 * was there, which leaks nothing about its content.
	 *
	 * @return void
	 */
	public function test_it_compares_the_signature_in_constant_time(): void {
		$this->assertStringContainsString(
			'hash_equals(',
			$this->verifierCodeWithoutComments(),
			'The verifier no longer calls hash_equals. Every other way of comparing two strings in PHP '
				. 'returns early on the first differing byte, and that timing is a side channel that '
				. 'reconstructs the expected signature one byte at a time.',
		);

		$this->assertSame(
			array(),
			$this->equalityComparisonsAgainstThePresentedSignature(),
			'The verifier compares the presented signature with an equality operator against something other '
				. 'than the empty string. That comparison is not constant time, and it is the whole reason '
				. 'hash_equals is in the file.',
		);
	}

	/**
	 * It writes nothing anywhere and carries no message at all.
	 *
	 * Two sweeps in one case, because they are halves of the same promise. The
	 * first says the file calls nothing that logs, prints, mails, stores or
	 * broadcasts, and raises nothing: an exception message ends up in a debug log
	 * and from there in a support ticket. The second says the file holds no
	 * non-empty string literal, which is the mechanical form of "no message of
	 * its own contains the secret, the expected signature or the presented one" -
	 * there is no message.
	 *
	 * @return void
	 */
	public function test_it_writes_nothing_to_any_log_and_carries_no_message_of_its_own(): void {
		$code      = $this->verifierCodeWithoutComments();
		$offenders = array();

		foreach ( self::FORBIDDEN_CALLS as $forbidden ) {
			if ( str_contains( $code, $forbidden . '(' ) ) {
				$offenders[] = $forbidden;
			}
		}

		foreach ( $this->significantTokens() as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_ECHO, T_PRINT, T_THROW, T_EXIT, T_INLINE_HTML ), true ) ) {
				$offenders[] = trim( $token[1] );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			sprintf(
				'The verifier reaches for %s. A rejected delivery must leave no trace that carries the secret, '
					. 'the expected signature or the presented one: a log line holding the expected signature '
					. 'of a known body hands over the effect of the secret for that body, and an exception '
					. 'message travels into support tickets. There is also nothing to record - the response '
					. 'the receiver returns already tells the sender everything the contract promises.',
				implode( ', ', $offenders ),
			),
		);

		$literals = array();

		foreach ( $this->significantTokens() as $token ) {
			if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				continue;
			}

			if ( "''" === $token[1] || '""' === $token[1] ) {
				continue;
			}

			$literals[] = $token[1];
		}

		$this->assertSame(
			array(),
			$literals,
			sprintf(
				'The verifier carries the string literal(s) %s. The only strings it needs are the empty ones '
					. 'it compares against, so any other is text meant to be read somewhere - and the only '
					. 'values in scope here are the secret, the expected signature and the presented one.',
				implode( ', ', $literals ),
			),
		);
	}

	/**
	 * And it never decodes, normalises or re-encodes the body it verifies.
	 *
	 * `json_encode( json_decode( $body ) )` gives back an equivalent document
	 * with different bytes - key order, spacing, the escaping of `/` and of
	 * non-ASCII characters, the notation of numbers - so hashing those would
	 * reject every legitimate sender with an inexplicable 401 and no local error
	 * of any kind.
	 *
	 * @return void
	 */
	public function test_it_never_decodes_or_re_encodes_the_body_it_verifies(): void {
		$code      = $this->verifierCodeWithoutComments();
		$offenders = array();

		foreach ( array( 'json_decode', 'json_encode', 'json_validate', 'serialize', 'unserialize', 'trim', 'stripslashes', 'wp_unslash' ) as $forbidden ) {
			if ( str_contains( $code, $forbidden . '(' ) ) {
				$offenders[] = $forbidden;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			sprintf(
				'The verifier calls %s on its way to the digest. The body is verified byte for byte as it '
					. 'arrived: decoding it, trimming it or encoding it again changes the bytes and with them '
					. 'the digest, with no error anywhere - only a 401 nobody can explain.',
				implode( ', ', $offenders ),
			),
		);
	}

	/**
	 * A verifier with the real signer, which is how the receivers build it.
	 *
	 * @return InboundSignatureVerifier
	 */
	private function verifier(): InboundSignatureVerifier {
		return new InboundSignatureVerifier();
	}

	/**
	 * Equality comparisons the source makes against the presented signature,
	 * excluding the one against the empty string.
	 *
	 * Written over tokens rather than a regular expression so that spacing,
	 * line breaks and the order of the operands cannot hide one.
	 *
	 * @return list<string> Textual form of each offending comparison, empty when there is none.
	 */
	private function equalityComparisonsAgainstThePresentedSignature(): array {
		$tokens    = array_values( $this->significantTokens() );
		$operators = array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL );
		$offenders = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], $operators, true ) ) {
				continue;
			}

			$left  = $tokens[ $index - 1 ] ?? '';
			$right = $tokens[ $index + 1 ] ?? '';

			$left_text  = is_array( $left ) ? $left[1] : (string) $left;
			$right_text = is_array( $right ) ? $right[1] : (string) $right;

			if ( '$presented' !== $left_text && '$presented' !== $right_text ) {
				continue;
			}

			$other = '$presented' === $left_text ? $right_text : $left_text;

			if ( "''" === $other || '""' === $other ) {
				continue;
			}

			$offenders[] = $left_text . ' ' . $token[1] . ' ' . $right_text;
		}

		return $offenders;
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
				'The vector "%s" is not in tests/fixtures/inbound-signature-vectors.json. It was not removed '
					. 'by this test, so either it was deleted or it was renamed; either way the case that used '
					. 'it is no longer measuring anything.',
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
		$path = dirname( __DIR__ ) . '/fixtures/inbound-signature-vectors.json';

		if ( ! is_file( $path ) ) {
			throw new RuntimeException(
				'tests/fixtures/inbound-signature-vectors.json is missing. The frozen signature of the '
					. 'upstream contract lives there and nowhere else, so without it these cases cannot check '
					. 'anything.',
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture on disk, not a remote URL; wp_remote_get would be the wrong tool and WordPress is not loaded in this suite.
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( 'tests/fixtures/inbound-signature-vectors.json could not be read.' );
		}

		$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $decoded ) || ! isset( $decoded['vectors'] ) || ! is_array( $decoded['vectors'] ) ) {
			throw new RuntimeException(
				'tests/fixtures/inbound-signature-vectors.json has no "vectors" list. Its shape is part of '
					. 'what these cases read; a fixture that decodes to something else is a broken fixture, '
					. 'not an empty one.',
			);
		}

		return array_values( $decoded['vectors'] );
	}

	/**
	 * The source of the verifier with its comments replaced by their own
	 * newlines, so that the sweeps read what the file DOES and the reported line
	 * numbers still point at the real lines.
	 *
	 * @return string
	 */
	private function verifierCodeWithoutComments(): string {
		$code = '';

		foreach ( $this->verifierTokens() as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * The tokens of the verifier without comments and without whitespace, so
	 * that a sweep can look at what sits on either side of an operator.
	 *
	 * @return list<array{0:int,1:string,2:int}|string>
	 */
	private function significantTokens(): array {
		$significant = array();

		foreach ( $this->verifierTokens() as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				continue;
			}

			$significant[] = $token;
		}

		return $significant;
	}

	/**
	 * Every token of the verifier's own file.
	 *
	 * @return list<array{0:int,1:string,2:int}|string>
	 *
	 * @throws RuntimeException When the file of the verifier cannot be located or read.
	 */
	private function verifierTokens(): array {
		$path = ( new ReflectionClass( InboundSignatureVerifier::class ) )->getFileName();

		if ( false === $path || ! is_file( $path ) ) {
			throw new RuntimeException( 'The file of InboundSignatureVerifier could not be located on disk.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file on disk, not a remote URL; WordPress is not loaded in this suite.
		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new RuntimeException( 'The file of InboundSignatureVerifier could not be read.' );
		}

		return token_get_all( $source );
	}
}
