<?php
/**
 * English-only gate: fails when a user-facing string of this package is written
 * in Spanish.
 *
 * @package Factuarea\WooCommerce
 */

/*
 * The one WordPress rule this file does not follow, with its reason.
 *
 * `WordPress.Files.FileName` asks a file that declares a class to be named
 * `class-<lowercased class name>.php`. This file cannot be: its name is the
 * frozen entry point of the `lint:english` script of `composer.json`, and the
 * header above promises the sibling repositories a file they can copy verbatim
 * and invoke by that exact name. Renaming it to satisfy a naming convention
 * would rename the command every repository runs.
 *
 * The disable is written for the whole sniff and not for its class-prefix code
 * because the sniff reports that code at position zero, before any annotation
 * exists to suppress it, and honours only a disable of the sniff, of
 * `WordPress.Files` or of `WordPress`. Its other half - the file name has to be
 * lowercase and hyphenated - IS satisfied here, and stays satisfied because the
 * name is frozen in two other places.
 *
 * It is the same trade `phpcs.xml.dist` already made for `src/*`, where PSR-4
 * requires the file to be named after the class it holds. Nothing else of the
 * WordPress standard is switched off in this file: the four inline exceptions
 * further down each name their sniff and their reason.
 */
// phpcs:disable WordPress.Files.FileName -- The name is the frozen entry point of `composer lint:english`; see the block above.

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Scripts;

/**
 * English-only gate.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Every other repository of this project writes its user-facing text in
 * Spanish, and that rule is enforced there by its own gate. This package is the
 * declared, bounded exception: it is published through the public WordPress
 * plugin directory, its reviewers read English, and its merchants are not only
 * Spanish. An exception that nobody measures becomes a licence within a month,
 * which is why the exception ships with the gate that bounds it rather than
 * with a paragraph asking people to remember.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE EXCEPTION ENDS
 * ---------------------------------------------------------------------------
 * The exception covers THREE package repositories and nothing else, because
 * three marketplaces ask for English in writing:
 *
 *   - this package, published to the WordPress plugin directory;
 *   - the public Shopify app, published to the Shopify App Store;
 *   - the n8n nodes, published to npm.
 *
 * Everything else keeps Spanish, with parity across the three locales the
 * product ships and the gates that enforce it: the backend, the web
 * application, the documentation portal, the assistant, the command line and
 * the software development kits. A package regenerated inside a synchronisation
 * wave does not change language either; that would be a product change wearing
 * the clothes of a regeneration.
 *
 * THE EXCEPTION DOES NOT TRAVEL. No string of this repository authorises a
 * single English string in the monorepo, and "the WooCommerce plugin writes it
 * this way" is not an argument there - it is the description of a boundary, on
 * the far side of it. The direction that matters is the one nobody defends:
 * this file exists to stop Spanish coming IN, and the paragraph above exists to
 * stop English going OUT. A reader who arrived here looking for a precedent has
 * found the reason there is none.
 *
 * ---------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------
 *     php scripts/check-english.php <path> [<path>...]
 *
 * Each `<path>` is a file or a directory. Directories are walked recursively,
 * skipping `vendor`, `node_modules`, `dist`, `build`, `coverage` and every
 * dot-directory.
 *
 * A path that DOES NOT EXIST is skipped with a warning on stderr and does not
 * fail the run. The composer script points this gate at `readme.txt`,
 * `README.md` and `docs`, which a later phase writes; a gate that dies on a
 * file nobody has written yet teaches everybody to stop running it. A path that
 * EXISTS and cannot be read is a different thing and is an error: "I cannot see
 * it" must never be reported as "it is clean".
 *
 * File kinds:
 *   - `.php` - string literals (single-quoted, double-quoted, heredoc and
 *     nowdoc), comments, docblocks and any markup outside the PHP tags. Names
 *     of classes, methods, variables and array keys are code, not text a person
 *     reads, and reporting them would make the gate unusable.
 *   - `.md` and `.txt` - the whole prose except code: fenced blocks and inline
 *     code spans are removed first. Both extensions share one extractor because
 *     the plugin directory listing (`readme.txt`) is prose with the same
 *     backtick convention as Markdown.
 *   - every other extension is ignored while walking. A file named explicitly
 *     on the command line whose extension is not inspected produces a warning,
 *     so that pointing the gate at something it does not read never looks like
 *     a clean result.
 *
 * Output: one `file:line: <text>` line per finding, followed by an indented
 * line naming the surface and the markers that matched.
 *
 * Exit codes:
 *   - 0 - no findings.
 *   - 1 - at least one finding.
 *   - 2 - the gate could not run as configured: no paths given, an unknown
 *     option, a path that exists and cannot be read, or a PHP build without the
 *     tokenizer.
 *
 * ---------------------------------------------------------------------------
 * WHICH SURFACES ARE COVERED, AND HOW
 * ---------------------------------------------------------------------------
 * The surfaces that escape a human review are the ones that are not on the
 * screen while the reviewer reads the diff: the literals of the translation
 * functions, the labels and help texts of the settings and checkout fields, the
 * messages of the exceptions, the administration notices, and the text of the
 * public directory listing.
 *
 * They are covered by a SUPERSET rather than by five separate detectors: every
 * string literal, every comment and all the prose is inspected, so a surface
 * cannot escape by not being recognised. Recognition is used only to NAME the
 * surface in the report, because "a Spanish label of a settings field" tells
 * the reader where to go and "a Spanish string" does not. A classifier that
 * decided WHAT to inspect would be a list of shapes, and the first user-facing
 * string written in a shape nobody enumerated would pass in silence.
 *
 * THE INBOUND HALF, MEASURED RATHER THAN ASSUMED. Four surfaces arrived with
 * it, and the sweep was checked against each one instead of being widened by
 * reflex. Three of them live under `src` and the fourth under `docs`, and both
 * are already in the scope the `lint:english` script hands this file, so the
 * sweep needed nothing:
 *
 *   - the messages of the two receivers (`src/Inbound`);
 *   - the labels of the settings section that governs them (`src/Admin`);
 *   - the fulfilment status labels shown to the buyer (`src/MyAccount`);
 *   - the inbound contract document (`docs`), which is prose and is read by
 *     the same extractor as the public directory listing.
 *
 * What DID need widening was the naming. A receiver message reaches the sender
 * in the body of a REST answer, so it is neither a translated label nor an
 * exception message; before it was recognised the gate reported it as a plain
 * string literal when it travelled in the answer body and as an exception
 * message when it travelled inside a WordPress error object, and the second
 * reading sends whoever fixes it looking for a `throw` that is not there. The
 * two shapes are recognised below, and the fixtures under
 * `tests/fixtures/english-gate/` prove it on both.
 *
 * ONE PATH PAIR THIS SCOPE DOES NOT REACH, WRITTEN DOWN SO THAT NOBODY READS A
 * GREEN RUN AS MORE THAN IT IS. `factuarea-for-woocommerce.php` and
 * `uninstall.php` are swept by the coding standard and by the no-paywall gate,
 * and are NOT in the `lint:english` scope, even though the plugin header of the
 * first is read by every merchant on the plugins screen and by the directory.
 * No surface of the inbound half lives in either, so this is not a hole this
 * file can close on its own: the scope lives in `composer.json` - see the
 * portability note at the end - and belongs to the phase that wires it.
 *
 * ---------------------------------------------------------------------------
 * KNOWN LIMITATIONS, STATED ON PURPOSE
 * ---------------------------------------------------------------------------
 * This is a SPANISH detector, not a "not English" classifier. It looks for
 * marks that are unmistakably Spanish - the two inverted opening marks, the
 * acute-accented vowels, the letter N with a tilde, and a short list of
 * function words - and it waves German or Portuguese through. That is enough,
 * because the declared risk is not "another language sneaks in": it is that
 * this exception gets used to smuggle back the one language everybody working
 * on this project writes by default.
 *
 * Two smaller limitations, so that a green run is not read as more than it is.
 * First, escape sequences are NOT decoded, so a Spanish character written as
 * `\u{...}` inside a double-quoted string is not seen. That is also what lets
 * this file declare its own character classes as escapes without reporting
 * itself, which is better than the alternative of exempting the gate from its
 * own sweep. Second, a short Spanish sentence carrying no accent and at most
 * one of the words below goes through; longer prose almost always trips
 * something.
 *
 * ---------------------------------------------------------------------------
 * PORTABILITY: THIS FILE IS MEANT TO BE COPIED
 * ---------------------------------------------------------------------------
 * The sibling package repositories of this initiative need exactly this gate.
 * This file is written to be copied into them UNCHANGED, and THE ONLY THING
 * THAT CHANGES IS THE FILE SCOPE - that is, the paths handed to it on the
 * command line, which live in the `lint:english` script of the receiving
 * `composer.json` and nowhere else.
 *
 * That is why it imports nothing from `src/`, reads no `composer.json`, assumes
 * no directory layout, needs no autoloader, uses only what a bare PHP build
 * ships with, and carries its allowlist inside itself instead of in a companion
 * file that a copy would leave behind. Writing the gate once in a shared place
 * was not an option - there is no shared place between repositories that ship
 * separately - and writing it three times would give three different rules
 * before the quarter is out.
 *
 * The sibling written in JavaScript already has its twin, at
 * `scripts/check-english-only.mjs` of the node package: same rules, same
 * allowlist shape, same exit codes.
 */
