<?php
/**
 * The drain that turns recorded outbox entries into deliveries.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Outbox;

use Closure;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\DeliveryOutcome;
use Factuarea\WooCommerce\Exception\CredentialsNotConfiguredException;
use Factuarea\WooCommerce\Exception\StoreOriginMismatchException;
use Factuarea\WooCommerce\Transport\DeliveryHeaders;
use Factuarea\WooCommerce\Transport\DeliverySigner;
use Factuarea\WooCommerce\Transport\RejectionClassifier;
use Factuarea\WooCommerce\Transport\WordPressHttpClient;
use Throwable;

/**
 * Claims what is due, delivers it, and settles what came back.
 *
 * It is the only place where the six pieces of the delivery path meet, and it
 * owns none of their decisions: the signer signs, the header composer composes,
 * the transport reports, the classifier judges, the planner picks the instant
 * and the repository stores. What lives here is the ORDER in which those happen
 * and what to do when one of them refuses.
 *
 * ---------------------------------------------------------------------------
 * IT KNOWS NOTHING ABOUT WOOCOMMERCE
 * ---------------------------------------------------------------------------
 * No order is loaded here, no `WC_Order` is touched, no body is built. The
 * bytes were produced and stored at capture time, inside the request that
 * handled the sale, and this class delivers them unchanged.
 *
 * That is not a layering preference. Rebuilding the body at delivery time would
 * mean an order edited after the sale is delivered AS IT LOOKS LATER, and the
 * invoice would describe something other than the sale that happened - and,
 * more immediately, the bytes stored are the bytes the signature will cover, so
 * anything that rebuilt them would have to re-sign them and would be free to
 * get that wrong. The three WordPress functions it does call - `get_option`,
 * `home_url` and `wp_parse_url` - are settings, identity and string handling,
 * not commerce.
 *
 * ---------------------------------------------------------------------------
 * WHAT drain() COUNTS
 * ---------------------------------------------------------------------------
 * The number of entries it SETTLED in this run - every entry it claimed and
 * wrote an outcome for, whatever that outcome was: delivered, permanently
 * rejected, rescheduled, exhausted, or refused by the origin guard.
 *
 * It is deliberately NOT the number of successful deliveries. A scheduled run
 * reports what it did, and "5" meaning "five sales went through" would read as
 * "nothing happened" on the run where five entries were rejected - which is the
 * run somebody needs to look at. Success is a property of each entry's status,
 * which is in the table; the count is a property of the run.
 *
 * A run that delivers nothing because the plugin is switched off or not
 * configured returns 0 WITHOUT claiming anything - see the preconditions in
 * `drain()`.
 *
 * ---------------------------------------------------------------------------
 * ONE ENTRY'S FAILURE NEVER TAKES THE RUN WITH IT
 * ---------------------------------------------------------------------------
 * Every entry is delivered and settled inside its own guard. An exception from
 * anywhere - another plugin filtering outgoing requests and throwing, a body
 * that some filter mangled, a bug of ours - is caught, becomes that entry's
 * outcome, and the loop moves to the next one.
 *
 * The reason is arithmetic: a drain takes a batch, and an exception escaping
 * the loop would abandon every entry after the failing one, still CLAIMED, for
 * the whole claim timeout. One bad entry would hold up a merchant's whole
 * queue, and it would do it once per run, forever.
 *
 * ---------------------------------------------------------------------------
 * THE THREE DELIVERY FACTS THAT ARE FIXED HERE, AND WHY THEY ARE FIXED
 * ---------------------------------------------------------------------------
 * MEASURED on 2026-09-02 against the receiver in the monorepo: its ingestion
 * controller reads exactly ONE of the seven delivery headers, the signature
 * (`StoreWebhookController` reads `StoreWebhookSignatureHeaders::headerFor()`,
 * and nothing else reads a header), and its WooCommerce normaliser holds
 * `order.updated` as a private constant it applies to every order delivery
 * rather than reading the topic off the wire.
 *
 * So the topic, the resource and the event are sent to be indistinguishable
 * from a native `order.updated` delivery, not because anything downstream
 * branches on them - and a refund travels as the ORDER carrying its refunds,
 * which is what a native delivery does too and why there is one topic and not
 * two. `event_type` on the entry is a column for whoever debugs the outbox; it
 * does not reach the wire. If a second topic ever has to be sent, it is decided
 * here, from that column, and nowhere else.
 *
 * ---------------------------------------------------------------------------
 * THE TWO TRACING IDENTIFIERS
 * ---------------------------------------------------------------------------
 * A native delivery numbers them from WooCommerce's own webhook and delivery
 * records, which this plugin does not have because it replaced that mechanism.
 * They are tracing only - the receiver reads neither - so:
 *
 *  - `X-WC-Webhook-ID` is the constant below. This plugin has exactly one
 *    logical webhook, so one integer describes it. Zero was rejected because it
 *    reads as "absent" in a log rather than as a value.
 *  - `X-WC-Webhook-Delivery-ID` is the outbox entry's primary key. It therefore
 *    identifies the ENTRY and stays the same across that entry's attempts,
 *    whereas a native one changes on every attempt. That difference is
 *    deliberate and it is the useful direction: it makes every attempt of one
 *    sale greppable together in a receiver's log, and the attempt number is in
 *    the `attempts` column where it belongs.
 *
 * NEITHER MAY EVER REACH AN EVENT IDENTITY. That prohibition is written where
 * the identity is composed, and it is repeated here because this is the file
 * where both values are in scope: an identity built from either would make the
 * second attempt of one sale look like a new fact, and the receiver would issue
 * a second invoice into a correlative series that nobody may delete.
 */
