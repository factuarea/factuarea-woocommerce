<?php
/**
 * The tax identifier field on the classic (shortcode) checkout.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Checkout;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\TaxIdField;

/**
 * Renders, validates and persists the tax identifier on the classic checkout.
 *
 * The classic checkout is the one a page serves through the
 * `[woocommerce_checkout]` shortcode, and it is a different world from the
 * block checkout: it builds its form from the `woocommerce_checkout_fields`
 * array, it validates on `woocommerce_checkout_process` and it hands the order
 * over on `woocommerce_checkout_create_order`. None of those three exist in the
 * block checkout, and the additional checkout fields interface that the block
 * checkout uses paints nothing here. That is why this plugin registers the same
 * field twice, by two mechanisms, instead of picking the modern one: a shop
 * still serving the shortcode checkout is exactly the shop that needs a tax
 * identifier field, and a plugin that only registers the modern one shows that
 * shop no field at all - with no error anywhere.
 *
 * Every string, the validation rule and the length limit come from
 * TaxIdField. Not one of them is written again here: the whole reason that
 * class exists is that the two checkouts would otherwise drift the day somebody
 * fixes the wording on one of them.
 *
 * ---------------------------------------------------------------------------
 * ONE FIELD, NOT TWO
 * ---------------------------------------------------------------------------
 * The dangerous shape of this class is not "the field is missing", which is
 * loud; it is "the field appears twice", which is quiet and produces two inputs
 * for one datum, of which the buyer fills whichever they see first.
 *
 * The guard against it has two signals, and they answer two different
 * questions:
 *
 *  1. IS IT ALREADY THERE? Measured, on the array WooCommerce hands us, every
 *     time. If any group already carries the field - under our own input name,
 *     under the additional-fields identifier, or under the meta key that
 *     interface persists to - somebody is already painting it and we do not.
 *     This signal is safe in both directions: it only ever suppresses a field
 *     that is demonstrably present.
 *
 *  2. DOES THE INSTALLED VERSION PAINT IT ITSELF? A version comparison against
 *     a declared threshold, for the case where a future WooCommerce paints
 *     additional fields on the classic checkout through some route that never
 *     touches this array.
 *
 * The threshold is null, and that is a MEASUREMENT rather than a placeholder.
 * Measured on 2026-09-02 against the WooCommerce sources: the service that
 * renders additional checkout fields outside the block checkout,
 * `Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsFrontend`, hooks
 * `woocommerce_order_details_after_customer_address`,
 * `woocommerce_order_details_after_customer_details`,
 * `woocommerce_my_account_after_my_address`,
 * `woocommerce_edit_account_form_fields`, `woocommerce_save_account_details`,
 * `woocommerce_address_to_edit` and `woocommerce_customer_save_address` - the
 * My Account forms and the order confirmation, and NOT the checkout form. No
 * released WooCommerce paints additional checkout fields on the classic
 * checkout, so there is no version to compare against yet and the honest value
 * is "none".
 *
 * Guessing a number here would be worse than leaving it empty, and the two
 * mistakes are not symmetric. Too high and the field is painted twice, which is
 * ugly. Too LOW and this plugin stops painting a field that WooCommerce is not
 * painting either, so the tax identifier silently disappears from the checkout
 * of every shop on that version - which is the precise failure this whole
 * package exists to remove. A guess is only safe in one direction, so no guess
 * is made.
 *
 * The threshold passes through a filter so that the day a WooCommerce release
 * does start painting it, a shop can say so without waiting for an update of
 * this plugin. That matters more here than it would in an application: this
 * plugin is DISTRIBUTED, and a merchant runs whichever version they last
 * updated to.
 */
final class ClassicCheckoutTaxIdField {

	/**
	 * Filter through which the version threshold below can be overridden.
	 *
	 * Two reasons, and neither is decoration. A shop that meets a WooCommerce
	 * release painting the field itself can suppress our copy on the spot
	 * instead of waiting for an update of a distributed plugin; and the branch
	 * that reads the threshold is reachable from a test, which a hard-coded
	 * constant that is null today would not be.
	 *
	 * The name is spelled out again at the call site rather than passed from
	 * here, because the coding standard cannot check the prefix of a hook name
	 * it cannot read. The two spellings are held together by a test that
	 * expects the filter to be applied under THIS constant, so a divergence
	 * fails the run rather than quietly creating a second hook nobody listens
	 * to.
	 */
	public const SELF_RENDERING_VERSION_FILTER = 'factuarea_wc_classic_additional_fields_version';

