<?php
/**
 * What happened to ONE article of an inbound catalogue batch.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

use InvalidArgumentException;

/**
 * The outcome of one article: applied, or rejected with a typed reason.
 *
 * ---------------------------------------------------------------------------
 * PER ARTICLE, BECAUSE THE BATCH IS NOT THE UNIT THAT FAILS
 * ---------------------------------------------------------------------------
 * A batch of five in which two articles cannot be resolved is not a failed
 * delivery: three articles were applied and they stay applied. The sender needs
 * to tell an article rejection from a delivery failure, because the two have
 * opposite answers - one is retried and the other never will be - and a single
 * status for the whole batch cannot carry that. So the answer body carries one
 * of these per article, and the batch is NOT reported as correct when any of
 * them was rejected.
 *
 * ---------------------------------------------------------------------------
 * THE REASONS ARE TYPED, AND THE LIST IS CLOSED
 * ---------------------------------------------------------------------------
 * A free-form sentence would be read by a program on the other side, and a
 * program that reads sentences breaks when somebody improves the wording. The
 * five below are the ways an article can fail to be applied ONCE the batch has
 * been read; anything that stops the batch being read at all is not here, it is
 * a malformed delivery answered with one status for the whole thing.
 *
 * Each reason names what the SENDER has to do, which is the only reason for a
 * receiver to explain itself at all:
 *
 *  - NOT_FOUND          - the reference matches no article here. Nothing is
 *                         created: creating belongs to the publishing mode of
 *                         the sending platform, and a receiver that created on
 *                         its own would turn one mis-addressed push into a
 *                         duplicated catalogue in a third party's shop.
 *  - AMBIGUOUS          - the reference matches more than one article. Nothing
 *                         is applied to any of them: choosing one is choosing
 *                         blindly which of the merchant's products changes
 *                         price.
 *  - STOCK_NOT_MANAGED  - the article does not manage stock, and this receiver
 *                         does not switch that on. That is a decision about the
 *                         merchant's shop.
 *  - UNREADABLE_AMOUNT  - the price is not a readable decimal. The previous
 *                         price is left exactly as it was rather than replaced
 *                         by a truncated value.
 *  - FIELD_NOT_ENUMERATED - the item declares a key this contract does not
 *                         enumerate. See {@see CatalogPushItem} for why that
 *                         rejects instead of being ignored, and for the
 *                         separate, harsher treatment of an INTERNAL figure,
 *                         which rejects the whole batch instead of one article.
 *
 * ---------------------------------------------------------------------------
 * THE ARTICLE IS NAMED THE WAY THE SENDER NAMED IT
 * ---------------------------------------------------------------------------
 * `sender_reference` is the remote identity when the push carried one and the
 * reference otherwise, never an internal identifier of this shop. The sender
 * asked about something it named; an answer keyed by a WordPress post id is an
 * answer to a question nobody asked, and it publishes this shop's internals to
 * a platform with no use for them.
 */
final class CatalogPushOutcome {

	/**
	 * The article was applied.
	 */
	public const STATUS_APPLIED = 'applied';

	/**
	 * The article was rejected, and the reason says which way.
	 */
	public const STATUS_REJECTED = 'rejected';

	/**
	 * The reference matches no article of this shop.
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * The reference matches more than one article of this shop.
	 */
	public const REASON_AMBIGUOUS = 'ambiguous';

	/**
	 * The article does not manage stock.
	 */
	public const REASON_STOCK_NOT_MANAGED = 'stock_not_managed';

	/**
	 * The price is not a readable decimal amount.
	 */
	public const REASON_UNREADABLE_AMOUNT = 'unreadable_amount';

	/**
	 * The item declares a key the inbound contract does not enumerate.
	 */
	public const REASON_FIELD_NOT_ENUMERATED = 'field_not_enumerated';

	/**
	 * The five reasons, and there is no sixth.
	 *
	 * @var list<string>
	 */
	public const VALID_REASONS = array(
		self::REASON_NOT_FOUND,
		self::REASON_AMBIGUOUS,
		self::REASON_STOCK_NOT_MANAGED,
		self::REASON_UNREADABLE_AMOUNT,
		self::REASON_FIELD_NOT_ENUMERATED,
	);

	/**
	 * Answer key holding how the sender named the article.
	 */
	public const FIELD_REFERENCE = 'reference';

	/**
	 * Answer key holding whether the article was applied or rejected.
	 */
	public const FIELD_STATUS = 'status';

	/**
	 * Answer key holding the typed reason of a rejection.
	 */
	public const FIELD_REASON = 'reason';

	/**
	 * Use {@see self::applied()} or {@see self::rejected()}.
	 *
	 * @param string      $sender_reference How the sender named the article.
	 * @param string      $status           One of the two statuses.
	 * @param string|null $reason           Typed reason, or null when applied.
	 */
	private function __construct(
		public readonly string $sender_reference,
		public readonly string $status,
		public readonly ?string $reason,
	) {
	}

	/**
	 * The article was applied.
	 *
	 * @param string $sender_reference How the sender named the article.
	 * @return self
	 */
	public static function applied( string $sender_reference ): self {
		return new self( $sender_reference, self::STATUS_APPLIED, null );
	}

	/**
	 * The article was rejected, for one of the five reasons.
	 *
	 * An unknown reason THROWS rather than being stored, because a reason the
	 * sender cannot interpret is the same as no reason at all, and the failure
	 * would only be noticed in the sender's logs weeks later. This is a
	 * programming error of this plugin, not a malformed delivery, which is why
	 * it is the one place in this contract that throws.
	 *
	 * @param string $sender_reference How the sender named the article.
	 * @param string $reason           One of {@see self::VALID_REASONS}.
	 * @return self
	 * @throws InvalidArgumentException When the reason is not one of the five.
	 */
	public static function rejected( string $sender_reference, string $reason ): self {
		if ( ! in_array( $reason, self::VALID_REASONS, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unknown catalogue push rejection reason "%1$s". The receiver may only answer with one of: %2$s.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A reason this plugin itself passed, formatted into a message that reaches a log or a failing test and never a page. Escaping it would make this contract call a WordPress function, and these classes run under a bare PHP unit suite with no WordPress loaded.
					$reason,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The frozen list of the five reasons, declared in this very class; see the note on the argument above.
					implode( ', ', self::VALID_REASONS )
				)
			);
		}

		return new self( $sender_reference, self::STATUS_REJECTED, $reason );
	}

	/**
	 * Was the article applied?
	 *
	 * @return bool
	 */
	public function is_applied(): bool {
		return self::STATUS_APPLIED === $this->status;
	}

	/**
	 * The outcome as it travels in the answer body.
	 *
	 * The reason key is present ONLY on a rejection. A `"reason": null` on an
	 * applied article invites the reader to branch on the value of a key that is
	 * always there, instead of on its presence, which is the shape that survives
	 * the contract gaining a sixth reason.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		$outcome = array(
			self::FIELD_REFERENCE => $this->sender_reference,
			self::FIELD_STATUS    => $this->status,
		);

		if ( null !== $this->reason ) {
			$outcome[ self::FIELD_REASON ] = $this->reason;
		}

		return $outcome;
	}
}
