# The inbound contract

What Factuarea can write into this shop, on which addresses, how a delivery proves
it came from Factuarea, what each notice carries, and what each answer means to the
sender.

This document exists so that whoever writes a sender programs against a value
rather than against a decision nobody has taken. It is the mirror of
[delivery-contract.md](delivery-contract.md), which describes the other direction:
what this shop sends out.

If this file and the code ever disagree, the code is right and this file is a bug.

---

## 1. The three receivers

All three are registered under this plugin's own REST namespace, `factuarea/v1`,
and all three answer `POST` and nothing else.

| Receiver | Address | What it applies |
| --- | --- | --- |
| Order status | `POST <site>/wp-json/factuarea/v1/order-status` | The fulfilment status of one order, and its tracking details. |
| Order refund | `POST <site>/wp-json/factuarea/v1/order-refund` | One refund the platform already issued, recorded on the order. |
| Catalogue push | `POST <site>/wp-json/factuarea/v1/catalog-push` | The catalogue fields, the price and the stock of a batch of articles. |

**The namespace is this plugin's, not `wc/v3`.** None of the three destinations is
a WooCommerce resource. Changing an order through `wc/v3/orders/{id}` would write
into the shop's own state machine, which belongs to the merchant, and would lose
the tracking details entirely; publishing a refund through
`wc/v3/orders/{id}/refunds` would **move money a second time** through the
merchant's payment gateway, over an amount the platform has already returned with
a corrective invoice behind it.

**The status and the refund are two routes and not one with a discriminator
inside.** The two notices have different bodies, different risks and different
consequences: a single entry point would force this plugin to branch on the
content before knowing what it is validating, and the day the status notice
changes shape it would drag the one about money with it. The address of each was
read off the frozen contract the platform publishes for this lane
(`backend/docs/architecture/store-order-status-receiver-contract.md`, section 2)
and off the constructors behind it, `WooCommerceEndpoints::orderStatus()` and
`::orderRefund()`.

**The catalogue receiver is born without a sender, on purpose.** The outbound
catalogue lane of the platform writes today through the **native** WooCommerce
REST API - `wc/v3/products/batch` and
`wc/v3/products/{id}/variations/batch` - with the store's key and secret pair,
and names no address under this namespace at all. Its route is published first so
that whoever writes the sender finds the name they would have guessed. Section 9
lists what that other lane emits and this one does not accept.

### 1.1 How the sender composes the address

The merchant never pastes a receiver address into Factuarea. What they register
there is the **address of the shop** - the store field labelled as the public
address the shop answers on, which refuses a value carrying a path - and the
platform appends `/wp-json/factuarea/v1/` and the route to it.

The settings screen of this plugin prints the composed address of each switch -
two of them, because the refund route is governed by the order-status switch and
is not printed on its own - **so that the merchant can check them**, not so that
they can copy them anywhere.

**The trap that has no message on either end: plain permalinks.** While a site
has its permalinks set to Plain, WordPress serves its REST routes as a query
argument - `?rest_route=/factuarea/v1/order-status` - and the address the sender
composes is one the site does not answer on. Every notice is then answered `404`,
the sender classifies that as permanent, and nothing on either end names the
cause. The settings screen warns about it, and it reads the condition off the
**composed address** rather than off the permalink setting, because a site that
renamed its REST prefix breaks in exactly the same way.

## 2. Both receivers are born switched off

| Setting | Option | Default |
| --- | --- | --- |
| Order status and refund receiver | `factuarea_wc_order_status_receiver_enabled` | off |
| Catalogue, price and stock receiver | `factuarea_wc_catalog_push_receiver_enabled` | off |

Updating the plugin must not change the behaviour of a shop that touched nothing,
which is the same rule the outgoing lane applies to its delivery switch. The two
switches are separate because a merchant who wants their prices published from
Factuarea does not necessarily want anything written into their orders, and one
switch would make them take both or neither.

**A delivery to a switched-off receiver is answered `403`, with a body saying so,
and its idempotency key is NOT recorded.** Recording it would mean that, once the
merchant switched the receiver on, the sender's retry would be answered
"redelivery discarded" and would take as published a notice that was never
applied. Nothing is written, no article is read, and no order is looked up.

