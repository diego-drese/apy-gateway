#!/bin/sh
# One-shot (serviço `db-grants` da stack): garante o usuário MySQL do agent com SELECT apenas nas
# tabelas que ele lê (SPEC.md §9.4) — nunca INSERT/UPDATE/DELETE: toda escrita é do control-plane.
#
# Por que um serviço e não /docker-entrypoint-initdb.d: o MySQL recusa GRANT em tabela que ainda
# não existe, e as tabelas só aparecem quando o serviço `migrate` roda. Aqui esperamos por elas.
# Idempotente e roda a cada deploy: também ressincroniza a senha com o secret (rotação) e funciona
# igual num MySQL que já tinha dados.
set -eu

MYSQL_PWD="$(cat /run/secrets/db_root_password)"
export MYSQL_PWD
agent_password="$(cat /run/secrets/db_agent_password)"
database="${MYSQL_DATABASE:?}"
tables="proxy_hosts ssl_certificates domain_events acme_challenges"
expected=$(echo $tables | wc -w)
in_list=$(echo $tables | sed "s/\([a-z_]*\)/'\1'/g; s/ /,/g")

until [ "$(mysql -h mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name IN ($in_list)" 2>/dev/null)" = "$expected" ]; do
  echo "aguardando MySQL e migrations ($tables)..."
  sleep 5
done

{
  echo "CREATE USER IF NOT EXISTS 'apy_agent'@'%' IDENTIFIED BY '$agent_password';"
  echo "ALTER USER 'apy_agent'@'%' IDENTIFIED BY '$agent_password';"
  for table in $tables; do
    echo "GRANT SELECT ON \`$database\`.\`$table\` TO 'apy_agent'@'%';"
  done
} | mysql -h mysql -uroot

echo "usuário apy_agent com SELECT em: $tables"
