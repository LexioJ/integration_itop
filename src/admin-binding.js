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
				+ (row.bound
					? '<button type="button" data-unbind="' + esc(row.uid) + '">'
					+ esc(t('integration_itop', 'Unlink')) + '</button>'
					: '')
				+ (row.excluded
					? '<button type="button" data-restore="' + esc(row.uid) + '">' + esc(t('integration_itop', 'Restore')) + '</button>'
					: '<button type="button" data-exclude="' + esc(row.uid) + '">' + esc(t('integration_itop', 'Exclude')) + '</button>')
				+ '</span>'
				+ '</div>',
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
		pager.innerHTML
			= '<button type="button" id="itop-binding-prev"' + (state.page <= 1 ? ' disabled' : '') + '>‹</button>'
			+ '<span>' + esc(t('integration_itop', 'Page {page} of {pages} · {total} account(s)',
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

			const rowUnbind = e.target.closest('button[data-unbind]')
			if (rowUnbind) { unbind(rowUnbind.dataset.unbind); return }

			if (e.target.closest('#itop-binding-bulk-exclude')) {
				setExcluded([...state.selected], true); return
			}
			if (e.target.closest('#itop-binding-bulk-restore')) {
				setExcluded([...state.selected], false); return
			}
			if (e.target.closest('#itop-binding-bulk-clear')) {
				state.selected.clear(); render(); return
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
		load()
	})
})()
