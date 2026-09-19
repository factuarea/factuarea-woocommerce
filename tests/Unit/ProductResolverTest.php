<?php
/**
 * Unit tests for the resolution of the article a catalogue push addresses.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Inbound\ProductResolver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case is here.
 *
 * The four WooCommerce functions the resolver may call are doubled by aliases
 * that BEHAVE like a shop - a catalogue of articles keyed by identifier, an
 * identity index, a reference index and a uniqueness answer - and every one of
 * them COUNTS its calls. The counters are what make the two strongest promises
 * of this class testable at all:
 *
 *   - a registered identity resolves WITHOUT searching by reference, which is
 *     «the reference search was never called» and not «the right article came
 *     back»;
 *   - an ambiguous reference touches NEITHER article, which is «neither of them
 *     was even built», and a test that only checked the meta of two doubles
 *     would pass for a resolver that loaded both and wrote to neither by luck.
 *
 * The articles are `WC_Product` doubles built by name: there is no WordPress
 * and no WooCommerce in a Unit run. They record their meta writes and their
 * saves, so «registers the identity» and «saves once» are read off the double
 * rather than off an expectation.
 *
 * The items always go through `CatalogPushItem::from_array()`, never built by
 * hand: an item this test could build but the frozen factory would reject is an
 * item that never reaches the resolver in production.
 */
