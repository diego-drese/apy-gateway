use std::time::Duration;

use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use tokio::sync::mpsc;

const EVENTS_CHANNEL: &str = "apy-gateway:events";
const INITIAL_BACKOFF: Duration = Duration::from_secs(1);
const MAX_BACKOFF: Duration = Duration::from_secs(30);

/// SPEC.md §8 Redis payload shape: `{ "id": "uuid", "type": "proxy_host.updated",
/// "subject_id": 42, "version": 7, "occurred_at": "..." }`.
#[derive(Debug, Clone, Deserialize)]
pub struct DomainEvent {
    pub id: String,
    #[serde(rename = "type")]
    pub event_type: String,
    pub subject_id: u64,
    pub version: u32,
    pub occurred_at: String,
}

/// Spawns a background task owning a dedicated pub/sub connection. Reconnects with exponential
/// backoff on any disconnect — SPEC.md §9.2's own rationale for periodic reconciliation is
/// "covers a replica missing a Redis message while offline," so a dropped subscribe connection
/// must never be fatal to the process (that would kill the already-synced Nginx for no reason).
pub fn spawn_event_subscriber(redis_url: String) -> mpsc::Receiver<DomainEvent> {
    let (tx, rx) = mpsc::channel(64);

    tokio::spawn(async move {
        let mut backoff = INITIAL_BACKOFF;
        loop {
            match run_subscribe_loop(&redis_url, &tx, &mut backoff).await {
                Ok(()) => return, // receiver dropped — main is shutting down
                Err(err) => {
                    tracing::warn!(
                        error = %err,
                        backoff_secs = backoff.as_secs(),
                        "redis subscribe connection lost, retrying"
                    );
                    tokio::time::sleep(backoff).await;
                    backoff = (backoff * 2).min(MAX_BACKOFF);
                }
            }
        }
    });

    rx
}

async fn run_subscribe_loop(
    redis_url: &str,
    tx: &mpsc::Sender<DomainEvent>,
    backoff: &mut Duration,
) -> anyhow::Result<()> {
    let client = redis::Client::open(redis_url)?;
    let mut pubsub = client.get_async_pubsub().await?;
    pubsub.subscribe(EVENTS_CHANNEL).await?;
    *backoff = INITIAL_BACKOFF; // reset only after a real successful (re)subscribe
    tracing::info!(channel = EVENTS_CHANNEL, "subscribed to redis event bus");

    let mut stream = pubsub.on_message();
    while let Some(msg) = stream.next().await {
        let payload: String = match msg.get_payload() {
            Ok(p) => p,
            Err(err) => {
                tracing::warn!(error = %err, "redis message had a non-string payload, skipping");
                continue;
            }
        };

        match serde_json::from_str::<DomainEvent>(&payload) {
            Ok(event) => {
                if tx.send(event).await.is_err() {
                    return Ok(());
                }
            }
            Err(err) => {
                tracing::warn!(error = %err, payload = %payload, "skipping malformed domain event payload");
            }
        }
    }

    anyhow::bail!("redis pub/sub message stream ended (connection dropped)")
}

/// Fase 10 (SPEC.md §12): structured heartbeat, replacing the bare Unix-timestamp value Fase 6
/// shipped. The control-plane's `replicas:sync-metrics` command (never the agent — SPEC.md §9.4
/// stays SELECT-only) reads this to populate `replica_agents`.
#[derive(Debug, Clone, Serialize)]
pub struct HeartbeatPayload {
    pub ts: u64,
    pub ip: String,
    pub agent_version: String,
    pub nginx_version: String,
    pub synced_domains_count: usize,
}

/// Runs forever, writing a liveness key on a fixed interval. Uses a *separate* regular
/// (multiplexed) connection — a PubSub connection in the `redis` crate can't also run `SET`.
/// Reconnects transparently each tick via a fresh client (heartbeat writes are infrequent enough
/// that per-tick connection cost is irrelevant, and it avoids a second backoff state machine).
/// `ip`/`nginx_version` are resolved once at boot by the caller (both invariant for a container's
/// lifetime — see `local_ip::detect_local_ip`/`nginx::detect_version`); `domain_count_rx` tracks
/// the latest sync outcome across the process's lifetime via a `watch` channel, updated in
/// `main.rs` after every successful sync.
pub async fn heartbeat_loop(
    redis_url: String,
    hostname: String,
    interval: Duration,
    ttl: Duration,
    ip: String,
    nginx_version: String,
    mut domain_count_rx: tokio::sync::watch::Receiver<usize>,
) {
    let mut ticker = tokio::time::interval(interval);
    loop {
        ticker.tick().await;
        let synced_domains_count = *domain_count_rx.borrow_and_update();
        if let Err(err) =
            write_heartbeat_once(&redis_url, &hostname, ttl, &ip, &nginx_version, synced_domains_count).await
        {
            tracing::warn!(error = %err, "failed to write heartbeat to redis (will retry next interval)");
        }
    }
}

async fn write_heartbeat_once(
    redis_url: &str,
    hostname: &str,
    ttl: Duration,
    ip: &str,
    nginx_version: &str,
    synced_domains_count: usize,
) -> anyhow::Result<()> {
    use redis::AsyncCommands;

    let client = redis::Client::open(redis_url)?;
    let mut conn = client.get_multiplexed_async_connection().await?;
    let key = format!("apy-gateway:replicas:{hostname}:heartbeat");

    let payload = HeartbeatPayload {
        ts: std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs(),
        ip: ip.to_string(),
        agent_version: env!("CARGO_PKG_VERSION").to_string(),
        nginx_version: nginx_version.to_string(),
        synced_domains_count,
    };

    conn.set_ex::<_, _, ()>(key, serde_json::to_string(&payload)?, ttl.as_secs()).await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_valid_event() {
        let raw = r#"{"id":"abc","type":"proxy_host.updated","subject_id":42,"version":7,"occurred_at":"2026-08-10T00:00:00Z"}"#;
        let event: DomainEvent = serde_json::from_str(raw).unwrap();
        assert_eq!(event.event_type, "proxy_host.updated");
        assert_eq!(event.subject_id, 42);
        assert_eq!(event.version, 7);
    }

    #[test]
    fn rejects_non_json_payload() {
        assert!(serde_json::from_str::<DomainEvent>("not json").is_err());
    }

    #[test]
    fn rejects_payload_missing_required_fields() {
        assert!(serde_json::from_str::<DomainEvent>(r#"{"id":"abc"}"#).is_err());
    }

    #[test]
    fn heartbeat_payload_serializes_with_the_expected_field_names() {
        let payload = HeartbeatPayload {
            ts: 1_723_400_000,
            ip: "10.0.0.5".to_string(),
            agent_version: "0.1.0".to_string(),
            nginx_version: "1.27.4".to_string(),
            synced_domains_count: 3,
        };

        let json: serde_json::Value = serde_json::to_value(&payload).unwrap();

        assert_eq!(json["ts"], 1_723_400_000);
        assert_eq!(json["ip"], "10.0.0.5");
        assert_eq!(json["agent_version"], "0.1.0");
        assert_eq!(json["nginx_version"], "1.27.4");
        assert_eq!(json["synced_domains_count"], 3);
    }
}
