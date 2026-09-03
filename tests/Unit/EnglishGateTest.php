<?php
/**
 * The English-only gate, measured against itself.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What this file measures, and why a gate needs a test at all.
 *
 * A gate is the one kind of code whose failure is INVISIBLE. When it breaks it
 * does not throw, it does not slow anything down and it does not produce a
 * wrong answer: it produces a green run, which everybody reads as "there is no
 * Spanish here" when what it means is "I looked somewhere else". Every other
 * file of this package is protected by this gate; the gate is protected by
 * nothing but this file.
 *
 * The failures worth catching are all of that shape:
 *
 *   - an extractor that stops seeing a whole file kind, so the public directory
 *     listing is never swept;
 *   - a surface classifier that silently stops recognising one of the five
 *     surfaces of the task, so a finding loses the label that tells its reader
 *     where to go;
 *   - a detection rule that is loosened to quiet a false positive and starts
 *     waving Spanish through;
 *   - the opposite, a rule tightened until ordinary English sentences are
 *     reported, which does not break the build so much as train everybody to
 *     ignore it;
 *   - the character classes of the gate reporting the gate itself, whose only
 *     tempting fix is to exempt the gate from its own sweep.
 *
 * The gate is exercised as a SUBPROCESS rather than by including it, and that
 * is deliberate. Its contract with the rest of the repository is a command
 * line and three exit codes - the `lint:english` script of `composer.json`
 * consumes exactly that - so the test asserts on exactly that. Including the
 * file would also run it, because the file ends by exiting with its own result.
 *
 * Four fixtures, not two: the task asks for a pair, and the gate has two file
 * kinds. A pair per kind is what keeps the prose extractor from becoming the
 * half of the gate that nobody ever measured, and the public directory listing
 * is prose, so leaving it untested would leave one of the named surfaces of
 * task 21.2 unswept by any check at all.
 *
 * Unit suite: no WordPress, no database, no doubles. A PHP binary and four
 * files on disk.
 */
final class EnglishGateTest extends TestCase {

	/**
	 * Exit code of a clean run.
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
	 * The gate reports Spanish on every surface task 21.2 names, and names the
	 * surface it found it on.
	 *
	 * The five surfaces are asserted one by one instead of asserting that the
	 * run is red. A single assertion on the exit code would still pass with
	 * four of the five extractors broken, which is the exact failure this file
	 * exists to catch.
	 *
	 * @return void
	 */
	public function test_it_reports_spanish_on_every_named_surface(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-surfaces.php' ) ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['output'] );

		foreach (
			array(
				'translation function literal',
				'settings or checkout field text',
				'exception message',
				'administration notice',
				'comment or docblock',
			) as $surface
		) {
			$this->assertStringContainsString(
				'surface: ' . $surface,
				$result['output'],
				'The gate stopped recognising the surface "' . $surface . '", which task 21.2 names one by one.'
			);
		}
	}

	/**
	 * Every finding names the file and the line, which is the whole of what
	 * makes a finding actionable.
	 *
	 * @return void
	 */
	public function test_every_finding_names_the_file_and_the_line(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-surfaces.php' ) ) );

		$matches = array();
		preg_match_all( '/^(\S*spanish-surfaces\.php):(\d+): /m', $result['output'], $matches );

		$this->assertGreaterThanOrEqual(
			5,
			count( $matches[0] ),
			'The Spanish fixture carries Spanish on more than five lines and the report named fewer: ' . $result['output']
		);
		foreach ( $matches[2] as $line ) {
			$this->assertGreaterThan( 0, (int) $line, 'A finding was reported without a usable line number.' );
		}
	}

	/**
	 * The gate passes the English twin of that fixture, traps included.
	 *
	 * The traps are the four things a careless gate reports by mistake: the
	 * Spanish tax acronyms, the product name, an English sentence carrying the
	 * negation and the word for a mistake - both of which are Spanish words too
	 * - and the accented official name of the tax authority, which is what the
	 * allowlist inside the gate exists for.
	 *
	 * @return void
	 */
	public function test_it_passes_the_english_fixture_with_its_traps(): void {
		$result = $this->run_gate( array( $this->fixture( 'english-surfaces.php' ) ) );

		$this->assertSame(
			self::EXIT_OK,
			$result['status'],
			'The gate reported English text. A gate that cries wolf is a gate everybody learns to ignore: ' . $result['output']
		);
	}

	/**
	 * The prose extractor sees the public directory listing.
	 *
	 * @return void
	 */
	public function test_it_reports_spanish_prose_of_a_directory_listing(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-listing.md' ) ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'surface: documentation', $result['output'] );
	}

