<?php
/**
 * Unit cover for the tax identifier field of the block checkout.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Checkout\BlockCheckoutTaxIdField;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\TaxIdField;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * What this file protects.
 *
 * The block checkout is the half of the problem this plugin exists for: a
 * plugin hooked only to the classic mechanism renders NOTHING here and says
 * nothing about it. So what is pinned are the things that fail in silence - the
 * boot point the field is declared on, the identity, group and type it is
 * declared with, and the copy into the key the connector actually reads - plus
 * the rule, whose interesting case is that an EMPTY value must keep passing.
 *
 * `WP_Error` and `WC_Order` are doubled with Mockery rather than with stub
 * classes declared here, so that the class names stay whatever the rest of the
 * suite makes of them when every phase's tests run in one process.
 */
final class BlockCheckoutTaxIdFieldTest extends TestCase {

	/**
	 * Hook registrations captured from the code under test.
	 *
	 * @var list<array{hook: string, callback: mixed, priority: int, args: int}>
	 */
	private array $hooks = array();

	/**
	 * Sets up the doubles of the WordPress functions used here.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->hooks = array();

		$capture = function ( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
			$this->hooks[] = array(
				'hook'     => $hook,
				'callback' => $callback,
				'priority' => $priority,
				'args'     => $args,
			);

			return true;
		};

		Functions\when( 'add_action' )->alias( $capture );
		Functions\when( 'add_filter' )->alias( $capture );
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

	/**
	 * The field carries the identifier, the group and the type frozen in the
	 * contract.
	 *
	 * The group is checked through the meta key rather than through a `group`
	 * option, because the interface has none: the location decides the group,
	 * and only `address` yields the billing one. Comparing the rebuilt key with
	 * the frozen constant is what catches a change of location or of identifier
	 * that would leave the value somewhere nothing reads.
	 *
	 * @return void
	 */
	public function test_the_field_is_declared_with_its_identifier_group_and_type(): void {
		$definition = BlockCheckoutTaxIdField::definition();

		$this->assertSame( TaxIdField::FIELD_ID, $definition['id'] );
		$this->assertSame( TaxIdField::TYPE, $definition['type'] );
		$this->assertSame( 'address', $definition['location'] );
		$this->assertSame(
			Constants::ADDITIONAL_FIELD_META_KEY,
			BlockCheckoutTaxIdField::persisted_meta_key(),
			'The interface would persist the field under a key nothing downstream expects.'
		);
		$this->assertSame(
			'_wc_' . TaxIdField::GROUP . '/' . TaxIdField::FIELD_ID,
			BlockCheckoutTaxIdField::persisted_meta_key()
		);
	}

	/**
	 * The field is optional, and it says so out loud.
	 *
	 * @return void
	 */
	public function test_the_field_is_not_marked_required(): void {
		$definition = BlockCheckoutTaxIdField::definition();

		$this->assertArrayHasKey( 'required', $definition );
		$this->assertFalse( $definition['required'] );
	}

	/**
	 * The copy and the length come from the contract, not from a second copy
	 * written here.
	 *
	 * @return void
	 */
	public function test_the_copy_comes_from_the_contract(): void {
		$definition = BlockCheckoutTaxIdField::definition();

		$this->assertSame( TaxIdField::LABEL, $definition['label'] );
		$this->assertSame( TaxIdField::OPTIONAL_LABEL, $definition['optionalLabel'] );
		$this->assertSame( TaxIdField::HELP_TEXT, $definition['attributes']['title'] );
		$this->assertSame( TaxIdField::MAX_LENGTH, $definition['attributes']['maxLength'] );
	}

