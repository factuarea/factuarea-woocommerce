<?php
/**
 * The result of one delivery attempt, and the only vocabulary the outbox reads.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * What happened to one delivery attempt, in the only shape the outbox
 * understands.
 *
 * The classifier produces it, the dispatcher acts on it and the outbox stores
 * it. Nothing else in the plugin is allowed to decide whether something is
 * retried: if a second place could decide it, the two would disagree the day a
 * response arrives that neither anticipated.
 *
 * The constructor is private and there are exactly four ways in, one per case
 * of DeliveryOutcomeKind. That is what makes the set closed in practice and not
 * just on paper: a fifth kind of outcome cannot be built without adding a fifth
 * named constructor here, which is a change a reviewer sees.
 *
 * The merchant message travels with the outcome because the merchant is the one
 * who has to act on it, and by the time they read it the response is long gone.
 * Whoever writes that message is responsible for it carrying no credential -
 * see the exception catalogue for the prohibition in full.
 */
final class DeliveryOutcome {

	/**
	 * Use the four named constructors below.
	 *
	 * @param DeliveryOutcomeKind $kind                The kind of outcome.
	 * @param int|null            $status_code         Status code received, or null when nothing came back.
	 * @param string|null         $rejection_code      Rejection code of the contract, or null when the response was not a rejection of it.
	 * @param int|null            $retry_after_seconds Wait declared by the receiver, in seconds, or null when it declared none.
	 * @param string              $merchant_message    What to show the merchant, in English, with a next step.
	 */
	private function __construct(
		public readonly DeliveryOutcomeKind $kind,
		public readonly ?int $status_code,
		public readonly ?string $rejection_code,
		public readonly ?int $retry_after_seconds,
		public readonly string $merchant_message,
	) {
	}

	/**
	 * The receiver accepted the delivery.
	 *
	 * @param int $status_code Status code received.
	 * @return self
	 */
	public static function accepted( int $status_code ): self {
		return new self(
			DeliveryOutcomeKind::ACCEPTED,
			$status_code,
			null,
			null,
			'Delivered to Factuarea.',
		);
	}

	/**
	 * The receiver rejected it under its contract and declared no wait.
	 *
	 * @param int    $status_code      Status code received.
	 * @param string $rejection_code   Rejection code as the receiver published it.
	 * @param string $merchant_message What to show the merchant, with a next step.
	 * @return self
	 */
	public static function permanent_rejection( int $status_code, string $rejection_code, string $merchant_message ): self {
		return new self(
			DeliveryOutcomeKind::PERMANENT_REJECTION,
			$status_code,
			$rejection_code,
			null,
			$merchant_message,
		);
	}

	/**
	 * The receiver rejected it under its contract and declared a wait.
	 *
	 * @param int    $status_code         Status code received.
	 * @param string $rejection_code      Rejection code as the receiver published it.
	 * @param int    $retry_after_seconds Wait the receiver declared, in seconds.
	 * @param string $merchant_message    What to show the merchant, with a next step.
	 * @return self
	 */
	public static function retryable_rejection( int $status_code, string $rejection_code, int $retry_after_seconds, string $merchant_message ): self {
		return new self(
			DeliveryOutcomeKind::RETRYABLE_REJECTION,
			$status_code,
			$rejection_code,
			$retry_after_seconds,
			$merchant_message,
		);
	}

	/**
	 * Anything else: transport failure, timeout, server error with no contract
	 * body, unreadable body.
	 *
	 * @param int|null $status_code      Status code received, or null when nothing came back.
	 * @param string   $merchant_message What to show the merchant, with a next step.
	 * @return self
	 */
	public static function transient_failure( ?int $status_code, string $merchant_message ): self {
		return new self(
			DeliveryOutcomeKind::TRANSIENT_FAILURE,
			$status_code,
			null,
			null,
			$merchant_message,
		);
	}

	/**
	 * Is there to be another attempt?
	 *
	 * Acceptance and permanent rejection close the entry; the other two
	 * reschedule it. The dispatcher asks this instead of comparing kinds, so
	 * that the answer lives in one place.
	 *
	 * @return bool
	 */
	public function should_retry(): bool {
		return DeliveryOutcomeKind::RETRYABLE_REJECTION === $this->kind
			|| DeliveryOutcomeKind::TRANSIENT_FAILURE === $this->kind;
	}

	/**
	 * Did the receiver itself say how long to wait?
	 *
	 * When it did, that wait wins over the plugin's own table: the receiver
	 * knows why it is busy and the plugin does not.
	 *
	 * @return bool
	 */
	public function has_declared_wait(): bool {
		return null !== $this->retry_after_seconds;
	}

	/**
	 * Was this a rejection published by the ingestion contract, as opposed to
	 * anything else that can come back from a network?
	 *
	 * @return bool
	 */
	public function is_contract_rejection(): bool {
		return DeliveryOutcomeKind::PERMANENT_REJECTION === $this->kind
			|| DeliveryOutcomeKind::RETRYABLE_REJECTION === $this->kind;
	}
}
