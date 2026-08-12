# Especificação Técnica — apy-gateway

> Este documento é a fonte de verdade da arquitetura. Mudanças de comportamento devem ser
> refletidas aqui antes (ou junto) da implementação. Progresso e histórico ficam em [CHANGELOG.md](./CHANGELOG.md).

## 1. Visão geral

O `apy-gateway` é um sistema de proxy reverso auto-gerenciável, com múltiplas instâncias de
Nginx distribuídas em um cluster, controladas por um plano de controle central (control-plane).

Diferente de soluções como o Nginx Proxy Manager (NPM), o objetivo aqui é suportar **múltiplas
réplicas independentes**, cada uma rodando seu próprio Nginx, que se auto-sincronizam a partir de
um banco de dados central e reagem em tempo real a mudanças via Redis Pub/Sub — sem depender de
volumes compartilhados ou orquestração manual entre máquinas.

## 2. Objetivos

- Gerenciar múltiplos proxies/domínios (criação, edição, remoção) a partir de uma única interface/API.
- Distribuir configuração e certificados SSL para N réplicas de Nginx, em máquinas diferentes do cluster.
- Cada réplica deve ser capaz de partir do zero (`docker compose up`), buscar o estado mais
  recente sozinha e só se anunciar "pronta" quando o Nginx estiver de fato servindo tráfego.
- Autenticação com controle de acesso por IP (allowlist com aprovação humana), login/senha e 2FA.
- Auditoria de todas as mudanças (quem, quando, o quê).

## 3. Não-objetivos (fora do escopo do MVP)

- Multi-tenancy (múltiplos clientes/organizações isolados) — pensar apenas em um único cluster/dono por instalação.
- Balanceamento de carga entre múltiplos upstreams por domínio (1 domínio → 1 upstream, na v1).
- Suporte a outro proxy além de Nginx (Traefik, HAProxy, etc.) — não faz parte do escopo inicial.
- Interface de usuário sofisticada (SPA) na v1 — Blade/Livewire é suficiente para o MVP.

## 4. Componentes do sistema

| Componente | Responsabilidade | Stack |
|---|---|---|
| **control-plane** | API + interface web para gerenciar proxies, certificados, usuários e IPs. Fonte de verdade dos dados. Publica eventos no Redis. | Laravel (`apps/control-plane`) |
| **agent** | Roda ao lado do Nginx em cada réplica. Sincroniza configuração/certificados a partir do banco, renderiza templates, valida e recarrega o Nginx. Escuta eventos no Redis. | Rust (`agent/`) |
| **nginx** | Serve o tráfego HTTP(S) real, configurado pelo agent. | Nginx (`nginx/`) |
| **MySQL** | Armazena domínios, certificados (metadados), usuários, IPs, eventos/auditoria. Fonte de verdade. | MySQL 8 |
| **Redis** | Pub/Sub para notificar réplicas de mudanças em tempo real. Também usado para filas do Laravel. | Redis |
| **MinIO (S3-compatible)** | Armazena o conteúdo dos certificados (chave privada + cadeia) fora do banco. O banco guarda apenas metadados (hash, versão, expiração). | MinIO |
| **Central Log Server** | Recebe logs de acesso/erro de todas as réplicas via rsyslog para análise centralizada. | rsyslog |

Cada componente roda em seu próprio container. Ver seção 13 para a topologia completa.

## 5. Modelo de dados (control-plane)

Desenho conceitual — os nomes finais de colunas serão definidos nas migrations.

### `users`
Usuários administradores do control-plane. Campos além do padrão Laravel: `two_factor_enabled`,
`two_factor_verified_at`.

### `ip_allowlist_entries`
IPs (ou CIDRs) autorizados a acessar rotas autenticadas.
- `ip_address`, `label`, `approved_by` (user), `approved_at`, `expires_at` (nullable — permitir IPs temporários).

### `ip_access_requests`
Fluxo de solicitação de liberação de IP (ver seção 6.1).
- `ip_address`, `requested_email`, `token` (assinado, uso único), `status` (`pending` | `approved` | `expired`),
  `approved_at`, `expires_at`.

