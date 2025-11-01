<template>
	<div ref="dashboard"
		class="itop-dashboard"
		role="region"
		:aria-label="t('integration_itop', 'Dashboard')">
		<!-- Loading State -->
		<div v-if="loading" class="itop-dashboard__loading">
			<div class="loading-spinner" />
			<p>{{ t('integration_itop', 'Loading dashboard...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="error" class="itop-dashboard__error">
			<p>{{ error }}</p>
			<button class="itop-btn" @click="refresh">
				{{ t('integration_itop', 'Retry') }}
			</button>
		</div>

		<!-- Main Content -->
		<div v-else class="itop-dashboard__content">
			<!-- Dashboard Content -->
			<template v-if="totalTickets > 0">
				<!-- Compact Header with Status Badges -->
				<div class="itop-header-compact itop-header-compact-clickable"
					@click="openPortalTickets">
					<h3 class="itop-header-compact__title">
						{{ totalTickets }} {{ t('integration_itop', totalTickets === 1 ? 'Ticket' : 'Tickets') }}
					</h3>
					<div class="itop-status-badges">
						<span v-if="byStatus.open" class="status-badge status-open">
							{{ byStatus.open }} {{ t('integration_itop', 'Open') }}
						</span>
						<span v-if="byStatus.escalated" class="status-badge status-escalated">
							{{ byStatus.escalated }} {{ t('integration_itop', 'Escalated') }}
						</span>
						<span v-if="byStatus.pending" class="status-badge status-pending">
							{{ byStatus.pending }} {{ t('integration_itop', 'Pending') }}
						</span>
						<span v-if="byStatus.resolved" class="status-badge status-resolved">
							{{ byStatus.resolved }} {{ t('integration_itop', 'Resolved') }}
						</span>
					</div>
				</div>

				<!-- Tickets List -->
				<div v-if="recentTickets.length === 0" class="itop-empty">
					{{ t('integration_itop', 'No recent tickets') }}
				</div>

				<div v-else class="itop-ticket-list">
					<div v-for="ticket in recentTickets"
						:key="ticket.type + '-' + ticket.id"
						class="itop-ticket-item"
						@click="openTicket(ticket.url)">
						<img :src="getTicketIcon(ticket)" alt="" class="ticket-svg">
						<div class="ticket-content">
							<div class="ticket-title" :title="getTicketTooltip(ticket)">
								<span class="ticket-emoji" :title="getStatusLabel(ticket.status)">{{ getStatusEmoji(ticket.status) }}</span>
								<span v-if="ticket.ref" class="ticket-ref">{{ ticket.ref }}:</span>
								{{ ticket.title || t('integration_itop', 'Untitled ticket') }}
							</div>
							<div class="ticket-meta">
								<span class="ticket-status">{{ getStatusLabel(ticket.operational_status || ticket.status) }}</span>
								<span class="ticket-meta-separator">•</span>
								<span class="ticket-priority-emoji" :title="getPriorityLabel(ticket.priority)">{{ getPriorityEmoji(ticket.priority) }}</span>
								<span v-if="ticket.last_update" class="ticket-meta-separator">•</span>
								<span v-if="ticket.last_update" class="ticket-time" :title="t('integration_itop', 'Last updated: ') + ticket.last_update">
									🕐 {{ formatRelativeTime(ticket.last_update) }}
								</span>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Empty State -->
			<div v-else-if="totalTickets === 0 && !loading" class="itop-empty-state">
				<div class="itop-empty-state__icon">
					<img :src="iconPath('ticket.svg')" alt="" class="empty-state-svg">
				</div>
				<h3>{{ t('integration_itop', 'No tickets found') }}</h3>
				<p>{{ t('integration_itop', 'You have no open tickets at the moment') }}</p>
			</div>

			<!-- Action Buttons -->
			<div class="itop-actions">
				<button class="itop-btn itop-btn-refresh"
					:disabled="refreshing"
					@click="refresh">
					<span v-if="refreshing" class="spinner">↻</span>
					<span v-else>↻</span>
					{{ t('integration_itop', 'Refresh') }}
				</button>
				<a :href="newTicketUrl"
					class="itop-btn itop-btn-new-ticket"
					target="_blank"
					rel="noopener noreferrer">
					<span>+</span>
					{{ t('integration_itop', 'New Ticket') }}
				</a>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'DashboardWidget',
	props: {
		title: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: true,
			refreshing: false,
			error: '',
			counts: {},
			byStatus: {},
			tickets: {},
			itopUrl: '',
			displayName: this.title || 'iTop',
		}
	},
	computed: {
		totalTickets() {
			// Calculate total from status badges (open + escalated + pending + resolved)
			const open = this.byStatus.open || 0
			const escalated = this.byStatus.escalated || 0
			const pending = this.byStatus.pending || 0
			const resolved = this.byStatus.resolved || 0
			const total = open + escalated + pending + resolved
			return total
		},
		recentTickets() {
		// Get up to 4 most recent tickets from all statuses
			const allTickets = []
			const statusOrder = ['open', 'escalated', 'pending', 'resolved']

			statusOrder.forEach(status => {
				if (this.tickets[status]) {
					allTickets.push(...this.tickets[status])
				}
			})

			// Sort by last_update or start_date, most recent first
			allTickets.sort((a, b) => {
				const dateA = a.last_update || a.start_date || ''
				const dateB = b.last_update || b.start_date || ''
				return dateB.localeCompare(dateA)
			})

			const tickets = allTickets.slice(0, 4)
			return tickets
		},
		viewAllUrl() {
			// URL to view all tickets in iTop
			const itopUrl = this.getItopUrl()
			return itopUrl ? `${itopUrl}/pages/UI.php` : '#'
		},
		newTicketUrl() {
			// URL to create new ticket in iTop portal
			const itopUrl = this.getItopUrl()
			return itopUrl ? `${itopUrl}/pages/exec.php/browse/services?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal` : '#'
		},
	},
	mounted() {
		// Fetch data on mount
		this.refresh()
	},
	methods: {
		iconPath(filename) {
			// Use direct path like ReferenceItopWidget to avoid routing issues
			return window.location.origin + '/apps/integration_itop/img/' + filename
		},
		getTicketIcon(ticket) {
			// Determine icon based on ticket type and status
			const statusRaw = (ticket.status || '').toLowerCase()
			const isIncident = ticket.type === 'Incident'
			const prefix = isIncident ? 'incident' : 'user-request'

			if (statusRaw === 'closed' || statusRaw === 'resolved') {
				return this.iconPath(`${prefix}-closed.svg`)
			} else if (statusRaw.includes('escalated')) {
				return this.iconPath(`${prefix}-escalated.svg`)
			} else if (statusRaw === 'pending' || statusRaw.includes('deadline')) {
				return this.iconPath(`${prefix}-deadline.svg`)
			} else if (isIncident && statusRaw === 'new') {
				return this.iconPath('incident-red.svg')
			}
			return this.iconPath(`${prefix}.svg`)
		},
		async refresh() {
			if (this.refreshing) return

			try {
				this.refreshing = true
				if (this.loading) {
				// First load - loading state is already true
				} else {
				// Subsequent refresh - set loading
					this.loading = true
				}
				const url = generateUrl('/apps/integration_itop/dashboard')
				const res = await axios.get(url)
				const data = res.data || {}

				if (data.error) {
					throw new Error(data.error)
				}

				// Use $set to ensure Vue reactivity
				Object.keys(data.counts || {}).forEach(key => {
					this.$set(this.counts, key, data.counts[key])
				})
				Object.keys((data.stats && data.stats.by_status) || {}).forEach(key => {
					this.$set(this.byStatus, key, data.stats.by_status[key])
				})
				Object.keys(data.tickets || {}).forEach(key => {
					this.$set(this.tickets, key, data.tickets[key])
				})
				this.itopUrl = data.itop_url || ''
				this.displayName = data.display_name || this.title || 'iTop'
				this.error = ''
				this.loading = false
			} catch (e) {
				this.error = e.response?.data?.error || this.t('integration_itop', 'Failed to refresh dashboard')
				this.loading = false
			} finally {
				this.refreshing = false
			}
		},
		priorityLevel(priority) {
			if (!priority) return 'low'
			const p = priority.toLowerCase()
			if (p.includes('critical') || p === '4') return 'critical'
			if (p.includes('high') || p === '3') return 'high'
			if (p.includes('medium') || p === '2') return 'medium'
			return 'low'
		},
		getStatusEmoji(status) {
			const s = (status || '').toLowerCase()

			// Status emoji mapping for Incident and UserRequest
			if (s === 'new') return '🆕' // 🆕 New
			if (s === 'assigned') return '👥' // 👥 Assigned
			if (s === 'pending') return '⏳' // ⏳ Pending
			if (s === 'escalated_tto' || s === 'escalated_ttr') return '⚠️' // ⚠️ Escalated
			if (s === 'resolved') return '✅' // ✅ Resolved
			if (s === 'closed') return '☑️' // ☑️ Closed

			// UserRequest-specific statuses
			if (s === 'waiting_for_approval') return '⏳' // ⏳ Waiting for approval (same as pending)
			if (s === 'approved') return '✅' // ✅ Approved (similar to resolved)
			if (s === 'rejected') return '❌' // ❌ Rejected

			return '⚪' // ⚪ Default white circle for unknown
		},
		getPriorityEmoji(priority) {
			if (!priority) return '🔵' // Blue circle for unknown
			const p = String(priority).toLowerCase()
			if (p.includes('1') || p.includes('critical')) return '🔴' // P1 Critical
			if (p.includes('2') || p.includes('high')) return '🟠' // P2 High
			if (p.includes('3') || p.includes('medium')) return '🟡' // P3 Medium
			if (p.includes('4') || p.includes('low')) return '🟢' // P4 Low
			return '⚪' // Default white circle
		},
		getStatusLabel(status) {
			const s = (status || '').toLowerCase()
			if (s === 'new') return this.t('integration_itop', 'New')
			if (s === 'assigned') return this.t('integration_itop', 'Assigned')
			if (s === 'ongoing') return this.t('integration_itop', 'Ongoing')
			if (s === 'pending') return this.t('integration_itop', 'Pending')
			if (s === 'escalated_tto') return this.t('integration_itop', 'Escalated TTO')
			if (s === 'escalated_ttr') return this.t('integration_itop', 'Escalated TTR')
			if (s === 'resolved') return this.t('integration_itop', 'Resolved')
			if (s === 'closed') return this.t('integration_itop', 'Closed')
			if (s === 'waiting_for_approval') return this.t('integration_itop', 'Waiting for approval')
			if (s === 'approved') return this.t('integration_itop', 'Approved')
			if (s === 'rejected') return this.t('integration_itop', 'Rejected')
			return status || this.t('integration_itop', 'Unknown')
		},
		getPriorityLabel(priority) {
			if (!priority) return this.t('integration_itop', 'Unknown priority')
			const p = String(priority).toLowerCase()
			if (p.includes('1') || p.includes('critical')) return this.t('integration_itop', 'Priority 1 - Critical')
			if (p.includes('2') || p.includes('high')) return this.t('integration_itop', 'Priority 2 - High')
			if (p.includes('3') || p.includes('medium')) return this.t('integration_itop', 'Priority 3 - Medium')
			if (p.includes('4') || p.includes('low')) return this.t('integration_itop', 'Priority 4 - Low')
			return this.t('integration_itop', 'Priority') + ' ' + priority
		},
		getTicketTooltip(ticket) {
			const ref = ticket.ref || this.t('integration_itop', 'N/A')
			const created = ticket.start_date || this.t('integration_itop', 'N/A')
			const updated = ticket.last_update || this.t('integration_itop', 'N/A')
			const title = ticket.title || this.t('integration_itop', 'Untitled ticket')

			// Sanitize description: remove HTML tags and convert <br> and <p> to newlines
			let description = ticket.description || this.t('integration_itop', 'No description')
			description = description
				.replace(/<br\s*\/?>/gi, '\n')
				.replace(/<\/p>/gi, '\n')
				.replace(/<p[^>]*>/gi, '')
				.replace(/<[^>]+>/g, '')
				.trim()

			return `${this.t('integration_itop', 'Ticket:')} ${ref}\n${this.t('integration_itop', 'Created:')} ${created}\n${this.t('integration_itop', 'Updated:')} ${updated}\n-------------------\n${this.t('integration_itop', 'Title:')} ${title}\n${this.t('integration_itop', 'Description:')}\n${description}`
		},
		formatRelativeTime(dateString) {
			if (!dateString) return ''

			try {
				const date = new Date(dateString)
				const now = new Date()
				const diffMs = now - date
				const diffSecs = Math.floor(diffMs / 1000)
				const diffMins = Math.floor(diffSecs / 60)
				const diffHours = Math.floor(diffMins / 60)
				const diffDays = Math.floor(diffHours / 24)
				const diffWeeks = Math.floor(diffDays / 7)

				if (diffSecs < 60) return this.t('integration_itop', 'just now')
				if (diffMins === 1) return this.t('integration_itop', '1 min ago')
				if (diffMins < 60) return `${diffMins} ${this.t('integration_itop', 'min ago')}`
				if (diffHours === 1) return this.t('integration_itop', '1 hour ago')
				if (diffHours < 24) return `${diffHours} ${this.t('integration_itop', 'hours ago')}`
				if (diffDays === 1) return this.t('integration_itop', '1 day ago')
				if (diffDays < 7) return `${diffDays} ${this.t('integration_itop', 'days ago')}`
				if (diffWeeks === 1) return this.t('integration_itop', '1 week ago')
				if (diffWeeks < 4) return `${diffWeeks} ${this.t('integration_itop', 'weeks ago')}`

				// For older dates, show actual date
				return date.toLocaleDateString()
			} catch (e) {
				return dateString
			}
		},
		getItopUrl() {
			// Return stored iTop URL
			return this.itopUrl || ''
		},
		openSearch() {
			// Trigger Nextcloud unified search filtered to iTop integration
			// Focus the search input which triggers the search modal
			const searchInput = document.querySelector('input[type="search"].unified-search__input')
			if (searchInput) {
				searchInput.focus()
				// Optionally set a search term to filter by iTop
				// searchInput.value = 'itop:'
				// searchInput.dispatchEvent(new Event('input', { bubbles: true }))
			}
		},
		openTicket(url) {
			if (url) {
				window.open(url, '_blank', 'noopener,noreferrer')
			}
		},
		openPortalTickets() {
			if (this.itopUrl) {
				const url = `${this.itopUrl}/pages/exec.php/manage/ongoing-tickets-for-portal-user?exec_module=itop-portal-base&exec_page=index.php&portal_id=itop-portal`
				window.open(url, '_blank', 'noopener,noreferrer')
			}
		},
	},
}
</script>

<style scoped>
/* Main Container */
.itop-dashboard {
	padding: 3px;
	font-family: var(--font-face);
	width: 100%;
	max-width: 100%;
	box-sizing: border-box;
	overflow: hidden;
}

/* Loading & Error States */
.itop-dashboard__loading,
.itop-dashboard__error {
	padding: 40px 20px;
	text-align: center;
}

.loading-spinner {
	width: 32px;
	height: 32px;
	border: 3px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
	margin: 0 auto 16px;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.itop-dashboard__error .itop-btn {
	margin-top: 12px;
}

/* Header */
.itop-header {
	margin-bottom: 20px;
	text-align: right;
}

.itop-btn-refresh {
	padding: 8px 5px;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border: none;
	margin: 3px;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 500;
	cursor: pointer;
	transition: background 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
}

.itop-btn-refresh:hover:not(:disabled) {
	background: var(--color-primary-element-hover);
}

.itop-btn-refresh:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.itop-btn-refresh .spinner {
	display: inline-block;
	animation: spin 1s linear infinite;
}

.itop-btn-new-ticket {
	padding: 8px 5px;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border: none;
	margin: 3px;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 500;
	cursor: pointer;
	transition: background 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	text-decoration: none;
}

.itop-btn-new-ticket:hover {
	background: var(--color-primary-element-hover);
}

.itop-btn-new-ticket span {
	font-size: 18px;
	line-height: 1;
}

/* Empty State */
.itop-empty-state {
	text-align: center;
	padding: 40px 20px;
}

.itop-empty-state__icon {
	font-size: 48px;
	margin-bottom: 16px;
}

.itop-empty-state h3 {
	margin: 0 0 8px;
	font-size: 18px;
	font-weight: 600;
}

.itop-empty-state p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Section */
.itop-section {
	margin-bottom: 20px;
	max-width: 100%;
	overflow: hidden;
}

.itop-section__title {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 12px;
	color: var(--color-main-text);
}

/* Status Grid */
.itop-status-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8px;
	margin-bottom: 8px;
	max-width: 100%;
}

.itop-status-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 12px 8px;
	text-align: center;
	transition: all 0.2s;
	min-width: 0;
}

