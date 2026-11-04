#!/usr/bin/env bash
#
# Gate de qualidade antes de qualquer `git commit` feito pelo Claude Code.
#
# Lê o payload do hook em stdin, identifica comandos de commit e roda
# PHP-CS-Fixer, PHPStan e Psalm no container PHP 8.4. Se qualquer um falhar,
# sai com código 2 para o Claude Code bloquear o commit.
#
# Testes (PHPUnit) são exigidos antes do push/PR e rodam no CI; o comando
# completo é `composer check` (cs + stan + psalm + test + audit).

set -uo pipefail

payload="$(cat)"

command="$(printf '%s' "$payload" | node -e '
let raw = "";
process.stdin.on("data", (chunk) => { raw += chunk; });
process.stdin.on("end", () => {
  try {
    const event = JSON.parse(raw);
    process.stdout.write(event?.tool_input?.command ?? "");
  } catch {
    process.stdout.write("");
  }
});
' 2>/dev/null || true)"

case "$command" in
  *"git commit"*) ;;
  *) exit 0 ;;
esac

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}" || exit 2

run_check() {
  local label="$1"
  shift
  echo "▶ $label" >&2
  if ! "$@"; then
    echo "✖ $label falhou. Corrija e rode novamente antes de commitar." >&2
    exit 2
  fi
}

if ! docker compose exec -T php php -v >/dev/null 2>&1; then
  echo "✖ O container PHP não está rodando. Suba com 'docker compose up -d php' e tente de novo." >&2
  exit 2
fi

run_check "PHP-CS-Fixer (composer cs)" docker compose exec -T php composer cs
run_check "PHPStan (composer stan)" docker compose exec -T php composer stan
run_check "Psalm (composer psalm)" docker compose exec -T php composer psalm

exit 0