There is no second secret next to the switches. Incoming deliveries are
authenticated with the signing secret that already signs the outgoing ones: one
credential per shop, one field to fill in, one value to rotate. Generating a new
secret changes both directions at once.

## 3. The signature

```
X-Factuarea-Signature: base64( hmac_sha256( raw body, signing secret ) )
```

That is `base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) )`, and
the fourth argument is not optional: without it the digest comes back in
hexadecimal, and the base64 of that string is a perfectly well-formed value that
verifies nowhere.

**What is verified is the exact byte sequence that arrived**, read before
anything decodes it. Re-serialising the document first -
`json_encode( json_decode( $body ) )` - returns an equivalent document and
different bytes: key order, spacing, escaping of `/` and of non-ASCII, number
notation. The comparison is made in constant time.

The receiver answers `401`, **without a body**, for all three ways of failing:
no header, a signature that does not correspond, and a shop with no signing
secret configured. Telling them apart is free information for whoever is probing
and useless to a legitimate sender, which either holds the secret or does not.

### 3.1 The frozen vector

Copied character for character from the platform's contract, where it is asserted
against the real sender. A receiver is written against this:

| | Value |
| --- | --- |
| Body, exact bytes | `{"order_id":"1042","status":"shipped"}` |
| Secret | `secreto-de-la-tienda` |
| `X-Factuarea-Signature` | `Ssaf9gX6rfbUJulkYwdJbPAns6JntfbvtO2p+X7hDF4=` |

The body is deliberately not a complete notice: what it pins is the **scheme**,
and a vector with fewer fields survives the contract gaining an optional key. The
body and the secret are Spanish literals and they stay that way - they are one
half of a shared anchor, and changing one character of either changes the
signature and destroys the anchoring that is the whole point.

The same vectors, with their negative cases, live in
`tests/fixtures/inbound-signature-vectors.json`. If the vector ever has to move,
it moves upstream first and is copied here afterwards. Editing a signature on its
own to make a red test pass is editing the answer key.

### 3.2 The same header name carries a different scheme on another lane

This is the mistake this section exists to prevent, and it costs every delivery of
this lane when it is made.

| | This lane, the receivers of this plugin | The platform's outbound webhooks |
| --- | --- | --- |
| Material signed | The body, exactly as sent | `timestamp` `.` `body` |
| Encoding | base64 of the raw digest | hexadecimal |
| Headers | `X-Factuarea-Signature` | `X-Factuarea-Signature` plus a timestamp header |
| Receiver | The route this plugin publishes inside the merchant's WordPress | The endpoint the Factuarea customer configures in their own infrastructure |

Both headers are Factuarea's own - the platform puts them there, not the
provider - so **the name says nothing about the scheme; the receiver does**.
Unifying the two because they look alike would reject every single delivery of
this lane, because this plugin verifies base64 over the body and would be handed
hexadecimal over `timestamp.body`; and going the other way would break every
customer who already wrote a verifier against the webhook lane.

There is a third scheme in play, and it is worth naming so that nobody unifies
that one either: the deliveries this shop **sends** sign themselves into
`X-WC-Webhook-Signature`, because they impersonate a native WooCommerce webhook
down to the header names. Three lanes, three receivers, two header names.

## 4. The idempotency key

Every notice carries its key in `idempotency_key`, and it is the only thing that
ties the two sides together: an accepted delivery is answered `204` with no body
and no remote reference of any kind.

* The platform's keys are at most 64 characters. **This receiver does not validate their form**, and that is a rule rather than an omission: a key of another shape simply matches no delivery already applied and follows the normal path, while a receiver that demanded, say, sixty-four hexadecimal characters would start refusing legitimate deliveries the day the sender changed its derivation - in every installation already published, with no fix short of an update each merchant has to accept.
* What **is** checked is the length against the column that stores it, 191 characters. A key longer than that is answered `422` before anything is claimed, because a ledger that cannot store a key whole cannot keep two deliveries apart.
* The claim is atomic and happens **before** any effect: an `INSERT` into this plugin's own table that either goes in or collides with a unique index. There is no window between checking and acting, which is exactly the window two simultaneous deliveries of the same key walk through.
* A key already claimed is answered `409`, and nothing is written a second time.
* The key is recorded in a table of this plugin, `<prefix>factuarea_inbound`, and never in a WordPress option or an order meta. An option is one row read and written whole, so two simultaneous deliveries overwrite each other and the claim stops being atomic; an order meta only serves notices that already resolved their order, and a catalogue push has no order at all.

