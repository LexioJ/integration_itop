# iTop Identity Binding — Phase 2b-1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give administrators a screen that shows every Nextcloud account's iTop binding state, and lets them exclude, restore and unbind accounts.

**Architecture:** A self-contained vanilla-JS table controller in its own bundle, rendering into a container in the existing admin settings template, driven entirely by the endpoints phase 2a already shipped. No new PHP: `GET /binding/users`, `POST /binding/exclude`, `POST /binding/restore` and `DELETE /binding/{uid}` already cover everything this screen does.

**Tech Stack:** Vanilla JS (ES modules via Vite), a server-rendered PHP template container, plain CSS. Nextcloud 32–35.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32.
- **No new npm dependencies.**
- **Vue is allowed for the scope picker only (Task 7), and nowhere else on this screen.** Admin settings are server-rendered PHP plus vanilla JS, and keeping them that way keeps the deferred Vue 3 / `@nextcloud/vue` 9 migration small. The one exception is the account/team/group picker: the Nextcloud component docs define pickers as Vue components (`NcSelect`, `NcChip`) with no plain-HTML equivalent, and hand-rolling a combobox means hand-rolling its keyboard and ARIA behaviour. `vue@^2.7.12` and `@nextcloud/vue@^8.26.1` are **already dependencies** of this app (used by `src/views/ReferenceItopWidget.vue`), so this adds no package. Tasks 1-5 stay vanilla; Task 7 mounts exactly one Vue component into one container. Note in the migration ticket that the admin page now has a Vue mount point.
- `declare(strict_types=1)` is **not** used anywhere in `lib/`. Do not introduce it.
- `Application::APP_ID` (`'integration_itop'`) — never hard-code the string in PHP.
- **Tests are local-only and never committed.** `tests/`, `phpunit.xml` and `docs/testing.md` are gitignored. Write and run them, but commit only source files.
- The `js/` directory is gitignored, but its built `.mjs` bundles **are tracked**. After `npm run build`, force-add them: `find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f`.
- Reuse the existing imports in a bundle rather than re-importing: `generateUrl` (`@nextcloud/router`), `getRequestToken` (`@nextcloud/auth`), `showError`/`showSuccess` (`@nextcloud/dialogs`), `translate as t` (`@nextcloud/l10n`).

### Do not grow the existing admin bundle

`src/admin-settings.js` is already **1,342 lines**, `templates/adminSettings.php` 796, `css/admin-settings.css` 1,156. This table is a self-contained feature, so it gets **its own Vite entry, its own JS file and its own CSS file**, loaded alongside the existing ones on the same page. Nextcloud permits multiple `script()` / `style()` calls per template. Do not add table code to `admin-settings.js`.

### How to verify UI behaviour — read this before testing

Synthetic DOM events do **not** reliably reach listeners registered by the page's own bundle in this project. During phase 2a a controller spent a long time chasing a non-existent "dead button" because `element.click()` dispatched from an evaluation context did nothing, while a listener attached from that *same* context fired — which made the context look self-consistent and the conclusion look sound.

So, for every UI step in this plan:

- **Trust ground truth:** the network request actually issued, and the resulting state read back with `occ` or `occ config:app:get`.
- **Trust real interaction:** a human clicking, or an automation stack known to deliver real input.
- **Do not** conclude a handler is broken because a synthetic `.click()` or `dispatchEvent` produced nothing. If you cannot verify a click path, say so plainly in your report rather than asserting either outcome.

### Dev environment notes

- The dev VM symlinks this worktree, so `npm run build` output is live immediately at `https://nextcloud-dev.orb.local`.
- Nextcloud's CSS and JS URLs do not change when only file contents change, so the browser can serve a **stale** stylesheet or bundle after a rebuild. If a style or handler seems missing, check the parsed CSSOM / loaded bundle before concluding the code is wrong. This is a dev-only artifact; real upgrades change the URL.
- The VM has previously suffered a cron pile-up that made it unresponsive. If page loads take tens of seconds, check `uptime` and the number of `cron.php` processes before blaming the code.
- Current live data: `nextadmin` is bound (`person_id=16`, `binding_method=token`); `pablo`, `test1`, `test2` are unbound; `binding_excluded_users` is empty.

### Out of scope — phase 2b-2

Match suggestions and bulk apply. This screen shows and manages **existing** state; it does not compute matches. That means **no tier column, no "Suggest matches" button, no Apply action, and no ambiguous-row picker** here. Those need `POST /binding/suggest`, `POST /binding/apply`, `POST /binding/apply-one` and `GET /binding/search-person`, none of which exist yet.

---

## API this screen consumes (all shipped in 2a, all admin-only)

`GET /apps/integration_itop/binding/users?filter=&search=&page=&pageSize=` returns:

```json
{ "total": 4, "page": 1, "pageSize": 25, "rows": [ … ] }
```

Each row:

```json
{ "uid": "pablo", "displayName": "Pablo Picasso", "email": "pablo.picasso@demo.com",
  "bound": false, "personId": null, "userId": null, "method": null,
  "boundAt": null, "excluded": false }
```

`filter` is one of `all` | `unbound` | `bound` | `excluded`; anything else falls back to `all`. `page` and `pageSize` are clamped server-side to `[1, …]` and `[1, 500]`.

`POST /binding/exclude` and `POST /binding/restore` take `{"uids": ["a","b"]}` and return `{"changed": [...], "excluded_users": [...]}`.

`DELETE /binding/{uid}` returns `200 {"uid":…,"bound":false}`, or **404** `{"message":"No such user"}` for a uid that does not exist.

## API this screen adds (Task 6, admin-only)

`GET /apps/integration_itop/binding/scopes?term=itop` returns:

```json
{ "accounts": [{ "type": "account", "id": "pablo", "label": "Pablo Picasso" }],
  "groups":   [{ "type": "group",   "id": "itop-testers", "label": "itop-testers" }],
  "teams":    [{ "type": "team",    "id": "abc123…", "label": "Helpdesk" }],
  "teamsAvailable": true }
```

An empty or whitespace-only `term` returns three empty lists (and the real `teamsAvailable`). `teamsAvailable` is `false` when the optional `circles` app is disabled, so the picker can omit the Teams section rather than show a silently empty one.

