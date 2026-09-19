<?php
/**
 * Unit test of what the buyer sees about the fulfilment of their own order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\MyAccount\OrderStatusDisplay;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each group is here.
 *
 *  1. THE SECTION APPEARS ONLY WITH SOMETHING RECEIVED. An order for which a
 *     fulfilment notice arrived shows the state; one for which nothing arrived
 *     shows NOTHING - asserted on the empty string and not on the absence of a
 *     word, because a section saying «no information yet» satisfies the second
 *     shape and is exactly what the requirement forbids. It is the rule the
 *     neighbouring invoice access already follows.
 *
 *  2. THE ACCESS IS NEVER PAINTED WITHOUT A DESTINATION. A state that carries
 *     no tracking address paints the state and the carrier and NO link. A link
 *     that leads nowhere is worse than no link: the buyer clicks it, lands on
 *     an error and writes to the shop.
 *
 *  3. OWNERSHIP GOVERNS EVERY SURFACE. Somebody else's order, a visitor with no
 *     session and a buyer whose shop revoked their order capability all get
 *     nothing rendered, in the detail AND in the row of the orders list.
 *
 *  4. EVERYTHING PAINTED IS ESCAPED. The carrier, the number, the address and
 *     the corrective reference all arrived from outside this shop, through a
 *     signed delivery that authenticates the SENDER and says nothing about the
 *     content. They are asserted escaped with values that carry markup, so a
 *     missing escape shows up as a failing test rather than as script running
 *     on the account page of every buyer of the shop.
 *
 *  5. THE WIRE LITERAL NEVER REACHES THE BUYER. What the platform publishes is
 *     `prepared|shipped|delivered|returned`; what the buyer reads is a label in
 *     their own language. The four are asserted one by one.
 *
 *  6. NOTHING IS WRITTEN. The order double is strict, so any call this class
 *     makes beyond reading - `set_status()`, `save()`, `add_order_note()` -
 *     fails the test by itself rather than by an assertion somebody has to
 *     remember to write.
 */