### 4.1 How long a key is kept

Thirty days - 2,592,000 seconds. The number is a floor with a margin rather than
a preference: it has to be **strictly greater** than the total span of the shared
retry schedule, because a retry arriving with its key already purged is taken for
a new delivery and its effect is applied a second time - a second refund on an
order, a second corrective invoice behind it.

The seven waits of that schedule add up to 398,160 seconds, four days and a half;
with the noise the schedule adds, the worst case is 437,976 seconds, a bit over
five days. Thirty days is roughly six times the whole schedule, which absorbs a
sender rescued late from a queue that was stopped.

The prune runs on the **same scheduled pass that already drains the outbox**. A
second scheduled task would be a second thing that can be disabled in a shop, and
the one that got disabled would be this one.

## 5. The order status notice

```
POST <site>/wp-json/factuarea/v1/order-status
```

| Key | Type | Required | What it is |
| --- | --- | --- | --- |
| `order_id` | string | yes | The identifier of the order **in this shop**. |
| `status` | string | yes | One of `prepared`, `shipped`, `delivered`, `returned`. |
| `occurred_at` | string | yes | The instant of the fact, in ATOM form. |
| `idempotency_key` | string | yes | Section 4. |
| `tracking` | object | no | Only when the shipment declares something. Section 5.1. |

```json
{
  "order_id": "1042",
  "status": "shipped",
  "occurred_at": "2026-09-19T10:00:00+00:00",
  "idempotency_key": "wc-1042-shipped-20260919T100000Z",
  "tracking": {
    "number": "TRK-0001",
    "carrier": "Carrier name",
    "url": "https://tracking.example.test/TRK-0001",
    "packages": 2
  }
}
```

**`order_id` is a string on the wire** even though WooCommerce order identifiers
are integers, because the sender stores the remote identifier of every platform
in one column and does not narrow it per platform. A number where the string
belongs is rejected rather than cast: `1042` and `"1042"` look interchangeable
until an identifier with a leading zero, or one long enough to lose precision,
arrives.

**Four states and no fifth.** `prepared`, `shipped`, `delivered`, `returned`. A
failed delivery attempt is not published at all: neither platform models that
fact, and translating it to the nearest state would tell the buyer something
false. A refund produces **two** notices - the one on `order-refund` and,
separately, an `order-status` carrying `returned`.

**What is written, and what is not.** The status is stored in this plugin's own
order meta, `_factuarea_fulfilment_status`, holding the wire literal. **The native
WooCommerce order status is never touched.** Setting it would start the shop's
own automations - e-mails to the buyer, stock movements, and this plugin's own
status captor, which would deliver the change straight back to Factuarea and
close a loop that produces a duplicated document.

**A notice older than the one already applied is discarded in silence** and still
answered `204`. Deliveries are queued and retried, so `shipped` published at 10:00
can legitimately arrive after `delivered` published at 11:00, and without this the
buyer would be told their parcel is on its way after being told it arrived. The
comparison is against the instant of the **fact** (`occurred_at`), never the
instant of the write, and it is strict: two notices stamped at the same instant
are two facts, and the later arrival is the one the sender resolved last. When
either instant cannot be read, the notice **is** applied: refusing would let one
unreadable value freeze an order's fulfilment status for good.

Every applied notice also writes a **private** order note, so the merchant has the
history in the place they already read it. Private, because the buyer has their
own place to read this and a customer note would e-mail them about something the
platform has already told them.

### 5.1 The tracking block

Only the sub-keys that are present travel: `number`, `carrier`, `url` and
`packages`. **If there is no tracking datum at all the whole block is absent**;
sending it with four nulls would force the receiver to tell "I do not know" from
"there is none" over the same value. A shipment without a tracking number is
published all the same: the buyer has a right to know their order left.

