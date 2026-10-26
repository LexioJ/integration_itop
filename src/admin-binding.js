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
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

(function() {
	'use strict'

	const state = {
		filter: 'all',
		search: '',
		scope: null,
		page: 1,
		pageSize: 25,
		total: 0,
		rows: [],
		selected: new Set(),
		// Last /binding/suggest result per uid, for rows currently on screen.
		// Never cached server-side, so it is dropped whenever a row leaves
		// view or the matching rule changes underneath it.
		suggestions: {},
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
		if (state.scope) {
			query.set('scopeType', state.scope.type)
			query.set('scopeId', state.scope.id)
		}
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
			// Same reasoning as selections above: a suggestion for a row the
			// admin can no longer see must never be acted on.
			for (const uid of Object.keys(state.suggestions)) {
				if (!visible.has(uid)) delete state.suggestions[uid]
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
	 * Quotes are escaped as well as the markup characters, because every call
	 * site below interpolates into a double-quoted attribute value. The obvious
	 * textContent/innerHTML round-trip is not enough: it leaves " and ' intact,
	 * so a uid of `a" onmouseover=alert(1) x="` would close the attribute and
	 * add a live event handler to an admin page. Nextcloud's own accounts
	 * cannot contain a double quote, but an external backend that derives the
	 * uid from a raw attribute (user_saml with a NameID-based uid) can, and the
	 * uid charset does allow a single quote.
	 *
	 * @param {string} value raw text
	 * @return {string} escaped text
	 */
	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
	}

	/**
	 * The single status cell for one row.
	 *
	 * Binding state and match tier used to be two columns, which was
	 * redundant in both directions: an unbound row showed "not linked" next
	 * to a suggestion that already said so, and a bound row showed a tier it
	 * is not allowed to act on. One cell shows exactly one thing instead --
	 * what the row is, or failing that, what the matcher thinks it could be.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for the status cell
	 */
	function describeStatus(row) {
		if (row.excluded) {
			return '<span class="itop-binding-badge excluded">' + esc(t('integration_itop', 'excluded')) + '</span>'
		}
		if (row.bound) {
			// A pre-existing binding made before provenance was recorded has no method.
			const origin = row.method
				? '<span class="itop-binding-badge origin">' + esc(row.method) + '</span>'
				: '<span class="itop-binding-badge origin unknown" title="'
					+ esc(t('integration_itop', 'Linked before this app recorded how bindings were made'))
					+ '">' + esc(t('integration_itop', 'unknown')) + '</span>'
			return '<strong>' + esc(t('integration_itop', 'person {id}', { id: row.personId })) + '</strong> ' + origin
		}
		const s = state.suggestions[row.uid]
		if (!s) {
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'not linked')) + '</span>'
		}
		return describeTier(row, s)
	}

	/**
	 * The value the matching rule compares for one account, as a muted line
	 * under the tier in the status cell.
	 *
	 * Only worth the space on the two tiers where it answers a question the
	 * admin already has: review ("these names differ -- what actually
	 * matched?") and none ("why no match -- what did it even look for?"). On an
	 * exact row it would only repeat what the badge already says.
	 *
	 * An empty value is the most informative case of all on a none row: the
	 * rule has nothing to compare, so no amount of iTop data could ever match.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for the source-value line
	 */
	function describeSourceValue(row) {
		if (!row.sourceValue) {
			return '<span class="itop-binding-source-value">'
				+ esc(t('integration_itop', 'nothing to match on')) + '</span>'
		}
		return '<span class="itop-binding-source-value" title="'
			+ esc(t('integration_itop', 'The value the matching rule compares for this account'))
			+ '">' + esc(t('integration_itop', 'matched on {value}', { value: row.sourceValue })) + '</span>'
	}

	/**
	 * Render the tier part of the status cell for one row, from its last
	 * /binding/suggest result.
	 *
	 * Suggestions are requested on demand (see suggest()), never computed on
	 * load, so most rows never reach this at all -- describeStatus shows
	 * "not linked" until the admin asks for matches.
	 *
	 * @param {object} row a row from /binding/users
	 * @param {object} s that row's suggestion, already known to exist
	 * @return {string} HTML for the tier part of the status cell
	 */
	function describeTier(row, s) {
		if (s.status === 'excluded') {
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'excluded')) + '</span>'
		}
		if (s.status === 'unknown') {
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'unknown')) + '</span>'
		}
		if (s.status === 'failed') {
			return '<span class="itop-binding-badge error">'
				+ esc(s.message || t('integration_itop', 'Suggestion failed')) + '</span>'
		}
		if (s.status !== 'resolved') {
			return '<span class="itop-binding-muted">—</span>'
		}

		switch (s.tier) {
		case 'exact':
			return '<span class="itop-binding-badge tier-exact">'
				+ esc(t('integration_itop', '{name} (person {id})', { name: s.itopName, id: s.personId }))
				+ '</span>'
		case 'review':
			return '<span class="itop-binding-tier-compare">'
				+ esc(t('integration_itop', '{ncName} vs. {itopName}', { ncName: s.ncName, itopName: s.itopName }))
				+ '</span>' + describeSourceValue(row)
		case 'ambiguous':
			return '<span class="itop-binding-badge tier-ambiguous">'
				+ esc(n('integration_itop', '{count} candidate', '{count} candidates', s.candidates.length, { count: s.candidates.length }))
				+ '</span>'
		case 'conflict':
			return '<span class="itop-binding-badge tier-conflict">'
				+ esc(t('integration_itop', 'already linked to {uid}', { uid: s.conflictUid }))
				+ '</span>'
		case 'none':
		default:
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'no match')) + '</span>'
				+ describeSourceValue(row)
		}
	}

	/**
	 * Live RowActions instances from the last repaint.
	 *
	 * Tracked because render() replaces the table's innerHTML wholesale: the
	 * assignment orphans every mounted instance, and an orphaned Vue instance
	 * keeps its watchers, its NcPopover (which lives on document.body, not in
	 * the cell) and its reference to the row object. Twenty-five of them per
	 * repaint, across a session of filtering and paging, is a leak that grows
	 * without bound -- so destroyRowActions() runs before every such
	 * assignment.
	 */
	let rowActions = []

	/** @type {?Function} Vue.extend(RowActions), once the dynamic import has landed. */
	let RowActionsCtor = null

	/** @type {?Promise} In-flight import, shared so a burst of repaints imports once. */
	let rowActionsImport = null

	/**
	 * Bumped by every repaint. mountRowActions() carries the value it was
	 * called with across its await and abandons the pass if another repaint
	 * has happened since -- otherwise two overlapping passes would both mount
	 * into the current cells and only the later set would ever be destroyed.
	 */
	let renderSeq = 0

	/**
	 * Tear down the RowActions instances mounted by the last repaint.
	 *
	 * Must be called before anything replaces the table's contents. $destroy()
	 * does not remove the instance's element in Vue 2, which is fine here:
	 * the innerHTML assignment that follows removes it anyway.
	 */
	function destroyRowActions() {
		for (const instance of rowActions) {
			instance.$destroy()
		}
		rowActions = []
	}

	/**
	 * Resolve the RowActions constructor, importing Vue and the component on
	 * first use.
	 *
	 * Dynamically imported for the same reason as the scope and person
	 * pickers: the Vue runtime is not parsed on a page without the
	 * association table.
	 *
	 * @return {Promise<Function>} the component constructor
	 */
	function loadRowActions() {
		if (RowActionsCtor) return Promise.resolve(RowActionsCtor)
		if (!rowActionsImport) {
			rowActionsImport = (async () => {
				const { default: Vue } = await import('vue')
				const { default: RowActions } = await import('./views/RowActions.vue')
				RowActionsCtor = Vue.extend(RowActions)
				return RowActionsCtor
			})()
		}
		return rowActionsImport
	}

	/**
	 * Mount one RowActions into each row's actions cell.
	 *
	 * The component only emits; every handler below is the same function the
	 * old delegated click handler called, so the guards that protect a bound
	 * row live where they always did.
	 *
	 * @param {number} seq the renderSeq value of the repaint that scheduled this
	 */
	async function mountRowActions(seq) {
		const table = document.getElementById('itop-binding-table')
		if (!table || !table.querySelector('[data-actions]')) return

		const Ctor = await loadRowActions()
		// A newer repaint has already destroyed this pass's instances-to-be
		// and replaced its cells; mounting now would leak into detached nodes.
		if (seq !== renderSeq) return

		for (const el of table.querySelectorAll('[data-actions]')) {
			const uid = el.dataset.actions
			const row = state.rows.find(r => r.uid === uid)
			// Defensive: the cells were built from state.rows a tick ago.
			if (!row) continue

			const instance = new Ctor({
				propsData: {
					row,
					suggestion: state.suggestions[uid] || null,
				},
			})
			instance.$on('apply', applyOneExact)
			instance.$on('confirm', confirmReview)
			// Both open the same picker; the labels differ because an
			// ambiguous row has candidates to choose between and every other
			// row searches from scratch, but the route is identical.
			instance.$on('choose', openPersonPicker)
			instance.$on('link', openPersonPicker)
			instance.$on('exclude', target => setExcluded([target], true))
			instance.$on('restore', target => setExcluded([target], false))
			instance.$on('unbind', unbind)
			// Vue 2's $mount(el) replaces el, so the mount point is a child of
			// the actions cell rather than the cell itself -- the cell has to
			// survive as the row's grid track.
			instance.$mount(el)
			rowActions.push(instance)
		}
	}

	/**
	 * Repaint the table, bulk bar and pager from state.
	 */
	function render() {
		const table = document.getElementById('itop-binding-table')
		if (!table) return

		// Before the innerHTML assignments below, which orphan them.
		destroyRowActions()
		renderSeq += 1

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
				// Same image NcAvatar fetches. alt is empty on purpose: the
				// display name sits next to it, so a label here would make
				// screen readers announce the account twice.
				+ '<img class="itop-binding-avatar" alt="" loading="lazy"'
				+ ' src="' + esc(generateUrl('/avatar/' + encodeURIComponent(row.uid) + '/32')) + '"'
				+ ' srcset="' + esc(generateUrl('/avatar/' + encodeURIComponent(row.uid) + '/64')) + ' 2x">'
				// Both lines can be truncated with an ellipsis under a long
				// enough translated label set (see .itop-binding-account),
				// so each carries its own title so the full value stays
				// reachable on hover/focus regardless.
				+ '<span class="itop-binding-account"><strong title="' + esc(row.displayName) + '">' + esc(row.displayName) + '</strong>'
				+ '<span class="itop-binding-muted" title="' + esc(row.uid + (row.email ? ' · ' + row.email : '')) + '">' + esc(row.uid)
				+ (row.email ? ' · ' + esc(row.email) : '') + '</span></span>'
				+ '<span class="itop-binding-status">' + describeStatus(row) + '</span>'
				// Empty mount point for this row's RowActions, filled by
				// mountRowActions() once the dynamic import lands. Vue 2
				// replaces it rather than rendering inside it, hence the
				// wrapping cell.
				+ '<span class="itop-binding-actions">'
				+ '<span data-actions="' + esc(row.uid) + '"></span>'
				+ '</span>'
				+ '</div>',
			).join('')
		}

		renderBulkBar()
		renderPager()
		renderToolbarApplyExact()
		// Deliberately not awaited: render() is synchronous for every caller,
		// and the actions cells fill in on the microtask after the first
		// import resolves.
		mountRowActions(renderSeq)
	}

	/**
	 * How many rows on the current page are unbound and currently hold an
	 * exact suggestion -- i.e. how many "Apply exact only" would actually
	 * apply. An already-bound row is excluded even if its suggestion is
	 * exact: moving a person is the explicit two-step act (unlink, then
	 * apply), never a side effect of a bulk button.
	 *
	 * @return {number} count
	 */
	function exactCountOnPage() {
		return state.rows.filter(row => {
			if (row.bound) return false
			const s = state.suggestions[row.uid]
			return s && s.status === 'resolved' && s.tier === 'exact'
		}).length
	}

	/**
	 * Keep the standalone toolbar "Apply exact only" button honest about its
	 * own scope.
	 *
	 * Unlike the bulk-bar copy -- where the bar's own "{count} selected" line
	 * already states what a click affects -- this button's scope (the whole
	 * page) is otherwise invisible. Binding is the one action on this screen
	 * that is not trivially reversible (a token-bound user who gets unlinked
	 * needs a new personal token), so the label names the count rather than
	 * leaving "the whole page" implicit. Disabled rather than hidden when
	 * there is nothing to apply, so it can't issue an empty request.
	 */
	function renderToolbarApplyExact() {
		const btn = document.getElementById('itop-binding-toolbar-apply-exact')
		if (!btn) return
		const count = exactCountOnPage()
		btn.disabled = count === 0
		btn.textContent = count > 0
			? n('integration_itop', 'Apply {count} exact match', 'Apply {count} exact matches', count, { count })
			: t('integration_itop', 'Apply exact only')
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
			+ '<button type="button" id="itop-binding-suggest">' + esc(t('integration_itop', 'Suggest matches')) + '</button>'
			+ '<button type="button" id="itop-binding-apply-exact">' + esc(t('integration_itop', 'Apply exact only')) + '</button>'
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
		pager.innerHTML
			= '<button type="button" id="itop-binding-prev"' + (state.page <= 1 ? ' disabled' : '') + '>‹</button>'
			+ '<span>' + esc(n('integration_itop', 'Page {page} of {pages} · {total} account',
				'Page {page} of {pages} · {total} accounts', state.total,
				{ page: state.page, pages, total: state.total })) + '</span>'
			+ '<button type="button" id="itop-binding-next"' + (state.page >= pages ? ' disabled' : '') + '>›</button>'
	}

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
				? n('integration_itop', '{count} account excluded from matching', '{count} accounts excluded from matching', uids.length, { count: uids.length })
				: n('integration_itop', '{count} account restored to matching', '{count} accounts restored to matching', uids.length, { count: uids.length }))
			state.selected.clear()
			await load()
		} catch (e) {
			showError(t('integration_itop', 'Could not update exclusions: {msg}', { msg: e.message }))
			// Reload anyway: a partial change may have landed, and the table must
			// show what the server actually holds rather than what we attempted.
			await load()
		}
	}

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

	/**
	 * Request match suggestions for a set of uids and merge them into
	 * state.suggestions, then repaint.
	 *
	 * One iTop call per account, so this is not instant for a full page --
	 * the triggering buttons are disabled and relabelled for the duration,
	 * the same loading affordance used for every other async action here.
	 *
	 * @param {string[]} uids accounts to suggest matches for
	 */
	async function suggest(uids) {
		if (uids.length === 0) {
			showError(t('integration_itop', 'No accounts to suggest matches for'))
			return
		}
		const buttons = [
			document.getElementById('itop-binding-suggest'),
			document.getElementById('itop-binding-toolbar-suggest'),
		].filter(Boolean)
		const originalLabels = buttons.map(b => b.textContent)
		buttons.forEach(b => {
			b.disabled = true
			b.textContent = t('integration_itop', 'Suggesting…')
		})
		try {
			const data = await apiCall('/binding/suggest', {
				method: 'POST',
				body: JSON.stringify({ uids }),
			})
			const results = data.results || {}
			for (const uid of uids) {
				if (uid in results) state.suggestions[uid] = results[uid]
			}
			// The server caps a batch (maxBatch) and truncates silently, so
			// without this the surplus rows would simply stay blank with
			// nothing said about why. Unreachable at the current page size,
			// which is the point: a guard rail should report when it fires
			// rather than only exist.
			const missing = uids.filter(uid => !(uid in results))
			if (missing.length > 0) {
				const max = data.maxBatch || uids.length - missing.length
				showError(n('integration_itop',
					'Only {max} account can be matched at once. {count} were left without a suggestion — select fewer accounts and run it again.',
					'Only {max} accounts can be matched at once. {count} were left without a suggestion — select fewer accounts and run it again.',
					max,
					{ max, count: missing.length }))
			}
		} catch (e) {
			showError(t('integration_itop', 'Could not suggest matches: {msg}', { msg: e.message }))
		} finally {
			// render() repaints the bulk bar from scratch, so only the static
			// toolbar button needs its own label restored here.
			buttons.forEach((b, i) => {
				if (document.body.contains(b)) {
					b.disabled = false
					b.textContent = originalLabels[i]
				}
			})
			render()
		}
	}

	/**
	 * The uids to act on when nothing is explicitly selected: the current
	 * selection if there is one, otherwise every row on the current page.
	 *
	 * @return {string[]} uids
	 */
	function selectionOrPage() {
		return state.selected.size > 0 ? [...state.selected] : state.rows.map(r => r.uid)
	}

	/**
	 * Summarize per-uid /binding/apply results into a short, human-readable
	 * line, so "already_bound" -- left alone because a link already exists
	 * -- reads differently from a fresh "bound".
	 *
	 * @param {object} results uid -> {status, ...} as returned by /binding/apply
	 * @return {string} summary text
	 */
	function summarizeApplyResults(results) {
		const labelFns = {
			bound: count => t('integration_itop', '{count} bound', { count }),
			already_bound: count => t('integration_itop', '{count} already linked', { count }),
			skipped: count => t('integration_itop', '{count} skipped', { count }),
			excluded: count => t('integration_itop', '{count} excluded', { count }),
			unknown: count => t('integration_itop', '{count} unknown', { count }),
			failed: count => t('integration_itop', '{count} failed', { count }),
		}
		const counts = {}
		for (const r of Object.values(results)) {
			counts[r.status] = (counts[r.status] || 0) + 1
		}
		return Object.keys(labelFns)
			.filter(status => counts[status])
			.map(status => labelFns[status](counts[status]))
			.join(', ')
	}

	/**
	 * Bind every uid in a set whose current suggestion is tier exact and
	 * which is not already bound.
	 *
	 * Filtered client-side so "Apply exact only" can never send a uid whose
	 * suggestion is review, ambiguous, conflict or none -- the server would
	 * refuse those anyway (apply() binds exact only), but the label on this
	 * button is a promise and the request itself should keep it. An
	 * already-bound uid is excluded for the same reason described on
	 * RowActions: moving a person is the explicit two-step act of
	 * unlink then apply, never a side effect of a bulk button.
	 *
	 * @param {string[]} candidateUids uids to consider
	 */
	async function applyExact(candidateUids) {
		const boundUids = new Set(state.rows.filter(r => r.bound).map(r => r.uid))
		const uids = candidateUids.filter(uid => {
			if (boundUids.has(uid)) return false
			const s = state.suggestions[uid]
			return s && s.status === 'resolved' && s.tier === 'exact'
		})
		if (uids.length === 0) {
			showError(t('integration_itop', 'No exact matches to apply. Run "Suggest matches" first.'))
			return
		}
		try {
			const data = await apiCall('/binding/apply', {
				method: 'POST',
				body: JSON.stringify({ uids }),
			})
			const results = data.results || {}
			const summary = summarizeApplyResults(results) || t('integration_itop', 'No changes')
			const failed = Object.values(results).some(r => r.status === 'failed')
			if (failed) showError(summary)
			else showSuccess(summary)
			state.selected.clear()
			await load()
		} catch (e) {
			showError(t('integration_itop', 'Could not apply matches: {msg}', { msg: e.message }))
			await load()
		}
	}

	/**
	 * Apply the exact suggestion already held for one row.
	 *
	 * @param {string} uid Nextcloud user id
	 */
	async function applyOneExact(uid) {
		await applyExact([uid])
	}

	/**
	 * Confirm and apply a review-tier suggestion for one row.
	 *
	 * Confirmed first, naming both names being matched, because review tier
	 * means they disagreed and a human is the one deciding they are in fact
	 * the same person.
	 *
	 * @param {string} uid Nextcloud user id
	 */
	async function confirmReview(uid) {
		// Defense in depth: RowActions never offers Confirm… once the row
		// is bound, but a stale instance (e.g. a click queued before a
		// reload finished) must not reach apply-one, which trusts the
		// administrator and has no already_bound guard of its own.
		const row = state.rows.find(r => r.uid === uid)
		if (row && row.bound) return
		const s = state.suggestions[uid]
		if (!s || s.status !== 'resolved' || s.tier !== 'review') return
		const ok = window.confirm(t('integration_itop',
			'Link {ncName} to {itopName} in iTop?', { ncName: s.ncName, itopName: s.itopName }))
		if (!ok) return
		try {
			await apiCall('/binding/apply-one', {
				method: 'POST',
				body: JSON.stringify({ uid, personId: s.personId }),
			})
			showSuccess(t('integration_itop', 'Linked {uid} to person {id}', { uid, id: s.personId }))
			await load()
		} catch (e) {
			showError(t('integration_itop', 'Could not apply the match: {msg}', { msg: e.message }))
			await load()
		}
	}

	/**
	 * Apply a person chosen for an ambiguous row.
	 *
	 * @param {string} uid Nextcloud user id
	 * @param {string} personId iTop person id chosen by the administrator
	 */
	async function applyChosenPerson(uid, personId) {
		// The same guard confirmReview carries, and more necessary here: the
		// window between rendering "Choose…" and this call spans a dynamic
		// import, a modal, a person search and a human decision, so another
		// administrator or occ itop:bind may well have bound the account in the
		// meantime. apply-one refuses an already-bound uid server-side; this
		// just avoids offering a request that is already doomed.
		const row = state.rows.find(r => r.uid === uid)
		if (row && row.bound) {
			showError(t('integration_itop',
				'{uid} was linked to a person while the picker was open. Reload, then unlink it first if you still want to change it.', { uid }))
			await load()
			return
		}
		try {
			await apiCall('/binding/apply-one', {
				method: 'POST',
				body: JSON.stringify({ uid, personId }),
			})
			showSuccess(t('integration_itop', 'Linked {uid} to person {id}', { uid, id: personId }))
			await load()
		} catch (e) {
			// A 409 here is the expected, meaningful outcome of choosing a
			// person someone else already holds -- its message already
			// names the holding account and the remedy, so it is shown
			// as-is rather than wrapped in a generic failure.
			showError(e.message)
			await load()
		}
	}

	/**
	 * Open the person picker for one account, for either a known-ambiguous
	 * row (seeded with that row's own candidates so the obvious choices need
	 * no search) or any other unbound, non-excluded row (opened empty, so
	 * the administrator searches) -- see RowActions for which label leads
	 * here.
	 *
	 * Mounted on demand, like the scope picker, so the Vue runtime is not
	 * parsed on a page where the association table is absent -- which here
	 * means it is also not parsed unless the admin actually opens a picker.
	 *
	 * @param {string} uid Nextcloud user id
	 */
	async function openPersonPicker(uid) {
		// Same stale-instance reasoning as confirmReview: RowActions never
		// offers this on a bound or excluded row, but a click queued before a
		// reload finished must not open a picker whose only outcome would be
		// a refusal from apply-one.
		const row = state.rows.find(r => r.uid === uid)
		if (row && (row.bound || row.excluded)) return

		const s = state.suggestions[uid]
		const candidates = (s && s.status === 'resolved' && s.tier === 'ambiguous') ? s.candidates : []

		const { default: Vue } = await import('vue')
		const { default: PersonPicker } = await import('./views/PersonPicker.vue')

		const mountPoint = document.createElement('div')
		document.body.appendChild(mountPoint)

		const Picker = Vue.extend(PersonPicker)
		const picker = new Picker({
			propsData: { candidates },
		}).$mount(mountPoint)

		const destroy = () => {
			picker.$destroy()
			mountPoint.remove()
		}

		picker.$on('select', function(person) {
			destroy()
			applyChosenPerson(uid, person.person_id)
		})
		picker.$on('cancel', destroy)
	}

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
			// Scope and free text are alternatives, not filters that stack: a
			// real typed term abandons whatever scope was selected. Guarded
			// on non-empty because vue-select also fires this event with an
			// empty string right after a selection, which must not undo the
			// scope just chosen.
			if (term !== '') {
				state.scope = null
			}
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

	/**
	 * Wire the toolbar and pager.
	 *
	 * Delegated from document, matching the pattern used elsewhere in this app:
	 * the pager and bulk bar are re-rendered on every repaint, so their buttons
	 * are replaced and a direct listener would be lost.
	 */
	function attachHandlers() {
		document.addEventListener('click', function(e) {
			// A click whose target is not an Element -- document itself, or a
			// text node from a synthetic event -- has no closest(). Because
			// this is a single delegated listener, letting it throw would kill
			// every interaction in the table until the page is reloaded.
			if (!(e.target instanceof Element)) return

			const filterBtn = e.target.closest('.itop-binding-filter')
			if (filterBtn) {
				state.filter = filterBtn.dataset.filter
				state.page = 1
				document.querySelectorAll('.itop-binding-filter').forEach(b => b.classList.remove('active'))
				filterBtn.classList.add('active')
				load()
				return
			}

			const selectBox = e.target.closest('input[data-select]')
			if (selectBox) {
				const uid = selectBox.dataset.select
				if (selectBox.checked) state.selected.add(uid)
				else state.selected.delete(uid)
				renderBulkBar()
				return
			}

			// Per-row actions are not delegated: they are NcActionButtons
			// inside each row's RowActions component, which emits to the
			// handlers wired in mountRowActions().

			if (e.target.closest('#itop-binding-bulk-exclude')) {
				setExcluded([...state.selected], true); return
			}
			if (e.target.closest('#itop-binding-bulk-restore')) {
				setExcluded([...state.selected], false); return
			}
			if (e.target.closest('#itop-binding-bulk-clear')) {
				state.selected.clear(); render(); return
			}

			// Both the bulk-bar and standalone toolbar "Suggest matches" act
			// over the current selection, falling back to the whole page when
			// nothing is selected -- the toolbar copy exists so a full-page
			// pass never requires selecting every row first.
			if (e.target.closest('#itop-binding-suggest') || e.target.closest('#itop-binding-toolbar-suggest')) {
				suggest(selectionOrPage()); return
			}
			// The bulk-bar "Apply exact only" scopes to the selection the bar
			// itself already states ("{count} selected"). The toolbar copy
			// always scopes to the whole current page -- its own label names
			// that count (see renderToolbarApplyExact) so the two never
			// silently disagree about what a click affects.
			if (e.target.closest('#itop-binding-apply-exact')) {
				applyExact([...state.selected]); return
			}
			if (e.target.closest('#itop-binding-toolbar-apply-exact')) {
				applyExact(state.rows.map(r => r.uid)); return
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

	document.addEventListener('DOMContentLoaded', function() {
		if (!document.getElementById('itop-binding-table')) return
		attachHandlers()
		mountScopePicker()
		load()

		// A tier computed under the old matching rule is a claim that is no
		// longer true. admin-settings.js dispatches this after a successful
		// rule save.
		document.addEventListener('itop:binding-rule-changed', function() {
			state.suggestions = {}
			render()
		})
	})
})()
