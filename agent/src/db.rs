use anyhow::Context;
use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions};
use sqlx::MySqlPool;

use crate::config::DbConfig;
use crate::models::{AcmeChallengeRow, ActiveProxyHost, ProxyHostRow};

pub async fn connect(cfg: &DbConfig) -> anyhow::Result<MySqlPool> {
    let mut opts = MySqlConnectOptions::new()
        .host(&cfg.host)
        .port(cfg.port)
        .username(&cfg.username)
        .password(&cfg.password)
        .database(&cfg.database)
        .ssl_mode(cfg.ssl_mode);

    if let Some(ca) = &cfg.ssl_ca_path {
        opts = opts.ssl_ca(ca);
    }
    if let (Some(cert), Some(key)) = (&cfg.ssl_client_cert_path, &cfg.ssl_client_key_path) {
        opts = opts.ssl_client_cert(cert).ssl_client_key(key);
    }

    MySqlPoolOptions::new()
        .max_connections(cfg.max_connections)
        .acquire_timeout(std::time::Duration::from_secs(10))
        .connect_with(opts)
        .await
        .context("failed to connect to MySQL")
}

// SPEC.md §9.4: the agent's MySQL credential is SELECT-only on proxy_hosts/ssl_certificates —
// explicit column list, never `SELECT *`, never a write statement anywhere in this crate.
const ACTIVE_PROXY_HOSTS_SQL: &str = r#"
    SELECT
        ph.id, ph.domain, ph.forward_scheme, ph.forward_host, ph.forward_port,
        ph.websockets_enabled, ph.version,
        ph.ssl_certificate_id,
        sc.version AS certificate_version,
        sc.storage_path AS certificate_storage_path
    FROM proxy_hosts ph
    LEFT JOIN ssl_certificates sc ON sc.id = ph.ssl_certificate_id
    WHERE ph.enabled = 1
    ORDER BY ph.domain ASC
"#;

pub async fn fetch_active_proxy_hosts(pool: &MySqlPool) -> anyhow::Result<Vec<ActiveProxyHost>> {
    let rows: Vec<ProxyHostRow> = sqlx::query_as(ACTIVE_PROXY_HOSTS_SQL)
        .fetch_all(pool)
        .await
        .context("failed to fetch active proxy_hosts")?;

    rows.into_iter().map(TryInto::try_into).collect()
}

const ACTIVE_PROXY_HOST_BY_ID_SQL: &str = r#"
    SELECT
        ph.id, ph.domain, ph.forward_scheme, ph.forward_host, ph.forward_port,
        ph.websockets_enabled, ph.version,
        ph.ssl_certificate_id,
        sc.version AS certificate_version,
        sc.storage_path AS certificate_storage_path
    FROM proxy_hosts ph
    LEFT JOIN ssl_certificates sc ON sc.id = ph.ssl_certificate_id
    WHERE ph.enabled = 1 AND ph.id = ?
"#;

/// Used by incremental sync for `proxy_host.created`/`proxy_host.updated` events. `None` means
/// the host is gone or was disabled between the event being published and now — the caller
/// falls back to removing any locally-applied config for it (see `sync::remove_by_host_id`).
pub async fn fetch_active_proxy_host_by_id(
    pool: &MySqlPool,
    id: u64,
) -> anyhow::Result<Option<ActiveProxyHost>> {
    let row: Option<ProxyHostRow> = sqlx::query_as(ACTIVE_PROXY_HOST_BY_ID_SQL)
        .bind(id)
        .fetch_optional(pool)
        .await
        .context("failed to fetch proxy_host by id")?;

    row.map(TryInto::try_into).transpose()
}

const ACTIVE_PROXY_HOSTS_BY_CERTIFICATE_ID_SQL: &str = r#"
    SELECT
        ph.id, ph.domain, ph.forward_scheme, ph.forward_host, ph.forward_port,
        ph.websockets_enabled, ph.version,
        ph.ssl_certificate_id,
        sc.version AS certificate_version,
        sc.storage_path AS certificate_storage_path
    FROM proxy_hosts ph
    LEFT JOIN ssl_certificates sc ON sc.id = ph.ssl_certificate_id
    WHERE ph.enabled = 1 AND ph.ssl_certificate_id = ?
    ORDER BY ph.domain ASC
"#;

/// Used by incremental sync for `certificate.issued`/`certificate.renewed`/`certificate.revoked`
/// events — a certificate could in principle be referenced by more than one proxy host.
pub async fn fetch_active_proxy_hosts_by_certificate_id(
    pool: &MySqlPool,
    cert_id: u64,
) -> anyhow::Result<Vec<ActiveProxyHost>> {
    let rows: Vec<ProxyHostRow> = sqlx::query_as(ACTIVE_PROXY_HOSTS_BY_CERTIFICATE_ID_SQL)
        .bind(cert_id)
        .fetch_all(pool)
        .await
        .context("failed to fetch active proxy_hosts by certificate id")?;

    rows.into_iter().map(TryInto::try_into).collect()
}

const PENDING_ACME_CHALLENGES_SQL: &str = r#"
    SELECT token, key_authorization
    FROM acme_challenges
    WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
"#;

/// Fetches every currently-pending, non-expired ACME HTTP-01 challenge across all
/// certificates — not scoped to a single domain/certificate. See `acme_challenges.rs` for why
/// the agent deliberately never correlates a challenge to a specific proxy host.
pub async fn fetch_pending_acme_challenges(pool: &MySqlPool) -> anyhow::Result<Vec<AcmeChallengeRow>> {
    sqlx::query_as(PENDING_ACME_CHALLENGES_SQL)
        .fetch_all(pool)
        .await
        .context("failed to fetch pending acme_challenges")
}