* **The tracking address arrives already composed and is stored exactly as it arrived.** It is never recomposed from the carrier and the number: the platform derives it from its own carrier template and persists it in no column, so deriving it a second time here would give two different addresses for the same shipment the day that template changes.
* **Tracking travels with `shipped` and only with `shipped`.** A notice of any other state has its tracking meta **deleted** rather than kept. A shop that kept it would show the buyer the tracking of a previous shipment as if it were the current one - which is why, after a `delivered`, an order has no tracking meta at all. Read the absence as an absence: a delivered order does not keep the link.
* **`packages` is declared and not persisted.** It is read and validated - an integer, never a numeric string - and this version stores nothing from it. It is in the contract because the sender emits it.

**An unknown key does not reject a status notice**, unlike a catalogue batch, and
the asymmetry is deliberate. Here the body only describes a fact: a key this
version does not read is a fact it does not show, which costs the merchant
nothing, while rejecting it would stop applying legitimate notices in every shop
that had not updated the day the contract grew one optional key.

## 6. The refund notice

```
POST <site>/wp-json/factuarea/v1/order-refund
```

| Key | Type | Required | What it is |
| --- | --- | --- | --- |
| `order_id` | string | yes | The identifier of the order **in this shop**. |
| `amount` | **string** | yes | The refunded amount, decimal, with its scale. |
| `currency` | string | yes | The currency the platform declared. |
| `scope` | string | yes | `total` or `partial`. |
| `corrective_invoice_reference` | string | yes | The **public** reference of the corrective invoice - its number, never an internal identifier. |
| `idempotency_key` | string | yes | Section 4. |

```json
{
  "order_id": "1042",
  "amount": "12.34",
  "currency": "EUR",
  "scope": "partial",
  "corrective_invoice_reference": "R-2026-0007",
  "idempotency_key": "wc-refund-019e0000-0000-7000-8000-000000000042"
}
```

**This notice reflects money; it does not move it.** The money was returned
outside this shop and a corrective invoice already backs it. The refund object is
created in WooCommerce with `refund_payment` and `restock_items` both false and no
line items: no payment gateway movement is ordered, and no stock is put back. If
the receiver also asked the gateway to refund, the buyer would be paid twice.

**The amount is a string end to end.** `"12.34"` is not `12.34`: a float cannot
hold most two-decimal amounts exactly, so a single cast on the way in turns a
refund of 12.34 into one of 12.339999999999999 and nothing downstream can tell
which the platform meant. The shape is checked with a pattern - optional minus
sign, digits, at most one decimal point - and not with `is_numeric()`, which also
accepts `"1e3"`, `" 12.34"` and hexadecimal-looking values that WooCommerce would
store verbatim and then render as a nonsense total. The scale is not fixed at two:
a currency with three decimals is a currency.

**The refund never travels back out.** Before the refund object is created, this
plugin writes an anti-loop mark on it - the claimed idempotency key, under
`_factuarea_inbound_refund` - and both of this plugin's refund captors read it and
stand down. Marking it afterwards would leave the loop open for the length of one
request, and the symptom would arrive weeks later as a duplicated corrective
invoice in a series filed with the tax authority, where a document is not deleted
but corrected with another document. A refund the **merchant** makes in their own
shop carries no mark and is delivered to Factuarea exactly as before.

The order itself keeps the public reference of the corrective invoice and the
scope of the last refund applied, and gets a private note naming the amount, the
currency, the scope and the reference.

## 7. The catalogue, price and stock push

```
POST <site>/wp-json/factuarea/v1/catalog-push
```

The body carries a batch of items and the idempotency key of that batch: the
whole batch is one delivery and is deduplicated as one.

The envelope carries exactly these two keys and no others:

| Key | Type | Required | What it is |
| --- | --- | --- | --- |
| `items` | list | yes | The articles of the batch. At least one; the shape of each is the table below. |
| `idempotency_key` | string | yes | The key of the **whole** batch, at most 191 characters. |

```json
{
    "idempotency_key": "wc-catalog-20260919T100000Z-0001",
    "items": [
        { "sku": "SKU-0001", "price": "12.40", "available_quantity": 7 }
    ]
}
```

The **item** was frozen before any receiver was written: its keys, their types and
the rules that reject it are what a sender programs against. The **envelope** was
not, and it is named here because this receiver chose it: the platform's outbound
catalogue lane writes through the native WooCommerce REST API and names no address
of this plugin, so there was no upstream shape to be compatible with. `items` is the
word the platform's own batch objects already use for the same collection, and
`idempotency_key` is spelled exactly as it is in the two notices of sections 5 and 6
- one lane, one name, or every sender has to carry two spellings of one idea. A
test of this package asserts the three are the same string. Neither name can drift
from this document without a version of the namespace.

