/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'
import eslint from 'vite-plugin-eslint'
/// <reference types="node" />

// Use static minification to avoid Node typings in lint

export default createAppConfig({
	reference: 'src/reference.js',
	dashboard: 'src/dashboard.js',
}, {
	config: {
		css: {
			modules: {
				localsConvention: 'camelCase',
			},
			preprocessorOptions: {
				scss: {},
			},
		},
		plugins: [eslint()],
		build: {
			outDir: '.',
			emptyOutDir: false,
		},
	},
	inlineCSS: { relativeCSSInjection: true },
	minify: true,
})
