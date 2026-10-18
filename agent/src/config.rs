use std::path::PathBuf;
use std::time::Duration;

use anyhow::{bail, Context};
use sqlx::mysql::MySqlSslMode;

use crate::logging_config::{self, LogDestination};

/// Resolves `name` from the environment, or — when `{name}_FILE` is set — from the file it points
/// to (trailing newline trimmed). This is the Docker secrets convention (`/run/secrets/...`): in
/// Swarm, credentials then never appear in `docker service inspect` or the task's environment.
/// Pure over its two lookups so precedence is unit-testable without mutating process env.
pub fn resolve_env_or_file(
    name: &str,
    get_env: impl Fn(&str) -> Option<String>,
    read_file: impl Fn(&str) -> std::io::Result<String>,
) -> anyhow::Result<Option<String>> {
    if let Some(path) = get_env(&format!("{name}_FILE")) {
        let content = read_file(&path).with_context(|| format!("reading {name}_FILE ({path})"))?;
        return Ok(Some(content.trim_end_matches(['\n', '\r']).to_string()));
    }
    Ok(get_env(name))
}

fn env_or_file(name: &str) -> anyhow::Result<Option<String>> {
    resolve_env_or_file(name, |key| std::env::var(key).ok(), |path| std::fs::read_to_string(path))
}

/// Injects `password` into a `redis://host:port/db` URL, so the password can come from its own
/// secret (shared with the control-plane's REDIS_PASSWORD) instead of being duplicated inside a URL.
/// Characters that would need percent-encoding are refused rather than silently mangled — generate
/// the password with e.g. `openssl rand -hex 32`.
pub fn redis_url_with_password(url: &str, password: &str) -> anyhow::Result<String> {
    if password.is_empty() || !password.chars().all(|c| c.is_ascii_alphanumeric() || "-_.~".contains(c)) {
        bail!("REDIS_PASSWORD must be non-empty and URL-safe ([A-Za-z0-9-_.~])");
    }

    let rest = url
        .strip_prefix("redis://")
        .ok_or_else(|| anyhow::anyhow!("REDIS_URL must start with redis:// when REDIS_PASSWORD is set"))?;
    if rest.split('/').next().unwrap_or_default().contains('@') {
        bail!("set the Redis password either in REDIS_URL or in REDIS_PASSWORD, not both");
    }

    Ok(format!("redis://:{password}@{rest}"))
}

fn required(name: &str) -> anyhow::Result<String> {
    env_or_file(name)?.with_context(|| format!("missing required env var {name} (or {name}_FILE)"))
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
    pub nginx_snippets_dir: PathBuf,
    pub log_destination: LogDestination,
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

        // REDIS_URL may embed the password (`redis://:secret@redis:6379/`), so it accepts _FILE too.
        let redis_url = env_or_file("REDIS_URL")?.unwrap_or_else(|| "redis://127.0.0.1:6379/".to_string());
        let redis = RedisConfig {
            url: match env_or_file("REDIS_PASSWORD")? {
                Some(password) => redis_url_with_password(&redis_url, &password)?,
                None => redis_url,
            },
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
            nginx_snippets_dir: PathBuf::from(optional("NGINX_SNIPPETS_DIR", "/etc/nginx/snippets")),
            log_destination: logging_config::resolve_log_destination(
                optional_parsed("LOG_FORWARDING_ENABLED", false)?,
                std::env::var("CENTRAL_LOG_SERVER_HOST").ok(),
                optional_parsed("CENTRAL_LOG_SERVER_PORT", 514u16)?,
            )?,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn no_file(_: &str) -> std::io::Result<String> {
        Err(std::io::Error::new(std::io::ErrorKind::NotFound, "no file"))
    }

    #[test]
    fn env_or_file_prefers_the_file_when_name_file_is_set() {
        let resolved = resolve_env_or_file(
            "DB_PASSWORD",
            |key| match key {
                "DB_PASSWORD_FILE" => Some("/run/secrets/db".to_string()),
                "DB_PASSWORD" => Some("from-env".to_string()),
                _ => None,
            },
            |path| {
                assert_eq!(path, "/run/secrets/db");
                Ok("from-secret\n".to_string())
            },
        )
        .unwrap();

        assert_eq!(resolved.as_deref(), Some("from-secret"));
    }

    #[test]
    fn env_or_file_falls_back_to_the_plain_variable() {
        let resolved = resolve_env_or_file(
            "DB_PASSWORD",
            |key| (key == "DB_PASSWORD").then(|| "from-env".to_string()),
            no_file,
        )
        .unwrap();

        assert_eq!(resolved.as_deref(), Some("from-env"));
    }

    #[test]
    fn env_or_file_errors_when_the_secret_file_is_unreadable() {
        let result = resolve_env_or_file(
            "DB_PASSWORD",
            |key| (key == "DB_PASSWORD_FILE").then(|| "/missing".to_string()),
            no_file,
        );

        assert!(result.is_err());
    }

    #[test]
    fn redis_password_is_injected_into_the_url() {
        assert_eq!(
            redis_url_with_password("redis://redis:6379/", "abc123").unwrap(),
            "redis://:abc123@redis:6379/"
        );
    }

    #[test]
    fn redis_password_rejects_ambiguous_or_unsafe_input() {
        assert!(redis_url_with_password("redis://:x@redis:6379/", "abc").is_err());
        assert!(redis_url_with_password("rediss://redis:6379/", "abc").is_err());
        assert!(redis_url_with_password("redis://redis:6379/", "p@ss/word").is_err());
        assert!(redis_url_with_password("redis://redis:6379/", "").is_err());
    }

    #[test]
    fn env_or_file_returns_none_when_neither_is_set() {
        assert_eq!(resolve_env_or_file("X", |_| None, no_file).unwrap(), None);
    }

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