A key of the envelope that this table does not enumerate rejects the batch as
malformed, for the reason section 7.1 gives for an unknown key of an item.

**A batch with no articles in it is refused**, `422`, rather than answered with an
empty list: the sender composed nothing and shipped it, and recording its key would
answer the corrected redelivery of that same key as already published.

Each item may carry these keys and no others:

| Key | Type | Required | What it is |
| --- | --- | --- | --- |
| `remote_id` | string | no | The identity this plugin already registered for the article. |
| `sku` | string | yes | The reference. The only way to resolve an article whose identity is not registered yet. |
| `name` | string | no | Catalogue field. |
| `description` | string | no | Catalogue field, rich content. |
| `active` | bool | no | Catalogue field. Whether the article is on sale in the shop. |
| `price` | **string** | no | Decimal with its scale, for the same reason the refunded amount is a string. |
| `available_quantity` | int | no | The **available** quantity. Section 7.3. |

An absent key means "this push is not about that field", and nothing of it is
written. A push of stock does not blank a description, and a push of catalogue
fields does not blank a price.

### 7.1 Two kinds of unaccepted key, and the batch is the unit that fails

**An unknown key rejects the whole batch**, answered `422`, with nothing applied
and no idempotency key claimed. An unknown key is not a datum to ignore politely:
it is a **contract that changed**. Accepting it silently means the platform
believes it published something the shop never applied, and in a catalogue that
difference is a price or a quantity - the merchant finds out by selling at the
wrong price. The cost is written down rather than hidden: the day the sender adds
a field, shops that have not updated reject their batches. That is a visible,
reversible failure with a message, and it is why the REST namespace has carried a
version since the first release.

**An internal figure also rejects the whole batch**, and the receiver names it
apart in its answer and in its log. These are `cost`, `supplier`,
`physical_quantity`, `committed_quantity` and `reorder_threshold`: not fields this
receiver has not learnt yet, but figures that must never leave the platform. One
of them arriving means something upstream is leaking, and applying the rest of
that batch would leave the leak without a witness.

Both checks run on the raw items, before any article is resolved or written.

### 7.2 How an article is resolved: identity, then reference, never creation

1. **The registered identity.** If the item carries `remote_id`, the shop is asked for the article carrying that value under `_factuarea_remote_product_id`. Exactly one match resolves it. **More than one is `ambiguous`**, and the push falls no further: an identity registered twice is the same danger as a reference shared twice. An identity registered on an article that can no longer be built is `not_found`, and it does **not** fall through to the reference either - applying the push to a different article while the identity stayed on the broken one would leave the shop with two articles claiming to be the same remote one.
2. **The reference.** No identity, or no article carrying it: the `sku` is looked up. No match is `not_found`. **A reference that more than one article carries is `ambiguous`**, and nothing is applied to either: choosing one is choosing blindly which of the merchant's products changes price.
3. **Nothing is ever created.** Whether an article should be created belongs to the publishing mode of the sending platform and travels in the push; a receiver that created on its own would turn one mis-addressed push into a duplicated catalogue in somebody else's shop.

**The first resolution by reference registers the identity.** When the item
carried a `remote_id` and the article was found by its reference, this plugin
writes that identity on the article, once in its life. That is why the following
pushes never search by reference again - and it is the point of the order above:
the search is the expensive and the ambiguous half of the resolution, and once the
identity is recorded the ambiguous question is never asked for that article again.

So `ambiguous` covers **two** situations, and a sender reading it must consider
both: a reference two articles share, and an identity two articles carry. The
second one this plugin cannot produce by itself; a restored backup or a duplicated
article can.

**One honest limit of the ambiguity check.** Whether a reference is unique is
asked through the WooCommerce function for it, which passes through a filter that
shops allowing duplicate references switch off. In such a shop this receiver
cannot see past a filter the shop itself installed to answer that question. What
it does, always, is refuse to choose when it does see two.

### 7.3 What each writer does

