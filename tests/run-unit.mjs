// Runs tests/unit/run.php on each supported PHP version through php-wasm, so
// neither contributors nor CI need PHP installed.
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const versions = process.env.PHP_VERSIONS ? process.env.PHP_VERSIONS.split(',') : ['7.4', '8.3'];
let failed = false;

for (const version of versions) {
	const php = new PHP(await loadNodeRuntime(version, { emscriptenOptions: { processId: process.pid } }));
	php.mkdir('/app');
	await php.mount('/app', createNodeFsMountHandler(root));
	const result = await php.runStream({ scriptPath: '/app/tests/unit/run.php' });
	const text = await result.stdoutText;
	const errors = await result.stderrText;
	const exit = await result.exitCode;
	console.log(`--- PHP ${version} ---\n${text}${errors}`);
	if (exit !== 0) failed = true;
	php.exit();
}

process.exit(failed ? 1 : 0);
