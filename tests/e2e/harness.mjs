// Shared E2E plumbing: a real WordPress on Playground and a stand-in facilitator.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

export const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

/** Facilitator double. `behavior` controls answers; `calls` records what it was sent. */
export async function startFacilitator() {
	const state = {
		calls: [],
		behavior: { verify: { isValid: true }, settle: null },
	};
	const server = createServer((req, res) => {
		let data = '';
		req.on('data', (chunk) => (data += chunk));
		req.on('end', () => {
			const body = data ? JSON.parse(data) : null;
			const route = req.url.split('?')[0];
			state.calls.push({ route, body });
			let answer;
			if (route === '/verify') {
				answer = state.behavior.verify;
			} else if (route === '/settle') {
				answer = state.behavior.settle ?? {
					success: true,
					transaction: `0x${'ab'.repeat(32)}`,
					network: body.paymentRequirements.network,
					payer: body.paymentPayload.payload.authorization.from,
				};
			} else if (route === '/supported') {
				answer = { kinds: [{ x402Version: 2, scheme: 'exact', network: 'eip155:84532' }, { x402Version: 1, scheme: 'exact', network: 'base-sepolia' }], extensions: [], signers: {} };
			} else {
				res.writeHead(404).end();
				return;
			}
			res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(answer));
		});
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	return {
		state,
		url: `http://127.0.0.1:${server.address().port}`,
		count: (route) => state.calls.filter((call) => call.route === route).length,
		last: (route) => state.calls.filter((call) => call.route === route).at(-1),
		reset() {
			state.calls.length = 0;
			state.behavior = { verify: { isValid: true }, settle: null };
		},
		close: () => server.close(),
	};
}

/** Boots WordPress with the plugin mounted and activated. */
export async function startWordPress({ port, php = '8.3', wp = 'latest' }) {
	const dir = mkdtempSync(path.join(tmpdir(), 'aibotpaywall-e2e-'));
	const blueprintPath = path.join(dir, 'blueprint.json');
	writeFileSync(
		blueprintPath,
		JSON.stringify({
			// A blueprint without this silently overrides the --php flag.
			preferredVersions: { php, wp },
			steps: [
				{ step: 'setSiteOptions', options: { permalink_structure: '/%postname%/', blogname: 'E2E Site' } },
				{ step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/aibotpaywall-e2e.php', data: readFileSync(path.join(root, 'tests/e2e/mu-plugin.php'), 'utf8') },
				{ step: 'activatePlugin', pluginPath: 'ai-bot-paywall/ai-bot-paywall.php' },
			],
		}),
	);

	const bin = path.join(root, 'node_modules/@wp-playground/cli/wp-playground.js');
	const child = spawn(
		process.execPath,
		[bin, 'server', '--port', String(port), '--php', php, '--wp', wp, '--workers', '2', '--define-bool', 'DISABLE_WP_CRON', 'true', '--mount-dir', path.join(root, 'ai-bot-paywall'), '/wordpress/wp-content/plugins/ai-bot-paywall', '--blueprint', blueprintPath, '--verbosity', 'quiet'],
		{ cwd: dir, stdio: ['ignore', 'pipe', 'pipe'] },
	);
	let log = '';
	child.stdout.on('data', (d) => (log += d));
	child.stderr.on('data', (d) => (log += d));

	const base = `http://127.0.0.1:${port}`;
	const deadline = Date.now() + 240_000;
	for (;;) {
		if (child.exitCode !== null) throw new Error(`Playground exited early:\n${log}`);
		if (Date.now() > deadline) throw new Error(`Playground did not start:\n${log}`);
		try {
			const res = await fetch(`${base}/?e2e=dump`);
			if (res.ok && (await res.json()).funnel) break;
		} catch {
			// Not listening yet.
		}
		await new Promise((resolve) => setTimeout(resolve, 1000));
	}
	return { base, stop: () => child.kill(), log: () => log };
}

/** Tiny assertion collector: keeps going so one run shows every failure. */
export function checker() {
	let passed = 0;
	const failures = [];
	return {
		eq(actual, expected, label) {
			const a = JSON.stringify(actual);
			const e = JSON.stringify(expected);
			if (a === e) {
				passed++;
			} else {
				failures.push(label);
				console.log(`FAIL ${label}\n  expected: ${e}\n  actual:   ${a}`);
			}
		},
		ok(value, label) {
			this.eq(Boolean(value), true, label);
		},
		section(name) {
			console.log(`-- ${name}`);
		},
		finish() {
			console.log(`\nE2E: ${passed} passed, ${failures.length} failed`);
			return failures.length === 0;
		},
	};
}
