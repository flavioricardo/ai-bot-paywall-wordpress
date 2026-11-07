# Portaria: registro de bots e cobrança x402 para WordPress

Status: aprovado pelo Flávio via `/goal` em 2026-11-06 ("siga até o final, com
code review e fix"). Decisões marcadas "(Claude)" são escolhas do implementador,
tomadas sem pergunta depois do goal; o resto veio da conversa.

## Intenção

Dar visibilidade ao Flávio como dev WordPress. Ele quer ser procurado por devs
com dúvidas e por empresas, brasileiras ou de fora, que querem implantar x402 e
ter dados reais de pagamentos. Plugin é vitrine; receita vem de implantação e
suporte.

**Promessa:** mostra quem chega, cobra quem pode pagar e registra tudo. Renda
com bots não é garantida.

## Objetivos

1. Registrar bots por dia, com identidade verificada, falsa ou desconhecida.
2. Cobrar via x402 (v2 e v1), pagamento direto na carteira do dono.
3. Mostrar pagamentos e bots na mesma tela (mockup A + B aprovado).
4. Funcionar com cache de página inteira, a lacuna do ForgeMesh:
   WP Rocket e LiteSpeed integrados; diagnóstico detecta qualquer outro cache.
5. Provar que a entrada funciona: pagamento de teste com link da transação.
6. Exibir valores e aceitar preço em reais, com recebimento em USDC.

## Não objetivos

- Stablecoin em real, Pix, MPP, Solana (token e rede ficam em filtro).
- Relatório brasileiro agregado: depende de sites parceiros, que não existem.
- Versão Pro, servidor do Flávio, conta externa, telemetria.
- Trecho grátis para bots e arquivos llms.txt ou agents.txt (Claude).

## Antiobjetivos

Prometer renda; derrubar SEO; custodiar dinheiro; depender de servidor do
Flávio; publicar número inventado como dado real; guardar IP de visitante.

## Decisões do Flávio

- Plugin inteiro grátis, GPL. Inglês base, pt-BR incluído.
- Cobrança x402 na primeira versão.
- Instala em modo Monitor: observa sem cobrar nem bloquear.
- Tela inicial: funil de pagamento em cima, tabela de bots embaixo.
- Real: exibição e preço em R$ (PTAX), recebimento em USDC.
- Sites de teste: nenhum real; testes com WordPress local e bots simulados.

## Comportamento

### Classificação

Catálogo próprio por User-Agent. Classes: `search`, `social`, `service`
(sempre liberadas), `ai_training`, `ai_search`, `ai_agent`, `seo`, `script`
(controladas por política). Navegador sem assinatura de pagamento é humano e
nunca é tocado. Humano logado, admin, robots, sitemap, 404 e OPTIONS nunca passam
pela portaria. POST e outros métodos passam: o WordPress renderiza a mesma página
para eles, então ficar só em GET deixaria um desvio aberto.

### Identidade

- `verified`: Web Bot Auth, faixa de IP publicada ou DNS reverso confirmado.
- `fake`: alega ser bot verificável e falha na verificação.
- `unknown`: não deu para verificar agora (orçamento de DNS, faixas não baixadas).
- `claimed`: bot sem método público de verificação.

Buscador em `unknown` passa grátis: SEO vem antes de receita. Atrás de proxy não
configurado, verificação que falha vira `unknown`, com aviso no painel; o dono
resolve escolhendo o cabeçalho de IP ou declarando que não há proxy. Web Bot Auth só
busca chaves em hosts do catálogo, nunca na URL enviada pelo cliente (SSRF).
IP nunca é guardado em texto puro; cache de identidade usa HMAC com sal diário.

### Decisão

Prioridade (Claude): buscador não falso sempre libera, depois regra por bot (nunca
para impostor), serviços e prévias de link, regra de
conteúdo (`path:`, `category:`, `type:`; primeira que casar) e política da classe.
Padrões ao ativar: treinamento cobra, busca de IA libera, agente cobra, SEO
libera, script cobra, falso cobra (Claude). Liberar busca de IA evita tirar o
site das respostas de assistentes sem o dono decidir isso.

### Modos

- **Monitor** (padrão): registra e calcula o que seria cobrado. Nada muda.
- **Ativo**: exige carteira e confirmação explícita do risco de crawlers de IA.

### Pagamento

Fluxo `authorization` do spec: confere localmente, `/verify`, renderiza a página
em buffer, `/settle`, entrega. Se a página falhar, ninguém é cobrado. Se o settle
falhar, a resposta é 402 sem conteúdo. `settlement_pending` entrega a página e
registra como pendente, porque o dinheiro pode ter saído.

Requisitos enviados ao facilitator são sempre os do servidor, nunca os do
cliente. Chamadas ao facilitator têm orçamento por minuto contra abuso.

### Cache

- Bots conhecidos entram em `rocket_cache_reject_ua` no WP Rocket.
- No LiteSpeed, bloco no `.htaccess` antes do LSCACHE, removido ao desativar.
- Diagnóstico: requisição real com User-Agent de bot e token. Se a resposta não
  trouxer o token, o cache está furando a portaria, seja qual for o cache.
- 402 sai com `no-store`; toda resposta decidida define `DONOTCACHEPAGE`.

### Dados

- `portaria_daily`: agregado por dia, bot e identidade.
- `portaria_payments`: pagamentos, com status, payer público e hash.
- Retenção configurável; sem IP, sem User-Agent cru.

## Serviços externos

Todos divulgados no readme: facilitator configurado (só com pagamento presente),
listas públicas de IP de OpenAI e Perplexity, PTAX do Banco Central, diretórios
Web Bot Auth de hosts do catálogo.

## Verificação

- Testes unitários em PHP 7.4 e 8.3 via `@php-wasm/node`.
- E2E com WordPress real (Playground), bots simulados, facilitator de mentira e
  cliente oficial `@x402/fetch` assinando de verdade.
- Interop opcional contra o facilitator real em testnet, sem fundos.

Limite assumido: WP Rocket e LiteSpeed reais não rodam aqui (um é pago, o outro
exige servidor LiteSpeed). A integração é testada com dublês; o diagnóstico é a
rede de segurança em produção.

## Decisão de publicação

GitHub e WordPress.org ficam para aprovação do Flávio: são ações públicas.