The three writers run in order over the resolved article - catalogue fields,
price, stock - and each applies only the fields it owns.

* **Catalogue fields.** `name` is stored as plain text, `description` as rich content filtered the way WordPress filters post content, and `active` maps to the published or draft status of the article. A key of the right name with a value of the wrong kind is rejected as `field_not_enumerated`, and nothing of that article is written.
* **Price.** Only the regular price is set, with the string exactly as it arrived. **No tax is recomputed, no tax class or tax status is touched, and no sale price is written or cleared** - section 9 says why. A price that is not a readable decimal is rejected as `unreadable_amount`, and the previous price is left exactly as it was rather than replaced by a truncated value.
* **Stock.** The quantity is **assigned**, never added to or subtracted from what is here: it is the quantity available for sale, which the platform computed by subtracting the live commitment before publishing, and adding it to a local balance would double-count. The stock status is recomputed with the same three inputs WooCommerce itself uses - the quantity, the shop's own out-of-stock threshold and the article's backorder setting. An article that does not manage stock is rejected as `stock_not_managed`; see section 9.

### 7.4 The answer: one outcome per article

The batch answers `200` with one outcome per article, under the same `items` key
the request named its articles with, so that a reader does not have to learn two
words for one list. Each outcome names the article **the way the sender named it** -
the remote identity when the push carried one, the reference otherwise, never an
internal identifier of this shop:

```json
{
    "items": [
        { "reference": "SKU-0001", "status": "applied" },
        { "reference": "SKU-0002", "status": "rejected", "reason": "not_found" }
    ]
}
```

**Why `200` and never the `204` the other two receivers answer.** The accepted
status of the table in section 8 is bodiless on purpose - there is nothing a status
notice needs to say back - and here the per-article outcome **is** the answer, so
there has to be a body. A batch whose articles were not all applied must not be
reported as accepted either. One status for both cases and not two, so that the
sender parses one shape and reads the list rather than branching on a number that
cannot carry what it needs anyway. That table still governs every **refusal** of
this route, which is what the sender classifies by number.

The `reason` key is present only on a rejection. The five reasons are closed, and
there is no sixth:

| Reason | What the sender has to do |
| --- | --- |
| `not_found` | The reference matches no article here. Nothing was created. |
| `ambiguous` | The reference, or the registered identity, matches more than one article. Nothing was applied to any of them. |
| `stock_not_managed` | The article does not manage stock, and this receiver does not switch that on. |
| `unreadable_amount` | The price is not a readable decimal. The previous price is untouched. |
| `field_not_enumerated` | The item declares a catalogue field this version cannot write. |

**A batch is not all-or-nothing once it has been read.** Three articles applied
out of five stay applied, and the batch is not reported as correct. A rejection of
one article is not a failed delivery: the two have opposite answers - one is
retried and the other never will be - and a single status for the whole batch
cannot carry that.

**A rejection can arrive after part of the article was already written**, and the
answer says so rather than pretending otherwise. The writers run in order and each
persists its own work, so an article whose catalogue fields and price were applied
and whose stock was refused answers `rejected` with `stock_not_managed` **and**
keeps the name and the price it was just given. That is each writer applying what
it owns, not a half-failure to undo.

## 8. The answer table

| Status | Body | What the sender makes of it |
| --- | --- | --- |
| `204` | none | **Published.** The effect is in the shop (the order-status and refund lanes). |
| `200` | yes | **Published**, with one outcome per article (the catalogue lane only, see §7.4): `{"items": [{reference, status[, reason]}, …]}`. An article the shop could not apply is a per-article `rejected`, not a refusal of the delivery. |
| `409` | yes | **Published**, redelivery discarded by idempotency. Nothing was written a second time. |
| `401` | **none** | **Permanent.** Signature or credential refused. |
| `403` | yes | **Permanent.** The receiver is installed and switched off. |
| `404` | yes | **Permanent.** The order named is not one of this shop's. |
| `422` | yes | **Permanent.** The body is not one this contract can read. |
| `429` | yes | **Retryable.** The only answer of this side the sender comes back from, with the shared retry schedule. |

The map is closed - `200` belongs to the catalogue lane alone, because there the
answer IS the per-article outcome. A receiver that answered a status outside it would be
classified by the sender's fallback - "any other 4xx is a malformed notice" -
which is a correct default for an unknown shop and a wrong answer for this one, so
this side never relies on it.

