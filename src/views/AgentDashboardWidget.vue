<template>
	<div ref="agentDashboard"
		class="itop-agent-dashboard"
		role="region"
		:aria-label="t('integration_itop', 'Agent Dashboard')">
		<!-- Loading State -->
		<div v-if="loading" class="itop-agent-dashboard__loading">
			<div class="loading-spinner" />
			<p>{{ t('integration_itop', 'Loading agent dashboard...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="error" class="itop-agent-dashboard__error">
			<p>{{ error }}</p>
			<button class="itop-btn" @click="refresh">
				{{ t('integration_itop', 'Retry') }}
			</button>
		</div>

		<!-- Main Content -->
		<div v-else class="itop-agent-dashboard__content">
			<!-- Metrics Grid (2x2) -->
			<div class="agent-metrics-grid">
				<!-- My Work -->
				<div class="metric-card">
					<div class="metric-header">
						<span class="metric-icon">👤</span>
						<span class="metric-title">{{ t('integration_itop', 'My Work') }}</span>
					</div>
					<div class="metric-body">
						<div class="metric-line metric-line-clickable" @click="openItopPage('Incident:MyIncidents')">
							<span class="metric-number" :class="{ 'has-count': counts.my_incidents > 0, 'metric-info': counts.my_incidents > 0 }">{{ counts.my_incidents || 0 }}</span>
							<span class="metric-text">{{ counts.my_incidents === 1 ? t('integration_itop', 'Incident') : t('integration_itop', 'Incidents') }}</span>
						</div>
						<div class="metric-line metric-line-clickable" @click="openItopPage('UserRequest:MyRequests')">
							<span class="metric-number" :class="{ 'has-count': counts.my_requests > 0, 'metric-info': counts.my_requests > 0 }">{{ counts.my_requests || 0 }}</span>
							<span class="metric-text">{{ counts.my_requests === 1 ? t('integration_itop', 'Request') : t('integration_itop', 'Requests') }}</span>
						</div>
					</div>
				</div>

				<!-- Team Queue -->
				<div class="metric-card">
					<div class="metric-header">
						<span class="metric-icon">👥</span>
						<span class="metric-title">{{ t('integration_itop', 'Team Queue') }}</span>
					</div>
					<div class="metric-body">
						<div class="metric-line metric-line-clickable" @click="openItopPage('Incident:OpenIncidents')">
							<span class="metric-number" :class="{ 'has-count': counts.team_incidents > 0, 'metric-info': counts.team_incidents > 0 }">{{ counts.team_incidents || 0 }}</span>
							<span class="metric-text">{{ counts.team_incidents === 1 ? t('integration_itop', 'Incident') : t('integration_itop', 'Incidents') }}</span>
						</div>
						<div class="metric-line metric-line-clickable" @click="openItopPage('UserRequest:OpenRequests')">
							<span class="metric-number" :class="{ 'has-count': counts.team_requests > 0, 'metric-info': counts.team_requests > 0 }">{{ counts.team_requests || 0 }}</span>
							<span class="metric-text">{{ counts.team_requests === 1 ? t('integration_itop', 'Request') : t('integration_itop', 'Requests') }}</span>
						</div>
					</div>
				</div>

				<!-- SLA Warnings -->
				<div class="metric-card metric-card-clickable" @click="openItopPage('Incident:OpenIncidents')">
					<div class="metric-header">
						<span class="metric-icon">⚠️</span>
						<span class="metric-title">{{ t('integration_itop', 'SLA Warnings') }}</span>
					</div>
					<div class="metric-body">
						<div class="metric-line">
							<span class="metric-number" :class="{ 'has-count': counts.sla_warning_tto > 0, 'metric-warning-active': counts.sla_warning_tto > 0 }">{{ counts.sla_warning_tto || 0 }}</span>
							<span class="metric-text">{{ t('integration_itop', 'not assigned') }}</span>
						</div>
						<div class="metric-line">
							<span class="metric-number" :class="{ 'has-count': counts.sla_warning_ttr > 0, 'metric-warning-active': counts.sla_warning_ttr > 0 }">{{ counts.sla_warning_ttr || 0 }}</span>
							<span class="metric-text">{{ t('integration_itop', 'not resolved') }}</span>
						</div>
					</div>
				</div>

				<!-- SLA Breaches -->
				<div class="metric-card metric-card-clickable" @click="openItopPage('Incident:EscalatedIncidents')">
					<div class="metric-header">
						<span class="metric-icon">🚨</span>
						<span class="metric-title">{{ t('integration_itop', 'SLA Breaches') }}</span>
					</div>
					<div class="metric-body">
						<div class="metric-line">
							<span class="metric-number" :class="{ 'has-count': counts.sla_breaches_tto > 0, 'metric-error-active': counts.sla_breaches_tto > 0 }">{{ counts.sla_breaches_tto || 0 }}</span>
							<span class="metric-text">{{ t('integration_itop', 'not assigned') }}</span>
						</div>
						<div class="metric-line">
							<span class="metric-number" :class="{ 'has-count': counts.sla_breaches_ttr > 0, 'metric-error-active': counts.sla_breaches_ttr > 0 }">{{ counts.sla_breaches_ttr || 0 }}</span>
							<span class="metric-text">{{ t('integration_itop', 'not resolved') }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Changes Section -->
			<div class="upcoming-changes-section">
				<h4 class="section-title section-title-clickable" @click="openItopPage('Changes')">
					<span>{{ changeCounts.total }} {{ t('integration_itop', 'Changes') }}</span>
					<div class="section-badges">
						<span v-if="changeCounts.current > 0" class="status-badge status-open">{{ changeCounts.current }} Now</span>
						<span v-if="changeCounts.planned > 0" class="status-badge status-pending">{{ changeCounts.planned }} Plan</span>
						<span v-if="changeCounts.resolved > 0" class="status-badge status-resolved">{{ changeCounts.resolved }} Resolved</span>
					</div>
				</h4>
				<div v-if="upcomingChanges && upcomingChanges.length > 0" class="change-list">
					<div v-for="change in upcomingChanges.slice(0, 1)"
						:key="change.id"
						class="itop-change-item"
						@click="openTicket(change.url)">
						<img :src="getChangeIcon(change)" class="change-icon" :alt="change.status || 'change'">
						<div class="change-content">
							<div class="change-title">
								<span v-if="change.ref" class="change-ref">{{ change.ref }}:</span>
								{{ change.title || t('integration_itop', 'Untitled change') }}
							</div>
							<div class="change-meta">
								<span v-if="isCurrentChange(change)" class="change-current-details">
									started: {{ formatCompactDateTime(change.start_date) }} ends at {{ formatCompactDateTime(change.end_date) }}
								</span>
								<span v-else class="change-future-details">
									{{ getChangeStatusLabel(change.status) }}
									<span class="ticket-meta-separator">•</span>
									{{ formatChangeDateTime(change.start_date) }}
								</span>
							</div>
						</div>
					</div>
				</div>
				<div v-else class="change-list">
					<div class="itop-change-item no-changes">
						<img :src="iconPath('change.svg')" class="change-icon-translucent" :alt="t('integration_itop', 'No changes')">
						<div class="change-content">
							<p class="no-changes-text">
								{{ t('integration_itop', 'No Upcoming Changes') }}
							</p>
						</div>
					</div>
				</div>

				<!-- Action Buttons (matching portal widget style) -->
				<div class="itop-actions">
					<button class="itop-btn-refresh"
						:disabled="refreshing"
						@click="refresh">
						<span v-if="refreshing" class="spinner">↻</span>
						<span v-else>↻</span>
						{{ t('integration_itop', 'Refresh') }}
					</button>
					<a :href="itopUrl"
						class="itop-btn-view-all"
						target="_blank"
						rel="noopener noreferrer">
						{{ t('integration_itop', 'View All Tickets') }}
					</a>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AgentDashboardWidget',

	data() {
		return {
			loading: true,
			refreshing: false,
			error: null,
			myTickets: [],
			teamTickets: [],
			escalatedTickets: [],
			upcomingChanges: [],
			counts: {
				my_tickets: 0,
				my_incidents: 0,
				my_requests: 0,
				team_tickets: 0,
				team_incidents: 0,
				team_requests: 0,
				sla_warning_tto: 0,
				sla_warning_ttr: 0,
				sla_breaches_tto: 0,
				sla_breaches_ttr: 0,
				upcoming_changes: 0,
			},
			itopUrl: '',
			displayName: 'iTop',
		}
	},

	computed: {
		changeCounts() {
			if (!this.upcomingChanges || this.upcomingChanges.length === 0) {
				return { total: 0, current: 0, planned: 0, resolved: 0 }
			}

			const now = new Date()
			let current = 0
			let planned = 0
			let resolved = 0

			this.upcomingChanges.forEach(change => {
				const startDate = change.start_date ? new Date(change.start_date) : null
				const endDate = change.end_date ? new Date(change.end_date) : null

				// Check if resolved based on operational_status
				const isResolved = change.operational_status === 'closed' || change.operational_status === 'resolved'

				if (isResolved) {
					resolved++
				} else if (startDate && endDate && startDate <= now && endDate >= now) {
					// Current: ongoing (started but not yet ended)
					current++
				} else if (startDate && startDate > now) {
					// Planned: not yet started
					planned++
				}
			})

			return {
				total: this.upcomingChanges.length,
				current,
				planned,
				resolved,
			}
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		async loadData() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/integration_itop/agent-dashboard')
				const response = await axios.get(url)

				if (response.data.error) {
					this.error = response.data.error
					return
				}

				this.myTickets = response.data.myTickets || []
				this.teamTickets = response.data.teamTickets || []
				this.escalatedTickets = response.data.escalatedTickets || []
				this.upcomingChanges = response.data.upcomingChanges || []
				this.counts = response.data.counts || {}
				this.itopUrl = response.data.itop_url || ''
				this.displayName = response.data.display_name || 'iTop'
			} catch (error) {
				console.error('Error loading agent dashboard data:', error)
				this.error = error.response?.data?.error || t('integration_itop', 'Failed to load dashboard data')
				showError(t('integration_itop', 'Failed to load agent dashboard'))
			} finally {
				this.loading = false
			}
		},

		async refresh() {
			this.refreshing = true
			await this.loadData()
			this.refreshing = false
		},

		iconPath(filename) {
			// Use direct path to avoid routing issues
			return window.location.origin + '/apps/integration_itop/img/' + filename
		},

		openTicket(url) {
			if (url) {
				window.open(url, '_blank', 'noopener,noreferrer')
			}
		},

		openItopPage(menu) {
			if (this.itopUrl) {
				const url = `${this.itopUrl}/pages/UI.php?c%5Bmenu%5D=${menu}`
				window.open(url, '_blank', 'noopener,noreferrer')
			}
		},

		formatChangeDate(dateStr) {
			if (!dateStr) return ''

			try {
				const date = new Date(dateStr)
				const now = new Date()
				const diff = date - now
				const days = Math.ceil(diff / (1000 * 60 * 60 * 24))

				if (days === 0) {
					return t('integration_itop', 'Today')
				} else if (days === 1) {
					return t('integration_itop', 'Tomorrow')
				} else if (days > 1 && days <= 7) {
					return t('integration_itop', 'In {days} days', { days })
				} else {
					return date.toLocaleDateString()
				}
			} catch (e) {
				return dateStr
			}
		},

		formatChangeDateTime(dateStr) {
			if (!dateStr) return ''

			try {
				const date = new Date(dateStr)
				const now = new Date()
				const diff = date - now
				const days = Math.ceil(diff / (1000 * 60 * 60 * 24))

				// Format time as "2:00 AM"
				const timeStr = date.toLocaleTimeString('en-US', {
					hour: 'numeric',
					minute: '2-digit',
					hour12: true,
				})

				if (days === 0) {
					return `${t('integration_itop', 'Today')} ${timeStr}`
				} else if (days === 1) {
					return `${t('integration_itop', 'Tomorrow')} ${timeStr}`
				} else if (days > 1 && days <= 7) {
					return `${t('integration_itop', 'In {days} days', { days })} ${timeStr}`
				} else {
					return `${date.toLocaleDateString()} ${timeStr}`
				}
			} catch (e) {
				return dateStr
			}
		},

		isCurrentChange(change) {
			if (!change.start_date || !change.end_date) return false

			const now = new Date()
			const startDate = new Date(change.start_date)
			const endDate = new Date(change.end_date)

			return startDate <= now && endDate >= now
		},

		getChangeIcon(change) {
			// Use change-approved.svg if status is approved
			if ((change.status || '').toLowerCase() === 'approved') {
				return this.iconPath('change-approved.svg')
			}

			// Map to icon based on finalclass (change type)
			const finalclass = change.finalclass || 'Change'
			if (finalclass === 'EmergencyChange') {
				return this.iconPath('change-emergency.svg')
			} else if (finalclass === 'RoutineChange') {
				return this.iconPath('change-routine.svg')
			} else if (finalclass === 'NormalChange') {
				return this.iconPath('change-normal.svg')
			}

			// Default fallback
			return this.iconPath('change.svg')
		},

		getChangeStatusLabel(status) {
			if (!status) return ''

			// Map status codes to user-friendly labels
			const statusMap = {
				new: 'New',
				validated: 'Validated',
				rejected: 'Rejected',
				assigned: 'Assigned',
				plannedscheduled: 'Planned and scheduled',
				approved: 'Approved',
				notapproved: 'Not approved',
				implemented: 'Implemented',
				monitored: 'Monitored',
				closed: 'Closed',
			}

			return statusMap[status.toLowerCase()] || status
		},

		formatCompactDateTime(dateStr) {
			if (!dateStr) return ''

			try {
				const date = new Date(dateStr)
				// Format as dd.mm. hh:MM (compact format without year and seconds)
				const day = String(date.getDate()).padStart(2, '0')
				const month = String(date.getMonth() + 1).padStart(2, '0')
				const hours = String(date.getHours()).padStart(2, '0')
				const minutes = String(date.getMinutes()).padStart(2, '0')
				return `${day}.${month}. ${hours}:${minutes}`
			} catch (e) {
				return dateStr
			}
		},
	},
}
</script>

<style scoped lang="scss">
.itop-agent-dashboard {
	padding: 0px;
	min-height: 200px;
}

.itop-agent-dashboard__loading,
.itop-agent-dashboard__error {
	text-align: center;
	padding: 32px 16px;
}

.loading-spinner {
	width: 32px;
	height: 32px;
	margin: 0 auto 16px;
	border: 3px solid #f3f3f3;
	border-top: 3px solid #0082c9;
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

// 2x2 Metrics Grid
.agent-metrics-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 10px;
	margin: 0;
	padding: 0;
}

.metric-card {
	background: var(--color-background-hover);
	border-radius: 6px;
	padding: 12px;
	transition: all 0.2s ease;
}

.metric-card-clickable {
	cursor: pointer;

	&:hover {
		background: var(--color-primary-light);
		transform: translateY(-1px);
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	}
}

.metric-header {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border);
}

.metric-icon {
	font-size: 16px;
	line-height: 1;
}

.metric-title {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-main-text);
}

