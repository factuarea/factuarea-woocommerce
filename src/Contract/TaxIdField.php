<?php
/**
 * The tax identifier checkout field: its identity, its copy and its rule.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Contract;

/**
 * Everything the tax identifier field is, in one place, because both checkouts
 * consume it.
 *
 * The block checkout registers the field through the additional checkout fields
 * interface and the classic checkout renders it through the template hooks.
 * Those are two different mechanisms in two different files, and the temptation
 * is to write the label twice. Two copies of the same sentence diverge the day
 * somebody fixes one of them, and the merchant then sees one wording on the
 * block checkout and another on the classic one - with no error anywhere,
 * because nothing compares them.
 *
 * The validation rule is here for a sharper reason: if the two checkouts
 * validated separately, one of them would eventually accept an identifier the
 * other rejects, and the invoice would go out with a tax identifier that fails
 * at the tax authority instead of at the checkout.
 *
 * ---------------------------------------------------------------------------
 * AN EMPTY VALUE IS VALID, AND THAT IS THE POINT
 * ---------------------------------------------------------------------------
 * The field is NOT required. A buyer who does not give a tax identifier gets a
 * simplified invoice, which is a correct document, so refusing the sale would
 * trade a lost deduction for a lost sale. What the rule rejects is a value that
 * is PRESENT and malformed, because that one produces a defective fiscal
 * document that, under verifiable invoicing, cannot be deleted - only corrected
 * with a second document.
 *
 * All copy in this file is English, which is the declared and bounded exception
 * this package publishes under.
 */
final class TaxIdField {

	/**
	 * Identifier of the field, as the additional checkout fields interface
	 * names it.
	 */
	public const FIELD_ID = Constants::ADDITIONAL_FIELD_ID;

	/**
	 * Name of the input in the classic checkout form. It is deliberately not
	 * the additional-fields identifier: a slash in a form field name is not the
	 * same key once PHP has parsed the request body.
	 */
	public const INPUT_NAME = 'factuarea_tax_id';

	/**
	 * Group the field belongs to. Billing, because a tax identifier is who is
	 * being billed, not where the parcel goes.
	 */
	public const GROUP = 'billing';

	/**
	 * Input type. Free text: a tax identifier mixes letters and digits and any
	 * narrower type would fight the buyer's keyboard.
	 */
	public const TYPE = 'text';

	/**
	 * Field label.
	 */
	public const LABEL = 'Tax ID (NIF/NIE/CIF)';

	/**
	 * Optional-label suffix. Shown so that nobody abandons a checkout looking
	 * for a number they do not have.
	 */
	public const OPTIONAL_LABEL = 'Tax ID (NIF/NIE/CIF) (optional)';

	/**
	 * Help text under the field. It says what the value is FOR, because a buyer
	 * who does not know why it is asked leaves it empty and then asks the shop
	 * for a proper invoice a week later.
	 */
	public const HELP_TEXT = 'Enter your Spanish tax ID to receive a full invoice you can deduct. Leave it empty and you will get a simplified invoice instead.';

	/**
	 * Message shown when a value is present and does not check out. It names
	 * what was expected and the three shapes that are accepted, so the buyer can
	 * see which one theirs was meant to be.
	 */
	public const ERROR_MESSAGE = 'That tax ID is not valid. Check the control letter and enter a Spanish NIF (8 digits and a letter), NIE (X, Y or Z, 7 digits and a letter) or CIF (a letter, 7 digits and a control character).';

	/**
	 * Placeholder. A NIF, because it is the shape most buyers will type.
	 */
	public const PLACEHOLDER = '12345678Z';

	/**
	 * Longest value the field accepts. Every Spanish tax identifier is nine
	 * characters once separators are removed.
	 */
	public const MAX_LENGTH = 9;

	/**
	 * Control letters of a NIF or NIE, indexed by the remainder of the number
	 * divided by 23.
	 */
	private const NIF_CONTROL_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/**
	 * Control letters of a CIF, indexed by the control digit.
	 */
	private const CIF_CONTROL_LETTERS = 'JABCDEFGHI';

	/**
	 * Organisation letters whose CIF always carries a LETTER as control
	 * character.
	 */
	private const CIF_LETTER_CONTROL_TYPES = 'PQRSNW';

