<template>
	<div class="itop-reference">
		<div v-if="isError">
			<h3>
				<ItopIcon :size="20" class="icon" />
				<span>{{ t('integration_itop', 'iTop API error') }}</span>
			</h3>
			<p v-if="richObject.error"
				class="widget-error">
				{{ richObject.error }}
			</p>
			<p v-else
				class="widget-error">
				{{ t('integration_itop', 'Unknown error') }}
			</p>
			<a :href="settingsUrl" class="settings-link external" target="_blank">
				<OpenInNewIcon :size="20" class="icon" />
				{{ t('integration_itop', 'iTop connected accounts settings') }}
			</a>
		</div>
		<div v-if="!isError" class="ticket-wrapper">
			<img :src="ticketIcon" class="ticket-icon" alt="">
			<div class="ticket-content">
				<!-- Row 1: Title | Status/Badges + Date -->
				<div class="row-1">
					<div class="left">
						<span v-if="!isCI && richObject.priority" class="priority-emoji">{{ priorityEmoji }}</span>
						<a :href="ticketUrl" class="ticket-link" target="_blank">
							<strong>{{ ticketTitle }}</strong>
						</a>
					</div>
					<div class="right">
						<!-- Ticket status -->
						<span v-if="!isCI && richObject.status" class="status-badge" :style="{ backgroundColor: statusBadgeColor, color: statusColor }">
							{{ richObject.status }}
						</span>
						<!-- CI badges -->
						<template v-if="isCI">
							<span v-for="badge in richObject.badges"
								:key="badge.label"
								class="ci-badge"
								:class="'badge-' + badge.type">
								{{ badge.label }}
							</span>
						</template>
						<!-- Dates -->
						<span v-if="richObject.close_date"
							v-tooltip.top="{ content: closedAtFormatted }"
							class="date-with-tooltip">
							&nbsp;· {{ closedAtText }}
						</span>
						<span v-else-if="richObject.last_update"
							v-tooltip.top="{ content: updatedAtFormatted }"
							class="date-with-tooltip">
							&nbsp;· {{ updatedAtText }}
						</span>
					</div>
				</div>
				<!-- Row 2: Service breadcrumb (Tickets) OR CI subtitle (CIs) -->
				<div class="row-2">
					<!-- Ticket service breadcrumb -->
					<span v-if="!isCI && serviceBreadcrumb" class="service-breadcrumb" v-html="serviceBreadcrumb" />
					<!-- CI subtitle: For PhysicalDevice show brand/model/serial inline; for others show regular subtitle -->
					<span v-if="isCI && physicalDeviceSubtitle" class="ci-subtitle physical-device">
						{{ physicalDeviceSubtitle }}
					</span>
					<span v-else-if="isCI && richObject.subtitle" class="ci-subtitle">
						{{ richObject.subtitle }}
					</span>
				</div>
				<!-- Row 3: Org/Team/Agent breadcrumb (Tickets) OR CI Chips (CIs) -->
				<div v-if="!isCI && orgTeamAgentBreadcrumb" class="row-3 ticket-org">
					<span class="org-breadcrumb" v-html="orgTeamAgentBreadcrumb" />
				</div>
				<div v-if="isCI && filteredChips.length > 0" class="row-3 ci-chips">
					<span v-for="chip in filteredChips" :key="chip.label" class="ci-chip">
						<span class="chip-icon">{{ getChipIcon(chip.icon) }}</span>
						<span class="chip-label">{{ chip.label }}</span>
					</span>
				</div>
				<!-- Row 4: CI Extras (class-specific fields) -->
				<div v-if="isCI && richObject.extras && richObject.extras.length > 0" class="row-4 ci-extras">
					<div v-for="extra in richObject.extras" :key="extra.label" class="ci-extra">
						<span v-if="extra.label" class="extra-label">{{ extra.label }}:</span>
						<span class="extra-value">{{ extra.value }}</span>
					</div>
				</div>
			</div>
		</div>
		<div v-if="!isError && richObject.description" class="description">
			<div v-tooltip.top="{ html: true, content: shortDescription ? t('integration_itop', 'Click to expand description') : undefined }"
				:class="{
					'description-content': true,
					'short-description': shortDescription,
				}"
				@click="shortDescription = !shortDescription">
				{{ richObject.description }}
			</div>
		</div>
	</div>
</template>

<script>
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

import ItopIcon from '../components/icons/ItopIcon.vue'

import { generateUrl } from '@nextcloud/router'
import moment from '@nextcloud/moment'

import { Tooltip } from '@nextcloud/vue'
import Vue from 'vue'

