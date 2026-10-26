# iTop Identity Binding — Phase 4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user link their own Nextcloud account to their iTop person from personal settings, without an administrator and without an iTop personal token — behind an admin toggle that is off by default.

**Architecture:** A new user-facing controller, deliberately separate from the admin-only `BindingController`, driven by the same `IdentityResolver` so tier rules cannot drift between the screen, the command and self-service. Reintroduces the two admin toggles that 1.5.0 removed, this time actually read.

**Tech Stack:** PHP controller and service, vanilla JS in `src/personal-settings.js`, a server-rendered PHP template. Nextcloud 32–35.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. The OCP stubs in `vendor/nextcloud/ocp/` are the authority for what exists.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` (`'integration_itop'`) — never hard-code the string in PHP.
- **No new npm dependencies.** Vue only in `src/views/` components; `src/personal-settings.js` stays vanilla.
- Every user-facing string through `$l->t()` in PHP or `t('integration_itop', …)` in JS. Counts through `translatePlural` as `n`. **No English-only plural idioms** such as `account(s)` — this app ships `de`, `de_DE` and `fr`, and labels run longer in all of them.
- **Every new string must be translated, not just wrapped.** Task 5 does that for the whole branch; do not leave it to a later sweep that may not happen.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored. Write and run them; commit only source.
- The `js/` directory is gitignored, but its built `.mjs` bundles **are tracked**. After `npm run build`: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- `no-unused-vars` is an eslint **error**. Expect 0 errors (14 pre-existing warnings are fine).
- The PHP suite is `./vendor/bin/phpunit`, currently **162 tests / 353 assertions green**. It must stay green.

## The security properties — these are the point of the phase

Copied from the spec. Discovery is the one place an **untrusted** user's attribute decides which identity they receive; that is the entire risk surface and why it is opt-in and defaults to off.

1. **The source value is derived server-side.** The discover endpoint accepts **no parameters at all**. This removes both forgery and the ability to enumerate iTop contacts by probing addresses.
2. **Confirmation re-resolves.** The confirm step must not accept a `personId` from the client. It re-runs `resolve()` and binds to that result. Otherwise the whole tier system is bypassed by posting an arbitrary id.
3. **Self-service binds only on `exact`.** `review`, `ambiguous`, `conflict` and `none` tell the user to contact their administrator. A user confirming a name mismatch *about themselves* is not a meaningful check; an administrator comparing two names is.
4. **Discovery never re-binds an already-bound account.** Otherwise a user could bind, change their email, and re-bind. Re-binding requires an administrator to unlink first.
5. **Discovery is rate-limited** with `#[UserRateLimit]`. `OCP\AppFramework\Http\Attribute\UserRateLimit` is present in the NC 32 stubs.
6. **Every bind writes an audit log line**: Nextcloud user, person id, method, and the source attribute used.
7. **Excluded accounts are never matched or bound** by any method, including discovery.

### A constraint the existing code already states

`lib/Controller/BindingController.php:30-32` says:

> Every endpoint here is an administrative action, so none carries NoAdminRequired. Self-service endpoints belong to a later phase and must not be added to this controller.

Honour it. Phase 4 gets its **own controller**. Do not add a `NoAdminRequired` method to `BindingController`.

### How to verify UI behaviour — read this before testing

Synthetic DOM events do **not** reliably reach listeners registered by the page's own bundle in this project. Trust the network request actually issued and state read back with `occ`; trust real interaction. Do not conclude a handler is broken because a scripted `.click()` produced nothing.

### Dev environment notes

- The dev VM symlinks this worktree; `npm run build` output is live at `https://nextcloud-dev.orb.local`.
- Nextcloud's CSS and JS URLs do not change when only contents change, so the browser can run a **stale** bundle or stylesheet. This has produced several false conclusions already; re-fetch with a cache-buster before believing a measurement.
- PHP opcache serves stale copies of files that changed size. If `php -l` disagrees with the server, restart `php8.3-fpm`.
- **Current fixture:** `nextadmin` bound (person 16, method `token`); `pablo`, `test1`, `test2` unbound; exclusion list empty. Expected resolver results: `pablo` → `exact` person 2; `test1` → `review` (person 12, names differ); `test2` → `none` (no email).

### What the spec expects that does not exist