	/**
	 * The declaration hangs off the boot point the interface requires, and the
	 * three hooks that check, normalise and copy the value hang off theirs with
	 * the argument counts the interface passes.
	 *
	 * An argument count that is too low is the quiet half of this: the callback
	 * would receive fewer arguments and read the wrong ones, with no error at
	 * all - `store_value` would see the group where it expects the object.
	 *
	 * @return void
	 */
	public function test_it_hangs_off_the_boot_point_the_interface_requires(): void {
		BlockCheckoutTaxIdField::register();

		$this->assertSame(
			array(
				array( 'woocommerce_init', array( BlockCheckoutTaxIdField::class, 'register_field' ), 10, 1 ),
				array( 'woocommerce_sanitize_additional_field', array( BlockCheckoutTaxIdField::class, 'sanitize_field' ), 10, 2 ),
				array( 'woocommerce_validate_additional_field', array( BlockCheckoutTaxIdField::class, 'validate_field' ), 10, 3 ),
				array( 'woocommerce_set_additional_field_value', array( BlockCheckoutTaxIdField::class, 'store_value' ), 10, 4 ),
			),
			array_map(
				static fn( array $hook ): array => array( $hook['hook'], $hook['callback'], $hook['priority'], $hook['args'] ),
				$this->hooks
			)
		);
	}

	/**
	 * Declaring the field hands the interface exactly the definition above.
	 *
	 * @return void
	 */
	public function test_declaring_the_field_hands_the_interface_the_definition(): void {
		$declared = array();

		Functions\when( 'woocommerce_register_additional_checkout_field' )->alias(
			static function ( array $definition ) use ( &$declared ): void {
				$declared[] = $definition;
			}
		);

		BlockCheckoutTaxIdField::register_field();

		$this->assertSame( array( BlockCheckoutTaxIdField::definition() ), $declared );
	}

	/**
	 * A well-formed identifier passes.
	 *
	 * @return void
	 */
	public function test_the_rule_accepts_a_valid_tax_identifier(): void {
		$this->assertSame( array(), $this->validate( '12345678Z' ) );
	}

	/**
	 * A value whose control character does not match its digits is rejected,
	 * with the message that says what was expected.
	 *
	 * The digits are the ones of the accepted case above, so the only thing that
	 * changed is the control letter: that is what makes this a test of the
	 * control character and not of the shape.
	 *
	 * @return void
	 */
	public function test_the_rule_rejects_a_wrong_control_character(): void {
		$errors = $this->validate( '12345678A' );

		$this->assertCount( 1, $errors );
		$this->assertSame( BlockCheckoutTaxIdField::VALIDATION_ERROR_CODE, $errors[0]['code'] );
		$this->assertSame( TaxIdField::ERROR_MESSAGE, $errors[0]['message'] );
	}

	/**
	 * An empty value passes, and so does one that is only separators.
	 *
	 * This is the assertion that keeps the field optional. Without a tax
	 * identifier the order invoices as a simplified invoice, which is a correct
	 * document; failing here would turn an optional field into a required one
	 * without anybody deciding it, and the first symptom would be buyers
	 * abandoning the checkout over a number they do not have.
	 *
	 * @return void
	 */
	public function test_the_rule_accepts_an_empty_value(): void {
		$this->assertSame( array(), $this->validate( '' ) );
		$this->assertSame( array(), $this->validate( '   ' ) );
	}

	/**
	 * A field belonging to somebody else is neither validated nor rewritten.
	 *
	 * Both directions matter: rejecting another plugin's value would break a
	 * checkout this plugin has nothing to do with, and normalising it would
	 * silently rewrite data that is not ours.
	 *
	 * @return void
	 */
	public function test_it_leaves_other_plugins_fields_alone(): void {
		$recorded = array();
		BlockCheckoutTaxIdField::validate_field( $this->error_bag( $recorded ), 'other-plugin/gov-id', 'nonsense' );

		$this->assertSame( array(), $recorded );
		$this->assertSame( ' 12.345.678-z ', BlockCheckoutTaxIdField::sanitize_field( ' 12.345.678-z ', 'other-plugin/gov-id' ) );
		$this->assertTrue( BlockCheckoutTaxIdField::sanitize_field( true, 'other-plugin/appointment' ) );
	}

	/**
	 * Sanitisation puts the value into the single shape the order stores, and it
	 * happens BEFORE validation, so the two see the same string.
	 *
	 * @return void
	 */
	public function test_sanitisation_normalises_the_submitted_value(): void {
		$this->assertSame( '12345678Z', BlockCheckoutTaxIdField::sanitize_field( ' 12.345.678-z ', TaxIdField::FIELD_ID ) );
	}

