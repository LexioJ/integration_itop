# Request-Access Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user cannot link their Nextcloud account to iTop themselves, give them a way to act — a link straight to the right request form in the iTop portal, configured by the administrator.

**Architecture:** Two optional app-config values holding an iTop `ServiceSubcategory` id each, one for an Incident and one for a User Request, because the two typically carry different SLAs. The URLs are built once server-side from the instance URL already configured, and surfaced everywhere the user is currently told to "ask an administrator".

**Tech Stack:** PHP config and settings providers, a server-rendered PHP template, vanilla JS in `src/personal-settings.js`. Nextcloud 32–35.

## The URL shape

```
{admin_instance_url}/pages/exec.php/object/view/ServiceSubcategory/{id}?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal
```

Only `{id}` varies per installation. The base is the instance URL the administrator already configured, and `templates/personalSettings.php:103` already builds a portal link from it the same way — follow that precedent rather than inventing a second base.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. The OCP stubs in `vendor/nextcloud/ocp/` are the authority.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` (`'integration_itop'`) — never hard-code the string in PHP.
- **No new npm dependencies.** `src/personal-settings.js` stays vanilla.
- Every user-facing string through `$l->t()` in PHP or `t('integration_itop', …)` in JS; counts through `translatePlural` as `n`. **No English-only plural idioms.**
- **Every new string must be added to all four locales**, in both the `.json` and the `.js`: `de` (informal "du"), `de_DE` (formal "Sie"), `fr` (formal *vous*), `en` (maps to itself). Plural entries are keyed `_<singular>_::_<plural>_` — this was a real bug earlier on this branch. Verify `.js`/`.json` key parity programmatically.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored.
- `js/` is gitignored but its built `.mjs` bundles **are tracked**. After `npm run build`: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- `no-unused-vars` is an eslint **error**. Baseline is **0 errors, 14 warnings**.
- PHP suite is `./vendor/bin/phpunit`, currently **181 tests / 405 assertions green**.

## Security — the ids reach a URL

An administrator is trusted, but these values are interpolated into a URL that users are invited to click, so sloppiness here turns a settings field into a redirect gadget.

- **Store only digits.** Validate server-side on write: empty, or `^[0-9]+$`. Reject anything else with a 400 rather than storing it.
- **Validate again on read** before building a URL. A value written by an older build, by `occ`, or straight into the database must not be trusted.
- Escape on output like every other interpolated value.
- Links open in a new tab, so they carry `rel="noopener noreferrer"`.

## Design decisions already made — do not re-litigate

- **Do not gate the links on the ticket-system type.** `ItopAPIService::getEffectiveTicketSystemType()` exists, but it takes a user id and may probe the iTop API — which cannot work for the unbound users this feature exists for. Gate on whether an id is configured: in simple-ticketing mode the administrator simply leaves the Incident id empty. That respects their intent and needs no probe.
- **Each id is independent.** One, both, or neither may be set. Show exactly the links that are configured. The administrator decides what each one means — the app does not infer it.
- **The same id in both fields is a supported, expected configuration**, for instances where one form covers both cases. When the two ids are identical, render **one** link with a neutral label (e.g. *"Request access"*) rather than two links to the same form, which reads as a bug. When they differ, render both with their distinct labels.
- **Both links appear wherever the user is told to ask an administrator** — not only when self-service is switched off entirely. Those dead ends are the whole point.

### Where "ask an administrator" currently appears

1. `templates/personalSettings.php` — the static message when **both** self-service toggles are off.
2. `DiscoveryController::notSelfServiceableMessage()` — the four tier messages (`review`, `ambiguous`, `conflict`, `none`), rendered by `src/personal-settings.js`.
3. The `excluded` refusal.

All three need the links. `already_bound` does **not** — that user has a working link already.

### How to verify UI behaviour

Synthetic DOM events do not reliably reach this page's listeners in this project, and a rendered control is not a working one — a guard that returned early once left a button that rendered fine and did nothing. **Click things.** Trust the network request issued and the state read back with `occ`.

### Dev environment notes

- The dev VM symlinks this worktree; `npm run build` output is live at `https://nextcloud-dev.orb.local`.
- Nextcloud does not change its CSS/JS URLs when only contents change, so the browser serves stale assets. Re-fetch with a cache-buster before believing a result.
- PHP opcache serves stale copies of files that changed size; restart `php8.3-fpm` if `php -l` disagrees with the server.
- **Fixture:** `nextadmin` bound (person 16, `token`); `pablo` bound (person 2, `admin`) — **the user's own binding, leave it alone**; `test1`, `test2` unbound; both self-service toggles unset (token on, discovery off).

