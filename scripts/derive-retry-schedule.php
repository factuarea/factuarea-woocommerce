<?php
/**
 * Re-derives the backoff reference file from the single retry calculator of the
 * platform, when that calculator is visible from here.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

/*
 * ---------------------------------------------------------------------------
 * WHAT THIS SCRIPT IS FOR
 * ---------------------------------------------------------------------------
 * The backoff policy of this plugin is not this plugin's to choose. It is the
 * one the platform applies to every delivery it makes, and it lives in exactly
 * one file of the monorepo:
 *
 *   backend/app/Webhooks/Domain/Service/RetryScheduleCalculator.php
 *
 * A distributed plugin cannot reach into that file at runtime - it runs inside
 * a merchant's WordPress, on the other side of the internet - so the numbers are
 * COPIED here, into `src/Contract/RetrySchedule.php`, and the copy is kept
 * honest by two things that are not the same thing:
 *
 *   - this script, which RE-DERIVES `tests/fixtures/retry-schedule.json` from
 *     the calculator whenever the monorepo happens to be beside this repository,
 *     and prints what changed;
 *   - the parity test, which compares the constants against that reference file
 *     on every single run, monorepo or no monorepo, and fails naming the
 *     interval that diverges.
 *
 * The split matters. The test can always run because the reference file is
 * versioned here; the script can only run where the source of truth is visible.
 * Merging them would mean either a test that skips on most machines or a
 * reference nobody can regenerate.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SKIP IS LOUD, AND WHY IT HAS ITS OWN EXIT CODE
 * ---------------------------------------------------------------------------
 * When the monorepo is not beside this one - which is the normal case in
 * continuous integration, and for any outside contributor - this script CANNOT
 * do its job. It says so, names the path it looked for, and exits with a code
 * that is neither success nor failure.
 *
 * Exiting 0 there would be the worse bug of the two. A check that cannot see
 * its reference and reports green is a guardrail that only looks like one: the
 * pipeline goes green, the summary says the schedule was verified, and the day
 * somebody changes an interval in the backend nothing anywhere goes red. That
 * failure mode has a name in this project and it is the reason this file spends
 * a paragraph on an exit code.
 *
 * Exiting 1 would be the other mistake: it would paint every contributor's
 * pipeline red for a condition none of them can fix, and a red that cannot be
 * fixed is a red that gets switched off.
 *
 *   0  Done. Either the reference file already matched the calculator, or it
 *      diverged and has been rewritten - the printed difference says which.
 *   1  The calculator was found but could not be read: its shape changed, or
 *      the reference file could not be written. A human has to look.
 *   3  SKIPPED. The monorepo is not visible from here, so nothing was derived
 *      and nothing was verified. Distinguishable from 0 on purpose.
 *
 * ---------------------------------------------------------------------------
 * WHY THE CALCULATOR IS TOKENISED AND NOT INCLUDED
 * ---------------------------------------------------------------------------
 * Including it would be shorter and would be wrong. That file is Laravel
 * application code: it declares strict types, sits in a namespace whose
 * autoloader is not ours, and is free to grow a dependency at any time. This
 * script runs on a bare PHP with nothing but the standard library, and it must
 * keep running there. So the file is read as TEXT and parsed with the PHP
 * tokeniser, which reads the same declarations the engine would and ignores the
 * documentation around them.
 *
 * Ignoring the documentation is not a detail. The calculator's own docblock
 * spells out `random_int(-base/10, base/10)` and `±10%` in prose; a regular
 * expression over the raw text would happily read the explanation instead of
 * the code, and would keep doing so after somebody changed the code and forgot
 * the comment - which is exactly the divergence this script exists to catch.
 *
 * Usage:
 *
 *   php scripts/derive-retry-schedule.php
 */

/**
 * Everything went as intended.
 */
const FACTUAREA_WC_EXIT_SUCCESS = 0;

/**
 * The calculator was found and could not be understood, or the reference file
 * could not be written.
 */
const FACTUAREA_WC_EXIT_ERROR = 1;

/**
 * The calculator is not visible from here. Nothing was derived and nothing was
 * verified.
 */
const FACTUAREA_WC_EXIT_SKIPPED = 3;

/**
 * Where the single retry calculator lives, relative to the root of THIS
 * repository. The two repositories are expected to be siblings, which is how
 * they are checked out on a maintainer's machine.
 */
