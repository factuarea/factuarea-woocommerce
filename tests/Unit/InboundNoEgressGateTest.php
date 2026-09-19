<?php
/**
 * The structural gate of the single outbound door and of the single retry policy.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\RetrySchedule;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What this file measures, and the half of the question it cannot answer.
 *
 * Two structural promises of this package are the kind that nothing enforces on
 * its own, because breaking either of them produces working code:
 *
 *   1. EVERY outbound request leaves through one door. The plugin owns exactly
 *      one transport client, and a second call site - a `wp_remote_post` in a
 *      listener, a raw cURL handle in an admin screen - works perfectly well
 *      while quietly escaping the timeout, the headers, the signature and the
 *      rejection classifier that the one door applies. Nothing fails. There is
 *      simply a request nobody accounted for.
 *
 *   2. NOTHING here declares a retry policy of its own. `Contract\RetrySchedule`
 *      is a COPY of the single retry calculator of the platform, kept honest by
 *      `RetryScheduleParityTest`. A second table of waits, a second maximum of
 *      attempts or a second proportion of noise written somewhere else does not
 *      throw and does not slow anything down: it retries at moments nobody
 *      chose, and the only symptom is a receiver being hit in a pattern that no
 *      one designed.
 *
 * The inbound half of this change makes the first promise sharper still.
 * Applying what arrived does not need the network, and going out to it would
 * turn ONE delivery into TWO: a remote failure would become a failure of the
 * response to a delivery that was already applied, and the sender would retry
 * something that is already done. So the classes that apply what arrived are
 * held to a stricter rule than the rest of the package - not "through the one
 * door" but "not at all", the door included.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS REPOSITORY CANNOT CHECK, AND WHERE IT IS CHECKED (task 20.4)
 * ---------------------------------------------------------------------------
 * This gate is one HALF of the promise, the half that lives on the side of the
 * merchant's WordPress. It sweeps the code that this package publishes and
 * nothing else. It cannot see, and never will:
 *
 *   - The INVENTORY OF OUTBOUND CALL SITES of the platform. It lives in the
 *     monorepo, at `backend/tests/Unit/Shared/Audit/EgressCallSiteCoverageTest.php`,
 *     which derives its universe from `backend/app/**` instead of holding a
 *     hand-written list, keys every point as `path:line`, and forces a written
 *     decision per point - a fixed provider endpoint that the egress policy has
 *     nothing to decide about, or a real gap that names the change that will
 *     close it. The second path, the one the framework's request prevention
 *     cannot see because it is raw cURL, is enumerated apart in
 *     `backend/tests/Shared/Connectors/CurlEgressInventory.php` and derived by
 *     `backend/tests/Unit/Shared/Connectors/RawCurlEgressInTestsCoverageTest.php`.
 *
 *   - The PER-COMPANY EGRESS ALLOWLIST. Whether the platform may reach the host
 *     of a given store at all is decided there, by
 *     `backend/app/Shared/Domain/Egress/TenantEgressAllowlist.php` and
 *     `backend/app/Shared/Domain/Egress/TenantEgressAdmissionPolicy.php`,
 *     admitted through
 *     `backend/app/Shared/Application/Service/TenantEgressUrlAdmissionService.php`
 *     and enforced by
 *     `backend/app/Shared/Infrastructure/Egress/GuardedHttpClient.php`. That one
 *     company cannot borrow another company's allowlist is covered by
 *     `backend/tests/Integration/Shared/Egress/TenantEgressAllowlistIsolationTest.php`.
 *     A merchant's WordPress has no notion of a company, so no check written
 *     here could ever stand in for it.
 *
 *   - That the SENDER of these deliveries keeps its own discipline: that the
 *     outbound store adapters do not open a second door of their own, covered
 *     by `backend/tests/Unit/Integration/Architecture/StoreOutboundAdapterEgressDisciplineTest.php`,
 *     `backend/tests/Unit/Integration/Architecture/StoreFeedbackAdapterEgressDisciplineTest.php`
 *     and `backend/tests/Unit/Integration/Application/Store/StoreConnectionHasNoOwnEgressCheckTest.php`.
 *
 * Those paths were measured in the monorepo on 2026-09-18. They are written
 * here as a pointer and not as an assertion: this suite runs on a checkout of
 * the plugin alone, where none of them exists, so asserting on them would make
 * this file red on every machine that is not a developer's.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SWEEP TOKENISES INSTEAD OF SEARCHING FOR TEXT
 * ---------------------------------------------------------------------------
 * Because the inbound classes DESCRIBE the outbox in their documentation - they
 * explain, at length, why they claim a row the way the outbox does - and a
 * textual search would report every one of those sentences. A gate that reports
 * prose is a gate whose first fix is an exemption, and an exemption is how a
 * gate stops meaning anything. The sweep therefore walks the PHP token stream
 * and drops comments and documentation blocks, so that what it reports is a
 * call and never a mention.
 *
 * The one thing it deliberately does NOT drop is string literals. A banned
 * function reached through `call_user_func( 'wp_remote_post', ... )` is a
 * request like any other, and the name of the function is a string there. Those
 * are reported under their own label, so that the reader can tell a dynamic
 * call from a direct one.
 *
 * ---------------------------------------------------------------------------
 * HOW THIS GATE AVOIDS BEING A GREEN THAT PROVES NOTHING
 * ---------------------------------------------------------------------------
 * The failure mode of a structural gate is not a false red, it is a green
 * produced by looking at nothing: an empty universe, a detector that stopped
 * recognising the shape it hunts, an allowlist that swallowed the whole
 * repository. Four cases here exist only for that:
 *
 *   - the universe is measured from disk - the directory, never a list of file
 *     names - and has a floor, so a sweep that stops finding files is red;
 *   - the detector is pointed at the REAL transport client with its exemption
 *     lifted, and must report it, so a detector that stopped recognising a
 *     request is red;
 *   - the detector is pointed at the REAL frozen table with its exemption
 *     lifted, and must report all three of the things it hunts;
 *   - the exemption lists are checked against disk, so a file that is renamed
 *     cannot leave an allowlist entry pointing at nothing and a rule covering
 *     nobody.
 *
 * Unit suite: no WordPress, no database, no doubles. The PHP tokeniser and the
 * files of this repository.
 */
final class InboundNoEgressGateTest extends TestCase {

	/**
	 * Directory, relative to the repository root, holding the plugin code that
	 * the published artefact carries.
	 *
	 * @var string
	 */
	private const SHIPPED_DIRECTORY = 'src';

	/**
	 * The two PHP files of the repository root that travel inside the artefact:
	 * the entry point WordPress loads and the routine it looks for on uninstall.
	 * Everything else at the root either does not ship or is not PHP.
	 *
	 * @var string[]
	 */
	private const SHIPPED_ROOT_FILES = array(
		'factuarea-for-woocommerce.php',
		'uninstall.php',
	);

	/**
	 * Floor of the number of shipped PHP files, measured on 2026-09-18: 49
	 * under `src/` plus the two root files. A floor and not an equality,
	 * because the inbound half is still growing; what it defends against is a
	 * sweep that quietly stops seeing the tree.
	 *
	 * @var int
	 */
	private const SHIPPED_FILE_FLOOR = 51;

