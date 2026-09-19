<?php
/**
 * The retention window of the inbound ledger lives in ONE place, and that place
 * is the contract constant.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\RetrySchedule;
use Factuarea\WooCommerce\Inbound\InboundDeliveryLedger;
use Factuarea\WooCommerce\Inbound\InboundDeliveryTable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What this file measures, and what it deliberately does not.
 *
 * The ledger keeps an applied idempotency key for a window, and that window has
 * exactly one safe property: it must outlast the whole retry schedule of the
 * sender, because a retry that arrives with its key already purged is taken for
 * a new delivery and its effect is applied a SECOND time - a second refund on
 * an order, and a second corrective invoice behind it.
 *
 * That property is pinned where the number lives, in
 * `tests/Unit/InboundContractTest.php`, which compares
 * `Constants::INBOUND_RETENTION_SECONDS` with the SUM of
 * `RetrySchedule::INTERVALS` rather than with a number typed by hand. It is NOT
 * repeated here: two files asserting the same thing is two files to update and
 * one of them will be forgotten.
 *
 * What is measured HERE is the other half, the half a reviewer cannot check by
 * reading: that the ledger uses THAT window and no other. A second subtraction
 * written somewhere else - a "30 days" spelled out in the prune, a shorter
 * figure at a call site - would be a second window, and the one that got
 * shortened would be the one nobody remembered. So this file:
 *
 *  1. derives the cut-off from the constant for several instants, without
 *     typing a single duration of its own;
 *  2. sweeps the source of the ledger for any integer literal big enough to BE
 *     a wait, with "big enough" taken from the shortest wait of the shared
 *     schedule instead of from a threshold invented here;
 *  3. checks that the table does not name the window at all, because a window
 *     mentioned in the schema would be just as effective at splitting the
 *     decision in two;
 *  4. sweeps ITSELF, so that this file cannot become the place where a duration
 *     is written down either. A test that hard-codes the number it is guarding
 *     stops failing the day somebody changes the number in one of the two
 *     places.
 *
 * Unit suite: no WordPress, no database, no doubles. Everything asserted here
 * is a constant, a subtraction and the text of three files on disk.
 */
final class InboundDeliveryLedgerRetentionTest extends TestCase {

	/**
	 * The cut-off is the instant minus the contract window, and nothing else.
	 *
	 * The instants are composed out of values that already exist - the current
	 * time and the window itself - so that not one duration is typed in this
	 * file. Typing one would make this assertion pass against a copy of the
	 * number instead of against the number.
	 *
	 * @return void
	 */
	public function test_the_cutoff_is_the_instant_minus_the_contract_window(): void {
		$now     = time();
		$instants = array(
			'now'                  => $now,
			'the window itself'    => Constants::INBOUND_RETENTION_SECONDS,
			'a window from now on' => $now + Constants::INBOUND_RETENTION_SECONDS,
		);

		foreach ( $instants as $description => $instant ) {
			$this->assertSame(
				$instant - Constants::INBOUND_RETENTION_SECONDS,
				InboundDeliveryLedger::prune_cutoff( $instant ),
				"The cut-off for {$description} must be that instant minus the contract window."
			);
		}
	}

	/**
	 * Moving the cut-off back by the window leaves exactly one window between
	 * the two, which is what makes the prune a window and not a point.
	 *
	 * @return void
	 */
	public function test_the_cutoff_moves_with_the_instant_it_is_asked_about(): void {
		$now = time();

		$this->assertSame(
			Constants::INBOUND_RETENTION_SECONDS,
			InboundDeliveryLedger::prune_cutoff( $now + Constants::INBOUND_RETENTION_SECONDS ) - InboundDeliveryLedger::prune_cutoff( $now ),
			'Two cut-offs one window apart have to be one window apart.'
		);
	}

	/**
	 * The ledger names the contract window, and names it once.
	 *
	 * Once and not "at least once": the cut-off is composed in a single place
	 * on purpose, and a second mention would mean a second subtraction that a
	 * later edit could shorten on its own.
	 *
	 * @return void
	 */
	public function test_the_ledger_reads_the_window_from_the_contract_exactly_once(): void {
		$code = $this->codeWithoutComments( InboundDeliveryLedger::class );

		$this->assertSame(
			1,
			substr_count( $code, 'Constants::INBOUND_RETENTION_SECONDS' ),
			'The ledger has to take its window from the contract constant, in exactly one place.'
		);
	}

