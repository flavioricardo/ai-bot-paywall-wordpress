// Talks to the real public facilitator on Base Sepolia with a throwaway wallet that has
// no funds. No money can move: a genuine signature should be judged and refused for
// lack of balance, which proves our requests are understood. Also refreshes the live
// verification data. Needs internet.
import { wrapFetchWithPayment, x402Client } from '@x402/fetch';
import { ExactEvmScheme } from '@x402/evm';
import { generatePrivateKey, privateKeyToAccount } from 'viem/accounts';
import { checker, startWordPress } from './harness.mjs';

const t = checker();
const wp = await startWordPress({ port: 9451 });
const { base } = wp;
const control = async (name, body) => {
	const res = await fetch(`${base}/?e2e=${name}`, body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) });
	return res.json();
};
const decode = (value) => JSON.parse(Buffer.from(value, 'base64').toString('utf8'));

try {
	await control('settings', {
		mode: 'active',
		ack_ai: true,
		wallet: '0x209693Bc6afc0C5328bA36FaF03C514EF312287C',
		network: 'base-sepolia',
		facilitator: 'https://facilitator.payai.network',
		show_brl: true,
	});

	t.section('Live verification data');
	const data = await control('refresh');
	console.log(data);
	t.ok(data.ranges.gptbot > 0, 'OpenAI GPTBot list downloaded');
	t.ok(data.ranges.oai_searchbot > 0, 'OpenAI search list downloaded');
	t.ok(data.ranges.chatgpt_user > 0, 'ChatGPT-User list downloaded');
	t.ok(data.ranges.perplexitybot > 0, 'Perplexity list downloaded');
	t.ok(data.rate > 1, 'PTAX rate downloaded');
	console.log(`Web Bot Auth keys: ${JSON.stringify(data.keys)}`);

	t.section('Live facilitator');
	const support = await control('facilitator');
	t.eq([support.reachable, support.v2, support.v1], [true, true, true], 'facilitator supports the network in v2 and v1');

	const account = privateKeyToAccount(generatePrivateKey());
	const client = new x402Client().register('eip155:*', new ExactEvmScheme(account));
	const challenge = await fetch(`${base}/`, { headers: { 'User-Agent': 'x402-agent/1.0' } });
	t.eq(challenge.status, 402, 'site asks for payment');
	const payment = await client.createPaymentPayload(decode(challenge.headers.get('payment-required')));
	const res = await fetch(`${base}/`, {
		headers: { 'User-Agent': 'x402-agent/1.0', 'PAYMENT-SIGNATURE': Buffer.from(JSON.stringify(payment)).toString('base64') },
	});
	t.eq(res.status, 402, 'unfunded wallet is refused');
	const reason = decode(res.headers.get('payment-response')).errorReason;
	console.log(`Facilitator said: ${reason}`);
	t.ok(/insufficient|balance|funds/i.test(reason), 'refused for lack of funds, so the signature and format were understood');

	const pay = wrapFetchWithPayment(fetch, client);
	const paid = await pay(`${base}/`, { headers: { 'User-Agent': 'x402-agent/1.0' } }).catch((e) => e);
	t.ok(paid instanceof Error || paid.status === 402, 'official client also stops without funds');
	const dump = await control('dump');
	t.eq(dump.funnel.paid, 0, 'nothing recorded as paid');
} finally {
	wp.stop();
}
process.exit(t.finish() ? 0 : 1);
