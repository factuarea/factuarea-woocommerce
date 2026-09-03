<?php
/**
 * The no-paywall, licence and packaging gate, measured against itself.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What this file measures, and why a gate needs a test at all.
 *
 * `composer lint:no-paywall` is green today. That is evidence of nothing on its
 * own: a gate that scanned the wrong directory, whose vocabulary matched no
 * spelling anybody uses, or that read a file it could not open and moved on,
 * would be green in exactly the same way. The whole value of this gate is the
 * day somebody adds a licence check, and that day is the one day nobody is
 * watching it. So the cases below are split by what each one would catch:
 *
 *  - the two fixtures prove the lens still SEES: one file that gates a feature
 *    behind a payment comes out red, and its twin that gates nothing comes out
 *    green. Both are needed. Only the red one, and a gate that reports
 *    everything passes; only the green one, and a gate that reports nothing
 *    does;
 *  - a third case reads the clean fixture and asserts it really carries the
 *    words a looser vocabulary would trip over. Without it the green fixture
 *    could drift into an empty file and keep passing, and the regression it
 *    exists to hold - a bare `paid`, `plan` or `pro` matching this
 *    repository's own prose - would be back;
 *  - the last four drive the licence and packaging halves over synthetic
 *    repositories, because the real one cannot exercise them: today it has no
 *    plugin entry point, and the day the wiring phase writes one it will have a
 *    correct one. A check that can only be observed in its passing state is a
 *    check nobody has seen work.
 *
 * The gate is run as a SUBPROCESS rather than included. Two reasons, and the
 * second is the one that matters: the exit code is half of the contract - 0, 1,
 * 2 and 3 mean four different things and the composer script and continuous
 * integration read them - and it can only be observed from outside; and the
 * script declares global constants sharing a prefix with the other script in
 * `scripts/`, so loading it into a test process would be the first place the
 * two ever meet.
 *
 * Unit suite: no WordPress, no database. Everything here is plain PHP reading
 * files and running a command.
 */
final class NoPaywallGateTest extends TestCase {

	/**
	 * Temporary directories built by a case, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $temporary = array();

	/**
	 * Removes whatever a case built.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary as $directory ) {
			$this->remove_directory( $directory );
		}

		$this->temporary = array();

		parent::tearDown();
	}

	/**
	 * The gate reports the file that withholds a feature until somebody pays.
	 *
	 * Each family of the vocabulary is asserted by its own offence rather than
	 * by the total count, so that a family deleted from the gate fails here
	 * naming itself instead of failing an arithmetic comparison that says
	 * nothing about which one went missing.
	 *
	 * @return void
	 */
	public function test_the_gate_fails_on_the_fixture_that_withholds_a_feature(): void {
		$result = $this->run_gate( array( '--scan', $this->fixture( 'gated-feature.php' ) ) );

		$this->assertSame(
			1,
			$result['status'],
			'The gate has to exit 1 over a file that gates a feature behind a payment. Output: ' . $result['output']
		);

		$this->assertStringContainsString( 'gated-feature.php:', $result['output'], 'Every finding names its file and its line.' );
		$this->assertStringContainsString( 'license_key', $result['output'], 'The licence-credential family is not being enforced.' );
		$this->assertStringContainsString( 'is_premium', $result['output'], 'The paid-tier family is not being enforced.' );
		$this->assertStringContainsString( 'plan_required', $result['output'], 'The plan family is not being enforced.' );
		$this->assertStringContainsString( 'Upgrade to Pro', $result['output'], 'The withheld-feature sentence family is not being enforced.' );

		$this->assertStringContainsString(
			"get_option( 'factuarea_wc_license_key' )",
			$result['output'],
			'The report has to quote the condition it found, not just say that it found one.'
		);
	}

	/**
	 * The gate passes on the file that withholds nothing.
	 *
	 * @return void
	 */
	public function test_the_gate_passes_on_the_fixture_that_withholds_nothing(): void {
		$result = $this->run_gate( array( '--scan', $this->fixture( 'ungated-feature.php' ) ) );

		$this->assertSame(
			0,
			$result['status'],
			'The gate has to exit 0 over a plugin that gates nothing. Output: ' . $result['output']
		);
	}

	/**
	 * The clean fixture really carries the words a looser vocabulary would trip
	 * over, so that the case above is not green over an empty file.
	 *
	 * @return void
	 */
	public function test_the_clean_fixture_carries_the_words_the_gate_must_not_confuse(): void {
		$source = (string) file_get_contents( $this->fixture( 'ungated-feature.php' ) );

		foreach ( array( 'a paid order', 'no further wait to plan', 'RetryPlanner', 'License: GPL-2.0-or-later', 'is_processing_or_completed' ) as $word ) {
			$this->assertStringContainsString(
				$word,
				$source,
				sprintf( 'The clean fixture has to keep carrying "%s": it is there to keep a bare pattern from coming back, and it cannot do that once somebody trims it.', $word )
			);
		}
	}

	/**
	 * With no plugin entry point there is no header to compare the licence
	 * against, and the gate says so with its own exit code instead of passing.
	 *
	 * @return void
	 */
	public function test_the_licence_comparison_is_skipped_by_name_when_there_is_no_plugin_file(): void {
		$root   = $this->synthetic_repository( null );
		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame(
			3,
			$result['status'],
			'A check that cannot see what it compares against must not report success. Output: ' . $result['output']
		);

		$this->assertStringContainsString( 'SKIPPED', $result['output'] );
		$this->assertStringContainsString( 'factuarea-for-woocommerce.php', $result['output'], 'The skip has to name what was missing.' );
	}