	/**
	 * The one file allowed to emit a request, with the reason written beside it.
	 *
	 * @var array<string, string>
	 */
	private const EGRESS_ALLOWLIST = array(
		'src/Transport/WordPressHttpClient.php' => 'The single transport client of the package: the one place that applies the timeout, the headers, the signature and the reading of the response.',
	);

	/**
	 * Short name of that transport client, used for the second half of the
	 * single-door rule: a class that never calls a remote function itself but
	 * asks this one to do it is still a door.
	 *
	 * @var string
	 */
	private const TRANSPORT_CLIENT = 'WordPressHttpClient';

	/**
	 * Every place allowed to reach the transport client, with the reason.
	 * Measured on 2026-09-18 against the published code.
	 *
	 * @var array<string, string>
	 */
	private const TRANSPORT_CLIENT_CONSUMERS = array(
		'src/Transport/WordPressHttpClient.php' => 'The client itself.',
		'src/Outbox/OutboxDispatcher.php'       => 'The drain of the outbox: the one place that delivers a queued entry.',
		'src/MyAccount/InvoicePdfLink.php'      => 'Reads the invoice document of an order so the buyer can download it where they bought.',
		'src/Admin/ConnectionTester.php'        => 'The button that tells the merchant whether the credentials they just typed work.',
	);

	/**
	 * Classes of the outbox. The inbound half may not name any of them: queueing
	 * a delivery is a request that has not happened yet, and a request deferred
	 * is still the second delivery this rule exists to prevent.
	 *
	 * @var string[]
	 */
	private const OUTBOX_CLASSES = array(
		'OutboxDispatcher',
		'OutboxRepository',
		'OutboxTable',
	);

	/**
	 * Directory of the inbound half. Swept as a DIRECTORY and never as a list of
	 * class names, so that the receivers and the writers that land after this
	 * gate is written are covered the day they appear rather than the day
	 * somebody remembers to add them here.
	 *
	 * @var string
	 */
	private const INBOUND_DIRECTORY = 'src/Inbound';

	/**
	 * Floor of the number of files of that directory, measured on 2026-09-18:
	 * the signature verifier, the idempotency table and its ledger, the status
	 * storage, the refund applier, the product resolver and the three writers.
	 *
	 * @var int
	 */
	private const INBOUND_FILE_FLOOR = 9;

	/**
	 * The payload classes that only exist because something was received. Any
	 * shipped file naming one of them in code is handling an inbound delivery,
	 * whichever directory it lives in, and joins the inbound half of the sweep.
	 *
	 * @var string[]
	 */
	private const INBOUND_PAYLOAD_CLASSES = array(
		'InboundStatusNotice',
		'InboundRefundNotice',
		'CatalogPushItem',
		'CatalogPushOutcome',
	);

	/**
	 * The one file allowed to declare a retry policy.
	 *
	 * @var string
	 */
	private const RETRY_POLICY_HOME = 'src/Contract/RetrySchedule.php';

	/**
	 * The parity check that keeps that copy honest against the single retry
	 * calculator of the platform.
	 *
	 * @var string
	 */
	private const RETRY_PARITY_CHECK = 'tests/Unit/RetryScheduleParityTest.php';

	/**
	 * Prefix of the functions that READ a response that has already arrived.
	 * They are not requests and reporting them would make the rule unusable
	 * inside the very client that is allowed to make one.
	 *
	 * @var string
	 */
	private const REMOTE_ACCESSOR_PREFIX = 'wp_remote_retrieve_';

	/**
	 * Functions that open a socket without any URL in sight.
	 *
	 * @var string[]
	 */
	private const SOCKET_FUNCTIONS = array(
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'socket_create',
		'socket_connect',
	);

	/**
	 * Functions that read a path and accept a URL just as happily. Shipped code
	 * has no business calling any of them: a local file is read through the
	 * WordPress filesystem abstraction, and a remote one through the transport
	 * client.
	 *
	 * @var string[]
	 */
	private const URL_CAPABLE_STREAM_FUNCTIONS = array(
		'file_get_contents',
		'fopen',
		'readfile',
		'file',
		'copy',
		'get_headers',
	);

	/**
	 * Classes that are a request by themselves, bypassing the remote functions
	 * altogether.
	 *
	 * @var string[]
	 */
	private const EGRESS_CLASSES = array(
		'WP_Http',
		'Requests',
	);

	/**
	 * Names that declare a ceiling on the number of attempts.
	 *
	 * @var string
	 */
	private const ATTEMPT_CAP_PATTERN = '/(max[_ ]?(number[_ ]?of[_ ]?)?(attempts?|retries|retry|tries)|(attempt|retry|try)[_ ]?(limit|cap|ceiling)|(attempts?|retries)[_ ]?max)/i';

	/**
	 * Names that declare a proportion of random noise.
	 *
	 * @var string
	 */
	private const NOISE_PATTERN = '/(jitter|noise)/i';

	/**
	 * Names that declare a wait between attempts beyond any doubt, whatever
	 * shape the value has.
	 *
	 * @var string
	 */
	private const WAIT_TABLE_PATTERN = '/((retry|retries|attempt|attempts|delivery)[_ ]?(schedule|intervals?|delays?|waits?|table)|back[_ ]?off)/i';

	/**
	 * Names that MIGHT be a wait. On their own they are ordinary - a drain
	 * interval, a claim timeout - so they are only reported when the value is a
	 * TABLE of numbers, which no ordinary duration ever is.
	 *
	 * @var string
	 */
	private const WAIT_NAME_PATTERN = '/(intervals?|waits?|delays?|back[_ ]?off|schedule|sleep|pause|cool[_ ]?down)/i';

	/**
	 * The sweep sees every PHP file the published artefact carries, measured
	 * from disk rather than listed by hand.
	 *
	 * @return void
	 */
	public function test_the_sweep_sees_every_php_file_the_published_artefact_carries(): void {
		$files = $this->shipped_files();

		$this->assertGreaterThanOrEqual(
			self::SHIPPED_FILE_FLOOR,
			count( $files ),
			sprintf(
				'The sweep found %d shipped PHP files and there were at least %d on 2026-09-18. Either files were deleted, or the discovery stopped descending into a directory - in which case every rule of this gate is green over a tree it is no longer reading.',
				count( $files ),
				self::SHIPPED_FILE_FLOOR
			)
		);

		foreach ( self::SHIPPED_ROOT_FILES as $root_file ) {
			$this->assertContains(
				$root_file,
				$files,
				sprintf( 'The root file %s ships inside the artefact and is not in the swept set.', $root_file )
			);
		}

		$this->assertContains(
			'src/Transport/WordPressHttpClient.php',
			$files,
			'The transport client is not in the swept set, so the rule about where a request may live is being applied to a tree that does not contain the one place a request lives.'
		);
	}

	/**
	 * Every swept file is PHP the tokeniser can read.
	 *
	 * A file the tokeniser cannot read produces no tokens, and no tokens
	 * produces no findings, which reads exactly like a clean file.
	 *
	 * @return void
	 */
	public function test_every_swept_file_is_php_the_tokeniser_can_read(): void {
		foreach ( $this->shipped_files() as $file ) {
			$tokens = $this->significant_tokens( $this->source_of( $file ) );

			$this->assertNotSame(
				array(),
				$tokens,
				sprintf( 'The file %s produced no tokens at all, so every rule of this gate passed over it without looking at anything.', $file )
			);

			$this->assertSame(
				T_OPEN_TAG,
				$tokens[0]['id'],
				sprintf( 'The file %s does not start with a PHP open tag, so the tokeniser read it as text and no rule of this gate applied to it.', $file )
			);
		}
	}

