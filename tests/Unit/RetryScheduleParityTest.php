<?php
/**
 * The plugin's copy of the backoff table against the reference derived from the
 * single retry calculator.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\RetrySchedule;
use PHPUnit\Framework\TestCase;

/**
 * What this file measures, and why it is a test and not a script.
 *
 * `RetrySchedule` is a COPY. The numbers belong to the single retry calculator
 * of the platform, and they are duplicated here only because a plugin running
 * inside a merchant's WordPress cannot reach into that repository. A copy with
 * no mechanism keeping it honest is a copy that drifts, and this particular
 * drift is invisible: two backoff policies that disagree do not throw, do not
 * warn and do not fail a delivery. They retry at different moments, and the
 * only symptom is deliveries arriving in a pattern nobody chose.
 *
 * `scripts/derive-retry-schedule.php` re-derives the reference file from the
 * calculator, but it can only run where the monorepo is beside this repository,
 * which is neither continuous integration nor an outside contributor's machine.
 * It SKIPS loudly there, with its own exit code, precisely so that nobody reads
 * its silence as a pass.
 *
 * This test is the half that always runs. The reference file is versioned in
 * this repository, so the comparison needs nothing but PHP, and it is therefore
 * the thing that actually fails when somebody edits a constant by hand - which
 * the constant's own documentation forbids and which no documentation has ever
 * prevented on its own.
 *
 * Every case names the value that diverges rather than reporting that two
 * arrays are different. An assertion that prints two seven-element maps and
 * leaves the reader to spot the changed number is a failure message that gets
 * skimmed.
 *
 * Unit suite: no WordPress, no database. A constant and a JSON file.
 */
final class RetryScheduleParityTest extends TestCase {

	/**
	 * The reference file, decoded, shared by every case in this file.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $reference = null;

	/**
	 * The reference file is where the constant says it is, and it is readable.
	 *
	 * This runs first because everything else in this file reads it, and because
	 * the path is itself part of the agreement: the constant names it, the
	 * generator rewrites it and this test reads it. Three places holding the
	 * same path is how one of them quietly starts pointing somewhere else, so
	 * the path is taken FROM the constant here rather than written again.
	 *
	 * @return void
	 */
	public function test_the_reference_file_is_where_the_contract_says_it_is(): void {
		$path = self::repository_root() . '/' . RetrySchedule::REFERENCE_FILE;

		$this->assertFileExists(
			$path,
			sprintf(
				'RetrySchedule::REFERENCE_FILE names "%s", and there is no file there. Either the reference moved and the constant was not updated, or it was deleted - in which case this parity check has nothing to compare against and the copy of the backoff table is unguarded.',
				RetrySchedule::REFERENCE_FILE
			)
		);

		$this->assertIsReadable( $path, 'The reference file exists and cannot be read, so the parity of the backoff table cannot be established.' );
	}

	/**
	 * The reference file carries the three things the plugin copies.
	 *
	 * @return void
	 */
	public function test_the_reference_file_carries_the_three_values_that_are_copied(): void {
		$reference = $this->reference();

		foreach ( array( 'intervals_seconds', 'max_attempts', 'jitter_ratio' ) as $field ) {
			$this->assertArrayHasKey(
				$field,
				$reference,
				sprintf(
					'The reference file has no "%s". Re-derive it with `php scripts/derive-retry-schedule.php` from a checkout that sits beside the monorepo; do not fill it in by hand.',
					$field
				)
			);
		}

		$this->assertIsArray( $reference['intervals_seconds'], 'The intervals of the reference file are not a map of attempt number to seconds.' );
		$this->assertNotSame( array(), $reference['intervals_seconds'], 'The reference file declares no interval at all, which would mean no delivery is ever retried.' );
	}

	/**
	 * The constant declares exactly the attempts the reference declares.
	 *
	 * Checked apart from the values, and before them, because the two failures
	 * need different remedies: an interval that changed value is a number to
	 * copy across, and an interval that appeared or vanished is a change in the
	 * SHAPE of the policy - one more retry, or one fewer - which is a decision
	 * somebody made in the backend and which nobody may absorb here without
	 * noticing.
	 *
	 * @return void
	 */
	public function test_the_constant_declares_neither_more_nor_fewer_intervals_than_the_reference(): void {
		$expected = array_keys( $this->reference_intervals() );
		$declared = array_keys( RetrySchedule::INTERVALS );

		sort( $expected );
		sort( $declared );

		$missing = array_values( array_diff( $expected, $declared ) );
		$extra   = array_values( array_diff( $declared, $expected ) );

		$this->assertSame(
			array(),
			$missing,
			sprintf(
				'The reference file declares an interval for attempt %s and RetrySchedule::INTERVALS does not. The platform retries once more than this plugin does, so the last attempt the platform makes is one this plugin never makes.',
				implode( ', ', $missing )
			)
		);

		$this->assertSame(
			array(),
			$extra,
			sprintf(
				'RetrySchedule::INTERVALS declares an interval for attempt %s and the reference file does not. This plugin retries once more than the platform does, past the point the platform considers the delivery finished.',
				implode( ', ', $extra )
			)
		);
	}