final class OrderStatusDisplayTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Identifier of the order of every case.
	 */
	private const ORDER_ID = 812;

	/**
	 * Identifier of the logged-in buyer who owns that order.
	 */
	private const BUYER_ID = 44;

	/**
	 * Identifier of somebody who is logged in and owns nothing here.
	 */
	private const STRANGER_ID = 91;

	/**
	 * Carrier of the shipment of most cases.
	 */
	private const CARRIER = 'Correos Express';

	/**
	 * Tracking number of the shipment of most cases.
	 */
	private const TRACKING_NUMBER = 'JJD-000-000-123456';

	/**
	 * Tracking address of the shipment of most cases.
	 */
	private const TRACKING_URL = 'https://tracking.example/parcel/JJD-000-000-123456';

	/**
	 * Public reference of the corrective invoice of the refund cases.
	 */
	private const CORRECTIVE_REFERENCE = 'R-2026-0007';

	/**
	 * Instant of the fact, as the notice declared it. Read by nothing on this
	 * screen; present because a real order carries it and the class must not
	 * start depending on its absence.
	 */
	private const APPLIED_AT = '2026-09-18T10:00:00+02:00';

	/**
	 * Identifier the doubled `get_current_user_id` answers with.
	 *
	 * @var int
	 */
	private int $current_user_id = self::BUYER_ID;

	/**
	 * What the doubled `current_user_can` answers.
	 *
	 * @var bool
	 */
	private bool $capability_granted = true;

	/**
	 * Capability checks the doubled `current_user_can` was asked, as pairs of
	 * capability and argument.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $capability_checks = array();

	/**
	 * Installs the doubles of the WordPress functions this class reaches for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->current_user_id    = self::BUYER_ID;
		$this->capability_granted = true;
		$this->capability_checks  = array();

		Functions\when( 'get_current_user_id' )->alias(
			function (): int {
				return $this->current_user_id;
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function ( string $capability, $argument = null ): bool {
				$this->capability_checks[] = array( $capability, $argument );

				return $this->capability_granted;
			}
		);

		// WordPress's own wrapper answers exactly what PHP's parser answers for
		// every address these cases carry; the wrapper exists for relative ones
		// on old PHP, which this package's floor of 8.1 does not have.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
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
	 * The two surfaces are the two the invoice access already uses, and both are
	 * hooked AFTER it.
	 *
	 * The priority is asserted rather than left to the order in which the plugin
	 * registers its pieces: «after the invoice access» is a promise of the
	 * wiring task, and a promise that depends on the order of two lines in
	 * another file is one somebody breaks while tidying that file.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_same_two_surfaces_as_the_invoice_access(): void {
		Actions\expectAdded( 'woocommerce_order_details_after_order_table' )
			->once()
			->with( Mockery::type( 'array' ), OrderStatusDisplay::HOOK_PRIORITY, 1 );
		Filters\expectAdded( 'woocommerce_my_account_my_orders_actions' )
			->once()
			->with( Mockery::type( 'array' ), OrderStatusDisplay::HOOK_PRIORITY, 2 );

		( new OrderStatusDisplay() )->register();

		$this->assertGreaterThan(
			10,
			OrderStatusDisplay::HOOK_PRIORITY,
			'The invoice access hooks both points at the default priority of 10; this section is asked to appear after it.'
		);
	}

	/**
	 * A shipped order with tracking shows the state, the carrier and the access
	 * to the carrier's own page.
	 *
	 * @return void
	 */
	public function test_a_shipped_order_with_tracking_shows_the_state_the_carrier_and_the_access(): void {
		$markup = $this->render( $this->order( $this->shipped_with_tracking() ) );

		$this->assertStringContainsString( 'Shipped', $markup );
		$this->assertStringContainsString( self::CARRIER, $markup );
		$this->assertStringContainsString( self::TRACKING_NUMBER, $markup );
		$this->assertStringContainsString( 'href="' . self::TRACKING_URL . '"', $markup );
		$this->assertStringContainsString( 'Track your shipment', $markup );
	}

	/**
	 * The link to the carrier opens away from the shop and hands the carrier
	 * nothing about where the buyer came from.
	 *
	 * @return void
	 */
	public function test_the_access_opens_apart_and_leaks_no_referrer(): void {
		$markup = $this->render( $this->order( $this->shipped_with_tracking() ) );

		$this->assertStringContainsString( 'target="_blank"', $markup );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $markup );
	}

	/**
	 * An order for which no fulfilment notice ever arrived shows nothing at all.
	 *
	 * @return void
	 */
	public function test_an_order_with_no_received_state_shows_nothing(): void {
		$this->assertSame(
			'',
			$this->render( $this->order( array() ) ),
			'An order nobody published anything about shows no section, not an empty one.'
		);
	}

	/**
	 * A state without a tracking address shows the state and the carrier, and no
	 * access at all.
	 *
	 * @return void
	 */
	public function test_a_state_without_an_address_shows_the_state_and_the_carrier_without_an_access(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::SHIPPED,
					Constants::FULFILMENT_APPLIED_AT_META_KEY => self::APPLIED_AT,
					Constants::TRACKING_CARRIER_META_KEY      => self::CARRIER,
					Constants::TRACKING_NUMBER_META_KEY       => self::TRACKING_NUMBER,
				)
			)
		);

		$this->assertStringContainsString( 'Shipped', $markup );
		$this->assertStringContainsString( self::CARRIER, $markup );
		$this->assertStringContainsString( self::TRACKING_NUMBER, $markup );
		$this->assertStringNotContainsString( '<a ', $markup, 'A link with nowhere to go is the one thing this screen must never paint.' );
		$this->assertStringNotContainsString( 'Track your shipment', $markup );
	}

	/**
	 * A delivered order - which by contract carries no tracking any more - shows
	 * its state and paints no leftover access.
	 *
	 * Measured upstream and written down by the phase that writes the meta: the
	 * three tracking keys mirror the LAST notice, so a `delivered` that follows
	 * a `shipped` deletes them. The absence is painted as an absence.
	 *
	 * @return void
	 */
	public function test_a_delivered_order_keeps_no_tracking_access(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::DELIVERED,
					Constants::FULFILMENT_APPLIED_AT_META_KEY => self::APPLIED_AT,
				)
			)
		);

		$this->assertStringContainsString( 'Delivered', $markup );
		$this->assertStringNotContainsString( '<a ', $markup );
		$this->assertStringNotContainsString( self::CARRIER, $markup );
	}

	/**
	 * Each of the four published states reaches the buyer as a readable label,
	 * and the wire literal reaches them never.
	 *
	 * @param string $state Wire literal as the platform publishes it.
	 * @param string $label What the buyer is to read instead.
	 * @return void
	 */
	#[DataProvider( 'states' )]
	public function test_each_published_state_is_painted_as_a_readable_label( string $state, string $label ): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY     => $state,
					Constants::FULFILMENT_APPLIED_AT_META_KEY => self::APPLIED_AT,
				)
			)
		);

		$this->assertStringContainsString( $label, $markup );
		$this->assertStringNotContainsString(
			$state,
			$markup,
			'The buyer reads a label in their language; the wire literal belongs to the meta, the note and the contract.'
		);
	}

	/**
	 * The four states and the label each one is painted with.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function states(): array {
		return array(
			'prepared'  => array( FulfilmentState::PREPARED, 'Ready to ship' ),
			'shipped'   => array( FulfilmentState::SHIPPED, 'Shipped' ),
			'delivered' => array( FulfilmentState::DELIVERED, 'Delivered' ),
			'returned'  => array( FulfilmentState::RETURNED, 'Returned' ),
		);
	}

	/**
	 * A stored state this version does not know is not approximated to the
	 * nearest one, and is not shown raw either.
	 *
	 * @return void
	 */
	public function test_a_state_outside_the_four_paints_nothing(): void {
		$this->assertSame(
			'',
			$this->render(
				$this->order(
					array(
						Constants::FULFILMENT_STATUS_META_KEY => 'lost-in-transit',
						Constants::TRACKING_CARRIER_META_KEY  => self::CARRIER,
					)
				)
			),
			'A word this version has not heard of has no honest label, and printing it raw would show the buyer a machine value.'
		);
	}

	/**
	 * A meta value that is not text - written by a version that stored something
	 * else - reads as absent instead of being printed.
	 *
	 * @return void
	 */
	public function test_a_state_that_is_not_text_reads_as_absent(): void {
		$this->assertSame(
			'',
			$this->render( $this->order( array( Constants::FULFILMENT_STATUS_META_KEY => array( 'shipped' ) ) ) )
		);
	}

	/**
	 * The order of somebody else shows nothing, in the detail and in the row.
	 *
	 * @return void
	 */
	public function test_the_order_of_somebody_else_shows_nothing(): void {
		$order = $this->order( $this->shipped_with_tracking(), self::STRANGER_ID );

		$this->assertSame( '', $this->render( $order ) );
		$this->assertSame( array(), $this->actions_for( $order ) );
	}

	/**
	 * A visitor with no session shows nothing, and the guest order of the shop
	 * does not match them by both identifiers being zero.
	 *
	 * @return void
	 */
	public function test_a_visitor_with_no_session_shows_nothing(): void {
		$this->current_user_id = 0;

		$order = $this->order( $this->shipped_with_tracking(), 0 );

		$this->assertSame( '', $this->render( $order ) );
		$this->assertSame( array(), $this->actions_for( $order ) );
	}

	/**
	 * A shop that revoked the buyer's order capability shows them nothing, and
	 * the capability asked is WooCommerce's own one, for this order.
	 *
	 * @return void
	 */
	public function test_a_revoked_order_capability_shows_nothing(): void {
		$this->capability_granted = false;

		$this->assertSame( '', $this->render( $this->order( $this->shipped_with_tracking() ) ) );
		$this->assertSame(
			array( array( OrderStatusDisplay::VIEW_ORDER_CAPABILITY, self::ORDER_ID ) ),
			$this->capability_checks
		);
	}

	/**
	 * Everything that arrived from outside is escaped before it is painted.
	 *
	 * @return void
	 */
	public function test_everything_painted_is_escaped(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::SHIPPED,
					Constants::FULFILMENT_APPLIED_AT_META_KEY => self::APPLIED_AT,
					Constants::TRACKING_CARRIER_META_KEY      => '<script>alert("carrier")</script>',
					Constants::TRACKING_NUMBER_META_KEY       => '"><b>number</b>',
					Constants::TRACKING_URL_META_KEY          => 'https://tracking.example/p?a=1&b=2',
					Constants::CORRECTIVE_REFERENCE_META_KEY  => '<em>R-2026-0007</em>',
					Constants::REFUND_SCOPE_META_KEY          => InboundRefundNotice::SCOPE_TOTAL,
				)
			)
		);

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringNotContainsString( '<b>', $markup );
		$this->assertStringNotContainsString( '<em>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringContainsString( '&lt;b&gt;', $markup );
		$this->assertStringContainsString( '&lt;em&gt;', $markup );
		$this->assertStringNotContainsString(
			'"><b>',
			$markup,
			'The quote that would close the attribute it sits in is the whole point of escaping the number.'
		);
	}

	/**
	 * A tracking address that is not an ordinary web address is not painted at
	 * all: the escape of the href is the second lock, not the first.
	 *
	 * @param string $address Address as it could have been stored.
	 * @return void
	 */
	#[DataProvider( 'addresses_that_are_not_web_addresses' )]
	public function test_an_address_that_is_not_a_web_address_is_not_painted( string $address ): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY => FulfilmentState::SHIPPED,
					Constants::TRACKING_CARRIER_META_KEY  => self::CARRIER,
					Constants::TRACKING_URL_META_KEY      => $address,
				)
			)
		);

		$this->assertStringContainsString( self::CARRIER, $markup, 'The rest of the section is unaffected by an address that cannot be offered.' );
		$this->assertStringNotContainsString( '<a ', $markup );
		$this->assertStringNotContainsString( 'Track your shipment', $markup );
	}

	/**
	 * Addresses that must never become a link on a buyer's page.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function addresses_that_are_not_web_addresses(): array {
		return array(
			'a script'           => array( 'javascript:alert(document.cookie)' ),
			'a script in caps'   => array( 'JavaScript:alert(1)' ),
			'an inline document' => array( 'data:text/html;base64,PHNjcmlwdD4=' ),
			'a local file'       => array( 'file:///etc/passwd' ),
			'no scheme at all'   => array( 'tracking.example/parcel/1' ),
			'scheme relative'    => array( '//tracking.example/parcel/1' ),
			'nothing but spaces' => array( '   ' ),
		);
	}

	/**
	 * The row of the orders list gains the access only when there is an address
	 * to send the buyer to, and never replaces what WooCommerce already built.
	 *
	 * @return void
	 */
	public function test_the_orders_list_row_gains_the_access_only_with_an_address(): void {
		$existing = array(
			'view' => array(
				'url'  => 'https://shop.example/my-account/view-order/812/',
				'name' => 'View',
			),
		);

		$with = ( new OrderStatusDisplay() )->add_orders_list_action( $existing, $this->order( $this->shipped_with_tracking() ) );

		$this->assertCount( 2, $with );
		$this->assertArrayHasKey( 'view', $with, 'The actions WooCommerce already built are kept.' );
		$this->assertSame( self::TRACKING_URL, $this->tracking_action( $with )['url'] );
		$this->assertSame( 'Track shipment', $this->tracking_action( $with )['name'] );

		$without = ( new OrderStatusDisplay() )->add_orders_list_action(
			$existing,
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY => FulfilmentState::SHIPPED,
					Constants::TRACKING_CARRIER_META_KEY  => self::CARRIER,
				)
			)
		);

		$this->assertSame( $existing, $without );
	}

	/**
	 * The address handed to the row is the stored one, unescaped: the template
	 * of WooCommerce escapes it, and escaping it twice is what turns an
	 * ampersand into `&amp;amp;` and the link into a 404.
	 *
	 * @return void
	 */
	public function test_the_row_carries_the_address_for_the_template_to_escape(): void {
		$actions = $this->actions_for(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY => FulfilmentState::SHIPPED,
					Constants::TRACKING_URL_META_KEY      => 'https://tracking.example/p?a=1&b=2',
				)
			)
		);

		$this->assertSame( 'https://tracking.example/p?a=1&b=2', $this->tracking_action( $actions )['url'] );
	}

	/**
	 * A row whose address is not a web address gains no action either: the two
	 * surfaces answer the same question with the same rule.
	 *
	 * @return void
	 */
	public function test_the_row_refuses_an_address_that_is_not_a_web_address(): void {
		$this->assertSame(
			array(),
			$this->actions_for(
				$this->order(
					array(
						Constants::FULFILMENT_STATUS_META_KEY => FulfilmentState::SHIPPED,
						Constants::TRACKING_URL_META_KEY      => 'javascript:alert(1)',
					)
				)
			)
		);
	}

	/**
	 * Whatever the row filter receives that is not an order leaves the actions
	 * exactly as they were.
	 *
	 * @return void
	 */
	public function test_the_row_of_something_that_is_not_an_order_is_left_alone(): void {
		$existing = array( 'view' => array( 'url' => 'https://shop.example/', 'name' => 'View' ) );

		$this->assertSame( $existing, ( new OrderStatusDisplay() )->add_orders_list_action( $existing, 'not an order' ) );
		$this->assertSame( array(), ( new OrderStatusDisplay() )->add_orders_list_action( 'not an array', 'not an order' ) );
	}

	/**
	 * The detail of something that is not an order prints nothing instead of
	 * failing: this action is fired from more than one template and is re-fired
	 * by other plugins.
	 *
	 * @return void
	 */
	public function test_the_detail_of_something_that_is_not_an_order_prints_nothing(): void {
		ob_start();

		try {
			( new OrderStatusDisplay() )->render_status( 'not an order' );
		} finally {
			$printed = (string) ob_get_clean();
		}

		$this->assertSame( '', $printed );
	}

	/**
	 * A refund published by the platform tells the buyer its scope and the
	 * document the money came back under.
	 *
	 * @return void
	 */
	public function test_a_refund_shows_its_scope_and_its_corrective_invoice(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::FULFILMENT_STATUS_META_KEY    => FulfilmentState::RETURNED,
					Constants::CORRECTIVE_REFERENCE_META_KEY => self::CORRECTIVE_REFERENCE,
					Constants::REFUND_SCOPE_META_KEY         => InboundRefundNotice::SCOPE_PARTIAL,
				)
			)
		);

		$this->assertStringContainsString( self::CORRECTIVE_REFERENCE, $markup );
		$this->assertStringContainsString( 'Partially refunded', $markup );
		$this->assertStringNotContainsString(
			InboundRefundNotice::SCOPE_PARTIAL,
			$markup,
			'The scope is a wire literal too, and the buyer reads a sentence.'
		);
	}

	/**
	 * A refund that arrived before - or without - any fulfilment notice is shown
	 * on its own, and it does not invent a shipping section to sit in.
	 *
	 * @return void
	 */
	public function test_a_refund_without_a_state_shows_itself_and_no_shipping_section(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::CORRECTIVE_REFERENCE_META_KEY => self::CORRECTIVE_REFERENCE,
					Constants::REFUND_SCOPE_META_KEY         => InboundRefundNotice::SCOPE_TOTAL,
				)
			)
		);

		$this->assertStringContainsString( self::CORRECTIVE_REFERENCE, $markup );
		$this->assertStringContainsString( 'Refunded in full', $markup );
		$this->assertStringNotContainsString( 'Shipping status', $markup );
	}

	/**
	 * A scope this version does not know is not shown, and it does not hide the
	 * reference, which is the half the buyer may have to quote.
	 *
	 * @return void
	 */
	public function test_an_unknown_refund_scope_is_not_painted_and_does_not_hide_the_reference(): void {
		$markup = $this->render(
			$this->order(
				array(
					Constants::CORRECTIVE_REFERENCE_META_KEY => self::CORRECTIVE_REFERENCE,
					Constants::REFUND_SCOPE_META_KEY         => 'half',
				)
			)
		);

		$this->assertStringContainsString( self::CORRECTIVE_REFERENCE, $markup );
		$this->assertStringNotContainsString( 'half', $markup );
	}

	/**
	 * The refund of somebody else's order is as invisible as their shipment.
	 *
	 * @return void
	 */
	public function test_the_refund_of_somebody_elses_order_shows_nothing(): void {
		$this->assertSame(
			'',
			$this->render(
				$this->order(
					array(
						Constants::CORRECTIVE_REFERENCE_META_KEY => self::CORRECTIVE_REFERENCE,
						Constants::REFUND_SCOPE_META_KEY         => InboundRefundNotice::SCOPE_TOTAL,
					),
					self::STRANGER_ID
				)
			)
		);
	}

	/**
	 * The sweep. This screen reads the order and paints; it does not deliver, it
	 * does not queue and it does not write anywhere.
	 *
	 * The structural gate of the package sweeps the same file by tokens; this
	 * one is here so that the failure names THIS class when somebody adds the
	 * line while looking at this file.
	 *
	 * @return void
	 */
	public function test_the_file_neither_delivers_nor_logs(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/MyAccount/OrderStatusDisplay.php' );

		$this->assertNotSame( '', $source, 'A sweep over an empty string proves nothing.' );

		foreach ( array( 'error_log', 'wp_remote_', 'wp_safe_remote_', 'curl_' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$source,
				sprintf( 'This screen reads and paints. `%s` belongs to the transport client, or to nowhere.', $forbidden )
			);
		}
	}

	/**
	 * Renders the order detail contribution and returns what it printed.
	 *
	 * @param MockInterface $order Order double the template passed.
	 * @return string
	 */
	private function render( MockInterface $order ): string {
		ob_start();

		try {
			( new OrderStatusDisplay() )->render_status( $order );
		} finally {
			$printed = (string) ob_get_clean();
		}

		return $printed;
	}

	/**
	 * Runs the orders list filter over an empty set of actions.
	 *
	 * @param MockInterface $order Order double of the row.
	 * @return array<string, array{url: string, name: string}>
	 */
	private function actions_for( MockInterface $order ): array {
		return ( new OrderStatusDisplay() )->add_orders_list_action( array(), $order );
	}

	/**
	 * The action this class added, out of the actions of a row.
	 *
	 * @param array<string, array{url: string, name: string}> $actions Actions of the row.
	 * @return array{url: string, name: string}
	 */
	private function tracking_action( array $actions ): array {
		$this->assertArrayHasKey( OrderStatusDisplay::ORDERS_LIST_ACTION_KEY, $actions );

		return $actions[ OrderStatusDisplay::ORDERS_LIST_ACTION_KEY ];
	}

	/**
	 * The meta of an order that was shipped with full tracking.
	 *
	 * @return array<string, mixed>
	 */
	private function shipped_with_tracking(): array {
		return array(
			Constants::FULFILMENT_STATUS_META_KEY     => FulfilmentState::SHIPPED,
			Constants::FULFILMENT_APPLIED_AT_META_KEY => self::APPLIED_AT,
			Constants::TRACKING_CARRIER_META_KEY      => self::CARRIER,
			Constants::TRACKING_NUMBER_META_KEY       => self::TRACKING_NUMBER,
			Constants::TRACKING_URL_META_KEY          => self::TRACKING_URL,
		);
	}

	/**
	 * An order double that answers the meta it was given and nothing else.
	 *
	 * The double is STRICT on purpose: a method this class calls and that is not
	 * declared here fails the test by itself. That is what pins down «this
	 * screen never writes» without an assertion anybody has to remember.
	 *
	 * @param array<string, mixed> $meta        Meta the order carries.
	 * @param int|null             $customer_id Customer of the order; the buyer by default.
	 * @return MockInterface
	 */
	private function order( array $meta, ?int $customer_id = null ): MockInterface {
		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( self::ORDER_ID );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $customer_id ?? self::BUYER_ID );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		return $order;
	}
}