Vue.directive('tooltip', Tooltip)

export default {
	name: 'ReferenceItopWidget',

	components: {
		ItopIcon,
		OpenInNewIcon,
	},

	props: {
		richObjectType: {
			type: String,
			default: '',
		},
		richObject: {
			type: Object,
			default: null,
		},
		accessible: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			settingsUrl: generateUrl('/settings/user/connected-accounts#itop_prefs'),
			shortDescription: true,
		}
	},

	computed: {
		isError() {
			return !!this.richObject.error
		},
		isCI() {
			// Check if this is a CI (not a ticket)
			return this.richObjectType === 'integration_itop_ci'
		},
		isPhysicalDevice() {
			// PhysicalDevice classes: PC, Printer, Tablet, MobilePhone
			const physicalDeviceClasses = ['PC', 'Printer', 'Tablet', 'MobilePhone']
			return this.isCI && physicalDeviceClasses.includes(this.richObject.class)
		},
		physicalDeviceSubtitle() {
			if (!this.isPhysicalDevice || !this.richObject.chips) {
				return null
			}

			// Extract brand/model and serial number from chips
			const parts = []

			// Find brand/model chip (icon: 'tag')
			const brandModelChip = this.richObject.chips.find(chip => chip.icon === 'tag')
			if (brandModelChip) {
				parts.push(brandModelChip.label)
			}

			// Find serial number chip (icon: 'identifier')
			const serialChip = this.richObject.chips.find(chip => chip.icon === 'identifier')
			if (serialChip) {
				parts.push(serialChip.label)
			}

			return parts.length > 0 ? parts.join(' • ') : null
		},
		filteredChips() {
			if (!this.richObject.chips) {
				return []
			}

			// For PhysicalDevice classes, filter out brand/model and serial number chips
			// since they're displayed in the subtitle
			if (this.isPhysicalDevice) {
				return this.richObject.chips.filter(chip =>
					chip.icon !== 'tag' && chip.icon !== 'identifier',
				)
			}

			// For other CI types, show all chips
			return this.richObject.chips
		},
		ticketUrl() {
			return this.richObject.url
		},
		ticketTitle() {
			if (this.isCI) {
				// For CIs, show title directly
				return this.richObject.title
			}
			// For tickets, show ref + title
			return '[' + this.richObject.ref + '] ' + this.richObject.title
		},
		callerUrl() {
			// Build URL to person details if we have person_id
			if (this.richObject.itop_url && this.richObject.caller_id) {
				return this.richObject.itop_url + '/pages/UI.php?operation=details&class=Person&id=' + this.richObject.caller_id
			}
			return this.ticketUrl
		},
		agentUrl() {
			// Build URL to agent details if we have agent_id
			if (this.richObject.itop_url && this.richObject.agent_id) {
				return this.richObject.itop_url + '/pages/UI.php?operation=details&class=Person&id=' + this.richObject.agent_id
			}
			return null
		},
		ticketIcon() {
			// Determine class robustly: explicit rich object, URL param, or ref prefix
			const explicitClass = this.richObject.class || ''
			const fromUrl = this.getClassFromUrl(this.richObject.url)
			const fromRef = this.getClassFromRef(this.richObject.ref)
			const objectClass = explicitClass || fromUrl || fromRef || ''
			const status = this.richObject.status?.toLowerCase() || ''
			const closeDate = this.richObject.close_date || ''
			const priority = this.richObject.priority || ''

			// Use direct path without generateUrl to avoid /index.php/ prefix
			const basePath = window.location.origin + '/apps/integration_itop/img/'

			if (this.isCI) {
				// CI icons only; do not mix with ticket fallbacks
				const iconFile = this.richObject.icon || 'ci-default.svg'
				return basePath + iconFile
			}

			// If we cannot determine ticket class at all, use a generic ticket icon
			if (!objectClass) {
				return basePath + 'ticket.svg'
			}

			// Ticket icons with state-specific logic matching SearchProvider
			let iconName = ''
			const ticketType = objectClass.toLowerCase()

			// Check for closed state first (based on close_date)
			if (closeDate) {
				iconName = ticketType + '-closed.svg'
			} else if (priority && !isNaN(priority) && parseInt(priority) <= 2) {
				// Check for escalated state (high priority: 1 or 2)
				iconName = ticketType + '-escalated.svg'
			} else if (status.includes('pending') || status.includes('waiting')) {
				// Check for deadline state (pending/waiting status)
				iconName = ticketType + '-deadline.svg'
			} else {
				// Default icon for the ticket type
				iconName = ticketType + '.svg'
			}

			// Convert class names to match icon filenames
			iconName = iconName.replace('userrequest', 'user-request')

			return basePath + iconName
		},
		statusColor() {
			const status = this.richObject.status?.toLowerCase() || ''
			if (status.includes('resolved') || status.includes('closed')) {
				return '#28a745' // green
			}
			if (status.includes('assigned')) {
				return '#8b5cf6' // purple
			}
			if (status.includes('new')) {
				return '#3b82f6' // blue
			}
			if (status.includes('pending')) {
				return '#f59e0b' // orange
			}
			return '#ef4444' // red
		},
		statusBadgeColor() {
			const status = this.richObject.status?.toLowerCase() || ''
			if (status.includes('resolved') || status.includes('closed')) {
				return 'rgba(40, 167, 69, 0.15)' // light green
			}
			if (status.includes('assigned')) {
				return 'rgba(139, 92, 246, 0.15)' // light purple
			}
			if (status.includes('new')) {
				return 'rgba(59, 130, 246, 0.15)' // light blue
			}
			if (status.includes('pending')) {
				return 'rgba(245, 158, 11, 0.15)' // light orange
			}
			return 'rgba(239, 68, 68, 0.15)' // light red
		},
		priorityEmoji() {
			const priority = this.richObject.priority?.toLowerCase() || ''
			if (priority.includes('1') || priority.includes('critical')) {
				return '🔴'
			}
			if (priority.includes('2') || priority.includes('high')) {
				return '🟠'
			}
			if (priority.includes('3') || priority.includes('medium')) {
				return '🟡'
			}
			return '🟢'
		},
		serviceBreadcrumb() {
			const service = this.richObject.service_name
			const subcategory = this.richObject.servicesubcategory_name
			const caller = this.richObject.caller_id_friendlyname
			const org = this.richObject.org_name

			if (!service && !subcategory && !caller) {
				return null
			}

			const parts = []

			// Add service emoji and name if exists
			if (service) {
				parts.push('🏷️ ' + service)
			}

			// Add subcategory with breadcrumb separator if exists
			if (subcategory) {
				parts.push(' > ' + subcategory)
			}

			// Add caller with link if exists
			if (caller) {
				const callerLink = `<a href="${this.callerUrl}" target="_blank" class="author-link">${caller}</a>`
				const orgText = org ? ` (${org})` : ''
				parts.push(' for ' + callerLink + orgText)
			}

			return parts.join('')
		},
		orgTeamAgentBreadcrumb() {
			const org = this.richObject.org_id_friendlyname
			const team = this.richObject.team_id_friendlyname
			const agent = this.richObject.agent_id_friendlyname

			if (!org && !team && !agent) {
				return null
			}

			const parts = []

			// Add org if exists
			if (org) {
				parts.push('🏢 ' + org)
			}

			// Add team if exists
			if (team) {
				parts.push('👥 ' + team)
			}

			// Add agent with link if exists
			if (agent) {
				if (this.agentUrl) {
					parts.push(`<a href="${this.agentUrl}" target="_blank" class="agent-link">👤 ${agent}</a>`)
				} else {
					parts.push('👤 ' + agent)
				}
			}

			return parts.join(' > ')
		},
		createdAtFormatted() {
			return moment(this.richObject.creation_date).format('LLL')
		},
		closedAtFormatted() {
			return moment(this.richObject.close_date).format('LLL')
		},
		updatedAtFormatted() {
			return moment(this.richObject.last_update).format('LLL')
		},
		createdAtText() {
			return t('integration_itop', 'created {relativeDate}', { relativeDate: moment(this.richObject.creation_date).fromNow() })
		},
		closedAtText() {
			return t('integration_itop', 'closed {relativeDate}', { relativeDate: moment(this.richObject.close_date).fromNow() })
		},
		updatedAtText() {
			return t('integration_itop', 'updated {relativeDate}', { relativeDate: moment(this.richObject.last_update).fromNow() })
		},
	},

	methods: {
		getChipIcon(iconName) {
			// Map icon names to emoji/symbols
			const iconMap = {
				organization: '🏢',
				'map-marker': '📍',
				contacts: '👤',
				barcode: '🏷️',
				identifier: '#',
				tag: '🔖',
			}
			return iconMap[iconName] || '•'
		},
		getClassFromUrl(url) {
			try {
				if (!url) return ''
				const u = new URL(url)
				const cls = u.searchParams.get('class')
				return cls || ''
			} catch (e) {
				return ''
			}
		},
		getClassFromRef(ref) {
			if (!ref || typeof ref !== 'string') return ''
			const r = ref.toUpperCase()
			if (r.startsWith('I-')) return 'Incident'
			if (r.startsWith('R-')) return 'UserRequest'
			return ''
		},
	},
}
</script>

