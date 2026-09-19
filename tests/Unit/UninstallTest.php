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
 * THE DATABASE IS REACHED ON PURPOSE NOW, SO THE CHECKS ARE ABOUT ITS SHAPE.
 *
 * The file used to be forbidden from naming `$wpdb` at all, which was a cheap
 * and complete guarantee while it had no table of its own to remove. The
 * inbound delivery ledger changed that: it is the plugin's deduplication
 * bookkeeping, it is dropped, and dropping a table is the one thing no
 * WordPress API does for you. So the blanket ban is gone and three narrower
 * checks stand in its place - the file may name no table but its own, it may
 * send no statement but the drop, and that statement must be written whole
 * instead of assembled - each of which says what the ban used to say about the
 * only case that mattered, and says it where a reader can see why.
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
		'Settings API identifiers of the settings screen: the group the options are registered against, its TWO sections - what this shop sends, and what it agrees to be told - and the two admin-post actions, the one that generates the signing secret and the one that runs the connection test. None is stored; a section identifier is what `add_settings_section` groups fields under and never reaches the options table. The options they organise are Constants::OPTION_* and are deleted' => array(
			'factuarea_wc_connection',
			'factuarea_wc_generate_signing_secret',
			'factuarea_wc_receivers',
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
		'Option of WOOCOMMERCE, not of this plugin, read through a private constant of the class. `defined()` cannot see a private class constant from outside, which is why the name arrives here unreadable rather than resolved. Looked at on 2026-09-18: `StockWriter::NO_STOCK_THRESHOLD_OPTION` is `woocommerce_notify_no_stock_amount`, the threshold WooCommerce itself stores and the writer only READS, to decide whether an assigned quantity leaves an item out of stock. This plugin never writes it. IT MUST NEVER BE ADDED TO Constants::ALL_OPTIONS: deleting it on uninstall would remove a WooCommerce setting the merchant configured and this plugin never created' => array(
			'src/Inbound/StockWriter.php [get_option(self::NO_STOCK_THRESHOLD_OPTION)]',
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
		'OUTBOX_TABLE'       => 'the outbox is the merchant record of which sales reached Factuarea, and naming its table here is one keyword away from dropping it. The inbound delivery table is the only table this file may touch',
		'delete_post_meta'   => 'the tax identifier and the fulfilment data on an order are the merchant accounting record, not the plugin state',
		'delete_metadata'    => 'same reason as delete_post_meta, one layer down',
		'delete_user_meta'   => 'the plugin stores nothing on a user, so a call here could only be destroying somebody else data',
		'delete_site_option' => 'the plugin registers no network option, so this could only delete something another plugin created',
		'get_option'         => 'reading an option value on the way to deleting it puts a credential in a variable for no reason',
		'get_site_option'    => 'same reason as get_option',
		'error_log'          => 'this file handles a signing secret and an API key and writes no log line at all',
	);

	/**
	 * SQL verbs that change or read data, and what each one would mean in a
	 * file whose entire database business is dropping ONE table it created.
	 *
	 * `DROP` is absent on purpose: it is the one statement this file is allowed
	 * to send, and `test_the_only_statement_of_the_uninstall_is_the_inbound_drop`
	 * reads that statement whole instead of forbidding the word.
	 *
	 * @var array<string, string>
	 */
	private const FORBIDDEN_SQL_VERBS = array(
		'DELETE'   => 'rows are deleted by the WordPress option API here, never by a statement of this file',
		'TRUNCATE' => 'emptying a table during an uninstall destroys merchant data without even leaving the table gone',
		'ALTER'    => 'an uninstall has nothing to migrate: the plugin is being removed, not upgraded',
		'UPDATE'   => 'nothing is edited on the way out; what leaves, leaves whole',
		'INSERT'   => 'the last thing this plugin does is not writing a row',
		'SELECT'   => 'reading is how an option value ends up in a variable, and two of them are credentials',
		'SHOW'     => 'asking whether the table is there is what IF EXISTS is for, and it costs one round trip instead of two',
	);

	/**
	 * The child process reports what the WordPress doubles were asked to do.
	 *
	 * @param bool   $uninstall_context Whether WP_UNINSTALL_PLUGIN is defined.
	 * @param bool   $multisite         Whether is_multisite() answers true.
	 * @param string $prefix            Table prefix of the installation, which is
	 *                                  deliberately NOT `wp_` in most cases here.
	 *
	 * @return array{deleted: list<string>, queries: list<string>, forbidden: list<string>, restored: int, switched: list<int>, get_sites_args: array<string, mixed>}
	 */
	private function run_uninstall( bool $uninstall_context, bool $multisite, string $prefix = 'wp_' ): array {
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
				'FACTUAREA_HARNESS_PREFIX'    => $prefix,
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
	'queries'        => array(),
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

	/*
	 * What WordPress actually does to $wpdb, because it is the half of
	 * switch_to_blog() this file depends on: the table prefix becomes that
	 * site's prefix - the base one for the first site of the network, the base
	 * one with the site id in it for every other - and it is why a table name
	 * composed before the walk would name the wrong table on every site but one.
	 */
	$GLOBALS['wpdb']->prefix = 1 === (int) $blog_id
		? $GLOBALS['wpdb']->base_prefix
		: $GLOBALS['wpdb']->base_prefix . (int) $blog_id . '_';

	return true;
}

function restore_current_blog() {
	++$GLOBALS['factuarea_report']['restored'];
	$GLOBALS['factuarea_report']['blog'] = 0;
	$GLOBALS['wpdb']->prefix             = $GLOBALS['wpdb']->base_prefix;
	return true;
}

class Factuarea_Harness_Wpdb {
	public $prefix;

	public $base_prefix;

	public function __construct( string $prefix ) {
		$this->prefix      = $prefix;
		$this->base_prefix = $prefix;
	}

	public function query( $sql ) {
		$GLOBALS['factuarea_report']['queries'][] = $GLOBALS['factuarea_report']['blog'] . '|' . $sql;
		return 0;
	}

	/*
	 * A stand-in for the placeholder expansion, not a stand-in for the escaping.
	 * `%i` is the one that matters here: WordPress quotes an identifier with
	 * backticks and doubles any backtick inside it, and reproducing that is what
	 * lets the test read the statement exactly as the database would receive it.
	 */
	public function prepare( $sql, ...$arguments ) {
		$values = $arguments;

		return preg_replace_callback(
			'/%[idfs]/',
			static function ( array $match ) use ( &$values ) {
				$value = array_shift( $values );

				if ( '%i' === $match[0] ) {
					return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}

				return "'" . addslashes( (string) $value ) . "'";
			},
			(string) $sql
		);
	}

	public function get_results( $sql, $output = null ) {
		factuarea_harness_forbidden( 'wpdb::get_results' );
		return array();
	}

	public function get_col( $sql ) {
		factuarea_harness_forbidden( 'wpdb::get_col' );
		return array();
	}

	public function get_var( $sql ) {
		factuarea_harness_forbidden( 'wpdb::get_var' );
		return null;
	}

	public function get_row( $sql, $output = null ) {
		factuarea_harness_forbidden( 'wpdb::get_row' );
		return null;
	}

	public function insert( $table, $data, $format = null ) {
		factuarea_harness_forbidden( 'wpdb::insert' );
		return false;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		factuarea_harness_forbidden( 'wpdb::update' );
		return false;
	}

	public function delete( $table, $where, $where_format = null ) {
		factuarea_harness_forbidden( 'wpdb::delete' );
		return false;
	}

	public function esc_like( $text ) {
		factuarea_harness_forbidden( 'wpdb::esc_like' );
		return $text;
	}
}

$factuarea_harness_prefix = (string) getenv( 'FACTUAREA_HARNESS_PREFIX' );

$GLOBALS['wpdb'] = new Factuarea_Harness_Wpdb( '' === $factuarea_harness_prefix ? 'wp_' : $factuarea_harness_prefix );

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
	 * The entries of a report list, grouped by the site they happened on.
	 *
	 * Both lists the harness records - the options it deleted and the
	 * statements it sent - carry the site in front of the value, because on a
	 * network the question is never "did it happen" but "did it happen on every
	 * site".
	 *
	 * @param list<string> $entries Entries shaped `site|value`.
	 *
	 * @return array<int, list<string>>
	 */
	private function by_site( array $entries ): array {
		$grouped = array();

		foreach ( $entries as $entry ) {
			list( $site, $value ) = explode( '|', $entry, 2 );

			$grouped[ (int) $site ][] = $value;
		}

		return $grouped;
	}

	/**
	 * Every statement the uninstall sent to the database, in order, without the
	 * site they were sent on.
	 *
	 * @param array{queries: list<string>} $report Harness report.
	 *
	 * @return list<string>
	 */
	private function statements( array $report ): array {
		return array_map(
			static fn( string $entry ): string => explode( '|', $entry, 2 )[1],
			$report['queries']
		);
	}

	/**
	 * Every option name the uninstall deleted, without the site it happened on.
	 *
	 * @param array{deleted: list<string>} $report Harness report.
	 *
	 * @return list<string>
	 */
	private function deleted_option_names( array $report ): array {
		return array_map(
			static fn( string $entry ): string => explode( '|', $entry, 2 )[1],
			$report['deleted']
		);
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
			$report['queries'],
			'The uninstall routine dropped the inbound delivery table although WP_UNINSTALL_PLUGIN was not defined, so '
			. 'a stray include of this file would make a working installation forget every delivery it has applied.'
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
	 * Every meta key the frozen contract declares, read off the contract itself
	 * rather than copied here.
	 *
	 * Copying the list would make this check describe the plugin as it was the
	 * day somebody typed it: the inbound half added nine keys to the contract,
	 * and a copied list would have kept asserting about the four it knew. The
	 * reflection asks the contract what it currently declares, so a key added
	 * tomorrow is covered the moment it is declared.
	 *
	 * @return array<string, string> Constant name to the meta key it holds.
	 */
	private function order_meta_keys_of_the_contract(): array {
		$keys = array();

		foreach ( ( new ReflectionClass( Constants::class ) )->getConstants() as $name => $value ) {
			if ( ! str_ends_with( $name, '_META_KEY' ) || ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$keys[ $name ] = $value;
		}

		ksort( $keys );

		return $keys;
	}

	/**
	 * The merchant's business records survive, and the file cannot even reach
	 * them.
	 *
	 * The orders keep everything the plugin ever wrote on them - the tax
	 * identifier, the correlated invoice identifier, and everything the inbound
	 * half added: the fulfilment status, its tracking data, the mark of an
	 * inbound refund, the corrective reference and the refund scope - and the
	 * outbox keeps its rows. They are the merchant accounting record and the
	 * merchant delivery history, and removing an integration is not a request to
	 * alter either. The one table that does go is the inbound delivery ledger,
	 * which holds no merchant data at all and has its own tests below.
	 *
	 * The check is on the source rather than on behaviour because deleting an
	 * order meta or dropping a table succeeds quietly, and quietly is how it
	 * would ship.
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

		$code       = implode( ' ', $words );
		$order_meta = $this->order_meta_keys_of_the_contract();

		/*
		 * The sweep below proves nothing against an empty set, and an empty set
		 * is exactly what a renamed constant would produce. These four are named
		 * so that the reflection has to keep finding the contract it is reading:
		 * one from the outgoing half, three from the inbound one.
		 */
		foreach ( array( 'TAX_ID_META_KEY', 'FULFILMENT_STATUS_META_KEY', 'TRACKING_NUMBER_META_KEY', 'INBOUND_REFUND_META_KEY' ) as $expected_constant ) {
			$this->assertArrayHasKey(
				$expected_constant,
				$order_meta,
				"Constants::{$expected_constant} is no longer a meta key of the frozen contract, so this check stopped "
				. 'covering it. Either the constant was renamed - update the four named here - or the plugin stopped '
				. 'writing that datum on the order.'
			);
		}

		$untouchable = array_merge( array( 'OUTBOX_TABLE' => Constants::OUTBOX_TABLE ), $order_meta );

		foreach ( $untouchable as $constant_name => $merchant_datum ) {
			$this->assertStringNotContainsString(
				strtolower( $merchant_datum ),
				$code,
				"uninstall.php names `{$merchant_datum}` (Constants::{$constant_name}) in code. The orders and the outbox "
				. 'belong to the merchant and outlive the plugin; only the connection, and the plugin bookkeeping that '
				. 'serves it, are being removed.'
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
	 * The options the inbound half brought with it are deleted like every other
	 * one, by being in the list rather than by anything written here.
	 *
	 * The three of them are the two switches that govern the receivers and the
	 * schema marker of the inbound delivery table. A switch left behind is the
	 * one that decides whether a receiver accepts deliveries, and it would
	 * survive a reinstall carrying the answer somebody gave months earlier; the
	 * schema marker left behind would claim a table that this very uninstall
	 * drops.
	 *
	 * @return void
	 */
	public function test_the_options_the_inbound_half_added_are_deleted_like_the_rest(): void {
		$inbound_options = array(
			'OPTION_ORDER_STATUS_RECEIVER_ENABLED' => Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED,
			'OPTION_CATALOG_PUSH_RECEIVER_ENABLED' => Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED,
			'OPTION_INBOUND_SCHEMA_VERSION'        => Constants::OPTION_INBOUND_SCHEMA_VERSION,
		);

		foreach ( $inbound_options as $constant_name => $option ) {
			$this->assertContains(
				$option,
				Constants::ALL_OPTIONS,
				"Constants::{$constant_name} is not in Constants::ALL_OPTIONS, so the uninstall walk never reaches it "
				. 'and the option stays in the merchant database for good.'
			);
		}

		$deleted = $this->deleted_option_names( $this->run_uninstall( true, false ) );

		foreach ( $inbound_options as $constant_name => $option ) {
			$this->assertContains(
				$option,
				$deleted,
				"The uninstall did not delete {$option} (Constants::{$constant_name})."
			);
		}
	}

	/**
	 * The inbound delivery table is dropped, and its name is built with the
	 * prefix of the installation the uninstall is running on.
	 *
	 * The prefix is the whole point of the test and the reason it is not `wp_`.
	 * Most installations that were ever migrated between hosts chose another
	 * one, and a hard-coded `wp_` would drop nothing on them - or, worse, drop
	 * the table of a DIFFERENT installation sharing the database, which is how
	 * several shops are hosted on one plan.
	 *
	 * @return void
	 */
	public function test_uninstall_drops_the_inbound_delivery_table_with_the_prefix_of_the_installation(): void {
		$report     = $this->run_uninstall( true, false, 'shop7_' );
		$statements = $this->statements( $report );

		$this->assertCount(
			1,
			$statements,
			'The uninstall sent ' . count( $statements ) . ' statements to the database. It has exactly one piece of '
			. "database business - dropping its own delivery ledger - and the statements were:\n  "
			. implode( "\n  ", $statements )
		);

		$this->assertMatchesRegularExpression(
			'/^DROP TABLE IF EXISTS `?' . preg_quote( 'shop7_' . Constants::INBOUND_TABLE, '/' ) . '`?$/',
			$statements[0],
			'The uninstall did not drop the inbound delivery table of this installation. `IF EXISTS` is what makes it '
			. 'safe on a site that never activated the inbound half, and the prefix has to be the one of the '
			. "installation rather than a hard-coded `wp_`. The statement was:\n  " . $statements[0]
		);

		$this->assertStringNotContainsString(
			'wp_' . Constants::INBOUND_TABLE,
			$statements[0],
			'The uninstall built the table name with a hard-coded `wp_` prefix, so on this installation it dropped a '
			. 'table belonging to somebody else and left its own behind.'
		);
	}

	/**
	 * Whatever the uninstall sends to the database is the drop of its own
	 * ledger, and nothing else - on a single site and on a network alike.
	 *
	 * The test above reads one statement built from one literal. This one reads
	 * EVERY statement that actually left, which is the only way to catch a
	 * statement assembled out of pieces: a literal check cannot see a name that
	 * was concatenated, and the outbox table is one concatenation away from any
	 * file that already holds `$wpdb`.
	 *
	 * @return void
	 */
	public function test_the_uninstall_sends_nothing_but_the_drop_of_its_own_ledger(): void {
		$statements = array_merge(
			$this->statements( $this->run_uninstall( true, false, 'shop7_' ) ),
			$this->statements( $this->run_uninstall( true, true, 'net_' ) )
		);

		$this->assertNotSame(
			array(),
			$statements,
			'The uninstall sent no statement at all, so this check proves nothing: the inbound delivery table would '
			. 'have survived the deletion too.'
		);

		foreach ( $statements as $statement ) {
			$this->assertStringContainsString(
				Constants::INBOUND_TABLE,
				$statement,
				"The uninstall sent a statement that is not about its own delivery ledger:\n  {$statement}"
			);

			$this->assertStringNotContainsString(
				Constants::OUTBOX_TABLE,
				$statement,
				"The uninstall named the outbox table in a statement:\n  {$statement}\n\nThe outbox holds which sales "
				. 'reached Factuarea and which ones never did. It is the merchant record and it outlives the plugin; '
				. 'the inbound ledger is deduplication bookkeeping and goes with it.'
			);

			foreach ( self::FORBIDDEN_SQL_VERBS as $verb => $consequence ) {
				$this->assertDoesNotMatchRegularExpression(
					'/\b' . $verb . '\b/i',
					$statement,
					"The uninstall sent a statement containing `{$verb}`: {$consequence}.\n  {$statement}"
				);
			}
		}
	}

	/**
	 * Read in the source, the file contains exactly ONE statement, written whole
	 * as a literal.
	 *
	 * A statement assembled out of pieces cannot be read by anybody auditing
	 * this file, and it is the shape every accidental deletion takes: a name
	 * concatenated from a variable that turns out to hold something else. The
	 * placeholder is the only thing that varies, and WordPress is what fills it.
	 *
	 * @return void
	 */
	public function test_the_only_statement_written_in_the_uninstall_is_the_inbound_drop(): void {
		$literals = array();

		foreach ( $this->code_tokens( $this->repository_path( 'uninstall.php' ) ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literals[] = substr( $token[1], 1, -1 );
			}
		}

		$statements = array();
		foreach ( $literals as $literal ) {
			if ( 1 === preg_match( '/\b(DROP|DELETE|TRUNCATE|ALTER|UPDATE|INSERT|SELECT|SHOW|CREATE|REPLACE|GRANT)\b/i', $literal ) ) {
				$statements[] = $literal;
			}
		}

		$this->assertSame(
			array( 'DROP TABLE IF EXISTS %i' ),
			$statements,
			"uninstall.php does not contain exactly one statement, written whole. What it contains is:\n  "
			. implode( "\n  ", $statements )
			. "\n\n`%i` is the identifier placeholder, so the table name is quoted by WordPress instead of being "
			. 'interpolated by hand, and `IF EXISTS` is what makes the drop safe on an installation whose table was '
			. 'never created.'
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
	 * The inbound delivery table is per site for the same reason, and it is the
	 * harder half to get right: each site has its own table because each site
	 * has its own prefix, and `$wpdb->prefix` only becomes that site's prefix
	 * after `switch_to_blog`. A name composed once, before the walk, would drop
	 * the first site's table three times and leave the other two standing.
	 *
	 * @return void
	 */
	public function test_uninstall_clears_every_site_of_a_multisite_network(): void {
		$report = $this->run_uninstall( true, true, 'net_' );

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

		$per_site = $this->by_site( $report['deleted'] );

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

		$per_site_statements = $this->by_site( $report['queries'] );

		$this->assertSame(
			array( 1, 4, 9 ),
			array_keys( $per_site_statements ),
			'The inbound delivery table was dropped on the wrong set of sites.'
		);

		/*
		 * What WordPress names a site's tables: the base prefix for the first
		 * site of the network, the base prefix with the site id in it for every
		 * other one.
		 */
		$expected_tables = array(
			1 => 'net_' . Constants::INBOUND_TABLE,
			4 => 'net_4_' . Constants::INBOUND_TABLE,
			9 => 'net_9_' . Constants::INBOUND_TABLE,
		);

		foreach ( $per_site_statements as $site => $statements ) {
			$this->assertCount( 1, $statements, "Site {$site} received more than the one drop of its own ledger." );

			$this->assertMatchesRegularExpression(
				'/^DROP TABLE IF EXISTS `?' . preg_quote( $expected_tables[ $site ], '/' ) . '`?$/',
				$statements[0],
				"Site {$site} did not lose its own inbound delivery table. Its table is "
				. "`{$expected_tables[ $site ]}`, and what was dropped was:\n  {$statements[0]}"
			);
		}
	}
}
