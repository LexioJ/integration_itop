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
