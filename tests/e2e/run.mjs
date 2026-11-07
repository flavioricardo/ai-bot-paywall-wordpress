// End-to-end suite: real WordPress, simulated bots, stand-in facilitator, and the
// official x402 client signing real EIP-712 payloads with a throwaway key.
import { createHash, generateKeyPairSync, sign as edSign } from 'node:crypto';
import { wrapFetchWithPayment, x402Client } from '@x402/fetch';
import { ExactEvmScheme } from '@x402/evm';
import { ExactEvmSchemeV1 } from '@x402/evm/v1';
import { generatePrivateKey, privateKeyToAccount } from 'viem/accounts';
import { verifyTypedData } from 'viem';
import { CHROME, checker, startFacilitator, startWordPress } from './harness.mjs';

const WALLET = '0x209693Bc6afc0C5328bA36FaF03C514EF312287C';
const USDC_SEPOLIA = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.3; +https://openai.com/gptbot';
const SEARCHBOT = 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)';

const POLICIES = { ai_training: { action: 'charge', price: '' }, ai_search: { action: 'allow', price: '' }, ai_agent: { action: 'charge', price: '' }, seo: { action: 'allow', price: '' }, script: { action: 'charge', price: '' }, fake: { action: 'charge', price: '' } };

const t = checker();
const facilitator = await startFacilitator();
const wp = await startWordPress({ port: Number(process.env.E2E_PORT ?? 9431), php: process.env.E2E_PHP ?? '8.3' });
const { base } = wp;

const control = async (name, body) => {
	const res = await fetch(`${base}/?e2e=${name}`, body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) });
	const text = await res.text();
	try {
		return JSON.parse(text);
	} catch {
		throw new Error(`Control ${name} returned ${res.status}:\n${text.slice(0, 1500)}\n--- server log ---\n${wp.log().slice(-1500)}`);
	}
};
const visit = (url, { ua = CHROME, ip, headers = {}, method = 'GET' } = {}) =>
	fetch(url.startsWith('http') ? url : base + url, {
		method,
		redirect: 'manual',
		headers: { 'User-Agent': ua, ...(ip ? { 'X-Real-IP': ip } : {}), ...headers },
	});
const decode = (value) => JSON.parse(Buffer.from(value, 'base64').toString('utf8'));
const dump = () => control('dump');
const bot = (d, key, identity) => d.bots.find((row) => row.bot === key && row.identity === identity);
const fresh = async (settings = {}) => {
	facilitator.reset();
	await control('reset');
	await control('settings', { mode: 'active', ack_ai: true, content_rules: '', bot_rules: {}, default_price: '0.01', currency: 'USD', policies: POLICIES, ...settings });
};

