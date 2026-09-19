# Factuarea for WooCommerce

A WordPress plugin that captures the buyer tax ID at the WooCommerce checkout,
delivers paid orders and refunds to [Factuarea](https://factuarea.com), which turns
them into invoices, and applies what Factuarea publishes back into the shop.

It exists for two reasons, and both of them are failures you cannot fix from the
Factuarea side.

**A shop that cannot ask for a tax ID can only issue simplified invoices.** A
simplified invoice does not let a business buyer deduct the tax they paid.
WooCommerce has no standard field for a tax ID, and every third-party plugin that
adds one writes it under its own meta key, so there is nothing an invoicing service
can read reliably. This plugin adds the field to both checkouts WooCommerce offers
and writes it under the key Factuarea reads.

**WooCommerce's own webhooks switch themselves off after five consecutive failed
deliveries.** No retry, no backoff, no record of what failed. Five errors from one
bad deploy silence a shop's invoicing permanently, until a person notices. This
plugin does not use them. It writes every order event into an outbox table of its
own, synchronously, inside the request that took the payment, and delivers from
there with its own backoff and its own idempotency.

Once those two are solved, a third failure is left over, and it is the one the
merchant pays for by hand. A shop that only **sends** shows the buyer an order that
still says "processing" while the warehouse shipped it yesterday, and makes somebody
copy every refund across by hand into a shop that will then try to refund it again.
So the plugin also **listens**: it publishes three addresses of its own, applies the
fulfilment status, the refunds and the catalogue data Factuarea sends to them, and
does it without ever touching the native order status or the payment gateway. Both
receivers arrive switched off. [The inbound half](#what-factuarea-can-write-into-this-shop)
is below, and its contract is in [docs/inbound-contract.md](docs/inbound-contract.md).

Everything the plugin does is free and GPL. Factuarea is the paid service; the
commercial boundary is there and never inside the plugin.

---

## Requirements

| | Minimum | Why this number |
| --- | --- | --- |
| WordPress | 6.5 | |
| WooCommerce | 8.9 | The additional checkout fields interface arrives in 8.9. Below it, the tax ID field cannot be registered for the block checkout at all. |
| PHP | 8.1 | |

You also need a Factuarea account with a WooCommerce connection and a store
registered for this shop.

## Installation

Install and activate it like any other plugin: upload the ZIP under
**Plugins > Add New > Upload Plugin**, or install it from the WordPress plugin
directory once it is published there.

Activation creates two tables in this site's database - `<prefix>factuarea_outbox`,
which holds what is waiting to be delivered, and `<prefix>factuarea_inbound`, which
records the incoming deliveries that have already been applied so that a redelivery
is not applied twice - and schedules the pass that drains the first and prunes the
second. Nothing is sent anywhere until you finish the configuration below and switch
delivery on, and nothing is applied from outside until you switch a receiver on.

## Configuration

Five values connect this shop to Factuarea, and one of them travels in the other
direction. Do the steps in this order: the last one only tells you the truth once
the first four are saved.

### 1. Register this shop as a store in Factuarea

Do this from the Factuarea app, or with the public API if you automate it. A store
carries the provider (`woocommerce`), the identifier this shop is known by
(`external_store_id`), a display name, and the connection that backs it. It also
carries the settings that decide how it invoices: the invoice series, the amount
below which an order is invoiced as a simplified invoice, whether a tax ID is
required, whether prices already include tax, whether paid orders are invoiced
automatically and whether the invoice is emailed to the buyer. All of them have
defaults, and automatic invoicing is off until you turn it on.

Copy the store identifier that Factuarea gives you back. It is a UUID, and it is the
value step 2 asks for.

### 2. Fill in the connection settings in WordPress

Go to **WooCommerce > Factuarea**:

| Field | What to put in it |
| --- | --- |
| Factuarea address | The base address of your Factuarea installation. |
| Factuarea API address | The base address of the Factuarea public API (`https://api.factuarea.com` by default). Deliveries go to the Factuarea address above; the connection test and the invoice PDF are read from this one, because the API is served on its own domain. Change it only if Factuarea told you to. |
| API key | A Factuarea API key. This plugin uses it only to **read**: the connection test and the invoice PDF. It never signs anything with it. |
| Store identifier | The UUID from step 1. |
| Delivery | Leave it off for now. Switch it on in step 5. |

Save.

### 3. Generate the signing secret and paste it into Factuarea

Press **Generate signing secret**.

The secret is shown **once**, right then, and never again, not even in part. Copy it
before you leave the page. If you lose it, generate another one and paste the new
one into Factuarea; the old one stops working the moment the new one is stored.

Paste it into Factuarea, on the **WooCommerce connection** that backs this store.
The secret belongs to the connection rather than to an individual store, so several
shops registered under one connection sign with the same value.

This is the credential that proves a delivery came from this shop. It signs outgoing
deliveries and nothing else: it never authenticates a read, and the API key never
signs.

### 4. Run the connection test

The test reads the configured store from the Factuarea API with the API key. It does
not send a sample delivery to the ingestion route, and that is deliberate: the
ingestion route answers 200 whether it knows your store or not, by design, so a probe
against it could not tell "configured correctly" from "nobody here knows you".

The result names which of the settings is wrong, rather than failing plainly:

* the API key is not valid;
* the API key is valid but lacks the permission to read stores;
* the store identifier does not belong to this account;
* the Factuarea address does not answer;
* something unexpected came back.

No part of the API key or of the signing secret appears in any of those messages.

### 5. Switch delivery on

From then on, a completed payment writes an entry into the outbox during the same
request that took the payment, and the delivery leaves right after, out of the
buyer's way.

### 6. Optionally, switch the receivers on

Under **Receivers**, two switches decide whether Factuarea may write into this shop:
one applies the order status, the tracking details and the refunds it publishes, the
other applies the catalogue fields, the price and the stock of your articles. They
are separate because wanting your prices published is not the same as wanting your
orders written into.

Both are off until you turn them on, and both are authenticated with the signing
secret from step 3 - there is no second secret and no second field. The screen
prints the address Factuarea will post to for each of them so that you can check it;
you do not paste those addresses anywhere, because what Factuarea asks for is the
address of the **shop** and it composes the rest itself.

### If the provider is not released yet

Until Factuarea releases the WooCommerce provider on its side, the ingestion route
refuses deliveries and says so. The plugin reports the refusal with the reason it
was given, and the orders stay in the outbox. Nothing is lost and nothing is
half-invoiced.

## What the buyer sees

A tax ID field in the checkout, marked optional, with the help text explaining what
it changes: give one and you get a full invoice you can deduct, leave it empty and
you get a simplified invoice. The value is checked as a Spanish NIF, NIE or CIF
before the order is placed, so a typo is caught at checkout instead of surfacing
weeks later on a document that cannot be corrected quietly.

The field appears in the **block checkout** and in the **classic checkout**. A plugin
wired only to the classic mechanism paints nothing at all in the block checkout, and
paints nothing **silently**, which is why this one registers through both and has an
end-to-end test per checkout.

When Factuarea has told the plugin which invoice belongs to an order, the buyer also
gets access to that invoice PDF from **My account > Orders**. When it has not, no
link is shown: not a broken one and not a disabled one that promises something. The
plugin never guesses the correlation from an amount or a date.

The same page is where the buyer reads how their order is getting to them, once the
order receiver is on: the fulfilment status Factuarea published - prepared, shipped,
delivered, returned - and, for a shipment that declares them, the tracking number,
the carrier and the tracking link. The same rule applies: nothing has arrived means
nothing is shown, never a placeholder. The status sits **next to** the WooCommerce
one and does not replace it.

## What Factuarea can write into this shop

Three addresses, all under this plugin's own REST namespace, all `POST`, all
refused unless the delivery is signed with this shop's signing secret, and all
behind a switch that starts off:

| Address | What it applies |
| --- | --- |
| `/wp-json/factuarea/v1/order-status` | The fulfilment status of one order and its tracking details. |
| `/wp-json/factuarea/v1/order-refund` | A refund Factuarea already issued, with the corrective invoice that backs it. |
| `/wp-json/factuarea/v1/catalog-push` | The catalogue fields, the price and the available stock of a batch of articles. |

Four properties are worth knowing before reading anything else, because each of
them is a decision that could reasonably have gone the other way:

* **The native order status is never touched.** The received status is stored and shown as the plugin's own. Setting the WooCommerce one would start this shop's automations, e-mail the buyer, and trip this plugin's own status captor, which would deliver the change straight back to Factuarea.
* **A received refund moves no money.** It is recorded on the order with no gateway movement and no restocking, because the money was already returned on the other side and a corrective invoice already backs it. It is marked so that neither of this plugin's refund captors sends it back out; without that mark the loop produces a second corrective invoice in a series filed with the tax authority, where a document is not deleted but corrected with another one.
* **No article is ever created.** An article is matched by an identity this plugin records for it, or otherwise by its SKU. A reference that two articles share is refused rather than guessed at, and an article this shop does not have is reported back as not found.
* **A redelivery is discarded, not repeated.** Every delivery carries a deduplication key, claimed in `<prefix>factuarea_inbound` **before** anything is applied, with an insert that either goes in or collides with a unique index - there is no window between checking and acting for two simultaneous deliveries to walk through.

The whole contract - the routes, the signature scheme with its frozen vector, the
body of each notice, the answer table and what each answer means to the sender, and
the limits this version declares - is in
[docs/inbound-contract.md](docs/inbound-contract.md). It is written once, there:
a contract written twice diverges, and the half that is wrong is never the half you
are reading.

## Privacy

**What is captured.** One personal datum: the tax ID the buyer types at checkout.

**Where it is stored.** On the order, in this site's own database, as order meta,
next to the rest of the billing details the buyer gave.

**Where it travels.** To Factuarea, inside the signed delivery of that order, over
HTTPS, as part of the order body. Factuarea needs it to issue an invoice the buyer
can deduct; that is the whole point of capturing it.

**Where it is never written.** Not to a log line, not to an admin notice, not to an
error message. The same prohibition covers the two credentials: the signing secret
and the API key never appear in a message, a notice, a log or a diagnostic dump,
whole or truncated. Every option this plugin registers is kept out of the WordPress
REST settings endpoint, so a settings export does not carry them off the site.

**What uninstalling deletes.** The stored credentials and settings: the Factuarea
address, the Factuarea API address, the API key, the store identifier, the signing
secret, the delivery switch, the flag recording that the secret was already shown,
the two receiver switches, and the two markers holding the schema versions of the
tables. They are deleted by name, one by one, from an enumerated list rather than by
matching a prefix: a prefix match would delete whatever anything else happened to
name that way, without anybody having decided to.

It also drops one table, `<prefix>factuarea_inbound`. That one holds the keys of
incoming deliveries already applied - deduplication bookkeeping of this plugin's
own, which says nothing to anybody once the plugin is gone.

**What uninstalling does not delete, on purpose.**

* The tax IDs written on orders. They are part of the order, and the order is the shop's accounting record. Spanish invoicing law obliges a merchant to keep the fiscal data of an issued sale, and an issued invoice carries that identifier. Removing a plugin is not a request to alter the books.
* The fulfilment status, the tracking details, the corrective invoice reference and the refund scope written on orders. Same reason, and the same rule that keeps the correlated invoice identifier: they are part of that order.
* The outbox table and its rows. Delivered rows are the evidence of which sales reached Factuarea and when. Pending rows are sales not delivered yet; dropping the table would destroy them without telling anybody.

A merchant who wants the table gone can drop it. A merchant who loses their delivery
history to an uninstall has no way back.

## Living with another tax ID plugin

They coexist, and neither has to yield. Factuarea resolves the tax ID by walking an
ordered list of meta keys that includes the ones third-party plugins commonly use, so
a shop that already collects one keeps working. This plugin writes its own key, which
that list prefers, and it never overwrites a value it did not write. It does not try
to arbitrate between two sources; the receiver does that, and records the divergence.

## When something does not arrive

The plugin raises an admin notice when the outbox has stopped moving: entries whose
retry budget ran out, and entries whose next attempt fell due more than an hour ago
and has not run. The second number usually means one thing, and it is not this
plugin: WordPress's scheduled work only runs when somebody visits the site, so a
quiet shop can go a long time between drains. A system cron aimed at `wp-cron.php` is
the fix, and it is a site setting rather than a plugin one.

Note the asymmetry that makes this safe: the **recording** of an event never depends
on scheduled work. It happens inside the request that took the payment. Only the
**delivery** waits, and a delayed delivery is still a delivery.

To read a delivery without reading the code, see
[docs/delivery-contract.md](docs/delivery-contract.md): the headers, the body, how
the signature is computed, how each kind of answer is classified and what the outbox
does with each one. For the other direction -- what may be written into this shop,
how it authenticates itself and what each answer tells the sender -- see
[docs/inbound-contract.md](docs/inbound-contract.md).

## Hooks this plugin exposes

### `factuarea_wc_classic_additional_fields_version`

The first WooCommerce version that paints additional checkout fields on the classic
checkout **by itself**. At or above that version, this plugin stops painting its own,
so the field appears once rather than twice.

Its default is `null`, and that is a measurement rather than a placeholder: no
released WooCommerce version paints them on the classic checkout, so there is no
threshold to declare. The plugin therefore also looks at the checkout field array it
is given and stands down if the field is already there, which is the signal that does
not depend on a version number at all.

```php
add_filter( 'factuarea_wc_classic_additional_fields_version', function () {
    return '9.9';
} );
```

Use it if a future WooCommerce starts painting them and this plugin has not been
updated yet. Returning `null` restores the default.

## Development

```bash
composer install
composer verify
```

`verify` runs, in order: the PHPUnit suites, the WordPress and WooCommerce coding
standards, the English-only gate, the no-paywall and licence gate, and the packaging
step that builds the artefact exactly as it would be published.

The end-to-end suite is separate because it needs a real WordPress with WooCommerce
and a browser:

```bash
npm install
npm run e2e:up
npm run e2e
npm run e2e:down
```

Two things in this repository are derived rather than written, and both fail loudly
rather than quietly:

* **The retry schedule.** `php scripts/derive-retry-schedule.php` re-derives it from the single retry calculator in the Factuarea monorepo when that repository sits next to this one, and a parity test compares the plugin's table against the reference file interval by interval. When the monorepo is not visible the generator **skips, naming the reason, with an exit code that is not success**. A check that cannot see what it checks and reports green is worse than one that reports nothing.
* **The English-only rule.** Everything a person can read in this repository is in English. See [docs/english-only.md](docs/english-only.md) for the scope of that exception and what it does not license.

The release runbook, including the manual steps the WordPress plugin directory
requires, is in [docs/publishing.md](docs/publishing.md).

## Licence

GPL-2.0-or-later. The full text is in [LICENSE](LICENSE).
