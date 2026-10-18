use anyhow::bail;

/// Mirrors `App\Enums\ForwardScheme` on the control-plane — stored in `proxy_hosts.forward_scheme`
/// as the enum's backing string value ('http'/'https').
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ForwardScheme {
    Http,
    Https,
}

impl ForwardScheme {
    pub fn parse(raw: &str) -> anyhow::Result<Self> {
        match raw {
            "http" => Ok(Self::Http),
            "https" => Ok(Self::Https),
            other => bail!("unknown proxy_hosts.forward_scheme value: {other:?}"),
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Http => "http",
            Self::Https => "https",
        }
    }
}

/// Raw row shape for the bootstrap query in `db.rs`. `custom_config` is intentionally not
/// selected — Laravel only validates it as `nullable|array`, no shape is defined anywhere yet
/// (Fase 7+ decides that), so there is nothing meaningful to interpret here.
#[derive(Debug, Clone, sqlx::FromRow)]
pub struct ProxyHostRow {
    pub id: u64,
    pub domain: String,
    pub forward_scheme: String,
    pub forward_host: String,
    pub forward_port: u16,
    pub websockets_enabled: bool,
    pub version: u32,
    pub ssl_certificate_id: Option<u64>,
    pub certificate_version: Option<u32>,
    pub certificate_storage_path: Option<String>,
}

#[derive(Debug, Clone)]
pub struct CertificateRef {
    pub id: u64,
    pub version: u32,
    /// `None` means the `ssl_certificates` row exists but nothing has been uploaded to MinIO yet
    /// (the only reality possible before Fase 7 — certificate issuance — exists).
    pub storage_path: Option<String>,
}

#[derive(Debug, Clone)]
pub struct ActiveProxyHost {
    pub id: u64,
    pub domain: String,
    pub forward_scheme: ForwardScheme,
    pub forward_host: String,
    pub forward_port: u16,
    pub websockets_enabled: bool,
    pub version: u32,
    pub certificate: Option<CertificateRef>,
}

/// Row shape for the pending-ACME-challenges query in `db.rs`. Deliberately has no `id`/`domain`
/// — the agent never correlates a challenge to a specific proxy host (see `acme_challenges.rs`),
/// it just materializes every currently-pending, non-expired challenge as a file named by token.
#[derive(Debug, Clone, sqlx::FromRow)]
pub struct AcmeChallengeRow {
    pub token: String,
    pub key_authorization: String,
}

/// Defense in depth (SPEC.md §15, "never trust data from an external system"): `domain` and
/// `forward_host` are interpolated verbatim into `server_name`/`proxy_pass`. The control-plane
/// validates both (App\Rules\Hostname / ForwardHost), but a row written by any other path — a
/// manual SQL fix, an older control-plane version — must still never be able to inject Nginx
/// directives. Hostnames and IPv4 addresses only ever need `[A-Za-z0-9.-]`; upstreams may also be
/// Docker Swarm service names (`{stack}_{service}`), hence `_` — never `;`, `{`, `}` or whitespace.
fn ensure_safe_host(field: &str, value: &str) -> anyhow::Result<()> {
    let safe = !value.is_empty()
        && value.len() <= 253
        && value.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.' || c == '_')
        && !value.starts_with('.')
        && !value.starts_with('-')
        && !value.contains("..");

    if !safe {
        bail!("refusing unsafe proxy_hosts.{field} value: {value:?}");
    }
    Ok(())
}

impl TryFrom<ProxyHostRow> for ActiveProxyHost {
    type Error = anyhow::Error;

    fn try_from(row: ProxyHostRow) -> anyhow::Result<Self> {
        ensure_safe_host("domain", &row.domain)?;
        ensure_safe_host("forward_host", &row.forward_host)?;
        let forward_scheme = ForwardScheme::parse(&row.forward_scheme)?;
        let certificate = row.ssl_certificate_id.map(|id| CertificateRef {
            id,
            version: row.certificate_version.unwrap_or(0),
            storage_path: row.certificate_storage_path,
        });

        Ok(Self {
            id: row.id,
            domain: row.domain,
            forward_scheme,
            forward_host: row.forward_host,
            forward_port: row.forward_port,
            websockets_enabled: row.websockets_enabled,
            version: row.version,
            certificate,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(domain: &str, forward_host: &str, forward_scheme: &str) -> ProxyHostRow {
        ProxyHostRow {
            id: 1,
            domain: domain.to_string(),
            forward_scheme: forward_scheme.to_string(),
            forward_host: forward_host.to_string(),
            forward_port: 80,
            websockets_enabled: false,
            version: 1,
            ssl_certificate_id: None,
            certificate_version: None,
            certificate_storage_path: None,
        }
    }

    #[test]
    fn accepts_hostnames_and_ipv4_forward_hosts() {
        assert!(ActiveProxyHost::try_from(row("app.example.com", "backend", "http")).is_ok());
        assert!(ActiveProxyHost::try_from(row("app.example.com", "10.0.0.5", "https")).is_ok());
        assert!(ActiveProxyHost::try_from(row("app.example.com", "mystack_web", "http")).is_ok());
    }

    #[test]
    fn rejects_directive_injection_in_forward_host() {
        let err = ActiveProxyHost::try_from(row(
            "app.example.com",
            "10.0.0.5:80; } server { location / { alias /var/lib/apy-agent/certs/; }",
            "http",
        ))
        .unwrap_err();
        assert!(err.to_string().contains("forward_host"));
    }

    #[test]
    fn rejects_directive_injection_in_domain() {
        assert!(ActiveProxyHost::try_from(row("example.com;return 200", "backend", "http")).is_err());
        assert!(ActiveProxyHost::try_from(row("example.com\nlisten 81", "backend", "http")).is_err());
        assert!(ActiveProxyHost::try_from(row("", "backend", "http")).is_err());
        assert!(ActiveProxyHost::try_from(row("../etc", "backend", "http")).is_err());
    }

    #[test]
    fn rejects_unknown_forward_scheme() {
        assert!(ActiveProxyHost::try_from(row("app.example.com", "backend", "ftp")).is_err());
    }

    #[test]
    fn missing_certificate_version_defaults_to_zero() {
        let mut r = row("app.example.com", "backend", "http");
        r.ssl_certificate_id = Some(9);
        let host = ActiveProxyHost::try_from(r).unwrap();
        let cert = host.certificate.unwrap();
        assert_eq!(cert.id, 9);
        assert_eq!(cert.version, 0);
        assert!(cert.storage_path.is_none());
    }
}
