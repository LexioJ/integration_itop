<!--
  - Nextcloud - iTop Integration
  -
  - Admin: the actions menu for one row of the user association table.
  -
  - A row can offer up to three actions, and three plain buttons do not fit
  - the actions column at any realistic translated label length -- they
  - wrapped onto a second line and doubled the row's height. NcActions with
  - `:inline="1"` renders the first child as a real button and collapses the
  - rest behind a "⋯" menu, so every row is one line tall whatever it
  - offers. A row with a single action renders that button alone, with no
  - "⋯" at all, which is why the children below are ordered rather than
  - conditionally grouped: the primary action for each kind of row is simply
  - the first one whose `v-if` holds.
  -
  - `force-name` is required, not cosmetic: NcActions renders an inline
  - action icon-only unless it is set, and "Apply" vs. "Unlink" is not
  - something an administrator should have to infer from a glyph on a screen
  - where one of the actions is irreversible.
  -
  - This component only emits. admin-binding.js owns every API call, the
  - confirmation dialogs and the reload that follows, so the guards that
  - protect a bound row from being silently re-pointed stay in one place.
  -->
<template>
	<NcActions :inline="1"
		force-name
		:aria-label="menuLabel">
		<!--
			Excluded takes precedence over every other state: an excluded row is
			never resolved and never bound, so Restore is the only thing it can
			offer and the only way back to the rest of this menu.
		-->
		<NcActionButton v-if="row.excluded"
			@click="$emit('restore', row.uid)">
			<template #icon>
				<RestoreIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Restore') }}
		</NcActionButton>

		<!--
			A bound row gets Unlink and nothing else that touches the binding.
			No Apply and no Confirm…, even when its suggestion is exact or
			review: apply-one trusts the administrator and carries no
			already-bound guard, so offering one here would be a one-click way
			to silently re-point a live binding. Moving a person stays the
			explicit two-step act of unlink, then apply.
		-->
		<NcActionButton v-else-if="row.bound"
			@click="$emit('unbind', row.uid)">
			<template #icon>
				<LinkVariantOffIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Unlink') }}
		</NcActionButton>

		<!-- Unbound, exact: the suggestion can be taken as it stands. -->
		<NcActionButton v-if="tier === 'exact'"
			@click="$emit('apply', row.uid)">
			<template #icon>
				<CheckIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Apply') }}
		</NcActionButton>

		<!--
			Unbound, review: the two names disagreed, so a human decides they
			are the same person. The ellipsis promises the confirmation that
			admin-binding.js shows.
		-->
		<NcActionButton v-else-if="tier === 'review'"
			@click="$emit('confirm', row.uid)">
			<template #icon>
				<AccountCheckIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Confirm…') }}
		</NcActionButton>

		<!--
			Unbound, ambiguous: the resolver found several people with equal
			claim and refused to guess, so this opens the picker seeded with
			those candidates. It is the manual-link route for this tier, which
			is why "Link…" is suppressed below rather than shown beside it --
			and why both carry the same ellipsis: they open the same picker,
			one seeded with candidates and one empty.
		-->
		<NcActionButton v-else-if="tier === 'ambiguous'"
			@click="$emit('choose', row.uid)">
			<template #icon>
				<AccountSearchIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Choose…') }}
		</NcActionButton>

		<!--
			Offered on every unbound, non-excluded row whose tier is not
			ambiguous -- including conflict, none, and no suggestion at all.
			That last case is the whole point of the feature: an account the
			matcher cannot resolve (no email to match on, say) would otherwise
			have no route to a link whatsoever. On an exact or review row it
			sits in the "⋯" menu as the escape hatch from a suggestion the
			administrator does not accept.
		-->
		<NcActionButton v-if="canLinkManually"
			@click="$emit('link', row.uid)">
			<template #icon>
				<LinkVariantPlusIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Link…') }}
		</NcActionButton>

		<NcActionButton v-if="!row.excluded"
			@click="$emit('exclude', row.uid)">
			<template #icon>
				<CancelIcon :size="20" />
			</template>
			{{ t('integration_itop', 'Exclude') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import AccountCheckIcon from 'vue-material-design-icons/AccountCheck.vue'
import AccountSearchIcon from 'vue-material-design-icons/AccountSearch.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import LinkVariantOffIcon from 'vue-material-design-icons/LinkVariantOff.vue'
import LinkVariantPlusIcon from 'vue-material-design-icons/LinkVariantPlus.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'RowActions',

	components: {
		NcActionButton,
		NcActions,
		AccountCheckIcon,
		AccountSearchIcon,
		CancelIcon,
		CheckIcon,
		LinkVariantOffIcon,
		LinkVariantPlusIcon,
		RestoreIcon,
	},

	props: {
		/**
		 * One row as returned by /binding/users.
		 */
		row: {
			type: Object,
			required: true,
		},

		/**
		 * That row's last /binding/suggest result, or null when matches have
		 * never been requested for it. Most rows are in the latter state --
		 * suggestions are computed on demand, never on load.
		 */
		suggestion: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The tier to act on, or null.
		 *
		 * Null for a bound or excluded row regardless of what the suggestion
		 * says, so none of the tier-driven actions below can appear on one:
		 * a stale suggestion must not outlive the state it was computed
		 * against. Null too for any non-resolved status (failed, unknown,
		 * excluded server-side), which carries no person to bind to.
		 *
		 * @return {?string} 'exact', 'review', 'ambiguous', 'conflict',
		 *                   'none', or null
		 */
		tier() {
			if (this.row.bound || this.row.excluded) return null
			if (!this.suggestion || this.suggestion.status !== 'resolved') return null
			return this.suggestion.tier
		},

		/**
		 * conflict and none deliberately reach this and not Apply: bind()
		 * refuses a conflict on every path, so an Apply button would promise
		 * something the server rejects. Both still need a manual route.
		 *
		 * @return {boolean} whether to offer the manual-link action
		 */
		canLinkManually() {
			if (this.row.bound || this.row.excluded) return false
			return this.tier !== 'ambiguous'
		},

		/**
		 * One menu per row, so the accessible name has to name the row --
		 * twenty-five identical "Actions" buttons tell a screen reader user
		 * nothing about which account they are about to change.
		 *
		 * escape: false and sanitize: false because Vue writes this straight
		 * into the attribute with setAttribute and no decoding step, so both of
		 * t()'s defaults only add entity noise -- a screen reader would announce
		 * "O&#39;Brien &amp; Co" to the one admin who depends on this label.
		 *
		 * @return {string} aria-label for the actions menu
		 */
		menuLabel() {
			return t('integration_itop', 'Actions for {account}',
				{ account: this.row.displayName || this.row.uid }, undefined, { escape: false, sanitize: false })
		},
	},

	methods: {
		t,
	},
}
</script>
