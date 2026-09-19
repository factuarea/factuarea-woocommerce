<?php
/**
 * What the buyer reads about the fulfilment of their own order.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\MyAccount;

use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use WC_Order;

/**
 * Shows a buyer, where they already read their order, what the platform
 * published about it: how far along its fulfilment is, how to follow the
 * parcel, and under which document any money came back.
 *
 * ---------------------------------------------------------------------------
 * IT READS, AND IT PAINTS. THAT IS ALL IT DOES
 * ---------------------------------------------------------------------------
 * Every value on this screen was written on the order by the inbound half of
 * this plugin - `Inbound\OrderStatusStorage` for the fulfilment state and its
 * tracking, `Inbound\RefundApplier` for the corrective reference and the scope
 * of a refund - after a signed delivery was verified and its idempotency key
 * claimed. This class writes nothing, saves nothing, queues nothing and asks
 * nothing of the network. A buyer opening their account page is not a reason to
 * talk to anybody, and a screen that wrote would write once per page view.
 *
 * It also never touches the NATIVE order status, and not merely because it has
 * no reason to: writing it fires `woocommerce_order_status_changed`, which this
 * same plugin listens on to enqueue a delivery back towards Factuarea, and
 * which drives every third-party automation the merchant installed.
 * `Contract\FulfilmentState` holds that reasoning in full. The published state
 * is shown NEXT TO the native one, never instead of it.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS SHOWN UNLESS SOMETHING WAS RECEIVED
 * ---------------------------------------------------------------------------
 * The signal that there is a fulfilment state to show is the meta key
 * `Constants::FULFILMENT_STATUS_META_KEY` holding one of the four published
 * literals; the signal that there is a refund to show is
 * `Constants::CORRECTIVE_REFERENCE_META_KEY` holding a reference. When neither
 * is there, NOTHING is rendered - not an empty section, not a «no information
 * yet» notice, not a disabled control. This is the same rule, for the same
 * reason, as the neighbouring `InvoicePdfLink`: an element that promises
 * something the shop cannot produce generates a support request per order and
 * gives the buyer nothing they can act on.
 *
 * A state the four literals do not contain is treated as absent rather than
 * approximated to the nearest one or printed raw. There is no honest label for
 * a word this version has not heard of, and `prepared` on a buyer's screen is a
 * machine value leaking into a page.
 *
 * ---------------------------------------------------------------------------
 * AN ACCESS IS PAINTED ONLY WHEN IT HAS SOMEWHERE TO GO
 * ---------------------------------------------------------------------------
 * The link to the carrier appears only when the order carries a tracking
 * ADDRESS. A carrier name and a tracking number do not add up to one: the
 * platform composes the address from its own carrier template and this package
 * deliberately does not recompose it, so when the address is absent there is
 * nothing to link to. The absence is shown as an absence.
 *
 * That is not a detail of taste. A link that leads nowhere is worse than no
 * link at all: the buyer clicks it, lands on somebody's 404, and writes to the
 * shop about a parcel that is on its way perfectly normally.
 *
 * The same follows from how the tracking meta works. It MIRRORS THE LAST
 * NOTICE, so a `delivered` that follows a `shipped` deletes the three tracking
 * keys - measured upstream, where only the shipped notice carries tracking at
 * all. A delivered order therefore shows its state and no access, and that is
 * correct rather than a gap: the parcel arrived, and there is nothing left to
 * follow.
 *
 * ---------------------------------------------------------------------------
 * THE ADDRESS IS CHECKED BEFORE IT IS OFFERED
 * ---------------------------------------------------------------------------
 * The address arrived from outside this shop. The delivery that brought it was
 * signed, which authenticates the SENDER and says nothing about the content, so
 * it is treated as data and not as markup: it is offered only when it is an
 * ordinary web address - `http` or `https`, with its scheme written down - and
 * escaped for a URL when it is printed.
 *
 * Both halves are needed, and in this order. `esc_url()` already refuses a
 * `javascript:` address, but it refuses it by returning an EMPTY string, which
 * would paint `<a href="">` - a link that goes nowhere, which is the one thing
 * this screen must never do. Deciding first and escaping second means the
 * anomaly shows up as no link rather than as a broken one.
 *
 * An address with no scheme is refused too. `//carrier.example/track` and
 * `carrier.example/track` look harmless and are not this package's to complete:
 * the platform publishes absolute addresses, and guessing a scheme here is how
 * a buyer ends up sent over plain HTTP to a page that only exists over TLS.
 *
 * ---------------------------------------------------------------------------
 * A BUYER SEES THEIR OWN ORDERS AND NOBODY ELSE'S
 * ---------------------------------------------------------------------------
 * Ownership is established BEFORE anything is read off the order, on both
 * surfaces, with exactly the pair of conditions `InvoicePdfLink` already uses:
 *
 *  1. The order's customer is the logged-in user. This one does not depend on
 *     WooCommerce's capability mapping being loaded.
 *  2. `current_user_can( 'view_order', … )` agrees, so a shop that revoked
 *     order access - a role plugin, an expired membership - revokes this too,
 *     and this plugin does not become the hole in somebody else's policy.
 *
 * A guest order shows nothing: it has no logged-in owner to compare against,
 * and the only thing that could stand in for one is the order key in the
 * address bar, which travels in referrers and in shared links. The identifier
 * of a visitor is zero and so is the customer of a guest order, so the visitor
 * is refused BEFORE the comparison rather than by it - two zeroes must never be
 * allowed to agree.
 *
 * ---------------------------------------------------------------------------
 * EVERYTHING PAINTED IS ESCAPED WHERE IT IS PAINTED
 * ---------------------------------------------------------------------------
 * Each value is escaped at the point of output and with the function that suits
 * its destination: `esc_html()` for text, `esc_attr()` for an attribute,
 * `esc_url()` for the address of the link. It is done at the print site rather
 * than on the way in, because a value escaped early travels through the code as
 * something that LOOKS safe and stops being obviously raw - and the day it is
 * printed somewhere else, nobody escapes it again.
 *
 * The address handed to the orders LIST is the exception, and deliberately so:
 * WooCommerce's own template escapes the URL of every row action, and escaping
 * it here as well would turn `&` into `&amp;amp;` and the link into a 404.
 */
