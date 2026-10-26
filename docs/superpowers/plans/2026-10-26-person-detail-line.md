# Person Detail Line Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Status column of the admin user-association table read like the Account column — the iTop person's name and email under the id, an icon distinguishing a portal user from an agent, and link provenance on hover.

**Architecture:** One enrichment service that answers for a whole page in **two** batched iTop queries, never per row. The table degrades to today's behaviour when iTop is unreachable, and says so when a person no longer exists.

**Tech Stack:** PHP service and controller, vanilla JS in `src/admin-binding.js`, plain CSS. Nextcloud 32–35.

## What the row already carries, and what it does not

`BindingListService::describe()` already returns, with **no** iTop call: `uid`, `displayName`, `email`, `bound`, `personId`, `userId`, `method`, `boundAt`, `excluded`, `sourceValue`.

It does **not** carry the person's name, email, or profile — those live in iTop. The table makes no iTop calls today, which is why it loads instantly and why match suggestions are explicitly opt-in. **This plan adds the first iTop dependency to the table, so it must not become a reason the table fails to load.**

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. The OCP stubs in `vendor/nextcloud/ocp/` are the authority.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` — never hard-code `'integration_itop'` in PHP.
- **No new npm dependencies.** `src/admin-binding.js` stays vanilla; Vue only in `src/views/`.
- Every user-facing string through `$l->t()` or `t('integration_itop', …)`; counts through `translatePlural` as `n`. **No English-only plural idioms.**
- **Every new string goes into all four locales**, both `.json` and `.js`: `de` informal, `de_DE` formal, `fr` formal *vous*, `en` identity. Plural entries are keyed `_<singular>_::_<plural>_`. Verify `.js`/`.json` parity programmatically. Baseline is 716 keys per locale.
- **Tests are local-only and never committed.**
- `js/` is gitignored but its built `.mjs` bundles **are tracked**. After `npm run build`: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- `no-unused-vars` is an eslint **error**. Baseline **0 errors, 14 warnings**.
- PHP suite is `./vendor/bin/phpunit`, currently **188 tests / 417 assertions green**.

## Design decisions already made — do not re-litigate

- **Two batched queries per page render, never per row.** `SELECT Person WHERE id IN (…)` for the names and emails, and `SELECT User WHERE id IN (…)` for the profiles. A 25-row page costs two requests, not fifty.
- **Look the User up by the stored `user_id`, not by `contactid`.** `IdentityResolver::findUserIdForPerson()` takes `reset($objects)` when a person has several Users — a known ambiguity recorded in the spec. The row already stores which User this account is bound to, so use it and sidestep the guess entirely.
- **Reuse `ProfileService::determinePortalOnly(array $profileNames): bool`.** It is a pure static function, documented as such, and a 1.5.0 bug fix corrected it because it gates CI entitlement scoping. **Do not reimplement the rule** — two copies will drift, and the copy that drifts is the one deciding what a user may see.
- **Unknown is not "agent".** A row whose `user_id` is absent, or whose User could not be read, has an **unknown** profile. Render it neutrally. Showing the agent icon would imply elevated access that has not been established.
- **A missing person is surfaced, not hidden.** If an id has no matching Person, say so — that is a broken link an administrator should fix. A transient iTop outage is different and must not look like a broken link.

## The three icon states

| State | Meaning | Icon |
|---|---|---|
| portal | `determinePortalOnly()` is true for this User's profiles | a plain person / account glyph |
| agent | `determinePortalOnly()` is false, profiles were read | a service-desk / headset glyph |
| unknown | no `user_id`, or the User could not be read | a neutral person |

**Use the app's existing artwork — do not add new icon assets and do not pull in `vue-material-design-icons` here:**

| State | Asset |
|---|---|
| agent | `img/security-pass.svg` — a pass on a lanyard, i.e. someone who holds credentials |
| portal | `img/team.svg` — ordinary staff, the people a service desk serves |
| unknown | `img/customer.svg` — a plain silhouette: a person about whom nothing further is established |

The unknown icon must never be the agent one. It stands for "we could not read this person's profile", and borrowing the pass would assert elevated access that was never established. `customer.svg` is the right shape for that: it is a single flat blue path with no pass and no second figure, so it reads as deliberately uninformative rather than as a third category of user.

**Render them through CSS `background-image`, not inline SVG.** Both files are ~5 KB full-colour illustrations carrying `<linearGradient id="…">` and `<radialGradient id="…">` with fixed ids. Inlining one per row on a 25-row page would mean ~130 KB of duplicated markup and 25 copies of every gradient id in one document — ids are resolved document-wide, so duplicating them is exactly the kind of thing that works until it quietly does not. A `background-image` gives the browser one cacheable copy and no id collisions at all.

`css/` and `img/` are siblings in the app directory, so a relative URL resolves without any PHP or JS plumbing:

```css
.itop-person-profile { background-size: contain; background-repeat: no-repeat; }
.itop-person-profile.agent   { background-image: url('../img/security-pass.svg'); }
.itop-person-profile.portal  { background-image: url('../img/team.svg'); }
.itop-person-profile.unknown { background-image: url('../img/customer.svg'); }
```

These are detailed illustrations drawn at 240×240, not flat monochrome icons. Render them at **20px**, not the 16px a Nextcloud icon would use — below roughly 18px the lanyard and the second figure stop being distinguishable, which defeats the whole point of having two.

A `background-image` is invisible to assistive technology, so each icon still needs an accessible name — it conveys whether the person is an agent, which is not decoration. Use a `<span class="itop-person-profile agent" role="img" aria-label="…">` with a translated label, and make the label say what the state means rather than naming the picture.

## How to verify UI behaviour

Synthetic DOM events do not reliably reach this page's listeners, and a rendered control is not a working one — a guard that returned early once left a button that rendered perfectly and did nothing. **Click things.** Trust the network request issued and the state read back with `occ`.

## Dev environment notes

- The dev VM symlinks this worktree; `npm run build` output is live at `https://nextcloud-dev.orb.local`.
- Nextcloud does not change its CSS/JS URLs when only contents change, so the browser serves stale assets. Re-fetch with a cache-buster before believing a result.
- PHP opcache serves stale copies of files that changed size; restart `php8.3-fpm` if `php -l` disagrees with the server.
- **Fixture**, verified against the live instance on 2026-10-26 — **these are the user's own bindings, leave them in place:** `nextadmin` → person 16 (`admin`), `pablo` → person 2 (`admin`, `user_id` 5), `test1` → person 12 (`admin`), `test2` unbound. Both self-service toggles off, both request ids empty. Expect iTop names: person 2 "Pablo Picasso", person 12 "Anna Gavalda", person 16 "Boris Vian".
- Only `pablo` has a stored `user_id`, so it is the **only** row that can currently resolve a portal/agent profile. The other two bound rows must render the `unknown` icon — that is correct behaviour, not a bug, and it is the case to check the unknown path against.

