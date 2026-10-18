#!/bin/sh
# Teste end-to-end do apy-gateway contra o ambiente real (examples/docker-compose.yml, projeto
# Compose `apy-gateway`): API autenticada (login + 2FA por e-mail) -> MySQL/MinIO -> evento Redis
# -> agent Rust -> nginx -t/reload -> tráfego HTTP(S) de verdade, incluindo falhas (Redis fora,
# réplica reiniciada) e uma segunda réplica.
#
# Uso:
#   ./scripts/e2e-test.sh            # sobe, testa e derruba (volumes inclusos)
#   KEEP_ENV=1 ./scripts/e2e-test.sh # deixa o ambiente no ar no final pra inspeção
#
# Só depende de `docker` no host: as chamadas HTTP rodam num container runner (scripts/e2e/checks.sh).
# Leva ~4 min (a fase de resiliência espera de propósito a reconciliação periódica de 60s).
set -eu

cd "$(dirname "$0")/.."

if [ -f .env ]; then
  set -a
  . ./.env
  set +a
fi

COMPOSE="docker compose -f examples/docker-compose.yml --env-file .env"
NETWORK="apy-gateway_default"
RUNNER="apy-gateway-e2e-runner"
SECOND_REPLICA="apy-gateway-e2e-agent-2"
ADMIN_PASSWORD="e2e-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"

cleanup() {
  status=$?
  docker rm -f "$RUNNER" "$SECOND_REPLICA" >/dev/null 2>&1 || true
  if [ "$status" -ne 0 ]; then
    echo
    echo "E2E FALHOU. Últimos logs do agent:"
    $COMPOSE logs --tail=40 agent 2>/dev/null || true
  fi
  if [ "${KEEP_ENV:-0}" = "1" ]; then
    echo "KEEP_ENV=1 — ambiente mantido no ar ($COMPOSE ps)."
  else
    echo "Derrubando o ambiente de teste..."
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT INT TERM

checks() {
  docker exec -e E2E_ADMIN_PASSWORD="$ADMIN_PASSWORD" -e E2E_SECOND_REPLICA="$SECOND_REPLICA" \
    "$RUNNER" sh /e2e/checks.sh "$1"
}

echo "== Subindo o ambiente (projeto apy-gateway)"
./scripts/install.sh >/dev/null

echo "== Preparando o runner de testes"
docker rm -f "$RUNNER" >/dev/null 2>&1 || true
docker run -d --name "$RUNNER" --network "$NETWORK" -v "$PWD/scripts/e2e:/e2e:ro" \
  alpine:3.20 sleep 3600 >/dev/null
docker exec "$RUNNER" apk add --no-cache curl jq openssl >/dev/null
RUNNER_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "$RUNNER")

# Fixture, não fluxo de produto: libera o IP do runner e define uma senha conhecida pro admin
# criado pelo install.sh (o fluxo real usa o link de definição de senha por e-mail).
$COMPOSE exec -T control-plane php artisan gateway:bootstrap-admin --ip="$RUNNER_IP" >/dev/null
$COMPOSE exec -T control-plane php artisan tinker --execute="App\\Models\\User::query()->where('email', 'admin@example.com')->firstOrFail()->forceFill(['password' => Illuminate\\Support\\Facades\\Hash::make('$ADMIN_PASSWORD')])->save();" >/dev/null

echo "== Guardas de acesso (SPEC.md §6)"
code=$(docker run --rm --network "$NETWORK" curlimages/curl:8.10.1 -s -o /dev/null -w '%{http_code}' http://control-plane/login)
[ "$code" = "403" ] || { echo "  FAIL  /login de IP fora da allowlist deveria dar 403 (veio $code)"; exit 1; }
echo "  PASS  /login (web) recusa IP fora da allowlist"

checks auth
checks proxy-basic
checks tls
checks lifecycle

echo "== Resiliência: Redis parado"
$COMPOSE stop redis >/dev/null
checks redis-down-create
$COMPOSE start redis >/dev/null
checks redis-down-verify

echo "== Métricas de réplica"
# O heartbeat sai a cada 15s: espera o primeiro que já reflete a reconciliação acima, senão o
# replicas:sync-metrics leria a contagem anterior (corrida do teste, não do produto).
i=0
until $COMPOSE exec -T redis redis-cli get apy-gateway:replicas:dev-replica-1:heartbeat | grep -q '"synced_domains_count":2'; do
  i=$((i + 1)); [ "$i" -lt 20 ] || { echo "  FAIL  heartbeat não refletiu os 2 domínios ativos"; exit 1; }
  sleep 2
done
$COMPOSE exec -T control-plane php artisan replicas:sync-metrics >/dev/null
checks replicas

echo "== Segunda réplica"
$COMPOSE run -d --no-deps --name "$SECOND_REPLICA" -e AGENT_HOSTNAME=dev-replica-2 agent >/dev/null
checks multi-replica

echo "== Réplica reiniciada"
$COMPOSE restart agent >/dev/null
checks after-restart

echo
echo "E2E OK — todas as fases passaram."
