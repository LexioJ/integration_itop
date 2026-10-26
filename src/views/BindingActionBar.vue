<!--
  - Nextcloud - iTop Integration
  -
  - Admin: the single, scope-aware action bar above the user association table.
  -
  - There used to be two sets of bulk actions on this screen: a static toolbar
  - pair ("Suggest matches", "Apply exact only") that acted on the whole page,
  - and a pale panel that appeared under it when rows were selected, carrying
  - the same two labels plus three more. The same label appeared twice on
  - screen with two different scopes.
  -
  - This is one bar instead, in the Files-app sense: it always acts on "the
  - current scope", which is the selection when there is one and the current
  - page otherwise. The selection chip is what states that scope, and
  - dismissing the chip is what clears the selection -- so no label is
  - duplicated and there is no second panel to appear and disappear.
  -
  - Every action is a labelled button, and the bar is allowed to wrap.
  -
  - That is the opposite of the rule the row actions follow, deliberately. In
  - a table row, width really is a hard constraint: a long translated label
  - there either wraps and makes one row taller than its neighbours or pushes
  - the row past the card, so RowActions collapses everything but one action
  - behind "⋯". This bar is its own full-width line and nothing is laid out
  - against it. A toolbar that needs two lines in German costs a few pixels of
  - vertical space in one locale; a toolbar that hides three of its four
  - actions behind "⋯" costs discoverability on every screen, in every
  - language, forever. The trade is not the same one, so it does not get the
  - same answer.
  -
  - There is also no NcActions here at all, which removes a real defect rather
  - than just a bad trade: NcActions renders a *single* available action inline
  - instead of as a menu, and an inline action is icon-only unless `force-name`
  - is set. With nothing selected only one entry remained, so "Apply exact
  - only" rendered as a bare double-checkmark -- an unexplainable control that
  - was still in the tab order. Nothing in this bar can render without a
  - label now: every control is an NcButton with text, and the chip's close
  - button carries `aria-label-close`.
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

		<!--
			Secondary, never primary: binding is the one act on this screen that
			is not trivially reversible -- a token-bound user who gets unlinked
			needs a new personal token -- so it does not get the visual weight
			that invites a reflex click.

			It names its own count for the same reason, so "how many accounts
			will this change" is never left to be inferred from the scope.
			Disabled rather than hidden when there is nothing to apply: an
			action that vanishes as suggestions land teaches nobody that it
			exists, and a disabled button with a zero count is self-explaining.
		-->
		<NcButton :disabled="busy || exactCount === 0"
			@click="$emit('apply-exact')">
			{{ applyLabel }}
		</NcButton>

		<!--
			Selection-only, both of them. Excluding a whole page from matching
			on one click is not something to offer without the administrator
			having picked the rows first, and "Restore to matching" is its
			counterpart rather than an independent action.
		-->
		<NcButton v-if="selectedCount > 0"
			:disabled="busy"
			@click="$emit('exclude')">
			{{ t('integration_itop', 'Exclude from matching') }}
		</NcButton>

		<NcButton v-if="selectedCount > 0"
			:disabled="busy"
			@click="$emit('restore')">
			{{ t('integration_itop', 'Restore to matching') }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'BindingActionBar',

	components: {
		NcButton,
		NcChip,
		NcLoadingIcon,
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
		 * @return {string} label for the apply-exact button
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