	/**
	 * First WooCommerce version that paints additional checkout fields on the
	 * classic checkout by itself, or null when no released version does.
	 *
	 * MEASURED on 2026-09-02, not declared: see the class docblock for what was
	 * read and where. Whoever changes this writes the measurement next to it.
	 *
	 * @var string|null
	 */
	public const SELF_RENDERING_WOOCOMMERCE_VERSION = null;

	/**
	 * Position of the field inside the billing group.
	 *
	 * Right after the company name, which sits at 30. A tax identifier is who
	 * is being billed, so it belongs beside the company rather than at the
	 * bottom of the form after the e-mail address, where an optional field is
	 * where optional fields go to be missed.
	 */
	public const FIELD_PRIORITY = 35;

	/**
	 * Priority at which the field is added to the checkout array.
	 *
	 * Late on purpose. The first guard signal reads the array it is given, so
	 * running at the default 10 would make it blind to anything added by a
	 * callback that runs after us - including a future WooCommerce one. Running
	 * late means the array we inspect is as complete as it is going to get.
	 */
	public const FILTER_PRIORITY = 100;

	/**
	 * Where the captured value is written. Resolved on first use so that
	 * nothing needs to be built for a checkout that submits no identifier.
	 *
	 * @var TaxIdOrderStorage|null
	 */
	private ?TaxIdOrderStorage $storage;

	/**
	 * Installed WooCommerce version, or null to read it from the environment.
	 *
	 * @var string|null
	 */
	private ?string $woocommerce_version;

	/**
	 * Constructor.
	 *
	 * @param TaxIdOrderStorage|null $storage             Where the captured value is
	 *                                                    written; built on first use
	 *                                                    when not given.
	 * @param string|null            $woocommerce_version Installed WooCommerce version;
	 *                                                    read from `WC_VERSION` when not
	 *                                                    given.
	 */
	public function __construct( ?TaxIdOrderStorage $storage = null, ?string $woocommerce_version = null ) {
		$this->storage             = $storage;
		$this->woocommerce_version = $woocommerce_version;
	}

	/**
	 * Hooks the three things the classic checkout needs: the field, its
	 * validation and its persistence.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_field' ), self::FILTER_PRIORITY, 1 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_submitted_value' ), 10, 0 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_submitted_value' ), 10, 2 );
	}

	/**
	 * Adds the tax identifier to the billing group of the classic checkout
	 * form, unless somebody is painting it already.
	 *
	 * @param array $fields Checkout fields, grouped as WooCommerce groups them.
	 * @return array The same fields, with the tax identifier added or not.
	 */
	public function add_field( array $fields ): array {
		if ( $this->woocommerce_paints_the_field_itself( $fields ) ) {
			return $fields;
		}

		if ( ! isset( $fields[ TaxIdField::GROUP ] ) || ! is_array( $fields[ TaxIdField::GROUP ] ) ) {
			return $fields;
		}

		$fields[ TaxIdField::GROUP ][ TaxIdField::INPUT_NAME ] = array(
			'type'         => TaxIdField::TYPE,
			'label'        => TaxIdField::LABEL,
			'description'  => TaxIdField::HELP_TEXT,
			'placeholder'  => TaxIdField::PLACEHOLDER,
			'maxlength'    => TaxIdField::MAX_LENGTH,
			'required'     => false,
			'priority'     => self::FIELD_PRIORITY,
			'class'        => array( 'form-row-wide' ),
			'autocomplete' => 'off',
		);

		return $fields;
	}

	/**
	 * Rejects a submitted identifier that is present and malformed.
	 *
	 * An absent or empty value is accepted, and that is the rule rather than an
	 * oversight: an order without a tax identifier invoices as a simplified
	 * invoice, which is a correct document, so refusing the sale would trade a
	 * lost deduction for a lost sale.
	 *
	 * This one reads the request rather than the assembled data because it runs
	 * before WooCommerce has assembled any: `WC_Checkout::process_checkout()`
	 * fires this hook with only the submitted request in hand.
	 *
	 * @return void
	 */
	public function validate_submitted_value(): void {
		$submitted = $this->submitted_value();

		if ( null === $submitted || TaxIdField::is_valid( $submitted ) ) {
			return;
		}

		wc_add_notice( TaxIdField::ERROR_MESSAGE, 'error' );
	}

