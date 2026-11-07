// Pays an AI Bot Paywall test URL with the official x402 client. Moves real money on mainnet:
// use a dedicated wallet holding only a few cents, or Base Sepolia.
//   PAY_TEST_PRIVATE_KEY=0x... node tools/pay-test.mjs "https://site/?aibotpaywall_test=TOKEN"
import { wrapFetchWithPayment, x402Client } from '@x402/fetch';
import { ExactEvmScheme } from '@x402/evm';
import { privateKeyToAccount } from 'viem/accounts';

const url = process.argv[2];
const key = process.env.PAY_TEST_PRIVATE_KEY;
if (!url || !key) {
	console.error('Usage: PAY_TEST_PRIVATE_KEY=0x... node tools/pay-test.mjs <url>');
	process.exit(2);
}

const account = privateKeyToAccount(key);
const pay = wrapFetchWithPayment(fetch, new x402Client().register('eip155:*', new ExactEvmScheme(account)));

console.log(`Paying from ${account.address}`);
const res = await pay(url);
console.log(`HTTP ${res.status}`);
const receipt = res.headers.get('payment-response');
if (receipt) console.log(JSON.parse(Buffer.from(receipt, 'base64').toString('utf8')));
console.log((await res.text()).slice(0, 300));
process.exit(res.ok ? 0 : 1);
