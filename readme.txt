=== Factuarea for WooCommerce ===
Contributors: factuarea
Tags: woocommerce, invoicing, invoices, spain, verifactu
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 8.9
WC tested up to: 8.9

Capture the buyer tax ID at checkout and deliver paid WooCommerce orders to Factuarea, which turns them into invoices.

== Description ==

A WooCommerce shop connected to an invoicing service without the buyer tax ID can
only ever issue a simplified invoice. A simplified invoice does not let a business
buyer deduct the tax they paid, so the shop loses exactly the customer it most
wants to keep.

WooCommerce has no standard field for a tax ID. Every third-party plugin that adds
one writes it under its own meta key, so there is nothing an invoicing service can
read reliably. This plugin adds the field itself, in both checkouts WooCommerce
offers, and writes it under the key Factuarea reads.

The second thing it does is deliver. WooCommerce's own webhooks switch themselves
off after five consecutive failed deliveries, with no retry and no queue of what
failed. Five errors from one bad deploy silence a shop's invoicing until a person
notices. This plugin does not use them: it keeps its own outbox table, writes every
order event into it synchronously as the sale happens, and delivers from there with
its own backoff. Nothing switches itself off, and nothing is lost while the receiver
is down.

= What it does =

* Adds a tax ID field to the **block checkout** and to the **classic checkout**, validates it as a Spanish NIF, NIE or CIF, and stores it on the order.
* Delivers paid orders and refunds to Factuarea as a signed webhook that is byte-compatible with a native WooCommerce one: the same seven headers, and the same order body the WooCommerce REST API produces.
* Signs every delivery with a secret that belongs to that shop alone, using HMAC-SHA256 over the exact bytes sent.
* Keeps its own outbox with idempotency per event, so the same paid order captured twice is delivered once, and a redelivery never produces a second invoice.
* Retries on the same schedule Factuarea itself uses, and honours a wait the receiver asks for.
* Tells the merchant when the outbox has stopped moving, instead of failing quietly.
* Declares compatibility with WooCommerce High-Performance Order Storage.

= What it does not do =

* It does not sync products, stock, customers or prices.
* It does not read historical orders. Factuarea reconciles those from its own side.
* It does not gate any of its own behaviour behind a payment, a licence or a plan. Every feature of this plugin works for everyone who installs it. Factuarea is a paid service, and that is where the commercial boundary sits, never inside the plugin.

= Requirements =

* WordPress 6.5 or newer.
* WooCommerce 8.9 or newer. This is a capability floor, not a preference: the additional checkout fields interface arrives in 8.9, and without it the tax ID field cannot be registered for the block checkout at all. The block checkout is half of the problem this plugin exists to solve, so a lower version is not supported rather than partly supported.
* PHP 8.1 or newer.
* A Factuarea account with a store registered for this shop.

= Privacy =

This plugin captures one personal datum: the tax ID the buyer types at checkout.

* It is stored on the order in this shop's own database, as order meta, alongside the rest of the billing details the buyer gave.
* It is sent to Factuarea inside the signed delivery of that order, over HTTPS, as part of the order body.
* It is never written to a log file, an admin notice or an error message by this plugin.
* Uninstalling the plugin deletes the stored credentials and does **not** delete the tax IDs already written on orders. Those belong to the shop's accounting records, and Spanish invoicing law obliges a merchant to keep the fiscal data of an issued sale.

A buyer who leaves the field empty completes the order normally and is invoiced with
a simplified invoice.

= External services =

This plugin talks to one external service, Factuarea, and only to the address the
merchant configures in its settings:

* **Order delivery.** When a payment completes, a status changes or a refund is recorded, the plugin sends that order to Factuarea. What travels is the order as the WooCommerce REST API represents it: buyer billing details including the tax ID, line items, totals, taxes, and payment metadata. This is the purpose of the plugin.
* **Connection test.** When the merchant presses it, the plugin reads the configured store of the configured company from the Factuarea API to check the credentials. No shop data is sent.
* **Invoice download.** When Factuarea has told the plugin which invoice belongs to an order, and only then, the plugin fetches that invoice PDF of the configured company on the buyer's behalf. The API key never reaches the buyer's browser.

Nothing is sent anywhere until the merchant configures an address and a key and
switches delivery on. Factuarea's terms and privacy policy: https://factuarea.com

== Installation ==