	/**
	 * Organisation letters whose CIF always carries a DIGIT as control
	 * character. Any other organisation letter accepts either form.
	 */
	private const CIF_DIGIT_CONTROL_TYPES = 'ABEH';

	/**
	 * Strips the separators people type and puts the value in the shape the
	 * rule expects: no spaces, no dots, no hyphens, upper case.
	 *
	 * Both checkouts normalise before validating and before storing, so that
	 * the same identifier written in two ways is one value in the order and not
	 * two.
	 *
	 * @param string $value Raw value as the buyer typed it.
	 * @return string Normalised value.
	 */
	public static function normalise( string $value ): string {
		$stripped = preg_replace( '/[\s.\-]/', '', $value );

		return strtoupper( trim( (string) $stripped ) );
	}

	/**
	 * Is this a valid Spanish tax identifier?
	 *
	 * An empty value returns true: the field is optional, and an order without
	 * a tax identifier invoices as a simplified invoice rather than failing.
	 *
	 * @param string $value Value as the buyer typed it; it is normalised here.
	 * @return bool True when the value is empty or checks out.
	 */
	public static function is_valid( string $value ): bool {
		$candidate = self::normalise( $value );

		if ( '' === $candidate ) {
			return true;
		}

		if ( 1 === preg_match( '/^[0-9]{8}[A-Z]$/', $candidate ) ) {
			return self::has_valid_nif_control( substr( $candidate, 0, 8 ), substr( $candidate, 8, 1 ) );
		}

		if ( 1 === preg_match( '/^[XYZ][0-9]{7}[A-Z]$/', $candidate ) ) {
			$prefix_digit = (string) strpos( 'XYZ', substr( $candidate, 0, 1 ) );

			return self::has_valid_nif_control( $prefix_digit . substr( $candidate, 1, 7 ), substr( $candidate, 8, 1 ) );
		}

		if ( 1 === preg_match( '/^[ABCDEFGHJKLMNPQRSUVW][0-9]{7}[0-9A-J]$/', $candidate ) ) {
			return self::has_valid_cif_control( $candidate );
		}

		return false;
	}

	/**
	 * Control letter check shared by NIF and NIE: the letter is a function of
	 * the eight digits, so a typo in any digit moves the letter.
	 *
	 * @param string $digits  The eight digits, with the NIE prefix already
	 *                        replaced by its digit.
	 * @param string $control The control letter as given.
	 * @return bool True when the control letter is the expected one.
	 */
	private static function has_valid_nif_control( string $digits, string $control ): bool {
		$expected = self::NIF_CONTROL_LETTERS[ ( (int) $digits ) % 23 ];

		return $expected === $control;
	}

	/**
	 * Control character check of a CIF.
	 *
	 * The digits in odd positions are doubled and their own digits added up;
	 * the ones in even positions are added as they are. The control digit is
	 * what takes that total to the next multiple of ten. Some organisation
	 * letters publish that digit as a letter, some as a digit, and the rest
	 * accept either - so all three cases are checked rather than assuming one.
	 *
	 * @param string $candidate Normalised nine-character CIF.
	 * @return bool True when the control character is the expected one.
	 */
	private static function has_valid_cif_control( string $candidate ): bool {
		$organisation = substr( $candidate, 0, 1 );
		$digits       = substr( $candidate, 1, 7 );
		$control      = substr( $candidate, 8, 1 );

		$total = 0;

		for ( $position = 0; $position < 7; $position++ ) {
			$digit = (int) $digits[ $position ];

			if ( 0 === $position % 2 ) {
				$doubled = $digit * 2;
				$total  += intdiv( $doubled, 10 ) + ( $doubled % 10 );
				continue;
			}

			$total += $digit;
		}

		$control_digit  = ( 10 - ( $total % 10 ) ) % 10;
		$control_letter = self::CIF_CONTROL_LETTERS[ $control_digit ];

		if ( false !== strpos( self::CIF_LETTER_CONTROL_TYPES, $organisation ) ) {
			return $control === $control_letter;
		}

		if ( false !== strpos( self::CIF_DIGIT_CONTROL_TYPES, $organisation ) ) {
			return $control === (string) $control_digit;
		}

		return $control === $control_letter || $control === (string) $control_digit;
	}

	/**
	 * Not instantiable: this is the frozen definition of one field, not an
	 * object with state.
	 */
	private function __construct() {
	}
}