`GET /binding/users` gains two optional parameters, `scopeType` and `scopeId`. `scopeType` is one of `account` | `group` | `team`; an unrecognised value is a **400** `{"message":"Unknown scope type"}` rather than a silent fallback, because silently widening a scope an admin narrowed is the dangerous direction. A `scopeId` that resolves to no members returns `total: 0` with the empty-state message — not an error.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/admin-binding.js` *(create)* | The whole table controller: fetch, render, filters, search, paging, selection, actions; mounts the scope picker |
| `src/views/BindingScopePicker.vue` *(create, Task 7)* | The one Vue component on this screen: an `NcSelect` searching accounts / groups / teams |
| `css/admin-binding.css` *(create)* | Table styling, scope picker styling |
| `vite.config.ts` *(modify)* | Add the `admin-binding` entry |
| `templates/adminSettings.php` *(modify)* | Container element plus `script()` / `style()` calls |
| `lib/Service/Binding/BindingScopeService.php` *(create, Task 6)* | Search accounts / groups / teams; resolve a scope to its member uids |
| `lib/Service/Binding/BindingListService.php` *(modify, Task 6)* | `list()` accepts an optional uid restriction |
| `lib/Controller/BindingController.php` *(modify, Task 6)* | `getUsers()` accepts `scopeType`/`scopeId`; new `searchScopes()` endpoint |
| `appinfo/routes.php` *(modify, Task 6)* | Add `GET /binding/scopes` |
| `CHANGELOG.md`, `README.md` *(modify)* | Document the screen |

---

## Task 1: Bundle, container, and a rendered table

**Files:**
- Create: `src/admin-binding.js`
- Create: `css/admin-binding.css`
- Modify: `vite.config.ts`
- Modify: `templates/adminSettings.php`

**Interfaces:**
- Consumes: `GET /apps/integration_itop/binding/users` (shape above).
- Produces, for later tasks in this plan — all in `src/admin-binding.js`:
  - `state` — module-level object `{ filter, search, page, pageSize, total, rows, selected }` where `selected` is a `Set` of uids
  - `async function load()` — fetches per `state` and calls `render()`
  - `function render()` — repaints `#itop-binding-table` from `state`
  - `function apiCall(path, options)` — `fetch` wrapper that adds `requesttoken` and returns the parsed body, throwing on a non-OK response

- [ ] **Step 1: Add the Vite entry**

In `vite.config.ts`, add to the entry map, after the existing `'admin-settings'` line:

```ts
	'admin-binding': 'src/admin-binding.js',
```

- [ ] **Step 2: Add the container to the template**

In `templates/adminSettings.php`, immediately after the existing `style($appId, 'admin-settings');` line near the top, add:

```php
script($appId, $appId . '-admin-binding');
style($appId, 'admin-binding');
```

Then **append at the very end of the file**. The `#itop-binding-rule` section opens at line 750 and its closing `</div>` is currently the file's last line, so the new section goes after it with no other markup in between:

```php
<div id="itop-binding-associations" class="section">
	<h2><?php p($l->t('User associations')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Which Nextcloud accounts are linked to a person in %s. Accounts excluded here are skipped by matching and by the occ itop:bind command.', [$_['user_facing_name']])); ?>
	</p>

	<div class="itop-binding-toolbar">
		<input type="search" id="itop-binding-search" placeholder="<?php p($l->t('Search accounts…')); ?>">
		<span class="itop-binding-filters" id="itop-binding-filters">
			<button type="button" class="itop-binding-filter active" data-filter="all"><?php p($l->t('All')); ?></button>
			<button type="button" class="itop-binding-filter" data-filter="unbound"><?php p($l->t('Unbound')); ?></button>
			<button type="button" class="itop-binding-filter" data-filter="bound"><?php p($l->t('Bound')); ?></button>
			<button type="button" class="itop-binding-filter" data-filter="excluded"><?php p($l->t('Excluded')); ?></button>
		</span>
	</div>

	<div id="itop-binding-bulk" class="itop-binding-bulk hidden"></div>
	<div id="itop-binding-table" class="itop-binding-table"></div>
	<div id="itop-binding-pager" class="itop-binding-pager"></div>
</div>
```

- [ ] **Step 3: Write the table controller**

Create `src/admin-binding.js`:

```js
/**
 * Nextcloud - iTop Integration
 *
 * Admin: user association table.
 *
 * Lives in its own bundle rather than admin-settings.js, which is already
 * ~1,300 lines. Reads and manages existing binding state only; computing
 * matches belongs to a later phase.
 */

import { getRequestToken } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

(function() {
	'use strict'

	const state = {
		filter: 'all',
		search: '',
		page: 1,
		pageSize: 25,
		total: 0,
		rows: [],
		selected: new Set(),
	}

	/**
	 * Fetch wrapper that adds the request token and throws on a non-OK response.
	 *
	 * @param {string} path URL below the app root, e.g. '/binding/users'
	 * @param {object} options fetch options
	 * @return {Promise<object>} parsed response body
	 */
	async function apiCall(path, options = {}) {
		const response = await fetch(generateUrl('/apps/integration_itop' + path), {
			...options,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken(),
				...(options.headers || {}),
			},
		})
		const body = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error(body.message || ('HTTP ' + response.status))
		}
		return body
	}

	/**
	 * Load the current page from the server and repaint.
	 */
	async function load() {
		const query = new URLSearchParams({
			filter: state.filter,
			search: state.search,
			page: String(state.page),
			pageSize: String(state.pageSize),
		})
		try {
			const data = await apiCall('/binding/users?' + query.toString())
			state.rows = data.rows || []
			state.total = data.total || 0
			state.page = data.page || 1
			// Drop selections for rows no longer on screen, so a bulk action can
			// never hit an account the admin can no longer see.
			const visible = new Set(state.rows.map(r => r.uid))
			for (const uid of [...state.selected]) {
				if (!visible.has(uid)) state.selected.delete(uid)
			}
			render()
		} catch (e) {
			showError(t('integration_itop', 'Could not load user associations: {msg}', { msg: e.message }))
		}
	}

	/**
	 * Escape text for safe insertion into innerHTML.
	 *
	 * Display names, emails and uids are user-controlled, so they must never be
	 * interpolated raw.
	 *
	 * @param {string} value raw text
	 * @return {string} escaped text
	 */
	function esc(value) {
		const div = document.createElement('div')
		div.textContent = String(value === null || value === undefined ? '' : value)
		return div.innerHTML
	}

	/**
	 * Human-readable binding state for one row.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for the state cell
	 */
	function describeBinding(row) {
		if (row.excluded) {
			return '<span class="itop-binding-badge excluded">' + esc(t('integration_itop', 'excluded')) + '</span>'
		}
		if (!row.bound) {
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'not linked')) + '</span>'
		}
		// A pre-existing binding made before provenance was recorded has no method.
		const origin = row.method
			? '<span class="itop-binding-badge origin">' + esc(row.method) + '</span>'
			: '<span class="itop-binding-badge origin unknown" title="'
				+ esc(t('integration_itop', 'Linked before this app recorded how bindings were made'))
				+ '">' + esc(t('integration_itop', 'unknown')) + '</span>'
		return '<strong>' + esc(t('integration_itop', 'person {id}', { id: row.personId })) + '</strong> ' + origin
	}

	/**
	 * Repaint the table, bulk bar and pager from state.
	 */
	function render() {
		const table = document.getElementById('itop-binding-table')
		if (!table) return

		if (state.rows.length === 0) {
			table.innerHTML = '<p class="itop-binding-muted">'
				+ esc(t('integration_itop', 'No accounts match this filter.')) + '</p>'
		} else {
			table.innerHTML = state.rows.map(row =>
				'<div class="itop-binding-row" data-uid="' + esc(row.uid) + '">'
				+ '<label class="itop-binding-select">'
				+ '<input type="checkbox" data-select="' + esc(row.uid) + '"'
				+ (state.selected.has(row.uid) ? ' checked' : '') + '>'
				+ '</label>'
				+ '<span class="itop-binding-account"><strong>' + esc(row.displayName) + '</strong>'
				+ '<span class="itop-binding-muted">' + esc(row.uid)
				+ (row.email ? ' · ' + esc(row.email) : '') + '</span></span>'
				+ '<span class="itop-binding-state">' + describeBinding(row) + '</span>'
				+ '<span class="itop-binding-actions">'
				+ (row.bound ? '<button type="button" data-unbind="' + esc(row.uid) + '">'
					+ esc(t('integration_itop', 'Unlink')) + '</button>' : '')
				+ (row.excluded
					? '<button type="button" data-restore="' + esc(row.uid) + '">' + esc(t('integration_itop', 'Restore')) + '</button>'
					: '<button type="button" data-exclude="' + esc(row.uid) + '">' + esc(t('integration_itop', 'Exclude')) + '</button>')
				+ '</span>'
				+ '</div>'
			).join('')
		}

		renderBulkBar()
		renderPager()
	}

	/**
	 * Show the bulk action bar only when something is selected.
	 */
	function renderBulkBar() {
		const bar = document.getElementById('itop-binding-bulk')
		if (!bar) return
		if (state.selected.size === 0) {
			bar.classList.add('hidden')
			bar.innerHTML = ''
			return
		}
		bar.classList.remove('hidden')
		bar.innerHTML = '<strong>'
			+ esc(t('integration_itop', '{count} selected', { count: state.selected.size }))
			+ '</strong>'
			+ '<button type="button" id="itop-binding-bulk-exclude">' + esc(t('integration_itop', 'Exclude from matching')) + '</button>'
			+ '<button type="button" id="itop-binding-bulk-restore">' + esc(t('integration_itop', 'Restore')) + '</button>'
			+ '<button type="button" id="itop-binding-bulk-clear">' + esc(t('integration_itop', 'Clear selection')) + '</button>'
	}

	/**
	 * Render the pager, stating the true filtered total so a page is never
	 * mistaken for the whole set.
	 */
	function renderPager() {
		const pager = document.getElementById('itop-binding-pager')
		if (!pager) return
		const pages = Math.max(1, Math.ceil(state.total / state.pageSize))
		pager.innerHTML =
			'<button type="button" id="itop-binding-prev"' + (state.page <= 1 ? ' disabled' : '') + '>‹</button>'
			+ '<span>' + esc(t('integration_itop', 'Page {page} of {pages} · {total} account(s)',
				{ page: state.page, pages, total: state.total })) + '</span>'
			+ '<button type="button" id="itop-binding-next"' + (state.page >= pages ? ' disabled' : '') + '>›</button>'
	}

	document.addEventListener('DOMContentLoaded', function() {
		if (!document.getElementById('itop-binding-table')) return
		load()
	})
})()
```