1. Install and activate the plugin.
2. In Factuarea, register this shop as a store and copy its store identifier. Copy the identifier of the company this shop invoices for as well: it is a second UUID and a different one, and every read this plugin makes carries it in the address. Registering a store and issuing an API key are done from the Factuarea app, not from here.
3. In WordPress, go to **WooCommerce > Factuarea**.
4. Fill in the Factuarea address, the Factuarea API address (leave the default unless Factuarea told you otherwise), the API key, the store identifier and the company identifier, and save. Without the company identifier the plugin reports an incomplete configuration rather than guessing which company to read against.
5. Press **Generate signing secret**. The secret is shown **once**. Copy it and paste it into the WooCommerce connection that backs this store in Factuarea, so both ends sign and verify with the same value (stores under the same connection share it).
6. Run the connection test. It reports which of the settings is wrong when one is, rather than a plain failure.
7. Switch **Delivery** on.

From then on, a completed payment writes an entry into the outbox during the same
request that takes the payment, and delivery happens right after, out of the buyer's
way.

== Frequently Asked Questions ==

= Do I need this plugin to use Factuarea with WooCommerce? =

No. Factuarea can read a shop through a WooCommerce REST credential on its own. What
that route cannot do is capture a tax ID, because WooCommerce has no field for one,
and it inherits WooCommerce's native webhooks, which switch themselves off after five
consecutive failures. This plugin exists for those two things.

= Which WordPress and WooCommerce versions is this tested against? =

The versions declared in the header above, and no higher, on purpose. The
end-to-end suite of this plugin runs against the minimum supported pair, because the
failure it is built to catch is a field that does not appear on a checkout, and that
failure shows up at the floor rather than at the ceiling. Raising either number means
running the suite against that pair first. A declared "tested up to" that nobody
tested is worse than a conservative one.

= The buyer left the tax ID empty. What happens? =

The order completes and is delivered like any other. Factuarea issues a simplified
invoice for it. Nothing is written to an empty meta key.

= I already use another tax ID plugin. Do they collide? =

They coexist. Factuarea reads the tax ID from an ordered list of meta keys that
includes the ones third-party plugins commonly use, so a shop that already collects
one keeps working. This plugin writes its own key and never overwrites a value it
did not write.

= WooCommerce disabled my webhooks in the past. Will that happen here? =

No, because this plugin does not use them. It has its own outbox table and its own
retry schedule. A run of failed deliveries leaves the entries in the outbox with their
next attempt scheduled, and the plugin tells you in the admin when the outbox has
stopped moving.

= What happens if the receiver rejects a delivery? =

It depends on what the rejection says, not on its status code. A rejection that
declares a wait is retried after that wait. A rejection that declares none is final,
and the reason is kept for the merchant to read. Anything that is not a rejection of
the ingestion contract at all, such as a gateway error from a proxy, is treated as
transient and retried. See docs/delivery-contract.md in the source repository.

= Does uninstalling remove my data? =

It removes the stored credentials and settings. It does not remove the tax IDs on
your orders, and it does not drop the outbox table: both are your business records.

= Is any feature paid, limited or unlocked by an upgrade? =

None. The plugin is GPL and complete. Factuarea, the service it delivers to, is
what you pay for.

== Changelog ==

= 0.2.0 =
* **Breaking: the settings screen has a new required field, Company identifier.**
Every Factuarea address this plugin calls now carries the company in its path, so
the plugin cannot reach the service until the field holds the UUID of your company
(Factuarea shows it in Settings, Companies; it is the company UUID, never a tax ID).
Until you fill it in, the connection test reports the configuration as incomplete
and invoice PDF links are not offered on the order screen. Nothing else changes:
deliveries already queued stay queued and are sent once the field is saved.

= 0.1.0 =
* First release. Tax ID field in the block and classic checkouts, order and refund
delivery with a signing secret of the shop's own, outbox with idempotency and
backoff, rejection classification driven by the ingestion contract, settings screen
with a connection test, stalled-outbox notice, and High-Performance Order Storage
compatibility declared.

== Upgrade Notice ==

= 0.2.0 =
Open WooCommerce > Settings > Factuarea and fill in the new Company identifier
field with your Factuarea company UUID. Every address the plugin calls now carries
the company, so until it is set the connection test reports the configuration as
incomplete and no invoice PDF link is offered.
