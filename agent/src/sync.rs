use std::collections::HashSet;

use anyhow::Context;

use crate::acme_challenges;
use crate::app_context::AppContext;
use crate::event_bus::DomainEvent;
use crate::models::ActiveProxyHost;
use crate::nginx::{self, RealNginxTester};
use crate::state::{self, AgentState, DomainState};
use crate::{db, templates};

#[derive(Default, Debug)]
pub struct SyncOutcome {
    pub applied: Vec<String>,
    pub failed: Vec<(String, String)>,
    pub removed: Vec<String>,
    /// Total domains currently applied after this sync — Fase 10 (SPEC.md §12) heartbeat metric.
    /// Read from final `agent_state` size rather than tracked as a diff, so it's correct even on
    /// "nothing changed"/"unknown event type" branches with no extra bookkeeping.
    pub synced_domains_count: usize,
}

pub async fn run_bootstrap(app: &AppContext) -> anyhow::Result<SyncOutcome> {
    let remote_hosts = db::fetch_active_proxy_hosts(&app.pool).await?;
    let mut agent_state = AgentState::load(&app.config.state_file_path)?;

    let remote_domains: HashSet<&str> = remote_hosts.iter().map(|h| h.domain.as_str()).collect();

    let mut outcome = sync_hosts(app, &remote_hosts, &mut agent_state).await;

    let stale: Vec<String> = agent_state
        .domains
        .keys()
        .filter(|d| !remote_domains.contains(d.as_str()))
        .cloned()
        .collect();

    for domain in stale {
        let tester = RealNginxTester {
            binary_path: &app.config.nginx_binary_path,
            main_conf_path: &app.config.nginx_conf_path,
        };
        match nginx::remove_domain_config(&app.config.nginx_conf_d_path, &domain, &tester) {
            Ok(()) => {
                agent_state.domains.remove(&domain);
                outcome.removed.push(domain);
            }
            Err(err) => {
                tracing::error!(domain = %domain, error = %err, "failed to remove stale proxy host config; leaving it in place");
            }
        }
    }

    // Independent of the host-diffing loop above and never allowed to fail the whole bootstrap —
    // a late/lost `acme_challenge.ready` event is exactly what this reconciliation pass is a
    // safety net for.
    if let Err(err) = acme_challenges::sync_challenges(app).await {
        tracing::error!(error = %err, "failed to reconcile ACME challenge files during bootstrap");
    }

    outcome.synced_domains_count = agent_state.domains.len();
    agent_state.save(&app.config.state_file_path)?;
    Ok(outcome)
}

/// Reacts to a single Redis event (SPEC.md §9.2: "repeat steps 2–6 for only the affected
/// domain"). Never talks to Redis itself — the caller already decoded the message.
pub async fn sync_incremental(app: &AppContext, event: &DomainEvent) -> anyhow::Result<SyncOutcome> {
    let mut agent_state = AgentState::load(&app.config.state_file_path)?;
    let mut outcome = SyncOutcome::default();

    match event.event_type.as_str() {
        "proxy_host.created" | "proxy_host.updated" => {
            match db::fetch_active_proxy_host_by_id(&app.pool, event.subject_id).await? {
                Some(host) => {
                    outcome = sync_hosts(app, std::slice::from_ref(&host), &mut agent_state).await;
                }
                // Host is gone or was disabled between publish and now — treat the same as an
                // explicit deletion instead of waiting up to `reconciliation_interval` for the
                // periodic pass to notice (disabling a host is plausibly a "cut it now" action).
                None => {
                    let tester = RealNginxTester {
                        binary_path: &app.config.nginx_binary_path,
                        main_conf_path: &app.config.nginx_conf_path,
                    };
                    remove_by_host_id(&app.config.nginx_conf_d_path, &tester, event.subject_id, &mut agent_state, &mut outcome);
                }
            }
        }
        "certificate.issued" | "certificate.renewed" | "certificate.revoked" => {
            let hosts = db::fetch_active_proxy_hosts_by_certificate_id(&app.pool, event.subject_id).await?;
            if hosts.is_empty() {
                tracing::debug!(
                    certificate_id = event.subject_id,
                    "no active host references this certificate; periodic reconciliation remains the safety net"
                );
            } else {
                outcome = sync_hosts(app, &hosts, &mut agent_state).await;
            }
        }
        "proxy_host.deleted" => {
            let tester = RealNginxTester {
                binary_path: &app.config.nginx_binary_path,
                main_conf_path: &app.config.nginx_conf_path,
            };
            remove_by_host_id(&app.config.nginx_conf_d_path, &tester, event.subject_id, &mut agent_state, &mut outcome);
        }
        // `subject_id` is ignored — the challenges table is small, so it's simpler and safer to
        // always resync the whole pending set than to correlate this event to specific domains.
        // No Nginx reload needed (see `acme_challenges::sync_challenge_files`), so `outcome` is
        // left empty on purpose.
        "acme_challenge.ready" => {
            acme_challenges::sync_challenges(app).await?;
        }
        other => {
            tracing::warn!(
                event_type = other,
                event_id = %event.id,
                "unknown domain event type, ignoring (periodic reconciliation still covers the underlying state)"
            );
        }
    }

    outcome.synced_domains_count = agent_state.domains.len();
    agent_state.save(&app.config.state_file_path)?;
    Ok(outcome)
}

