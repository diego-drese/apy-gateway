#!/bin/sh
# Fase canário da migração (docs/deploy/swarm.md, "Fase 4"): para cada domínio, compara o que o
# NPM serve (80/443) com o que a borda do apy-gateway serve nas portas canário (8080/8443) NO MESMO
# NÓ — status HTTP, destino de redirect e certificado TLS. Corpo da resposta não é comparado:
# apps reais variam (tokens CSRF, datas) e isso geraria falso alarme.
#
#   ./deploy/swarm/compare-with-npm.sh <ip-do-nó> dominios.txt
#
# dominios.txt: um domínio por linha (ex.: saída de `gateway:import-npm`, linhas "+ dominio").
# Roda dentro de um container (curl/openssl) com --network host — não depende de nada no host.
# Sai != 0 se qualquer domínio divergir.
set -eu

NODE_IP="${1:?uso: $0 <ip-do-nó> <arquivo-de-domínios>}"
DOMAINS_FILE="${2:?uso: $0 <ip-do-nó> <arquivo-de-domínios>}"
NPM_HTTP="${NPM_HTTP_PORT:-80}"
NPM_HTTPS="${NPM_HTTPS_PORT:-443}"
EDGE_HTTP="${EDGE_HTTP_PORT:-8080}"
EDGE_HTTPS="${EDGE_HTTPS_PORT:-8443}"
TOOLS_IMAGE="${TOOLS_IMAGE:-alpine:3.20}"

sed -e 's/^[+ ]*//' -e 's/[[:space:]]*$//' "$DOMAINS_FILE" | grep -E '^[a-z0-9.-]+$' | \
docker run --rm -i --network host \
  -e NODE_IP="$NODE_IP" -e NPM_HTTP="$NPM_HTTP" -e NPM_HTTPS="$NPM_HTTPS" \
  -e EDGE_HTTP="$EDGE_HTTP" -e EDGE_HTTPS="$EDGE_HTTPS" \
  "$TOOLS_IMAGE" sh -c '
    apk add --no-cache curl openssl >/dev/null
    probe() { # port scheme domain -> "status redirect"
      if [ "$2" = https ]; then
        curl -sk -o /dev/null --max-time 10 -w "%{http_code} %{redirect_url}" \
          --resolve "$3:$1:$NODE_IP" "https://$3:$1/" | sed "s/:$1\//\//"
      else
        curl -s -o /dev/null --max-time 10 -w "%{http_code} %{redirect_url}" \
          -H "Host: $3" "http://$NODE_IP:$1/" | sed "s/:$1\//\//"
      fi
    }
    # Só conta certificado válido PARA o domínio: o NPM responde SNI desconhecido com um certificado
    # dummy, a borda recusa o handshake — os dois equivalem a "sem TLS pra esse domínio".
    fingerprint() { # port domain
      pem=$(echo | openssl s_client -connect "$NODE_IP:$1" -servername "$2" 2>/dev/null | openssl x509 2>/dev/null) || return 0
      echo "$pem" | openssl x509 -noout -checkhost "$2" 2>/dev/null | grep -q "does match" || return 0
      echo "$pem" | openssl x509 -noout -fingerprint -sha256 | cut -d= -f2
    }
    failed=0
    while read -r d; do
      npm_http=$(probe "$NPM_HTTP" http "$d");     edge_http=$(probe "$EDGE_HTTP" http "$d")
      npm_https=$(probe "$NPM_HTTPS" https "$d");  edge_https=$(probe "$EDGE_HTTPS" https "$d")
      npm_cert=$(fingerprint "$NPM_HTTPS" "$d");   edge_cert=$(fingerprint "$EDGE_HTTPS" "$d")
      if [ "$npm_http" = "$edge_http" ] && [ "$npm_https" = "$edge_https" ] && [ "$npm_cert" = "$edge_cert" ]; then
        echo "OK    $d  (http: $edge_http | https: $edge_https)"
      else
        failed=1
        echo "DIFF  $d"
        echo "      http : npm=[$npm_http] edge=[$edge_http]"
        echo "      https: npm=[$npm_https] edge=[$edge_https]"
        [ "$npm_cert" = "$edge_cert" ] || echo "      cert : npm=${npm_cert:-nenhum} edge=${edge_cert:-nenhum}"
      fi
    done
    exit $failed
  '