- [ ] **Step 4: Style it**

Create `css/admin-binding.css`:

```css
/* Admin: user association table */
.itop-binding-toolbar {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	margin-bottom: 10px;
}

.itop-binding-filter.active {
	font-weight: bold;
	text-decoration: underline;
}

.itop-binding-bulk {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	padding: 8px;
	margin-bottom: 8px;
	border-radius: var(--border-radius, 3px);
	background-color: var(--color-primary-element-light, #e8f0fe);
}

.itop-binding-bulk.hidden {
	display: none;
}

.itop-binding-row {
	display: grid;
	grid-template-columns: 32px minmax(180px, 2fr) minmax(140px, 1fr) auto;
	gap: 8px;
	align-items: center;
	padding: 6px 4px;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.itop-binding-account {
	display: flex;
	flex-direction: column;
}

.itop-binding-muted {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 90%;
}

.itop-binding-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius, 3px);
	font-size: 85%;
	background-color: var(--color-background-dark, #ededed);
}

.itop-binding-badge.excluded {
	opacity: 0.7;
}

.itop-binding-badge.origin.unknown {
	font-style: italic;
}

.itop-binding-pager {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-top: 10px;
}
```

- [ ] **Step 5: Build and confirm the table paints**

```bash
npm run lint
npm run build
```

Expected: eslint 0 errors (14 pre-existing warnings are fine), build succeeds.

Then load `https://nextcloud-dev.orb.local/index.php/settings/admin/integration_itop` and confirm a **User associations** section lists four accounts: `nextadmin` shown as bound with a `token` badge, and `pablo`, `test1`, `test2` as "not linked". The pager should read `Page 1 of 1 · 4 account(s)`.

Check the browser console for errors and the network tab for the `GET /binding/users` request and its status. Report both. If the section does not appear, check whether the browser is serving a stale bundle before concluding the code is wrong.

- [ ] **Step 6: Commit**

```bash
git add src/admin-binding.js css/admin-binding.css vite.config.ts templates/adminSettings.php
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add the admin user-association table

Shows every Nextcloud account's iTop binding state, in its own bundle
rather than admin-settings.js, which is already ~1,300 lines.

Display names, uids and emails are escaped before insertion: they are
user-controlled and the table builds its markup as HTML.

A binding made before provenance was recorded has no method, so the
origin badge renders that case as 'unknown' with an explanatory title
rather than leaving the cell blank."
```

---

## Task 2: Filters, search and paging

**Files:**
- Modify: `src/admin-binding.js`

**Interfaces:**
- Consumes: `state`, `load()`, `render()` from Task 1.
- Produces: no new exported names; wires the toolbar and pager controls already rendered by Task 1.

- [ ] **Step 1: Wire the controls**

In `src/admin-binding.js`, add this function above the `DOMContentLoaded` listener:

```js
	/**
	 * Wire the toolbar and pager.
	 *
	 * Delegated from document, matching the pattern used elsewhere in this app:
	 * the pager and bulk bar are re-rendered on every repaint, so their buttons
	 * are replaced and a direct listener would be lost.
	 */
	function attachHandlers() {
		const search = document.getElementById('itop-binding-search')
		if (search) {
			let debounce = null
			search.addEventListener('input', function() {
				clearTimeout(debounce)
				debounce = setTimeout(() => {
					state.search = search.value.trim()
					state.page = 1
					load()
				}, 300)
			})
		}

		document.addEventListener('click', function(e) {
			const filterBtn = e.target.closest('.itop-binding-filter')
			if (filterBtn) {
				state.filter = filterBtn.dataset.filter
				state.page = 1
				document.querySelectorAll('.itop-binding-filter').forEach(b => b.classList.remove('active'))
				filterBtn.classList.add('active')
				load()
				return
			}

			if (e.target.closest('#itop-binding-prev')) {
				if (state.page > 1) { state.page -= 1; load() }
				return
			}

			if (e.target.closest('#itop-binding-next')) {
				const pages = Math.max(1, Math.ceil(state.total / state.pageSize))
				if (state.page < pages) { state.page += 1; load() }
			}
		})
	}
```

Then change the `DOMContentLoaded` listener to call it:

```js
	document.addEventListener('DOMContentLoaded', function() {
		if (!document.getElementById('itop-binding-table')) return
		attachHandlers()
		load()
	})
```

- [ ] **Step 2: Build**

```bash
npm run lint
npm run build
```

Expected: 0 eslint errors, build succeeds.

- [ ] **Step 3: Verify against ground truth**

In the browser, for each filter click, confirm the `GET /binding/users` request carries the expected `filter` parameter and that the row count matches what the API returns. On the current dev data:

| Filter | Expected |
|---|---|
| All | 4 accounts |
| Unbound | 3 (`pablo`, `test1`, `test2`) |
| Bound | 1 (`nextadmin`) |
| Excluded | 0, with the empty-state message |

Then type `pablo` in the search box and confirm the request carries `search=pablo` and one row returns. Set `pageSize` is not user-exposed; to exercise paging, temporarily append `&pageSize=2` by hand to the request URL in the network tab, or trust the server-side clamp already covered by phase 2a's unit tests — say which you did.

Report the actual request URLs and row counts you observed.

- [ ] **Step 4: Commit**

```bash
git add src/admin-binding.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Wire association table filters, search and paging

Handlers are delegated from document because the pager and bulk bar are
re-rendered on every repaint, which would discard direct listeners.

Search is debounced at 300ms so typing does not issue a request per
keystroke against an endpoint that enumerates all users."
```

---

## Task 3: Selection and bulk exclude/restore

**Files:**
- Modify: `src/admin-binding.js`

**Interfaces:**
- Consumes: `state`, `load()`, `render()`, `apiCall()` from Task 1; the delegated click handler from Task 2.
- Produces: no new exported names.

- [ ] **Step 1: Add selection and bulk actions**

In `src/admin-binding.js`, add this function above `attachHandlers()`:

```js
	/**
	 * Apply an exclusion change to a set of uids, then reload.
	 *
	 * @param {string[]} uids accounts to change
	 * @param {boolean} excluded true to exclude, false to restore
	 */
	async function setExcluded(uids, excluded) {
		if (uids.length === 0) return
		try {
			await apiCall('/binding/' + (excluded ? 'exclude' : 'restore'), {
				method: 'POST',
				body: JSON.stringify({ uids }),
			})
			showSuccess(excluded
				? t('integration_itop', '{count} account(s) excluded from matching', { count: uids.length })
				: t('integration_itop', '{count} account(s) restored to matching', { count: uids.length }))
			state.selected.clear()
			await load()
		} catch (e) {
			showError(t('integration_itop', 'Could not update exclusions: {msg}', { msg: e.message }))
			// Reload anyway: a partial change may have landed, and the table must
			// show what the server actually holds rather than what we attempted.
			await load()
		}
	}
```

Then extend the delegated click handler in `attachHandlers()` by inserting these branches **before** the `#itop-binding-prev` branch:

```js
			const selectBox = e.target.closest('input[data-select]')
			if (selectBox) {
				const uid = selectBox.dataset.select
				if (selectBox.checked) state.selected.add(uid)
				else state.selected.delete(uid)
				renderBulkBar()
				return
			}

			const rowExclude = e.target.closest('button[data-exclude]')
			if (rowExclude) { setExcluded([rowExclude.dataset.exclude], true); return }

			const rowRestore = e.target.closest('button[data-restore]')
			if (rowRestore) { setExcluded([rowRestore.dataset.restore], false); return }

			if (e.target.closest('#itop-binding-bulk-exclude')) {
				setExcluded([...state.selected], true); return
			}
			if (e.target.closest('#itop-binding-bulk-restore')) {
				setExcluded([...state.selected], false); return
			}
			if (e.target.closest('#itop-binding-bulk-clear')) {
				state.selected.clear(); render(); return
			}
```

- [ ] **Step 2: Build**

```bash
npm run lint
npm run build
```

- [ ] **Step 3: Verify against ground truth, then restore state**

In the browser, exclude `test2` using its row button. Then confirm with the authoritative source rather than the UI:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:get integration_itop binding_excluded_users
```

Expected: `["test2"]`. Confirm the row now shows an `excluded` badge and appears under the **Excluded** filter but not under **Unbound**.

Then select two accounts with the checkboxes, confirm the bulk bar appears reading `2 selected`, exclude them, and re-check the `occ` output.

**Restore state when done** — the stored exclusion list must end empty:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ config:app:delete integration_itop binding_excluded_users
```

Report the real `occ` output at each stage.

- [ ] **Step 4: Commit**

```bash
git add src/admin-binding.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add selection and bulk exclude/restore to the association table

A failed bulk change still reloads, because a partial change may have
landed and the table must show what the server holds rather than what
was attempted.

Selections are dropped for rows that leave the current page, so a bulk
action can never reach an account the admin can no longer see."
```

---

## Task 4: Per-row unlink

**Files:**
- Modify: `src/admin-binding.js`

**Interfaces:**
- Consumes: `state`, `load()`, `apiCall()` from Task 1; the delegated click handler from Task 2.
- Produces: no new exported names.

- [ ] **Step 1: Add the unlink action**

In `src/admin-binding.js`, add above `attachHandlers()`:

```js
	/**
	 * Remove the iTop link from one account.
	 *
	 * Confirmed first: unlinking is not reversible from this screen, and a
	 * token-bound user can only re-link by pasting a new personal token.
	 *
	 * @param {string} uid Nextcloud user id
	 */
	async function unbind(uid) {
		const ok = window.confirm(t('integration_itop',
			'Remove the iTop link for {uid}? They will not see their tickets until they are linked again.', { uid }))
		if (!ok) return
		try {
			await apiCall('/binding/' + encodeURIComponent(uid), { method: 'DELETE' })
			showSuccess(t('integration_itop', 'Link removed for {uid}', { uid }))
			await load()
		} catch (e) {
			showError(t('integration_itop', 'Could not remove the link: {msg}', { msg: e.message }))
			await load()
		}
	}
```

Then add this branch to the delegated click handler, beside the other row actions:

```js
			const rowUnbind = e.target.closest('button[data-unbind]')
			if (rowUnbind) { unbind(rowUnbind.dataset.unbind); return }
```

- [ ] **Step 2: Build**

```bash
npm run lint
npm run build
```

- [ ] **Step 3: Verify — without unbinding the only bound account**

`nextadmin` is the **only** bound account on the dev instance, and re-binding it needs a personal token you do not have. **Do not unlink it.**

Instead, create a disposable binding, unlink that, and confirm with ground truth:

```bash
# bind test2 to a person so there is something safe to unlink
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test2 integration_itop person_id 2
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test2 integration_itop binding_method admin
```

Reload the screen, confirm `test2` now shows as bound with an `admin` badge, click **Unlink**, accept the confirmation, then:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting test2 integration_itop person_id
```

Expected: `The setting does not exist for user "test2".`

Also confirm `nextadmin` is still bound:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ user:setting nextadmin integration_itop person_id
```

Expected: `16`.

If the `occ user:setting` write fails with *"conflict between new type (mixed) and old type (string)"*, that is a known Nextcloud behaviour documented in the 1.5.0 changelog: delete the key first (`occ user:setting test2 integration_itop person_id --delete`) and set it again.