const FACTUAREA_WC_CALCULATOR_RELATIVE_PATH = '../factuarea/backend/app/Webhooks/Domain/Service/RetryScheduleCalculator.php';

/**
 * The same path, relative to the root of the monorepo, which is how the
 * reference file records where its numbers came from.
 */
const FACTUAREA_WC_CALCULATOR_REPOSITORY_PATH = 'backend/app/Webhooks/Domain/Service/RetryScheduleCalculator.php';

/**
 * The reference file this script rewrites, relative to the root of THIS
 * repository. It has to stay equal to `RetrySchedule::REFERENCE_FILE`, which is
 * what the parity test reads.
 */
const FACTUAREA_WC_REFERENCE_RELATIVE_PATH = 'tests/fixtures/retry-schedule.json';

/**
 * Warning carried inside the reference file itself, for whoever opens it
 * without having read this script.
 */
const FACTUAREA_WC_REFERENCE_COMMENT = 'GENERATED REFERENCE - do not edit by hand. Rewritten by scripts/derive-retry-schedule.php from the single retry calculator of the backend. The parity test compares src/Contract/RetrySchedule.php against this file, interval by interval, and fails naming the one that diverges.';

/**
 * Write one line to standard output.
 *
 * Standard output rather than `echo` because this file is inspected by the
 * WordPress coding standard, whose output-escaping rule is written for pages;
 * there is no page here, and escaping a diff for HTML would make it unreadable
 * in the terminal it is actually read in.
 *
 * @param string $line Line to write, without its line break.
 * @return void
 */
function factuarea_wc_say( string $line ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem does not exist here; and the target is the terminal, not a file.
	fwrite( STDOUT, $line . PHP_EOL );
}

/**
 * Report that nothing could be derived, and stop with the skip code.
 *
 * @param string   $reason What could not be done, in one sentence.
 * @param string[] $detail Extra lines, such as the path that was looked for.
 * @return never
 */
function factuarea_wc_skip( string $reason, array $detail = array() ): never {
	factuarea_wc_say( 'SKIPPED: ' . $reason );

	foreach ( $detail as $line ) {
		factuarea_wc_say( '  ' . $line );
	}

	factuarea_wc_say( '' );
	factuarea_wc_say( 'Nothing was derived and nothing was verified. This is NOT a pass: exit code ' . FACTUAREA_WC_EXIT_SKIPPED . '.' );
	factuarea_wc_say( 'The parity test still runs, and still compares the constants against the reference file that is versioned in this repository.' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument of exit is the process exit code here, not a message: this script writes every line it emits with fwrite and never passes a string to exit.
	exit( FACTUAREA_WC_EXIT_SKIPPED );
}

/**
 * Report that something is wrong with the source or the target, and stop.
 *
 * @param string $reason What went wrong, in one sentence, with the next step.
 * @return never
 */
function factuarea_wc_fail( string $reason ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem does not exist here; and the target is the terminal, not a file.
	fwrite( STDERR, 'ERROR: ' . $reason . PHP_EOL );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument of exit is the process exit code here, not a message: this script writes every line it emits with fwrite and never passes a string to exit.
	exit( FACTUAREA_WC_EXIT_ERROR );
}

/**
 * A noise proportion rendered for a human to read.
 *
 * The default string conversion of a float is used rather than a fixed number
 * of decimals, so that a proportion the calculator states as 0.1 reads as 0.1
 * and one stated as 0.125 reads as 0.125. Padding it to a fixed width would
 * invent precision the source never claimed, in the one message somebody reads
 * when deciding whether two numbers are the same number.
 *
 * @param float $ratio Proportion, as a fraction of one.
 * @return string The proportion as it is written.
 */
function factuarea_wc_format_ratio( float $ratio ): string {
	return sprintf( '%s', $ratio );
}

/**
 * The tokens of a PHP source, with whitespace and every kind of comment
 * removed, normalised to a shape that is uniform for single-character tokens
 * and for named ones.
 *
 * Comments are dropped here, once, rather than skipped at each call site. See
 * the header: the calculator documents its own noise expression in prose, and a
 * parser that could see prose would read the explanation instead of the code.
 *
 * @param string $source PHP source to tokenise.
 * @return list<array{id: int|null, text: string}> Significant tokens, in order.
 */
function factuarea_wc_significant_tokens( string $source ): array {
	$ignored = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
	$tokens  = array();

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_string( $token ) ) {
			$tokens[] = array(
				'id'   => null,
				'text' => $token,
			);
			continue;
		}

		if ( in_array( $token[0], $ignored, true ) ) {
			continue;
		}

		$tokens[] = array(
			'id'   => $token[0],
			'text' => $token[1],
		);
	}

	return $tokens;
}

