<?php
/**
 * No-paywall, licence and packaging gate.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

/*
 * ---------------------------------------------------------------------------
 * WHY THIS GATE EXISTS, QUOTED
 * ---------------------------------------------------------------------------
 * The WordPress plugin directory guidelines say it in one sentence, under
 * guideline 5, "Trialware is not permitted":
 *
 *   "Plugins may not contain functionality that is restricted or locked, only
 *    to be made available by payment or upgrade."
 *
 * That is not a style preference and it is not negotiable at review time: a
 * plugin that withholds one feature until somebody pays is rejected, and a
 * plugin already published that grows one is removed. Reinstating it means
 * waiting for every merchant to update, which takes weeks.
 *
 * So the commercial boundary of this product is the SERVICE and never the
 * plugin. Factuarea charges for the invoicing platform on the other end of the
 * delivery; the plugin that captures the tax identifier, signs the delivery and
 * keeps the outbox alive is free, complete and does everything it can do the
 * moment it is installed. A merchant without a Factuarea account gets a plugin
 * that is fully functional and has nowhere to deliver to - which is a missing
 * SERVICE, not a locked FEATURE.
 *
 * This gate is what keeps that true after the people who decided it have moved
 * on. It is deliberately cheap to run: plain PHP, no dependencies, no network.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT CHECKS, IN THREE PARTS
 * ---------------------------------------------------------------------------
 * 1. NO PAYWALL. The plugin sources, the scripts and the two root files are
 *    read with the PHP tokeniser, and every token that is not a comment is
 *    matched against an enumerated vocabulary of licence checks, paid tiers,
 *    plan gates, licensing frameworks and the sentences that announce them.
 *    Each finding names the file, the line, the matched text and the source
 *    line it was found on - the condition, not just its existence.
 *
 * 2. LICENCE. The licence file is present and INTEGRAL - a truncated GPL is
 *    worse than an absent one, because it looks like a licence - and what the
 *    plugin header and the composer manifest declare agrees with the text that
 *    actually ships.
 *
 * 3. PACKAGING. The artefact that would be published carries the plugin, its
 *    licence and its directory listing, and nothing else: no tests, no
 *    scripts, no development documentation, no developer tooling and no
 *    installed dependency tree.
 *
 * ---------------------------------------------------------------------------
 * THE TWO RECEIVER TOGGLES, AND WHY THEY ARE NOT WHAT THIS GATE LOOKS FOR
 * ---------------------------------------------------------------------------
 * The inbound half is the first thing this plugin ever put a condition in
 * front of. Two settings - `factuarea_wc_order_status_receiver_enabled` and
 * `factuarea_wc_catalog_push_receiver_enabled` - decide whether each receiver
 * answers at all, and both start switched OFF, so a fresh installation accepts
 * nothing until the merchant says so.
 *
 * That is not a paywall and this gate must not report it. The distinction is
 * not "how many conditions" but WHO the condition belongs to: the merchant
 * owns a setting of their own shop, switches it on by clicking a checkbox and
 * pays nobody for the privilege. A paywall is a condition owned by the seller,
 * which the merchant can only satisfy with money. Defaulting to off is the
 * opposite of withholding: an endpoint that accepted signed deliveries the
 * moment the plugin was installed would be a security decision made on the
 * merchant's behalf.
 *
 * What the vocabulary grew for is the version of the same code where the
 * condition changes hands. MEASURED on 2026-09-18 against a fixture written as
 * somebody would actually write it - `tests/fixtures/no-paywall/gated-receiver.php`
 * - the vocabulary as it stood caught the licence key, the plan level, the paid
 * tier and the three sentences, and MISSED four conditions: the subscription
 * the receiver consults, the entitlement beside it, the function asking whether
 * the plan allows the inbound half, and the answer naming the payment as the
 * reason. Its clean twin, `ungated-receiver.php`, is the same receiver behind
 * the merchant toggle, and it carries the near misses - `is_paid()`, the word
 * `subscription` on its own, `planner`, `processing`, `unlocked` - in CODE and
 * not in a docblock, because the scanner drops comments before it matches and
 * a near miss parked in prose proves nothing.
 *
 * One shape is deliberately NOT a pattern: the bare status code of a payment
 * demand. The scanner matches one token at a time, so the key and its value
 * never meet in the same subject, and a pattern for a bare three-digit number
 * would be the kind of loose rule the note below explains this file refuses.
 * The constant a PHP receiver would name it with IS a pattern, and so is the
 * sentence, which is two doors on the same room.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PHP TOKENISER AND NOT A REGULAR EXPRESSION OVER THE TEXT
 * ---------------------------------------------------------------------------
 * Because this repository writes long docblocks, and MEASURED on 2026-09-02
 * against the sources as they stood, a text sweep for the obvious words
 * produced fifteen hits and every single one was prose:
 *
 *   - "a paid order is lost for good"     (HttpResponse, RejectionClassifier,
 *                                          WordPressHttpClient, and four more);
 *   - ":paid" as the suffix of an event identity (EventIdentity);
 *   - "no further wait to plan", "for the planner" (RetrySchedule);
 *   - "RetryPlanner", the class name, in a frozen signature list.
 *
 * A gate that is red on a clean repository is a gate somebody switches off, and
 * that is the failure this project has a name for. The tokeniser removes the
 * whole class mechanically: comments are dropped, so prose about a paid order
 * cannot fire, while `$is_pro`, `'license_key'` and `"upgrade to premium"` all
 * still do, because those are code and string literals rather than commentary.
 *
 * The other half of the same decision is that the vocabulary is ENUMERATED and
 * anchored, never a loose substring: `is_pro` does not match `is_processing`
 * and `paid plan` does not match "a paid order", while a bare `pro` or a bare
 * `plan` would have matched both and the list would have been useless within a
 * week. The anchors are not `\b`, and the reason is measured - see the note on
 * FACTUAREA_WC_PAYWALL_WORD_START.
 *
 * The direction of the remaining error is chosen on purpose. A word inside a
 * string literal is reported even when it is innocent, because a false red is
 * read by a human and dismissed in a minute, and a false green ships to every
 * merchant. The allowlist below is the place where a dismissal gets written
 * down instead of being repeated.
 *
 * ---------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------
 *   php scripts/check-no-paywall.php
 *       Audits this repository: the three parts above. This is what
 *       `composer lint:no-paywall` runs.
 *
 *   php scripts/check-no-paywall.php --root <dir>
 *       The same, treating <dir> as the repository root. The self-test uses
 *       this to feed the gate synthetic repositories.
 *
 *   php scripts/check-no-paywall.php --scan <path>
 *       Runs ONLY the paywall lens, over one file or one directory, and
 *       reports nothing else. The self-test uses this on its two fixtures.
 *
 * EXIT CODES
 *   0  Clean. Everything the gate can check was checked and nothing was found.
 *   1  Findings. Every one of them is printed with its file and its line.
 *   2  The gate could not be run: a bad argument, or a path it cannot read.
 *   3  SKIPPED. Nothing was found, but at least one check could not run
 *      because the thing it compares against does not exist yet - the plugin
 *      entry point, written by the wiring phase, is the case this package hits
 *      today. Distinguishable from 0 on purpose: a check that cannot see its
 *      reference and reports success is a guardrail that only looks like one,
 *      and this repository has been bitten by exactly that.
 *
 * ---------------------------------------------------------------------------
 * COPYING THIS FILE TO THE OTHER PACKAGE REPOSITORIES
 * ---------------------------------------------------------------------------
 * The paywall lens is not specific to WooCommerce, and the sibling package
 * repositories publish under directories with the same rule. What would have to
 * change to reuse it: the scanned paths, the licence expected by the manifest,
 * and the packaging lists. The vocabulary and the tokeniser stay as they are.
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- A command line script that runs on bare PHP with no WordPress loaded, so WP_Filesystem and the WordPress HTTP API do not exist here. Every use below is a local read of a file in this repository or a write to the terminal.

/**
 * Everything the gate could check was checked, and nothing was found.
 */
