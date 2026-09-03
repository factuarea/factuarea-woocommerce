<?php
/**
 * When the planner says the next delivery attempt is due.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\RetrySchedule;
use Factuarea\WooCommerce\Outbox\RetryPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What this file measures, and why the randomness is injected rather than
 * tolerated.
 *
 * The planner is a handful of additions, and every way of getting it wrong
 * produces a plausible timestamp. A table read one position off retries after
 * five minutes instead of one; noise drawn from a wider interval than declared
 * lands inside the expected range on most runs; a declared wait quietly ignored
 * looks exactly like a declared wait honoured, until a receiver that asked for
 * an hour is hammered for a day. None of them throws, and the only symptom is a
 * pattern of delivery times that nobody would ever look at.
 *
 * So the source of randomness is a constructor parameter. That lets these cases
 * assert THE BOUNDS THE PLANNER ASKED FOR rather than the value it happened to
 * get, which is the thing that is actually specified: a planner drawing between
 * plus and minus a fifth of the base wait would pass a range assertion nineteen
 * times out of twenty, and the twentieth would be filed as a flake.
 *
 * The last case is the counterweight. It builds the planner the way production
 * builds it - with nothing passed, so the real `random_int` is used - and
 * checks the bounds hold over many draws. Without it, every case here would be
 * measuring a planner nobody ships.
 *
 * Unit suite: no WordPress, no database. The planner is plain PHP and takes the
 * current instant as an argument, so nothing here depends on a clock either.
 */
final class RetryPlannerTest extends TestCase {

	/**
	 * An arbitrary instant, fixed so that every expectation in this file is an
	 * exact number and not an interval around "now".
	 */
	private const NOW = 1_800_000_000;

	/**
	 * The first failure schedules the first interval of the table.
	 *
	 * @return void
	 */
	public function test_the_first_failure_schedules_the_first_interval(): void {
		$planner = $this->planner_drawing( 0 );

		$this->assertSame(
			self::NOW + RetrySchedule::INTERVALS[1],
			$planner->next_attempt_at( 1, self::NOW ),
			'One failed attempt has to be followed by the first wait of the table.'
		);
	}

	/**
	 * The seventh failure schedules the last interval of the table.
	 *
	 * @return void
	 */
	public function test_the_seventh_failure_schedules_the_last_interval(): void {
		$last    = RetrySchedule::MAX_ATTEMPTS - 1;
		$planner = $this->planner_drawing( 0 );

		$this->assertSame(
			self::NOW + RetrySchedule::INTERVALS[ $last ],
			$planner->next_attempt_at( $last, self::NOW ),
			'The last failed attempt that still has a wait has to be followed by the longest wait of the table, not by an early exit.'
		);
	}

	/**
	 * The eighth failure schedules nothing.
	 *
	 * @return void
	 */
	public function test_the_failure_that_exhausts_the_schedule_plans_no_further_attempt(): void {
		$planner = $this->planner_drawing( 0 );

		$this->assertNull(
			$planner->next_attempt_at( RetrySchedule::MAX_ATTEMPTS, self::NOW ),
			'Once every attempt the policy allows has failed there is no further wait to plan, and the entry leaves the retry loop.'
		);
	}

	/**
	 * Every attempt of the table is read from the table, and none of them is
	 * written into the planner.
	 *
	 * With the noise fixed at zero the result is the base wait exactly, so this
	 * walks the whole policy in one case. It exists so that a planner that
	 * happened to hard-code the first and the last interval - the two the cases
	 * above pin - would still be caught on the five in between.
	 *
	 * @return void
	 */
	public function test_every_wait_comes_from_the_shared_table(): void {
		$planner = $this->planner_drawing( 0 );

		foreach ( RetrySchedule::INTERVALS as $attempt => $base_wait ) {
			$this->assertSame(
				self::NOW + $base_wait,
				$planner->next_attempt_at( $attempt, self::NOW ),
				sprintf( 'The wait planned after failed attempt %d is not the one the shared table declares.', $attempt )
			);
		}
	}

