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

Capture the buyer tax ID at checkout, deliver paid orders to Factuarea, and apply the status, refunds and catalogue it publishes back.

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

The third thing it does is listen. A shop that only sends leaves its buyers looking
at an order that still says "processing" while the warehouse has already shipped it,
and leaves the merchant copying a refund by hand into a shop that will then try to
refund it a second time. So the plugin also accepts what Factuarea publishes back:
the fulfilment status of an order and its tracking details, a refund that was
already issued and already has a corrective invoice behind it, and the catalogue,
price and stock of articles. Every one of those is authenticated with this shop's
own signing secret, deduplicated so a redelivery never applies twice, and switched
**off** until the merchant turns it on. None of it ever changes the native
WooCommerce status of an order or moves money through the payment gateway.

= What it does =

* Adds a tax ID field to the **block checkout** and to the **classic checkout**, validates it as a Spanish NIF, NIE or CIF, and stores it on the order.
* Delivers paid orders and refunds to Factuarea as a signed webhook a receiver cannot tell from a native WooCommerce one: the same seven headers, with the same names and values, and the same order body the WooCommerce REST API produces.
* Signs every delivery with a secret that belongs to that shop alone, using HMAC-SHA256 over the exact bytes sent.
* Keeps its own outbox with idempotency per event, so the same paid order captured twice is delivered once, and a redelivery never produces a second invoice.
* Retries on the same schedule Factuarea itself uses, and honours a wait the receiver asks for.
* Tells the merchant when the outbox has stopped moving, instead of failing quietly.
* Declares compatibility with WooCommerce High-Performance Order Storage.
* Applies the fulfilment status Factuarea publishes - prepared, shipped, delivered, returned - as a status **of its own**, shown to the buyer next to the native one, which it never overwrites.
* Shows the buyer the tracking number, the carrier and the tracking link of their shipment in **My account**, when the shipment declares them.
* Records a refund Factuarea has already issued on the order, with the reference of the corrective invoice that backs it, **without ordering any payment through the gateway and without restocking**, and without sending it back out again.
* Applies the name, description, visibility, price and available stock Factuarea publishes for an article, matching it by an identity it has recorded or by its SKU, and answering per article which ones it applied and why it refused the rest.
* Verifies every incoming delivery against this shop's own signing secret, over the exact bytes received, and keeps a durable record of the deliveries it has applied so that a redelivery is discarded rather than repeated.
* Declares which shop a delivery came from, in an eighth header of its own that is added after those seven and only when a store identifier is configured, so that two shops registered under the same Factuarea connection are told apart.

= What it does not do =

* It does not create products. An article Factuarea publishes that this shop does not have is reported back as not found, never invented here.
* It does not switch stock management on for an article that does not use it, and it does not touch a sale price the merchant set.
* It does not change the native status of an order, ever. It stores and shows its own.
* It does not refund money. A refund it receives has already been returned on the other side; recording it here a second time through the gateway would pay the buyer twice.
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

Personal data also arrives, once the order receiver is switched on: the fulfilment
status of an order and, when the shipment declares them, the tracking number, the
carrier and the tracking address. They are stored on the order as order meta, next
to the rest of it, and shown to that buyer in **My account**. They are not sent
anywhere else, and uninstalling leaves them on the order for the same reason it
leaves the tax IDs: they are part of the shop's record of that sale.

= External services =

This plugin talks to one external service, Factuarea, and only to the address the
merchant configures in its settings. Data travels in both directions, and both are
listed here.

**What this site sends out.**

* **Order delivery.** When a payment completes, a status changes or a refund is recorded, the plugin sends that order to Factuarea. What travels is the order as the WooCommerce REST API represents it: buyer billing details including the tax ID, line items, totals, taxes, and payment metadata. This is the purpose of the plugin.
* **Connection test.** When the merchant presses it, the plugin reads the configured store from the Factuarea API to check the credentials. No shop data is sent.
* **Invoice download.** When Factuarea has told the plugin which invoice belongs to an order, and only then, the plugin fetches that invoice PDF on the buyer's behalf. The API key never reaches the buyer's browser.

**What Factuarea sends in.** The plugin publishes three addresses of its own on
this site, under `/wp-json/factuarea/v1/`. Factuarea posts to them; nothing else
does, and each delivery is refused unless it is signed with this shop's signing
secret. Both receivers arrive **switched off** and apply nothing until the merchant
switches them on.

* **Order status, at `/order-status`.** Sent when the warehouse prepares, ships, delivers or takes back an order. It carries the identifier of that order in this shop, one of four status words, the instant of the fact, a key that deduplicates the delivery, and - only for a shipment - a tracking number, a carrier name, a tracking address and a number of packages. It is stored on the order and shown to that buyer in **My account**. The native WooCommerce status of the order is never changed.
* **Refund, at `/order-refund`.** Sent when Factuarea has issued a corrective invoice for a refund it already returned. It carries the order identifier, the amount, the currency, whether the whole sale or part of it came back, the public reference of the corrective invoice, and the deduplication key. A WooCommerce refund is recorded on the order with no payment ordered through the gateway and no restocking.
* **Catalogue, price and stock, at `/catalog-push`.** Sent when Factuarea publishes article data to this shop. Each item carries a reference, optionally the identity this plugin recorded for that article, and any of a name, a description, a visibility flag, a price and an available quantity. No personal data travels on this address, and no article is ever created here.

Nothing is sent anywhere until the merchant configures an address and a key and
switches delivery on, and nothing is applied from outside until they switch a
receiver on. Factuarea's terms and privacy policy: https://factuarea.com