	/**
	 * The value the interface stored under its own key is copied to the key the
	 * connector reads.
	 *
	 * Without this the tax identifier is captured and delivered and then read by
	 * nobody, which invoices every B2B sale as a simplified invoice with nothing
	 * reporting it.
	 *
	 * @return void
	 */
	public function test_it_copies_the_billing_value_to_the_key_the_connector_reads(): void {
		$written = array();
		$order   = $this->order( $written );

		BlockCheckoutTaxIdField::store_value( TaxIdField::FIELD_ID, '12345678Z', TaxIdField::GROUP, $order );

		$this->assertSame( array( Constants::TAX_ID_META_KEY => '12345678Z' ), $written );
	}

	/**
	 * The shipping copy of the same field is ignored.
	 *
	 * The interface persists an address field once per group and fires this hook
	 * for each. Taking the shipping one would let a value typed into a delivery
	 * address - usually none at all - land on the order second and replace the
	 * one the buyer gave for billing.
	 *
	 * @return void
	 */
	public function test_it_ignores_the_shipping_copy_of_the_field(): void {
		$written = array();
		$order   = $this->order( $written );

		BlockCheckoutTaxIdField::store_value( TaxIdField::FIELD_ID, '', 'shipping', $order );

		$this->assertSame( array(), $written );
		$this->assertFalse( BlockCheckoutTaxIdField::handles( TaxIdField::FIELD_ID, 'shipping', $order ) );
	}

	/**
	 * Persistence that is not ours, or that is not an order, is ignored.
	 *
	 * The customer case is not hypothetical: the same hook fires when a saved
	 * address is updated from the account pages, and there is no order there to
	 * write to.
	 *
	 * @return void
	 */
	public function test_it_ignores_another_field_and_a_customer(): void {
		$written = array();

		$this->assertFalse( BlockCheckoutTaxIdField::handles( 'other-plugin/gov-id', TaxIdField::GROUP, $this->order( $written ) ) );
		$this->assertFalse( BlockCheckoutTaxIdField::handles( TaxIdField::FIELD_ID, TaxIdField::GROUP, Mockery::mock( 'WC_Customer' ) ) );

		BlockCheckoutTaxIdField::store_value( TaxIdField::FIELD_ID, '12345678Z', TaxIdField::GROUP, Mockery::mock( 'WC_Customer' ) );

		$this->assertSame( array(), $written );
	}

	/**
	 * Runs the value through the pair the interface runs it through - sanitise,
	 * then validate - and returns whatever landed in the error bag.
	 *
	 * Going through both is the point: validating a raw value the interface
	 * would never hand over would prove something that does not happen.
	 *
	 * @param string $value Value as the buyer typed it.
	 * @return list<array{code: string, message: string}> Errors recorded.
	 */
	private function validate( string $value ): array {
		$sanitised = BlockCheckoutTaxIdField::sanitize_field( $value, TaxIdField::FIELD_ID );

		$recorded = array();
		BlockCheckoutTaxIdField::validate_field( $this->error_bag( $recorded ), TaxIdField::FIELD_ID, $sanitised );

		return $recorded;
	}

	/**
	 * An error bag that records what is added to it.
	 *
	 * @param array $recorded Filled with the errors added, by reference.
	 * @return \WP_Error
	 */
	private function error_bag( array &$recorded ): \WP_Error {
		$errors = Mockery::mock( 'WP_Error' );
		$errors->shouldReceive( 'add' )->andReturnUsing(
			static function ( $code, $message = '' ) use ( &$recorded ): void {
				$recorded[] = array(
					'code'    => $code,
					'message' => $message,
				);
			}
		);

		return $errors;
	}

	/**
	 * An order that records the metadata written to it.
	 *
	 * Only the two calls the storage makes for a non-empty value are expected,
	 * so a storage that started writing somewhere else would fail here rather
	 * than pass quietly.
	 *
	 * @param array $written Filled with the metadata written, by reference.
	 * @return \WC_Order
	 */
	private function order( array &$written ): \WC_Order {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( $key, $value ) use ( &$written ): void {
				$written[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save' )->andReturn( 1 );

		return $order;
	}
}