- [ ] **Step 4: Commit**

```bash
git add src/admin-binding.js
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add per-row unlink to the association table

Confirmed before acting: unlinking is not reversible from this screen,
and a token-bound user can only re-link by pasting a new personal token."
```

---

## Task 5: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Extend the Unreleased changelog entry**

In `CHANGELOG.md`, inside the existing `## [Unreleased]` section's `### Added` block, add a second bullet beneath the existing one:

```markdown
- **User associations screen**: Admin settings → iTop now lists every Nextcloud account with its iTop binding state, filterable by all / unbound / bound / excluded and searchable by name, user ID or email. Accounts can be excluded from matching (individually or in bulk) and links can be removed. Bindings made before this app recorded provenance show their origin as "unknown" rather than blank. Computing matches is not part of this screen
```

- [ ] **Step 2: Update the README**

In `README.md`, in the bullet added for identity binding, replace the sentence that currently reads:

```
Accounts can be excluded from matching via `occ config:app:set integration_itop binding_excluded_users` or the admin API; the settings screen itself only configures the matching rule.
```

with:

```
**Admin settings → iTop → User associations** lists every Nextcloud account with its binding state, and lets accounts be excluded from matching or unlinked. The equivalent `occ` commands remain available for scripted setups:
```

Leave the `occ` code block that follows it in place.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document the user associations screen"
```

---

## Task 6: Scope search backend — accounts, groups and teams

**Files:**
- Create: `lib/Service/Binding/BindingScopeService.php`
- Modify: `lib/Service/Binding/BindingListService.php:49-79`
- Modify: `lib/Controller/BindingController.php:45-52`
- Modify: `appinfo/routes.php:29`
- Test: `tests/Unit/Service/Binding/BindingScopeServiceTest.php` *(local only, never committed)*

**Interfaces:**
- Consumes: `IUserManager`, `IGroupManager`, `IAppManager` from core; `\OCA\Circles\CirclesManager` from the optional `circles` app.
- Produces, for Task 7:
  - `BindingScopeService::SCOPE_ACCOUNT` = `'account'`, `SCOPE_GROUP` = `'group'`, `SCOPE_TEAM` = `'team'`, and `SCOPES` listing all three
  - `BindingScopeService::search(string $term): array` — `{accounts, groups, teams, teamsAvailable}`
  - `BindingScopeService::resolveUids(string $type, string $id): array` — list of uids
  - `BindingListService::list(string $filter, string $search, int $page, int $pageSize, ?array $restrictUids = null)`
  - `GET /binding/scopes?term=` and the `scopeType` / `scopeId` parameters on `GET /binding/users`

**Why a service and not a controller method:** the circles integration needs guarding, session handling and exception trapping. That does not belong in a controller, and `BindingController` is the one place a thrown `Throwable` would take the whole screen down.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Binding/BindingScopeServiceTest.php`:

```php
<?php

namespace OCA\Itop\Tests\Unit\Service\Binding;

use OCA\Itop\Service\Binding\BindingScopeService;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BindingScopeServiceTest extends TestCase {

	private function service(
		?IUserManager $userManager = null,
		?IGroupManager $groupManager = null,
		bool $circlesEnabled = false,
	): BindingScopeService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn($circlesEnabled);

		return new BindingScopeService(
			$userManager ?? $this->createMock(IUserManager::class),
			$groupManager ?? $this->createMock(IGroupManager::class),
			$appManager,
			new NullLogger(),
		);
	}

	public function testBlankTermReturnsNoSuggestions(): void {
		$result = $this->service()->search('   ');

		$this->assertSame([], $result['accounts']);
		$this->assertSame([], $result['groups']);
		$this->assertSame([], $result['teams']);
	}

	public function testTeamsUnavailableWhenCirclesDisabled(): void {
		$this->assertFalse($this->service()->search('any')['teamsAvailable']);
	}

	public function testGroupSuggestionsCarryGidAndDisplayName(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('itop-testers');
		$group->method('getDisplayName')->willReturn('iTop testers');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([$group]);

		$groups = $this->service(null, $groupManager)->search('itop')['groups'];

		$this->assertSame(
			[['type' => 'group', 'id' => 'itop-testers', 'label' => 'iTop testers']],
			$groups,
		);
	}

	public function testResolveUidsForGroupListsItsMembers(): void {
		$pablo = $this->createMock(IUser::class);
		$pablo->method('getUID')->willReturn('pablo');
		$test1 = $this->createMock(IUser::class);
		$test1->method('getUID')->willReturn('test1');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$pablo, $test1]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('itop-testers')->willReturn($group);

		$this->assertSame(
			['pablo', 'test1'],
			$this->service(null, $groupManager)->resolveUids('group', 'itop-testers'),
		);
	}

	public function testResolveUidsForMissingGroupIsEmptyNotEveryone(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$this->assertSame([], $this->service(null, $groupManager)->resolveUids('group', 'gone'));
	}

	public function testResolveUidsForUnknownAccountIsEmpty(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(false);

		$this->assertSame([], $this->service($userManager)->resolveUids('account', 'nobody'));
	}

	public function testResolveUidsForKnownAccountIsJustThatAccount(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);

		$this->assertSame(['pablo'], $this->service($userManager)->resolveUids('account', 'pablo'));
	}

	public function testTeamScopeResolvesEmptyWhenCirclesDisabled(): void {
		$this->assertSame([], $this->service()->resolveUids('team', 'whatever'));
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/BindingScopeServiceTest.php`
Expected: FAIL with `Class "OCA\Itop\Service\Binding\BindingScopeService" does not exist`.

- [ ] **Step 3: Write the service**

Create `lib/Service/Binding/BindingScopeService.php`:

```php
<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Searches the things an administrator can narrow the association table to —
 * one account, a Nextcloud group, or a Nextcloud Team — and resolves a chosen
 * scope to the account ids it covers.
 *
 * Teams come from the optional circles app. Every call into it is guarded by
 * isEnabledForAnyone() and wrapped in a try/catch: with circles absent the
 * teams source returns nothing and the picker omits that section, rather than
 * the whole association screen failing.
 */
class BindingScopeService {

	public const SCOPE_ACCOUNT = 'account';
	public const SCOPE_GROUP = 'group';
	public const SCOPE_TEAM = 'team';

	public const SCOPES = [
		self::SCOPE_ACCOUNT,
		self::SCOPE_GROUP,
		self::SCOPE_TEAM,
	];

	private const SUGGESTION_LIMIT = 10;
	private const CIRCLES_APP_ID = 'circles';

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{accounts: array, groups: array, teams: array, teamsAvailable: bool}
	 */
	public function search(string $term): array {
		$term = trim($term);
		$teamsAvailable = $this->teamsAvailable();

		if ($term === '') {
			return [
				'accounts' => [],
				'groups' => [],
				'teams' => [],
				'teamsAvailable' => $teamsAvailable,
			];
		}

		return [
			'accounts' => $this->searchAccounts($term),
			'groups' => $this->searchGroups($term),
			'teams' => $teamsAvailable ? $this->searchTeams($term) : [],
			'teamsAvailable' => $teamsAvailable,
		];
	}

	/**
	 * Account ids covered by a scope.
	 *
	 * An unknown id yields an empty list, never every account: silently
	 * widening a scope the administrator narrowed is the dangerous direction,
	 * because the next action on this screen may be a bulk exclude.
	 *
	 * @return array list of uids
	 */
	public function resolveUids(string $type, string $id): array {
		if ($id === '') {
			return [];
		}

		return match ($type) {
			self::SCOPE_ACCOUNT => $this->userManager->userExists($id) ? [$id] : [],
			self::SCOPE_GROUP => $this->groupUids($id),
			self::SCOPE_TEAM => $this->teamUids($id),
			default => [],
		};
	}

	public function isKnownScope(string $type): bool {
		return in_array($type, self::SCOPES, true);
	}

	public function teamsAvailable(): bool {
		return $this->appManager->isEnabledForAnyone(self::CIRCLES_APP_ID);
	}

	private function searchAccounts(string $term): array {
		// Search both uid and display name: an admin looking for "test1" is
		// typing the uid, one looking for "Picasso" is typing the name.
		$found = array_merge(
			$this->userManager->search($term, self::SUGGESTION_LIMIT),
			$this->userManager->searchDisplayName($term, self::SUGGESTION_LIMIT),
		);

		$suggestions = [];
		foreach ($found as $user) {
			$uid = $user->getUID();
			$suggestions[$uid] = [
				'type' => self::SCOPE_ACCOUNT,
				'id' => $uid,
				'label' => $user->getDisplayName(),
			];
		}

		return array_values(array_slice($suggestions, 0, self::SUGGESTION_LIMIT));
	}

	private function searchGroups(string $term): array {
		$suggestions = [];
		foreach ($this->groupManager->search($term, self::SUGGESTION_LIMIT) as $group) {
			$suggestions[] = [
				'type' => self::SCOPE_GROUP,
				'id' => $group->getGID(),
				'label' => $group->getDisplayName(),
			];
		}

		return $suggestions;
	}

	private function groupUids(string $gid): array {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(
			static fn (IUser $user) => $user->getUID(),
			$group->getUsers(),
		));
	}

	private function searchTeams(string $term): array {
		$needle = mb_strtolower($term);
		$suggestions = [];

		try {
			$manager = Server::get(\OCA\Circles\CirclesManager::class);
			$manager->startSuperSession();

			$probe = new \OCA\Circles\Model\Probes\CircleProbe();
			$probe->filterSingleCircles();
			$probe->filterPersonalCircles();
			$probe->filterHiddenCircles();

			foreach ($manager->getCircles($probe) as $circle) {
				$label = $circle->getDisplayName();
				if (!str_contains(mb_strtolower($label), $needle)) {
					continue;
				}
				$suggestions[] = [
					'type' => self::SCOPE_TEAM,
					'id' => $circle->getSingleId(),
					'label' => $label,
				];
				if (count($suggestions) >= self::SUGGESTION_LIMIT) {
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('iTop: could not search teams', ['exception' => $e]);
			return [];
		}

		return $suggestions;
	}

	private function teamUids(string $singleId): array {
		if (!$this->teamsAvailable()) {
			return [];
		}

		try {
			$manager = Server::get(\OCA\Circles\CirclesManager::class);
			$manager->startSuperSession();

			$uids = [];
			foreach ($manager->getCircle($singleId)->getInheritedMembers() as $member) {
				// Teams can contain groups and other teams; only real accounts
				// belong in a table of Nextcloud accounts.
				if ($member->getUserType() !== \OCA\Circles\Model\Member::TYPE_USER) {
					continue;
				}
				$uids[$member->getUserId()] = true;
			}

			return array_keys($uids);
		} catch (Throwable $e) {
			$this->logger->warning('iTop: could not resolve team members', ['exception' => $e]);
			return [];
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Service/Binding/BindingScopeServiceTest.php`
Expected: 8 tests, 8 assertions-or-more, PASS, no warnings.

- [ ] **Step 5: Restrict the list to a scope**

In `lib/Service/Binding/BindingListService.php`, change the `list()` signature and the enumeration. Replace lines 49-68 — the `public function list(...)` header through the closing `});` of `callForAllUsers` — with:

```php
	/**
	 * @param ?array $restrictUids when given, only these uids are considered
	 * @return array{total: int, page: int, pageSize: int, rows: array}
	 */
	public function list(string $filter, string $search, int $page, int $pageSize, ?array $restrictUids = null): array {
		if (!in_array($filter, self::FILTERS, true)) {
			$filter = self::FILTER_ALL;
		}
		$page = max(1, $page);
		$pageSize = max(1, min(500, $pageSize));

		// Hash set, not in_array: a group can have thousands of members and
		// this runs once per account on the instance.
		$restrict = $restrictUids === null ? null : array_fill_keys($restrictUids, true);

		$rows = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$rows, $filter, $search, $restrict) {
			// Checked before describe(), which costs four config reads.
			if ($restrict !== null && !isset($restrict[$user->getUID()])) {
				return;
			}

			$row = $this->describe($user);

			if (!$this->matchesSearch($row, $search)) {
				return;
			}
			if (!$this->matchesFilter($row, $filter)) {
				return;
			}

			$rows[] = $row;
		});
```

- [ ] **Step 6: Wire the controller and the route**

In `lib/Controller/BindingController.php`, add `BindingScopeService $scopeService` to the constructor's promoted properties, immediately after `private BindingListService $listService,`:

```php
		private BindingScopeService $scopeService,
```

Add the import beside the existing ones:

```php
use OCA\Itop\Service\Binding\BindingScopeService;
```

Replace the whole `getUsers()` method with:

```php
	public function getUsers(
		string $filter = BindingListService::FILTER_ALL,
		string $search = '',
		int $page = 1,
		int $pageSize = 25,
		string $scopeType = '',
		string $scopeId = '',
	): DataResponse {
		$restrictUids = null;

		if ($scopeType !== '') {
			if (!$this->scopeService->isKnownScope($scopeType)) {
				return new DataResponse(
					['message' => 'Unknown scope type'],
					Http::STATUS_BAD_REQUEST,
				);
			}
			// A scope that resolves to nobody yields an empty table, not every
			// account: an admin who narrowed the view must never be silently
			// widened back out, because the next action may be a bulk exclude.
			$restrictUids = $this->scopeService->resolveUids($scopeType, $scopeId);
		}

		return new DataResponse(
			$this->listService->list($filter, $search, $page, $pageSize, $restrictUids),
		);
	}

	public function searchScopes(string $term = ''): DataResponse {
		return new DataResponse($this->scopeService->search($term));
	}
```

In `appinfo/routes.php`, add immediately after the `binding#getUsers` line:

```php
		['name' => 'binding#searchScopes', 'url' => '/binding/scopes', 'verb' => 'GET'],
```

- [ ] **Step 7: Verify against ground truth**