final class OrderStatusDisplay {

	/**
	 * Priority both hooks are added with.
	 *
	 * It is above the default 10 that `InvoicePdfLink` uses because the wiring
	 * asks for this section to appear AFTER the invoice access. Saying it with
	 * a priority rather than with the order of two lines in `Plugin.php` is
	 * what keeps the promise true the day somebody tidies that file.
	 */
	public const HOOK_PRIORITY = 20;

	/**
	 * Capability asked of the current user, in addition to being the customer
	 * of the order. It is WooCommerce's own meta capability for reading one
	 * order, and it is the same one the invoice access asks for.
	 */
	public const VIEW_ORDER_CAPABILITY = 'view_order';

	/**
	 * Key this class adds to the actions of a row of the orders list.
	 */
	public const ORDERS_LIST_ACTION_KEY = 'factuarea-tracking';

	/**
	 * Class prefix of the fulfilment section, so a shop can style it.
	 */
	public const SHIPPING_BLOCK = 'factuarea-fulfilment';

	/**
	 * Class prefix of the refund section.
	 */
	public const REFUND_BLOCK = 'factuarea-refund';

	/**
	 * Schemes a tracking address may carry to be offered to a buyer.
	 *
	 * @var list<string>
	 */
	private const WEB_SCHEMES = array( 'http', 'https' );

	/**
	 * Hooks the two points the invoice access already uses, so the buyer finds
	 * the state of their order where they find its invoice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_status' ), self::HOOK_PRIORITY, 1 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_orders_list_action' ), self::HOOK_PRIORITY, 2 );
	}

	/**
	 * Prints what is known about the order under its table, or prints nothing.
	 *
	 * The parameter is not type-hinted as an order because this action is fired
	 * from more than one template and is re-fired by other plugins; a hint would
	 * turn a caller that passes something else into a fatal error on the buyer's
	 * account page. It is checked instead.
	 *
	 * @param mixed $order What the template passed, expected to be an order.
	 * @return void
	 */
	public function render_status( $order ): void {
		if ( ! $order instanceof WC_Order || ! $this->owns( $order, get_current_user_id() ) ) {
			return;
		}

		$this->print_shipping_section( $order );
		$this->print_refund_section( $order );
	}

	/**
	 * Adds the access to the carrier to one row of the orders list, or leaves
	 * the row alone.
	 *
	 * A row action is a link and nothing else, so the only thing of this screen
	 * that belongs here is the one datum that has a destination. The state
	 * itself is not turned into a button that goes nowhere; it is read in the
	 * detail, one click away.
	 *
	 * @param mixed $actions Actions of the row, as WooCommerce built them.
	 * @param mixed $order   Order of the row.
	 * @return array<string, array{url: string, name: string}> The actions, with
	 *         ours added when there is somewhere to send the buyer.
	 */
	public function add_orders_list_action( $actions, $order ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $order instanceof WC_Order || ! $this->owns( $order, get_current_user_id() ) ) {
			return $actions;
		}

		if ( '' === $this->fulfilment_state( $order ) ) {
			return $actions;
		}