### `proxy_hosts`
Cada domínio/proxy gerenciado.
- `domain` (unique), `forward_scheme` (`http`/`https`), `forward_host`, `forward_port`,
  `websockets_enabled`, `custom_config` (json — snippets extras, headers), `ssl_certificate_id` (nullable),
  `enabled`, `version` (int, incrementado a cada alteração — é o que o agent usa para saber se está desatualizado),
  `created_by`, timestamps.

### `ssl_certificates`
- `type` (`acme` | `uploaded`), `primary_domain`, `domain_names` (json — SAN), `provider` (`letsencrypt`, `manual`),
  `status` (`pending` | `valid` | `expiring` | `expired` | `error`), `storage_path` (chave no MinIO),
  `content_hash`, `version`, `issued_at`, `expires_at`, `last_renewal_attempt_at`.
  A chave privada nunca é persistida no MySQL — apenas no MinIO, com acesso restrito ao control-plane e aos agents.

### `replica_agents`
Registro das réplicas conhecidas (para observabilidade/painel, não para roteamento).
- `hostname`, `ip_address`, `agent_version`, `nginx_version`, `status` (`online`/`offline`), `last_heartbeat_at`.

### `domain_events`
Log persistente de todo evento também publicado no Redis — é a fonte de verdade para reconciliação
(o Redis Pub/Sub não é durável; se uma réplica estiver offline, ela perde a mensagem, mas não perde o
estado, porque consulta o banco no boot e periodicamente).
- `type` (`proxy_host.created` | `proxy_host.updated` | `proxy_host.deleted` | `certificate.issued` |
  `certificate.renewed` | `certificate.revoked`), `subject_type`, `subject_id`, `payload` (json),
  `created_by`, `created_at`.

### `audit_logs`
Trilha de auditoria genérica de ações administrativas (login, aprovação de IP, alteração de usuário, etc.).
- `user_id`, `action`, `subject_type`, `subject_id`, `ip_address`, `metadata` (json), `created_at`.

## 6. Autenticação e autorização

### 6.0 Exposição de rede (primeira camada, antes de qualquer allowlist)

**O control-plane nunca tem porta pública exposta.** Ele roda em uma rede privada do cluster,
sem publicação de porta 80/443/qualquer no host — quem serve tráfego da internet pública é
exclusivamente a réplica de `apy-gateway-nginx` (ver §13). O control-plane só é alcançável por
quem já está dentro da rede privada do cluster (ex.: VPN/bastion — mecanismo exato é decisão de
infraestrutura, ver §16), nunca diretamente pela internet.

Isso significa que o allowlist de IP descrito abaixo (§6.1) **não é o perímetro** — é uma
segunda camada, que restringe, dentro da rede privada, quais IPs de origem podem autenticar.
Ela existe porque a rede privada por si só não garante que todo dispositivo/pessoa conectado a
ela deveria ter acesso ao control-plane (ex.: pool de IPs da VPN compartilhado, dispositivo
comprometido). Nenhum usuário de fora do cluster consegue sequer chegar no endpoint de
`/auth/ip-requests` — a rede já barra antes disso.

### 6.1 Fluxo de liberação de IP

1. Requisição chega, **de dentro da rede privada**, de um IP fora da `ip_allowlist_entries`
   para qualquer rota autenticada → `403` com opção de "solicitar liberação".
2. `POST /auth/ip-requests { email }` — cria um `ip_access_request` com token único e envia e-mail
   para o **e-mail do administrador do sistema** (configurado via `.env`, não o e-mail do solicitante).
3. O administrador recebe o link único, clica, e isso:
   - marca a `ip_access_request` como aprovada;
   - cria/atualiza a `ip_allowlist_entry` correspondente.
4. A partir daí, o IP aprovado pode prosseguir para o login normal (passo 6.2).

O link de aprovação expira (ex.: 24h) e é de uso único.

### 6.2 Login

1. IP precisa estar na allowlist (passo anterior), senão a rota de login já responde `403`.
2. `POST /auth/login { email, password }` — credenciais válidas geram um estado "pendente de 2FA".
3. Um código é enviado por e-mail (`POST /auth/login/verify { code }`) — válido por poucos minutos, uso único.
4. Verificado, o control-plane emite a sessão/token (Sanctum).

