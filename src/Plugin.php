<?php
/**
 * The one place this plugin is wired together.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce;

use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Checkout\BlockCheckoutTaxIdField;
use Factuarea\WooCommerce\Checkout\ClassicCheckoutTaxIdField;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Events\OrderRefundedListener;
use Factuarea\WooCommerce\Events\OrderStatusChangedListener;
use Factuarea\WooCommerce\Events\PaymentCompleteListener;
use Factuarea\WooCommerce\Inbound\CatalogPushEndpoint;
use Factuarea\WooCommerce\Inbound\InboundDeliveryLedger;
use Factuarea\WooCommerce\Inbound\InboundDeliveryTable;
use Factuarea\WooCommerce\Inbound\OrderRefundEndpoint;
use Factuarea\WooCommerce\Inbound\OrderStatusEndpoint;
use Factuarea\WooCommerce\MyAccount\InvoiceCorrelationEndpoint;
use Factuarea\WooCommerce\MyAccount\InvoicePdfLink;
use Factuarea\WooCommerce\MyAccount\OrderStatusDisplay;
use Factuarea\WooCommerce\Outbox\OutboxDispatcher;
use Factuarea\WooCommerce\Outbox\OutboxTable;

/*
 * Direct access guard, and this is the ONE class file of the plugin that needs
 * one.
 *
 * Every other file under `src/` declares a class and nothing else, so requesting
 * it straight from a browser loads a definition and runs no statement. This one
 * carries the `require_once` below at file scope - the single unavoidable
 * exception explained right after - which means that hitting its URL directly
 * would execute it outside WordPress, with `ABSPATH` undefined and the frozen
 * contract pulled in for nobody. The official plugin check names exactly this
 * file for exactly this reason.
 */
defined( 'ABSPATH' ) || exit;

/*
 * The one thing this file cannot autoload, because it is what makes autoloading
 * possible.
 *
 * `SCHEDULER_GROUP` below is a class constant whose value is read from the frozen
 * contract, and PHP evaluates the constant expressions of a class the first time
 * the class is touched - which is the call to `register_autoloader()` itself. At
 * that instant there is no autoloader yet, so `Constants` cannot be found and the
 * plugin dies with a fatal error before it has loaded anything. MEASURED, not
 * feared: the boot smoke of this phase reproduced it exactly that way.
 *
 * One `require_once` removes the circle, and it is the honest shape of the fact
 * that the loader needs the frozen contract before it can install the loader.
 */
require_once __DIR__ . '/Contract/Constants.php';

/**
 * Loader, requirement gate and single wiring point of the plugin.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS EXACTLY ONE OF THESE
 * ---------------------------------------------------------------------------
 * Every other class of this plugin registers ITSELF - `register()` on the field,
 * on the three listeners, on the settings screen, on the buyer-facing surfaces,
 * on the three inbound receivers. None of them knows about any other, and none
 * of them decides WHEN it is registered. That decision is here, once, in
 * `wire()`, so that the answer to "what does this plugin hook, and under what
 * conditions" is one screen of code rather than a search across twenty files.
 *
 * ---------------------------------------------------------------------------
 * THE AUTOLOADER IS OURS, AND THAT IS A DISTRIBUTION DECISION
 * ---------------------------------------------------------------------------
 * The published artefact carries no `vendor/` directory: `.distignore` excludes
 * it, and the packaging check fails if it ever appears. It can, because this
 * plugin declares NO runtime dependency - the only thing composer installs is
 * developer tooling.
 *
 * So the plugin registers its own PSR-4 autoloader for `Factuarea\WooCommerce\`
 * instead of requiring `vendor/autoload.php`. Two reasons, and neither is taste:
 *
 *  1. In the artefact there is nothing to require. A `require_once` of a file
 *     that is not there is a fatal error on the merchant's site, on every
 *     request, with the shop down.
 *  2. Requiring one when it HAPPENS to be there - a developer checkout, a
 *     working copy copied into `wp-content/plugins` - would pull PHPUnit, the
 *     code sniffer and their dependency trees into a WordPress process. That is
 *     the library-collision failure this package refuses to cause: two plugins
 *     carrying different versions of the same library in one process, and the
 *     one that loads first wins.
 *
 * The development suites are unaffected: phpunit bootstraps composer's
 * autoloader itself, and composer maps the same namespace to the same directory.
 *
 * ---------------------------------------------------------------------------
 * WHAT RUNS BEFORE THE REQUIREMENT GATE, AND WHAT CANNOT
 * ---------------------------------------------------------------------------
 * The gate runs on `plugins_loaded` and NOT while this file is being included,
 * because WooCommerce is not loaded yet at that point: plugin files are included
 * in directory order and `factuarea-for-woocommerce` sorts before `woocommerce`.
 * Asking `class_exists( 'WooCommerce' )` from the entry point would answer "no"
 * on a shop where WooCommerce is active and fine.
 *
 * Until the gate has run, the only things registered are the autoloader and the
 * order-storage compatibility declaration, which has to be registered from the
 * entry point for its own reasons (see `Compatibility\HposCompatibility`).
 * NOTHING ELSE is hooked when a requirement is unmet - not the checkout field,
 * not a listener, not the settings screen - because hooking onto functions that
 * are not there is how a plugin turns a missing dependency into a fatal error on
 * the merchant's admin.
 */
