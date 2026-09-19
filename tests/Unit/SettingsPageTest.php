<?php
/**
 * Unit test of the settings screen, the one-time signing secret and the stalled
 * outbox notice.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Admin\ConnectionTester;
use Factuarea\WooCommerce\Admin\SettingsPage;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Outbox\OutboxRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What this test holds.
 *
 * Six groups, and the fifth is the one that would be tempting to leave out.
 *
 *  1. REGISTRATION. The screen, the eight options and the seven fields are
 *     registered, in one group, with the shop manager capability - and every
 *     option carries `show_in_rest` set to false, which is what keeps the two
 *     credentials out of a settings export. Two of the eight are ADDRESSES, and
 *     the pair is asserted apart: deliveries go to one host and the v1 reads to
 *     another, so a screen with one address field would make one of the two
 *     answer 404 whichever value the merchant typed.
 *
 *  2. THE CONNECTION TEST. The button is rendered behind a nonce, hooked to
 *     the administration action it posts to, refused without the shop manager
 *     capability, and its result - message AND next step - comes back as a
 *     notice on this screen. What the test itself decides is asserted where it
 *     lives.
 *
 *  3. THE SIGNING SECRET. It is generated from a cryptographically secure
 *     source, it has the declared length, and it is shown EXACTLY once. The
 *     "once" is asserted by rendering the screen twice and reading the two
 *     outputs, not by inspecting a flag: a flag that is written and never read
 *     would pass a test about the flag and still show the secret twice.
 *
 *  4. THE STALLED OUTBOX NOTICE. It appears with the right counts and stays
 *     away from a healthy outbox. The counts come from the outbox repository,
 *     which is doubled here: what this class is responsible for is the decision
 *     and the wording, not the query, and the query is tested against a real
 *     database in its own phase.
 *
 *  5. THE SWEEP. The rendered screen and the diagnostic block are read back and
 *     searched for the two stored credentials. It is the check that catches the
 *     mistake nobody makes on purpose - a field pre-filled "so the merchant can
 *     see what is configured", a masked form that keeps four characters, a
 *     debug entry added later by somebody who did not read the prohibition. The
 *     search looks for the WHOLE value and for a four-character fragment of it,
 *     because a truncated secret is not a safer secret, only a shorter one.
 *
 *  6. THE TWO RECEIVERS. Their switches are registered disabled, they answer to
 *     one rule, the address printed next to each one is COMPOSED from the
 *     address of this site rather than written out, the screen warns about the
 *     one combination that rejects everything silently - a receiver switched on
 *     with no signing secret stored - and nothing on that surface consults an
 *     option other than the switch itself and the secret. The last one is the
 *     no-paywall promise written as a measurement instead of as a word list:
 *     a receiver gated behind a payment would have to read something to know
 *     whether it was paid for, and this reads the option names it asks for.
 */
