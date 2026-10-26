# Admin Settings Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorder the iTop admin settings page so the things an admin uses live at the top and the connection setup sits at the bottom, hide everything but connection setup until the app is configured, restyle the two identity-binding sections to match the rest of the page, and add chip navigation between sections.

**Architecture:** All changes are confined to the server-rendered template, its stylesheet, one small JS behaviour for smooth scrolling, and one new template variable computed in `Admin::getForm()`. No new endpoints, no new bundles.

**Tech Stack:** Server-rendered PHP template, plain CSS, vanilla JS. Nextcloud 32–35.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32.
- **No new npm dependencies.** Vue is permitted only in `src/views/BindingScopePicker.vue` — do not introduce it anywhere else on this page.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` (`'integration_itop'`) — never hard-code the string in PHP.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored.
- The `js/` directory is gitignored, but its built `.mjs` bundles **are tracked**. After `npm run build`, force-add them: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- `no-unused-vars` is an eslint **error**. Expect 0 errors (14 pre-existing warnings are fine).
- Every user-facing string goes through `$l->t()` in PHP or `t('integration_itop', …)` in JS. No bare literals.

### Decisions already made — do not re-litigate

- **"Unconfigured" means the server URL is empty *or* the application token is absent.** Both are required for any iTop call to succeed, so a half-filled form is still unconfigured.
- **When unconfigured, the page shows the header and Connection Configuration only** — including hiding Current Status, which would otherwise just report "not connected" next to an empty form.
- Chip navigation lists only the sections actually rendered, so in the unconfigured state there are no chips.

### How to verify UI behaviour — read this before testing

Synthetic DOM events do **not** reliably reach listeners registered by the page's own bundle in this project. Trust the network request actually issued and state read back with `occ`; trust real interaction. Do not conclude a handler is broken because a scripted `.click()` produced nothing — if you cannot verify a click path, say so plainly rather than asserting either outcome.

### Dev environment notes

- The dev VM symlinks this worktree, so `npm run build` output is live at `https://nextcloud-dev.orb.local`.
- Nextcloud's CSS and JS URLs do not change when only file contents change, so the browser can serve a **stale** stylesheet or bundle after a rebuild. Hard-reload (`Cmd+Shift+R`) before concluding anything is wrong.
- PHP opcache on that VM can serve a stale copy of a template that changed size, which has already produced one bogus "syntax error" 500 in this project. If `php -l` disagrees with the server, restart `php8.3-fpm` rather than editing code.
- Current dev data: `nextadmin` is bound (`person_id=16`, method `token`); `pablo`, `test1`, `test2` are unbound; the exclusion list is empty; the instance **is** configured.

---

## Current structure

`templates/adminSettings.php` wraps everything in `#itop_prefs.section > .itop-settings-wrapper`, which contains `.itop-admin-header` followed by seven `<div class="settings-section">` cards. Each card opens with:

```php
<div class="section-header">
	<h3><?php p($l->t('📊 Current Status')); ?></h3>
	<p class="section-description">…</p>
</div>
```

The two identity-binding blocks are the odd ones out: `#itop-binding-rule` and `#itop-binding-associations` sit **outside** `.itop-settings-wrapper` and use Nextcloud's generic `.section` with an `<h2>` and `.settings-hint`, which is why they do not match.

**Target order**, top to bottom:

| # | Section | id |
|---|---|---|
| 1 | 📊 Current Status | `itop-section-status` |
| 2 | 🔗 Identity binding | `itop-binding-rule` |
| 3 | 👥 User associations | `itop-binding-associations` |
| 4 | 🎫 Ticket System Type | `itop-section-tickets` |
| 5 | 🔔 Notification Configuration | `itop-section-notifications` |
| 6 | ⚡ Cache & Performance Settings | `itop-section-cache` |
| 7 | 🎯 CI Class Configuration | `itop-section-ci-classes` |
| 8 | 🔧 Custom CI Classes | `itop-section-custom-ci` |
| 9 | 📡 Connection Configuration | `itop-section-connection` |

---

