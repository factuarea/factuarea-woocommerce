<?php
/**
 * Unit test of the outbox drain.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\DeliveryOutcome;
use Factuarea\WooCommerce\Contract\DeliveryOutcomeKind;
use Factuarea\WooCommerce\Contract\RetrySchedule;
use Factuarea\WooCommerce\Outbox\OutboxDispatcher;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Factuarea\WooCommerce\Outbox\RetryPlanner;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What this file pins down.
 *
 * The drain is the piece where a wrong decision is expensive in both
 * directions, so the cases are chosen by the mistake they would catch rather
 * than by the method they exercise:
 *
 *  1. A PERMANENT REJECTION PRODUCES NO RETRY AT ALL. Not "eventually stops":
 *     zero further attempts, asserted as an absent next instant on the only
 *     settlement there is.
 *
 *  2. A RETRYABLE REJECTION IS RESCHEDULED AT THE WAIT THE RECEIVER DECLARED,
 *     and the assertion is against that number and NOT against the table, so a
 *     dispatcher that ignored the receiver and fell back to sixty seconds fails
 *     here instead of hammering a receiver that asked for room.
 *
 *  3. FIVE CONSECUTIVE TRANSIENT FAILURES LEAVE THE ENTRY ALIVE with its sixth
 *     attempt scheduled. This is the whole reason the package exists: the native
 *     mechanism switches itself off after five failed deliveries, and five bad
 *     minutes would silence a shop's invoicing until a human noticed.
 *
 *  4. AN ENTRY RECORDED FOR ANOTHER SHOP NEVER REACHES THE NETWORK. Asserted on
 *     the requests actually made, not on the settlement, because the harm is the
 *     request itself.
 *
 *  5. A REDELIVERY IS BYTE-IDENTICAL. Same bytes, same signature, same headers,
 *     so the receiver derives the same identity twice and issues one document.
 *
 * The pieces that are pure - the signer, the header composer, the classifier and
 * the retry planner - are used FOR REAL rather than doubled. Doubling them would
 * turn every case below into a test of this file's own idea of what they do; used
 * for real, a change in any of them that broke the drain shows up here. What is
 * doubled is the outbox, whose real implementation talks to a database and is
 * tested against one in its own phase, and the WordPress functions.
 *
 * The retry planner is real but its noise source is pinned to zero, and the
 * clock is pinned to a fixed instant. Both are so that the assertions can name
 * the exact second the next attempt was scheduled for: with real noise the only
 * assertable thing is a range, and a planner that picked the WRONG INTERVAL
 * would still land inside the range of the right one often enough to be filed as
 * a flake. That the noise itself stays inside its declared bounds is the
 * planner's own test.
 */
