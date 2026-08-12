# Changelog

Todas as mudanças notáveis deste projeto são documentadas neste arquivo, na ordem em que
aconteceram. Formato inspirado em [Keep a Changelog](https://keepachangelog.com/).

Este arquivo é o controle de progresso do projeto entre sessões: antes de continuar a
implementação, comece por aqui para saber o que já existe e qual é o próximo passo.
Detalhes de arquitetura/decisões vivem em [SPEC.md](./SPEC.md); aqui só registramos o que
mudou e o que falta.

> Nota: `apps/control-plane/CHANGELOG.md` é o changelog padrão do framework Laravel
> (gerado pelo `laravel/laravel`), não é o changelog deste projeto — ignore-o.

## [Unreleased]

### Documentação
- Especificação técnica completa do sistema (`SPEC.md`): modelo de dados, fluxo de
  autenticação (IP allowlist + login + 2FA), protocolo de eventos via Redis, ciclo de vida
  do agent, gestão de certificados, topologia de cluster.
- README reescrito com a visão do produto, arquitetura e como navegar na documentação.
- Decisões de arquitetura confirmadas com o time:
  - Banco de dados principal: **MySQL**.
  - Certificados SSL: emissão **híbrida** (ACME/Let's Encrypt automático + upload manual).
  - Segundo fator de autenticação: **código enviado por e-mail**.
  - Estratégia de imagens Docker: agent (Rust) e Nginx na **mesma imagem**
    (`apy-gateway-nginx`, multi-stage, agent como PID 1 supervisionando o nginx); a API/web
    fica em imagem separada (`apy-gateway-control-plane`); MySQL/Redis/MinIO usam imagens
    oficiais, não são construídas por nós (SPEC.md §14).
  - Segurança de transporte agent ↔ serviços centrais: mTLS obrigatório (certificado de
    cliente único por agent, nunca segredo compartilhado) + credenciais de privilégio mínimo
    (MySQL do agent é somente leitura; policy do MinIO escopada aos certificados que a
    réplica serve) (SPEC.md §9.4).
  - Certificados SSL em repouso no MinIO protegidos por SSE; gerenciamento da chave de
    cifragem (estática vs. KMS/Vault) fica em aberto (SPEC.md §11, §16).
  - Topologia núcleo vs. réplicas: MySQL/Redis/MinIO/control-plane (núcleo) rodam em host(s)
    fixo(s) com volume local, fora do pool de máquinas elásticas; troca de host do núcleo é
    operação manual e pouco frequente (copiar volume + rebuild), nunca volume de rede
    (NFS/CIFS) por risco de corrupção. Réplicas `agent+nginx` continuam efêmeras e sem
    estado local (SPEC.md §13.1, §13.2).
  - MySQL confirmado (não MongoDB) mesmo para cenário de réplica/HA futura — usar MySQL
    Group Replication quando necessário, preservando FK, transação padrão e Eloquent.
  - Deploy padrão sobe MySQL/MinIO junto com o núcleo (bundled), mas nada é hardcoded — se o
    cliente já tem um MySQL/MinIO centralizado na rede interna do cluster, basta apontar a
    configuração pra ele em vez de subir os containers bundled (SPEC.md §13.3).

### Implementado
- **Fase 11 — Ambiente Docker completo** (SPEC.md §13, §14): `docker compose up` (via
  `./scripts/install.sh`) sobe o ambiente de dev inteiro com um único comando — control-plane
  (web/queue/scheduler), MySQL, Redis, MinIO (+ `minio-init` criando o bucket idempotentemente),
  Mailpit e uma réplica real `agent+nginx`, todos com healthcheck real (não só "container up").
  **Verificado de ponta a ponta neste ambiente** (não simulado): `./scripts/install.sh` do zero
  ficou com todos os serviços `healthy`, `gateway:bootstrap-admin` liberou o IP e criou o admin,
  `./scripts/healthcheck.sh` confirmou os 5 serviços expostos, e a réplica `agent+nginx` respondeu
  com o catch-all `444` documentado em `nginx.conf` (zero `proxy_hosts` configurados ainda — não é
  falha, é o comportamento esperado de instalação nova).
  **`apps/control-plane/Dockerfile` novo** (não existia antes): multi-stage (`composer:2` pra
  vendor, depois `dunglas/frankenphp:1-php8.3`) — uma imagem só, vários papéis
  (web/queue/scheduler/migrate) definidos por `docker/entrypoint.sh`, cada papel um serviço
  diferente no compose, nenhum supervisor de processo necessário (FrankenPHP é um binário só pro
  papel web; os outros já são comandos Artisan de processo único). Deliberadamente sem
  `config:cache`/`route:cache` no build — cachear `env()` em build time quebraria o mesmo binário
  rodando com env diferente por deploy (SPEC.md §13.3). `nginx/Dockerfile` (multi-stage
  agent+nginx) já existia desde a Fase 5 — não precisou de nenhuma mudança, só ligar no compose.
  **`gateway:bootstrap-admin` novo** (`BootstrapAdminCommand`): sem ele, `docker compose up -d`
  deixava a aplicação rodando sem ninguém conseguir logar — a UI da Fase 8 não tem
  auto-registro, e `CreateUserAction` exige um ator autenticado existente (não serve pro primeiro
  usuário do zero). Idempotente (roda em todo `install.sh`, não só na primeira vez): sempre
  garante o IP na allowlist (útil quando o IP observado pelo container difere de `127.0.0.1` —
  confirmado empiricamente que o NAT do Docker Desktop pra Mac apresenta esse tráfego com um
  gateway próprio, não o loopback real do host) e só cria o admin se nenhum usuário existir ainda,
  reaproveitando o fluxo de senha via link de reset (nunca senha direta), igual a
  `CreateUserAction`. Rota `GET /_install/whoami` nova em `web.php`, deliberadamente fora do
  `ip.allowlist` (mesmo racional de `/auth/ip-requests`) — só existe pra deixar o `install.sh`
  descobrir qual IP o app realmente enxerga antes de qualquer coisa estar liberada; não vaza nada
  além do próprio IP do chamador.
  **`.github/workflows/main.yml` reescrito** (SPEC.md §14): parava de buildar um `Dockerfile` na
  raiz que não existia; agora dois jobs de build separados (`build-control-plane`,
  `build-nginx`), cada um com contexto/Dockerfile próprio, `docker/metadata-action` computando as
  tags (`latest` só em push, `sha` curto sempre, semver quando a tag `vX.Y.Z` existir). Build
  roda em PR contra `main` (só valida que builda, sem publicar) e em push a `main`/tag `v*.*.*`
  (publica de verdade); `homolog` e demais branches só rodam os testes (`test-control-plane`,
  `test-agent`), nunca build/publish de imagem.
  **`.gitignore` corrigido**: `/.env` (raiz) não estava listado — só as variações dentro de
  `apps/control-plane/` estavam. Sem essa entrada, o `.env` real gerado por `install.sh` (com
  `APP_KEY`, credenciais do MinIO) ficaria só "não rastreado", um `git add -A` descuidado
  commitaria segredo de verdade (CLAUDE.md §Segurança: "Secrets never belong in the repository").
  `LICENSE` (MIT) adicionado — estava vazio (`e69de29`) desde o primeiro commit.
  `examples/docker-compose.yml`, `examples/.env.example`, `examples/cluster-example.yml`,
  `scripts/install.sh`/`update.sh`/`healthcheck.sh` implementados (eram placeholders vazios desde
  o início do projeto, listados em "Pendente / não iniciado"). `cluster-example.yml` usa a
  imagem **publicada** (`diegoneumann/apy-gateway-nginx:latest`), não builda nada — é o exemplo
  pra subir uma réplica de borda numa máquina separada do núcleo (SPEC.md §13.1), diferente do
  `docker-compose.yml` de dev, que builda tudo local.
- **Fase 10 — Observabilidade** (SPEC.md §12): duas metades, ambas fechadas nesta fase — logs
  centralizados via encaminhamento **opt-in** (decisão do usuário: quem não quiser centralizar
  continua acessando log local de cada réplica, sem mudança nenhuma de comportamento) e métricas
  de sincronização por réplica (`GET /api/replicas`, antes "não implementado").
  **Agent (Rust) — zero dependências novas no Cargo.toml de novo** (`std::net::UdpSocket`,
  `std::process::Command`, `serde_json` e `tokio::sync::watch` já eram dependências/features já
  usadas). Heartbeat deixou de ser um inteiro cru no Redis e virou JSON estruturado
  (`{ts,ip,agent_version,nginx_version,synced_domains_count}`) — `ip` autodetectado via
  `UdpSocket::connect(db_host:db_port).local_addr()` (truque padrão, não manda pacote nenhum,
  só pergunta pro SO qual interface local seria usada), `nginx_version` via `nginx -v` rodado uma
  vez no boot (mais fiel que uma env var fixa que podia dessincronizar da imagem base real),
  ambos detectados uma única vez (invariantes pra vida do container, mesmo padrão de
  `replica_hostname`). `synced_domains_count` flui de `SyncOutcome` (novo campo, preenchido com
  `agent_state.domains.len()` no fim de `run_bootstrap`/`sync_incremental`) pra dentro do
  `heartbeat_loop` via um `tokio::sync::watch::channel` atualizado depois de cada sync bem-sucedida.
  **Log encaminhado via suporte nativo do Nginx a `access_log syslog:server=...;`** — não um
  daemon rsyslog local: o agent continua supervisionando só o Nginx, nunca ganha um segundo
  processo (decisão confirmada com o usuário). Flag `LOG_FORWARDING_ENABLED` (default `false`) +
  `CENTRAL_LOG_SERVER_HOST`/`CENTRAL_LOG_SERVER_PORT`; desligado, comportamento idêntico ao que já
  existia (arquivos symlinkados pra stdout/stderr pela imagem base do Nginx). O snippet gerado
  (`nginx/snippets/logging.conf`) é escrito pelo agent no boot (`logging_config.rs`) — diferente
  dos outros snippets do repo, que são arquivos estáticos `COPY`ados pelo `nginx/Dockerfile`; esse
  só existe depois que o agent roda.
  **Duas descobertas empíricas reais durante a verificação, nenhuma hipotética** (ver
  `docker/observability-verification/README.md`): (1) a tag do syslog do Nginx só aceita
  alfanumérico e underscore — a primeira versão usou `apy-gateway-nginx` (hífen, igual a toda
  outra convenção do projeto) e `nginx -t` rejeitou; corrigido pra `apy_gateway_nginx`.
  (2) o Nginx resolve o hostname do `CENTRAL_LOG_SERVER_HOST` no momento de `nginx -t`/boot, não
  de forma preguiçosa no primeiro log — hostname não resolvível faz o Nginx inteiro falhar a subir,
  não só o encaminhamento de log ficar faltando. Registrado como risco real em aberto no SPEC.md
  §16 (recomendação: apontar pra algo confiavelmente resolvível, nunca algo efêmero).
  **Control-plane**: `replica_agents` ganhou `synced_domains_count` (nullable — null é "nunca
  recebeu heartbeat válido", nunca confundir com zero real). `SyncReplicaMetricsFromHeartbeatsAction`
  lê `KEYS apy-gateway:replicas:*:heartbeat` + `MGET` na conexão Redis dedicada `events` (mesma
  usada por `RecordDomainEventAction` desde a Fase 4), `updateOrCreate` por hostname,
  payload malformado ou chave que expirou entre o `KEYS` e o `MGET` é ignorado com log (mesmo
  padrão do agent pra evento de domínio malformado). **A liveness usa o TTL do Redis como fonte de
  verdade**: qualquer `replica_agents` que estava `online` e não apareceu no scan atual vira
  `offline` — nenhum relógio/estado de expiração duplicado do lado PHP.
  `SyncReplicaMetricsCommand` (`replicas:sync-metrics`) roda a cada minuto (piso do scheduler do
  Laravel — segunda entrada de `Schedule::` do projeto, depois da renovação ACME da Fase 9), então
  o flip pra `offline` pode atrasar até ~60-100s além do TTL real — aceitável pra métricas de
  observabilidade, documentado no SPEC como trade-off, não um requisito de tempo real.
  `GET /api/replicas` (`ReplicaController`/`ReplicaAgentResource`/`ReplicaAgentPolicy`, só
  `viewAny`) segue o mesmo gate `auth:sanctum + ip.allowlist` de todo o resto.
  **Simplificação deliberada, registrada no SPEC**: `synced_domains_count` é agregado por réplica,
  não quebrado por domínio (SPEC.md §12 antigo dizia literalmente "versão aplicada por domínio") —
  criar uma tabela filha réplica-domínio pra isso seria desproporcional a "métricas mínimas"; o
  detalhe por domínio continua só no `state.json` local e efêmero de cada réplica.
  **10 testes novos** (44 Rust total — `resolve_log_destination`/`render_snippet`/`write_snippet`,
  `parse_version_output`, `detect_local_ip`, round-trip do `HeartbeatPayload`; 97 PHP total —
  `SyncReplicaMetricsCommandTest` com Redis mockado cobrindo hostname novo/existente/payload
  malformado/chave sumida/flip pra offline/já-offline-não-reescreve, `ReplicaApiTest`).
  **Verificação de ponta a ponta real** em `docker/observability-verification/` (harness novo —
  nem estende `agent/tests/integration/`, que é só agent/migrate throwaway, nem reaproveita
  `docker/acme-verification/`, específico do Pebble): rsyslog de verdade (`imudp`/`input()`
  configurado à mão e testado isoladamente antes de entrar no compose — rsyslog não escuta nada
  por padrão) recebendo linhas de access log reais via UDP com a tag correta; heartbeat JSON real
  fluindo até `GET /api/replicas` (`status: online`, `synced_domains_count` batendo); heartbeat
  parado de verdade + polling até o TTL expirar de verdade no Redis (nunca simulado/adiantado) +
  `replicas:sync-metrics` confirmando o flip pra `status: offline`. Corrida real encontrada e
  corrigida: o `agent` desse harness agora espera o `control-plane` ficar `healthy` (schema
  migrado) antes de subir, não só o MySQL — sem isso o agent crashava no boot com
  `Table 'proxy_hosts' doesn't exist`.
- **Fase 9 — Emissão automática de certificados via ACME/Let's Encrypt (HTTP-01)** (SPEC.md §11,
  §9.4). Fecha a lacuna deixada aberta de propósito na Fase 7 (só upload manual). Control-plane é
  o único cliente ACME (RFC 8555); o agent Rust continua sem receber nenhuma chamada direta e
  permanece SELECT-only — o hand-off do desafio HTTP-01 é inteiramente via banco.
  **Modelo de dados**: tabela nova `acme_challenges` (`ssl_certificate_id`, `domain`, `token`
  único, `key_authorization`, `status` pending/valid/invalid, `expires_at`) + case novo
  `DomainEventType::AcmeChallengeReady` (`acme_challenge.ready` — otimização de latência, o agent
  ignora `subject_id` e sempre resincroniza o conjunto pendente inteiro).
  **Control-plane**: `POST /api/certificates/request-acme` (`RequestCertificateIssuanceAction`)
  exige que o domínio já exista como `proxy_hosts` ativo (senão 422 imediato — sem isso não há
  onde o agent serviria o desafio) e devolve **202** (não 201 — recurso ainda `pending`), com
  idempotência para pedido duplicado. Despacha `IssueAcmeCertificateJob` (`ShouldQueue` +
  `ShouldBeUnique`, fila Redis — **primeiro uso de fila no projeto**, `QUEUE_CONNECTION` trocado
  de `database` pro `redis` já antecipado pelo comentário em `config/database.php` desde a Fase 4).
  `PerformAcmeCertificateIssuanceAction` orquestra o protocolo inteiro numa classe só (mesmo
  precedente de `UploadCertificateAction`): conta → order → autorizações → publica
  `acme_challenges` + evento → aguarda propagação (`ACME_CHALLENGE_PROPAGATION_DELAY_SECONDS`) →
  dispara validação → poll → finaliza → baixa cadeia → sobe MinIO → atualiza `SslCertificate` →
  **auto-associa** o(s) `proxy_hosts` correspondente(s) (decisão confirmada com o usuário: fecha o
  loop sem passo manual, reaproveita o código do agent das Fases 5/7 sem nenhuma mudança Rust) →
  registra `certificate.issued`/`certificate.renewed`. `MarkCertificateIssuanceFailedAction`
  (chamada de dois lugares: catch do orquestrador e `Job::failed()`) marca `status=error` e
  invalida challenges ainda `pending` — os que já validaram ficam `valid` mesmo se o finalize
  falhar depois (autorização RFC 8555 sobrevive a uma falha pontual de finalize). Renovação:
  `FindCertificatesDueForRenewalAction` + `CheckExpiringCertificatesCommand`
  (`certificates:check-expiring`, primeiro `Schedule::command()` do projeto, diário) reusa o mesmo
  Job (renovação = reemissão). Chave de conta ACME (RSA) persistida no MinIO
  (`MinioAcmeAccount implements AcmeAccountInterface`), nunca em disco local do container.
  **Biblioteca ACME**: `acmephp/core` (cotado no plano) provou-se inviável — conflito real de
  dependência, exige `psr/http-message ^1.0`, Laravel 13 trava em `^2.0`. Adotado
  `rogierw/rw-acme-client` em seu lugar (resolve limpo, tem `HttpClientInterface` própria
  injetável — testabilidade sem precisar lidar com mock do Guzzle).
  **Quatro incompatibilidades reais entre essa lib e um servidor RFC 8555 estrito (Pebble) foram
  encontradas e corrigidas durante a verificação de ponta a ponta, todas em código nosso, nunca
  remendando o vendor**: (1) `AccountData::fromResponse()` lê `createdAt` sem fallback — Pebble não
  manda esse campo — corrigido com `PebbleCompatibleHttpClient` (decorator que faz backfill só
  quando ausente, inofensivo contra Let's Encrypt real). (2) `DomainValidation::start()` manda o
  campo legado `keyAuthorization` no corpo do POST de desafio — RFC 8555 §7.5.1 exige corpo vazio
  `{}`; Boulder ignora o campo extra, Pebble rejeita como malformado, e o próprio helper de JWS da
  lib (`KeyId::generate()`) nem consegue produzir um payload `"{}"` literal — corrigido assinando o
  POST de disparo do desafio manualmente (`triggerHttpChallenge()`, só com os primitivos públicos
  da lib). (3) `Directory::getOrder()` deriva a URL de status do pedido com
  `str_replace('new-order', 'order', ...)` — convenção de path específica do Boulder/Let's
  Encrypt, não do RFC — Pebble usa paths deliberadamente diferentes (`/order-plz`), então o
  replace não casa nada e a URL sintetizada dá 404 — corrigido usando `OrderData::url` (a URL real,
  do header `Location`) direto. (4) refresh de pedido precisa ser POST-as-GET assinado (RFC 8555
  §6.3), não GET puro — Pebble rejeita GET puro com 405 — corrigido assinando também esse refresh.
  Também achado (não é bug da lib): a chave da conta pode existir no MinIO sem o servidor ACME
  reconhecer (tentativa anterior gerou a chave mas falhou antes do registro completar) —
  `resolveAccount()` cai pra `create()` nesse caso (idempotente por definição no RFC 8555 §7.3).
  **Agent (Rust)**: **zero dependências novas no Cargo.toml**. Query SELECT-only nova
  (`fetch_pending_acme_challenges`) + módulo novo `acme_challenges.rs`
  (`sync_challenge_files`/`sync_challenges`) — escreve/remove arquivos no diretório compartilhado
  `/var/lib/apy-agent/acme-challenges/`, **sem precisar de `nginx -t`/reload** (é só arquivo
  estático servido via `alias`, diferente de todo resto que o agent já sincroniza). Ligado em
  `run_bootstrap` (reconciliação, nunca derruba o boot em erro) e `sync_incremental` (novo arm
  `"acme_challenge.ready"`, ignora `subject_id` de propósito).
  **Nginx**: `nginx/snippets/acme-challenge.conf` novo (`location ^~ /.well-known/acme-challenge/`).
  O branch SSL do template precisou de restruturação: o bloco `:80` era um `return 301` solto no
  nível do server (prioridade incondicional sobre qualquer `location`), virou `location /` com o
  redirect dentro, pra `location ^~ acme-challenge` conseguir competir e ganhar.
  **36 testes novos** (schema: 3, request-acme: 6, orquestração ACME com `HttpClientInterface`
  fake simulando a conversa RFC 8555 inteira — JWS, nonce, order lifecycle: 3, Job: 3, comando de
  renovação: 3, Rust `acme_challenges`: 5 + `templates.rs` atualizado — 88 PHP + 33 Rust no total,
  zero regressão nas Fases 1-8).
  **Verificação de ponta a ponta real contra Pebble** (`docker/acme-verification/` — primeiro
  harness do repo a rodar o control-plane de verdade, não só um container throwaway de migrate,
  já que a lógica de emissão mora inteira no Laravel; Pebble faz validação HTTP-01 **real**, nunca
  `PEBBLE_VA_ALWAYS_VALID`): emissão completa em ~6-7s (bem dentro do orçamento configurado de
  propagação+poll), renovação em ~1s, confirmado em duas rodadas limpas (`down -v` + `up` do
  zero, sem intervenção manual). Emissor do certificado confere como `Pebble Intermediate CA`
  (prova de emissão real, não stub). `acme_challenges`/diretório de desafios voltam a ficar vazios
  depois — prova que a limpeza funciona, não só a escrita. Rejeição rápida (422, sem tocar o
  Pebble) confirmada para domínio sem `proxy_hosts`. **Dois achados extras, fora do escopo da
  lib ACME**: `php artisan serve` não repassa as env vars do container pro subprocesso PHP que ele
  sobe (confirmado via `/proc/<pid>/environ`) — harness trocou pra `php -S` direto; e semear
  `ProxyHost::factory()` sem sobrescrever `ssl_certificate_id` herda um certificado "ruído" da
  factory com `storage_path` inexistente no MinIO, que derruba a sincronização do domínio
  **inteiro** (até o bloco `:80` puro) — corrigido no seed do harness.
- **Fase 8 — Interface web (Blade/Livewire) + APIs novas de usuários e IP allowlist**
  (SPEC.md §3: "SPA sofisticada" explicitamente fora do escopo v1 — Blade/Livewire "é
  suficiente para o MVP", sem investimento em design visual). Escopo completo aprovado com o
  usuário, incluindo construir do zero as APIs de usuários e IP allowlist, que nunca
  existiram (só `proxy_hosts`/`certificates` tinham API real, das Fases 3/4/7).
  **Backend novo**: `POST/GET/PUT/DELETE /api/users`, `GET/POST/DELETE /api/ip-allowlist-entries`,
  `GET /api/ip-access-requests` + `POST /api/ip-access-requests/{id}/approve` (autenticado,
  distinto do endpoint público por token da Fase 2 — duas classes de controller separadas de
  propósito, cada uma sempre com ou sempre sem `$this->authorize()`, nunca misturado).
  `CreateUserAction` gera senha placeholder inútil e dispara
  `Password::sendResetLink()` (broker padrão do Laravel, `password_reset_tokens` já existia
  sem uso desde a Fase 1); `DeleteUserAction` tem duas guardas (auto-exclusão, último usuário
  do sistema) — nenhuma das duas existe em `DeleteIpAllowlistEntryAction` (assimetria
  deliberada: perder acesso por IP é recuperável via o fluxo de e-mail da Fase 2, perder o
  último usuário não tem recuperação nenhuma). `ApproveIpAccessRequestAction` ganhou
  `?User $approver = null` (aditivo) para que `approved_by`/`audit_logs.user_id` reflitam
  aprovação anônima por token vs. aprovação autenticada pela UI.
  **Frontend novo** (Livewire v3.8, não v4 — v4 usa single-file components, prejudica
  testabilidade/previsibilidade): `Login`/`TwoFactorChallenge` (guard `web`, chamam as Actions
  de auth diretamente, nunca via fetch pra API JSON — `ValidationException` de qualquer método
  de componente já vira `$errors` automaticamente), telas `Index`/`Create`/`Edit` para
  proxy-hosts e usuários, `Index`/`Upload` para certificados (sem `Edit` — o backend nunca teve
  update/destroy de certificado), e uma única tela `Index` para IP allowlist com duas seções
  (allowlist atual + solicitações pendentes) e formulário inline de adicionar IP (sem rota
  `Create` separada — só 3 campos, não compensa fragmentar). `/` (raiz autenticada) reaproveita
  `ProxyHosts\Index` como dashboard, em vez de construir uma tela não pedida em lugar nenhum do
  SPEC. CSS embutido simples (`layouts/partials/styles.blade.php`), sem Tailwind/CDN — não há
  pipeline de build (`public/build/manifest.json` não existe) e a rede é privada sem egress
  garantido (SPEC.md §6.0); as views inicialmente usaram classes Tailwind por engano (sem
  nenhum efeito visual real) e foram reescritas antes de prosseguir.
  `routes/livewire.php` novo, exigido de `web.php` (mesmo padrão de `auth.php`); rotas
  autenticadas atrás de `['auth', 'ip.allowlist']` — guard de sessão, não `auth:sanctum` (isso
  é só pra API JSON stateless). Logout é `LogoutController` simples (não uma Action — sem
  efeito persistido pra auditar), rota **POST** de propósito (nunca GET, por CSRF).
  **70 testes** (52 já existentes + 18 novos): API de usuários/IP allowlist com os mesmos
  gates de sempre (401/403) mais as duas guardas novas de `DeleteUserAction`; componentes
  Livewire testados via `Livewire::test()` — `Login`/`TwoFactorChallenge` (caminho feliz,
  senha/código errado, deep-link sem login pendente) e um caminho feliz por tela de CRUD
  (`Index` renderiza, `Create`/`Upload` cria, apagar remove, `approve`/`revoke` no IP
  allowlist) — sem repetir toda aresta de validação já coberta a nível de Action/API.
  Removido `tests/Feature/ExampleTest.php` (scaffold padrão do Laravel, assumia `GET /` como
  página pública — não é mais verdade, `/` agora é o dashboard autenticado).
  **Verificação**: `php artisan route:list` confirma as 46 rotas com os middlewares certos;
  suíte completa passa sem regressão nas Fases 1–7. **Limitação registrada**: sem ferramenta de
  navegador disponível neste ambiente, a verificação manual ficou no nível HTTP — subiu SQLite
  real + `php artisan serve` real e confirmou via curl que cada rota autenticada redireciona
  corretamente pra `/login` quando anônima, que `/login` renderiza a página completa (layout +
  estilos + snapshot do Livewire) sem erro, e que o guard de deep-link do `TwoFactorChallenge`
  redireciona de verdade em HTTP — não uma sessão de clique ponta-a-ponta como as Fases 5/6/7
  tiveram com o agent.
- **Fase 7 — Certificados SSL: upload manual** (SPEC.md §11, escopo decidido com o usuário: só
  upload manual, emissão automática via ACME fica pra fase futura — exigiria desenhar um
  mecanismo novo de exposição do desafio HTTP-01 pelo agent, escopo grande demais pra misturar
  aqui; esta fase **não toca `agent/` nem `nginx/`**).
  `POST /api/certificates/upload` (+ `GET /api/certificates`, `GET /api/certificates/{id}`) —
  `UploadCertificateAction` faz parse X.509 real (`openssl_x509_parse`), confirma que a chave
  privada bate com o certificado (`openssl_x509_check_private_key`), extrai `primary_domain`
  (subject CN) e `domain_names` (SAN), rejeita certificado expirado/ainda-não-válido, sobe pro
  MinIO **antes** de criar a linha no MySQL (evita linha órfã prometendo um certificado que não
  existe), e publica `certificate.issued` via `RecordDomainEventAction` — fechando o ciclo com a
  Fase 6, que desde então já sabia reagir a esse evento mas nunca tinha visto um de verdade.
  Disk `minio` novo em `config/filesystems.php` (`league/flysystem-aws-s3-v3`), usando os
  **mesmos nomes de env var que o agent Rust já usa** (`MINIO_*`, não `AWS_*`) — os dois lados
  claramente apontam pro mesmo MinIO.
  **Duas descobertas empíricas que mudaram o plano**: (1) testei `ServerSideEncryption: AES256`
  contra um MinIO de verdade sem KMS — rejeitado (`NotImplemented: KMS not configured`). SSE
  fica como opção (`MINIO_SSE_ENABLED`, default `false`), não hardcoded — é exatamente a decisão
  em aberto que o §16 já registrava, agora confirmada como dependente de infra que não existe
  ainda. (2) Validei o formato real de `openssl_x509_parse()` gerando um certificado de verdade
  antes de escrever o código de extração de SAN, em vez de adivinhar o formato da string.
  `SslCertificateResource` nunca expõe `storage_path` (SPEC.md §15 — só metadados).
  `POST /api/proxy-hosts/{id}/certificates` (associar certificado) e `POST /api/certificates/{id}/renew`
  ficam de fora de propósito — o primeiro já é coberto por `PUT /api/proxy-hosts/{id}` com
  `ssl_certificate_id` desde a Fase 3/4, construir uma rota nova faria a mesma coisa duas vezes.
  6 testes novos (certificado+chave gerados de verdade em tempo de teste via `openssl_csr_sign`,
  `Storage::fake('minio')`, mesmo padrão de mock do Redis das Fases 4/6).
  **Verificação de ponta a ponta real, fechando o ciclo Laravel → MinIO → agent Rust**: subiu
  MySQL+MinIO+Redis reais, rodou o control-plane de verdade, fez upload de um certificado via
  `POST /api/certificates/upload` de verdade (não mockado), associou a um `proxy_host` via
  `PUT /api/proxy-hosts/{id}`, subiu a imagem `apy-gateway-nginx` **sem nenhuma mudança** — o
  build deu 100% cache hit, provando que o agent realmente não foi tocado — e confirmou: o agent
  baixou o certificado certo já no primeiro boot (`applied=1 failed=0`), serviu HTTPS com ele, e
  o SHA256 do arquivo `fullchain.pem` no container bate **exatamente** com o `content_hash` que a
  API retornou no upload — prova criptográfica de que a convenção `storage_path` (placeholder
  documentado desde a Fase 5) funciona de ponta a ponta com um produtor de verdade.
- **Fase 6 — Agent: sync em tempo real + reconciliação** (SPEC.md §9.2–§9.3): assinatura do
  canal `apy-gateway:events` (`agent/src/event_bus.rs`, crate `redis` — só `redis://`, sem TLS
  nesta fase, ver limitação abaixo), sync incremental por domínio, reconciliação periódica
  completa (`run_bootstrap` reaproveitado num timer de 5 min — já era uma reconciliação completa
  desde a Fase 5), heartbeat.
  **Tensão real entre §9.2 e §9.4 resolvida**: heartbeat vai pro **Redis**
  (`apy-gateway:replicas:{hostname}:heartbeat`, `SET ... EX ttl`), nunca pro MySQL — `replica_agents`
  nem está no grant de leitura do agent, e §9.4 é explícito que toda escrita é exclusiva do
  control-plane. Persistir heartbeat em `replica_agents.last_heartbeat_at` de verdade fica pra
  uma fase futura do lado control-plane, ainda não atribuída no roadmap. Agent continua
  permanentemente SELECT-only — zero SQL de escrita nesta fase.
  **Limitação conhecida registrada, não escondida**: mTLS no Redis não é viável nesta fase — a
  versão da crate `redis` compatível com `aws-sdk-s3`/`aws-config` já presentes não tem feature
  de TLS funcional (`tls-rustls`/`tokio-rustls-comp` causou conflito real de versão,
  `E0463: can't find crate for aws_smithy_types`); só `redis://` por enquanto, ao lado da decisão
  já existente de mTLS ser infra em aberto (§16).
  `sync.rs` refatorado (extração pura, sem mudar comportamento): `sync_hosts` compartilhado entre
  reconciliação completa e sync incremental; `remove_by_host_id` (reverse-lookup por
  `proxy_host_id` no `state.json` — a linha já não existe mais no MySQL quando um evento
  `proxy_host.deleted` chega) também trata o caso "host desabilitado entre publicação e agora",
  removendo imediatamente em vez de esperar a reconciliação periódica. `main.rs` trocou o
  `select!` de 2 branches da Fase 5 por um `loop { select! }` de 4 (nginx/shutdown continuam
  terminais; evento Redis e timer de reconciliação nunca terminam o processo) — finalmente usa
  `nginx::reload` (escrito na Fase 5, sem uso até agora).
  **27 testes unitários** (parse de evento, `resolve_replica_hostname_from`, `remove_by_host_id`
  com testers fake — corrigido no processo: a primeira versão construía `RealNginxTester`
  internamente, o que faria os testes tentarem rodar um binário `nginx` inexistente no container
  de teste; refatorado pra receber um `ConfigTester` injetável, igual ao padrão já estabelecido
  em `nginx.rs` na Fase 5). **Verificação de ponta a ponta com Redis real** (MySQL+MinIO+Redis+
  imagem real): heartbeat aparece com TTL e avança numa conexão independente do pub/sub; update
  direto no MySQL + `PUBLISH` manual aplica ao vivo **sem reiniciar o container** (`StartedAt`
  inalterado); deleção via evento remove ao vivo; host desabilitado é removido imediatamente
  (não espera os 5 min); JSON malformado só loga e o loop continua processando normalmente;
  Redis derrubado no meio → agent não cai, Nginx segue servindo, heartbeat/subscribe reconectam
  sozinhos com backoff, e a mudança feita durante a queda é pega pela reconciliação periódica sem
  nunca ter recebido o evento. Não verificado nesta rodada: eventos `certificate.*` contra um
  MinIO real (reaproveita o mesmo `sync_hosts` já exercitado duas vezes; só a query nova
  `fetch_active_proxy_hosts_by_certificate_id` fica sem prova ao vivo — registrado como risco
  baixo em `agent/tests/integration/README.md`).
- **Fase 5 — Agent (Rust): bootstrap e sync inicial** (SPEC.md §9.1 passos 1–6): primeiro
  código Rust do projeto. `agent/src/{main,config,models,db,minio,templates,nginx,state,sync,
  app_context}.rs` — conecta MySQL (SELECT-only, explícito nunca `SELECT *`), busca
  `proxy_hosts` ativos + certificado associado, compara com `state.json` local
  (`/var/lib/apy-agent/state.json`, versão de host **e** de certificado — pega renovação de
  cert mesmo sem bump de versão do host), baixa certificado do MinIO quando necessário
  (`aws-sdk-s3`, convenção placeholder documentada: `storage_path` → `.crt`/`.key`), renderiza
  o `server {}` via Tera (`nginx/templates/proxy_host.conf.tera` + `nginx/snippets/*`), testa
  cada domínio **individualmente** (`nginx -t` valida a árvore inteira de uma vez, então cada
  troca é feita e testada isoladamente — domínio ruim é revertido sem afetar os outros), sobe o
  Nginx como processo filho (agent = PID 1) e só escreve o marker de healthcheck depois de
  confirmar via TCP que o Nginx está de fato aceitando conexão. `predis`/`phpredis`, heartbeat
  em `replica_agents` e assinatura do canal Redis ficam pra Fase 6 de propósito (a última
  inclusive contradiria o grant SELECT-only se fosse o agent escrevendo). `nginx/Dockerfile`
  (multi-stage: compila o agent, empacota com Nginx) e `agent/Dockerfile` (só dev/CI,
  `cargo build`/`cargo test`) já ficam prontos aqui — a antecipação foi necessária pra provar o
  próprio critério de aceite da fase ("healthcheck só OK após Nginx no ar"); Fase 10 só
  precisa ligar isso no `docker-compose.yml`/CI, não recriar o Dockerfile.
  **17 testes unitários** (`cargo test`, sem serviço externo): diff de estado (host novo,
  inalterado, versão mudou, cert anexado/renovado/removido) + round-trip do `state.json`;
  render dos templates reais do repo (sem SSL, com SSL, com websockets); a lógica de
  troca-testa-reverte de `nginx.rs` com um `ConfigTester` fake. **Verificação de ponta a ponta
  com infraestrutura real** (MySQL com o schema aplicado via `php artisan migrate` de verdade,
  MinIO com certificado de teste enviado manualmente, backend HTTP real, imagem
  `apy-gateway-nginx` real) confirmou: domínio HTTP simples e domínio HTTPS (cert baixado do
  MinIO) servindo 200; domínio desabilitado nunca aparece; domínio com certificado sem
  `storage_path` falha isolado (nunca derruba o boot); reboot sem mudança nos dados é
  `applied=0 removed=0` (idempotência real); domínio com config malformada é revertido sem
  tirar do ar os domínios saudáveis, `state.json`/`conf.d` ficam limpos pra ele, e o container
  segue `healthy` o tempo todo. O gotcha de runtime do `rustls` (exige instalar um
  `CryptoProvider` global) foi verificado ao vivo — não houve panic ao conectar de verdade no
  MySQL/MinIO. Harness documentado em `agent/tests/integration/` (`docker-compose.yml`,
  `seed.sql`, `README.md`) para reproduzir.
- **Fase 4 — Barramento de eventos** (SPEC.md §8): `RecordDomainEventAction`
  (`app/Actions/Events/`) — classe reutilizável que persiste em `domain_events` e publica no
  canal Redis `apy-gateway:events`, chamada pelas 3 Actions de `ProxyHost` (create/update/delete)
  logo após a mutação. Publicação nunca amarrada na mesma transação da escrita (SPEC trata como
  "sinal de acorda e verifica", não fonte de verdade). `id` do payload Redis é um UUID novo por
  publicação (não o PK de `domain_events`); `version` sempre capturado no momento certo (no
  delete, antes de apagar a linha). Trocado `REDIS_CLIENT` de `phpredis` (extensão PECL) para
  `predis` (pacote composer puro-PHP) para não depender de extensão compilada.
  **Bug real encontrado e corrigido durante verificação manual com Redis de verdade** (não só
  testes mockados): o `REDIS_PREFIX` global do `config/database.php` (pensado para chaves de
  cache/fila) estava reescrevendo silenciosamente o canal de `apy-gateway:events` para
  `laravel-database-apy-gateway:events` — o que quebraria a assinatura do agent Rust. Corrigido
  com uma connection Redis dedicada (`'events'` em `config/database.php`) com `prefix` vazio,
  confirmado por teste manual com container Redis avulso (`PSUBSCRIBE *`) antes e depois da
  correção. `tests/Feature/Events/ProxyHostDomainEventTest.php` cobre as 3 operações, validando
  o formato exato do payload Redis e a linha gravada em `domain_events`.
- **Fase 3 — API de gestão de proxies** (SPEC.md §7): CRUD completo de `proxy_hosts`
  (`GET/POST /api/proxy-hosts`, `GET/PUT/DELETE /api/proxy-hosts/{id}`) atrás de
  `auth:sanctum` + `ip.allowlist` — a allowlist de IP vale pra qualquer rota autenticada, não
  só login (SPEC.md §6.1). Primeiro recurso de domínio autorizável do projeto: `ProxyHostPolicy`
  (todos os métodos liberados pra qualquer usuário autenticado — não há papel/permissão no
  schema, é um admin por instalação) e `Controller` base ganhou `AuthorizesRequests`. `version`
  nasce em 1 explicitamente na criação e incrementa a cada `update` (nunca aceito do cliente).
  `ProxyHostResource` achatado (sem nested resources — `SslCertificateResource`/`UserResource`
  não existem ainda). Sem `domain_events`/Redis (Fase 4) e sem `audit_logs` para CRUD de proxy
  (SPEC só exige auditoria para login/IP/2FA). Nenhuma migration nova.
  `tests/Feature/ProxyHost/ProxyHostApiTest.php` cobre listagem paginada, criação, domínio
  duplicado, incremento de versão, remoção, e os dois gates (sem token → 401, IP fora da
  allowlist mesmo autenticado → 403).
- **Fase 2 — Autenticação** (SPEC.md §6): fluxo completo de liberação de IP por e-mail
  (`POST /auth/ip-requests` + `GET /auth/ip-requests/{token}/approve`), login com senha
  (`POST /auth/login`) e 2FA por código de e-mail (`POST /auth/login/verify`), atrás do
  middleware `ip.allowlist` (só nas rotas de login — a rota de solicitação de IP fica de fora
  de propósito, é o escape-hatch do §6.0). Sanctum instalado (`php artisan install:api`): a
  verificação de 2FA emite sessão (`Auth::login`) **e** token (`createToken`) no mesmo passo.
  Código de 2FA fica hasheado em cache (TTL curto), nunca em coluna nova de `users`; qual
  usuário está pendente de 2FA fica na sessão. Nenhuma migration nova além da que o próprio
  Sanctum publica (`personal_access_tokens`) — todo o resto reaproveita o schema da Fase 1.
  Login/2FA falhos e bem-sucedidos e aprovação de IP são gravados em `audit_logs`, nunca a
  solicitação de IP em si (SPEC.md é explícito: só a aprovação é auditada). Rate limiting nas
  três rotas sensíveis (SPEC.md §15). Sem `App\Policies\*` ainda — não há recurso autorizável
  nesta fase (isso é Fase 3). `tests/Feature/Auth/*` cobre o fluxo feliz e as bordas (IP não
  allowlisted, senha errada, token de aprovação reusado/expirado, código de 2FA errado/expirado
  /sem login pendente).
- **Fase 1 — Modelo de dados do control-plane** (SPEC.md §5): migrations + models Eloquent
  para `ssl_certificates`, `proxy_hosts`, `ip_allowlist_entries`, `ip_access_requests`,
  `replica_agents`, `domain_events`, `audit_logs`, além de `two_factor_enabled`/
  `two_factor_verified_at` em `users`. Campos de conjunto fixo usam PHP enums nativos
  (`app/Enums`) com cast do Eloquent, em vez de `ENUM` nativo do banco (portável entre SQLite
  dos testes e MySQL de produção). `subject_type`/`subject_id` de `domain_events`/
  `audit_logs` usam `morphTo()` com morph map registrado em `AppServiceProvider` (aliases
  curtos, nunca FQCN). As duas tabelas são append-only (`UPDATED_AT = null`). Nenhuma coluna
  de chave privada em `ssl_certificates` (SPEC.md §15) — só `storage_path` (chave no MinIO).
  Factories para todos os models novos + `tests/Feature/ControlPlaneSchemaTest.php` cobrindo
  relations, casts, morph map e a constraint de unicidade de `proxy_hosts.domain`. Ainda sem
  Actions/Policies/Resources/Sanctum — isso é Fase 2/3.
- Scaffold do control-plane em Laravel 13 (`apps/control-plane`) — instalação padrão.
- Scaffold do agente em Rust (`agent/Cargo.toml`, `agent/Dockerfile`) — sem código-fonte
  ainda (`agent/src` não existe).
- `Dockerfile` inicial do Nginx (`nginx/Dockerfile`) — sem templates/snippets ainda.
- Estrutura de diretórios do monorepo: `apps/control-plane`, `agent`, `nginx`, `docker`,
  `examples`, `scripts`, `apps/docs`.
- Workflow de CI inicial (`.github/workflows/main.yml`) — build e push da imagem Docker
  para o DockerHub na branch `AJUSTAR`. **Hoje não faz nada de útil**: aponta para um único
  `Dockerfile` na raiz que não existe, e não reflete a arquitetura multi-imagem do projeto.
  Decisão registrada (SPEC.md §14): quando `apps/control-plane/Dockerfile` e o
  `nginx/Dockerfile` (multi-stage, agent+nginx) estiverem prontos, reescrever este workflow
  para buildar/publicar `apy-gateway-control-plane` e `apy-gateway-nginx` como jobs
  separados. Até lá, o workflow fica como está — não implementar o build ainda.

### Pendente / não iniciado
`apps/docs/` ainda não tem código — placeholder vazio no repositório.

## Próximos passos (roadmap)

Ordem sugerida — cada fase entrega algo testável antes de avançar para a próxima, conforme
a filosofia de mudanças pequenas e verificáveis do projeto.

- [x] **Fase 1 — Modelo de dados do control-plane** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 2 — Autenticação** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 3 — API de gestão de proxies** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 4 — Barramento de eventos** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 5 — Agent (Rust): bootstrap e sync inicial** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 6 — Agent: sync em tempo real + reconciliação** ✅ concluída — ver "Implementado" acima.

- [x] **Fase 7 — Certificados SSL: upload manual** ✅ concluída — ver "Implementado" acima.
  **Pendente pra fase futura (fora do escopo desta)**: emissão automática via ACME/Let's
  Encrypt (HTTP-01) — exige desenhar exposição do desafio pelo agent Rust — e renovação
  agendada (que também depende do ACME existir).

- [x] **Fase 8 — Interface web** ✅ concluída — ver "Implementado" acima.
- [x] **Fase 9 — Emissão automática via ACME/Let's Encrypt (HTTP-01)** ✅ concluída — ver
  "Implementado" acima.
- [x] **Fase 10 — Observabilidade** ✅ concluída — ver "Implementado" acima.
- [x] **Fase 11 — Ambiente Docker completo** ✅ concluída — ver "Implementado" acima.

## [0.0.0] - Estado inicial

- `997466d` first commit
- `220d408` Iniciado projeto
- `bcec3a6` Ajustado build automatico