	/**
	 * Code inside a listing is code: fenced blocks and inline spans are not
	 * prose, and the clean listing carries Spanish in both to prove that both
	 * rules still work.
	 *
	 * @return void
	 */
	public function test_it_passes_a_listing_whose_only_spanish_is_inside_code(): void {
		$result = $this->run_gate( array( $this->fixture( 'english-listing.md' ) ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['output'] );
	}

	/**
	 * The gate is inside the scope it sweeps, and comes out clean.
	 *
	 * This is not a formality. The gate has to name the Spanish punctuation and
	 * the accented letters it looks for, and writing them as characters would
	 * make it report itself on every run. The tempting fix for that is to
	 * exempt the gate from its own sweep, and a gate with an exemption for
	 * itself is one edit away from an exemption for everything else. The gate
	 * writes those classes as escapes instead; this test is what keeps that
	 * decision from being undone by accident.
	 *
	 * @return void
	 */
	public function test_the_gate_does_not_report_itself(): void {
		$result = $this->run_gate( array( $this->repository_root() . '/scripts/check-english.php' ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['output'] );
	}

	/**
	 * A path that does not exist yet is skipped by name, and does not fail.
	 *
	 * The `lint:english` script points the gate at the two readme files and at
	 * the documentation directory, which a later phase writes. A gate that died
	 * on a file nobody has written yet would be a gate nobody runs.
	 *
	 * @return void
	 */
	public function test_a_path_that_does_not_exist_is_skipped_by_name(): void {
		$missing = $this->repository_root() . '/this-file-is-not-written-yet.md';

		$result = $this->run_gate( array( $missing ) );

		$this->assertSame( self::EXIT_OK, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'this-file-is-not-written-yet.md', $result['output'] );
		$this->assertStringContainsString( 'does not exist yet', $result['output'] );
	}

	/**
	 * A run the gate cannot make is its own exit code, distinct from clean.
	 *
	 * Reporting "no paths given" as success is the shape of every phantom
	 * guardrail: a green run that proves nothing, indistinguishable from a green
	 * run that proves everything.
	 *
	 * @return void
	 */
	public function test_a_run_without_paths_is_a_configuration_error(): void {
		$result = $this->run_gate( array() );

		$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'no paths given', $result['output'] );
	}

	/**
	 * An option the gate does not know is a configuration error too, and never
	 * a path.
	 *
	 * @return void
	 */
	public function test_an_unknown_option_is_a_configuration_error(): void {
		$result = $this->run_gate( array( '--only-the-parts-i-like' ) );

		$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'unknown option', $result['output'] );
	}

	/**
	 * A file the gate cannot read as text is refused, never reported as clean.
	 *
	 * Every rule of the gate is a UTF-8 regular expression, and the engine
	 * returns an empty result rather than an error when the subject is in
	 * another encoding. A file of Spanish prose saved in a single-byte encoding
	 * would therefore come out green, which is the phantom this gate exists to
	 * avoid, on the gate itself.
	 *
	 * The file is written to the system temporary directory rather than kept as
	 * a fixture, because a committed file that is deliberately not UTF-8 fights
	 * the encoding the repository pins in `.editorconfig`.
	 *
	 * @return void
	 */
	public function test_a_file_that_is_not_utf8_is_refused_rather_than_called_clean(): void {
		$path = tempnam( sys_get_temp_dir(), 'english-gate-' ) . '.md';
		/* Spanish prose in a single-byte encoding: the bytes are not valid UTF-8. */
		file_put_contents( $path, "# Aviso\n\nLa configuraci\xF3n de la tienda no es v\xE1lida.\n" );

		try {
			$result = $this->run_gate( array( $path ) );

			$this->assertSame( self::EXIT_MISCONFIGURED, $result['status'], $result['output'] );
			$this->assertStringContainsString( 'not valid UTF-8', $result['output'] );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * Runs the gate in a separate process and captures everything it said.
	 *
	 * @param string[] $arguments Arguments for the gate.
	 * @return array{status: int, output: string}
	 */
	private function run_gate( array $arguments ): array {
		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( $this->repository_root() . '/scripts/check-english.php' );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}

		$lines  = array();
		$status = 0;
		exec( $command . ' 2>&1', $lines, $status );

		return array(
			'status' => $status,
			'output' => implode( PHP_EOL, $lines ),
		);
	}

	/**
	 * Absolute path of a self-check fixture.
	 *
	 * @param string $name File name inside the fixture directory.
	 * @return string
	 */
	private function fixture( string $name ): string {
		return $this->repository_root() . '/tests/fixtures/english-gate/' . $name;
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
