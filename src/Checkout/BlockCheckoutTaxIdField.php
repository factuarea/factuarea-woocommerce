<?php
/**
 * Registration of the tax identifier field on the block checkout.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Checkout;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\TaxIdField;

/**
 * Puts the tax identifier field on the BLOCK checkout, through the additional
 * checkout fields interface, which is the only mechanism that renders anything
 * there at all.
 *
 * This is one half of the field. The classic checkout is the other half and is
 * a different mechanism in a different file; both read their identifier, their
 * copy and their rule from `Contract\TaxIdField`, so neither owns the wording.
 *
 * ---------------------------------------------------------------------------
 * THE BOOT POINT IS PART OF THE INTERFACE, AND MISSING IT IS SILENT
 * ---------------------------------------------------------------------------
 * The field has to be declared on `woocommerce_init`, or later. That is not a
 * convention: `woocommerce_register_additional_checkout_field()` is defined by
 * WooCommerce while it initialises, and the register it writes into is read
 * afterwards, when the Store API assembles the schema of the checkout.
 *
 * Both ways of getting it wrong produce NO ERROR:
 *
 *  - Declared TOO EARLY - from the main plugin file as it loads, on
 *    `plugins_loaded`, on `muplugins_loaded` - the function does not exist yet.
 *    Unguarded that is a fatal error on every request of the shop; guarded, as
 *    it is below, the guard returns and the field is simply never registered.
 *    Nothing is logged, nothing warns, and the checkout renders complete and
 *    correct WITHOUT the field.
 *  - Declared TOO LATE - on `wp_footer`, inside a template, from an admin-only
 *    branch - the register has already been read for this request. Same result,
 *    same silence.
 *
 * An absent field looks exactly like a field nobody filled in, so the failure
 * reaches production as "our B2B customers never enter their tax ID" months
 * later, with every one of those sales already invoiced as a simplified
 * invoice. That is the failure this whole package exists to remove, so the boot
 * point is written here, once, and the end-to-end check on a real block
 * checkout is what proves it holds.
 *
 * The `function_exists` guard is deliberate and it is what makes the early case
 * quiet rather than fatal. It is kept, because a merchant who activates this
 * plugin on a WooCommerce older than the announced minimum should get a shop
 * that still works, not a white screen. Refusing activation on an old
 * WooCommerce belongs to the main plugin file, which is where a merchant can be
 * TOLD; this class cannot tell anyone anything from inside a Store API request.
 *
 * ---------------------------------------------------------------------------
 * WHY `location` IS `address` AND WHAT THAT COSTS
 * ---------------------------------------------------------------------------
 * The interface offers three locations - `contact`, `address` and `order` - and
 * there is no `billing`. The persisted meta key is built from the location's
 * group, and only `address` produces the billing group: an `address` field is
 * stored under `_wc_billing/{namespace}/{key}` and, separately,
 * `_wc_shipping/{namespace}/{key}`. `contact` and `order` both store under
 * `_wc_other/...`.
 *
 * So `address` is not a preference. It is the only location that yields
 * `Constants::ADDITIONAL_FIELD_META_KEY`, which is the key the delivered body
 * carries and therefore the key the connector reads. Choosing `order` for the
 * tidier single field would put the value somewhere the connector never looks,
 * and the plugin would capture the tax identifier perfectly and deliver it
 * nowhere.
 *
 * The documented cost, measured against the interface's own documentation on
 * 2026-09-02 rather than assumed: an `address` field renders in BOTH the
 * billing and the shipping address forms, and it is NOT possible to have it in
 * only one of them. A buyer shipping to a different address therefore sees the
 * field twice and two values are collected. The consequences, written down so
 * that nothing downstream has to rediscover them:
 *
 *  - The BILLING value is the tax identifier. A tax identifier belongs to who
 *    is being billed, never to where the parcel goes, so whatever ends up in
 *    the shipping copy is not a second opinion - it is noise.
 *  - Whatever reads the value off an order reads the billing group,
 *    `TaxIdField::GROUP`, and never the shipping one. Reading "whichever is not
 *    empty" would invoice a company against an identifier typed into a delivery
 *    address.
 *
 * ---------------------------------------------------------------------------
 * THE VALUE IS COPIED TO OUR OWN KEY, AND THAT IS NOT BELT AND BRACES
 * ---------------------------------------------------------------------------
 * The interface persists the field itself, under
 * `Constants::ADDITIONAL_FIELD_META_KEY`. That key is NOT in the connector's
 * enumerated catalogue of tax identifier meta keys - the catalogue reserves
 * `Constants::TAX_ID_META_KEY` for this plugin and lists it first by
 * preference - so a value left only where the interface put it is captured
 * correctly, delivered correctly, and then read by nobody. Every B2B sale would
 * still invoice as a simplified invoice, which is the entire failure this
 * plugin exists to remove, and nothing anywhere would report it.
 *
 * So the value is copied, on `woocommerce_set_additional_field_value`, into the
 * key the catalogue names. The copy goes through `TaxIdOrderStorage`, which is
 * the ONE class allowed to know a tax identifier meta key; this one writes no
 * metadata of its own and holds no key of its own.
 *
 * That hook was measured on 2026-09-02 rather than assumed: it is declared as
 * `do_action( 'woocommerce_set_additional_field_value', $key, $value, $group,
 * $wc_object )` and arrived in WooCommerce 8.9.0, the same release as the
 * interface itself and the version this plugin announces as its minimum. It is
 * preferred over the Store API's order hook because it fires AT the moment of
 * persistence and hands over the value directly: no question of ordering
 * against whatever else writes the order, and no second place that has to know
 * the interface's meta key in order to read it back out.
 *
 * Three guards, and each one is a defect it prevents rather than a formality:
 *
 *  - THE GROUP MUST BE BILLING. The same field persists under the shipping
 *    group too (see above), and the hook fires once per group. Without this
 *    guard the shipping copy would run second and overwrite the billing one, so
 *    a buyer shipping to an office would invoice their company against whatever
 *    was typed into the delivery address - or against nothing, since the
 *    shipping copy is usually left empty.
 *  - THE OBJECT MUST BE AN ORDER. The hook also fires for `WC_Customer`, when a
 *    saved address is updated from the account pages. There is no order to
 *    write to there, and `TaxIdOrderStorage::write()` takes an order.
 *  - THE KEY MUST BE OURS. Other plugins register their own fields through the
 *    same interface and this hook carries all of them.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PLACEHOLDER IS NOT WIRED HERE
 * ---------------------------------------------------------------------------
 * `TaxIdField::PLACEHOLDER` is deliberately absent from the attributes below.
 * The interface accepts only an enumerated set of attributes - `maxLength`,
 * `readOnly`, `pattern`, `autocomplete`, `autocapitalize`, `title`, plus
 * anything prefixed `data-` or `aria-` - and drops the rest while complaining
 * that they "are not allowed". Passing a placeholder would add a notice to the
 * merchant's log on every checkout and render nothing. The classic checkout
 * builds its own input and does use it.
 *
 * The help text goes into `title` for the same reason: the registration array
 * has no description or help-text key, and `title` is the one allowed attribute
 * that shows the buyer what the value is for.
 */