	/**
	 * Hands the submitted identifier to the storage.
	 *
	 * This class writes no order meta of its own, on purpose: the meta keys of
	 * the tax identifier are known to exactly one class, and a second writer is
	 * how two keys start disagreeing about the same value.
	 *
	 * Unlike the validation above, this one reads the assembled data rather
	 * than the request: by the time WooCommerce creates the order it has
	 * already collected, unslashed and cleaned every declared field, and
	 * reading the raw request again would be reading a worse copy of it.
	 *
	 * Nothing is handed over for an empty value. When the guard suppressed our
	 * field, the classic form carries no value of ours at all, and this class
	 * has to stay silent rather than hand the storage an emptiness it never
	 * measured - the value in that case belongs to whoever painted the field.
	 *
	 * @param \WC_Order $order Order being created by the classic checkout.
	 * @param array     $data  Posted checkout data, already cleaned by WooCommerce.
	 * @return void
	 */
	public function store_submitted_value( \WC_Order $order, array $data ): void {
		$raw = $data[ TaxIdField::INPUT_NAME ] ?? '';

		if ( ! is_scalar( $raw ) ) {
			return;
		}

		$value = TaxIdField::normalise( (string) $raw );

		if ( '' === $value ) {
			return;
		}

		$this->storage()->write( $order, $value );
	}

	/**
	 * Is somebody else already painting the tax identifier on this form?
	 *
	 * @param array $fields Checkout fields, grouped as WooCommerce groups them.
	 * @return bool True when the field must not be added again.
	 */
	public function woocommerce_paints_the_field_itself( array $fields ): bool {
		foreach ( $fields as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( self::painted_field_keys() as $key ) {
				if ( array_key_exists( $key, $group ) ) {
					return true;
				}
			}
		}

		return $this->installed_version_paints_the_field();
	}

	/**
	 * The keys under which the tax identifier would already be present.
	 *
	 * Three of them, because the field can arrive under three names: ours, the
	 * identifier the additional checkout fields interface knows it by, and the
	 * meta key that interface persists it to. Enumerated one by one rather than
	 * matched by a prefix: a prefix would also swallow a future field of ours
	 * that has nothing to do with this one, and the guard would suppress a
	 * field nobody is painting.
	 *
	 * @return list<string>
	 */
	private static function painted_field_keys(): array {
		return array(
			TaxIdField::INPUT_NAME,
			TaxIdField::FIELD_ID,
			Constants::ADDITIONAL_FIELD_META_KEY,
		);
	}

	/**
	 * Does the installed WooCommerce version paint additional checkout fields
	 * on the classic checkout by itself?
	 *
	 * @return bool True when the installed version is at or above the declared
	 *              threshold.
	 */
	private function installed_version_paints_the_field(): bool {
		/**
		 * Filters the first WooCommerce version that paints additional checkout
		 * fields on the classic checkout by itself.
		 *
		 * @since 0.1.0
		 *
		 * @param string|null $version Version threshold, or null when no released
		 *                             WooCommerce version does.
		 */
		$threshold = apply_filters( 'factuarea_wc_classic_additional_fields_version', self::SELF_RENDERING_WOOCOMMERCE_VERSION );

		if ( ! is_string( $threshold ) || '' === $threshold ) {
			return false;
		}

		$installed = $this->installed_woocommerce_version();

		if ( null === $installed || '' === $installed ) {
			return false;
		}

		return version_compare( $installed, $threshold, '>=' );
	}

	/**
	 * The installed WooCommerce version.
	 *
	 * @return string|null The version, or null when it cannot be read.
	 */
	private function installed_woocommerce_version(): ?string {
		if ( null !== $this->woocommerce_version ) {
			return $this->woocommerce_version;
		}

		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		return null;
	}

	/**
	 * The value the classic checkout form submitted, or null when it submitted
	 * none.
	 *
	 * @return string|null Cleaned submitted value, or null when absent or not a
	 *                     scalar.
	 */
	private function submitted_value(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WC_Checkout::process_checkout() verifies the woocommerce-process-checkout-nonce before it fires the hook this runs on and aborts the whole checkout when that fails; the value is unslashed and sanitised four lines below, once the scalar check that casting it to a string requires has passed.
		$submitted = $_POST[ TaxIdField::INPUT_NAME ] ?? null;

		if ( ! is_scalar( $submitted ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( (string) $submitted ) );
	}

	/**
	 * The storage, built on first use.
	 *
	 * @return TaxIdOrderStorage The single writer of tax identifier order meta.
	 */
	private function storage(): TaxIdOrderStorage {
		if ( null === $this->storage ) {
			$this->storage = new TaxIdOrderStorage();
		}

		return $this->storage;
	}
}
