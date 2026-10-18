#!/bin/sh
# Ensaio completo da migração NPM -> apy-gateway num cluster Swarm de 4 nós (3 managers + 1 worker),
# seguindo os mesmos passos de docs/deploy/swarm.md. Pré-requisitos:
#   docker compose -f examples/docker-compose.yml build   # imagens do projeto
#   ./deploy/swarm/simulation/cluster.sh up               # nós dind + registry
#
# Etapas: (1) NPM em produção simulada com 4 hosts reais; (2) núcleo + borda do apy-gateway em
# portas canário; (3) import do NPM; (4) comparação canário x NPM; (5) corte para 80/443;
# (6) drills de falha (nó do núcleo fora, propagação para todos os nós).
set -eu

cd "$(dirname "$0")/../../.."

NETWORK="apy-sim"
REG="apy-sim-registry:5000"
NODES="apy-sim-m1 apy-sim-m2 apy-sim-m3 apy-sim-w1"
RUNNER="apy-sim-runner"
NPM_NODE="apy-sim-m2"
CORE_NODE="apy-sim-w1"
OPS_EMAIL="ops@sim.test"

pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1" >&2; exit 1; }
m1() { docker exec -i apy-sim-m1 "$@"; }
r() { docker exec "$RUNNER" sh -c "$1"; }
ip_of() { docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "$1"; }

# Espera um serviço da stack ter N tasks rodando (e, quando houver healthcheck, healthy).
wait_service() { # service desired timeout
  i=0
  while [ "$i" -lt "$3" ]; do
    running=$(m1 docker service ls --filter "name=$1" --format '{{.Name}} {{.Replicas}}' | awk -v s="$1" '$1==s {print $2}')
    [ "$running" = "$2/$2" ] && return 0
    i=$((i + 3)); sleep 3
  done
  m1 docker service ps --no-trunc "$1" | head -8 >&2
  fail "serviço $1 não convergiu para $2/$2 (está ${running:-?})"
}

container_on() { # node service-name-prefix
  docker exec "$1" docker ps -q --filter "name=$2" | head -1
}

artisan() {
  cp_container=$(container_on "$CORE_NODE" apy-gateway_control-plane)
  docker exec -i "$CORE_NODE" docker exec -i "$cp_container" php artisan "$@"
}

# Linha "Hostname: <container>" do whoami: identifica qual backend respondeu.
backend_of() { # url host [extra curl args]
  r "curl -s --max-time 5 ${3:-} -H 'Host: $2' '$1' | sed -n 's/^Hostname: //p'"
}

cert_fingerprint() { # ip:port sni
  r "echo | openssl s_client -connect $1 -servername $2 2>/dev/null | openssl x509 -noout -fingerprint -sha256 2>/dev/null"
}

stack_deploy() { # http_port https_port [update_parallelism]
  docker exec apy-sim-m1 rm -rf /stack
  docker cp deploy/swarm apy-sim-m1:/stack
  m1 sh -c "cd /stack && \
    REGISTRY=$REG APY_GATEWAY_VERSION=sim \
    MYSQL_IMAGE=$REG/mysql:8.4 REDIS_IMAGE=$REG/redis:7.4-alpine \
    MINIO_IMAGE=$REG/minio:sim MC_IMAGE=$REG/mc:sim \
    CONTROL_PLANE_URL=http://$CORE_NODE:8000 UPSTREAMS_NETWORK=proxy \
    EDGE_HTTP_PORT=$1 EDGE_HTTPS_PORT=$2 EDGE_UPDATE_PARALLELISM=${3:-1} \
    MAIL_HOST=mailpit MAIL_PORT=1025 MAIL_FROM_ADDRESS=gateway@sim.test ADMIN_NOTIFICATION_EMAIL=infra@sim.test \
    EXTRA_STACK_FILES=simulation/stack.sim.yml ENV_FILE=/dev/null ./deploy.sh" >/dev/null
}