final class Plugin {

	/**
	 * Namespace prefix the autoloader answers for, with its trailing separator.
	 */
	public const NAMESPACE_PREFIX = 'Factuarea\\WooCommerce\\';

	/**
	 * Action fired by the scheduler for every drain of the outbox.
	 *
	 * It is a HOOK NAME and not an option: nothing is stored under it. It carries
	 * the plugin option prefix because a scheduler hook is a global name and the
	 * coding standard of this repository prefixes every global name the plugin
	 * publishes.
	 */
	public const DRAIN_HOOK = 'factuarea_wc_drain_outbox';

	/**
	 * Group the scheduled drain belongs to.
	 *
	 * A group of our own rather than the default one, so that a merchant or a
	 * support engineer reading WooCommerce's scheduled-actions screen can filter
	 * this plugin's work out of the thousands of rows WooCommerce itself puts
	 * there, and so that `as_unschedule_all_actions()` on deactivation cannot
	 * reach anything that is not ours.
	 */
	public const SCHEDULER_GROUP = Constants::PLUGIN_SLUG;

	/**
	 * Seconds between two drains of the outbox.
	 *
	 * SIXTY, and the number is the shortest interval the retry schedule can
	 * express rather than a preference. The first retry of a failed delivery is
	 * planned for 60 seconds after the failure, so a drain that ran less often
	 * would make that first retry late by construction, for every delivery, and
	 * the plugin would be describing a backoff it does not keep. Shorter is not
	 * possible either: the scheduler's own queue runner is triggered about once a
	 * minute by WordPress's scheduled work, so an interval below that is a
	 * request the environment cannot honour.
	 *
	 * A drain that finds nothing due costs one indexed SELECT.
	 */
	public const DRAIN_INTERVAL_SECONDS = 60;

	/**
	 * Most entries one drain takes.
	 *
	 * TEN, chosen from the worst case rather than from the throughput. Each entry
	 * is one outbound request with a 15 second timeout, so ten is 150 seconds of
	 * wall clock in the pathological run where every single delivery times out -
	 * which still fits inside a scheduled run, and, more to the point, inside the
	 * five minutes after which a claim held by a drain that died is taken back.
	 * A larger batch would let one bad run hold entries longer than the outbox
	 * waits before deciding that run is gone.
	 *
	 * Ten per minute is six hundred deliveries an hour, which is far above the
	 * paid-order rate of any shop this plugin is for; whatever a burst leaves
	 * behind is taken by the next pass sixty seconds later.
	 */
	public const DRAIN_BATCH_SIZE = 10;

	/**
	 * Has the autoloader been registered already?
	 *
	 * @var bool
	 */
	private static $autoloader_registered = false;

	/**
	 * Requirements found unmet by the gate, as sentences ready to be shown.
	 *
	 * @var array
	 */
	private static $unmet_requirements = array();