Toda tentativa de login, aprovação de IP e verificação de 2FA é registrada em `audit_logs`.

## 7. API REST — recursos principais

Todas as respostas seguem o padrão de Resources do Laravel (JSON:API-like, contrato estável).

- `GET/POST /api/proxy-hosts`, `GET/PUT/DELETE /api/proxy-hosts/{id}` — CRUD de proxies.
  Associar um certificado é `PUT /api/proxy-hosts/{id}` com `ssl_certificate_id` — não há rota
  dedicada de associação (evitaria fazer a mesma coisa duas vezes).
- `GET/POST /api/certificates` (`show`), `POST /api/certificates/upload` — upload manual
  (Fase 7). Emissão automática via ACME e renovação agendada ficam para fase futura (fora do
  MVP, ver roadmap em CHANGELOG.md).
- `GET/POST/PUT/DELETE /api/users` — CRUD de usuários; criação envia link de definição de
  senha por e-mail (nunca senha direta). `DELETE` rejeita auto-exclusão e exclusão do último
  usuário do sistema (Fase 8).
- `GET/POST/DELETE /api/ip-allowlist-entries` — adicionar/listar/revogar IP liberado.
- `GET /api/ip-access-requests`, `POST /api/ip-access-requests/{id}/approve` — aprovação
  autenticada pela UI de solicitações de IP pendentes; distinta do fluxo público por token do
  §6 (duas superfícies de segurança diferentes sobre o mesmo model).
- `GET /api/replicas` — status das réplicas conhecidas (paginado): `hostname`, `ip_address`,
  `agent_version`, `nginx_version`, `status` (`online`/`offline`), `synced_domains_count`
  (agregado — ver §12), `last_heartbeat_at`. Populado por `replicas:sync-metrics` (Fase 10), nunca
  escrito diretamente pelo agent (§9.4).
- `GET /api/audit-logs` — trilha de auditoria (paginada). **Ainda não implementado.**

Controllers apenas validam (Form Requests), autorizam (Policies) e delegam para Actions —
nenhuma regra de negócio no controller (ver `CLAUDE.md`).

## 8. Barramento de eventos (Redis Pub/Sub)

- Canal único `apy-gateway:events` (namespace do app), payload:
  ```json
  { "id": "uuid", "type": "proxy_host.updated", "subject_id": 42, "version": 7, "occurred_at": "..." }
  ```
- Toda Action que muda estado relevante: (1) persiste em `domain_events`, (2) publica no Redis.
  A publicação nunca é a única fonte de verdade — é apenas um "sinal de acorda e verifica".
- Réplicas offline no momento da publicação **não perdem o evento**: ao voltar, comparam sua versão
  local com a versão em `proxy_hosts`/`ssl_certificates` no banco.

## 9. Agente (Rust) — ciclo de vida

### 9.1 Bootstrap (na inicialização do container)
1. Conecta no MySQL (credenciais via env/secret).
2. Busca todos os `proxy_hosts` ativos + certificado associado (versão atual).
3. Compara com estado local (`/var/lib/apy-agent/state.json` — versão aplicada por domínio).
4. Para domínios novos/desatualizados: baixa certificado do MinIO, renderiza o template Nginx
   (`nginx/templates` + `nginx/snippets`).
5. Roda `nginx -t` no config gerado.
   - Válido → aplica e recarrega/inicia o Nginx.
   - Inválido → mantém o último config válido conhecido, loga o erro e publica evento `agent.sync_error`.
6. **O container só é considerado "ready" (healthcheck OK) depois que o Nginx sobe servindo com o config sincronizado.**
7. Assina o canal Redis para atualizações em tempo real.

### 9.2 Operação contínua
- Ao receber evento no Redis: repete os passos 2–6 apenas para o domínio afetado (sync incremental, idempotente).
- Reconciliação completa periódica (ex.: a cada 5 min) como rede de segurança — cobre o caso de a
  réplica ter perdido uma mensagem Redis enquanto estava offline/reiniciando.