/**
 * Index of the first token of the value assigned to a class constant.
 *
 * @param list<array{id: int|null, text: string}> $tokens Significant tokens of the source.
 * @param string                                  $name   Name of the constant.
 * @return int|null Index of the first token after the `=`, or null when the constant is not declared.
 */
function factuarea_wc_constant_value_index( array $tokens, string $name ): ?int {
	$total = count( $tokens );

	for ( $index = 0; $index < $total - 2; $index++ ) {
		if ( T_CONST !== $tokens[ $index ]['id'] ) {
			continue;
		}

		if ( T_STRING !== $tokens[ $index + 1 ]['id'] || $name !== $tokens[ $index + 1 ]['text'] ) {
			continue;
		}

		if ( '=' !== $tokens[ $index + 2 ]['text'] ) {
			return null;
		}

		return $index + 3;
	}

	return null;
}

/**
 * The integer a PHP integer literal denotes.
 *
 * The calculator writes its longer intervals with digit separators - `259_200`
 * reads as three days at a glance and `259200` does not - so the separators are
 * removed before the conversion. Anything that is not a plain decimal literal
 * stops the run rather than being coerced: a hexadecimal or a float appearing
 * in that table would mean the table changed shape, which is a thing a human
 * has to look at.
 *
 * @param string $literal Token text of the literal.
 * @return int The value it denotes.
 */
function factuarea_wc_integer_literal( string $literal ): int {
	$digits = str_replace( '_', '', $literal );

	if ( 1 !== preg_match( '/^[0-9]+$/', $digits ) ) {
		factuarea_wc_fail(
			sprintf(
				'The calculator holds the literal "%s" where a plain decimal integer was expected. Read it by hand and update this script: it refuses to guess what a literal it does not recognise is worth.',
				$literal
			)
		);
	}

	return (int) $digits;
}

/**
 * The waits table of the calculator: failed attempt number to seconds.
 *
 * @param list<array{id: int|null, text: string}> $tokens Significant tokens of the calculator.
 * @return array<int, int> The table, in the order it is declared.
 */
function factuarea_wc_parse_schedule( array $tokens ): array {
	$index = factuarea_wc_constant_value_index( $tokens, 'SCHEDULE' );

	if ( null === $index ) {
		factuarea_wc_fail( 'The calculator no longer declares a constant named SCHEDULE. Its shape changed; read it by hand and update this script.' );
	}

	if ( T_ARRAY === $tokens[ $index ]['id'] ) {
		++$index;
	}

	if ( '[' !== $tokens[ $index ]['text'] && '(' !== $tokens[ $index ]['text'] ) {
		factuarea_wc_fail( 'SCHEDULE is declared as something other than an array literal. Read it by hand and update this script.' );
	}

	$schedule     = array();
	$depth        = 0;
	$pending_key  = null;
	$expect_value = false;
	$total        = count( $tokens );

	for ( ; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( '[' === $token['text'] || '(' === $token['text'] ) {
			++$depth;
			continue;
		}

		if ( ']' === $token['text'] || ')' === $token['text'] ) {
			--$depth;

			if ( 0 === $depth ) {
				break;
			}

			continue;
		}

		if ( ',' === $token['text'] ) {
			continue;
		}

		if ( T_DOUBLE_ARROW === $token['id'] ) {
			$expect_value = true;
			continue;
		}

		if ( T_LNUMBER === $token['id'] ) {
			$number = factuarea_wc_integer_literal( $token['text'] );

			if ( $expect_value ) {
				$schedule[ $pending_key ] = $number;
				$pending_key              = null;
				$expect_value             = false;
				continue;
			}

			$pending_key = $number;
			continue;
		}

		factuarea_wc_fail(
			sprintf(
				'SCHEDULE holds "%s", which is not a number, an arrow or a separator. The table stopped being a plain map of integers; read it by hand and update this script rather than letting it guess.',
				$token['text']
			)
		);
	}

	if ( array() === $schedule ) {
		factuarea_wc_fail( 'SCHEDULE was found and parsed to an empty table. Read the calculator by hand: an empty backoff table would mean no delivery is ever retried.' );
	}

	return $schedule;
}

