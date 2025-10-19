/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'
import eslint from 'vite-plugin-eslint'

const isProduction = process.env.NODE_ENV === 'production'

export default createAppConfig({
	reference: 'src/reference.js',
}, {
	config: {
		css: {
			modules: {
				localsConvention: 'camelCase',
			},
			preprocessorOptions: {
				scss: {
					api: 'modern-compiler',
				},
			},
		},
		plugins: [eslint()],
		build: {
			outDir: '.',
			emptyOutDir: false,
		},
	},
	inlineCSS: { relativeCSSInjection: true },
	minify: isProduction,
})
