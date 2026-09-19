<?php
/**
 * Version-coherence gate: fails when the versions this package declares
 * contradict each other, and emits the declared tested pair for the job that
 * revalidates it.
 *
 * @package Factuarea\WooCommerce
 */

/*
 * The two WordPress rules this file does not follow, with their reasons.
 *
 * `WordPress.Files.FileName` asks a file that declares a class to be named
 * `class-<lowercased class name>.php`. This file cannot be: its name is the
 * frozen entry point of the `lint:tested-up-to` script of `composer.json` and
 * of the revalidation job of `.github/workflows/ci.yml`, both of which invoke
 * it by that exact name. Renaming it to satisfy a naming convention would
 * rename the command two other files run. Its other half - the name has to be
 * lowercase and hyphenated - IS satisfied here. Same trade, and the same
 * reason, as `scripts/check-english.php`.
 *
 * `WordPress.WP.AlternativeFunctions` asks for `WP_Filesystem` and the
 * WordPress HTTP API. Neither exists here: this is a command line gate that
 * runs on bare PHP with no WordPress loaded, and every use below is a local
 * read of a file of this repository or a write to a standard stream. Same
 * trade, and the same reason, as `scripts/check-no-paywall.php`.
 */
// phpcs:disable WordPress.Files.FileName -- The name is the frozen entry point of `composer lint:tested-up-to`; see the block above.
// phpcs:disable WordPress.WP.AlternativeFunctions -- A command line gate on bare PHP: local file reads and terminal writes only; see the block above.

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Scripts;