const FACTUAREA_WC_PAYWALL_EXIT_SUCCESS = 0;

/**
 * At least one finding. Each one is printed with its file and its line.
 */
const FACTUAREA_WC_PAYWALL_EXIT_FINDINGS = 1;

/**
 * The gate could not be run at all: a bad argument, or an unreadable path.
 */
const FACTUAREA_WC_PAYWALL_EXIT_USAGE = 2;

/**
 * Nothing was found, but a check could not run because its reference is absent.
 */
const FACTUAREA_WC_PAYWALL_EXIT_SKIPPED = 3;

/**
 * Where the paywall lens looks, relative to the repository root.
 *
 * Enumerated one path at a time and never as a wildcard over the root. A
 * wildcard would start covering whatever directory somebody adds next, and the
 * first thing it would cover is `vendor/`, where every licensing framework this
 * gate knows about lives legitimately as somebody else's code.
 *
 * A path that does not exist is SKIPPED by name rather than passed over: the
 * two root files are written by the wiring phase, and a gate that quietly
 * scanned nothing would report success over an empty set.
 *
 * @var list<string>
 */
const FACTUAREA_WC_PAYWALL_SCANNED_PATHS = array(
	'src',
	'scripts',
	'factuarea-for-woocommerce.php',
	'uninstall.php',
);

/**
 * This gate, as the repository names it. It is the file the allowlist lives in
 * and the one file the paywall lens does not read.
 */
const FACTUAREA_WC_PAYWALL_GATE_FILE = 'scripts/check-no-paywall.php';

/**
 * Files inside the scanned paths that the paywall lens does not read, keyed by
 * the reason, enumerated by EXACT path and never by pattern.
 *
 * There is one, and it is this file. Every pattern of the vocabulary appears in
 * it as a literal, so scanning it would report the gate as its own worst
 * offender on every run. The exclusion is safe for a reason that is measured
 * rather than assumed: `scripts/` is excluded by `.distignore`, and the
 * packaging check below fails if anything under it reaches the artefact - so a
 * paywall hidden in this file could not ship even if somebody put one here.
 *
 * @var array<string, list<string>>
 */
const FACTUAREA_WC_PAYWALL_VOCABULARY_FILES = array(
	'declares the vocabulary below, so every pattern appears in it as a literal; it never ships, and the packaging check is what proves that' => array(
		FACTUAREA_WC_PAYWALL_GATE_FILE,
	),
);

/**
 * Opening of every pattern below: a boundary that treats a letter or a digit as
 * part of the word and an underscore or a hyphen as a separator.
 *
 * `\b` cannot be used here and the reason is measured. PCRE counts `_` as a
 * word character, so `\blicense_key\b` does NOT match `factuarea_wc_license_key`
 * - there is no boundary between `wc_` and `license`. The first version of this
 * gate used `\b` and, over the fixture written to defeat it, matched the
 * variable `$license_key` and missed the option name beside it, the function
 * `is_premium_active` and the option `factuarea_wc_plan_required`: three of the
 * four families silently unenforced, which is what a self-test is for.
 */
const FACTUAREA_WC_PAYWALL_WORD_START = '/(?<![A-Za-z0-9])';

/**
 * Closing boundary, symmetric with the one above. It is what keeps `is_pro`
 * from matching `is_processing` while still matching `is_pro_mode`.
 */
const FACTUAREA_WC_PAYWALL_WORD_END = '(?![A-Za-z0-9])/i';

/**
 * The vocabulary, keyed by the reason a match is a problem.
 *
 * Every entry is wrapped in the two boundaries above, so an entry here is just
 * the words. Read the note at the top of this file before adding a loose one: a
 * bare `pro`, `plan` or `paid` matches this repository's own prose and turns
 * the gate into the kind of noise somebody switches off.
 *
 * @var array<string, list<string>>
 */
const FACTUAREA_WC_PAYWALL_PATTERNS = array(
	'names a licence key or a licence check, which is the credential a paywall consults before it unlocks anything' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'licen[cs]e[_-]?key' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'api[_-]?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'licen[cs]e[_-]?status' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'is[_-]?licen[cs]ed' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'has[_-]?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'check[_-]?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'validate[_-]?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'activate[_-]?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'licen[cs]e[_-]?activation' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'activation[_-]?key' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
	'names a paid tier of this plugin, and a paid tier can only exist by withholding something from whoever has not paid' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'is[_-]?premium' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'is[_-]?pro' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'pro[_-]?version' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'premium[_-]?version' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'premium[_-]?only' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'pro[_-]?only' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'go[_-]?pro' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'upgrade[_-]?url' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'upsell\w*' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
	'names a plan or a subscription the plugin would consult before it agrees to run a feature' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'plan[_-]?required' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'required[_-]?plan' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'plan[_-]?level' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'subscription[_-]?required' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'requires[_-]?subscription' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'trial[_-]?(?:expired|expires|days|ends?)' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
	'is a licensing or paywall framework, whose whole purpose inside a plugin is to gate features behind a payment' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'freemius' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'fs_dynamic_init' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'edd_sl_\w*' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'edd_software_licensing' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'wc_am_\w*' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'wc_api_manager' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
	'conditions a feature on something the merchant has to buy, which is the paywall of the licence family wearing another noun' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'subscription[_-]?(?:status|state|active|tier|level|expired|expires)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'entitlement\w*' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'paid[_-]?(?:plan|plans|tier|tiers|feature|features)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'plan[_-]?(?:allows?|allowed|gate|gates|gated|check|status|active|tier)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'requires?[\s_-](?:an?[\s_-]+)?(?:paid[\s_-]+)?plan' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'payment[\s_-]?required' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
	'is a sentence that tells the reader a feature is being withheld until they pay' => array(
		FACTUAREA_WC_PAYWALL_WORD_START . 'upgrade\s+to\s+(?:the\s+)?(?:a\s+)?(?:pro|premium|paid)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'upgrade\s+your\s+plan' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'requires?\s+(?:an?\s+)?(?:paid|premium|pro)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'requires?\s+(?:a\s+)?(?:valid\s+)?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'purchase\s+(?:a\s+)?licen[cs]e' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'paid\s+plan' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'pro\s+plan' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'premium\s+(?:feature|plan|version)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'unlock\s+(?:this|the|all|more|full)' . FACTUAREA_WC_PAYWALL_WORD_END,
		FACTUAREA_WC_PAYWALL_WORD_START . 'available\s+(?:only\s+)?(?:in|with)\s+(?:the\s+)?(?:pro|premium|paid)' . FACTUAREA_WC_PAYWALL_WORD_END,
	),
);

