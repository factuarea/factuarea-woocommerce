<?php
/**
 * Unit test of the declaration of compatibility with the WooCommerce order
 * tables.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Compatibility\HposCompatibility;
use PHPUnit\Framework\TestCase;

/*
 * The main plugin file publishes its own path under this constant, and the
 * class under test reads it from there. A constant cannot be undefined once it
 * is defined, so it is defined here once, at file scope, with the value a real
 * installation would carry: defining it inside a test would decide, by
 * execution order, whether any later test could still see it missing.
 */
if ( ! defined( 'FACTUAREA_WC_PLUGIN_FILE' ) ) {
	define( 'FACTUAREA_WC_PLUGIN_FILE', '/var/www/html/wp-content/plugins/factuarea-for-woocommerce/factuarea-for-woocommerce.php' );
}

/**
 * What this test holds, and why each half of it exists.
 *
 * The first half exercises the class: it replaces `add_action` with a double,
 * keeps whatever callback was registered and invokes THAT, so what is checked
 * is the thing WordPress would have called and not a method the test picked. It
 * asserts the three facts the declaration is made of - the boot point, the
 * affirmative sign and the path of the main plugin file.
 *
 * The second half reads the source of the class and fails naming any deviation
 * it finds, because the two ways of getting this wrong do not fail at runtime.
 * A declaration made on a later boot point is accepted and ignored, and a
 * negative declaration is accepted and obeyed: neither of them throws, neither
 * of them warns, and the first half would keep passing while the shop lost its
 * order tables. The sweep reads TOKENS and not text, with comments removed,
 * because this file's own documentation has to be free to spell out `false` and
 * to name the wrong boot points - which is exactly what it does - without the
 * check mistaking an explanation for the code it explains.
 */
final class HposCompatibilityTest extends TestCase {

	/**
	 * Path of the file the sweep reads, relative to the repository root.
	 */
	private const SOURCE_RELATIVE_PATH = 'src/Compatibility/HposCompatibility.php';

	/**
	 * The boot point the declaration has to be registered on.
	 */
	private const BOOT_HOOK = 'before_woocommerce_init';

	/**
	 * The identifier of the WooCommerce feature being declared.
	 */
	private const FEATURE_ID = 'custom_order_tables';

	/**
	 * What the `add_action` double received, in the order it received it.
	 *
	 * It is a property and not a local of the helper that fills it because a
	 * PHP array handed back from a method is a COPY: the double would keep
	 * appending to the original while the test read the snapshot, and every
	 * assertion would see an empty list.
	 *
	 * @var array<int, array{hook: string, callback: callable}>
	 */
	private array $registered = array();

	/**
	 * Installs the function doubles and empties the record of declarations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		FeaturesUtil::reset();
		$this->registered = array();
	}

	/**
	 * Removes the function doubles and verifies the expectations set on them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The registration happens on the boot point that runs before WooCommerce
	 * initialises, which is the last one where the declaration is still read.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_declaration_on_the_boot_point_before_woocommerce_initialises(): void {
		$this->capture_registrations();

		HposCompatibility::register();

		$this->assertCount(
			1,
			$this->registered,
			'register() has to register exactly one action: a second registration would declare the same compatibility twice.'
		);
		$this->assertSame(
			self::BOOT_HOOK,
			$this->registered[0]['hook'],
			sprintf(
				'The declaration was registered on "%s". It has to be registered on "%s": WooCommerce reads the register of declarations while it initialises, so a later boot point writes into a register nobody reads again, without an error and without a warning.',
				$this->registered[0]['hook'],
				self::BOOT_HOOK
			)
		);
		$this->assertIsCallable( $this->registered[0]['callback'] );
	}

	/**
	 * The callback that was registered declares the compatibility with the
	 * affirmative value and against the main plugin file.
	 *
	 * @return void
	 */
	public function test_the_registered_callback_declares_compatibility_affirmatively_for_the_main_plugin_file(): void {
		$this->capture_registrations();

		HposCompatibility::register();

		$this->assertCount( 1, $this->registered );
		( $this->registered[0]['callback'] )();

		$this->assertSame(
			array(
				array(
					'feature_id'             => self::FEATURE_ID,
					'plugin_file'            => FACTUAREA_WC_PLUGIN_FILE,
					'positive_compatibility' => true,
				),
			),
			FeaturesUtil::declarations(),
			'The registered callback has to declare compatibility with the WooCommerce order tables, affirmatively, for the path of the main plugin file.'
		);
	}