/**
 * Version-coherence gate and extractor of the declared tested pair.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * `readme.txt` tells the public plugin directory four things about versions:
 * the WordPress the plugin requires, the WordPress it was tested against, and
 * the same two for WooCommerce. Three of those four numbers are written down
 * somewhere else as well - the plugin header repeats both minimums and the
 * WooCommerce tested version, and `Contract\Constants` freezes the two
 * minimums, which is the copy that actually refuses to boot - and until this
 * gate existed nothing compared them with each other.
 *
 * Everything that can go wrong there goes wrong in silence. A listing whose
 * floor is above its ceiling is published exactly as written. A minimum raised
 * in the listing alone hides the plugin from a WordPress the code still runs
 * on; lowered in the listing alone it invites an installation the code refuses
 * on activation. A declaration that stops being a version - "latest" - reads
 * like an answer and is not one. None of these break a test, slow anything
 * down or produce a wrong result: they produce a plugin page that says
 * something untrue to strangers.
 *
 * ---------------------------------------------------------------------------
 * WHY IT DOES NOT COMPARE THE DECLARATION WITH THE CONTAINERISED PIN
 * ---------------------------------------------------------------------------
 * The tempting reading of this debt is to compare `Tested up to` of the listing
 * with the `core` pin of `.wp-env.json` and fail when the first is greater.
 * That comparison is REJECTED here, and the reason is worth keeping in the file
 * rather than in a commit message.
 *
 * The two numbers are different ON PURPOSE. The pin is the FLOOR the plugin
 * promises to support, and the end-to-end suite runs against it on every push
 * so that the promise keeps being true. The declaration is the CEILING that was
 * measured separately - `docs/publishing.md` documents the override file and
 * the environment variables that do it - and it is the number the directory
 * shows and the official plugin check reads.
 *
 * A gate comparing them would put a legitimately measured declaration in red,
 * and the only edit that turns that red green is lowering the declaration.
 * Lowering a measured claim to satisfy a gate is losing published coverage in
 * exchange for a colour. So the declaration is revalidated by EXECUTING it: the
 * emission mode below hands the declared pair to a continuous integration job
 * that starts the environment against that pair and runs the whole end-to-end
 * suite. Raising the number without the suite passing against it leaves the
 * build red; raising it with the suite passing is exactly what the number is
 * supposed to mean. This gate checks what can be checked by reading - internal
 * coherence - and refuses to pretend that reading is the same as running.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS CHECKED, AND WHAT IS DELIBERATELY NOT
 * ---------------------------------------------------------------------------
 * Checked:
 *   - the four version declarations of the listing are present and readable;
 *   - the two minimums and the tested WooCommerce of the plugin header, and the
 *     two minimums frozen in `Contract\Constants`, are present and readable;
 *   - no tested version is below the minimum its own listing requires;
 *   - the WordPress minimum agrees across the listing, the plugin header and
 *     the constant, and the same for the WooCommerce one;
 *   - the WooCommerce version declared as tested agrees between the listing and
 *     the plugin header, which are the two places that carry it. WordPress has
 *     no tested header, so its claim has one home and nothing to disagree with.
 *
 * NOT checked, on purpose and stated so that a green run is not read as more
 * than it is: the minimum PHP version. It is declared in FIVE places - the
 * listing, the plugin header, `Constants::MINIMUM_PHP_VERSION`, `require.php`
 * of `composer.json` and `testVersion` of `phpcs.xml.dist` - and a gate that
 * compared three of the five would be a gate that reports "the PHP floor is
 * coherent" while two of its homes are unread. Either all five or none, and all
 * five is a different check from this one, whose subject is the pair of
 * platforms the directory publishes.
 *
 * ---------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------
 *     php scripts/check-tested-up-to.php [--root <dir>] [--listing <path>] [--emit]
 *
 *   --root <dir>     Treat <dir> as the repository root. Defaults to the parent
 *                    of this script's directory. The plugin header and the
 *                    constants file are read from under it.
 *   --listing <path> Read the listing from <path> instead of <root>/readme.txt.
 *                    The self-test points the gate at its fixtures with it.
 *   --emit           Print the declared tested pair on standard output and
 *                    NOTHING else, in the form continuous integration consumes:
 *
 *                        FACTUAREA_E2E_WORDPRESS=<x.y>
 *                        FACTUAREA_E2E_WOOCOMMERCE=<x.y>
 *
 *                    Those are the two names `tests/e2e/global-setup.js`
 *                    already reads, so the job appends this output to the file
 *                    the runner exports and the harness needs no second naming
 *                    convention to keep in sync. The checks run FIRST: a
 *                    listing the gate refuses emits no pair at all, because
 *                    handing continuous integration a declaration that was just
 *                    found broken is how a broken declaration gets a green
 *                    tick.
 *
 * Exit codes:
 *   - 0 - the declarations are coherent.
 *   - 1 - at least one declaration is unreadable or contradicts another.
 *   - 2 - the gate could not run as configured: an unknown option, an option
 *     without its value, or one of the three files missing or unreadable.
 *
 * The boundary between 1 and 2 is deliberate. A file that is not there is not
 * "coherent" and not "incoherent": the comparison did not happen, and saying so
 * with its own exit code is what keeps "I could not look" from being reported
 * as "I looked and it is fine". A file that IS there and says something
 * unreadable is a finding, because the declaration exists and is wrong.
 */
final class TestedUpToGate {

	/**
	 * The declarations are coherent.
	 *
	 * @var int
	 */
	private const EXIT_OK = 0;

	/**
	 * At least one declaration is unreadable or contradicts another.
	 *
	 * @var int
	 */
	private const EXIT_FINDINGS = 1;

	/**
	 * The gate could not run as configured.
	 *
	 * @var int
	 */
	private const EXIT_MISCONFIGURED = 2;

	/**
	 * Plugin entry point, relative to the repository root.
	 *
	 * @var string
	 */
	private const PLUGIN_FILE = 'factuarea-for-woocommerce.php';

	/**
	 * File carrying the frozen minimums, relative to the repository root.
	 *
	 * @var string
	 */
	private const CONSTANTS_FILE = 'src/Contract/Constants.php';

	/**
	 * Default listing, relative to the repository root.
	 *
	 * @var string
	 */
	private const LISTING_FILE = 'readme.txt';