final class OutboxDispatcher {

	/**
	 * Topic of every delivery this plugin sends, as `resource.event`.
	 *
	 * One value for orders and refunds alike: a refund is delivered as the order
	 * carrying its refunds, exactly as a native delivery does, and the receiver
	 * finds the refund in that body.
	 */
	public const DELIVERY_TOPIC = 'order.updated';

	/**
	 * Resource half of the topic.
	 */
	public const DELIVERY_RESOURCE = 'order';

	/**
	 * Event half of the topic.
	 */
	public const DELIVERY_EVENT = 'updated';

	/**
	 * Value of the webhook identifier header. See the note above: tracing only,
	 * constant because this plugin has exactly one logical webhook.
	 */
	public const WEBHOOK_ID = 1;

	/**
	 * The transport.
	 *
	 * @var WordPressHttpClient
	 */
	private WordPressHttpClient $client;

	/**
	 * The one piece that decides whether a delivery is retried.
	 *
	 * @var RejectionClassifier
	 */
	private RejectionClassifier $classifier;

	/**
	 * The outbox.
	 *
	 * @var OutboxRepository
	 */
	private OutboxRepository $outbox;

	/**
	 * The one piece that decides WHEN a retried delivery is tried again.
	 *
	 * @var RetryPlanner
	 */
	private RetryPlanner $planner;

	/**
	 * The signer.
	 *
	 * @var DeliverySigner
	 */
	private DeliverySigner $signer;

	/**
	 * The header composer.
	 *
	 * @var DeliveryHeaders
	 */
	private DeliveryHeaders $headers;

	/**
	 * Source of the current instant.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Builds a dispatcher.
	 *
	 * EVERY DEPENDENCY IS OPTIONAL, and production passes none of them: the
	 * wiring phase constructs `new OutboxDispatcher()` and schedules `drain()`.
	 * They exist so that the unit test can stand in for the outbox and pin the
	 * instant, which is the only way to assert WHEN the next attempt was
	 * scheduled rather than that some number came back.
	 *
	 * The clock is injectable for the same reason and not for tidiness: a test
	 * that read the real clock could only assert a range, and a planner that
	 * scheduled the wrong interval would land inside that range often enough to
	 * be filed as a flake.
	 *
	 * @param WordPressHttpClient|null $client     Transport, or null for the real one.
	 * @param RejectionClassifier|null $classifier Classifier, or null for the real one.
	 * @param OutboxRepository|null    $outbox     Outbox, or null for the real one.
	 * @param RetryPlanner|null        $planner    Retry planner, or null for the real one.
	 * @param DeliverySigner|null      $signer     Signer, or null for the real one.
	 * @param DeliveryHeaders|null     $headers    Header composer, or null for the real one.
	 * @param callable|null            $clock      Source of the current Unix timestamp, or null for `time()`.
	 */
	public function __construct(
		?WordPressHttpClient $client = null,
		?RejectionClassifier $classifier = null,
		?OutboxRepository $outbox = null,
		?RetryPlanner $planner = null,
		?DeliverySigner $signer = null,
		?DeliveryHeaders $headers = null,
		?callable $clock = null
	) {
		$this->client     = $client ?? new WordPressHttpClient();
		$this->classifier = $classifier ?? new RejectionClassifier();
		$this->outbox     = $outbox ?? new OutboxRepository();
		$this->planner    = $planner ?? new RetryPlanner();
		$this->signer     = $signer ?? new DeliverySigner();
		$this->headers    = $headers ?? new DeliveryHeaders();
		$this->clock      = null === $clock
			? static function (): int {
				return time();
			}
			: Closure::fromCallable( $clock );
	}

