import './views/DashboardWidget.css'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('integration_itop', async (el, { widget }) => {
		const { default: Vue } = await import('vue')
		const { default: DashboardWidget } = await import('./views/DashboardWidget.vue')
		const View = Vue.extend(DashboardWidget)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})
})
