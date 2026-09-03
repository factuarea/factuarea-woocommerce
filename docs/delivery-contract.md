# The delivery contract

What this plugin puts on the wire, how it signs it, how it reads the answer, and what
the outbox does with each kind of answer.

This document exists so that a delivery can be debugged without reading the code.
If it and the code ever disagree, the code is right and this file is a bug.

---

## 1. What is delivered, and when

Three moments in WooCommerce produce an entry in the outbox. All three write the entry
**synchronously, inside the request that handled the event**, before any network call
happens.

| Moment | Fact it records |
| --- | --- |
| Payment completed | This order has been paid for. |
| Order status changed | The same fact, when it is reached by a status transition instead; and a refunded status. |
| Order refunded | This amount has been refunded, including a partial refund. |

The reason the recording is synchronous is the failure it removes: an event that is
recorded only by scheduled work is lost on a site whose scheduled work never runs.
Here the recording never depends on it. Only the **delivery** does, and a late
delivery is still a delivery.

The reason the refund has its own capture point is that a **partial** refund does not
change the order status. A plugin that watched status alone would miss every one of
them.

### Event identity

Each entry carries an identity derived from the domain object and from nothing else:

```
woocommerce:order:{orderId}:paid
woocommerce:refund:{refundId}
```

That identity is the `UNIQUE` key of the outbox table, so two capture points that see
the same fact collapse into **one** entry, in the database, rather than in PHP after a
read that another request could have raced.

**No delivery header takes part in composing it.** `X-WC-Webhook-ID` and
`X-WC-Webhook-Delivery-ID` change between attempts, so an identity built from either
would make a redelivery look like a new fact, and the receiver would issue a second
invoice into a correlative series that VeriFactu does not allow anybody to delete.

The receiver derives its own idempotency key from the same three parts:

```
sha256("{provider}:{externalStoreId}:{eventId}")
```

The plugin computes the same digest and stores it against the order, so that a later
correlation callback can find the order it belongs to. It never sends it: the two
sides agree because they compute the same thing from the same parts, not because one
tells the other.

## 2. Where it is delivered

```
POST {Factuarea address}/api/webhooks/stores/woocommerce
```

The path is measured against the receiver rather than copied from a document: the
route is declared without a prefix and registered under a group that adds `api`, so
that is the path that answers. The route takes no session authentication; the
signature is what identifies the shop.

Two answers of that route are worth knowing before you debug anything:

* **It answers 200 for a store it does not know**, on purpose, so that an unknown sender does not retry forever. This is why the connection test reads the store through the Factuarea API instead of sending a probe here: a probe could not tell "configured correctly" from "nobody here knows you".
* **It answers 404 for a provider with no verifier registered.** A 404 here is not a wrong URL; it is a provider that has not been released yet on the Factuarea side.

## 3. The headers

Exactly seven, the same set and the same names a native WooCommerce delivery sends:

| Header | Value |
| --- | --- |
| `X-WC-Webhook-Source` | The address of this shop. The outbox refuses to deliver an entry whose source is not the one currently configured. |
| `X-WC-Webhook-Topic` | `order.updated` |
| `X-WC-Webhook-Resource` | `order` |
| `X-WC-Webhook-Event` | `updated` |
| `X-WC-Webhook-Signature` | The signature. Section 5. |
| `X-WC-Webhook-ID` | Tracing only. |
| `X-WC-Webhook-Delivery-ID` | Tracing only. It is the outbox row id, so it is stable across the attempts of one entry (the attempt number lives in the `attempts` column); native WooCommerce deliveries change it on every attempt, this plugin does not. |

The set is complete on purpose. Six of them would be accepted by the receiver today
and would stop being indistinguishable from a native delivery the moment anything
downstream starts reading the seventh. The receiver does not currently read the topic
header at all - it normalises every WooCommerce order delivery under `order.updated`
and reads the body - so the topic is sent to match a native delivery rather than
because anything downstream branches on it.

The transport adds `Content-Type: application/json; charset=utf-8` when the caller
named no content type, and nothing else. It does not add an `Accept`, and it does not
set a `User-Agent`.

A refund travels as the **order** carrying its refunds, which is what a native
WooCommerce delivery does too. The receiver detects the refund from the refunds it
finds in that order body.

## 4. The body

The body is the order exactly as the WooCommerce REST API represents it. It is not
composed field by field here: that would be re-writing a contract of dozens of fields
that WooCommerce changes between minor versions.

Two properties of the body matter to the receiver:

