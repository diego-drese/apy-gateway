# Fase 10 end-to-end verification — opt-in log forwarding + replica metrics

Proves both halves of Fase 10 (SPEC.md §12) against real infrastructure — not mocks: Nginx
actually speaking syslog UDP to a real rsyslog receiver (with real `imudp`/`input()` config, which
rsyslog doesn't enable by default), and the full Redis→`replicas:sync-metrics`→MySQL→
`GET /api/replicas` round trip, including the Offline flip once a replica's heartbeat TTL really
expires. Run every command from the repo root.

## 1. Start core infra + rsyslog receiver

```bash
docker compose -f docker/observability-verification/docker-compose.yml up -d mysql redis minio backend rsyslog
```

Wait for healthy (`docker compose -f docker/observability-verification/docker-compose.yml ps`),
then create the MinIO bucket (nothing auto-creates it):

```bash
docker run --rm --network observability-verification_default --entrypoint sh minio/mc -c "
mc alias set testminio http://minio:9000 minioadmin minioadmin &&
mc mb testminio/apy-gateway-certs
"
```

(Network name depends on the compose project directory name — check with `docker network ls` if
this doesn't match.)

## 2. Start control-plane, then the agent (with log forwarding on)

```bash
docker compose -f docker/observability-verification/docker-compose.yml up -d --build control-plane
# wait for control-plane healthy before starting the agent — it needs the schema migrated first
docker compose -f docker/observability-verification/docker-compose.yml up -d --build agent
```

The `agent` service already sets `LOG_FORWARDING_ENABLED=true`, `CENTRAL_LOG_SERVER_HOST=rsyslog`,
`CENTRAL_LOG_SERVER_PORT=514`.

## 3. Seed an allowlisted user, API token, and a proxy host

```bash
docker compose -f docker/observability-verification/docker-compose.yml exec control-plane php artisan tinker --execute="
use App\Models\User; use App\Models\IpAllowlistEntry; use App\Models\ProxyHost;
IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
\$user = User::factory()->create(['email' => 'admin@observability-verification.test']);
\$token = \$user->createToken('observability-verification')->plainTextToken;
ProxyHost::factory()->create(['domain' => 'obs-test.local', 'forward_scheme' => 'http', 'forward_host' => 'backend', 'forward_port' => 80, 'ssl_certificate_id' => null, 'enabled' => true]);
file_put_contents('/tmp/token.txt', \$token);
echo \$token, PHP_EOL;
"
```

(`ssl_certificate_id` explicitly `null` — see `docker/acme-verification/README.md`'s "what this
proved" for why `ProxyHost::factory()`'s own default association breaks the whole domain's sync.)

Restart the agent so it picks up the new host (or wait for the periodic reconciliation tick):

```bash
docker compose -f docker/observability-verification/docker-compose.yml restart agent
```

## 4. Generate real traffic and confirm it lands in the real rsyslog receiver

```bash
for i in 1 2 3; do curl -s -o /dev/null -H "Host: obs-test.local" http://localhost:18080/; done
docker compose -f docker/observability-verification/docker-compose.yml exec rsyslog cat /var/log/messages
```

Expect real access-log lines tagged `apy_gateway_nginx`, with the actual request method/status.

## 5. Confirm both `logging.conf` variants pass `nginx -t` in the real image

```bash
docker compose -f docker/observability-verification/docker-compose.yml exec agent cat /etc/nginx/snippets/logging.conf
docker compose -f docker/observability-verification/docker-compose.yml exec agent nginx -t
```

## 6. Confirm the real heartbeat JSON shape

```bash
docker compose -f docker/observability-verification/docker-compose.yml exec redis \
  redis-cli GET apy-gateway:replicas:observability-verification-replica:heartbeat
```

## 7. Run the metrics command and confirm `GET /api/replicas`

```bash
docker compose -f docker/observability-verification/docker-compose.yml exec control-plane php artisan replicas:sync-metrics

TOKEN=$(docker compose -f docker/observability-verification/docker-compose.yml exec -T control-plane cat /tmp/token.txt)
docker compose -f docker/observability-verification/docker-compose.yml exec control-plane curl -s \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/replicas
```

Expect one row, `status: "online"`, real `agent_version`/`nginx_version`/`ip_address`, and
`synced_domains_count` matching the number of domains actually applied.

## 8. Confirm the Offline flip after the heartbeat TTL really expires

```bash
docker compose -f docker/observability-verification/docker-compose.yml stop agent
sleep 25   # HEARTBEAT_TTL_SECS=20 in this harness — give Redis time to actually expire the key
docker compose -f docker/observability-verification/docker-compose.yml exec control-plane php artisan replicas:sync-metrics
docker compose -f docker/observability-verification/docker-compose.yml exec control-plane curl -s \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/replicas
```

Expect `status: "offline"`.

## Teardown

```bash
docker compose -f docker/observability-verification/docker-compose.yml down -v
```

## What this proved (2026-08-11 manual run)

All scenarios above passed against real infrastructure — real rsyslog (with `imudp`/`input()`
explicitly configured, confirmed via a standalone container before wiring it into this compose
file at all: rsyslog listens on nothing without them), the real `apy-gateway-nginx` image with
`LOG_FORWARDING_ENABLED=true`, the real control-plane app, and a real heartbeat TTL actually
expiring in Redis (not simulated/backdated).

**Log forwarding**: real access-log lines (`GET / HTTP/1.1 200 ...`, tagged `apy_gateway_nginx`)
landed in the rsyslog receiver's `/var/log/messages` after 3 real `curl` requests through the
proxied domain. `nginx -t` accepted the real agent-rendered `logging.conf` in the actual image.

**Two real bugs found and fixed during this verification, neither hypothetical**:
1. Nginx's `syslog:` sink **resolves the target hostname at `nginx -t`/startup time**, not lazily
   on first log write — an unresolvable `CENTRAL_LOG_SERVER_HOST` doesn't just silently drop logs,
   it makes the **entire Nginx config fail validation**, so Nginx never starts at all (confirmed
   directly: `docker run ... nginx -t` against a config pointing at a genuinely unresolvable
   hostname failed with `host not found in syslog server`). This turns what's meant to be a
   "plus"/opt-in observability feature into a real availability risk if the Central Log Server's
   DNS name is ever briefly unresolvable exactly when a replica boots or reloads. Documented as an
   explicit open risk in SPEC.md §16 — operators should point `CENTRAL_LOG_SERVER_HOST` at
   something reliably resolvable (a stable internal DNS name or a literal IP), and this harness's
   own successful run only worked because `rsyslog` (the compose service) was already up and
   resolvable before the agent ever started (`agent` waits on nothing that guarantees this in
   general — it's incidental here, not something the code enforces).
2. Nginx's syslog `tag=` parameter **only allows alphanumeric characters and underscore** — the
   first draft of `logging_config.rs` used `apy-gateway-nginx` (hyphens, matching every other tag
   used elsewhere in this project), which `nginx -t` rejected outright
   (`syslog "tag" only allows alphanumeric characters and underscore`). Fixed to
   `apy_gateway_nginx`.

**Metrics**: the real heartbeat JSON (`{"ts":...,"ip":"172.19.0.8","agent_version":"0.1.0",
"nginx_version":"1.27.5","synced_domains_count":1}`) flowed correctly through
`replicas:sync-metrics` into `replica_agents` and out through `GET /api/replicas` — `status:
"online"`, correct `synced_domains_count`. Stopping the agent and polling Redis until the
`HEARTBEAT_TTL_SECS=20` key genuinely expired (not assumed/timed), then rerunning
`replicas:sync-metrics`, correctly flipped the row to `status: "offline"` — proving the
TTL-as-liveness-source-of-truth design actually works, not just the happy path.
