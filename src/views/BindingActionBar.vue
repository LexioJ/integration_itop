<!--
  - Nextcloud - iTop Integration
  -
  - Admin: the single, scope-aware action bar above the user association table.
  -
  - There used to be two sets of bulk actions on this screen: a static toolbar
  - pair ("Suggest matches", "Apply exact only") that acted on the whole page,
  - and a pale panel that appeared under it when rows were selected, carrying
  - the same two labels plus three more. The same label appeared twice on
  - screen with two different scopes, and six plain buttons abreast wrapped
  - onto a second line.
  -
  - This is one bar instead, in the Files-app sense: it always acts on "the
  - current scope", which is the selection when there is one and the current
  - page otherwise. The selection chip is what states that scope, and
  - dismissing the chip is what clears the selection -- so no label is
  - duplicated and there is no second panel to appear and disappear.
  -
  - Width is a hard constraint here, not a preference: every label is
  - translated and German runs far longer than English. So exactly one
  - labelled button is ever on screen -- the primary -- and everything else
  - lives in one NcActions overflow, where a long label costs nothing because
  - the menu sizes itself. That is why the irreversible action (applying exact
  - matches) is a menu entry rather than a second button: it is the second step
  - of a two-step flow, its label has to carry a count to be honest about
  - scope, and a count-bearing translated label is exactly what cannot be
  - fitted abreast.
  -
  - The primary deliberately does not change identity with the selection.
  - Making it become "Apply N exact matches" once suggestions land would put an
  - irreversible bulk bind under a button the administrator last used for a
  - read-only suggestion pass.
  -
  - State arrives by assignment from admin-binding.js (this is an
  - imperatively-mounted root, so it has data rather than props), and this
  - component only emits. Every API call, guard and reload stays in
  - admin-binding.js.
  -->
<template>
	<div class="itop-binding-action-bar">
		<!--
			The one thing on this bar that states its scope. Its close button is
			also the only "clear selection" affordance, which is why that
			standalone button is gone.
		-->
		<NcChip v-if="selectedCount > 0"
			class="itop-binding-selection-chip"
			:text="selectionLabel"
			:aria-label-close="clearLabel"
			@close="$emit('clear-selection')" />

		<!--
			Read-only, so its page-wide scope when nothing is selected is safe
			to leave implicit. Disabled on an empty page so it cannot issue a
			request with no uids at all.
		-->
		<NcButton type="primary"
			:disabled="busy || scopeSize === 0"
			@click="$emit('suggest')">
			<template v-if="busy" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ busy ? t('integration_itop', 'Suggesting…') : t('integration_itop', 'Suggest matches') }}
		</NcButton>

		<NcActions :aria-label="menuLabel">
			<!--
				Names its own count because binding is the one act on this
				screen that is not trivially reversible -- a token-bound user
				who gets unlinked needs a new personal token -- so "how many
				accounts will this change" must not be left to the scope being
				inferred from the chip. Disabled rather than hidden when there
				is nothing to apply: a menu entry that comes and goes as
				suggestions land would move the entries below it.
			-->
			<NcActionButton :disabled="busy || exactCount === 0"
				@click="$emit('apply-exact')">
				<template #icon>
					<CheckAllIcon :size="20" />
				</template>
				{{ applyLabel }}
			</NcActionButton>

			<!--
				Selection-only, both of them. Excluding a whole page from
				matching on one menu click is not something to offer without
				the administrator having picked the rows first, and "Restore"
				is its counterpart rather than an independent action.
			-->
			<NcActionButton v-if="selectedCount > 0"
				:disabled="busy"
				@click="$emit('exclude')">
				<template #icon>
					<CancelIcon :size="20" />
				</template>
				{{ t('integration_itop', 'Exclude from matching') }}
			</NcActionButton>

			<NcActionButton v-if="selectedCount > 0"
				:disabled="busy"
				@click="$emit('restore')">
				<template #icon>
					<RestoreIcon :size="20" />
				</template>
				{{ t('integration_itop', 'Restore to matching') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'BindingActionBar',

	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcChip,
		NcLoadingIcon,
		CancelIcon,
		CheckAllIcon,
		RestoreIcon,
	},

	/**
	 * Mounted imperatively by admin-binding.js, which owns the table state and
	 * assigns these after every repaint. Props would be the wrong shape: Vue 2
	 * warns on mutating the props of a root instance, and there is no parent
	 * template here to bind them from.
	 *
	 * @return {object} initial bar state
	 */
	data() {
		return {
			/** Rows selected on the current page. 0 means "the bar acts on the page". */
			selectedCount: 0,
			/** How many accounts the current scope covers, selected or whole page. */
			scopeSize: 0,
			/** How many of those would actually be bound by "Apply exact". */
			exactCount: 0,
			/** A suggestion pass is in flight. */
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} the selection chip's text
		 */
		selectionLabel() {
			return n('integration_itop', '{count} selected', '{count} selected',
				this.selectedCount, { count: this.selectedCount })
		},

		/**
		 * @return {string} accessible name for the chip's close button
		 */
		clearLabel() {
			return t('integration_itop', 'Clear selection')
		},

		/**
		 * Scope-aware because the menu's contents differ between the two
		 * modes, and an overflow toggle called "More actions" would not say
		 * which accounts they are more actions for.
		 *
		 * @return {string} accessible name for the overflow menu
		 */
		menuLabel() {
			return this.selectedCount > 0
				? t('integration_itop', 'More actions for the selected accounts')
				: t('integration_itop', 'More actions for this page')
		},

		/**
		 * @return {string} label for the apply-exact menu entry
		 */
		applyLabel() {
			if (this.exactCount === 0) {
				return t('integration_itop', 'Apply exact only')
			}
			return n('integration_itop', 'Apply {count} exact match', 'Apply {count} exact matches',
				this.exactCount, { count: this.exactCount })
		},
	},

	methods: {
		t,
	},
}
</script>
