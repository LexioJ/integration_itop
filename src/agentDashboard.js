import { translate, translatePlural } from '@nextcloud/l10n'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('integration_itop_agent', async (el, { widget }) => {
		const { default: Vue } = await import('vue')
		const { default: AgentDashboardWidget } = await import('./views/AgentDashboardWidget.vue')
		Vue.prototype.t = translate
		Vue.prototype.n = translatePlural
		Vue.prototype.OC = OC
		const View = Vue.extend(AgentDashboardWidget)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})
})