echo "== 0. Limpeza de execuções anteriores, runner de testes e labels dos nós"
m1 docker stack rm apy-gateway npm backends >/dev/null 2>&1 || true
i=0
until [ -z "$(m1 docker service ls -q)" ]; do i=$((i + 1)); [ "$i" -lt 40 ] || break; sleep 3; done
# Volumes só podem ser removidos depois que os containers das stacks saírem de fato de cada nó.
for n in $NODES; do
  i=0
  until [ -z "$(docker exec "$n" docker ps -aq --filter label=com.docker.stack.namespace)" ]; do
    i=$((i + 1)); [ "$i" -lt 40 ] || fail "containers antigos não saíram de $n"; sleep 2
  done
  docker exec "$n" sh -c 'docker volume ls -q | grep -E "^(apy-gateway|npm)_" | xargs -r docker volume rm' >/dev/null
done
m1 sh -c 'docker secret ls -q --filter name=apy_gateway_ | xargs -r docker secret rm' >/dev/null 2>&1 || true

docker rm -f "$RUNNER" >/dev/null 2>&1 || true
docker run -d --name "$RUNNER" --network "$NETWORK" alpine:3.20 sleep 7200 >/dev/null
docker exec "$RUNNER" apk add --no-cache curl jq openssl >/dev/null
RUNNER_IP=$(ip_of "$RUNNER")
m1 docker node update --label-add apy-gateway.core=true "$CORE_NODE" >/dev/null
for n in $NODES; do m1 docker node update --label-add apy-gateway.edge=true "$n" >/dev/null; done
m1 docker node update --label-add npm=true "$NPM_NODE" >/dev/null
pass "núcleo em $CORE_NODE (worker); borda em todos os 4 nós"

echo "== 1. Produção simulada: NPM + 2 apps na rede overlay 'proxy'"
m1 docker network inspect proxy >/dev/null 2>&1 || m1 docker network create -d overlay --attachable proxy >/dev/null
m1 sh -c "cat > /tmp/backends.yml" <<EOF
version: "3.8"
services:
  # Fora do nó do núcleo: o drill final derruba esse nó e precisa medir a borda, não o app.
  whoami1:
    image: $REG/whoami:v1.10
    networks: [proxy]
    deploy: { placement: { constraints: ["node.hostname != $CORE_NODE"] } }
  whoami2:
    image: $REG/whoami:v1.10
    networks: [proxy]
    deploy: { placement: { constraints: ["node.hostname != $CORE_NODE"] } }
networks:
  proxy: { external: true }
EOF
m1 sh -c "cat > /tmp/npm.yml" <<EOF
version: "3.8"
services:
  app:
    image: $REG/nginx-proxy-manager:2.12.3
    ports: ["80:80", "443:443", "81:81"]
    volumes: ["data:/data", "letsencrypt:/etc/letsencrypt"]
    networks: [proxy]
    deploy:
      placement: { constraints: ["node.labels.npm == true"] }
networks:
  proxy: { external: true }
volumes:
  data:
  letsencrypt:
EOF
m1 docker stack deploy --detach=true -c /tmp/backends.yml backends >/dev/null
m1 docker stack deploy --detach=true -c /tmp/npm.yml npm >/dev/null
wait_service backends_whoami1 1 120
wait_service npm_app 1 180

NODE_IP=$(ip_of apy-sim-m1)
NPM_API="http://$NODE_IP:81/api"
i=0
until r "curl -sf $NPM_API/ >/dev/null"; do i=$((i + 1)); [ "$i" -lt 60 ] || fail "API do NPM não subiu"; sleep 3; done
NPM_TOKEN=$(r "curl -sf -X POST $NPM_API/tokens -H 'Content-Type: application/json' -d '{\"identity\":\"admin@example.com\",\"secret\":\"changeme\"}' | jq -r .token")
npm() { r "curl -sf -H 'Authorization: Bearer $NPM_TOKEN' $*"; }

r "openssl req -x509 -newkey rsa:2048 -nodes -days 60 -subj '/CN=shop.sim.test' -addext 'subjectAltName=DNS:shop.sim.test' -keyout /tmp/shop.key -out /tmp/shop.crt 2>/dev/null"
CERT_ID=$(npm "-X POST $NPM_API/nginx/certificates -H 'Content-Type: application/json' -d '{\"provider\":\"other\",\"nice_name\":\"shop\"}'" | jq -r .id)
npm "-X POST $NPM_API/nginx/certificates/$CERT_ID/upload -F certificate=@/tmp/shop.crt -F certificate_key=@/tmp/shop.key" >/dev/null
ACL_ID=$(npm "-X POST $NPM_API/nginx/access-lists -H 'Content-Type: application/json' -d '{\"name\":\"admins\",\"satisfy_any\":true,\"pass_auth\":false,\"items\":[{\"username\":\"admin\",\"password\":\"secret\"}],\"clients\":[]}'" | jq -r .id)

