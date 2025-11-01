document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('integration_itop_agent', async (el, { widget }) => {
		const { default: Vue } = await import('vue')
		const { default: AgentDashboardWidget } = await import('./views/AgentDashboardWidget.vue')
		const View = Vue.extend(AgentDashboardWidget)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})
})
