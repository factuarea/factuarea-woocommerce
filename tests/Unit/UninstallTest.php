<?php
/**
 * Unit test of the uninstall routine.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\Constants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What this test holds, and why it is built the way it is.
 *
 * THE BEHAVIOURAL HALF RUNS THE REAL FILE, IN A CHILD PROCESS.
 *
 * `uninstall.php` is guarded by a constant, and a constant cannot be undefined
 * once it is defined. Two of the three facts that have to be proved need the
 * constant present and the third needs it absent, so no ordering of tests
 * inside one process can prove all three: whichever ran first would decide the
 * answer for the rest, and PHPUnit is configured to reorder tests by defects.
 * The file is therefore included by a small harness script running in a child
 * PHP process, once per case, with the presence of the constant handed to it in
 * the environment. Nothing is mocked away: the child includes the same
 * `uninstall.php` that ships, loads the same frozen contract it loads on a real
 * site, and the only things replaced are the WordPress functions themselves,
 * which record what they were asked to do.
 *
 * That structure buys a second thing worth more than the ordering fix. The
 * child declares NO composer autoloader, so the run also proves what a real
 * uninstall needs and what the packaged artefact makes true: the file finds the
 * option list on its own, in a process where the plugin was never loaded and
 * where `vendor/` does not exist.
 *
 * THE STATIC HALF READS THE SOURCE, BECAUSE THE DANGEROUS FAILURES ARE SILENT.
 *
 * Deleting the merchant's orders or dropping the outbox table would not throw;
 * it would succeed, and the behavioural half would still be green, because the
 * only way to observe it is to look at what the file is written to do. The same
 * goes for the two ways this file could quietly stop protecting anything:
 * selecting options by matching the `factuarea_wc_` prefix instead of the
 * enumerated list, and reading an option value on its way to deleting it, which
 * is how a credential ends up somewhere it can be seen.
 *
 * THE COVERAGE HALF IS THE ONE THAT WILL FAIL ON SOMEBODY ELSE'S CODE.
 *
 * An uninstall routine deletes the list it was given; it has no way to know
 * about an option a later phase invented. So the list is checked against the
 * source of `src/`, in both directions, and a new option name that nobody added
 * to `Constants::ALL_OPTIONS` turns this test red. That is the intended
 * outcome and the whole point: the alternative is an uninstall that leaves
 * something behind and a green suite that says it did not.
 */
final class UninstallTest extends TestCase {

	/**
	 * Option names that carry the plugin prefix and are NOT options.
	 *
	 * The reason is the KEY, so an entry without one does not compile. The
	 * shape mirrors the coverage baselines of the monorepo on purpose: an
	 * exclusion is a decision somebody wrote down, never an omission.
	 *
	 * Everything here shares the prefix because every public name of this plugin
	 * does, and none of the four is written to the options table. Each was read
	 * off the source rather than assumed: an entry that says "not an option"
	 * without somebody having looked is how a real option gets waved through.
	 *
	 * @var array<string, list<string>>
	 */
	private const NON_OPTION_NAMES_BY_DESIGN = array(
		'Filter name published by the classic checkout field, not a stored option' => array(
			'factuarea_wc_classic_additional_fields_version',
		),
		'Settings API identifiers of the settings screen: the group the options are registered against, the single section of the screen, and the two admin-post actions - the one that generates the signing secret and the one that runs the connection test. None is stored; the options they organise are Constants::OPTION_* and are deleted' => array(
			'factuarea_wc_connection',
			'factuarea_wc_generate_signing_secret',
			'factuarea_wc_settings',
			'factuarea_wc_test_connection',
		),
		'Admin-post action the buyer invoice download is dispatched by, not a stored option. MyAccount\InvoicePdfLink writes no option at all: it reads Constants::OPTION_API_KEY, which the uninstall already deletes, and the invoice identity it shows lives in order meta, which is the merchant record this uninstall deliberately keeps' => array(
			'factuarea_wc_invoice_pdf',
		),
		'Action Scheduler hook the recurring outbox drain is fired under, not a stored option. Plugin::DRAIN_HOOK names an action that Plugin::wire() listens to and that Plugin::schedule_drain() puts on the WooCommerce scheduler; the schedule itself lives in the scheduler tables, which the deactivation routine unschedules by hook and group, and which this plugin does not own' => array(
			'factuarea_wc_drain_outbox',
		),
	);

	/**
	 * Calls to the option API whose name cannot be read statically, and which
	 * were LOOKED AT and found not to hide an option of their own.
	 *
	 * The key is the reason and the entry names the file, the function and the
	 * argument as written - never a line number, because a line number turns
	 * every edit of somebody else's file into a red of this one, and a reader
	 * who has to re-decide should be re-deciding about a shape rather than about
	 * a position.
	 *
	 * `SettingsPage::credential_status( string $option )` is a private forwarder
	 * that answers "configured" or "not configured" without touching the value.
	 * Measured on 2026-09-02: it has exactly two callers, and they pass
	 * `Constants::OPTION_API_KEY` and `Constants::OPTION_SIGNING_SECRET`, both of
	 * which the uninstall deletes. It reads no option of its own.
	 *
	 * @var array<string, list<string>>
	 */
	private const UNRESOLVED_OPTION_CALLS_BY_DESIGN = array(
		'Private forwarder of the settings screen whose two callers both pass a Constants::OPTION_* already in the deleted list' => array(
			'src/Admin/SettingsPage.php [get_option($option)]',
		),
	);