/// Shared diff-then-sync loop used by both a full reconciliation pass (all active hosts) and
/// incremental sync (just the host(s) affected by one event).
async fn sync_hosts(app: &AppContext, hosts: &[ActiveProxyHost], state: &mut AgentState) -> SyncOutcome {
    let mut outcome = SyncOutcome::default();

    for host in hosts {
        if state::diff(state.domains.get(&host.domain), host) == state::DomainDiff::Unchanged {
            continue;
        }

        match sync_one_domain(app, host).await {
            Ok(new_state) => {
                state.domains.insert(host.domain.clone(), new_state);
                outcome.applied.push(host.domain.clone());
            }
            Err(err) => {
                tracing::error!(
                    domain = %host.domain,
                    error = %err,
                    "failed to sync proxy host; keeping last known-good config for this domain, will retry"
                );
                outcome.failed.push((host.domain.clone(), err.to_string()));
                // Deliberately not touching state here — absence means "retry every future run".
            }
        }
    }

    outcome
}

/// Reverse-lookup a domain by its `proxy_host_id` in local state and remove its config. Used
/// both for `proxy_host.deleted` events and for hosts that disappeared/got disabled between an
/// event being published and the agent acting on it — the row is gone from MySQL either way, so
/// there's nothing left to query by id.
///
/// Takes only the Nginx-related config it needs (not the whole `AppContext`), and an injectable
/// `ConfigTester` (not a hardcoded `RealNginxTester`), so it's testable against a real temp
/// directory + a fake tester, without a live MySQL pool/MinIO client or an actual `nginx` binary
/// — the same seam `nginx::apply_domain_config`/`remove_domain_config` already established.
fn remove_by_host_id(
    nginx_conf_d_path: &std::path::Path,
    tester: &dyn nginx::ConfigTester,
    host_id: u64,
    state: &mut AgentState,
    outcome: &mut SyncOutcome,
) {
    let domain = state
        .domains
        .iter()
        .find(|(_, s)| s.proxy_host_id == host_id)
        .map(|(d, _)| d.clone());

    let Some(domain) = domain else {
        tracing::debug!(proxy_host_id = host_id, "no local state for this host id — nothing to remove");
        return;
    };

    match nginx::remove_domain_config(nginx_conf_d_path, &domain, tester) {
        Ok(()) => {
            state.domains.remove(&domain);
            outcome.removed.push(domain);
        }
        Err(err) => {
            tracing::error!(domain = %domain, error = %err, "failed to remove proxy host config; leaving it in place");
            outcome.failed.push((domain, err.to_string()));
        }
    }
}

