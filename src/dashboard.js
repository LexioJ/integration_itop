import './views/DashboardWidget.css'
import { translate, translatePlural } from '@nextcloud/l10n'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('integration_itop', async (el, { widget }) => {
		const { default: Vue } = await import('vue')
		const { default: DashboardWidget } = await import('./views/DashboardWidget.vue')
		Vue.prototype.t = translate
		Vue.prototype.n = translatePlural
		Vue.prototype.OC = OC
		const View = Vue.extend(DashboardWidget)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})
})