/**
 * Findings that are legitimate, keyed by the reason and listed by their key,
 * which is `path:line [matched text]`.
 *
 * EMPTY TODAY, and that is a measured state rather than an empty stub: the
 * vocabulary was run against every source of this repository on 2026-09-02 and
 * matched nothing, which is what the word boundaries and the tokeniser were
 * chosen for. The examples that would have needed an entry under a looser
 * design - the word `License` in the GPL header of the plugin file, the file
 * named `LICENSE`, the word `plan` in a docblock about the retry schedule - are
 * not findings at all here, because the header and the docblocks are comments
 * and `LICENSE` is not a pattern.
 *
 * The list is enforced in BOTH directions. A finding that is not listed fails
 * the gate; an entry that no longer matches anything fails it too, naming the
 * entry, so an allowlist cannot quietly outlive the thing it excused. Moving a
 * real paywall in here is the exact move this gate exists to prevent: the
 * commercial boundary is the service, so there is no legitimate reason for the
 * plugin to consult a licence before it works.
 *
 * @var array<string, list<string>>
 */
const FACTUAREA_WC_PAYWALL_ALLOWLIST = array();

/**
 * The licence file the artefact must carry, relative to the repository root.
 */
const FACTUAREA_WC_PAYWALL_LICENCE_FILE = 'LICENSE';

/**
 * Markers that together mean "this is the whole GPL version 2 text", keyed by
 * what each one proves.
 *
 * More than one marker because each catches a different way of being wrong. The
 * title alone appears in any file that mentions the licence; the version line
 * is what tells version 2 from version 3, which is a different licence with a
 * different obligation; and the two ends of the terms are what a truncated
 * copy loses. A truncated licence is worse than a missing one, because it
 * passes a glance.
 *
 * @var array<string, string>
 */
const FACTUAREA_WC_PAYWALL_LICENCE_MARKERS = array(
	'the title of the licence' => '/^\s*GNU GENERAL PUBLIC LICENSE\s*$/m',
	'the version line, which is what tells version 2 from version 3' => '/^\s*Version 2, June 1991\s*$/m',
	'the opening of the terms, absent from a summary or a link to one' => '/TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION/',
	'the disclaimer, whose removal changes the licence into another one' => '/NO WARRANTY/',
	'the closing of the terms, absent from a copy that was cut short' => '/END OF TERMS AND CONDITIONS/',
);

/**
 * The plugin entry point, whose header declares the licence to WordPress.
 */
const FACTUAREA_WC_PAYWALL_PLUGIN_FILE = 'factuarea-for-woocommerce.php';

/**
 * The public directory listing, relative to the repository root.
 *
 * Declared beside the licence and the entry point rather than written inline
 * in the packaging list, because the three of them are the answer to the same
 * question - what travels inside the artefact - and a name repeated in two
 * places is a name that ends up disagreeing with itself.
 */
const FACTUAREA_WC_PAYWALL_LISTING_FILE = 'readme.txt';

/**
 * Spellings of "GPL version 2 or later" the plugin header may use.
 *
 * Enumerated rather than reduced to one, because the directory accepts all of
 * them and a gate that failed a correct plugin over a spelling would be a gate
 * somebody removes. What it does refuse is a DIFFERENT licence, which is the
 * thing that would contradict the text shipped beside it.
 *
 * @var list<string>
 */
const FACTUAREA_WC_PAYWALL_HEADER_LICENCES = array(
	'GPL-2.0-or-later',
	'GPL-2.0+',
	'GPLv2 or later',
	'GPL v2 or later',
	'GNU General Public License v2 or later',
);

/**
 * The licence identifier the composer manifest must declare.
 *
 * Exactly one spelling here, unlike the header: this field is a machine-read
 * SPDX identifier, composer validates it as such, and every tool downstream
 * compares it as a string.
 */
const FACTUAREA_WC_PAYWALL_MANIFEST_LICENCE = 'GPL-2.0-or-later';

/**
 * The address the plugin header must point at for the licence.
 */
const FACTUAREA_WC_PAYWALL_LICENCE_URI = 'https://www.gnu.org/licenses/gpl-2.0.html';

/**
 * The list of what the artefact must not carry, keyed by the reason. A path
 * ending in a solidus is a directory and covers everything under it.
 *
 * @var array<string, list<string>>
 */
const FACTUAREA_WC_PAYWALL_ARTEFACT_FORBIDDEN = array(
	'exists to verify the plugin rather than to run it, and shipping it puts test doubles and fixtures on a merchant site' => array(
		'tests/',
	),
	'exists to verify the plugin rather than to run it, and one of the things under it is this gate' => array(
		'scripts/',
	),
	'is development documentation, written for whoever maintains the plugin and not for the merchant who installs it' => array(
		'docs/',
	),
	'is developer tooling, which means nothing on a merchant site and tells a reader the artefact was packed from a working copy' => array(
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'phpunit.xml.dist',
		'phpcs.xml.dist',
		'playwright.config.js',
		'.editorconfig',
		'.distignore',
		'.wp-env.json',
	),
	'is an installed dependency tree, and a second copy of somebody else library inside a WordPress process is the classic collision this plugin refuses to cause' => array(
		'vendor/',
		'node_modules/',
	),
);

/**
 * The list of what the artefact must carry, keyed by the reason.
 *
 * @var array<string, list<string>>
 */
const FACTUAREA_WC_PAYWALL_ARTEFACT_REQUIRED = array(
	'is the file WordPress reads to know a plugin is there; without it the artefact installs nothing' => array(
		FACTUAREA_WC_PAYWALL_PLUGIN_FILE,
	),
	'is the licence text the directory requires to travel with the plugin' => array(
		FACTUAREA_WC_PAYWALL_LICENCE_FILE,
	),
	'is the plugin itself' => array(
		'src/',
	),
	'is the directory listing, which is the page every merchant reads before installing; an artefact without it installs and runs, and the directory shows the plugin with no description at all' => array(
		FACTUAREA_WC_PAYWALL_LISTING_FILE,
	),
);

/**
 * Where a packaged artefact is looked for, relative to the repository root.
 */
const FACTUAREA_WC_PAYWALL_ARTEFACT_DIRECTORY = 'dist/factuarea-for-woocommerce';

/**
 * The published artefact, when it has been built.
 */
const FACTUAREA_WC_PAYWALL_ARTEFACT_ZIP = 'dist/factuarea-for-woocommerce.zip';

/**
 * The file that says what never travels inside the artefact.
 */
const FACTUAREA_WC_PAYWALL_DISTIGNORE = '.distignore';