	/**
	 * WordPress functions that READ or WRITE an option, as opposed to deleting
	 * one. A call to any of them in `src/` means the plugin stores an option
	 * under whatever name it passes, and that name has to be in the deleted
	 * list.
	 *
	 * @var list<string>
	 */
	private const OPTION_REGISTERING_FUNCTIONS = array(
		'get_option',
		'add_option',
		'update_option',
		'get_site_option',
		'add_site_option',
		'update_site_option',
	);

	/**
	 * Things `uninstall.php` must never contain, each with what would happen if
	 * it did. Matched against the file's TOKENS with comments stripped, because
	 * the documentation of that file has to be free to explain why it does not
	 * do these things - and it does explain it - without the check mistaking
	 * the explanation for the deed.
	 *
	 * @var array<string, string>
	 */
	private const FORBIDDEN_CODE_TOKENS = array(
		'wpdb'               => 'a direct database access during uninstall is how a table gets dropped or options get matched by prefix',
		'delete_post_meta'   => 'the tax identifier on an order is the merchant accounting record, not the plugin state',
		'delete_metadata'    => 'same reason as delete_post_meta, one layer down',
		'delete_user_meta'   => 'the plugin stores nothing on a user, so a call here could only be destroying somebody else data',
		'delete_site_option' => 'the plugin registers no network option, so this could only delete something another plugin created',
		'get_option'         => 'reading an option value on the way to deleting it puts a credential in a variable for no reason',
		'get_site_option'    => 'same reason as get_option',
		'error_log'          => 'this file handles a signing secret and an API key and writes no log line at all',
	);

	/**
	 * The child process reports what the WordPress doubles were asked to do.
	 *
	 * @param bool $uninstall_context Whether WP_UNINSTALL_PLUGIN is defined.
	 * @param bool $multisite         Whether is_multisite() answers true.
	 *
	 * @return array{deleted: list<string>, forbidden: list<string>, restored: int, switched: list<int>, get_sites_args: array<string, mixed>}
	 */
	private function run_uninstall( bool $uninstall_context, bool $multisite ): array {
		if ( ! is_string( PHP_BINARY ) || '' === PHP_BINARY || ! is_executable( PHP_BINARY ) ) {
			$this->markTestSkipped(
				'SKIPPED, not passed: the uninstall file has to be included in a child process to be exercised with and '
				. 'without its guard constant, and no usable PHP binary was found at PHP_BINARY.'
			);
		}

		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped(
				'SKIPPED, not passed: proc_open is disabled in this environment, so the uninstall file cannot be run in '
				. 'the child process the guard constant requires.'
			);
		}

		$harness_path = $this->write_harness();

