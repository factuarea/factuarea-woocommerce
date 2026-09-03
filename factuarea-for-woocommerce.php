<?php
/**
 * Plugin Name:          Factuarea for WooCommerce
 * Plugin URI:           https://factuarea.com
 * Description:          Captures the buyer tax ID at checkout and delivers paid WooCommerce orders and refunds to Factuarea, which turns them into invoices.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Factuarea
 * Author URI:           https://factuarea.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          factuarea-for-woocommerce
 * WC requires at least: 8.9
 * WC tested up to:      8.9
 *
 * @package Factuarea\WooCommerce
 */

/*
 * ---------------------------------------------------------------------------
 * THIS FILE CARRIES NO LOGIC, AND THAT IS ENFORCED BY WHAT IT IS
 * ---------------------------------------------------------------------------
 * A plugin entry point is the one file WordPress reads on EVERY request of the
 * site, and the one file that cannot be unit tested without loading WordPress.
 * Everything it does is therefore load and hand over: it publishes its own path,
 * puts the autoloader in place, declares the order-storage compatibility, and
 * calls `Plugin::boot()`. Every decision - which WordPress version is enough,
 * whether WooCommerce is there, what gets hooked, what the activation creates -
 * lives in `src/Plugin.php`, where it can be read and exercised on its own.
 *
 * ---------------------------------------------------------------------------
 * WHY THE VERSION NUMBER IS WRITTEN IN FOUR PLACES, AND THEY MOVE TOGETHER
 * ---------------------------------------------------------------------------
 * The minimum PHP version above is the same number as `require.php` in
 * composer.json, `testVersion` in phpcs.xml.dist and
 * `Constants::MINIMUM_PHP_VERSION`. Three places saying different numbers is how
 * a plugin ends up fatally erroring on the one installation that believed the
 * lowest of them, so they are changed in one commit or not at all.
 *
 * The same applies to `Version:` here and `Stable tag:` in readme.txt. The
 * release workflow refuses to build when the git tag, this header and that field
 * do not name the same version: a `Stable tag` pointing at a version that was
 * never uploaded makes the directory serve nothing at all.
 *
 * ---------------------------------------------------------------------------
 * NO TEXT DOMAIN IS LOADED HERE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * WordPress has loaded translations for directory-hosted plugins from language
 * packs by itself since 4.6, and calling `load_plugin_textdomain()` early enough
 * to matter is flagged as a mistake by WordPress 6.7 and later. The header
 * declares the text domain, which is what the language packs are built from, and
 * nothing calls anything.
 */

defined( 'ABSPATH' ) || exit;

/*
 * The one explicit require of the whole plugin: the autoloader itself cannot be
 * autoloaded. Everything after this line resolves through it.
 */
require_once __DIR__ . '/src/Plugin.php';

\Factuarea\WooCommerce\Plugin::register_autoloader();

/*
 * These two lines belong together and are written together.
 *
 * `HposCompatibility::declare_hpos_compatibility()` needs the absolute path of
 * THIS file - the path WooCommerce keys its register of feature declarations by -
 * and it runs much later, on `before_woocommerce_init`, when `__FILE__` no longer
 * points here. The constant is how the path survives that gap, and without it the
 * declaration is skipped rather than made wrongly.
 *
 * Declaring is unconditional and comes before the requirement checks, which are
 * deferred to `plugins_loaded` because WooCommerce loads AFTER this plugin does
 * (plugin files are included in directory order, and `factuarea-` sorts before
 * `woocommerce`). A declaration made by a plugin that then refuses to hook
 * anything is still true: a plugin that writes nothing cannot be incompatible
 * with where orders are stored.
 */
define( 'FACTUAREA_WC_PLUGIN_FILE', __FILE__ );
\Factuarea\WooCommerce\Compatibility\HposCompatibility::register();

\Factuarea\WooCommerce\Plugin::boot();