	/**
	 * One boot produces exactly one declaration, through the WooCommerce
	 * utility that owns the register.
	 *
	 * Declaring twice is not harmless noise: WooCommerce keeps the last
	 * declaration it received for a plugin, so a second call is a second chance
	 * to say the opposite of the first, and the one that wins is decided by
	 * whichever ran last.
	 *
	 * @return void
	 */
	public function test_the_declaration_is_made_through_the_woocommerce_utility_and_nowhere_else(): void {
		$this->capture_registrations();

		HposCompatibility::register();

		$this->assertCount( 1, $this->registered );
		( $this->registered[0]['callback'] )();

		$this->assertCount(
			1,
			FeaturesUtil::declarations(),
			'The declaration has to go through the WooCommerce utility exactly once per boot: WooCommerce owns the register, and a second route into it is a second thing to keep in sync.'
		);
	}

	/**
	 * The name of the constant the main plugin file has to publish its own path
	 * under is part of the contract with the wiring phase, so it is asserted
	 * rather than left to be discovered when the two halves are put together.
	 *
	 * @return void
	 */
	public function test_the_main_plugin_file_publishes_its_path_under_the_agreed_constant(): void {
		$this->assertSame(
			'FACTUAREA_WC_PLUGIN_FILE',
			HposCompatibility::PLUGIN_FILE_CONSTANT,
			'The main plugin file defines this constant from its own __FILE__ right before calling register(). Renaming it here without renaming it there leaves the plugin declaring nothing.'
		);
	}

	/**
	 * Sweep of the source: the sign of the declaration.
	 *
	 * @return void
	 */
	public function test_the_source_never_declares_the_compatibility_negatively(): void {
		$tokens = $this->significant_tokens_of_the_class_under_test();

		$declarations = $this->arguments_of_calls_to( $tokens, 'FeaturesUtil', 'declare_compatibility' );

		$this->assertCount(
			1,
			$declarations,
			sprintf(
				'%s has to contain exactly one call to FeaturesUtil::declare_compatibility(), and it contains %d.',
				self::SOURCE_RELATIVE_PATH,
				count( $declarations )
			)
		);
		$this->assertCount(
			3,
			$declarations[0],
			'The call has to pass the three arguments explicitly. Leaving the third one out means "true" today, which is right by accident and stops being readable the moment somebody wonders what it does.'
		);
		$this->assertSame(
			self::FEATURE_ID,
			$this->literal_or_expression( $declarations[0][0] ),
			'The feature being declared has to be the WooCommerce order tables, written as a literal: a name resolved somewhere else is a name this sweep cannot read.'
		);

		$sign = $this->argument_source( $declarations[0][2] );

		$this->assertSame(
			'true',
			strtolower( $sign ),
			sprintf(
				'%s declares the compatibility with "%s". It has to declare it with "true". A negative declaration is not this plugin opting out of anything: it tells WooCommerce the merchant cannot keep orders in its own tables while this plugin is active, so one wrong boolean here degrades the order storage of a whole shop that has nothing to do with us.',
				self::SOURCE_RELATIVE_PATH,
				$sign
			)
		);
	}

