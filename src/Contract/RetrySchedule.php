<?php
/**
 * The backoff table, derived from the single retry calculator of the backend.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * Waits between delivery attempts, the maximum number of attempts and the
 * proportion of random noise.
 *
 * ---------------------------------------------------------------------------
 * DO NOT EDIT THIS TABLE BY HAND
 * ---------------------------------------------------------------------------
 * These numbers are not this plugin's to choose. They are a COPY of the single
 * retry calculator that governs every delivery of the platform, and the copy
 * exists only because a distributed plugin cannot reach into that repository at
 * runtime. Two backoff policies that were meant to be the same and drifted
 * apart do not fail loudly: they retry at different moments, and the difference
 * only shows up as deliveries piling on a receiver that is already struggling.
 *
 * The way to change them is:
 *
 *   1. change the single calculator in the backend;
 *   2. run `php scripts/derive-retry-schedule.php` with the monorepo beside
 *      this one, which rewrites `tests/fixtures/retry-schedule.json` from it
 *      and prints the difference;
 *   3. bring the constants below in line with that reference file.
 *
 * The parity test compares the two, interval by interval, and fails naming the
 * one that diverges. When the generator cannot see the monorepo it SKIPS,
 * naming the reason, with an exit code that is distinguishable from success: a
 * check that cannot see its reference and reports green is a guardrail that
 * only looks like one.
 *
 * ---------------------------------------------------------------------------
 * WHAT `attempt` MEANS
 * ---------------------------------------------------------------------------
 * `attempt` is the number of attempts that have ALREADY FAILED, counting from
 * 1, exactly as the calculator counts them. An entry whose first attempt just
 * failed is planned with `attempt = 1` and waits the first interval before its
 * second attempt. After the seventh failure there is no eighth wait, so the
 * entry leaves the retry loop and is settled as exhausted.
 *
 * Measured against the backend calculator on 2026-09-02: seven intervals of
 * 60, 300, 1800, 7200, 43200, 86400 and 259200 seconds, a maximum of 8 attempts
 * and uniform noise of plus or minus 10 per cent applied to the base wait.
 */
final class RetrySchedule {

	/**
	 * Failed attempt number to seconds to wait before the next attempt, before
	 * noise is applied.
	 *
	 * @var array<int, int>
	 */
	public const INTERVALS = array(
		1 => 60,
		2 => 300,
		3 => 1800,
		4 => 7200,
		5 => 43200,
		6 => 86400,
		7 => 259200,
	);

	/**
	 * Total number of attempts an entry gets, the first one included. Once this
	 * many attempts have failed there is no further wait to plan.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Proportion of uniform random noise applied to each base wait, so that a
	 * receiver coming back up is not hit by every store at the same instant.
	 * The resulting wait lies between 0.9 and 1.1 times the base one.
	 */
	public const JITTER_RATIO = 0.1;

	/**
	 * Path of the reference file, relative to the repository root, that the
	 * generator rewrites and the parity test compares against.
	 */
	public const REFERENCE_FILE = 'tests/fixtures/retry-schedule.json';

	/**
	 * The table as a plain map, for the planner and for the parity test.
	 *
	 * @return array<int, int>
	 */
	public static function intervals(): array {
		return self::INTERVALS;
	}

	/**
	 * Base wait for a given number of failed attempts, or null when there is no
	 * further attempt to plan.
	 *
	 * @param int $attempt Number of attempts already failed, counting from 1.
	 * @return int|null Seconds to wait, or null when the schedule is exhausted.
	 */
	public static function base_wait( int $attempt ): ?int {
		return self::INTERVALS[ $attempt ] ?? null;
	}

	/**
	 * Not instantiable: this is a frozen table, not an object.
	 */
	private function __construct() {
	}
}
