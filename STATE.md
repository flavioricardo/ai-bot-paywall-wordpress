# STATE — Botfare (botfare-ai-bot-paywall)

> **Convenção deste arquivo:** só fato vivo — o que é verdade hoje e o que está
> aberto. Histórico de como chegamos aqui vive no `git log`. Ao fechar uma
> pendência, apague-a daqui (o commit registra); se ela deixou algo ainda por
> fazer, promova essa linha para Pendências. Nunca seção "Resolvidas"/"Feito"
> (o CI barra). Limite: ~150 linhas.

## Status

- **Fase:** primeira versão (0.1.0) completa e na `main`. Falta validar em site real.
- **No ar:** repositório público `flavioricardo/ai-bot-paywall-wordpress`. Enviado ao WordPress.org em 2026-11-26 (0.1.0), aguardando review; slug `botfare-ai-bot-paywall`. Scan automático passou; o aviso de "offloading" em `class-catalog.php` é falso positivo (listas de IP em JSON, não scripts) e não deve ser contornado.

## Decisões

- Objetivo é visibilidade do Flávio como dev WordPress; receita vem de implantação e suporte. Plugin inteiro grátis, GPL, sem servidor próprio. Spec: `docs/superpowers/specs/2026-11-06-ai-bot-paywall-design.md`.
- Promessa pública: mostra quem chega, cobra quem pode pagar. Renda com bots não é garantida.
- Testes não exigem PHP nem Docker: rodam em WASM via `@php-wasm/node` e WordPress Playground.

## Concorrência

- ForgeMesh Pay-per-Crawl: publicado, grátis com Pro de US$ 4,99/mês, sem conta externa. Mais próximo do nosso escopo.
- Bottoll: publicado, exige conta e API key, e envia IP e path de cada GET público à API deles. Nosso diferencial: funciona sem serviço externo.
- CrawlerToll: site em prévia privada, não deu para avaliar. Só a descrição vista em busca.
- PayLayer: lista de espera na última checagem (2026-11-06).

## Limites conhecidos

- WP Rocket e LiteSpeed Cache reais nunca rodaram aqui; integração testada com dublê. Formato `(.*)token(.*)` do Rocket vem da interface dele, de memória: conferir num site real.
- Nenhum pagamento moveu dinheiro real. Interop contra facilitator real chegou até a recusa por falta de saldo.
- Playground não roteia permalink bonito; variantes de caminho `%70`, `//` e `/index.php/` só têm teste unitário.

## Pendências

- [ ] **Responder o review do WordPress.org**: e-mail "[WordPress Plugin Directory] Review in Progress: Botfare – AI Bot Paywall" de plugins@wordpress.org (1 a 10 dias; envio e confirmação em 2026-11-26). Corrigir o que pedirem e subir o zip por "Upload updated" em https://wordpress.org/plugins/developers/add/. Depois de aprovado, publicar via SVN | [humano] e-mail chega pra ele
- [ ] **Validar num site real**: instalar o zip num site com WP Rocket ou LiteSpeed; rodar Diagnóstico → Testar agora (se falhar, ajustar o padrão em `class-cache-compat.php`); depois pagar centavos com `PAY_TEST_PRIVATE_KEY=... node tools/pay-test.mjs "<URL de teste>"`, carteira própria na Base | [humano] precisa de site, licença e dinheiro
- [ ] **Incluir o repo na automação de regras base**: dar acesso a `flavioricardo/ai-bot-paywall-wordpress` no PAT do `ROLLOUT_TOKEN` (https://github.com/settings/personal-access-tokens) e só então adicionar a linha em `rollout-projects.txt` do claude-config | [humano] token
