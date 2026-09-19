<?php
/**
 * The version-coherence gate, measured against itself.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What this file measures, and why this gate in particular needs a test.
 *
 * `readme.txt` tells the public plugin directory four things about versions:
 * the WordPress it needs, the WordPress it was tested against, and the same two
 * for WooCommerce. Three of those numbers are repeated elsewhere - the plugin
 * header and `Contract\Constants` carry the two minimums as well - and until
 * this gate existed nothing compared them. The failure is silent by
 * construction: a listing that promises a range whose floor is above its
 * ceiling, or a minimum that the code does not enforce, is published exactly as
 * written and looks right to everybody who reads it.
 *
 * So the cases below are split by what each one would catch:
 *
 *   - a coherent listing coming out red, which is the failure that trains
 *     people to raise the threshold until the gate says nothing;
 *   - each of the three incoherent listings coming out green, which is the
 *     failure the gate exists to prevent, one shape at a time;
 *   - a finding that does not name the values or the line it is about, which is
 *     a red nobody can act on;
 *   - the emission mode printing something other than the declared pair, which
 *     would send the revalidation job at a WordPress nobody claimed;
 *   - the emission mode printing a pair it did not verify, which would turn a
 *     broken declaration into a green run against whatever it happened to say;
 *   - and the one regression that D11 of `design.md` is about: the gate
 *     starting to compare the declaration with the pin of the containerised
 *     environment, which would put a legitimately measured claim in red and
 *     push somebody to lower it.
 *
 * The gate is exercised as a SUBPROCESS rather than included, for the same two
 * reasons as its two siblings: its contract with the repository is a command
 * line and three exit codes, which can only be observed from outside, and the
 * file ends by exiting with its own result, so including it would run it.
 *
 * Standard output and standard error are captured SEPARATELY here, which the
 * sibling gate tests do not need to do. The emission mode of this gate is meant
 * to be appended to a file that continuous integration reads, so "prints
 * exactly the pair" is a claim about standard output alone; merging the two
 * streams would let a warning travel into that file unnoticed.
 *
 * Unit suite: no WordPress, no database, no doubles. A PHP binary, four
 * fixtures and the files of this repository.
 */
final class TestedUpToGateTest extends TestCase {

	/**
	 * Exit code of a coherent run.
	 *
	 * @var int
	 */
	private const EXIT_OK = 0;

	/**
	 * Exit code of a run with findings.
	 *
	 * @var int
	 */
	private const EXIT_FINDINGS = 1;

	/**
	 * Exit code of a run the gate refused to make.
	 *
	 * @var int
	 */
	private const EXIT_MISCONFIGURED = 2;

	/**
	 * The declaration this repository publishes today is coherent.
	 *
	 * This is the gate in its production configuration - no fixture, no
	 * override - and it is the case that keeps every other one honest: the four
	 * fixtures could all behave perfectly against a gate that could not read the
	 * real listing at all.
	 *
	 * @return void
	 */
	public function test_the_declaration_of_this_repository_is_coherent(): void {
		$result = $this->run_gate( array() );

		$this->assertSame(
			self::EXIT_OK,
			$result['status'],
			'The version declarations of this repository disagree: ' . $result['stdout'] . $result['stderr']
		);
	}