/**
 * The maximum number of attempts the calculator declares.
 *
 * @param list<array{id: int|null, text: string}> $tokens Significant tokens of the calculator.
 * @return int Total number of attempts, the first one included.
 */
function factuarea_wc_parse_max_attempts( array $tokens ): int {
	$index = factuarea_wc_constant_value_index( $tokens, 'MAX_ATTEMPTS' );

	if ( null === $index ) {
		factuarea_wc_fail( 'The calculator no longer declares a constant named MAX_ATTEMPTS. Its shape changed; read it by hand and update this script.' );
	}

	if ( T_LNUMBER !== $tokens[ $index ]['id'] ) {
		factuarea_wc_fail( 'MAX_ATTEMPTS is declared as something other than an integer literal. Read it by hand and update this script.' );
	}

	return factuarea_wc_integer_literal( $tokens[ $index ]['text'] );
}

/**
 * The proportion of random noise the calculator applies to each base wait.
 *
 * It is read from the arguments of the FIRST `random_int` call in the file,
 * which is the one inside the calculation. Every floating point literal in that
 * call has to be the same number: the expression draws between minus and plus
 * the same proportion, and two different proportions would mean the noise stopped
 * being symmetric - which changes what "within the declared range" means for the
 * planner and its test.
 *
 * @param list<array{id: int|null, text: string}> $tokens Significant tokens of the calculator.
 * @return float The proportion, as a fraction of one.
 */
function factuarea_wc_parse_jitter_ratio( array $tokens ): float {
	$total = count( $tokens );
	$start = null;

	for ( $index = 0; $index < $total - 1; $index++ ) {
		if ( T_STRING === $tokens[ $index ]['id'] && 'random_int' === $tokens[ $index ]['text'] && '(' === $tokens[ $index + 1 ]['text'] ) {
			$start = $index + 1;
			break;
		}
	}

	if ( null === $start ) {
		factuarea_wc_fail( 'The calculator no longer calls random_int, so the proportion of noise could not be read. It may have moved to another source of randomness; read it by hand and update this script.' );
	}

	$ratios = array();
	$depth  = 0;

	for ( $index = $start; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( '(' === $token['text'] ) {
			++$depth;
			continue;
		}

		if ( ')' === $token['text'] ) {
			--$depth;

			if ( 0 === $depth ) {
				break;
			}

			continue;
		}

		if ( T_DNUMBER === $token['id'] ) {
			$ratios[] = (float) str_replace( '_', '', $token['text'] );
		}
	}

	if ( array() === $ratios ) {
		factuarea_wc_fail( 'The call to random_int carries no floating point literal, so the proportion of noise could not be read. The expression changed shape; read it by hand and update this script.' );
	}

	$first = $ratios[0];

	foreach ( $ratios as $ratio ) {
		if ( $ratio !== $first ) {
			factuarea_wc_fail(
				sprintf(
					'The call to random_int mixes the proportions %s and %s, so the noise is no longer symmetric around the base wait. Read it by hand: this script will not pick one of the two.',
					factuarea_wc_format_ratio( $first ),
					factuarea_wc_format_ratio( $ratio )
				)
			);
		}
	}

	return $first;
}

/**
 * The data the reference file currently holds, or null when it holds nothing
 * usable.
 *
 * A file that cannot be read or that does not parse is treated as absent rather
 * than as an error: it is the target of this script, and rewriting it is the
 * remedy. What it must not do is silently keep whatever was there.
 *
 * @param string $path Absolute path of the reference file.
 * @return array{intervals: array<int, int>, max_attempts: int, jitter_ratio: float}|null The data, or null.
 */