* **The tax ID travels in the order meta**, under the key the receiver's enumerated catalogue reserves for this plugin. The check that it does is made on the **delivered body**, never on what was stored, because the delivered body is what the receiver reads.
* **An order without a tax ID is delivered normally**, and no key is written empty. The receiver invoices it as a simplified invoice.

The bytes stored in the outbox at capture time are the bytes sent. The body is not
rebuilt at delivery time, and that is deliberate: an order edited after the sale would
otherwise be delivered as it looks **later**, and the invoice would describe something
other than the sale that happened.

## 5. The signature

```
X-WC-Webhook-Signature: base64( hmac_sha256( raw body, signing secret ) )
```

The digest is taken in binary and then base64-encoded. Encoding the hexadecimal
digest instead produces a different string that never validates.

**The material signed is the exact byte sequence that is sent.** Re-encoding the body
after signing - `json_encode(json_decode($body))` - returns an equivalent document and
**different bytes**: key order, spacing, escaping of `/` and of non-ASCII, number
notation. The signature keeps covering the old bytes. Nothing fails locally; the
receiver answers 401 and the cause is invisible from this end. The receiver verifies
in constant time against the raw request body it captured before touching anything
else.

The signing secret is per WooCommerce connection in Factuarea, not per store, and it
is a **write** credential: it signs outgoing deliveries and verifies the incoming
correlation callback. It never authenticates a read. The API key is a **read**
credential: the connection test and the invoice PDF. It never signs.

An empty secret does not produce a delivery. Signing raises a typed error, the entry
is settled with that reason, and it is not retried. Sending unsigned would come back
401, be classified transient, and burn the entry's whole retry budget against a
configuration problem no retry can fix.

Golden vectors live in `tests/fixtures/signature-vectors.json`, byte-identical to the
ones the receiver's verifier is tested against. They include a body whose
re-serialisation produces different bytes, so the mistake above fails a test instead
of failing in production.

## 6. How the answer is read

The HTTP client reports and judges nothing: a fixed timeout of 15 seconds, no
redirects followed, and every outcome - including a timeout and a connection failure -
coming back through the same structure. A response with no readable status code is
reported as a transport failure and never as a status of zero.

One piece classifies, and only one. Mixing the two is exactly how a proxy's 502 ends
up read as a refusal.

### The rules, in order

| # | The response is | Outcome |
| --- | --- | --- |
| 1 | Nothing at all: timeout, connection failure, unreadable | **Transient** |
| 2 | Any 2xx | **Accepted**, whatever the body says |
| 3 | Not a rejection of the ingestion contract | **Transient** |
| 4 | A rejection of the contract that declares a wait | **Retryable**, with that wait |
| 5 | A rejection of the contract marked retryable with no usable number | **Transient** |
| 6 | A rejection of the contract that declares no wait | **Permanent** |

**Rule 3 is the one that protects a paid order.** A 502 from a proxy carries no wait
header and refuses nothing; classified permanent, it would close a paid sale for
good. So a response counts as a rejection of the contract only when it carries a
**readable rejection code**, as a non-empty string, in its body. An appliance in front
of the shop that answers with a numeric `code` field is describing itself, not this
contract.

**Rule 6 is why a code invented tomorrow classifies correctly today.** This plugin is
distributed: a shop runs whichever version it last updated to, and an installation
from eight months ago cannot be handed a new table of codes. There is no list of known
codes anywhere in it. A rejection is recognised by its shape and classified by what
the response declares about waiting - never by the code itself, and never by the
status code alone.

### What the receiver actually sends

Every rejection it produces carries a JSON body with four fields - the code, a message
for the merchant, a boolean class and the wait in seconds - and it sets `Retry-After`
if and only if the rejection is retryable and declares a wait. The header value is
always an integer number of seconds; this receiver never sends the HTTP-date form that
the HTTP specification also allows, so only seconds are parsed. Its rejection status
codes are 400, 401, 404, 422, 429 and 503. None is a 2xx.

### Header against body, and why they are not symmetrical

The header is preferred: when it carries a usable number, that number is the wait. The
body's own class field is a fallback that can only ever **add** a retry, never cancel
one.

The asymmetry is the whole point. The header can be dropped, rewritten or synthesised
by anything between the two ends - a reverse proxy, a CDN, an appliance in front of
the shop. The body cannot: it is what the receiver wrote, end to end. If the two ever
disagree, reading a wait out of the body costs one wasted attempt; declaring a
rejection permanent because a proxy ate its header loses a paid order.