	/**
	 * A coherent listing passes, and the report says what it found rather than
	 * only that it found nothing.
	 *
	 * @return void
	 */
	public function test_a_coherent_listing_passes(): void {
		$result = $this->run_gate( array( '--listing', $this->fixture( 'coherent.txt' ) ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( '7.2', $result['stdout'] );
		$this->assertStringContainsString( '8.9', $result['stdout'] );
	}

	/**
	 * A tested version below its own minimum is reported with BOTH values and
	 * the line of each.
	 *
	 * Both values, because "the listing is incoherent" does not tell anybody
	 * which of the two numbers is the one that moved.
	 *
	 * @return void
	 */
	public function test_a_tested_version_below_its_minimum_is_reported_with_both_values(): void {
		$listing = $this->fixture( 'tested-below-required.txt' );

		$result = $this->run_gate( array( '--listing', $listing ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'WordPress', $result['stdout'] );
		$this->assertStringContainsString( '6.4', $result['stdout'] );
		$this->assertStringContainsString( '6.5', $result['stdout'] );
		$this->assertStringContainsString(
			$listing . ':' . $this->line_of( $listing, 'Tested up to:' ) . ':',
			$result['stdout'],
			'The finding did not name the line the tested declaration is on.'
		);
		$this->assertStringNotContainsString(
			'WooCommerce version declared as tested',
			$result['stdout'],
			'The WooCommerce pair of this fixture is coherent, and a gate that reports it is finding what is not there.'
		);
	}

	/**
	 * An unreadable declaration is reported with its line and with what it says,
	 * never skipped.
	 *
	 * Skipping what cannot be parsed is the shape of every phantom guardrail: a
	 * green run that means "I looked somewhere else".
	 *
	 * @return void
	 */
	public function test_an_unreadable_declaration_is_reported_with_its_line(): void {
		$listing = $this->fixture( 'unreadable-declaration.txt' );

		$result = $this->run_gate( array( '--listing', $listing ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'WC tested up to', $result['stdout'] );
		$this->assertStringContainsString( 'latest', $result['stdout'] );
		$this->assertStringContainsString(
			$listing . ':' . $this->line_of( $listing, 'WC tested up to:' ) . ':',
			$result['stdout'],
			'The finding did not name the line the unreadable declaration is on.'
		);
	}

	/**
	 * Minimums that do not agree are reported with the THREE values and the
	 * three files that carry them.
	 *
	 * @return void
	 */
	public function test_minimums_that_disagree_are_reported_with_the_three_values(): void {
		$listing = $this->fixture( 'minimums-disagree.txt' );

		$result = $this->run_gate( array( '--listing', $listing ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['stdout'] . $result['stderr'] );

		foreach (
			array(
				$listing,
				'factuarea-for-woocommerce.php',
				'Constants.php',
				'6.6',
				'6.5',
			) as $expected
		) {
			$this->assertStringContainsString(
				$expected,
				$result['stdout'],
				'The report of a three-way disagreement left out "' . $expected . '".'
			);
		}

		$this->assertStringNotContainsString(
			'WooCommerce minimum',
			$result['stdout'],
			'The WooCommerce minimum of this fixture agrees with the other two, and reporting it would be noise.'
		);
	}

	/**
	 * A tested version written in its two homes with two different numbers is
	 * reported with both, and named as the two readers that consume them.
	 *
	 * `WC tested up to` is the one declaration of the four that is written
	 * twice: the listing carries it for the directory and the plugin header
	 * carries it for the shop. Nothing compared them before this gate, so this
	 * case is the one that would have been silent the longest.
	 *
	 * @return void
	 */
	public function test_a_tested_version_that_disagrees_with_the_plugin_header_is_reported_with_both(): void {
		$listing = $this->fixture( 'tested-disagrees-with-header.txt' );

		$result = $this->run_gate( array( '--listing', $listing ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'WooCommerce version declared as tested', $result['stdout'] );
		$this->assertStringContainsString( '9.1', $result['stdout'] );
		$this->assertStringContainsString( '8.9', $result['stdout'] );
		$this->assertStringContainsString( 'factuarea-for-woocommerce.php', $result['stdout'] );
		$this->assertStringContainsString(
			$listing . ':' . $this->line_of( $listing, 'WC tested up to:' ) . ':',
			$result['stdout'],
			'The finding did not name the line of the listing declaration.'
		);
	}

	/**
	 * The WordPress tested claim has one home, and the gate does not invent a
	 * second one for it.
	 *
	 * WordPress defines no `Tested up to` plugin header. A gate that looked for
	 * one would report every listing of every plugin as missing a declaration
	 * that cannot exist, and the fix everybody would reach for is to stop
	 * reporting missing declarations at all.
	 *
	 * @return void
	 */
	public function test_the_wordpress_tested_claim_is_not_looked_for_in_the_plugin_header(): void {
		$header = (string) file_get_contents( $this->repository_root() . '/factuarea-for-woocommerce.php' );

		$this->assertSame(
			0,
			preg_match( '/^[\s*\/#@]*Tested up to\s*:/mi', $header ),
			'The plugin header now declares a WordPress tested version, so the gate should compare it with the listing like it does for WooCommerce.'
		);
		$this->assertSame( self::EXIT_OK, $this->run_gate( array() )['status'] );
	}

	/**
	 * The emission mode prints EXACTLY the declared pair on standard output and
	 * nothing else.
	 *
	 * Exactly, because the consumer of this output is a redirection into the
	 * file continuous integration reads: one extra line there is an environment
	 * variable nobody wrote.
	 *
	 * @return void
	 */
	public function test_the_emission_mode_prints_exactly_the_declared_pair(): void {
		$result = $this->run_gate( array( '--listing', $this->fixture( 'coherent.txt' ), '--emit' ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertSame(
			"FACTUAREA_E2E_WORDPRESS=7.2\nFACTUAREA_E2E_WOOCOMMERCE=8.9\n",
			$result['stdout'],
			'The emitted pair is not the pair the listing declares, character for character.'
		);
	}

	/**
	 * The two names it emits are the two the end-to-end harness reads.
	 *
	 * The pair travels from this gate to `tests/e2e/global-setup.js` through a
	 * workflow file neither of them can see. Asserting the names here is what
	 * keeps a rename of one of them from producing a revalidation job that
	 * starts the suite against the default pins and passes.
	 *
	 * @return void
	 */
	public function test_the_emitted_names_are_the_ones_the_harness_reads(): void {
		$harness = (string) file_get_contents( $this->repository_root() . '/tests/e2e/global-setup.js' );

		foreach ( array( 'FACTUAREA_E2E_WORDPRESS', 'FACTUAREA_E2E_WOOCOMMERCE' ) as $name ) {
			$this->assertStringContainsString(
				'process.env.' . $name,
				$harness,
				'The harness no longer reads "' . $name . '", so the pair this gate emits would be ignored.'
			);
		}
	}

	/**
	 * The emission mode refuses to print a pair it could not verify.
	 *
	 * A gate whose emission half skipped the checks would hand continuous
	 * integration the very declaration that is broken, and the job would go
	 * green against a version the listing never really claimed.
	 *
	 * @return void
	 */
	public function test_the_emission_mode_prints_nothing_when_the_listing_is_incoherent(): void {
		$result = $this->run_gate(
			array( '--listing', $this->fixture( 'tested-below-required.txt' ), '--emit' )
		);

		$this->assertSame( self::EXIT_FINDINGS, $result['status'] );
		$this->assertSame( '', $result['stdout'], 'A pair was emitted for a listing the gate had just refused.' );
		$this->assertStringContainsString( '6.4', $result['stderr'] );
	}

	/**
	 * A listing that is not where it was said to be is a configuration error,
	 * and never a clean run.
	 *
	 * @return void
	 */
	public function test_a_listing_that_is_not_there_is_a_configuration_error(): void {
		$missing = $this->repository_root() . '/tests/fixtures/tested-up-to/this-listing-does-not-exist.txt';

		$result = $this->run_gate( array( '--listing', $missing ) );

		$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'this-listing-does-not-exist.txt', $result['stderr'] );
	}

	/**
	 * A root without the plugin header is a configuration error, naming the
	 * file it could not read.
	 *
	 * The listing alone is not enough to run this gate: two of its four checks
	 * are comparisons with the other two homes of the minimums. A run that found
	 * only the listing and reported "coherent" would be reporting the half it
	 * could see as if it were the whole.
	 *
	 * @return void
	 */
	public function test_a_root_without_the_plugin_header_is_a_configuration_error(): void {
		$root = sys_get_temp_dir() . '/tested-up-to-' . uniqid();
		mkdir( $root );
		copy( $this->fixture( 'coherent.txt' ), $root . '/readme.txt' );

		try {
			$result = $this->run_gate( array( '--root', $root ) );

			$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['stdout'] . $result['stderr'] );
			$this->assertStringContainsString( 'factuarea-for-woocommerce.php', $result['stderr'] );
		} finally {
			unlink( $root . '/readme.txt' );
			rmdir( $root );
		}
	}

	/**
	 * An option given without its value is a configuration error, not a run
	 * against the defaults.
	 *
	 * @return void
	 */
	public function test_an_option_without_its_value_is_a_configuration_error(): void {
		$result = $this->run_gate( array( '--listing' ) );

		$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'needs a path', $result['stderr'] );
	}

	/**
	 * The usage text names the three exit codes and the emission mode.
	 *
	 * The exit codes are the contract this gate has with `composer verify` and
	 * with the revalidation job; a usage text that stopped naming them would be
	 * the first sign that one of them had quietly changed meaning.
	 *
	 * @return void
	 */
	public function test_the_usage_text_names_the_exit_codes_and_the_emission_mode(): void {
		$result = $this->run_gate( array( '--help' ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['stderr'] );
		$this->assertStringContainsString( '--emit', $result['stdout'] );
		$this->assertStringContainsString( '0 coherent, 1 findings, 2 the gate could not run', $result['stdout'] );
	}

	/**
	 * An option the gate does not know is a configuration error too, and never
	 * a path.
	 *
	 * @return void
	 */
	public function test_an_unknown_option_is_a_configuration_error(): void {
		$result = $this->run_gate( array( '--only-the-parts-i-like' ) );

		$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( 'unknown option', $result['stderr'] );
	}

	/**
	 * The gate does NOT compare the declaration with the pin of the
	 * containerised environment, and the reason is written down.
	 *
	 * This is D11 of `design.md` turned into a check. The naive reading of the
	 * debt - fail when `Tested up to` is greater than the `core` pin of
	 * `.wp-env.json` - would put today's declaration in red, because the
	 * declaration rests on a measured run against a WordPress the default
	 * environment deliberately does not pin, and the only way to quiet that red
	 * is to lower the declaration. Lowering a measured claim to satisfy a gate
	 * is losing published coverage for nothing.
	 *
	 * Two assertions, because either half alone rots: the reason has to be
	 * readable in the file, and the file has to keep not reading the pin. The
	 * pin is looked for among the STRING LITERALS of the gate rather than in its
	 * text, precisely because the text is where the reason lives.
	 *
	 * @return void
	 */
	public function test_the_gate_does_not_read_the_pin_of_the_containerised_environment(): void {
		$source = (string) file_get_contents( $this->gate_path() );

		$literals = '';
		$comments = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] || T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
				$literals .= $token[1];
			}
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				$comments .= $token[1];
			}
		}

		$this->assertStringNotContainsString(
			'wp-env',
			$literals,
			'The gate reads the containerised environment pin. D11 rejects that comparison: it puts a measured declaration in red and the only way out is to lower it.'
		);
		$this->assertStringContainsString(
			'wp-env',
			$comments,
			'The reason why the pin is NOT compared is no longer written in the gate, so the next reader will add the comparison.'
		);
	}

	/**
	 * Today the declared WordPress and the pinned one are different numbers,
	 * and the gate is still green.
	 *
	 * Measured 2026-09-18: `readme.txt` declares `Tested up to: 7.1` and
	 * `.wp-env.json` pins WordPress 6.5, which is the floor the plugin promises
	 * to support. The two are different on purpose - `docs/publishing.md`
	 * documents how the declaration is measured with an override file - and the
	 * green run above is what says the gate accepts that distance.
	 *
	 * The day somebody makes the two numbers equal this case goes red instead of
	 * quietly proving nothing, and re-anchoring it is a deliberate edit.
	 *
	 * @return void
	 */
	public function test_the_declared_wordpress_is_not_the_pinned_one_and_that_is_accepted(): void {
		$listing = (string) file_get_contents( $this->repository_root() . '/readme.txt' );
		$pins    = (string) file_get_contents( $this->repository_root() . '/.wp-env.json' );

		$declared = array();
		preg_match( '/^Tested up to:\s*(\S+)\s*$/m', $listing, $declared );
		$pinned = array();
		preg_match( '/wordpress-(\d+\.\d+(?:\.\d+)?)\.zip/', $pins, $pinned );

		$this->assertNotSame(
			$declared[1] ?? 'declared',
			$pinned[1] ?? 'pinned',
			'The declared WordPress and the pinned one are now the same number, so this case no longer measures the distance the gate has to tolerate. Re-anchor it against the new pair.'
		);
		$this->assertSame( self::EXIT_OK, $this->run_gate( array() )['status'] );
	}

	/**
	 * Runs the gate in a separate process, keeping the two streams apart.
	 *
	 * @param string[] $arguments Arguments for the gate.
	 * @return array{status: int, stdout: string, stderr: string}
	 */
	private function run_gate( array $arguments ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->gate_path() );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = proc_open( $command, $descriptors, $pipes );

		$this->assertIsResource( $process, 'The gate could not be started.' );

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Line number a declaration is on, measured rather than written down.
	 *
	 * @param string $path   File to look in.
	 * @param string $prefix Start of the declaration line.
	 * @return int
	 */
	private function line_of( string $path, string $prefix ): int {
		$lines = explode( "\n", (string) file_get_contents( $path ) );
		foreach ( $lines as $index => $line ) {
			if ( str_starts_with( $line, $prefix ) ) {
				return $index + 1;
			}
		}

		$this->fail( 'The fixture ' . $path . ' no longer carries a line starting with "' . $prefix . '".' );
	}

	/**
	 * Absolute path of a listing fixture.
	 *
	 * @param string $name File name inside the fixture directory.
	 * @return string
	 */
	private function fixture( string $name ): string {
		return $this->repository_root() . '/tests/fixtures/tested-up-to/' . $name;
	}

	/**
	 * Absolute path of the gate.
	 *
	 * @return string
	 */
	private function gate_path(): string {
		return $this->repository_root() . '/scripts/check-tested-up-to.php';
	}

	/**
	 * Absolute path of the repository root.
	 *
	 * @return string
	 */
	private function repository_root(): string {
		return dirname( __DIR__, 2 );
	}
}