---

## Task 1: The two configuration values

**Files:**
- Modify: `lib/Service/Binding/BindingConfig.php`
- Modify: `lib/Controller/BindingController.php`
- Modify: `lib/Settings/Admin.php`
- Modify: `templates/adminSettings.php`
- Modify: `src/admin-settings.js`
- Test: `tests/Unit/Service/Binding/BindingConfigTest.php` *(local only)*

**Interfaces produced:**
- `BindingConfig::getRequestIncidentId(): string` and `getRequestUserRequestId(): string` — `''` when unset or invalid
- `BindingConfig::setRequestCategoryIds(string $incidentId, string $userRequestId): void`
- Both ids carried on the existing `binding#setSelfService` route

- [ ] **Step 1: Write the failing tests**

```php
	public function testRequestIdsDefaultToEmpty(): void {
		$config = $this->makeConfig([]);
		$this->assertSame('', $config->getRequestIncidentId());
		$this->assertSame('', $config->getRequestUserRequestId());
	}

	public function testRequestIdsReadStoredDigits(): void {
		$config = $this->makeConfig([
			'binding_request_incident_id' => '137',
			'binding_request_userrequest_id' => '42',
		]);
		$this->assertSame('137', $config->getRequestIncidentId());
		$this->assertSame('42', $config->getRequestUserRequestId());
	}

	public function testANonNumericStoredValueIsIgnored(): void {
		// These are interpolated into a URL users are invited to click, so a
		// value that arrived from an older build, from occ, or straight from
		// the database must not be trusted on read either.
		$config = $this->makeConfig(['binding_request_incident_id' => '../../evil']);
		$this->assertSame('', $config->getRequestIncidentId());
	}

	public function testSetRejectsNonNumeric(): void {
		// Assert the write path refuses rather than silently storing.
	}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/BindingConfigTest.php`
Expected: FAIL — the methods do not exist.

- [ ] **Step 3: Add the accessors**

In `BindingConfig`, add the two getters and the setter. Both getters return `''` unless the stored value matches `^[0-9]+$`. Store with `setValueString`, consistent with every other key here.

Put the reason in a comment: these values end up in a URL shown to users, so an invalid one is dropped rather than rendered.

- [ ] **Step 4: Accept them on the existing route**

Extend `BindingController::setSelfService()` to take the two ids alongside the toggles. Reject a non-empty value that is not all digits with `Http::STATUS_BAD_REQUEST` and a message naming which field was wrong — an administrator who pastes a whole URL should be told, not silently ignored.

- [ ] **Step 5: Expose them in the admin UI**

Add the two fields to the **Self-service account linking** subsection of `templates/adminSettings.php`, beside the toggles they relate to, and send them from the existing save handler in `src/admin-settings.js`.

Label them for what they do, naming the iTop object type so an administrator knows what to look up — for example *"ServiceSubcategory ID for 'I have a problem' (Incident)"* and *"… for 'I need assistance' (User Request)"*. Add a hint that both are optional, that leaving one empty hides that link, and that **entering the same id in both is fine** when one form covers both cases — the user then sees a single link.

- [ ] **Step 6: Verify**

```bash
./vendor/bin/phpunit
php -l lib/Service/Binding/BindingConfig.php lib/Controller/BindingController.php lib/Settings/Admin.php templates/adminSettings.php
npm run lint && npm run build
```

- [ ] **Step 7: Commit**

