// Compiles the pt_BR .po into a .mo, so contributors do not need gettext installed.
import { readFileSync, writeFileSync } from 'node:fs';
import { moFile, parsePo, poFile } from './i18n-lib.mjs';

const entries = [...parsePo(readFileSync(poFile, 'utf8'))].sort(([a], [b]) => (Buffer.from(a) < Buffer.from(b) ? -1 : 1));
const ids = entries.map(([id]) => Buffer.from(id));
const strs = entries.map(([, str]) => Buffer.from(str));

const HEADER = 28;
const tableOffset = HEADER + entries.length * 16;
const header = Buffer.alloc(HEADER);
header.writeUInt32LE(0x950412de, 0);
header.writeUInt32LE(0, 4);
header.writeUInt32LE(entries.length, 8);
header.writeUInt32LE(HEADER, 12);
header.writeUInt32LE(HEADER + entries.length * 8, 16);
header.writeUInt32LE(0, 20);
header.writeUInt32LE(tableOffset, 24);

let offset = tableOffset;
const table = (buffers) =>
	Buffer.concat(
		buffers.map((buffer) => {
			const row = Buffer.alloc(8);
			row.writeUInt32LE(buffer.length, 0);
			row.writeUInt32LE(offset, 4);
			offset += buffer.length + 1;
			return row;
		}),
	);
const idTable = table(ids);
const strTable = table(strs);
const NUL = Buffer.from([0]);
const data = Buffer.concat([...ids, ...strs].flatMap((buffer) => [buffer, NUL]));

writeFileSync(moFile, Buffer.concat([header, idTable, strTable, data]));
console.log(`Wrote ${entries.length} entries to ${moFile}`);