/**
 * Paths the packaging script excludes on top of the ignore file, so that a
 * listing derived here describes what `composer package` actually produces.
 *
 * @var list<string>
 */
const FACTUAREA_WC_PAYWALL_EXTRA_EXCLUDES = array( '.git', 'dist' );

/**
 * How much of an offending source line is quoted back. Long enough to read the
 * condition, short enough not to bury the report under one minified line.
 */
const FACTUAREA_WC_PAYWALL_LINE_EXCERPT = 160;

/**
 * Write one line to standard output.
 *
 * Standard output rather than `echo` because this file is inspected by the
 * WordPress coding standard, whose output-escaping rule is written for pages.
 * There is no page here, and escaping a report for HTML would make it
 * unreadable in the terminal it is read in.
 *
 * @param string $line Line to write, without its line break.
 * @return void
 */
function factuarea_wc_paywall_say( string $line ): void {
	fwrite( STDOUT, $line . PHP_EOL );
}

/**
 * Write one line to standard error.
 *
 * @param string $line Line to write, without its line break.
 * @return void
 */
function factuarea_wc_paywall_warn( string $line ): void {
	fwrite( STDERR, $line . PHP_EOL );
}

/**
 * Read a file, or return null when it cannot be read.
 *
 * The null means "I could not see it" and never "it is fine": every caller
 * turns it into a finding or into a named skip, and none of them into silence.
 *
 * @param string $path Absolute path.
 * @return string|null Contents, or null when the file is missing or unreadable.
 */
function factuarea_wc_paywall_read( string $path ): ?string {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return null;
	}

	$contents = file_get_contents( $path );

	return false === $contents ? null : $contents;
}

/**
 * Every file under a directory, as paths relative to it, depth first.
 *
 * The pruning patterns are matched against each entry NAME, which is what rsync
 * does with a pattern that carries no path separator, and a pruned directory is
 * not descended into. Pruning during the walk rather than filtering afterwards
 * is what keeps this off `vendor/` and `node_modules/`, two trees that are tens
 * of thousands of files and are excluded from the artefact anyway.
 *
 * @param string   $directory Absolute path of the directory.
 * @param string[] $prune   Patterns whose matches are not collected.
 * @return list<string> Relative paths, sorted.
 */
function factuarea_wc_paywall_files_under( string $directory, array $prune = array() ): array {
	if ( ! is_dir( $directory ) ) {
		return array();
	}

	$found   = array();
	$pending = array( '' );

	while ( array() !== $pending ) {
		$relative = array_pop( $pending );
		$absolute = '' === $relative ? $directory : $directory . '/' . $relative;
		$entries  = scandir( $absolute );

		if ( false === $entries ) {
			continue;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || factuarea_wc_paywall_is_pruned( $entry, $prune ) ) {
				continue;
			}

			$child = '' === $relative ? $entry : $relative . '/' . $entry;

			if ( is_dir( $absolute . '/' . $entry ) ) {
				$pending[] = $child;
				continue;
			}

			$found[] = $child;
		}
	}

	sort( $found );

	return $found;
}

/**
 * Whether one path segment is covered by any pruning pattern.
 *
 * @param string   $segment  One name of a path.
 * @param string[] $patterns Patterns, without path separators.
 * @return bool True when the segment never reaches the artefact.
 */
function factuarea_wc_paywall_is_pruned( string $segment, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( fnmatch( $pattern, $segment ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The key an allowlist entry is written with: `path:line [matched text]`.
 *
 * The matched text is part of the key because one line can carry more than one
 * match; without it two findings on the same line would share a key and
 * excusing one would silently excuse the other.
 *
 * @param string $path    Path relative to the repository root.
 * @param int    $line    Line number, counting from one.
 * @param string $matched The text the vocabulary matched.
 * @return string The key.
 */
function factuarea_wc_paywall_key( string $path, int $line, string $matched ): string {
	return sprintf( '%s:%d [%s]', $path, $line, $matched );
}

/**
 * Runs the paywall vocabulary over one PHP file.
 *
 * The file is tokenised and every comment is dropped, so documentation cannot
 * fire; everything else - identifiers, string literals, inline markup - is
 * matched. See the note at the top of this file for why that split is the
 * whole design and not an optimisation.
 *
 * @param string $absolute Absolute path of the file.
 * @param string $relative Path relative to the repository root, for reporting.
 * @return list<array{key: string, path: string, line: int, message: string}> Findings.
 */
function factuarea_wc_paywall_scan_file( string $absolute, string $relative ): array {
	$source = factuarea_wc_paywall_read( $absolute );

	if ( null === $source ) {
		return array(
			array(
				'key'     => factuarea_wc_paywall_key( $relative, 0, 'unreadable' ),
				'path'    => $relative,
				'line'    => 0,
				'message' => 'could not be read, so it was not scanned. An unread file is not a clean file.',
			),
		);
	}

	$lines    = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $source ) );
	$findings = array();

	foreach ( token_get_all( $source ) as $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			continue;
		}

		foreach ( FACTUAREA_WC_PAYWALL_PATTERNS as $reason => $patterns ) {
			foreach ( $patterns as $pattern ) {
				$count = preg_match_all( $pattern, $token[1], $matches, PREG_OFFSET_CAPTURE );

				/*
				 * A pattern that does not COMPILE is not a pattern that found
				 * nothing, and treating the two the same is how this gate would
				 * lie. MEASURED on 2026-09-18 while the vocabulary was being
				 * widened: two new patterns carried an invalid range inside a
				 * character class, the engine returned false on every token,
				 * and the gate reported the paywalled fixture as carrying four
				 * fewer offences than it does - with no exit code to show for
				 * it. A rule that switched itself off in silence is the exact
				 * failure the rest of this file is built against, so the
				 * failure is reported as a finding of its own, which fails the
				 * run and names the rule that stopped running.
				 */
				if ( false === $count ) {
					$findings[] = array(
						'key'     => factuarea_wc_paywall_key( $relative, $token[2], 'uncompilable pattern' ),
						'path'    => $relative,
						'line'    => $token[2],
						'message' => sprintf(
							'was not scanned for one rule of the vocabulary: the pattern %s could not be compiled, so it matched nothing anywhere. A rule that does not run is not a rule that found nothing. Fix the pattern under "%s".',
							$pattern,
							$reason
						),
					);

					continue;
				}

				if ( 0 === $count ) {
					continue;
				}

				foreach ( $matches[0] as $match ) {
					$line    = $token[2] + substr_count( substr( $token[1], 0, (int) $match[1] ), "\n" );
					$excerpt = trim( $lines[ $line - 1 ] ?? '' );
					$excerpt = strlen( $excerpt ) > FACTUAREA_WC_PAYWALL_LINE_EXCERPT
						? substr( $excerpt, 0, FACTUAREA_WC_PAYWALL_LINE_EXCERPT ) . '...'
						: $excerpt;
					$matched = (string) $match[0];

					$findings[] = array(
						'key'     => factuarea_wc_paywall_key( $relative, $line, $matched ),
						'path'    => $relative,
						'line'    => $line,
						'message' => sprintf( '%s. Found "%s" in: %s', $reason, $matched, $excerpt ),
					);
				}
			}
		}
	}

	return $findings;
}

