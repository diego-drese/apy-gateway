# Deploy em Docker Swarm e migração do Nginx Proxy Manager

Plano para instalar o apy-gateway no cluster Swarm de produção (**3 managers + 1 worker**) que hoje
roda o **Nginx Proxy Manager (NPM)** com **~50 domínios**, e trocar um pelo outro com
30–60 s de indisponibilidade e rollback imediato.

Tudo aqui foi ensaiado de ponta a ponta num cluster simulado com a mesma topologia
(`deploy/swarm/simulation/`, ver [Ensaio local](#ensaio-local-do-plano)).

Arquivos usados:

| Arquivo | Papel |
|---|---|
| `deploy/swarm/apy-gateway-stack.yml` | Stack de produção (núcleo + borda) |
| `deploy/swarm/deploy.sh` | Valida variáveis/rede/secrets e faz o `docker stack deploy` |
| `deploy/swarm/.env.example` | Variáveis de deploy (versão, portas, SMTP, rede dos upstreams) |
| `deploy/swarm/create-secrets.sh` | Gera os Docker secrets (uma vez) |
| `deploy/swarm/configs/*.sh` | One-shots `db-grants`/`minio-init`: usuários somente-leitura do agent no MySQL e no MinIO |
| `deploy/swarm/compare-with-npm.sh` | Compara NPM x apy-gateway domínio a domínio (fase canário) |
| `php artisan gateway:import-npm` | Importa hosts e certificados do NPM |

---

## 1. Antes de começar: perguntas que mudam o plano

Responda estas antes da janela — cada uma muda um passo concreto:

1. **Para onde o DNS dos 50 domínios aponta?** Um IP de um nó só, vários nós (round-robin), ou um
   balanceador/IP flutuante na frente? A borda do apy-gateway publica 80/443 em `mode: host`, então
   **só responde nos nós onde roda**. O plano padrão roda a borda nos 4 nós (equivale ao routing
   mesh que o NPM provavelmente usa hoje), então qualquer uma das três opções funciona — mas se o
   DNS aponta para um nó só, esse nó é o ponto de falha (igual hoje).
2. **Como o NPM alcança os apps?** Em Swarm, normalmente por uma rede overlay compartilhada (ex.:
   `proxy`) com `forward_host` = nome do serviço (`stack_servico`). Anote o nome dessa rede
   (`docker service inspect <npm> --format '{{json .Spec.TaskTemplate.Networks}}'`). Se algum host
   aponta para IP:porta publicada, também funciona.
3. **Qual nó hospeda o núcleo** (MySQL, Redis, MinIO, painel)? Recomendação: o **worker** — mantém
   os managers leves para o Raft e concentra o disco com dados num lugar só. Ele precisa de disco
   local confiável e backup (§11).
4. **SMTP de produção** — login com 2FA e aprovação de IP dependem de e-mail. Sem SMTP ninguém entra
   no painel.
5. **Como os administradores chegam ao painel** (VPN, bastion, túnel SSH)? O painel nunca é
   publicado na internet (SPEC.md §6.0).
6. **Recursos livres por nó.** O núcleo pede ~1,5 GB de RAM no nó dele; a borda ~30–60 MB por nó.

## 2. Topologia

```
                    DNS dos 50 domínios (1 IP, round-robin ou LB)
                                   │
        ┌──────────────────┬───────┴──────────┬──────────────────┐
        ▼                  ▼                  ▼                  ▼
   manager-1           manager-2          manager-3           worker
   edge :80/:443       edge :80/:443      edge :80/:443       edge :80/:443   (global, mode host)
        │                  │                  │                  │
        └───────── overlay "core" (cifrada) ───┴──────────────────┤
                                                                  ▼
                                                     NÚCLEO (label apy-gateway.core)
                                                     mysql · redis · minio
                                                     control-plane :8000 (só VPN)
                                                     queue · scheduler
        edge ──── overlay "upstreams" (a rede que o NPM já usa) ───▶ apps existentes
```

- **Borda (`edge`)**: agent Rust + Nginx, `mode: global`, sem estado. Nasce vazia, baixa tudo do
  núcleo e só fica `healthy` quando o Nginx está servindo a configuração sincronizada.
- **Núcleo**: fixado por label num nó, volumes **locais** (nunca NFS — SPEC.md §13.2).
- **Portas em `mode: host`**: o backend recebe o **IP real do cliente** em `X-Real-IP` /
  `X-Forwarded-For` (o ingress do Swarm trocaria pelo IP interno). Consequência: dois containers
  não podem segurar a mesma porta no mesmo nó — por isso o canário usa 8080/8443 e o corte libera
  80/443 do NPM primeiro.
- **Firewall entre os nós**: 2377/tcp, 7946/tcp+udp, 4789/udp (Swarm) e **protocolo ESP (IP 50)**,
  exigido pela overlay cifrada `core`. Da internet: só 80/443. Porta do painel (8000): só da VPN.

### O que a borda **não** reproduz do NPM

O importador detecta e reporta cada caso — nada muda em silêncio:

| Recurso do NPM | No apy-gateway | Tratamento no import |
|---|---|---|
| Access lists (basic auth / IP) | não existe | **host pulado** (não pode ficar exposto sem a proteção) |
| Advanced config (diretivas Nginx) | não existe (`custom_config` não é renderizado) | **host pulado** |
| Custom locations | não existe | **host pulado** |
| Domínio wildcard (`*.ex.com`) | não suportado | **host pulado** |
| Cache assets, block exploits, HSTS, HTTP/2 | não existe | importado, com aviso |
| HTTPS sem "Force SSL" | sempre redireciona 80→443 quando há certificado | importado, com aviso |
| Redirection hosts, streams, 404 hosts | não existe | fora do import — inventariar à mão |

Hosts pulados ficam no NPM até serem resolvidos (ver §6.4). `client_max_body_size 2000m` e timeouts
de 90s seguem o padrão do NPM (`nginx/nginx.conf`), para uploads e endpoints lentos continuarem
funcionando.

## 3. Fase 0 — Inventário (dias antes)

```sh
# Exporte do NPM (de qualquer máquina que alcance a porta 81 do NPM):
TOKEN=$(curl -s -X POST http://<npm>:81/api/tokens -H 'Content-Type: application/json' \
  -d '{"identity":"<admin-npm>","secret":"<senha>"}' | jq -r .token)
curl -s -H "Authorization: Bearer $TOKEN" http://<npm>:81/api/nginx/proxy-hosts > proxy-hosts.json
curl -s -H "Authorization: Bearer $TOKEN" http://<npm>:81/api/nginx/redirection-hosts | jq length
curl -s -H "Authorization: Bearer $TOKEN" http://<npm>:81/api/nginx/streams | jq length

# Quantos hosts usam algo não suportado:
jq '[.[] | select(.access_list_id > 0 or (.advanced_config // "") != "" or (.locations | length) > 0)] | length' proxy-hosts.json
```

Decida para cada host pulado: manter no NPM por enquanto, reimplementar a proteção no próprio app,
ou importar sem o recurso (`--include-unsupported`) sabendo o que perde.

## 4. Fase 1 — Imagens

O CI publica `apy-gateway-control-plane` e `apy-gateway-nginx` no Docker Hub em push na `main` e
em tags `vX.Y.Z` (SPEC.md §14). Para produção, crie uma tag:

```sh
git checkout main && git merge homolog && git tag v0.2.0 && git push origin main v0.2.0
```

Use sempre a tag (`APY_GATEWAY_VERSION=v0.2.0`), nunca `latest` — o rollback precisa saber para
qual versão voltar.

## 5. Fase 2 — Núcleo e borda em portas canário (sem impacto)

Num manager, com o repositório (ou só a pasta `deploy/swarm`) disponível:

```sh
# 1. Labels: núcleo no worker, borda em todos os nós
docker node update --label-add apy-gateway.core=true <worker>
for n in $(docker node ls -q); do docker node update --label-add apy-gateway.edge=true $n; done

# 2. Segredos (aleatórios, nunca impressos; idempotente)
./deploy/swarm/create-secrets.sh

# 3. Variáveis: EDGE_HTTP_PORT=8080 / EDGE_HTTPS_PORT=8443 nesta fase
cp deploy/swarm/.env.example deploy/swarm/.env && vi deploy/swarm/.env

# 4. Deploy (stack "apy-gateway"). deploy.sh valida .env, rede dos upstreams e secrets antes —
#    não use `docker stack deploy` direto: ver o cabeçalho de apy-gateway-stack.yml.
./deploy/swarm/deploy.sh
watch docker stack services apy-gateway     # tudo N/N; edge = 4/4; db-grants/minio-init/migrate 0/1 (one-shots concluídos)

# 5. Primeiro acesso: libere o IP de quem administra e crie o admin (link de senha vai por e-mail)
CP=$(docker ps -q -f name=apy-gateway_control-plane)   # no nó do núcleo
docker exec $CP php artisan gateway:bootstrap-admin --email=voce@empresa.com --ip=<seu-ip-na-vpn>
```

Nada disso toca o NPM: ele continua com 80/443. A borda já está nos 4 nós, nas portas 8080/8443.

## 6. Fase 3 — Importar do NPM

### 6.1 Copiar export e certificados para o container do painel

Os certificados do NPM ficam no volume dele: `/etc/letsencrypt/live/npm-<id>/` (Let's Encrypt) e
`/data/custom_ssl/npm-<id>/` (enviados à mão). `tar -h` segue os symlinks de `live/`.

```sh
NPM=$(docker ps -q -f name=<stack-do-npm>_app)        # no nó do NPM
CP=$(docker ps -q -f name=apy-gateway_control-plane)  # no nó do núcleo

docker exec $CP mkdir -p /tmp/npm-import/certs
docker exec -i $CP sh -c 'cat > /tmp/npm-import/proxy-hosts.json' < proxy-hosts.json
for dir in /etc/letsencrypt/live /data/custom_ssl; do   # pule o que não existir no seu NPM
  docker exec $NPM test -d $dir && \
    docker exec $NPM sh -c "cd $dir && tar -chf - npm-*" | docker exec -i $CP tar -xf - -C /tmp/npm-import/certs
done
```

(Se NPM e núcleo estão em nós diferentes, faça o `tar` para um arquivo no nó do NPM, copie com
`scp` e extraia no nó do núcleo. Apague `/tmp/npm-import` ao final — contém chaves privadas.)

### 6.2 Dry-run, revisão, import

```sh
docker exec $CP php artisan gateway:import-npm /tmp/npm-import/proxy-hosts.json \
  --certificates-dir=/tmp/npm-import/certs --as=voce@empresa.com --dry-run

docker exec $CP php artisan gateway:import-npm /tmp/npm-import/proxy-hosts.json \
  --certificates-dir=/tmp/npm-import/certs --as=voce@empresa.com | tee import.txt
docker exec $CP rm -rf /tmp/npm-import
```

O relatório põe cada domínio em um balde: `+` criado, `=` já existia, `!` criado com aviso, `-`
pulado (com motivo). Sai com código ≠ 0 se algo foi pulado. É idempotente: corrija e rode de novo.

- Um host do NPM com N domínios vira N proxy hosts (o apy-gateway é 1 domínio → 1 upstream).
- O certificado é enviado **uma vez** e associado a todos os domínios dele — o corte não depende de
  emitir nada no Let's Encrypt.
- Valores vindos do NPM passam pela mesma validação da API (hostname, upstream, porta).

### 6.3 Conferir

```sh
grep '^  +' import.txt > dominios.txt
./deploy/swarm/compare-with-npm.sh <ip-de-um-nó> dominios.txt   # rode em mais de um nó
```

Para cada domínio compara, no mesmo nó, NPM (80/443) x borda (8080/8443): status HTTP, destino do
redirect e certificado. Espere `DIFF` só onde o import avisou (ex.: host com certificado mas sem
"Force SSL" no NPM — o apy-gateway redireciona). Investigue qualquer outro antes do corte.

### 6.4 Hosts pulados

Ficam no NPM. Como o NPM perde 80/443 no corte (§7), decida antes da janela: resolver (proteção no
app + `--include-unsupported`), ou adiar o corte desses domínios — nesse caso o NPM continua
dono de 80/443 e o corte espera.

## 7. Fase 4 — Corte (janela de ~5 minutos, indisponibilidade de 30–60 s)

```sh
# 1. NPM solta 80/443 (continua rodando, com o painel na 81, pronto para rollback). NÃO use
#    --detach: o comando precisa terminar — enquanto o Swarm não tira as portas do routing mesh,
#    as regras do ingress continuam capturando 80/443 em todos os nós e escondem a borda.
docker service update --publish-rm 80 --publish-rm 443 <stack-do-npm>_app

# 2. Borda assume 80/443 em todos os nós de uma vez (EDGE_UPDATE_PARALLELISM=0: o NPM já soltou as
#    portas, ir nó a nó só prolongaria a janela dos últimos nós)
sed -i 's/^EDGE_HTTP_PORT=.*/EDGE_HTTP_PORT=80/; s/^EDGE_HTTPS_PORT=.*/EDGE_HTTPS_PORT=443/' deploy/swarm/.env
EDGE_UPDATE_PARALLELISM=0 ./deploy/swarm/deploy.sh
watch "docker service inspect apy-gateway_edge --format '{{.UpdateStatus.State}}'"   # até "completed"

# 3. Verificar (de fora do cluster)
for d in $(cat dominios.txt); do printf '%s ' $d; curl -s -o /dev/null -w '%{http_code}\n' https://$d/; done
```

**Indisponibilidade esperada: 30–60 s para todos os domínios.** Em duas rodadas do ensaio (4 nós)
o NPM levou 12–13 s para soltar 80/443 e a borda respondia em 80 e 443 nos 4 nós aos 31 s e 60 s
(a diferença é o tempo de cada nó subir o container novo). Rode os passos 1 e 2 em
sequência, sem pausa; faça na janela de menor tráfego. O `UpdateStatus` só vira `completed` ~60 s
depois (período de `monitor` do rollback automático) — o tráfego já está sendo servido antes disso.

### Rollback (a qualquer momento nas primeiras semanas)

```sh
sed -i 's/^EDGE_HTTP_PORT=.*/EDGE_HTTP_PORT=8080/; s/^EDGE_HTTPS_PORT=.*/EDGE_HTTPS_PORT=8443/' deploy/swarm/.env
EDGE_UPDATE_PARALLELISM=0 ./deploy/swarm/deploy.sh
# espere UpdateStatus "completed" (portas 80/443 livres em todos os nós), então:
docker service update --publish-add 80:80 --publish-add 443:443 <stack-do-npm>_app
```

O NPM não foi alterado em nada além das portas — volta exatamente como estava. **Não remova a
stack do NPM nem os volumes dele** antes de algumas semanas estáveis.

## 8. Fase 5 — Depois do corte

1. **Certificados para ACME (renovação automática).** Os importados são `uploaded` — não renovam
   sozinhos. Com o DNS já apontando para a borda, peça emissão por domínio (ou grupo SAN), em
   lotes (limites do Let's Encrypt: 50 certificados por domínio registrado por semana):
   `POST /api/certificates/request-acme {"domain_names": ["app.ex.com", "www.app.ex.com"]}`.
   Ao ficar `valid`, o certificado é associado aos hosts automaticamente. Faça isso **bem antes**
   do vencimento dos importados (`GET /api/certificates` mostra `expires_at`).
2. Desligar o NPM (`docker stack rm <npm>`) depois do período de observação; guardar um backup dos
   volumes dele.
3. Configurar backup do núcleo (§11) e monitoração (§10) **antes** de desligar o NPM.

## 9. Atualizações de versão

```sh
vi deploy/swarm/.env              # APY_GATEWAY_VERSION=v0.3.0
./deploy/swarm/deploy.sh          # aqui sim um nó por vez (padrão), os outros seguem servindo
```

`migrate` roda de novo (imagem nova); a borda atualiza um nó por vez, com rollback automático se a
task nova não ficar healthy em 60s. Configs (`configs/*.sh`) são imutáveis no Swarm: ao alterar,
incremente o sufixo `_vN` do nome na stack.

## 10. Operação e monitoração

- `docker stack services apy-gateway` — todos `N/N`; `edge` = número de nós com label.
- `GET /api/replicas` (ou a tela de réplicas) — cada nó `online`, `synced_domains_count` igual ao
  número de hosts ativos. Uma réplica com contagem menor está com domínio falhando: veja
  `docker service logs apy-gateway_edge | grep -i error`.
- Logs com rotação (`json-file`, 3×10 MB por container) — o volume de acesso de 50 domínios não
  enche o disco.
- O que acontece quando o **núcleo cai**: as bordas **continuam servindo** a última configuração
  (ensaiado). Mudanças ficam represadas até ele voltar. Uma borda que **reiniciar** com o núcleo
  fora **não sobe** (nasce sem estado) — por isso o núcleo precisa de backup e de um procedimento
  de troca de nó testado (§11).

## 11. Backup e troca do nó do núcleo

```sh
# MySQL (no nó do núcleo) — diário, fora do nó
docker exec $(docker ps -q -f name=apy-gateway_mysql) sh -c \
  'mysqldump -uroot -p"$(cat /run/secrets/apy_gateway_db_root_password)" --single-transaction apy_gateway' \
  | gzip > apy-gateway-$(date +%F).sql.gz

# MinIO (certificados + chave da conta ACME) — copie o volume apy-gateway_minio-data
docker run --rm -v apy-gateway_minio-data:/data -v "$PWD":/backup alpine \
  tar -czf /backup/minio-$(date +%F).tgz -C /data .
```

Trocar o nó do núcleo (manutenção planejada): `docker service scale` dos serviços do núcleo para 0,
copiar os volumes `apy-gateway_mysql-data`, `apy-gateway_redis-data`, `apy-gateway_minio-data`
para o nó novo, mover o label `apy-gateway.core` e fazer o deploy de novo (SPEC.md §13.2).

**Usuário do agent**: o one-shot `db-grants` (`configs/db-grants.sh`) cria/atualiza o
`apy_agent` com `SELECT` só nas 4 tabelas que o agent lê, a cada deploy — inclusive num MySQL que
já tinha dados, e ressincronizando a senha se o secret for rotacionado.

## 12. Riscos conhecidos

| Risco | Mitigação |
|---|---|
| Núcleo é ponto único de falha para **mudanças** e para **bordas reiniciando** | Backup diário; nó do núcleo estável; troca de nó documentada (§11). HA do núcleo está fora do MVP (SPEC.md §16). |
| DNS apontando para 1 nó | Igual ao NPM hoje. Para eliminar: round-robin entre nós ou IP flutuante (keepalived) — a borda já roda em todos. |
| Certificados importados não renovam | Fase 5, item 1, com folga antes do vencimento. |
| Overlay cifrada exige ESP entre nós | Liberar IP proto 50; se a rede não permitir, remova `encrypted` da rede `core` e trate a rede do cluster como confiável (decisão consciente). |
| `scheduler` com 1 réplica | Intencional — evita jobs duplicados. Se o nó cair, renovações esperam ele voltar. |
| Recursos do NPM não suportados | Import pula/avisa; `compare-with-npm.sh` confirma antes do corte. |

## Ensaio local do plano

Sem VMs: 4 containers docker-in-docker formam um Swarm de 3 managers + 1 worker (~1,5 GB de RAM).

```sh
docker compose -f examples/docker-compose.yml --env-file .env build   # imagens do projeto
./deploy/swarm/simulation/cluster.sh up
./deploy/swarm/simulation/rehearsal.sh
./deploy/swarm/simulation/cluster.sh down
```

O ensaio sobe um NPM real com 4 hosts (multi-domínio, TLS próprio, access list, desabilitado) na
rede overlay `proxy`, executa as Fases 2–4 deste documento com a stack de produção sem alterações
(só um Mailpit no lugar do SMTP), e roda drills: domínio novo propagando aos 4 nós, heartbeats das
4 réplicas, e o nó do núcleo fora do ar com as bordas continuando a servir.