	/**
	 * The noise is drawn between minus and plus the declared proportion of the
	 * base wait, and never from a wider interval.
	 *
	 * The bounds the planner ASKS FOR are recorded and asserted, which is the
	 * half a range assertion cannot see: a planner drawing from a wider interval
	 * lands inside the expected range on almost every run.
	 *
	 * @return void
	 */
	public function test_the_noise_is_drawn_between_minus_and_plus_the_declared_proportion(): void {
		$asked   = array();
		$planner = new RetryPlanner(
			static function ( int $minimum, int $maximum ) use ( &$asked ): int {
				$asked[] = array( $minimum, $maximum );

				return $minimum;
			}
		);

		foreach ( RetrySchedule::INTERVALS as $attempt => $base_wait ) {
			$asked = array();
			$span  = (int) ( RetrySchedule::JITTER_RATIO * $base_wait );

			$planned = $planner->next_attempt_at( $attempt, self::NOW );

			$this->assertSame(
				array( array( -$span, $span ) ),
				$asked,
				sprintf(
					'After failed attempt %d the planner has to draw its noise exactly once, between %d and %d seconds. Drawing from a wider interval would still land inside the declared range on most runs, which is why the bounds are asserted and not the result.',
					$attempt,
					-$span,
					$span
				)
			);

			$this->assertSame(
				self::NOW + $base_wait - $span,
				$planned,
				sprintf( 'The lowest draw after failed attempt %d has to produce the earliest instant the policy allows.', $attempt )
			);
		}
	}

	/**
	 * The highest draw produces the latest instant the policy allows.
	 *
	 * @return void
	 */
	public function test_the_highest_draw_stays_inside_the_declared_range(): void {
		$planner = new RetryPlanner(
			static function ( int $minimum, int $maximum ): int {
				unset( $minimum );

				return $maximum;
			}
		);

		foreach ( RetrySchedule::INTERVALS as $attempt => $base_wait ) {
			$span = (int) ( RetrySchedule::JITTER_RATIO * $base_wait );

			$this->assertSame(
				self::NOW + $base_wait + $span,
				$planner->next_attempt_at( $attempt, self::NOW ),
				sprintf( 'The highest draw after failed attempt %d has to produce the latest instant the policy allows.', $attempt )
			);
		}
	}

	/**
	 * A wait declared by the receiver beats the table.
	 *
	 * @return void
	 */
	public function test_a_wait_declared_by_the_receiver_beats_the_table(): void {
		$planner = $this->planner_that_must_not_draw();

		$this->assertSame(
			self::NOW + 45,
			$planner->next_attempt_at( 1, self::NOW, 45 ),
			'The receiver said to wait forty-five seconds while the table says sixty. The receiver knows why it is busy and this plugin does not, so the receiver wins.'
		);
	}

	/**
	 * A declared wait is honoured exactly, with no noise added.
	 *
	 * The planner used here fails the test if it draws at all, which is the
	 * point: the noise is symmetric, so a tenth of it applied to a declared wait
	 * would land BEFORE the instant the receiver asked for. "Retries after that
	 * wait and not before" is the promise, and shaving it by a tenth breaks the
	 * promise in the direction that hurts a receiver already asking for room.
	 *
	 * @return void
	 */
	public function test_a_declared_wait_carries_no_noise(): void {
		$planner  = $this->planner_that_must_not_draw();
		$declared = RetrySchedule::INTERVALS[1];

		$this->assertSame(
			self::NOW + $declared,
			$planner->next_attempt_at( 1, self::NOW, $declared ),
			'A wait the receiver declared has to be used as declared. Here it equals the table value on purpose: without the source of randomness refusing to be called, a noisy implementation would be indistinguishable from a faithful one.'
		);
	}

	/**
	 * An exhausted schedule beats a wait declared by the receiver.
	 *
	 * @return void
	 */
	public function test_an_exhausted_schedule_beats_a_wait_declared_by_the_receiver(): void {
		$planner = $this->planner_that_must_not_draw();

		$this->assertNull(
			$planner->next_attempt_at( RetrySchedule::MAX_ATTEMPTS, self::NOW, 30 ),
			'A receiver asking to be tried again does not buy a further attempt once the policy is exhausted. Otherwise a receiver that keeps declaring waits keeps an entry alive for ever, which is the unbounded loop the maximum exists to prevent.'
		);
	}