npm_host() { # json
  npm "-X POST $NPM_API/nginx/proxy-hosts -H 'Content-Type: application/json' -d '$1'" | jq -r .id
}
base='"forward_scheme":"http","forward_port":80,"meta":{"letsencrypt_agree":false,"dns_challenge":false},"advanced_config":"","locations":[],"block_exploits":false,"caching_enabled":false,"hsts_enabled":false,"hsts_subdomains":false,"http2_support":false'
npm_host "{\"domain_names\":[\"site1.sim.test\",\"www.site1.sim.test\"],\"forward_host\":\"backends_whoami1\",\"allow_websocket_upgrade\":true,\"access_list_id\":0,\"certificate_id\":0,\"ssl_forced\":false,$base}" >/dev/null
npm_host "{\"domain_names\":[\"shop.sim.test\"],\"forward_host\":\"backends_whoami2\",\"allow_websocket_upgrade\":false,\"access_list_id\":0,\"certificate_id\":$CERT_ID,\"ssl_forced\":true,$base}" >/dev/null
npm_host "{\"domain_names\":[\"admin.sim.test\"],\"forward_host\":\"backends_whoami1\",\"allow_websocket_upgrade\":false,\"access_list_id\":$ACL_ID,\"certificate_id\":0,\"ssl_forced\":false,$base}" >/dev/null
OFF_ID=$(npm_host "{\"domain_names\":[\"off.sim.test\"],\"forward_host\":\"backends_whoami2\",\"allow_websocket_upgrade\":false,\"access_list_id\":0,\"certificate_id\":0,\"ssl_forced\":false,$base}")
npm "-X POST $NPM_API/nginx/proxy-hosts/$OFF_ID/disable" >/dev/null