		$address = $this->tracking_address( $order );

		if ( '' === $address ) {
			return $actions;
		}

		$actions[ self::ORDERS_LIST_ACTION_KEY ] = array(
			'url'  => $address,
			'name' => __( 'Track shipment', 'factuarea-for-woocommerce' ),
		);

		return $actions;
	}

	/**
	 * Prints the fulfilment section of an order that has one.
	 *
	 * @param WC_Order $order Order being rendered, already owned by the reader.
	 * @return void
	 */
	private function print_shipping_section( WC_Order $order ): void {
		$state = $this->fulfilment_state( $order );

		if ( '' === $state ) {
			return;
		}

		$carrier = $this->meta_string( $order, Constants::TRACKING_CARRIER_META_KEY );
		$number  = $this->meta_string( $order, Constants::TRACKING_NUMBER_META_KEY );
		$address = $this->tracking_address( $order );

		$this->open_section( self::SHIPPING_BLOCK, __( 'Shipping status', 'factuarea-for-woocommerce' ) );

		$this->print_row( self::SHIPPING_BLOCK, __( 'Status', 'factuarea-for-woocommerce' ), self::label_for( $state ) );

		if ( '' !== $carrier ) {
			$this->print_row( self::SHIPPING_BLOCK, __( 'Carrier', 'factuarea-for-woocommerce' ), $carrier );
		}

		if ( '' !== $number ) {
			$this->print_row( self::SHIPPING_BLOCK, __( 'Tracking number', 'factuarea-for-woocommerce' ), $number );
		}

		if ( '' !== $address ) {
			$this->print_access_row( self::SHIPPING_BLOCK, $address, __( 'Track your shipment', 'factuarea-for-woocommerce' ) );
		}

		$this->close_section();
	}

	/**
	 * Prints the refund section of an order that has one.
	 *
	 * The money itself is already visible: the refund created by
	 * `Inbound\RefundApplier` shows in the totals of the order like any other.
	 * What is NOT visible without this section is the document the money came
	 * back under, which is the half a buyer may have to quote, and whether the
	 * sale came back whole or in part.
	 *
	 * It has its own presence test rather than hanging off the fulfilment one.
	 * A refund and a fulfilment state are two separate deliveries - one notice
	 * per fact - so an order can legitimately carry one and not the other, and
	 * in either order.
	 *
	 * @param WC_Order $order Order being rendered, already owned by the reader.
	 * @return void
	 */
	private function print_refund_section( WC_Order $order ): void {
		$reference = $this->meta_string( $order, Constants::CORRECTIVE_REFERENCE_META_KEY );

		if ( '' === $reference ) {
			return;
		}

		$scope = self::scope_label_for( $this->meta_string( $order, Constants::REFUND_SCOPE_META_KEY ) );

		$this->open_section( self::REFUND_BLOCK, __( 'Refund', 'factuarea-for-woocommerce' ) );

		if ( '' !== $scope ) {
			$this->print_row( self::REFUND_BLOCK, __( 'Status', 'factuarea-for-woocommerce' ), $scope );
		}

		$this->print_row( self::REFUND_BLOCK, __( 'Corrective invoice', 'factuarea-for-woocommerce' ), $reference );

		$this->close_section();
	}

	/**
	 * Opens one section with its heading and the list its rows go in.
	 *
	 * @param string $block Class prefix of the section.
	 * @param string $title Heading the buyer reads, already translated.
	 * @return void
	 */
	private function open_section( string $block, string $title ): void {
		printf(
			'<section class="%1$s"><h2 class="%1$s__title">%2$s</h2><ul class="%1$s__list">',
			esc_attr( $block ),
			esc_html( $title )
		);
	}

	/**
	 * Closes the section opened by `open_section()`.
	 *
	 * @return void
	 */
	private function close_section(): void {
		echo '</ul></section>';
	}

	/**
	 * Prints one row of label and value.
	 *
	 * @param string $block Class prefix of the section.
	 * @param string $label What the value is, already translated.
	 * @param string $value The value itself, exactly as it was stored.
	 * @return void
	 */
	private function print_row( string $block, string $label, string $value ): void {
		printf(
			'<li class="%1$s__item"><span class="%1$s__label">%2$s</span> <span class="%1$s__value">%3$s</span></li>',
			esc_attr( $block ),
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Prints the row carrying the access to the carrier's own page.
	 *
	 * It opens apart from the shop, so the buyer does not lose the order they
	 * were reading, and it hands the carrier neither a window to navigate this
	 * page with nor the address the buyer came from.
	 *
	 * @param string $block   Class prefix of the section.
	 * @param string $address Tracking address, already established to be one.
	 * @param string $text    What the link reads, already translated.
	 * @return void
	 */
	private function print_access_row( string $block, string $address, string $text ): void {
		printf(
			'<li class="%1$s__item %1$s__item--access"><a class="%1$s__link" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></li>',
			esc_attr( $block ),
			esc_url( $address ),
			esc_html( $text )
		);
	}

	/**
	 * The fulfilment state of the order, or an empty string when there is none
	 * to show.
	 *
	 * @param WC_Order $order Order to read.
	 * @return string One of the four published literals, or an empty string.
	 */
	private function fulfilment_state( WC_Order $order ): string {
		$stored = $this->meta_string( $order, Constants::FULFILMENT_STATUS_META_KEY );

		return FulfilmentState::is_valid( $stored ) ? $stored : '';
	}

	/**
	 * The tracking address of the order when it is one that may be offered to a
	 * buyer, and an empty string otherwise.
	 *
	 * @param WC_Order $order Order to read.
	 * @return string
	 */
	private function tracking_address( WC_Order $order ): string {
		$address = $this->meta_string( $order, Constants::TRACKING_URL_META_KEY );

		if ( '' === $address ) {
			return '';
		}

		$scheme = wp_parse_url( $address, PHP_URL_SCHEME );

		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), self::WEB_SCHEMES, true ) ) {
			return '';
		}

		return $address;
	}

	/**
	 * What a buyer reads instead of the wire literal of a state.
	 *
	 * The four labels are built HERE, when they are needed, and not held in a
	 * constant: a constant is filled once, at the moment the file is loaded,
	 * which on a multilingual shop is before the language of the request is
	 * known - and every buyer would then read the language of whoever loaded the
	 * file first.
	 *
	 * @param string $state One of the four published literals.
	 * @return string The label, translated, or an empty string for anything else.
	 */
	private static function label_for( string $state ): string {
		$labels = array(
			FulfilmentState::PREPARED  => __( 'Ready to ship', 'factuarea-for-woocommerce' ),
			FulfilmentState::SHIPPED   => __( 'Shipped', 'factuarea-for-woocommerce' ),
			FulfilmentState::DELIVERED => __( 'Delivered', 'factuarea-for-woocommerce' ),
			FulfilmentState::RETURNED  => __( 'Returned', 'factuarea-for-woocommerce' ),
		);

		return $labels[ $state ] ?? '';
	}

	/**
	 * What a buyer reads instead of the wire literal of a refund scope.
	 *
	 * @param string $scope Scope as `Contract\InboundRefundNotice` declares them.
	 * @return string The sentence, translated, or an empty string for anything else.
	 */
	private static function scope_label_for( string $scope ): string {
		$labels = array(
			InboundRefundNotice::SCOPE_TOTAL   => __( 'Refunded in full', 'factuarea-for-woocommerce' ),
			InboundRefundNotice::SCOPE_PARTIAL => __( 'Partially refunded', 'factuarea-for-woocommerce' ),
		);

		return $labels[ $scope ] ?? '';
	}

	/**
	 * Whether the given user is the buyer of the given order.
	 *
	 * Identity first and capability second, which is the order that fails
	 * safely: the comparison is this plugin's own and holds whatever else is
	 * installed, and the capability is WooCommerce's, which a role plugin can
	 * widen. Requiring both means neither is the only thing standing between a
	 * visitor and somebody else's order. It is the same pair, in the same order,
	 * as `InvoicePdfLink::owns()`.
	 *
	 * @param WC_Order $order           Order being asked about.
	 * @param int      $current_user_id User asking.
	 * @return bool
	 */
	private function owns( WC_Order $order, int $current_user_id ): bool {
		if ( $current_user_id < 1 ) {
			return false;
		}

		if ( (int) $order->get_customer_id() !== $current_user_id ) {
			return false;
		}

		return (bool) current_user_can( self::VIEW_ORDER_CAPABILITY, $order->get_id() );
	}

	/**
	 * Reads one meta value as a trimmed string.
	 *
	 * Order meta is not typed and these keys can have been written by an older
	 * version of this plugin, so anything that is not a scalar reads as absent.
	 * A value that is not text is not a state, a carrier or an address, and
	 * printing one would put an array on a buyer's page.
	 *
	 * @param WC_Order $order Order to read from.
	 * @param string   $key   Meta key to read.
	 * @return string The value as a trimmed string, or an empty string.
	 */
	private function meta_string( WC_Order $order, string $key ): string {
		$stored = $order->get_meta( $key );

		if ( ! is_scalar( $stored ) ) {
			return '';
		}

		return trim( (string) $stored );
	}
}
