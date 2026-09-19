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
| Everything else: gates, version consistency, revalidating the declared versions, artefact | Continuous integration | Every release |

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
- [ ] **`composer verify` is green.** All of it, including the packaging step and the version-coherence gate, `composer lint:tested-up-to`.
- [ ] **The declared versions are coherent and revalidated.** Two separate things, and both have to hold:
  - `composer lint:tested-up-to` reads the four declarations of `readme.txt` - the minimum and the tested version of WordPress and of WooCommerce - and fails when one is unreadable, when a tested version sits **below** its own required minimum, or when the minimums in the listing, in the plugin header and in `Contract\Constants` disagree. Three places have always carried those numbers and nothing compared them until this gate existed.
  - Continuous integration then **runs the end-to-end suite against the declared pair**, taking it from that gate's emit mode rather than parsing the listing a second time. A declaration nobody exercises is a promise made to strangers that stops being true in silence, and the directory republishes it as it stands. Raising `Tested up to` without the run now turns the pipeline red instead of resting on the honesty of whoever edited the line.
- [ ] **The end-to-end suite is green** against the containerised environment: five scenarios, and the report says five rather than zero. That run defends the **floor** - the minimum pair `.wp-env.json` pins - and the run above defends the **ceiling** that is declared. They are two jobs on purpose: merging them would stop testing one of the two.
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
- `Tested up to` and `WC tested up to`, **only if you actually ran the end-to-end suite against that pair.** Raising either number without running it is the one edit in this file that is forbidden outright: it is a claim, made to strangers, that nobody verified. It is no longer only a rule: continuous integration runs the suite against whatever those two lines declare, so an unbacked number fails the pipeline naming the version it could not stand up.
- Nothing else about the version is automatic. Submitting the plugin the first time, waiting for the review and uploading the release are still done by a person, and the review is done by a person who does not work here. Nothing in this repository shortens that queue or predicts its date; section 1 is the whole of what this side controls.

#### How to run the suite against a WordPress the environment does not pin

`.wp-env.json` pins the MINIMUM - WordPress 6.5, WooCommerce 8.9 - because that
is the pair the suite exists to defend. `Tested up to` is a claim about a
different pair, so measuring it takes four commands and leaves nothing behind:

```bash
eval "$( php scripts/check-tested-up-to.php --emit | sed 's/^/export /' )"   # the declared pair, from the gate
node tests/e2e/declared-pair.js          # writes .wp-env.override.json: core + the WHOLE plugins array, release resolved inside the series
npm run e2e:down && npx wp-env destroy && npm run e2e:up
npm run e2e                              # the two variables are already exported
node tests/e2e/declared-pair.js --remove
```

The script does by hand what the `e2e-declared` job of `ci.yml` does in CI, and
it is the only writer of that file on purpose: a second reader of `readme.txt`
would be a second declaration of the pair.

Two things about that file that the `e2e-declared` job of `ci.yml` also has to
respect. wp-env replaces the `plugins` array whole, so the override must repeat
`"."` or the WordPress it starts has no plugin under test. And `WC tested up to`
names a SERIES (`8.9`), not a release: `woocommerce.8.9.zip` does not exist, so
the release inside that series has to be resolved (the job reads the newest one
from the directory index; by hand, pick it from the plugin's changelog) - never
written as a fourth version declaration that drifts on its own.

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
