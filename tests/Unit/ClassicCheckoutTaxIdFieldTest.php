<?php
/**
 * Unit tests for the tax identifier field on the classic checkout.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Checkout\ClassicCheckoutTaxIdField;
use Factuarea\WooCommerce\Checkout\TaxIdOrderStorage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\TaxIdField;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * What the classic checkout field has to get right.
 *
 * Four things, and the order below is the order in which they fail in real
 * shops: it paints the field when nobody else does, it does NOT paint it when
 * somebody else does, it refuses a malformed identifier, and the value a buyer
 * typed ends up in the storage.
 *
 * Every WordPress function these tests touch is doubled, so the whole file runs
 * on a bare PHP with only composer installed. `WC_Order` is doubled with
 * Mockery, which declares the class when it is absent, so the type declarations
 * of the code under test are satisfied without loading WooCommerce.
 */
final class ClassicCheckoutTaxIdFieldTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A well-formed Spanish tax identifier, written the way a buyer types it -
	 * with a separator and in lower case - so that normalisation is exercised
	 * rather than assumed.
	 */
	private const SUBMITTED_IDENTIFIER = ' 1234 5678-z ';

	/**
	 * The same identifier once normalised.
	 */
	private const NORMALISED_IDENTIFIER = '12345678Z';

	/**
	 * Sets up the doubles of the WordPress functions the code under test calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'wp_unslash'          => null,
				'sanitize_text_field' => null,
			)
		);
	}

	/**
	 * Removes the doubles and the request left behind by each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST[ TaxIdField::INPUT_NAME ] );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The three hooks the classic checkout needs are the three it registers:
	 * one to paint the field, one to refuse a bad value and one to keep a good
	 * one.
	 *
	 * @return void
	 */
	public function test_it_registers_the_field_the_validation_and_the_persistence(): void {
		Filters\expectAdded( 'woocommerce_checkout_fields' )->once();
		Actions\expectAdded( 'woocommerce_checkout_process' )->once();
		Actions\expectAdded( 'woocommerce_checkout_create_order' )->once();

		( new ClassicCheckoutTaxIdField() )->register();
	}

	/**
	 * The field lands in the billing group, carrying the frozen copy, the
	 * frozen length limit and the frozen type - and it is not required.
	 *
	 * @return void
	 */
	public function test_it_adds_the_field_to_the_billing_group(): void {
		$fields = ( new ClassicCheckoutTaxIdField() )->add_field( $this->checkout_fields() );

		$this->assertArrayHasKey( TaxIdField::INPUT_NAME, $fields[ TaxIdField::GROUP ] );

		$field = $fields[ TaxIdField::GROUP ][ TaxIdField::INPUT_NAME ];

		$this->assertSame( TaxIdField::TYPE, $field['type'] );
		$this->assertSame( TaxIdField::LABEL, $field['label'] );
		$this->assertSame( TaxIdField::HELP_TEXT, $field['description'] );
		$this->assertSame( TaxIdField::PLACEHOLDER, $field['placeholder'] );
		$this->assertSame( TaxIdField::MAX_LENGTH, $field['maxlength'] );
		$this->assertFalse( $field['required'] );
	}

	/**
	 * When the additional checkout fields interface has already put the field
	 * on this form, the classic mechanism keeps its hands off it. The buyer
	 * gets one input, not two.
	 *
	 * @return void
	 */
	public function test_it_does_not_add_the_field_when_the_additional_fields_interface_already_painted_it(): void {
		$fields = $this->checkout_fields();

		$fields[ TaxIdField::GROUP ][ TaxIdField::FIELD_ID ] = array( 'label' => 'painted by somebody else' );

		$result = ( new ClassicCheckoutTaxIdField() )->add_field( $fields );

		$this->assertArrayNotHasKey( TaxIdField::INPUT_NAME, $result[ TaxIdField::GROUP ] );
	}

	/**
	 * The same, for the meta key that interface persists the field under: a
	 * form built from stored fields can carry that name instead.
	 *
	 * @return void
	 */
	public function test_it_does_not_add_the_field_when_the_persisted_meta_key_is_already_there(): void {
		$fields = $this->checkout_fields();

		$fields[ TaxIdField::GROUP ][ Constants::ADDITIONAL_FIELD_META_KEY ] = array( 'label' => 'painted by somebody else' );

		$result = ( new ClassicCheckoutTaxIdField() )->add_field( $fields );

		$this->assertArrayNotHasKey( TaxIdField::INPUT_NAME, $result[ TaxIdField::GROUP ] );
	}

	/**
	 * Running the filter twice adds one field, not two. WooCommerce asks for
	 * the checkout fields more than once per request, and a callback that
	 * appends blindly is how a form grows a second copy of everything.
	 *
	 * @return void
	 */
	public function test_it_adds_the_field_only_once_when_the_filter_runs_twice(): void {
		$field = new ClassicCheckoutTaxIdField();

		$once  = $field->add_field( $this->checkout_fields() );
		$twice = $field->add_field( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * When a WooCommerce release starts painting additional checkout fields on
	 * the classic checkout through a route that never touches this array, the
	 * declared threshold is what stops the second copy.
	 *
	 * @return void
	 */
	public function test_it_does_not_add_the_field_when_the_installed_version_paints_it_itself(): void {
		Filters\expectApplied( ClassicCheckoutTaxIdField::SELF_RENDERING_VERSION_FILTER )->andReturn( '99.0.0' );

		$result = ( new ClassicCheckoutTaxIdField( null, '99.1.0' ) )->add_field( $this->checkout_fields() );

		$this->assertArrayNotHasKey( TaxIdField::INPUT_NAME, $result[ TaxIdField::GROUP ] );
	}

	/**
	 * Below that threshold the field is painted, because nobody else is
	 * painting it. This is the direction that matters: a guard that suppresses
	 * the field on a version that does not paint it removes the tax identifier
	 * from the checkout altogether.
	 *
	 * @return void
	 */
	public function test_it_adds_the_field_when_the_installed_version_is_below_the_threshold(): void {
		Filters\expectApplied( ClassicCheckoutTaxIdField::SELF_RENDERING_VERSION_FILTER )->andReturn( '99.0.0' );

		$result = ( new ClassicCheckoutTaxIdField( null, '98.9.0' ) )->add_field( $this->checkout_fields() );

		$this->assertArrayHasKey( TaxIdField::INPUT_NAME, $result[ TaxIdField::GROUP ] );
	}

	/**
	 * No released WooCommerce paints additional checkout fields on the classic
	 * checkout, so the declared threshold is empty and no installed version
	 * suppresses the field on its own.
	 *
	 * @return void
	 */
	public function test_no_installed_version_suppresses_the_field_by_default(): void {
		$this->assertNull( ClassicCheckoutTaxIdField::SELF_RENDERING_WOOCOMMERCE_VERSION );

		$result = ( new ClassicCheckoutTaxIdField( null, '999.0.0' ) )->add_field( $this->checkout_fields() );

		$this->assertArrayHasKey( TaxIdField::INPUT_NAME, $result[ TaxIdField::GROUP ] );
	}

	/**
	 * A form without a billing group is left exactly as it came. Inventing the
	 * group would put the field on a page that is not rendering one.
	 *
	 * @return void
	 */
	public function test_it_leaves_the_form_alone_when_there_is_no_billing_group(): void {
		$fields = array( 'order' => array( 'order_comments' => array( 'label' => 'Order notes' ) ) );

		$this->assertSame( $fields, ( new ClassicCheckoutTaxIdField() )->add_field( $fields ) );
	}

	/**
	 * A malformed identifier is refused with the frozen message. The control
	 * letter below is wrong for those digits, which is the mistake a buyer
	 * actually makes.
	 *
	 * @return void
	 */
	public function test_the_validation_rejects_a_malformed_identifier(): void {
		$_POST[ TaxIdField::INPUT_NAME ] = '12345678A';

		Functions\expect( 'wc_add_notice' )->once()->with( TaxIdField::ERROR_MESSAGE, 'error' );

		( new ClassicCheckoutTaxIdField() )->validate_submitted_value();
	}

	/**
	 * A well-formed identifier passes without a word.
	 *
	 * @return void
	 */
	public function test_the_validation_accepts_a_well_formed_identifier(): void {
		$_POST[ TaxIdField::INPUT_NAME ] = self::SUBMITTED_IDENTIFIER;

		Functions\expect( 'wc_add_notice' )->never();

		( new ClassicCheckoutTaxIdField() )->validate_submitted_value();

		$this->assertTrue( TaxIdField::is_valid( self::SUBMITTED_IDENTIFIER ) );
	}

	/**
	 * An empty value passes: the field is optional and an order without a tax
	 * identifier invoices as a simplified invoice rather than failing.
	 *
	 * @return void
	 */
	public function test_the_validation_accepts_an_empty_value(): void {
		$_POST[ TaxIdField::INPUT_NAME ] = '';

		Functions\expect( 'wc_add_notice' )->never();

		( new ClassicCheckoutTaxIdField() )->validate_submitted_value();

		$this->assertTrue( TaxIdField::is_valid( '' ) );
	}

	/**
	 * A checkout that submits no such field at all is not a checkout with an
	 * invalid one. Nothing is said.
	 *
	 * @return void
	 */
	public function test_the_validation_says_nothing_when_the_field_was_not_submitted(): void {
		Functions\expect( 'wc_add_notice' )->never();

		( new ClassicCheckoutTaxIdField() )->validate_submitted_value();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading the request to assert that this test left nothing in it, not processing a form.
		$this->assertArrayNotHasKey( TaxIdField::INPUT_NAME, $_POST );
	}

	/**
	 * The value the buyer typed reaches the storage, normalised, and comes back
	 * out of it. This is the end of the classic half of the capture: everything
	 * downstream reads it from the order.
	 *
	 * @return void
	 */
	public function test_the_submitted_value_reaches_the_storage(): void {
		$order = $this->order_double();

		( new ClassicCheckoutTaxIdField() )->store_submitted_value(
			$order,
			array( TaxIdField::INPUT_NAME => self::SUBMITTED_IDENTIFIER )
		);

		$this->assertSame( self::NORMALISED_IDENTIFIER, ( new TaxIdOrderStorage() )->read( $order ) );
	}

	/**
	 * An empty submission touches nothing.
	 *
	 * It matters that this is silence and not a write of an empty value: when
	 * the guard suppressed our field, whoever painted it owns the value, and
	 * handing the storage an emptiness we never measured would let the classic
	 * mechanism erase what the other one captured.
	 *
	 * @return void
	 */
	public function test_an_empty_submission_never_reaches_the_storage(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		( new ClassicCheckoutTaxIdField() )->store_submitted_value(
			$order,
			array( TaxIdField::INPUT_NAME => '   ' )
		);

		$this->assertSame( '', ( new TaxIdOrderStorage() )->read( $this->order_double() ) );
	}

	/**
	 * A submission that is not a scalar - an array smuggled into the request -
	 * touches nothing either, rather than reaching a string parameter and
	 * fataling in the middle of a checkout.
	 *
	 * @return void
	 */
	public function test_a_non_scalar_submission_never_reaches_the_storage(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		( new ClassicCheckoutTaxIdField() )->store_submitted_value(
			$order,
			array( TaxIdField::INPUT_NAME => array( 'smuggled' ) )
		);

		$this->assertTrue( true, 'The order double would have failed the run had it been written to.' );
	}

	/**
	 * This class writes no order meta of its own.
	 *
	 * The meta keys of the tax identifier are known to exactly one class, and a
	 * second writer is how two keys start disagreeing about the same value. The
	 * source is read WITHOUT its comments, because this very file names those
	 * functions in prose to explain why it does not call them, and a plain text
	 * search would confuse naming with doing.
	 *
	 * @return void
	 */
	public function test_it_never_writes_order_meta_by_itself(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a file of this repository from a test that runs without WordPress loaded; wp_remote_get() is for remote URLs and does not exist here.
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Checkout/ClassicCheckoutTaxIdField.php'
		);

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$forbidden = array(
			'update_meta_data',
			'add_meta_data',
			'delete_meta_data',
			'update_post_meta',
			'add_post_meta',
			'delete_post_meta',
		);

		foreach ( $forbidden as $call ) {
			$this->assertStringNotContainsString(
				$call,
				$code,
				sprintf( 'The classic checkout field calls %s() itself instead of delegating to the storage.', $call )
			);
		}
	}

	/**
	 * A checkout fields array shaped the way WooCommerce shapes one, with the
	 * groups and a couple of the core billing fields, so that the code under
	 * test is given the same thing production gives it.
	 *
	 * @return array<string, array<string, mixed>> Checkout fields by group.
	 */
	private function checkout_fields(): array {
		return array(
			'billing'  => array(
				'billing_first_name' => array( 'label' => 'First name' ),
				'billing_company'    => array( 'label' => 'Company name' ),
				'billing_email'      => array( 'label' => 'Email address' ),
			),
			'shipping' => array(
				'shipping_first_name' => array( 'label' => 'First name' ),
			),
			'order'    => array(
				'order_comments' => array( 'label' => 'Order notes' ),
			),
		);
	}

	/**
	 * An order double that keeps the meta it is given, so that a value written
	 * through the storage can be read back through it.
	 *
	 * @return MockInterface The order double.
	 */
	private function order_double(): MockInterface {
		$meta = new \ArrayObject();

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( string $key, $value ) use ( $meta ): void {
				$meta[ $key ] = $value;
			}
		);

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		$order->shouldReceive( 'save' )->andReturn( 1 );
		$order->shouldIgnoreMissing();

		return $order;
	}
}