	/**
	 * No request leaves the package anywhere but through its one transport
	 * client. This is the rule of task 20.1.
	 *
	 * @return void
	 */
	public function test_no_egress_call_lives_outside_the_single_transport_client(): void {
		$findings = $this->egress_findings( $this->shipped_files(), array_keys( self::EGRESS_ALLOWLIST ) );

		$this->assertSame(
			array(),
			$findings,
			"A request is emitted outside the single transport client of the package:\n"
				. $this->format( $findings )
				. "\nEverything that goes out goes through Transport\\WordPressHttpClient, which is the one place that applies the timeout, the headers and the reading of the response. A second call site works perfectly well and escapes all three."
		);
	}

	/**
	 * With its exemption lifted, the detector reports the real transport client
	 * by file and by line.
	 *
	 * This is the case that keeps the rule above from being a green produced by
	 * a detector that no longer recognises a request. It measures the detector
	 * against real production code rather than against a fixture, which is the
	 * only way to notice that WordPress renamed the function or that the file
	 * stopped making the call it is exempted for.
	 *
	 * @return void
	 */
	public function test_the_detector_reports_the_real_transport_client_when_its_exemption_is_lifted(): void {
		$findings = $this->egress_findings( $this->shipped_files(), array() );

		$this->assertNotSame(
			array(),
			$findings,
			'With no file exempted at all, the sweep of the whole package found no request anywhere. The package does emit requests, so this is a detector that has stopped detecting and a rule that is now vacuous.'
		);

		$paths = array_unique( array_column( $findings, 'path' ) );

		$this->assertSame(
			array_keys( self::EGRESS_ALLOWLIST ),
			array_values( $paths ),
			"With no file exempted the sweep should report exactly the exempted one, and it reported:\n" . $this->format( $findings )
		);

		foreach ( $findings as $finding ) {
			$this->assertGreaterThan(
				0,
				$finding['line'],
				'A request was reported without a usable line number, which is half of what makes a finding actionable.'
			);
		}
	}

	/**
	 * The exemption list names one file, and that file is on disk.
	 *
	 * An allowlist entry pointing at a path that no longer exists is worse than
	 * no allowlist: the rule covers everybody, the exemption covers nobody, and
	 * the run stays green because the file it was written for is gone.
	 *
	 * @return void
	 */
	public function test_the_only_exempt_file_is_the_transport_client_and_it_is_the_one_on_disk(): void {
		$this->assertCount(
			1,
			self::EGRESS_ALLOWLIST,
			'The package has ONE door. An exemption list with a second entry is a second door with a note attached.'
		);

		foreach ( self::EGRESS_ALLOWLIST as $path => $reason ) {
			$this->assertContains(
				$path,
				$this->shipped_files(),
				sprintf( 'The exempted file %s is not among the shipped files. Either it moved and the exemption was not updated, or it no longer ships.', $path )
			);

			$this->assertNotSame( '', trim( $reason ), sprintf( 'The exemption of %s carries no reason.', $path ) );
		}
	}

	/**
	 * The transport client is reached only from the places that declare why.
	 *
	 * The rule above looks at the remote FUNCTIONS. This one looks at the
	 * CLIENT, because a class that asks the client to post is a door as much as
	 * one that calls the remote function itself, and the function sweep would
	 * never see it.
	 *
	 * @return void
	 */
	public function test_the_transport_client_is_reached_from_a_declared_set_of_places(): void {
		$findings = $this->class_reference_findings(
			$this->shipped_files(),
			array( self::TRANSPORT_CLIENT ),
			array_keys( self::TRANSPORT_CLIENT_CONSUMERS )
		);

		$this->assertSame(
			array(),
			$findings,
			"A place that is not declared reaches the transport client:\n"
				. $this->format( $findings )
				. "\nEvery outbound request of this package is accounted for one by one. If this place really has to emit one, add it to TRANSPORT_CLIENT_CONSUMERS with the reason written out; do not delete the rule."
		);

		foreach ( array_keys( self::TRANSPORT_CLIENT_CONSUMERS ) as $consumer ) {
			$this->assertNotSame(
				array(),
				$this->class_reference_findings( array( $consumer ), array( self::TRANSPORT_CLIENT ), array() ),
				sprintf( 'The file %s is declared as a place that reaches the transport client and it no longer names it. An entry that covers nothing is an entry that will be inherited by whatever lands in that file next.', $consumer )
			);
		}
	}

	/**
	 * The inbound half is swept, it is found by directory, and it is not empty.
	 *
	 * @return void
	 */
	public function test_the_inbound_half_is_swept_and_is_not_empty(): void {
		$inbound = $this->inbound_files();

		$this->assertGreaterThanOrEqual(
			self::INBOUND_FILE_FLOOR,
			count( $inbound ),
			sprintf(
				'The inbound half was swept as %d files and it held at least %d on 2026-09-18. A rule applied to an empty set passes without proving anything.',
				count( $inbound ),
				self::INBOUND_FILE_FLOOR
			)
		);

		$directory_files = $this->php_files_under( self::INBOUND_DIRECTORY );

		$this->assertNotSame(
			array(),
			$directory_files,
			sprintf( 'The directory %s holds no PHP file. The inbound half is discovered by directory precisely so that the pieces landing after this gate was written are covered the day they appear.', self::INBOUND_DIRECTORY )
		);

		foreach ( $directory_files as $file ) {
			$this->assertContains( $file, $inbound, sprintf( 'The file %s lives in the inbound directory and was left out of the inbound sweep.', $file ) );
		}
	}

	/**
	 * Not one of the classes that apply what arrived emits a request. This is
	 * the rule of task 20.2.
	 *
	 * Stricter than the rule of the package: there is no exemption here, not
	 * even the transport client, because applying a delivery does not need the
	 * network at all.
	 *
	 * @return void
	 */
	public function test_the_inbound_half_contains_no_egress_call_at_all(): void {
		$findings = $this->egress_findings( $this->inbound_files(), array() );

		$this->assertSame(
			array(),
			$findings,
			"A class of the inbound half emits a request:\n"
				. $this->format( $findings )
				. "\nApplying what arrived does not need the network. Going out to it turns ONE delivery into TWO: a remote failure becomes a failure of the response to a delivery that was already applied, and the sender retries something that is already done."
		);
	}

	/**
	 * The inbound half does not reach the transport client either, nor queue a
	 * delivery in the outbox.
	 *
	 * The request that is queued now and sent by the drain in a minute is still
	 * the second delivery this rule exists to prevent; it is merely harder to
	 * see. The anti-loop marks written by the refund applier and read by the two
	 * listeners exist for exactly that reason, and this is the mechanical half
	 * of the same promise.
	 *
	 * @return void
	 */
	public function test_the_inbound_half_does_not_reach_the_transport_client_or_the_outbox(): void {
		$forbidden = array_merge( array( self::TRANSPORT_CLIENT ), self::OUTBOX_CLASSES );

		$findings = $this->class_reference_findings( $this->inbound_files(), $forbidden, array() );

		$this->assertSame(
			array(),
			$findings,
			"A class of the inbound half reaches the transport client or the outbox:\n"
				. $this->format( $findings )
				. "\nA delivery queued now and drained in a minute is still a delivery back towards the platform for something the platform itself just sent."
		);
	}