	/**
	 * The ledger writes no figure that could be a wait.
	 *
	 * The threshold is the SHORTEST wait of the shared retry schedule, read
	 * from `RetrySchedule::INTERVALS`, so this file does not decide what counts
	 * as a duration either: anything at least as long as the shortest wait the
	 * sender ever takes is long enough to be mistaken for one.
	 *
	 * The row counts the ledger does use are well below it, so they pass
	 * without an allow-list - and an allow-list is exactly what would let a
	 * real duration through the day somebody added it next to one of them.
	 *
	 * The sweep is over the LEDGER and not over the table: the table writes the
	 * width of its key column, which is a figure with nothing to do with time,
	 * and the table is covered instead by the assertion below that it does not
	 * name the window at all.
	 *
	 * @return void
	 */
	public function test_the_ledger_writes_no_figure_that_could_be_a_wait(): void {
		$shortest_wait = min( RetrySchedule::INTERVALS );

		foreach ( $this->integerLiteralsOf( InboundDeliveryLedger::class ) as $literal ) {
			$this->assertLessThan(
				$shortest_wait,
				$literal,
				"The ledger writes the figure {$literal}, which is long enough to be a wait. The retention window "
				. 'belongs in Constants::INBOUND_RETENTION_SECONDS and nowhere else.'
			);
		}
	}

	/**
	 * The table does not mention the retention window at all.
	 *
	 * The schema describes the shape of the rows; how long they are kept is a
	 * decision of the ledger. Splitting that decision across the two files is
	 * how a schema comment and a prune end up disagreeing.
	 *
	 * @return void
	 */
	public function test_the_table_does_not_mention_the_retention_window(): void {
		$this->assertSame(
			0,
			substr_count( $this->codeWithoutComments( InboundDeliveryTable::class ), 'INBOUND_RETENTION_SECONDS' ),
			'How long a delivery is kept is not part of the table description.'
		);
	}

	/**
	 * And this file writes no figure that could be a wait either.
	 *
	 * A test that spells out the number it is guarding is a copy of that
	 * number, and a copy stops failing the day the original moves. The sweep
	 * runs over this very file so that the rule cannot be broken here first.
	 *
	 * @return void
	 */
	public function test_this_file_writes_no_figure_that_could_be_a_wait(): void {
		$shortest_wait = min( RetrySchedule::INTERVALS );

		foreach ( $this->integerLiteralsOf( self::class ) as $literal ) {
			$this->assertLessThan(
				$shortest_wait,
				$literal,
				"This file writes the figure {$literal}, which is long enough to be a wait, so it would be asserting "
				. 'against a copy of the window instead of against the window.'
			);
		}
	}

	/**
	 * Every integer literal of a class file, comments excluded.
	 *
	 * @param class-string $class Class whose file is swept.
	 * @return list<int>
	 *
	 * @throws RuntimeException When the file cannot be located or read.
	 */
	private function integerLiteralsOf( string $class ): array {
		$literals = array();

		foreach ( token_get_all( $this->codeWithoutComments( $class ) ) as $token ) {
			if ( is_array( $token ) && T_LNUMBER === $token[0] ) {
				$literals[] = (int) str_replace( '_', '', $token[1] );
			}
		}

		return $literals;
	}

	/**
	 * The source of a class with its comments blanked out.
	 *
	 * Comments go because a docblock that EXPLAINS the window - and the ones in
	 * these files do, at length - would otherwise be read as the window being
	 * written down twice. Each comment is replaced by its own newlines, so the
	 * sweep reads what the file DOES while reported line numbers still point at
	 * the real lines.
	 *
	 * @param class-string $class Class whose file is read.
	 * @return string
	 *
	 * @throws RuntimeException When the file cannot be located or read.
	 */
	private function codeWithoutComments( string $class ): string {
		$path = ( new ReflectionClass( $class ) )->getFileName();

		if ( false === $path || ! is_file( $path ) ) {
			throw new RuntimeException( "The file of {$class} could not be located on disk." );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file on disk, not a remote URL; WordPress is not loaded in this suite.
		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new RuntimeException( "The file of {$class} could not be read." );
		}

		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}
}
