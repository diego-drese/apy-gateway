use std::path::PathBuf;
use std::time::Duration;

use anyhow::{bail, Context};
use sqlx::mysql::MySqlSslMode;

fn required(name: &str) -> anyhow::Result<String> {
    std::env::var(name).with_context(|| format!("missing required env var {name}"))
}

fn optional(name: &str, default: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| default.to_string())
}

fn optional_parsed<T: std::str::FromStr>(name: &str, default: T) -> anyhow::Result<T>
where
    T::Err: std::fmt::Display,
{
    match std::env::var(name) {
        Ok(raw) => raw
            .parse()
            .map_err(|e| anyhow::anyhow!("invalid value for {name}: {e}")),
        Err(_) => Ok(default),
    }
}

fn parse_ssl_mode(raw: &str) -> anyhow::Result<MySqlSslMode> {
    Ok(match raw {
        "disabled" => MySqlSslMode::Disabled,
        "preferred" => MySqlSslMode::Preferred,
        "required" => MySqlSslMode::Required,
        "verify_ca" => MySqlSslMode::VerifyCa,
        "verify_identity" => MySqlSslMode::VerifyIdentity,
        other => bail!("invalid DB_SSL_MODE {other:?} (expected disabled|preferred|required|verify_ca|verify_identity)"),
    })
}

pub struct DbConfig {
    pub host: String,
    pub port: u16,
    pub database: String,
    pub username: String,
    pub password: String,
    pub max_connections: u32,
    pub ssl_mode: MySqlSslMode,
    // SPEC.md §9.4: mTLS is required in production, but the exact enrollment mechanism is an
    // open infra decision (§16) — wired here as optional config, not enforced by this phase.
    pub ssl_ca_path: Option<String>,
    pub ssl_client_cert_path: Option<String>,
    pub ssl_client_key_path: Option<String>,
}

pub struct MinioConfig {
    pub endpoint_url: String,
    pub region: String,
    pub bucket: String,
    pub access_key: String,
    pub secret_key: String,
    pub force_path_style: bool,
}

pub struct RedisConfig {
    // Plaintext `redis://` only this phase — the `redis` crate version compatible with the
    // already-validated aws-sdk-s3/aws-config dependency set has no working TLS feature (a real
    // `cargo check` with tls-rustls/tokio-rustls-comp produced a genuine version-unification
    // conflict). mTLS on this connection remains an open infra decision (SPEC.md §9.4, §16).
    pub url: String,
}

pub struct AppConfig {
    pub db: DbConfig,
    pub minio: MinioConfig,
    pub redis: RedisConfig,
    pub state_file_path: PathBuf,
    pub cert_storage_dir: PathBuf,
    pub acme_challenge_dir: PathBuf,
    pub nginx_binary_path: String,
    pub nginx_conf_path: PathBuf,
    pub nginx_conf_d_path: PathBuf,
    pub nginx_templates_dir: PathBuf,
    pub readiness_check_addr: String,
    pub readiness_timeout: Duration,
    pub healthcheck_marker_path: PathBuf,
    pub heartbeat_interval: Duration,
    pub heartbeat_ttl: Duration,
    pub reconciliation_interval: Duration,
    pub replica_hostname: String,
}

/// Pure function (no direct env access) so the precedence is unit-testable without mutating
/// process-global state. Precedence: `AGENT_HOSTNAME` (explicit operator-set identity) >
/// `HOSTNAME` (Docker sets this automatically, stable across restarts of the same container) >
/// a random-ish fallback so a missing identity never blocks boot.
pub fn resolve_replica_hostname_from(agent_hostname: Option<String>, docker_hostname: Option<String>) -> String {
    agent_hostname
        .or(docker_hostname)
        .unwrap_or_else(|| format!("unknown-{}", std::process::id()))
}

fn resolve_replica_hostname() -> String {
    resolve_replica_hostname_from(std::env::var("AGENT_HOSTNAME").ok(), std::env::var("HOSTNAME").ok())
}

