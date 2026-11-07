// Shared by the i18n tools: reads translatable strings from PHP and parses .po files.
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const plugin = path.join(root, 'ai-bot-paywall');
export const poFile = path.join(plugin, 'languages', 'ai-bot-paywall-pt_BR.po');
export const moFile = path.join(plugin, 'languages', 'ai-bot-paywall-pt_BR.mo');

function phpFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) return phpFiles(full);
		return entry.name.endsWith('.php') ? [full] : [];
	});
}

/** Strings passed to WordPress translation functions, with the text domain. */
export function extractStrings() {
	const strings = new Set();
	const call = /\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'ai-bot-paywall'\s*\)/g;
	for (const file of phpFiles(plugin)) {
		for (const match of readFileSync(file, 'utf8').matchAll(call)) {
			strings.add(match[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\'));
		}
	}
	return strings;
}

const unquote = (line) => JSON.parse(line.replace(/\\'/g, "'"));

/** Parses a .po file into a Map of msgid to msgstr. The header has an empty msgid. */
export function parsePo(text) {
	const entries = new Map();
	let id = null;
	let str = null;
	let target = null;
	const flush = () => {
		if (id !== null) entries.set(id, str ?? '');
		id = null;
		str = null;
	};
	for (const raw of text.split(/\r?\n/)) {
		const line = raw.trim();
		if (line.startsWith('msgid ')) {
			flush();
			id = unquote(line.slice(6));
			target = 'id';
		} else if (line.startsWith('msgstr ')) {
			str = unquote(line.slice(7));
			target = 'str';
		} else if (line.startsWith('"')) {
			if (target === 'id') id += unquote(line);
			else if (target === 'str') str += unquote(line);
		}
	}
	flush();
	return entries;
}