function factuarea_wc_current_reference( string $path ): ?array {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem does not exist here.
	$raw = file_get_contents( $path );

	if ( false === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	if ( ! is_array( $decoded ) || ! isset( $decoded['intervals_seconds'], $decoded['max_attempts'], $decoded['jitter_ratio'] ) ) {
		return null;
	}

	if ( ! is_array( $decoded['intervals_seconds'] ) ) {
		return null;
	}

	$intervals = array();

	foreach ( $decoded['intervals_seconds'] as $attempt => $seconds ) {
		$intervals[ (int) $attempt ] = (int) $seconds;
	}

	return array(
		'intervals'    => $intervals,
		'max_attempts' => (int) $decoded['max_attempts'],
		'jitter_ratio' => (float) $decoded['jitter_ratio'],
	);
}

/**
 * The difference between what was derived and what the reference file holds,
 * one human sentence per divergence.
 *
 * @param array{intervals: array<int, int>, max_attempts: int, jitter_ratio: float}      $derived Data read from the calculator.
 * @param array{intervals: array<int, int>, max_attempts: int, jitter_ratio: float}|null $current Data the reference file holds, or null when it holds none.
 * @return list<string> One line per divergence; empty when the two agree.
 */
function factuarea_wc_difference( array $derived, ?array $current ): array {
	if ( null === $current ) {
		return array( 'the reference file is missing or unreadable, so every value below is new' );
	}

	$lines    = array();
	$attempts = array_unique( array_merge( array_keys( $derived['intervals'] ), array_keys( $current['intervals'] ) ) );
	sort( $attempts );

	foreach ( $attempts as $attempt ) {
		$has_derived = array_key_exists( $attempt, $derived['intervals'] );
		$has_current = array_key_exists( $attempt, $current['intervals'] );

		if ( $has_derived && ! $has_current ) {
			$lines[] = sprintf( 'interval for attempt %d: absent -> %d seconds', $attempt, $derived['intervals'][ $attempt ] );
			continue;
		}

		if ( ! $has_derived && $has_current ) {
			$lines[] = sprintf( 'interval for attempt %d: %d seconds -> absent', $attempt, $current['intervals'][ $attempt ] );
			continue;
		}

		if ( $derived['intervals'][ $attempt ] !== $current['intervals'][ $attempt ] ) {
			$lines[] = sprintf( 'interval for attempt %d: %d -> %d seconds', $attempt, $current['intervals'][ $attempt ], $derived['intervals'][ $attempt ] );
		}
	}

	if ( $derived['max_attempts'] !== $current['max_attempts'] ) {
		$lines[] = sprintf( 'maximum number of attempts: %d -> %d', $current['max_attempts'], $derived['max_attempts'] );
	}

	if ( $derived['jitter_ratio'] !== $current['jitter_ratio'] ) {
		$lines[] = sprintf( 'proportion of noise: %s -> %s', factuarea_wc_format_ratio( $current['jitter_ratio'] ), factuarea_wc_format_ratio( $derived['jitter_ratio'] ) );
	}

	return $lines;
}

/**
 * The reference file as it is to be written.
 *
 * @param array{intervals: array<int, int>, max_attempts: int, jitter_ratio: float} $derived     Data read from the calculator.
 * @param string                                                                    $measured_at Date the derivation was made, as `YYYY-MM-DD`.
 * @return string The whole file, with its trailing line break.
 */
function factuarea_wc_encode_reference( array $derived, string $measured_at ): string {
	$payload = array(
		'_comment'          => FACTUAREA_WC_REFERENCE_COMMENT,
		'source'            => FACTUAREA_WC_CALCULATOR_REPOSITORY_PATH,
		'measured_at'       => $measured_at,
		'intervals_seconds' => (object) $derived['intervals'],
		'max_attempts'      => $derived['max_attempts'],
		'jitter_ratio'      => $derived['jitter_ratio'],
	);

	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode does not exist on the bare PHP this script runs on, and it would drop the pretty printing that makes the reference file reviewable in a diff.
	$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( false === $json ) {
		factuarea_wc_fail( 'The derived table could not be encoded as JSON, which should not be possible for a map of integers. Report it with the output above.' );
	}

	return $json . PHP_EOL;
}

$factuarea_wc_repository_root = dirname( __DIR__ );
$factuarea_wc_calculator_path = $factuarea_wc_repository_root . '/' . FACTUAREA_WC_CALCULATOR_RELATIVE_PATH;
$factuarea_wc_reference_path  = $factuarea_wc_repository_root . '/' . FACTUAREA_WC_REFERENCE_RELATIVE_PATH;

factuarea_wc_say( 'Factuarea for WooCommerce - deriving the backoff table from the single retry calculator' );
factuarea_wc_say( '' );

if ( ! is_file( $factuarea_wc_calculator_path ) || ! is_readable( $factuarea_wc_calculator_path ) ) {
	factuarea_wc_skip(
		'the monorepo is not visible from here, so the single retry calculator could not be read.',
		array(
			'Looked for: ' . $factuarea_wc_calculator_path,
			'This is the normal case in continuous integration and for an outside contributor: the two repositories are expected to be siblings, and here they are not.',
		)
	);
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem does not exist here.
$factuarea_wc_source = file_get_contents( $factuarea_wc_calculator_path );

if ( false === $factuarea_wc_source ) {
	factuarea_wc_fail( 'The single retry calculator was found at ' . $factuarea_wc_calculator_path . ' and could not be read. Check its permissions.' );
}

$factuarea_wc_tokens  = factuarea_wc_significant_tokens( $factuarea_wc_source );
$factuarea_wc_derived = array(
	'intervals'    => factuarea_wc_parse_schedule( $factuarea_wc_tokens ),
	'max_attempts' => factuarea_wc_parse_max_attempts( $factuarea_wc_tokens ),
	'jitter_ratio' => factuarea_wc_parse_jitter_ratio( $factuarea_wc_tokens ),
);

factuarea_wc_say( 'Source: ' . $factuarea_wc_calculator_path );
factuarea_wc_say( 'Target: ' . FACTUAREA_WC_REFERENCE_RELATIVE_PATH );
factuarea_wc_say( '' );
factuarea_wc_say( 'Derived from the calculator:' );

foreach ( $factuarea_wc_derived['intervals'] as $factuarea_wc_attempt => $factuarea_wc_seconds ) {
	factuarea_wc_say( sprintf( '  attempt %d -> %d seconds', $factuarea_wc_attempt, $factuarea_wc_seconds ) );
}

factuarea_wc_say( sprintf( '  maximum number of attempts: %d', $factuarea_wc_derived['max_attempts'] ) );
factuarea_wc_say( sprintf( '  proportion of noise: %s', factuarea_wc_format_ratio( $factuarea_wc_derived['jitter_ratio'] ) ) );
factuarea_wc_say( '' );

$factuarea_wc_current    = factuarea_wc_current_reference( $factuarea_wc_reference_path );
$factuarea_wc_difference = factuarea_wc_difference( $factuarea_wc_derived, $factuarea_wc_current );

if ( array() === $factuarea_wc_difference ) {
	factuarea_wc_say( 'Difference against the reference file: NONE. The two already say the same thing, so the file is left exactly as it is.' );
	factuarea_wc_say( 'Leaving it untouched is deliberate: rewriting a file whose data did not change would move its recorded measurement date and turn a no-op into a commit.' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument of exit is the process exit code here, not a message: this script writes every line it emits with fwrite and never passes a string to exit.
	exit( FACTUAREA_WC_EXIT_SUCCESS );
}

factuarea_wc_say( 'Difference against the reference file:' );

foreach ( $factuarea_wc_difference as $factuarea_wc_line ) {
	factuarea_wc_say( '  ' . $factuarea_wc_line );
}

$factuarea_wc_encoded = factuarea_wc_encode_reference( $factuarea_wc_derived, gmdate( 'Y-m-d' ) );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem does not exist here.
$factuarea_wc_written = file_put_contents( $factuarea_wc_reference_path, $factuarea_wc_encoded );

if ( false === $factuarea_wc_written ) {
	factuarea_wc_fail( 'The reference file at ' . $factuarea_wc_reference_path . ' could not be written. Check its permissions, then run this again.' );
}

factuarea_wc_say( '' );
factuarea_wc_say( 'Rewritten ' . FACTUAREA_WC_REFERENCE_RELATIVE_PATH . ' from the calculator.' );
factuarea_wc_say( 'Now bring src/Contract/RetrySchedule.php in line with it: the parity test compares the two and is about to fail naming each interval above.' );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument of exit is the process exit code here, not a message: this script writes every line it emits with fwrite and never passes a string to exit.
exit( FACTUAREA_WC_EXIT_SUCCESS );