.itop-status-card:hover {
	box-shadow: 0 2px 8px rgba(0,0,0,0.1);
	transform: translateY(-2px);
}

.status-icon {
	font-size: 24px;
	margin-bottom: 8px;
}

.status-count {
	font-size: 24px;
	font-weight: 700;
	color: var(--color-main-text);
	line-height: 1;
	margin-bottom: 4px;
}

.status-label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Ticket List */
.itop-ticket-list {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
	width: 100%;
	max-width: 100%;
	box-sizing: border-box;
}

.itop-ticket-item {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	width: 100%;
	max-width: 100%;
	box-sizing: border-box;
	overflow: hidden;
	cursor: pointer;
	transition: all 0.2s ease;
	outline: none;
}

.itop-ticket-item:hover {
	background: var(--color-primary-light);
	transform: translateY(-1px);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	outline: none;
	border-bottom-color: transparent !important;
	border-color: transparent !important;
}

.itop-ticket-item:last-child {
	border-bottom: none;
}

.ticket-icon {
	font-size: 20px;
	line-height: 1.4;
}

.ticket-content {
	flex: 1;
	min-width: 0;
	max-width: 100%;
	overflow: hidden;
}

.ticket-title {
	display: block;
	font-weight: 500;
	color: var(--color-main-text);
	margin-bottom: 4px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ticket-ref {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.ticket-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.ticket-meta span {
	white-space: nowrap;
}

.priority-critical {
	color: #bf2600;
	font-weight: 600;
}

.priority-high {
	color: #ff8b00;
	font-weight: 600;
}

.priority-medium {
	color: #ffab00;
	font-weight: 600;
}

.priority-low {
	color: #36b37e;
	font-weight: 600;
}

/* Empty message */
.itop-empty {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: 8px;
}

/* Action Buttons - styled in dashboard.css */

.itop-btn {
	padding: 10px 5px;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 500;
	margin: 3px;
	text-decoration: none;
	text-align: center;
	cursor: pointer;
	border: 1px solid var(--color-border);
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
}

.itop-btn-primary {
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	border-color: var(--color-primary-element);
}

.itop-btn-primary:hover {
	background: var(--color-primary-element-hover);
}

.itop-btn-secondary {
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.itop-btn-secondary:hover {
	background: var(--color-background-hover);
}

/* Responsive Design */
@media (max-width: 480px) {
	.itop-dashboard {
		padding: 8px;
		}

	.itop-ticket-item {
		gap: 8px;
		padding: 8px 12px;
	}

	.ticket-meta {
		flex-direction: column;
		gap: 4px;
		align-items: flex-start;
	}

	.itop-actions {
		flex-direction: column;
		gap: 8px;
	}

	.itop-btn,
	.itop-btn-refresh,
	.itop-btn-new-ticket {
		width: 100%;
		justify-content: center;
	}
}

@media (min-width: 481px) and (max-width: 768px) {
	.itop-actions {
		flex-wrap: wrap;
		gap: 8px;
	}

	.ticket-meta {
		gap: 8px;
	}
}

/* Clickable Header */
.itop-header-compact-clickable {
	cursor: pointer;
	border-radius: 4px;
	transition: background 0.2s ease;
	padding: 4px;
	padding-bottom: 5px;
	margin: 0px;
}

.itop-header-compact-clickable:hover {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

</style>
