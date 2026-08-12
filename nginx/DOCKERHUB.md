# apy-gateway-nginx

Nginx + agente de sincronização (Rust) para o **apy-gateway** — um sistema auto-gerenciável de
proxy reverso com múltiplas réplicas de Nginx sincronizadas a partir de uma fonte de verdade
central (MySQL), coordenadas via Redis Pub/Sub.

Repositório: https://github.com/diegoneumann/apy-gateway
Documentação técnica completa: [SPEC.md](https://github.com/diegoneumann/apy-gateway/blob/main/SPEC.md)

Esta imagem roda **uma réplica completa**: o binário `apy-agent` (Rust) ao lado do Nginx no mesmo
container. O agente conecta no banco central, baixa a configuração mais recente de cada domínio,
gera os arquivos de config e certificados localmente, inicia o Nginx e só então marca o container
como pronto (healthcheck). Depois disso, escuta eventos publicados no Redis pelo control-plane e
reage a eles: sincroniza arquivos, valida configuração e recarrega o Nginx.

Não confundir com **apy-gateway-control-plane**, a imagem da API/interface web em Laravel — as
duas são publicadas separadamente e nunca combinadas numa imagem só.

## Tags

| Tag | Descrição |
|---|---|
| `latest` | Último build da branch `main`. |
| `vX.Y.Z` | Release versionada, a partir de tags `git`. |
| `sha-xxxxxxx` | Build de um commit específico. |

## Como rodar

Esta imagem **não sobe sozinha**. Ela é sempre uma réplica de um cluster: precisa do mesmo MySQL,
Redis e storage S3-compatível que a **apy-gateway-control-plane** usa, e normalmente só fica útil
depois que o control-plane já rodou as migrations e criou pelo menos um proxy. As duas imagens
existem para rodar juntas — veja o `docker-compose.yml` completo abaixo.

Para desenvolvimento local a partir do código-fonte (build em vez de pull), veja
[`./scripts/install.sh`](https://github.com/diegoneumann/apy-gateway) no repositório.

### docker-compose completo (as duas imagens juntas)

```yaml
services:
  mysql:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: apy_gateway
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-uroot", "-proot"]
      interval: 3s
      retries: 30

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 3s
      retries: 30

  minio:
    image: minio/minio
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    command: server /data --console-address ":9001"
    volumes:
      - minio-data:/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
      interval: 3s
      retries: 30

  # One-shot: garante que o bucket dos certificados existe antes de qualquer outro serviço subir.
  minio-init:
    image: minio/mc
    entrypoint: sh
    command:
      - -c
      - |
        mc alias set local http://minio:9000 minioadmin minioadmin &&
        mc mb --ignore-existing local/apy-gateway-certs
    depends_on:
      minio:
        condition: service_healthy

  # Roda uma vez e finaliza — aplica as migrations antes de qualquer serviço que dependa do schema.
  migrate:
    image: diegoneumann/apy-gateway-control-plane:latest
    command: ["migrate"]
    environment: &control_plane_env
      APP_KEY: ${APP_KEY}
      APP_ENV: production
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_DATABASE: apy_gateway
      DB_USERNAME: root
      DB_PASSWORD: root
      SESSION_DRIVER: database
      CACHE_STORE: database
      QUEUE_CONNECTION: redis
      REDIS_HOST: redis
      MINIO_ENDPOINT_URL: http://minio:9000
      MINIO_BUCKET: apy-gateway-certs
      MINIO_ACCESS_KEY: minioadmin
      MINIO_SECRET_KEY: minioadmin
      MINIO_FORCE_PATH_STYLE: "true"
    depends_on:
      mysql:
        condition: service_healthy

  control-plane:
    image: diegoneumann/apy-gateway-control-plane:latest
    command: ["web"]
    environment: *control_plane_env
    ports:
      - "8000:80"
    depends_on:
      migrate:
        condition: service_completed_successfully
      minio-init:
        condition: service_completed_successfully
      redis:
        condition: service_healthy

  queue-worker:
    image: diegoneumann/apy-gateway-control-plane:latest
    command: ["queue"]
    environment: *control_plane_env
    depends_on:
      migrate:
        condition: service_completed_successfully

  scheduler:
    image: diegoneumann/apy-gateway-control-plane:latest
    command: ["scheduler"]
    environment: *control_plane_env
    depends_on:
      migrate:
        condition: service_completed_successfully

  # A réplica de Nginx. Escala horizontalmente: basta duplicar este serviço (hostnames/portas
  # diferentes) apontando para o mesmo MySQL/Redis/MinIO — cada réplica se sincroniza sozinha.
  nginx-replica:
    image: diegoneumann/apy-gateway-nginx:latest
    environment:
      DB_HOST: mysql
      DB_DATABASE: apy_gateway
      DB_USERNAME: root
      DB_PASSWORD: root
      DB_SSL_MODE: disabled
      MINIO_ENDPOINT_URL: http://minio:9000
      MINIO_BUCKET: apy-gateway-certs
      MINIO_ACCESS_KEY: minioadmin
      MINIO_SECRET_KEY: minioadmin
      MINIO_FORCE_PATH_STYLE: "true"
      REDIS_URL: redis://redis:6379/
      AGENT_HOSTNAME: replica-1
    ports:
      - "8080:80"
      - "8443:443"
    depends_on:
      control-plane:
        condition: service_healthy

volumes:
  mysql-data:
  minio-data:
```

Antes de subir, gere um `APP_KEY` e exporte no `.env` do compose (ou na variável de ambiente do
shell):

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env
docker compose up -d
```

Senhas/credenciais acima (`root`, `minioadmin`) são só para exemplo — troque por segredos reais
fora do repositório antes de usar em produção.

## Variáveis de ambiente

### Obrigatórias

| Variável | Descrição |
|---|---|
| `DB_HOST` | Host do MySQL central. |
| `DB_DATABASE` | Nome do banco. |
| `DB_USERNAME` | Usuário do banco. |
| `DB_PASSWORD` | Senha do banco. |
| `MINIO_ENDPOINT_URL` | Endpoint do storage S3-compatível (certificados SSL). |
| `MINIO_BUCKET` | Bucket onde os certificados são armazenados. |
| `MINIO_ACCESS_KEY` | Access key do storage. |
| `MINIO_SECRET_KEY` | Secret key do storage. |

### Opcionais

| Variável | Padrão | Descrição |
|---|---|---|
| `DB_PORT` | `3306` | Porta do MySQL. |
| `DB_MAX_CONNECTIONS` | `5` | Tamanho do pool de conexões. |
| `DB_SSL_MODE` | `preferred` | `disabled`, `preferred`, `required`, `verify_ca` ou `verify_identity`. |
| `DB_SSL_CA_PATH` | — | Caminho do CA para TLS com o MySQL. |
| `DB_SSL_CLIENT_CERT_PATH` | — | Certificado de cliente (mTLS). |
| `DB_SSL_CLIENT_KEY_PATH` | — | Chave de cliente (mTLS). |
| `MINIO_REGION` | `us-east-1` | Região do storage S3. |
| `MINIO_FORCE_PATH_STYLE` | `true` | Necessário para MinIO e a maioria dos storages S3-compatíveis. |
| `REDIS_URL` | `redis://127.0.0.1:6379/` | Conexão usada para Pub/Sub de eventos. |
| `AGENT_HOSTNAME` | hostname do container | Identidade estável da réplica entre restarts. Tem prioridade sobre `HOSTNAME`. |
| `HEARTBEAT_INTERVAL_SECS` | `15` | Intervalo entre heartbeats da réplica. |
| `HEARTBEAT_TTL_SECS` | `45` | TTL do heartbeat no Redis. |
| `RECONCILIATION_INTERVAL_SECS` | `300` | Intervalo da reconciliação periódica completa (fallback caso um evento seja perdido). |
| `STATE_FILE_PATH` | `/var/lib/apy-agent/state.json` | Estado local persistido pelo agente. |
| `CERT_STORAGE_DIR` | `/var/lib/apy-agent/certs` | Cache local dos certificados baixados do storage. |
| `ACME_CHALLENGE_DIR` | `/var/lib/apy-agent/acme-challenges` | Diretório usado para o desafio HTTP-01 do ACME. |
| `NGINX_BINARY_PATH` | `nginx` | Caminho do binário do Nginx. |
| `NGINX_CONF_PATH` | `/etc/nginx/nginx.conf` | Config principal do Nginx. |
| `NGINX_CONF_D_PATH` | `/etc/nginx/conf.d` | Diretório dos vhosts gerados. |
| `NGINX_TEMPLATES_DIR` | `/etc/apy-agent/templates` | Templates usados para gerar a config dos vhosts. |
| `NGINX_SNIPPETS_DIR` | `/etc/nginx/snippets` | Snippets reutilizáveis incluídos nos vhosts. |
| `NGINX_READINESS_CHECK_ADDR` | `127.0.0.1:80` | Endereço checado para considerar o Nginx pronto. |
| `NGINX_READINESS_TIMEOUT_SECS` | `30` | Tempo máximo de espera pelo Nginx no boot. |
| `HEALTHCHECK_MARKER_PATH` | `/var/run/apy-agent/ready` | Arquivo usado pelo `HEALTHCHECK` do container. |
| `LOG_FORWARDING_ENABLED` | `false` | Habilita envio de logs para um servidor central via rsyslog. |
| `CENTRAL_LOG_SERVER_HOST` | — | Host do servidor de logs central (obrigatório se `LOG_FORWARDING_ENABLED=true`). |
| `CENTRAL_LOG_SERVER_PORT` | `514` | Porta do servidor de logs central. |
| `RUST_LOG` | — | Nível de log do agente (`info`, `debug`, etc). |

## Portas

| Porta | Descrição |
|---|---|
| `80` | HTTP. |
| `443` | HTTPS. |

## Healthcheck

O container expõe um `HEALTHCHECK` nativo que verifica a existência do arquivo em
`HEALTHCHECK_MARKER_PATH`. Esse marcador só é criado depois que o agente sincroniza a config mais
recente do banco e confirma que o Nginx subiu com ela — ou seja, "healthy" aqui significa
"servindo tráfego com a configuração correta", não apenas "processo no ar".

## Código-fonte da imagem

`nginx/Dockerfile` no repositório. O contexto de build é a raiz do repositório (o agente Rust em
`agent/` é compilado no mesmo build multi-stage).