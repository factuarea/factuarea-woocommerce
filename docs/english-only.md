# Why this repository is written in English

Factuarea writes user-facing text in Spanish. Its application ships every string
through a translation function with parity across three locales, and a gate in its
continuous integration fails on a user-facing string missing from any of them.

**This repository is a declared exception.** Everything here is in English: the plugin
name, the checkout field label and its help text, every settings label, every error
message and admin notice, the directory listing, this documentation and the code
comments.

The exception is written down rather than assumed, and it is bounded rather than
general. Both of those are the point of this file.

## The reason

It is mechanical, not editorial.

The WordPress plugin directory translates plugins through its own platform. It reads
the source strings out of the published artefact, treats them as the **English
original**, and builds language packs from the translations volunteers contribute
against them. A plugin whose source strings are Spanish does not get a Spanish
audience; it gets an untranslatable plugin whose "original" everybody else is asked to
translate from a language they do not read. Nobody translates it, including into
Spanish, because Spanish is where it already is and the platform has nowhere to put
it.

The same mechanism has a second edge, already handled in this repository: the text
domain has to equal the plugin slug, or the language packs the platform builds are
never applied, silently. Both halves are in `src/Contract/Constants.php`.

Then there is the review. The Plugins team reviews in English and the directory
listing is read in English. A listing in Spanish is not a rule broken and fixed later;
it is a listing most of its readers close.

A bilingual plugin was considered and rejected. Carrying both languages in every label
makes every label twice as long and worse in both, and it competes with the platform
that already exists to produce the Spanish version properly.

## The scope

The exception covers **sibling package repositories only** - packages published into a
third-party marketplace whose review and audience are in English:

| Repository | Package |
| --- | --- |
| `factuarea-woocommerce` (this one) | The WordPress plugin, published to the WordPress plugin directory. |
| `factuarea-n8n-nodes` | The n8n trigger node, published to npm. |
| The Shopify app repository | The public Shopify app. |

It covers **nothing else**. In particular:

- The Factuarea monorepo keeps Spanish, with parity across its three locales and the gates that enforce it. This exception is not a precedent for a single English string in the backend or in the web application.
- The Factuarea documentation portal keeps its three-language editions.
- Nothing in this repository may be copied into the monorepo on the grounds that "the WooCommerce plugin does it this way".

If you are here because you want to write an English string in the monorepo, this file
is the reason you may not.

## What "user-facing" means here

Everything a person can read that this package authored:

- The plugin name, its description and its directory listing.
- The checkout field: label, help text, placeholder and validation message.
- Every settings label, description and button.
- Every admin notice, including the stalled-outbox one.
- Every exception message and every diagnostic of the connection test.
- `readme.txt`, `README.md` and everything under `docs/`.
- Code comments, which are read by whoever maintains this package next.

## What stays in Spanish, and why it is not a violation

Two categories, and neither is a translation that was skipped.

**Spanish fiscal terms and proper nouns.** `NIF`, `NIE`, `CIF`, `VeriFactu`, `AEAT`.
These are names of documents and of a tax authority, not words with English
equivalents. Translating `NIF` to "tax ID number" in the field label would be worse
for the person filling it in: they are looking for the word printed on their document.
The label carries both - an English noun and the Spanish names - which is what a buyer
recognises.

**Text that arrives from the receiver.** When Factuarea rejects a delivery it sends a
message for the merchant, and that message is in Spanish. It reaches the outbox and
the admin notice **relayed verbatim**, because it is a datum this package transports,
not a literal this package authored. Rewriting it would mean this plugin claiming to
know what a rejection code it has never heard of means, which is exactly what its
classifier is built not to do. The gate must read it as data: it lives in a database
column and in a variable, never in a string literal of `src/`.

Anything else in Spanish is deficit, and it goes in the code rather than in the
allowlist.

## The gate

`scripts/check-english.php`, run by `composer lint:english`, sweeps `src`, `scripts`,
`readme.txt`, `README.md` and `docs` for user-facing Spanish and fails naming the
file, the line and the string. It covers the surfaces that a human review misses:
translation function literals, checkout and settings field labels and help text,
exception messages, admin notices and the directory listing.

Its allowlist is keyed **by the reason**, so an entry with no reason does not compile,
and it holds proper nouns and Spanish fiscal terms and nothing else. Moving real debt
into it is precisely what the gate exists to prevent: it would keep catching new
strings while quietly blessing the ones already there.

It checks itself. Two fixtures under `tests/fixtures/english-gate/` - one with a
Spanish string, one without - and a test that the gate fails on the first and passes
on the second. A gate that is never pointed at a known violation can be looking
somewhere else entirely and reporting green from there.

## Copying the gate to the other sibling repositories

The script is written to be copied **as it stands**. It is plain PHP with no
dependencies, so a contributor with nothing but PHP installed can run it, and there is
nothing in it specific to WooCommerce or to WordPress.

To adopt it in another sibling package repository:

1. Copy `scripts/check-english.php` into that repository, unchanged.
2. Change **the file scope only**: the paths it sweeps. In this repository they are `src scripts readme.txt README.md docs`, passed on the command line by the `lint:english` script in `composer.json`. In a package with another layout they are that package's source, scripts and documentation.
3. Copy the two self-check fixtures and the test that points the gate at them. A gate carried over without its self-check is a gate nobody has ever seen fail.
4. Wire it into that repository's verification command, so it runs with the other gates rather than on request. A gate that has to be remembered is not a gate.
5. Start the allowlist **empty**. The proper nouns of one package are not those of another, and inheriting a list is how an entry ends up excusing something nobody ever looked at.

Do not fork the detection logic per repository. The point of one shared script is that
the three packages agree on what counts as a Spanish user-facing string; three
divergent copies would disagree quietly, and the disagreement would surface as a
package that shipped in the wrong language.
