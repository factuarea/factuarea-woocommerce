<?php
/**
 * An inbound refund notice, as it arrived.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * A refund the platform already issued, published into this shop so that the
 * order stops saying it was paid in full.
 *
 * ---------------------------------------------------------------------------
 * THIS NOTICE REFLECTS MONEY; IT DOES NOT MOVE IT
 * ---------------------------------------------------------------------------
 * The money was returned outside this shop and a corrective invoice already
 * backs it. What arrives here is the record. Whoever applies it creates a
 * WooCommerce refund object WITHOUT ordering a payment-gateway movement and
 * WITHOUT restocking, and marks it as inbound so that neither of the plugin's
 * two refund captors delivers it back - a refund that went out again would
 * produce a SECOND corrective invoice on the same sale, in a series filed with
 * the tax authority, where a document is not deleted but corrected with another
 * document.
 *
 * ---------------------------------------------------------------------------
 * THE WIRE KEYS, MEASURED
 * ---------------------------------------------------------------------------
 * Read on 2026-09-18 off the frozen contract the sender publishes,
 * `backend/docs/architecture/store-order-status-receiver-contract.md` §4.2:
 *
 *   order_id                     string,  required
 *   amount                       STRING,  required - decimal with its scale
 *   currency                     string,  required
 *   scope                        string,  required - `total` or `partial`
 *   corrective_invoice_reference string,  required - the PUBLIC number
 *   idempotency_key              string,  required
 *
 * ---------------------------------------------------------------------------
 * THE AMOUNT IS A STRING, AND IT IS VALIDATED WITHOUT BECOMING A NUMBER
 * ---------------------------------------------------------------------------
 * `"12.34"` is not `12.34`. A float cannot hold most two-decimal amounts
 * exactly, so a single cast on the way in is enough to turn a refund of 12.34
 * into one of 12.339999999999999, and nothing downstream can tell which of the
 * two the platform meant. The value therefore travels as the string it arrived
 * as, all the way to `wc_create_refund()`, which accepts one.
 *
 * The shape IS checked here - sign, digits, one decimal point - with a pattern
 * rather than with `is_numeric()`, because `is_numeric()` also accepts `"1e3"`,
 * `" 12.34"` and hexadecimal-looking values that WooCommerce would store
 * verbatim and then render as a nonsense total. A value that fails the pattern
 * makes the whole notice MALFORMED: there is no truncated amount to fall back
 * on, and refunding an amount nobody sent is worse than refunding nothing.
 *
 * ---------------------------------------------------------------------------
 * THE FACTORY ANSWERS `null`; IT DOES NOT THROW
 * ---------------------------------------------------------------------------
 * Same reason as {@see InboundStatusNotice}: a body of another shape is an
 * ordinary outcome with a frozen status, and an exception escaping a REST
 * callback would turn a permanent 422 into a retryable 500.
 */
final class InboundRefundNotice {

	/**
	 * Body field holding the identifier of the order in this shop.
	 */
	public const FIELD_ORDER_ID = 'order_id';

	/**
	 * Body field holding the refunded amount, as a decimal string.
	 */
	public const FIELD_AMOUNT = 'amount';

	/**
	 * Body field holding the currency of the refund.
	 */
	public const FIELD_CURRENCY = 'currency';

	/**
	 * Body field holding the scope of the refund.
	 */
	public const FIELD_SCOPE = 'scope';

	/**
	 * Body field holding the public reference of the corrective invoice.
	 */
	public const FIELD_CORRECTIVE_INVOICE_REFERENCE = 'corrective_invoice_reference';

	/**
	 * Body field holding the idempotency key of the delivery.
	 */
	public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';

	/**
	 * The whole sale came back.
	 */
	public const SCOPE_TOTAL = 'total';

	/**
	 * Part of the sale came back.
	 */
	public const SCOPE_PARTIAL = 'partial';

	/**
	 * The two scopes, and there is no third.
	 *
	 * @var list<string>
	 */
	public const VALID_SCOPES = array(
		self::SCOPE_TOTAL,
		self::SCOPE_PARTIAL,
	);

	/**
	 * What an amount has to look like: an optional minus sign, at least one
	 * digit, and at most one decimal point followed by at least one digit.
	 *
	 * No thousands separator, no exponent, no surrounding blanks. The scale is
	 * NOT fixed at two: a currency with three decimals is a currency, and a
	 * receiver that insisted on two would reject it in every shop already
	 * published.
	 */
	private const AMOUNT_PATTERN = '/^-?\d+(\.\d+)?$/';

	/**
	 * Use {@see self::from_array()}.
	 *
	 * @param string $order_id                     Identifier of the order in this shop.
	 * @param string $amount                       Refunded amount as a decimal string.
	 * @param string $currency                     Currency of the refund.
	 * @param string $scope                        One of the two scopes.
	 * @param string $corrective_invoice_reference Public reference of the corrective invoice.
	 * @param string $idempotency_key              Key that deduplicates the delivery.
	 */
	private function __construct(
		public readonly string $order_id,
		public readonly string $amount,
		public readonly string $currency,
		public readonly string $scope,
		public readonly string $corrective_invoice_reference,
		public readonly string $idempotency_key,
	) {
	}

	/**
	 * Reads an already decoded document, or answers null when it is not one.
	 *
	 * @param array<string, mixed> $document Body already decoded as an associative array.
	 * @return self|null The notice, or null when the shape is not the expected one.
	 */
	public static function from_array( array $document ): ?self {
		$order_id        = self::required_string( $document, self::FIELD_ORDER_ID );
		$amount          = self::required_string( $document, self::FIELD_AMOUNT );
		$currency        = self::required_string( $document, self::FIELD_CURRENCY );
		$scope           = self::required_string( $document, self::FIELD_SCOPE );
		$reference       = self::required_string( $document, self::FIELD_CORRECTIVE_INVOICE_REFERENCE );
		$idempotency_key = self::required_string( $document, self::FIELD_IDEMPOTENCY_KEY );

		if ( null === $order_id || null === $amount || null === $currency ) {
			return null;
		}

		if ( null === $scope || null === $reference || null === $idempotency_key ) {
			return null;
		}

		if ( ! self::is_decimal_string( $amount ) ) {
			return null;
		}

		if ( ! in_array( $scope, self::VALID_SCOPES, true ) ) {
			return null;
		}

		return new self( $order_id, $amount, $currency, $scope, $reference, $idempotency_key );
	}

	/**
	 * Did the whole sale come back?
	 *
	 * @return bool
	 */
	public function is_total(): bool {
		return self::SCOPE_TOTAL === $this->scope;
	}

	/**
	 * Is this a decimal amount this contract can carry?
	 *
	 * Public because the catalogue price travels under the same rule and the two
	 * must not drift apart: one pattern, read from one place.
	 *
	 * @param string $amount Candidate amount, as it arrived.
	 * @return bool
	 */
	public static function is_decimal_string( string $amount ): bool {
		return 1 === preg_match( self::AMOUNT_PATTERN, $amount );
	}

	/**
	 * A required field that has to be a NON-EMPTY string.
	 *
	 * A number where a string belongs is rejected rather than cast; see the
	 * amount block above for what a cast costs here.
	 *
	 * @param array<string, mixed> $document Decoded body.
	 * @param string               $field    Field name.
	 * @return string|null The value, or null when it is absent, empty or not a string.
	 */
	private static function required_string( array $document, string $field ): ?string {
		$value = $document[ $field ] ?? null;

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}
}