final class EnglishGate {

	/**
	 * Exit code: nothing found.
	 *
	 * @var int
	 */
	private const EXIT_OK = 0;

	/**
	 * Exit code: at least one finding.
	 *
	 * @var int
	 */
	private const EXIT_FINDINGS = 1;

	/**
	 * Exit code: the gate could not run as configured.
	 *
	 * @var int
	 */
	private const EXIT_MISCONFIGURED = 2;

	/**
	 * Extensions inspected as PHP source.
	 *
	 * @var string[]
	 */
	private const SOURCE_EXTENSIONS = array( 'php' );

	/**
	 * Extensions inspected as prose.
	 *
	 * `txt` is here for the public directory listing, which is prose with the
	 * same backtick convention as Markdown and is one of the surfaces this gate
	 * exists to cover.
	 *
	 * @var string[]
	 */
	private const PROSE_EXTENSIONS = array( 'md', 'txt' );

	/**
	 * Directories never walked into.
	 *
	 * @var string[]
	 */
	private const SKIPPED_DIRECTORIES = array( 'vendor', 'node_modules', 'dist', 'build', 'coverage' );

	/**
	 * Longest text reported for a single finding.
	 *
	 * @var int
	 */
	private const MAX_REPORTED_LENGTH = 200;

	/**
	 * The two inverted opening marks, written as escapes.
	 *
	 * They are the inverted question mark and the inverted exclamation mark. No
	 * other language this project will ever hold opens a sentence with them, so
	 * a single one is a finding on its own.
	 *
	 * Escapes rather than the characters themselves, because this file is
	 * inside the scope the gate sweeps: writing them literally would make the
	 * gate report itself on every run, and the fix for that would be to exempt
	 * the gate from its own sweep, which is the beginning of a gate that stops
	 * meaning anything.
	 *
	 * @var string
	 */
	private const SPANISH_PUNCTUATION = "\u{00BF}\u{00A1}";