impl AppConfig {
    pub fn from_env() -> anyhow::Result<Self> {
        let db = DbConfig {
            host: required("DB_HOST")?,
            port: optional_parsed("DB_PORT", 3306u16)?,
            database: required("DB_DATABASE")?,
            username: required("DB_USERNAME")?,
            password: required("DB_PASSWORD")?,
            max_connections: optional_parsed("DB_MAX_CONNECTIONS", 5u32)?,
            ssl_mode: parse_ssl_mode(&optional("DB_SSL_MODE", "preferred"))?,
            ssl_ca_path: std::env::var("DB_SSL_CA_PATH").ok(),
            ssl_client_cert_path: std::env::var("DB_SSL_CLIENT_CERT_PATH").ok(),
            ssl_client_key_path: std::env::var("DB_SSL_CLIENT_KEY_PATH").ok(),
        };

        let minio = MinioConfig {
            endpoint_url: required("MINIO_ENDPOINT_URL")?,
            region: optional("MINIO_REGION", "us-east-1"),
            bucket: required("MINIO_BUCKET")?,
            access_key: required("MINIO_ACCESS_KEY")?,
            secret_key: required("MINIO_SECRET_KEY")?,
            force_path_style: optional_parsed("MINIO_FORCE_PATH_STYLE", true)?,
        };

        let redis = RedisConfig {
            url: optional("REDIS_URL", "redis://127.0.0.1:6379/"),
        };

        Ok(Self {
            db,
            minio,
            redis,
            state_file_path: PathBuf::from(optional("STATE_FILE_PATH", "/var/lib/apy-agent/state.json")),
            cert_storage_dir: PathBuf::from(optional("CERT_STORAGE_DIR", "/var/lib/apy-agent/certs")),
            acme_challenge_dir: PathBuf::from(optional(
                "ACME_CHALLENGE_DIR",
                "/var/lib/apy-agent/acme-challenges",
            )),
            nginx_binary_path: optional("NGINX_BINARY_PATH", "nginx"),
            nginx_conf_path: PathBuf::from(optional("NGINX_CONF_PATH", "/etc/nginx/nginx.conf")),
            nginx_conf_d_path: PathBuf::from(optional("NGINX_CONF_D_PATH", "/etc/nginx/conf.d")),
            nginx_templates_dir: PathBuf::from(optional("NGINX_TEMPLATES_DIR", "/etc/apy-agent/templates")),
            readiness_check_addr: optional("NGINX_READINESS_CHECK_ADDR", "127.0.0.1:80"),
            readiness_timeout: Duration::from_secs(optional_parsed("NGINX_READINESS_TIMEOUT_SECS", 30u64)?),
            healthcheck_marker_path: PathBuf::from(optional(
                "HEALTHCHECK_MARKER_PATH",
                "/var/run/apy-agent/ready",
            )),
            heartbeat_interval: Duration::from_secs(optional_parsed("HEARTBEAT_INTERVAL_SECS", 15u64)?),
            heartbeat_ttl: Duration::from_secs(optional_parsed("HEARTBEAT_TTL_SECS", 45u64)?),
            reconciliation_interval: Duration::from_secs(optional_parsed(
                "RECONCILIATION_INTERVAL_SECS",
                300u64,
            )?),
            replica_hostname: resolve_replica_hostname(),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn agent_hostname_takes_precedence_when_set() {
        let resolved = resolve_replica_hostname_from(Some("explicit".to_string()), Some("docker-id".to_string()));
        assert_eq!(resolved, "explicit");
    }

    #[test]
    fn falls_back_to_docker_hostname_when_agent_hostname_unset() {
        let resolved = resolve_replica_hostname_from(None, Some("docker-id".to_string()));
        assert_eq!(resolved, "docker-id");
    }

    #[test]
    fn falls_back_to_unknown_when_neither_is_set() {
        let resolved = resolve_replica_hostname_from(None, None);
        assert!(resolved.starts_with("unknown-"));
    }
}