.metric-body {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.metric-line {
	display: flex;
	align-items: baseline;
	gap: 6px;
}

.metric-line-clickable {
	cursor: pointer;
	padding: 4px;
	margin: -4px;
	border-radius: 4px;
	transition: background 0.2s ease;

	&:hover {
		background: var(--color-primary-light);
	}
}

.metric-number {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	line-height: 1;
}

.metric-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

// Dynamic colors based on count values
.metric-info {
	color: var(--color-element-info);
}

.metric-warning-active {
	color: var(--color-element-warning);
}

.metric-error-active {
	color: var(--color-element-error);
}

.upcoming-changes-section {
	margin-top: 16px;
	margin-bottom: 16px;
}

.section-title {
	font-size: 15px;
	font-weight: 600;
	margin: 0 0 8px 0;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 3px;
	flex-wrap: wrap;
}

.section-title-clickable {
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 3px;
	margin-top: 8px;
	margin-bottom: 8px;
	border-radius: 4px;
	transition: background 0.2s ease;
	flex-wrap: wrap;
	width: 100%;

	&:hover {
		background: var(--color-primary-light);
		color: var(--color-primary-element);
	}
}

.section-badges {
	display: flex;
	gap: 3px;
	flex-wrap: wrap;
	justify-content: flex-end;
	align-items: center;
}

.change-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.change-icon {
	width: 24px;
	height: 24px;
	flex-shrink: 0;
	margin-top: 2px;
}

.change-content {
	flex: 1;
	min-width: 0;
}

.change-title {
	font-size: 12px;
	font-weight: 500;
	margin-bottom: 3px;
	color: var(--color-main-text);
}

.change-ref {
	color: var(--color-primary);
	font-weight: 600;
}

.change-meta {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.ticket-meta-separator {
	margin: 0 2px;
}

.change-current-details {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

.change-future-details {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	gap: 4px;
}

.change-status-current {
	color: var(--color-primary-element);
	font-weight: 600;
}

.itop-change-item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	background: var(--color-background-hover);
	border-radius: 6px;
	padding: 10px;
	cursor: pointer;
	transition: background 0.2s ease;

	&:hover {
		background: var(--color-primary-light);
	}
}

.itop-change-item.no-changes {
	cursor: default;
	padding: 16px 12px;

	&:hover {
		background: var(--color-background-hover);
	}
}

.change-icon-translucent {
	width: 24px;
	height: 24px;
	flex-shrink: 0;
	margin-top: 2px;
	opacity: 0.7;
}

.no-changes-text {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

// Action Buttons (matching portal widget style)
.itop-actions {
	display: flex;
	gap: 6px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}

.itop-btn-refresh {
	flex: 1;
	padding: 10px 5px;
	margin: 3px;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--itop-radius-md);
	font-size: 15px;
	font-weight: 500;
	cursor: pointer;
	transition: background 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;

	&:hover:not(:disabled) {
		background: var(--color-primary-element-hover);
	}

	&:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}
}

.itop-btn-view-all {
	flex: 1;
	padding: 10px 5px;
	margin: 3px;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--itop-radius-md);
	font-size: 15px;
	font-weight: 500;
	cursor: pointer;
	transition: background 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	text-decoration: none;

	&:hover {
		background: var(--color-primary-element-hover);
	}
}

.spinner {
	display: inline-block;
	animation: spin 1s linear infinite;
}
</style>
