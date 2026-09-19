<?php
/**
 * An inbound fulfilment status notice, as it arrived.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * The fulfilment status of one order, published by the platform into this shop.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 * It is the decoded body of a delivery to the order-status receiver, and
 * nothing else: no order is looked up here, no meta is written, no WordPress
 * function is called. Reading and applying are separate on purpose - the
 * reading has to be testable without a database, and the applying must not be
 * able to half-read a body it already started writing from.
 *
 * ---------------------------------------------------------------------------
 * THE WIRE KEYS, MEASURED
 * ---------------------------------------------------------------------------
 * Read on 2026-09-18 off the frozen contract the sender publishes,
 * `backend/docs/architecture/store-order-status-receiver-contract.md` §4.1 and
 * §4.4, and against `WooCommerceStatusFeedbackClient` behind it:
 *
 *   order_id        string, required - the identifier of the order IN THIS SHOP
 *   status          string, required - one of the four of FulfilmentState
 *   occurred_at     string, required - instant of the fact, ATOM
 *   idempotency_key string, required - §4.3
 *   tracking        object, optional - number, carrier, url, packages
 *
 * `order_id` is a STRING on the wire even though WooCommerce order identifiers
 * are integers: the sender stores the remote identifier of every platform in one
 * column and does not narrow it per platform. Whoever resolves the order casts
 * it, and a value that is not a numeric string simply resolves to no order,
 * which is the 404 of the table - not a crash.
 *
 * ---------------------------------------------------------------------------
 * THE FACTORY ANSWERS `null`; IT DOES NOT THROW
 * ---------------------------------------------------------------------------
 * A body that is not this shape is a MALFORMED delivery, which is an ordinary,
 * expected outcome with a frozen status of its own
 * (`InboundResponseStatus::MALFORMED`). Throwing would make the receiver wrap
 * every read in a catch to produce the answer it already has to produce, and an
 * uncaught one inside a REST callback turns a malformed notice into a 500, which
 * the sender classifies as RETRYABLE and comes back with seven more times.
 *
 * `InboundPayloadNotReadableException` exists for the other half of the problem
 * - a body that cannot even be decoded - and it names the field, never the
 * secret, the expected signature or the presented one.
 *
 * ---------------------------------------------------------------------------
 * AN UNKNOWN KEY DOES NOT REJECT THIS NOTICE, UNLIKE A CATALOGUE BATCH
 * ---------------------------------------------------------------------------
 * The asymmetry is deliberate and is worth stating, because the two live in the
 * same namespace. A catalogue batch that carries a key this contract does not
 * enumerate is rejected whole: there, an unknown key means the sender is writing
 * something into the merchant's catalogue that this version does not understand,
 * and accepting it silently loses the change. Here, the body only describes a
 * fact; a key this version does not read is a fact it does not show, which costs
 * the merchant nothing, while rejecting it would stop applying legitimate
 * notices in every shop that had not updated the day the contract grew one
 * optional key.
 */
final class InboundStatusNotice {

	/**
	 * Body field holding the identifier of the order in this shop.
	 */
	public const FIELD_ORDER_ID = 'order_id';

	/**
	 * Body field holding the fulfilment status.
	 */
	public const FIELD_STATUS = 'status';

	/**
	 * Body field holding the instant of the fact.
	 */
	public const FIELD_OCCURRED_AT = 'occurred_at';

	/**
	 * Body field holding the idempotency key of the delivery.
	 */
	public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';

	/**
	 * Body field holding the tracking block, when the shipment declares one.
	 */
	public const FIELD_TRACKING = 'tracking';

	/**
	 * Tracking sub-field holding the tracking number.
	 */
	public const FIELD_TRACKING_NUMBER = 'number';

	/**
	 * Tracking sub-field holding the carrier name.
	 */
	public const FIELD_TRACKING_CARRIER = 'carrier';

	/**
	 * Tracking sub-field holding the tracking address, already composed.
	 */
	public const FIELD_TRACKING_URL = 'url';

	/**
	 * Tracking sub-field holding the number of packages.
	 */
	public const FIELD_TRACKING_PACKAGES = 'packages';

	/**
	 * Use {@see self::from_array()}.
	 *
	 * @param string      $order_id          Identifier of the order in this shop.
	 * @param string      $status            One of the four of FulfilmentState.
	 * @param string      $occurred_at       Instant of the fact, as it arrived.
	 * @param string      $idempotency_key   Key that deduplicates the delivery.
	 * @param string|null $tracking_number   Tracking number, or null when absent.
	 * @param string|null $tracking_carrier  Carrier name, or null when absent.
	 * @param string|null $tracking_url      Tracking address, or null when absent.
	 * @param int|null    $tracking_packages Number of packages, or null when absent.
	 */
	private function __construct(
		public readonly string $order_id,
		public readonly string $status,
		public readonly string $occurred_at,
		public readonly string $idempotency_key,
		public readonly ?string $tracking_number,
		public readonly ?string $tracking_carrier,
		public readonly ?string $tracking_url,
		public readonly ?int $tracking_packages,
	) {
	}

