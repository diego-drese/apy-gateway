# Fase 5 + Fase 6 end-to-end verification

Proves the agent's bootstrap/sync flow (SPEC.md §9.1 steps 1–6, Fase 5) and real-time
sync/reconciliation/heartbeat (SPEC.md §9.2, Fase 6) against real MySQL, MinIO, Redis, and the
actual multi-stage `nginx/Dockerfile` image — not mocks. This is a manual verification harness
for these phases, not part of the dev environment (`docker compose up` for local dev is Fase
10's job).

Run every command from the repo root.

## 1. Start MySQL and MinIO, apply the real Laravel schema

```bash
docker compose -f agent/tests/integration/docker-compose.yml up -d mysql minio backend
```

Wait for both to report healthy (`docker compose -f agent/tests/integration/docker-compose.yml ps`),
then apply the **real** Laravel migrations — never a hand-copied schema, to guarantee the agent
reads exactly what `apps/control-plane` actually produces:

```bash
docker run --rm --network apy-gateway-integration-test_default \
  -v "$(pwd)":/app -w /app/apps/control-plane \
  -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 \
  -e DB_DATABASE=apy_gateway -e DB_USERNAME=root -e DB_PASSWORD=root \
  php:8.3-cli-alpine sh -c "docker-php-ext-install pdo_mysql >/dev/null 2>&1 && php artisan migrate --force"
```

(Network name depends on the compose project directory name — check with `docker network ls`
if the above doesn't match.)

## 2. Seed test data

```bash
docker compose -f agent/tests/integration/docker-compose.yml exec -T mysql \
  mysql -uroot -proot apy_gateway < agent/tests/integration/seed.sql
```

## 3. Upload a test certificate to MinIO

`seed.sql` references `storage_path = 'certs/secure-test-local'` for `secure.test.local` — the
agent's placeholder convention (see `agent/src/minio.rs`) expects `<storage_path>.crt` and
`<storage_path>.key` objects in the bucket:

```bash
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /tmp/test-privkey.pem -out /tmp/test-fullchain.pem -days 1 -subj "/CN=secure.test.local"

docker run --rm --network apy-gateway-integration-test_default -v /tmp:/certs --entrypoint sh minio/mc -c "
mc alias set testminio http://minio:9000 minioadmin minioadmin &&
mc mb testminio/apy-gateway-certs &&
mc cp /certs/test-fullchain.pem testminio/apy-gateway-certs/certs/secure-test-local.crt &&
mc cp /certs/test-privkey.pem testminio/apy-gateway-certs/certs/secure-test-local.key
"
```

## 4. Build and start the agent

```bash
docker compose -f agent/tests/integration/docker-compose.yml up -d --build agent
```

## 5. Verify

- **Healthcheck only passes once Nginx is actually serving**:
  `docker inspect --format='{{.State.Health.Status}}' <agent-container>` — should read
  `starting` immediately after boot, `healthy` only once port 80 is actually accepting requests.
- **Plain HTTP proxy**: `curl -H "Host: plain.test.local" http://localhost:18080/` → `200`.
- **HTTPS via the downloaded certificate**: `curl -H "Host: secure.test.local" http://localhost:18080/`
  → `301` (redirect); `curl -k -H "Host: secure.test.local" https://localhost:18443/` → `200`.
- **Unknown host**: `curl http://localhost:18080/` (no Host override) → connection closed
  (nginx `return 444;` catch-all — curl reports this as "empty reply from server").
- **`disabled.test.local` and `nostorage.test.local`** never appear in
  `docker exec <agent-container> ls /etc/nginx/conf.d/` — the first because it's outside
  `WHERE enabled = 1`, the second because `sync.rs` logs it as a failed domain
  (`docker logs <agent-container> | grep "failed to sync"`) and skips it without touching
  `conf.d` or `state.json`.
- **No-op reboot**: `docker restart <agent-container>`, then
  `docker logs <agent-container> | grep "bootstrap sync complete"` — the second occurrence
  should read `applied=0 removed=0` (state.json already matches the DB, nothing re-rendered).
- **Per-domain failure isolation**: insert a proxy host with a config-breaking value, e.g.
  ```sql
  INSERT INTO proxy_hosts (domain, forward_scheme, forward_host, forward_port, enabled, version, created_at, updated_at)
  VALUES ('broken.test.local', 'http', '10.0.0.5; evil_directive', 80, 1, 1, NOW(), NOW());
  ```
  then `docker restart <agent-container>` and confirm: the log shows
  `nginx -t rejected config for domain broken.test.local; reverted`; `plain.test.local` and
  `secure.test.local` keep responding `200` throughout; `broken.test.local.conf` never appears
  in `conf.d/`; `state.json` has no entry for it (so it retries forever until fixed); the
  container's health status stays `healthy` the whole time.

## 6. Verify real-time sync + reconciliation + heartbeat (Fase 6)

The compose file already sets short intervals for this (`HEARTBEAT_INTERVAL_SECS=5`,
`RECONCILIATION_INTERVAL_SECS=15`) so these scenarios don't require long waits.

- **Heartbeat**: `docker exec <redis-container> redis-cli GET apy-gateway:replicas:integration-test-replica:heartbeat`
  returns a Unix timestamp; `TTL` on the same key is close to 15; wait 5s and re-check — the
  value advances and the TTL refreshes, on a connection independent of the pub/sub one.
- **Live incremental update, no restart**: note `docker inspect --format='{{.State.StartedAt}}' <agent-container>`,
  then `UPDATE proxy_hosts SET forward_port=..., version=version+1 WHERE domain=...` directly in
  MySQL and immediately `redis-cli PUBLISH apy-gateway:events '{"id":"...","type":"proxy_host.updated","subject_id":<id>,"version":<new version>,"occurred_at":"..."}'`.
  Confirm within ~1s: `docker logs <agent-container> | grep "incremental sync applied changes"`,
  the `conf.d/<domain>.conf` file reflects the new value, and `StartedAt` is unchanged (no
  restart happened — Nginx workers just reload).
- **Live incremental deletion**: `PUBLISH` a `proxy_host.deleted` event for a domain currently
  synced (note: this only removes the agent's local file/state — if the row still exists and is
  still `enabled=1` in MySQL, the next periodic reconciliation will legitimately recreate it,
  since periodic reconciliation is the ultimate source of truth; this is correct, not a bug —
  actually delete or disable the row first if you want the removal to stick).
- **Idempotency**: publish the exact same event payload (same `id`, same `version`) a second
  time — `docker logs` should show no second `"incremental sync applied changes"` line for it
  (state already matches, true no-op).
- **Malformed payloads don't crash the loop**: `redis-cli PUBLISH apy-gateway:events 'not json'`
  and `redis-cli PUBLISH apy-gateway:events '{"id":"x"}'` (missing fields) — both should produce
  a `"skipping malformed domain event payload"` warning in the logs, and the container stays
  `healthy`; a subsequent valid event still gets processed normally right after.
- **Disabling (not deleting) a host removes it immediately**: `UPDATE proxy_hosts SET enabled=0
  WHERE domain=...` then `PUBLISH` a `proxy_host.updated` event for its id — the domain's
  `conf.d` file should disappear immediately (doesn't wait for the periodic pass), because the
  by-id query no longer finds it and the incremental handler falls back to the same
  reverse-lookup-and-remove path used for `proxy_host.deleted`.
- **Redis outage resilience**: `docker stop <redis-container>` — confirm the agent stays
  `healthy`, `docker logs` shows `"redis subscribe connection lost, retrying"` with growing
  backoff and periodic `"failed to write heartbeat"` warnings, but nothing crashes and Nginx
  keeps serving already-synced domains. While Redis is down, make a DB change that would
  normally need an event. Restart Redis (`docker compose ... up -d redis` or re-run the
  container) — confirm `"subscribed to redis event bus"` reappears in the logs (reconnected),
  heartbeat resumes advancing, and the DB change made during the outage gets picked up by the
  next periodic reconciliation tick without ever needing its event replayed.

## Teardown

```bash
docker compose -f agent/tests/integration/docker-compose.yml down -v
```

## What this already proved (2026-08-10 manual run)

**Fase 5**: all of the §9.1 scenarios above passed against real containers, including: the
`rustls` `CryptoProvider` gotcha noted in the implementation plan did **not** manifest at
runtime (no panic downloading the real certificate from MinIO or connecting to MySQL) — verified
live, not assumed from `cargo check` alone.

**Fase 6**: all of the §9.2 scenarios above passed against real containers (MySQL, MinIO, Redis,
the built `apy-gateway-nginx` image) — including a genuine Redis outage/reconnect cycle with a
DB change made while Redis was down, correctly picked up by periodic reconciliation without any
event ever being published for it. Not verified in this pass: `certificate.issued/renewed/revoked`
event handling specifically — it reuses the exact same `sync_hosts` function already exercised
twice by the `proxy_host.*` scenarios above, and the underlying MinIO download path was already
proven working in the Fase 5 run; only the new `fetch_active_proxy_hosts_by_certificate_id` query
itself (a near-identical variant of the already-tested by-id query) is unverified against a real
database. Worth a quick manual check before relying on it in production, but low risk.