## Task 1: Configured flag, section ids, and the reorder

**Files:**
- Modify: `lib/Settings/Admin.php`
- Modify: `templates/adminSettings.php`

**Interfaces:**
- Produces: template variable `$_['is_configured']` (bool), and the nine section `id`s in the table above, which Tasks 2 and 3 depend on.

- [ ] **Step 1: Compute the flag**

In `lib/Settings/Admin.php`, in the parameters array returned by `getForm()`, add immediately after the `'has_application_token' => $hasApplicationToken,` line:

```php
			// Both are required before any iTop call can succeed, so a
			// half-filled form still counts as unconfigured: the page hides
			// everything except the connection form until both are present.
			'is_configured' => $adminInstanceUrl !== '' && $hasApplicationToken,
```

- [ ] **Step 2: Give every section an id**

In `templates/adminSettings.php`, add the `id` from the table above to each of the seven existing `<div class="settings-section">` opening tags, e.g.:

```php
		<div class="settings-section" id="itop-section-status">
```

Match each id to its section by the emoji heading it contains. Do not change any heading text in this step.

- [ ] **Step 3: Reorder**

Move the whole `📡 Connection Configuration` card — its `<div class="settings-section" id="itop-section-connection">` through its matching closing `</div>`, including the nested "User Permission Requirements" block — so it becomes the **last** card inside `.itop-settings-wrapper`, after `🔧 Custom CI Classes`.

This is a move, not a rewrite: the markup inside must be byte-identical afterwards. Verify with `git diff --stat` that the line count of the file is unchanged apart from the ids added in Step 2, and verify the nesting with `php -l`.

- [ ] **Step 4: Gate the page on the flag**

Wrap the sections so that when `$_['is_configured']` is false only the header and the connection card render. Put the connection card outside the conditional so it renders in both states, and wrap the other **eight** sections:

```php
		<?php if ($_['is_configured']) { ?>
			… the eight configured-only sections …
		<?php } ?>
```

Note that Identity binding and User associations are not yet inside the wrapper at this point — Task 2 moves them. For now, place the conditional so that the seven existing cards other than Connection Configuration are inside it, and leave the two binding blocks where they are.

- [ ] **Step 5: Verify**

```bash
php -l templates/adminSettings.php
php -l lib/Settings/Admin.php
./vendor/bin/phpunit
```

Expected: no syntax errors; suite green (125 tests).

Then load `https://nextcloud-dev.orb.local/index.php/settings/admin/integration_itop` and confirm the configured instance still shows every section, with Connection Configuration now **last**. Report the rendered section order you actually observed.

To verify the unconfigured state **without destroying the working dev config**, read the current values first, then clear and restore:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:get integration_itop admin_instance_url
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:get integration_itop admin_application_token
```

Save both values. Then clear the URL only, reload, confirm **only** the header and Connection Configuration render, and put the value straight back with `occ config:app:set integration_itop admin_instance_url --value='<the saved value>'`. Confirm the page returns to normal afterwards. If the token key has a different name, find it in `Admin.php` rather than guessing.

- [ ] **Step 6: Commit**

```bash
git add lib/Settings/Admin.php templates/adminSettings.php
git commit -m "Put connection setup last and hide the rest until configured

An admin configures the connection once and then never touches it, so it
belongs at the bottom; the sections they use daily move up.

Unconfigured means no URL or no application token, because both are
required before any iTop call succeeds. In that state the page shows
only the connection form — the other sections would all report empty or
disconnected state next to a form that has not been filled in."
```

---

## Task 2: Restyle the identity binding sections as cards

**Files:**
- Modify: `templates/adminSettings.php`
- Modify: `css/admin-binding.css`

**Interfaces:**
- Consumes: the `is_configured` conditional and section ids from Task 1.
- Produces: `#itop-binding-rule` and `#itop-binding-associations` as `.settings-section` cards inside `.itop-settings-wrapper`, in positions 2 and 3.

- [ ] **Step 1: Move both blocks inside the wrapper**