	/**
	 * No second retry policy is declared anywhere but in the frozen table. This
	 * is the rule of task 20.3.
	 *
	 * @return void
	 */
	public function test_no_second_retry_policy_is_declared_outside_the_frozen_table(): void {
		$findings = $this->retry_policy_findings( $this->shipped_files(), self::RETRY_POLICY_HOME );

		$this->assertSame(
			array(),
			$findings,
			"A retry policy is declared outside Contract\\RetrySchedule:\n"
				. $this->format( $findings )
				. "\nThose numbers are not this package's to choose: they are a copy of the single retry calculator of the platform, and a second copy that was meant to agree and drifted does not fail loudly. It retries at moments nobody chose."
		);
	}

	/**
	 * No file copies the waits of the frozen table under another name.
	 *
	 * The rule above reads NAMES, so a table called something innocuous would
	 * slip past it. This one reads the VALUES, taken from the constant itself
	 * rather than written here again, so that a table re-keyed or renamed is
	 * still recognised by the numbers in it.
	 *
	 * @return void
	 */
	public function test_no_file_copies_the_waits_of_the_frozen_table(): void {
		$findings = $this->frozen_wait_copy_findings( $this->shipped_files(), self::RETRY_POLICY_HOME );

		$this->assertSame(
			array(),
			$findings,
			"A file repeats the waits of the frozen backoff table:\n"
				. $this->format( $findings )
				. "\nThere is one table. A second one with the same numbers today is a second one with different numbers the day the platform changes them, and only one of the two is guarded by the parity check."
		);
	}

	/**
	 * The frozen table still declares the three things the sweep hunts for
	 * everywhere else.
	 *
	 * Without this, "no policy outside RetrySchedule" could be satisfied by a
	 * RetrySchedule that declares no policy at all.
	 *
	 * @return void
	 */
	public function test_the_frozen_table_still_declares_the_three_values_the_sweep_looks_for(): void {
		$this->assertContains(
			self::RETRY_POLICY_HOME,
			$this->shipped_files(),
			sprintf( 'The frozen table is exempted at %s and there is no such shipped file, so the exemption covers nobody and the rule covers everybody.', self::RETRY_POLICY_HOME )
		);

		$this->assertNotSame( array(), RetrySchedule::INTERVALS, 'The frozen table declares no interval at all, which would mean no delivery is ever retried.' );
		$this->assertGreaterThan( 0, RetrySchedule::MAX_ATTEMPTS, 'The frozen table declares no maximum number of attempts.' );
		$this->assertGreaterThan( 0.0, RetrySchedule::JITTER_RATIO, 'The frozen table declares no proportion of noise.' );
	}

	/**
	 * With its exemption lifted, the detector reports the frozen table itself,
	 * and reports all three of the things it hunts.
	 *
	 * @return void
	 */
	public function test_the_detector_reports_the_frozen_table_itself_when_its_exemption_is_lifted(): void {
		$findings = $this->retry_policy_findings( array( self::RETRY_POLICY_HOME ), null );

		$kinds = array_unique( array_column( $findings, 'name' ) );
		sort( $kinds );

		$this->assertSame(
			array( 'a maximum number of attempts', 'a proportion of noise', 'a table of waits' ),
			$kinds,
			"Pointed at the frozen table with no exemption, the detector should report the three things it hunts, and it reported:\n" . $this->format( $findings )
		);

		foreach ( $findings as $finding ) {
			$this->assertGreaterThan( 0, $finding['line'], 'A retry policy was reported without a usable line number.' );
		}
	}

	/**
	 * The parity check that keeps the copied table honest is still in place, and
	 * so is the reference it compares against.
	 *
	 * This gate proves there is only ONE table. That the one table still says
	 * what the platform says is the parity check's job, and a rule of one
	 * without the other would leave a single table free to drift.
	 *
	 * @return void
	 */
	public function test_the_parity_check_of_the_copied_table_is_still_in_place(): void {
		$this->assertFileExists(
			$this->repository_root() . '/' . self::RETRY_PARITY_CHECK,
			sprintf( 'The parity check %s is gone. This gate would still prove there is a single backoff table, and nothing at all would prove that it matches the single retry calculator of the platform.', self::RETRY_PARITY_CHECK )
		);

		$this->assertFileExists(
			$this->repository_root() . '/' . RetrySchedule::REFERENCE_FILE,
			sprintf( 'The reference file named by RetrySchedule::REFERENCE_FILE (%s) is not on disk, so the parity check has nothing to compare the copy against.', RetrySchedule::REFERENCE_FILE )
		);
	}

	/**
	 * Each shape of request the sweep hunts is reported, naming the file and the
	 * line.
	 *
	 * The source is held here rather than on disk on purpose: a fixture file
	 * carrying a real `wp_remote_post` would be a file of this repository that
	 * emits a request, which is the very thing being forbidden, and the first
	 * person to sweep the repository with a plain search would find it.
	 *
	 * @return void
	 */
	public function test_a_forbidden_call_is_reported_with_its_file_and_its_line(): void {
		$findings = $this->egress_findings_in_source( 'synthetic/every-shape.php', $this->source_with_every_shape_of_request() );

		$reported = array();
		foreach ( $findings as $finding ) {
			$reported[ $finding['symbol'] ] = $finding['line'];
		}

		foreach (
			array(
				'wp_remote_post',
				'wp_remote_get',
				'wp_safe_remote_request',
				'curl_init',
				'curl_exec',
				'fsockopen',
				'stream_socket_client',
				'file_get_contents',
				'fopen',
				'WP_Http',
			) as $symbol
		) {
			$this->assertArrayHasKey(
				$symbol,
				$reported,
				sprintf( 'The sweep stopped recognising %s as a way out of the process. Reported instead: %s', $symbol, $this->format( $findings ) )
			);
			$this->assertGreaterThan( 0, $reported[ $symbol ], sprintf( 'The finding for %s carries no usable line number.', $symbol ) );
		}

		foreach ( $findings as $finding ) {
			$this->assertSame(
				'synthetic/every-shape.php',
				$finding['path'],
				'A finding named a file other than the one being swept, so the path a reader would open is not the file the finding came from.'
			);
		}
	}

	/**
	 * A forbidden name reached through a string is reported too, and under its
	 * own label.
	 *
	 * @return void
	 */
	public function test_a_forbidden_name_reached_through_a_string_is_reported_too(): void {
		$findings = $this->egress_findings_in_source( 'synthetic/dynamic.php', $this->source_with_a_dynamic_call() );

		$symbols = array_column( $findings, 'symbol' );

		$this->assertContains(
			'wp_remote_post',
			$symbols,
			'A request made through call_user_func was not reported. The name of the function is a string there, and a sweep that only reads call syntax walks straight past it.'
		);

		$kinds = array_unique( array_column( $findings, 'kind' ) );

		$this->assertContains(
			'name reached through a string',
			$kinds,
			'The dynamic call was reported under the same label as a direct one, so the reader cannot tell which of the two they are looking at.'
		);
	}