---

## Task 1: The batched enrichment service

**Files:**
- Create: `lib/Service/Binding/PersonDetailService.php`
- Test: `tests/Unit/Service/Binding/PersonDetailServiceTest.php` *(local only)*

**Interfaces produced:**

```php
PersonDetailService::describeMany(array $rows): array
// personId => [
//   'name' => string,            // '' when unresolved
//   'email' => string,           // '' when absent
//   'profile' => 'portal'|'agent'|'unknown',
//   'status' => 'ok'|'not_found'|'unavailable',
// ]
```

`unavailable` means iTop could not be reached — the whole call failed. `not_found` means iTop answered and that person does not exist. **The UI treats those very differently, so the service must not collapse them.**

- [ ] **Step 1: Write the failing tests**

Cover at least:

- an empty input makes **no** iTop call at all and returns `[]`
- unbound rows are skipped — only rows with a `personId` are queried
- one iTop failure yields `unavailable` for every requested id, and **throws nothing**
- a person absent from an otherwise successful response yields `not_found`
- a row with a `user_id` whose profiles contain `Portal user` yields `profile => 'portal'`
- the same with an agent profile set yields `'agent'`
- a row with **no** `user_id` yields `'unknown'`, and no User query is issued for it
- duplicate person ids across rows are queried **once**

Mock `ItopAPIService`. Assert call counts, not just return values — "two queries, not fifty" is the point of this service and only a call-count assertion protects it.

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/PersonDetailServiceTest.php`
Expected: FAIL — `PersonDetailService` does not exist.

- [ ] **Step 3: Write the service**

Collect the distinct `personId`s and the distinct `userId`s from the rows. Issue at most two `appRequest()` calls:

```
SELECT Person WHERE id IN (…)   output_fields: id,first_name,name,email
SELECT User   WHERE id IN (…)   output_fields: id,contactid,profile_list
```

Build the id list from integers you have validated, not by string-joining whatever arrived — these go into OQL. The class and field names are fixed in code, never taken from a request. Follow how `IdentityResolver` builds its queries.

Derive the profile by passing the parsed `profile_list` through `ProfileService::determinePortalOnly()`. Reuse `ProfileService`'s existing parsing of that field rather than writing a second parser; if it is private, make it accessible rather than duplicating it, and say which you did.

Wrap the iTop calls so any `Throwable` yields `unavailable` for everything and logs once. **The table must still render when iTop is down.**

- [ ] **Step 4: Run the tests**

Expected: PASS, output pristine, including the call-count assertions.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/Binding/PersonDetailService.php
git commit -m "Resolve iTop person details for a whole page at once

Two queries per page render rather than one per row: the table shows 25
accounts and fifty round trips would make it the slowest screen in the
app.

The User is looked up by the user_id the binding already stored, not by
contactid, which sidesteps the ambiguity where a person has several
Users and the resolver has to pick one.

Profile status goes through ProfileService::determinePortalOnly() rather
than a second copy of the rule: it decides what a user may see, and a
drifting copy would decide it differently."
```

