#!/usr/bin/env node

/**
 * Convert Nextcloud l10n JSON files to JS files
 * Required for JavaScript/Vue translations to work
 */

const fs = require('fs');
const path = require('path');

const l10nDir = path.join(__dirname, '..', 'l10n');
const appId = 'integration_itop';

// Get all JSON files in l10n directory
const jsonFiles = fs.readdirSync(l10nDir).filter(f => f.endsWith('.json') && !f.endsWith('.tmp'));

jsonFiles.forEach(jsonFile => {
	const locale = path.basename(jsonFile, '.json');
	const jsonPath = path.join(l10nDir, jsonFile);
	const jsPath = path.join(l10nDir, `${locale}.js`);

	// Read JSON file
	const jsonData = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));

	// Extract translations and pluralForm
	const translations = jsonData.translations || {};
	const pluralForm = jsonData.pluralForm || 'nplurals=2; plural=(n != 1);';

	// Generate JS content
	const jsContent = `OC.L10N.register(
    "${appId}",
    ${JSON.stringify(translations, null, 4)},
    "${pluralForm}");
`;

	// Write JS file
	fs.writeFileSync(jsPath, jsContent, 'utf8');
	console.log(`✓ Generated ${jsPath}`);
});

console.log(`\n✅ Converted ${jsonFiles.length} translation files to JS format`);
