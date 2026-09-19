<?php
/**
 * Unit tests for the inbound price writer.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Inbound\PriceWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case is here.
 *
 * The article is a double that BEHAVES like a product rather than merely
 * recording calls: a written price lands in an array and a read comes back out
 * of it. `WC_Product` does not exist in a Unit run - there is no WordPress and
 * no WooCommerce - so it is built by name, exactly as the order double of
 * `OrderStatusStorageTest` is. A double that only counted calls would let a
 * writer that stores a truncated amount pass every case below.
 *
 * The double declares `set_price`, `set_sale_price`, `set_date_on_sale_from`
 * and `set_date_on_sale_to` as calls it must NOT receive. That is the
 * mechanical half of two rules the class block holds in prose: the offer the
 * merchant set is not this receiver's to revisit, and the effective price is
 * derived by WooCommerce from the regular price and the offer, so writing it
 * here would publish the regular price over a live offer.
 *
 * THE AMOUNTS ARE NOT DECORATIVE. Every amount used for the identity cases is
 * one whose float round trip is VISIBLY different, and the test asserts that
 * difference before asserting the stored value. Without that assertion the
 * identity cases would also pass against a writer that casts, and the whole
 * point of this file is that they must not.
 */
final class PriceWriterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Product properties written on the double, keyed by property name.
	 *
	 * @var array<string, string>
	 */
	private array $props = array();

	/**
	 * How many times the product double has been saved.
	 *
	 * @var int
	 */
	private int $saves = 0;

	/**
	 * Starts every test from an article with no property written.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		$this->props = array();
		$this->saves = 0;
	}

	/**
	 * Removes the doubles so the next test starts from nothing.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * A price with decimals keeps exactly the scale it arrived with.
	 *
	 * The scale is part of the amount and not a presentation detail: a shop that
	 * stores `19.9` where the platform published `19.90` has a different string
	 * in its database from the one the platform believes it published, and every
	 * later comparison between the two - a reconciliation, a diff, a support
	 * question - reads as a discrepancy that is not one.
	 *
	 * @return void
	 */
	public function test_a_price_with_decimals_keeps_its_exact_scale(): void {
		$amounts = array( '19.90', '0.10', '12.345', '7.000', '1200.00' );

		foreach ( $amounts as $amount ) {
			$product = $this->product();

			$outcome = ( new PriceWriter() )->write( $product, $this->item( array( 'price' => $amount ) ) );

			self::assertTrue( $outcome->is_applied(), sprintf( 'The amount "%s" is readable and must be applied.', $amount ) );
			self::assertSame(
				$amount,
				$this->props['regular_price'] ?? null,
				sprintf( 'The amount "%s" must be stored with its own scale, character for character.', $amount )
			);
		}
	}

	/**
	 * The applied amount is the received amount, and a float would have shown.
	 *
	 * The second assertion is what makes the first one mean something. Each
	 * amount below is one that does NOT survive a round trip through a float -
	 * either the scale is lost or the value itself is - so a writer that cast
	 * anywhere on the way would store something different from what is asserted.
	 * The test proves that the trap is armed before it checks that nothing fell
	 * into it.
	 *
	 * @return void
	 */
	public function test_the_applied_amount_is_the_received_one_and_a_float_would_have_been_visible(): void {
		$amounts = array(
			'1.10',
			'19.900',
			'0.1000',
			'9007199254740993',
			'1234567890123456.78',
		);

		foreach ( $amounts as $amount ) {
			self::assertNotSame(
				$amount,
				(string) (float) $amount,
				sprintf(
					'The amount "%s" is in this list because a float round trip changes it; if it stopped doing so it is no longer evidence of anything.',
					$amount
				)
			);

			$product = $this->product();

			( new PriceWriter() )->write( $product, $this->item( array( 'price' => $amount ) ) );

			self::assertSame(
				$amount,
				$this->props['regular_price'] ?? null,
				sprintf( 'The amount "%s" must reach the article untouched.', $amount )
			);
		}
	}

	/**
	 * Nothing is added to the amount and nothing is taken off it.
	 *
	 * The tax question is asked twice, because it has two halves that fail
	 * differently. The first is behavioural: the amount that reaches the article
	 * is the amount that arrived, so no rate was applied in either direction.
	 * The second is structural and lives in
	 * {@see self::test_the_writer_reads_nothing_about_tax_and_goes_nowhere()}:
	 * a writer could ask the shop whether its prices include tax, decide they
	 * already match and change nothing today, and start recomposing the day a
	 * merchant flips that setting. The behavioural half would still be green.
	 *
	 * @return void
	 */
	public function test_no_tax_is_added_to_the_amount_nor_taken_off_it(): void {
		$product = $this->product();

		( new PriceWriter() )->write( $product, $this->item( array( 'price' => '100.00' ) ) );

		self::assertSame(
			'100.00',
			$this->props['regular_price'] ?? null,
			'A tax recomposed in either direction would have turned 100.00 into 121.00 or into 82.64.'
		);
	}

	/**
	 * An amount that is not a readable decimal rejects the article and leaves
	 * the price it already had exactly as it was.
	 *
	 * Leaving the previous price is the point. The alternative that a careless
	 * writer reaches for - store whatever the amount casts to - publishes a
	 * price of zero for every unreadable amount, and a shop selling at zero is
	 * discovered by its customers.
	 *
	 * @return void
	 */
	public function test_an_unreadable_amount_rejects_the_article_and_leaves_its_price_alone(): void {
		$amounts = array( 'abc', '19,90', '1.2.3', ' 19.90', '19.90 ', '1e3', '0x1A', '1_000', '+5', '.5', '5.', '19.90 EUR' );

		foreach ( $amounts as $amount ) {
			$product = $this->product( array( 'regular_price' => '42.00' ) );

			$outcome = ( new PriceWriter() )->write( $product, $this->item( array( 'price' => $amount ) ) );

			self::assertFalse( $outcome->is_applied(), sprintf( 'The amount "%s" is not a readable decimal.', $amount ) );
			self::assertSame(
				CatalogPushOutcome::REASON_UNREADABLE_AMOUNT,
				$outcome->reason,
				sprintf( 'The rejection of "%s" has to name the amount as the reason, and not any of the other four.', $amount )
			);
			self::assertSame(
				'42.00',
				$this->props['regular_price'] ?? null,
				sprintf( 'The previous price must survive the rejection of "%s".', $amount )
			);
			self::assertSame( 0, $this->saves, sprintf( 'A rejected article is not saved; "%s" saved it.', $amount ) );
		}
	}

	/**
	 * The offer the merchant set survives the push.
	 *
	 * The inbound contract publishes A PRICE and not a campaign. A receiver that
	 * cleared the sale price would be undoing a decision the merchant took in
	 * their own shop, and would do it silently on every push of a regular price.
	 *
	 * @return void
	 */
	public function test_an_existing_offer_survives_the_push(): void {
		$product = $this->product(
			array(
				'regular_price' => '30.00',
				'sale_price'    => '24.00',
			)
		);

		$outcome = ( new PriceWriter() )->write( $product, $this->item( array( 'price' => '33.00' ) ) );

		self::assertTrue( $outcome->is_applied(), 'A readable amount applies.' );
		self::assertSame( '33.00', $this->props['regular_price'] ?? null, 'The regular price is the one the push carries.' );
		self::assertSame( '24.00', $this->props['sale_price'] ?? null, 'The offer is the merchant\'s and is left exactly as it was.' );
	}

	/**
	 * A push that does not declare a price changes nothing at all.
	 *
	 * The three writers of this contract run over the same article in order, so
	 * this class is called for every item of every batch - including the ones
	 * that are only about stock. An absent price means «this push is not about
	 * the price», and a writer that read it as «blank it» would wipe the price
	 * of an article whose stock was being synchronised.
	 *
	 * The outcome is APPLIED and not a third state, because that is what lets
	 * the receiver collect one outcome per article from three writers: a writer
	 * with nothing to do has nothing to object to.
	 *
	 * @return void
	 */
	public function test_a_push_that_does_not_declare_a_price_changes_nothing(): void {
		$product = $this->product( array( 'regular_price' => '15.00' ) );

		$outcome = ( new PriceWriter() )->write( $product, $this->item( array( 'available_quantity' => 7 ) ) );

		self::assertTrue( $outcome->is_applied(), 'An item without a price is not a rejected item.' );
		self::assertSame( '15.00', $this->props['regular_price'] ?? null, 'The price that was there stays there.' );
		self::assertSame( 0, $this->saves, 'A writer with nothing to write does not save, and does not fire the update hooks of the shop.' );
	}

	/**
	 * The article is saved exactly once when a price is applied.
	 *
	 * Once, because every save fires `woocommerce_update_product` and whatever
	 * the merchant hung on it; at least once, because a setter that is never
	 * persisted is a write that disappears in silence.
	 *
	 * @return void
	 */
	public function test_an_applied_price_is_persisted_with_exactly_one_save(): void {
		$product = $this->product();

		( new PriceWriter() )->write( $product, $this->item( array( 'price' => '11.11' ) ) );

		self::assertSame( 1, $this->saves, 'The writer that changes a value is the one that persists it, and once.' );
	}

	/**
	 * The outcome names the article the way the SENDER named it.
	 *
	 * The remote identity when the push carried one, the reference otherwise,
	 * and never an identifier of this shop: the sender asked about something it
	 * named, and an answer keyed by a WordPress post id answers a question
	 * nobody asked.
	 *
	 * @return void
	 */
	public function test_the_outcome_names_the_article_the_way_the_sender_named_it(): void {
		$applied = ( new PriceWriter() )->write(
			$this->product(),
			$this->item(
				array(
					'remote_id' => 'prod-9f2a',
					'price'     => '5.00',
				)
			)
		);

		self::assertSame( 'prod-9f2a', $applied->sender_reference, 'With a remote identity, that is how the sender named it.' );

		$rejected = ( new PriceWriter() )->write(
			$this->product(),
			$this->item( array( 'price' => 'not-a-price' ) )
		);

		self::assertSame( 'SKU-001', $rejected->sender_reference, 'Without a remote identity, the reference is how the sender named it.' );
	}

	/**
	 * The readability check is the package's single pattern, not a narrower one
	 * written here.
	 *
	 * `InboundRefundNotice::is_decimal_string()` owns the shape of an amount for
	 * the whole package, and this writer asks it rather than writing a second
	 * pattern that would drift. The consequence is pinned rather than left
	 * implicit: a currency with three decimals is accepted, a whole number is
	 * accepted, and the sign the pattern allows travels through unchanged.
	 *
	 * The sign is the interesting one, so it is stated out loud: this writer
	 * does NOT reject a negative amount. A negative price is not a shape this
	 * receiver can diagnose - it is readable, and calling it unreadable would
	 * hand the sender one of the five closed reasons with the wrong meaning -
	 * so if a negative price must never be published, that decision belongs in
	 * the contract and in the emitter, where it can be answered honestly.
	 *
	 * @return void
	 */
	public function test_the_readability_check_is_the_package_pattern_and_is_not_narrowed_here(): void {
		$accepted = array( '0', '7', '1234567890123.45', '0.123', '-5.00' );

		foreach ( $accepted as $amount ) {
			$product = $this->product();

			$outcome = ( new PriceWriter() )->write( $product, $this->item( array( 'price' => $amount ) ) );

			self::assertTrue(
				$outcome->is_applied(),
				sprintf( 'The package pattern accepts "%s", and this writer does not narrow it.', $amount )
			);
			self::assertSame( $amount, $this->props['regular_price'] ?? null, sprintf( 'And "%s" travels through unchanged.', $amount ) );
		}
	}

	/**
	 * The writer reads nothing about tax, converts nothing to a float and goes
	 * nowhere.
	 *
	 * A sweep of the class's own source, with comments and docblocks removed so
	 * that the prose explaining these prohibitions cannot satisfy them. Three
	 * families, three different failures:
	 *
	 *  - A float conversion loses the last decimal of amounts that are perfectly
	 *    representable as text, and it is found in a real order.
	 *  - Reading the shop's tax settings is how a writer starts recomposing an
	 *    amount the platform already resolved, months after this file was
	 *    reviewed.
	 *  - An HTTP call from a receiver turns one delivery into two, and the
	 *    plugin has exactly one way out, which is its transport.
	 *
	 * @return void
	 */
	public function test_the_writer_reads_nothing_about_tax_and_goes_nowhere(): void {
		$code = $this->code_of( dirname( __DIR__, 2 ) . '/src/Inbound/PriceWriter.php' );

		$forbidden = array(
			'(float)'                     => 'a float conversion changes the last decimal of a perfectly representable amount',
			'(double)'                    => 'a float conversion changes the last decimal of a perfectly representable amount',
			'floatval('                   => 'a float conversion changes the last decimal of a perfectly representable amount',
			'round('                      => 'rounding here would decide a scale the platform already decided',
			'number_format('              => 'formatting an amount is a presentation decision and this is storage',
			'wc_format_decimal('          => 'the shop\'s decimal normalisation is WooCommerce\'s own business, applied by its setter',
			'sprintf('                    => 'an amount rebuilt through a format string is an amount rewritten',
			'wc_get_price_including_tax(' => 'no tax is added to the amount',
			'wc_get_price_excluding_tax(' => 'no tax is taken off the amount',
			'wc_prices_include_tax('      => 'the shop\'s declaration about its own prices does not reinterpret this one',
			'get_option('                 => 'no setting of the shop changes what is stored, so none is read',
			'tax_class'                   => 'the tax class is not read and not written',
			'tax_status'                  => 'the tax status is not read and not written',
			'set_price('                  => 'the effective price is derived by WooCommerce from the regular price and the offer',
			'set_sale_price('             => 'the offer belongs to the merchant',
			'set_date_on_sale'            => 'the window of the offer belongs to the merchant',
			'wp_remote_'                  => 'applying what arrived does not need the network',
			'curl_'                       => 'applying what arrived does not need the network',
		);

		foreach ( $forbidden as $fragment => $reason ) {
			self::assertStringNotContainsString(
				$fragment,
				$code,
				sprintf( 'PriceWriter must not contain "%s": %s.', $fragment, $reason )
			);
		}

		self::assertStringContainsString(
			'set_regular_price(',
			$code,
			'The one write this class performs is the regular price, so the sweep has to see it; a sweep that saw nothing would pass over an empty file.'
		);
	}

	/**
	 * Builds one item of a catalogue push from the keys the test declares.
	 *
	 * Built through `CatalogPushItem::from_array()` and never by hand, so that
	 * the items these cases use are items the receiver could really produce: a
	 * shape the contract rejects never reaches this writer, and a test that
	 * hand-built one would be testing a situation that cannot happen.
	 *
	 * @param array<string, mixed> $fields Item keys beyond the reference.
	 * @return CatalogPushItem
	 */
	private function item( array $fields ): CatalogPushItem {
		$item = CatalogPushItem::from_array( array_merge( array( 'sku' => 'SKU-001' ), $fields ) );

		self::assertInstanceOf(
			CatalogPushItem::class,
			$item,
			'The fixture itself has to be a readable item; if the contract rejects it, the case is not about the price writer.'
		);

		return $item;
	}

	/**
	 * The article double.
	 *
	 * @param array<string, string> $existing_props Properties the article already has.
	 * @return MockInterface
	 */
	private function product( array $existing_props = array() ): MockInterface {
		$this->props = $existing_props;
		$this->saves = 0;

		$product = Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'set_regular_price' )->andReturnUsing(
			function ( $value ): void {
				self::assertIsString( $value, 'The price reaches WooCommerce as a string, which is what it persists verbatim.' );

				$this->props['regular_price'] = $value;
			}
		);

		$product->shouldReceive( 'get_regular_price' )->andReturnUsing(
			function (): string {
				return $this->props['regular_price'] ?? '';
			}
		);

		$product->shouldReceive( 'get_sale_price' )->andReturnUsing(
			function (): string {
				return $this->props['sale_price'] ?? '';
			}
		);

		$product->shouldReceive( 'get_id' )->andReturn( 4711 );

		$product->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				++$this->saves;

				return 4711;
			}
		);

		// The prohibitions, made mechanical. The first two would publish the
		// regular price over a live offer; the last two would move the window
		// of an offer this receiver was never asked about.
		$product->shouldNotReceive( 'set_price' );
		$product->shouldNotReceive( 'set_sale_price' );
		$product->shouldNotReceive( 'set_date_on_sale_from' );
		$product->shouldNotReceive( 'set_date_on_sale_to' );

		return $product;
	}

	/**
	 * The PHP code of a file with its comments and docblocks removed.
	 *
	 * Removed on purpose: the class block explains at length why none of the
	 * swept fragments may appear, and a sweep of the raw text would be satisfied
	 * by that explanation. What is swept is what runs.
	 *
	 * Whitespace is collapsed as well, so that a call split across two lines is
	 * still found by the name it is called with.
	 *
	 * @param string $path Absolute path of the file to read.
	 * @return string The code, without comments and without whitespace.
	 */
	private function code_of( string $path ): string {
		$source = file_get_contents( $path );

		self::assertIsString( $source, sprintf( 'The sweep has to read %s; a file it cannot read is not a file it checked.', $path ) );

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$code .= $token[1];

				continue;
			}

			$code .= $token;
		}

		return (string) preg_replace( '/\s+/', '', $code );
	}
}
