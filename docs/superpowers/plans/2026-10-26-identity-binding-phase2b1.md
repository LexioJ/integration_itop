# iTop Identity Binding — Phase 2b-1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give administrators a screen that shows every Nextcloud account's iTop binding state, and lets them exclude, restore and unbind accounts.

**Architecture:** A self-contained vanilla-JS table controller in its own bundle, rendering into a container in the existing admin settings template, driven entirely by the endpoints phase 2a already shipped. No new PHP: `GET /binding/users`, `POST /binding/exclude`, `POST /binding/restore` and `DELETE /binding/{uid}` already cover everything this screen does.

**Tech Stack:** Vanilla JS (ES modules via Vite), a server-rendered PHP template container, plain CSS. Nextcloud 32–35.

## Global Constraints

- Minimum Nextcloud version is **32**; `max-version` is **35**. Do not use APIs newer than 32.
- **No Vue, and no new npm dependencies.** Admin settings are server-rendered PHP plus vanilla JS; introducing Vue here would enlarge the surface the deferred Vue 3 migration must cross.
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

---

## File Structure

| File | Responsibility |
|---|---|
| `src/admin-binding.js` *(create)* | The whole table controller: fetch, render, filters, search, paging, selection, actions |
| `css/admin-binding.css` *(create)* | Table styling |
| `vite.config.ts` *(modify)* | Add the `admin-binding` entry |
| `templates/adminSettings.php` *(modify)* | Container element plus `script()` / `style()` calls |
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
