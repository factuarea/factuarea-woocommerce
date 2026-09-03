<?php
/**
 * Marker of every exception this plugin throws, and the rule they all obey.
 *
 * @package Factuarea\WooCommerce
 */

declare( strict_types = 1 );

namespace Factuarea\WooCommerce\Exception;

/**
 * Every exception this plugin throws implements this, so that a caller can tell
 * "something of ours went wrong" from "WordPress or WooCommerce threw".
 *
 * ===========================================================================
 * NO MESSAGE, NOTICE OR LOG LINE MAY CONTAIN A CREDENTIAL
 * ===========================================================================
 * This prohibition covers the SIGNING SECRET and the API KEY, and it covers
 * them WHOLE and TRUNCATED alike. Not the first four characters, not the last
 * four, not a masked form that keeps some of them, not a hash of them, not a
 * length. It applies to:
 *
 *   - the message of any exception in this catalogue and of any exception this
 *     plugin catches and re-describes;
 *   - every admin notice and every string on the settings screen, including the
 *     five diagnostics of the connection test;
 *   - anything written to a log, an error handler, a diagnostic dump or a
 *     settings export;
 *   - the merchant message carried by a delivery outcome, which is stored in
 *     the outbox table and read back later.
 *
 * WHY IT IS ABSOLUTE RATHER THAN "BE CAREFUL". A truncated secret is not a
 * safer secret, it is a shorter one, and the places these strings end up are
 * exactly the places that get shared: a merchant pastes a WordPress debug log
 * into a support ticket, a hosting panel mails the fatal-error notice to
 * whoever is on the account, a settings export is attached to a migration
 * request. None of those is under this plugin's control once the string is
 * written. The signing secret is the one thing standing between anyone on the
 * internet and a forged sale delivered into the merchant's books.
 *
 * WHAT TO SAY INSTEAD. Name the SETTING, never its value: "the signing secret
 * is not configured", "the API key was rejected". The merchant does not need to
 * be shown the credential to know which field to look at, and being shown it
 * does not help them fix anything.
 *
 * Every message in this catalogue also carries a CAUSE and a NEXT STEP, in
 * English. A message that only states that something failed turns into a
 * support ticket, and a support ticket about a broken invoice arrives after the
 * sale it broke.
 */
interface PluginException extends \Throwable {
}