Create a real group with two known members, then query the endpoints as the admin:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ group:add itop-testers
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ group:adduser itop-testers pablo
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ group:adduser itop-testers test1
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ group:list
```

Expected: `itop-testers` listed with `pablo` and `test1`.

Then, in the browser devtools console on the admin settings page (the session is already authenticated, so no token juggling):

```js
await (await fetch(OC.generateUrl('/apps/integration_itop/binding/scopes?term=itop'), { headers: { requesttoken: OC.requestToken } })).json()
await (await fetch(OC.generateUrl('/apps/integration_itop/binding/users?scopeType=group&scopeId=itop-testers'), { headers: { requesttoken: OC.requestToken } })).json()
await (await fetch(OC.generateUrl('/apps/integration_itop/binding/users?scopeType=nonsense&scopeId=x'), { headers: { requesttoken: OC.requestToken } })).json()
```

Expected, in order: a `groups` entry for `itop-testers` and `teamsAvailable: true` (the `circles` app is installed on this VM); `total: 2` with rows `pablo` and `test1`; and an HTTP **400** carrying `Unknown scope type`.

Also confirm the graceful-degradation path, which is the one most likely to be wrong:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ app:disable circles
```

Re-run the `scopes?term=itop` call: expected `teamsAvailable: false`, `teams: []`, and **no** error in `nextcloud.log`. Then put the instance back:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ app:enable circles
```

Report the actual JSON you got for each call. Leave `itop-testers` in place — Task 7 verifies the picker against it and deletes it at the end.

- [ ] **Step 8: Commit**

```bash
git add lib/Service/Binding/BindingScopeService.php lib/Service/Binding/BindingListService.php lib/Controller/BindingController.php appinfo/routes.php
git commit -m "Add scope search for the association table

An administrator can narrow the table to one account, a Nextcloud group
or a Nextcloud Team. Teams come from the optional circles app, so every
call into it is guarded and wrapped: with circles disabled the teams
source returns nothing and the picker omits that section rather than the
screen failing.

A scope that resolves to nobody yields an empty table and an unknown
scope type is a 400, because silently widening a scope the admin
narrowed is the dangerous direction — the next action on this screen may
be a bulk exclude.

The uid restriction is checked before describe(), which costs four
config reads per account."
```

---

## Task 7: The scope picker

**Files:**
- Create: `src/views/BindingScopePicker.vue`
- Modify: `src/admin-binding.js`
- Modify: `templates/adminSettings.php`
- Modify: `css/admin-binding.css`

**Interfaces:**
- Consumes: `GET /binding/scopes` and the `scopeType` / `scopeId` parameters from Task 6; `state`, `load()`, `apiCall()` from Task 1.
- Produces: no new exported names. The component emits two events, `search` (a debounced raw term, string) and `select` (`{type, id, label}` or `null`).

**This is the one Vue component on this screen** (see Global Constraints). It replaces the plain `<input type="search">` added in Task 1 and the search-input debounce added in Task 2 — `NcSelect` owns the text field now.

**Both behaviours must survive:** typing free text still filters the table by name / uid / email through the existing `search` parameter, *and* the same keystrokes offer account / group / team suggestions. Picking a suggestion switches to scope mode and clears the free-text search; clearing the selection returns to free text.

- [ ] **Step 1: Write the component**

Create `src/views/BindingScopePicker.vue`:

```vue
<!--
  - Nextcloud - iTop Integration
  -
  - Admin: the account / group / team picker above the association table.
  -
  - The only Vue component on the admin settings page. Pickers are Vue
  - components in the Nextcloud component library with no plain-HTML
  - equivalent, and NcSelect brings the keyboard and ARIA behaviour a
  - hand-rolled combobox would have to reimplement.
  -->
<template>
	<NcSelect
		id="itop-binding-scope-select"
		:options="options"
		:loading="loading"
		:filterable="false"
		:clearable="true"
		:placeholder="t('integration_itop', 'Search accounts, groups or teams…')"
		label="label"
		@search="onSearch"
		@input="onInput">
		<template #option="option">
			<span class="itop-scope-option">
				<span>{{ option.label }}</span>
				<span class="itop-scope-option__type">{{ option.typeLabel }}</span>
			</span>
		</template>
	</NcSelect>
</template>

<script>
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'BindingScopePicker',

	components: { NcSelect },

	data() {
		return {
			options: [],
			loading: false,
			debounce: null,
		}
	},

	beforeDestroy() {
		clearTimeout(this.debounce)
	},

	methods: {
		t,

		/**
		 * Debounce every keystroke once, then do both jobs: filter the table
		 * by free text, and fetch suggestions. Two debounces would fire the
		 * table reload and the suggestion fetch at different moments for the
		 * same keystroke.
		 *
		 * @param {string} term what the admin has typed
		 */
		onSearch(term) {
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => {
				this.$emit('search', term.trim())
				this.fetchSuggestions(term.trim())
			}, 300)
		},

		/**
		 * @param {?object} option the chosen suggestion, or null when cleared
		 */
		onInput(option) {
			this.$emit('select', option
				? { type: option.type, id: option.id, label: option.label }
				: null)
		},

		/**
		 * @param {string} term search term, already trimmed
		 */
		async fetchSuggestions(term) {
			if (term === '') {
				this.options = []
				return
			}

			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/integration_itop/binding/scopes?term=' + encodeURIComponent(term)),
					{ headers: { requesttoken: getRequestToken() } },
				)
				if (!response.ok) {
					throw new Error('HTTP ' + response.status)
				}
				const body = await response.json()
				this.options = [
					...this.decorate(body.accounts, t('integration_itop', 'Account')),
					...this.decorate(body.groups, t('integration_itop', 'Group')),
					// Omitted entirely when the circles app is disabled, rather
					// than shown as a section that can never match.
					...(body.teamsAvailable ? this.decorate(body.teams, t('integration_itop', 'Team')) : []),
				]
			} catch (e) {
				this.options = []
				showError(t('integration_itop', 'Could not search accounts, groups or teams: {msg}', { msg: e.message }))
			} finally {
				this.loading = false
			}
		},

		/**
		 * NcSelect has no option groups, so each row carries its own type
		 * label instead of sitting under a heading.
		 *
		 * @param {Array} entries suggestions of one type
		 * @param {string} typeLabel translated name of that type
		 * @return {Array} entries with a typeLabel
		 */
		decorate(entries, typeLabel) {
			return (entries || []).map(entry => ({ ...entry, typeLabel }))
		},
	},
}
</script>

<style scoped>
.itop-scope-option {
	display: flex;
	justify-content: space-between;
	gap: 8px;
}

.itop-scope-option__type {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}
</style>
```

- [ ] **Step 2: Swap the template's input for a mount point**

In `templates/adminSettings.php`, inside `.itop-binding-toolbar`, replace this line:

```php
		<input type="search" id="itop-binding-search" placeholder="<?php p($l->t('Search accounts…')); ?>">
```

with:

```php
		<div id="itop-binding-scope-picker" class="itop-binding-scope-picker"></div>