	/**
	 * The five acute-accented vowels in both cases, and the letter N with a
	 * tilde in both cases. Written as escapes for the reason above.
	 *
	 * The diaeresis vowel is not here: it is Spanish, but it is also German,
	 * and no word this package needs carries it. The grave-accented vowels are
	 * not here either - they are Catalan, French and Italian, not the language
	 * this gate is aimed at.
	 *
	 * @var string
	 */
	private const SPANISH_LETTERS = "\u{00E1}\u{00E9}\u{00ED}\u{00F3}\u{00FA}\u{00C1}\u{00C9}\u{00CD}\u{00D3}\u{00DA}\u{00F1}\u{00D1}";

	/**
	 * Word boundary before a candidate word.
	 *
	 * Built by hand instead of using the shorthand, for two reasons. A hyphen
	 * counts as a word character, so the English compounds and the delivery
	 * header names do not read as the two-letter Spanish preposition inside
	 * them. And letters are matched by their Unicode property, so an accented
	 * neighbour is a word character too, which the shorthand gets wrong.
	 *
	 * @var string
	 */
	private const WORD_BOUNDARY_BEFORE = '(?<![\p{L}\p{N}_-])';

	/**
	 * Word boundary after a candidate word. See the constant above.
	 *
	 * @var string
	 */
	private const WORD_BOUNDARY_AFTER = '(?![\p{L}\p{N}_-])';

	/**
	 * Spanish words that are not also English words or identifiers.
	 *
	 * Matching one of these is evidence. The list is deliberately short: every
	 * false positive is paid as noise by every future contributor, and a noisy
	 * gate is a bypassed gate. Words that are also English - the negation, the
	 * word for a mistake, the two-letter forms that are locale codes - are not
	 * here; they are in the weak list below.
	 *
	 * The last ten are not function words but the nouns this plugin would use
	 * if somebody wrote one of its messages in Spanish. They earn their place
	 * because the failure this gate is aimed at is a message about an invoice,
	 * an order or a store, not a paragraph of literature.
	 *
	 * @var string[]
	 */
	private const STRONG_WORDS = array(
		'el',
		'la',
		'los',
		'las',
		'de',
		'del',
		'que',
		'para',
		'con',
		'por',
		'una',
		'uno',
		'se',
		'su',
		'sus',
		'este',
		'esta',
		'esto',
		'estos',
		'estas',
		'como',
		'cuando',
		'donde',
		'desde',
		'hasta',
		'pero',
		'porque',
		'sobre',
		'entre',
		'cada',
		'todos',
		'todas',
		'otro',
		'otra',
		'ninguna',
		'ninguno',
		'tiene',
		'puede',
		'debe',
		'factura',
		'pedido',
		'tienda',
		'cliente',
		'clave',
		'secreto',
		'mensaje',
		'cabecera',
		'entrega',
		'firma',
		'campo',
	);

	/**
	 * Spanish words that are ALSO ordinary English words, locale codes or
	 * common identifiers.
	 *
	 * These can add to the count but can never make it on their own, and that
	 * is the whole reason the two lists are separate. Measured against this
	 * package: the English sentence "there is no error code in the response"
	 * carries two of them, and a rule that only counted words would report it.
	 * The rule below asks for at least one word from the strong list before any
	 * of these count for anything.
	 *
	 * @var string[]
	 */
	private const WEAK_WORDS = array(
		'no',
		'es',
		'en',
		'un',
		'y',
		'al',
		'si',
		'ha',
		'son',
		'sin',
		'dos',
		'hay',
		'mas',
		'ya',
		'error',
	);

	/**
	 * How many distinct words have to match before a text is reported.
	 *
	 * One is too few: a single word from the strong list appears inside proper
	 * nouns, file paths and English prose about this project. Two, with at
	 * least one of them unmistakably Spanish, is the point where the sentence
	 * stops being a coincidence.
	 *
	 * @var int
	 */
	private const WORD_THRESHOLD = 2;

	/**
	 * The allowlist. THE REASON IS THE KEY.
	 *
	 * The shape is `array<reason, list<term>>` on purpose: an entry cannot
	 * exist without somebody writing down why, because a bare list of allowed
	 * strings becomes, within a few commits, a place to silence the gate rather
	 * than a record of decisions.
	 *
	 * IT IS ONLY FOR PROPER NOUNS AND FOR SPANISH TAX VOCABULARY THAT IS NOT
	 * TRANSLATED. Moving a real finding here - a label, a help text, an error
	 * message, a paragraph of the listing that happens to be in Spanish - is
	 * EXACTLY WHAT THIS GATE EXISTS TO PREVENT. It would keep catching new
	 * arrivals while quietly certifying the Spanish that is already shipping,
	 * which is worse than having no gate at all, because a green run would then
	 * mean something it does not mean. If a string is user-facing and Spanish,
	 * the only correct move is to write it in English.
	 *
	 * The list is short because most of the terms that people expect to find
	 * here need no entry: the names of the Spanish tax identifiers, the tax
	 * authority acronym and the name of the electronic invoicing regime carry
	 * no accent and no function word, so nothing reports them in the first
	 * place. Adding them would be dead weight that outlives its reason.
	 *
	 * A term whitens ITSELF and nothing else: the rest of the text around it is
	 * still inspected, so one legitimate proper noun is never a free pass for
	 * the Spanish sentence wrapped around it.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWLIST = array(
		'Official Spanish name of the tax authority and of the three tax identifiers. An English text NAMES them, it does not translate them: the merchant reads these words on the document the authority issued, and an invented English rendering would name an institution and three documents that do not exist. They carry the accented letters that the sweep reports, which is why they need an entry at all.' => array(
			'Agencia Estatal de Administraci' . "\u{00F3}" . 'n Tributaria',
			'N' . "\u{00FA}" . 'mero de Identificaci' . "\u{00F3}" . 'n Fiscal',
			'N' . "\u{00FA}" . 'mero de Identidad de Extranjero',
			'C' . "\u{00F3}" . 'digo de Identificaci' . "\u{00F3}" . 'n Fiscal',
		),
	);

	/**
	 * Functions whose literals are translated text by definition.
	 *
	 * @var string[]
	 */
	private const TRANSLATION_FUNCTIONS = array(
		'__',
		'_e',
		'_x',
		'_ex',
		'_n',
		'_nx',
		'_n_noop',
		'_nx_noop',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
		'esc_textarea__',
		'translate',
	);

