#!/bin/sh
# Fase 11 (CLAUDE.md: "um novo desenvolvedor precisa conseguir iniciar o projeto com um único
# comando") — checa cada serviço do ambiente de dev de verdade (não confia só no status de
# healthcheck do Compose, faz a mesma chamada que a aplicação faria). Sai != 0 se algo falhar.
set -e

cd "$(dirname "$0")/.."
COMPOSE="docker compose -f examples/docker-compose.yml --env-file .env"

fail=0

check() {
  label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    echo "OK   $label"
  else
    echo "FAIL $label"
    fail=1
  fi
}

check "control-plane (/up)" curl -fsS http://localhost:8000/up
check "mysql"               $COMPOSE exec -T mysql mysqladmin ping -h 127.0.0.1 -uroot -p"${DB_PASSWORD:-root}"
check "redis"                $COMPOSE exec -T redis redis-cli ping
check "minio"                curl -fsS http://localhost:9000/minio/health/live
check "mailpit"              curl -fsS http://localhost:8025/api/v1/info

if [ "$fail" -ne 0 ]; then
  echo "Algum serviço não respondeu. Veja: $COMPOSE ps / $COMPOSE logs <serviço>"
  exit 1
fi

echo "Todos os serviços respondendo."
