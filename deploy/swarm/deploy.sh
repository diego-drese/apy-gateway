#!/bin/sh
# Deploy (ou atualização) da stack apy-gateway num manager do Swarm. Carrega deploy/swarm/.env,
# confere as variáveis obrigatórias e roda `docker stack deploy`. Idempotente — rodar de novo
# aplica só o que mudou (ex.: trocar EDGE_HTTP_PORT de 8080 para 80 no corte).
#
#   ./deploy/swarm/deploy.sh                 # usa deploy/swarm/.env
#   ENV_FILE=/caminho/.env ./deploy/swarm/deploy.sh
#
# Existe porque a stack não pode usar `${VAR:?erro}` (o `docker stack deploy` corrompe o valor —
# ver o cabeçalho de apy-gateway-stack.yml): sem esta checagem, uma variável esquecida viraria
# string vazia em produção em vez de um erro.
set -eu

cd "$(dirname "$0")"
ENV_FILE="${ENV_FILE:-.env}"
STACK_NAME="${STACK_NAME:-apy-gateway}"

if [ -f "$ENV_FILE" ]; then
  set -a
  . "$ENV_FILE"
  set +a
fi

missing=""
for var in APY_GATEWAY_VERSION CONTROL_PLANE_URL UPSTREAMS_NETWORK MAIL_HOST MAIL_FROM_ADDRESS ADMIN_NOTIFICATION_EMAIL; do
  eval "value=\${$var:-}"
  [ -n "$value" ] || missing="$missing $var"
done
if [ -n "$missing" ]; then
  echo "faltando em $ENV_FILE:$missing" >&2
  exit 1
fi

if ! docker network inspect "$UPSTREAMS_NETWORK" --format '{{.Scope}}' 2>/dev/null | grep -qx swarm; then
  echo "rede overlay '$UPSTREAMS_NETWORK' (UPSTREAMS_NETWORK) não existe neste swarm" >&2
  exit 1
fi

for secret in app_key db_root_password db_app_password db_agent_password redis_password \
  minio_root_user minio_root_password minio_app_secret_key minio_agent_secret_key; do
  docker secret inspect "apy_gateway_$secret" >/dev/null 2>&1 || {
    echo "secret apy_gateway_$secret não existe — rode deploy/swarm/create-secrets.sh" >&2
    exit 1
  }
done

# Arquivos extras (-c) são mesclados por cima — usado pelo ensaio (simulation/stack.sim.yml).
extra=""
for file in ${EXTRA_STACK_FILES:-}; do extra="$extra -c $file"; done

# shellcheck disable=SC2086 # $extra é uma lista de flags intencionalmente dividida
docker stack deploy --detach=true --with-registry-auth -c apy-gateway-stack.yml $extra "$STACK_NAME"
echo "deploy enviado. Acompanhe: docker stack services $STACK_NAME"
