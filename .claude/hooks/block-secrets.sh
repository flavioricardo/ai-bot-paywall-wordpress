#!/usr/bin/env bash
# block-secrets.sh — hook UserPromptSubmit: barra prompt com cara de credencial.
# Origem: flavioricardo/claude-config (templates/hooks). Exit 2 = prompt bloqueado,
# stderr aparece pro usuário. Sem jq (Git Bash no Windows pode não ter).
#
# --project: cópia registrada no .claude/ de um projeto. Se o settings.json
# global já registra este hook, sai sem fazer nada (evita rodar e avisar 2x).
# --scan: texto cru no stdin (não JSON de hook) — o sync.sh passa as linhas
# adicionadas antes do auto-commit. Exit 2 = achou credencial.
if [ "${1:-}" = "--project" ] && grep -q 'block-secrets.sh' "$HOME/.claude/settings.json" 2>/dev/null; then
  exit 0
fi

if [ "${1:-}" = "--scan" ]; then
  prompt=$(cat)
else
  # Só o campo "prompt" do JSON — cwd e transcript_path não entram na checagem.
  prompt=$(grep -oE '"prompt"[[:space:]]*:[[:space:]]*"([^"\\]|\\.)*"')
fi

# Formatos com prefixo conhecido: batem sozinhos.
# GitHub, OpenAI/Anthropic, Supabase, AWS, Slack, Google (API key e client
# secret OAuth), Stripe, Resend, npm, GitLab.
known='(^|[^A-Za-z0-9])(gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|sk-[A-Za-z0-9_-]{20,}|sbp_[a-f0-9]{30,}|AKIA[0-9A-Z]{16}|xox[abprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35}|GOCSPX-[A-Za-z0-9_-]{20,}|[sr]k_(live|test)_[A-Za-z0-9]{20,}|re_[A-Za-z0-9]{8,}_[A-Za-z0-9]{16,}|npm_[A-Za-z0-9]{36}|glpat-[A-Za-z0-9_-]{20,})|-----BEGIN [A-Z ]*PRIVATE KEY-----|eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}'

# NOME_TOKEN=valor: nome termina em token/secret/senha/password/key, valor de
# 16+ caracteres com dígito (tokenCount = computeTokens(...) não bate).
assign='[A-Za-z0-9_]*(token|secret|senha|password|passwd|api[_-]?key)[\\"]*[[:space:]]*[:=][[:space:]]*[\\"]*[A-Za-z0-9_./+=-]{16,}'

if printf '%s' "$prompt" | grep -qE "$known" ||
   printf '%s' "$prompt" | grep -oiE "$assign" | sed -E 's/^[^:=]*[:=]//' | grep -q '[0-9]'; then
  echo "Bloqueado: o prompt parece conter uma credencial. Não cole segredo no chat — use variável de ambiente/painel. Se já colou em outro lugar, trate como exposto e rotacione." >&2
  exit 2
fi
exit 0