```

- [ ] **Step 3: Mount it and send the scope to the server**

In `src/admin-binding.js`, add `scope: null` to `state`, immediately after the `search: ''` line:

```js
		scope: null,
```

In `load()`, replace the `const query = new URLSearchParams({…})` statement with:

```js
		const query = new URLSearchParams({
			filter: state.filter,
			search: state.search,
			page: String(state.page),
			pageSize: String(state.pageSize),
		})
		if (state.scope) {
			query.set('scopeType', state.scope.type)
			query.set('scopeId', state.scope.id)
		}
```

Delete the search-input block from `attachHandlers()` — the whole `const search = document.getElementById('itop-binding-search')` statement through the closing brace of its `if (search) { … }` — and add this function above `attachHandlers()`:

```js
	/**
	 * Mount the scope picker and react to it.
	 *
	 * Loaded on demand so the Vue runtime is not parsed on a page where the
	 * association table is absent.
	 */
	async function mountScopePicker() {
		const el = document.getElementById('itop-binding-scope-picker')
		if (!el) return

		const { default: Vue } = await import('vue')
		const { default: BindingScopePicker } = await import('./views/BindingScopePicker.vue')

		const Picker = Vue.extend(BindingScopePicker)
		const picker = new Picker().$mount(el)

		picker.$on('search', function(term) {
			state.search = term
			state.page = 1
			load()
		})

		picker.$on('select', function(scope) {
			// Scope and free text are alternatives, not filters that stack:
			// a chosen group must not stay narrowed by a leftover term.
			state.scope = scope
			state.search = ''
			state.page = 1
			load()
		})
	}
```

Then change the `DOMContentLoaded` listener to mount it:

```js
	document.addEventListener('DOMContentLoaded', function() {
		if (!document.getElementById('itop-binding-table')) return
		attachHandlers()
		mountScopePicker()
		load()
	})
```

- [ ] **Step 4: Style the mount point**

In `css/admin-binding.css`, add after the `.itop-binding-toolbar` rule:

```css
.itop-binding-scope-picker {
	min-width: 320px;
}
```

- [ ] **Step 5: Build**

```bash
npm run lint
npm run build
```

Expected: 0 eslint errors, build succeeds. The `admin-binding` bundle grows substantially — it now pulls in Vue and `NcSelect`. Report its size.

**If `npm run lint` rejects `src/views/BindingScopePicker.vue`**, check how `src/views/ReferenceItopWidget.vue` satisfies the same rules and follow it; do not add an eslint disable comment.

- [ ] **Step 6: Verify against ground truth**

`itop-testers` (containing `pablo` and `test1`) was created in Task 6, Step 7.

Load the admin settings page. **Hard-reload it** (`Cmd+Shift+R`): Nextcloud's JS URL does not change when only contents change, so a normal reload can run the previous bundle and make working controls look dead. Then:

1. Type `itop` in the picker. Confirm the dropdown offers **itop-testers** labelled `Group`, and that `GET /binding/scopes?term=itop` returned 200.
2. Pick it. Confirm the table request carries `scopeType=group&scopeId=itop-testers`, the table shows exactly `pablo` and `test1`, and the pager reads `Page 1 of 1 · 2 account(s)`.
3. With the group still selected, click the **Bound** filter. Confirm the request carries both `filter=bound` and the scope, and that the table is empty — neither member is bound.
4. Clear the selection with the picker's ✕. Confirm the table returns to 4 accounts.
5. Type `picasso`. Confirm the dropdown offers **Pablo Picasso** labelled `Account`, and that the table itself filters to one row from the free-text `search=picasso` parameter without selecting anything.
6. Select that account suggestion. Confirm the request carries `scopeType=account&scopeId=pablo` and `search=` is empty.

Report the request URLs and row counts you actually observed, and the console state. Then remove the fixture:

```bash
orb -m nextcloud-dev sudo -u www-data php /var/www/nextcloud/occ group:delete itop-testers
```

- [ ] **Step 7: Commit**

```bash
git add src/views/BindingScopePicker.vue src/admin-binding.js templates/adminSettings.php css/admin-binding.css
find js -name '*.mjs' ! -name '*.map' -print0 | xargs -0 git add -f
git commit -m "Add an account, group and team picker to the association table

One field searches all three and narrows the table to the accounts a
scope covers. Built with NcSelect, the component the Nextcloud design
docs prescribe for pickers and the only Vue on this page: it brings the
keyboard and ARIA behaviour a hand-rolled combobox would have to
reimplement, and vue and @nextcloud/vue were already dependencies.

Scope and free text are alternatives rather than filters that stack, so
choosing a group drops a leftover search term instead of silently
narrowing the group.

Vue is imported dynamically so its runtime is not parsed on pages
without the association table."
```

---

## Task 8: Document the scope picker

**Files:**
- Modify: `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Extend the changelog entry**

In `CHANGELOG.md`, inside the `## [Unreleased]` section's `### Added` block, add a bullet beneath the user-associations one:

```markdown
- **Scope picker on the user associations screen**: one field searches Nextcloud accounts, groups and Teams, and narrows the table to the accounts the chosen scope covers. Teams require the `circles` app; with it disabled the picker simply omits them. Typing without choosing a suggestion still filters by name, user ID or email
```

- [ ] **Step 2: Update the README**

In `README.md`, in the **Admin settings → iTop → User associations** sentence added in Task 5, append this sentence directly after it:

```
A single picker above the table searches accounts, groups and Teams, so the list can be narrowed to one group's members before excluding or unlinking them.
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md README.md
git commit -m "Document the association table scope picker"
```

---

## Out of scope — phase 2b-2

- `POST /binding/suggest` — run the resolver over selected accounts and return a tier per account.
- `POST /binding/apply` — re-resolve server-side and bind `exact` rows. It must **re-resolve** rather than trust a client-supplied `personId`, for the same reason the spec gives for self-service discovery.
- `POST /binding/apply-one` — explicit confirmation for a `review` or `ambiguous` row. This one *may* take a `personId`, because an administrator choosing among candidates is a trusted decision.
- `GET /binding/search-person` — candidates for the manual picker on ambiguous rows.
- The tier column, the "Suggest matches" button, "Apply exact only", and the ambiguous-row picker.

## Carried-over findings to address while in this code

From the spec's deferred list, these sit in code 2b-2 will touch:

- `BindingListService::describe()` returns `method => null` while `IdentityBindingService::getBinding()` returns `method => ''`. Task 1 of this plan renders the `null` case as "unknown"; collapsing the two accessors onto one shape is still worth doing.
- A row carries no `sourceValue`, so the table cannot show what is actually being matched when the rule uses `uid` or `displayname`. Add it from `IdentityResolver::sourceValueFor()` when the tier column lands.
- `BindingConfig::setExcluded()` is a read-modify-write per uid, so a bulk exclude of N accounts is 2N config operations and two concurrent admins lose an edit. This plan's bulk bar makes that reachable — a `setExcludedMany()` belongs in 2b-2.
- `describe()` issues four `getValueString` calls per user; this screen is exactly the load path that makes it matter. `getValuesByUsers()` would be one query per key.
