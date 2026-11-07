# STATE — AI Bot Paywall (ai-bot-paywall)

> **Convenção deste arquivo:** só fato vivo — o que é verdade hoje e o que está
> aberto. Histórico de como chegamos aqui vive no `git log`. Ao fechar uma
> pendência, apague-a daqui (o commit registra); se ela deixou algo ainda por
> fazer, promova essa linha para Pendências. Nunca seção "Resolvidas"/"Feito"
> (o CI barra). Limite: ~150 linhas.

## Status

- **Fase:** primeira versão (0.1.0) completa e testada localmente, no branch `feat/plugin-v1`. Sem remoto.
- **No ar:** nada. Nem GitHub nem WordPress.org.

## Decisões

- Objetivo é visibilidade do Flávio como dev WordPress; receita vem de implantação e suporte. Plugin inteiro grátis, GPL, sem servidor próprio. Spec: `docs/superpowers/specs/2026-11-06-ai-bot-paywall-design.md`.
- Promessa pública: mostra quem chega, cobra quem pode pagar. Renda com bots não é garantida.
- Testes não exigem PHP nem Docker: rodam em WASM via `@php-wasm/node` e WordPress Playground.

## Limites conhecidos

- WP Rocket e LiteSpeed Cache reais nunca rodaram aqui; integração testada com dublê. Formato `(.*)token(.*)` do Rocket vem da interface dele, de memória: conferir num site real.
- Nenhum pagamento moveu dinheiro real. Interop contra facilitator real chegou até a recusa por falta de saldo.
- Playground não roteia permalink bonito; variantes de caminho `%70`, `//` e `/index.php/` só têm teste unitário.

## Pendências

- [ ] **Criar repo público e abrir PR de `feat/plugin-v1`**: `gh repo create flavioricardo/ai-bot-paywall --public`, depois incluir em `rollout-projects.txt` do claude-config e no `ROLLOUT_TOKEN` | [humano] aprovar publicação
- [ ] **Testar num site real com WP Rocket ou LiteSpeed**: rodar Diagnóstico → Testar agora; se falhar, ajustar o padrão em `class-cache-compat.php` | [humano] precisa de site e licença
- [ ] **Fazer pagamento real de centavos**: `PAY_TEST_PRIVATE_KEY=... node tools/pay-test.mjs "<URL de teste>"`, com carteira própria na Base | [humano] envolve dinheiro. Antes de enviar ao WordPress.org, confirmar termos e privacidade da PayAI (https://payai.network): os links antigos dão 404