	/**
	 * A plugin header that declares a different licence from the one shipped
	 * beside it is a finding, because users would be told one thing and given
	 * another.
	 *
	 * @return void
	 */
	public function test_a_plugin_header_declaring_another_licence_is_a_finding(): void {
		$root = $this->synthetic_repository(
			"<?php\n/**\n * Plugin Name: Factuarea for WooCommerce\n * License: MIT\n * License URI: https://opensource.org/licenses/MIT\n */\n"
		);

		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame( 1, $result['status'], 'Output: ' . $result['output'] );
		$this->assertStringContainsString( 'License: MIT', $result['output'] );
		$this->assertStringContainsString( 'License URI', $result['output'] );
	}

	/**
	 * An artefact that carries the test suite is a finding, and the finding
	 * names the path that travelled.
	 *
	 * @return void
	 */
	public function test_an_artefact_carrying_the_test_suite_is_a_finding(): void {
		$root = $this->synthetic_repository(
			"<?php\n/**\n * Plugin Name: Factuarea for WooCommerce\n * License: GPL-2.0-or-later\n * License URI: https://www.gnu.org/licenses/gpl-2.0.html\n */\n"
		);

		$packaged = $root . '/dist/factuarea-for-woocommerce';
		$this->write( $packaged . '/factuarea-for-woocommerce.php', "<?php\n" );
		$this->write( $packaged . '/LICENSE', (string) file_get_contents( $this->repository_root() . '/LICENSE' ) );
		$this->write( $packaged . '/src/Plugin.php', "<?php\n" );
		$this->write( $packaged . '/tests/Unit/SomethingTest.php', "<?php\n" );
		$this->write( $packaged . '/composer.json', "{}\n" );

		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame( 1, $result['status'], 'Output: ' . $result['output'] );
		$this->assertStringContainsString( 'tests/Unit/SomethingTest.php', $result['output'] );
		$this->assertStringContainsString( 'composer.json', $result['output'] );
	}

	/**
	 * An argument the gate does not know is a usage error and not a pass.
	 *
	 * @return void
	 */
	public function test_an_unknown_argument_is_a_usage_error(): void {
		$result = $this->run_gate( array( '--everything' ) );

		$this->assertSame( 2, $result['status'], 'Output: ' . $result['output'] );
		$this->assertStringContainsString( 'unknown argument', $result['output'] );
	}

	/**
	 * Builds a repository that has everything the gate needs except, optionally,
	 * the plugin entry point.
	 *
	 * The licence and the ignore file are COPIED from this repository rather
	 * than invented, so that a case about the licence is measured against the
	 * text that actually ships.
	 *
	 * @param string|null $plugin_file Contents of the entry point, or null for none.
	 * @return string Absolute path of the repository root.
	 */
	private function synthetic_repository( ?string $plugin_file ): string {
		$root = sys_get_temp_dir() . '/factuarea-no-paywall-' . bin2hex( random_bytes( 8 ) );

		$this->temporary[] = $root;

		$this->write( $root . '/LICENSE', (string) file_get_contents( $this->repository_root() . '/LICENSE' ) );
		$this->write( $root . '/.distignore', (string) file_get_contents( $this->repository_root() . '/.distignore' ) );
		$this->write( $root . '/src/Plugin.php', "<?php\n\ndeclare( strict_types = 1 );\n" );
		$this->write(
			$root . '/composer.json',
			(string) json_encode(
				array(
					'name'    => 'factuarea/factuarea-for-woocommerce',
					'license' => 'GPL-2.0-or-later',
					'require' => array( 'php' => '>=8.1' ),
					'scripts' => array( 'package' => 'rsync -a --exclude-from=.distignore ./ dist/' ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);

		if ( null !== $plugin_file ) {
			$this->write( $root . '/factuarea-for-woocommerce.php', $plugin_file );
		}

		return $root;
	}

	/**
	 * Absolute path of one of the two self-check fixtures.
	 *
	 * @param string $name File name.
	 * @return string Absolute path.
	 */
	private function fixture( string $name ): string {
		return $this->repository_root() . '/tests/fixtures/no-paywall/' . $name;
	}

	/**
	 * Root of this repository.
	 *
	 * @return string Absolute path.
	 */
	private function repository_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Runs the gate and captures its two streams and its exit code.
	 *
	 * @param list<string> $arguments Arguments for the gate.
	 * @return array{status: int, output: string}
	 */
	private function run_gate( array $arguments ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->repository_root() . '/scripts/check-no-paywall.php' );

		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, $this->repository_root() );

		$this->assertIsResource( $process, 'The gate could not be started: ' . $command );

		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'output' => $output,
		);
	}

	/**
	 * Writes a file, creating whatever directories it needs.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents What to write.
	 * @return void
	 */
	private function write( string $path, string $contents ): void {
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}

		file_put_contents( $path, $contents );
	}

	/**
	 * Removes a directory and everything under it.
	 *
	 * @param string $directory Absolute path.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( (array) scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
				continue;
			}

			unlink( $path );
		}

		rmdir( $directory );
	}
}