async fn sync_one_domain(app: &AppContext, host: &ActiveProxyHost) -> anyhow::Result<DomainState> {
    let ssl = match &host.certificate {
        Some(cert) => {
            let storage_path = cert.storage_path.as_deref().ok_or_else(|| {
                anyhow::anyhow!(
                    "proxy host {} references ssl_certificate {} with no storage_path yet",
                    host.domain,
                    cert.id
                )
            })?;
            let dest_dir = app.config.cert_storage_dir.join(&host.domain);
            let paths = app
                .minio
                .download_certificate(storage_path, &dest_dir)
                .await
                .with_context(|| format!("downloading certificate for {}", host.domain))?;

            Some(templates::SslTemplateContext {
                crt_path: paths.crt_path.display().to_string(),
                key_path: paths.key_path.display().to_string(),
            })
        }
        None => None,
    };

    let rendered = app.templates.render_proxy_host(&templates::ProxyHostTemplateContext {
        domain: host.domain.clone(),
        forward_scheme: host.forward_scheme.as_str().to_string(),
        forward_host: host.forward_host.clone(),
        forward_port: host.forward_port,
        websockets_enabled: host.websockets_enabled,
        ssl,
    })?;

    let tester = RealNginxTester {
        binary_path: &app.config.nginx_binary_path,
        main_conf_path: &app.config.nginx_conf_path,
    };
    nginx::apply_domain_config(&app.config.nginx_conf_d_path, &host.domain, &rendered, &tester)?;

    Ok(DomainState {
        proxy_host_id: host.id,
        proxy_host_version: host.version,
        certificate_id: host.certificate.as_ref().map(|c| c.id),
        certificate_version: host.certificate.as_ref().map(|c| c.version),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::nginx::ConfigTester;

    struct AlwaysOk;
    impl ConfigTester for AlwaysOk {
        fn test(&self) -> anyhow::Result<()> {
            Ok(())
        }
    }

    struct AlwaysFail;
    impl ConfigTester for AlwaysFail {
        fn test(&self) -> anyhow::Result<()> {
            anyhow::bail!("simulated nginx -t failure")
        }
    }

    fn unique_temp_dir(name: &str) -> std::path::PathBuf {
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let dir = std::env::temp_dir().join(format!("apy-agent-test-sync-{name}-{nanos}"));
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    fn state_with_domain(domain: &str, host_id: u64) -> AgentState {
        let mut state = AgentState { schema_version: 1, domains: Default::default() };
        state.domains.insert(
            domain.to_string(),
            DomainState { proxy_host_id: host_id, proxy_host_version: 1, certificate_id: None, certificate_version: None },
        );
        state
    }

    #[test]
    fn remove_by_host_id_finds_and_removes_known_domain() {
        let dir = unique_temp_dir("found");
        std::fs::write(dir.join("example.com.conf"), "server {}").unwrap();
        let mut state = state_with_domain("example.com", 42);
        let mut outcome = SyncOutcome::default();

        remove_by_host_id(&dir, &AlwaysOk, 42, &mut state, &mut outcome);

        assert!(!dir.join("example.com.conf").exists());
        assert!(!state.domains.contains_key("example.com"));
        assert_eq!(outcome.removed, vec!["example.com".to_string()]);
        assert!(outcome.failed.is_empty());
    }

    #[test]
    fn remove_by_host_id_is_a_no_op_when_id_is_unknown() {
        let dir = unique_temp_dir("unknown-id");
        std::fs::write(dir.join("example.com.conf"), "server {}").unwrap();
        let mut state = state_with_domain("example.com", 1);
        let mut outcome = SyncOutcome::default();

        remove_by_host_id(&dir, &AlwaysOk, 999, &mut state, &mut outcome);

        // Unrelated id — nothing should be touched.
        assert!(dir.join("example.com.conf").exists());
        assert!(state.domains.contains_key("example.com"));
        assert!(outcome.removed.is_empty());
    }

    #[test]
    fn remove_by_host_id_matches_by_id_not_domain_name() {
        let dir = unique_temp_dir("match-by-id");
        std::fs::write(dir.join("renamed.example.com.conf"), "server {}").unwrap();
        let mut state = state_with_domain("renamed.example.com", 7);
        let mut outcome = SyncOutcome::default();

        remove_by_host_id(&dir, &AlwaysOk, 7, &mut state, &mut outcome);

        assert_eq!(outcome.removed, vec!["renamed.example.com".to_string()]);
    }

    #[test]
    fn remove_by_host_id_reverts_and_records_failure_when_nginx_t_fails() {
        let dir = unique_temp_dir("revert");
        std::fs::write(dir.join("example.com.conf"), "server {}").unwrap();
        let mut state = state_with_domain("example.com", 42);
        let mut outcome = SyncOutcome::default();

        remove_by_host_id(&dir, &AlwaysFail, 42, &mut state, &mut outcome);

        assert!(dir.join("example.com.conf").exists());
        assert!(state.domains.contains_key("example.com"));
        assert!(outcome.removed.is_empty());
        assert_eq!(outcome.failed.len(), 1);
    }
}