---

## Task 2: Carry the details to the table

**Files:**
- Modify: `lib/Controller/BindingController.php`
- Test: `tests/Unit/Controller/BindingControllerTest.php` *(local only)*

- [ ] **Step 1: Enrich the listing response**

In `getUsers()`, after the list is built, pass the rows through `PersonDetailService::describeMany()` and attach the result to each bound row under a `person` key. Leave unbound rows untouched.

Keep every existing row field exactly as it is — `src/admin-binding.js` and the existing tests depend on the current shape.

- [ ] **Step 2: Add a test**

Assert that a bound row gains `person` and an unbound row does not, and that an `unavailable` result still returns **200** with the rest of the row intact. A failing iTop must never turn the listing into an error response.

- [ ] **Step 3: Verify**

```bash
./vendor/bin/phpunit
php -l lib/Controller/BindingController.php
```

- [ ] **Step 4: Commit**

```bash
git add lib/Controller/BindingController.php
git commit -m "Attach iTop person details to bound rows"
```

---

## Task 3: Render the second line

**Files:**
- Modify: `src/admin-binding.js`
- Modify: `css/admin-binding.css`

- [ ] **Step 1: Render it**

Under the existing `person {id}` line in the status cell, add a muted second line in the **same visual style** as the Account column's `uid · email` — that symmetry is the point of the change.

| `status` | Line |
|---|---|
| `ok` | `{name} · {email}`, or just `{name}` when the email is empty |
| `not_found` | an explicit "not found in iTop" note, styled as a warning |
| `unavailable` | nothing — the row looks as it does today |

The difference between the last two matters: a deleted person is a broken link worth an administrator's attention, while iTop being briefly unreachable is not, and dressing an outage up as a broken link would send someone hunting a problem that does not exist.

Put the link date and the method in the cell's `title`. Keep the existing method badge visible — the title is additional, not a replacement, because a hover is invisible on touch.

- [ ] **Step 2: Add the icon**

Render the icon for the `profile` value beside the name, per the three states above. Give it an accessible name — it conveys whether the person is an agent, which is not decoration.

- [ ] **Step 3: Escape everything**

The name and email come from iTop and are not under our control. This table builds markup as HTML strings, so every interpolated value goes through the existing `esc()` helper, which escapes `&`, `<`, `>`, `"` and `'`.

- [ ] **Step 4: Build and verify by clicking**

```bash
npm run lint && npm run build
```

Hard-reload and confirm against the fixture: `nextadmin` shows "Boris Vian", `pablo` "Pablo Picasso", `test1` "Anna Gavalda", and `test2` keeps its "not linked" cell with no second line.

Check the icon differs between a portal user and an agent on this instance, and report which row showed which. Confirm the `title` carries the date and method. Then confirm the degraded paths — report how you produced them:

- iTop unreachable → table still renders, rows show as today, no error toast
- a person id that does not exist → the "not found" note

Report the rendered text for every row.

- [ ] **Step 5: Commit**

```bash
git add src/admin-binding.js css/admin-binding.css
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Show who the iTop person actually is

A bare 'person 16' asks the administrator to go and look it up. The name
and email put the two columns in the same shape, and the icon separates
a portal user from an agent at a glance.

A person that no longer exists is called out, because that is a broken
link. iTop merely being unreachable is not, so it degrades to the id
alone rather than crying wolf."
```

---

## Task 4: Translations and documentation

**Files:** `l10n/*.json`, `l10n/*.js`, `CHANGELOG.md`

- [ ] **Step 1: Translate**

Extract the new literals programmatically and add them to all four locales in both formats. Assert `.js`/`.json` key parity and that each new string resolves in all four.

- [ ] **Step 2: Changelog**

Add a bullet to `## [Unreleased]` → `### Added`: the status column now names the person and shows whether they are a portal user or an agent, resolved for the whole page in two queries, degrading to the id alone when iTop is unreachable.

- [ ] **Step 3: Commit**

```bash
git add l10n/ CHANGELOG.md
git commit -m "Translate and document the person detail line"
```
