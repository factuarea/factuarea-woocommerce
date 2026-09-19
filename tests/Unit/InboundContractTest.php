<?php
/**
 * Unit tests for the frozen contracts of the inbound half.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Tests\Unit;

use Factuarea\WooCommerce\Contract\CatalogPushItem;
use Factuarea\WooCommerce\Contract\CatalogPushOutcome;
use Factuarea\WooCommerce\Contract\Constants;
use Factuarea\WooCommerce\Contract\FulfilmentState;
use Factuarea\WooCommerce\Contract\InboundRefundNotice;
use Factuarea\WooCommerce\Contract\InboundResponseStatus;
use Factuarea\WooCommerce\Contract\InboundStatusNotice;
use Factuarea\WooCommerce\Contract\RetrySchedule;
use Factuarea\WooCommerce\MyAccount\InvoiceCorrelationEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * What this file pins down, and why each case earns its place.
 *
 * These classes have no behaviour worth speaking of: they read a document and
 * answer a value or null. What they DO have is a contract with something
 * outside this repository - a sender that is already written, deployed, and
 * will not be redeployed at the same moment as any shop - so the cases here are
 * not a sample of branches. They are the places where a body can be accepted
 * that should not have been, or rejected that should not have been, and both
 * mistakes are silent: the first applies the wrong thing to an order, the
 * second makes a sender's queue retry seven times and give up.
 *
 * Four groups:
 *
 *  1. The factories accept a well-formed document, field for field.
 *  2. They answer null for EACH way of being malformed, one case per way. Not
 *     one case with everything wrong, which would pass even if only one of the
 *     guards worked.
 *  3. A NUMBER where the contract declares a string is rejected rather than
 *     cast. `1042` and `"1042"` look interchangeable until an identifier with a
 *     leading zero arrives, and `12.34` as a float is not the amount `"12.34"`.
 *  4. The retention window is strictly greater than the SUM of the retry
 *     schedule. Compared against the sum computed here and never against a
 *     number typed into this file: the point of the test is that WIDENING THE
 *     SCHEDULE turns it red, and a hand-written copy of today's total would go
 *     on passing while the hole opened.
 */
final class InboundContractTest extends TestCase {

	/**
	 * A status notice with every field the contract declares.
	 *
	 * @return array<string, mixed>
	 */
	private static function status_document(): array {
		return array(
			'order_id'        => '1042',
			'status'          => 'shipped',
			'occurred_at'     => '2026-09-19T10:00:00+00:00',
			'idempotency_key' => 'wc-1042-shipped-20260919T100000Z',
			'tracking'        => array(
				'number'   => 'TRK-0001',
				'carrier'  => 'Test Carrier',
				'url'      => 'https://tracking.example.test/TRK-0001',
				'packages' => 2,
			),
		);
	}

	/**
	 * A refund notice with every field the contract declares.
	 *
	 * @return array<string, mixed>
	 */
	private static function refund_document(): array {
		return array(
			'order_id'                     => '1042',
			'amount'                       => '12.34',
			'currency'                     => 'EUR',
			'scope'                        => 'partial',
			'corrective_invoice_reference' => 'R-2026-0007',
			'idempotency_key'              => 'wc-refund-019e0000-0000-7000-8000-000000000042',
		);
	}

	/**
	 * A catalogue push item with every key the contract enumerates.
	 *
	 * @return array<string, mixed>
	 */
	private static function catalog_item(): array {
		return array(
			'remote_id'          => '77',
			'sku'                => 'REF-001',
			'name'               => 'Test article',
			'description'        => '<p>Rich description</p>',
			'active'             => true,
			'price'              => '19.95',
			'available_quantity' => 7,
		);
	}

	/**
	 * The example of the frozen contract, read whole.
	 *
	 * The tracking values are asserted one by one rather than as an array,
	 * because the failure this catches is a pair of fields crossed over - the
	 * carrier read into the number - which an array comparison reports as "two
	 * arrays differ" and a field comparison reports by name.
	 */
	public function test_a_well_formed_status_notice_is_read_field_by_field(): void {
		$notice = InboundStatusNotice::from_array( self::status_document() );

		$this->assertNotNull( $notice );
		$this->assertSame( '1042', $notice->order_id );
		$this->assertSame( 'shipped', $notice->status );
		$this->assertSame( '2026-09-19T10:00:00+00:00', $notice->occurred_at );
		$this->assertSame( 'wc-1042-shipped-20260919T100000Z', $notice->idempotency_key );
		$this->assertSame( 'TRK-0001', $notice->tracking_number );
		$this->assertSame( 'Test Carrier', $notice->tracking_carrier );
		$this->assertSame( 'https://tracking.example.test/TRK-0001', $notice->tracking_url );
		$this->assertSame( 2, $notice->tracking_packages );
		$this->assertTrue( $notice->has_tracking() );
	}