- Heartbeat periódico grava um payload JSON (`ts`, `ip`, `agent_version`, `nginx_version`,
  `synced_domains_count`) em `apy-gateway:replicas:{hostname}:heartbeat` no **Redis**, com TTL —
  nunca em `replica_agents` diretamente, isso violaria o grant SELECT-only do §9.4. Quem escreve
  `replica_agents.last_heartbeat_at`/`status`/`synced_domains_count` é o control-plane, via o
  comando agendado `replicas:sync-metrics` (Fase 10, §12), que lê o Redis periodicamente — a
  expiração do TTL no Redis (o agent parou de dar heartbeat) é a fonte de verdade pra decidir
  quando uma réplica vira `offline`, não um relógio separado do lado PHP.

### 9.3 Idempotência
Aplicar o mesmo evento/estado múltiplas vezes nunca deve gerar efeito colateral duplicado — o agent
sempre compara contra o estado local antes de agir.

### 9.4 Segurança de transporte (agent ↔ serviços centrais)

O agent nunca fala com o REST API do control-plane — ele conecta direto em MySQL, Redis e MinIO
(§9.1). Como essas réplicas podem estar em máquinas diferentes do núcleo, esse tráfego cruza rede
interna do cluster e precisa de controles próprios, além da rede privada do §6.0:

- **mTLS obrigatório** nas três conexões (MySQL, Redis, MinIO). Cada agent tem um certificado de
  cliente próprio, único por réplica — nunca um segredo compartilhado entre todos os agents.
  Mecanismo exato de emissão/rotação (enrollment) é decisão de infraestrutura em aberto (§16).
- **Least privilege no MySQL**: a credencial do agent só tem `SELECT` nas tabelas que ele
  precisa ler (`proxy_hosts`, `ssl_certificates`, `domain_events`, `acme_challenges` desde a
  Fase 9). Nunca `INSERT`/`UPDATE`/`DELETE` — toda escrita é exclusiva do control-plane, nunca do
  agent.
- **Least privilege no MinIO**: a policy de cada agent é escopada aos certificados dos domínios
  que a própria réplica serve, não uma credencial universal de leitura de todo o bucket.
- Consequência prática: comprometer um único agent vaza, no limite, os certificados daquela
  réplica — nunca funciona como chave mestra para decifrar/ler os dados de todo o cluster.

## 10. Templates Nginx

- `nginx/templates`: templates (ex. Handlebars/Tera) para `server { ... }` por `proxy_host`.
- `nginx/snippets`: blocos reutilizáveis (headers de segurança, websockets, SSL comum).
- O agent renderiza um arquivo por domínio em `conf.d/`, nunca edita o `nginx.conf` principal.

## 11. Gestão de certificados SSL

