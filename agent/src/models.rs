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

impl TryFrom<ProxyHostRow> for ActiveProxyHost {
    type Error = anyhow::Error;

    fn try_from(row: ProxyHostRow) -> anyhow::Result<Self> {
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