	/**
	 * Calls whose literals reach the merchant as an administration message.
	 *
	 * @var string[]
	 */
	private const NOTICE_FUNCTIONS = array(
		'add_settings_error',
		'wp_die',
		'wp_admin_notice',
		'wp_get_admin_notice',
	);

	/**
	 * Calls whose literals travel back to the caller inside the body of a REST
	 * answer, which is how the receivers of this package speak.
	 *
	 * `WP_Error` is in the list, and the trade is written down rather than left
	 * to be discovered. Its name carries the word this gate uses to recognise
	 * an exception, so without an entry here a receiver message built as an
	 * error object is reported as an exception message and its reader goes
	 * looking for a `throw`. Measured on 2026-09-18: no source of this package
	 * constructs a `WP_Error` for any other purpose - the checkout validator
	 * adds to an error bag it is handed, which is a different shape and is
	 * recognised by the translation call around its text. Should that change,
	 * the label still points at a message that travels back to a caller, which
	 * is the half of the reading that matters.
	 *
	 * @var string[]
	 */
	private const RESPONSE_FUNCTIONS = array(
		'wp_error',
		'wp_rest_response',
		'rest_ensure_response',
		'wp_send_json',
		'wp_send_json_error',
		'wp_send_json_success',
	);

	/**
	 * Array keys whose value is text of a receiver answer.
	 *
	 * The body of an answer is usually composed as an array before it reaches
	 * the call that returns it, and the walk back to the enclosing call stops
	 * at the bracket that opens the array. Without these keys the message of a
	 * receiver would be recognised only in the shape where it is written
	 * directly as an argument, which is the shape this package uses least.
	 *
	 * `message` is not a guess: it is the field the sender reads, frozen in
	 * `Contract\Constants` as the message field of a rejection body.
	 *
	 * @var string[]
	 */
	private const RECEIVER_TEXT_KEYS = array(
		'message',
		'reason',
		'detail',
		'details',
	);

	/**
	 * Array keys whose value is a label, a help text or a placeholder of a
	 * settings field or of a checkout field.
	 *
	 * @var string[]
	 */
	private const FIELD_TEXT_KEYS = array(
		'label',
		'description',
		'title',
		'placeholder',
		'help',
		'help_text',
		'hint',
		'tip',
		'desc',
		'summary',
		'optionsLabel',
		'error_message',
		'button_label',
	);

	/**
	 * Runs the gate.
	 *
	 * @param string[] $arguments Command line arguments, without the script name.
	 * @return int One of the exit code constants.
	 */
	public static function run( array $arguments ): int {
		$targets = array();

		foreach ( $arguments as $argument ) {
			if ( '--help' === $argument || '-h' === $argument ) {
				self::write_out( self::help_text() );
				return self::EXIT_OK;
			}
			if ( '' !== $argument && '-' === $argument[0] ) {
				self::write_error( 'unknown option ' . $argument . '.' );
				return self::EXIT_MISCONFIGURED;
			}
			$targets[] = $argument;
		}

		if ( array() === $targets ) {
			self::write_error( 'no paths given. Usage: php check-english.php <path> [<path>...]' );
			return self::EXIT_MISCONFIGURED;
		}

		if ( ! function_exists( 'token_get_all' ) ) {
			self::write_error( 'this PHP build has no tokenizer, so PHP sources cannot be inspected. Refusing to report a partial sweep as clean.' );
			return self::EXIT_MISCONFIGURED;
		}

		$files = array();
		foreach ( $targets as $target ) {
			if ( ! file_exists( $target ) ) {
				self::write_warning( 'skipping ' . $target . ': it does not exist yet.' );
				continue;
			}
			if ( ! self::collect( $target, true, $files ) ) {
				return self::EXIT_MISCONFIGURED;
			}
		}

		$findings = array();
		foreach ( $files as $file ) {
			$contents = self::read_file( $file['path'] );
			if ( null === $contents ) {
				return self::EXIT_MISCONFIGURED;
			}

			$segments = 'source' === $file['kind']
				? self::source_segments( $contents )
				: self::prose_segments( $contents );

			foreach ( $segments as $segment ) {
				$markers = self::spanish_markers( self::strip_allowlisted( $segment['text'] ) );
				if ( array() === $markers ) {
					continue;
				}
				$findings[] = array(
					'path'    => $file['path'],
					'line'    => $segment['line'],
					'text'    => self::shorten( $segment['text'] ),
					'surface' => $segment['surface'],
					'markers' => $markers,
				);
			}
		}

		if ( array() === $findings ) {
			self::write_out( 'english-only gate: no Spanish found in ' . count( $files ) . ' file(s).' . PHP_EOL );
			return self::EXIT_OK;
		}

		foreach ( $findings as $finding ) {
			self::write_out( $finding['path'] . ':' . $finding['line'] . ': ' . $finding['text'] . PHP_EOL );
			self::write_out( '    surface: ' . $finding['surface'] . PHP_EOL );
			self::write_out( '    markers: ' . implode( ', ', $finding['markers'] ) . PHP_EOL );
		}
		self::write_out(
			'english-only gate: ' . count( $findings ) . ' finding(s). Write the text in English. '
			. 'The allowlist inside this file is only for proper nouns and untranslated Spanish tax vocabulary, '
			. 'and moving a real finding there is what this gate exists to prevent.' . PHP_EOL
		);
		return self::EXIT_FINDINGS;
	}