Estratégia híbrida, conforme decidido:
- **Automático (ACME/Let's Encrypt)**: control-plane é o único cliente ACME (RFC 8555) — o agent
  nunca fala o protocolo ACME nem recebe chamada direta do control-plane (mantém a invariante do
  §9.4). O hand-off é inteiramente via banco: `POST /api/certificates/request-acme` cria o
  `SslCertificate` (`status=pending`) e despacha um Job (`IssueAcmeCertificateJob`, fila Redis —
  primeiro uso de fila no projeto); a orquestração do protocolo (conta → order → autorizações →
  finalize → download) publica os desafios HTTP-01 na tabela `acme_challenges` (token +
  key_authorization por domínio) e dispara um evento (`acme_challenge.ready`) — puramente uma
  otimização de latência, já que a reconciliação periódica do agent pegaria a tabela de qualquer
  forma. O agent materializa cada desafio pendente como um arquivo estático num diretório
  compartilhado (`/var/lib/apy-agent/acme-challenges/`), servido por um `location` dedicado em
  todo bloco `:80` renderizado (`nginx/snippets/acme-challenge.conf`) — não precisa de reload do
  Nginx (é só troca de arquivo). Um domínio precisa já existir como `proxy_hosts` ativo antes do
  pedido de emissão (senão não há onde servir o desafio); a Action rejeita com 422 imediato nesse
  caso, antes de qualquer chamada ao servidor ACME. Assim que o certificado fica `valid`, o
  proxy_host correspondente é associado automaticamente (`ssl_certificate_id` + versão
  incrementada + evento `proxy_host.updated`) — fecha o loop sem passo manual, reaproveitando o
  código do agent das Fases 5/7 sem nenhuma mudança Rust. A chave de conta ACME (RSA, gerada uma
  única vez) fica no MinIO (`acme/account-key.pem`), nunca em disco local do container. Renovação
  agendada (`certificates:check-expiring`, `Schedule::command(...)->daily()`) roda antes do
  vencimento (`ACME_RENEWAL_THRESHOLD_DAYS`, default 30 dias) e reusa o mesmo Job (renovação =
  reemissão). Todos os domínios compartilham o mesmo diretório de desafios — não é uma
  vulnerabilidade: o servidor ACME sempre valida discando diretamente pro domínio autorizado, então
  um domínio "ver" o token pendente de outro não permite emitir certificado para um domínio que não
  controla (mesmo padrão de qualquer setup de webroot compartilhado). Testado localmente contra
  Pebble (servidor de teste ACME v2 do próprio Let's Encrypt) em `docker/acme-verification/` —
  validação HTTP-01 real, não fake (nunca `PEBBLE_VA_ALWAYS_VALID`).
- **Manual (upload)**: admin envia cert + chave via API; sistema valida cadeia/expiração antes de aceitar.
- Em ambos os casos, o conteúdo vai para o MinIO; o MySQL guarda apenas metadados + hash + versão.
- Em repouso, o conteúdo no MinIO é protegido por SSE (server-side encryption). Gerenciamento da
  chave de cifragem (estática na aplicação vs. KMS/Vault) é decisão em aberto (§16) — o objetivo é
  evitar um segredo único cuja perda decifre os certificados de todos os domínios de uma vez.

## 12. Observabilidade e logs

- **Encaminhamento de log centralizado é opt-in, desligado por padrão** (Fase 10) — decisão
  confirmada com o usuário: quem não quer centralizar continua acessando o log de cada réplica
  localmente (`docker logs`, já funciona hoje via os symlinks pra stdout/stderr da imagem base do
  Nginx), sem nenhuma mudança de comportamento. Ligado (`LOG_FORWARDING_ENABLED=true` no agent +
  `CENTRAL_LOG_SERVER_HOST`/`CENTRAL_LOG_SERVER_PORT`), o Nginx passa a usar seu suporte nativo a
  `access_log syslog:server=...;`/`error_log syslog:server=...;` — **não** um daemon rsyslog local:
  o agent continua supervisionando só o processo do Nginx, nunca ganha um segundo processo. O
  "Central Log Server" continua sendo rsyslog (ou compatível) do lado de quem recebe — é só o lado
  que envia que fica mais simples. Transporte é UDP (nginx não tem cliente syslog TCP/TLS nativo) —
  sem garantia de entrega, aceitável pro MVP (§16).
- Logs do control-plane seguem o padrão Laravel (`config/logging.php`), nunca logam segredos
  (senha, chave privada, token de sessão).
- **Métricas por réplica** (Fase 10): heartbeat estruturado (JSON, não só timestamp — ver §9.2)
  flui do agent pro Redis, e do Redis pro `replica_agents` via o comando agendado
  `replicas:sync-metrics` (a cada minuto — piso do scheduler do Laravel, então o flip pra
  `offline` pode atrasar até ~60-100s além do TTL real do heartbeat). Exposto via
  `GET /api/replicas` (§7). **Simplificação deliberada**: `synced_domains_count` é agregado (total
  de domínios aplicados com sucesso naquela réplica), não quebrado por domínio — o detalhe por
  domínio continua só no `state.json` local de cada réplica (efêmero, como já documentado em
  §13.2), criar uma tabela filha por réplica-domínio seria desproporcional a "métricas mínimas".

## 13. Ambiente Docker / topologia

Ambiente de desenvolvimento completo via `docker compose up -d`, incluindo: control-plane
(app + queue worker), MySQL, Redis, MinIO, Mailpit (para testar e-mails de aprovação de IP/2FA
localmente) e ao menos uma réplica de agent+nginx.

```
control-plane (Laravel) ──▶ MySQL (dados)
       │                 └─▶ MinIO (certificados)
       ▼
   Redis Pub/Sub
       │
 ┌─────┼─────────────┐
 ▼     ▼              ▼
agent  agent          agent   (Rust, 1 por réplica)
 │     │              │
 ▼     ▼              ▼
nginx  nginx          nginx
 │     │              │
 └─────┴── rsyslog ────┘
              │
              ▼
      Central Log Server
```

Em produção/cluster, cada réplica (agent + nginx no mesmo container ou pod) roda em uma máquina
diferente, conectando-se ao MySQL/Redis/MinIO centrais.

### 13.1 Núcleo (core) vs. réplicas de borda

- **Núcleo**: control-plane, MySQL, Redis, MinIO. Rodam em host(s) fixo(s), dentro da mesma rede
  privada do cluster (§6.0) — não fazem parte do pool de máquinas que sobem/descem livremente.
  São a fonte de verdade dos dados; não existe "de onde re-sincronizar" se eles forem perdidos.
- **Réplicas de borda**: agent + nginx. Efêmeras por natureza — qualquer máquina do cluster pode
  subir uma réplica nova, que nasce sem estado local e busca tudo do núcleo (§9.1). Escaláveis
  livremente, sem cuidado especial ao reinstalar ou trocar de máquina.

### 13.2 Persistência do núcleo

- MySQL, Redis e MinIO usam **volume local** no host do núcleo — nunca sistema de arquivos de
  rede (NFS/CIFS/etc.) como backend de storage. Tanto MySQL (locking/consistência) quanto MinIO
  (recomendação oficial do projeto) não suportam bem esse tipo de volume, com risco real de
  corrupção de dado.
- Trocar o host do núcleo (manutenção, reinstalação, upgrade de máquina) é uma operação explícita
  e pouco frequente, não automática: parar os serviços, copiar o volume de dados para a nova
  máquina, subir o `docker compose` nela. Dado tem peso — movê-lo sempre exige esse passo.
- Backup periódico do volume do núcleo é obrigatório (dump/snapshot do MySQL + espelhamento do
  MinIO), independente da estratégia de troca de host.
- HA real do núcleo (múltiplos nós replicados, sem esse passo manual) é um upgrade possível, mas
  fora do escopo do MVP (§3) — ver §16.

### 13.3 Deploy padrão (bundled) vs. serviço centralizado do cliente

- O deploy padrão sobe MySQL e MinIO junto com o control-plane no host do núcleo, via
  `docker compose` (§13.1) — é o caminho simples para desenvolvimento e instalações pequenas.
- Nenhuma credencial/endereço de MySQL, Redis ou MinIO é hardcoded — tanto o control-plane
  (`.env`) quanto o agent (env/secret, §9.1) resolvem esses serviços por configuração.
- Consequência: se o cliente já opera um MySQL ou MinIO centralizado na rede interna do próprio
  cluster, basta apontar a configuração para esse serviço existente em vez de subir os containers
  bundled — sem mudança de código. Esse serviço passa a exercer o papel de "núcleo" (§13.1) e
  segue as mesmas regras (mTLS, least privilege, sem volume de rede, backup); a responsabilidade
  de operar o host é do cliente, não do `docker-compose.yml` padrão do projeto.

## 14. Estratégia de imagens Docker / CI

Nem todo diretório com um `Dockerfile` vira uma imagem publicada. Só publicamos imagem para
componentes com código próprio:

| Imagem publicada | Conteúdo | Dockerfile |
|---|---|---|
| `apy-gateway-control-plane` | API + interface web (Laravel) | `apps/control-plane/Dockerfile` *(a criar)* |
| `apy-gateway-nginx` | Agent (Rust) + Nginx na mesma imagem — build multi-stage: 1ª stage compila o binário do agent a partir de `agent/`, 2ª stage parte de uma base Nginx e copia o binário + `nginx/templates` + `nginx/snippets`. O **agent é o entrypoint/PID 1** e sobe o Nginx como processo filho — é assim que o container consegue só ficar "ready" quando o Nginx de fato subiu com a config sincronizada (seção 9.1). | `nginx/Dockerfile` *(a reescrever como multi-stage)* |

`agent/Dockerfile` (o que existe hoje) serve apenas para build/test isolado do agent em
desenvolvimento e CI (ex.: `cargo build`, `cargo test`) — **não é uma imagem publicada em
produção**; o binário final é empacotado dentro de `apy-gateway-nginx`.

MySQL, Redis e MinIO usam imagens oficiais do Docker Hub (`mysql`, `redis`, `minio/minio`) —
não construímos nem publicamos imagens próprias para eles, apenas os referenciamos no
`docker-compose.yml` com a configuração via variáveis de ambiente/volumes.

O workflow de CI (`.github/workflows/main.yml`) hoje builda uma única imagem genérica a
partir de um `Dockerfile` na raiz que não existe — isso será reescrito para buildar e
publicar `apy-gateway-control-plane` e `apy-gateway-nginx` como jobs separados, **somente
depois que os respectivos Dockerfiles estiverem prontos** (Fase 10 do roadmap, ver
CHANGELOG.md). Até lá, o workflow atual fica como está, sem uso real.

## 15. Segurança

- Toda entrada externa é validada (Form Requests). Nunca confiar em dado de sistema externo.
- Chave privada de certificado nunca aparece em log, nunca é retornada por API (apenas metadados).
- Rate limiting nas rotas de auth (login, solicitação de IP, verificação de 2FA).
- Exceptions internas nunca vazam para o cliente (handler customizado para respostas de API).
- Tráfego agent ↔ serviços centrais (MySQL/Redis/MinIO) é autenticado via mTLS, com credenciais
  de privilégio mínimo por agent — ver §9.4.

## 16. Decisões em aberto (para próximas iterações, não bloqueiam o MVP)

- Algoritmo/estratégia de DNS-01 (qual provedor de DNS suportar primeiro).
- Suporte a múltiplos upstreams por domínio (load balancing) — avaliar depois do MVP.
- Formato exato do template engine no agent Rust (Tera é o candidato natural).
- Mecanismo exato de emissão/rotação dos certificados mTLS dos agents (enrollment) — §9.4.
- Gerenciamento da chave de SSE do MinIO: chave estática na aplicação vs. KMS/Vault — §11.
- HA real do núcleo (MySQL replicado, MinIO distribuído, Redis Sentinel/Cluster), caso o cluster
  cresça além do que um único host de núcleo aguenta — §13.2.
- Rotação/backup da chave de conta ACME (Fase 9, §11): hoje é uma chave RSA de vida longa única,
  gerada uma vez e guardada no MinIO, sem mecanismo de rotação — perdê-la exige registrar uma
  conta ACME nova (não perde certificados já emitidos, só a identidade da conta).
- Transporte UDP do encaminhamento de log via `syslog:` do Nginx (Fase 9, §12): sem garantia de
  entrega, sem criptografia em trânsito — aceito como trade-off do MVP já que a feature é opt-in.
  Upgrade pra um transporte garantido/cifrado exigiria um processo local adicional (o que essa
  fase decidiu deliberadamente evitar) ou um módulo do Nginx além do core.
- **Risco real encontrado empiricamente na verificação da Fase 10** (não hipotético — reproduzido
  de verdade, ver `docker/observability-verification/README.md`): o Nginx resolve o hostname do
  `CENTRAL_LOG_SERVER_HOST` no momento de `nginx -t`/boot, não de forma preguiçosa no primeiro log
  — se o hostname não resolver nesse momento, o Nginx inteiro falha a validar e **não sobe**, não
  é só o encaminhamento de log que fica faltando. Isso significa que uma feature pensada como
  "plus"/opcional pode virar risco de disponibilidade do proxy real se o Central Log Server ficar
  brevemente sem resolver DNS bem na hora de um boot/reload de réplica. Recomendação pro operador:
  apontar `CENTRAL_LOG_SERVER_HOST` pra algo confiavelmente resolvível (nome DNS interno estável
  ou IP literal), nunca algo efêmero. Mitigação automática (ex.: o agent validar/tolerar isso antes
  de escrever o snippet) fica em aberto pra uma iteração futura.