```bash
git add lib/Service/Binding/BindingConfig.php lib/Controller/BindingController.php lib/Settings/Admin.php templates/adminSettings.php src/admin-settings.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Let an administrator point users at the right request form

Two optional ServiceSubcategory ids, one for an incident and one for a
user request, because the two usually carry different SLAs.

Digits only, checked on write and again on read: these end up in a URL
that users are invited to click, and a value could have arrived from an
older build or straight from occ. An invalid one is dropped rather than
rendered."
```

---

## Task 2: Show the links where the user is stuck

**Files:**
- Modify: `lib/Settings/Personal.php`
- Modify: `templates/personalSettings.php`
- Modify: `src/personal-settings.js`
- Modify: `css/personal-settings.css`

**Interfaces consumed:** the two getters from Task 1.

- [ ] **Step 1: Build the URLs once, server-side**

In `Personal::getForm()`, build each configured id into its full URL and expose them as template variables (empty string when unset). Build them in **one** place — the template and the JS must not each re-derive the URL pattern.

Follow the base-URL precedent at `templates/personalSettings.php:103`.

- [ ] **Step 2: Render them in the static case**

In `templates/personalSettings.php`, where the message already appears when both self-service toggles are off, add whichever links are configured. Each opens in a new tab and carries `rel="noopener noreferrer"`.

Wording should say what the user gets, not what the system is doing — e.g. *"Report a problem"* and *"Request assistance"*, under a short line explaining that an administrator will link the account. When both ids are identical, use the single neutral label instead.

- [ ] **Step 3: Render them after a failed discovery**

In `src/personal-settings.js`, append the same links to the result area for every outcome that ends at "ask an administrator": the four `not_self_serviceable` tiers and `excluded`. **Not** `already_bound`.

Read the URLs from the template-provided values; do not rebuild them. Escape anything interpolated.

- [ ] **Step 4: Build and verify by clicking**

```bash
npm run lint && npm run build
```

Verify as a signed-in user, with real clicks, that:

1. With neither id configured, nothing changes anywhere — no empty link, no stray separator.
2. With only one id configured, exactly that one link appears.
3. With both configured **and different**, both appear with their distinct labels, in the static case **and** after a discovery that cannot link.
3b. With both configured **to the same id**, exactly **one** neutral link appears — not two identical ones.
4. Each link's `href` is exactly the documented pattern with the configured id, and it opens in a new tab.
5. `already_bound` shows **no** links.

Report the actual `href` values you observed. Restore the fixture and the config afterwards.

- [ ] **Step 5: Commit**

```bash
git add lib/Settings/Personal.php templates/personalSettings.php src/personal-settings.js css/personal-settings.css
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Offer a request-access link wherever linking is refused

Every path that ends at 'ask an administrator' now offers one, if the
administrator configured it: both self-service methods disabled, all four
non-self-serviceable tiers, and an excluded account.

An already-linked account gets none -- it has nothing to request."
```

---

## Task 3: Translations and documentation

**Files:**
- Modify: `l10n/de.json`, `l10n/de.js`, `l10n/de_DE.json`, `l10n/de_DE.js`, `l10n/fr.json`, `l10n/fr.js`, `l10n/en.json`, `l10n/en.js`
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Translate every new string**

Extract the new literals programmatically rather than by hand, and add them to all four locales in both file formats. `de` informal, `de_DE` formal, `fr` formal *vous*, `en` identity.

Terminology already established on this branch: link → *Verknüpfung / verknüpfen*, account → *Konto*, match → *Übereinstimmung*; French *liaison / lier*, *compte*, *correspondance*. Leave `iTop`, `Nextcloud`, `ServiceSubcategory` and the instance display name untranslated.

Assert afterwards that every locale's `.js` and `.json` have identical key sets and that each new string resolves in all four.

- [ ] **Step 2: Document**

Add a bullet to the `## [Unreleased]` → `### Added` section of `CHANGELOG.md`: what the two ids are for, that both are optional, that the link appears only where a user cannot link their own account, and that leaving one empty hides that link.

Add the two `occ config:app:set` lines to the README block that already lists the binding keys.

- [ ] **Step 3: Commit**

```bash
git add l10n/ CHANGELOG.md README.md
git commit -m "Translate and document the request-access links"
```