final class BlockCheckoutTaxIdField {

	/**
	 * Boot point on which the interface requires the field to be declared.
	 *
	 * Declaring anywhere else leaves the field unregistered without producing an
	 * error of any kind; see the note on this class.
	 */
	public const REGISTRATION_HOOK = 'woocommerce_init';

	/**
	 * Location the field is registered in.
	 *
	 * `address` because it is the only one whose persisted group is billing,
	 * which is the group `Constants::ADDITIONAL_FIELD_META_KEY` names and the
	 * connector reads. See the note on this class.
	 */
	public const LOCATION = 'address';

	/**
	 * Name of the interface function that declares a field. Held as a constant
	 * because the guard and the call must not be able to disagree about it.
	 */
	public const REGISTRATION_FUNCTION = 'woocommerce_register_additional_checkout_field';

	/**
	 * Filter through which the interface sanitises a submitted value, before it
	 * validates it.
	 */
	public const SANITIZE_HOOK = 'woocommerce_sanitize_additional_field';

	/**
	 * Action through which the interface validates a single submitted field,
	 * after sanitisation.
	 */
	public const VALIDATION_HOOK = 'woocommerce_validate_additional_field';

	/**
	 * Action the interface fires as it persists a field, once per group, with
	 * the value and the object it is being written to.
	 */
	public const PERSISTENCE_HOOK = 'woocommerce_set_additional_field_value';

