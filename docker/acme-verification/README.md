# Fase 9 end-to-end verification — ACME automatic issuance against Pebble

Proves the full HTTP-01 issuance loop (SPEC.md §11) against a **real** ACME v2 server — Pebble
(`letsencrypt/pebble`, the same protocol family as Boulder/Let's Encrypt) — doing **real** domain
validation over the compose network against the actual `apy-gateway-nginx` image, not mocks. This
is the first harness in the repo that runs the real `apps/control-plane` app + a queue worker
(Fase 9's issuance job lives entirely in Laravel), unlike `agent/tests/integration/` which only
ever needed a throwaway migrate container. Run every command from the repo root.

## 1. Start core infrastructure + Pebble

```bash
docker compose -f docker/acme-verification/docker-compose.yml up -d mysql redis minio backend pebble
```

Wait for healthy (`docker compose -f docker/acme-verification/docker-compose.yml ps`), then create
the MinIO bucket (nothing auto-creates it — same as every other harness in this repo):

```bash
docker run --rm --network apy-gateway-acme-verification_default --entrypoint sh minio/mc -c "
mc alias set testminio http://minio:9000 minioadmin minioadmin &&
mc mb testminio/apy-gateway-certs
"
```

(Network name depends on the compose project directory name — check with `docker network ls` if
this doesn't match.)

## 2. Start the agent, control-plane, and queue worker

```bash
docker compose -f docker/acme-verification/docker-compose.yml up -d --build agent control-plane queue-worker
```

`control-plane`/`queue-worker` install `pdo_mysql` and trust `pebble/pebble.minica.pem` (the real
static root that signs Pebble's own HTTPS directory endpoint — confirmed empirically by comparing
its `openssl x509 -noout -subject` against Pebble's live TLS certificate, not assumed from docs)
into the container's system CA store on every boot — this is an **additive** trust, never a
"disable TLS verification" flag, and only ever applies inside this throwaway verification
container, never production code. Wait for `control-plane` to report `healthy`
(`GET /up`) before continuing — first boot also runs `php artisan migrate --force` for real,
including the new `acme_challenges` table.

## 3. Seed an allowlisted user, API token, and the proxy host

`RequestCertificateIssuanceAction` requires the domain to already exist as an active proxy host
(SPEC.md §11 — otherwise there's nowhere for the agent to serve the challenge from), so seed that
first, from inside the container so the IP allowlist check sees `127.0.0.1`:

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec control-plane php artisan tinker --execute="
use App\Models\User; use App\Models\IpAllowlistEntry; use App\Models\ProxyHost;
IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
\$user = User::factory()->create(['email' => 'admin@acme-verification.test']);
\$token = \$user->createToken('acme-verification')->plainTextToken;
// ssl_certificate_id explicitly null — ProxyHostFactory's own default associates an unrelated
// cert with a storage_path that doesn't exist in MinIO, which makes the agent fail to sync the
// whole domain (see "What this proved" below).
ProxyHost::factory()->create(['domain' => 'acme-test.local', 'forward_scheme' => 'http', 'forward_host' => 'backend', 'forward_port' => 80, 'ssl_certificate_id' => null, 'enabled' => true]);
file_put_contents('/tmp/token.txt', \$token);
echo \$token, PHP_EOL;
"
```

Note the printed token (or `docker compose ... exec control-plane cat /tmp/token.txt` later).

## 4. Request the certificate

All requests run **inside** the `control-plane` container (loopback `127.0.0.1`, matching the
seeded allowlist entry — hitting the host-mapped `18000` port instead would show up as the Docker
bridge gateway IP, which isn't allowlisted).

```bash
TOKEN=$(docker compose -f docker/acme-verification/docker-compose.yml exec -T control-plane cat /tmp/token.txt)

docker compose -f docker/acme-verification/docker-compose.yml exec control-plane curl -s \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -X POST http://localhost:8000/api/certificates/request-acme \
  -d '{"domain_names":["acme-test.local"]}'
```

Expect `202` with `"status":"pending"`. Note the returned `data.id`.

## 5. Poll until issued

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec control-plane curl -s \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/certificates/<id>
```

Repeat every second or two. **Record the actual observed wall-clock time** from request to
`"status":"valid"` — this is the first real measurement of whether the configured
`ACME_CHALLENGE_PROPAGATION_DELAY_SECONDS`/`ACME_POLL_INTERVAL_SECONDS` budget is sane; don't
assume the defaults are right until this has actually run. Watch
`docker compose -f docker/acme-verification/docker-compose.yml logs -f queue-worker` in parallel
for `IssueAcmeCertificateJob` progress/failures.

## 6. Verify the issued certificate is real, not a stub

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec agent \
  cat /var/lib/apy-agent/certs/acme-test.local/fullchain.pem | \
  docker compose -f docker/acme-verification/docker-compose.yml exec -T control-plane openssl x509 -noout -issuer -subject
```

Issuer should read `Pebble Intermediate CA ...` — a real ACME-issued chain, not a self-signed
placeholder. Then confirm Nginx is actually serving it:

```bash
curl -k -H "Host: acme-test.local" https://localhost:18443/   # 200
curl -H "Host: acme-test.local" http://localhost:18080/       # 301 redirect to https
```

## 7. Verify challenge cleanup

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec mysql \
  mysql -uroot -proot apy_gateway -e "SELECT status FROM acme_challenges;"
docker compose -f docker/acme-verification/docker-compose.yml exec agent \
  ls /var/lib/apy-agent/acme-challenges/
```

Challenge row(s) should read `valid`; the directory should be **empty** — proves
`acme_challenges::sync_challenge_files` actually removes resolved challenges, not just writes
them.

## 8. Verify the fail-fast domain constraint

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec control-plane curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -X POST http://localhost:8000/api/certificates/request-acme \
  -d '{"domain_names":["no-such-proxy-host.test"]}'
```

Expect `422`, immediately (no wait) — and confirm `docker compose ... logs pebble` shows **zero**
new log lines from this request, proving the validation never reaches the ACME server at all.

## 9. Verify renewal

Backdate the issued certificate's `expires_at` directly in MySQL (same direct-SQL-mutation style
`agent/tests/integration/README.md` already uses), then run the scheduled command manually:

```bash
docker compose -f docker/acme-verification/docker-compose.yml exec mysql \
  mysql -uroot -proot apy_gateway -e "UPDATE ssl_certificates SET expires_at = NOW() + INTERVAL 5 DAY WHERE primary_domain = 'acme-test.local';"

docker compose -f docker/acme-verification/docker-compose.yml exec control-plane \
  php artisan certificates:check-expiring
```

Poll `GET /api/certificates/<id>` again: `version` should be one higher than after step 5,
`content_hash` different, and `docker compose ... exec mysql mysql ... -e "SELECT type FROM domain_events ORDER BY id DESC LIMIT 3;"`
should show `certificate.renewed` (not `certificate.issued`). Confirm the agent picked up the new
certificate the same way step 6 did.

## Teardown

```bash
docker compose -f docker/acme-verification/docker-compose.yml down -v
```

## What this proved (2026-08-11 manual run)

All scenarios in steps 1–9 passed against a real Pebble instance, the real `apy-gateway-nginx`
image, and the real control-plane app + queue worker — not mocks, including a final from-scratch
run (`docker compose down -v` then a clean `up`) with zero manual intervention beyond the README's
own steps, confirming the harness is actually reproducible and not just something that happened to
work after ad-hoc fixes. **Observed latency**: first issuance took ~6–7s end to end across two
separate clean runs (well inside the default 3s propagation delay + poll budget); renewal
(triggered manually via `certificates:check-expiring`) resolved in ~1s. The configured defaults
(`ACME_CHALLENGE_PROPAGATION_DELAY_SECONDS=3`, `ACME_POLL_INTERVAL_SECONDS=2`,
`ACME_POLL_MAX_ATTEMPTS=10`) were never close to exhausted — no tuning needed.

**Four real, empirically-confirmed incompatibilities between `rogierw/rw-acme-client` and a
strict RFC 8555 server (Pebble) were found and fixed, all in `PerformAcmeCertificateIssuanceAction`
/ `AcmeClientFactory` / `PebbleCompatibleHttpClient` — never by patching vendor code**:

1. `AccountData::fromResponse()` reads `$body['createdAt']` with no fallback; Pebble's account
   responses don't include that field (RFC 8555 doesn't require it). Fixed by
   `PebbleCompatibleHttpClient`, which backfills a value only when the field is missing —
   harmless against real Let's Encrypt too, which always sends it (left untouched there).
2. `DomainValidation::start()` sends the legacy `keyAuthorization` field in the challenge-response
   POST body. RFC 8555 §7.5.1 requires an empty `{}` body — real Let's Encrypt (Boulder) silently
   ignores the extra legacy field, but Pebble correctly rejects it as malformed, and the library's
   own `KeyId::generate()` JWS helper can't even produce a literal `"{}"` payload (empty/falsy
   payloads always encode to `""`). Fixed by hand-signing the challenge-trigger POST in
   `PerformAcmeCertificateIssuanceAction::triggerHttpChallenge()`, using only the library's public
   `Base64`/`nonce()`/`localAccount()` primitives.
3. `Directory::getOrder()` derives the per-order status URL via
   `str_replace('new-order', 'order', $newOrderUrl)` — a Let's Encrypt/Boulder-specific path
   convention, not part of RFC 8555. Pebble deliberately uses unrelated path names (`/order-plz`),
   so the string-replace matches nothing and the synthesized URL 404s. Fixed by using
   `OrderData::url` (the real URL, correctly extracted from the order-creation response's
   `Location` header) directly instead of `Order::get($id)`.
4. Order-status refresh must be a signed POST-as-GET (RFC 8555 §6.3), not a plain GET — Pebble
   correctly enforces this (405 on a plain GET) where a more lenient server might not. Fixed in
   the same `refreshOrder()` helper, signing via the library's public `KeyId::generate()`.

**One harness-infrastructure bug, unrelated to the library**: `php artisan serve` does not pass
the container's environment variables through to the PHP built-in server subprocess it spawns
(confirmed via `/proc/<pid>/environ` — the parent process has `DB_CONNECTION=mysql` etc., the
spawned `php -S` child only has `APP_ENV`). The harness now runs `php -S 0.0.0.0:8000 -t public`
directly instead, which inherits the full environment normally. Not a Fase 9 bug — would affect
any Docker-based Laravel dev setup relying on env-var config with `artisan serve`.

**One real edge case in `PerformAcmeCertificateIssuanceAction` itself, found via this harness and
fixed**: the local ACME account key can exist in MinIO without the ACME server actually knowing
about it (a prior attempt generated+persisted the key but failed before the registration POST
completed) — `account()->get()` then genuinely errors. `resolveAccount()` now falls back to
`create()` in that case, which is safe/idempotent per RFC 8555 §7.3.

**Test harness pitfall, not a product bug**: seeding the test proxy host via
`ProxyHost::factory()->create([...])` without explicitly overriding `ssl_certificate_id` leaves
the factory's own default (an unrelated cert with a `storage_path` pointing at nothing in MinIO)
attached — the agent then fails to sync *the whole domain*, including its plain-HTTP `:80` block,
leaving nothing for Pebble to validate against. The seed step in this README now sets no
`ssl_certificate_id` at all. Worth remembering for any future harness that reuses this factory.