The spec says the personal settings UI replaces a "current misleading portal message" about personal tokens not being issued to portal users. **No such message exists in the code** — the spec anticipated one. Do not go looking for it; add the guidance as new copy.

---

## Existing interfaces you build on

```php
IdentityResolver::resolve(IUser): MatchResult      // tiers: exact|review|ambiguous|conflict|none
IdentityResolver::sourceValueFor(IUser): string
IdentityBindingService::bind(uid, personId, ?userId, method, sourceAttribute = '')
IdentityBindingService::isBound(uid): bool
IdentityBindingService::findConflictingUid(personId, exceptUid = ''): ?string
IdentityBindingService::METHOD_DISCOVERY           // = 'discovery', already defined
BindingConfig::isExcluded(uid): bool
BindingConfig::getMatchSource(): string
MatchResult::TIER_EXACT, ->tier, ->personId, ->userId, ->itopName, ->ncName, ->sourceValue
```

`METHOD_DISCOVERY` already exists on `IdentityBindingService` — this phase is what finally uses it.

---

## Task 1: The two admin toggles, wired up

1.5.0 **removed** `isTokenBindingEnabled()` and `isDiscoveryEnabled()` from `BindingConfig` because nothing read them, and an admin setting that is silently ignored is worse than none. This task reintroduces them *and* their readers in the same change, so that cannot recur.

**Files:**
- Modify: `lib/Service/Binding/BindingConfig.php`
- Modify: `lib/Controller/ConfigController.php`
- Modify: `lib/Settings/Admin.php`
- Modify: `templates/adminSettings.php`
- Modify: `src/admin-settings.js`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Service/Binding/BindingConfigTest.php` *(local only)*

**Interfaces produced:** `BindingConfig::isTokenBindingEnabled(): bool` (default **true**), `BindingConfig::isDiscoveryEnabled(): bool` (default **false**), and a `POST /binding/self-service` admin route that sets them.

- [ ] **Step 1: Write the failing tests**

```php
	public function testTokenBindingDefaultsToEnabled(): void {
		// Preserves the behaviour every existing install already has.
		$this->assertTrue($this->makeConfig([])->isTokenBindingEnabled());
	}

	public function testDiscoveryDefaultsToDisabled(): void {
		// Opt-in: discovery is the one path where an untrusted user's
		// attribute decides which identity they receive.
		$this->assertFalse($this->makeConfig([])->isDiscoveryEnabled());
	}

	public function testTogglesReadTheStoredValue(): void {
		$config = $this->makeConfig([
			'binding_token_enabled' => '0',
			'binding_discovery_enabled' => '1',
		]);
		$this->assertFalse($config->isTokenBindingEnabled());
		$this->assertTrue($config->isDiscoveryEnabled());
	}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/BindingConfigTest.php`
Expected: FAIL — the methods do not exist.

- [ ] **Step 3: Add the accessors**

In `BindingConfig`, add the two methods plus a `setSelfServiceMethods(bool $token, bool $discovery): void` writer. Store `'1'` / `'0'` via `setValueString`, consistent with every other key here. Defaults: token `'1'`, discovery `'0'`.

- [ ] **Step 4: Make the token toggle actually bite**

In `lib/Controller/ConfigController.php`, the personal-token binding path (`setConfig`, the branch that validates a token and writes `person_id`): refuse with `Http::STATUS_FORBIDDEN` and a clear message when `isTokenBindingEnabled()` is false, **before** validating the token or writing anything.

This is the reader whose absence got these toggles deleted. Without it the setting is decorative.

- [ ] **Step 5: Expose them to the admin**

Add `'binding_token_enabled'` and `'binding_discovery_enabled'` to `Admin::getForm()`'s parameters, two checkboxes in the **🔗 Matching rule** subsection of `templates/adminSettings.php`, and a save handler in `src/admin-settings.js` posting to a new admin route:

```php
		['name' => 'binding#setSelfService', 'url' => '/binding/self-service', 'verb' => 'POST'],
```

Label the discovery checkbox so the risk is legible, e.g. *"Let users link themselves by matching their account attribute (off by default — the matched attribute decides which iTop person they receive)"*.

- [ ] **Step 6: Verify**

```bash
./vendor/bin/phpunit
php -l lib/Service/Binding/BindingConfig.php
php -l lib/Controller/ConfigController.php
npm run lint && npm run build
```

- [ ] **Step 7: Commit**

```bash
git add lib/Service/Binding/BindingConfig.php lib/Controller/ConfigController.php lib/Settings/Admin.php templates/adminSettings.php src/admin-settings.js appinfo/routes.php
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Reintroduce the self-service toggles, with their readers