	/**
	 * The two platforms, and how each one is declared in each of its homes.
	 *
	 * Written as data rather than as two copies of the same code: the only
	 * difference between the WordPress checks and the WooCommerce ones is these
	 * strings, and two copies is how one of them stops being checked after
	 * somebody edits the other.
	 *
	 * `header_tested_field` is the asymmetry of the two platforms, and it is
	 * null for WordPress on purpose. WordPress has no `Tested up to` header: the
	 * claim lives in the listing and nowhere else, so there is nothing to
	 * compare it with. WooCommerce does have one - `WC tested up to` is read
	 * from the plugin header to decide whether the shop shows the
	 * incompatibility notice - so that claim has TWO homes, and two homes that
	 * nothing compares is the same defect as the three homes of the minimums,
	 * one platform over.
	 *
	 * @var array<string, array{minimum_field: string, tested_field: string, header_tested_field: ?string, constant: string, variable: string}>
	 */
	private const PLATFORMS = array(
		'WordPress'   => array(
			'minimum_field'       => 'Requires at least',
			'tested_field'        => 'Tested up to',
			'header_tested_field' => null,
			'constant'            => 'MINIMUM_WORDPRESS_VERSION',
			'variable'            => 'FACTUAREA_E2E_WORDPRESS',
		),
		'WooCommerce' => array(
			'minimum_field'       => 'WC requires at least',
			'tested_field'        => 'WC tested up to',
			'header_tested_field' => 'WC tested up to',
			'constant'            => 'MINIMUM_WOOCOMMERCE_VERSION',
			'variable'            => 'FACTUAREA_E2E_WOOCOMMERCE',
		),
	);

	/**
	 * How each source is named in a report.
	 *
	 * @var array<string, string>
	 */
	private const HOME_NAMES = array(
		'listing'   => 'listing',
		'plugin'    => 'plugin header',
		'constants' => 'frozen constants',
	);

	/**
	 * Shape of a version: one, two or three numbers separated by dots.
	 *
	 * WordPress and WooCommerce declare series - `6.5`, `8.9` - and sometimes a
	 * patch. Anything else is not something an environment can be started
	 * against, which is the whole point of the number.
	 *
	 * @var string
	 */
	private const VERSION_PATTERN = '/^\d+(?:\.\d+){0,2}$/';

	/**
	 * Whether standard output is reserved for the emitted pair.
	 *
	 * @var bool
	 */
	private static bool $emitting = false;