/**
 * Every PHP file the paywall lens reads, under one path of the repository.
 *
 * @param string $root     Absolute path of the repository root.
 * @param string $relative Path relative to the root, file or directory.
 * @return list<string> Paths relative to the root.
 */
function factuarea_wc_paywall_php_files( string $root, string $relative ): array {
	$absolute = $root . '/' . $relative;

	if ( is_file( $absolute ) ) {
		return 'php' === strtolower( (string) pathinfo( $absolute, PATHINFO_EXTENSION ) ) ? array( $relative ) : array();
	}

	$files = array();

	foreach ( factuarea_wc_paywall_files_under( $absolute ) as $child ) {
		if ( 'php' !== strtolower( (string) pathinfo( $child, PATHINFO_EXTENSION ) ) ) {
			continue;
		}

		$files[] = $relative . '/' . $child;
	}

	return $files;
}

/**
 * The set of files the vocabulary declaration lives in, flattened.
 *
 * @return list<string> Paths relative to the repository root.
 */
function factuarea_wc_paywall_excluded_files(): array {
	$excluded = array();

	foreach ( FACTUAREA_WC_PAYWALL_VOCABULARY_FILES as $paths ) {
		foreach ( $paths as $path ) {
			$excluded[] = $path;
		}
	}

	return $excluded;
}

/**
 * Runs the paywall lens over the whole repository.
 *
 * @param string $root Absolute path of the repository root.
 * @return array{findings: list<array{key: string, path: string, line: int, message: string}>, skipped: list<string>, scanned: int}
 */
function factuarea_wc_paywall_scan_repository( string $root ): array {
	$findings = array();
	$skipped  = array();
	$excluded = factuarea_wc_paywall_excluded_files();
	$scanned  = 0;

	foreach ( FACTUAREA_WC_PAYWALL_SCANNED_PATHS as $relative ) {
		if ( ! file_exists( $root . '/' . $relative ) ) {
			$skipped[] = sprintf(
				'the paywall lens could not read "%s": it does not exist yet, so nothing under it was scanned.',
				$relative
			);
			continue;
		}

		foreach ( factuarea_wc_paywall_php_files( $root, $relative ) as $file ) {
			if ( in_array( $file, $excluded, true ) ) {
				continue;
			}

			++$scanned;
			$findings = array_merge( $findings, factuarea_wc_paywall_scan_file( $root . '/' . $file, $file ) );
		}
	}

	return array(
		'findings' => $findings,
		'skipped'  => $skipped,
		'scanned'  => $scanned,
	);
}

/**
 * Applies the allowlist to a list of findings, in both directions.
 *
 * Findings are first collapsed by their key, because the key is the identity an
 * allowlist entry excuses. The same word can be matched twice on one line - once
 * as a variable and once as the option name it reads, which is what a real
 * paywall looks like - and reporting it twice while one allowlist entry silences
 * both would make the two counts disagree.
 *
 * @param list<array{key: string, path: string, line: int, message: string}> $findings Raw findings.
 * @return list<array{path: string, line: int, message: string}> What remains, plus one entry per stale allowlist key.
 */
function factuarea_wc_paywall_apply_allowlist( array $findings ): array {
	$allowed = array();

	foreach ( FACTUAREA_WC_PAYWALL_ALLOWLIST as $reason => $keys ) {
		foreach ( $keys as $key ) {
			$allowed[ $key ] = $reason;
		}
	}

	$unique = array();

	foreach ( $findings as $finding ) {
		$unique[ $finding['key'] ] = $finding;
	}

	$remaining = array();
	$matched   = array();

	foreach ( $unique as $key => $finding ) {
		if ( array_key_exists( $key, $allowed ) ) {
			$matched[ $key ] = true;
			continue;
		}

		$remaining[] = array(
			'path'    => $finding['path'],
			'line'    => $finding['line'],
			'message' => $finding['message'],
		);
	}

	foreach ( $allowed as $key => $reason ) {
		if ( array_key_exists( $key, $matched ) ) {
			continue;
		}

		$remaining[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_GATE_FILE,
			'line'    => 0,
			'message' => sprintf(
				'the allowlist entry "%s" ("%s") no longer matches anything. Remove it: an excuse that outlives what it excused is how an allowlist turns into a rubber stamp.',
				$key,
				$reason
			),
		);
	}

	return $remaining;
}

/**
 * Checks the licence file, and what the plugin header and the manifest declare
 * about it.
 *
 * @param string $root Absolute path of the repository root.
 * @return array{problems: list<array{path: string, line: int, message: string}>, skipped: list<string>}
 */
function factuarea_wc_paywall_check_licence( string $root ): array {
	$problems = array();
	$skipped  = array();

	$licence = factuarea_wc_paywall_read( $root . '/' . FACTUAREA_WC_PAYWALL_LICENCE_FILE );

	if ( null === $licence ) {
		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_LICENCE_FILE,
			'line'    => 0,
			'message' => 'is missing or unreadable. The plugin directory requires the licence text to ship with the plugin, and every other check about the licence has nothing to compare against.',
		);

		return array(
			'problems' => $problems,
			'skipped'  => $skipped,
		);
	}

	foreach ( FACTUAREA_WC_PAYWALL_LICENCE_MARKERS as $proves => $marker ) {
		if ( 1 === preg_match( $marker, $licence ) ) {
			continue;
		}

		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_LICENCE_FILE,
			'line'    => 0,
			'message' => sprintf( 'does not carry %s, so it is not the whole GPL version 2 text. A truncated licence is worse than a missing one: it passes a glance.', $proves ),
		);
	}

	$manifest_problems = factuarea_wc_paywall_check_manifest_licence( $root );
	$problems          = array_merge( $problems, $manifest_problems );

	$plugin_file = $root . '/' . FACTUAREA_WC_PAYWALL_PLUGIN_FILE;
	$header      = factuarea_wc_paywall_read( $plugin_file );

	if ( null === $header ) {
		$skipped[] = sprintf(
			'the licence the plugin header declares was not compared against "%s": "%s" does not exist yet, so there is no header to read.',
			FACTUAREA_WC_PAYWALL_LICENCE_FILE,
			FACTUAREA_WC_PAYWALL_PLUGIN_FILE
		);

		return array(
			'problems' => $problems,
			'skipped'  => $skipped,
		);
	}

	$declared = factuarea_wc_paywall_header_value( $header, 'License' );
	$uri      = factuarea_wc_paywall_header_value( $header, 'License URI' );

	if ( null === $declared ) {
		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_PLUGIN_FILE,
			'line'    => 0,
			'message' => 'declares no "License" header. WordPress reads that header to know what the plugin is licensed under, and the directory refuses a plugin without it.',
		);
	} elseif ( ! in_array( $declared, FACTUAREA_WC_PAYWALL_HEADER_LICENCES, true ) ) {
		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_PLUGIN_FILE,
			'line'    => 0,
			'message' => sprintf(
				'declares "License: %s", which is not one of the accepted spellings of the licence shipped in %s (%s). The header and the text have to say the same thing, or users are told one licence and given another.',
				$declared,
				FACTUAREA_WC_PAYWALL_LICENCE_FILE,
				implode( ', ', FACTUAREA_WC_PAYWALL_HEADER_LICENCES )
			),
		);
	}

	if ( null === $uri ) {
		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_PLUGIN_FILE,
			'line'    => 0,
			'message' => 'declares no "License URI" header, so the header names a licence without pointing at its text.',
		);
	} elseif ( FACTUAREA_WC_PAYWALL_LICENCE_URI !== $uri ) {
		$problems[] = array(
			'path'    => FACTUAREA_WC_PAYWALL_PLUGIN_FILE,
			'line'    => 0,
			'message' => sprintf( 'declares "License URI: %s"; it has to be %s, which is where the shipped text comes from.', $uri, FACTUAREA_WC_PAYWALL_LICENCE_URI ),
		);
	}

	return array(
		'problems' => $problems,
		'skipped'  => $skipped,
	);
}