	/**
	 * Sweep of the source: the boot point of the registration.
	 *
	 * @return void
	 */
	public function test_the_source_registers_the_declaration_on_no_other_boot_point(): void {
		$tokens = $this->significant_tokens_of_the_class_under_test();

		$hooks = $this->hooks_registered_in( $tokens );

		$this->assertSame(
			array( self::BOOT_HOOK ),
			$hooks,
			sprintf(
				'%s registers on %s. It has to register on "%s", written as a literal, and on nothing else: a declaration made after WooCommerce has initialised is added to a register that has already been read, so it has no effect at all, and it fails silently - the call returns the same as one made in time.',
				self::SOURCE_RELATIVE_PATH,
				array() === $hooks ? 'no boot point at all' : '[ ' . implode( ', ', $hooks ) . ' ]',
				self::BOOT_HOOK
			)
		);
	}

	/**
	 * Replaces `add_action` with a double that keeps what was registered in
	 * self::$registered.
	 *
	 * @return void
	 */
	private function capture_registrations(): void {
		Functions\expect( 'add_action' )
			->andReturnUsing(
				function ( string $hook, $callback ): bool {
					$this->registered[] = array(
						'hook'     => $hook,
						'callback' => $callback,
					);

					return true;
				}
			);
	}

	/**
	 * Reads the class under test and returns its tokens with the comments and
	 * the whitespace removed.
	 *
	 * @return array<int, array{0: int|string, 1: string}>
	 */
	private function significant_tokens_of_the_class_under_test(): array {
		$path = dirname( __DIR__, 2 ) . '/' . self::SOURCE_RELATIVE_PATH;

		$this->assertFileExists( $path );

		$source = file_get_contents( $path );

		$this->assertIsString( $source );

		$significant = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$significant[] = array( $token[0], $token[1] );
				continue;
			}