	/**
	 * Runs the gate.
	 *
	 * @param string[] $arguments Arguments, without the script name.
	 * @return int One of the three exit codes.
	 */
	public static function run( array $arguments ): int {
		$root    = dirname( __DIR__ );
		$listing = null;
		$emit    = false;

		while ( array() !== $arguments ) {
			$argument = array_shift( $arguments );

			if ( '--help' === $argument || '-h' === $argument ) {
				self::write_out( self::usage() );

				return self::EXIT_OK;
			}

			if ( '--emit' === $argument ) {
				$emit = true;
				continue;
			}

			if ( '--root' === $argument || '--listing' === $argument ) {
				$value = array_shift( $arguments );

				if ( null === $value || '' === $value ) {
					self::fail( sprintf( '"%s" needs a path.', $argument ) );

					return self::EXIT_MISCONFIGURED;
				}

				if ( '--root' === $argument ) {
					$root = $value;
				} else {
					$listing = $value;
				}

				continue;
			}

			self::fail( sprintf( 'unknown option "%s". %s', $argument, trim( self::usage() ) ) );

			return self::EXIT_MISCONFIGURED;
		}

		self::$emitting = $emit;

		if ( ! is_dir( $root ) ) {
			self::fail( sprintf( '"%s" is not a directory.', $root ) );

			return self::EXIT_MISCONFIGURED;
		}

		$sources = array(
			'listing'   => null === $listing ? $root . '/' . self::LISTING_FILE : $listing,
			'plugin'    => $root . '/' . self::PLUGIN_FILE,
			'constants' => $root . '/' . self::CONSTANTS_FILE,
		);

		$contents = array();
		foreach ( $sources as $name => $path ) {
			$read = self::read( $path );

			if ( null === $read ) {
				self::fail(
					sprintf(
						'the %s is not at "%s", or cannot be read. Nothing was compared, and that is not the same as nothing being wrong.',
						$name,
						$path
					)
				);

				return self::EXIT_MISCONFIGURED;
			}

			$contents[ $name ] = $read;
		}

		$findings = array();
		$tested   = array();

		foreach ( self::PLATFORMS as $platform => $homes ) {
			$declarations = self::declarations_of( $platform, $homes, $sources, $contents, $findings );

			if ( null === $declarations ) {
				continue;
			}

			self::check_tested_against_minimum( $platform, $declarations, $findings );
			self::check_the_three_minimums( $platform, $declarations, $findings );
			self::check_the_two_tested( $platform, $declarations, $findings );

			$tested[ $homes['variable'] ] = $declarations['tested']['value'];
		}

		if ( array() !== $findings ) {
			self::report( $findings );

			return self::EXIT_FINDINGS;
		}

		if ( $emit ) {
			foreach ( $tested as $variable => $version ) {
				self::write_pair( $variable . '=' . $version . PHP_EOL );
			}

			return self::EXIT_OK;
		}

		self::write_out( 'tested-up-to gate: the declared versions are coherent.' . PHP_EOL );
		foreach ( self::PLATFORMS as $platform => $homes ) {
			self::write_out(
				sprintf(
					'    %s: requires %s, tested %s' . PHP_EOL,
					$platform,
					self::declaration_value( $contents['listing'], $homes['minimum_field'] ),
					self::declaration_value( $contents['listing'], $homes['tested_field'] )
				)
			);
		}

		return self::EXIT_OK;
	}

	/**
	 * Reads the four declarations of one platform, or records why it cannot.
	 *
	 * Returns null when any of the four is missing or unreadable: a comparison
	 * between a number and a hole is not a comparison, and reporting it as one
	 * would bury the finding that matters - the hole - under a second one that
	 * is only its consequence.
	 *
	 * @param string                                                                                 $platform Platform name, for the messages.
	 * @param array{minimum_field: string, tested_field: string, constant: string, variable: string} $homes    Where this platform is declared.
	 * @param array<string, string>                                                                  $sources  Path of each source, by name.
	 * @param array<string, string>                                                                  $contents Contents of each source, by name.
	 * @param list<array{path: string, line: ?int, message: string, details: list<string>}>          $findings     Findings, appended to.
	 * @return array<string, array{value: string, line: int, path: string}>|null
	 */
	private static function declarations_of(
		string $platform,
		array $homes,
		array $sources,
		array $contents,
		array &$findings
	): ?array {
		$wanted = array(
			'minimum'        => array( 'listing', self::field_reader( $homes['minimum_field'] ), $homes['minimum_field'] ),
			'tested'         => array( 'listing', self::field_reader( $homes['tested_field'] ), $homes['tested_field'] ),
			'header_minimum' => array( 'plugin', self::field_reader( $homes['minimum_field'] ), $homes['minimum_field'] ),
			'frozen_minimum' => array( 'constants', self::constant_reader( $homes['constant'] ), $homes['constant'] ),
		);

		if ( null !== $homes['header_tested_field'] ) {
			$wanted['header_tested'] = array(
				'plugin',
				self::field_reader( $homes['header_tested_field'] ),
				$homes['header_tested_field'],
			);
		}

		$declarations = array();

		foreach ( $wanted as $name => $where ) {
			list( $source, $reader, $label ) = $where;

			$found = $reader( $contents[ $source ] );

			if ( null === $found ) {
				$findings[] = self::finding(
					$sources[ $source ],
					null,
					sprintf(
						'the %s declaration of the %s is not there: no line declares "%s".',
						$platform,
						self::HOME_NAMES[ $source ],
						$label
					)
				);

				return null;
			}

			if ( 1 !== preg_match( self::VERSION_PATTERN, $found['value'] ) ) {
				$findings[] = self::finding(
					$sources[ $source ],
					$found['line'],
					sprintf(
						'"%s" does not declare a readable version: "%s". A declaration nothing can be started against is a claim with no meaning.',
						$label,
						$found['value']
					)
				);

				return null;
			}

			$found['path']         = $sources[ $source ];
			$declarations[ $name ] = $found;
		}

		return $declarations;
	}