/**
 * The licence the composer manifest declares.
 *
 * @param string $root Absolute path of the repository root.
 * @return list<array{path: string, line: int, message: string}> Problems.
 */
function factuarea_wc_paywall_check_manifest_licence( string $root ): array {
	$manifest = factuarea_wc_paywall_manifest( $root );

	if ( null === $manifest ) {
		return array(
			array(
				'path'    => 'composer.json',
				'line'    => 0,
				'message' => 'is missing or is not valid JSON, so the licence it declares cannot be compared with the text that ships.',
			),
		);
	}

	$declared = $manifest['license'] ?? null;

	if ( FACTUAREA_WC_PAYWALL_MANIFEST_LICENCE === $declared ) {
		return array();
	}

	return array(
		array(
			'path'    => 'composer.json',
			'line'    => 0,
			'message' => sprintf(
				'declares "license": %s; it has to be "%s", the SPDX identifier of the text shipped in %s.',
				null === $declared ? 'nothing' : factuarea_wc_paywall_render( $declared ),
				FACTUAREA_WC_PAYWALL_MANIFEST_LICENCE,
				FACTUAREA_WC_PAYWALL_LICENCE_FILE
			),
		),
	);
}

/**
 * A value rendered into a message, without depending on WordPress.
 *
 * @param mixed $value Value to render.
 * @return string The value as JSON, or its type when it cannot be encoded.
 */
function factuarea_wc_paywall_render( $value ): string {
	$encoded = json_encode( $value );

	return false === $encoded ? gettype( $value ) : $encoded;
}

/**
 * The composer manifest, decoded.
 *
 * @param string $root Absolute path of the repository root.
 * @return array<string, mixed>|null The manifest, or null when it cannot be read.
 */
