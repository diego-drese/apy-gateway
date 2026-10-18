#!/bin/sh
# Cluster Swarm de ensaio, sem VMs: 4 nós docker-in-docker reproduzindo a topologia de produção
# (3 managers + 1 worker) + um registry local, tudo como containers no Docker da sua máquina.
# Não altera o daemon do host (ele continua fora de qualquer swarm).
#
#   ./deploy/swarm/simulation/cluster.sh up      # cria nós, forma o swarm, publica imagens no registry
#   ./deploy/swarm/simulation/cluster.sh down    # remove tudo (nós, volumes, rede, registry)
#
# Imagens: as do projeto são buildadas antes (docker compose build) e, como as de terceiros, vão
# para o registry local — os nós nunca puxam nada da internet (sem rate limit, sem banda repetida).
set -eu

NETWORK="apy-sim"
REGISTRY="apy-sim-registry"
NODES="apy-sim-m1 apy-sim-m2 apy-sim-m3 apy-sim-w1"
MANAGERS="apy-sim-m2 apy-sim-m3"
WORKER="apy-sim-w1"
DIND_IMAGE="docker:27-dind"

# Imagem local (host) -> nome no registry. As do projeto vêm do `docker compose build`.
IMAGES="
apy-gateway-control-plane:latest=apy-gateway-control-plane:sim
apy-gateway-agent:latest=apy-gateway-nginx:sim
mysql:8.4=mysql:8.4
redis:7.4-alpine=redis:7.4-alpine
pgsty/minio:RELEASE.2026-08-04T00-00-00Z=minio:sim
pgsty/mc:RELEASE.2026-10-18T00-00-00Z=mc:sim
jc21/nginx-proxy-manager:2.12.3=nginx-proxy-manager:2.12.3
traefik/whoami:v1.10=whoami:v1.10
axllent/mailpit:v1.21=mailpit:v1.21
alpine:3.20=alpine:3.20
"

up() {
  docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null

  if ! docker inspect "$REGISTRY" >/dev/null 2>&1; then
    docker run -d --name "$REGISTRY" --network "$NETWORK" -p 127.0.0.1:5001:5000 registry:2 >/dev/null
  fi

  for name in $NODES; do
    if docker inspect "$name" >/dev/null 2>&1; then continue; fi
    # /var/lib/docker em volume: overlay2 não funciona sobre o overlay do próprio container.
    docker run -d --privileged --name "$name" --hostname "$name" --network "$NETWORK" \
      -v "$name-docker:/var/lib/docker" \
      "$DIND_IMAGE" --insecure-registry "$REGISTRY:5000" >/dev/null
  done

  for name in $NODES; do
    until docker exec "$name" docker info >/dev/null 2>&1; do sleep 1; done
  done
  echo "nós prontos: $NODES"

  if [ "$(docker exec apy-sim-m1 docker info --format '{{.Swarm.LocalNodeState}}')" != "active" ]; then
    m1_ip=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" apy-sim-m1)
    docker exec apy-sim-m1 docker swarm init --advertise-addr "$m1_ip" >/dev/null
    manager_token=$(docker exec apy-sim-m1 docker swarm join-token -q manager)
    worker_token=$(docker exec apy-sim-m1 docker swarm join-token -q worker)
    for name in $MANAGERS; do
      docker exec "$name" docker swarm join --token "$manager_token" "$m1_ip:2377" >/dev/null
    done
    docker exec "$WORKER" docker swarm join --token "$worker_token" "$m1_ip:2377" >/dev/null
  fi
  docker exec apy-sim-m1 docker node ls

  echo "publicando imagens no registry local..."
  for pair in $IMAGES; do
    src="${pair%%=*}"
    dst="localhost:5001/${pair#*=}"
    docker image inspect "$src" >/dev/null 2>&1 || docker pull -q "$src" >/dev/null
    docker tag "$src" "$dst"
    docker push -q "$dst" >/dev/null
    echo "  $src -> $REGISTRY:5000/${pair#*=}"
  done
}

down() {
  docker rm -f $NODES "$REGISTRY" >/dev/null 2>&1 || true
  for name in $NODES; do docker volume rm "$name-docker" >/dev/null 2>&1 || true; done
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  echo "cluster de ensaio removido."
}

case "${1:-}" in
  up) up ;;
  down) down ;;
  *) echo "uso: $0 up|down" >&2; exit 2 ;;
esac