	/**
	 * Fails when a tested version is below the minimum its own listing requires.
	 *
	 * @param string                                                                        $platform     Platform name, for the message.
	 * @param array<string, array{value: string, line: int, path: string}>                  $declarations The four readable declarations.
	 * @param list<array{path: string, line: ?int, message: string, details: list<string>}> $findings     Findings, appended to.
	 * @return void
	 */
	private static function check_tested_against_minimum( string $platform, array $declarations, array &$findings ): void {
		if ( ! version_compare( $declarations['tested']['value'], $declarations['minimum']['value'], '<' ) ) {
			return;
		}

		$findings[] = self::finding(
			$declarations['tested']['path'],
			$declarations['tested']['line'],
			sprintf(
				'the %s version declared as tested (%s) is below the minimum the listing requires (%s), which is a range whose floor is above its ceiling.',
				$platform,
				$declarations['tested']['value'],
				$declarations['minimum']['value']
			),
			array(
				sprintf( 'required: %s (%s:%d)', $declarations['minimum']['value'], $declarations['minimum']['path'], $declarations['minimum']['line'] ),
				sprintf( 'tested:   %s (%s:%d)', $declarations['tested']['value'], $declarations['tested']['path'], $declarations['tested']['line'] ),
			)
		);
	}

	/**
	 * Fails when the three homes of a minimum do not agree, naming the three.
	 *
	 * All three values are printed even when only one of them moved, because
	 * "they disagree" does not say which one is the odd one, and the constant is
	 * the one that actually refuses to boot.
	 *
	 * @param string                                                                        $platform     Platform name, for the message.
	 * @param array<string, array{value: string, line: int, path: string}>                  $declarations The four readable declarations.
	 * @param list<array{path: string, line: ?int, message: string, details: list<string>}> $findings     Findings, appended to.
	 * @return void
	 */
	private static function check_the_three_minimums( string $platform, array $declarations, array &$findings ): void {
		$homes = array(
			'listing'         => $declarations['minimum'],
			'plugin header'   => $declarations['header_minimum'],
			'frozen constant' => $declarations['frozen_minimum'],
		);

		$values = array();
		foreach ( $homes as $home ) {
			$values[] = $home['value'];
		}

		if ( 1 === count( array_unique( $values ) ) ) {
			return;
		}

		$details = array();
		foreach ( $homes as $name => $home ) {
			$details[] = sprintf( '%-16s %s (%s:%d)', $name . ':', $home['value'], $home['path'], $home['line'] );
		}

		$findings[] = self::finding(
			$declarations['minimum']['path'],
			$declarations['minimum']['line'],
			sprintf(
				'the %s minimum is declared in three places and the three do not agree. The constant is the one that refuses to boot; the listing is the one the directory publishes.',
				$platform
			),
			$details
		);
	}