			$significant[] = array( $token, $token );
		}

		return $significant;
	}

	/**
	 * Every call to a static method of a class whose name ends in the given
	 * suffix, as a list of argument token lists.
	 *
	 * The class is matched by suffix so that an imported short name and a fully
	 * qualified one are both found: the two spellings compile to different
	 * tokens and mean the same call.
	 *
	 * @param array<int, array{0: int|string, 1: string}> $tokens      Significant tokens.
	 * @param string                                      $class_suffix End of the class name.
	 * @param string                                      $method       Method name.
	 * @return array<int, array<int, array<int, array{0: int|string, 1: string}>>>
	 */
	private function arguments_of_calls_to( array $tokens, string $class_suffix, string $method ): array {
		$calls = array();
		$count = count( $tokens );

		for ( $index = 2; $index < $count - 1; $index++ ) {
			if ( T_STRING !== $tokens[ $index ][0] || $method !== $tokens[ $index ][1] ) {
				continue;
			}

			if ( '(' !== $tokens[ $index + 1 ][1] || T_DOUBLE_COLON !== $tokens[ $index - 1 ][0] ) {
				continue;
			}

			if ( ! str_ends_with( $tokens[ $index - 2 ][1], $class_suffix ) ) {
				continue;
			}

			$calls[] = $this->arguments_from( $tokens, $index + 1 );
		}

		return $calls;
	}

	/**
	 * The first argument of every hook registration in the file, as source
	 * text.
	 *
	 * Both `add_action` and `add_filter` are looked for: the two register on the
	 * same list of hooks, and registering the declaration with the second one
	 * would be the same deviation wearing another name.
	 *
	 * @param array<int, array{0: int|string, 1: string}> $tokens Significant tokens.
	 * @return array<int, string>
	 */
	private function hooks_registered_in( array $tokens ): array {
		$hooks = array();
		$count = count( $tokens );

		for ( $index = 0; $index < $count - 1; $index++ ) {
			if ( T_STRING !== $tokens[ $index ][0] ) {
				continue;
			}

			if ( 'add_action' !== $tokens[ $index ][1] && 'add_filter' !== $tokens[ $index ][1] ) {
				continue;
			}

			if ( '(' !== $tokens[ $index + 1 ][1] ) {
				continue;
			}

			if ( $index > 0 && in_array( $tokens[ $index - 1 ][0], array( T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION ), true ) ) {
				continue;
			}

			$arguments = $this->arguments_from( $tokens, $index + 1 );

			$hooks[] = array() === $arguments ? '<no argument>' : $this->literal_or_expression( $arguments[0] );
		}

		return $hooks;
	}

	/**
	 * Splits the argument list that opens at the given parenthesis into one
	 * token list per argument.
	 *
	 * @param array<int, array{0: int|string, 1: string}> $tokens       Significant tokens.
	 * @param int                                         $paren_index  Index of the opening parenthesis.
	 * @return array<int, array<int, array{0: int|string, 1: string}>>
	 */
	private function arguments_from( array $tokens, int $paren_index ): array {
		$arguments = array();
		$current   = array();
		$depth     = 0;
		$count     = count( $tokens );

		for ( $index = $paren_index; $index < $count; $index++ ) {
			$text = $tokens[ $index ][1];

			if ( '(' === $text || '[' === $text ) {
				++$depth;

				if ( 1 === $depth && '(' === $text ) {
					continue;
				}
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					if ( array() !== $current ) {
						$arguments[] = $current;
					}

					return $arguments;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				$arguments[] = $current;
				$current     = array();
				continue;
			}

			$current[] = $tokens[ $index ];
		}

		return $arguments;
	}

	/**
	 * The source text of one argument, rebuilt from its tokens.
	 *
	 * @param array<int, array{0: int|string, 1: string}> $argument Tokens of the argument.
	 * @return string
	 */
	private function argument_source( array $argument ): string {
		return implode( '', array_column( $argument, 1 ) );
	}

	/**
	 * The value of one argument when it is a plain string literal, and an
	 * unmistakable description of it when it is anything else.
	 *
	 * The two cases are kept apart on purpose. A hook name or a feature
	 * identifier reached through a constant, a variable or a function call is
	 * not readable from here, and reporting it as if it were its value would
	 * turn this sweep into a check that believes whatever it is told.
	 *
	 * @param array<int, array{0: int|string, 1: string}> $argument Tokens of the argument.
	 * @return string
	 */
	private function literal_or_expression( array $argument ): string {
		if ( 1 === count( $argument ) && T_CONSTANT_ENCAPSED_STRING === $argument[0][0] ) {
			return (string) substr( $argument[0][1], 1, -1 );
		}

		return '<not a literal string: ' . $this->argument_source( $argument ) . '>';
	}
}

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( FeaturesUtil::class, false ) ) {
	/**
	 * Stand-in for the WooCommerce utility that owns the register of feature
	 * compatibility declarations.
	 *
	 * It lives here, in the file of the only test that needs it, rather than in
	 * a shared bootstrap: a double installed for every test is a piece of global
	 * state that the next test inherits without asking for it, and the Unit
	 * suite of this package is built so that each test installs and removes its
	 * own.
	 *
	 * @package Factuarea\WooCommerce
	 */
	final class FeaturesUtil {

		/**
		 * Every declaration received since the last reset.
		 *
		 * @var array<int, array{feature_id: string, plugin_file: string, positive_compatibility: bool}>
		 */
		private static array $declarations = array();

		/**
		 * Records a declaration, exactly as WooCommerce would receive it.
		 *
		 * @param string $feature_id             Identifier of the feature.
		 * @param string $plugin_file            Path of the main plugin file.
		 * @param bool   $positive_compatibility Whether the plugin is compatible.
		 * @return bool
		 */
		public static function declare_compatibility( string $feature_id, string $plugin_file, bool $positive_compatibility = true ): bool {
			self::$declarations[] = array(
				'feature_id'             => $feature_id,
				'plugin_file'            => $plugin_file,
				'positive_compatibility' => $positive_compatibility,
			);

			return true;
		}

		/**
		 * The declarations received since the last reset.
		 *
		 * @return array<int, array{feature_id: string, plugin_file: string, positive_compatibility: bool}>
		 */
		public static function declarations(): array {
			return self::$declarations;
		}

		/**
		 * Forgets every declaration received.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$declarations = array();
		}
	}
}
