<?php
/**
 * The settings screen of the plugin, its signing secret and its stalled outbox
 * notice.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Admin;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Outbox\OutboxRepository;

/**
 * Everything the merchant configures, plus the one notice that keeps a stalled
 * outbox from being silent.
 *
 * The screen holds five settings and one switch: the address deliveries are
 * posted to, the address the v1 reads are made against, the v1 API key, the
 * identifier of this store, the signing secret and whether delivery is switched
 * on. The two addresses are two settings and not one because they are two
 * different hosts - see `Constants::OPTION_API_BASE_URL` for the measurement
 * that separated them.
 *
 * The two credentials have two different jobs and neither does the other's -
 * the signing secret only signs outgoing deliveries, the API key only
 * authenticates incoming reads - which is why they are set in two different
 * ways: one is GENERATED here and pasted into Factuarea, the other is generated
 * in Factuarea and pasted here.
 *
 * ---------------------------------------------------------------------------
 * NEITHER CREDENTIAL IS EVER PRINTED, AND THE SECRET IS PRINTED ONCE
 * ---------------------------------------------------------------------------
 * The prohibition is written in full in `Exception\PluginException`, and this
 * class is the surface it was written for: a settings screen is the one place
 * where showing a stored credential feels helpful. It is not. The screen renders
 * the API key field as a password input that is NEVER pre-filled with the stored
 * value, and next to it a status - configured or not configured - because that
 * is the only thing the merchant can act on. Being shown the key would not help
 * them fix anything, and the rendered markup of an admin screen ends up in
 * screenshots attached to support tickets.
 *
 * The signing secret is shown EXACTLY ONCE, on the render that follows its
 * generation, and never again. That is not extra caution either: the merchant
 * has to copy it into the WooCommerce connection of Factuarea that backs this
 * store, so it has to be visible once, and after it has been copied there is no
 * further reason for it to appear on a screen anybody with access to the shop
 * can open. `OPTION_SECRET_REVEALED` is
 * the flag that makes "once" mean once, and it is written by the same call that
 * returns the secret, so a reload cannot show it twice.
 *
 * ---------------------------------------------------------------------------
 * WHY AN EMPTY SUBMISSION KEEPS THE STORED CREDENTIAL
 * ---------------------------------------------------------------------------
 * WordPress saves EVERY option registered against a settings page when that page
 * is submitted, and it saves null for the ones the form did not post. With a
 * password field that is deliberately never pre-filled, and a signing secret
 * that has no form field at all, the plain reading of "save what was submitted"
 * would wipe both credentials on every single save of an unrelated setting.
 *
 * So the two credential sanitisers return the STORED value when the submission
 * is empty or absent, and the signing secret's sanitiser ignores its input
 * entirely - the only thing that ever sets it is the generation action, which
 * has its own nonce and its own capability check. Rotating the API key is
 * pasting a new one; there is no "clear it" control, because the case that needs
 * one is uninstalling, and uninstalling deletes it.
 *
 * ---------------------------------------------------------------------------
 * THE STALLED OUTBOX NOTICE IS THE POINT OF THE WHOLE PACKAGE
 * ---------------------------------------------------------------------------
 * This plugin exists because WooCommerce's own webhooks switch themselves off
 * after five consecutive failures and say nothing. An outbox that nobody drains
 * is the same silence with another name: the events are safe on disk, and the
 * merchant's invoicing has stopped without a single symptom. WordPress runs its
 * scheduled tasks off site traffic, so a shop with little traffic, or a host
 * that switched WP-Cron off without adding a system cron entry, drains nothing -
 * and that is a configuration this plugin cannot fix, only report.
 *
 * The notice reports two conditions, because they have two different remedies:
 * entries that have used up every attempt, which will not be retried on their
 * own, and entries whose next attempt has been due for longer than the declared
 * window, which means nothing is draining at all. The counts come from the
 * outbox repository, which is the only thing allowed to read that table.
 */
final class SettingsPage {

	/**
	 * Slug of the settings screen. It doubles as the `page` query argument and
	 * as the identifier the settings sections are registered against.
	 */
	public const MENU_SLUG = 'factuarea-for-woocommerce';

	/**
	 * Capability required to see the screen, to save it and to generate a
	 * signing secret.
	 *
	 * `manage_woocommerce` and not `manage_options`: the shop manager who runs
	 * this integration is the person who already administers orders, and asking
	 * for full site administration would push the merchant to hand out an
	 * administrator account for a screen that configures an invoicing
	 * connection.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings group the five options are registered against. It is what
	 * `settings_fields()` writes into the form and what WordPress resolves the
	 * list of options to save from.
	 */
	public const OPTION_GROUP = 'factuarea_wc_settings';