**Why `403` rather than a fourth flavour of `401`.** The sender's translator puts
`401` and `403` in one branch whose merchant-facing reason ends by telling them to
check the signing secret of this shop **and that the Factuarea plugin is still
installed and active** - which is exactly the sentence that sends a merchant to
the switch they have to flip. Every other 4xx falls into the last branch and is
reported as the shop having rejected the **content** of the notice, which sends
them to inspect a notice that is perfectly fine. Both classify as permanent, so
the difference is not what the queue does; it is what the merchant reads.

**Why `401` carries no body while `403` does.** Refusing to say which of the three
ways of failing authentication happened denies a prober information. Refusing to
say "this receiver is off" denies it to the one person who can turn it on.

**Nothing this receiver writes carries a credential.** Not the signing secret, not
the expected signature, not the presented one - not in an answer, not in an admin
notice, not in a log line, not in a diagnostic dump, whole or truncated.

### 8.1 The order a receiver runs in, which is part of the contract

1. Verify the signature over the **raw** body. Failure is `401`, with no body, before anything is decoded.
2. Read the receiver's switch. Off is `403`, **without claiming anything**.
3. Read the body. A shape this contract cannot read is `422`, **without claiming anything**.
4. Check the idempotency key against the column length. Too long is `422`, before claiming.
5. Resolve the order (order-status and order-refund lanes). An order that is not of this shop is `404`, **without claiming anything**; no similar order is looked for, by amount, by date or by proximity, because a near miss hands one buyer another buyer's order. The catalogue lane does NOT resolve here: its articles are resolved AFTER the claim (step 7), because the resolver registers `_factuarea_remote_product_id` on the first match by reference and resolving before claiming would let a redelivery write again (see §7.2).
6. Claim the key. Already claimed is `409`.
7. Apply, and record the key as applied.

Every refusal before step 6 claims nothing, and that is the point of putting them
there: a key claimed for an effect that never landed would answer the sender's
next delivery of the same fact with a `409`, which it classifies as PUBLISHED,
and the notice would be lost with both sides believing it arrived - the same
harm the switched-off receiver avoids by not claiming.

If applying fails after the key was claimed, the claim is **released** so that the
sender's retry can come back, and the answer is a `5xx` - which the sender
classifies as retryable - or a `422` when the failure is permanent, such as an
amount that cannot be read. A stale status notice is not a failure: it is
discarded, answered `204`, and its claim stays.

## 9. Declared limits of this version

Written down rather than discovered. Each is a decision, and the reason is the
part that matters.

**(a) The catalogue receiver has no sender yet.** The platform's outbound
catalogue lane writes through the native WooCommerce REST API. This receiver's
contract is published first so that a sender can be written against it.

**(b) `sale_price` is not accepted, and a new regular price does not clear a live
sale.** The inbound contract publishes **a price, not a campaign**: a sale price
the merchant set in their own shop is theirs, and a receiver that cleared it would
be undoing a decision nobody asked it to revisit. The consequence is visible and
worth stating: an article on sale that receives a new regular price **keeps
charging its sale price**, and the sale does not disappear. The native lane of the
platform does emit `sale_price`; this one does not read it.

**(c) `manage_stock` is not accepted.** An article that does not manage stock is
**rejected** with `stock_not_managed` rather than having stock management switched
on for it. Switching it on is a decision about how the merchant's catalogue
behaves, and taking it from a webhook changes their shop without anybody asking.
The native lane of the platform does send `manage_stock`, so an article that lane
would switch on is one this lane refuses - a divergence, declared here, not an
oversight.

**(d) A variation whose stock the parent manages is refused for the same reason.**
Writing the quantity to the parent would change it for every sibling variation of
that parent, which is not what a push addressed to one variation asked for.

**(e) A negative amount that is readable is applied.** The pattern accepts an
optional minus sign, and the five rejection reasons have no arm for "readable and
implausible". Whether a negative price or a negative refund may be published is
the **sender's** decision and belongs to the contract, not to a receiver that
would have to lie about the reason.