try {
	await control('settings', { wallet: WALLET, network: 'base-sepolia', facilitator: facilitator.url, client_ip_header: 'X-Real-IP', show_brl: false, mode: 'monitor' });
	const version = (await dump()).php;
	t.ok(version.startsWith(process.env.E2E_PHP ?? '8.3'), `running on the requested PHP (${version})`);
	const seeded = await control('seed');
	const POST = new URL(seeded.permalink).pathname;
	const BODY = 'Secret article body';
	const SINGLE = '/?p=' + seeded.id;

	t.section('Monitor mode changes nothing');
	await control('reset');
	let res = await visit(POST);
	t.eq(res.status, 200, 'human gets the page');
	t.ok((await res.text()).includes(BODY), 'human sees the content');
	res = await visit(POST, { ua: GPTBOT, ip: '132.196.86.5' });
	t.eq(res.status, 200, 'monitor: GPTBot still gets the page');
	t.ok((await res.text()).includes(BODY), 'monitor: GPTBot sees the content');
	res = await visit(POST, { ua: 'curl/8.4.0', ip: '5.5.5.5' });
	t.eq(res.status, 200, 'monitor: curl still gets the page');
	res = await visit(POST, { ua: GOOGLEBOT, ip: '66.249.66.1' });
	t.eq(res.status, 200, 'monitor: Googlebot gets the page');
	res = await visit(POST, { ua: GOOGLEBOT, ip: '6.6.6.6' });
	t.eq(res.status, 200, 'monitor: fake Googlebot still gets the page');
	let d = await dump();
	t.eq(d.funnel.hits, 4, 'monitor: only bots counted, human is not');
	t.eq(d.funnel.priced, 3, 'monitor: GPTBot, curl and fake bot would be charged');
	t.eq(d.funnel.priced_atomic, 30000, 'monitor: value if charged');
	t.eq(d.funnel.challenged, 0, 'monitor: no 402 sent');
	t.eq(d.funnel.fake, 1, 'monitor: fake bot counted');
	t.ok(bot(d, 'googlebot', 'verified'), 'real Googlebot verified by forward-confirmed reverse DNS');
	t.ok(bot(d, 'googlebot', 'fake'), 'wrong reverse DNS marked fake');
	t.eq(facilitator.state.calls.length, 0, 'monitor: facilitator never contacted');

	t.section('Identity');
	await control('reset');
	await visit(POST, { ua: GOOGLEBOT, ip: '6.6.6.7' });
	d = await dump();
	t.ok(bot(d, 'googlebot', 'fake'), 'PTR on the right domain fails when forward lookup disagrees');
	await control('option', { aibotpaywall_ip_ranges: { gptbot: ['132.196.86.0/24'] } });
	await visit(POST, { ua: GPTBOT, ip: '132.196.86.5' });
	await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	d = await dump();
	t.ok(bot(d, 'gptbot', 'verified'), 'GPTBot inside published range is verified');
	t.ok(bot(d, 'gptbot', 'fake'), 'GPTBot outside published range is fake');
	await control('option', { aibotpaywall_ip_ranges: null });
	await control('reset');
	await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	d = await dump();
	t.ok(bot(d, 'gptbot', 'unknown'), 'no list downloaded yet means unknown, not fake');
	await visit(POST, { ua: 'Mozilla/5.0 ClaudeBot/1.0', ip: '5.5.5.5' });
	d = await dump();
	t.ok(bot(d, 'claudebot', 'claimed'), 'no public method means unverifiable');

	t.section('Active mode: who pays, who passes');
	await fresh();
	res = await visit(POST);
	t.eq(res.status, 200, 'human is never charged');
	t.ok((await res.text()).includes(BODY), 'human sees the content in Active mode');
	res = await visit(POST, { ua: GOOGLEBOT, ip: '66.249.66.1' });
	t.eq(res.status, 200, 'real Googlebot passes free');
	t.ok((await res.text()).includes(BODY), 'real Googlebot sees the content');
	res = await visit(POST, { ua: SEARCHBOT, ip: '5.5.5.5' });
	t.eq(res.status, 200, 'AI search allowed by default');
	res = await visit(POST, { ua: 'Twitterbot/1.0', ip: '5.5.5.5' });
	t.eq(res.status, 200, 'link preview passes');
	res = await visit(POST, { ua: 'UptimeRobot/2.0', ip: '5.5.5.5' });
	t.eq(res.status, 200, 'uptime monitor passes');

	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, 'GPTBot must pay');
	const page402 = await res.text();
	t.ok(!page402.includes(BODY), '402 does not leak content');
	t.ok(res.headers.get('cache-control').includes('no-store'), '402 stays out of caches');
	const required = decode(res.headers.get('payment-required'));
	t.eq(required.x402Version, 2, 'header carries v2 challenge');
	t.eq(required.accepts[0].amount, '10000', 'default price in atomic USDC');
	t.eq(required.accepts[0].network, 'eip155:84532', 'configured network');
	t.eq(required.accepts[0].asset, USDC_SEPOLIA, 'USDC asset');
	t.eq(required.accepts[0].payTo, WALLET, 'owner wallet');
	t.eq(required.resource.url, base + POST, 'resource URL');
	const v1Body = JSON.parse(page402);
	t.eq(v1Body.x402Version, 1, 'body carries v1 challenge');
	t.eq(v1Body.accepts[0].maxAmountRequired, '10000', 'v1 amount');

	res = await visit(POST, { ua: 'curl/8.4.0', ip: '5.5.5.5' });
	t.eq(res.status, 402, 'scripts pay');
	res = await visit(POST, { ua: GOOGLEBOT, ip: '6.6.6.6' });
	t.eq(res.status, 402, 'fake Googlebot pays');
	res = await visit('/?rest_route=/wp/v2/posts', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, 'REST API is not a way around');
	t.eq(decode(res.headers.get('payment-required')).resource.mimeType, 'application/json', 'REST challenge is JSON');
	res = await visit('/?feed=rss2', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, 'RSS feed is not a way around');

	res = await visit('/?robots=1', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 200, 'robots.txt stays open');
	res = await visit('/?sitemap=index', { ua: GPTBOT, ip: '5.5.5.5' });
	t.ok(res.status !== 402, 'sitemap stays open');
	res = await visit('/?p=99999', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 404, 'nothing to sell on a 404');
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', method: 'POST' });
	t.eq(res.status, 402, 'POST is not a way around');
	res = await visit(POST, { method: 'POST' });
	t.ok(res.status !== 402, 'people can still POST');
	res = await visit(`${SINGLE}&preview=true`, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, '?preview=true is not a way around');
	res = await visit('/?rest_route=/WP/V2/posts', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, 'REST route case is not a way around');
	res = await visit('/?rest_route=/wp/v2/posts&_method=GET', { ua: GPTBOT, ip: '5.5.5.5', method: 'POST' });
	t.eq(res.status, 402, 'REST method override is not a way around');
	res = await visit(POST, { ua: 'Google-Site-Verification/1.0', ip: '66.249.66.1' });
	t.eq(res.status, 200, 'Search Console verification passes');
	res = await visit(POST, { ua: 'Mozilla/5.0 (Linux; Android 9; CUBOT X20) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36', ip: '5.5.5.5' });
	t.eq(res.status, 200, 'person on a phone named CUBOT is not a bot');
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', method: 'OPTIONS' });
	t.ok(res.status !== 402, 'preflight is not gated');
	t.eq(facilitator.state.calls.length, 0, 'no payment presented, no outbound call');

	d = await dump();
	t.ok(d.funnel.challenged >= 5, '402 responses counted');

	t.section('Official x402 client, v2');
	await fresh();
	const account = privateKeyToAccount(generatePrivateKey());
	const clientV2 = new x402Client().register('eip155:*', new ExactEvmScheme(account));
	const payV2 = wrapFetchWithPayment(fetch, clientV2);
	res = await payV2(base + POST, { headers: { 'User-Agent': 'x402-agent/1.0 undici', 'X-Real-IP': '5.5.5.5' } });
	t.eq(res.status, 200, 'official client pays and gets the page');
	t.ok((await res.text()).includes(BODY), 'paid response has the content');
	const settlement = decode(res.headers.get('payment-response'));
	t.eq(settlement.success, true, 'PAYMENT-RESPONSE reports success');
	t.eq(settlement.transaction, `0x${'ab'.repeat(32)}`, 'PAYMENT-RESPONSE has the hash');
	t.eq(settlement.network, 'eip155:84532', 'PAYMENT-RESPONSE network');
	t.ok(res.headers.get('cache-control').includes('no-store'), 'paid page stays out of caches');
	t.eq(facilitator.count('/verify'), 1, 'verified once');
	t.eq(facilitator.count('/settle'), 1, 'settled once');
	const sentV2 = facilitator.last('/settle').body;
	t.eq(sentV2.x402Version, 2, 'facilitator gets v2');
	t.eq(sentV2.paymentRequirements.payTo, WALLET, 'facilitator gets server requirements');
	const auth = sentV2.paymentPayload.payload.authorization;
	t.eq(auth.to, WALLET, 'client signed to the owner wallet');
	t.eq(auth.value, '10000', 'client signed the exact amount');
	const genuine = await verifyTypedData({
		address: account.address,
		domain: { name: 'USDC', version: '2', chainId: 84532, verifyingContract: USDC_SEPOLIA },
		types: {
			TransferWithAuthorization: [
				{ name: 'from', type: 'address' },
				{ name: 'to', type: 'address' },
				{ name: 'value', type: 'uint256' },
				{ name: 'validAfter', type: 'uint256' },
				{ name: 'validBefore', type: 'uint256' },
				{ name: 'nonce', type: 'bytes32' },
			],
		},
		primaryType: 'TransferWithAuthorization',
		message: { ...auth, value: BigInt(auth.value), validAfter: BigInt(auth.validAfter), validBefore: BigInt(auth.validBefore) },
		signature: sentV2.paymentPayload.payload.signature,
	});
	t.eq(genuine, true, 'EIP-712 signature recovers against the domain we advertised');
	d = await dump();
	t.eq(d.funnel.paid, 1, 'payment counted');
	t.eq(d.funnel.paid_atomic, 10000, 'amount counted');
	t.eq(d.payments[0].status, 'paid', 'payment recorded');
	t.eq(d.payments[0].tx, `0x${'ab'.repeat(32)}`, 'hash recorded');
	t.eq(d.payments[0].payer.toLowerCase(), account.address.toLowerCase(), 'payer recorded');
	t.eq(d.payments[0].path, POST, 'page recorded without query');

	t.section('Official x402 client, v1');
	await fresh();
	// Current SDKs prefer the v2 header, so play a legacy client: it knows only the
	// response body and the X-PAYMENT header.
	const clientV1 = new x402Client().registerV1('base-sepolia', new ExactEvmSchemeV1(account));
	res = await visit(POST, { ua: 'x402-legacy/1.0', ip: '5.5.5.5' });
	const legacyPayment = await clientV1.createPaymentPayload(await res.json());
	res = await visit(POST, { ua: 'x402-legacy/1.0', ip: '5.5.5.5', headers: { 'X-PAYMENT': Buffer.from(JSON.stringify(legacyPayment)).toString('base64') } });
	t.eq(res.status, 200, 'v1 client pays and gets the page');
	t.ok(res.headers.get('x-payment-response'), 'v1 response header');
	t.eq(facilitator.last('/settle').body.x402Version, 1, 'facilitator gets v1');
	t.eq(facilitator.last('/settle').body.paymentRequirements.maxAmountRequired, '10000', 'v1 server requirements');

	// A valid signed payment, for failure scenarios.
	const client = new x402Client().register('eip155:*', new ExactEvmScheme(account));
	const signedHeader = async (url = base + POST) => {
		const challenge = await visit(url, { ua: GPTBOT, ip: '5.5.5.5' });
		const parsed = decode(challenge.headers.get('payment-required'));
		const payment = await client.createPaymentPayload(parsed);
		return Buffer.from(JSON.stringify(payment)).toString('base64');
	};

	t.section('Failures never deliver content or lose money');
	await fresh();
	let header = await signedHeader();
	facilitator.state.behavior.verify = { isValid: false, invalidReason: 'insufficient_funds' };
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 402, 'failed verification: 402');
	t.ok(!(await res.text()).includes(BODY), 'failed verification: no content');
	t.eq(decode(res.headers.get('payment-response')).errorReason, 'insufficient_funds', 'reason returned to client');
	t.eq(decode(res.headers.get('payment-required')).error, 'insufficient_funds', 'challenge explains why');
	t.eq(facilitator.count('/settle'), 0, 'failed verification: settle never called');
	d = await dump();
	t.eq(d.payments[0].status, 'failed', 'failure recorded');
	t.eq(d.payments[0].reason, 'insufficient_funds', 'failure reason recorded');
	t.eq(d.funnel.attempts, 1, 'attempt counted');
	t.eq(d.funnel.paid, 0, 'not counted as paid');

	await fresh();
	header = await signedHeader();
	facilitator.state.behavior.settle = { success: false, errorReason: 'invalid_transaction_state', transaction: '', network: 'eip155:84532' };
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 402, 'failed settlement: 402');
	t.ok(!(await res.text()).includes(BODY), 'failed settlement: rendered page discarded');
	t.eq(decode(res.headers.get('payment-response')).success, false, 'failed settlement reported');
	d = await dump();
	t.eq(d.funnel.paid, 0, 'failed settlement not counted');

	await fresh();
	header = await signedHeader();
	facilitator.state.behavior.settle = { success: false, errorReason: 'settlement_pending', transaction: `0x${'cd'.repeat(32)}`, network: 'eip155:84532' };
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 200, 'pending settlement: money may have moved, payer gets the page');
	t.ok((await res.text()).includes(BODY), 'pending settlement delivers content');
	d = await dump();
	t.eq(d.payments[0].status, 'pending', 'pending recorded');
	t.eq(d.payments[0].tx, `0x${'cd'.repeat(32)}`, 'pending hash kept for reconciliation');

	await fresh();
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': 'not-base64!!' } });
	t.eq(res.status, 400, 'malformed payment: 400');
	t.ok(res.headers.get('payment-required'), 'malformed payment still gets the terms');
	t.eq(facilitator.state.calls.length, 0, 'malformed payment: no outbound call');

	await fresh();
	header = await signedHeader();
	await control('settings', { default_price: '0.02' });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 402, 'stale amount refused');
	t.eq(facilitator.state.calls.length, 0, 'underpayment refused locally, before any outbound call');
	t.eq(decode(res.headers.get('payment-response')).errorReason, 'invalid_exact_evm_payload_authorization_value_mismatch', 'underpayment reason');

	await fresh();
	header = await signedHeader();
	await control('settings', { wallet: '0x0000000000000000000000000000000000000001' });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 402, 'payment to another wallet refused');
	t.eq(facilitator.state.calls.length, 0, 'wrong recipient refused locally');
	await control('settings', { wallet: WALLET });

	await fresh();
	header = await signedHeader();
	await control('option', { e2e_fatal: 1 });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	await control('option', { e2e_fatal: null });
	t.eq(facilitator.count('/verify'), 1, 'page crash: payment had been verified');
	t.eq(facilitator.count('/settle'), 0, 'page crash: nobody charged');
	t.ok(res.status >= 500, 'page crash: error is visible');

	await fresh();
	header = await signedHeader(`${base}/?p=${seeded.id}`);
	res = await visit(`/?p=${seeded.id}`, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	t.eq(res.status, 301, 'canonical redirect still works');
	t.eq(facilitator.count('/settle'), 0, 'redirect delivers no content, nobody charged');

	t.section('Rules');
	await fresh({ policies: { ai_training: { action: 'block', price: '' }, ai_search: { action: 'allow', price: '' }, ai_agent: { action: 'charge', price: '' }, seo: { action: 'charge', price: '0.07' }, script: { action: 'allow', price: '' }, fake: { action: 'charge', price: '' } } });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 403, 'class policy blocks');
	t.ok(!(await res.text()).includes(BODY), 'block has no content');
	res = await visit(POST, { ua: 'curl/8', ip: '5.5.5.5' });
	t.eq(res.status, 200, 'class policy allows');
	res = await visit(POST, { ua: 'AhrefsBot/7.0', ip: '5.5.5.5' });
	t.eq(decode(res.headers.get('payment-required')).accepts[0].amount, '70000', 'class price');

	await fresh({ content_rules: `path:${POST} charge 0.05\npath:/ allow`, bot_rules: { bytespider: { action: 'block', price: '' } } });
	res = await visit(POST, { ua: SEARCHBOT, ip: '5.5.5.5' });
	t.eq(decode(res.headers.get('payment-required')).accepts[0].amount, '50000', 'path rule charges an otherwise free bot');
	res = await visit('/', { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 200, 'path rule frees an otherwise paid bot');
	res = await visit(POST, { ua: 'Bytespider', ip: '5.5.5.5' });
	t.eq(res.status, 403, 'bot rule wins over content rule');
	await fresh({ content_rules: 'path:/premium/ charge 0.05' });
	// Other spellings (%70, //, /index.php/) are rewritten or 404 before PHP in Playground,
	// so normalize_path's unit tests cover them.
	for (const dodge of ['/premium/a/', '/PREMIUM/a/']) {
		res = await visit(dodge, { ua: SEARCHBOT, ip: '5.5.5.5' });
		t.eq(res.headers.get('payment-required') ? decode(res.headers.get('payment-required')).accepts[0].amount : res.status, '50000', `path rule holds for ${dodge}`);
	}
	res = await visit('/premium-other/', { ua: SEARCHBOT, ip: '5.5.5.5' });
	t.eq(res.status, 200, 'path rule stops at segment boundary');
	await fresh({ bot_rules: { gptbot: { action: 'allow', price: '' } } });
	await control('option', { aibotpaywall_ip_ranges: { gptbot: ['132.196.86.0/24'] } });
	res = await visit(POST, { ua: GPTBOT, ip: '132.196.86.5' });
	t.eq(res.status, 200, 'bot rule frees the real bot');
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 402, 'bot rule does not free an impostor');
	await control('option', { aibotpaywall_ip_ranges: null });
	await fresh({ content_rules: 'category:news charge 0.09' });
	res = await visit(SINGLE, { ua: SEARCHBOT, ip: '5.5.5.5' });
	t.eq(decode(res.headers.get('payment-required')).accepts[0].amount, '90000', 'category rule');
	await fresh({ content_rules: 'type:post block' });
	res = await visit(SINGLE, { ua: SEARCHBOT, ip: '5.5.5.5' });
	t.eq(res.status, 403, 'post type rule');

	t.section('Site behind a proxy nobody told AI Bot Paywall about');
	await fresh({ client_ip_header: '' });
	res = await visit(POST, { ua: GOOGLEBOT, headers: { 'X-Forwarded-For': '66.249.66.1' } });
	t.eq(res.status, 200, 'real Googlebot is not charged for a missing setting');
	d = await dump();
	t.ok(bot(d, 'googlebot', 'unknown'), 'marked unknown, not fake');
	res = await visit(POST, { ua: GOOGLEBOT });
	t.eq(res.status, 402, 'no proxy signs: a failed check still means fake');
	res = await visit('/wp-admin/admin.php?page=ai-bot-paywall', { ua: CHROME });
	await fresh({ client_ip_header: 'direct' });
	res = await visit(POST, { ua: GOOGLEBOT, headers: { 'X-Forwarded-For': '66.249.66.1' } });
	t.eq(res.status, 402, 'owner said no proxy: a spoofed header gains nothing');
	await control('settings', { client_ip_header: 'X-Real-IP' });

	t.section('Reais');
	await control('option', { aibotpaywall_usd_brl: null });
	await fresh({ currency: 'BRL', default_price: '0.05' });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.status, 200, 'no exchange rate yet: not charged rather than guessed');
	await control('option', { aibotpaywall_usd_brl: { rate: 5, date: '2026-11-05' } });
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(decode(res.headers.get('payment-required')).accepts[0].amount, '10000', 'R$ 0.05 at 5.00 is 0.01 USDC');
	await control('settings', { currency: 'USD', default_price: '0.01' });

	t.section('Web Bot Auth, real Ed25519 through WordPress sodium_compat');
	await fresh();
	const { privateKey, publicKey } = generateKeyPairSync('ed25519');
	const x = publicKey.export({ format: 'jwk' }).x;
	const keyid = createHash('sha256').update(`{"crv":"Ed25519","kty":"OKP","x":"${x}"}`).digest('base64url');
	await control('option', { aibotpaywall_signature_keys: { 'chatgpt.com': { [keyid]: Buffer.from(x, 'base64url').toString('base64') } } });
	const signedRequest = (key = privateKey, authority = new URL(base).host) => {
		const now = Math.floor(Date.now() / 1000);
		const agent = '"https://chatgpt.com"';
		const params = `("@authority" "signature-agent");created=${now - 5};expires=${now + 300};keyid="${keyid}";alg="ed25519";tag="web-bot-auth"`;
		const message = `"@authority": ${authority}\n"signature-agent": ${agent}\n"@signature-params": ${params}`;
		const signature = edSign(null, Buffer.from(message), key).toString('base64');
		return { 'Signature-Agent': agent, 'Signature-Input': `sig1=${params}`, Signature: `sig1=:${signature}:` };
	};
	res = await visit(POST, { ip: '5.5.5.5', headers: signedRequest() });
	t.eq(res.status, 402, 'signed agent with browser User-Agent is identified and charged');
	d = await dump();
	t.ok(bot(d, 'chatgpt_user', 'verified'), 'signature proves identity');
	const otherKey = generateKeyPairSync('ed25519').privateKey;
	res = await visit(POST, { ip: '5.5.5.5', headers: signedRequest(otherKey) });
	t.eq(res.status, 200, 'wrong key with browser User-Agent stays a regular visitor');
	res = await visit(POST, { ip: '5.5.5.5', headers: signedRequest(privateKey, 'other-site.example') });
	t.eq(res.status, 200, 'signature made for another site does not count');
	await control('reset');
	await visit(POST, { ua: 'Mozilla/5.0; ChatGPT-User/1.0', ip: '5.5.5.5', headers: signedRequest() });
	d = await dump();
	t.ok(bot(d, 'chatgpt_user', 'verified'), 'signature verifies a declared agent');

	t.section('Test payment');
	await fresh({ mode: 'monitor' });
	const token = (await dump()).settings.test_token;
	t.ok(/^[a-f0-9]{32}$/.test(token), 'private token generated on activation');
	res = await visit('/?aibotpaywall_test=wrong', { ua: 'curl/8' });
	t.eq(res.status, 200, 'wrong token is an ordinary page');
	const testUrl = `${base}/?aibotpaywall_test=${token}`;
	res = await visit(testUrl, { ua: 'curl/8' });
	t.eq(res.status, 402, 'test URL charges even in Monitor mode');
	t.ok(!res.headers.get('payment-required').includes(token) && !decode(res.headers.get('payment-required')).resource.url.includes(token), 'challenge does not echo the private token');
	res = await payV2(testUrl, { headers: { 'User-Agent': 'x402-agent/1.0', 'X-Real-IP': '5.5.5.5' } });
	t.eq(res.status, 200, 'test payment succeeds');
	t.eq((await res.json()).ok, true, 'test payment confirms');
	d = await dump();
	t.eq(d.payments[0].kind, 'test', 'recorded as test');
	t.eq(d.funnel.paid, 0, 'test payment stays out of real numbers');

	t.section('Page cache');
	await fresh({ mode: 'monitor', cache_bypass: true });
	let check = await control('selftest');
	t.eq(check.ok, true, `self-test passes without a cache (reachable=${check.reachable})`);
	await control('option', { e2e_fake_cache: 1 });
	res = await visit(POST, { ua: CHROME });
	t.eq(res.headers.get('x-e2e-cache'), 'hit', 'stand-in cache serves browsers');
	res = await visit(POST, { ua: GPTBOT, ip: '5.5.5.5' });
	t.eq(res.headers.get('x-e2e-cache'), null, 'bypass: known bot skips the cache');
	check = await control('selftest');
	t.eq(check.ok, true, 'bypass on: self-test passes behind a cache');
	await control('settings', { cache_bypass: false });
	check = await control('selftest');
	t.eq(check.reachable, true, 'bypass off: site still reachable');
	t.eq(check.ok, false, 'bypass off: self-test detects that the cache hides bots');
	t.eq((await control('rocket_ua')).agents, [], 'bypass off: nothing added to WP Rocket');
	await control('settings', { cache_bypass: true });
	const agents = (await control('rocket_ua')).agents;
	t.ok(agents.includes('(.*)GPTBot(.*)') && agents.includes('(.*)Screaming\\sFrog(.*)'), 'bypass on: tokens added to WP Rocket with its wildcards');
	t.ok(!agents.includes('(.*)Googlebot(.*)'), 'search engines stay cached');
	await control('option', { e2e_fake_cache: null });
	res = await visit(POST, { ua: 'Mozilla/5.0 AIBotPaywallSelfTest/00000000000000000000000000000000' });
	t.eq(res.headers.get('x-ai-bot-paywall-check'), null, 'self-test header needs the live token');

	t.section('Facilitator check');
	check = await control('facilitator');
	t.eq([check.reachable, check.v2, check.v1], [true, true, true], 'supported networks read');

	t.section('Admin screens and forms');
	await fresh({ mode: 'monitor' });
	const anon = await visit('/wp-admin/admin.php?page=ai-bot-paywall');
	t.eq(anon.status, 302, 'admin needs login');
	const login = await fetch(`${base}/wp-login.php`, {
		method: 'POST',
		redirect: 'manual',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
		body: new URLSearchParams({ log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' }),
	});
	const cookie = login.headers.getSetCookie().map((c) => c.split(';')[0]).join('; ');
	t.ok(cookie.includes('wordpress_logged_in'), 'logged in');
	const admin = (url, init = {}) => fetch(base + url, { redirect: 'manual', ...init, headers: { Cookie: cookie, 'User-Agent': CHROME, ...(init.headers ?? {}) } });
	const page = async (tab) => {
		const r = await admin(`/wp-admin/admin.php?page=ai-bot-paywall&tab=${tab}`);
		return { status: r.status, html: await r.text() };
	};
	for (const tab of ['dashboard', 'rules', 'settings', 'diagnostics']) {
		const p = await page(tab);
		t.eq(p.status, 200, `${tab} tab loads`);
		t.ok(!/Fatal error|Warning:|Notice:|Deprecated:/.test(p.html), `${tab} tab has no PHP errors`);
	}

	const nonce = (html, action) => {
		const match = html.match(new RegExp(`name="action" value="${action}"`)) && html.match(/name="_wpnonce" value="([a-f0-9]+)"/);
		return match ? match[1] : '';
	};
	const settingsHtml = (await page('settings')).html;
	const post = (fields) => admin('/wp-admin/admin-post.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) });
	const base_fields = (extra) => ({
		action: 'aibotpaywall_save_settings',
		_wpnonce: nonce(settingsHtml, 'aibotpaywall_save_settings'),
		wallet: WALLET,
		network: 'base-sepolia',
		facilitator: facilitator.url,
		client_ip_header: 'X-Real-IP',
		retention_days: '100',
		...extra,
	});
	res = await post(base_fields({ mode: 'active' }));
	t.eq(res.status, 302, 'settings save redirects');
	d = await dump();
	t.eq(d.settings.mode, 'monitor', 'Active mode refused without confirmation');
	res = await post(base_fields({ mode: 'active', ack_ai: '1', wallet: 'not-a-wallet' }));
	d = await dump();
	t.eq(d.settings.mode, 'active', 'keeps the previous valid wallet');
	t.eq(d.settings.wallet, WALLET, 'invalid wallet not saved');
	res = await post(base_fields({ mode: 'active', ack_ai: '1', client_ip_header: '' }));
	d = await dump();
	t.eq(d.settings.mode, 'monitor', 'Active mode refused until the proxy question is answered');
	res = await post(base_fields({ mode: 'monitor', facilitator: 'http://evil.example/' }));
	d = await dump();
	t.eq(d.settings.facilitator, facilitator.url, 'plain HTTP facilitator refused');
	res = await post(base_fields({ mode: 'monitor', retention_days: '1' }));
	d = await dump();
	t.eq(d.settings.retention_days, 30, 'retention has a floor');
	res = await post({ ...base_fields({ mode: 'active', ack_ai: '1' }), _wpnonce: 'bad' });
	t.eq(res.status, 403, 'forged nonce refused');
	d = await dump();
	t.eq(d.settings.mode, 'monitor', 'forged request changes nothing');
	res = await fetch(`${base}/wp-admin/admin-post.php`, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(base_fields({ mode: 'active', ack_ai: '1' })) });
	d = await dump();
	t.eq(d.settings.mode, 'monitor', 'anonymous request changes nothing');

	const rulesHtml = (await page('rules')).html;
	res = await post({
		action: 'aibotpaywall_save_rules',
		_wpnonce: nonce(rulesHtml, 'aibotpaywall_save_rules'),
		default_price: '0.03',
		currency: 'USD',
		'policies[ai_training][action]': 'block',
		'policies[ai_training][price]': 'abc',
		'policies[ai_search][action]': 'hack',
		'bot_rules[gptbot][action]': 'charge',
		'bot_rules[gptbot][price]': '0.40',
		'bot_rules[not_a_bot][action]': 'block',
		content_rules: 'path:/x charge 0.1\nnonsense',
	});
	t.eq(res.status, 302, 'rules save redirects');
	d = await dump();
	t.eq(d.settings.default_price, '0.03', 'default price saved');
	t.eq(d.settings.policies.ai_training, { action: 'block', price: '' }, 'invalid price cleared');
	t.eq(d.settings.policies.ai_search.action, 'allow', 'invalid action falls back to allow');
	t.eq(d.settings.bot_rules, { gptbot: { action: 'charge', price: '0.40' } }, 'only known bots saved');
	const noticeHtml = (await page('rules')).html;
	t.ok(noticeHtml.includes('nonsense'), 'bad content rule is reported');

	// Visitor-controlled text reaches the admin only escaped.
	await fresh();
	const xssPath = `${POST}?x=1`;
	header = await signedHeader(base + xssPath);
	await visit(`${POST}%3Cscript%3Ealert(1)%3C/script%3E/`, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	await visit(`/=cmd%7C'calc'/?p=${seeded.id}`, { ua: GPTBOT, ip: '5.5.5.5', headers: { 'PAYMENT-SIGNATURE': header } });
	const dash = (await page('dashboard')).html;
	t.ok(!dash.includes('<script>alert(1)'), 'hostile path is escaped on the dashboard');

	await payV2(base + POST, { headers: { 'User-Agent': 'x402-agent/1.0', 'X-Real-IP': '5.5.5.5' } });
	const dash2 = (await page('dashboard')).html;
	t.ok(dash2.includes('US$'), 'dashboard shows money');
	t.ok(dash2.includes('sepolia.basescan.org/tx/0x'), 'dashboard links the transaction');
	const exportUrl = dash2.match(/href="([^"]*aibotpaywall_export[^"]*)"/)[1].replaceAll('&#038;', '&').replaceAll('&amp;', '&');
	res = await fetch(exportUrl, { headers: { Cookie: cookie } });
	const csv = await res.text();
	t.ok(csv.startsWith('created_at_utc'), 'CSV export works');
	t.ok(csv.includes(POST), 'CSV has the payment');
	await control('settings', { mode: 'monitor' });

	t.section('Brazilian Portuguese');
	// WordPress refuses WPLANG without core's language pack, so force the locale instead.
	await control('option', { e2e_locale: 'pt_BR' });
	const translated = (await page('dashboard')).html;
	t.ok(translated.includes('Bots e pagamentos'), 'dashboard is translated');
	t.ok(translated.includes('Visitas de bots'), 'funnel is translated');
	const translatedRules = (await page('rules')).html;
	t.ok(translatedRules.includes('Salvar regras'), 'rules tab is translated');
	await control('option', { e2e_locale: null });

	t.section('Deactivate and uninstall leave nothing behind');
	const gone = await control('uninstall');
	t.eq(gone.htaccess_block_after_deactivate, false, 'deactivation removes the cache rules');
	t.eq(gone.user_rules_kept, true, 'other .htaccess rules are kept');
	t.eq(gone.tables, [], 'uninstall drops the tables');
	t.eq(gone.settings, false, 'uninstall removes the settings');
	t.eq(gone.cron, false, 'uninstall clears the schedule');
} finally {
	wp.stop();
	facilitator.close();
}

process.exit(t.finish() ? 0 : 1);
