<!--
  - Nextcloud - iTop Integration
  -
  - Admin: the picker opened from an ambiguous row's "Choose…" action.
  -
  - "Ambiguous" means the resolver found more than one iTop Person with
  - equal claim to the account and deliberately refused to guess; this
  - picker is where the administrator makes that call instead. It is seeded
  - with the suggestion's own candidates so the obvious choices need no
  - search, and falls back to a live search for anyone else.
  -->
<template>
	<NcModal size="small" @close="onCancel">
		<div class="itop-person-picker">
			<h2>{{ t('integration_itop', 'Choose the matching person') }}</h2>
			<NcSelect id="itop-person-picker-select"
				:value="selected"
				:options="options"
				:loading="loading"
				:filterable="false"
				:clearable="true"
				:placeholder="t('integration_itop', 'Search people in iTop…')"
				label="name"
				@search="onSearch"
				@input="onInput">
				<template #option="option">
					<span>{{ t('integration_itop', '{name} (person {id})', { name: option.name, id: option.person_id }) }}</span>
				</template>
			</NcSelect>
			<div class="itop-person-picker__actions">
				<NcButton @click="onCancel">
					{{ t('integration_itop', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!selected" @click="onChoose">
					{{ t('integration_itop', 'Choose') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PersonPicker',

	components: { NcButton, NcModal, NcSelect },

	props: {
		// The suggestion's own candidates, {person_id, user_id, name}[] —
		// shown immediately so the obvious choices need no search.
		candidates: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			options: this.candidates,
			loading: false,
			debounce: null,
			// The chosen person, bound to NcSelect so the field actually shows
			// it — see BindingScopePicker for why this matters.
			selected: null,
			// vue-select fires a synthetic empty @search right after a
			// selection or clear; the next onSearch call with an empty term
			// consumes this flag instead of re-emitting a redundant search.
			ignoreNextEmptySearch: false,
			// Search fetches can resolve out of order; only the response to
			// the most recently issued request is allowed to update the
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
		 * @param {string} term what the admin has typed
		 */
		onSearch(term) {
			const trimmed = term.trim()
			if (trimmed === '' && this.ignoreNextEmptySearch) {
				this.ignoreNextEmptySearch = false
				clearTimeout(this.debounce)
				return
			}
			this.ignoreNextEmptySearch = false

			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => {
				this.fetchCandidates(trimmed)
			}, 300)
		},

		/**
		 * @param {?object} option the chosen candidate, or null when cleared
		 */
		onInput(option) {
			this.selected = option
			// A selection (or an explicit clear via the ✕) is immediately
			// followed by a synthetic empty @search from vue-select; ignore
			// that one occurrence instead of letting it re-fetch a second
			// time or wipe out the selection just made.
			this.ignoreNextEmptySearch = true
			clearTimeout(this.debounce)
		},

		/**
		 * @param {string} term search term, already trimmed
		 */
		async fetchCandidates(term) {
			if (term === '') {
				// The endpoint returns [] for a blank term rather than every
				// person; fall back to the seeded candidates instead of
				// emptying a list the admin has not actually searched away.
				this.options = this.candidates
				return
			}

			const seq = ++this.requestSeq
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/integration_itop/binding/search-person?q=' + encodeURIComponent(term)),
					{ headers: { requesttoken: getRequestToken() } },
				)
				if (!response.ok) {
					throw new Error('HTTP ' + response.status)
				}
				const body = await response.json()
				// A newer request may have started and already won the race;
				// an older response must never clobber its fresher options.
				if (seq !== this.requestSeq) return
				this.options = body.candidates || []
			} catch (e) {
				if (seq !== this.requestSeq) return
				this.options = []
				showError(t('integration_itop', 'Could not search people in iTop: {msg}', { msg: e.message }))
			} finally {
				if (seq === this.requestSeq) {
					this.loading = false
				}
			}
		},

		onChoose() {
			if (!this.selected) return
			this.$emit('select', { person_id: this.selected.person_id, name: this.selected.name })
		},

		onCancel() {
			this.$emit('cancel')
		},
	},
}
</script>

<style scoped>
.itop-person-picker {
	padding: 20px;
}

.itop-person-picker__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
