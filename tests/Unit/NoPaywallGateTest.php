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
 * The inbound half adds a second pair of fixtures and four more cases, for a
 * distinction this gate had never had to make. Until this change the plugin put
 * NO condition in front of any of its features, so every condition was a
 * finding by construction. Now there are two - the toggles that govern the two
 * receivers - and they are legitimate: a merchant setting, off by default, that
 * costs nobody anything. The pair proves the gate tells them apart from a
 * receiver whose condition is a purchase; a fifth case drives the whole
 * repository run so that the sweep is shown to REACH `src/Inbound`, which
 * pointing the lens at a file by hand never shows; and two more cover the
 * artefact, which this change fills with new files under `tests`, `scripts` and
 * `docs`.
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
		$root = $this->synthetic_repository( $this->correct_plugin_file() );

		$packaged = $root . '/dist/factuarea-for-woocommerce';
		$this->write( $packaged . '/factuarea-for-woocommerce.php', "<?php\n" );
		$this->write( $packaged . '/LICENSE', (string) file_get_contents( $this->repository_root() . '/LICENSE' ) );
		$this->write( $packaged . '/readme.txt', "=== Factuarea for WooCommerce ===\n" );
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
	 * The gate reports a RECEIVER that answers only for whoever has paid.
	 *
	 * The two receiver toggles are the first conditions this plugin ever put in
	 * front of a feature, and a condition is what a paywall looks like from a
	 * distance. This case proves the lens tells the two apart in the direction
	 * that matters: it fails on the receiver whose condition is a purchase.
	 *
	 * MEASURED on 2026-09-18 before the vocabulary grew: of the six offences
	 * below, the gate saw only `license_key`, `plan_level`, `is_premium` and the
	 * three sentences. It did NOT see the subscription the receiver consults,
	 * the entitlement beside it, the function that asks whether the plan allows
	 * the inbound half, or the status the answer carries - four conditions that
	 * would have shipped a paywalled receiver through a green gate. Each one is
	 * asserted by its own offence rather than by the total, so a pattern
	 * deleted from the gate fails here naming itself.
	 *
	 * @return void
	 */
	public function test_the_gate_fails_on_a_receiver_gated_behind_a_payment(): void {
		$result = $this->run_gate( array( '--scan', $this->fixture( 'gated-receiver.php' ) ) );

		$this->assertSame(
			1,
			$result['status'],
			'The gate has to exit 1 over a receiver that answers only for whoever has paid. Output: ' . $result['output']
		);

		$this->assertStringContainsString( 'gated-receiver.php:', $result['output'], 'Every finding names its file and its line.' );

		foreach (
			array(
				'license_key'         => 'the licence-credential family',
				'is_premium'          => 'the paid-tier family',
				'plan_level'          => 'the plan family',
				'Upgrade your plan'   => 'the withheld-feature sentence family',
				'subscription_status' => 'the subscription a receiver would consult',
				'entitlement'         => 'the entitlement a receiver would consult',
				'plan_allows'         => 'the question "does the plan allow the inbound half"',
				'payment_required'    => 'the answer that names the payment as the reason',
				'paid_tier'           => 'the value the plan is compared against',
			) as $offence => $what
		) {
			$this->assertStringContainsString(
				$offence,
				$result['output'],
				sprintf( '%s is not being enforced: "%s" was not reported. Output: %s', $what, $offence, $result['output'] )
			);
		}
	}

	/**
	 * The gate passes the receiver whose only condition is the merchant toggle.
	 *
	 * Without this half the case above proves only that the gate can shout, and
	 * the thing it would shout at is the correct implementation of this change:
	 * two toggles, off by default, that cost nobody anything.
	 *
	 * @return void
	 */
	public function test_the_gate_passes_on_a_receiver_gated_only_by_its_merchant_toggle(): void {
		$result = $this->run_gate( array( '--scan', $this->fixture( 'ungated-receiver.php' ) ) );

		$this->assertSame(
			0,
			$result['status'],
			'The gate reported a receiver whose only condition is a setting of the shop. A gate red on correct code is a gate somebody switches off. Output: ' . $result['output']
		);
	}

	/**
	 * The clean receiver fixture really carries the near misses, IN CODE.
	 *
	 * The scanner drops comments before it matches, so a near miss parked in a
	 * docblock proves nothing at all. Each of these is in a string literal, an
	 * identifier or a call.
	 *
	 * @return void
	 */
	public function test_the_clean_receiver_fixture_carries_the_near_misses_in_code(): void {
		$source = (string) file_get_contents( $this->fixture( 'ungated-receiver.php' ) );

		foreach (
			array(
				'$order->is_paid()',
				'subscription or pre-order plugin',
				'$planner_table',
				'is_processing_and_paid',
				'has unlocked it',
				"'factuarea_wc_order_status_receiver_enabled'",
			) as $near_miss
		) {
			$this->assertStringContainsString(
				$near_miss,
				$source,
				sprintf( 'The clean receiver fixture has to keep carrying "%s": it is there to keep a bare pattern from coming back, and it cannot do that once somebody trims it.', $near_miss )
			);
		}
	}

	/**
	 * The REPOSITORY sweep, and not only the lens, reaches a paywall added
	 * under the inbound half.
	 *
	 * The two cases above point the lens at a file by hand, which proves the
	 * vocabulary sees the shape and nothing about where the gate looks when
	 * nobody points it anywhere. This one drives the whole repository run over
	 * a synthetic tree whose only offence is inside `src/Inbound`, which is
	 * where the receivers of this change live.
	 *
	 * @return void
	 */
	public function test_the_repository_sweep_reaches_a_paywall_under_the_inbound_half(): void {
		$root = $this->synthetic_repository( $this->correct_plugin_file() );

		$this->write(
			$root . '/src/Inbound/OrderStatusEndpoint.php',
			"<?php\n\ndeclare( strict_types = 1 );\n\nfunction factuarea_receiver_is_open(): bool {\n"
				. "\treturn 'active' === get_option( 'factuarea_wc_subscription_status' );\n}\n"
		);

		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame( 1, $result['status'], 'Output: ' . $result['output'] );
		$this->assertStringContainsString( 'src/Inbound/OrderStatusEndpoint.php', $result['output'], 'The sweep does not reach the directory the receivers live in.' );
		$this->assertStringContainsString( 'subscription_status', $result['output'] );
	}

	/**
	 * The artefact has to carry the directory listing.
	 *
	 * The plugin, its licence and its listing are the three things that travel,
	 * and until this case the gate required only the first two. An artefact
	 * without `readme.txt` installs and runs, which is why nothing else catches
	 * it: what it loses is the page every merchant reads before installing, and
	 * the directory shows the plugin with no description at all.
	 *
	 * @return void
	 */
	public function test_the_artefact_must_carry_the_directory_listing(): void {
		$root = $this->synthetic_repository( $this->correct_plugin_file() );

		$packaged = $root . '/dist/factuarea-for-woocommerce';
		$this->write( $packaged . '/factuarea-for-woocommerce.php', "<?php\n" );
		$this->write( $packaged . '/LICENSE', (string) file_get_contents( $this->repository_root() . '/LICENSE' ) );
		$this->write( $packaged . '/src/Plugin.php', "<?php\n" );

		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame( 1, $result['status'], 'Output: ' . $result['output'] );
		$this->assertStringContainsString( 'readme.txt', $result['output'] );
		$this->assertStringContainsString( 'does not travel inside the artefact and must', $result['output'] );
	}

	/**
	 * Everything this change adds under `tests`, `scripts` and `docs` stays out
	 * of the artefact, and what stays in is the plugin, its licence and its
	 * listing.
	 *
	 * The exclusions are written as DIRECTORIES rather than as a list of files,
	 * which is what makes them hold for files nobody has written yet. This case
	 * measures that claim with the files this change actually adds instead of
	 * reading the ignore file and believing it.
	 *
	 * @return void
	 */
	public function test_the_artefact_leaves_out_what_this_change_adds_to_tests_scripts_and_docs(): void {
		$root = $this->synthetic_repository( $this->correct_plugin_file() );

		$added = array(
			'tests/Unit/InboundGateTest.php',
			'tests/fixtures/inbound/order-status/shipped.json',
			'scripts/check-tested-up-to.php',
			'docs/inbound-contract.md',
		);

		foreach ( $added as $path ) {
			$this->write( $root . '/' . $path, "placeholder\n" );
		}

		$result = $this->run_gate( array( '--root', $root ) );

		$this->assertSame(
			0,
			$result['status'],
			'A repository carrying what this change adds under tests, scripts and docs must still package cleanly. Output: ' . $result['output']
		);

		foreach ( $added as $path ) {
			$this->assertStringNotContainsString(
				$path,
				$result['output'],
				sprintf( '"%s" reached the artefact listing: the exclusion is no longer covering everything under its directory.', $path )
			);
		}
	}

	/**
	 * A plugin entry point whose header declares the licence this package ships.
	 *
	 * @return string
	 */
	private function correct_plugin_file(): string {
		return "<?php\n/**\n * Plugin Name: Factuarea for WooCommerce\n * License: GPL-2.0-or-later\n * License URI: https://www.gnu.org/licenses/gpl-2.0.html\n */\n";
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
		$this->write( $root . '/readme.txt', "=== Factuarea for WooCommerce ===\nStable tag: 0.1.0\n" );
		$this->write( $root . '/src/Plugin.php', "<?php\n\ndeclare( strict_types = 1 );\n" );
		$this->write( $root . '/uninstall.php', "<?php\n\ndeclare( strict_types = 1 );\n" );
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
