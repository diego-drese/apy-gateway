#!/bin/sh
# Fase 11 — reconstrói as imagens locais (control-plane/agent) e reaplica o schema. Migração roda
# via o serviço `migrate` (idempotente, faz parte da própria dependência do compose), não precisa
# de passo separado aqui.
set -e

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f examples/docker-compose.yml --env-file .env"

echo "Reconstruindo imagens..."
$COMPOSE build

echo "Subindo com as imagens novas..."
$COMPOSE up -d

echo "Esperando os serviços ficarem saudáveis..."
attempt=0
until ./scripts/healthcheck.sh >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 40 ]; then
    echo "Timeout esperando os serviços. Veja: $COMPOSE ps / $COMPOSE logs"
    exit 1
  fi
  sleep 3
done

echo "Atualizado."
