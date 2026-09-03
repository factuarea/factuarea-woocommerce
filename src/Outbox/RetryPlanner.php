<?php
/**
 * When the next delivery attempt of an outbox entry is due.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Outbox;

use Factuarea\WooCommerce\Contract\RetrySchedule;
use InvalidArgumentException;

/**
 * The one place that decides WHEN an entry is tried again.
 *
 * It decides only the instant. Whether there is to be another attempt at all is
 * the classifier's decision, carried by the delivery outcome; this class is
 * asked once that decision is already made. Keeping the two apart is what stops
 * a wait of zero from being read as "give up" and a null instant from being
 * read as "retry immediately".
 *
 * ---------------------------------------------------------------------------
 * THE TABLE IS NOT THIS CLASS'S TO CHOOSE
 * ---------------------------------------------------------------------------
 * Every number it uses comes from RetrySchedule, which is a copy of the single
 * retry calculator that governs every delivery of the platform, kept in step by
 * `scripts/derive-retry-schedule.php` and guarded by the parity test. Nothing
 * here may hard-code a wait, a maximum or a noise proportion: a second table
 * that was meant to be the same and drifted does not fail loudly, it just
 * retries at moments nobody chose.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR RULES, IN THE ORDER THEY ARE APPLIED
 * ---------------------------------------------------------------------------
 *  1. `attempt` is the number of attempts that have ALREADY FAILED, counting
 *     from 1, exactly as the single calculator counts them. Below 1 is a
 *     programming mistake and throws, because returning null instead would be
 *     indistinguishable from "the schedule is exhausted" and would silently
 *     close an entry on its way to its first retry.
 *
 *  2. EXHAUSTION WINS OVER EVERYTHING, the receiver's wait included. Once the
 *     schedule has no further interval for that attempt number there is no next
 *     attempt, and a receiver asking to be tried again in thirty seconds does
 *     not buy a ninth attempt. A receiver that keeps declaring waits would
 *     otherwise keep an entry alive forever, which is the unbounded loop the
 *     maximum exists to prevent.
 *
 *  3. A WAIT DECLARED BY THE RECEIVER BEATS THE TABLE, and is used EXACTLY as
 *     declared, with no noise added. The receiver knows why it is busy and this
 *     plugin does not. Noise is deliberately not applied to it: the noise is
 *     symmetric, so a tenth of it would land BEFORE the instant the receiver
 *     asked for, and "retries after that wait and not before" is the promise
 *     being kept. A declared wait below zero is read as zero rather than
 *     rejected - it comes from a remote party, and the only harm in it is an
 *     instant in the past, which would spin the drain.
 *
 *  4. OTHERWISE THE TABLE, with its proportional noise. The noise exists so
 *     that a receiver coming back up after an incident is not hit by every
 *     store that queued up during it at the same instant. It is computed with
 *     the same expression as the single calculator - a uniform draw between
 *     minus and plus the noise proportion of the base wait, truncated towards
 *     zero - so that the two produce the same distribution and not merely a
 *     similar one.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SOURCE OF RANDOMNESS IS INJECTABLE
 * ---------------------------------------------------------------------------
 * Because the alternative is a test that asserts a range and passes by luck. A
 * planner that drew its noise from a wider interval than declared would still
 * land inside the asserted range on most runs, and the run where it did not
 * would be filed as a flake. With the draw injected, the test can assert the
 * BOUNDS THE PLANNER ASKED FOR rather than the value it happened to get, which
 * is the thing that is actually specified. Production passes nothing and gets
 * `random_int`, which is the cryptographically secure source and the same one
 * the backend calculator uses.
 */
final class RetryPlanner {

	/**
	 * Draw of the noise: a callable taking the inclusive lower and upper bounds
	 * and returning an integer between them.
	 *
	 * @var \Closure(int, int): int
	 */
	private \Closure $random;

	/**
	 * Build a planner.
	 *
	 * @param callable|null $random Source of the noise, taking two integer bounds and returning an integer between them. Defaults to `random_int`, which is what production uses; tests pass their own so that the bounds themselves can be asserted.
	 */
	public function __construct( ?callable $random = null ) {
		$this->random = null === $random
			? static function ( int $minimum, int $maximum ): int {
				return random_int( $minimum, $maximum );
			}
			: \Closure::fromCallable( $random );
	}

	/**
	 * The instant of the next delivery attempt, as a Unix timestamp.
	 *
	 * The third parameter is OPTIONAL and LAST on purpose: the signature frozen
	 * in `Contract\Constants` is `next_attempt_at( int $attempt, int $now )`,
	 * and every call written against that signature keeps working unchanged.
	 * The alternative considered was a sibling method taking the delivery
	 * outcome, and it was rejected because it would be a second door into the
	 * same room: the exhaustion rule and the "declared wait wins" rule both have
	 * to hold on every path, and the day somebody adds a third rule to one door
	 * the other keeps the old behaviour without a single test going red.
	 *
	 * The caller unpacks the outcome, which costs one property read:
	 * `$planner->next_attempt_at( $attempt, $now, $outcome->retry_after_seconds )`.
	 * That property is already null when the receiver declared no wait, so a
	 * caller that always passes it gets the table for the cases that should get
	 * the table, without asking `has_declared_wait()` first.
	 *
	 * @param int      $attempt      Number of attempts that have ALREADY failed, counting from 1.
	 * @param int      $now          Current instant as a Unix timestamp, from which the wait is measured.
	 * @param int|null $declared_wait Wait declared by the receiver in seconds, or null when it declared none.
	 * @return int|null The Unix timestamp of the next attempt, or null when there is to be no further attempt.
	 * @throws InvalidArgumentException When the attempt number is below 1, which is a caller mistake and not an exhausted schedule.
	 */
	public function next_attempt_at( int $attempt, int $now, ?int $declared_wait = null ): ?int {
		if ( $attempt < 1 ) {
			throw new InvalidArgumentException(
				sprintf(
					'The attempt number counts attempts that have already failed and starts at 1, but %d was given. Returning "no further attempt" here would be indistinguishable from an exhausted schedule and would close an entry that has not been tried.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An integer the caller passed, formatted with %d into a message that reaches a log or a failing test and never a page. Escaping it would make the planner call a WordPress function, and this class runs under a bare PHP unit suite with no WordPress loaded.
					$attempt
				)
			);
		}

		$base_wait = RetrySchedule::base_wait( $attempt );

		if ( null === $base_wait ) {
			return null;
		}

		if ( null !== $declared_wait ) {
			return $now + max( 0, $declared_wait );
		}

		return $now + $base_wait + $this->noise_for( $base_wait );
	}

	/**
	 * The noise added to one base wait.
	 *
	 * The bounds are computed with the same expression as the single calculator
	 * of the backend, cast to integer AFTER the multiplication so that the
	 * truncation happens in the same direction on both sides. Every interval of
	 * the current table multiplies to a whole number, so the cast changes
	 * nothing today; it is written this way so that an interval added tomorrow
	 * behaves identically on both sides instead of differing by one second in a
	 * way nobody would ever look for.
	 *
	 * @param int $base_wait Base wait in seconds, before noise.
	 * @return int Noise in seconds, between minus and plus the declared proportion of the base wait.
	 */
	private function noise_for( int $base_wait ): int {
		$span = RetrySchedule::JITTER_RATIO * $base_wait;

		return ( $this->random )( (int) -$span, (int) $span );
	}
}