**(f) A refund is created in the currency of the order.** The notice declares a
`currency` and WooCommerce creates the refund in the order's own currency,
ignoring it. The declared currency is named in the order note next to the amount
**so that a mismatch is visible to the merchant instead of silent**, but it is not
required to match and a mismatch does not reject the notice: the order was born in
this same shop, so by construction the two agree.

**(g) `tracking.packages` is read and not stored**, as section 5.1 says.

**(h) A full catalogue push can save the same article up to four times in one
request**: once when the resolution registers its identity, and once per writer
that has something of its own to apply. Each save fires WooCommerce's own product
update hook and whatever is listening on it. Collapsing them would mean a writer
answering `applied` for work it had not persisted, which would be a lie in the
answer body; the cost is declared instead.

**(i) Identifying which shop sent a delivery, in the other direction.** When a
store identifier is configured, every delivery this shop **sends** carries it in
an eighth header of its own. That is the outgoing half and it is described in
[delivery-contract.md](delivery-contract.md); it is named here because it is the
answer to "two shops under one connection" and people look for it in both files.

**(j) An inactive VARIATION is set to `draft` here and the native lane sets it to
`private`.** `active: false` is written as `draft` for every article, variations
included. The platform's own `wc/v3` lane deliberately writes `private` to a
variation instead, because WooCommerce does not accept `draft` as a variation
status and the shop would refuse it
(`WooCommerceCatalogWriter::fieldsFor()` in the monorepo says so in as many
words). So an inactive variation that the native lane parks as `private` is one
this lane may fail to park at all. It is declared rather than patched: which
status an inactive variation deserves is a decision of the inbound contract, and
taking it inside a writer would give this package a second, silent answer to a
question section 7.3 answers in one line for every article. Reported as open
question 6 of this change.

**(k) An article the writers reject may still keep its remote identity.** The resolver
registers `_factuarea_remote_product_id` the first time a reference resolves,
BEFORE the three writers run - so an article that then answers `rejected`
(for example `stock_not_managed`) keeps that one durable write. Deliberate: moving
the write behind the writers would lose the identity of an article rejected only
for its stock, and the next push would search by reference again (measured end
to end, 2026-09-18).

### 9.1 The gap this package does not close: invoice correlation

The `invoice-correlation` route of this plugin, which predates everything
described above, **still has no sender**, and nothing in this work gives it one.
Its receiver is left exactly as it is.

Closing that loop needs one of two things, and both belong to the platform and to
another change: either the public v1 representation of an **invoice** emits its
idempotency key, or there is a v1 query for invoices **by** that key. The column
exists on the platform side and is unique per company; it is simply not published,
and there is no query by it.

Until one of the two exists the route answers and correlates nothing, which is its
**intended** state. The alternative to building it was never to wait: it was to
correlate an order with an invoice by amount and date, a rule that works for months
and one day hands a buyer somebody else's invoice.

What this contract resolves is the publication of **order state**, which names the
order by its identifier in the shop. That is a different thing from correlating the
invoice, and it does not stand in for it.


## 10. Debugging a delivery that did not arrive

| What you see | What it usually is |
| --- | --- |
| Every notice answered `404` | Either the order is not this shop's, or the site serves its REST routes as a query argument. Check the permalink setting first: the settings screen warns when the composed address is the query-argument form. |
| Every notice answered `401`, and the merchant is sure of the secret | The signing secret stored here is not the one Factuarea signs with. Generate a new one and paste it into the WooCommerce connection that backs this store. A sender that signed with the webhook-lane scheme gets the same answer; section 3.2. |
| Every notice answered `403` | The receiver is switched off. Nothing was recorded, so switching it on and letting the sender retry applies the notice normally. |
| A notice answered `409` that the merchant says never happened | The key was claimed by an earlier delivery. Look for that delivery: the row in `<prefix>factuarea_inbound` carries when it was received and when its effect landed. A row received and never dated is a receiver that died between the claim and the effect. |
| A status applied, and the order still showing its old WooCommerce status | That is the contract, not a defect. The native status is never touched; the received status is shown next to it. |
| A catalogue batch answered `422` with nothing applied | An unknown key or an internal figure. The answer names which, and they mean different things: section 7.1. |

The private order notes are where the history of an applied order lives. They are
notes for the merchant, so look for them in the order screen of the WordPress
admin, not in the buyer's e-mail.