1.5.0 removed these two keys because nothing read them, and a setting an
administrator can change with no effect is worse than no setting. They
come back together with the code that honours them: the personal-token
path now refuses when token binding is disabled, and discovery is gated
on its own toggle from the start.

Token binding defaults on, preserving existing behaviour. Discovery
defaults off: it is the one path where an untrusted user's attribute
decides which identity they receive."
```

---

## Task 2: The discovery endpoints

**Files:**
- Create: `lib/Controller/DiscoveryController.php`
- Create: `lib/Service/Binding/DiscoveryService.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Service/Binding/DiscoveryServiceTest.php` *(local only)*

**Interfaces produced:** `POST /binding/discover` and `POST /binding/discover/confirm`.

- [ ] **Step 1: Write the failing tests**

These tests are the security properties. Cover at least:

```php
	public function testDiscoverRefusesWhenDisabled(): void {}
	public function testDiscoverRefusesAnAlreadyBoundAccount(): void {}
	public function testDiscoverRefusesAnExcludedAccount(): void {}
	public function testDiscoverReturnsTheProposalForAnExactMatch(): void {}
	public function testDiscoverReportsReviewAsNotSelfServiceable(): void {}
	public function testDiscoverReportsAmbiguousConflictAndNoneTheSameWay(): void {}

	public function testConfirmReResolvesAndIgnoresAnySuppliedPersonId(): void {
		// Post a personId; assert bind() received the resolver's id, not it.
	}
	public function testConfirmBindsOnlyExact(): void {}
	public function testConfirmRefusesAnAlreadyBoundAccount(): void {}
	public function testConfirmRecordsMethodDiscoveryAndTheSourceAttribute(): void {}
```

Follow the construction style of `tests/Unit/Service/Binding/SuggestionServiceTest.php`.

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/DiscoveryServiceTest.php`
Expected: FAIL — `DiscoveryService` does not exist.

- [ ] **Step 3: Write the service**

`DiscoveryService` takes the `IUser` and returns a result object or array describing one of: `disabled`, `already_bound`, `excluded`, `proposal` (with `itopName` and `personId`), or `not_self_serviceable` (carrying the tier so the UI can word its message).

**It must never return a personId for a non-`exact` tier.** A `review` proposal leaking the matched person's name to a user who may not be that person is an information disclosure; return the tier and nothing else.

`confirm()` re-runs `resolve()`, binds only on `exact`, uses `IdentityBindingService::METHOD_DISCOVERY` and passes `BindingConfig::getMatchSource()` as the source attribute so the audit line records what was matched.

- [ ] **Step 4: Run the tests**

Expected: PASS, output pristine.

- [ ] **Step 5: Write the controller**

Create `lib/Controller/DiscoveryController.php` — a **new** controller, because `BindingController` is admin-only by design and says so at lines 30-32.

```php
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	public function discover(): DataResponse { … }

	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	public function confirm(): DataResponse { … }
```

**Neither method takes a single parameter.** That is security property 1, enforced by the signature rather than by validation. Use PHP attributes, not PHPDoc annotations — 1.5.0 converted this codebase to attributes and the annotations now log a deprecation per request.

Routes:

```php
		['name' => 'discovery#discover', 'url' => '/binding/discover', 'verb' => 'POST'],
		['name' => 'discovery#confirm', 'url' => '/binding/discover/confirm', 'verb' => 'POST'],
```

- [ ] **Step 6: Verify against ground truth**

```bash
./vendor/bin/phpunit
php -l lib/Controller/DiscoveryController.php lib/Service/Binding/DiscoveryService.php
```

Then, **as a non-admin user** — this is the whole point and admin-only testing proves nothing. The dev instance has `pablo`, `test1`, `test2`. Report which account you used and how you authenticated as them.

With discovery **disabled** (the default), `POST /binding/discover` must refuse. Then enable it:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:set integration_itop binding_discovery_enabled --value='1'
```

Expected per account: `pablo` → a proposal naming Pablo Picasso; `test1` → not self-serviceable, tier `review`, **no person name or id in the response**; `test2` → not self-serviceable, tier `none`.

Confirm for `pablo`, then check ground truth:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop person_id
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting pablo integration_itop binding_method
```