function factuarea_wc_paywall_manifest( string $root ): ?array {
	$raw = factuarea_wc_paywall_read( $root . '/composer.json' );

	if ( null === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : null;
}

/**
 * One value of the WordPress plugin header block.
 *
 * The header is a comment, which is precisely why it is read from the raw text
 * here instead of from the tokens: the paywall lens drops comments, and this
 * check needs the one comment that carries a contract.
 *
 * @param string $source Contents of the plugin file.
 * @param string $name   Header name, such as `License`.
 * @return string|null The value, trimmed, or null when the header is absent.
 */
function factuarea_wc_paywall_header_value( string $source, string $name ): ?string {
	$pattern = '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . '\s*:\s*(.+)$/mi';

	if ( 1 !== preg_match( $pattern, $source, $matches ) ) {
		return null;
	}

	return trim( $matches[1] );
}

/**
 * The listing of the artefact that would be published.
 *
 * Three ways of getting it, in order of how much they prove. A packed tree or a
 * built archive is what actually ships and is preferred; the derivation from
 * the ignore file is the fallback, and it says so in the report, because a
 * derivation describes what SHOULD ship rather than what did.
 *
 * @param string $root Absolute path of the repository root.
 * @return array{files: list<string>, origin: string, problems: list<array{path: string, line: int, message: string}>, skipped: list<string>}
 */
function factuarea_wc_paywall_artefact_listing( string $root ): array {
	$directory = $root . '/' . FACTUAREA_WC_PAYWALL_ARTEFACT_DIRECTORY;

	if ( is_dir( $directory ) ) {
		return array(
			'files'    => factuarea_wc_paywall_files_under( $directory ),
			'origin'   => 'the packaged tree at ' . FACTUAREA_WC_PAYWALL_ARTEFACT_DIRECTORY,
			'problems' => array(),
			'skipped'  => array(),
		);
	}

	$archive = $root . '/' . FACTUAREA_WC_PAYWALL_ARTEFACT_ZIP;

	if ( ! is_file( $archive ) ) {
		return factuarea_wc_paywall_derived_listing( $root, array() );
	}

	if ( class_exists( 'ZipArchive' ) ) {
		return factuarea_wc_paywall_zip_listing( $archive );
	}

	// The artefact IS here and could not be opened. Falling back to the
	// derivation without saying so would report "no packaged artefact was
	// found" over a repository holding one, which is a report that reads as
	// stronger than it is: the derivation describes what SHOULD ship, and the
	// question the archive answers is what DID.
	return factuarea_wc_paywall_derived_listing(
		$root,
		array(
			sprintf(
				'the artefact at %s was not opened: this PHP has no zip extension, so what actually travels inside it was not read and the listing below is derived from %s instead.',
				FACTUAREA_WC_PAYWALL_ARTEFACT_ZIP,
				FACTUAREA_WC_PAYWALL_DISTIGNORE
			),
		)
	);
}

/**
 * The listing of a built archive.
 *
 * @param string $archive Absolute path of the archive.
 * @return array{files: list<string>, origin: string, problems: list<array{path: string, line: int, message: string}>, skipped: list<string>}
 */
function factuarea_wc_paywall_zip_listing( string $archive ): array {
	$zip    = new ZipArchive();
	$origin = 'the archive at ' . FACTUAREA_WC_PAYWALL_ARTEFACT_ZIP;

	if ( true !== $zip->open( $archive ) ) {
		return array(
			'files'    => array(),
			'origin'   => $origin,
			'problems' => array(
				array(
					'path'    => FACTUAREA_WC_PAYWALL_ARTEFACT_ZIP,
					'line'    => 0,
					'message' => 'exists but could not be opened, so what it carries was not audited.',
				),
			),
			'skipped'  => array(),
		);
	}

	$files = array();

	$entries = $zip->count();

	for ( $index = 0; $index < $entries; $index++ ) {
		$name = $zip->getNameIndex( $index );

		if ( false === $name || str_ends_with( $name, '/' ) ) {
			continue;
		}

		// The packaging script lays the artefact out under one directory named
		// after the plugin, exactly as the directory expects to receive it.
		$files[] = preg_replace( '#^[^/]+/#', '', $name );
	}

	$zip->close();
	sort( $files );

	return array(
		'files'    => $files,
		'origin'   => $origin,
		'problems' => array(),
		'skipped'  => array(),
	);
}

/**
 * The listing the packaging script would produce, derived from the ignore file.
 *
 * @param string   $root    Absolute path of the repository root.
 * @param string[] $skipped Skips the caller already knows about, carried through.
 * @return array{files: list<string>, origin: string, problems: list<array{path: string, line: int, message: string}>, skipped: list<string>}
 */
function factuarea_wc_paywall_derived_listing( string $root, array $skipped ): array {
	$origin   = 'a listing derived from ' . FACTUAREA_WC_PAYWALL_DISTIGNORE;
	$problems = array();
	$raw      = factuarea_wc_paywall_read( $root . '/' . FACTUAREA_WC_PAYWALL_DISTIGNORE );

	if ( null === $raw ) {
		return array(
			'files'    => array(),
			'origin'   => $origin,
			'problems' => array(
				array(
					'path'    => FACTUAREA_WC_PAYWALL_DISTIGNORE,
					'line'    => 0,
					'message' => 'is missing or unreadable, and it is what decides what travels inside the artefact. Nothing about the packaging could be checked.',
				),
			),
			'skipped'  => $skipped,
		);
	}

	$patterns = FACTUAREA_WC_PAYWALL_EXTRA_EXCLUDES;

	foreach ( explode( "\n", $raw ) as $number => $line ) {
		$pattern = trim( $line );

		if ( '' === $pattern || str_starts_with( $pattern, '#' ) ) {
			continue;
		}

		if ( str_contains( $pattern, '/' ) ) {
			$problems[] = array(
				'path'    => FACTUAREA_WC_PAYWALL_DISTIGNORE,
				'line'    => $number + 1,
				'message' => sprintf(
					'declares the pattern "%s", which contains a path separator. This gate replicates the ignore file by matching each pattern against every segment of a path, which is what the current patterns need and nothing more. Either simplify the pattern or teach the replication about it: a replication that silently mis-models the packaging would report on an artefact that is not the one published.',
					$pattern
				),
			);
			continue;
		}

		$patterns[] = $pattern;
	}

	$problems = array_merge( $problems, factuarea_wc_paywall_check_packaging_script( $root ) );

	return array(
		'files'    => factuarea_wc_paywall_files_under( $root, $patterns ),
		'origin'   => $origin,
		'problems' => $problems,
		'skipped'  => $skipped,
	);
}

/**
 * The packaging script still honours the ignore file this gate derives from.
 *
 * Without this the derivation could describe a packaging that no longer exists:
 * somebody replaces the exclusion list with a hand-written file list, the gate
 * keeps deriving from the ignore file, and it audits an artefact nobody builds.
 *
 * @param string $root Absolute path of the repository root.
 * @return list<array{path: string, line: int, message: string}> Problems.
 */
function factuarea_wc_paywall_check_packaging_script( string $root ): array {
	$manifest = factuarea_wc_paywall_manifest( $root );
	$script   = $manifest['scripts']['package'] ?? null;

	if ( ! is_string( $script ) ) {
		return array(
			array(
				'path'    => 'composer.json',
				'line'    => 0,
				'message' => 'declares no "package" script, so there is no packaging for this gate to describe.',
			),
		);
	}

	if ( str_contains( $script, '--exclude-from=' . FACTUAREA_WC_PAYWALL_DISTIGNORE ) ) {
		return array();
	}

	return array(
		array(
			'path'    => 'composer.json',
			'line'    => 0,
			'message' => sprintf(
				'the "package" script no longer excludes from %s, so the listing this gate derives from that file no longer describes what is published.',
				FACTUAREA_WC_PAYWALL_DISTIGNORE
			),
		),
	);
}

/**
 * Audits what the artefact carries and what it does not.
 *
 * @param string $root Absolute path of the repository root.
 * @return array{problems: list<array{path: string, line: int, message: string}>, skipped: list<string>, origin: string, files: int}
 */
function factuarea_wc_paywall_check_packaging( string $root ): array {
	$listing  = factuarea_wc_paywall_artefact_listing( $root );
	$files    = $listing['files'];
	$problems = $listing['problems'];
	$skipped  = $listing['skipped'];

	foreach ( FACTUAREA_WC_PAYWALL_ARTEFACT_FORBIDDEN as $reason => $paths ) {
		foreach ( $paths as $path ) {
			foreach ( factuarea_wc_paywall_matching( $files, $path ) as $carried ) {
				$problems[] = array(
					'path'    => $carried,
					'line'    => 0,
					'message' => sprintf( 'travels inside the artefact and must not: "%s" %s.', $path, $reason ),
				);
			}
		}
	}

	foreach ( FACTUAREA_WC_PAYWALL_ARTEFACT_REQUIRED as $reason => $paths ) {
		foreach ( $paths as $path ) {
			if ( array() !== factuarea_wc_paywall_matching( $files, $path ) ) {
				continue;
			}

			if ( ! file_exists( $root . '/' . rtrim( $path, '/' ) ) ) {
				$skipped[] = sprintf(
					'the artefact was not checked for "%s": it does not exist in the repository yet, so its absence from the artefact says nothing.',
					$path
				);
				continue;
			}

			$problems[] = array(
				'path'    => $path,
				'line'    => 0,
				'message' => sprintf( 'does not travel inside the artefact and must: it %s.', $reason ),
			);
		}
	}

	$problems = array_merge( $problems, factuarea_wc_paywall_check_runtime_dependencies( $root ) );

	return array(
		'problems' => $problems,
		'skipped'  => $skipped,
		'origin'   => $listing['origin'],
		'files'    => count( $files ),
	);
}

/**
 * Every artefact path covered by one entry of the packaging lists.
 *
 * @param string[] $files Artefact paths.
 * @param string   $path  Entry; a trailing solidus makes it a directory.
 * @return list<string> The paths it covers.
 */
function factuarea_wc_paywall_matching( array $files, string $path ): array {
	if ( str_ends_with( $path, '/' ) ) {
		return array_values(
			array_filter(
				$files,
				static fn( string $file ): bool => str_starts_with( $file, $path )
			)
		);
	}

	return in_array( $path, $files, true ) ? array( $path ) : array();
}

/**
 * The plugin declares no runtime dependency, which is what makes "the artefact
 * carries no vendor tree" a DERIVED expectation rather than an assumed one.
 *
 * The two halves are deliberately in different places. That `vendor/` never
 * travels is a flat rule of the packaging list above, reported once against the
 * path that travelled; WHY it is a flat rule is this check, reported against the
 * manifest. Sweeping the artefact for `vendor/` here as well would report the
 * same file twice under two reasons, which reads as two problems and is one.
 *
 * If a runtime dependency ever appears, this says so instead of quietly
 * continuing to demand an empty tree over a plugin that now needs one - and the
 * finding lands on the dependency, because bundling somebody else's library
 * inside a WordPress process is the collision this package refused by design.
 *
 * @param string $root Absolute path of the repository root.
 * @return list<array{path: string, line: int, message: string}> Problems.
 */
function factuarea_wc_paywall_check_runtime_dependencies( string $root ): array {
	$manifest = factuarea_wc_paywall_manifest( $root );

	if ( null === $manifest ) {
		return array();
	}

	$required = $manifest['require'] ?? array();

	if ( ! is_array( $required ) ) {
		return array(
			array(
				'path'    => 'composer.json',
				'line'    => 0,
				'message' => '"require" is not an object, so what the plugin needs at runtime cannot be read.',
			),
		);
	}

	$problems = array();

	foreach ( array_keys( $required ) as $package ) {
		if ( 'php' === $package || str_starts_with( (string) $package, 'ext-' ) ) {
			continue;
		}

		$problems[] = array(
			'path'    => 'composer.json',
			'line'    => 0,
			'message' => sprintf(
				'declares the runtime dependency "%s". This plugin ships with none on purpose: a second copy of somebody else library inside a WordPress process collides with whichever copy loaded first, and the one that wins is not the one that was tested. Move it to "require-dev" or drop it.',
				$package
			),
		);
	}

	return $problems;
}

/**
 * Prints the report and returns the exit code.
 *
 * @param list<array{path: string, line: int, message: string}> $problems Findings.
 * @param string[]                                              $skipped  Named skips.
 * @param string                                                $summary  What was audited, in one line.
 * @return int Exit code.
 */
function factuarea_wc_paywall_report( array $problems, array $skipped, string $summary ): int {
	if ( array() !== $problems ) {
		$lines = array_map(
			static fn( array $problem ): string => sprintf(
				'  %s: %s',
				0 === $problem['line'] ? $problem['path'] : $problem['path'] . ':' . $problem['line'],
				$problem['message']
			),
			$problems
		);

		sort( $lines );

		factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: %d problem(s). %s', count( $problems ), $summary ) );
		factuarea_wc_paywall_warn( '' );

		foreach ( $lines as $line ) {
			factuarea_wc_paywall_warn( $line );
		}

		if ( array() !== $skipped ) {
			factuarea_wc_paywall_warn( '' );

			foreach ( $skipped as $skip ) {
				factuarea_wc_paywall_warn( '  SKIPPED: ' . $skip );
			}
		}

		return FACTUAREA_WC_PAYWALL_EXIT_FINDINGS;
	}

	if ( array() !== $skipped ) {
		factuarea_wc_paywall_say( sprintf( 'check-no-paywall: nothing found. %s', $summary ) );
		factuarea_wc_paywall_say( '' );

		foreach ( $skipped as $skip ) {
			factuarea_wc_paywall_say( 'SKIPPED: ' . $skip );
		}

		factuarea_wc_paywall_say( '' );
		factuarea_wc_paywall_say( sprintf( 'This is NOT a pass: exit code %d.', FACTUAREA_WC_PAYWALL_EXIT_SKIPPED ) );

		return FACTUAREA_WC_PAYWALL_EXIT_SKIPPED;
	}

	factuarea_wc_paywall_say( sprintf( 'check-no-paywall: OK. %s', $summary ) );

	return FACTUAREA_WC_PAYWALL_EXIT_SUCCESS;
}

/**
 * Runs only the paywall lens over one path.
 *
 * @param string $target Path to a file or a directory.
 * @return int Exit code.
 */
function factuarea_wc_paywall_run_scan( string $target ): int {
	$absolute = realpath( $target );

	if ( false === $absolute ) {
		factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: "%s" cannot be read.', $target ) );

		return FACTUAREA_WC_PAYWALL_EXIT_USAGE;
	}

	$files = array();

	if ( is_file( $absolute ) ) {
		$files[ $absolute ] = basename( $absolute );
	} else {
		foreach ( factuarea_wc_paywall_files_under( $absolute ) as $relative ) {
			if ( 'php' !== strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$files[ $absolute . '/' . $relative ] = $relative;
		}
	}

	if ( array() === $files ) {
		factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: "%s" holds no PHP file, so nothing was scanned. An empty scan is not a pass.', $target ) );

		return FACTUAREA_WC_PAYWALL_EXIT_USAGE;
	}

	$findings = array();

	foreach ( $files as $path => $relative ) {
		$findings = array_merge( $findings, factuarea_wc_paywall_scan_file( $path, $relative ) );
	}

	return factuarea_wc_paywall_report(
		factuarea_wc_paywall_apply_allowlist( $findings ),
		array(),
		sprintf( '%d file(s) scanned under %s, paywall lens only.', count( $files ), $target )
	);
}

/**
 * Runs the three parts over a repository.
 *
 * @param string $root Path of the repository root.
 * @return int Exit code.
 */
function factuarea_wc_paywall_run_repository( string $root ): int {
	$absolute = realpath( $root );

	if ( false === $absolute || ! is_dir( $absolute ) ) {
		factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: "%s" is not a directory.', $root ) );

		return FACTUAREA_WC_PAYWALL_EXIT_USAGE;
	}

	$scan      = factuarea_wc_paywall_scan_repository( $absolute );
	$licence   = factuarea_wc_paywall_check_licence( $absolute );
	$packaging = factuarea_wc_paywall_check_packaging( $absolute );

	$problems = array_merge(
		factuarea_wc_paywall_apply_allowlist( $scan['findings'] ),
		$licence['problems'],
		$packaging['problems']
	);

	$skipped = array_merge( $scan['skipped'], $licence['skipped'], $packaging['skipped'] );

	return factuarea_wc_paywall_report(
		$problems,
		$skipped,
		sprintf(
			'%d source file(s) scanned for a paywall, the licence checked, and %d artefact path(s) audited from %s.',
			$scan['scanned'],
			$packaging['files'],
			$packaging['origin']
		)
	);
}

/**
 * Entry point.
 *
 * @param string[] $arguments Arguments, without the script name.
 * @return int Exit code.
 */
function factuarea_wc_paywall_main( array $arguments ): int {
	$root   = dirname( __DIR__ );
	$target = null;

	while ( array() !== $arguments ) {
		$argument = array_shift( $arguments );

		if ( '--root' === $argument || '--scan' === $argument ) {
			$value = array_shift( $arguments );

			if ( null === $value ) {
				factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: "%s" needs a path.', $argument ) );

				return FACTUAREA_WC_PAYWALL_EXIT_USAGE;
			}

			if ( '--root' === $argument ) {
				$root = $value;
				continue;
			}

			$target = $value;
			continue;
		}

		factuarea_wc_paywall_warn( sprintf( 'check-no-paywall: unknown argument "%s". Usage: check-no-paywall.php [--root <dir>] [--scan <path>].', $argument ) );

		return FACTUAREA_WC_PAYWALL_EXIT_USAGE;
	}

	return null === $target
		? factuarea_wc_paywall_run_repository( $root )
		: factuarea_wc_paywall_run_scan( $target );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument of exit is the process exit code here, not a message: this script writes every line it emits with fwrite and never passes a string to exit.
exit( factuarea_wc_paywall_main( array_slice( (array) ( $argv ?? array() ), 1 ) ) );