The message the receiver sends for the merchant is relayed **verbatim** and reaches
the outbox in Spanish. It is a datum this plugin transports, not a string it authors.

## 7. What the outbox does with each outcome

Settling an entry always increments its attempt counter. What changes is the status
and whether a next attempt is planned.

| Outcome | Status | Next attempt |
| --- | --- | --- |
| Accepted | `delivered` | none; it is never sent again |
| Permanent rejection | `failed` | **none, not even one**; the reason is kept for the merchant |
| Retryable rejection | `pending` | at the wait the receiver declared |
| Transient failure | `pending` | at this plugin's own backoff |
| Any retryable outcome whose budget ran out | `exhausted` | none |

An entry is claimed before it is delivered, so two drains running at once never hand
the same entry to both: the claim is a conditional update rather than a read followed
by a write. A claim held by a drain that died is taken back after five minutes, at
which point the entry is pending again.

The statuses in full:

| Status | Meaning |
| --- | --- |
| `pending` | Waiting for its next attempt. |
| `claimed` | A drain has it in hand right now. |
| `delivered` | Accepted by the receiver. Terminal. |
| `failed` | Rejected permanently. Terminal. Read `last_message`. |
| `exhausted` | Retried until the budget ran out. Terminal. |

### The origin guard

An entry whose source is not the address currently configured is **not delivered**. It
is settled with a typed reason. Credentials belong to the installation that holds
them, and an entry captured for another one is not this shop's to send.

## 8. The retry schedule

It is not written by hand. It is derived from the single retry calculator Factuarea
uses for every one of its own deliveries, kept in a versioned reference file, and
compared against the plugin's own table interval by interval.

| Attempts already failed | Wait before the next one |
| --- | --- |
| 1 | 60 s |
| 2 | 5 min |
| 3 | 30 min |
| 4 | 2 h |
| 5 | 12 h |
| 6 | 24 h |
| 7 | 72 h |

* **8 attempts in total.** Once the eighth has failed, there is no further wait to plan and the entry becomes `exhausted`.
* **Noise of plus or minus 10 percent** on each wait, so a receiver coming back up is not hit by every shop at the same instant.
* **A wait the receiver declared wins over the table, and is used exactly**, with no noise added. If the receiver says how long to wait, it is obeyed.
* **Exhaustion wins over everything, the declared wait included.** A receiver asking for a retry does not extend a budget that has run out.

`php scripts/derive-retry-schedule.php` re-derives the reference file from the
calculator when the Factuarea monorepo sits next to this repository. When it does not,
the script **skips, naming the reason, with an exit code that is not success**. A check
that cannot see what it checks and reports green is a check that protects nothing.

## 9. Debugging a delivery

Everything you need is in the outbox table, `<prefix>factuarea_outbox`.

| Column | What it tells you |
| --- | --- |
| `event_id` | Which fact this is, and the key two capture points collapse on. |
| `order_id` | The order it is about. |
| `event_type` | Which fact it is, in the receiver's vocabulary. |
| `raw_body` | The exact bytes sent. These are the bytes the signature covers. |
| `source` | The shop address it was captured for. |
| `attempts` | How many attempts have failed. Feeds the schedule in section 8. |
| `next_attempt_at` | Unix timestamp of the next attempt. Zero when there is not going to be one. |
| `status` | Section 7. |
| `last_status_code` | The status code of the last answer. `NULL` means nothing came back at all, which is not the same as a zero. |
| `last_message` | What to show the merchant. For a rejection this is the receiver's own message, relayed as it arrived. |

Reading the symptoms:

| What you see | What it usually is |
| --- | --- |
| `status = failed`, `last_status_code = 401` | The signing secret in Factuarea is not the one this shop signs with. Generate a new one and paste it, or paste the stored one again. |
| `status = failed`, `last_status_code = 404` | The provider is not released yet on the Factuarea side, or the address is wrong. |
| Everything `pending` with `next_attempt_at` long past | Nothing is draining. WordPress's scheduled work only runs when somebody visits the site. Point a system cron at `wp-cron.php`. |
| `status = pending`, `attempts` climbing, `last_status_code` empty or 502 | The receiver is not reachable or something between is answering for it. This is transient by design and will keep retrying. |
| An order never appeared in the table at all | The capture never ran: delivery switched off, or the order never reached a state that produces an event. |
| `status = exhausted` | Eight attempts failed. Fix what `last_message` names, then re-enqueue the event. |

Nothing in that table, and nothing in any message the plugin writes, contains the
signing secret or the API key. If you find one there, that is a defect and not a
configuration option.