	/**
	 * Identifier of the single settings section.
	 */
	public const SETTINGS_SECTION = 'factuarea_wc_connection';

	/**
	 * Action name of the signing secret generation, used both as the
	 * `admin-post.php` action and as the nonce action.
	 */
	public const GENERATE_SECRET_ACTION = 'factuarea_wc_generate_signing_secret';

	/**
	 * Action name of the connection test, used both as the `admin-post.php`
	 * action and as the nonce action.
	 */
	public const TEST_CONNECTION_ACTION = 'factuarea_wc_test_connection';

	/**
	 * Number of random bytes the signing secret is built from.
	 *
	 * Thirty-two bytes is the block size of the SHA-256 the deliveries are
	 * signed with, so a longer secret buys nothing and a shorter one is the only
	 * thing an attacker forging a sale into the merchant's books would have to
	 * guess.
	 */
	public const SIGNING_SECRET_BYTES = 32;

	/**
	 * Length of the generated secret, in characters.
	 *
	 * Hexadecimal, so two characters per byte. Hexadecimal rather than base64
	 * because this string is copied by hand between two screens: it survives a
	 * double-click selection, an e-mail client that decides `+` was a space and
	 * a form that trims padding, and none of those is hypothetical when the
	 * transfer is manual.
	 */
	public const SIGNING_SECRET_LENGTH = 64;

	/**
	 * Value written into `OPTION_SECRET_REVEALED` once the secret has been
	 * shown.
	 */
	public const SECRET_REVEALED = '1';

	/**
	 * How long an entry's next attempt has to have been due before the outbox
	 * counts as stalled, in seconds.
	 *
	 * One hour, and the number comes from the drain rather than from taste: the
	 * scheduled drain runs far more often than hourly, and the first step of the
	 * backoff is a minute. An entry that has been due for a whole hour has
	 * therefore missed the drain many times over, which is a broken scheduler
	 * and not a slow one. Anything much shorter would fire the notice at every
	 * normal retry; much longer and a shop that stopped invoicing this morning
	 * finds out tomorrow.
	 *
	 * The wiring phase may pass another value through the constructor.
	 */
	public const STALLED_OVERDUE_AFTER_SECONDS = 3600;

	/**
	 * Identifier of the block this plugin adds to the Site Health information.
	 */
	public const DEBUG_INFORMATION_SECTION = 'factuarea-for-woocommerce';

	/**
	 * Reads the outbox. Built on first use so that nothing touches the database
	 * on a request that never renders the notice.
	 *
	 * @var OutboxRepository|null
	 */
	private ?OutboxRepository $outbox;

	/**
	 * Seconds a next attempt has to be overdue before the outbox counts as
	 * stalled.
	 *
	 * @var int
	 */
	private int $overdue_after_seconds;

	/**
	 * Runs the connection test. Built on first use, like the outbox reader, so
	 * that a request that never presses the button builds nothing.
	 *
	 * @var ConnectionTester|null
	 */
	private ?ConnectionTester $tester;

	/**
	 * Constructor.
	 *
	 * Every argument is optional, so the wiring phase keeps registering this
	 * screen with `new SettingsPage()`.
	 *
	 * @param OutboxRepository|null $outbox                Reader of the outbox;
	 *                                                     built on first use when
	 *                                                     not given.
	 * @param int                   $overdue_after_seconds Seconds a next attempt
	 *                                                     has to be overdue before
	 *                                                     the outbox counts as
	 *                                                     stalled.
	 * @param ConnectionTester|null $tester                Connection test; built on
	 *                                                     first use when not given.
	 */
	public function __construct( ?OutboxRepository $outbox = null, int $overdue_after_seconds = self::STALLED_OVERDUE_AFTER_SECONDS, ?ConnectionTester $tester = null ) {
		$this->outbox                = $outbox;
		$this->overdue_after_seconds = $overdue_after_seconds;
		$this->tester                = $tester;
	}