Expected `2` and `discovery`. Then confirm a second time: it must refuse as already bound.

**Restore the fixture** — delete `person_id`, `user_id`, `binding_method`, `binding_at` for `pablo`, and set `binding_discovery_enabled` back to `'0'`.

- [ ] **Step 7: Commit**

```bash
git add lib/Controller/DiscoveryController.php lib/Service/Binding/DiscoveryService.php appinfo/routes.php
git commit -m "Add self-service discovery endpoints

A user can link their own account without an administrator and without
an iTop personal token, which portal-only users cannot obtain.

Neither endpoint takes a parameter: the source value is derived from the
signed-in user server-side, so there is nothing to forge and no way to
probe iTop for which addresses exist. Confirmation re-resolves rather
than trusting what the browser was shown, and binds only on an exact
match -- a user confirming a name mismatch about themselves is not a
meaningful check.

A non-exact result returns its tier and nothing else: naming the matched
person to a user who may not be that person would be a disclosure.

Lives in its own controller because BindingController is admin-only by
design and says so."
```

---

## Task 3: The personal settings UI

**Files:**
- Modify: `templates/personalSettings.php`
- Modify: `src/personal-settings.js`
- Modify: `lib/Settings/Personal.php`
- Modify: `css/personal-settings.css` *(if one exists; otherwise the existing personal stylesheet)*

- [ ] **Step 1: Pass the state to the template**

Add to `Personal::getForm()`: `binding_token_enabled`, `binding_discovery_enabled`, and `user_facing_name` if not already present.

- [ ] **Step 2: Show only what is available**

In `templates/personalSettings.php`, around the existing personal-token field (line ~86):

- Token binding **disabled** → hide the token field entirely rather than letting a user paste a token into a form that will refuse it.
- Discovery **enabled** → a **"Discover me in {instance}"** button.
- **Neither** enabled → a short line saying an administrator must link the account, with no dead controls.

Both methods are offered when both are enabled. The app cannot know in advance whether a given unbound user can obtain a token, because portal-vs-backend status derives from `user_id`, which only exists *after* binding — so the failure is made actionable rather than predicted.

- [ ] **Step 3: Wire the two-step flow**

In `src/personal-settings.js`: the button calls `POST /binding/discover`, then shows what came back.

- `proposal` → show the iTop name and ask for confirmation. Confirming calls `POST /binding/discover/confirm`, then reloads so the page shows the bound state.
- `not_self_serviceable` → say the account could not be linked automatically and to ask an administrator. **Word it by tier** — `review` is "a person was found but the names differ", `ambiguous` is "more than one person matched", `none` is "no match". Do **not** show a name or id, the endpoint does not return one.
- `already_bound`, `excluded`, `disabled` → their own plain messages.

Add the portal guidance the spec calls for: when a user cannot obtain a personal token, point them at discovery if it is enabled, otherwise at their administrator, and mention the iTop Portal Personal Tokens extension as an administrator-side remedy.

- [ ] **Step 4: Build and verify as a real user**

```bash
npm run lint && npm run build
```

Verify **signed in as a non-admin**, with discovery enabled, that the button appears, the proposal shows, confirming binds, and the bound state renders. Then verify with discovery disabled that the button is absent. Restore the fixture and the toggle afterwards. Report which account you used.

- [ ] **Step 5: Commit**

```bash
git add templates/personalSettings.php src/personal-settings.js lib/Settings/Personal.php
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Offer self-service linking in personal settings

Shows only the methods the administrator has enabled, so there are no
controls that refuse when used. A result that cannot be linked
automatically says why in the terms of its tier and points at an
administrator, without naming the person that was matched.

Portal-only users, who cannot obtain an iTop personal token at all, are
now told what to do instead rather than being left at a token field that
can never work for them."
```

---

## Task 4: Documentation

**Files:** `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Changelog**

This is a new feature after 1.5.0 was closed. Open a fresh `## [Unreleased]` section above `## [1.5.0]`, with an `### Added` block describing self-service discovery: what it does, that it is **off by default**, that it binds only on an exact match, and that it never re-binds an already-linked account.

- [ ] **Step 2: README**

