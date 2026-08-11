-- Seed data for the Fase 5 end-to-end verification (see README.md in this directory).
-- Run against a database that already has the real Laravel migrations applied
-- (php artisan migrate against apps/control-plane) — never a hand-copied schema.

INSERT INTO ssl_certificates (type, primary_domain, domain_names, provider, status, storage_path, version, created_at, updated_at)
VALUES ('uploaded', 'secure.test.local', '["secure.test.local"]', 'manual', 'valid', 'certs/secure-test-local', 1, NOW(), NOW());

-- Plain HTTP proxy host, no certificate.
INSERT INTO proxy_hosts (domain, forward_scheme, forward_host, forward_port, websockets_enabled, enabled, version, created_at, updated_at)
VALUES ('plain.test.local', 'http', 'apy-fase5-backend', 80, 0, 1, 1, NOW(), NOW());

-- HTTPS proxy host — storage_path above must have matching `<path>.crt`/`<path>.key`
-- objects uploaded to MinIO before the agent boots (see README.md).
INSERT INTO proxy_hosts (domain, forward_scheme, forward_host, forward_port, websockets_enabled, ssl_certificate_id, enabled, version, created_at, updated_at)
VALUES ('secure.test.local', 'http', 'apy-fase5-backend', 80, 0, 1, 1, 1, NOW(), NOW());

-- Disabled — must never be fetched by the agent's `WHERE enabled = 1` query, and must
-- never get a rendered conf.d file.
INSERT INTO proxy_hosts (domain, forward_scheme, forward_host, forward_port, websockets_enabled, enabled, version, created_at, updated_at)
VALUES ('disabled.test.local', 'http', 'apy-fase5-backend', 80, 0, 0, 1, NOW(), NOW());

-- References a certificate row with no storage_path yet (the only reality possible before
-- Fase 7 exists) — must surface as an isolated per-domain sync failure, never a crash.
INSERT INTO ssl_certificates (type, primary_domain, domain_names, provider, status, storage_path, version, created_at, updated_at)
VALUES ('uploaded', 'nostorage.test.local', '["nostorage.test.local"]', 'manual', 'pending', NULL, 1, NOW(), NOW());

INSERT INTO proxy_hosts (domain, forward_scheme, forward_host, forward_port, websockets_enabled, ssl_certificate_id, enabled, version, created_at, updated_at)
VALUES ('nostorage.test.local', 'http', 'apy-fase5-backend', 80, 0, 2, 1, 1, NOW(), NOW());