	/**
	 * Code carried by the validation error. It identifies the rejection in the
	 * error bag; what the buyer reads is `TaxIdField::ERROR_MESSAGE`.
	 */
	public const VALIDATION_ERROR_CODE = 'factuarea_invalid_tax_id';

	/**
	 * Hooks the field, its sanitisation and its validation.
	 *
	 * Only the DECLARATION of the field needs the boot point above. The other
	 * two run much later, during the Store API request that submits the
	 * checkout, so they are hooked here directly - what they need is to be
	 * registered BEFORE that request, which calling this from the loading plugin
	 * guarantees.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::REGISTRATION_HOOK, array( self::class, 'register_field' ) );
		add_filter( self::SANITIZE_HOOK, array( self::class, 'sanitize_field' ), 10, 2 );
		add_action( self::VALIDATION_HOOK, array( self::class, 'validate_field' ), 10, 3 );
		add_action( self::PERSISTENCE_HOOK, array( self::class, 'store_value' ), 10, 4 );
	}

	/**
	 * Declares the field. Runs on the boot point registered above, never
	 * directly.
	 *
	 * @return void
	 */
	public static function register_field(): void {
		if ( ! function_exists( self::REGISTRATION_FUNCTION ) ) {
			return;
		}

		call_user_func( self::REGISTRATION_FUNCTION, self::definition() );
	}

	/**
	 * The registration array, in one place so that the field the plugin
	 * declares and the field the tests read are the same object.
	 *
	 * `required` is written out as false rather than left to the default. The
	 * field being optional is a fiscal decision, not an oversight: a buyer
	 * without a tax identifier gets a simplified invoice, which is a valid
	 * document, so refusing the sale would trade a lost deduction for a lost
	 * sale. Stated explicitly, nobody has to guess whether it was considered.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'id'            => TaxIdField::FIELD_ID,
			'label'         => TaxIdField::LABEL,
			'optionalLabel' => TaxIdField::OPTIONAL_LABEL,
			'location'      => self::LOCATION,
			'type'          => TaxIdField::TYPE,
			'required'      => false,
			'attributes'    => array(
				'maxLength'      => TaxIdField::MAX_LENGTH,
				'title'          => TaxIdField::HELP_TEXT,
				'autocapitalize' => 'characters',
				'autocomplete'   => 'off',
			),
		);
	}

	/**
	 * Puts a submitted value into the one shape the rule and the order both
	 * expect: separators stripped, upper case.
	 *
	 * It runs before validation, so the value that is checked and the value that
	 * is stored are the same string. Without it, `12.345.678-Z` and `12345678z`
	 * would be two different tax identifiers on two orders of the same customer.
	 *
	 * Registered with `add_filter` rather than `add_action` because the interface
	 * consumes it with `apply_filters` and uses what it returns. The two
	 * functions are the same register underneath, so the alternative would also
	 * work; naming it for what it is keeps the next reader from believing the
	 * return value is ignored.
	 *
	 * Any field that is not ours is returned untouched, including values that
	 * are not strings: other plugins register checkboxes here.
	 *
	 * @param mixed  $field_value Submitted value.
	 * @param string $field_key   Identifier of the field being sanitised.
	 * @return mixed Sanitised value.
	 */
	public static function sanitize_field( $field_value, string $field_key ) {
		if ( TaxIdField::FIELD_ID !== $field_key || ! is_string( $field_value ) ) {
			return $field_value;
		}

		return TaxIdField::normalise( $field_value );
	}