	/**
	 * Delivers up to $limit due entries and settles every one of them.
	 *
	 * ---------------------------------------------------------------------
	 * THE PRECONDITIONS CLAIM NOTHING, AND THAT IS THE POINT
	 * ---------------------------------------------------------------------
	 * Three things are checked BEFORE any entry is claimed, and each of them
	 * ends the run with 0 rather than settling anything:
	 *
	 *  1. DELIVERY SWITCHED OFF. The merchant said not to send, so nothing is
	 *     sent and nothing is consumed.
	 *  2. NO FACTUAREA ADDRESS.
	 *  3. NO SIGNING SECRET.
	 *
	 * The last two are the state of a freshly activated plugin, and claiming
	 * entries in that state is what this order of checks exists to prevent.
	 * Every attempt costs an entry one of its eight, and the eight are spread
	 * over four and a half days; spending them against a settings screen nobody
	 * has filled in yet would let a shop's first sales expire before the
	 * merchant finished setting up. Left unclaimed, those entries stay PENDING
	 * and go out unchanged the moment the settings are there - which is exactly
	 * what the typed configuration error promises the merchant.
	 *
	 * A shop left in that state is not silent: the entries fall past their due
	 * moment and the stalled-outbox notice on the settings screen counts them.
	 *
	 * The signer's own refusal to sign with an empty secret still stands behind
	 * this check, for the case where the secret is removed between this line and
	 * that one. It is caught below.
	 *
	 * @param int $limit Maximum number of entries to take in this run.
	 * @return int How many entries were settled. See the note on counting above.
	 */
	public function drain( int $limit ): int {
		if ( $limit < 1 ) {
			return 0;
		}

		if ( '1' !== (string) get_option( Constants::OPTION_DELIVERY_ENABLED, '0' ) ) {
			return 0;
		}

		$base_url = trim( (string) get_option( Constants::OPTION_BASE_URL, '' ) );
		$secret   = (string) get_option( Constants::OPTION_SIGNING_SECRET, '' );

		if ( '' === $base_url || '' === $secret ) {
			return 0;
		}

		$url = rtrim( $base_url, '/' ) . Constants::INGESTION_PATH;

		/*
		 * The origin of this installation, read ONCE for the whole run. It is
		 * `home_url()` and not a setting: a setting could be edited to match
		 * whatever the entries say, which would turn the guard below into a
		 * checkbox. What the shop says it is is not a preference.
		 */
		$configured_origin = (string) home_url();

		$settled = 0;

		foreach ( $this->outbox->claim_due( $limit ) as $row ) {
			list( $outcome, $next_attempt_at ) = $this->deliver( $row, $url, $secret, $configured_origin );

			try {
				$this->outbox->settle( (int) $row['id'], $outcome, $next_attempt_at );

				++$settled;
			} catch ( Throwable ) {
				/*
				 * The outcome could not be written. The entry stays CLAIMED, so
				 * `claim_due` takes it back after the claim timeout and nothing
				 * is lost; what must not happen is that the rest of this batch
				 * is abandoned because one row could not be written.
				 */
				continue;
			}
		}

		return $settled;
	}

