# Publishing

How a version of this plugin reaches the WordPress plugin directory.

Read section 1 before anything else. It is the part that no workflow can do for you,
and the part that decides how long the first release takes.

---

## 1. Read this first: what is manual, and what that costs

Publishing to the WordPress plugin directory is **not a deploy**. Three of its steps
are performed by a person, and one of them is performed by a person who does not work
here.

| Step | Who | When |
| --- | --- | --- |
| Submitting the plugin for the first time | A maintainer | Once, ever |
| Reviewing that submission | The WordPress Plugins team | Once, ever, and it takes as long as it takes |
| Uploading a release to the directory | A maintainer | Every release |
| Everything else: gates, version consistency, artefact | Continuous integration | Every release |

The consequence is worth stating plainly, because it changes how a mistake is
handled. **Once a version is in the directory, it cannot be taken back.** It can be
superseded, and the stable pointer can be rolled back to an earlier version, but every
shop that already updated keeps running what it downloaded until it updates again.
Weeks, for a plugin nobody thinks about.

That is the reason two things in this repository are stricter than they would be in a
service: the classification of rejections that do not exist yet, and the deletion of
credentials on uninstall. Both are things you cannot fix with a hotfix in an hour.

## 2. Before the first submission

A checklist, in the order the review notices problems.

- [ ] **The name and the slug.** The plugin is `Factuarea for WooCommerce`, slug `factuarea-for-woocommerce`. The directory does not allow another party's trademark as the leading term of a slug; the permitted shape is your own brand first, which is what this one is. Do not rename it to lead with `woocommerce-`.
- [ ] **The licence.** GPL-2.0-or-later, with the full text in `LICENSE`, and the same licence declared in the plugin header and in `readme.txt`. The no-paywall gate checks that the three agree.
- [ ] **No paywall, no trialware.** The directory refuses a plugin whose functionality is restricted, disabled or unlocked by a payment, a licence key or a plan upgrade. Every feature of this plugin works for everybody who installs it; the commercial boundary is Factuarea, the service. `composer lint:no-paywall` fails on any condition that gates behaviour on a payment.
- [ ] **The `Contributors` line in `readme.txt` names real WordPress.org accounts.** It reads `factuarea` today. If the account that owns the submission is named differently, correct it before submitting: an unknown username there is a review finding, and it is a slow one to resolve.
- [ ] **External services are disclosed.** `readme.txt` says what leaves the site, to where, when, and what it contains. This is a review requirement and the plugin sends personal data, so it is checked closely.
- [ ] **`composer verify` is green.** All of it, including the packaging step.
- [ ] **The end-to-end suite is green** against the containerised environment: five scenarios, and the report says five rather than zero.
- [ ] **The official plugin check tool reports no blocking findings**, run at its **current** version rather than a pinned one. It is not pinned on purpose: a reproducible green can be lying the day the directory tightens a requirement, and finding that out here is better than finding it out in review.
- [ ] **The artefact contains what it should.** Unzip `dist/factuarea-for-woocommerce.zip` and look: the plugin entry point, `uninstall.php`, `src/`, `LICENSE`, `readme.txt`, `README.md`. No `tests/`, no `scripts/`, no `docs/`, no `vendor/`, no `node_modules/`, no development configuration.

## 3. The first submission

1. Build the artefact: `composer package`.
2. Submit `dist/factuarea-for-woocommerce.zip` at <https://wordpress.org/plugins/developers/add/>, signed in with the account that will own the plugin.
3. Wait. The Plugins team reviews by hand and answers by email. Expect weeks rather than days, and expect at least one round of questions.
4. Answer every finding in full, and change the code rather than the argument. A finding that comes back a second time restarts the queue.
5. On approval you receive an SVN repository at `https://plugins.svn.wordpress.org/factuarea-for-woocommerce/`. It is empty until you fill it, and the plugin does not appear in the directory until you do.

## 4. Every release, including the first upload

### 4.1 Prepare the version

Three places carry the version number and all three must agree:

| Place | Field |
| --- | --- |
| `factuarea-for-woocommerce.php` | `Version:` in the plugin header |
| `readme.txt` | `Stable tag:` |
| The git tag | `vX.Y.Z` |

The release workflow **checks this before it builds anything** and stops if they
diverge. That check exists because a `Stable tag` pointing at a version that was never
uploaded makes the directory serve nothing at all, and a plugin header behind the tag
makes WordPress offer an update that installs the same code.

Also update:

- `== Changelog ==` in `readme.txt`, with the section for this version.
- `Tested up to` and `WC tested up to`, **only if you actually ran the end-to-end suite against that pair.** Raising either number without running it is the one edit in this file that is forbidden outright: it is a claim, made to strangers, that nobody verified.

#### How to run the suite against a WordPress the environment does not pin

`.wp-env.json` pins the MINIMUM - WordPress 6.5, WooCommerce 8.9 - because that
is the pair the suite exists to defend. `Tested up to` is a claim about a
different pair, so measuring it takes four commands and leaves nothing behind:

```bash
echo '{ "core": "https://wordpress.org/wordpress-<x.y>.zip" }' > .wp-env.override.json
npm run e2e:down && npx wp-env destroy && npm run e2e:up
FACTUAREA_E2E_WORDPRESS=<x.y> npm run e2e
rm .wp-env.override.json
```