final class ProductResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Article doubles of the shop, keyed by identifier.
	 *
	 * @var array<int, MockInterface>
	 */
	private array $articles = array();

	/**
	 * Meta of each article double, keyed by identifier and then by meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * How many times each article double has been saved, keyed by identifier.
	 *
	 * @var array<int, int>
	 */
	private array $saves = array();

	/**
	 * Identifiers the identity lookup answers with.
	 *
	 * @var list<int>
	 */
	private array $identity_matches = array();

	/**
	 * Identifier the reference search answers with; 0 means «no article».
	 *
	 * @var int
	 */
	private int $reference_match = 0;

	/**
	 * Whether the reference of the shop is unique.
	 *
	 * @var bool
	 */
	private bool $reference_is_unique = true;

	/**
	 * Arguments every identity lookup was asked with, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $identity_queries = array();

	/**
	 * Arguments the uniqueness question was asked with, in order.
	 *
	 * @var list<array{int, string}>
	 */
	private array $uniqueness_questions = array();

	/**
	 * References the reference search was asked about, in order.
	 *
	 * @var list<string>
	 */
	private array $reference_searches = array();

	/**
	 * How many times each doubled WooCommerce function was called.
	 *
	 * @var array<string, int>
	 */
	private array $calls = array();

	/**
	 * Installs the doubles of the four WooCommerce functions the class may use.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();

		$this->articles             = array();
		$this->meta                 = array();
		$this->saves                = array();
		$this->identity_matches     = array();
		$this->reference_match      = 0;
		$this->reference_is_unique  = true;
		$this->identity_queries     = array();
		$this->uniqueness_questions = array();
		$this->reference_searches   = array();
		$this->calls                = array(
			'wc_get_products'           => 0,
			'wc_get_product_id_by_sku'  => 0,
			'wc_product_has_unique_sku' => 0,
			'wc_get_product'            => 0,
			'wc_get_product_types'      => 0,
		);

		// A shop with a product type installed by another plugin, so that the
		// types are read from the shop and not listed by the resolver.
		Functions\when( 'wc_get_product_types' )->alias(
			function (): array {
				++$this->calls['wc_get_product_types'];

				return array(
					'simple'       => 'Simple product',
					'grouped'      => 'Grouped product',
					'external'     => 'External/Affiliate product',
					'variable'     => 'Variable product',
					'subscription' => 'Subscription',
				);
			}
		);

		Functions\when( 'wc_get_products' )->alias(
			function ( array $args ): array {
				++$this->calls['wc_get_products'];

				$this->identity_queries[] = $args;

				return array_slice( $this->identity_matches, 0, (int) ( $args['limit'] ?? 1 ) );
			}
		);

		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function ( string $sku ): int {
				++$this->calls['wc_get_product_id_by_sku'];

				$this->reference_searches[] = $sku;

				return $this->reference_match;
			}
		);

		Functions\when( 'wc_product_has_unique_sku' )->alias(
			function ( int $product_id, string $sku ): bool {
				++$this->calls['wc_product_has_unique_sku'];

				$this->uniqueness_questions[] = array( $product_id, $sku );

				return $this->reference_is_unique;
			}
		);

		Functions\when( 'wc_get_product' )->alias(
			function ( int $id ) {
				++$this->calls['wc_get_product'];

				return $this->articles[ $id ] ?? false;
			}
		);
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
	 * An article whose identity is registered resolves without any search.
	 *
	 * «Without searching» is the whole point of registering the identity, and
	 * it is asserted as the absence of the call: an article found by identity
	 * that still asked the reference search would pay the expensive half of the
	 * resolution on every push, for ever.
	 *
	 * @return void
	 */
	public function test_a_registered_identity_resolves_without_searching_by_reference(): void {
		$this->article( 77, array( Constants::REMOTE_PRODUCT_ID_META_KEY => 'PRD-8841' ) );
		$this->identity_matches = array( 77 );

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertSame( $this->articles[77], $resolved );
		self::assertSame( '', $resolver->last_rejection(), 'A resolved article leaves no rejection behind.' );
		self::assertSame( 0, $this->calls['wc_get_product_id_by_sku'], 'The reference was never searched.' );
		self::assertSame( 0, $this->calls['wc_product_has_unique_sku'], 'Nor was its uniqueness asked about.' );
		self::assertSame( 0, $this->saves[77], 'An identity that is already registered is not written again.' );
	}

	/**
	 * The identity wins over a reference that points somewhere else.
	 *
	 * A merchant who renames a SKU has renamed a reference, not swapped the
	 * article; and the old reference can end up on a different article. The
	 * link that survives that is the registered identity, so it is the one that
	 * is asked first.
	 *
	 * @return void
	 */
	public function test_the_registered_identity_wins_over_a_reference_pointing_elsewhere(): void {
		$this->article( 77 );
		$this->article( 99 );
		$this->identity_matches = array( 77 );
		$this->reference_match  = 99;

		$resolved = ( new ProductResolver() )->resolve(
			$this->identified( 'PRD-8841', 'REF-001' )
		);

		self::assertSame( $this->articles[77], $resolved );
	}

	/**
	 * The identity lookup asks for variations and for the frozen meta key.
	 *
	 * Measured against WooCommerce 8.9.5: the default type set of
	 * `WC_Product_Query` has no `variation` in it, and a variation is exactly
	 * the kind of article a catalogue push addresses - it is what carries its
	 * own reference, price and stock. A lookup that kept the default would
	 * silently fail to find half of the catalogue of a shop with variable
	 * products.
	 *
	 * @return void
	 */
	public function test_the_identity_lookup_asks_for_variations_and_for_the_frozen_meta_key(): void {
		$this->article( 77 );
		$this->identity_matches = array( 77 );

		( new ProductResolver() )->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertCount( 1, $this->identity_queries );

		$query = $this->identity_queries[0];

		self::assertSame( Constants::REMOTE_PRODUCT_ID_META_KEY, $query['meta_key'] ?? null );
		self::assertSame( 'PRD-8841', $query['meta_value'] ?? null );
		self::assertSame( 'ids', $query['return'] ?? null, 'The objects of an ambiguous answer are never built.' );
		self::assertSame( 2, $query['limit'] ?? null, 'Two candidates answer "is there more than one".' );
		self::assertContains( 'variation', $query['type'] ?? array() );
		self::assertContains( 'simple', $query['type'] ?? array() );
		self::assertContains(
			'subscription',
			$query['type'] ?? array(),
			'The types are read from the shop, so a type installed by another plugin is searched too.'
		);
	}

	/**
	 * An identity no article carries is not a failure: the reference is asked.
	 *
	 * The sender can legitimately carry an identity this shop has never seen -
	 * a shop restored from a backup, an article deleted and written again - and
	 * that is precisely what the reference is for.
	 *
	 * @return void
	 */
	public function test_an_identity_that_no_article_carries_falls_through_to_the_reference(): void {
		$this->article( 99 );
		$this->identity_matches = array();
		$this->reference_match  = 99;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertSame( $this->articles[99], $resolved );
		self::assertSame( '', $resolver->last_rejection() );
		self::assertSame( 1, $this->calls['wc_get_product_id_by_sku'] );
	}

	/**
	 * A reference that matches exactly one article resolves it and records the
	 * identity on it.
	 *
	 * @return void
	 */
	public function test_a_unique_reference_resolves_and_registers_the_identity(): void {
		$this->article( 99 );
		$this->reference_match = 99;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertSame( $this->articles[99], $resolved );
		self::assertSame( '', $resolver->last_rejection() );
		self::assertSame(
			'PRD-8841',
			$this->meta[99][ Constants::REMOTE_PRODUCT_ID_META_KEY ] ?? null,
			'The identity is written so that the next push does not search again.'
		);
	}

	/**
	 * The registration writes that one meta key and saves the article once.
	 *
	 * One save, and only the plugin's own key: the resolver writes an index
	 * entry, not catalogue data, and the fields of the push belong to the three
	 * writers that come after it.
	 *
	 * @return void
	 */
	public function test_the_registration_writes_the_frozen_meta_key_and_saves_once(): void {
		$this->article( 99 );
		$this->reference_match = 99;

		( new ProductResolver() )->resolve(
			$this->item(
				array(
					'remote_id' => 'PRD-8841',
					'sku'       => 'REF-001',
					'name'      => 'A name the resolver must not write',
					'price'     => '19.90',
				)
			)
		);

		self::assertSame( array( Constants::REMOTE_PRODUCT_ID_META_KEY ), array_keys( $this->meta[99] ) );
		self::assertSame( 1, $this->saves[99] );
	}

	/**
	 * A push that declares no identity resolves by reference and records
	 * nothing.
	 *
	 * There is nothing to record: the identity is the sender's, and inventing
	 * one here would write a value no push will ever carry.
	 *
	 * @return void
	 */
	public function test_a_push_without_an_identity_resolves_by_reference_and_records_nothing(): void {
		$this->article( 99 );
		$this->reference_match = 99;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->unidentified( 'REF-001' ) );

		self::assertSame( $this->articles[99], $resolved );
		self::assertSame( array(), $this->meta[99] );
		self::assertSame( 0, $this->saves[99] );
		self::assertSame( 0, $this->calls['wc_get_products'], 'With no identity declared there is nothing to look up.' );
	}

	/**
	 * An identity already written with the same value is not written again.
	 *
	 * This is the article whose identity lookup failed for a reason of the shop
	 * - a filter, an index being rebuilt - and was then resolved by reference.
	 * Writing the same value again would pay a save, and a save of a product
	 * fires every automation the merchant installed.
	 *
	 * @return void
	 */
	public function test_an_identity_already_registered_on_the_article_is_not_written_again(): void {
		$this->article( 99, array( Constants::REMOTE_PRODUCT_ID_META_KEY => 'PRD-8841' ) );
		$this->reference_match = 99;

		$resolved = ( new ProductResolver() )->resolve(
			$this->identified( 'PRD-8841', 'REF-001' )
		);

		self::assertSame( $this->articles[99], $resolved );
		self::assertSame( 0, $this->saves[99] );
	}

	/**
	 * An article carrying a DIFFERENT identity has it overwritten.
	 *
	 * The reference resolved to this article and the push named another
	 * identity for it: the index entry is stale and the push is the newer fact.
	 *
	 * @return void
	 */
	public function test_an_article_carrying_a_different_identity_has_it_rewritten(): void {
		$this->article( 99, array( Constants::REMOTE_PRODUCT_ID_META_KEY => 'PRD-0001' ) );
		$this->reference_match = 99;

		( new ProductResolver() )->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertSame( 'PRD-8841', $this->meta[99][ Constants::REMOTE_PRODUCT_ID_META_KEY ] ?? null );
		self::assertSame( 1, $this->saves[99] );
	}

	/**
	 * A reference that matches no article is rejected as not found.
	 *
	 * @return void
	 */
	public function test_a_reference_that_matches_no_article_is_rejected_as_not_found(): void {
		$this->reference_match = 0;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->unidentified( 'REF-404' ) );

		self::assertNull( $resolved );
		self::assertSame( CatalogPushOutcome::REASON_NOT_FOUND, $resolver->last_rejection() );
	}

	/**
	 * A reference that matches no article creates nothing.
	 *
	 * Creating belongs to the publishing mode of the sending platform and
	 * travels in the push. A receiver that created on its own would turn one
	 * mis-addressed batch into a duplicated catalogue in a third party's shop.
	 *
	 * @return void
	 */
	public function test_a_reference_that_matches_no_article_creates_nothing(): void {
		$this->reference_match = 0;

		( new ProductResolver() )->resolve( $this->identified( 'PRD-8841', 'REF-404' ) );

		self::assertSame( array(), $this->articles, 'The shop gained no article.' );
		self::assertSame( 0, $this->calls['wc_get_product'], 'Nothing was even built.' );
		self::assertSame( array(), $this->meta );
		self::assertSame( array(), $this->saves );
	}

	/**
	 * A reference two articles share is rejected as ambiguous.
	 *
	 * @return void
	 */
	public function test_a_duplicated_reference_is_rejected_as_ambiguous(): void {
		$this->article( 77 );
		$this->article( 99 );
		$this->reference_match     = 77;
		$this->reference_is_unique = false;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->unidentified( 'REF-001' ) );

		self::assertNull( $resolved );
		self::assertSame( CatalogPushOutcome::REASON_AMBIGUOUS, $resolver->last_rejection() );
	}

	/**
	 * A duplicated reference leaves both articles untouched.
	 *
	 * Neither is built, so neither is read, written or saved: choosing one is
	 * choosing blindly which of the merchant's products changes price, and not
	 * choosing has to be visible in what the shop did, not only in what came
	 * back.
	 *
	 * @return void
	 */
	public function test_a_duplicated_reference_touches_neither_of_the_two_articles(): void {
		$this->article( 77 );
		$this->article( 99 );
		$this->reference_match     = 77;
		$this->reference_is_unique = false;

		( new ProductResolver() )->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertSame( 0, $this->calls['wc_get_product'] );
		self::assertSame( array(), $this->meta[77] );
		self::assertSame( array(), $this->meta[99] );
		self::assertSame( 0, $this->saves[77] );
		self::assertSame( 0, $this->saves[99] );
	}

	/**
	 * An identity two articles carry is rejected as ambiguous too.
	 *
	 * This plugin cannot produce that state, but a restored backup or a
	 * duplicated article can. The harm of guessing is the same one, so the
	 * answer is the same one - and the reference is NOT tried afterwards: a
	 * conflict is not an absence.
	 *
	 * @return void
	 */
	public function test_an_identity_carried_by_two_articles_is_rejected_as_ambiguous(): void {
		$this->article( 77 );
		$this->article( 99 );
		$this->identity_matches = array( 77, 99 );
		$this->reference_match  = 77;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertNull( $resolved );
		self::assertSame( CatalogPushOutcome::REASON_AMBIGUOUS, $resolver->last_rejection() );
		self::assertSame( 0, $this->calls['wc_get_product_id_by_sku'], 'A conflict is not an absence.' );
		self::assertSame( 0, $this->calls['wc_get_product'] );
	}

	/**
	 * A reference whose article cannot be built is rejected as not found.
	 *
	 * A row of a lookup table can outlive the article it points at; what the
	 * receiver may not do is write to something that is not an article.
	 *
	 * @return void
	 */
	public function test_a_reference_whose_article_cannot_be_built_is_rejected_as_not_found(): void {
		$this->reference_match = 99;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertNull( $resolved );
		self::assertSame( CatalogPushOutcome::REASON_NOT_FOUND, $resolver->last_rejection() );
		self::assertSame( 1, $this->calls['wc_get_product'] );
	}

	/**
	 * A registered identity whose article cannot be built does not fall through
	 * to the reference.
	 *
	 * The identity IS registered in this shop. Resolving by reference would
	 * apply the push to a different article while the identity stayed on the
	 * broken one, and the shop would end up with two articles claiming to be
	 * the same remote one - the ambiguity this class exists to avoid, created
	 * by this class.
	 *
	 * @return void
	 */
	public function test_a_registered_identity_whose_article_cannot_be_built_does_not_fall_through(): void {
		$this->article( 99 );
		$this->identity_matches = array( 77 );
		$this->reference_match  = 99;

		$resolver = new ProductResolver();

		$resolved = $resolver->resolve( $this->identified( 'PRD-8841', 'REF-001' ) );

		self::assertNull( $resolved );
		self::assertSame( CatalogPushOutcome::REASON_NOT_FOUND, $resolver->last_rejection() );
		self::assertSame( 0, $this->calls['wc_get_product_id_by_sku'] );
		self::assertSame( array(), $this->meta[99], 'The article the reference points at was left alone.' );
	}

	/**
	 * The reference search is asked about the reference the push declared.
	 *
	 * @return void
	 */
	public function test_the_reference_search_is_asked_about_the_reference_of_the_push(): void {
		$this->article( 99 );
		$this->reference_match = 99;

		( new ProductResolver() )->resolve( $this->unidentified( 'REF-001' ) );

		self::assertSame( array( 'REF-001' ), $this->reference_searches );
	}

	/**
	 * The uniqueness is asked about the article the search found, and about the
	 * same reference.
	 *
	 * That pair is what makes the question «is there ANOTHER article with this
	 * reference» rather than «does this reference exist», which the search has
	 * just answered.
	 *
	 * @return void
	 */
	public function test_the_uniqueness_is_asked_about_the_article_that_was_found(): void {
		$this->article( 99 );
		$this->reference_match = 99;

		( new ProductResolver() )->resolve( $this->unidentified( 'REF-001' ) );

		self::assertSame( array( array( 99, 'REF-001' ) ), $this->uniqueness_questions );
	}

	/**
	 * The rejection of one item does not leak into the next one.
	 *
	 * The receiver walks a batch with ONE resolver and writes one outcome per
	 * article. A reason left over from a previous item would label a different
	 * article's rejection with somebody else's cause - or, worse, label an
	 * article that resolved.
	 *
	 * @return void
	 */
	public function test_the_rejection_of_one_item_does_not_leak_into_the_next(): void {
		$this->article( 99 );
		$resolver = new ProductResolver();

		$this->reference_match = 0;
		$resolver->resolve( $this->unidentified( 'REF-404' ) );

		self::assertSame( CatalogPushOutcome::REASON_NOT_FOUND, $resolver->last_rejection() );

		$this->reference_match = 99;
		$resolved              = $resolver->resolve( $this->unidentified( 'REF-001' ) );

		self::assertSame( $this->articles[99], $resolved );
		self::assertSame( '', $resolver->last_rejection() );

		$this->reference_match     = 77;
		$this->reference_is_unique = false;

		self::assertNull( $resolver->resolve( $this->unidentified( 'REF-002' ) ) );
		self::assertSame( CatalogPushOutcome::REASON_AMBIGUOUS, $resolver->last_rejection() );
	}

	/**
	 * Both rejections are values of the frozen outcome contract.
	 *
	 * The receiver hands whatever comes out of here straight to
	 * `CatalogPushOutcome::rejected()`, which THROWS on a reason outside its
	 * five. A reason invented here would reach the sender as a 500 on an
	 * article that simply was not found.
	 *
	 * @return void
	 */
	public function test_every_rejection_is_one_of_the_frozen_reasons(): void {
		$resolver = new ProductResolver();

		$this->reference_match = 0;
		$resolver->resolve( $this->unidentified( 'REF-404' ) );
		$not_found = $resolver->last_rejection();

		$this->article( 77 );
		$this->reference_match     = 77;
		$this->reference_is_unique = false;
		$resolver->resolve( $this->unidentified( 'REF-001' ) );
		$ambiguous = $resolver->last_rejection();

		self::assertContains( $not_found, CatalogPushOutcome::VALID_REASONS );
		self::assertContains( $ambiguous, CatalogPushOutcome::VALID_REASONS );
		self::assertNotSame( $not_found, $ambiguous, 'The two failures are told apart, which is why the reason exists.' );

		// Both are readable by the outcome the receiver composes.
		self::assertSame(
			$not_found,
			CatalogPushOutcome::rejected( 'REF-404', $not_found )->reason
		);
		self::assertSame(
			$ambiguous,
			CatalogPushOutcome::rejected( 'REF-001', $ambiguous )->reason
		);
	}

	/**
	 * A resolver that has resolved nothing yet reports no rejection.
	 *
	 * @return void
	 */
	public function test_a_resolver_that_has_not_resolved_anything_reports_no_rejection(): void {
		self::assertSame( '', ( new ProductResolver() )->last_rejection() );
	}

	/**
	 * Registers an article double in the shop.
	 *
	 * The double behaves like an article rather than recording calls: meta
	 * written on it is read back out of it, and its saves are counted.
	 *
	 * @param int                  $id            Identifier of the article.
	 * @param array<string, mixed> $existing_meta Meta the article already carries.
	 * @return MockInterface
	 */
	private function article( int $id, array $existing_meta = array() ): MockInterface {
		$this->meta[ $id ]  = $existing_meta;
		$this->saves[ $id ] = 0;

		$product = Mockery::mock( 'WC_Product' );

		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( string $key ) use ( $id ) {
				return $this->meta[ $id ][ $key ] ?? '';
			}
		);

		$product->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( $id ): void {
				$this->meta[ $id ][ $key ] = $value;
			}
		);

		$product->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $id ): int {
				++$this->saves[ $id ];

				return $id;
			}
		);

		$product->shouldReceive( 'get_id' )->andReturn( $id );

		// The resolver resolves; it does not write catalogue data. These are
		// the calls that would mean it had started doing somebody else's job.
		$product->shouldNotReceive( 'set_regular_price' );
		$product->shouldNotReceive( 'set_stock_quantity' );
		$product->shouldNotReceive( 'set_name' );
		$product->shouldNotReceive( 'set_sku' );

		$this->articles[ $id ] = $product;

		return $product;
	}

	/**
	 * Builds an item through the frozen factory, never by hand.
	 *
	 * @param array<string, mixed> $document Item as it would arrive in a batch.
	 * @return CatalogPushItem
	 */
	private function item( array $document ): CatalogPushItem {
		$item = CatalogPushItem::from_array( $document );

		self::assertNotNull( $item, 'The body of this case has to be a readable item.' );

		return $item;
	}

	/**
	 * An item that declares both the identity and the reference.
	 *
	 * The keys come from the frozen contract rather than from a literal, so a
	 * renamed field of the wire breaks these cases instead of leaving them
	 * exercising a shape nothing sends.
	 *
	 * @param string $remote_id Identity the platform uses for the article.
	 * @param string $sku       Reference the push declares.
	 * @return CatalogPushItem
	 */
	private function identified( string $remote_id, string $sku ): CatalogPushItem {
		return $this->item(
			array(
				CatalogPushItem::FIELD_REMOTE_ID => $remote_id,
				CatalogPushItem::FIELD_SKU       => $sku,
			)
		);
	}

	/**
	 * An item that declares only the reference, which the contract allows.
	 *
	 * @param string $sku Reference the push declares.
	 * @return CatalogPushItem
	 */
	private function unidentified( string $sku ): CatalogPushItem {
		return $this->item( array( CatalogPushItem::FIELD_SKU => $sku ) );
	}
}
