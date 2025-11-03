/**
 * @copyright Copyright (c) 2025 Integration Bot
 *
 * @author Integration Bot
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

import { registerWidget } from '@nextcloud/vue/components/NcRichText'

// Shared widget registration function
const registerItopWidget = (widgetType) => {
	try {
		registerWidget(widgetType, async (el, { richObjectType, richObject, accessible }) => {
			const { createApp } = await import('vue')
			const { default: ReferenceItopWidget } = await import('./views/ReferenceItopWidget.vue')

			const app = createApp(
				ReferenceItopWidget,
				{
					richObjectType,
					richObject,
					accessible,
				},
			)
			app.mixin({ methods: { t, n } })
			app.mount(el)
		}, () => {}, { hasInteractiveView: false })
	} catch (error) {
		// Widget already registered, this is fine - just ignore the error silently
	}
}

// Register both ticket and CI widgets
registerItopWidget('integration_itop_ticket')
registerItopWidget('integration_itop_ci')
