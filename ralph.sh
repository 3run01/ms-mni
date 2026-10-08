#!/usr/bin/env bash
#
# ralph.sh — atalho deste projeto para o orquestrador do bc-harness
# (https://github.com/beerandcodeteam/beer-and-code-harness).
#
# Delega para o scripts/ralph.sh do plugin bc-harness instalado no Claude Code
# e fixa o comando de teste do gate 2. Este projeto nao tem PHP nem composer no
# host: o PHP roda no servico `php` do docker-compose. Sem o comando fixado, o
# ralph detecta `composer test`, nao acha o `composer` no host e aborta.
#
# Pre-requisito: containers de pe (docker compose up -d) e a suite verde em
# HEAD — o gate 2 reprova a fase se QUALQUER teste falhar.
#
# Uso (mesmas opcoes do ralph original; --help lista todas):
#   ./ralph.sh --engine claude .spec/features/<slug>/PHASES.md
#
# Variaveis:
#   RALPH_TEST_CMD   sobrepoe o comando de teste (--test-cmd tem prioridade)
#   RALPH_BIN        caminho de outro ralph.sh (ex: checkout local do harness)

set -euo pipefail

export RALPH_TEST_CMD="${RALPH_TEST_CMD:-docker compose exec -T php php artisan test --compact}"

ralph_bin="${RALPH_BIN:-}"

if [ -z "$ralph_bin" ]; then
  plugins_dir="${CLAUDE_CONFIG_DIR:-$HOME/.claude}/plugins"

  # Versao instalada mais recente; o clone do marketplace e o fallback.
  shopt -s nullglob
  installed=("$plugins_dir"/cache/beer-and-code/bc-harness/*/scripts/ralph.sh)
  shopt -u nullglob

  if [ "${#installed[@]}" -gt 0 ]; then
    ralph_bin="$(printf '%s\n' "${installed[@]}" | sort -V | tail -n 1)"
  else
    ralph_bin="$plugins_dir/marketplaces/beer-and-code/scripts/ralph.sh"
  fi
fi

if [ ! -f "$ralph_bin" ]; then
  echo "ralph.sh do bc-harness nao encontrado: $ralph_bin" >&2
  echo "Instale o plugin: claude plugin install bc-harness@beer-and-code --scope project" >&2
  exit 1
fi

exec bash "$ralph_bin" "$@"