	/**
	 * A shipment without tracking is still a shipment.
	 *
	 * The contract says the tracking block does NOT travel when there is
	 * nothing to put in it, precisely so that the receiver never has to tell
	 * "I do not know" from "there is none" over the same value. A receiver that
	 * required the block would stop publishing legitimate shipments.
	 */
	public function test_a_status_notice_without_tracking_is_accepted(): void {
		$document = self::status_document();
		unset( $document['tracking'] );

		$notice = InboundStatusNotice::from_array( $document );

		$this->assertNotNull( $notice );
		$this->assertNull( $notice->tracking_number );
		$this->assertNull( $notice->tracking_carrier );
		$this->assertNull( $notice->tracking_url );
		$this->assertNull( $notice->tracking_packages );
		$this->assertFalse( $notice->has_tracking() );
	}

	/**
	 * A partially declared tracking block is accepted with what it declares.
	 *
	 * The contract says only the present sub-keys travel. A carrier and a number
	 * without an address is the ordinary shape of a shipment whose carrier has
	 * no tracking page, and it must not be rejected - nor must an address be
	 * composed for it out of the other two.
	 */
	public function test_a_status_notice_with_a_partial_tracking_block_is_accepted(): void {
		$document             = self::status_document();
		$document['tracking'] = array(
			'number'  => 'TRK-0002',
			'carrier' => 'Test Carrier',
		);

		$notice = InboundStatusNotice::from_array( $document );

		$this->assertNotNull( $notice );
		$this->assertSame( 'TRK-0002', $notice->tracking_number );
		$this->assertSame( 'Test Carrier', $notice->tracking_carrier );
		$this->assertNull( $notice->tracking_url );
	}

