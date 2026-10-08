#!/usr/bin/env bash
# Executado pelo entrypoint do Postgres somente na primeira inicialização (volume vazio).
# Habilita o pgvector e restaura, em ordem alfabética, os dumps em /dumps (docker/postgres/dumps).
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -c 'CREATE EXTENSION IF NOT EXISTS vector;'

shopt -s nullglob
for dump in /dumps/*; do
    [ -f "$dump" ] || continue

    echo "Restaurando $dump em $POSTGRES_DB..."
    if [ "$(head -c 5 "$dump")" = "PGDMP" ]; then
        # Formato custom do pg_dump (-Fc), independente da extensão do arquivo
        pg_restore --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --no-owner --no-acl "$dump"
    else
        psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -f "$dump"
    fi
done
