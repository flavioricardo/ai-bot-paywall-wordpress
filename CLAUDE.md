<!-- BEGIN:claude-config-base -->
<!-- Gerado de flavioricardo/claude-config (templates/CLAUDE-base.md). Não editar aqui: editar lá e rodar scripts/rollout-base.sh. -->
## Regras base (valem em sessão local e na nuvem)

- Português quando o Flávio escrever em português. Modo caveman nível full (skill `caveman`) na prosa; exceções: código, commits, PRs, aviso de segurança, confirmação de ação irreversível, texto pra terceiros.
- **Início:** ler `STATE.md` (fonte única de verdade). **Fim:** atualizar só com fato vivo. Pendência fechada é **apagada** — nunca seção "Resolvidas", "Feito" ou log de sessão; histórico mora no `git log`. Passou de ~150 linhas: enxugar antes de acrescentar.
- **Código chega na main só por PR** — nunca push direto, nem de STATE.md. Antes do merge: rodar a skill `code-review` e corrigir o que ela achar, e conferir o check `ci` verde no último commit (não há branch protection — ninguém mais barra); o corpo do PR diz o que o review achou e como foi verificado (comando + resultado). Build limpo sozinho não é verificação.
- Feature nova: skill `brainstorming` antes de codar (spec/plano em `docs/superpowers/`). Bug: skill `systematic-debugging` antes de propor correção.
- **Credencial** (token, senha, chave, JWT de sessão) colada no chat: não usar, avisar, e abrir pendência P1 de rotação no `STATE.md`. O hook `.claude/hooks/block-secrets.sh` já barra os formatos conhecidos.
- Pendência que só o Flávio fecha (pessoa, painel, ação manual) leva a marca `[humano]` e não trava trabalho novo. Pendência técnica aberta há 2+ sessões trava: resolver antes de começar outra coisa.
- Ícones em projeto React: `lucide-react`, salvo exceção registrada no `CLAUDE.md` do próprio projeto (fora deste bloco). Marca (Google, Instagram, logo do produto) em SVG inline — Lucide 1.0 tirou os ícones de marca.
- Autoria: nunca e-mail corporativo em repo pessoal (a Vercel bloqueia o deploy com erro de permissão). Valem `flaviobazana@gmail.com` e `flavioricardo@live.com`; se o global da máquina for corporativo: `git config user.email flaviobazana@gmail.com` no repo.
<!-- END:claude-config-base -->
