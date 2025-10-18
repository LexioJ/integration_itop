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
			// WARNING: emptyOutDir: false doesn't work as expected with @nextcloud/vite-config
			// It still deletes ALL files in js/ before building
			// WORKAROUND: admin-settings.js and personal-settings.js are restored from git after build
			// The generated .mjs files are in .gitignore
			emptyOutDir: false,
		},
	},
	inlineCSS: { relativeCSSInjection: true },
	minify: isProduction,
})
