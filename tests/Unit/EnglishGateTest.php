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
 * SIX fixtures since the inbound half landed. The third pair carries the
 * surfaces that half adds - the message of a receiver, the labels of the
 * settings section that governs the two receivers, and the fulfilment status
 * labels shown to the buyer in "My account" - because only the first of them
 * was already named by the classifier, and a surface the gate can only call "a
 * string literal" is a finding whose reader does not know which file to open.
 *
 * The fourth surface that half adds, the inbound contract document under
 * `docs`, is prose, and the pair of listing fixtures already measures the prose
 * extractor. What it needed instead was a check that `docs` is inside the swept
 * scope at all, and that check reads the scope out of `composer.json` rather
 * than repeating it here: a copy of the scope would agree with itself forever
 * while the real one drifted.
 *
 * Unit suite: no WordPress, no database, no doubles. A PHP binary and six
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
	 * The scope the repository actually sweeps reaches every surface the
	 * inbound half adds.
	 *
	 * The scope is READ from `composer.json` instead of being repeated here.
	 * The failure this guards against is a gate pointed somewhere else: a copy
	 * of the path list written into this test would keep agreeing with itself
	 * long after the real `lint:english` script had stopped covering a
	 * directory, and the test would stay green while the sweep shrank.
	 *
	 * The inbound contract document does not exist until the documentation
	 * phase writes it, which is why the assertion is about the directory that
	 * holds it. The gate skips a path that is not written yet BY NAME, so a
	 * swept directory is what makes the document covered the day it lands.
	 *
	 * Measured edge, stated so that a green run here is not read as more than
	 * it is: `factuarea-for-woocommerce.php` and `uninstall.php` are swept by
	 * the coding standard and by the no-paywall gate, and are NOT in this
	 * scope. No surface of the inbound half lives in either of them, so this
	 * case does not assert their absence - asserting it would freeze a gap that
	 * the wiring phase, which owns `composer.json`, is being asked to close.
	 *
	 * @return void
	 */
	public function test_the_swept_scope_reaches_the_surfaces_the_inbound_half_adds(): void {
		$swept = $this->swept_paths();

		$this->assertNotEmpty( $swept, 'The lint:english script of composer.json declares no path at all.' );

		foreach (
			array(
				'src/Inbound'               => 'the messages of the two receivers',
				'src/Admin/SettingsPage.php' => 'the labels of the settings section that governs the two receivers',
				'src/MyAccount'             => 'the fulfilment status labels shown to the buyer in "My account"',
				'docs'                      => 'the inbound contract document',
			) as $surface => $what
		) {
			$this->assertTrue(
				$this->is_swept( $surface, $swept ),
				sprintf(
					'"%s" holds %s and is not under any path of the lint:english scope (%s). A surface outside the sweep is a surface the gate reports clean without reading.',
					$surface,
					$what,
					implode( ' ', $swept )
				)
			);
		}
	}

	/**
	 * The gate names the surfaces of the inbound half instead of calling them
	 * string literals.
	 *
	 * The receiver message is the one that needed the classifier to grow. It
	 * reaches the sender in the body of a REST answer, so it is neither a
	 * translated label nor an exception message, and before this case the gate
	 * reported it as "string literal" when it travelled in an array and as
	 * "exception message" when it travelled inside a WordPress error object.
	 * The second reading is the worse of the two: it sends whoever fixes it
	 * looking for a `throw`.
	 *
	 * @return void
	 */
	public function test_it_names_the_surfaces_the_inbound_half_adds(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-inbound-surfaces.php' ) ) );

		$this->assertSame( self::EXIT_FINDINGS, $result['status'], $result['output'] );

		foreach (
			array(
				'receiver message',
				'settings or checkout field text',
				'translation function literal',
				'comment or docblock',
			) as $surface
		) {
			$this->assertStringContainsString(
				'surface: ' . $surface,
				$result['output'],
				'The gate stopped recognising the surface "' . $surface . '", which the inbound half writes on.'
			);
		}
	}

	/**
	 * A receiver message is named as one in BOTH of the shapes it travels in:
	 * inside a WordPress error object, and as a value of the answer body.
	 *
	 * Asserted line by line rather than by the presence of the label anywhere
	 * in the report, because one recognised shape and one unrecognised one
	 * would still put the label in the output.
	 *
	 * @return void
	 */
	public function test_a_receiver_message_is_named_in_both_of_its_shapes(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-inbound-surfaces.php' ) ) );

		foreach (
			array(
				'El cuerpo del aviso entrante'  => 'the message carried by a WordPress error object',
				'El receptor de estado'         => 'the message carried as a value of the answer body',
				'Esta clave de idempotencia'    => 'the message built as an array before the answer is composed',
			) as $needle => $shape
		) {
			$this->assertMatchesRegularExpression(
				'/' . preg_quote( $needle, '/' ) . '[^\n]*\n\s*surface: receiver message/',
				$result['output'],
				sprintf( 'The gate did not name %s as a receiver message: %s', $shape, $result['output'] )
			);
		}
	}

	/**
	 * Every finding of the inbound fixture names the file and the line.
	 *
	 * @return void
	 */
	public function test_every_finding_of_the_inbound_fixture_names_the_file_and_the_line(): void {
		$result = $this->run_gate( array( $this->fixture( 'spanish-inbound-surfaces.php' ) ) );

		$matches = array();
		preg_match_all( '/^(\S*spanish-inbound-surfaces\.php):(\d+): /m', $result['output'], $matches );

		$this->assertGreaterThanOrEqual(
			6,
			count( $matches[0] ),
			'The Spanish fixture of the inbound half carries Spanish on more than six lines and the report named fewer: ' . $result['output']
		);

		foreach ( $matches[2] as $line ) {
			$this->assertGreaterThan( 0, (int) $line, 'A finding was reported without a usable line number.' );
		}
	}

	/**
	 * The gate passes the English twin of those surfaces, traps included.
	 *
	 * Without this half the case above proves only that the gate can shout. The
	 * inbound half answers with a body on five of its seven statuses, and a
	 * gate that reported all of them would teach every contributor to run it
	 * with the result ignored.
	 *
	 * @return void
	 */
	public function test_it_passes_the_english_twin_of_the_inbound_surfaces(): void {
		$result = $this->run_gate( array( $this->fixture( 'english-inbound-surfaces.php' ) ) );

		$this->assertSame(
			self::EXIT_OK,
			$result['status'],
			'The gate reported English text of the inbound half. A gate that cries wolf is a gate everybody learns to ignore: ' . $result['output']
		);
	}

	/**
	 * The paths the `lint:english` script of `composer.json` hands the gate.
	 *
	 * @return string[]
	 */
	private function swept_paths(): array {
		$manifest = json_decode( (string) file_get_contents( $this->repository_root() . '/composer.json' ), true );

		$this->assertIsArray( $manifest, 'composer.json could not be read as JSON.' );
		$this->assertArrayHasKey( 'scripts', $manifest );
		$this->assertArrayHasKey( 'lint:english', $manifest['scripts'], 'composer.json no longer declares a lint:english script, so nothing runs the gate.' );

		$tokens = preg_split( '/\s+/', trim( (string) $manifest['scripts']['lint:english'] ) );

		/* The command is `php scripts/check-english.php <path>...`: everything after the script is scope. */
		return array_values(
			array_filter(
				(array) $tokens,
				static fn( string $token ): bool => 'php' !== $token && ! str_ends_with( $token, 'check-english.php' )
			)
		);
	}

	/**
	 * Whether a path of the repository is under one of the swept paths.
	 *
	 * @param string   $relative Path relative to the repository root.
	 * @param string[] $swept    Swept paths, as the composer script declares them.
	 * @return bool
	 */
	private function is_swept( string $relative, array $swept ): bool {
		foreach ( $swept as $path ) {
			$path = rtrim( $path, '/' );

			if ( $relative === $path || str_starts_with( $relative, $path . '/' ) ) {
				return true;
			}
		}

		return false;
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
