# apy-gateway-control-plane

API + interface web (Laravel, sobre FrankenPHP) do **apy-gateway** — um sistema auto-gerenciável
de proxy reverso com múltiplas réplicas de Nginx sincronizadas a partir de uma fonte de verdade
central, coordenadas via Redis Pub/Sub.

Repositório: https://github.com/diegoneumann/apy-gateway
Documentação técnica completa: [SPEC.md](https://github.com/diegoneumann/apy-gateway/blob/main/SPEC.md)

Esta imagem é responsável pelo CRUD autenticado de proxies e certificados SSL — os mesmos
processos que ferramentas como o Nginx Proxy Manager oferecem para uma única instância, mas aqui
publicados como eventos no Redis para que cada réplica de Nginx (imagem
**apy-gateway-nginx**) se sincronize sozinha.

Não confundir com **apy-gateway-nginx**, a imagem que roda o Nginx + agente de sincronização em
Rust — as duas são publicadas separadamente e nunca combinadas numa imagem só.

## Uma imagem, vários papéis

A mesma imagem serve para todos os processos da aplicação; o papel é escolhido pelo primeiro
argumento passado ao container (`CMD`):

| Comando | Papel |
|---|---|
| `web` (padrão) | Servidor HTTP (FrankenPHP). |
| `queue` | Worker de filas (`php artisan queue:work`). |
| `scheduler` | Agendador de tarefas (`php artisan schedule:work`). |
| `migrate` | Executa as migrations e finaliza. |

Não há supervisor de processos dentro do container — cada papel roda um único processo em
foreground, e a orquestração entre eles (ex.: rodar `migrate` antes de subir `web`) é
responsabilidade do compose/orquestrador, não da imagem.

## Tags

| Tag | Descrição |
|---|---|
| `latest` | Último build da branch `main`. |
| `vX.Y.Z` | Release versionada, a partir de tags `git`. |
| `sha-xxxxxxx` | Build de um commit específico. |

## Como rodar

Esta imagem **não é útil sozinha**. Ela é a fonte de verdade central que publica eventos no Redis
para que as réplicas de Nginx (imagem **apy-gateway-nginx**) se sincronizem — sem pelo menos uma
réplica rodando, o control-plane não tem o que gerenciar. As duas imagens existem para rodar
juntas — veja o `docker-compose.yml` completo abaixo.

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

Configuração completa em `apps/control-plane/.env.example` no repositório. As principais:

| Variável | Descrição |
|---|---|
| `APP_KEY` | Chave de criptografia da aplicação Laravel. Obrigatória. Gere com `php artisan key:generate --show`. |
| `APP_ENV` | `local`, `staging` ou `production`. |
| `APP_URL` | URL pública usada para gerar links (ex.: e-mails de confirmação/2FA). |
| `DB_CONNECTION` | `mysql` em produção. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexão com o MySQL central. |
| `SESSION_DRIVER`, `CACHE_STORE` | `database` no ambiente de exemplo. |
| `QUEUE_CONNECTION` | `redis` — necessário para os jobs de emissão/renovação de certificado (ACME). |
| `REDIS_HOST`, `REDIS_PORT` | Usado tanto para filas quanto para o Pub/Sub de eventos consumido pelas réplicas de Nginx. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | SMTP para e-mails de liberação de IP e 2FA. |
| `MINIO_ENDPOINT_URL`, `MINIO_BUCKET`, `MINIO_ACCESS_KEY`, `MINIO_SECRET_KEY` | Storage S3-compatível onde os certificados SSL ficam armazenados. |
| `MINIO_FORCE_PATH_STYLE` | `true` para MinIO e a maioria dos storages S3-compatíveis. |
| `ACME_BASE_URL` | Endpoint da autoridade certificadora ACME (ex.: Let's Encrypt). |
| `ACME_RENEWAL_THRESHOLD_DAYS` | Dias antes do vencimento em que a renovação automática é disparada. |

Deliberadamente **não** é feito `config:cache`/`route:cache` no build da imagem: essas caches
travariam os valores de `env()` no momento do build, mas a mesma imagem roda com variáveis
diferentes em cada ambiente/deploy.

## Portas

| Porta | Descrição |
|---|---|
| `80` | HTTP (papel `web`). Não exposta nos demais papéis. |

## Healthcheck

No papel `web`, a aplicação expõe `GET /up` (rota padrão de health do Laravel).

## Código-fonte da imagem

`apps/control-plane/Dockerfile` no repositório. O contexto de build é o próprio diretório
`apps/control-plane` (build autocontido, sem depender da raiz do repositório).