Move `#itop-binding-rule` and `#itop-binding-associations` from their current position outside `.itop-settings-wrapper` to **immediately after** the `📊 Current Status` card and **inside** the `$_['is_configured']` conditional from Task 1, with Identity binding first and User associations second.

- [ ] **Step 2: Convert both to the page's card markup**

Change each block's wrapper from `class="section"` to `class="settings-section"`, and replace its `<h2>` + `<p class="settings-hint">` with the `.section-header` structure the other seven cards use. For Identity binding:

```php
		<div class="settings-section" id="itop-binding-rule">
			<div class="section-header">
				<h3><?php p($l->t('🔗 Identity binding')); ?></h3>
				<p class="section-description"><?php p($l->t('How Nextcloud accounts are matched to people in %s. Used by admin-side binding and by the occ itop:bind command.', [$_['user_facing_name']])); ?></p>
			</div>
```

and for User associations:

```php
		<div class="settings-section" id="itop-binding-associations">
			<div class="section-header">
				<h3><?php p($l->t('👥 User associations')); ?></h3>
				<p class="section-description"><?php p($l->t('Which Nextcloud accounts are linked to a person in %s. Accounts excluded here are skipped by matching and by the occ itop:bind command.', [$_['user_facing_name']])); ?></p>
			</div>
```

Keep every inner element — the selects, the save button, the mutable warning, the picker mount point, the filter buttons, the bulk bar, the table and the pager — exactly as they are, **including all existing ids and classes**. The JS looks those up by id; renaming one silently breaks the screen.

- [ ] **Step 3: Drop the styling that fought the card layout**

In `css/admin-binding.css`, remove any rule that only existed because these blocks were generic `.section` blocks rather than cards — in particular padding, margin or background declarations on `#itop-binding-rule` / `#itop-binding-associations` that now duplicate or conflict with `.settings-section`. Leave the table, badge, row, toolbar, picker and pager rules alone.

If no such rules exist, change nothing and say so in your report rather than inventing an edit.

- [ ] **Step 4: Verify**

```bash
php -l templates/adminSettings.php
npm run lint
```

Hard-reload the admin page and confirm: both sections now render as cards visually indistinguishable in padding, border, background and heading style from `🎫 Ticket System Type`; the association table still lists four accounts with avatars; the picker still mounts; the filter buttons, the save button and the mutable warning are all still present.

Report what you observed. The handler paths themselves were verified before this change — you are checking the markup move did not drop an element, not re-testing the features.

- [ ] **Step 5: Commit**

```bash
git add templates/adminSettings.php css/admin-binding.css
git commit -m "Style the identity binding sections as page cards

They were added as generic Nextcloud sections outside the settings
wrapper, so they sat apart from the other seven cards with different
padding and heading sizes. Same markup, moved inside the wrapper and
given the section-header structure the rest of the page uses."
```

---

## Task 3: Chip navigation

**Files:**
- Modify: `templates/adminSettings.php`
- Modify: `css/admin-settings.css`
- Modify: `src/admin-settings.js`

**Interfaces:**
- Consumes: the nine section ids from Tasks 1 and 2.
- Produces: `.itop-nav-chips` in the header and one `.itop-to-top` chip per card.

- [ ] **Step 1: Add the chip bar to the header**

In `templates/adminSettings.php`, immediately after the closing `</div>` of `.itop-admin-header` and inside the `$_['is_configured']` conditional, add:

```php
			<nav class="itop-nav-chips" aria-label="<?php p($l->t('Jump to a settings section')); ?>">
				<a class="itop-chip" href="#itop-section-status"><?php p($l->t('📊 Status')); ?></a>
				<a class="itop-chip" href="#itop-binding-rule"><?php p($l->t('🔗 Identity binding')); ?></a>
				<a class="itop-chip" href="#itop-binding-associations"><?php p($l->t('👥 User associations')); ?></a>
				<a class="itop-chip" href="#itop-section-tickets"><?php p($l->t('🎫 Tickets')); ?></a>
				<a class="itop-chip" href="#itop-section-notifications"><?php p($l->t('🔔 Notifications')); ?></a>
				<a class="itop-chip" href="#itop-section-cache"><?php p($l->t('⚡ Cache')); ?></a>
				<a class="itop-chip" href="#itop-section-ci-classes"><?php p($l->t('🎯 CI classes')); ?></a>
				<a class="itop-chip" href="#itop-section-custom-ci"><?php p($l->t('🔧 Custom CI')); ?></a>
				<a class="itop-chip" href="#itop-section-connection"><?php p($l->t('📡 Connection')); ?></a>
			</nav>
```