	/**
	 * Each required field of the status notice, missing on its own.
	 *
	 * One case per field rather than one case with all four removed: with all
	 * four gone the test passes even if only one guard works.
	 *
	 * @dataProvider provide_required_status_fields
	 * @param string $field Name of the field to remove.
	 */
	public function test_a_status_notice_missing_a_required_field_is_not_read( string $field ): void {
		$document = self::status_document();
		unset( $document[ $field ] );

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * The four required fields of the status notice.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_required_status_fields(): array {
		return array(
			'order identifier' => array( 'order_id' ),
			'status'           => array( 'status' ),
			'instant'          => array( 'occurred_at' ),
			'idempotency key'  => array( 'idempotency_key' ),
		);
	}

	/**
	 * An empty required field is as unreadable as an absent one.
	 *
	 * An empty idempotency key deduplicates nothing and an empty order
	 * identifier resolves to no order while looking like a value, so accepting
	 * either would be accepting a delivery that cannot do what it claims.
	 *
	 * @dataProvider provide_required_status_fields
	 * @param string $field Name of the field to blank.
	 */
	public function test_a_status_notice_with_an_empty_required_field_is_not_read( string $field ): void {
		$document           = self::status_document();
		$document[ $field ] = '';

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * The order identifier arrives as a NUMBER instead of a string.
	 *
	 * This is the case the whole "strings on the wire" discipline exists for.
	 * Casting it would look harmless for `1042` and would silently resolve the
	 * wrong order - or none - for an identifier with a leading zero or one long
	 * enough to lose precision.
	 */
	public function test_a_status_notice_whose_order_identifier_is_a_number_is_not_read(): void {
		$document             = self::status_document();
		$document['order_id'] = 1042;

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * A status outside the four is malformed and is NOT approximated.
	 *
	 * Approximating is how a buyer gets told their parcel arrived because the
	 * sender used a word this version had not heard of.
	 */
	public function test_a_status_notice_with_a_status_outside_the_four_is_not_read(): void {
		$document           = self::status_document();
		$document['status'] = 'fulfilment_failed';

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * A tracking block that is present and is not an object.
	 *
	 * The sender said there is tracking and then did not describe it. That is a
	 * broken body, not an absent block, and treating it as absent would apply a
	 * shipment while quietly dropping the only data the buyer wanted.
	 */
	public function test_a_status_notice_whose_tracking_block_is_not_an_object_is_not_read(): void {
		$document             = self::status_document();
		$document['tracking'] = 'TRK-0001';

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * A tracking number sent as a number.
	 *
	 * Tracking numbers are not integers even when they look like one, and the
	 * ones with leading zeros are exactly the ones a cast breaks.
	 */
	public function test_a_status_notice_whose_tracking_number_is_a_number_is_not_read(): void {
		$document                       = self::status_document();
		$document['tracking']['number'] = 1;

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * The number of packages sent as a numeric string.
	 *
	 * The contract declares this one as an integer. Accepting both shapes is how
	 * a field ends up meaning two things, and this is the only field of the
	 * notice that is a count rather than a label.
	 */
	public function test_a_status_notice_whose_package_count_is_a_string_is_not_read(): void {
		$document                         = self::status_document();
		$document['tracking']['packages'] = '2';

		$this->assertNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * An unknown key does NOT reject a status notice.
	 *
	 * The asymmetry with the catalogue batch is deliberate and is asserted so
	 * that nobody "fixes" it into symmetry: here the body describes a fact, so a
	 * key this version does not read is a fact it does not show; there, an
	 * unknown key means something is being written into the merchant's
	 * catalogue that this version does not understand.
	 */
	public function test_a_status_notice_carrying_an_unknown_key_is_still_read(): void {
		$document                 = self::status_document();
		$document['delivered_by'] = 'somebody';

		$this->assertNotNull( InboundStatusNotice::from_array( $document ) );
	}

	/**
	 * The example of the frozen contract, read whole.
	 */
	public function test_a_well_formed_refund_notice_is_read_field_by_field(): void {
		$notice = InboundRefundNotice::from_array( self::refund_document() );

		$this->assertNotNull( $notice );
		$this->assertSame( '1042', $notice->order_id );
		$this->assertSame( '12.34', $notice->amount );
		$this->assertSame( 'EUR', $notice->currency );
		$this->assertSame( 'partial', $notice->scope );
		$this->assertSame( 'R-2026-0007', $notice->corrective_invoice_reference );
		$this->assertSame( 'wc-refund-019e0000-0000-7000-8000-000000000042', $notice->idempotency_key );
		$this->assertFalse( $notice->is_total() );
	}

	/**
	 * Each required field of the refund notice, missing on its own.
	 *
	 * @dataProvider provide_required_refund_fields
	 * @param string $field Name of the field to remove.
	 */
	public function test_a_refund_notice_missing_a_required_field_is_not_read( string $field ): void {
		$document = self::refund_document();
		unset( $document[ $field ] );

		$this->assertNull( InboundRefundNotice::from_array( $document ) );
	}

	/**
	 * The six required fields of the refund notice.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_required_refund_fields(): array {
		return array(
			'order identifier'              => array( 'order_id' ),
			'amount'                        => array( 'amount' ),
			'currency'                      => array( 'currency' ),
			'scope'                         => array( 'scope' ),
			'corrective invoice reference'  => array( 'corrective_invoice_reference' ),
			'idempotency key'               => array( 'idempotency_key' ),
		);
	}

	/**
	 * The amount arrives as a FLOAT.
	 *
	 * This is the single most expensive cast in this contract: a float cannot
	 * hold 12.34 exactly, so accepting it would refund 12.339999999999999 and
	 * nothing downstream could tell which of the two the platform meant.
	 */
	public function test_a_refund_notice_whose_amount_is_a_float_is_not_read(): void {
		$document           = self::refund_document();
		$document['amount'] = 12.34;

		$this->assertNull( InboundRefundNotice::from_array( $document ) );
	}

	/**
	 * An amount that is a string and is not a decimal.
	 *
	 * @dataProvider provide_unreadable_amounts
	 * @param string $amount Amount as it would arrive.
	 */
	public function test_a_refund_notice_whose_amount_is_not_a_decimal_string_is_not_read( string $amount ): void {
		$document           = self::refund_document();
		$document['amount'] = $amount;

		$this->assertNull( InboundRefundNotice::from_array( $document ) );
	}

	/**
	 * The shapes `is_numeric()` would have accepted and this contract does not.
	 *
	 * They are here by name because each one is a value WooCommerce would store
	 * verbatim and then render as a nonsense total.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_unreadable_amounts(): array {
		return array(
			'exponent notation'    => array( '1e3' ),
			'leading blank'        => array( ' 12.34' ),
			'thousands separator'  => array( '1,234.56' ),
			'currency symbol'      => array( '12.34 EUR' ),
			'not a number at all'  => array( 'twelve' ),
			'trailing point'       => array( '12.' ),
		);
	}

	/**
	 * A decimal with more than two places is accepted.
	 *
	 * The scale is not fixed at two on purpose: a currency with three decimals
	 * is a currency, and a receiver that insisted on two would reject it in
	 * every shop already published.
	 */
	public function test_a_refund_notice_with_a_three_decimal_amount_is_read(): void {
		$document           = self::refund_document();
		$document['amount'] = '12.345';

		$notice = InboundRefundNotice::from_array( $document );

		$this->assertNotNull( $notice );
		$this->assertSame( '12.345', $notice->amount );
	}

	/**
	 * A scope outside the two.
	 */
	public function test_a_refund_notice_with_a_scope_outside_the_two_is_not_read(): void {
		$document          = self::refund_document();
		$document['scope'] = 'half';

		$this->assertNull( InboundRefundNotice::from_array( $document ) );
	}

	/**
	 * A total refund answers so, because the two scopes are shown differently to
	 * the merchant and to the buyer.
	 */
	public function test_a_total_refund_notice_declares_its_scope(): void {
		$document          = self::refund_document();
		$document['scope'] = 'total';

		$notice = InboundRefundNotice::from_array( $document );

		$this->assertNotNull( $notice );
		$this->assertTrue( $notice->is_total() );
	}

	/**
	 * A catalogue item with every enumerated key.
	 */
	public function test_a_well_formed_catalog_item_is_read_field_by_field(): void {
		$item = CatalogPushItem::from_array( self::catalog_item() );

		$this->assertNotNull( $item );
		$this->assertSame( '77', $item->remote_id );
		$this->assertSame( 'REF-001', $item->sku );
		$this->assertSame( 'Test article', $item->name );
		$this->assertSame( '<p>Rich description</p>', $item->description );
		$this->assertTrue( $item->active );
		$this->assertSame( '19.95', $item->price );
		$this->assertSame( 7, $item->available_quantity );
		$this->assertTrue( $item->declares_price() );
		$this->assertTrue( $item->declares_quantity() );
	}

	/**
	 * An item without the reference cannot be applied to anything.
	 *
	 * The reference is the only way to resolve an article whose identity is not
	 * registered yet, so an item without one has no destination even when it
	 * carries a price.
	 */
	public function test_a_catalog_item_without_a_reference_is_not_read(): void {
		$item = self::catalog_item();
		unset( $item['sku'] );

		$this->assertNull( CatalogPushItem::from_array( $item ) );
	}

	/**
	 * An item without a registered identity is read: that is the ordinary first
	 * push of an article, which resolves by reference.
	 */
	public function test_a_catalog_item_without_a_remote_identity_is_read(): void {
		$item = self::catalog_item();
		unset( $item['remote_id'] );

		$read = CatalogPushItem::from_array( $item );

		$this->assertNotNull( $read );
		$this->assertNull( $read->remote_id );
		$this->assertSame( 'REF-001', $read->sender_reference() );
	}

	/**
	 * The article is named to the sender the way the sender named it.
	 *
	 * Never by an internal identifier of this shop: the sender asked about
	 * something it named, and answering with a WordPress post id publishes this
	 * shop's internals to a platform with no use for them.
	 */
	public function test_a_catalog_item_is_named_back_by_its_remote_identity_when_it_has_one(): void {
		$item = CatalogPushItem::from_array( self::catalog_item() );

		$this->assertNotNull( $item );
		$this->assertSame( '77', $item->sender_reference() );
	}

	/**
	 * A price sent as a number is unreadable.
	 *
	 * The same cast as the refunded amount, with the same consequence: the last
	 * decimal of a perfectly representable price, discovered in a real order.
	 */
	public function test_a_catalog_item_whose_price_is_a_number_is_not_read(): void {
		$item          = self::catalog_item();
		$item['price'] = 19.95;

		$this->assertNull( CatalogPushItem::from_array( $item ) );
	}

	/**
	 * A quantity sent as a string is unreadable.
	 */
	public function test_a_catalog_item_whose_quantity_is_a_string_is_not_read(): void {
		$item                       = self::catalog_item();
		$item['available_quantity'] = '7';

		$this->assertNull( CatalogPushItem::from_array( $item ) );
	}

	/**
	 * The activity flag sent as the string WordPress forms would produce.
	 */
	public function test_a_catalog_item_whose_activity_flag_is_a_string_is_not_read(): void {
		$item           = self::catalog_item();
		$item['active'] = 'yes';

		$this->assertNull( CatalogPushItem::from_array( $item ) );
	}

	/**
	 * A price STRING that is not a decimal is still READ here.
	 *
	 * This is the boundary between "malformed batch" and "rejected article", and
	 * it is asserted because getting it wrong is expensive in both directions:
	 * failing it here would make one bad amount reject the whole batch and lose
	 * the four good articles with it, while the price writer rejects that single
	 * article with a typed reason and leaves its previous price alone.
	 */
	public function test_a_catalog_item_whose_price_string_is_not_a_decimal_is_still_read(): void {
		$item          = self::catalog_item();
		$item['price'] = 'twelve';

		$read = CatalogPushItem::from_array( $item );

		$this->assertNotNull( $read );
		$this->assertSame( 'twelve', $read->price );
		$this->assertFalse( InboundRefundNotice::is_decimal_string( $read->price ) );
	}

	/**
	 * A key the contract does not enumerate rejects the item.
	 */
	public function test_a_catalog_item_with_an_unenumerated_key_is_not_read(): void {
		$item             = self::catalog_item();
		$item['weight_g'] = 500;

		$this->assertNull( CatalogPushItem::from_array( $item ) );
		$this->assertSame( array( 'weight_g' ), CatalogPushItem::unknown_keys_in( $item ) );
		$this->assertSame( array(), CatalogPushItem::internal_keys_in( $item ) );
	}

	/**
	 * An internal figure is reported apart from an unknown key.
	 *
	 * The two failures have different blast radii - one article against the
	 * whole batch - so the receiver has to be able to tell them apart, and it
	 * can only do that if these two inspectors answer separately.
	 *
	 * @dataProvider provide_internal_keys
	 * @param string $key Internal key that must never travel.
	 */
	public function test_an_internal_figure_is_reported_as_internal_and_not_as_unknown( string $key ): void {
		$item         = self::catalog_item();
		$item[ $key ] = 1;

		$this->assertNull( CatalogPushItem::from_array( $item ) );
		$this->assertSame( array( $key ), CatalogPushItem::internal_keys_in( $item ) );
		$this->assertSame( array(), CatalogPushItem::unknown_keys_in( $item ) );
	}

	/**
	 * The five figures that must never leave the platform.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_internal_keys(): array {
		return array(
			'cost'               => array( 'cost' ),
			'supplier'           => array( 'supplier' ),
			'physical balance'   => array( 'physical_quantity' ),
			'committed quantity' => array( 'committed_quantity' ),
			'reorder threshold'  => array( 'reorder_threshold' ),
		);
	}

	/**
	 * A push that declares no catalogue field declares none.
	 *
	 * This is what stops a price push from blanking a description: the writer
	 * applies the fields this answers with, and this answers with what the push
	 * actually carried.
	 */
	public function test_a_catalog_item_declares_only_the_fields_it_carries(): void {
		$item = CatalogPushItem::from_array(
			array(
				'sku'   => 'REF-001',
				'price' => '19.95',
			)
		);

		$this->assertNotNull( $item );
		$this->assertSame( array(), $item->declared_catalog_fields() );
		$this->assertTrue( $item->declares_price() );
		$this->assertFalse( $item->declares_quantity() );
	}

	/**
	 * The declared catalogue fields come back keyed by their wire name.
	 */
	public function test_a_catalog_item_hands_over_its_declared_catalog_fields(): void {
		$item = CatalogPushItem::from_array( self::catalog_item() );

		$this->assertNotNull( $item );
		$this->assertSame(
			array(
				'name'        => 'Test article',
				'description' => '<p>Rich description</p>',
				'active'      => true,
			),
			$item->declared_catalog_fields()
		);
	}

	/**
	 * An article switched OFF is a legitimate value and not an absent field.
	 *
	 * The boolean reader cannot use `false` as its "malformed" answer the way
	 * the string and integer readers do, and this is the case that proves it
	 * does not.
	 */
	public function test_a_catalog_item_may_declare_the_article_as_inactive(): void {
		$item           = self::catalog_item();
		$item['active'] = false;

		$read = CatalogPushItem::from_array( $item );

		$this->assertNotNull( $read );
		$this->assertFalse( $read->active );
		$this->assertArrayHasKey( 'active', $read->declared_catalog_fields() );
		$this->assertFalse( $read->declared_catalog_fields()['active'] );
	}

	/**
	 * An applied outcome carries no reason key at all.
	 *
	 * Not `"reason": null`: a key that is always there invites the reader to
	 * branch on its value instead of on its presence, and the presence is the
	 * shape that survives the contract gaining a sixth reason.
	 */
	public function test_an_applied_outcome_carries_no_reason(): void {
		$outcome = CatalogPushOutcome::applied( 'REF-001' );

		$this->assertTrue( $outcome->is_applied() );
		$this->assertNull( $outcome->reason );
		$this->assertSame(
			array(
				'reference' => 'REF-001',
				'status'    => 'applied',
			),
			$outcome->to_array()
		);
	}

	/**
	 * A rejection carries its typed reason.
	 *
	 * @dataProvider provide_rejection_reasons
	 * @param string $reason One of the five.
	 */
	public function test_a_rejected_outcome_carries_its_typed_reason( string $reason ): void {
		$outcome = CatalogPushOutcome::rejected( 'REF-001', $reason );

		$this->assertFalse( $outcome->is_applied() );
		$this->assertSame(
			array(
				'reference' => 'REF-001',
				'status'    => 'rejected',
				'reason'    => $reason,
			),
			$outcome->to_array()
		);
	}

	/**
	 * The five reasons, named one by one so that removing one from the class
	 * cannot go unnoticed.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_rejection_reasons(): array {
		return array(
			'not found'         => array( CatalogPushOutcome::REASON_NOT_FOUND ),
			'ambiguous'         => array( CatalogPushOutcome::REASON_AMBIGUOUS ),
			'stock not managed' => array( CatalogPushOutcome::REASON_STOCK_NOT_MANAGED ),
			'unreadable amount' => array( CatalogPushOutcome::REASON_UNREADABLE_AMOUNT ),
			'field not listed'  => array( CatalogPushOutcome::REASON_FIELD_NOT_ENUMERATED ),
		);
	}

	/**
	 * The five and no more.
	 */
	public function test_the_catalog_rejection_reasons_are_exactly_five(): void {
		$this->assertSame(
			array( 'not_found', 'ambiguous', 'stock_not_managed', 'unreadable_amount', 'field_not_enumerated' ),
			CatalogPushOutcome::VALID_REASONS
		);
	}

	/**
	 * An invented reason throws instead of travelling.
	 *
	 * A reason the sender cannot interpret is the same as no reason at all, and
	 * the failure would only be noticed in the sender's logs weeks later. This
	 * is the one place in the contract that throws, and it throws because it is
	 * a programming error of this plugin rather than a malformed delivery.
	 */
	public function test_an_invented_rejection_reason_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		CatalogPushOutcome::rejected( 'REF-001', 'because_i_said_so' );
	}

	/**
	 * The four publishable states, with the literals the platform publishes.
	 *
	 * Asserted as literals and not against anything in this repository: these
	 * ARE the wire values, and a state spelled differently is rejected as
	 * malformed on every single notice.
	 */
	public function test_the_four_fulfilment_states_are_the_wire_literals(): void {
		$this->assertSame( array( 'prepared', 'shipped', 'delivered', 'returned' ), FulfilmentState::ALL );
	}

	/**
	 * Every one of the four is valid and nothing else is.
	 */
	public function test_only_the_four_fulfilment_states_are_valid(): void {
		foreach ( FulfilmentState::ALL as $state ) {
			$this->assertTrue( FulfilmentState::is_valid( $state ) );
		}

		$this->assertFalse( FulfilmentState::is_valid( 'fulfilment_failed' ) );
		$this->assertFalse( FulfilmentState::is_valid( 'processing' ) );
		$this->assertFalse( FulfilmentState::is_valid( '' ) );
	}

	/**
	 * Only the shipped state carries tracking.
	 *
	 * Publishing a tracking number next to "prepared" would announce a shipment
	 * that has not left.
	 */
	public function test_only_the_shipped_state_carries_tracking(): void {
		$this->assertTrue( FulfilmentState::carries_tracking( FulfilmentState::SHIPPED ) );
		$this->assertFalse( FulfilmentState::carries_tracking( FulfilmentState::PREPARED ) );
		$this->assertFalse( FulfilmentState::carries_tracking( FulfilmentState::DELIVERED ) );
		$this->assertFalse( FulfilmentState::carries_tracking( FulfilmentState::RETURNED ) );
	}

	/**
	 * The seven statuses with their numbers, as the frozen contract tabulates
	 * them.
	 */
	public function test_the_response_statuses_are_the_frozen_numbers(): void {
		$this->assertSame( 204, InboundResponseStatus::ACCEPTED );
		$this->assertSame( 401, InboundResponseStatus::UNAUTHORISED );
		$this->assertSame( 403, InboundResponseStatus::RECEIVER_DISABLED );
		$this->assertSame( 404, InboundResponseStatus::UNKNOWN );
		$this->assertSame( 409, InboundResponseStatus::DUPLICATE_DISCARDED );
		$this->assertSame( 422, InboundResponseStatus::MALFORMED );
		$this->assertSame( 429, InboundResponseStatus::RATE_LIMITED );
	}

	/**
	 * The four that already existed are REFERENCED and not re-typed.
	 *
	 * Two equal literals written in two places diverge, and the divergence here
	 * would be silent: the sender would go on classifying, just wrongly.
	 */
	public function test_the_four_existing_statuses_are_the_ones_the_plugin_already_froze(): void {
		$this->assertSame( InvoiceCorrelationEndpoint::STATUS_ASSOCIATED, InboundResponseStatus::ACCEPTED );
		$this->assertSame( InvoiceCorrelationEndpoint::STATUS_UNAUTHORISED, InboundResponseStatus::UNAUTHORISED );
		$this->assertSame( InvoiceCorrelationEndpoint::STATUS_UNKNOWN_KEY, InboundResponseStatus::UNKNOWN );
		$this->assertSame( InvoiceCorrelationEndpoint::STATUS_MALFORMED, InboundResponseStatus::MALFORMED );
	}

	/**
	 * Each status classifies the way the sender's table says it does.
	 *
	 * The two PUBLISHED ones are the dangerous half: a receiver that answered
	 * something the sender reads as a failure would have it retry an applied
	 * refund, and one that answered 409 where nothing was applied would have it
	 * record as published a notice that never happened.
	 */
	public function test_each_status_classifies_the_way_the_sender_reads_it(): void {
		$this->assertSame( 'published', InboundResponseStatus::classification_of( 204 ) );
		$this->assertSame( 'published', InboundResponseStatus::classification_of( 409 ) );
		$this->assertSame( 'permanent', InboundResponseStatus::classification_of( 401 ) );
		$this->assertSame( 'permanent', InboundResponseStatus::classification_of( 403 ) );
		$this->assertSame( 'permanent', InboundResponseStatus::classification_of( 404 ) );
		$this->assertSame( 'permanent', InboundResponseStatus::classification_of( 422 ) );
		$this->assertSame( 'retryable', InboundResponseStatus::classification_of( 429 ) );
	}

	/**
	 * A status this side never answers has no classification here.
	 *
	 * Null is the assertion that no receiver invented a status outside the
	 * frozen table: 500 is classified by the sender, of course, but this side
	 * must never produce it on purpose.
	 */
	public function test_a_status_outside_the_frozen_table_has_no_classification(): void {
		$this->assertNull( InboundResponseStatus::classification_of( 500 ) );
		$this->assertNull( InboundResponseStatus::classification_of( 200 ) );
		$this->assertCount( 7, InboundResponseStatus::all() );
	}

	/**
	 * The authentication refusal is the one that carries no body.
	 *
	 * Refusing to say which of the three ways of failing authentication happened
	 * denies a prober information; refusing to say "this receiver is off" denies
	 * it to the one person who can turn it on.
	 */
	public function test_only_the_authentication_refusal_and_the_acceptance_are_bodiless(): void {
		$this->assertFalse( InboundResponseStatus::carries_body( InboundResponseStatus::UNAUTHORISED ) );
		$this->assertFalse( InboundResponseStatus::carries_body( InboundResponseStatus::ACCEPTED ) );
		$this->assertTrue( InboundResponseStatus::carries_body( InboundResponseStatus::RECEIVER_DISABLED ) );
		$this->assertTrue( InboundResponseStatus::carries_body( InboundResponseStatus::MALFORMED ) );
	}

	/**
	 * THE WINDOW IS COMPARED WITH THE SUM, NEVER WITH A NUMBER TYPED HERE.
	 *
	 * This is the point of the whole case. A retry that arrives with its key
	 * already purged is taken for a new delivery and its effect applied twice -
	 * a second refund on an order, with a second corrective invoice behind it.
	 * The guard is only worth anything if WIDENING THE SCHEDULE turns it red, so
	 * the total is computed from `RetrySchedule::INTERVALS` here. A hand-written
	 * copy of today's 398,160 would go on passing while the hole opened.
	 *
	 * The jitter is included for the same reason: the schedule's waits are
	 * multiplied by up to 1 + JITTER_RATIO, so the worst case is longer than the
	 * plain sum and the worst case is what has to fit.
	 */
	public function test_the_retention_window_outlasts_the_whole_retry_schedule(): void {
		$total_wait = array_sum( RetrySchedule::INTERVALS );
		$worst_case = (int) ceil( $total_wait * ( 1 + RetrySchedule::JITTER_RATIO ) );

		$this->assertGreaterThan(
			$total_wait,
			Constants::INBOUND_RETENTION_SECONDS,
			'The inbound idempotency window has to outlast the sender\'s whole retry schedule: a retry arriving with its key already purged is applied a second time.'
		);

		$this->assertGreaterThan(
			$worst_case,
			Constants::INBOUND_RETENTION_SECONDS,
			'The window has to outlast the WORST case of the schedule, jitter included, and not only its plain sum.'
		);
	}

	/**
	 * The route addresses and the signature header, as the frozen contract
	 * spells them.
	 *
	 * The two order routes are measured against the platform's own endpoint
	 * table; the catalogue one is this repository's decision, published first
	 * and with no sender yet.
	 */
	public function test_the_inbound_addresses_are_the_ones_the_contract_publishes(): void {
		$this->assertSame( 'factuarea/v1', Constants::INBOUND_REST_NAMESPACE );
		$this->assertSame( 'POST', Constants::INBOUND_REST_METHOD );
		$this->assertSame( '/order-status', Constants::INBOUND_ORDER_STATUS_ROUTE );
		$this->assertSame( '/order-refund', Constants::INBOUND_ORDER_REFUND_ROUTE );
		$this->assertSame( '/catalog-push', Constants::INBOUND_CATALOG_PUSH_ROUTE );
		$this->assertSame( 'X-Factuarea-Signature', Constants::INBOUND_SIGNATURE_HEADER );
	}

	/**
	 * The namespace, the method and the signature header are REFERENCED from the
	 * route this plugin already publishes.
	 *
	 * The assertion above pins the literals; this one pins that there is only
	 * ONE of each literal in the repository. Both are needed: the first catches
	 * a wrong value, the second catches a second copy of the right one.
	 */
	public function test_the_inbound_namespace_and_header_are_not_a_second_copy(): void {
		$this->assertSame( InvoiceCorrelationEndpoint::REST_NAMESPACE, Constants::INBOUND_REST_NAMESPACE );
		$this->assertSame( InvoiceCorrelationEndpoint::REST_METHOD, Constants::INBOUND_REST_METHOD );
		$this->assertSame( InvoiceCorrelationEndpoint::SIGNATURE_HEADER, Constants::INBOUND_SIGNATURE_HEADER );
	}

	/**
	 * The inbound signature header is NOT the outgoing one.
	 *
	 * They are two contracts in two directions. Anyone who unified them by name
	 * would have this plugin verify incoming deliveries against the header that
	 * its own outgoing ones use, and reject every legitimate notice.
	 */
	public function test_the_inbound_signature_header_is_not_the_outgoing_one(): void {
		$this->assertNotSame( Constants::HEADER_SIGNATURE, Constants::INBOUND_SIGNATURE_HEADER );
	}

	/**
	 * The three new options are in the enumerated list the uninstall deletes.
	 *
	 * That list is deleted one by one and never matched by prefix, so an option
	 * missing from it is an option that survives an uninstall for ever - and two
	 * of these three are the switches that decide whether a reinstalled plugin
	 * starts accepting deliveries without anybody asking.
	 */
	public function test_the_new_options_are_deleted_on_uninstall(): void {
		$this->assertContains( Constants::OPTION_ORDER_STATUS_RECEIVER_ENABLED, Constants::ALL_OPTIONS );
		$this->assertContains( Constants::OPTION_CATALOG_PUSH_RECEIVER_ENABLED, Constants::ALL_OPTIONS );
		$this->assertContains( Constants::OPTION_INBOUND_SCHEMA_VERSION, Constants::ALL_OPTIONS );
	}

	/**
	 * Every option this plugin names carries the shared prefix, the three new
	 * ones included.
	 */
	public function test_every_option_carries_the_shared_prefix(): void {
		foreach ( Constants::ALL_OPTIONS as $option ) {
			$this->assertStringStartsWith( Constants::OPTION_PREFIX, $option );
		}
	}

	/**
	 * The inbound table name carries NO WordPress prefix.
	 *
	 * A hard-coded `wp_` breaks every installation that chose another prefix,
	 * which is most of the ones that were ever migrated, and the failure is a
	 * table that is created and then never found.
	 */
	public function test_the_inbound_table_name_carries_no_installation_prefix(): void {
		$this->assertSame( 'factuarea_inbound', Constants::INBOUND_TABLE );
		$this->assertStringStartsNotWith( 'wp_', Constants::INBOUND_TABLE );
		$this->assertNotSame( Constants::OUTBOX_TABLE, Constants::INBOUND_TABLE );
	}

	/**
	 * Every meta key the inbound half writes lives under the plugin's own meta
	 * prefix.
	 *
	 * The prefix is what the merchant's other tools use to tell our data from
	 * theirs, and a key outside it is a key nobody would think to look for when
	 * something has to be cleaned up.
	 */
	public function test_every_inbound_meta_key_carries_the_meta_prefix(): void {
		$keys = array(
			Constants::FULFILMENT_STATUS_META_KEY,
			Constants::TRACKING_NUMBER_META_KEY,
			Constants::TRACKING_CARRIER_META_KEY,
			Constants::TRACKING_URL_META_KEY,
			Constants::FULFILMENT_APPLIED_AT_META_KEY,
			Constants::INBOUND_REFUND_META_KEY,
			Constants::CORRECTIVE_REFERENCE_META_KEY,
			Constants::REFUND_SCOPE_META_KEY,
			Constants::REMOTE_PRODUCT_ID_META_KEY,
		);

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( Constants::META_PREFIX, $key );
		}

		$this->assertSame( $keys, array_unique( $keys ), 'Two meta keys that are equal would overwrite each other.' );
	}

	/**
	 * The eighth outgoing header is not one of the seven native ones.
	 *
	 * The seven are asserted as "what a native WooCommerce delivery sends", and a
	 * receiver that only knows those seven has to keep accepting the delivery.
	 */
	public function test_the_store_identity_header_is_additive_to_the_seven_native_ones(): void {
		$this->assertCount( 7, Constants::DELIVERY_HEADERS );
		$this->assertNotContains( Constants::HEADER_STORE_ID, Constants::DELIVERY_HEADERS );
		$this->assertSame( 'X-Factuarea-Store-Id', Constants::HEADER_STORE_ID );
	}
}