	/**
	 * Reading a response that already arrived is not a request.
	 *
	 * Reporting the accessors would make the rule unusable inside the one file
	 * allowed to make a request, and the tempting fix for that is to loosen the
	 * rule for everybody.
	 *
	 * @return void
	 */
	public function test_the_accessors_of_a_response_are_not_reported(): void {
		$findings = $this->egress_findings_in_source( 'synthetic/accessors.php', $this->source_with_response_accessors() );

		$this->assertSame(
			array(),
			$findings,
			"Reading a response that has already arrived was reported as a request:\n" . $this->format( $findings )
		);
	}

	/**
	 * A name that appears only in a comment is a mention and not a call.
	 *
	 * This is not hypothetical: the ledger and the table of the inbound half
	 * explain in their documentation blocks why they claim a row the way the
	 * outbox does, and a textual search reports every one of those sentences.
	 *
	 * @return void
	 */
	public function test_a_name_that_only_appears_in_a_comment_is_not_reported(): void {
		$source = $this->source_with_names_only_in_comments();

		$this->assertSame(
			array(),
			$this->egress_findings_in_source( 'synthetic/comments.php', $source ),
			'A request named in a comment was reported as a request. A gate that reports prose is a gate whose first fix is an exemption.'
		);

		$this->assertSame(
			array(),
			$this->class_reference_findings_in_source( 'synthetic/comments.php', $source, self::OUTBOX_CLASSES ),
			'A class named in a documentation block was reported as a reference to it.'
		);
	}

	/**
	 * A label that happens to be an ordinary English word is not a dynamic call.
	 *
	 * Measured, not imagined: the settings screen of this plugin carries a
	 * button labelled "Copy", and `copy` is one of the forbidden functions. A
	 * rule that reported it would have exactly one possible fix - rename the
	 * button - and would be deleted the second time it fired.
	 *
	 * @return void
	 */
	public function test_an_ordinary_word_in_a_label_is_not_reported_as_a_dynamic_call(): void {
		$findings = $this->egress_findings_in_source( 'synthetic/labels.php', $this->source_with_ordinary_words_as_labels() );

		$this->assertSame(
			array(),
			$findings,
			"A user-facing label was reported as a function reached through a string:\n" . $this->format( $findings )
		);

		$dynamic = $this->egress_findings_in_source( 'synthetic/dynamic.php', $this->source_with_a_dynamic_call() );

		$this->assertNotSame(
			array(),
			$dynamic,
			'The narrowing that lets ordinary words through also let a real dynamic call through, which trades one hole for another.'
		);
	}

	/**
	 * A method that happens to share the name of a forbidden function is not one.
	 *
	 * @return void
	 */
	public function test_a_method_that_shares_the_name_of_a_forbidden_function_is_not_reported(): void {
		$findings = $this->egress_findings_in_source( 'synthetic/methods.php', $this->source_with_methods_of_the_same_name() );

		$this->assertSame(
			array(),
			$findings,
			"A method call was reported as a call to the global function of the same name:\n" . $this->format( $findings )
		);
	}

	/**
	 * A second retry policy is reported, naming the file, the line and which of
	 * the three things it declares.
	 *
	 * @return void
	 */
	public function test_a_second_retry_policy_is_reported_with_its_file_and_its_line(): void {
		$findings = $this->retry_policy_findings_in_source( 'synthetic/policy.php', $this->source_with_a_second_retry_policy() );

		$kinds = array_unique( array_column( $findings, 'name' ) );
		sort( $kinds );

		$this->assertSame(
			array( 'a maximum number of attempts', 'a proportion of noise', 'a table of waits' ),
			$kinds,
			"A second retry policy was not reported in full. Reported:\n" . $this->format( $findings )
		);

		foreach ( $findings as $finding ) {
			$this->assertSame( 'synthetic/policy.php', $finding['path'] );
			$this->assertGreaterThan( 0, $finding['line'] );
		}

		$copy = $this->frozen_wait_copy_findings_in_source( 'synthetic/policy.php', $this->source_with_a_copy_of_the_frozen_waits() );

		$this->assertNotSame(
			array(),
			$copy,
			'A table holding the waits of the frozen schedule under an innocuous name was not reported. Reading names alone is exactly the hole this second rule exists to close.'
		);
	}

	/**
	 * An ordinary duration is not a retry policy.
	 *
	 * The package declares a drain interval, a claim timeout and a request
	 * timeout, and every one of them is a duration that has nothing to do with
	 * retrying a delivery. A rule that reported them would be turned off within
	 * the week.
	 *
	 * @return void
	 */
	public function test_an_ordinary_duration_is_not_reported_as_a_retry_policy(): void {
		$findings = $this->retry_policy_findings_in_source( 'synthetic/durations.php', $this->source_with_ordinary_durations() );

		$this->assertSame(
			array(),
			$findings,
			"An ordinary duration was reported as a retry policy:\n" . $this->format( $findings )
		);
	}

