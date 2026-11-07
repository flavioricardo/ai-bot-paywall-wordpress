=== Portaria: AI Bot Ledger and x402 Paywall ===
Contributors: flavioricardo
Tags: ai crawlers, bots, x402, paywall, analytics
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See every bot that reads your site, tell real crawlers from fake ones, and charge the ones that can pay through x402, directly to your wallet.

== Description ==

Portaria ("front desk" in Portuguese) shows who is reading your site automatically and lets you decide what each one gets.

**Be clear about what pays today.** Clients that speak x402 pay. The major AI crawlers (GPTBot, ClaudeBot, PerplexityBot and others) do not pay per page yet, so a site that charges them simply stops serving them. Portaria does not promise income from bots. What it gives you from day one is real data about who takes what.

= What it does =

* **Counts bots, by day.** Who visited, how often, and under which rule.
* **Spots fake bots.** Search engines and crawlers are checked by Web Bot Auth signature, published IP list or forward-confirmed reverse DNS.
* **Starts in Monitor mode.** Nothing changes for anyone until you switch. You see what would have been charged first.
* **Charges through x402.** v2 and v1 clients, USDC on Base, paid straight to your wallet. Portaria never holds money and takes no cut.
* **Keeps SEO safe.** Search engines that are not proven fake always read for free.
* **Works with page caches.** Integrates with WP Rocket and LiteSpeed Cache, and a built-in test detects any other cache or CDN hiding bots from the plugin.
* **Proves it works.** A private test URL lets you make a real payment and see the transaction on the dashboard.
* **Everything is free.** No Pro version, no account, no external dashboard.
* **Reais included.** Show amounts and set prices in BRL using the Banco Central PTAX rate. Bots still pay in USDC.

= How a payment works =

1. A bot requests a page and receives `402 Payment Required` with the price, token and your wallet.
2. It signs a USDC transfer authorization and retries.
3. Portaria checks the payment and asks the facilitator to verify it.
4. WordPress builds the page.
5. Only then does the facilitator settle on-chain, and the page is delivered.

If the page fails, nobody is charged. If settlement fails, no content is delivered.

= Known limits =

* A scraper that copies a browser's User-Agent looks like a person. Any User-Agent based tool shares this limit.
* Link preview and uptime services, and search engines with no public verification method, are free by default and are identified only by what they claim. You can set a rule per bot for the first two.
* Charging crawlers and clients that cannot pay means they receive no content.
* Active mode gates every method except OPTIONS for bots and scripts. If your site receives webhooks on public pages, or a headless front end reads the REST API, allow them with a rule such as `path:/webhook/ allow` or a rule for the Scripts group.
* Category and type rules apply to single posts and category pages. The home page, feeds and the REST API follow the group rules.
* Reverse DNS checks run during the bot's request, at most 30 new lookups per minute, and slow DNS can delay those bot requests.
* On Multisite each site has its own data and settings, and finishes setup the first time an admin opens its dashboard.
* WP Rocket and LiteSpeed Cache integrations send known bots past the cache, which uses more server resources on sites with heavy crawling. You can turn this off.
* Selling to someone abroad and receiving USDC can have tax and currency consequences where you live. They are yours to check with an accountant.

== External services ==

Portaria contacts these services. Nothing about your human visitors is ever sent.

= x402 facilitator =

Verifies payments and submits them to the blockchain. By default, [PayAI](https://payai.network) at `facilitator.payai.network` ([documentation](https://docs.payai.network/introduction)). You can use any facilitator, including your own.

It receives the signed payment, your wallet address, the price and the page URL, and is contacted **only when a client presents a payment**, or when you press "Check facilitator".

= Published crawler IP lists =

Public files downloaded once a day to verify crawlers. Nothing is sent besides the request itself.

* OpenAI: `openai.com/gptbot.json`, `openai.com/searchbot.json`, `openai.com/chatgpt-user.json` ([terms](https://openai.com/policies/terms-of-use/), [privacy](https://openai.com/policies/privacy-policy/))
* Perplexity: `www.perplexity.ai/perplexitybot.json`, `www.perplexity.ai/perplexity-user.json` ([terms](https://www.perplexity.ai/hub/legal/terms-of-service), [privacy](https://www.perplexity.ai/hub/legal/privacy-policy))

= Web Bot Auth key directory =

The public key file at `chatgpt.com/.well-known/http-message-signatures-directory`, downloaded once a day to verify signed agents ([terms](https://openai.com/policies/terms-of-use/), [privacy](https://openai.com/policies/privacy-policy/)). Keys are only ever fetched from hosts listed in the plugin, never from an address sent by a client.

= Banco Central do Brasil =

The public USD/BRL PTAX rate from `olinda.bcb.gov.br`, downloaded once a day and only if you show or set prices in reais ([open data portal](https://dadosabertos.bcb.gov.br/)).

== Privacy ==

Portaria stores daily counts per bot. It does not store IP addresses, User-Agent strings or anything about human visitors. Reverse DNS results are cached briefly under a key that changes daily and cannot be turned back into an address. When a client pays, its public wallet address and transaction hash are kept as the payment record.

== Installation ==

1. Install and activate. Portaria starts in Monitor mode.
2. Watch the Dashboard for a few days, and run the page cache test under Diagnostics.
3. To charge, add your wallet's public address in Settings, confirm the warning and switch to Active. Never paste a private key.
4. Make a test payment from the Diagnostics tab.

If your site is behind Cloudflare or another proxy, set the client IP header so verification sees the real address.

== Frequently Asked Questions ==

= Will this hurt my Google ranking? =

Search engines that are not proven fake are always free, whatever your rules say. If verification is not possible at that moment, they pass.

= Will AI assistants stop citing my site? =

They can, if you charge or block their crawlers. That is why AI search crawlers are allowed by default and Active mode asks for your confirmation first.

= Do I need an account or API key? =

No. Only a wallet address, and only if you want to charge.

= What happens to my data when I delete the plugin? =

Tables, settings and cache rules are removed.

== Changelog ==

= 0.1.0 =
* First version: bot ledger, identity checks, x402 v2 and v1 charging, content and bot rules, page cache integration and self-test, test payment, Brazilian Portuguese translation.