== Installation ==

1. Install and activate the plugin.
2. In Factuarea, register this shop as a store and copy its store identifier. Registering a store and issuing an API key are done from the Factuarea app, not from here.
3. In WordPress, go to **WooCommerce > Factuarea**.
4. Fill in the Factuarea address, the Factuarea API address (leave the default unless Factuarea told you otherwise), the API key and the store identifier, and save.
5. Press **Generate signing secret**. The secret is shown **once**. Copy it and paste it into the WooCommerce connection that backs this store in Factuarea, so both ends sign and verify with the same value (stores under the same connection share it).
6. Run the connection test. It reports which of the settings is wrong when one is, rather than a plain failure.
7. Switch **Delivery** on.

From then on, a completed payment writes an entry into the outbox during the same
request that takes the payment, and delivery happens right after, out of the buyer's
way.

8. Optionally, switch on the receivers you want under **Receivers**:
one applies the order status, the tracking details and the refunds Factuarea
publishes, the other applies the catalogue, the price and the stock of your
articles. Both are off until you switch them on, and both are authenticated with
the same signing secret from step 5 - there is no second secret to generate. The
screen prints the address Factuarea will post to for each one so you can check it.

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

= Where do I paste the addresses of the two receivers into Factuarea? =

You do not. What Factuarea asks for is the **address of your shop**, and it refuses
a value that carries a path. Factuarea then builds the address of each receiver
itself, by adding `/wp-json/factuarea/v1/` and the route to it.

The settings screen prints the two addresses it will build, and they are there **to
check, not to copy**. One case is worth checking them for: if your permalinks are
set to Plain, WordPress serves its REST routes as a query argument, the address
Factuarea builds is one this site does not answer on, and every notice is lost as
not found with no message at either end. The screen warns you when that is the case.
Any permalink setting other than Plain fixes it.

= Why do the receivers arrive switched off? =

Because updating a plugin must never change what a shop already does. A shop that
was only sending before the update is only sending after it, until somebody decides
otherwise. It is the same rule the delivery switch has always followed.

There is a second reason, and it is the one that matters when you turn them on: a
receiver is a door into your catalogue and your orders. Opening it is a decision,
and a decision has to be taken by a person rather than inherited from a release.

= Why is there no separate secret for incoming deliveries? =

Because there is one shop and one credential. The signing secret you generated for
the deliveries this shop sends is the same one that verifies the deliveries it
receives; the two directions use different header names and the same secret. A
second field would be a second place to get it wrong and a second value to rotate,
and generating a new secret would then fix one direction and break the other.

Generating a new secret changes **both** directions at once, so paste it into
Factuarea before you expect either to work again.

= What happens to notices sent while a receiver is switched off? =

They are refused, and - this is the part that matters - **their deduplication key
is not recorded**. Switch the receiver on and the same notice, redelivered, applies
normally.

That is deliberate. If a switched-off receiver recorded the key, the retry that
arrives after you switch it on would be answered "already applied" and Factuarea
would mark as published a notice that never touched your shop. Factuarea retries a
refused delivery on its own schedule; anything it has already given up on is
republished from Factuarea, not from here.

= Does uninstalling remove my data? =

It removes the stored credentials and settings, the two receiver switches among
them, and it drops the table that records which incoming deliveries have already
been applied - that one is this plugin's own bookkeeping and says nothing to
anybody.

It does **not** remove the tax IDs on your orders, the fulfilment status and
tracking written on them, or the outbox table. Those are your business records: the
outbox is the evidence of which sales reached Factuarea and when, and the rest is
part of the order.

= Is any feature paid, limited or unlocked by an upgrade? =

None. The plugin is GPL and complete. Factuarea, the service it delivers to, is
what you pay for.

== Changelog ==

= 0.2.0 =
* Receives what Factuarea publishes back, on three addresses of the plugin's own
under `/wp-json/factuarea/v1/`: the fulfilment status of an order and its tracking
details, a refund already issued with the corrective invoice that backs it, and the
catalogue, price and available stock of articles.
* Two switches for those receivers, both **off** after the update, so a shop that
touched nothing keeps behaving exactly as it did. The settings screen prints the
address Factuarea will post to for each one, warns when the permalink setting would
make that address unreachable, and warns when a receiver is on with no signing
secret stored.
* Incoming deliveries are authenticated with the shop's existing signing secret,
over the exact bytes received, and recorded in a table of their own so that a
redelivery is discarded instead of applied twice.
* A received status never overwrites the native WooCommerce status of the order,
and a received refund never moves money through the payment gateway or restocks.
Neither travels back out to Factuarea, so a refund cannot produce a second
corrective invoice for the same sale.
* The buyer sees the fulfilment status and the tracking of their shipment in
**My account**, and nothing at all when no status has arrived.
* A catalogue push matches articles by an identity the plugin records or by their
SKU, never creates one, and answers per article which were applied and why the rest
were refused.
* Deliveries this shop sends now also declare which shop they came from, in an
eighth header of the package's own, when a store identifier is configured. The
seven native headers are untouched and the signature is unchanged.
* Uninstalling removes the new settings and drops the incoming deduplication table.
The outbox table, the tax IDs and the fulfilment data on orders stay.

= 0.1.0 =
* First release. Tax ID field in the block and classic checkouts, order and refund
delivery with a signing secret of the shop's own, outbox with idempotency and
backoff, rejection classification driven by the ingestion contract, settings screen
with a connection test, stalled-outbox notice, and High-Performance Order Storage
compatibility declared.