	/**
	 * Requests found in the given files, except in the exempted ones.
	 *
	 * @param string[] $files  Paths relative to the repository root.
	 * @param string[] $exempt Paths relative to the repository root that may emit a request.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function egress_findings( array $files, array $exempt ): array {
		$findings = array();

		foreach ( $files as $file ) {
			if ( in_array( $file, $exempt, true ) ) {
				continue;
			}

			$findings = array_merge( $findings, $this->egress_findings_in_source( $file, $this->source_of( $file ) ) );
		}

		return $findings;
	}

	/**
	 * Requests found in one piece of source.
	 *
	 * @param string $label  Path reported with each finding.
	 * @param string $source PHP source to sweep.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function egress_findings_in_source( string $label, string $source ): array {
		$tokens   = $this->significant_tokens( $source );
		$findings = array();
		$total    = count( $tokens );

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];

			$called = $this->called_name( $tokens, $index );

			if ( null !== $called ) {
				$kind = $this->egress_kind_of( $called );

				if ( null !== $kind ) {
					$findings[] = $this->finding( $label, $token['line'], $called, 'call', $kind );
					continue;
				}
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $token['id'] ) {
				$literal = $this->string_value( $token['text'] );
				$name    = strtolower( $literal );
				$kind    = $this->egress_kind_of( $name );

				if ( null !== $kind && $this->is_unmistakably_a_function_name( $name ) ) {
					$findings[] = $this->finding( $label, $token['line'], $literal, 'name reached through a string', $kind );
				}

				continue;
			}

			$referenced = $this->referenced_class( $tokens, $index );

			if ( null !== $referenced && in_array( $referenced, self::EGRESS_CLASSES, true ) ) {
				$findings[] = $this->finding( $label, $token['line'], $referenced, 'class', 'a request object of its own' );
			}
		}

		return $findings;
	}

	/**
	 * References to the given classes found in the given files, except in the
	 * exempted ones.
	 *
	 * @param string[] $files   Paths relative to the repository root.
	 * @param string[] $classes Short class names that may not be named.
	 * @param string[] $exempt  Paths relative to the repository root allowed to name them.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function class_reference_findings( array $files, array $classes, array $exempt ): array {
		$findings = array();

		foreach ( $files as $file ) {
			if ( in_array( $file, $exempt, true ) ) {
				continue;
			}

			$findings = array_merge( $findings, $this->class_reference_findings_in_source( $file, $this->source_of( $file ), $classes ) );
		}

		return $findings;
	}

	/**
	 * References to the given classes found in one piece of source.
	 *
	 * @param string   $label   Path reported with each finding.
	 * @param string   $source  PHP source to sweep.
	 * @param string[] $classes Short class names that may not be named.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function class_reference_findings_in_source( string $label, string $source, array $classes ): array {
		$tokens   = $this->significant_tokens( $source );
		$findings = array();
		$total    = count( $tokens );

		for ( $index = 0; $index < $total; $index++ ) {
			$referenced = $this->referenced_class( $tokens, $index );

			if ( null === $referenced || ! in_array( $referenced, $classes, true ) ) {
				continue;
			}

			$findings[] = $this->finding( $label, $tokens[ $index ]['line'], $referenced, 'class', 'named in code' );
		}

		return $findings;
	}

	/**
	 * Retry policies declared in the given files, except in the one allowed to
	 * declare them.
	 *
	 * @param string[]    $files Paths relative to the repository root.
	 * @param string|null $home  Path allowed to declare a policy, or null for none.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function retry_policy_findings( array $files, ?string $home ): array {
		$findings = array();

		foreach ( $files as $file ) {
			if ( $file === $home ) {
				continue;
			}

			$findings = array_merge( $findings, $this->retry_policy_findings_in_source( $file, $this->source_of( $file ) ) );
		}

		return $findings;
	}

	/**
	 * Retry policies declared in one piece of source.
	 *
	 * A declaration is a constant, a property or a variable given a value. What
	 * makes it a POLICY and not ordinary vocabulary is the pair of name and
	 * value: the name has to speak of waiting between attempts, of a ceiling on
	 * them or of noise, and the value has to be a number. The package is full of
	 * names that speak of retrying - the header a receiver answers with, the
	 * field of a rejection body, the label of a classification - and every one of
	 * them holds a string.
	 *
	 * @param string $label  Path reported with each finding.
	 * @param string $source PHP source to sweep.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function retry_policy_findings_in_source( string $label, string $source ): array {
		$findings = array();

		foreach ( $this->declarations( $source ) as $declaration ) {
			$name = $declaration['name'];

			if ( ! $declaration['has_number'] || $declaration['has_string'] ) {
				continue;
			}

			if ( 1 === preg_match( self::ATTEMPT_CAP_PATTERN, $name ) ) {
				$findings[] = $this->finding( $label, $declaration['line'], $name, 'declaration', 'a maximum number of attempts' );
				continue;
			}

			if ( 1 === preg_match( self::NOISE_PATTERN, $name ) ) {
				$findings[] = $this->finding( $label, $declaration['line'], $name, 'declaration', 'a proportion of noise' );
				continue;
			}

			$is_table = 1 === preg_match( self::WAIT_TABLE_PATTERN, $name )
				|| ( $declaration['is_array'] && $declaration['numbers'] >= 2 && 1 === preg_match( self::WAIT_NAME_PATTERN, $name ) );

			if ( $is_table ) {
				$findings[] = $this->finding( $label, $declaration['line'], $name, 'declaration', 'a table of waits' );
			}
		}

		return $findings;
	}

	/**
	 * Files that repeat the waits of the frozen table, except the one allowed to
	 * hold them.
	 *
	 * @param string[]    $files Paths relative to the repository root.
	 * @param string|null $home  Path allowed to hold them, or null for none.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function frozen_wait_copy_findings( array $files, ?string $home ): array {
		$findings = array();

		foreach ( $files as $file ) {
			if ( $file === $home ) {
				continue;
			}

			$findings = array_merge( $findings, $this->frozen_wait_copy_findings_in_source( $file, $this->source_of( $file ) ) );
		}

		return $findings;
	}

	/**
	 * Waits of the frozen table repeated in one piece of source.
	 *
	 * Three of them is the threshold, and it is deliberate: the shortest waits
	 * are round numbers of seconds that any duration might use, and a file that
	 * happens to hold one of them is not a copy of anything. Three of the seven
	 * in the same file is not a coincidence. The values are read FROM the
	 * constant, so a table that the platform changes tomorrow is still the table
	 * this rule hunts for.
	 *
	 * @param string $label  Path reported with each finding.
	 * @param string $source PHP source to sweep.
	 * @return array<int, array{path: string, line: int, symbol: string, kind: string, name: string}>
	 */
	private function frozen_wait_copy_findings_in_source( string $label, string $source ): array {
		$waits = array_values( RetrySchedule::INTERVALS );
		$seen  = array();
		$line  = 0;

		foreach ( $this->significant_tokens( $source ) as $token ) {
			if ( T_LNUMBER !== $token['id'] ) {
				continue;
			}

			$value = (int) $token['text'];

			if ( ! in_array( $value, $waits, true ) || in_array( $value, $seen, true ) ) {
				continue;
			}

			$seen[] = $value;

			if ( 0 === $line ) {
				$line = $token['line'];
			}
		}

		if ( count( $seen ) < 3 ) {
			return array();
		}

		sort( $seen );

		return array(
			$this->finding(
				$label,
				$line,
				implode( ', ', $seen ),
				'values',
				'waits of the frozen backoff table, repeated here'
			),
		);
	}

	/**
	 * Every declaration of the source, with what its name is and what shape its
	 * value has.
	 *
	 * @param string $source PHP source to read.
	 * @return array<int, array{name: string, line: int, has_number: bool, has_string: bool, is_array: bool, numbers: int}>
	 */
	private function declarations( string $source ): array {
		$tokens       = $this->significant_tokens( $source );
		$total        = count( $tokens );
		$declarations = array();

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];
			$name  = null;
			$value = $index;

			if ( T_CONST === $token['id'] && isset( $tokens[ $index + 1 ] ) && T_STRING === $tokens[ $index + 1 ]['id'] ) {
				$name  = $tokens[ $index + 1 ]['text'];
				$value = $index + 2;
			} elseif ( T_VARIABLE === $token['id'] ) {
				$name  = ltrim( $token['text'], '$' );
				$value = $index + 1;
			}

			if ( null === $name || ! isset( $tokens[ $value ] ) || '=' !== $tokens[ $value ]['text'] ) {
				continue;
			}

