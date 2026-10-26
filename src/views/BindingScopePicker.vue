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
	<NcSelect id="itop-binding-scope-select"
		:value="selected"
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
				<NcAvatar v-if="option.type === 'account'"
					:user="option.id"
					:display-name="option.label"
					:size="24"
					:disable-menu="true"
					:disable-tooltip="true" />
				<AccountGroupIcon v-else-if="option.type === 'group'" :size="24" />
				<AccountMultipleIcon v-else :size="24" />
				<span class="itop-scope-option__label">{{ option.label }}</span>
				<span class="itop-scope-option__type">{{ option.typeLabel }}</span>
			</span>
		</template>
	</NcSelect>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'BindingScopePicker',

	components: { NcAvatar, NcSelect, AccountGroupIcon, AccountMultipleIcon },

	data() {
		return {
			options: [],
			loading: false,
			debounce: null,
			// The chosen scope, bound to NcSelect so the field actually shows
			// it — without this the dropdown silently narrows the table while
			// still displaying its placeholder.
			selected: null,
			// vue-select fires a synthetic empty @search right after a
			// selection or clear; the next onSearch call with an empty term
			// consumes this flag instead of re-emitting a redundant 'search'.
			ignoreNextEmptySearch: false,
			// Suggestion fetches can resolve out of order; only the response
			// to the most recently issued request is allowed to update the
			// dropdown.
			requestSeq: 0,
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
		 * vue-select also fires this with an empty string right after a
		 * selection or a clear; that synthetic event is consumed via
		 * `ignoreNextEmptySearch` rather than treated as the admin clearing
		 * the field by hand, which would otherwise re-trigger a redundant
		 * table reload (or, worse, race the scope that was just chosen).
		 *
		 * @param {string} term what the admin has typed
		 */
		onSearch(term) {
			const trimmed = term.trim()
			if (trimmed === '' && this.ignoreNextEmptySearch) {
				this.ignoreNextEmptySearch = false
				clearTimeout(this.debounce)
				return
			}
			// A real typed term closes the window in which a stale flag
			// could wrongly swallow a later, genuine empty search.
			this.ignoreNextEmptySearch = false

			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => {
				// Scope and free text are alternatives, not filters that
				// stack: typing a real term abandons whatever was selected,
				// in the component's own display and in the parent's state
				// (the 'search' event's non-empty term tells the parent to
				// drop state.scope).
				if (trimmed !== '' && this.selected) {
					this.selected = null
				}
				this.$emit('search', trimmed)
				this.fetchSuggestions(trimmed)
			}, 300)
		},

		/**
		 * @param {?object} option the chosen suggestion, or null when cleared
		 */
		onInput(option) {
			this.selected = option
			// A selection (or an explicit clear via the ✕) is immediately
			// followed by a synthetic empty @search from vue-select; ignore
			// that one occurrence instead of letting it reload the table a
			// second time or wipe out the selection just made.
			this.ignoreNextEmptySearch = true
			clearTimeout(this.debounce)
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

			const seq = ++this.requestSeq
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
				// A newer request may have started and already won the race;
				// an older response must never clobber its fresher options.
				if (seq !== this.requestSeq) return
				this.options = [
					...this.decorate(body.accounts, t('integration_itop', 'Account')),
					...this.decorate(body.groups, t('integration_itop', 'Group')),
					// Omitted entirely when the circles app is disabled, rather
					// than shown as a section that can never match.
					...(body.teamsAvailable ? this.decorate(body.teams, t('integration_itop', 'Team')) : []),
				]
			} catch (e) {
				if (seq !== this.requestSeq) return
				this.options = []
				showError(t('integration_itop', 'Could not search accounts, groups or teams: {msg}', { msg: e.message }))
			} finally {
				if (seq === this.requestSeq) {
					this.loading = false
				}
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
	align-items: center;
	gap: 8px;
}

.itop-scope-option__label {
	flex: 1 1 auto;
}

.itop-scope-option__type {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}
</style>