	/**
	 * Every interval of the constant equals the one the reference declares, and
	 * the failure names the attempt it belongs to.
	 *
	 * @return void
	 */
	public function test_every_interval_matches_the_reference_one_by_one(): void {
		foreach ( $this->reference_intervals() as $attempt => $seconds ) {
			$this->assertSame(
				$seconds,
				RetrySchedule::INTERVALS[ $attempt ] ?? null,
				sprintf(
					'The wait after failed attempt %d diverges: the single retry calculator says %d seconds and RetrySchedule::INTERVALS says %s. Do not edit the constant to taste - re-derive the reference with `php scripts/derive-retry-schedule.php` and bring the constant in line with what comes out.',
					$attempt,
					$seconds,
					var_export( RetrySchedule::INTERVALS[ $attempt ] ?? null, true )
				)
			);
		}
	}

	/**
	 * The maximum number of attempts matches.
	 *
	 * @return void
	 */
	public function test_the_maximum_number_of_attempts_matches_the_reference(): void {
		$this->assertSame(
			(int) $this->reference()['max_attempts'],
			RetrySchedule::MAX_ATTEMPTS,
			'The maximum number of attempts diverges from the single retry calculator. A plugin that gives up sooner drops sales the platform would still have accepted; one that gives up later keeps retrying a delivery the platform has already written off.'
		);
	}

	/**
	 * The proportion of noise matches, compared exactly.
	 *
	 * Exactly, and not within a tolerance: a tolerance here would accept a
	 * proportion that is nearly the declared one, and "nearly the same noise"
	 * is not a thing anybody decided. The two numbers are literals on both
	 * sides, so exact equality is what they are.
	 *
	 * @return void
	 */
	public function test_the_proportion_of_noise_matches_the_reference(): void {
		$this->assertSame(
			(float) $this->reference()['jitter_ratio'],
			RetrySchedule::JITTER_RATIO,
			'The proportion of random noise diverges from the single retry calculator. The noise exists so that every store queued up during an incident does not hit the receiver at the same instant when it comes back, and two different proportions spread two different populations.'
		);
	}

	/**
	 * The table and the maximum say the same thing about how many attempts
	 * there are.
	 *
	 * They are two numbers describing one policy, and they can disagree without
	 * either of them being wrong on its own: a table of seven waits and a
	 * maximum of eight attempts are coherent, a table of seven waits and a
	 * maximum of six is a plugin that plans a wait it will never honour. The
	 * planner reads the table and stops when it runs out, so the maximum is the
	 * value that would be silently ignored.
	 *
	 * @return void
	 */
	public function test_the_table_and_the_maximum_describe_the_same_policy(): void {
		$this->assertSame(
			RetrySchedule::MAX_ATTEMPTS - 1,
			count( RetrySchedule::INTERVALS ),
			'The table holds a number of waits that does not match the declared maximum of attempts. There is one wait BETWEEN each pair of consecutive attempts, so a maximum of N attempts needs exactly N-1 waits.'
		);

		$this->assertSame(
			range( 1, RetrySchedule::MAX_ATTEMPTS - 1 ),
			array_keys( RetrySchedule::INTERVALS ),
			'The table is not keyed by consecutive failed-attempt numbers starting at 1. The planner looks each attempt up by that number, so a gap in the keys is an attempt that silently ends the retry loop early.'
		);
	}

	/**
	 * The intervals the reference declares, keyed by integer attempt number.
	 *
	 * JSON has no integer object keys, so they come back as strings and are
	 * cast here, in one place. Comparing an integer constant against a string
	 * key with a loose comparison is how a parity test passes while comparing
	 * nothing at all.
	 *
	 * @return array<int, int>
	 */
	private function reference_intervals(): array {
		$intervals = array();

		/** @var array<string, mixed> $declared */
		$declared = $this->reference()['intervals_seconds'];

		foreach ( $declared as $attempt => $seconds ) {
			$intervals[ (int) $attempt ] = (int) $seconds;
		}

		ksort( $intervals );

		return $intervals;
	}

	/**
	 * The decoded reference file.
	 *
	 * @return array<string, mixed>
	 */
	private function reference(): array {
		if ( null !== self::$reference ) {
			return self::$reference;
		}

		$path = self::repository_root() . '/' . RetrySchedule::REFERENCE_FILE;
		$raw  = file_get_contents( $path );

		$this->assertIsString( $raw, sprintf( 'The reference file at %s could not be read.', $path ) );

		$decoded = json_decode( $raw, true );

		$this->assertIsArray(
			$decoded,
			sprintf(
				'The reference file at %s is not valid JSON. It is written by `php scripts/derive-retry-schedule.php`; if it was edited by hand, re-derive it.',
				$path
			)
		);

		self::$reference = $decoded;

		return self::$reference;
	}

	/**
	 * Root of this repository, derived from this file's own location.
	 *
	 * @return string
	 */
	private static function repository_root(): string {
		return dirname( __DIR__, 2 );
	}
}
