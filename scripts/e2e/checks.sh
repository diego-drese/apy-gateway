#!/bin/sh
# Verificações end-to-end do apy-gateway. NÃO rode direto: é executado por scripts/e2e-test.sh
# dentro de um container "runner" (alpine + curl/jq/openssl) ligado à rede do projeto, uma fase por
# chamada (`checks.sh <fase>`). Estado entre fases (token, ids) fica em $STATE_DIR dentro do runner.
#
# Por que dentro de um container: o teste não depende de curl/jq/openssl instalados no host
# (CLAUDE.md — reprodutível só se roda em Docker) e fala com os serviços pelos nomes da rede
# interna, igual ao que acontece em produção.
set -eu

API="http://control-plane"
MAILPIT="http://mailpit:8025"
STATE_DIR="/tmp/e2e-state"
ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:?E2E_ADMIN_PASSWORD obrigatório}"
BACKEND_BODY="hello from apy-gateway dev backend"

mkdir -p "$STATE_DIR"

pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1" >&2; exit 1; }

token() { cat "$STATE_DIR/token"; }

# api METHOD PATH [JSON_BODY] -> grava corpo em $STATE_DIR/last.json, imprime o status HTTP.
api() {
  if [ $# -ge 3 ]; then
    curl -sS -o "$STATE_DIR/last.json" -w '%{http_code}' -X "$1" "$API$2" \
      -H "Authorization: Bearer $(token)" -H 'Accept: application/json' -H 'Content-Type: application/json' \
      --data "$3"
  else
    curl -sS -o "$STATE_DIR/last.json" -w '%{http_code}' -X "$1" "$API$2" \
      -H "Authorization: Bearer $(token)" -H 'Accept: application/json'
  fi
}

last() { jq -r "$1" "$STATE_DIR/last.json"; }

expect_status() {
  [ "$1" = "$2" ] || { cat "$STATE_DIR/last.json" >&2; fail "$3 (esperado HTTP $2, veio $1)"; }
  pass "$3"
}

# GET http://<replica>/ com Host: <domain>. Imprime o corpo; código de saída do curl preservado
# (52 = "empty reply", que é exatamente o `return 444` do catch-all do nginx.conf).
http_get() {
  curl -sS --max-time 5 -H "Host: $2" "http://$1/" 2>/dev/null
}

# Repete até o conteúdo servido conter (ou deixar de conter) o esperado — o caminho
# API -> MySQL -> Redis -> agent -> nginx -t -> reload é assíncrono por desenho (SPEC.md §8).
wait_served() { # replica domain timeout_secs
  i=0
  while [ "$i" -lt "$3" ]; do
    if http_get "$1" "$2" | grep -q "$BACKEND_BODY"; then return 0; fi
    i=$((i + 1)); sleep 1
  done
  return 1
}

wait_not_served() { # replica domain timeout_secs
  i=0
  while [ "$i" -lt "$3" ]; do
    if ! http_get "$1" "$2" >/dev/null; then return 0; fi
    i=$((i + 1)); sleep 1
  done
  return 1
}

create_host() { # domain [extra_json]
  extra="${2:-}"
  api POST /api/proxy-hosts "{\"domain\":\"$1\",\"forward_scheme\":\"http\",\"forward_host\":\"backend\",\"forward_port\":80$extra}"
}

host_id_of() { cat "$STATE_DIR/host-$1"; }

# Certificado autoassinado com SAN; o campo O identifica a "versão" pra provar a rotação.
make_cert() { # domain org out_prefix
  openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
    -subj "/CN=$1/O=$2" -addext "subjectAltName=DNS:$1" \
    -keyout "$3.key" -out "$3.crt" >/dev/null 2>&1
}

upload_cert() { # out_prefix -> imprime status
  body=$(jq -n --rawfile c "$1.crt" --rawfile k "$1.key" '{certificate: $c, private_key: $k}')
  api POST /api/certificates/upload "$body"
}

served_cert_org() { # replica domain -> imprime o O= do certificado servido via SNI
  echo | openssl s_client -connect "$1:443" -servername "$2" 2>/dev/null \
    | openssl x509 -noout -subject 2>/dev/null | sed -n 's/.*O *= *\([^,]*\).*/\1/p'
}

phase_auth() {
  echo "[auth] login (senha) + 2FA por e-mail via Mailpit"
  curl -sS -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
  rm -f "$STATE_DIR/cookies"

  status=$(curl -sS -o "$STATE_DIR/last.json" -w '%{http_code}' -c "$STATE_DIR/cookies" -b "$STATE_DIR/cookies" \
    -X POST "$API/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    --data "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
  expect_status "$status" 200 "POST /auth/login aceita credenciais e pede 2FA"

  code=""
  i=0
  while [ -z "$code" ] && [ "$i" -lt 30 ]; do
    id=$(curl -sS "$MAILPIT/api/v1/messages" | jq -r '.messages[0].ID // empty')
    if [ -n "$id" ]; then
      code=$(curl -sS "$MAILPIT/api/v1/message/$id" | jq -r '.HTML' | grep -oE '\b[0-9]{6}\b' | head -1 || true)
    fi
    i=$((i + 1)); sleep 1
  done
  [ -n "$code" ] || fail "código 2FA não chegou no Mailpit (queue-worker rodando?)"
  pass "código 2FA entregue por e-mail (fila Redis -> queue-worker -> SMTP)"

  status=$(curl -sS -o "$STATE_DIR/last.json" -w '%{http_code}' -c "$STATE_DIR/cookies" -b "$STATE_DIR/cookies" \
    -X POST "$API/auth/login/verify" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    --data "{\"code\":\"$code\"}")
  expect_status "$status" 200 "POST /auth/login/verify emite token Sanctum"
  last '.token' > "$STATE_DIR/token"
}

phase_proxy_basic() {
  echo "[proxy] CRUD básico propagando até o Nginx"
  status=$(create_host plain.e2e.test)
  expect_status "$status" 201 "cria proxy host plain.e2e.test"
  last '.data.id' > "$STATE_DIR/host-plain.e2e.test"

  wait_served agent plain.e2e.test 20 || fail "plain.e2e.test não passou a ser servido pela réplica"
  pass "réplica serve plain.e2e.test (evento Redis -> sync incremental -> reload)"

  if http_get agent unknown.e2e.test >/dev/null; then fail "domínio desconhecido deveria cair no catch-all 444"; fi
  pass "domínio desconhecido cai no catch-all (444)"

  status=$(api POST /api/proxy-hosts '{"domain":"x.e2e.test","forward_scheme":"http","forward_host":"10.0.0.1:80; } server { listen 80","forward_port":80}')
  expect_status "$status" 422 "injeção de diretiva Nginx em forward_host é rejeitada"

  status=$(create_host Plain.E2E.test)
  expect_status "$status" 422 "domínio que só difere por maiúsculas é rejeitado como duplicado"
}

phase_tls() {
  echo "[tls] upload de certificado, HTTPS, redirect, SNI desconhecido e rotação"
  make_cert secure.e2e.test e2e-v1 "$STATE_DIR/v1"
  status=$(upload_cert "$STATE_DIR/v1")
  expect_status "$status" 201 "upload de certificado (v1)"
  cert_v1=$(last '.data.id')

  status=$(create_host secure.e2e.test ",\"ssl_certificate_id\":$cert_v1")
  expect_status "$status" 201 "cria secure.e2e.test com certificado"
  last '.data.id' > "$STATE_DIR/host-secure.e2e.test"

  i=0
  until [ "$(served_cert_org agent secure.e2e.test)" = "e2e-v1" ]; do
    i=$((i + 1)); [ "$i" -lt 20 ] || fail "HTTPS de secure.e2e.test não serviu o certificado v1"
    sleep 1
  done
  pass "HTTPS serve o certificado enviado (v1)"

  body=$(curl -sk --max-time 5 --connect-to secure.e2e.test:443:agent:443 https://secure.e2e.test/)
  echo "$body" | grep -q "$BACKEND_BODY" || fail "HTTPS não chegou no backend"
  pass "HTTPS faz proxy até o backend"

  location=$(curl -sS -o /dev/null -w '%{redirect_url}' -H 'Host: secure.e2e.test' http://agent/path?q=1)
  [ "$location" = "https://secure.e2e.test/path?q=1" ] || fail "HTTP deveria redirecionar pra HTTPS (veio '$location')"
  pass "HTTP redireciona pra HTTPS preservando path e query"

  if echo | openssl s_client -connect agent:443 -servername nao-existe.e2e.test 2>/dev/null | grep -q 'BEGIN CERTIFICATE'; then
    fail "SNI desconhecido não deveria receber nenhum certificado"
  fi
  pass "SNI desconhecido tem o handshake recusado (nenhum certificado vaza)"

  make_cert secure.e2e.test e2e-v2 "$STATE_DIR/v2"
  status=$(upload_cert "$STATE_DIR/v2")
  expect_status "$status" 201 "upload de certificado (v2)"
  cert_v2=$(last '.data.id')
  status=$(api PUT "/api/proxy-hosts/$(host_id_of secure.e2e.test)" "{\"ssl_certificate_id\":$cert_v2}")
  expect_status "$status" 200 "associa o certificado v2"

  i=0
  until [ "$(served_cert_org agent secure.e2e.test)" = "e2e-v2" ]; do
    i=$((i + 1)); [ "$i" -lt 20 ] || fail "rotação: certificado v2 não passou a ser servido"
    sleep 1
  done
  pass "rotação de certificado sem downtime (v1 -> v2)"
}

phase_lifecycle() {
  echo "[lifecycle] rename, disable/enable e delete"
  id=$(host_id_of plain.e2e.test)

  status=$(api PUT "/api/proxy-hosts/$id" '{"domain":"renamed.e2e.test"}')
  expect_status "$status" 200 "renomeia plain.e2e.test -> renamed.e2e.test"
  wait_served agent renamed.e2e.test 20 || fail "novo nome não passou a ser servido"
  wait_not_served agent plain.e2e.test 20 || fail "nome antigo continuou sendo servido após o rename"
  pass "rename: novo nome servido, nome antigo removido na hora"

  status=$(api PUT "/api/proxy-hosts/$id" '{"enabled":false}')
  expect_status "$status" 200 "desabilita renamed.e2e.test"
  wait_not_served agent renamed.e2e.test 20 || fail "host desabilitado continuou sendo servido"
  pass "host desabilitado sai do ar"

  status=$(api PUT "/api/proxy-hosts/$id" '{"enabled":true}')
  expect_status "$status" 200 "reabilita renamed.e2e.test"
  wait_served agent renamed.e2e.test 20 || fail "host reabilitado não voltou"
  pass "host reabilitado volta ao ar"

  status=$(api DELETE "/api/proxy-hosts/$id")
  expect_status "$status" 204 "remove renamed.e2e.test"
  wait_not_served agent renamed.e2e.test 20 || fail "host removido continuou sendo servido"
  pass "host removido sai do ar"
}

phase_redis_down_create() {
  echo "[resiliência] Redis fora do ar durante uma alteração"
  status=$(create_host offline-event.e2e.test)
  expect_status "$status" 201 "API continua aceitando alterações com o Redis fora"
}

phase_redis_down_verify() {
  # Evento perdido de propósito: só a reconciliação periódica (RECONCILIATION_INTERVAL_SECS=60 no
  # compose de dev) pode trazer o domínio — é a garantia do SPEC.md §8/§9.2.
  wait_served agent offline-event.e2e.test 120 || fail "reconciliação periódica não aplicou o domínio cujo evento se perdeu"
  pass "reconciliação periódica aplica o que o evento perdido não entregou"
}

phase_replicas() {
  echo "[observabilidade] heartbeat -> replicas:sync-metrics -> GET /api/replicas"
  status=$(api GET /api/replicas)
  expect_status "$status" 200 "GET /api/replicas"
  online=$(last '[.data[] | select(.hostname == "dev-replica-1" and .status == "online")] | length')
  [ "$online" = "1" ] || { cat "$STATE_DIR/last.json" >&2; fail "dev-replica-1 deveria estar online"; }
  count=$(last '.data[] | select(.hostname == "dev-replica-1") | .synced_domains_count')
  [ "$count" -ge 2 ] || fail "synced_domains_count deveria ser >= 2 (veio $count)"
  pass "dev-replica-1 online com synced_domains_count=$count"
}

phase_multi_replica() {
  echo "[multi-réplica] segunda réplica nasce sem estado e converge sozinha"
  replica2="${E2E_SECOND_REPLICA:?}"
  wait_served "$replica2" offline-event.e2e.test 60 || fail "réplica 2 não sincronizou o estado existente no boot"
  [ "$(served_cert_org "$replica2" secure.e2e.test)" = "e2e-v2" ] || fail "réplica 2 não baixou o certificado atual do MinIO"
  pass "réplica 2 sobe do zero com todos os domínios e certificados"

  status=$(create_host fanout.e2e.test)
  expect_status "$status" 201 "cria fanout.e2e.test"
  wait_served agent fanout.e2e.test 20 || fail "réplica 1 não recebeu o evento"
  wait_served "$replica2" fanout.e2e.test 20 || fail "réplica 2 não recebeu o evento"
  pass "um único evento propaga pras duas réplicas"
}

phase_after_restart() {
  echo "[boot] réplica reiniciada (estado local efêmero) volta servindo tudo"
  wait_served agent fanout.e2e.test 60 || fail "réplica reiniciada não voltou a servir fanout.e2e.test"
  [ "$(served_cert_org agent secure.e2e.test)" = "e2e-v2" ] || fail "réplica reiniciada não serve o certificado atual"
  pass "réplica reiniciada ressincroniza tudo do núcleo"
}

case "${1:-}" in
  auth) phase_auth ;;
  proxy-basic) phase_proxy_basic ;;
  tls) phase_tls ;;
  lifecycle) phase_lifecycle ;;
  redis-down-create) phase_redis_down_create ;;
  redis-down-verify) phase_redis_down_verify ;;
  replicas) phase_replicas ;;
  multi-replica) phase_multi_replica ;;
  after-restart) phase_after_restart ;;
  *) echo "fase desconhecida: ${1:-<vazia>}" >&2; exit 2 ;;
esac