	/**
	 * Fails when a platform declares its tested version twice and the two copies
	 * do not say the same thing.
	 *
	 * Only WooCommerce reaches this check, and the enumeration in PLATFORMS says
	 * why: WordPress declares what it was tested against in the listing alone.
	 * The WooCommerce claim is in the listing AND in the plugin header, and the
	 * two are read by different readers - the directory shows the first, the
	 * shop decides its incompatibility notice from the second - so two numbers
	 * here is two different answers to the same question, given to two different
	 * people.
	 *
	 * @param string                                                                        $platform     Platform name, for the message.
	 * @param array<string, array{value: string, line: int, path: string}>                  $declarations The readable declarations.
	 * @param list<array{path: string, line: ?int, message: string, details: list<string>}> $findings     Findings, appended to.
	 * @return void
	 */
	private static function check_the_two_tested( string $platform, array $declarations, array &$findings ): void {
		if ( ! array_key_exists( 'header_tested', $declarations ) ) {
			return;
		}

		if ( $declarations['tested']['value'] === $declarations['header_tested']['value'] ) {
			return;
		}

		$findings[] = self::finding(
			$declarations['tested']['path'],
			$declarations['tested']['line'],
			sprintf(
				'the %s version declared as tested is written in two places and they do not agree. The directory publishes the listing; the shop reads the plugin header.',
				$platform
			),
			array(
				sprintf(
					'%-16s %s (%s:%d)',
					'listing:',
					$declarations['tested']['value'],
					$declarations['tested']['path'],
					$declarations['tested']['line']
				),
				sprintf(
					'%-16s %s (%s:%d)',
					'plugin header:',
					$declarations['header_tested']['value'],
					$declarations['header_tested']['path'],
					$declarations['header_tested']['line']
				),
			)
		);
	}

	/**
	 * A reader of a listing or plugin header field.
	 *
	 * The field is anchored at the start of the line, after whatever comment
	 * decoration the plugin header puts there. That anchoring is what keeps
	 * "Requires at least" from also matching the "WC requires at least" line,
	 * which is the one mistake this parser could make that would go unnoticed:
	 * it would compare the WooCommerce minimum with itself and call it coherent.
	 *
	 * @param string $field Field name as written.
	 * @return callable(string): ?array{value: string, line: int}
	 */
	private static function field_reader( string $field ): callable {
		$pattern = '/^[\s*\/#@]*' . preg_quote( $field, '/' ) . '\s*:(.*)$/i';

		return static function ( string $contents ) use ( $pattern ): ?array {
			return self::first_match( $contents, $pattern );
		};
	}

	/**
	 * A reader of a frozen constant of `Contract\Constants`.
	 *
	 * The source is parsed rather than loaded. Loading it would need the
	 * autoloader and would pull in the class the constants file references,
	 * which is a WordPress-facing endpoint: a gate that needs the plugin to be
	 * loadable to tell you the versions disagree is a gate that stops working
	 * on the day it is most needed.
	 *
	 * @param string $name Constant name.
	 * @return callable(string): ?array{value: string, line: int}
	 */
	private static function constant_reader( string $name ): callable {
		$pattern = '/^\s*(?:public\s+|final\s+)*const\s+' . preg_quote( $name, '/' ) . '\s*=\s*\'([^\']*)\'/';

		return static function ( string $contents ) use ( $pattern ): ?array {
			return self::first_match( $contents, $pattern );
		};
	}

	/**
	 * First line matching a pattern, with the captured value trimmed.
	 *
	 * @param string $contents File contents.
	 * @param string $pattern  Pattern with exactly one capturing group.
	 * @return array{value: string, line: int}|null
	 */
	private static function first_match( string $contents, string $pattern ): ?array {
		$lines = preg_split( '/\r\n|\n|\r/', $contents );

		if ( false === $lines ) {
			return null;
		}

		foreach ( $lines as $index => $line ) {
			$matched = array();

			if ( 1 === preg_match( $pattern, $line, $matched ) ) {
				return array(
					'value' => trim( $matched[1] ),
					'line'  => $index + 1,
				);
			}
		}

		return null;
	}

	/**
	 * Value of a listing field, for the closing summary of a clean run.
	 *
	 * Only ever called after every declaration has been read and validated, so
	 * the empty string it returns for a missing field is unreachable rather than
	 * a silent default.
	 *
	 * @param string $contents Listing contents.
	 * @param string $field    Field name as written.
	 * @return string
	 */
	private static function declaration_value( string $contents, string $field ): string {
		$reader = self::field_reader( $field );
		$found  = $reader( $contents );

		return null === $found ? '' : $found['value'];
	}