	/**
	 * A declared wait below zero never schedules an attempt in the past.
	 *
	 * @return void
	 */
	public function test_a_declared_wait_below_zero_never_schedules_an_attempt_in_the_past(): void {
		$planner = $this->planner_that_must_not_draw();

		$this->assertSame(
			self::NOW,
			$planner->next_attempt_at( 1, self::NOW, -600 ),
			'The declared wait comes from a remote party. A negative one would put the next attempt in the past, and the drain would take that entry on every pass without ever waiting.'
		);
	}

	/**
	 * A declared wait of zero means the next pass, not "no wait declared".
	 *
	 * @return void
	 */
	public function test_a_declared_wait_of_zero_is_a_declared_wait(): void {
		$planner = $this->planner_that_must_not_draw();

		$this->assertSame(
			self::NOW,
			$planner->next_attempt_at( 1, self::NOW, 0 ),
			'Zero is a wait the receiver declared and not the absence of one. Falling back to the table here would make a receiver saying "try again now" wait a minute.'
		);
	}

	/**
	 * An attempt number below one is rejected rather than answered.
	 *
	 * @param int $attempt Attempt number that must be refused.
	 * @return void
	 *
	 * @dataProvider attempt_numbers_below_one
	 */
	public function test_an_attempt_number_below_one_is_rejected( int $attempt ): void {
		$planner = $this->planner_drawing( 0 );

		$this->expectException( InvalidArgumentException::class );

		$planner->next_attempt_at( $attempt, self::NOW );
	}

	/**
	 * Attempt numbers that count nothing.
	 *
	 * @return array<string, array{int}>
	 */
	public static function attempt_numbers_below_one(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * The planner production builds - with the real source of randomness - keeps
	 * every result inside the declared range.
	 *
	 * Every other case in this file passes its own draw, so none of them
	 * exercises the wiring a merchant actually runs. This one does, over enough
	 * draws that a bound off by one would show, and it is the reason the default
	 * argument is allowed to exist at all.
	 *
	 * @return void
	 */
	public function test_the_planner_production_builds_stays_inside_the_declared_range(): void {
		$planner = new RetryPlanner();

		foreach ( RetrySchedule::INTERVALS as $attempt => $base_wait ) {
			$span = (int) ( RetrySchedule::JITTER_RATIO * $base_wait );

			for ( $draw = 0; $draw < 200; $draw++ ) {
				$planned = $planner->next_attempt_at( $attempt, self::NOW );

				$this->assertGreaterThanOrEqual(
					self::NOW + $base_wait - $span,
					$planned,
					sprintf( 'The real source of randomness produced an instant earlier than the policy allows after failed attempt %d.', $attempt )
				);

				$this->assertLessThanOrEqual(
					self::NOW + $base_wait + $span,
					$planned,
					sprintf( 'The real source of randomness produced an instant later than the policy allows after failed attempt %d.', $attempt )
				);
			}
		}
	}

	/**
	 * A planner whose noise is always the same number.
	 *
	 * @param int $noise Seconds of noise every draw returns.
	 * @return RetryPlanner
	 */
	private function planner_drawing( int $noise ): RetryPlanner {
		return new RetryPlanner(
			static function ( int $minimum, int $maximum ) use ( $noise ): int {
				unset( $minimum, $maximum );

				return $noise;
			}
		);
	}

	/**
	 * A planner whose source of randomness fails the test if it is used.
	 *
	 * It is what turns "the declared wait was honoured" from a coincidence into
	 * a measurement: a value that happens to match can be produced by an
	 * implementation that added noise of zero, and this one cannot.
	 *
	 * @return RetryPlanner
	 */
	private function planner_that_must_not_draw(): RetryPlanner {
		return new RetryPlanner(
			function ( int $minimum, int $maximum ): int {
				$this->fail(
					sprintf(
						'The planner drew noise between %d and %d where none was allowed: either a wait the receiver declared was about to be perturbed, or an exhausted schedule was about to be given one more attempt.',
						$minimum,
						$maximum
					)
				);
			}
		);
	}
}