[ -n "$(backend_of "http://$NODE_IP/" site1.sim.test)" ] || fail "NPM não está servindo site1.sim.test"
pass "NPM servindo 4 hosts (multi-domínio, TLS custom, access list, desabilitado)"

echo "== 2. apy-gateway: segredos + núcleo + borda em portas canário (8080/8443)"
docker cp deploy/swarm/create-secrets.sh apy-sim-m1:/create-secrets.sh
m1 sh /create-secrets.sh >/dev/null
stack_deploy 8080 8443
for s in mysql redis minio control-plane queue scheduler mailpit; do wait_service "apy-gateway_$s" 1 300; done
wait_service apy-gateway_edge 4 300
pass "núcleo saudável em $CORE_NODE e borda global (4/4) com NPM ainda em 80/443"

artisan gateway:bootstrap-admin --email="$OPS_EMAIL" --ip="$RUNNER_IP" >/dev/null
pass "primeiro admin criado e IP do operador liberado"

echo "== 3. Import do NPM (export da API + certificados do volume)"
CP_CONTAINER=$(container_on "$CORE_NODE" apy-gateway_control-plane)
NPM_CONTAINER=$(container_on "$NPM_NODE" npm_app)
npm "$NPM_API/nginx/proxy-hosts" > /tmp/apy-sim-npm-hosts.json
docker exec "$CORE_NODE" docker exec "$CP_CONTAINER" mkdir -p /tmp/npm-import/certs
docker exec -i "$CORE_NODE" docker exec -i "$CP_CONTAINER" sh -c 'cat > /tmp/npm-import/proxy-hosts.json' < /tmp/apy-sim-npm-hosts.json
# custom_ssl (certificados enviados) e letsencrypt/live (emitidos) têm o mesmo layout npm-<id>/;
# -h segue os symlinks de live/ para os arquivos reais em archive/.
# Qualquer um dos dois pode não existir (ex.: nenhum certificado Let's Encrypt emitido ainda).
for dir in /data/custom_ssl /etc/letsencrypt/live; do
  if docker exec "$NPM_NODE" docker exec "$NPM_CONTAINER" test -d "$dir"; then
    docker exec "$NPM_NODE" docker exec "$NPM_CONTAINER" sh -c "cd $dir && tar -chf - npm-*" \
      | docker exec -i "$CORE_NODE" docker exec -i "$CP_CONTAINER" tar -xf - -C /tmp/npm-import/certs
  fi
done

artisan gateway:import-npm /tmp/npm-import/proxy-hosts.json --certificates-dir=/tmp/npm-import/certs --as="$OPS_EMAIL" --dry-run > /tmp/apy-sim-dry-run.txt || true
grep -q "shop.sim.test" /tmp/apy-sim-dry-run.txt || fail "dry-run não listou shop.sim.test"
if artisan gateway:import-npm /tmp/npm-import/proxy-hosts.json --certificates-dir=/tmp/npm-import/certs --as="$OPS_EMAIL" > /tmp/apy-sim-import.txt; then
  fail "import deveria sair != 0 por causa do host com access list"
fi
sed 's/^/        /' /tmp/apy-sim-import.txt
grep -q "admin.sim.test: recursos não suportados: access list" /tmp/apy-sim-import.txt || fail "admin.sim.test (access list) deveria ter sido pulado"
pass "import: 4 domínios migrados, certificado reaproveitado, host com access list pulado com motivo"

echo "== 4. Canário: borda (8080/8443) x NPM (80/443), nos 4 nós"
for n in $NODES; do
  ip=$(ip_of "$n")
  for d in site1.sim.test www.site1.sim.test; do
    i=0
    until [ -n "$(backend_of "http://$ip:8080/" "$d")" ]; do
      i=$((i + 1)); [ "$i" -lt 30 ] || fail "borda em $n não serve $d"; sleep 2
    done
    edge=$(backend_of "http://$ip:8080/" "$d"); npm_backend=$(backend_of "http://$NODE_IP/" "$d")
    [ -n "$edge" ] && [ "$edge" = "$npm_backend" ] || fail "$d: borda ($edge) e NPM ($npm_backend) chegam em backends diferentes"
  done
  fp=$(cert_fingerprint "$ip:8443" shop.sim.test)
  [ -n "$fp" ] && [ "$fp" = "$(cert_fingerprint "$NODE_IP:443" shop.sim.test)" ] || fail "shop.sim.test: certificado da borda difere do NPM em $n"
  edge=$(backend_of "https://shop.sim.test:8443/" shop.sim.test "-k --resolve shop.sim.test:8443:$ip")
  npm_backend=$(backend_of "https://shop.sim.test/" shop.sim.test "-k --resolve shop.sim.test:443:$NODE_IP")
  [ -n "$edge" ] && [ "$edge" = "$npm_backend" ] || fail "shop.sim.test: HTTPS da borda ($edge) difere do NPM ($npm_backend) em $n"
  if r "curl -s --max-time 3 -H 'Host: off.sim.test' http://$ip:8080/ | grep -q Hostname"; then fail "off.sim.test (desabilitado) não deveria ser servido"; fi
done
pass "mesmos upstreams e mesmo certificado que o NPM, em todos os nós; desabilitado continua fora"

# O mesmo script que o runbook manda rodar em produção, aqui contra os domínios importados.
sed -n 's/^  + //p' /tmp/apy-sim-import.txt > /tmp/apy-sim-domains.txt
docker cp deploy/swarm/compare-with-npm.sh apy-sim-m1:/compare-with-npm.sh
docker cp /tmp/apy-sim-domains.txt apy-sim-m1:/domains.txt
if ! m1 env TOOLS_IMAGE=$REG/alpine:3.20 sh /compare-with-npm.sh "$NODE_IP" /domains.txt > /tmp/apy-sim-compare.txt; then
  sed 's/^/        /' /tmp/apy-sim-compare.txt
  fail "compare-with-npm.sh encontrou divergências"
fi
sed 's/^/        /' /tmp/apy-sim-compare.txt
pass "compare-with-npm.sh: nenhum domínio divergente"

echo "== 5. Corte: NPM libera 80/443, borda assume"
cutover_started=$(date +%s)
# Sem --detach: só segue quando o Swarm terminou de tirar 80/443 do routing mesh. Antes disso as
# regras do ingress ainda capturam essas portas em todos os nós e "escondem" a borda (visto no ensaio).
m1 docker service update --publish-rm 80 --publish-rm 443 npm_app >/dev/null 2>&1
echo "        t=$(( $(date +%s) - cutover_started ))s  NPM sem 80/443 (service update convergiu)"
stack_deploy 80 443 0
# Linha do tempo real do corte, do ponto de vista do cliente: segundos desde o passo 1 até cada
# nó responder em 80 e em 443.
pending=""
for n in $NODES; do pending="$pending $n:80 $n:443"; done
while [ -n "$(echo $pending)" ]; do
  elapsed=$(( $(date +%s) - cutover_started ))
  [ "$elapsed" -lt 600 ] || fail "após 10 min ainda sem resposta em:$pending"
  still=""
  for target in $pending; do
    n=${target%:*}; port=${target#*:}; ip=$(ip_of "$n")
    if [ "$port" = 80 ]; then ok=$(backend_of "http://$ip/" site1.sim.test); else ok=$(backend_of "https://shop.sim.test/" shop.sim.test "-k --resolve shop.sim.test:443:$ip"); fi
    if [ -n "$ok" ]; then echo "        t=${elapsed}s  $n:$port respondendo"; else still="$still $target"; fi
  done
  pending="$still"
  [ -z "$(echo $pending)" ] || sleep 2
done
echo "        janela total do corte: $(( $(date +%s) - cutover_started ))s"
# O Swarm só declara o update "completed" depois do `monitor` (60s) — esperar isso antes de medir
# inflaria a janela; por isso a medição acima roda primeiro.
i=0
until [ "$(m1 docker service inspect apy-gateway_edge --format '{{if .UpdateStatus}}{{.UpdateStatus.State}}{{end}}')" = "completed" ]; do
  i=$((i + 1)); [ "$i" -lt 100 ] || fail "rolling update da borda não concluiu"; sleep 3
done
for n in $NODES; do
  ip=$(ip_of "$n")
  loc=$(r "curl -s -o /dev/null -w '%{redirect_url}' -H 'Host: shop.sim.test' http://$ip/x")
  [ "$loc" = "https://shop.sim.test/x" ] || fail "shop.sim.test deveria redirecionar para HTTPS em $n"
done
real_ip=$(r "curl -s -H 'Host: site1.sim.test' http://$(ip_of apy-sim-m2)/ | sed -n 's/^X-Real-Ip: //p' | tr -d '\r'")
[ "$real_ip" = "$RUNNER_IP" ] || fail "backend deveria ver o IP real do cliente ($RUNNER_IP), viu '$real_ip'"
pass "80/443 servidos pela borda nos 4 nós, com IP real do cliente preservado (mode host)"

echo "== 6. Drills"
artisan tinker --execute="app(App\\Actions\\ProxyHost\\CreateProxyHostAction::class)->handle(['domain' => 'new.sim.test', 'forward_scheme' => 'http', 'forward_host' => 'backends_whoami2', 'forward_port' => 80], App\\Models\\User::query()->where('email', '$OPS_EMAIL')->firstOrFail());" >/dev/null
for n in $NODES; do
  ip=$(ip_of "$n"); i=0
  until [ -n "$(backend_of "http://$ip/" new.sim.test)" ]; do
    i=$((i + 1)); [ "$i" -lt 20 ] || fail "novo domínio não propagou para $n"; sleep 1
  done
done
pass "domínio novo propaga para os 4 nós em segundos (Redis Pub/Sub)"

artisan replicas:sync-metrics >/dev/null
online=$(docker exec "$CORE_NODE" docker exec "$(container_on "$CORE_NODE" apy-gateway_mysql)" sh -c 'mysql -uroot -p"$(cat /run/secrets/apy_gateway_db_root_password)" -N -e "select count(*) from apy_gateway.replica_agents where status=\"online\"" 2>/dev/null')
[ "$online" = "4" ] || fail "esperado 4 réplicas online em replica_agents, veio '$online'"
pass "4 réplicas reportando heartbeat (hostname = nó do Swarm)"

docker pause "$CORE_NODE" >/dev/null
sleep 3
for n in apy-sim-m1 apy-sim-m2 apy-sim-m3; do
  [ -n "$(backend_of "http://$(ip_of "$n")/" site1.sim.test)" ] || { docker unpause "$CORE_NODE" >/dev/null; fail "com o núcleo fora, $n parou de servir"; }
done
docker unpause "$CORE_NODE" >/dev/null
pass "com o nó do núcleo fora do ar, as bordas dos outros nós continuam servindo"

echo
echo "ENSAIO OK — migração NPM -> apy-gateway validada no cluster de 4 nós."