	/**
	 * Reads a file, or null when it is not a readable file.
	 *
	 * @param string $path Absolute or relative path.
	 * @return string|null
	 */
	private static function read( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path );

		return false === $contents ? null : $contents;
	}

	/**
	 * Builds a finding.
	 *
	 * @param string   $path    File the finding is about.
	 * @param int|null $line    Line of the declaration, when there is one.
	 * @param string   $message What is wrong.
	 * @param string[] $details Extra lines, printed indented.
	 * @return array{path: string, line: ?int, message: string, details: list<string>}
	 */
	private static function finding( string $path, ?int $line, string $message, array $details = array() ): array {
		return array(
			'path'    => $path,
			'line'    => $line,
			'message' => $message,
			'details' => $details,
		);
	}

	/**
	 * Prints the findings.
	 *
	 * @param list<array{path: string, line: ?int, message: string, details: list<string>}> $findings Findings.
	 * @return void
	 */
	private static function report( array $findings ): void {
		foreach ( $findings as $finding ) {
			self::write_report(
				$finding['path']
				. ( null === $finding['line'] ? '' : ':' . $finding['line'] )
				. ': ' . $finding['message'] . PHP_EOL
			);

			foreach ( $finding['details'] as $detail ) {
				self::write_report( '    ' . $detail . PHP_EOL );
			}
		}

		self::write_report(
			'tested-up-to gate: ' . count( $findings ) . ' finding(s). The four declarations of the listing, '
			. 'the three of the plugin header and the two frozen in Constants are one promise written in '
			. 'three places, and they are changed in one commit or not at all.' . PHP_EOL
		);
	}

	/**
	 * Usage text.
	 *
	 * @return string
	 */
	private static function usage(): string {
		return 'Version-coherence gate.' . PHP_EOL . PHP_EOL
			. '  php check-tested-up-to.php [--root <dir>] [--listing <path>] [--emit]' . PHP_EOL . PHP_EOL
			. 'Fails when the four version declarations of the listing are unreadable, when a tested' . PHP_EOL
			. 'version is below its own minimum, when the minimums of the listing, of the plugin' . PHP_EOL
			. 'header and of Contract\\Constants do not agree, or when the two WooCommerce tested' . PHP_EOL
			. 'declarations disagree. With --emit it prints the declared' . PHP_EOL
			. 'tested pair, and nothing else, for the job that revalidates it by running against it.' . PHP_EOL . PHP_EOL
			. 'Exit codes: 0 coherent, 1 findings, 2 the gate could not run as configured.' . PHP_EOL;
	}

	/**
	 * Writes the emitted pair, which is the only thing allowed on standard
	 * output in emission mode.
	 *
	 * @param string $line Line to write.
	 * @return void
	 */
	private static function write_pair( string $line ): void {
		fwrite( STDOUT, $line );
	}

	/**
	 * Writes a line of the report.
	 *
	 * In emission mode it goes to standard error: standard output is being
	 * redirected into the file continuous integration reads, and a finding that
	 * travelled there would become an environment variable nobody wrote.
	 *
	 * @param string $line Line to write.
	 * @return void
	 */
	private static function write_report( string $line ): void {
		fwrite( self::$emitting ? STDERR : STDOUT, $line );
	}

	/**
	 * Writes a line the gate says for itself.
	 *
	 * @param string $line Line to write.
	 * @return void
	 */
	private static function write_out( string $line ): void {
		fwrite( self::$emitting ? STDERR : STDOUT, $line );
	}

	/**
	 * Writes the reason the gate could not run.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	private static function fail( string $message ): void {
		fwrite( STDERR, 'tested-up-to gate: ' . $message . PHP_EOL );
	}
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument is a process exit status, not output: escaping an integer for HTML would be meaningless here.
exit( TestedUpToGate::run( array_slice( $argv, 1 ) ) );