	/**
	 * Puts the PSR-4 autoloader in place. Idempotent.
	 *
	 * @return void
	 */
	public static function register_autoloader() {
		if ( self::$autoloader_registered ) {
			return;
		}

		self::$autoloader_registered = true;

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads one class of this plugin, and answers for nothing else.
	 *
	 * A class name outside the plugin namespace returns without touching the
	 * filesystem, and so does one whose remainder is not a plain sequence of
	 * name segments: an autoloader that turned an arbitrary string into a path
	 * would be a file-inclusion surface, and there is no legitimate class name
	 * this refuses.
	 *
	 * @param string $class_name Fully qualified class name PHP is looking for.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		$class_name = (string) $class_name;

		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative ) ) {
			return;
		}

		$path = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Registers the activation and deactivation routines and defers the rest to
	 * `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! defined( Compatibility\HposCompatibility::PLUGIN_FILE_CONSTANT ) ) {
			return;
		}

		$plugin_file = (string) constant( Compatibility\HposCompatibility::PLUGIN_FILE_CONSTANT );

		register_activation_hook( $plugin_file, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( $plugin_file, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'load' ), 10, 0 );
	}

	/**
	 * Runs the requirement gate and either wires the plugin or explains why it
	 * did not.
	 *
	 * @return void
	 */
	public static function load() {
		self::$unmet_requirements = self::unmet_requirements();

		if ( array() !== self::$unmet_requirements ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_requirements_notice' ), 10, 0 );

			return;
		}

		self::wire();
	}

	/**
	 * What this installation is missing, as sentences a merchant can act on.
	 *
	 * An empty list means the plugin may run. Every entry names the requirement
	 * AND what this site has instead, because "requires WooCommerce 8.9" without
	 * the installed version leaves the reader to go and look it up before they
	 * can even tell whether the message applies to them.
	 *
	 * @return array List of requirement sentences; empty when everything is met.
	 */
	public static function unmet_requirements() {
		$unmet = array();

		if ( version_compare( PHP_VERSION, Constants::MINIMUM_PHP_VERSION, '<' ) ) {
			$unmet[] = sprintf(
				/* translators: 1: minimum PHP version required, 2: PHP version this site runs. */
				__( 'PHP %1$s or later. This site runs PHP %2$s.', 'factuarea-for-woocommerce' ),
				Constants::MINIMUM_PHP_VERSION,
				PHP_VERSION
			);
		}

		$wordpress_version = (string) get_bloginfo( 'version' );

		if ( '' !== $wordpress_version && version_compare( $wordpress_version, Constants::MINIMUM_WORDPRESS_VERSION, '<' ) ) {
			$unmet[] = sprintf(
				/* translators: 1: minimum WordPress version required, 2: WordPress version this site runs. */
				__( 'WordPress %1$s or later. This site runs WordPress %2$s.', 'factuarea-for-woocommerce' ),
				Constants::MINIMUM_WORDPRESS_VERSION,
				$wordpress_version
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$unmet[] = sprintf(
				/* translators: %s: minimum WooCommerce version required. */
				__( 'WooCommerce %s or later, active. It is not active on this site.', 'factuarea-for-woocommerce' ),
				Constants::MINIMUM_WOOCOMMERCE_VERSION
			);
		} elseif ( ! defined( 'WC_VERSION' ) ) {
			$unmet[] = sprintf(
				/* translators: %s: minimum WooCommerce version required. */
				__( 'WooCommerce %s or later. This site runs a WooCommerce that does not report its version, so it is older than any version this plugin can work with.', 'factuarea-for-woocommerce' ),
				Constants::MINIMUM_WOOCOMMERCE_VERSION
			);
		} elseif ( version_compare( (string) WC_VERSION, Constants::MINIMUM_WOOCOMMERCE_VERSION, '<' ) ) {
			$unmet[] = sprintf(
				/* translators: 1: minimum WooCommerce version required, 2: WooCommerce version this site runs. */
				__( 'WooCommerce %1$s or later. This site runs WooCommerce %2$s, which has no additional checkout fields interface, so the tax ID field cannot be added to the block checkout at all.', 'factuarea-for-woocommerce' ),
				Constants::MINIMUM_WOOCOMMERCE_VERSION,
				(string) WC_VERSION
			);
		}

		return $unmet;
	}

	/**
	 * Prints the notice that says what is missing.
	 *
	 * Shown only to somebody who can do something about it. A buyer, or a shop
	 * manager who cannot activate plugins, would read an instruction they are not
	 * able to follow.
	 *
	 * @return void
	 */
	public static function render_requirements_notice() {
		if ( array() === self::$unmet_requirements ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: plugin name. */
				__( '%s is installed but not running, because this site does not meet what it needs:', 'factuarea-for-woocommerce' ),
				Constants::PLUGIN_NAME
			)
		);
		echo '</p><ul style="list-style:disc;margin-left:2em;">';

		foreach ( self::$unmet_requirements as $requirement ) {
			echo '<li>' . esc_html( (string) $requirement ) . '</li>';
		}

		echo '</ul><p>';
		echo esc_html__(
			'Nothing has been lost. No order is being captured and nothing is being sent while this notice is here; the plugin starts working as soon as the list above is satisfied.',
			'factuarea-for-woocommerce'
		);
		echo '</p></div>';
	}

	/**
	 * Registers every part of the plugin, in the order they have to be
	 * registered in.
	 *
	 * The two checkout fields come first because both have to be hooked before
	 * WooCommerce initialises: the block checkout field is declared on
	 * `woocommerce_init`, and a registration made after that hook has run
	 * declares a field nobody paints.
	 *
	 * ---------------------------------------------------------------------
	 * THE THREE RECEIVERS ARE REGISTERED UNCONDITIONALLY, AND THAT IS THE
	 * DECISION
	 * ---------------------------------------------------------------------
	 * Each receiver is governed by its own switch, and both switches are born
	 * off. The switch is read INSIDE the request, not here, because a route
	 * that is not registered answers 404 - the same answer a WordPress that
	 * never heard of this plugin gives - and the sender cannot tell "this shop
	 * has the receiver switched off" from "this address is wrong". Registered
	 * and switched off answers the frozen `RECEIVER_DISABLED`, which is an
	 * answer the sender can act on, and it still applies nothing and claims no
	 * idempotency key.
	 *
	 * ---------------------------------------------------------------------
	 * WHAT OF THE INBOUND HALF IS *NOT* WIRED HERE, AND WHY
	 * ---------------------------------------------------------------------
	 * Only the three receivers and the buyer-facing display. The signature
	 * verifier, the delivery ledger, the fulfilment storage, the refund
	 * applier, the product resolver and the three catalogue writers have no
	 * `register()` and hook nothing: they are collaborators the receivers build
	 * lazily, per request, and a PHP run that never receives a delivery never
	 * constructs one of them. Wiring them from here would build seven objects
	 * on every single page view of the shop to serve the requests that do not
	 * arrive.
	 *
	 * The transport client is not built here either, for a harder reason: this
	 * plugin has ONE outbound door, and the structural gate of the package
	 * keeps the list of files allowed through it. `OutboxDispatcher` builds its
	 * own client inside; handing it one from here would make this file a
	 * consumer of the door and would have to be declared as such.
	 *
	 * The order of the last four lines does not matter. "After the invoice
	 * access" is a property of the buyer's screen and is settled by the hook
	 * PRIORITY of `OrderStatusDisplay` - 20, against the default 10 of
	 * `InvoicePdfLink`, on the same two hooks - and not by which line is typed
	 * first here.
	 *
	 * @return void
	 */
	private static function wire() {
		BlockCheckoutTaxIdField::register();
		( new ClassicCheckoutTaxIdField() )->register();

		( new PaymentCompleteListener() )->register();
		( new OrderStatusChangedListener() )->register();
		( new OrderRefundedListener() )->register();

		( new SettingsPage() )->register();
		( new InvoiceCorrelationEndpoint() )->register();
		( new InvoicePdfLink() )->register();

		( new OrderStatusEndpoint() )->register();
		( new OrderRefundEndpoint() )->register();
		( new CatalogPushEndpoint() )->register();
		( new OrderStatusDisplay() )->register();

		add_action( self::DRAIN_HOOK, array( __CLASS__, 'drain_outbox' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'ensure_drain_scheduled' ), 20, 0 );
	}

	/**
	 * Empties as much of the outbox as one pass is allowed to take, and forgets
	 * the inbound deliveries that are old enough to be forgotten.
	 *
	 * ---------------------------------------------------------------------
	 * WHY THE TWO THINGS RIDE THE SAME PASS
	 * ---------------------------------------------------------------------
	 * The inbound ledger needs a housekeeping pass of its own: a row per
	 * delivery received, kept for as long as the sender could still retry it,
	 * and removed afterwards. That is one indexed DELETE a minute, and it does
	 * not deserve a second recurring action.
	 *
	 * A second scheduled action would be a second thing that can be lost by a
	 * database restore or by a cleanup plugin, a second entry on the merchant's
	 * scheduled-actions screen, and a second hook to unschedule on
	 * deactivation - all to run a statement that costs less than the SELECT
	 * this pass already makes. Hanging it here means the ledger is swept
	 * exactly as often as the outbox is drained, by the schedule this plugin
	 * already asserts on every request.
	 *
	 * The order is deliberate: the outbox first. The drain is what the merchant
	 * loses money by not running, and a housekeeping DELETE that somehow failed
	 * must not be able to stand between a paid order and its invoice.
	 *
	 * ---------------------------------------------------------------------
	 * THE CUT-OFF IS NOT COMPOSED HERE
	 * ---------------------------------------------------------------------
	 * `InboundDeliveryLedger::prune_cutoff()` is the ONE place that turns the
	 * retention window into an instant, and this call site asks it rather than
	 * subtracting anything itself. A subtraction written here would be a second
	 * retention window, and the day one of the two was shortened the forgotten
	 * key of a retry in flight would be taken for a new delivery and its effect
	 * applied a SECOND time. `prune()` returns how many rows it removed and
	 * never throws.
	 *
	 * @return void
	 */
	public static function drain_outbox() {
		$dispatcher = new OutboxDispatcher();

		$dispatcher->drain( self::DRAIN_BATCH_SIZE );

		( new InboundDeliveryLedger() )->prune( InboundDeliveryLedger::prune_cutoff( time() ) );
	}

	/**
	 * Makes sure the recurring drain is on the schedule, and schedules it if it
	 * is not.
	 *
	 * Activation already schedules it, so this looks redundant and is not. A
	 * plugin activated before WooCommerce has no scheduler to talk to at that
	 * moment, and there is nothing to stop a merchant activating them in that
	 * order; a schedule can also be lost by a database restore, or by a cleanup
	 * plugin emptying the scheduler's tables. Without this the outbox would fill
	 * up and never empty, which is the exact silence this package exists to
	 * remove - so the schedule is asserted on every request instead of trusted.
	 *
	 * The check is one indexed lookup and it runs after the scheduler has
	 * loaded, which is why it hangs off `init` rather than off `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function ensure_drain_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::DRAIN_HOOK, null, self::SCHEDULER_GROUP ) ) {
			return;
		}

		self::schedule_drain();
	}

	/**
	 * Creates what the plugin needs on this site and starts the drain.
	 *
	 * The tables are created here rather than lazily on first write, because
	 * the first write happens inside the request that took a payment, or inside
	 * the one applying an inbound delivery: a shop whose table appeared at that
	 * moment would be one migration away from losing the sale that created it.
	 *
	 * On a network activation every site gets its own tables, exactly as the
	 * uninstall routine removes the options from every site. Both the outbox
	 * and the inbound ledger live under `$wpdb->prefix`, so one pair of tables
	 * on the main site would leave every other shop of the network writing into
	 * nothing.
	 *
	 * KNOWN LIMIT, written down rather than hidden: a site created AFTER this
	 * plugin was network-activated gets no tables from here. Activating the
	 * plugin on that site, or reactivating it network-wide, creates them.
	 *
	 * @param bool $network_wide Whether WordPress is activating this plugin for
	 *                           the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_on_current_site();
				restore_current_blog();
			}

			return;
		}

		self::install_on_current_site();
	}

	/**
	 * Stops the drain and leaves everything else exactly where it is.
	 *
	 * A deactivated plugin captures nothing and sends nothing, but the outbox and
	 * its rows are the merchant's record of what was and was not delivered, and
	 * the settings are what they typed. Both survive: deactivating is how a
	 * merchant pauses this plugin to look at something, and a pause that threw
	 * away undelivered sales would not be a pause. Removing them is what
	 * uninstalling does, and even that keeps the outbox.
	 *
	 * @param bool $network_wide Whether WordPress is deactivating this plugin for
	 *                           the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::unschedule_drain();
				restore_current_blog();
			}

			return;
		}

		self::unschedule_drain();
	}

	/**
	 * Creates or updates the two tables of the site that is current right now,
	 * and schedules the pass that serves both.
	 *
	 * The inbound ledger is created alongside the outbox and by the same route,
	 * for the same reason and with a sharper edge: the first write to it
	 * happens inside the request that is APPLYING an inbound delivery, and a
	 * shop whose ledger appeared at that moment would have no record of the
	 * delivery it just applied - so the sender's next retry would be taken for
	 * a new one and applied again. Both calls are idempotent: `dbDelta` on an
	 * unchanged table is a handful of `DESCRIBE` queries.
	 *
	 * @return void
	 */
	private static function install_on_current_site() {
		OutboxTable::install();
		InboundDeliveryTable::install();

		self::schedule_drain();
	}

	/**
	 * Puts the recurring drain on the schedule.
	 *
	 * Guarded rather than assumed: the scheduler is part of WooCommerce, and this
	 * runs on activation, which a merchant can perform before WooCommerce is
	 * there. When it is missing the drain is simply not scheduled yet, and
	 * `ensure_drain_scheduled()` puts it on as soon as WooCommerce loads.
	 *
	 * @return void
	 */
	private static function schedule_drain() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::DRAIN_HOOK, null, self::SCHEDULER_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			self::DRAIN_INTERVAL_SECONDS,
			self::DRAIN_HOOK,
			array(),
			self::SCHEDULER_GROUP
		);
	}

	/**
	 * Takes the recurring drain off the schedule, and only it.
	 *
	 * Scoped by hook AND by group, so that nothing else scheduled on this site
	 * can be reached even if some other plugin ever published a hook by the same
	 * name.
	 *
	 * @return void
	 */
	private static function unschedule_drain() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::DRAIN_HOOK, array(), self::SCHEDULER_GROUP );
	}

	/**
	 * Not instantiable: a loader has no state worth carrying around.
	 */
	private function __construct() {
	}
}
