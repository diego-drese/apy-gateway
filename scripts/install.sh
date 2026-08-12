#!/bin/sh
# Fase 11 — o "único comando" que o CLAUDE.md pede: sobe o ambiente de dev completo
# (examples/docker-compose.yml) e deixa pronto pra logar, não só com os containers no ar.
set -e

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "Criando .env a partir de examples/.env.example..."
  cp examples/.env.example .env
fi

if ! grep -q "^APP_KEY=.\+" .env; then
  echo "Gerando APP_KEY..."
  key="base64:$(openssl rand -base64 32)"
  # BSD sed (macOS) e GNU sed (Linux) divergem na flag -i — tenta as duas formas.
  sed -i.bak "s|^APP_KEY=.*|APP_KEY=${key}|" .env 2>/dev/null || sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
  rm -f .env.bak
fi

COMPOSE="docker compose -f examples/docker-compose.yml --env-file .env"

echo "Subindo os serviços..."
$COMPOSE up -d --build

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

echo "Liberando IP e criando o primeiro usuário admin (idempotente)..."
$COMPOSE exec -T control-plane php artisan gateway:bootstrap-admin

cat <<'EOF'

Pronto:
  - Control-plane: http://localhost:8000
  - Mailpit (e-mails de dev, inclusive o link pra definir a senha do admin): http://localhost:8025
  - MinIO console: http://localhost:19001
  - Réplica agent+nginx: http://localhost:8080 (HTTPS: https://localhost:8443)

Se acessar de um IP diferente de 127.0.0.1 (ex.: outra máquina na rede), rode:
  docker compose -f examples/docker-compose.yml --env-file .env exec control-plane \
    php artisan gateway:bootstrap-admin --ip=<seu-ip>
EOF