Extend the identity-binding bullet to mention the third binding route and its toggle, keeping the existing tone.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document self-service discovery"
```

---

## Task 5: Translate every untranslated string on this branch

The app ships `de`, `de_DE`, `fr` and `en`. **128 source strings are missing from `de.json`** — most of them added by this branch: the whole association table, the scope and person pickers, the action bar, and every error toast. Shipping as-is means the newest screen is English-only for German and French users. Phase 4 adds roughly twenty more.

**Files:**
- Modify: `l10n/de.json`, `l10n/de.js`, `l10n/de_DE.json`, `l10n/de_DE.js`, `l10n/fr.json`, `l10n/fr.js`, `l10n/en.json`, `l10n/en.js`

### The conventions this app already follows — verified, not assumed

- **`de` is informal and `de_DE` is formal.** 46 existing entries differ on exactly this: `"Ein neues Ticket wurde dir zugewiesen"` (de) against `"Ein neues Ticket wurde Ihnen zugewiesen"` (de_DE); `"Klicke auf …"` against `"Klicken Sie auf …"`. Strings with no address form are identical in both files. Follow that: translate once, then produce the `de_DE` variant only where an address form appears.
- **`fr`** uses the usual formal *vous*.
- **`en.json` is near-identity** — 498 of its 510 entries map a string to itself. It exists so English is a complete catalogue; add new strings there mapping to themselves unless a different en form is genuinely needed.
- Each locale has a **`.json` and a `.js`** carrying the same catalogue (`OC.L10N.register(...)`). **Both must be updated and kept in sync** — a string present in one and not the other is a silent gap.
- `pluralForm` headers already exist (`nplurals=2; plural=(n != 1);` for de). There are currently **no plural entries at all**; this branch introduced `translatePlural`, so its entries must be **arrays** of the right length for each locale, not strings.

- [ ] **Step 1: Produce the authoritative list of missing strings**

Extract every `t('integration_itop', '…')`, `n('integration_itop', '…', '…', …)` and `$l->t('…')` literal from `src/`, `templates/` and `lib/`, and diff against each locale's catalogue. Do not hand-collect — the list is ~150 strings and anything missed ships untranslated.

Report the count per locale before you start translating.

- [ ] **Step 2: Translate**

Terminology to keep consistent, because these recur across the screen and drift is worse than an imperfect single choice:

| English | German | French |
|---|---|---|
| binding / to link | Verknüpfung / verknüpfen | liaison / lier |
| match (noun) | Übereinstimmung | correspondance |
| to match | zuordnen | faire correspondre |
| account | Konto | compte |
| person (iTop) | Person | personne |
| excluded | ausgeschlossen | exclu |
| suggestion | Vorschlag | suggestion |

Leave product and protocol nouns untranslated: `iTop`, `Nextcloud`, `Team`, `occ`, `REST`, `Person` as an iTop class name, and the instance's own display name.

Preserve every placeholder exactly — `{count}`, `{uid}`, `{name}`, `{msg}`, `%s`. A dropped or renamed placeholder is a runtime defect, not a typo.

**Flag rather than guess.** If a string's meaning is ambiguous out of context, or a term has no good equivalent, translate it as best you can and list it in your report under "please check" — the user is a native German speaker and will review the German.

- [ ] **Step 3: Keep `.js` and `.json` in sync**

Regenerate or edit both per locale so the catalogues match exactly. Verify programmatically that every locale's `.js` and `.json` contain the same key set — do not eyeball it.

- [ ] **Step 4: Verify**

```bash
php -l l10n/de.js 2>/dev/null || true
node -e "require('./l10n/de.js')" 2>/dev/null || true
./vendor/bin/phpunit
npm run lint && npm run build
```

Then check programmatically that:
- every source string now resolves in all four locales,
- every plural entry is an array of the locale's `nplurals` length,
- every placeholder present in the English string is present in each translation,
- `de` and `de_DE` differ **only** where an address form occurs.

Report the before/after missing counts per locale.

- [ ] **Step 5: Commit**

```bash
git add l10n/
git commit -m "Translate the identity binding UI into de, de_DE and fr

128 strings were missing from the German catalogue alone -- the whole
association table, both pickers, the action bar and every error toast --
so the newest screen rendered in English for every non-English user.

de is informal and de_DE formal, matching the 46 entries that already
differ on exactly that. Plural entries are arrays per locale rather than
the English (s) idiom, which no other language forms that way."
```
