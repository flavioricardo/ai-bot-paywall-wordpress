// Fails when a translatable string has no pt_BR translation, when a translation
// has no string left, or when placeholders differ. Pass --list to print the strings.
import { existsSync, readFileSync } from 'node:fs';
import { extractStrings, moFile, parsePo, poFile } from './i18n-lib.mjs';

const strings = extractStrings();

if (process.argv.includes('--list')) {
	for (const s of [...strings].sort()) console.log(JSON.stringify(s));
	process.exit(0);
}

const translations = parsePo(readFileSync(poFile, 'utf8'));
const placeholders = (s) => (s.match(/%(\d+\$)?[sd]/g) ?? []).sort().join();
const errors = [];

for (const s of strings) {
	const translated = translations.get(s);
	if (!translated) errors.push(`no translation: ${s}`);
	else if (placeholders(s) !== placeholders(translated)) errors.push(`placeholders differ: ${s}`);
}
for (const id of translations.keys()) {
	if (id !== '' && !strings.has(id)) errors.push(`unused translation: ${id}`);
}
if (!existsSync(moFile)) errors.push('compiled .mo missing: run npm run build:i18n');

for (const e of errors) console.log(e);
console.log(`i18n: ${strings.size} strings, ${errors.length} problems`);
process.exit(errors.length ? 1 : 0);
