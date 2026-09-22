# Factuarea for WooCommerce

A WordPress plugin that captures the buyer tax ID at the WooCommerce checkout and
delivers paid orders and refunds to [Factuarea](https://factuarea.com), which turns
them into invoices.

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

Activation creates one table in this site's database, `<prefix>factuarea_outbox`,
and schedules the drain that empties it. Nothing is sent anywhere until you finish
the configuration below and switch delivery on.

## Configuration

Six values connect this shop to Factuarea, and one of them travels in the other
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

Copy the **company identifier** too, from the company this shop invoices for. It is
a UUID as well, and it is a different one: the store is this shop, the company is who
issues the invoices. Every read this plugin makes carries the company in its address
— `https://api.factuarea.com/v1/companies/{company}/stores/{store}` for the
connection test, `…/v1/companies/{company}/invoices/{invoice}/pdf` for an invoice
download — so the plugin cannot do anything until it has one.

You paste the identifier rather than your tax ID, and that is deliberate rather than
an omission. Translating a tax ID into the identifier is an operation on the
**account**, and it needs a permission over every company of the account; a shop's
read key does not have it and should not be given it in order to build a store
address. One field costs less than that permission.

### 2. Fill in the connection settings in WordPress

Go to **WooCommerce > Factuarea**:

| Field | What to put in it |
| --- | --- |
| Factuarea address | The base address of your Factuarea installation. |
| Factuarea API address | The base address of the Factuarea public API (`https://api.factuarea.com` by default). Deliveries go to the Factuarea address above; the connection test and the invoice PDF are read from this one, because the API is served on its own domain. Change it only if Factuarea told you to. |
| API key | A Factuarea API key. This plugin uses it only to **read**: the connection test and the invoice PDF. It never signs anything with it. |
| Store identifier | The store UUID from step 1. |
| Company identifier | The company UUID from step 1 — the company this shop invoices for, not the store. Leave it empty and the connection test, and every invoice download, report an incomplete configuration instead of guessing a company. |
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

* a setting the test needs is still empty, and it names which;
* the API key is not valid;
* the API key is valid but lacks the permission to read stores;
* the API key is valid and cannot reach the configured **company**;
* the **store** identifier does not belong to this account;
* the Factuarea address does not answer;
* something unexpected came back.

The company and the store are two separate results on purpose. Both come back from
Factuarea as the same kind of refusal, and they are fixed in two different fields: a
message naming the store would send somebody whose company identifier is wrong to
re-copy a store identifier that was already right.

No part of the API key or of the signing secret appears in any of those messages.

### 5. Switch delivery on

From then on, a completed payment writes an entry into the outbox during the same
request that took the payment, and the delivery leaves right after, out of the
buyer's way.

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
address, the API key, the store identifier, the company identifier, the signing
secret, the delivery switch, the flag recording that the secret was already shown,
and the marker holding the outbox table's schema version. They are deleted by name,
one by one, from an enumerated list rather than by matching a prefix: a prefix match
would delete whatever anything else happened to name that way, without anybody
having decided to.

**What uninstalling does not delete, on purpose.**

* The tax IDs written on orders. They are part of the order, and the order is the shop's accounting record. Spanish invoicing law obliges a merchant to keep the fiscal data of an issued sale, and an issued invoice carries that identifier. Removing a plugin is not a request to alter the books.
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
does with each one.

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