	/**
	 * Rejects a value that is present and does not check out.
	 *
	 * AN EMPTY VALUE IS VALID AND MUST STAY VALID. `TaxIdField::is_valid()`
	 * returns true for it, so an order without a tax identifier passes through
	 * here untouched and invoices as a simplified invoice. Adding an error for
	 * the empty case would make an optional field required through the back
	 * door, and the first symptom would be buyers abandoning the checkout over a
	 * number they do not have.
	 *
	 * What is rejected is a value that is THERE and malformed - a wrong control
	 * letter, a mistyped digit - because that one produces a defective fiscal
	 * document that, under verifiable invoicing, cannot be deleted, only
	 * corrected with a second document. Catching it here costs the buyer one
	 * correction at the checkout.
	 *
	 * @param \WP_Error $errors      Error bag the interface reads afterwards.
	 * @param string    $field_key   Identifier of the field being validated.
	 * @param mixed     $field_value Sanitised value.
	 * @return void
	 */
	public static function validate_field( \WP_Error $errors, string $field_key, $field_value ): void {
		if ( TaxIdField::FIELD_ID !== $field_key ) {
			return;
		}

		if ( ! is_string( $field_value ) || TaxIdField::is_valid( $field_value ) ) {
			return;
		}

		$errors->add( self::VALIDATION_ERROR_CODE, TaxIdField::ERROR_MESSAGE );
	}

	/**
	 * Copies the value the interface just stored into the key the connector
	 * reads.
	 *
	 * See the note on this class for why the copy exists and what each guard
	 * prevents. Nothing is written here directly: `TaxIdOrderStorage` is the one
	 * class that knows a tax identifier meta key, and an empty value writes
	 * nothing at all, which it decides.
	 *
	 * @param string $key       Identifier of the field being persisted.
	 * @param mixed  $value     Value being persisted.
	 * @param string $group     Group being persisted: billing, shipping or other.
	 * @param mixed  $wc_object Object being written to: an order or a customer.
	 * @return void
	 */
	public static function store_value( string $key, $value, string $group, $wc_object ): void {
		if ( ! self::handles( $key, $group, $wc_object ) || ! is_string( $value ) ) {
			return;
		}

		( new TaxIdOrderStorage() )->write( $wc_object, $value );
	}

	/**
	 * Is this persistence ours, on the billing group, on an order?
	 *
	 * Kept apart from the copy itself so that the decision can be read - and
	 * checked - without a storage layer in the way. It is the whole of the
	 * correctness of `store_value`.
	 *
	 * @param string $key       Identifier of the field being persisted.
	 * @param string $group     Group being persisted.
	 * @param mixed  $wc_object Object being written to.
	 * @return bool
	 */
	public static function handles( string $key, string $group, $wc_object ): bool {
		return TaxIdField::FIELD_ID === $key
			&& TaxIdField::GROUP === $group
			&& $wc_object instanceof \WC_Order;
	}

	/**
	 * Meta key the interface persists this field under, rebuilt from the group
	 * and the identifier it is registered with.
	 *
	 * It exists so that the agreement between what is registered here and what
	 * `Constants::ADDITIONAL_FIELD_META_KEY` promises can be CHECKED instead of
	 * trusted. The two are written in different files by different phases, and
	 * the day the field identifier changes and the constant does not, the tax
	 * identifier stops being read by the connector with nothing failing
	 * anywhere.
	 *
	 * @return string
	 */
	public static function persisted_meta_key(): string {
		return sprintf( '_wc_%s/%s', TaxIdField::GROUP, TaxIdField::FIELD_ID );
	}

	/**
	 * Not instantiable: registering a field needs no state.
	 */
	private function __construct() {
	}
}