		$environment = array_merge(
			getenv(),
			array(
				'FACTUAREA_HARNESS_UNINSTALL' => $this->repository_path( 'uninstall.php' ),
				'FACTUAREA_HARNESS_CONTEXT'   => $uninstall_context ? '1' : '0',
				'FACTUAREA_HARNESS_MULTISITE' => $multisite ? '1' : '0',
			)
		);

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', $harness_path ),
			$descriptors,
			$pipes,
			null,
			$environment
		);

		$this->assertIsResource( $process, 'The harness process could not be started.' );

		$standard_output = (string) stream_get_contents( $pipes[1] );
		$error_output    = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		unlink( $harness_path );

		$this->assertSame(
			0,
			$exit_code,
			"The harness process failed.\nstdout: {$standard_output}\nstderr: {$error_output}"
		);
		$this->assertSame(
			'',
			trim( $error_output ),
			"Including uninstall.php produced diagnostics, which on a real site would be output during a deletion:\n{$error_output}"
		);

		$report = json_decode( trim( $standard_output ), true );
		$this->assertIsArray( $report, "The harness produced no readable report. Raw output:\n{$standard_output}" );

		return $report;
	}

	/**
	 * Writes the harness script the child process runs and returns its path.
	 *
	 * Every WordPress function `uninstall.php` can reach is declared here, and
	 * the ones it must NOT call are declared too: an undeclared function would
	 * make the child die with a fatal error and hand back a failure that says
	 * nothing, while a declared one records the call and names it.
	 *
	 * @return string
	 */
	private function write_harness(): string {
		$harness = <<<'PHP'
<?php

$GLOBALS['factuarea_report'] = array(
	'deleted'        => array(),
	'forbidden'      => array(),
	'restored'       => 0,
	'switched'       => array(),
	'get_sites_args' => array(),
	'blog'           => 0,
);

function factuarea_harness_forbidden( string $what ): void {
	$GLOBALS['factuarea_report']['forbidden'][] = $what;
}

function delete_option( $name ) {
	$GLOBALS['factuarea_report']['deleted'][] = $GLOBALS['factuarea_report']['blog'] . '|' . $name;
	return true;
}

function get_option( $name, $fallback = false ) {
	factuarea_harness_forbidden( 'get_option:' . $name );
	return $fallback;
}

function add_option( $name, $value = '' ) {
	factuarea_harness_forbidden( 'add_option:' . $name );
	return true;
}

function update_option( $name, $value = '' ) {
	factuarea_harness_forbidden( 'update_option:' . $name );
	return true;
}

function get_site_option( $name, $fallback = false ) {
	factuarea_harness_forbidden( 'get_site_option:' . $name );
	return $fallback;
}

function delete_site_option( $name ) {
	factuarea_harness_forbidden( 'delete_site_option:' . $name );
	return true;
}

function delete_transient( $name ) {
	factuarea_harness_forbidden( 'delete_transient:' . $name );
	return true;
}

function delete_site_transient( $name ) {
	factuarea_harness_forbidden( 'delete_site_transient:' . $name );
	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	factuarea_harness_forbidden( 'delete_post_meta:' . $key );
	return true;
}

function delete_metadata( $type, $object_id, $key, $value = '', $all = false ) {
	factuarea_harness_forbidden( 'delete_metadata:' . $key );
	return true;
}

function delete_user_meta( $user_id, $key, $value = '' ) {
	factuarea_harness_forbidden( 'delete_user_meta:' . $key );
	return true;
}

function wp_cache_flush() {
	factuarea_harness_forbidden( 'wp_cache_flush' );
	return true;
}

function is_multisite() {
	return '1' === getenv( 'FACTUAREA_HARNESS_MULTISITE' );
}

function get_sites( $args = array() ) {
	$GLOBALS['factuarea_report']['get_sites_args'] = $args;
	return array( 1, 4, 9 );
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['factuarea_report']['blog']       = (int) $blog_id;
	$GLOBALS['factuarea_report']['switched'][] = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	++$GLOBALS['factuarea_report']['restored'];
	$GLOBALS['factuarea_report']['blog'] = 0;
	return true;
}

class Factuarea_Harness_Wpdb {
	public $prefix = 'wp_';

	public function query( $sql ) {
		factuarea_harness_forbidden( 'wpdb::query' );
		return 0;
	}

	public function get_results( $sql, $output = null ) {
		factuarea_harness_forbidden( 'wpdb::get_results' );
		return array();
	}

	public function get_col( $sql ) {
		factuarea_harness_forbidden( 'wpdb::get_col' );
		return array();
	}

	public function prepare( $sql, ...$arguments ) {
		factuarea_harness_forbidden( 'wpdb::prepare' );
		return $sql;
	}

	public function esc_like( $text ) {
		factuarea_harness_forbidden( 'wpdb::esc_like' );
		return $text;
	}
}

$GLOBALS['wpdb'] = new Factuarea_Harness_Wpdb();

if ( '1' === getenv( 'FACTUAREA_HARNESS_CONTEXT' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'factuarea-for-woocommerce/factuarea-for-woocommerce.php' );
}

require getenv( 'FACTUAREA_HARNESS_UNINSTALL' );

unset( $GLOBALS['factuarea_report']['blog'] );

echo json_encode( $GLOBALS['factuarea_report'] );
PHP;

		$path = tempnam( sys_get_temp_dir(), 'factuarea-uninstall-harness-' );
		$this->assertIsString( $path, 'A temporary file for the harness could not be created.' );
		file_put_contents( $path, $harness );

		return $path;
	}

	/**
	 * Absolute path of something in the repository.
	 *
	 * @param string $relative Path relative to the repository root.
	 *
	 * @return string
	 */
	private function repository_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	/**
	 * Every PHP file of `src/`, sorted, so a failure names them in a stable
	 * order.
	 *
	 * @return list<string>
	 */
	private function source_files(): array {
		$directory = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->repository_path( 'src' ), \FilesystemIterator::SKIP_DOTS )
		);

		$files = array();
		foreach ( $directory as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Names carrying the plugin option prefix that appear as string literals in
	 * the given files.
	 *
	 * Only literals are collected, never the contents of a comment: the
	 * documentation of this plugin names options constantly, and a check that
	 * could not tell an explanation from an instruction would be unusable.
	 *
	 * @param list<string> $files Absolute paths.
	 *
	 * @return array<string, list<string>> Name to the places it appears.
	 */
	private function prefixed_literals( array $files ): array {
		$found = array();

		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );

			foreach ( $tokens as $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					continue;
				}

				$value = substr( $token[1], 1, -1 );

				if ( 1 !== preg_match( '/^' . preg_quote( Constants::OPTION_PREFIX, '/' ) . '[a-z0-9_]+$/', $value ) ) {
					continue;
				}

				$found[ $value ][] = $this->relative( $file ) . ':' . $token[2];
			}
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Option names passed to a reading or writing call of the WordPress option
	 * API in the given files.
	 *
	 * The first argument is resolved when it is a string literal or a class
	 * constant that can be loaded. When it is anything else - a variable, a
	 * concatenation, a method call - the call site is reported as UNRESOLVED
	 * rather than skipped: an option name this check could not read is a name
	 * it cannot vouch for, and treating it as absent would be the silent green
	 * this whole file exists to refuse.
	 *
	 * @param list<string> $files Absolute paths.
	 *
	 * @return array{resolved: array<string, list<string>>, unresolved: list<string>}
	 */
	private function registered_option_names( array $files ): array {
		$resolved   = array();
		$unresolved = array();

		foreach ( $files as $file ) {
			$tokens         = token_get_all( (string) file_get_contents( $file ) );
			$count          = count( $tokens );
			$namespace      = '';
			$class          = '';
			$imports        = array();
			$expect_import  = false;
			$import_buffer  = '';

			for ( $index = 0; $index < $count; $index++ ) {
				$token = $tokens[ $index ];

				if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
					$namespace = trim( $this->read_name( $tokens, $index + 1 ) );
					continue;
				}

				if ( is_array( $token ) && T_USE === $token[0] ) {
					$expect_import = true;
					$import_buffer = '';
					continue;
				}

				if ( $expect_import ) {
					if ( ';' === $token || '{' === $token || '(' === $token ) {
						$expect_import = false;
						$import_buffer = trim( $import_buffer );
						if ( '' !== $import_buffer ) {
							$parts                    = explode( '\\', $import_buffer );
							$imports[ end( $parts ) ] = $import_buffer;
						}
						continue;
					}
					if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
						$import_buffer .= $token[1];
					}
					continue;
				}

				if ( is_array( $token ) && in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
					$name = $this->read_name( $tokens, $index + 1 );
					if ( '' !== $name ) {
						$class = $name;
					}
					continue;
				}

				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}

				if ( ! in_array( $token[1], self::OPTION_REGISTERING_FUNCTIONS, true ) ) {
					continue;
				}

				$previous = $this->previous_significant( $tokens, $index );
				if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}

				$next = $this->next_significant( $tokens, $index );
				if ( '(' !== $next['token'] ) {
					continue;
				}

				$argument = $this->first_argument_tokens( $tokens, $next['index'] );
				$name     = $this->resolve_option_name( $argument, $namespace, $class, $imports );

				if ( null === $name ) {
					$unresolved[] = $this->relative( $file ) . ' [' . $token[1] . '(' . $this->argument_text( $argument ) . ')]';
					continue;
				}

				$resolved[ $name ][] = $this->relative( $file ) . ':' . $token[2] . ' [' . $token[1] . ']';
			}
		}

		ksort( $resolved );
		$unresolved = array_values( array_unique( $unresolved ) );
		sort( $unresolved );

		return array(
			'resolved'   => $resolved,
			'unresolved' => $unresolved,
		);
	}

	/**
	 * The argument as it is written, so an exclusion can name a shape instead
	 * of a position.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $argument Argument tokens.
	 *
	 * @return string
	 */
	private function argument_text( array $argument ): string {
		$text = '';

		foreach ( $argument as $token ) {
			$text .= is_array( $token ) ? $token[1] : $token;
		}

		return $text;
	}

	/**
	 * Reads a name (possibly qualified) starting at the given index.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list.
	 * @param int                                                 $index  Where to start.
	 *
	 * @return string
	 */
	private function read_name( array $tokens, int $index ): string {
		$name  = '';
		$count = count( $tokens );

		for ( ; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				if ( '' !== $name ) {
					break;
				}
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$name .= $token[1];
				continue;
			}

			break;
		}

		return $name;
	}

	/**
	 * The token before the given index, skipping whitespace and comments.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list.
	 * @param int                                                 $index  Reference index.
	 *
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private function previous_significant( array $tokens, int $index ) {
		for ( $cursor = $index - 1; $cursor >= 0; $cursor-- ) {
			$token = $tokens[ $cursor ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $token;
		}

		return null;
	}

	/**
	 * The token after the given index, skipping whitespace and comments.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list.
	 * @param int                                                 $index  Reference index.
	 *
	 * @return array{token: array{0: int, 1: string, 2: int}|string|null, index: int}
	 */
	private function next_significant( array $tokens, int $index ): array {
		$count = count( $tokens );

		for ( $cursor = $index + 1; $cursor < $count; $cursor++ ) {
			$token = $tokens[ $cursor ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return array(
				'token' => $token,
				'index' => $cursor,
			);
		}

		return array(
			'token' => null,
			'index' => $count,
		);
	}

	/**
	 * The tokens of the first argument of a call whose opening parenthesis is
	 * at the given index.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens         Token list.
	 * @param int                                                 $opening_index  Index of '('.
	 *
	 * @return list<array{0: int, 1: string, 2: int}|string>
	 */
	private function first_argument_tokens( array $tokens, int $opening_index ): array {
		$depth     = 0;
		$argument  = array();
		$count     = count( $tokens );

		for ( $cursor = $opening_index; $cursor < $count; $cursor++ ) {
			$token = $tokens[ $cursor ];

			if ( '(' === $token || '[' === $token ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $token || ']' === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}

			if ( ',' === $token && 1 === $depth ) {
				break;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$argument[] = $token;
		}

		return $argument;
	}

	/**
	 * Resolves the option name an argument denotes, or null when it cannot be
	 * read statically.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $argument  Argument tokens.
	 * @param string                                        $namespace Enclosing namespace.
	 * @param string                                        $class     Enclosing class name.
	 * @param array<string, string>                         $imports   Alias to fully qualified name.
	 *
	 * @return string|null
	 */
	private function resolve_option_name( array $argument, string $namespace, string $class, array $imports ): ?string {
		if ( 1 === count( $argument ) && is_array( $argument[0] ) && T_CONSTANT_ENCAPSED_STRING === $argument[0][0] ) {
			return substr( $argument[0][1], 1, -1 );
		}

		if ( 3 !== count( $argument ) ) {
			return null;
		}

		$owner    = $argument[0];
		$operator = $argument[1];
		$member   = $argument[2];

		$is_double_colon = is_array( $operator ) && T_DOUBLE_COLON === $operator[0];
		if ( ! $is_double_colon || ! is_array( $owner ) || ! is_array( $member ) ) {
			return null;
		}

		$owner_name  = $owner[1];
		$member_name = $member[1];

		if ( in_array( strtolower( $owner_name ), array( 'self', 'static' ), true ) ) {
			$fully_qualified = '' === $namespace ? $class : $namespace . '\\' . $class;
		} elseif ( isset( $imports[ $owner_name ] ) ) {
			$fully_qualified = $imports[ $owner_name ];
		} elseif ( str_starts_with( $owner_name, '\\' ) ) {
			$fully_qualified = ltrim( $owner_name, '\\' );
		} else {
			$fully_qualified = '' === $namespace ? $owner_name : $namespace . '\\' . $owner_name;
		}

		if ( '' === $fully_qualified || ! class_exists( $fully_qualified ) ) {
			return null;
		}

		$constant = $fully_qualified . '::' . $member_name;
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * The tokens of a file with comments and whitespace removed.
	 *
	 * @param string $file Absolute path.
	 *
	 * @return list<array{0: int, 1: string, 2: int}|string>
	 */
	private function code_tokens( string $file ): array {
		$kept = array();

		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$kept[] = $token;
		}

		return $kept;
	}

	/**
	 * A repository-relative path, so a failure message is readable.
	 *
	 * @param string $absolute Absolute path.
	 *
	 * @return string
	 */
	private function relative( string $absolute ): string {
		$root = dirname( __DIR__, 2 ) . '/';

		return str_starts_with( $absolute, $root ) ? substr( $absolute, strlen( $root ) ) : $absolute;
	}

	/**
	 * The entries of a by-design list, without their reasons.
	 *
	 * @param array<string, list<string>> $by_design Reason to entries.
	 *
	 * @return list<string>
	 */
	private function flatten( array $by_design ): array {
		$flat = array();

		foreach ( $by_design as $entries ) {
			foreach ( $entries as $entry ) {
				$flat[] = $entry;
			}
		}

		return $flat;
	}

	/**
	 * An uninstall deletes every option the plugin stores, and the two that
	 * hold a credential are among them.
	 *
	 * @return void
	 */
	public function test_uninstall_deletes_every_stored_option(): void {
		$report = $this->run_uninstall( true, false );

		$deleted = array_map(
			static fn( string $entry ): string => explode( '|', $entry, 2 )[1],
			$report['deleted']
		);

		sort( $deleted );
		$expected = Constants::ALL_OPTIONS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$deleted,
			'The uninstall routine did not delete exactly the enumerated option list.'
		);

		foreach ( Constants::CREDENTIAL_OPTIONS as $credential_option ) {
			$this->assertContains(
				$credential_option,
				$deleted,
				"The credential option {$credential_option} survived the uninstall, so the signing secret or the API key "
				. 'stayed in the merchant database after the plugin was deleted.'
			);
		}
	}

	/**
	 * Nothing that is not one of this plugin's options is touched.
	 *
	 * The doubles of the child process record every other way of destroying
	 * something - another option read or written, a network option, a
	 * transient, post or user metadata, a direct query - so a call to any of
	 * them arrives here by name instead of being noticed by somebody months
	 * later.
	 *
	 * @return void
	 */
	public function test_uninstall_touches_nothing_that_is_not_its_own_option(): void {
		$report = $this->run_uninstall( true, false );

		$this->assertSame(
			array(),
			$report['forbidden'],
			'The uninstall routine reached beyond its own option list: ' . implode( ', ', $report['forbidden'] )
		);
	}

	/**
	 * Included outside an uninstall, the file does nothing at all.
	 *
	 * This is the case the guard exists for: a direct request to the file, or a
	 * stray include, must not delete the credentials of a plugin that is still
	 * installed and working.
	 *
	 * @return void
	 */
	public function test_uninstall_does_nothing_outside_the_uninstall_context(): void {
		$report = $this->run_uninstall( false, false );

		$this->assertSame(
			array(),
			$report['deleted'],
			'The uninstall routine deleted options although WP_UNINSTALL_PLUGIN was not defined.'
		);
		$this->assertSame(
			array(),
			$report['forbidden'],
			'The uninstall routine did something although WP_UNINSTALL_PLUGIN was not defined: '
			. implode( ', ', $report['forbidden'] )
		);
	}

	/**
	 * The guard is spelled with the constant WordPress actually defines.
	 *
	 * A guard that tests a constant nobody defines is always true, so the file
	 * would never do anything and the behavioural test above would be the only
	 * thing that noticed - which it would, but only because it is written the
	 * way it is. This says it directly.
	 *
	 * @return void
	 */
	public function test_the_guard_reads_the_constant_wordpress_defines(): void {
		$tokens = $this->code_tokens( $this->repository_path( 'uninstall.php' ) );
		$found  = false;

		foreach ( $tokens as $position => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'defined' !== $token[1] ) {
				continue;
			}

			$argument = $this->first_argument_tokens( $tokens, $position + 1 );
			if ( 1 === count( $argument ) && is_array( $argument[0] ) && "'WP_UNINSTALL_PLUGIN'" === $argument[0][1] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			"uninstall.php does not guard itself with defined( 'WP_UNINSTALL_PLUGIN' ), which is the only signal that "
			. 'WordPress is genuinely uninstalling the plugin.'
		);
	}

	/**
	 * The merchant's business records survive, and the file cannot even reach
	 * them.
	 *
	 * The orders keep their tax identifier and the outbox keeps its rows: they
	 * are the merchant accounting record and the merchant delivery history, and
	 * removing an integration is not a request to alter either. The check is on
	 * the source rather than on behaviour because dropping a table succeeds
	 * quietly, and quietly is how it would ship.
	 *
	 * @return void
	 */
	public function test_uninstall_cannot_reach_orders_or_the_outbox(): void {
		$tokens = $this->code_tokens( $this->repository_path( 'uninstall.php' ) );

		$words = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$words[] = strtolower( $token[1] );
				continue;
			}

			$words[] = strtolower( $token );
		}

		foreach ( self::FORBIDDEN_CODE_TOKENS as $forbidden => $consequence ) {
			$this->assertNotContains(
				strtolower( $forbidden ),
				$words,
				"uninstall.php names `{$forbidden}` in code: {$consequence}."
			);
		}

		$code = implode( ' ', $words );

		foreach ( array( Constants::OUTBOX_TABLE, Constants::TAX_ID_META_KEY, Constants::TAX_ID_MIRROR_META_KEY, Constants::IDEMPOTENCY_KEY_META_KEY, Constants::INVOICE_ID_META_KEY ) as $merchant_datum ) {
			$this->assertStringNotContainsString(
				strtolower( $merchant_datum ),
				$code,
				"uninstall.php names `{$merchant_datum}` in code. The orders and the outbox belong to the merchant and "
				. 'outlive the plugin; only the connection is being removed.'
			);
		}
	}

	/**
	 * Options are deleted from the enumerated list and never by prefix.
	 *
	 * A prefix match would delete whatever anybody names that way in the
	 * future, including an option belonging to somebody else, and it would do
	 * it without a decision. It is the same anti-pattern the monorepo forbids
	 * for route allowlists, spelled for a database.
	 *
	 * @return void
	 */
	public function test_options_are_deleted_by_the_enumerated_list_and_never_by_prefix(): void {
		$tokens = $this->code_tokens( $this->repository_path( 'uninstall.php' ) );

		$names = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$names[] = $token[1];
			}
		}

		$code = implode( ' ', $names );

		$this->assertStringNotContainsString(
			'OPTION_PREFIX',
			$code,
			'uninstall.php uses the option prefix. Deleting by prefix removes whatever happens to carry it, which is a '
			. 'guess that runs on every uninstall instead of a decision somebody made.'
		);
		$this->assertStringNotContainsString(
			'LIKE',
			strtoupper( $code ),
			'uninstall.php builds a pattern match. Options are deleted one by one from Constants::ALL_OPTIONS.'
		);
		$this->assertStringContainsString(
			'ALL_OPTIONS',
			$code,
			'uninstall.php does not read Constants::ALL_OPTIONS, so whatever it deletes is a second list that will '
			. 'diverge from the first one.'
		);
	}

	/**
	 * Every option name the plugin writes in `src/` is in the deleted list.
	 *
	 * This is the direction that fails on somebody else's code, and it is meant
	 * to. A phase that adds an option - the outbox schema version is the one
	 * already known to be coming - and does not enumerate it in
	 * `Constants::ALL_OPTIONS` leaves that option in the merchant database
	 * forever. The remedy is one line in the frozen contract, and the failure
	 * message says so.
	 *
	 * @return void
	 */
	public function test_every_option_name_written_in_the_source_is_deleted(): void {
		$literals    = $this->prefixed_literals( $this->source_files() );
		$declared    = Constants::ALL_OPTIONS;
		$by_design   = $this->flatten( self::NON_OPTION_NAMES_BY_DESIGN );
		$undeclared  = array();

		foreach ( $literals as $name => $places ) {
			if ( in_array( $name, $declared, true ) || in_array( $name, $by_design, true ) ) {
				continue;
			}

			$undeclared[] = $name . ' (' . implode( ', ', $places ) . ')';
		}

		$this->assertSame(
			array(),
			$undeclared,
			"These names carry the plugin option prefix and are neither in Constants::ALL_OPTIONS nor declared as "
			. "something other than an option:\n  " . implode( "\n  ", $undeclared )
			. "\n\nAdd the option to Constants::ALL_OPTIONS so the uninstall deletes it, or, if it is not an option, "
			. 'add it to NON_OPTION_NAMES_BY_DESIGN with the reason as the key.'
		);

		$stale = array();
		foreach ( $by_design as $name ) {
			if ( ! isset( $literals[ $name ] ) ) {
				$stale[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			'These names are declared as non-options but no longer appear in src/: ' . implode( ', ', $stale )
			. '. Remove the entry, so the list keeps describing the code rather than its history.'
		);

		$never_written = array();
		foreach ( $declared as $option ) {
			if ( ! isset( $literals[ $option ] ) ) {
				$never_written[] = $option;
			}
		}

		$this->assertSame(
			array(),
			$never_written,
			'These options are deleted on uninstall but their names appear nowhere in src/: '
			. implode( ', ', $never_written ) . '. Either the option is gone and the list is stale, or the name in the '
			. 'list is not the name the code writes.'
		);
	}

	/**
	 * Every call that reads or writes an option in `src/` passes a name the
	 * uninstall deletes, and no such call is unreadable.
	 *
	 * The literal sweep above catches a name written as a literal. This one
	 * catches the call itself, which is what matters when a phase decides to
	 * build a name instead of writing it: such a call cannot be verified, so it
	 * is reported rather than passed over.
	 *
	 * @return void
	 */
	public function test_every_option_api_call_uses_a_deleted_option_name(): void {
		$calls        = $this->registered_option_names( $this->source_files() );
		$declared     = Constants::ALL_OPTIONS;
		$looked_at    = $this->flatten( self::UNRESOLVED_OPTION_CALLS_BY_DESIGN );
		$unaccounted  = array_values( array_diff( $calls['unresolved'], $looked_at ) );

		$this->assertSame(
			array(),
			$unaccounted,
			"These calls to the WordPress option API pass a name this check could not read:\n  "
			. implode( "\n  ", $unaccounted )
			. "\n\nPass Constants::OPTION_* or a string literal. If the name genuinely cannot be written there - a "
			. 'forwarder that takes the option as a parameter - read the callers, satisfy yourself that every name they '
			. 'pass is deleted on uninstall, and add the call to UNRESOLVED_OPTION_CALLS_BY_DESIGN with that reason as '
			. 'the key. An unverifiable name is not a verified one, and the decision belongs to a person.'
		);

		$stale_calls = array_values( array_diff( $looked_at, $calls['unresolved'] ) );

		$this->assertSame(
			array(),
			$stale_calls,
			'These calls are declared as looked at but no longer exist in src/: ' . implode( ', ', $stale_calls )
			. '. Remove the entry: an exclusion that outlives the code it excused starts excusing whatever moves into '
			. 'its place.'
		);

		$undeclared = array();
		foreach ( $calls['resolved'] as $name => $places ) {
			if ( in_array( $name, $declared, true ) ) {
				continue;
			}

			$undeclared[] = $name . ' (' . implode( ', ', $places ) . ')';
		}

		$this->assertSame(
			array(),
			$undeclared,
			"These options are read or written by the plugin and are not in Constants::ALL_OPTIONS:\n  "
			. implode( "\n  ", $undeclared )
			. "\n\nAdd each one to Constants::ALL_OPTIONS, as its own OPTION_* constant of the frozen contract: what "
			. 'the plugin creates, the uninstall deletes. It is deliberately not fixable from uninstall.php, because a '
			. 'second list of options read from somewhere else is the divergence the single list exists to prevent - '
			. 'and because uninstall.php runs with no autoloader, so reaching into src/ for a class constant is a fatal '
			. 'error away from aborting the deletion halfway through.'
		);
	}

	/**
	 * The scanners find what they are supposed to find.
	 *
	 * There is no call to the option API in `src/` yet, so the check above
	 * would pass over an empty set, and an empty set proves nothing. Two files
	 * are planted with the three shapes an option name can take - a literal, a
	 * constant of the frozen contract, a constant of the class itself - plus
	 * one that cannot be read at all, and the scanners are asked to produce
	 * exactly that.
	 *
	 * @return void
	 */
	public function test_the_scanners_find_planted_option_names(): void {
		$directory = sys_get_temp_dir() . '/factuarea-uninstall-scan-' . bin2hex( random_bytes( 6 ) );
		mkdir( $directory );

		$planted = $directory . '/Planted.php';
		file_put_contents(
			$planted,
			"<?php\nnamespace Factuarea\\WooCommerce\\Planted;\n\n"
			. "use Factuarea\\WooCommerce\\Contract\\Constants;\n\n"
			. "final class Planted {\n"
			. "\tpublic const OWN_OPTION = 'factuarea_wc_planted_own';\n\n"
			. "\tpublic function run( string \$computed ): void {\n"
			. "\t\tupdate_option( 'factuarea_wc_planted_literal', 1 );\n"
			. "\t\tget_option( Constants::OPTION_API_KEY, '' );\n"
			. "\t\tadd_option( self::OWN_OPTION, 1 );\n"
			. "\t\tupdate_option( \$computed, 1 );\n"
			. "\t\t// update_option( 'factuarea_wc_in_a_comment', 1 );\n"
			. "\t}\n"
			. "}\n"
		);

		/*
		 * The planted class is loaded before the scan, because a `self::`
		 * constant can only be resolved against a class that exists - which is
		 * the situation of every real file of `src/`, all of which the composer
		 * autoloader can reach. Loading it only declares the class; the method
		 * that calls the option API is never invoked.
		 */
		require_once $planted;

		$literals = $this->prefixed_literals( array( $planted ) );
		$this->assertSame(
			array( 'factuarea_wc_planted_literal', 'factuarea_wc_planted_own' ),
			array_keys( $literals ),
			'The literal scanner did not find exactly the planted names, or it read a name out of a comment.'
		);

		$calls = $this->registered_option_names( array( $planted ) );
		$this->assertSame(
			array( Constants::OPTION_API_KEY, 'factuarea_wc_planted_literal', 'factuarea_wc_planted_own' ),
			array_keys( $calls['resolved'] ),
			'The call scanner did not resolve the three readable shapes of an option name.'
		);
		$this->assertCount(
			1,
			$calls['unresolved'],
			'The call scanner did not report the call whose option name cannot be read statically.'
		);

		unlink( $planted );
		rmdir( $directory );
	}

	/**
	 * Every option constant of the frozen contract is in the deleted list, and
	 * every entry of the deleted list is one of those constants.
	 *
	 * The first direction catches the ordinary mistake: a constant added and
	 * the list forgotten. The second catches the one nobody looks for: a name
	 * left in the list after the option it named was removed, which makes every
	 * uninstall delete something that no longer exists and hides the fact that
	 * the list stopped describing the plugin.
	 *
	 * @return void
	 */
	public function test_the_deleted_list_and_the_option_constants_are_the_same_set(): void {
		$constants = ( new ReflectionClass( Constants::class ) )->getConstants();

		$from_constants = array();
		foreach ( $constants as $name => $value ) {
			if ( ! str_starts_with( $name, 'OPTION_' ) || 'OPTION_PREFIX' === $name || ! is_string( $value ) ) {
				continue;
			}

			$from_constants[] = $value;
		}

		sort( $from_constants );

		$declared = Constants::ALL_OPTIONS;
		sort( $declared );

		$this->assertSame(
			$from_constants,
			$declared,
			'Constants::ALL_OPTIONS and the OPTION_* constants of the frozen contract are not the same set. An option '
			. 'the plugin declares and the uninstall does not delete stays in the merchant database for good.'
		);

		$this->assertNotEmpty( $declared, 'The deleted option list is empty, so the uninstall proves nothing.' );

		foreach ( Constants::CREDENTIAL_OPTIONS as $credential_option ) {
			$this->assertContains(
				$credential_option,
				$declared,
				"The credential option {$credential_option} is not in Constants::ALL_OPTIONS, so an uninstall would "
				. 'leave it behind.'
			);
		}
	}

	/**
	 * On a multisite network every site loses its options, not just the one the
	 * uninstall happened to run on.
	 *
	 * These are per-site options: each store in a network configures its own
	 * base address, its own store identifier and its own credentials. WordPress
	 * runs the uninstall once, so without the walk the API key and the signing
	 * secret of every other store stay in the database - the exact outcome the
	 * file exists to prevent, only harder to notice.
	 *
	 * @return void
	 */
	public function test_uninstall_clears_every_site_of_a_multisite_network(): void {
		$report = $this->run_uninstall( true, true );

		$this->assertSame(
			array( 1, 4, 9 ),
			$report['switched'],
			'The uninstall routine did not visit every site of the network.'
		);
		$this->assertSame(
			count( $report['switched'] ),
			$report['restored'],
			'The uninstall routine switched sites more often than it restored, which leaves the process pointing at a '
			. 'site nobody asked for.'
		);

		$per_site = array();
		foreach ( $report['deleted'] as $entry ) {
			list( $blog, $option ) = explode( '|', $entry, 2 );
			$per_site[ (int) $blog ][] = $option;
		}

		$expected = Constants::ALL_OPTIONS;
		sort( $expected );

		$this->assertSame( array( 1, 4, 9 ), array_keys( $per_site ), 'Options were deleted on the wrong set of sites.' );

		foreach ( $per_site as $blog => $options ) {
			sort( $options );
			$this->assertSame(
				$expected,
				$options,
				"Site {$blog} did not lose the whole option list."
			);
		}

		$this->assertSame(
			array(
				'fields' => 'ids',
				'number' => 0,
			),
			$report['get_sites_args'],
			'The site list is fetched with the default page size, so a network larger than that page keeps the '
			. 'credentials of every site past it.'
		);
	}
}