			$declarations[] = $this->value_shape( $tokens, $value + 1 ) + array(
				'name' => $name,
				'line' => $token['line'],
			);
		}

		return $declarations;
	}

	/**
	 * Shape of the value that starts at the given token and runs to the end of
	 * its statement.
	 *
	 * @param array<int, array{id: int|null, text: string, line: int}> $tokens Token stream.
	 * @param int                                                      $start  Index of the first token of the value.
	 * @return array{has_number: bool, has_string: bool, is_array: bool, numbers: int}
	 */
	private function value_shape( array $tokens, int $start ): array {
		$depth      = 0;
		$has_number = false;
		$has_string = false;
		$numbers    = 0;
		$total      = count( $tokens );
		$is_array   = isset( $tokens[ $start ] )
			&& ( T_ARRAY === $tokens[ $start ]['id'] || '[' === $tokens[ $start ]['text'] );

		for ( $index = $start; $index < $total; $index++ ) {
			$text = $tokens[ $index ]['text'];

			if ( in_array( $text, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				--$depth;

				if ( $depth < 0 ) {
					break;
				}
			} elseif ( ( ';' === $text || ',' === $text ) && 0 === $depth ) {
				break;
			}

			if ( T_LNUMBER === $tokens[ $index ]['id'] || T_DNUMBER === $tokens[ $index ]['id'] ) {
				$has_number = true;
				++$numbers;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $index ]['id'] || T_ENCAPSED_AND_WHITESPACE === $tokens[ $index ]['id'] ) {
				$has_string = true;
			}
		}

		return array(
			'has_number' => $has_number,
			'has_string' => $has_string,
			'is_array'   => $is_array,
			'numbers'    => $numbers,
		);
	}

	/**
	 * Name of the function called at the given token, or null when that token is
	 * not a function call.
	 *
	 * @param array<int, array{id: int|null, text: string, line: int}> $tokens Token stream.
	 * @param int                                                      $index  Index of the token to look at.
	 * @return string|null Lowercased name without its namespace, or null.
	 */
	private function called_name( array $tokens, int $index ): ?string {
		$token = $tokens[ $index ];

		if ( ! in_array( $token['id'], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			return null;
		}

		if ( ! isset( $tokens[ $index + 1 ] ) || '(' !== $tokens[ $index + 1 ]['text'] ) {
			return null;
		}

		if ( $index > 0 && $this->is_member_or_declaration( $tokens[ $index - 1 ] ) ) {
			return null;
		}

		return strtolower( $this->short_name( $token['text'] ) );
	}

	/**
	 * Short name of the class referenced at the given token, or null when that
	 * token does not reference a class.
	 *
	 * @param array<int, array{id: int|null, text: string, line: int}> $tokens Token stream.
	 * @param int                                                      $index  Index of the token to look at.
	 * @return string|null Short class name, or null.
	 */
	private function referenced_class( array $tokens, int $index ): ?string {
		$token = $tokens[ $index ];

		if ( ! in_array( $token['id'], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			return null;
		}

		if ( $index > 0 && $this->is_member_or_declaration( $tokens[ $index - 1 ] ) ) {
			return null;
		}

		return $this->short_name( $token['text'] );
	}

	/**
	 * Whether the given token makes the name that follows it a member of
	 * something or the name of a declaration, rather than a global call or a
	 * class reference.
	 *
	 * @param array{id: int|null, text: string, line: int} $token Token before the name.
	 * @return bool
	 */
	private function is_member_or_declaration( array $token ): bool {
		if ( in_array( $token['text'], array( '->', '?->', '::' ), true ) ) {
			return true;
		}

		return in_array(
			$token['id'],
			array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST ),
			true
		);
	}

	/**
	 * Which way out of the process the given function name is, or null when it
	 * is not one.
	 *
	 * @param string $name Lowercased function name without its namespace.
	 * @return string|null
	 */
	private function egress_kind_of( string $name ): ?string {
		if ( str_starts_with( $name, self::REMOTE_ACCESSOR_PREFIX ) ) {
			return null;
		}

		if ( str_starts_with( $name, 'wp_remote_' ) || str_starts_with( $name, 'wp_safe_remote_' ) ) {
			return 'a WordPress remote request';
		}

		if ( str_starts_with( $name, 'curl_' ) ) {
			return 'a raw cURL handle';
		}

		if ( in_array( $name, self::SOCKET_FUNCTIONS, true ) ) {
			return 'a raw socket';
		}

		if ( in_array( $name, self::URL_CAPABLE_STREAM_FUNCTIONS, true ) ) {
			return 'a stream function that accepts a URL';
		}

		return null;
	}

	/**
	 * Whether a name is one nobody would write by accident.
	 *
	 * Two of the forbidden functions are also ordinary English words - `copy`
	 * and `file` - and the settings screen of this plugin really does carry a
	 * button labelled "Copy". Reported as a dynamic call, that label would be a
	 * finding whose only possible fix is to rename a button, and the second such
	 * finding is the one that gets the whole rule deleted.
	 *
	 * A name written in a string is therefore only taken as a function name when
	 * it could not be prose: when it carries an underscore, or when it is one of
	 * the handful of single words that are not words. Those two functions remain
	 * forbidden as CALLS, where there is nothing to mistake.
	 *
	 * @param string $name Lowercased name as the literal held it.
	 * @return bool
	 */
	private function is_unmistakably_a_function_name( string $name ): bool {
		if ( str_contains( $name, '_' ) ) {
			return true;
		}

		return in_array( $name, array( 'fopen', 'readfile', 'fsockopen', 'pfsockopen' ), true );
	}

	/**
	 * One finding, in the shape every rule of this file reports.
	 *
	 * @param string $path   Path the finding belongs to.
	 * @param int    $line   Line the finding belongs to.
	 * @param string $symbol Symbol that was found.
	 * @param string $kind   How it was found: a call, a string, a class, a declaration.
	 * @param string $name   What it is, in words.
	 * @return array{path: string, line: int, symbol: string, kind: string, name: string}
	 */
	private function finding( string $path, int $line, string $symbol, string $kind, string $name ): array {
		return array(
			'path'   => $path,
			'line'   => $line,
			'symbol' => $symbol,
			'kind'   => $kind,
			'name'   => $name,
		);
	}

	/**
	 * The findings written out one per line, file and line first.
	 *
	 * @param array<int, array{path: string, line: int, symbol: string, kind: string, name: string}> $findings Findings to write out.
	 * @return string
	 */
	private function format( array $findings ): string {
		$lines = array();

		foreach ( $findings as $finding ) {
			$lines[] = sprintf(
				'  %s:%d: %s (%s) - %s',
				$finding['path'],
				$finding['line'],
				$finding['symbol'],
				$finding['kind'],
				$finding['name']
			);
		}

		return implode( PHP_EOL, $lines );
	}

	/**
	 * The tokens of a piece of source, without whitespace and without anything
	 * that is prose.
	 *
	 * @param string $source PHP source to read.
	 * @return array<int, array{id: int|null, text: string, line: int}>
	 */
	private function significant_tokens( string $source ): array {
		$significant = array();
		$line        = 1;

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				$line = $token[2];

				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$significant[] = array(
					'id'   => $token[0],
					'text' => $token[1],
					'line' => $token[2],
				);

				continue;
			}

			$significant[] = array(
				'id'   => null,
				'text' => $token,
				'line' => $line,
			);
		}

		return $significant;
	}

	/**
	 * The last segment of a possibly namespaced name.
	 *
	 * @param string $name Name as it was written.
	 * @return string
	 */
	private function short_name( string $name ): string {
		$segments = explode( '\\', $name );

		return (string) end( $segments );
	}

	/**
	 * The text a single quoted or double quoted literal holds.
	 *
	 * @param string $literal Literal as it was written, quotes included.
	 * @return string
	 */
	private function string_value( string $literal ): string {
		return trim( $literal, "'\"" );
	}

	/**
	 * Every PHP file the published artefact carries, relative to the repository
	 * root and in a stable order.
	 *
	 * @return string[]
	 */
	private function shipped_files(): array {
		$files = $this->php_files_under( self::SHIPPED_DIRECTORY );

		foreach ( self::SHIPPED_ROOT_FILES as $root_file ) {
			if ( is_file( $this->repository_root() . '/' . $root_file ) ) {
				$files[] = $root_file;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * The files of the inbound half: the directory that holds it, plus anything
	 * else that handles a payload which only exists because something arrived.
	 *
	 * @return string[]
	 */
	private function inbound_files(): array {
		$files = $this->php_files_under( self::INBOUND_DIRECTORY );

		foreach ( $this->shipped_files() as $file ) {
			if ( in_array( $file, $files, true ) ) {
				continue;
			}

			if ( array() !== $this->class_reference_findings_in_source( $file, $this->source_of( $file ), self::INBOUND_PAYLOAD_CLASSES ) ) {
				$files[] = $file;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Every PHP file under a directory of the repository, relative to the root
	 * and in a stable order.
	 *
	 * @param string $directory Directory relative to the repository root.
	 * @return string[]
	 */
	private function php_files_under( string $directory ): array {
		$absolute = $this->repository_root() . '/' . $directory;

		if ( ! is_dir( $absolute ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $absolute, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
				continue;
			}

			$files[] = $this->relative( (string) $entry->getRealPath() );
		}

		sort( $files );

		return $files;
	}

	/**
	 * The source of a file of this repository.
	 *
	 * @param string $file Path relative to the repository root.
	 * @return string
	 */
	private function source_of( string $file ): string {
		$path = $this->repository_root() . '/' . $file;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A unit suite that runs on bare PHP with no WordPress loaded, reading a file of this repository from disk. WP_Filesystem does not exist here, and the remote alternative the sniff suggests is the very thing this gate forbids.
		$source = file_get_contents( $path );

		$this->assertIsString( $source, sprintf( 'The file %s could not be read, so no rule of this gate was applied to it.', $file ) );

		return $source;
	}

	/**
	 * A path of this repository, relative to its root.
	 *
	 * @param string $absolute Absolute path.
	 * @return string
	 */
	private function relative( string $absolute ): string {
		return ltrim( str_replace( $this->repository_root(), '', $absolute ), '/' );
	}

	/**
	 * Absolute path of the repository root.
	 *
	 * @return string
	 */
	private function repository_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Source carrying one of every shape of request the sweep hunts.
	 *
	 * @return string
	 */
	private function source_with_every_shape_of_request(): string {
		return <<<'PHP'
<?php
function factuarea_synthetic_every_shape( $url, $handle ) {
	wp_remote_post( $url, array() );
	wp_remote_get( $url );
	\wp_safe_remote_request( $url );
	$handle = curl_init( $url );
	curl_exec( $handle );
	fsockopen( 'example.test', 80 );
	stream_socket_client( 'tcp://example.test:80' );
	file_get_contents( $url );
	fopen( $url, 'r' );
	$http = new WP_Http();

	return $http;
}
PHP;
	}

	/**
	 * Source reaching a forbidden function through its name as a string.
	 *
	 * @return string
	 */
	private function source_with_a_dynamic_call(): string {
		return <<<'PHP'
<?php
function factuarea_synthetic_dynamic( $url ) {
	$name = 'wp_remote_post';

	return call_user_func( $name, $url );
}
PHP;
	}

	/**
	 * Source reading a response that has already arrived.
	 *
	 * @return string
	 */
	private function source_with_response_accessors(): string {
		return <<<'PHP'
<?php
function factuarea_synthetic_accessors( $response ) {
	return array(
		'status'  => wp_remote_retrieve_response_code( $response ),
		'headers' => wp_remote_retrieve_headers( $response ),
		'body'    => wp_remote_retrieve_body( $response ),
	);
}
PHP;
	}

	/**
	 * Source carrying user-facing labels that are also the names of forbidden
	 * functions.
	 *
	 * @return string
	 */
	private function source_with_ordinary_words_as_labels(): string {
		return <<<'PHP'
<?php
function factuarea_synthetic_labels() {
	return array(
		'copy_button' => 'Copy',
		'file_button' => 'File',
		'copy_hint'   => 'copy',
	);
}
PHP;
	}

	/**
	 * Source naming forbidden things only where a reader would, and never where
	 * the process would.
	 *
	 * @return string
	 */
	private function source_with_names_only_in_comments(): string {
		return <<<'PHP'
<?php
/**
 * Claims a row the same way Outbox\OutboxRepository claims one in the opposite
 * direction, and deliberately never calls wp_remote_post() or curl_exec() to
 * tell anybody about it.
 */
function factuarea_synthetic_comments( $key ) {
	// The drain of OutboxDispatcher is what calls wp_remote_post(); this does not.
	return $key;
}
PHP;
	}

	/**
	 * Source calling methods that happen to share the name of a forbidden
	 * function.
	 *
	 * @return string
	 */
	private function source_with_methods_of_the_same_name(): string {
		return <<<'PHP'
<?php
class Factuarea_Synthetic_Methods {
	public function fopen( $path ) {
		return $path;
	}

	public function read( $path ) {
		return $this->fopen( $path );
	}

	public static function file_get_contents( $path ) {
		return $path;
	}

	public function read_static( $path ) {
		return self::file_get_contents( $path );
	}
}
PHP;
	}

	/**
	 * Source declaring a second retry policy of its own.
	 *
	 * @return string
	 */
	private function source_with_a_second_retry_policy(): string {
		return <<<'PHP'
<?php
class Factuarea_Synthetic_Policy {
	public const RETRY_INTERVALS = array( 30, 90, 270 );

	public const MAX_ATTEMPTS = 4;

	public const JITTER_RATIO = 0.25;
}
PHP;
	}

	/**
	 * Source holding the waits of the frozen table under a name that says
	 * nothing.
	 *
	 * @return string
	 */
	private function source_with_a_copy_of_the_frozen_waits(): string {
		$waits = array_values( RetrySchedule::INTERVALS );

		return sprintf(
			"<?php\nclass Factuarea_Synthetic_Copy {\n\tpublic const STEPS = array( %s );\n}\n",
			implode( ', ', array_slice( $waits, 0, 4 ) )
		);
	}

	/**
	 * Source declaring the ordinary durations the package really has.
	 *
	 * @return string
	 */
	private function source_with_ordinary_durations(): string {
		return <<<'PHP'
<?php
class Factuarea_Synthetic_Durations {
	public const DRAIN_INTERVAL_SECONDS = 60;

	public const DRAIN_BATCH_SIZE = 10;

	public const CLAIM_TIMEOUT_SECONDS = 300;

	public const REQUEST_TIMEOUT_SECONDS = 15;

	public const RETRY_AFTER_HEADER = 'Retry-After';

	public const REJECTION_BODY_RETRY_AFTER_FIELD = 'retry_after';

	public const CLASSIFICATION_RETRYABLE = 'retryable';

	public function plan( $attempt, $now ) {
		$base_wait = 60;
		$attempts  = $attempt + 1;

		return $now + $base_wait + $attempts;
	}
}
PHP;
	}
}