The environment guard still refuses anything other than the series named in
`FACTUAREA_E2E_WORDPRESS`, and the run prints the three versions it found. That
printed line is the evidence; copy it into the pull request that moves the
number. `FACTUAREA_E2E_WOOCOMMERCE` and `FACTUAREA_E2E_PHP` do the same for the
other two, alongside the matching change in `.wp-env.override.json`.

The official plugin check (`wp plugin check`) reports an outdated `Tested up to`
as an ERROR, and it is right to: a plugin whose header lags the current
WordPress is hidden from directory search. So the number is not cosmetic - but
it is still a claim, and the four commands above are how it stops being one.

**Measured 2026-09-03:** the five scenarios pass on WordPress 7.1 with
WooCommerce 8.9.5 and PHP 8.1.34, which is what `Tested up to: 7.1` in
`readme.txt` rests on. `WC tested up to` stays at 8.9 because 8.9.5 is what both
runs exercised.

### 4.2 Tag and let continuous integration build

```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

The release workflow then:

1. checks that the tag, the plugin header and `readme.txt` name the same version;
2. runs `composer verify` in full;
3. builds the artefact.

It stops there. It does not upload, and that is deliberate: the credential that can
write to the directory is not a credential a workflow holds.

### 4.3 Upload to the directory

This is the manual step, and it is SVN rather than git.

```bash
svn checkout https://plugins.svn.wordpress.org/factuarea-for-woocommerce/ svn-factuarea
cd svn-factuarea
```

The layout is fixed by the directory:

| Directory | What goes in it |
| --- | --- |
| `trunk/` | The current code and the current `readme.txt`. |
| `tags/X.Y.Z/` | An immutable copy of the released version. |
| `assets/` | The directory listing images: icon, banner, screenshots. **Never shipped inside the plugin ZIP.** |

Then:

1. Replace the contents of `trunk/` with the contents of the built artefact.
2. `svn add` anything new, `svn delete` anything gone. SVN does not infer either.
3. Copy trunk into the tag: `svn copy trunk tags/X.Y.Z`.
4. Confirm `trunk/readme.txt` has `Stable tag: X.Y.Z`. **This line is what the directory serves**: it points at the tag people download, and pointing it at a tag that does not exist takes the plugin offline.
5. `svn commit -m "Release X.Y.Z"`.

The directory picks the change up within about fifteen minutes.

### 4.4 Verify the published release

- Open the plugin page and check the version, the requirements and the changelog.
- Download the ZIP the directory serves and unzip it. It has to be the artefact you built, not trunk plus stray files.
- Install that downloaded ZIP on a clean WordPress with WooCommerce at the **minimum** supported version and walk one paid order end to end.

### 4.5 Releases that carry a breaking change of the Factuarea API

Some versions of this plugin exist because the API moved, not because the plugin
gained anything. They are the ones where the order of the steps above stops being a
formality, and there are two rules for them.

**The version in the directory is the one every shop is running.** A route this
plugin builds and the receiver no longer serves is a broken shop from the moment the
receiver changes until the moment the merchant updates — and merchants update when
they notice, which for a quiet shop is weeks. So the window between the two is not
"a release that is late"; it is the number of days shops are broken. Write the window
down in the pull request that makes the change, with both ends named: what the
receiver changed, and what the merchant has to do besides updating.

**A version that needs a new setting is not a silent update.** The company
identifier is the first of those: it has no default and the plugin cannot invent one,
so an installation that updates without filling it in reports an incomplete
configuration on the connection test and stops serving invoice downloads. That is
the designed outcome — the alternative is reading against whichever company the
credential happens to belong to, which answers 200 while invoicing under the wrong
tax ID. It still has to be said in the changelog entry, in the upgrade notice and in
the answer the merchant gets, all three, because a merchant who reads none of them
finds out from a buyer.

#### Coordinating with work that has not merged yet

This repository is worked on by more than one line of work at a time, and they do not
merge in a fixed order. The branch that moves the plugin onto the company segment is
one of them, and it is ready rather than released.

The rule, and it is the only one that keeps concurrent branches from being quietly
lost:

- **A branch does not publish.** Publishing is section 3 and section 4.3 of this file,
  both manual, both performed by a maintainer who has decided to. A branch is ready,
  not released; `Stable tag` is not touched by work that is not being uploaded.
- **Whichever merges second rebases or regenerates against the first.** Concurrent
  lines of work touch the same files — `Contract\Constants.php` above all — and a
  merge that resolves by taking one side drops the other's work without anything
  going red.
- **One release carries both, or the first to merge releases and the second follows.**
  What must not happen is a release built from a branch that silently lost half of the
  other, because the evidence of the loss is a green pipeline.

## 5. Rolling back a bad release

You cannot delete a version. What you can do:

1. Point `Stable tag` in `trunk/readme.txt` at the last good tag and commit. New downloads and new updates go back to that version within minutes.
2. Release a fixed version as soon as it exists. Rolling the pointer back protects people who have not updated yet; it does nothing for anybody who already did.
3. If the defect exposes data or loses orders, say so in the changelog of the fixed version. A shop that hit it needs to know it was hit.

Point 2 is the whole reason section 1 is written the way it is. The pointer is a
rollback for the directory, not for the installed base.

## 6. What to do about a stalled review

The Plugins team answers on its own schedule and does not take escalation. What
shortens the queue is not sending another email; it is that the next answer needs no
further round. Before replying, re-run every gate and re-read the finding literally:
most second rounds are caused by an answer that addressed what the reviewer meant
rather than what they wrote.
