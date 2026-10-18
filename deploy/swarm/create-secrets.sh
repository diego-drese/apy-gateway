#!/bin/sh
# Cria (uma vez) os Docker secrets que deploy/swarm/apy-gateway-stack.yml espera. Rode num nó
# manager. Idempotente: secret que já existe é mantido — trocar um valor exige rotação explícita
# (docs/deploy/swarm.md, "Rotação de segredos"), nunca sobrescrita silenciosa.
#
# Os valores são aleatórios e nunca impressos. Senhas são hex: seguras em URL (REDIS_PASSWORD do
# agent) e em SQL sem escape.
set -eu

random_hex() {
  od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
}

create_secret() {
  name="$1"
  value="$2"
  if docker secret inspect "$name" >/dev/null 2>&1; then
    echo "mantido: $name (já existe)"
    return
  fi
  printf '%s' "$value" | docker secret create "$name" - >/dev/null
  echo "criado:  $name"
}

create_secret apy_gateway_app_key "base64:$(head -c 32 /dev/urandom | base64)"
create_secret apy_gateway_db_root_password "$(random_hex 24)"
create_secret apy_gateway_db_app_password "$(random_hex 24)"
create_secret apy_gateway_db_agent_password "$(random_hex 24)"
create_secret apy_gateway_redis_password "$(random_hex 32)"
create_secret apy_gateway_minio_root_user "apy-root-$(random_hex 4)"
create_secret apy_gateway_minio_root_password "$(random_hex 24)"
create_secret apy_gateway_minio_app_secret_key "$(random_hex 24)"
create_secret apy_gateway_minio_agent_secret_key "$(random_hex 24)"