final class OutboxDispatcherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The instant every run of the dispatcher believes it is at.
	 */
	private const NOW = 1893456000;

	/**
	 * Address of the Factuarea installation, with the trailing slash a merchant
	 * pasting from a browser leaves behind.
	 */
	private const BASE_URL = 'https://app.factuarea.example/';

	/**
	 * Address the ingestion route actually answers at, once the base address and
	 * the measured path are joined.
	 */
	private const INGESTION_URL = 'https://app.factuarea.example/api/webhooks/stores/woocommerce';

	/**
	 * Address of the shop this installation is.
	 */
	private const SHOP_URL = 'https://shop.example.test';

	/**
	 * Signing secret of this shop. Recognisable on sight, so that a sweep that
	 * ever found it somewhere would name something a reader can search for.
	 */
	private const SIGNING_SECRET = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Body of the entry under test. Small, but a real shape: the receiver reads
	 * the tax identifier out of the order meta of a body like this one.
	 */
	private const RAW_BODY = '{"id":4711,"status":"processing","total":"121.00","meta_data":[{"id":9,"key":"_factuarea_tax_id","value":"B12345674"}]}';

	/**
	 * The options as the double of `get_option` sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * What `home_url()` answers.
	 *
	 * @var string
	 */
	private string $home_url = self::SHOP_URL;

	/**
	 * Entries the doubled outbox has waiting.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $due = array();

	/**
	 * The limits `claim_due` was called with, in order. Empty means the drain
	 * never claimed anything, which is an assertion in its own right.
	 *
	 * @var array<int, int>
	 */
	private array $claim_limits = array();

	/**
	 * Every settlement, in order.
	 *
	 * @var array<int, array{id: int, outcome: DeliveryOutcome, next_attempt_at: int|null}>
	 */
	private array $settled = array();

	/**
	 * Every outgoing request, in order.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, body: string}>
	 */
	private array $requests = array();

	/**
	 * Entry identifiers whose settlement the doubled outbox refuses to write, so
	 * that a database failure on one row can be exercised.
	 *
	 * @var array<int, int>
	 */
	private array $unsettleable = array();

	/**
	 * What the doubled transport answers, as a queue. The last entry is repeated
	 * once the queue runs out, so a test that only cares about one answer sets
	 * one.
	 *
	 * @var array<int, mixed>
	 */
	private array $answers = array();

	/**
	 * Installs the doubles of the WordPress functions the dispatcher reaches
	 * for. There are only four, and that is the point of the class: it knows
	 * settings and identity, and nothing about commerce.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			Constants::OPTION_DELIVERY_ENABLED => '1',
			Constants::OPTION_BASE_URL         => self::BASE_URL,
			Constants::OPTION_SIGNING_SECRET   => self::SIGNING_SECRET,
		);

		$this->home_url     = self::SHOP_URL;
		$this->due          = array( $this->entry() );
		$this->claim_limits = array();
		$this->settled      = array();
		$this->requests     = array();
		$this->answers      = array();
		$this->unsettleable = array();

		Functions\when( 'get_option' )->alias(
			function ( string $option, $default_value = false ) {
				return $this->options[ $option ] ?? $default_value;
			}
		);

		Functions\when( 'home_url' )->alias(
			function (): string {
				return $this->home_url;
			}
		);

		// What WordPress's own wrapper does on every PHP this plugin supports.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url ) {
				return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This IS the double of the alternative function.
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['response']['code'] )
					? $response['response']['code']
					: '';
			}
		);

		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['headers'] ) ? $response['headers'] : array();
			}
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string {
				return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);

		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $arguments ) {
				$this->requests[] = array(
					'url'     => $url,
					'headers' => $arguments['headers'],
					'body'    => $arguments['body'],
				);

				$answer = array_shift( $this->answers );

				if ( null === $answer ) {
					$this->fail( 'The dispatcher made a request the test had no answer for.' );
				}

				if ( array() === $this->answers ) {
					$this->answers = array( $answer );
				}

				if ( $answer instanceof \Throwable ) {
					throw $answer;
				}

				return $answer;
			}
		);
	}

	/**
	 * Removes the doubles.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Fixtures and helpers.
	// ---------------------------------------------------------------------

	/**
	 * One claimed row, shaped exactly as the outbox hands them over.
	 *
	 * @param array<string, mixed> $overrides Values to change.
	 * @return array<string, mixed>
	 */
	private function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 17,
				'event_id'         => 'woocommerce:order:4711:paid',
				'order_id'         => 4711,
				'event_type'       => 'order.paid',
				'raw_body'         => self::RAW_BODY,
				'source'           => self::SHOP_URL,
				'attempts'         => 0,
				'next_attempt_at'  => self::NOW - 10,
				'status'           => OutboxRepository::STATUS_CLAIMED,
				'last_status_code' => null,
				'last_message'     => '',
				'claimed_token'    => 'f00dcafe',
				'claimed_at'       => self::NOW,
				'created_at'       => self::NOW - 600,
				'updated_at'       => self::NOW,
			),
			$overrides
		);
	}

	/**
	 * A response as the WordPress HTTP API builds one.
	 *
	 * @param int                   $status_code Status code.
	 * @param string                $body        Body.
	 * @param array<string, string> $headers     Response headers.
	 * @return array<string, mixed>
	 */
	private function answer( int $status_code, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array(
				'code'    => $status_code,
				'message' => '',
			),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * A rejection body exactly as the receiver's only rejection producer writes
	 * one: four fields, always.
	 *
	 * @param string   $code        Rejection code.
	 * @param bool     $retryable   Whether the receiver called it retryable.
	 * @param int|null $retry_after Wait it declared, or null.
	 * @param string   $message     Message for the merchant, as it arrives.
	 * @return string
	 */
	private function rejection_body( string $code, bool $retryable, ?int $retry_after, string $message ): string {
		/*
		 * Plain `json_encode` and not WordPress's wrapper: doubling the wrapper
		 * would mean the rejection bodies of these tests were produced by a stub
		 * of the test's own writing, when what is wanted is the document the
		 * receiver would actually put on the wire.
		 */
		return (string) json_encode(
			array(
				'code'        => $code,
				'message'     => $message,
				'retryable'   => $retryable,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * The dispatcher under test, with the real pure pieces and a doubled outbox.
	 *
	 * @return OutboxDispatcher
	 */
	private function dispatcher(): OutboxDispatcher {
		$outbox = Mockery::mock( OutboxRepository::class );

		$outbox->shouldReceive( 'claim_due' )->andReturnUsing(
			function ( int $limit ): array {
				$this->claim_limits[] = $limit;

				return array_slice( $this->due, 0, $limit );
			}
		);

		$outbox->shouldReceive( 'settle' )->andReturnUsing(
			function ( int $entry_id, DeliveryOutcome $outcome, ?int $next_attempt_at ): void {
				if ( in_array( $entry_id, $this->unsettleable, true ) ) {
					throw new RuntimeException( 'the database refused the write' );
				}

				$this->settled[] = array(
					'id'              => $entry_id,
					'outcome'         => $outcome,
					'next_attempt_at' => $next_attempt_at,
				);

				/*
				 * Mirrors the one thing the real repository does that the drain
				 * depends on: it increments the attempt counter itself. Without
				 * this here, the consecutive-failures case would plan every
				 * retry from zero and pass while the real pair repeated the
				 * first interval forever.
				 */
				foreach ( $this->due as $index => $row ) {
					if ( (int) $row['id'] === $entry_id ) {
						$this->due[ $index ]['attempts'] = ( (int) $row['attempts'] ) + 1;
					}
				}
			}
		);

		return new OutboxDispatcher(
			null,
			null,
			$outbox,
			/*
			 * The real planner, with its noise pinned to zero so that the
			 * assertions below can name the exact second an attempt was
			 * scheduled for. That the noise itself stays within its declared
			 * bounds is the planner's own test; here what is being asserted is
			 * WHICH interval the drain asked for.
			 */
			new RetryPlanner(
				static function ( int $minimum, int $maximum ): int {
					return 0;
				}
			),
			null,
			null,
			static function (): int {
				return self::NOW;
			}
		);
	}

	/**
	 * The one settlement of a run that settled exactly one entry.
	 *
	 * @return array{id: int, outcome: DeliveryOutcome, next_attempt_at: int|null}
	 */
	private function only_settlement(): array {
		$this->assertCount( 1, $this->settled, 'Exactly one entry should have been settled.' );

		return $this->settled[0];
	}

	// ---------------------------------------------------------------------
	// 1. A permanent rejection produces no retry at all.
	// ---------------------------------------------------------------------

	/**
	 * A rejection of the contract with no declared wait closes the entry without
	 * a single further attempt, and keeps the receiver's own reason for the
	 * merchant.
	 *
	 * The code used is one that does not exist, which is deliberate: this plugin
	 * is distributed, so the codes it meets in a merchant's shop are frequently
	 * younger than the copy that meets them. A dispatcher that classified by a
	 * table of known codes would send this one down whichever branch happens to
	 * be last.
	 *
	 * @return void
	 */
	public function test_a_permanent_rejection_produces_no_retry_at_all(): void {
		$this->answers = array(
			$this->answer(
				422,
				$this->rejection_body(
					'store_plan_limit_reached',
					false,
					null,
					'La tienda supera el limite de su plan.'
				)
			),
		);

		$settled_entries = $this->dispatcher()->drain( 10 );

		$settlement = $this->only_settlement();

		$this->assertSame( 1, $settled_entries );
		$this->assertSame( 17, $settlement['id'] );
		$this->assertSame( DeliveryOutcomeKind::PERMANENT_REJECTION, $settlement['outcome']->kind );
		$this->assertNull(
			$settlement['next_attempt_at'],
			'A permanent rejection must be settled with no next attempt whatsoever.'
		);
		$this->assertSame( 422, $settlement['outcome']->status_code );
		$this->assertSame( 'store_plan_limit_reached', $settlement['outcome']->rejection_code );
		$this->assertSame(
			'La tienda supera el limite de su plan.',
			$settlement['outcome']->merchant_message,
			'The receiver knows why it refused; its message is relayed as it arrived.'
		);
		$this->assertCount( 1, $this->requests, 'It is sent once and never again.' );
	}

	/**
	 * The same thing said the other way round, because it is the property that
	 * matters: however many times the drain runs afterwards, the entry is not
	 * sent again. The doubled outbox stops offering it once it is closed, which
	 * is what the real one does too.
	 *
	 * @return void
	 */
	public function test_a_permanently_rejected_entry_is_not_sent_by_a_later_run(): void {
		$this->answers = array( $this->answer( 400, $this->rejection_body( 'unknown_store', false, null, 'Tienda desconocida.' ) ) );

		$dispatcher = $this->dispatcher();
		$dispatcher->drain( 10 );

		$this->due = array();

		$this->assertSame( 0, $dispatcher->drain( 10 ) );
		$this->assertCount( 1, $this->requests );
	}

	// ---------------------------------------------------------------------
	// 2. A retryable rejection waits exactly as long as it was told to.
	// ---------------------------------------------------------------------

	/**
	 * The receiver declared a wait, so that wait is what is scheduled - not the
	 * plugin's own first interval, and not the wait plus noise.
	 *
	 * The assertion names the receiver's number and, separately, denies the
	 * table's. Asserting only the first would pass on a dispatcher that happened
	 * to be right for the wrong reason on this particular pair of numbers.
	 *
	 * @return void
	 */
	public function test_a_retryable_rejection_is_rescheduled_at_the_declared_wait(): void {
		$declared_wait = 90;

		$this->answers = array(
			$this->answer(
				429,
				$this->rejection_body( 'ingestion_burst', true, $declared_wait, 'Demasiadas entregas seguidas.' ),
				array( 'Retry-After' => (string) $declared_wait )
			),
		);

		$this->dispatcher()->drain( 10 );

		$settlement = $this->only_settlement();

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $settlement['outcome']->kind );
		$this->assertSame( self::NOW + $declared_wait, $settlement['next_attempt_at'] );
		$this->assertNotSame(
			self::NOW + RetrySchedule::INTERVALS[1],
			$settlement['next_attempt_at'],
			'The plugin fell back to its own table while the receiver was telling it how long to wait.'
		);
	}

	/**
	 * Exhaustion still wins over a declared wait. A receiver that kept asking to
	 * be tried again would otherwise keep one entry alive for ever, which is the
	 * unbounded loop the maximum exists to prevent.
	 *
	 * @return void
	 */
	public function test_a_declared_wait_does_not_buy_a_ninth_attempt(): void {
		$this->due     = array( $this->entry( array( 'attempts' => RetrySchedule::MAX_ATTEMPTS - 1 ) ) );
		$this->answers = array(
			$this->answer(
				503,
				$this->rejection_body( 'ingestion_paused', true, 30, 'Ingesta en pausa.' ),
				array( 'Retry-After' => '30' )
			),
		);

		$this->dispatcher()->drain( 10 );

		$settlement = $this->only_settlement();

		$this->assertSame( DeliveryOutcomeKind::RETRYABLE_REJECTION, $settlement['outcome']->kind );
		$this->assertNull(
			$settlement['next_attempt_at'],
			'The eighth failed attempt exhausts the entry, whatever the receiver asked for.'
		);
	}

	// ---------------------------------------------------------------------
	// 3. Five consecutive failures do not silence the shop.
	// ---------------------------------------------------------------------

	/**
	 * The case the whole package exists for.
	 *
	 * WooCommerce's native webhook mechanism disables itself after five
	 * consecutive failed deliveries, with no retry, no backoff and no queue of
	 * failures: five bad minutes of a receiver silence a shop's invoicing
	 * permanently, until somebody happens to notice. So five transient failures
	 * in a row are run here, and what is asserted is that after the fifth the
	 * entry is STILL ALIVE with its sixth attempt scheduled.
	 *
	 * A 502 with an empty body is used because it is the shape that would be
	 * misread as a refusal: a proxy in front of the receiver answers it with an
	 * HTML error page, carrying no rejection code and no wait header. Classified
	 * permanently, that paid order is gone.
	 *
	 * The waits are asserted interval by interval as well, because "still alive"
	 * would also be true of a dispatcher that rescheduled everything sixty
	 * seconds out for ever.
	 *
	 * @return void
	 */
	public function test_five_consecutive_transient_failures_leave_the_sixth_attempt_scheduled(): void {
		$this->answers = array( $this->answer( 502, '<html><body>502 Bad Gateway</body></html>' ) );

		$dispatcher = $this->dispatcher();

		for ( $run = 0; $run < 5; $run++ ) {
			$this->assertSame( 1, $dispatcher->drain( 10 ), sprintf( 'Run %d settled nothing.', $run + 1 ) );
		}

		$this->assertCount( 5, $this->settled );
		$this->assertCount( 5, $this->requests, 'Every run has to have actually tried.' );

		foreach ( $this->settled as $index => $settlement ) {
			$attempt = $index + 1;

			$this->assertSame(
				DeliveryOutcomeKind::TRANSIENT_FAILURE,
				$settlement['outcome']->kind,
				sprintf( 'A gateway error with no contract body is transient; attempt %d said otherwise.', $attempt )
			);

			$this->assertSame(
				self::NOW + RetrySchedule::INTERVALS[ $attempt ],
				$settlement['next_attempt_at'],
				sprintf( 'Attempt %d was not rescheduled at its own interval of the table.', $attempt )
			);
		}

		$sixth_attempt = end( $this->settled );

		$this->assertNotNull(
			$sixth_attempt['next_attempt_at'],
			'After five failures the entry has to be alive with a sixth attempt planned, which is exactly what the native mechanism does not do.'
		);
		$this->assertSame( self::NOW + RetrySchedule::INTERVALS[5], $sixth_attempt['next_attempt_at'] );
		$this->assertSame( 5, (int) $this->due[0]['attempts'] );
	}

	// ---------------------------------------------------------------------
	// 4. An entry of another shop is not delivered.
	// ---------------------------------------------------------------------

	/**
	 * An entry recorded under a different shop address never reaches the
	 * network, and is settled with its typed reason rather than dropped.
	 *
	 * It is asserted on the REQUESTS and not on the settlement, because the harm
	 * is the request itself: it would carry one shop's sale signed with another
	 * shop's secret, and where both shops sit under one account it would invoice
	 * a real sale twice. The ordinary way to get here is a staging site made by
	 * copying the production database, pending outbox and all.
	 *
	 * @return void
	 */
	public function test_an_entry_recorded_for_another_shop_never_reaches_the_network(): void {
		$this->due     = array( $this->entry( array( 'source' => 'https://production-shop.example.test' ) ) );
		$this->answers = array( $this->answer( 200 ) );

		$settled_entries = $this->dispatcher()->drain( 10 );

		$this->assertSame( array(), $this->requests, 'Nothing may go out for another shop.' );
		$this->assertSame( 1, $settled_entries );

		$settlement = $this->only_settlement();

		$this->assertNull(
			$settlement['next_attempt_at'],
			'No later attempt can make the entry belong to this installation.'
		);
		$this->assertStringContainsString( 'production-shop.example.test', $settlement['outcome']->merchant_message );
		$this->assertStringContainsString( 'shop.example.test', $settlement['outcome']->merchant_message );
	}

	/**
	 * The comparison is of installations, not of strings. These four pairs are
	 * the same shop written differently, and refusing them would strand every
	 * pending sale of a shop that did nothing but get its own address back with
	 * a trailing slash.
	 *
	 * @param string $recorded   Origin stored with the entry.
	 * @param string $configured Origin the installation reports.
	 * @return void
	 */
	#[DataProvider( 'provide_origins_that_are_the_same_installation' )]
	public function test_the_origin_guard_accepts_the_same_shop_written_differently( string $recorded, string $configured ): void {
		$this->due      = array( $this->entry( array( 'source' => $recorded ) ) );
		$this->home_url = $configured;
		$this->answers  = array( $this->answer( 200 ) );

		$this->dispatcher()->drain( 10 );

		$this->assertCount( 1, $this->requests, sprintf( '"%s" and "%s" are one shop.', $recorded, $configured ) );
	}

	/**
	 * The pairs of the previous case.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_origins_that_are_the_same_installation(): array {
		return array(
			'trailing slash'    => array( 'https://shop.example.test/', 'https://shop.example.test' ),
			'host capitalised'  => array( 'https://Shop.Example.Test', 'https://shop.example.test' ),
			'default port named' => array( 'https://shop.example.test:443', 'https://shop.example.test' ),
			'subdirectory shop' => array( 'https://shop.example.test/store/', 'https://shop.example.test/store' ),
		);
	}

	/**
	 * And these are not, however similar they look. The subdirectory pair is the
	 * one that matters: two WordPress installations under one host - the
	 * ordinary shape of a subdirectory multisite - differ only in their path, so
	 * a guard that compared scheme and host alone would let one deliver the
	 * other's sales.
	 *
	 * @param string $recorded   Origin stored with the entry.
	 * @param string $configured Origin the installation reports.
	 * @return void
	 */
	#[DataProvider( 'provide_origins_that_are_different_installations' )]
	public function test_the_origin_guard_refuses_a_different_installation( string $recorded, string $configured ): void {
		$this->due      = array( $this->entry( array( 'source' => $recorded ) ) );
		$this->home_url = $configured;
		$this->answers  = array( $this->answer( 200 ) );

		$this->dispatcher()->drain( 10 );

		$this->assertSame(
			array(),
			$this->requests,
			sprintf( '"%s" and "%s" are not the same installation.', $recorded, $configured )
		);
	}

	/**
	 * The pairs of the previous case.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_origins_that_are_different_installations(): array {
		return array(
			'another host'      => array( 'https://staging.example.test', 'https://shop.example.test' ),
			'another subdirectory' => array( 'https://shop.example.test/store-a', 'https://shop.example.test/store-b' ),
			'a subdirectory and the root' => array( 'https://shop.example.test/store', 'https://shop.example.test' ),
			'another scheme'    => array( 'http://shop.example.test', 'https://shop.example.test' ),
			'another port'      => array( 'https://shop.example.test:8443', 'https://shop.example.test' ),
		);
	}

	// ---------------------------------------------------------------------
	// 5. A redelivery is byte-identical, so one sale is one document.
	// ---------------------------------------------------------------------

	/**
	 * The same entry delivered twice sends the same thing twice.
	 *
	 * The receiver derives the identity of the event from the BODY, so what has
	 * to hold is that the second attempt carries the same bytes as the first. It
	 * does because the body was frozen at capture time and this class never
	 * rebuilds it - the failure this asserts against is a dispatcher that
	 * re-read the order, or re-encoded the stored JSON, either of which would
	 * hand the receiver a second document to invoice.
	 *
	 * The whole header set is compared too, and it is identical: the two tracing
	 * identifiers of this plugin describe the ENTRY, not the attempt. That is a
	 * deliberate difference from a native delivery, whose delivery identifier
	 * changes every time, and it is the safe direction - nothing derived from an
	 * identity can drift between attempts if nothing about the attempt travels.
	 *
	 * @return void
	 */
	public function test_a_redelivery_sends_the_same_identity_and_the_same_bytes(): void {
		$this->answers = array(
			$this->answer( 502, '' ),
			$this->answer( 200, '' ),
		);

		$dispatcher = $this->dispatcher();
		$dispatcher->drain( 10 );
		$dispatcher->drain( 10 );

		$this->assertCount( 2, $this->requests );

		$first  = $this->requests[0];
		$second = $this->requests[1];

		$this->assertSame( self::RAW_BODY, $first['body'], 'The stored bytes are the delivered bytes.' );
		$this->assertSame( $first['body'], $second['body'], 'A redelivery must carry the same bytes.' );
		$this->assertSame( $first['headers'], $second['headers'], 'A redelivery must carry the same headers.' );
		$this->assertSame( $first['url'], $second['url'] );

		$this->assertSame(
			'woocommerce:order:4711:paid',
			$this->due[0]['event_id'],
			'The identity belongs to the entry and the dispatcher never rewrites it.'
		);

		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $this->settled[0]['outcome']->kind );
		$this->assertSame( DeliveryOutcomeKind::ACCEPTED, $this->settled[1]['outcome']->kind );
		$this->assertNull(
			$this->settled[1]['next_attempt_at'],
			'An acceptance closes the entry; it is never sent a third time.'
		);
	}

	// ---------------------------------------------------------------------
	// What goes on the wire.
	// ---------------------------------------------------------------------

	/**
	 * The seven native headers, the fixed topic, and the address the measured
	 * ingestion path produces once joined to a base address a merchant pasted
	 * with a trailing slash.
	 *
	 * @return void
	 */
	public function test_the_delivery_carries_the_seven_native_headers_to_the_measured_path(): void {
		$this->answers = array( $this->answer( 200 ) );

		$this->dispatcher()->drain( 10 );

		$request = $this->requests[0];

		$this->assertSame( self::INGESTION_URL, $request['url'] );

		/*
		 * The seven, in the order a native delivery sends them, at the head of
		 * the set - and the transport's own content type as the ONE addition.
		 * Asserted as a partition rather than as "the seven are in there
		 * somewhere", because a delivery that grew an eighth header of ours
		 * would stop being indistinguishable from a native one and nothing else
		 * would notice.
		 */
		$sent_headers = array_keys( $request['headers'] );

		$this->assertSame( Constants::DELIVERY_HEADERS, array_slice( $sent_headers, 0, 7 ) );
		$this->assertSame(
			array( 'Content-Type' ),
			array_slice( $sent_headers, 7 ),
			'The transport labels the body as JSON; nothing else may be added to a delivery.'
		);
		$this->assertSame( self::SHOP_URL, $request['headers'][ Constants::HEADER_SOURCE ] );
		$this->assertSame( OutboxDispatcher::DELIVERY_TOPIC, $request['headers'][ Constants::HEADER_TOPIC ] );
		$this->assertSame( OutboxDispatcher::DELIVERY_RESOURCE, $request['headers'][ Constants::HEADER_RESOURCE ] );
		$this->assertSame( OutboxDispatcher::DELIVERY_EVENT, $request['headers'][ Constants::HEADER_EVENT ] );
		$this->assertSame( (string) OutboxDispatcher::WEBHOOK_ID, $request['headers'][ Constants::HEADER_WEBHOOK_ID ] );
		$this->assertSame( '17', $request['headers'][ Constants::HEADER_DELIVERY_ID ], 'The delivery identifier is the entry.' );
	}

	/**
	 * The signature covers the exact bytes that were sent.
	 *
	 * Asserted by re-signing the body the transport ACTUALLY received, rather
	 * than the one the test meant to send: a dispatcher that signed the stored
	 * string and then handed the transport a re-encoded one would pass any
	 * assertion written the other way round, and would fail at the receiver with
	 * a 401 that looks exactly like a wrong secret.
	 *
	 * @return void
	 */
	public function test_the_signature_covers_the_bytes_that_were_sent(): void {
		$this->answers = array( $this->answer( 200 ) );

		$this->dispatcher()->drain( 10 );

		$request = $this->requests[0];

		$this->assertSame(
			( new DeliverySigner() )->sign( $request['body'], self::SIGNING_SECRET ),
			$request['headers'][ Constants::HEADER_SIGNATURE ]
		);
	}

	// ---------------------------------------------------------------------
	// When the run does nothing, it consumes nothing.
	// ---------------------------------------------------------------------

	/**
	 * Delivery switched off means nothing is claimed, nothing is sent and
	 * nothing is settled. Claiming and settling would spend attempts of entries
	 * the merchant asked not to send.
	 *
	 * @return void
	 */
	public function test_a_run_with_delivery_switched_off_claims_nothing(): void {
		$this->options[ Constants::OPTION_DELIVERY_ENABLED ] = '0';
		$this->answers = array( $this->answer( 200 ) );

		$this->assertSame( 0, $this->dispatcher()->drain( 10 ) );
		$this->assertSame( array(), $this->claim_limits );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->settled );
	}

	/**
	 * A plugin that has not been configured yet claims nothing either, and that
	 * is the case this ordering exists for.
	 *
	 * A freshly activated plugin has neither address nor secret while the first
	 * sales are already arriving. Claiming them would spend one of their eight
	 * attempts per run against a settings screen nobody has filled in, and the
	 * eight are spread over four and a half days - so a shop's first sales could
	 * expire before the merchant finished setting up. Left unclaimed they stay
	 * pending and go out unchanged once the settings are there, which is what
	 * the typed configuration error promises.
	 *
	 * @param string $missing_option Option left empty.
	 * @return void
	 */
	#[DataProvider( 'provide_settings_without_which_nothing_can_be_delivered' )]
	public function test_an_unconfigured_plugin_claims_nothing( string $missing_option ): void {
		$this->options[ $missing_option ] = '';
		$this->answers                    = array( $this->answer( 200 ) );

		$this->assertSame( 0, $this->dispatcher()->drain( 10 ) );
		$this->assertSame( array(), $this->claim_limits );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->settled );
	}

	/**
	 * The two settings of the previous case.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_settings_without_which_nothing_can_be_delivered(): array {
		return array(
			'no Factuarea address' => array( Constants::OPTION_BASE_URL ),
			'no signing secret'    => array( Constants::OPTION_SIGNING_SECRET ),
		);
	}

	/**
	 * A limit below one takes nothing. A drain scheduled with a bad number is a
	 * configuration mistake, and claiming rows to do nothing with them would
	 * leave them claimed for the whole claim timeout.
	 *
	 * @return void
	 */
	public function test_a_run_with_no_room_claims_nothing(): void {
		$this->answers = array( $this->answer( 200 ) );

		$this->assertSame( 0, $this->dispatcher()->drain( 0 ) );
		$this->assertSame( array(), $this->claim_limits );
		$this->assertSame( array(), $this->requests );
	}

	// ---------------------------------------------------------------------
	// One bad entry does not take the run with it.
	// ---------------------------------------------------------------------

	/**
	 * An exception nobody anticipated - here, another plugin's filter on the
	 * outgoing request throwing - settles THAT entry and leaves the rest of the
	 * batch alone.
	 *
	 * Without this, one entry would abandon every entry behind it in the batch,
	 * still claimed, for the whole claim timeout - once per run, for as long as
	 * the bad entry existed. So the assertion is on the SECOND entry going
	 * through, not on the first one being handled.
	 *
	 * It is settled as transient and rescheduled, which is the cheap direction
	 * to be wrong in: a defect may be gone in an hour, and closing a paid sale
	 * over it is not undoable.
	 *
	 * @return void
	 */
	public function test_an_unexpected_failure_settles_that_entry_and_the_run_continues(): void {
		$this->due = array(
			$this->entry(),
			$this->entry(
				array(
					'id'       => 18,
					'event_id' => 'woocommerce:order:4712:paid',
					'order_id' => 4712,
				)
			),
		);

		$this->answers = array(
			new RuntimeException( 'another plugin refused the outgoing request' ),
			$this->answer( 200 ),
		);

		$settled_entries = $this->dispatcher()->drain( 10 );

		$this->assertSame( 2, $settled_entries );
		$this->assertCount( 2, $this->settled );

		$this->assertSame( 17, $this->settled[0]['id'] );
		$this->assertSame( DeliveryOutcomeKind::TRANSIENT_FAILURE, $this->settled[0]['outcome']->kind );
		$this->assertSame( self::NOW + RetrySchedule::INTERVALS[1], $this->settled[0]['next_attempt_at'] );
		$this->assertStringContainsString(
			'another plugin refused the outgoing request',
			$this->settled[0]['outcome']->merchant_message
		);

		$this->assertSame( 18, $this->settled[1]['id'] );
		$this->assertSame( DeliveryOutcomeKind::ACCEPTED, $this->settled[1]['outcome']->kind );
	}

	/**
	 * A settlement that cannot be written leaves the rest of the batch alone
	 * too, and the run does not count it.
	 *
	 * The entry stays CLAIMED, which is not a loss: the outbox takes a claim back
	 * after its timeout, so the entry is picked up again by a later run. What
	 * would be a loss is abandoning the entries behind it in the batch, which is
	 * what an exception escaping the loop here would do - and it would do it
	 * every run, for as long as whatever broke that write kept breaking it.
	 *
	 * @return void
	 */
	public function test_a_settlement_that_cannot_be_written_does_not_abandon_the_batch(): void {
		$this->due = array(
			$this->entry(),
			$this->entry( array( 'id' => 18 ) ),
		);

		$this->unsettleable = array( 17 );
		$this->answers      = array( $this->answer( 200 ) );

		$settled_entries = $this->dispatcher()->drain( 10 );

		$this->assertSame( 1, $settled_entries, 'An entry whose outcome was not written was not settled.' );
		$this->assertCount( 2, $this->requests, 'The second entry was still delivered.' );
		$this->assertCount( 1, $this->settled );
		$this->assertSame( 18, $this->settled[0]['id'] );
	}

	/**
	 * The number a run reports is the number of entries it SETTLED, whatever
	 * became of each of them. A count of successful deliveries would read as
	 * "nothing happened" on the run where everything was rejected, which is the
	 * run somebody needs to look at.
	 *
	 * @return void
	 */
	public function test_the_run_counts_every_entry_it_settled(): void {
		$this->due = array(
			$this->entry(),
			$this->entry( array( 'id' => 18 ) ),
			$this->entry( array( 'id' => 19 ) ),
		);

		$this->answers = array(
			$this->answer( 200 ),
			$this->answer( 400, $this->rejection_body( 'unknown_store', false, null, 'Tienda desconocida.' ) ),
			$this->answer( 502, '' ),
		);

		$this->assertSame( 3, $this->dispatcher()->drain( 10 ) );
		$this->assertSame( array( 10 ), $this->claim_limits, 'The limit is passed straight to the outbox.' );
	}
}
