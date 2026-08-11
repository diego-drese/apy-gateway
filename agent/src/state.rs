use std::collections::HashMap;
use std::path::Path;

use anyhow::Context;

use crate::models::ActiveProxyHost;

#[derive(Debug, Default, serde::Serialize, serde::Deserialize)]
pub struct AgentState {
    pub schema_version: u32,
    pub domains: HashMap<String, DomainState>,
}

#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct DomainState {
    pub proxy_host_id: u64,
    pub proxy_host_version: u32,
    pub certificate_id: Option<u64>,
    pub certificate_version: Option<u32>,
}

impl AgentState {
    pub fn load(path: &Path) -> anyhow::Result<Self> {
        match std::fs::read_to_string(path) {
            Ok(raw) => {
                serde_json::from_str(&raw).with_context(|| format!("parsing {}", path.display()))
            }
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Self {
                schema_version: 1,
                ..Default::default()
            }),
            Err(e) => Err(e).with_context(|| format!("reading {}", path.display())),
        }
    }

    pub fn save(&self, path: &Path) -> anyhow::Result<()> {
        if let Some(parent) = path.parent() {
            std::fs::create_dir_all(parent)
                .with_context(|| format!("creating directory {}", parent.display()))?;
        }
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)
            .with_context(|| format!("writing {}", tmp.display()))?;
        std::fs::rename(&tmp, path)
            .with_context(|| format!("swapping {} into place", path.display()))?;
        Ok(())
    }
}

#[derive(Debug, PartialEq, Eq)]
pub enum DomainDiff {
    Unchanged,
    NeedsSync,
}

/// SPEC.md §9.3: idempotency — the agent always compares against local state before acting.
/// Compares both the host's own `version` AND the certificate's `version`: a certificate
/// renewal (Fase 7) bumps `ssl_certificates.version` in place without touching
/// `proxy_hosts.version`, so tracking only the host version would silently miss cert rotations.
pub fn diff(existing: Option<&DomainState>, remote: &ActiveProxyHost) -> DomainDiff {
    let remote_cert_id = remote.certificate.as_ref().map(|c| c.id);
    let remote_cert_version = remote.certificate.as_ref().map(|c| c.version);

    match existing {
        Some(e)
            if e.proxy_host_version == remote.version
                && e.certificate_id == remote_cert_id
                && e.certificate_version == remote_cert_version =>
        {
            DomainDiff::Unchanged
        }
        _ => DomainDiff::NeedsSync,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::{CertificateRef, ForwardScheme};

    fn host(version: u32, certificate: Option<CertificateRef>) -> ActiveProxyHost {
        ActiveProxyHost {
            id: 1,
            domain: "example.com".to_string(),
            forward_scheme: ForwardScheme::Http,
            forward_host: "10.0.0.1".to_string(),
            forward_port: 3000,
            websockets_enabled: false,
            version,
            certificate,
        }
    }

    fn unique_temp_path(name: &str) -> std::path::PathBuf {
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        std::env::temp_dir().join(format!("apy-agent-test-{name}-{nanos}"))
    }

    #[test]
    fn new_domain_needs_sync() {
        assert_eq!(diff(None, &host(1, None)), DomainDiff::NeedsSync);
    }

    #[test]
    fn unchanged_domain_is_unchanged() {
        let existing = DomainState {
            proxy_host_id: 1,
            proxy_host_version: 1,
            certificate_id: None,
            certificate_version: None,
        };
        assert_eq!(diff(Some(&existing), &host(1, None)), DomainDiff::Unchanged);
    }

    #[test]
    fn host_version_bump_needs_sync() {
        let existing = DomainState {
            proxy_host_id: 1,
            proxy_host_version: 1,
            certificate_id: None,
            certificate_version: None,
        };
        assert_eq!(diff(Some(&existing), &host(2, None)), DomainDiff::NeedsSync);
    }

    #[test]
    fn certificate_attached_needs_sync() {
        let existing = DomainState {
            proxy_host_id: 1,
            proxy_host_version: 1,
            certificate_id: None,
            certificate_version: None,
        };
        let cert = CertificateRef { id: 10, version: 1, storage_path: Some("certs/example".into()) };
        assert_eq!(diff(Some(&existing), &host(1, Some(cert))), DomainDiff::NeedsSync);
    }

    #[test]
    fn certificate_renewal_needs_sync_even_without_host_version_bump() {
        let existing = DomainState {
            proxy_host_id: 1,
            proxy_host_version: 1,
            certificate_id: Some(10),
            certificate_version: Some(1),
        };
        let renewed = CertificateRef { id: 10, version: 2, storage_path: Some("certs/example".into()) };
        assert_eq!(diff(Some(&existing), &host(1, Some(renewed))), DomainDiff::NeedsSync);
    }

    #[test]
    fn certificate_detached_needs_sync() {
        let existing = DomainState {
            proxy_host_id: 1,
            proxy_host_version: 1,
            certificate_id: Some(10),
            certificate_version: Some(1),
        };
        assert_eq!(diff(Some(&existing), &host(1, None)), DomainDiff::NeedsSync);
    }

    #[test]
    fn load_missing_file_returns_empty_state() {
        let path = unique_temp_path("missing.json");
        let state = AgentState::load(&path).unwrap();
        assert_eq!(state.schema_version, 1);
        assert!(state.domains.is_empty());
    }

    #[test]
    fn save_then_load_round_trips() {
        let path = unique_temp_path("roundtrip.json");
        let mut state = AgentState { schema_version: 1, domains: Default::default() };
        state.domains.insert(
            "example.com".to_string(),
            DomainState { proxy_host_id: 1, proxy_host_version: 3, certificate_id: None, certificate_version: None },
        );

        state.save(&path).unwrap();
        let loaded = AgentState::load(&path).unwrap();

        assert_eq!(loaded.domains.get("example.com"), state.domains.get("example.com"));
        std::fs::remove_file(&path).ok();
    }
}