	/**
	 * Hooks the screen, the settings, the notice, the generation action and the
	 * diagnostic block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_stalled_outbox_notice' ), 10, 0 );
		add_action( 'admin_post_' . self::GENERATE_SECRET_ACTION, array( $this, 'handle_generate_signing_secret' ), 10, 0 );
		add_action( 'admin_post_' . self::TEST_CONNECTION_ACTION, array( $this, 'handle_test_connection' ), 10, 0 );
		add_filter( 'debug_information', array( $this, 'debug_information' ), 10, 1 );
	}

	/**
	 * Adds the screen under the WooCommerce menu.
	 *
	 * Under WooCommerce rather than under Settings because everything on it is
	 * about orders: a merchant looking for it goes where the orders are.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Factuarea', 'factuarea-for-woocommerce' ),
			__( 'Factuarea', 'factuarea-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the five options, the section and the four fields.
	 *
	 * Every option is registered with `show_in_rest` set to false. That flag is
	 * what keeps them out of the settings export: an option exposed through the
	 * REST settings endpoint is readable by anything holding a token for this
	 * site and is copied by every tool that migrates a site by exporting its
	 * settings. None of these five belongs in such an export, and the two
	 * credentials must never leave the installation at all.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_BASE_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_base_url' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_API_BASE_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_base_url' ),
				'show_in_rest'      => false,
				'default'           => Constants::DEFAULT_API_BASE_URL,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_STORE_ID,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_store_id' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_SIGNING_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_signing_secret' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			Constants::OPTION_DELIVERY_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_delivery_enabled' ),
				'show_in_rest'      => false,
				'default'           => '0',
			)
		);

		add_settings_section(
			self::SETTINGS_SECTION,
			__( 'Connection', 'factuarea-for-woocommerce' ),
			array( $this, 'render_section_description' ),
			self::MENU_SLUG
		);

		add_settings_field(
			Constants::OPTION_BASE_URL,
			__( 'Factuarea address', 'factuarea-for-woocommerce' ),
			array( $this, 'render_base_url_field' ),
			self::MENU_SLUG,
			self::SETTINGS_SECTION
		);
		add_settings_field(
			Constants::OPTION_API_BASE_URL,
			__( 'Factuarea API address', 'factuarea-for-woocommerce' ),
			array( $this, 'render_api_base_url_field' ),
			self::MENU_SLUG,
			self::SETTINGS_SECTION
		);
		add_settings_field(
			Constants::OPTION_API_KEY,
			__( 'API key', 'factuarea-for-woocommerce' ),
			array( $this, 'render_api_key_field' ),
			self::MENU_SLUG,
			self::SETTINGS_SECTION
		);
		add_settings_field(
			Constants::OPTION_STORE_ID,
			__( 'Store identifier', 'factuarea-for-woocommerce' ),
			array( $this, 'render_store_id_field' ),
			self::MENU_SLUG,
			self::SETTINGS_SECTION
		);
		add_settings_field(
			Constants::OPTION_DELIVERY_ENABLED,
			__( 'Delivery', 'factuarea-for-woocommerce' ),
			array( $this, 'render_delivery_enabled_field' ),
			self::MENU_SLUG,
			self::SETTINGS_SECTION
		);
	}

	/**
	 * Renders the whole screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Factuarea for WooCommerce', 'factuarea-for-woocommerce' ); ?></h1>

			<?php settings_errors( self::OPTION_GROUP ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				?>
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php echo esc_html__( 'Save settings', 'factuarea-for-woocommerce' ); ?>
					</button>
				</p>
			</form>

			<h2><?php echo esc_html__( 'Connection test', 'factuarea-for-woocommerce' ); ?></h2>
			<?php $this->render_connection_test_block(); ?>

			<h2><?php echo esc_html__( 'Signing secret', 'factuarea-for-woocommerce' ); ?></h2>
			<?php $this->render_signing_secret_block(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the connection test block: what the test does, and the button that
	 * runs it.
	 *
	 * The result is not printed here. It is added as a settings notice by the
	 * handler and printed at the top of this screen after the redirect, which is
	 * where an administrator already looks for the outcome of an action.
	 *
	 * @return void
	 */
	public function render_connection_test_block(): void {
		?>
		<p class="description">
			<?php
			echo esc_html__(
				'The test reads the configured store from the Factuarea API with the API key. It sends nothing to the delivery address, so it can never be mistaken for a sale, and it names which setting is wrong instead of only passing or failing.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_CONNECTION_ACTION ); ?>" />
			<?php wp_nonce_field( self::TEST_CONNECTION_ACTION ); ?>
			<button type="submit" class="button">
				<?php echo esc_html__( 'Test connection', 'factuarea-for-woocommerce' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Description under the section heading.
	 *
	 * @return void
	 */
	public function render_section_description(): void {
		?>
		<p>
			<?php
			echo esc_html__(
				'These values connect this shop to a store in Factuarea. Paid orders and refunds are delivered to that store, which turns them into invoices.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Base address field.
	 *
	 * @return void
	 */
	public function render_base_url_field(): void {
		$value = (string) get_option( Constants::OPTION_BASE_URL, '' );
		?>
		<input
			type="url"
			class="regular-text code"
			id="<?php echo esc_attr( Constants::OPTION_BASE_URL ); ?>"
			name="<?php echo esc_attr( Constants::OPTION_BASE_URL ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="https://app.factuarea.com"
			spellcheck="false"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			echo esc_html__(
				'Address of the Factuarea installation this shop delivers to, without a trailing slash. Paths are appended to it, so an address that points at a page instead of the application makes every delivery answer 404.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Address of the Factuarea API, which is not the address deliveries go to.
	 *
	 * @return void
	 */
	public function render_api_base_url_field(): void {
		?>
		<input
			type="url"
			class="regular-text code"
			id="<?php echo esc_attr( Constants::OPTION_API_BASE_URL ); ?>"
			name="<?php echo esc_attr( Constants::OPTION_API_BASE_URL ); ?>"
			value="<?php echo esc_attr( self::api_base_url() ); ?>"
			placeholder="<?php echo esc_attr( Constants::DEFAULT_API_BASE_URL ); ?>"
			spellcheck="false"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			echo esc_html__(
				'Address the plugin reads from: the connection test and the invoice PDFs. It is a different address from the one above, because Factuarea serves its API on its own host, and leaving it at the suggested value is right for a Factuarea account. Change it only for a self-hosted or staging installation.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * API key field. Never pre-filled with the stored value.
	 *
	 * @return void
	 */
	public function render_api_key_field(): void {
		$configured = '' !== (string) get_option( Constants::OPTION_API_KEY, '' );
		?>
		<input
			type="password"
			class="regular-text"
			id="<?php echo esc_attr( Constants::OPTION_API_KEY ); ?>"
			name="<?php echo esc_attr( Constants::OPTION_API_KEY ); ?>"
			value=""
			spellcheck="false"
			autocomplete="new-password"
		/>
		<p class="description">
			<?php
			echo $configured
				? esc_html__( 'An API key is stored. It is never shown again, not even in part.', 'factuarea-for-woocommerce' )
				: esc_html__( 'No API key is stored yet.', 'factuarea-for-woocommerce' );
			?>
			<?php
			echo esc_html__(
				'This key only reads: it runs the connection test and downloads invoice PDFs, and it never signs a delivery. Create it in Factuarea, then paste it here. Leaving this field empty keeps the key already stored.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Store identifier field.
	 *
	 * @return void
	 */
	public function render_store_id_field(): void {
		$value = (string) get_option( Constants::OPTION_STORE_ID, '' );
		?>
		<input
			type="text"
			class="regular-text code"
			id="<?php echo esc_attr( Constants::OPTION_STORE_ID ); ?>"
			name="<?php echo esc_attr( Constants::OPTION_STORE_ID ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			spellcheck="false"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			echo esc_html__(
				'Identifier of this shop as the store registered in Factuarea. Copy it from the store you created there; an identifier belonging to another account fails the connection test with its own message.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Delivery switch.
	 *
	 * The hidden input before the checkbox is not decoration: a checkbox that is
	 * not ticked posts nothing at all, so without it WordPress would receive no
	 * value for this option and store null, and the switch could be turned on
	 * but never off.
	 *
	 * @return void
	 */
	public function render_delivery_enabled_field(): void {
		$enabled = '1' === (string) get_option( Constants::OPTION_DELIVERY_ENABLED, '0' );
		?>
		<input type="hidden" name="<?php echo esc_attr( Constants::OPTION_DELIVERY_ENABLED ); ?>" value="0" />
		<label for="<?php echo esc_attr( Constants::OPTION_DELIVERY_ENABLED ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( Constants::OPTION_DELIVERY_ENABLED ); ?>"
				name="<?php echo esc_attr( Constants::OPTION_DELIVERY_ENABLED ); ?>"
				value="1"
				<?php echo $enabled ? ' checked="checked"' : ''; ?>
			/>
			<?php echo esc_html__( 'Deliver paid orders and refunds to Factuarea', 'factuarea-for-woocommerce' ); ?>
		</label>
		<p class="description">
			<?php
			echo esc_html__(
				'Switch this on once the connection test passes. While it is off, order events are still recorded and nothing is lost; they are simply not sent.',
				'factuarea-for-woocommerce'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the signing secret block: the one-time reveal, or the status and
	 * the generation button.
	 *
	 * @return void
	 */
	public function render_signing_secret_block(): void {
		$revealed = $this->reveal_signing_secret_once();
		$stored   = '' !== (string) get_option( Constants::OPTION_SIGNING_SECRET, '' );

		if ( '' !== $revealed ) {
			$this->render_revealed_secret( $revealed );
			return;
		}

		?>
		<p class="description">
			<?php
			echo $stored
				? esc_html__( 'A signing secret is stored. It is shown once, when it is generated, and never again - not even in part.', 'factuarea-for-woocommerce' )
				: esc_html__( 'No signing secret yet. Generate one, then paste it into the WooCommerce connection of Factuarea that backs this store. The secret belongs to the connection rather than to a single store, so every shop registered under that connection signs with the same value.', 'factuarea-for-woocommerce' );
			?>
		</p>
		<?php if ( $stored ) : ?>
			<p class="description">
				<?php
				echo esc_html__(
					'Generating a new one replaces the stored secret immediately, so deliveries are rejected until the new secret is pasted into Factuarea as well.',
					'factuarea-for-woocommerce'
				);
				?>
			</p>
		<?php endif; ?>
		<?php $this->render_generate_form( $stored ); ?>
		<?php
	}

	/**
	 * Sanitiser of the base address.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Cleaned address, without a trailing slash.
	 */
	public function sanitize_base_url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return rtrim( esc_url_raw( trim( (string) $value ) ), '/' );
	}

	/**
	 * Sanitiser of the API key.
	 *
	 * An empty or absent submission keeps the stored key. See the class docblock
	 * for why that rule exists and is not a convenience.
	 *
	 * @param mixed $value Submitted value.
	 * @return string The submitted key, or the stored one when nothing was
	 *                submitted.
	 */
	public function sanitize_api_key( $value ): string {
		$submitted = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';

		if ( '' === $submitted ) {
			return (string) get_option( Constants::OPTION_API_KEY, '' );
		}

		return $submitted;
	}

	/**
	 * Sanitiser of the store identifier.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Cleaned identifier.
	 */
	public function sanitize_store_id( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	/**
	 * Sanitiser of the signing secret.
	 *
	 * It ignores whatever was submitted and returns the stored value, always.
	 * The screen renders no input for the secret, so anything arriving under
	 * this option name came from somewhere that has no business setting it; and
	 * WordPress saves every registered option of a submitted page, so without
	 * this the secret would be replaced by null on each save.
	 *
	 * @param mixed $value Submitted value, ignored on purpose.
	 * @return string The stored secret.
	 */
	public function sanitize_signing_secret( $value ): string {
		unset( $value );

		return (string) get_option( Constants::OPTION_SIGNING_SECRET, '' );
	}

	/**
	 * Sanitiser of the delivery switch.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Either '1' or '0'.
	 */
	public function sanitize_delivery_enabled( $value ): string {
		return ( is_scalar( $value ) && '1' === (string) $value ) ? '1' : '0';
	}

	/**
	 * Generates a signing secret from a cryptographically secure source.
	 *
	 * `random_bytes` and nothing else: it is the only source in PHP that fails
	 * loudly rather than returning a predictable value when the platform has no
	 * good randomness, and a predictable signing secret is a forged sale in the
	 * merchant's books.
	 *
	 * @return string A hexadecimal secret of self::SIGNING_SECRET_LENGTH
	 *                characters.
	 */
	public static function generate_signing_secret(): string {
		return bin2hex( random_bytes( self::SIGNING_SECRET_BYTES ) );
	}

	/**
	 * Replaces the stored signing secret with a freshly generated one and marks
	 * it as not yet shown.
	 *
	 * @return string The new secret.
	 */
	public function rotate_signing_secret(): string {
		$secret = self::generate_signing_secret();

		update_option( Constants::OPTION_SIGNING_SECRET, $secret );
		update_option( Constants::OPTION_SECRET_REVEALED, '0' );

		return $secret;
	}

	/**
	 * Returns the stored signing secret the first time it is asked for after a
	 * generation, and an empty string every time after that.
	 *
	 * The flag is written by this method rather than by the renderer, and that
	 * is what makes "once" mean once: a reload, a second render on the same
	 * request, or a second caller all go through here, so none of them can show
	 * a secret a previous one already showed.
	 *
	 * @return string The secret, or an empty string when there is none or it has
	 *                already been shown.
	 */
	public function reveal_signing_secret_once(): string {
		$secret = (string) get_option( Constants::OPTION_SIGNING_SECRET, '' );

		if ( '' === $secret ) {
			return '';
		}

		if ( self::SECRET_REVEALED === (string) get_option( Constants::OPTION_SECRET_REVEALED, '' ) ) {
			return '';
		}

		update_option( Constants::OPTION_SECRET_REVEALED, self::SECRET_REVEALED );

		return $secret;
	}

	/**
	 * Handles the generation request posted to `admin-post.php`.
	 *
	 * @return void
	 */
	public function handle_generate_signing_secret(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to generate a signing secret for this shop. Ask an administrator for the shop manager capability, then try again.', 'factuarea-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::GENERATE_SECRET_ACTION );

		$this->rotate_signing_secret();

		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	/**
	 * Handles the connection test posted to `admin-post.php`.
	 *
	 * The result travels back through the settings notices that WordPress
	 * already carries across a redirect, rather than through query arguments of
	 * this plugin's own. Two reasons, and neither is style: a message put in a
	 * query argument is a message an attacker can put there too, and it would
	 * arrive already shortened to whatever fits in an address.
	 *
	 * @return void
	 */
	public function handle_test_connection(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test the Factuarea connection of this shop. Ask an administrator for the shop manager capability, then try again.', 'factuarea-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::TEST_CONNECTION_ACTION );

		$result = $this->tester()->test();

		add_settings_error(
			self::OPTION_GROUP,
			self::TEST_CONNECTION_ACTION,
			$result['message'] . ' ' . $result['next_step'],
			true === $result['ok'] ? 'success' : 'error'
		);

		/*
		 * The redirect loses everything in memory, so the notices are handed to
		 * the transient WordPress reads them back from. Thirty seconds is the
		 * whole life of that hand-off: it is consumed by the very next page
		 * load, and a longer life would show yesterday's result to whoever opens
		 * this screen next.
		 */
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $this->settings_url() ) );
		exit;
	}

	/**
	 * Prints the stalled outbox notice, or nothing when the outbox is healthy.
	 *
	 * @return void
	 */
	public function render_stalled_outbox_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( '1' !== (string) get_option( Constants::OPTION_DELIVERY_ENABLED, '0' ) ) {
			return;
		}

		$markup = $this->stalled_outbox_notice_markup( $this->stalled_counts() );

		if ( '' === $markup ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the method above, which is where the escaping is asserted.
		echo $markup;
	}

	/**
	 * Builds the stalled outbox notice from a pair of counts.
	 *
	 * Kept apart from the printing so that the decision - does this outbox
	 * deserve a notice, and what does it say - can be exercised without a
	 * database, and so that the counting stays in the one class allowed to read
	 * the outbox table.
	 *
	 * @param array $counts Counts as the outbox repository reports them, with an
	 *                      `exhausted` and an `overdue` entry.
	 * @return string The notice markup, or an empty string when the outbox is
	 *                healthy.
	 */
	public function stalled_outbox_notice_markup( array $counts ): string {
		$exhausted = isset( $counts['exhausted'] ) && is_numeric( $counts['exhausted'] ) ? (int) $counts['exhausted'] : 0;
		$overdue   = isset( $counts['overdue'] ) && is_numeric( $counts['overdue'] ) ? (int) $counts['overdue'] : 0;

		if ( $exhausted <= 0 && $overdue <= 0 ) {
			return '';
		}

		$sentences = array();

		if ( $exhausted > 0 ) {
			/* translators: %d: number of outbox entries that used up every delivery attempt. */
			$exhausted_sentence = _n(
				'%d order event has used up every delivery attempt and will not be retried on its own.',
				'%d order events have used up every delivery attempt and will not be retried on their own.',
				$exhausted,
				'factuarea-for-woocommerce'
			);

			$sentences[] = sprintf( esc_html( $exhausted_sentence ), $exhausted );
		}

		if ( $overdue > 0 ) {
			/* translators: 1: number of outbox entries past their next attempt. 2: how long they have been due, already written out. */
			$overdue_sentence = _n(
				'%1$d order event has been waiting for its next delivery attempt for more than %2$s.',
				'%1$d order events have been waiting for their next delivery attempt for more than %2$s.',
				$overdue,
				'factuarea-for-woocommerce'
			);

			$sentences[] = sprintf( esc_html( $overdue_sentence ), $overdue, esc_html( $this->overdue_window_label() ) );
		}

		$next_step = sprintf(
			/* translators: %s: link to the settings screen of this plugin. */
			esc_html__(
				'Next step: check that scheduled tasks run on this site - if your host switched WP-Cron off, add a system cron entry that calls wp-cron.php - and then run the connection test on %s.',
				'factuarea-for-woocommerce'
			),
			'<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'the Factuarea settings screen', 'factuarea-for-woocommerce' ) . '</a>'
		);

		return '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'Factuarea for WooCommerce: order events are not being delivered.', 'factuarea-for-woocommerce' )
			. '</strong></p><p>'
			. implode( ' ', $sentences )
			. '</p><p>'
			. $next_step
			. '</p></div>';
	}

	/**
	 * Adds a block to the Site Health information, with both credentials
	 * replaced by a status.
	 *
	 * The replacement is total. Not the first characters, not the last, not a
	 * length, not a hash: Site Health has a "copy to clipboard" button whose
	 * whole purpose is pasting this block into a support thread, so anything
	 * printed here should be assumed to end up in one.
	 *
	 * Both `value` and `debug` carry the same status, because Site Health copies
	 * `debug` when it is present and shows `value` on screen: writing the real
	 * credential into either one is enough to leak it.
	 *
	 * @param array $info Site Health information, section by section.
	 * @return array The same information with this plugin's block added.
	 */
	public function debug_information( array $info ): array {
		$info[ self::DEBUG_INFORMATION_SECTION ] = array(
			'label'       => __( 'Factuarea for WooCommerce', 'factuarea-for-woocommerce' ),
			'description' => __( 'How this shop is connected to Factuarea. The API key and the signing secret are never included here, in whole or in part.', 'factuarea-for-woocommerce' ),
			'fields'      => array(
				'base_url'         => array(
					'label' => __( 'Factuarea address', 'factuarea-for-woocommerce' ),
					'value' => (string) get_option( Constants::OPTION_BASE_URL, '' ),
					'debug' => (string) get_option( Constants::OPTION_BASE_URL, '' ),
				),
				'api_base_url'     => array(
					'label' => __( 'Factuarea API address', 'factuarea-for-woocommerce' ),
					'value' => self::api_base_url(),
					'debug' => self::api_base_url(),
				),
				'store_id'         => array(
					'label' => __( 'Store identifier', 'factuarea-for-woocommerce' ),
					'value' => (string) get_option( Constants::OPTION_STORE_ID, '' ),
					'debug' => (string) get_option( Constants::OPTION_STORE_ID, '' ),
				),
				'delivery_enabled' => array(
					'label' => __( 'Delivery', 'factuarea-for-woocommerce' ),
					'value' => '1' === (string) get_option( Constants::OPTION_DELIVERY_ENABLED, '0' )
						? __( 'On', 'factuarea-for-woocommerce' )
						: __( 'Off', 'factuarea-for-woocommerce' ),
					'debug' => '1' === (string) get_option( Constants::OPTION_DELIVERY_ENABLED, '0' ) ? 'on' : 'off',
				),
				'api_key'          => array(
					'label'   => __( 'API key', 'factuarea-for-woocommerce' ),
					'value'   => $this->credential_status( Constants::OPTION_API_KEY ),
					'debug'   => $this->credential_status( Constants::OPTION_API_KEY ),
					'private' => true,
				),
				'signing_secret'   => array(
					'label'   => __( 'Signing secret', 'factuarea-for-woocommerce' ),
					'value'   => $this->credential_status( Constants::OPTION_SIGNING_SECRET ),
					'debug'   => $this->credential_status( Constants::OPTION_SIGNING_SECRET ),
					'private' => true,
				),
			),
		);

		return $info;
	}

	/**
	 * Address of the settings screen.
	 *
	 * @return string Absolute admin URL of the screen.
	 */
	public function settings_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * The address the v1 reads go to, with the default applied.
	 *
	 * The default is applied HERE and not left to the one declared in
	 * `register_setting`: that one only reaches a reader who asks `get_option`
	 * for the value without passing a fallback of its own, and every reader in
	 * this plugin passes one. Resolving it in a single method is what makes the
	 * connection test and the invoice download read the same address as the
	 * field shows.
	 *
	 * Static because the readers of this address are not the settings screen -
	 * the connection test and the invoice PDF link are - and none of them has a
	 * reason to build one.
	 *
	 * @return string Address without a trailing slash.
	 */
	public static function api_base_url(): string {
		$stored = rtrim( trim( (string) get_option( Constants::OPTION_API_BASE_URL, '' ) ), '/' );

		return '' === $stored ? Constants::DEFAULT_API_BASE_URL : $stored;
	}

	/**
	 * The counts the notice is built from.
	 *
	 * @return array{exhausted: int, overdue: int} Counts as the outbox reports
	 *                                             them.
	 */
	private function stalled_counts(): array {
		return $this->outbox()->count_stalled( $this->overdue_after_seconds, time() );
	}

	/**
	 * The outbox reader, built on first use.
	 *
	 * @return OutboxRepository Reader of the outbox table.
	 */
	private function outbox(): OutboxRepository {
		if ( null === $this->outbox ) {
			$this->outbox = new OutboxRepository();
		}

		return $this->outbox;
	}

	/**
	 * The connection test, built on first use.
	 *
	 * @return ConnectionTester Runner of the connection test.
	 */
	private function tester(): ConnectionTester {
		if ( null === $this->tester ) {
			$this->tester = new ConnectionTester();
		}

		return $this->tester;
	}

	/**
	 * Whether a credential is stored, said without saying anything about it.
	 *
	 * Two words, and neither of them is derived from the value: no length, no
	 * prefix, no hash. See `Exception\PluginException` for why the prohibition
	 * covers truncated forms as well.
	 *
	 * @param string $option Option name of the credential.
	 * @return string A status, never a credential.
	 */
	private function credential_status( string $option ): string {
		return '' === (string) get_option( $option, '' )
			? __( 'Not configured', 'factuarea-for-woocommerce' )
			: __( 'Configured', 'factuarea-for-woocommerce' );
	}

	/**
	 * How long an entry has to be overdue, written for a person.
	 *
	 * @return string The window, in minutes or in hours.
	 */
	private function overdue_window_label(): string {
		$minutes = (int) round( $this->overdue_after_seconds / 60 );

		if ( $minutes < 60 ) {
			return sprintf(
				/* translators: %d: number of minutes. */
				_n( '%d minute', '%d minutes', $minutes, 'factuarea-for-woocommerce' ),
				$minutes
			);
		}

		$hours = (int) round( $minutes / 60 );

		return sprintf(
			/* translators: %d: number of hours. */
			_n( '%d hour', '%d hours', $hours, 'factuarea-for-woocommerce' ),
			$hours
		);
	}

	/**
	 * Renders the secret, once, with a button that copies it.
	 *
	 * @param string $secret The freshly generated secret.
	 * @return void
	 */
	private function render_revealed_secret( string $secret ): void {
		?>
		<div class="notice notice-warning inline">
			<p><strong><?php echo esc_html__( 'Copy this signing secret now. It is not shown again.', 'factuarea-for-woocommerce' ); ?></strong></p>
			<p>
				<code id="factuarea-wc-signing-secret"><?php echo esc_html( $secret ); ?></code>
				<button
					type="button"
					class="button"
					id="factuarea-wc-copy-secret"
					data-copied="<?php echo esc_attr__( 'Copied', 'factuarea-for-woocommerce' ); ?>"
				>
					<?php echo esc_html__( 'Copy', 'factuarea-for-woocommerce' ); ?>
				</button>
			</p>
			<p>
				<?php
				echo esc_html__(
					'Paste it into the WooCommerce connection of Factuarea that backs this store, under Integrations, and save. The secret belongs to the connection rather than to a single store, so every shop registered under that connection signs with the same value. Until the two match, every delivery is rejected as unsigned.',
					'factuarea-for-woocommerce'
				);
				?>
			</p>
		</div>
		<?php
		wp_print_inline_script_tag( $this->copy_button_script() );
	}

	/**
	 * Renders the form that generates a signing secret.
	 *
	 * @param bool $replacing Whether a secret is already stored.
	 * @return void
	 */
	private function render_generate_form( bool $replacing ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::GENERATE_SECRET_ACTION ); ?>" />
			<?php wp_nonce_field( self::GENERATE_SECRET_ACTION ); ?>
			<button type="submit" class="button">
				<?php
				echo $replacing
					? esc_html__( 'Generate a new signing secret', 'factuarea-for-woocommerce' )
					: esc_html__( 'Generate a signing secret', 'factuarea-for-woocommerce' );
				?>
			</button>
		</form>
		<?php
	}

	/**
	 * The script behind the copy button.
	 *
	 * It reads the secret out of the element that already shows it rather than
	 * carrying a second copy in an attribute: one place on the page holds the
	 * secret, so there is one place to look when asking what this screen
	 * printed.
	 *
	 * @return string Script body, without its tag.
	 */
	private function copy_button_script(): string {
		return "( function () {\n"
			. "\tvar button = document.getElementById( 'factuarea-wc-copy-secret' );\n"
			. "\tvar secret = document.getElementById( 'factuarea-wc-signing-secret' );\n"
			. "\tif ( ! button || ! secret || ! navigator.clipboard ) { return; }\n"
			. "\tbutton.addEventListener( 'click', function () {\n"
			. "\t\tnavigator.clipboard.writeText( secret.textContent.trim() ).then( function () {\n"
			. "\t\t\tbutton.textContent = button.dataset.copied;\n"
			. "\t\t} );\n"
			. "\t} );\n"
			. '} )();';
	}
}
