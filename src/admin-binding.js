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
import { getCanonicalLocale, translate as t, translatePlural as n } from '@nextcloud/l10n'
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
	 * interpolated raw. Since the person detail line was added, the same is
	 * true of iTop-controlled name and email strings: an iTop person is
	 * created and edited inside iTop, not this app, so this page has no more
	 * control over what they contain than it does over a Nextcloud account's.
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
			return describePerson(row)
		}
		const s = state.suggestions[row.uid]
		if (!s) {
			return '<span class="itop-binding-muted">' + esc(t('integration_itop', 'not linked')) + '</span>'
		}
		return describeTier(row, s)
	}

	/**
	 * The method badge for a bound row: how the link came to be made.
	 *
	 * A pre-existing binding made before provenance was recorded has no
	 * method, and says so rather than leaving the badge off -- "we did not
	 * record this" is a different statement from "there is nothing here".
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for the badge
	 */
	function methodBadge(row) {
		if (row.method) {
			return '<span class="itop-binding-badge origin">' + esc(row.method) + '</span>'
		}
		return '<span class="itop-binding-badge origin unknown" title="'
			+ esc(t('integration_itop', 'Linked before this app recorded how bindings were made'))
			+ '">' + esc(t('integration_itop', 'unknown')) + '</span>'
	}

	/**
	 * The whole status cell for a bound row, built as the structural twin of
	 * the Account cell: an icon in the avatar's 32px slot, then a bold
	 * identity line and a muted secondary line.
	 *
	 * That symmetry is the point. The two columns describe the same person on
	 * either side of the link, so they should read as the same kind of thing --
	 * `#16 Boris Vian` over `boris.vian@it.com · admin` against `nextadmin`
	 * over `nextadmin · boris.vian@…`. A bare `person 16` asked the admin to
	 * go and look the id up somewhere else.
	 *
	 * `status` draws a distinction an administrator has to act on differently,
	 * so the two lines are filled from it rather than assumed:
	 *
	 * - `ok`          name on the identity line, email on the muted one, and
	 *                 the profile icon in the slot.
	 * - `not_found`   iTop answered and this person is gone. That is a broken
	 *                 link somebody has to repair, so the second line says so
	 *                 as a warning rather than staying muted.
	 * - `unavailable` the id and the badge alone -- exactly how the cell reads
	 *                 when nothing was ever asked. iTop was merely
	 *                 unreachable, and dressing a transient outage up as a
	 *                 broken link would send an admin hunting a problem that
	 *                 does not exist.
	 *
	 * The icon slot is rendered on every bound row even when it is empty: its
	 * whole job is that every row's text starts at the same x-position. It is
	 * only filled when a profile was actually established -- an empty slot
	 * asserts nothing about a person nothing is known about, which is why
	 * `not_found` gets no silhouette next to a note saying the person does not
	 * exist.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for the status cell of a bound row
	 */
	function describePerson(row) {
		const person = row.person
		const resolved = !!person && person.status === 'ok'
		const notFound = !!person && person.status === 'not_found'

		// `#` followed by digits is the same in every locale, so it is
		// concatenated rather than given an l10n key of its own. Joined from a
		// filtered list so an unresolved person never leaves a trailing space.
		const identity = ['#' + row.personId, resolved ? person.name : '']
			.filter(Boolean).join(' ')

		// Built as HTML fragments rather than text, because the badge is one of
		// the parts. Filtered before joining so a missing email can never leave
		// a leading separator. The plain-text twin is what the title carries.
		const secondHtml = notFound
			? esc(t('integration_itop', 'not found in iTop'))
			: [resolved && person.email ? esc(person.email) : '', methodBadge(row)]
				.filter(Boolean).join(' · ')
		const secondTitle = notFound
			? t('integration_itop', 'not found in iTop')
			: [resolved && person.email ? person.email : '', row.method || t('integration_itop', 'unknown')]
				.filter(Boolean).join(' · ')

		// Both lines are clipped with an ellipsis past the status column's cap
		// (see .itop-binding-person), so each carries its own title to keep the
		// full iTop-supplied value reachable on hover and focus -- the same
		// treatment both lines of the Account column get.
		return '<div class="itop-binding-person-line">'
			+ (resolved ? profileIcon(person.profile) : emptyProfileSlot())
			+ '<span class="itop-binding-person">'
			+ '<strong title="' + esc(identity) + '">' + esc(identity) + '</strong>'
			+ '<span class="itop-binding-muted' + (notFound ? ' not-found' : '')
			+ '" title="' + esc(secondTitle) + '">' + secondHtml + '</span>'
			+ '</span>'
			+ '</div>'
	}

	/**
	 * The icon slot on a bound row whose iTop profile is not established.
	 *
	 * Holds the slot's width open so the text beside it starts on the same
	 * edge as every other row's, and says nothing: `aria-hidden` rather than
	 * the `role="img"` a filled slot carries, because there is no state here
	 * to name.
	 *
	 * @return {string} HTML for an empty icon slot
	 */
	function emptyProfileSlot() {
		return '<span class="itop-person-profile" aria-hidden="true"></span>'
	}

	/**
	 * The icon for one iTop profile state.
	 *
	 * Drawn as a CSS background-image through a class rather than inlined:
	 * these are full-colour illustrations carrying fixed `linearGradient` ids,
	 * so inlining one per row would put 25 copies of every one of those ids in
	 * a single document. Ids resolve document-wide, which makes that the kind
	 * of thing that works until it quietly does not.
	 *
	 * A background image is invisible to assistive technology and this one
	 * carries meaning, so every state gets a name saying what it means rather
	 * than describing the picture. There is no artwork for an unestablished
	 * profile at all: claiming agent or portal access that was never
	 * established would be worse than saying nothing, so that case falls back
	 * to emptyProfileSlot() instead of a branch here.
	 *
	 * @param {string} profile 'portal' or 'agent'
	 * @return {string} HTML for the icon, or the empty slot for anything else
	 */
	function profileIcon(profile) {
		const labels = {
			agent: t('integration_itop', 'Service desk agent in iTop'),
			portal: t('integration_itop', 'Portal user in iTop'),
		}
		if (!Object.prototype.hasOwnProperty.call(labels, profile)) {
			return emptyProfileSlot()
		}
		return '<span class="itop-person-profile ' + profile + '" role="img"'
			+ ' aria-label="' + esc(labels[profile]) + '"></span>'
	}

	/**
	 * When and how a bound row came to be linked, as a title on its status cell.
	 *
	 * Additional to the method badge, not a replacement for it: a hover is
	 * invisible on a touch screen, so the badge stays on the page and this
	 * carries the one thing it has no room for -- the date.
	 *
	 * Either half can be missing on a binding made before this app recorded
	 * provenance, and both fall back to the same 'unknown' the badge already
	 * uses.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} a title attribute to splice into the cell's tag, or ''
	 */
	function describeBindingTitle(row) {
		if (!row.bound) {
			return ''
		}
		const unknown = t('integration_itop', 'unknown')
		const date = row.boundAt
			? new Date(row.boundAt * 1000).toLocaleString(getCanonicalLocale())
			: unknown
		// escape: false, then esc() once over the result: t() escapes its own
		// placeholders by default, which would double-escape everything esc()
		// then saw. esc() is what keeps this safe inside an attribute.
		return ' title="' + esc(t('integration_itop', 'Linked on {date} · method {method}',
			{ date, method: row.method || unknown }, undefined, { escape: false })) + '"'
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
			+ '">' + esc(t('integration_itop', 'matched on {value}', { value: row.sourceValue }, undefined, { escape: false })) + '</span>'
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
				+ esc(t('integration_itop', '{name} (person {id})', { name: s.itopName, id: s.personId }, undefined, { escape: false }))
				+ '</span>'
		case 'review':
			return '<span class="itop-binding-tier-compare">'
				+ esc(t('integration_itop', '{ncName} vs. {itopName}', { ncName: s.ncName, itopName: s.itopName }, undefined, { escape: false }))
				+ '</span>' + describeSourceValue(row)
		case 'ambiguous':
			return '<span class="itop-binding-badge tier-ambiguous">'
				+ esc(n('integration_itop', '{count} candidate', '{count} candidates', s.candidates.length, { count: s.candidates.length }))
				+ '</span>'
		case 'conflict':
			return '<span class="itop-binding-badge tier-conflict">'
				+ esc(t('integration_itop', 'already linked to {uid}', { uid: s.conflictUid }, undefined, { escape: false }))
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
			// the actions cell rather than the cell itself -- the <td> has to
			// survive as the row's cell in its column.
			instance.$mount(el)
			rowActions.push(instance)
		}
	}

	/**
	 * The markup for one row.
	 *
	 * A real <tr> in a real table, which is what fixed the column alignment
	 * this screen used to have: every row was its own CSS grid, so each one
	 * resolved `grid-template-columns` against its own content and the actions
	 * track came out a different width depending on whether that row's button
	 * said "Unlink" or "Link…". Columns in a table share a width by
	 * construction, and <th>/<td> is also what tells a screen reader this is
	 * tabular data.
	 *
	 * @param {object} row a row from /binding/users
	 * @return {string} HTML for one table row
	 */
	function renderRow(row) {
		const account = row.displayName || row.uid
		return '<tr class="itop-binding-row" data-uid="' + esc(row.uid) + '">'
			// Named after the account rather than left bare: twenty-five
			// unlabelled checkboxes in a column tell a screen reader user
			// nothing about which account each one selects.
			// The <label> carries no text -- aria-label on the input is what
			// names it, and takes precedence over an empty label anyway. It is
			// there to widen a 14px checkbox into a cell-sized click target.
			+ '<td class="itop-binding-col-select">'
			+ '<label class="itop-binding-select">'
			+ '<input type="checkbox" data-select="' + esc(row.uid) + '"'
			+ ' aria-label="' + esc(t('integration_itop', 'Select {account}', { account }, undefined, { escape: false })) + '"'
			+ (state.selected.has(row.uid) ? ' checked' : '') + '>'
			+ '</label>'
			+ '</td>'
			// Avatar and account text share one cell, so the names line up
			// against a single column edge. The flex container is the inner
			// div, not the <td>: `display: flex` on a table cell drops its
			// `table-cell` role, the browser wraps it in an anonymous cell,
			// and the column widths stop being the table's to decide -- which
			// is the one thing this rework exists to fix.
			+ '<td class="itop-binding-col-account">'
			+ '<div class="itop-binding-account-line">'
			// Same image NcAvatar fetches. alt is empty on purpose: the
			// display name sits next to it, so a label here would make
			// screen readers announce the account twice.
			+ '<img class="itop-binding-avatar" alt="" loading="lazy"'
			+ ' src="' + esc(generateUrl('/avatar/' + encodeURIComponent(row.uid) + '/32')) + '"'
			+ ' srcset="' + esc(generateUrl('/avatar/' + encodeURIComponent(row.uid) + '/64')) + ' 2x">'
			// Both lines are truncated with an ellipsis past the account
			// column's cap (see .itop-binding-account), so each carries its
			// own title to keep the full value reachable on hover/focus.
			+ '<span class="itop-binding-account"><strong title="' + esc(row.displayName) + '">' + esc(row.displayName) + '</strong>'
			+ '<span class="itop-binding-muted" title="' + esc(row.uid + (row.email ? ' · ' + row.email : '')) + '">' + esc(row.uid)
			+ (row.email ? ' · ' + esc(row.email) : '') + '</span></span>'
			+ '</div>'
			+ '</td>'
			// The provenance title goes on the cell rather than on the text
			// inside it, so the whole column is a hover target. The person
			// line below carries its own title for its own truncated value.
			+ '<td class="itop-binding-status"' + describeBindingTitle(row) + '>'
			+ describeStatus(row) + '</td>'
			// Empty mount point for this row's RowActions, filled by
			// mountRowActions() once the dynamic import lands. Vue 2 replaces
			// it rather than rendering inside it, so it needs a parent that
			// survives -- and that parent is the inner div rather than the
			// <td> for the same table-cell reason as the account cell above.
			+ '<td class="itop-binding-col-actions">'
			+ '<div class="itop-binding-actions">'
			+ '<span data-actions="' + esc(row.uid) + '"></span>'
			+ '</div>'
			+ '</td>'
			+ '</tr>'
	}

	/**
	 * Repaint the table, action bar and pager from state.
	 */
	function render() {
		const table = document.getElementById('itop-binding-table')
		if (!table) return

		// Before the innerHTML assignments below, which orphan them.
		destroyRowActions()
		renderSeq += 1

		if (state.rows.length === 0) {
			// No header either: a toggle-all checkbox over nothing is worse
			// than its absence.
			table.innerHTML = '<p class="itop-binding-muted">'
				+ esc(t('integration_itop', 'No accounts match this filter.')) + '</p>'
		} else {
			table.innerHTML = '<table class="itop-binding-grid">'
				+ '<thead><tr>'
				// The toggle-all input is the column header's whole content,
				// so its aria-label is what names the column; adding header
				// text beside it would only make the checkbox announce twice.
				+ '<th scope="col" class="itop-binding-col-select">'
				+ '<label class="itop-binding-select">'
				+ '<input type="checkbox" id="itop-binding-select-all"'
				+ ' aria-label="' + esc(t('integration_itop', 'Select all accounts on this page')) + '">'
				+ '</label>'
				+ '</th>'
				+ '<th scope="col">' + esc(t('integration_itop', 'Account')) + '</th>'
				+ '<th scope="col">' + esc(t('integration_itop', 'Status')) + '</th>'
				+ '<th scope="col" class="itop-binding-col-actions">' + esc(t('integration_itop', 'Actions')) + '</th>'
				+ '</tr></thead>'
				+ '<tbody>' + state.rows.map(renderRow).join('') + '</tbody>'
				+ '</table>'
		}

		syncSelectionBoxes()
		renderActionBar()
		renderPager()
		// Deliberately not awaited: render() is synchronous for every caller,
		// and the actions cells fill in on the microtask after the first
		// import resolves.
		mountRowActions(renderSeq)
	}

	/**
	 * Derive every checkbox on screen -- each row's and the header's -- from
	 * state.selected.
	 *
	 * The single place that does so, deliberately. The two directions used to
	 * be handled separately and drifted apart at once: toggling the header
	 * updated state.selected without touching the row checkboxes, so each one
	 * still rendered the `checked` it was painted with. Clicking a row then
	 * made an already-selected box checked, which *added* a uid that was
	 * already in the set -- so the selection never shrank and the header never
	 * reached the indeterminate state it should have. One function reading one
	 * source of truth is what stops that recurring.
	 *
	 * Called after every repaint as well as on every checkbox click, because
	 * the selection also changes with nobody touching a checkbox: a filter
	 * change or a page turn drops the selections for rows that left view (see
	 * load()).
	 *
	 * Cheaper than a render() on a click, which is the alternative: a repaint
	 * destroys and remounts one RowActions per row (~3 ms each, ~80 ms for a
	 * full page) to change nothing but a boolean on an <input>.
	 *
	 * `indeterminate` is a DOM property, not an attribute, so it cannot be
	 * written into the markup in the first place and has to be set here.
	 */
	function syncSelectionBoxes() {
		const table = document.getElementById('itop-binding-table')
		if (!table) return

		for (const box of table.querySelectorAll('input[data-select]')) {
			box.checked = state.selected.has(box.dataset.select)
		}

		const all = table.querySelector('#itop-binding-select-all')
		if (!all) return
		const selectedOnPage = state.rows.filter(r => state.selected.has(r.uid)).length
		all.checked = selectedOnPage > 0 && selectedOnPage === state.rows.length
		all.indeterminate = selectedOnPage > 0 && selectedOnPage < state.rows.length
	}

	/**
	 * Select or deselect every row on the current page.
	 *
	 * Scoped to the page on purpose: the server never hears about this, it
	 * only drives state.selected, and "all" meaning every account in the
	 * instance would let one click aim a bulk action at accounts the
	 * administrator has not even seen.
	 *
	 * @param {boolean} selected true to select the page, false to deselect it
	 */
	function setPageSelected(selected) {
		for (const row of state.rows) {
			if (selected) state.selected.add(row.uid)
			else state.selected.delete(row.uid)
		}
	}

	/**
	 * How many of the given accounts are unbound and currently hold an exact
	 * suggestion -- i.e. how many "Apply exact" would actually bind. An
	 * already-bound row is excluded even if its suggestion is exact: moving a
	 * person is the explicit two-step act (unlink, then apply), never a side
	 * effect of a bulk action.
	 *
	 * @param {string[]} uids accounts to count over
	 * @return {number} count
	 */
	function exactCountFor(uids) {
		const bound = new Set(state.rows.filter(r => r.bound).map(r => r.uid))
		return uids.filter(uid => {
			if (bound.has(uid)) return false
			const s = state.suggestions[uid]
			return s && s.status === 'resolved' && s.tier === 'exact'
		}).length
	}

	/**
	 * Push the current scope into the action bar.
	 *
	 * The bar has no access to state: it is told how many rows are selected,
	 * how many accounts its scope covers and how many of those hold an exact
	 * match, and it decides what to show from that alone.
	 */
	function renderActionBar() {
		if (!actionBar) return
		const scope = selectionOrPage()
		actionBar.selectedCount = state.selected.size
		actionBar.scopeSize = scope.length
		actionBar.exactCount = exactCountFor(scope)
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
	 * the action bar's primary button carries a spinner and is disabled for
	 * the duration, the same loading affordance used for every other async
	 * action here.
	 *
	 * @param {string[]} uids accounts to suggest matches for
	 */
	async function suggest(uids) {
		if (uids.length === 0) {
			showError(t('integration_itop', 'No accounts to suggest matches for'))
			return
		}
		if (actionBar) actionBar.busy = true
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
			if (actionBar) actionBar.busy = false
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
	 * The one mounted BindingActionBar, or null until its import lands.
	 *
	 * Mounted once for the lifetime of the page rather than rebuilt per
	 * repaint -- unlike the per-row menus, there is exactly one of it, so
	 * renderActionBar() assigns to its data instead and there is nothing to
	 * destroy.
	 *
	 * @type {?object}
	 */
	let actionBar = null

	/**
	 * Mount the scope-aware action bar and wire its events.
	 *
	 * Loaded on demand for the same reason as the scope picker: the Vue
	 * runtime is not parsed on a page without the association table.
	 */
	async function mountActionBar() {
		const el = document.getElementById('itop-binding-actions-bar')
		if (!el) return

		const { default: Vue } = await import('vue')
		const { default: BindingActionBar } = await import('./views/BindingActionBar.vue')

		const Bar = Vue.extend(BindingActionBar)
		actionBar = new Bar().$mount(el)

		// The bar only emits; every scope decision, guard and API call stays
		// here. "Suggest" and "Apply exact" take the selection when there is
		// one and the whole page otherwise -- that is the scope the bar's own
		// chip and counts describe.
		actionBar.$on('suggest', () => suggest(selectionOrPage()))
		actionBar.$on('apply-exact', () => applyExact(selectionOrPage()))
		// Selection-only, which is why the bar hides both with nothing
		// selected: excluding a whole page from matching is not something to
		// do without having picked the rows.
		actionBar.$on('exclude', () => setExcluded([...state.selected], true))
		actionBar.$on('restore', () => setExcluded([...state.selected], false))
		actionBar.$on('clear-selection', () => {
			state.selected.clear()
			render()
		})

		// The first load() may well have finished before this import did.
		renderActionBar()
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
	 * the table and pager are re-rendered on every repaint, so their controls
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

			// Reading .checked after the click gives the state the click just
			// produced, for the toggle-all as much as for a single row.
			const selectAll = e.target.closest('#itop-binding-select-all')
			if (selectAll) {
				setPageSelected(selectAll.checked)
				// Both branches re-derive every box from state.selected rather
				// than trusting the one the browser just toggled: the header
				// has to repaint the rows it selected, and a row has to
				// repaint the header above it.
				syncSelectionBoxes()
				renderActionBar()
				return
			}

			const selectBox = e.target.closest('input[data-select]')
			if (selectBox) {
				const uid = selectBox.dataset.select
				if (selectBox.checked) state.selected.add(uid)
				else state.selected.delete(uid)
				syncSelectionBoxes()
				renderActionBar()
				return
			}

			// Per-row actions are not delegated: they are NcActionButtons
			// inside each row's RowActions component, which emits to the
			// handlers wired in mountRowActions(). The bulk actions are not
			// delegated either, for the same reason -- BindingActionBar emits
			// to the handlers wired in mountActionBar().

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
		mountActionBar()
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