	/**
	 * Delivers one entry and works out what should happen to it next.
	 *
	 * Nothing is written here: it returns the pair the caller settles with, so
	 * that there is exactly one write per entry and no path can settle twice.
	 *
	 * THE TWO TYPED FAULTS ARE SETTLED WITHOUT A NEXT ATTEMPT, and that is the
	 * difference between them and everything else that goes wrong. An entry
	 * captured for another shop and an entry that cannot be signed are not
	 * failures that a later attempt could resolve: the first one is not this
	 * installation's to send at all, and the second one is a settings problem
	 * that no amount of waiting fixes. Retrying either would spend eight
	 * attempts to arrive at the same place with the reason four days staler.
	 *
	 * They are carried as TRANSIENT outcomes with no next attempt - which the
	 * outbox stores as `exhausted`, with the reason - rather than as permanent
	 * rejections, because a permanent rejection means the RECEIVER refused under
	 * its contract and requires a status code it actually sent. Neither of these
	 * ever reached the receiver.
	 *
	 * @param array<string, mixed> $row               Claimed row, as `claim_due` shapes it.
	 * @param string               $url               Address to deliver to.
	 * @param string               $secret            Signing secret of this store.
	 * @param string               $configured_origin Address this installation reports as its own.
	 * @return array{0: DeliveryOutcome, 1: int|null} The outcome and the instant of the next attempt, or null when there is to be none.
	 */
	private function deliver( array $row, string $url, string $secret, string $configured_origin ): array {
		try {
			/*
			 * The origin is sent AS RECORDED, never re-derived at delivery time.
			 * The guard has already established that the two describe the same
			 * installation, and what a delivery states is where the sale was
			 * captured.
			 */
			$recorded_origin = (string) $row['source'];

			$this->guard_origin( $recorded_origin, $configured_origin );

			/*
			 * The bytes are read from the row ONCE, into a variable that is both
			 * signed and sent, so that no later edit can leave the signature
			 * covering one string while the request carries another. That
			 * mistake produces no local error at all: the receiver answers 401
			 * for every attempt and it looks exactly like a wrong secret.
			 */
			$raw_body = (string) $row['raw_body'];

			$signature = $this->signer->sign( $raw_body, $secret );

			$response = $this->client->post(
				$url,
				$this->headers->for_order(
					self::DELIVERY_TOPIC,
					self::DELIVERY_RESOURCE,
					self::DELIVERY_EVENT,
					$recorded_origin,
					$signature,
					self::WEBHOOK_ID,
					(int) $row['id']
				),
				$raw_body
			);

			$outcome = $this->classifier->classify( $response );

			return array( $outcome, $this->next_attempt_for( $row, $outcome ) );
		} catch ( StoreOriginMismatchException | CredentialsNotConfiguredException $configuration_fault ) {
			return array(
				DeliveryOutcome::transient_failure( null, $configuration_fault->getMessage() ),
				null,
			);
		} catch ( Throwable $unexpected ) {
			/*
			 * Something nobody anticipated: a filter another plugin installed on
			 * the outgoing request that throws, a body a filter mangled, a
			 * defect of ours. It is treated as TRANSIENT and rescheduled, which
			 * is the cheap direction to be wrong in - the expensive one closes a
			 * paid sale over a bug that may be gone in an hour.
			 */
			$outcome = DeliveryOutcome::transient_failure( null, $this->unexpected_failure_message( $unexpected ) );

			return array( $outcome, $this->next_attempt_for( $row, $outcome ) );
		}
	}

