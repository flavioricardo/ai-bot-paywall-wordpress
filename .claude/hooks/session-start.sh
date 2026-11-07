#!/usr/bin/env bash
# session-start.sh — hook SessionStart do projeto.
# Origem: flavioricardo/claude-config (templates/hooks). Stdout vira contexto
# da sessão. Nunca falha a sessão: qualquer erro sai com 0.
cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0
command -v timeout >/dev/null 2>&1 || timeout() { shift; "$@"; }   # macOS não tem

if [ "${CLAUDE_CODE_REMOTE:-}" = "true" ]; then
  # Nuvem: nada de ~/.claude aqui, então o lembrete do caveman vem daqui.
  echo "Regras base no CLAUDE.md (bloco claude-config-base). Caveman nível full na prosa: invoque a skill caveman antes da primeira resposta."

  # Dependências pra poder rodar teste/lint antes de dizer que terminou.
  # Só instala pelo lockfile (nunca reescreve lock). Marcador em vez de
  # "node_modules existe": install cortado no meio tenta de novo.
  if [ -f package.json ] && [ ! -f node_modules/.deps-ok ]; then
    if [ -f package-lock.json ]; then
      npm ci --no-audit --no-fund >/dev/null 2>&1 && touch node_modules/.deps-ok
    elif [ -f pnpm-lock.yaml ]; then
      { command -v pnpm >/dev/null || corepack enable >/dev/null 2>&1; } &&
        pnpm install --frozen-lockfile >/dev/null 2>&1 && touch node_modules/.deps-ok
    fi
    [ -f node_modules/.deps-ok ] ||
      echo "AVISO: dependências não instaladas (install falhou ou lockfile não é npm/pnpm) — instale antes de rodar teste/lint."
  fi
else
  # Local: o clone pode estar velho (regra: git fetch antes de ler o STATE.md).
  timeout 10 git fetch -q 2>/dev/null
  behind=$(git rev-list --count 'HEAD..@{u}' 2>/dev/null)
  [ "${behind:-0}" -gt 0 ] &&
    echo "AVISO: clone local $behind commit(s) atrás do remoto — git pull antes de confiar no STATE.md abaixo."
fi

# Pendências abertas do STATE.md, pra sessão começar por elas. Vai até o
# próximo título do mesmo nível ou acima (subtítulos entram).
if [ -f STATE.md ]; then
  pend=$(awk '
    !lvl && /^#+[[:space:]]*[Pp]end/ { match($0, /^#+/); lvl=RLENGTH; print; next }
    lvl && /^#+[[:space:]]/ { match($0, /^#+/); if (RLENGTH <= lvl) exit }
    lvl' STATE.md)
  [ -n "$pend" ] && printf '%s\n' "STATE.md — pendências abertas (técnica aberta há 2+ sessões trava trabalho novo; [humano] não trava):" "$pend" | head -60
fi
exit 0