<style scoped lang="scss">
.itop-reference {
	width: 100%;
	white-space: normal;
	padding: 12px;

	a {
		padding: 0 !important;
		color: var(--color-main-text) !important;
		text-decoration: unset !important;
	}

	h3 {
		display: flex;
		align-items: center;
		font-weight: bold;
		margin-top: 0;
		.icon {
			margin-right: 8px;
		}
	}

	.ticket-wrapper {
		width: 100%;
		display: flex;
		flex-direction: row;
		align-items: start;
		gap: 12px;

		.ticket-icon {
			width: 48px;
			height: 48px;
			flex-shrink: 0;
			margin-top: 4px;
		}

		.ticket-content {
			flex: 1;
			min-width: 0;
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.row-1 {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 12px;

			.left {
				flex: 1;
				display: flex;
				align-items: center;
				gap: 4px;
				flex-wrap: wrap;
			}

			.right {
				display: flex;
				align-items: center;
				gap: 4px;
				flex-wrap: wrap;
				flex-shrink: 0;
			}

			.priority-emoji {
				font-size: 16px;
			}

			.ticket-link {
				strong {
					font-weight: 600;
				}
			}

			.status-badge {
				padding: 2px 8px;
				border-radius: var(--border-radius-pill);
				background-color: var(--color-background-dark);
				font-size: 12px;
			}

			.date-with-tooltip {
				color: var(--color-text-maxcontrast);
				font-size: 12px;
			}
		}

		.row-2 {
			font-size: 13px;
			color: var(--color-text-maxcontrast);

			.service-breadcrumb {
				::v-deep a {
					color: inherit !important;
					&:hover {
						color: #58a6ff !important;
					}
				}
			}

			.ci-subtitle {
				color: var(--color-text-maxcontrast);
			}
		}

		.row-3 {
			&.ticket-org {
				font-size: 13px;
				color: var(--color-text-maxcontrast);

				.org-breadcrumb {
					::v-deep a {
						color: inherit !important;
						&:hover {
							color: #58a6ff !important;
						}
					}
				}
			}

			&.ci-chips {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin-top: 4px;

				.ci-chip {
					display: inline-flex;
					align-items: center;
					gap: 4px;
					padding: 3px 8px;
					background-color: var(--color-background-hover);
					border-radius: var(--border-radius-pill);
					font-size: 12px;
					color: var(--color-text-maxcontrast);

					.chip-icon {
						font-size: 11px;
					}

					.chip-label {
						line-height: 1.2;
					}
				}
			}
		}

		.row-4 {
			&.ci-extras {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				margin-top: 6px;
				font-size: 12px;

				.ci-extra {
					display: flex;
					gap: 4px;

					.extra-label {
						font-weight: 500;
						color: var(--color-text-maxcontrast);
					}

					.extra-value {
						color: var(--color-main-text);
					}
				}
			}
		}

		// CI badges styling
		.ci-badge {
			padding: 2px 8px;
			border-radius: var(--border-radius-pill);
			font-size: 12px;
			font-weight: 500;

			&.badge-success {
				background-color: rgba(40, 167, 69, 0.15);
				color: #28a745;
			}

			&.badge-info {
				background-color: rgba(59, 130, 246, 0.15);
				color: #3b82f6;
			}

			&.badge-warning {
				background-color: rgba(245, 158, 11, 0.15);
				color: #f59e0b;
			}

			&.badge-error {
				background-color: rgba(239, 68, 68, 0.15);
				color: #ef4444;
			}

			&.badge-neutral {
				background-color: var(--color-background-dark);
				color: var(--color-text-maxcontrast);
			}
		}
	}

	.description {
		margin-top: 12px;
		padding-top: 12px;
		border-top: 1px solid var(--color-border);

		&-content {
			cursor: pointer;
			max-height: 250px;
			overflow: auto;
			color: var(--color-text-maxcontrast);
			white-space: pre-wrap;

			&.short-description {
				max-height: 40px;
				overflow: hidden;
				text-overflow: ellipsis;
			}
		}
	}

	::v-deep .author-link,
	::v-deep .agent-link,
	.slug-link {
		color: inherit !important;
	}

	.date-with-tooltip,
	::v-deep .author-link,
	::v-deep .agent-link,
	.slug-link,
	.ticket-link {
		&:hover {
			color: #58a6ff !important;
		}
	}

	.settings-link {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 4px;
		}
	}

	.widget-error {
		margin-bottom: 8px;
	}
}
</style>