	/**
	 * Refuses an entry that belongs to another installation.
	 *
	 * This is the half of the isolation guarantee that lives in this repository.
	 * The plugin runs inside one merchant's WordPress and reads no Factuarea
	 * table, so there is no cross-company query here to isolate; what there is,
	 * is the risk of signing one shop's sales with another shop's credentials.
	 *
	 * The case is ordinary rather than exotic: a staging site is normally made
	 * by copying the production database, and that copy carries the outbox with
	 * every pending entry in it, recorded under the production address. Delivered
	 * from staging they would be signed with staging's secret - rejected if the
	 * two shops are separate, and, if both are registered under the same
	 * account, they would invoice real sales a second time.
	 *
	 * @param string $recorded_origin   Origin stored with the entry.
	 * @param string $configured_origin Origin this installation reports.
	 * @return void
	 * @throws StoreOriginMismatchException When the two are not the same installation.
	 */
	private function guard_origin( string $recorded_origin, string $configured_origin ): void {
		if ( $this->normalised_origin( $recorded_origin ) === $this->normalised_origin( $configured_origin ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Two addresses, into a message that reaches the outbox table and an admin notice that escapes on render. Escaping here would store HTML entities in the column.
		throw StoreOriginMismatchException::between( $recorded_origin, $configured_origin );
	}

	/**
	 * One shop address in the form the guard compares.
	 *
	 * WHAT IS NORMALISED, AND WHAT IS DELIBERATELY NOT:
	 *
	 *  - The SCHEME and the HOST are lower-cased. Both are case-insensitive by
	 *    specification, so a difference in case is not a difference of shop.
	 *  - A DEFAULT PORT is dropped. `https://shop.test` and
	 *    `https://shop.test:443` are one address written two ways.
	 *  - TRAILING SLASHES go. `home_url()` returns the address without one and
	 *    the stored site address may carry one, and that difference means
	 *    nothing.
	 *  - THE PATH STAYS. Two WordPress installations under one host - the
	 *    ordinary shape of a subdirectory multisite, and of any host that runs a
	 *    second shop under a folder - differ ONLY in their path. Comparing
	 *    scheme and host alone would let one deliver the other's entries, which
	 *    is the case this guard exists for.
	 *  - THE SCHEME STAYS. It is part of the address the merchant registered,
	 *    and an entry captured under the other one would go out describing a
	 *    shop the receiver has no record of, which that route answers 200 to on
	 *    purpose - a success that never produces an invoice. Refusing it names
	 *    the two addresses instead, which is a thing the merchant can act on.
	 *
	 * An address this cannot take apart is compared as it is, minus trailing
	 * slashes. Returning something unrecognisable for it would make every entry
	 * mismatch; returning the empty string would make every entry match, and
	 * that direction opens the guard.
	 *
	 * @param string $origin Address as recorded or as reported.
	 * @return string The comparable form.
	 */
	private function normalised_origin( string $origin ): string {
		$origin = rtrim( trim( $origin ), '/' );

		if ( '' === $origin ) {
			return '';
		}

		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === (string) $parts['host'] ) {
			return $origin;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );
		$path   = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		return sprintf(
			'%s://%s%s%s',
			$scheme,
			$host,
			0 === $port ? '' : ':' . (string) $port,
			$path
		);
	}

	/**
	 * The instant of the next attempt for one settled entry, or null when there
	 * is to be none.
	 *
	 * THE ATTEMPT NUMBER IS THE ROW'S PLUS ONE, and getting that wrong is the
	 * quiet mistake this comment exists to prevent. `settle` increments the
	 * counter itself, so the number of attempts that will have failed once this
	 * outcome is written is one more than the number read off the claimed row.
	 * Planning for the stale number repeats every wait: an entry would sit at
	 * sixty seconds forever, delivering nothing new and never reaching the end
	 * of its budget.
	 *
	 * The receiver's declared wait is passed straight through. It is already
	 * null when the receiver declared none, so the planner falls back to the
	 * table without anybody asking it to, and exhaustion still wins over both.
	 *
	 * @param array<string, mixed> $row     Claimed row, as `claim_due` shapes it.
	 * @param DeliveryOutcome      $outcome What the classifier made of the response.
	 * @return int|null
	 */
	private function next_attempt_for( array $row, DeliveryOutcome $outcome ): ?int {
		if ( ! $outcome->should_retry() ) {
			return null;
		}

		return $this->planner->next_attempt_at(
			( (int) $row['attempts'] ) + 1,
			( $this->clock )(),
			$outcome->retry_after_seconds
		);
	}

	/**
	 * What the merchant is shown when the delivery failed in a way nothing
	 * anticipated.
	 *
	 * The reason is relayed because it is the only thing that says what went
	 * wrong, and it is framed in English with a next step because a message that
	 * only states that something failed becomes a support ticket. No credential
	 * can reach it: the exceptions of this plugin are forbidden from carrying
	 * one, and nothing here reads a setting.
	 *
	 * @param Throwable $unexpected What was thrown.
	 * @return string
	 */
	private function unexpected_failure_message( Throwable $unexpected ): string {
		$reason = trim( $unexpected->getMessage() );

		if ( '' === $reason ) {
			$reason = get_class( $unexpected );
		}

		return sprintf(
			'The delivery failed unexpectedly: %s. The order is kept and will be tried again. If it keeps happening, check whether another plugin on this site filters outgoing requests.',
			$reason
		);
	}
}