	/**
	 * Reads an already decoded document, or answers null when it is not one.
	 *
	 * Every required field is checked for BEING A STRING and not merely for
	 * being there. A number where a string belongs is rejected rather than cast:
	 * `1042` and `"1042"` look interchangeable until an identifier with a leading
	 * zero, or one long enough to lose precision, arrives - and a silent cast
	 * would have resolved it to the wrong order, or to none, with no trace of
	 * why.
	 *
	 * @param array<string, mixed> $document Body already decoded as an associative array.
	 * @return self|null The notice, or null when the shape is not the expected one.
	 */
	public static function from_array( array $document ): ?self {
		$order_id        = self::required_string( $document, self::FIELD_ORDER_ID );
		$status          = self::required_string( $document, self::FIELD_STATUS );
		$occurred_at     = self::required_string( $document, self::FIELD_OCCURRED_AT );
		$idempotency_key = self::required_string( $document, self::FIELD_IDEMPOTENCY_KEY );

		if ( null === $order_id || null === $status || null === $occurred_at || null === $idempotency_key ) {
			return null;
		}

		if ( ! FulfilmentState::is_valid( $status ) ) {
			return null;
		}

		$tracking = $document[ self::FIELD_TRACKING ] ?? null;

		if ( null === $tracking ) {
			return new self( $order_id, $status, $occurred_at, $idempotency_key, null, null, null, null );
		}

		// Present but not an object: the sender said there is tracking and then
		// did not describe it. That is a broken body, not an absent block.
		if ( ! is_array( $tracking ) ) {
			return null;
		}

		$number   = self::optional_string( $tracking, self::FIELD_TRACKING_NUMBER );
		$carrier  = self::optional_string( $tracking, self::FIELD_TRACKING_CARRIER );
		$url      = self::optional_string( $tracking, self::FIELD_TRACKING_URL );
		$packages = self::optional_int( $tracking, self::FIELD_TRACKING_PACKAGES );

		if ( false === $number || false === $carrier || false === $url || false === $packages ) {
			return null;
		}

		return new self( $order_id, $status, $occurred_at, $idempotency_key, $number, $carrier, $url, $packages );
	}

	/**
	 * Does this notice carry any tracking datum at all?
	 *
	 * Asked as a whole because the three data are shown together: a carrier with
	 * no number and no address is still something the buyer can read.
	 *
	 * @return bool
	 */
	public function has_tracking(): bool {
		return null !== $this->tracking_number || null !== $this->tracking_carrier || null !== $this->tracking_url;
	}

	/**
	 * A required field that has to be a NON-EMPTY string.
	 *
	 * Empty is rejected with the absent ones: an empty idempotency key
	 * deduplicates nothing, and an empty order identifier resolves to no order
	 * while looking like a value.
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

	/**
	 * An optional field that, WHEN PRESENT, has to be a non-empty string.
	 *
	 * Three answers, which is why the return type is what it is: the string when
	 * it is there, `null` when the field is absent - a legitimate shipment
	 * without a tracking number - and `false` when it is present and not a
	 * non-empty string, which is a broken body. Collapsing the last two into
	 * `null` would accept a tracking number sent as a number and store nothing.
	 *
	 * @param array<string, mixed> $document Decoded block.
	 * @param string               $field    Field name.
	 * @return string|null|false The value, null when absent, false when malformed.
	 */
	private static function optional_string( array $document, string $field ) {
		if ( ! array_key_exists( $field, $document ) ) {
			return null;
		}

		$value = $document[ $field ];

		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		return $value;
	}

	/**
	 * An optional field that, WHEN PRESENT, has to be an integer.
	 *
	 * Same three answers as {@see self::optional_string()}. A boolean is
	 * excluded explicitly because `is_int()` already rejects it, and a numeric
	 * STRING is rejected too: the contract declares this one as an integer, and
	 * accepting both shapes is how a field ends up meaning two things.
	 *
	 * @param array<string, mixed> $document Decoded block.
	 * @param string               $field    Field name.
	 * @return int|null|false The value, null when absent, false when malformed.
	 */
	private static function optional_int( array $document, string $field ) {
		if ( ! array_key_exists( $field, $document ) ) {
			return null;
		}

		$value = $document[ $field ];

		if ( ! is_int( $value ) ) {
			return false;
		}

		return $value;
	}
}
