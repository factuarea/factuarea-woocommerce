<?php
/**
 * Declaration of compatibility with WooCommerce's own order tables.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Compatibility;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Declares, affirmatively and at the one boot point where the declaration is
 * still read, that this plugin works with the WooCommerce order tables.
 *
 * ---------------------------------------------------------------------------
 * WHY THE POSITION MATTERS: A LATE DECLARATION DOES NOTHING AT ALL
 * ---------------------------------------------------------------------------
 * WooCommerce builds its register of feature-compatibility declarations while
 * it initialises, and `before_woocommerce_init` is the last moment a plugin can
 * still write into it. A declaration made afterwards - on `init`, on
 * `woocommerce_init`, on `plugins_loaded` after WooCommerce has loaded, or from
 * inside any admin screen - is not rejected and does not warn: it is simply
 * added to a register that has already been read, so it has NO effect
 * whatsoever. The plugin keeps being listed as one that has said nothing, and
 * "has said nothing" is treated as "not compatible".
 *
 * That is the failure this class exists to prevent, and it is invisible from
 * the code: `FeaturesUtil::declare_compatibility()` returns the same thing
 * whether it was called in time or too late. The only way to keep it from
 * happening is to keep the registration ON this hook, which is why the sweep in
 * the unit test reads this file and fails naming any other boot point it finds.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SIGN MATTERS: A NEGATIVE DECLARATION HITS THE WHOLE STORE
 * ---------------------------------------------------------------------------
 * The third argument is not "do I support this?" in the sense of a feature of
 * ours being switched off. It is a statement about the MERCHANT'S STORE:
 * declaring `false` tells WooCommerce that this plugin cannot work while orders
 * live in WooCommerce's own tables, and WooCommerce believes it. The merchant
 * is then kept from using that storage for the whole shop - not for our
 * deliveries, for everything - because a single active plugin said it would
 * break. On a shop that already moved to it, the incompatibility surfaces as a
 * warning pushing the merchant back towards the legacy storage.
 *
 * So the boolean is not a detail: `true` costs the merchant nothing and is
 * true, and `false` degrades the storage engine of a shop that has nothing to
 * do with us. There is no third option and no "safe default" to fall back on -
 * omitting the argument means `true`, and writing `false` "to be careful" is
 * the expensive mistake. The sweep in the unit test fails on it by name.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE MAIN PLUGIN FILE COMES FROM
 * ---------------------------------------------------------------------------
 * `register()` takes no arguments - that signature is frozen in
 * `Contract\Constants` and consumed by the wiring phase - and WooCommerce
 * identifies the declaring plugin by the path of its MAIN FILE, which this
 * class cannot know on its own. It reads it from the global constant named by
 * self::PLUGIN_FILE_CONSTANT, which the main plugin file defines from its own
 * `__FILE__` immediately before calling `register()`.
 *
 * It is `__FILE__` of the main file and not a path rebuilt from this class's
 * location on purpose: WordPress registers a plugin under the path it was
 * loaded from, and on an installation whose plugin directory is a symbolic link
 * a path resolved from `__DIR__` is a DIFFERENT string from the registered one.
 * `plugin_basename()`, which WooCommerce applies to whatever it is given, would
 * then produce a plugin identifier that matches no active plugin, and the
 * declaration would be filed against nobody.
 *
 * When the constant is missing this class declares NOTHING, and that is the
 * deliberate behaviour rather than an omission: declaring against an empty or
 * invented path files a compatibility statement for a plugin that is not
 * installed, which leaves this one listed as undeclared anyway and adds a
 * phantom entry to the register. Declaring nothing produces exactly one
 * symptom, WooCommerce's own "incompatible plugins" notice, and that notice
 * names this plugin and points at the wiring that was left out.
 */
final class HposCompatibility {

	/**
	 * Name of the global constant holding the absolute path of the main plugin
	 * file.
	 *
	 * The main plugin file defines it as `define( 'FACTUAREA_WC_PLUGIN_FILE',
	 * __FILE__ );` on the line before it calls `register()`. The two belong
	 * together and are written together: a definition somewhere else, or a
	 * `register()` call reached by another path, is how the constant goes
	 * missing.
	 */
	public const PLUGIN_FILE_CONSTANT = 'FACTUAREA_WC_PLUGIN_FILE';

	/**
	 * Hooks the declaration onto the boot point that runs before WooCommerce
	 * initialises.
	 *
	 * This method only REGISTERS. It does not declare anything itself, because
	 * it is called from the main plugin file while WordPress is still loading
	 * plugins, and at that instant WooCommerce has not been loaded yet.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declares the compatibility. Runs on the hook registered above, never
	 * directly.
	 *
	 * The guard on the utility class is not decoration. A merchant can activate
	 * this plugin on a WooCommerce older than the one it announces as its
	 * minimum, and there the hook exists while the utility class does not: an
	 * unguarded static call would be a fatal error on every request of the shop,
	 * front end included. Returning early leaves the merchant with a plugin that
	 * declares nothing on a WooCommerce whose order tables do not exist either.
	 *
	 * @return void
	 */
	public static function declare_hpos_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		$plugin_file = self::plugin_file();

		if ( '' === $plugin_file ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
	}

	/**
	 * Absolute path of the main plugin file, or an empty string when the main
	 * plugin file has not published it.
	 *
	 * @return string
	 */
	private static function plugin_file(): string {
		if ( ! defined( self::PLUGIN_FILE_CONSTANT ) ) {
			return '';
		}

		$plugin_file = constant( self::PLUGIN_FILE_CONSTANT );

		return is_string( $plugin_file ) ? $plugin_file : '';
	}

	/**
	 * Not instantiable: declaring a compatibility needs no state.
	 */
	private function __construct() {
	}
}