Real anchors, not buttons: they work before the bundle loads, they are keyboard reachable for free, and middle-click still does something sensible.

- [ ] **Step 2: Add a back-to-top chip to every card**

As the **last** child of each of the nine `.settings-section` cards, immediately before its closing `</div>`, add:

```php
			<div class="itop-section-foot">
				<a class="itop-chip itop-to-top" href="#itop_prefs"><?php p($l->t('↑ Back to top')); ?></a>
			</div>
```

- [ ] **Step 3: Style the chips**

In `css/admin-settings.css`, append:

```css
/* Section navigation chips */
.itop-nav-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 16px 0;
}

.itop-chip {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill, 100px);
	border: 1px solid var(--color-border, #ededed);
	background-color: var(--color-background-hover, #f5f5f5);
	color: var(--color-main-text, #222);
	font-size: 90%;
	text-decoration: none;
	white-space: nowrap;
}

.itop-chip:hover,
.itop-chip:focus {
	background-color: var(--color-primary-element-light, #e8f0fe);
	text-decoration: none;
}

/* Keeps the target heading clear of Nextcloud's sticky header when a chip
   jumps to it. */
.settings-section {
	scroll-margin-top: 60px;
}

.itop-section-foot {
	display: flex;
	justify-content: flex-end;
	margin-top: 12px;
}

.itop-to-top {
	color: var(--color-text-maxcontrast, #767676);
}
```

- [ ] **Step 4: Smooth scrolling, honouring reduced motion**

In `src/admin-settings.js`, inside the existing `DOMContentLoaded` handler, add:

```js
		// Smooth scroll for the section chips. prefers-reduced-motion is
		// honoured because a full-page jump-scroll is exactly the kind of
		// motion that triggers vestibular symptoms.
		const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
		if (!reduceMotion) {
			document.querySelectorAll('.itop-chip').forEach(function(chip) {
				chip.addEventListener('click', function(e) {
					const target = document.querySelector(chip.getAttribute('href'))
					if (!target) return
					e.preventDefault()
					target.scrollIntoView({ behavior: 'smooth', block: 'start' })
				})
			})
		}
```

If the file has no `DOMContentLoaded` handler, follow whatever initialisation pattern it already uses rather than inventing a second one.

- [ ] **Step 5: Verify**

```bash
php -l templates/adminSettings.php
npm run lint
npm run build
```

Hard-reload the admin page and confirm: nine chips render under the header; each one scrolls to its section with the heading visible and not hidden behind the sticky header; every card ends with a right-aligned "↑ Back to top" chip that returns to the top. Confirm the chip bar does not appear in the unconfigured state.

- [ ] **Step 6: Commit**

```bash
git add templates/adminSettings.php css/admin-settings.css src/admin-settings.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add chip navigation between admin settings sections

The page is long enough that finding a section meant scrolling past
several unrelated ones. Chips in the header jump to each section and
every card ends with a back-to-top chip.

Plain anchors, so they work before the bundle loads and are keyboard
reachable without extra code; smooth scrolling is skipped under
prefers-reduced-motion."
```

---

## Task 4: Documentation

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add a Changed entry**

In `CHANGELOG.md`, inside the `## [1.5.0]` section's existing `### Changed` block, add as the first bullet:

```markdown
- **Admin settings reorganised**: the sections an administrator uses day to day come first and connection setup moved to the bottom, since it is configured once. Before the connection is configured the page shows only that form. Identity binding and user associations now match the styling of the other sections, and chips under the page header jump to any section, with a back-to-top chip on each
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "Document the admin settings reorganisation"
```