	/**
	 * Collects the inspectable files under a path.
	 *
	 * @param string                                        $target    File or directory.
	 * @param bool                                          $explicit  Whether the path was named on the command line.
	 * @param array<int, array{path: string, kind: string}> $collected Accumulator, by reference.
	 * @return bool False when the gate cannot continue.
	 */
	private static function collect( string $target, bool $explicit, array &$collected ): bool {
		if ( is_dir( $target ) ) {
			$entries = scandir( $target );
			if ( false === $entries ) {
				self::write_error( 'cannot read the directory ' . $target . '.' );
				return false;
			}
			sort( $entries );
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = rtrim( $target, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $entry;
				if ( is_dir( $path ) && ( in_array( $entry, self::SKIPPED_DIRECTORIES, true ) || '.' === $entry[0] ) ) {
					continue;
				}
				if ( ! self::collect( $path, false, $collected ) ) {
					return false;
				}
			}
			return true;
		}

		$extension = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, self::SOURCE_EXTENSIONS, true ) ) {
			$collected[] = array(
				'path' => $target,
				'kind' => 'source',
			);
			return true;
		}
		if ( in_array( $extension, self::PROSE_EXTENSIONS, true ) ) {
			$collected[] = array(
				'path' => $target,
				'kind' => 'prose',
			);
			return true;
		}
		if ( $explicit ) {
			self::write_warning( 'skipping ' . $target . ': this gate does not inspect files with that extension.' );
		}
		return true;
	}

	/**
	 * Reads a file that is known to exist.
	 *
	 * @param string $path File path.
	 * @return string|null Null when it cannot be read, which is an error and never a clean result.
	 */
	private static function read_file( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			self::write_error( 'cannot read ' . $path . '. A file that cannot be seen is not a file that is clean.' );
			return null;
		}

		/*
		 * The WordPress filesystem abstraction is not available here and cannot
		 * be: this is a command line gate that runs with nothing but composer
		 * installed, on a checkout with no WordPress in it. Reading a file of
		 * the repository being inspected is the entire job.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- No WordPress is loaded: this is a command line gate.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			self::write_error( 'cannot read ' . $path . '. A file that cannot be seen is not a file that is clean.' );
			return null;
		}

		/*
		 * Every rule below is a UTF-8 regular expression, and the engine gives
		 * back an empty result - not an error - when the subject is not valid
		 * UTF-8. Left alone, a file in another encoding would come out CLEAN,
		 * which is the exact shape of the failure this gate exists to prevent:
		 * a green run that means "I could not read it". The repository pins the
		 * encoding in `.editorconfig`; this is what happens when something
		 * arrives that ignored it.
		 */
		if ( 1 !== preg_match( '//u', $contents ) ) {
			self::write_error(
				$path . ' is not valid UTF-8, so it cannot be inspected. Refusing to report it as clean. '
				. 'The repository pins UTF-8 in .editorconfig; convert the file.'
			);
			return null;
		}
		return $contents;
	}

	/**
	 * Extracts the inspectable segments of a PHP source: string literals,
	 * comments, docblocks and markup outside the PHP tags, one segment per
	 * line so that every finding can name the line it is on.
	 *
	 * The tokenizer that PHP itself ships with does the lexing. A set of
	 * regular expressions would be shorter and its dangerous failure is not
	 * noise but silence: one quote inside a comment desynchronises the match
	 * and the rest of the file stops being inspected, with the gate still
	 * reporting green.
	 *
	 * @param string $contents File contents.
	 * @return array<int, array{line: int, text: string, surface: string}>
	 */
	private static function source_segments( string $contents ): array {
		$tokens   = token_get_all( $contents );
		$segments = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$id   = $token[0];
			$text = $token[1];
			$line = $token[2];

			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$surface = self::surface_of_literal( $tokens, $index, $text );
				self::push_lines( $segments, self::unquote( $text ), $line, $surface );
				continue;
			}

			if ( T_ENCAPSED_AND_WHITESPACE === $id ) {
				/*
				 * The body of a double-quoted string with interpolation, and
				 * the body of a heredoc or a nowdoc. Already unquoted by the
				 * tokenizer.
				 */
				self::push_lines( $segments, $text, $line, self::surface_of_literal( $tokens, $index, $text ) );
				continue;
			}

			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				self::push_lines( $segments, self::strip_comment_markers( $text ), $line, 'comment or docblock' );
				continue;
			}

			if ( T_INLINE_HTML === $id ) {
				self::push_lines( $segments, $text, $line, 'markup outside the PHP tags' );
			}
		}

		return $segments;
	}

	/**
	 * Extracts the prose of a Markdown or plain text document: every line
	 * except code.
	 *
	 * Fenced blocks are dropped whole and inline code spans are removed from
	 * the lines that survive. Indented code blocks are NOT dropped: telling
	 * them apart from an indented list item needs a real Markdown parser, which
	 * would be a dependency this file refuses to have. A snippet that trips the
	 * gate goes inside a fence.
	 *
	 * @param string $contents File contents.
	 * @return array<int, array{line: int, text: string, surface: string}>
	 */
	private static function prose_segments( string $contents ): array {
		$lines    = preg_split( '/\r\n|\n|\r/', $contents );
		$segments = array();
		$fence    = null;

		foreach ( (array) $lines as $offset => $line ) {
			$matches  = array();
			$is_fence = 1 === preg_match( '/^\s{0,3}(`{3,}|~{3,})/', (string) $line, $matches );

			if ( null !== $fence ) {
				if ( $is_fence && $matches[1][0] === $fence['character'] && strlen( $matches[1] ) >= $fence['length'] ) {
					$fence = null;
				}
				continue;
			}

			if ( $is_fence ) {
				$fence = array(
					'character' => $matches[1][0],
					'length'    => strlen( $matches[1] ),
				);
				continue;
			}

			$without_code = preg_replace( '/(`+)[^`]*?\1/', ' ', (string) $line );
			if ( '' !== trim( (string) $without_code ) ) {
				$segments[] = array(
					'line'    => $offset + 1,
					'text'    => (string) $without_code,
					'surface' => 'documentation',
				);
			}
		}

		return $segments;
	}

	/**
	 * Splits a multi-line piece of text into one segment per non-blank line,
	 * keeping the line numbers right.
	 *
	 * @param array<int, array{line: int, text: string, surface: string}> $segments Accumulator, by reference.
	 * @param string                                                      $text     Text to split.
	 * @param int                                                         $line     Line the text starts on.
	 * @param string                                                      $surface  Surface label.
	 * @return void
	 */
	private static function push_lines( array &$segments, string $text, int $line, string $surface ): void {
		foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $offset => $piece ) {
			if ( '' === trim( $piece ) ) {
				continue;
			}
			$segments[] = array(
				'line'    => $line + $offset,
				'text'    => $piece,
				'surface' => $surface,
			);
		}
	}

	/**
	 * Names the surface a string literal belongs to.
	 *
	 * Recognition NAMES the finding, it does not decide what gets inspected:
	 * see the note on the superset in the file docblock. A literal nobody
	 * recognises is still swept, and is reported as a plain string literal.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $index  Index of the literal.
	 * @param string                   $text   Raw text of the literal.
	 * @return string
	 */
	private static function surface_of_literal( array $tokens, int $index, string $text ): string {
		if ( 1 === preg_match( '/class="[^"]*\bnotice\b|\bnotice-(?:error|warning|success|info)\b/', $text ) ) {
			return 'administration notice';
		}

		$call = self::enclosing_call( $tokens, $index );
		if ( null !== $call ) {
			if ( in_array( strtolower( $call['name'] ), self::TRANSLATION_FUNCTIONS, true ) ) {
				return 'translation function literal';
			}
			if ( in_array( strtolower( $call['name'] ), self::NOTICE_FUNCTIONS, true ) ) {
				return 'administration notice';
			}

			/*
			 * Before the exception branch on purpose: `WP_Error` matches the
			 * rule that recognises an exception by its name, and a receiver
			 * message reported as an exception message sends its reader to the
			 * wrong file. See the note on the constant.
			 */
			if ( in_array( strtolower( $call['name'] ), self::RESPONSE_FUNCTIONS, true ) ) {
				return 'receiver message';
			}
			if ( $call['is_exception'] ) {
				return 'exception message';
			}
		}

		$key = self::preceding_array_key( $tokens, $index );
		if ( null !== $key ) {
			if ( in_array( $key, self::FIELD_TEXT_KEYS, true ) ) {
				return 'settings or checkout field text';
			}
			if ( in_array( $key, self::RECEIVER_TEXT_KEYS, true ) ) {
				return 'receiver message';
			}
		}

		return 'string literal';
	}

	/**
	 * The array key a literal is the value of, when it is one.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $index  Index of the literal.
	 * @return string|null The key, unquoted, or null when the literal is not a value.
	 */
	private static function preceding_array_key( array $tokens, int $index ): ?string {
		$previous = self::significant_before( $tokens, $index, 1 );
		$before   = self::significant_before( $tokens, $index, 2 );

		if ( null === $previous || null === $before ) {
			return null;
		}

		if ( T_DOUBLE_ARROW !== self::token_id( $previous )
			|| T_CONSTANT_ENCAPSED_STRING !== self::token_id( $before )
		) {
			return null;
		}

		return self::unquote( self::token_text( $before ) );
	}

	/**
	 * Walks back from a literal to the call it is an argument of.
	 *
	 * The walk is bounded and tracks bracket depth, so the second and third
	 * arguments of a call are attributed to the same call as the first. That is
	 * what makes the plural form of a translation call - whose user-facing text
	 * is the SECOND argument - be recognised as translated text.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $index  Index of the literal.
	 * @return array{name: string, is_exception: bool}|null
	 */
	private static function enclosing_call( array $tokens, int $index ): ?array {
		$depth  = 0;
		$budget = 200;

		for ( $cursor = $index - 1; $cursor >= 0 && $budget > 0; $cursor-- ) {
			$token = $tokens[ $cursor ];
			if ( self::is_ignorable( $token ) ) {
				continue;
			}
			--$budget;
			$text = self::token_text( $token );

			if ( ')' === $text || ']' === $text ) {
				++$depth;
				continue;
			}
			if ( '(' === $text ) {
				if ( $depth > 0 ) {
					--$depth;
					continue;
				}
				return self::call_before_parenthesis( $tokens, $cursor );
			}
			if ( '[' === $text ) {
				if ( $depth > 0 ) {
					--$depth;
					continue;
				}
				return null;
			}
			if ( ';' === $text || '{' === $text || '}' === $text ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Reads the callee that sits just before an opening parenthesis.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $cursor Index of the opening parenthesis.
	 * @return array{name: string, is_exception: bool}|null
	 */
	private static function call_before_parenthesis( array $tokens, int $cursor ): ?array {
		$name = self::significant_before( $tokens, $cursor, 1 );
		if ( null === $name || ! in_array( self::token_id( $name ), self::name_token_ids(), true ) ) {
			return null;
		}

		$qualifier    = self::significant_before( $tokens, $cursor, 2 );
		$owner        = self::significant_before( $tokens, $cursor, 3 );
		$is_exception = false;

		if ( null !== $qualifier && T_NEW === self::token_id( $qualifier ) ) {
			$is_exception = self::names_an_exception( self::token_text( $name ) );
		} elseif ( null !== $qualifier && T_DOUBLE_COLON === self::token_id( $qualifier ) && null !== $owner ) {
			/* A static factory of an exception: the message is still an exception message. */
			$is_exception = self::names_an_exception( self::token_text( $owner ) );
		}

		if ( ! $is_exception ) {
			$is_exception = self::throw_within_reach( $tokens, $cursor );
		}

		return array(
			'name'         => self::short_name( self::token_text( $name ) ),
			'is_exception' => $is_exception,
		);
	}

	/**
	 * Token identifiers that can carry the name of a callee.
	 *
	 * PHP 8 hands back a qualified name as ONE token instead of a sequence of
	 * separators and plain strings, and three of the four identifiers below did
	 * not exist before it. They are resolved by name rather than written as
	 * constants so that copying this file into a repository running an older
	 * build cannot make it fatal the moment it loads.
	 *
	 * @return int[]
	 */
	private static function name_token_ids(): array {
		$ids = array( T_STRING );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
			if ( defined( $constant ) ) {
				$ids[] = (int) constant( $constant );
			}
		}
		return $ids;
	}

	/**
	 * The last segment of a possibly qualified name.
	 *
	 * @param string $name Name as the tokenizer gave it.
	 * @return string
	 */
	private static function short_name( string $name ): string {
		$segments = explode( '\\', $name );
		return (string) end( $segments );
	}

	/**
	 * Whether a keyword sits close enough before a call to make it a throw.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $cursor Index of the opening parenthesis.
	 * @return bool
	 */
	private static function throw_within_reach( array $tokens, int $cursor ): bool {
		for ( $step = 1; $step <= 6; $step++ ) {
			$token = self::significant_before( $tokens, $cursor, $step );
			if ( null === $token ) {
				return false;
			}
			if ( T_THROW === self::token_id( $token ) ) {
				return true;
			}
			if ( ';' === self::token_text( $token ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Whether a class name names an exception.
	 *
	 * @param string $name Class name, possibly qualified.
	 * @return bool
	 */
	private static function names_an_exception( string $name ): bool {
		return false !== stripos( $name, 'exception' ) || false !== stripos( $name, 'error' );
	}

	/**
	 * Returns the Nth significant token before a position, skipping whitespace,
	 * comments and name separators.
	 *
	 * @param array<int, array|string> $tokens All tokens of the file.
	 * @param int                      $index  Position to walk back from.
	 * @param int                      $step   How many significant tokens back.
	 * @return array|string|null
	 */
	private static function significant_before( array $tokens, int $index, int $step ) {
		$seen = 0;
		for ( $cursor = $index - 1; $cursor >= 0; $cursor-- ) {
			$token = $tokens[ $cursor ];
			if ( self::is_ignorable( $token ) ) {
				continue;
			}
			++$seen;
			if ( $seen === $step ) {
				return $token;
			}
		}
		return null;
	}

	/**
	 * Whether a token carries no meaning for the walk back.
	 *
	 * The name separator is skipped so that a qualified class name reads as one
	 * token: without this, a fully qualified exception would hide behind its
	 * backslashes.
	 *
	 * @param array|string $token Token.
	 * @return bool
	 */
	private static function is_ignorable( $token ): bool {
		if ( ! is_array( $token ) ) {
			return false;
		}
		return in_array(
			$token[0],
			array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR ),
			true
		);
	}

	/**
	 * The identifier of a token, or null for a single character token.
	 *
	 * @param array|string $token Token.
	 * @return int|null
	 */
	private static function token_id( $token ): ?int {
		return is_array( $token ) ? $token[0] : null;
	}

	/**
	 * The source text of a token.
	 *
	 * @param array|string $token Token.
	 * @return string
	 */
	private static function token_text( $token ): string {
		return is_array( $token ) ? $token[1] : $token;
	}

	/**
	 * Removes the surrounding quotes of a string literal.
	 *
	 * Escape sequences are deliberately NOT decoded; see the limitations in the
	 * file docblock.
	 *
	 * @param string $text Raw literal, quotes included.
	 * @return string
	 */
	private static function unquote( string $text ): string {
		if ( strlen( $text ) >= 2 ) {
			$first = $text[0];
			$last  = $text[ strlen( $text ) - 1 ];
			if ( ( "'" === $first || '"' === $first ) && $first === $last ) {
				return substr( $text, 1, -1 );
			}
		}
		return $text;
	}

	/**
	 * Removes the markers of a comment so that the report shows the text and
	 * not the layout around it.
	 *
	 * @param string $text Raw comment.
	 * @return string
	 */
	private static function strip_comment_markers( string $text ): string {
		$text = preg_replace( '#^\s*(?://|\#)#m', '', $text );
		$text = preg_replace( '#/\*+|\*+/#', '', (string) $text );
		return (string) preg_replace( '#^\s*\*+ ?#m', '', (string) $text );
	}

	/**
	 * Removes every allowlisted term from a text before the detection runs.
	 *
	 * The term is replaced by a space rather than by nothing, so that cutting a
	 * term out of the middle of a word cannot glue its two halves into a new
	 * word that then matches.
	 *
	 * @param string $text Text to whiten.
	 * @return string
	 */
	private static function strip_allowlisted( string $text ): string {
		$terms = array();
		foreach ( self::ALLOWLIST as $reason => $entries ) {
			if ( '' === trim( (string) $reason ) ) {
				continue;
			}
			foreach ( $entries as $term ) {
				$terms[] = $term;
			}
		}
		/* Longest first, so that a long term is removed whole instead of being cut in half by a shorter one that overlaps it. */
		usort(
			$terms,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);

		foreach ( $terms as $term ) {
			if ( false !== strpos( $text, $term ) ) {
				$text = str_replace( $term, ' ', $text );
			}
		}
		return $text;
	}

	/**
	 * Finds the Spanish markers of a text.
	 *
	 * The three rules, in the order they are applied. An inverted opening mark
	 * or an accented letter is a finding on its own, because neither appears in
	 * English at all. Words are weighed: at least two distinct ones, of which at
	 * least one has to come from the strong list.
	 *
	 * @param string $text Text to inspect, already whitened by the allowlist.
	 * @return string[] Human readable markers; empty when the text is clean.
	 */
	private static function spanish_markers( string $text ): array {
		$markers = array();

		$found = array();
		preg_match_all( '/[' . self::SPANISH_PUNCTUATION . ']/u', $text, $found );
		foreach ( array_unique( $found[0] ) as $character ) {
			$markers[] = 'Spanish punctuation "' . $character . '"';
		}

		$found = array();
		preg_match_all( '/[' . self::SPANISH_LETTERS . ']/u', $text, $found );
		foreach ( array_unique( $found[0] ) as $character ) {
			$markers[] = 'Spanish letter "' . $character . '"';
		}

		$strong = self::matched_words( $text, self::STRONG_WORDS );
		$weak   = self::matched_words( $text, self::WEAK_WORDS );

		if ( array() !== $strong && count( $strong ) + count( $weak ) >= self::WORD_THRESHOLD ) {
			foreach ( array_merge( $strong, $weak ) as $word ) {
				$markers[] = 'Spanish word "' . $word . '"';
			}
		}

		return $markers;
	}

	/**
	 * The distinct words of a list that appear in a text as whole words.
	 *
	 * @param string   $text  Text to inspect.
	 * @param string[] $words Words to look for.
	 * @return string[]
	 */
	private static function matched_words( string $text, array $words ): array {
		$pattern = '/' . self::WORD_BOUNDARY_BEFORE . '(' . implode( '|', $words ) . ')' . self::WORD_BOUNDARY_AFTER . '/iu';
		$found   = array();
		preg_match_all( $pattern, $text, $found );
		return array_values( array_unique( array_map( 'strtolower', $found[1] ) ) );
	}

	/**
	 * Collapses and truncates the text of a finding.
	 *
	 * @param string $text Text of the finding.
	 * @return string
	 */
	private static function shorten( string $text ): string {
		$collapsed = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		/*
		 * Truncation goes through the regular expression engine rather than
		 * through the multibyte string extension, because that extension is not
		 * part of a bare PHP build and this file promises the sibling
		 * repositories that it needs nothing installed. Cutting an accented
		 * character in half with a byte-wise substring would produce a report
		 * line that is not valid text.
		 */
		$matches = array();
		if ( 1 === preg_match( '/^(.{' . self::MAX_REPORTED_LENGTH . '}).+$/u', $collapsed, $matches ) ) {
			return $matches[1] . '...';
		}
		return $collapsed;
	}

	/**
	 * The help text.
	 *
	 * @return string
	 */
	private static function help_text(): string {
		return 'English-only gate.' . PHP_EOL . PHP_EOL
			. '  php check-english.php <path> [<path>...]' . PHP_EOL . PHP_EOL
			. 'Fails when a user-facing string is written in Spanish. Inspects the string' . PHP_EOL
			. 'literals, comments and inline markup of PHP sources and the prose of Markdown' . PHP_EOL
			. 'and plain text documents. Paths that do not exist are skipped with a warning.' . PHP_EOL . PHP_EOL
			. 'Exit codes: 0 clean, 1 findings, 2 the gate could not run as configured.' . PHP_EOL;
	}

	/**
	 * Writes to standard output.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function write_out( string $message ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- No WordPress is loaded: writing to the standard streams is what a command line gate does.
		fwrite( STDOUT, $message );
	}

	/**
	 * Writes an error to standard error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function write_error( string $message ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- No WordPress is loaded: writing to the standard streams is what a command line gate does.
		fwrite( STDERR, 'english-only gate: ' . $message . PHP_EOL );
	}

	/**
	 * Writes a warning to standard error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function write_warning( string $message ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- No WordPress is loaded: writing to the standard streams is what a command line gate does.
		fwrite( STDERR, 'warning: ' . $message . PHP_EOL );
	}
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The argument is a process exit status, not output: escaping an integer for HTML would be meaningless here.
exit( EnglishGate::run( array_slice( $argv, 1 ) ) );
