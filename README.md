# Portaria

WordPress plugin that counts the bots reading your site, tells real crawlers from fake ones, and charges the ones that can pay through [x402](https://x402.org), straight to your wallet.

Plugin WordPress que conta os bots que leem seu site, separa crawlers reais de falsos e cobra quem pode pagar via x402, direto na sua carteira.

**What pays today:** clients that speak x402 pay. The major AI crawlers do not pay per page yet. Portaria gives you real data from day one and does not promise income from bots.

The user-facing documentation lives in [`portaria-bots/readme.txt`](portaria-bots/readme.txt). The design is in [`docs/superpowers/specs`](docs/superpowers/specs/2026-11-06-portaria-design.md).

## Development

Node 22 is the only requirement. PHP and WordPress run in WebAssembly through WordPress Playground.

```bash
npm ci
```

```bash
npm test
```

```bash
npm run test:e2e
```

- `npm test`: unit tests on PHP 7.4 and 8.3, plus a translation check.
- `npm run test:e2e`: real WordPress, simulated bots, a stand-in facilitator, and the official `@x402/fetch` client signing real payloads.
- `npm run test:interop`: same flow against the real facilitator on a test network, with a throwaway unfunded wallet. Needs internet.
- `npm run build:i18n`: compiles the pt_BR translation.
- `npm run build:zip`: packages committed code into `build/portaria-bots.zip`.

### Paying the test URL yourself

```bash
PAY_TEST_PRIVATE_KEY=0x... node tools/pay-test.mjs "https://your-site/?portaria_test=TOKEN"
```

Use a dedicated wallet holding only a few cents. The key stays in your shell, in the environment variable.

## Known test limits

- WP Rocket (paid) and LiteSpeed Cache (needs a LiteSpeed server) do not run in this suite. The integration is tested against a stand-in; the plugin's self-test is the safety net on real sites.
- Playground does not route pretty permalinks, so E2E uses `/?p=ID` and arbitrary paths. The gate does not depend on rewrite rules.
- No payment has moved real money through a real facilitator here: that needs a funded wallet.