final class SettingsPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Stored API key used throughout. Recognisable on sight so that a sweep
	 * failure names something a reader can find.
	 */
	private const STORED_API_KEY = 'fk_live_APIKEYVALUE0123456789';

	/**
	 * Stored signing secret used throughout.
	 */
	private const STORED_SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

	/**
	 * The options as the doubles of `get_option` and `update_option` see them.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Everything handed to `register_setting`, keyed by option name.
	 *
	 * @var array<string, array{group: string, args: array<string, mixed>}>
	 */
	private array $registered_settings = array();

	/**
	 * Everything handed to `add_settings_field`, keyed by field identifier.
	 *
	 * @var array<string, array{title: string, page: string, section: string, callback: callable}>
	 */
	private array $registered_fields = array();

	/**
	 * Everything handed to `add_settings_section`, in the order it arrived.
	 *
	 * @var array<int, array{page: string, callback: callable}>
	 */
	private array $registered_sections = array();

	/**
	 * Everything handed to `add_submenu_page`, in the order it arrived.
	 *
	 * @var array<int, array{parent: string, capability: string, slug: string}>
	 */
	private array $submenu_pages = array();

	/**
	 * What the double of `rest_url` prepends to a route.
	 *
	 * It is a property rather than a constant so that a test can move this site
	 * to another address - or to the query-argument form a site with plain
	 * permalinks serves - and read what the screen prints afterwards. An address
	 * that does not move when the site moves is an address written by hand.
	 *
	 * @var string
	 */
	private string $rest_base = 'https://shop.example/wp-json/';

	/**
	 * Every option name `get_option` was asked for, in the order it was asked.
	 *
	 * @var list<string>
	 */
	private array $read_options = array();

	/**
	 * Installs the doubles of the WordPress functions the class under test
	 * reaches for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->options = array(
			Constants::OPTION_BASE_URL         => 'https://app.factuarea.example',
			Constants::OPTION_API_BASE_URL     => 'https://api.factuarea.example',
			Constants::OPTION_API_KEY          => self::STORED_API_KEY,
			Constants::OPTION_STORE_ID         => 'store-0001',
			Constants::OPTION_SIGNING_SECRET   => self::STORED_SECRET,
			Constants::OPTION_DELIVERY_ENABLED => '1',
			Constants::OPTION_SECRET_REVEALED  => SettingsPage::SECRET_REVEALED,
		);
		$this->registered_settings = array();
		$this->registered_fields   = array();
		$this->registered_sections = array();
		$this->submenu_pages       = array();
		$this->rest_base           = 'https://shop.example/wp-json/';
		$this->read_options        = array();

		$this->capture_admin_registrations();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				$this->read_options[] = $name;

				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'settings_errors' )->justReturn( null );

		/*
		 * What WordPress does: with a permalink structure in place it hangs the
		 * route off the REST prefix of the site, and with plain permalinks it
		 * hands the route over as a query argument instead. The double composes
		 * from a base this test can move, which is the only way to tell an
		 * address that is built from this site apart from one that is typed.
		 */
		Functions\when( 'rest_url' )->alias(
			function ( string $path = '' ): string {
				return $this->rest_base . ltrim( $path, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url ) {
				return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This IS the double of the alternative function.
			}
		);

		/*
		 * `do_settings_sections` prints what WordPress would print: the section
		 * description and every field registered on that page. Stubbing it to
		 * nothing would leave the credential sweep reading a screen with no
		 * fields on it - which is exactly the screen where a pre-filled API key
		 * would sit, so the sweep would be passing over the thing it exists to
		 * look at.
		 */
		Functions\when( 'do_settings_sections' )->alias(
			function ( string $page ): void {
				foreach ( $this->registered_sections as $section ) {
					if ( $section['page'] === $page ) {
						( $section['callback'] )();
					}
				}

				foreach ( $this->registered_fields as $field ) {
					if ( $field['page'] === $page ) {
						( $field['callback'] )();
					}
				}
			}
		);
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $javascript ): void {
				echo '<script>' . $javascript . '</script>'; // phpcs:ignore
			}
		);
	}

	/**
	 * Removes the doubles.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The screen hangs off the WooCommerce menu and asks for the shop manager
	 * capability.
	 *
	 * @return void
	 */
	public function test_the_screen_is_added_under_woocommerce_with_the_shop_manager_capability(): void {
		( new SettingsPage() )->add_menu();

		$this->assertCount(
			1,
			$this->submenu_pages,
			'The plugin has to add exactly one screen: a second one would be a second place to configure the same connection.'
		);
		$this->assertSame( 'woocommerce', $this->submenu_pages[0]['parent'] );
		$this->assertSame(
			'manage_woocommerce',
			$this->submenu_pages[0]['capability'],
			'The screen configures an order integration, so the capability is the one a shop manager already has. Asking for manage_options would push the merchant into handing out an administrator account for it.'
		);
		$this->assertSame( SettingsPage::MENU_SLUG, $this->submenu_pages[0]['slug'] );
	}

	/**
	 * The eight options are registered, in one group, each with a sanitiser.
	 *
	 * @return void
	 */
	public function test_the_eight_settings_are_registered_with_a_sanitiser_each(): void {
		( new SettingsPage() )->register_settings();

		$expected = array(
			Constants::OPTION_BASE_URL,
			Constants::OPTION_API_BASE_URL,
			Constants::OPTION_API_KEY,
			Constants::OPTION_STORE_ID,
			Constants::OPTION_SIGNING_SECRET,
			Constants::OPTION_DELIVERY_ENABLED,
			Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED,
			Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED,
		);

		$this->assertSame(
			$expected,
			array_keys( $this->registered_settings ),
			'The screen has to register exactly the eight options the plugin stores on this screen, and no others: WordPress saves every option registered against a submitted page, so an option registered here that nothing renders is an option that gets overwritten on every save.'
		);

		foreach ( $expected as $option ) {
			$this->assertSame(
				SettingsPage::OPTION_GROUP,
				$this->registered_settings[ $option ]['group'],
				sprintf( 'Option "%s" has to belong to the one settings group the form submits.', $option )
			);
			$this->assertIsCallable(
				$this->registered_settings[ $option ]['args']['sanitize_callback'] ?? null,
				sprintf( 'Option "%s" has to declare a sanitiser: without one WordPress stores whatever was posted.', $option )
			);
		}
	}

	/**
	 * No option of this plugin, and least of all the two credentials, is exposed
	 * through the REST settings endpoint.
	 *
	 * @return void
	 */
	public function test_no_setting_is_exposed_to_the_rest_settings_export(): void {
		( new SettingsPage() )->register_settings();

		foreach ( $this->registered_settings as $option => $registration ) {
			$this->assertArrayHasKey(
				'show_in_rest',
				$registration['args'],
				sprintf( 'Option "%s" has to say what it does about REST. Omitting it means false today, which is right by accident.', $option )
			);
			$this->assertFalse(
				$registration['args']['show_in_rest'],
				sprintf(
					'Option "%s" is exposed through the REST settings endpoint. That endpoint is what a settings export reads, and for the two credentials it is how a signing secret leaves the installation inside a migration bundle.',
					$option
				)
			);
		}

		foreach ( Constants::CREDENTIAL_OPTIONS as $credential ) {
			$this->assertArrayHasKey(
				$credential,
				$this->registered_settings,
				sprintf( 'The credential option "%s" has to be registered, so that its sanitiser runs and its REST exposure is declared.', $credential )
			);
		}
	}

	/**
	 * The two sections and the seven visible fields are registered on the
	 * screen, each field in the section it belongs to.
	 *
	 * The signing secret is deliberately NOT among them: it has no input,
	 * because the only thing that sets it is the generation action.
	 *
	 * The two addresses both have one, and that is the point of the second: the
	 * v1 is served from a different host than the one deliveries are posted to,
	 * so one field could not hold both and the merchant has to be able to see
	 * and correct each.
	 *
	 * The two receiver switches sit in a section of their own, and the section
	 * is what makes the difference readable: everything above it is what this
	 * shop sends, and the two below are what it agrees to be told.
	 *
	 * @return void
	 */
	public function test_the_seven_visible_fields_are_registered_in_their_two_sections(): void {
		( new SettingsPage() )->register_settings();

		$expected = array(
			Constants::OPTION_BASE_URL                      => SettingsPage::SETTINGS_SECTION,
			Constants::OPTION_API_BASE_URL                  => SettingsPage::SETTINGS_SECTION,
			Constants::OPTION_API_KEY                       => SettingsPage::SETTINGS_SECTION,
			Constants::OPTION_STORE_ID                      => SettingsPage::SETTINGS_SECTION,
			Constants::OPTION_DELIVERY_ENABLED              => SettingsPage::SETTINGS_SECTION,
			Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED => SettingsPage::RECEIVERS_SECTION,
			Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED => SettingsPage::RECEIVERS_SECTION,
		);

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $this->registered_fields ),
			'The screen renders seven inputs. The signing secret is not one of them: it is generated, shown once and never edited by hand.'
		);

		$this->assertNotSame(
			SettingsPage::SETTINGS_SECTION,
			SettingsPage::RECEIVERS_SECTION,
			'The receivers are a section of their own, so the two identifiers cannot be the same string.'
		);

		foreach ( $this->registered_fields as $field => $registration ) {
			$this->assertSame( SettingsPage::MENU_SLUG, $registration['page'], sprintf( 'Field "%s" has to be registered on this plugin\'s screen.', $field ) );
			$this->assertSame( $expected[ $field ], $registration['section'], sprintf( 'Field "%s" is registered in the wrong section.', $field ) );
			$this->assertNotSame( '', $registration['title'], sprintf( 'Field "%s" has to carry a label.', $field ) );
		}

		$this->assertCount(
			2,
			$this->registered_sections,
			'Two sections, and both on this screen: the connection, and the two receivers.'
		);

		foreach ( $this->registered_sections as $section ) {
			$this->assertSame( SettingsPage::MENU_SLUG, $section['page'] );
		}
	}

	/**
	 * The address the v1 reads go to is a setting of its own, and it is not the
	 * address deliveries are posted to.
	 *
	 * The two are different hosts - the ingestion route hangs off the
	 * application, the v1 off its own name - so one field cannot serve both, and
	 * a screen that offered one would make either every delivery or every read
	 * answer 404 with nothing on it to correct.
	 *
	 * @return void
	 */
	public function test_the_api_address_is_a_setting_of_its_own(): void {
		( new SettingsPage() )->register_settings();

		$this->assertArrayHasKey( Constants::OPTION_API_BASE_URL, $this->registered_settings );
		$this->assertNotSame(
			Constants::OPTION_BASE_URL,
			Constants::OPTION_API_BASE_URL,
			'The delivery address and the API address are two settings because they are two hosts.'
		);
		$this->assertSame(
			Constants::DEFAULT_API_BASE_URL,
			$this->registered_settings[ Constants::OPTION_API_BASE_URL ]['args']['default'] ?? null,
			'The API address is the one setting a merchant of the hosted product never has to learn about, so it ships with the hosted address already in it.'
		);
		$this->assertSame(
			$this->registered_settings[ Constants::OPTION_BASE_URL ]['args']['sanitize_callback'],
			$this->registered_settings[ Constants::OPTION_API_BASE_URL ]['args']['sanitize_callback'],
			'Both addresses are cleaned the same way. Two sanitisers for two addresses is two places for the trailing slash rule to drift apart.'
		);
	}

	/**
	 * With nothing stored, the API address resolves to the default; with
	 * something stored, to that.
	 *
	 * @return void
	 */
	public function test_the_api_address_falls_back_to_the_default_and_loses_its_trailing_slash(): void {
		$this->assertSame( 'https://api.factuarea.example', SettingsPage::api_base_url() );

		$this->options[ Constants::OPTION_API_BASE_URL ] = '';
		$this->assertSame(
			Constants::DEFAULT_API_BASE_URL,
			SettingsPage::api_base_url(),
			'An empty API address is not a missing setting: it is the hosted address, which is right for every merchant who never opens that field.'
		);

		$this->options[ Constants::OPTION_API_BASE_URL ] = 'https://api.self.hosted.example/';
		$this->assertSame(
			'https://api.self.hosted.example',
			SettingsPage::api_base_url(),
			'Paths are appended to this address, so it is resolved without its trailing slash wherever it came from.'
		);
	}

	/**
	 * The rendered field shows the address that is in force, and the diagnostic
	 * block reports it.
	 *
	 * @return void
	 */
	public function test_the_api_address_is_rendered_and_reported(): void {
		$markup = $this->render( new SettingsPage() );

		$this->assertStringContainsString( 'https://api.factuarea.example', $markup );

		$info = ( new SettingsPage() )->debug_information( array() );

		$this->assertSame(
			'https://api.factuarea.example',
			$info[ SettingsPage::DEBUG_INFORMATION_SECTION ]['fields']['api_base_url']['debug'] ?? null,
			'The API address is not a credential and it is the first thing a support thread needs: a shop reading from the wrong host fails every read while every delivery works.'
		);
	}

	/**
	 * The generated secret has the declared length, is hexadecimal and differs
	 * from the previous one.
	 *
	 * @return void
	 */
	public function test_the_generated_secret_has_the_declared_length_and_comes_from_a_secure_source(): void {
		$first  = SettingsPage::generate_signing_secret();
		$second = SettingsPage::generate_signing_secret();

		$this->assertSame(
			SettingsPage::SIGNING_SECRET_LENGTH,
			strlen( $first ),
			sprintf( 'The secret has to be %d characters long, which is the hexadecimal form of %d random bytes.', SettingsPage::SIGNING_SECRET_LENGTH, SettingsPage::SIGNING_SECRET_BYTES )
		);
		$this->assertMatchesRegularExpression(
			'/\A[0-9a-f]+\z/',
			$first,
			'The secret is copied by hand between two screens, so it is hexadecimal: it survives a double-click selection and a form that trims padding.'
		);
		$this->assertNotSame(
			$first,
			$second,
			'Two generated secrets have to differ. A repeating secret would mean the source is not random, and a predictable signing secret is a forged sale in the merchant\'s books.'
		);
	}

	/**
	 * Generating a secret stores it and marks it as not yet shown.
	 *
	 * @return void
	 */
	public function test_rotating_stores_the_new_secret_and_marks_it_as_not_yet_shown(): void {
		$page = new SettingsPage();

		$secret = $page->rotate_signing_secret();

		$this->assertSame( $secret, $this->options[ Constants::OPTION_SIGNING_SECRET ] );
		$this->assertNotSame(
			SettingsPage::SECRET_REVEALED,
			(string) $this->options[ Constants::OPTION_SECRET_REVEALED ],
			'A newly generated secret has to be shown once. Leaving the previous "already shown" flag in place would store a secret the merchant never sees, and every delivery would then be rejected as unsigned with nothing on screen to explain it.'
		);
	}

	/**
	 * The secret comes back the first time and never again.
	 *
	 * @return void
	 */
	public function test_the_secret_is_revealed_once_and_never_again(): void {
		$page   = new SettingsPage();
		$secret = $page->rotate_signing_secret();

		$this->assertSame( $secret, $page->reveal_signing_secret_once() );
		$this->assertSame(
			'',
			$page->reveal_signing_secret_once(),
			'The second reveal has to come back empty. The flag is written by the reveal itself, so a reload cannot show a secret a previous render already showed.'
		);
	}

	/**
	 * Nothing is revealed when nothing is stored.
	 *
	 * @return void
	 */
	public function test_nothing_is_revealed_when_no_secret_is_stored(): void {
		$this->options[ Constants::OPTION_SIGNING_SECRET ]  = '';
		$this->options[ Constants::OPTION_SECRET_REVEALED ] = '';

		$this->assertSame( '', ( new SettingsPage() )->reveal_signing_secret_once() );
		$this->assertSame(
			'',
			(string) $this->options[ Constants::OPTION_SECRET_REVEALED ],
			'An absent secret must not mark anything as shown: doing so would swallow the one reveal of the secret generated afterwards.'
		);
	}

	/**
	 * The screen prints the secret on the render that follows the generation,
	 * and not on the next one.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_the_signing_secret_on_one_render_only(): void {
		$page   = new SettingsPage();
		$secret = $page->rotate_signing_secret();

		$first  = $this->render( $page );
		$second = $this->render( $page );

		$this->assertStringContainsString(
			$secret,
			$first,
			'The merchant has to be able to copy the secret into Factuarea, so the render that follows the generation shows it.'
		);
		$this->assertStringNotContainsString(
			$secret,
			$second,
			'A second render must not show it again. Every later reader of this screen - a support screenshot, a colleague with shop manager rights - would otherwise get the key that signs this shop\'s sales.'
		);
		$this->assertStringContainsString(
			'factuarea-wc-copy-secret',
			$first,
			'The one render that shows the secret has to offer a copy button: the value is transferred by hand and a mistyped secret fails as an unsigned delivery.'
		);
	}

	/**
	 * The screen says the signing secret is pasted into the CONNECTION, not into
	 * the store, and that stores under one connection share it.
	 *
	 * MEASURED on 2026-09-02 against the receiver: the store creation endpoint
	 * does not accept a signing secret at all, and the verifier reads it from
	 * the credentials of the integration. A merchant told to paste it "into the
	 * store" looks for a field that does not exist, and the deliveries of a shop
	 * whose secret was never stored anywhere are all rejected as unsigned - with
	 * the screen that misled them still saying the secret is stored.
	 *
	 * The copy is asserted in BOTH places it appears, because they are read at
	 * different moments and only one of them can be seen at a time: the merchant
	 * with no secret yet reads the block, and the merchant who just generated one
	 * reads the reveal.
	 *
	 * @return void
	 */
	public function test_the_signing_secret_copy_sends_the_merchant_to_the_connection_and_not_to_the_store(): void {
		$this->options[ Constants::OPTION_SIGNING_SECRET ] = '';

		$before = $this->capture( static fn ( SettingsPage $page ) => $page->render_signing_secret_block(), new SettingsPage() );

		$this->assertStringContainsString( 'WooCommerce connection', $before );
		$this->assertStringContainsString(
			'connection signs with the same value',
			$before,
			'Several shops can hang off one connection, so the merchant has to be told the secret is shared before they generate a second one and silently break the first shop.'
		);
		$this->assertStringNotContainsString(
			'paste it into the store you registered',
			$before,
			'The store form has no signing secret field. Sending the merchant there is sending them to look for something that is not there.'
		);

		$page   = new SettingsPage();
		$secret = $page->rotate_signing_secret();
		$reveal = $this->capture( static fn ( SettingsPage $subject ) => $subject->render_signing_secret_block(), $page );

		$this->assertStringContainsString( $secret, $reveal, 'The reveal is the render this copy is read on.' );
		$this->assertStringContainsString( 'WooCommerce connection', $reveal );
		$this->assertStringContainsString( 'connection signs with the same value', $reveal );
	}

	/**
	 * The screen offers a connection test behind a nonce, and the action it
	 * posts to is the one the plugin hooks.
	 *
	 * @return void
	 */
	public function test_the_screen_offers_a_connection_test_behind_a_nonce(): void {
		$nonced = false;

		Functions\when( 'wp_nonce_field' )->alias(
			function ( string $action ) use ( &$nonced ): void {
				$nonced = $nonced || SettingsPage::TEST_CONNECTION_ACTION === $action;
			}
		);

		$markup = $this->capture( static fn ( SettingsPage $page ) => $page->render_connection_test_block(), new SettingsPage() );

		$this->assertStringContainsString( 'admin-post.php', $markup );
		$this->assertStringContainsString( 'value="' . SettingsPage::TEST_CONNECTION_ACTION . '"', $markup );
		$this->assertStringContainsString( 'Test connection', $markup );
		$this->assertTrue(
			$nonced,
			'The button changes nothing, but it does spend an outgoing request and report on a credential, so it is posted with a nonce like every other action of this screen.'
		);
	}

	/**
	 * The connection test is hooked to the administration action the button
	 * posts to.
	 *
	 * @return void
	 */
	public function test_the_connection_test_is_hooked_to_the_action_the_button_posts_to(): void {
		Actions\expectAdded( 'admin_post_' . SettingsPage::TEST_CONNECTION_ACTION )->once();

		( new SettingsPage() )->register();

		$this->assertSame(
			'factuarea_wc_test_connection',
			SettingsPage::TEST_CONNECTION_ACTION,
			'The action name is half of a contract with the wiring phase and with the form field above; it is asserted so that renaming it is a decision and not a rename.'
		);
	}

	/**
	 * Pressing the button runs the test and reports its result as a notice.
	 *
	 * @return void
	 */
	public function test_pressing_the_button_reports_the_result_of_the_test(): void {
		$reported = $this->run_connection_test(
			array(
				'ok'         => false,
				'diagnostic' => 'invalid_credential',
				'message'    => 'Factuarea did not accept the API key.',
				'next_step'  => 'Create a new API key in Factuarea.',
			)
		);

		$this->assertSame( SettingsPage::OPTION_GROUP, $reported['setting'] );
		$this->assertSame( SettingsPage::TEST_CONNECTION_ACTION, $reported['code'] );
		$this->assertStringContainsString( 'Factuarea did not accept the API key.', $reported['message'] );
		$this->assertStringContainsString(
			'Create a new API key in Factuarea.',
			$reported['message'],
			'A failure without its next step is the binary result this test exists instead of: the merchant learns something is wrong and not what to do about it.'
		);
		$this->assertSame( 'error', $reported['type'] );
		$this->assertStringContainsString(
			'settings-updated',
			$reported['redirect'],
			'WordPress only reads the notices back after a redirect when that argument is present, so without it the merchant lands on a screen with no result at all.'
		);
	}

	/**
	 * A passing test is reported as a success rather than as an error.
	 *
	 * @return void
	 */
	public function test_a_passing_test_is_reported_as_a_success(): void {
		$reported = $this->run_connection_test(
			array(
				'ok'         => true,
				'diagnostic' => 'ok',
				'message'    => 'The connection works.',
				'next_step'  => 'Switch delivery on.',
			)
		);

		$this->assertSame( 'success', $reported['type'] );
	}

	/**
	 * A user without the capability cannot run the test.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_cannot_run_the_test(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message ): void {
				throw new \RuntimeException( $message );
			}
		);

		$tester = Mockery::mock( ConnectionTester::class );
		$tester->shouldNotReceive( 'test' );

		$this->expectException( \RuntimeException::class );

		( new SettingsPage( null, SettingsPage::STALLED_OVERDUE_AFTER_SECONDS, $tester ) )->handle_test_connection();
	}

	/**
	 * The rendered screen carries neither credential once the secret has been
	 * shown.
	 *
	 * @return void
	 */
	public function test_the_rendered_screen_carries_neither_credential_once_the_secret_has_been_shown(): void {
		$markup = $this->render( new SettingsPage() );

		$this->assertNotSame( '', $markup, 'The screen has to render something; an empty render would pass every sweep below without proving anything.' );
		$this->assert_carries_no_credential( $markup, 'the rendered settings screen' );
		$this->assertStringContainsString(
			'type="password"',
			$markup,
			'The API key input has to be a password field.'
		);
	}

	/**
	 * The diagnostic block carries neither credential, whole or truncated.
	 *
	 * @return void
	 */
	public function test_the_diagnostic_block_carries_neither_credential(): void {
		$info = ( new SettingsPage() )->debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertArrayHasKey( 'wp-core', $info, 'The filter has to add to what it was given, not replace it.' );
		$this->assertArrayHasKey( SettingsPage::DEBUG_INFORMATION_SECTION, $info );

		$this->assert_carries_no_credential(
			$this->flatten( $info[ SettingsPage::DEBUG_INFORMATION_SECTION ] ),
			'the Site Health diagnostic block'
		);

		$fields = $info[ SettingsPage::DEBUG_INFORMATION_SECTION ]['fields'];

		$this->assertSame(
			'Configured',
			$fields['api_key']['debug'],
			'Site Health copies the `debug` entry when it is present, so that is the one a support thread receives. It has to carry a status and never the credential.'
		);
		$this->assertSame( 'Configured', $fields['signing_secret']['debug'] );
	}

	/**
	 * A count of exhausted entries produces a notice that names how many.
	 *
	 * @return void
	 */
	public function test_the_stalled_notice_names_how_many_entries_ran_out_of_attempts(): void {
		$markup = ( new SettingsPage() )->stalled_outbox_notice_markup(
			array(
				'exhausted' => 3,
				'overdue'   => 0,
			)
		);

		$this->assertStringContainsString( '3 order events have used up every delivery attempt', $markup );
		$this->assertStringContainsString(
			'wp-cron.php',
			$markup,
			'The notice has to carry a next step, and the step that fixes the common cause is a real cron entry: WordPress runs its scheduled tasks off site traffic, so a quiet shop drains nothing.'
		);
	}

	/**
	 * A count of overdue entries produces a notice that names how many and how
	 * long they have been waiting.
	 *
	 * @return void
	 */
	public function test_the_stalled_notice_names_how_many_entries_are_overdue_and_for_how_long(): void {
		$markup = ( new SettingsPage() )->stalled_outbox_notice_markup(
			array(
				'exhausted' => 0,
				'overdue'   => 1,
			)
		);

		$this->assertStringContainsString( '1 order event has been waiting', $markup );
		$this->assertStringContainsString(
			'1 hour',
			$markup,
			'The declared window belongs in the notice: "waiting" without "for how long" does not tell the merchant whether this is a retry in progress or a scheduler that stopped.'
		);
	}

	/**
	 * A healthy outbox produces no notice at all.
	 *
	 * @return void
	 */
	public function test_a_healthy_outbox_produces_no_notice(): void {
		$page = new SettingsPage();

		$this->assertSame(
			'',
			$page->stalled_outbox_notice_markup(
				array(
					'exhausted' => 0,
					'overdue'   => 0,
				)
			),
			'A drained outbox is the normal state. A notice that shows up in it is noise, and noise is how the notice that matters stops being read.'
		);
		$this->assertSame( '', $page->stalled_outbox_notice_markup( array() ) );
	}

	/**
	 * The printed notice is built from what the outbox reports, asked for with
	 * the declared window.
	 *
	 * @return void
	 */
	public function test_the_printed_notice_uses_the_counts_the_outbox_reports_for_the_declared_window(): void {
		$outbox = $this->outbox_double();
		$outbox->shouldReceive( 'count_stalled' )
			->once()
			->with( SettingsPage::STALLED_OVERDUE_AFTER_SECONDS, Mockery::type( 'int' ) )
			->andReturn(
				array(
					'exhausted' => 2,
					'overdue'   => 5,
				)
			);

		$printed = $this->capture( static fn ( SettingsPage $page ) => $page->render_stalled_outbox_notice(), new SettingsPage( $outbox ) );

		$this->assertStringContainsString( '2 order events have used up every delivery attempt', $printed );
		$this->assertStringContainsString( '5 order events have been waiting', $printed );
	}

	/**
	 * Nothing is printed when the outbox is healthy.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_when_the_outbox_is_healthy(): void {
		$outbox = $this->outbox_double();
		$outbox->shouldReceive( 'count_stalled' )->andReturn(
			array(
				'exhausted' => 0,
				'overdue'   => 0,
			)
		);

		$this->assertSame(
			'',
			$this->capture( static fn ( SettingsPage $page ) => $page->render_stalled_outbox_notice(), new SettingsPage( $outbox ) )
		);
	}

	/**
	 * With delivery switched off the outbox is not even consulted.
	 *
	 * A still outbox is what "off" means, so a notice about it would fire for as
	 * long as the merchant kept the switch where they put it, and a notice that
	 * is always on is a notice nobody reads on the day it means something.
	 *
	 * @return void
	 */
	public function test_no_notice_is_printed_while_delivery_is_switched_off(): void {
		$this->options[ Constants::OPTION_DELIVERY_ENABLED ] = '0';

		$outbox = $this->outbox_double();
		$outbox->shouldNotReceive( 'count_stalled' );

		$this->assertSame(
			'',
			$this->capture( static fn ( SettingsPage $page ) => $page->render_stalled_outbox_notice(), new SettingsPage( $outbox ) )
		);
	}

	/**
	 * A user without the capability sees no notice and costs no query.
	 *
	 * @return void
	 */
	public function test_no_notice_is_printed_for_a_user_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$outbox = $this->outbox_double();
		$outbox->shouldNotReceive( 'count_stalled' );

		$this->assertSame(
			'',
			$this->capture( static fn ( SettingsPage $page ) => $page->render_stalled_outbox_notice(), new SettingsPage( $outbox ) )
		);
	}

	/**
	 * An empty submission of the API key keeps the stored one.
	 *
	 * @return void
	 */
	public function test_an_empty_api_key_submission_keeps_the_stored_key(): void {
		$page = new SettingsPage();

		$this->assertSame(
			self::STORED_API_KEY,
			$page->sanitize_api_key( '' ),
			'The API key field is never pre-filled, so it posts an empty value on every save of an unrelated setting. Taking that at face value would wipe the key each time the merchant saved the screen.'
		);
		$this->assertSame( self::STORED_API_KEY, $page->sanitize_api_key( null ) );
		$this->assertSame( 'fk_live_NEWKEY', $page->sanitize_api_key( ' fk_live_NEWKEY ' ) );
	}

	/**
	 * Nothing submitted can set the signing secret.
	 *
	 * @return void
	 */
	public function test_the_signing_secret_cannot_be_set_by_a_submission(): void {
		$this->assertSame(
			self::STORED_SECRET,
			( new SettingsPage() )->sanitize_signing_secret( 'an-attacker-chosen-secret' ),
			'The secret has no input on the screen, so anything arriving under its option name came from somewhere with no business setting it. The only thing that sets it is the generation action, behind its own nonce and capability check.'
		);
	}

	/**
	 * The delivery switch stores one of two values, and an unticked checkbox
	 * turns it off rather than leaving it as it was.
	 *
	 * @return void
	 */
	public function test_the_delivery_switch_stores_one_of_two_values(): void {
		$page = new SettingsPage();

		$this->assertSame( '1', $page->sanitize_delivery_enabled( '1' ) );
		$this->assertSame( '0', $page->sanitize_delivery_enabled( '0' ) );
		$this->assertSame(
			'0',
			$page->sanitize_delivery_enabled( null ),
			'An unticked checkbox posts nothing at all. The hidden input on the screen makes it post a zero, and this sanitiser turns anything else into one too, so the switch can be turned off and not only on.'
		);
	}

	/**
	 * The base address loses its trailing slash, because every path is appended
	 * to it.
	 *
	 * @return void
	 */
	public function test_the_base_address_is_stored_without_a_trailing_slash(): void {
		$this->assertSame(
			'https://app.factuarea.example',
			( new SettingsPage() )->sanitize_base_url( '  https://app.factuarea.example/  ' )
		);
	}

	/**
	 * The two receiver switches are registered disabled and answer to one rule.
	 *
	 * The default is asserted as the constant and as the literal behind it,
	 * because "disabled by default" is the promise that an update changes
	 * nothing in a shop that touched nothing, and a default of `'1'` spelled by
	 * accident would keep every assertion about the constant green.
	 *
	 * @return void
	 */
	public function test_the_two_receivers_are_registered_disabled_and_share_one_switch_rule(): void {
		( new SettingsPage() )->register_settings();

		$this->assertSame( '0', SettingsPage::RECEIVER_DISABLED, 'The stored form of a switched-off receiver is the same one the delivery switch uses.' );
		$this->assertSame( '1', SettingsPage::RECEIVER_ENABLED );

		foreach ( array( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED, Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ) as $option ) {
			$this->assertSame(
				SettingsPage::RECEIVER_DISABLED,
				$this->registered_settings[ $option ]['args']['default'] ?? null,
				sprintf( 'Receiver option "%s" has to be registered disabled: updating the plugin must not start writing into a shop that asked for nothing.', $option )
			);
			$this->assertFalse(
				$this->registered_settings[ $option ]['args']['show_in_rest'],
				sprintf( 'Receiver option "%s" is a switch over a route that writes into orders; it has no business travelling in a settings export.', $option )
			);
		}

		$this->assertSame(
			$this->registered_settings[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ]['args']['sanitize_callback'],
			$this->registered_settings[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ]['args']['sanitize_callback'],
			'One rule for both switches. Two sanitisers for two switches is two places for "what counts as on" to drift apart.'
		);
	}

	/**
	 * A shop that never touched these settings has both receivers off, and the
	 * screen renders them off.
	 *
	 * The options are ABSENT here rather than stored as zero, which is the state
	 * of every installation that updates the plugin: nothing wrote them yet, so
	 * what decides is the fallback the readers pass.
	 *
	 * @return void
	 */
	public function test_a_shop_that_touched_nothing_has_both_receivers_switched_off(): void {
		unset(
			$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ],
			$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ]
		);
		$this->options[ Constants::OPTION_DELIVERY_ENABLED ] = '0';

		$this->assertFalse( SettingsPage::receiver_enabled( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ) );
		$this->assertFalse( SettingsPage::receiver_enabled( Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ) );

		$markup = $this->render( new SettingsPage() );

		$this->assertSame(
			0,
			substr_count( $markup, 'checked="checked"' ),
			'With the delivery switch off in this shop and both receivers never written, nothing on the screen may render as ticked.'
		);
	}

	/**
	 * A stored switch is read back as on, and anything that is not the stored
	 * "on" is off.
	 *
	 * @return void
	 */
	public function test_the_receiver_switch_stores_one_of_two_values_by_the_same_rule_as_delivery(): void {
		$page = new SettingsPage();

		$this->assertSame( SettingsPage::RECEIVER_ENABLED, $page->sanitize_receiver_enabled( '1' ) );
		$this->assertSame( SettingsPage::RECEIVER_DISABLED, $page->sanitize_receiver_enabled( '0' ) );
		$this->assertSame(
			SettingsPage::RECEIVER_DISABLED,
			$page->sanitize_receiver_enabled( null ),
			'An unticked checkbox posts nothing at all. The hidden input makes it post a zero, and the sanitiser turns anything else into one too, so a receiver can be switched off and not only on.'
		);
		$this->assertSame( SettingsPage::RECEIVER_DISABLED, $page->sanitize_receiver_enabled( 'yes' ) );
		$this->assertSame( SettingsPage::RECEIVER_DISABLED, $page->sanitize_receiver_enabled( array( '1' ) ) );

		foreach ( array( '1', '0', null, 'yes', array( '1' ) ) as $submitted ) {
			$this->assertSame(
				$page->sanitize_delivery_enabled( $submitted ),
				$page->sanitize_receiver_enabled( $submitted ),
				'The three switches of this screen answer to one rule, so that "on" cannot come to mean two things on one form.'
			);
		}

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;

		$this->assertTrue( SettingsPage::receiver_enabled( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ) );
		$this->assertFalse( SettingsPage::receiver_enabled( Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ) );
	}

	/**
	 * Each receiver prints the whole address a sender posts to, built from the
	 * frozen route and from the address this site answers on.
	 *
	 * @return void
	 */
	public function test_each_receiver_shows_the_address_composed_from_the_address_of_this_site(): void {
		$status  = SettingsPage::receiver_url( Constants::INBOUND_ORDER_STATUS_ROUTE );
		$catalog = SettingsPage::receiver_url( Constants::INBOUND_CATALOG_PUSH_ROUTE );

		$this->assertSame( 'https://shop.example/wp-json/factuarea/v1/order-status', $status );
		$this->assertSame( 'https://shop.example/wp-json/factuarea/v1/catalog-push', $catalog );
		$this->assertNotSame( $status, $catalog, 'Two receivers, two addresses: one of them printed for both is a merchant pasting the wrong one into Factuarea.' );

		$markup = $this->render( new SettingsPage() );

		$this->assertStringContainsString( $status, $markup );
		$this->assertStringContainsString( $catalog, $markup );
	}

	/**
	 * Move the site and the printed addresses move with it.
	 *
	 * This is the assertion behind "composed, not written out". An address typed
	 * into the screen would pass every check above and keep pointing at the
	 * shop it was typed for.
	 *
	 * @return void
	 */
	public function test_the_receiver_addresses_follow_the_site_when_it_moves(): void {
		$this->rest_base = 'https://another-shop.example/some/path/wp-json/';

		$status = SettingsPage::receiver_url( Constants::INBOUND_ORDER_STATUS_ROUTE );

		$this->assertSame( 'https://another-shop.example/some/path/wp-json/factuarea/v1/order-status', $status );
		$this->assertStringEndsWith(
			Constants::INBOUND_ORDER_STATUS_ROUTE,
			$status,
			'The route half comes from the frozen contract, so the address ends exactly with it.'
		);
		$this->assertStringContainsString( $status, $this->render( new SettingsPage() ) );
	}

	/**
	 * The help next to the receivers says the signing secret is the one that
	 * already signs what this shop sends, and that there is no second field.
	 *
	 * @return void
	 */
	public function test_the_receivers_section_says_the_signing_secret_is_the_one_that_already_signs(): void {
		$markup = $this->capture( static fn ( SettingsPage $page ) => $page->render_receivers_section_description(), new SettingsPage() );

		$this->assertStringContainsString( 'signing secret', $markup );
		$this->assertStringContainsString(
			'no second field',
			$markup,
			'A merchant who is not told the secret is shared looks for a field to paste an inbound secret into, does not find one, and concludes the receiver is unfinished.'
		);
		$this->assertStringContainsString(
			'switched off',
			$markup,
			'The section has to say the receivers start off; it is the sentence that explains why nothing happened after the update.'
		);
	}

	/**
	 * The warning appears for the one combination that rejects everything, and
	 * for no other.
	 *
	 * @return void
	 */
	public function test_the_missing_secret_warning_appears_only_with_a_receiver_on_and_no_secret(): void {
		$page = new SettingsPage();

		$this->assertSame( '', $page->missing_secret_notice_markup(), 'Both receivers off and a secret stored: nothing to warn about.' );

		$this->options[ Constants::OPTION_SIGNING_SECRET ] = '';
		$this->assertSame(
			'',
			$page->missing_secret_notice_markup(),
			'No secret and no receiver switched on is the state of a shop that only delivers outwards. Warning there would be noise on a screen that is fine.'
		);

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;
		$warning = $page->missing_secret_notice_markup();

		$this->assertStringContainsString( 'signing secret', $warning );
		$this->assertStringContainsString( 'notice-warning', $warning );

		$this->options[ Constants::OPTION_SIGNING_SECRET ] = self::STORED_SECRET;
		$this->assertSame( '', $page->missing_secret_notice_markup(), 'A receiver switched on with a secret stored is the working state.' );

		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
		$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;
		$this->options[ Constants::OPTION_SIGNING_SECRET ]                = '';

		$this->assertNotSame(
			'',
			$page->missing_secret_notice_markup(),
			'The warning is about either receiver. Watching only the first one would leave the catalogue receiver rejecting every batch with nothing on screen.'
		);
		$this->assert_carries_no_credential( $page->missing_secret_notice_markup(), 'the missing secret warning' );
	}

	/**
	 * The warning is printed on the screen and not only returned by a method.
	 *
	 * @return void
	 */
	public function test_the_missing_secret_warning_is_printed_on_the_screen(): void {
		$this->options[ Constants::OPTION_SIGNING_SECRET ]                = '';
		$this->options[ Constants::OPTION_SECRET_REVEALED ]               = '';
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;

		$markup = $this->render( new SettingsPage() );

		$this->assertStringContainsString(
			'has no signing secret',
			$markup,
			'A warning that is only reachable through a method nobody calls is a warning the merchant never reads.'
		);
	}

	/**
	 * A site serving its REST routes as a query argument is told so, because
	 * the sender composes the address the other way.
	 *
	 * MEASURED against the frozen contract of the sender
	 * (`backend/docs/architecture/store-order-status-receiver-contract.md` §2,
	 * read 2026-09-18): the platform appends the route to the base address of
	 * the shop, so a site whose REST routes only answer as a query argument -
	 * which is what WordPress serves while permalinks are set to Plain - is
	 * unreachable, and every notice 404s with nothing on screen to explain it.
	 *
	 * @return void
	 */
	public function test_the_screen_warns_when_this_site_serves_rest_as_a_query_argument(): void {
		$page = new SettingsPage();

		$this->assertSame( '', $page->plain_permalinks_notice_markup(), 'The usual site serves its routes as a path and needs no warning.' );

		$this->rest_base = 'https://shop.example/?rest_route=/';

		$warning = $page->plain_permalinks_notice_markup();

		$this->assertStringContainsString( 'notice-warning', $warning );
		$this->assertStringContainsString( 'permalink', $warning );
		$this->assertStringContainsString(
			'permalink',
			$this->render( new SettingsPage() ),
			'The warning belongs on the screen next to the addresses it is about.'
		);
	}

	/**
	 * Nothing on the receivers surface consults anything but its own switch and
	 * the secret.
	 *
	 * This is the no-paywall promise of this screen written as a measurement.
	 * A receiver held back until somebody paid would have to READ something to
	 * find out whether they had - an entitlement, a plan, a licence flag - and
	 * what this asserts is the exact list of option names the surface asks for.
	 * The authoritative gate is `scripts/check-no-paywall.php`; this one fails
	 * in the phase that adds the switches, which is where the mistake would be
	 * made.
	 *
	 * @return void
	 */
	public function test_the_receivers_consult_nothing_but_their_own_switch_and_the_secret(): void {
		$page = new SettingsPage();

		$this->read_options = array();
		$this->capture( static fn ( SettingsPage $subject ) => $subject->render_order_status_receiver_field(), $page );

		$this->assertSame(
			array( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ),
			array_values( array_unique( $this->read_options ) ),
			'The order status switch is decided by its own option and by nothing else.'
		);

		$this->read_options = array();
		$this->capture( static fn ( SettingsPage $subject ) => $subject->render_catalog_push_receiver_field(), $page );

		$this->assertSame(
			array( Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ),
			array_values( array_unique( $this->read_options ) ),
			'The catalogue switch is decided by its own option and by nothing else.'
		);

		/*
		 * The help is swept with each receiver switched on in turn, because the
		 * warning stops reading as soon as it has an answer: with both off it
		 * never reaches the secret, and with the first one on it never reaches
		 * the second switch. The union of the two sweeps is everything this
		 * surface can ask for.
		 */
		$asked = array();

		foreach ( array( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED, Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ) as $switched_on ) {
			$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
			$this->options[ Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_DISABLED;
			$this->options[ $switched_on ]                                    = SettingsPage::RECEIVER_ENABLED;

			$this->read_options = array();
			$this->capture( static fn ( SettingsPage $subject ) => $subject->render_receivers_section_description(), $page );

			$asked = array_merge( $asked, $this->read_options );
		}

		$asked = array_values( array_unique( $asked ) );
		$allowed = array(
			Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED,
			Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED,
			Constants::OPTION_SIGNING_SECRET,
		);

		sort( $asked );
		sort( $allowed );

		$this->assertSame(
			$allowed,
			$asked,
			'The help and its warning read the two switches and the secret. Any other option name read here is something this screen would be consulting before it agreed to receive.'
		);
	}

	/**
	 * The diagnostic block reports both receivers, and still carries no
	 * credential in any form.
	 *
	 * @return void
	 */
	public function test_the_diagnostic_block_reports_both_receivers_without_a_credential(): void {
		$this->options[ Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED ] = SettingsPage::RECEIVER_ENABLED;

		$fields = ( new SettingsPage() )->debug_information( array() )[ SettingsPage::DEBUG_INFORMATION_SECTION ]['fields'];

		$this->assertSame( 'on', $fields['order_status_receiver']['debug'] ?? null, 'Whether a receiver is switched on is the first thing a support thread about a notice that never arrived needs.' );
		$this->assertSame( 'off', $fields['catalog_push_receiver']['debug'] ?? null );
		$this->assertSame( 'On', $fields['order_status_receiver']['value'] ?? null );
		$this->assertSame( 'Off', $fields['catalog_push_receiver']['value'] ?? null );

		$this->assert_carries_no_credential(
			$this->flatten( ( new SettingsPage() )->debug_information( array() )[ SettingsPage::DEBUG_INFORMATION_SECTION ] ),
			'the Site Health diagnostic block with the receivers reported'
		);
	}

	/**
	 * Every translated string of the screen goes through the text domain of the
	 * package, and that domain is the identifier of the package itself.
	 *
	 * The domain is MEASURED off the plugin header and off the name of the entry
	 * point rather than compared with a literal written here: WordPress resolves
	 * translations by matching the domain against the directory the plugin ships
	 * in, so a domain that stops matching the package identifier does not fail -
	 * it silently serves English to every merchant who installed a translation.
	 *
	 * @return void
	 */
	public function test_every_translated_string_of_the_screen_uses_the_text_domain_of_the_package(): void {
		$root     = dirname( __DIR__, 2 );
		$manifest = (string) file_get_contents( $root . '/factuarea-for-woocommerce.php' );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m', $manifest, $declared ),
			'The plugin header has to declare a text domain; WordPress loads no translation without one.'
		);

		$domain = $declared[1];

		$this->assertSame(
			basename( $root . '/factuarea-for-woocommerce.php', '.php' ),
			$domain,
			'The declared domain has to be the identifier of the package, which is what the directory and the entry point are named after.'
		);
		$this->assertSame( SettingsPage::MENU_SLUG, $domain, 'The screen slug is that same identifier, so the two cannot drift apart unnoticed.' );

		$domains = $this->translation_domains_of( $root . '/src/Admin/SettingsPage.php' );

		$this->assertNotSame( array(), $domains, 'The screen is made of user-facing strings; finding none means the sweep below stopped reading them.' );
		$this->assertSame(
			array( $domain ),
			$domains,
			sprintf( 'Every translated string of the screen has to carry "%s" as its domain. A call with another domain, or with none at all, is a string that never reaches a translator.', $domain )
		);
	}

	/**
	 * Renders the whole screen - the page and the fields WordPress prints inside
	 * it - and returns what it printed.
	 *
	 * The registration runs first because the fields exist only once they are
	 * registered, and the fields are half of what the sweeps read.
	 *
	 * @param SettingsPage $page Screen under test.
	 * @return string Rendered markup.
	 */
	private function render( SettingsPage $page ): string {
		$page->register_settings();

		return $this->capture( static fn ( SettingsPage $subject ) => $subject->render(), $page );
	}

	/**
	 * Runs a callable against the screen and returns everything it printed.
	 *
	 * @param callable     $call Callable taking the screen.
	 * @param SettingsPage $page Screen under test.
	 * @return string Whatever was printed.
	 */
	private function capture( callable $call, SettingsPage $page ): string {
		ob_start();

		try {
			$call( $page );
		} finally {
			$printed = (string) ob_get_clean();
		}

		return $printed;
	}

	/**
	 * Fails when a haystack carries either stored credential, whole or in a
	 * fragment.
	 *
	 * The fragment matters as much as the whole value: a masked form that keeps
	 * the first or the last few characters is the shape this mistake usually
	 * takes, and it is not a safer secret, only a shorter one.
	 *
	 * @param string $haystack What the surface produced.
	 * @param string $surface  Name of the surface, for the failure message.
	 * @return void
	 */
	private function assert_carries_no_credential( string $haystack, string $surface ): void {
		foreach ( array( self::STORED_API_KEY, self::STORED_SECRET ) as $credential ) {
			$this->assertStringNotContainsString(
				$credential,
				$haystack,
				sprintf( 'A stored credential appears in %s. Nothing this plugin prints may carry the API key or the signing secret.', $surface )
			);

			foreach ( array( substr( $credential, 0, 8 ), substr( $credential, -8 ) ) as $fragment ) {
				$this->assertStringNotContainsString(
					$fragment,
					$haystack,
					sprintf(
						'A fragment of a stored credential ("%s") appears in %s. Truncating it does not make it safe: the places these strings end up - a support ticket, a migration bundle, a hosting panel e-mail - are exactly the places a fragment is useful to somebody else.',
						$fragment,
						$surface
					)
				);
			}
		}
	}

	/**
	 * Flattens a nested array into one string, so a sweep reads every leaf
	 * whatever depth it sits at.
	 *
	 * @param mixed $value Anything the diagnostic block holds.
	 * @return string Every scalar leaf, joined.
	 */
	private function flatten( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ' ', array_map( array( $this, 'flatten' ), array_merge( array_keys( $value ), array_values( $value ) ) ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Every text domain a translation call of a file hands over, once each.
	 *
	 * Read with the PHP tokeniser and not with a regular expression, for the
	 * reason `scripts/check-no-paywall.php` writes out at length: this
	 * repository spreads a translation call over four lines and puts the domain
	 * on one of its own, and a pattern that survived that would also match the
	 * word inside a docblock. The tokeniser walks the call, keeps the string
	 * literals that belong to it and takes the LAST one, which is where every
	 * translation function of WordPress carries the domain - so a call that
	 * forgot it hands back its own message and fails the comparison, which is
	 * exactly what should happen.
	 *
	 * @param string $path Absolute path of the file to read.
	 * @return list<string> The domains found, in order of first appearance.
	 */
	private function translation_domains_of( string $path ): array {
		$translators = array( '__', '_e', '_n', '_x', '_nx', '_n_noop', '_nx_noop', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x' );
		$tokens      = token_get_all( (string) file_get_contents( $path ) );
		$total       = count( $tokens );
		$domains     = array();

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $translators, true ) ) {
				continue;
			}

			$opening = $index + 1;

			while ( $opening < $total && is_array( $tokens[ $opening ] ) && in_array( $tokens[ $opening ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$opening++;
			}

			if ( $opening >= $total || '(' !== $tokens[ $opening ] ) {
				continue;
			}

			$depth      = 0;
			$literals   = array();

			for ( $cursor = $opening; $cursor < $total; $cursor++ ) {
				$inner = $tokens[ $cursor ];

				if ( '(' === $inner ) {
					$depth++;
					continue;
				}

				if ( ')' === $inner ) {
					$depth--;

					if ( 0 === $depth ) {
						break;
					}

					continue;
				}

				if ( is_array( $inner ) && T_CONSTANT_ENCAPSED_STRING === $inner[0] && 1 === $depth ) {
					$literals[] = trim( $inner[1], '\'"' );
				}
			}

			if ( array() !== $literals ) {
				$domains[] = (string) end( $literals );
			}
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * Presses the connection test button with a doubled tester and returns what
	 * the handler reported.
	 *
	 * The redirect double throws, which is how the handler is stopped before its
	 * `exit`: an administration action that did not end the request would carry
	 * on rendering the page it just told the browser to leave.
	 *
	 * @param array{ok: bool, diagnostic: string, message: string, next_step: string} $result What the tester answers.
	 * @return array{setting: string, code: string, message: string, type: string, redirect: string}
	 */
	private function run_connection_test( array $result ): array {
		$reported = array(
			'setting'  => '',
			'code'     => '',
			'message'  => '',
			'type'     => '',
			'redirect' => '',
		);

		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias(
			static function ( string $key, string $value, string $url ): string {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			static function ( string $setting, string $code, string $message, string $type ) use ( &$reported ): void {
				$reported['setting'] = $setting;
				$reported['code']    = $code;
				$reported['message'] = $message;
				$reported['type']    = $type;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new RedirectedAway( $url );
			}
		);

		$tester = Mockery::mock( ConnectionTester::class );
		$tester->shouldReceive( 'test' )->once()->andReturn( $result );

		try {
			( new SettingsPage( null, SettingsPage::STALLED_OVERDUE_AFTER_SECONDS, $tester ) )->handle_test_connection();
			$this->fail( 'The handler has to end the request with a redirect, so that a reload does not run the test again.' );
		} catch ( RedirectedAway $redirect ) {
			$reported['redirect'] = $redirect->getMessage();
		}

		return $reported;
	}

	/**
	 * A double of the outbox reader.
	 *
	 * The reader is asked for two counts and nothing else, so it is doubled here
	 * rather than pointed at a database: what this class decides is whether a
	 * notice appears and what it says, and the query behind the counts is tested
	 * where it lives.
	 *
	 * @return \Mockery\MockInterface Double of the outbox reader.
	 */
	private function outbox_double(): \Mockery\MockInterface {
		if ( class_exists( OutboxRepository::class ) && ( new ReflectionClass( OutboxRepository::class ) )->isFinal() ) {
			$this->fail(
				'OutboxRepository is declared final, so this suite cannot double it. The settings screen receives it by constructor because exactly one class may read the outbox table; drop the `final` keyword from it, or give it a small interface this test can double instead.'
			);
		}

		return Mockery::mock( OutboxRepository::class );
	}

	/**
	 * Replaces the registration functions with doubles that keep what they were
	 * given.
	 *
	 * @return void
	 */
	private function capture_admin_registrations(): void {
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent, string $page_title, string $menu_title, string $capability, string $slug, $callback ): string {
				unset( $page_title, $menu_title, $callback );

				$this->submenu_pages[] = array(
					'parent'     => $parent,
					'capability' => $capability,
					'slug'       => $slug,
				);

				return $slug;
			}
		);
		Functions\when( 'register_setting' )->alias(
			function ( string $group, string $option, array $args = array() ): void {
				$this->registered_settings[ $option ] = array(
					'group' => $group,
					'args'  => $args,
				);
			}
		);
		Functions\when( 'add_settings_section' )->alias(
			function ( string $id, string $title, $callback, string $page ): void {
				unset( $id, $title );

				$this->registered_sections[] = array(
					'page'     => $page,
					'callback' => $callback,
				);
			}
		);
		Functions\when( 'add_settings_field' )->alias(
			function ( string $id, string $title, $callback, string $page, string $section = 'default' ): void {
				$this->registered_fields[ $id ] = array(
					'title'    => $title,
					'page'     => $page,
					'section'  => $section,
					'callback' => $callback,
				);
			}
		);
	}
}

/**
 * Thrown by the doubled redirect so that a handler under test stops where the
 * real one ends the request.
 */
final class RedirectedAway extends \RuntimeException {
}
