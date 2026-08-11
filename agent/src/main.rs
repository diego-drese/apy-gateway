mod acme_challenges;
mod app_context;
mod config;
mod db;
mod event_bus;
mod minio;
mod models;
mod nginx;
mod state;
mod sync;
mod templates;

use anyhow::Context;

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    // rustls 0.23+ needs a process-global CryptoProvider installed before the first TLS
    // connection (sqlx/tls-rustls, aws-sdk-s3) or it panics — install it once, up front.
    let _ = rustls::crypto::ring::default_provider().install_default();

    let config = config::AppConfig::from_env()?;
    let app = app_context::AppContext::build(config).await?;

    tracing::info!("starting bootstrap sync");
    let outcome = sync::run_bootstrap(&app).await?;
    tracing::info!(
        applied = outcome.applied.len(),
        failed = outcome.failed.len(),
        removed = outcome.removed.len(),
        "bootstrap sync complete"
    );
    if !outcome.failed.is_empty() {
        tracing::warn!(
            failed = ?outcome.failed,
            "some domains failed to sync — kept last known-good config, will retry next boot"
        );
    }

    let mut nginx_child = nginx::spawn(&app.config.nginx_binary_path, &app.config.nginx_conf_path)?;
    nginx::wait_until_serving(
        &app.config.readiness_check_addr,
        app.config.readiness_timeout,
        &mut nginx_child,
    )
    .await
    .context("Nginx did not become ready")?;

    std::fs::write(&app.config.healthcheck_marker_path, b"ready\n")
        .context("failed to write healthcheck marker file")?;
    tracing::info!(
        marker = %app.config.healthcheck_marker_path.display(),
        "container ready — Nginx is serving synced config"
    );

    tracing::info!(channel = "apy-gateway:events", "starting redis event subscriber");
    let mut event_rx = event_bus::spawn_event_subscriber(app.config.redis.url.clone());

    tracing::info!(
        hostname = %app.config.replica_hostname,
        interval_secs = app.config.heartbeat_interval.as_secs(),
        "starting heartbeat loop"
    );
    tokio::spawn(event_bus::heartbeat_loop(
        app.config.redis.url.clone(),
        app.config.replica_hostname.clone(),
        app.config.heartbeat_interval,
        app.config.heartbeat_ttl,
    ));

    let mut reconciliation_timer = tokio::time::interval(app.config.reconciliation_interval);
    reconciliation_timer.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    reconciliation_timer.tick().await; // consume the immediate first tick — bootstrap just ran

    // nginx_child.wait()/shutdown_signal() are the only two conditions that end the process;
    // Redis events and the reconciliation timer are recurring reactions that must never do so.
    let status = loop {
        tokio::select! {
            status = nginx_child.wait() => {
                break status.context("waiting on nginx child")?;
            }
            _ = shutdown_signal() => {
                tracing::info!("received shutdown signal, stopping nginx");
                let _ = std::process::Command::new(&app.config.nginx_binary_path)
                    .arg("-s").arg("quit").arg("-c").arg(&app.config.nginx_conf_path).status();
                break nginx_child.wait().await.context("waiting on nginx child after shutdown signal")?;
            }
            // `Some(event) = ...` disables this branch permanently (not a hot loop) once the
            // channel closes — which only happens when main exits, since the subscriber task
            // only returns when `event_rx` itself is dropped.
            Some(event) = event_rx.recv() => {
                tracing::info!(
                    event_type = %event.event_type,
                    subject_id = event.subject_id,
                    event_id = %event.id,
                    "received domain event"
                );
                match sync::sync_incremental(&app, &event).await {
                    Ok(outcome) if outcome.applied.is_empty() && outcome.removed.is_empty() => {
                        tracing::debug!(event_id = %event.id, "incremental sync: nothing changed");
                    }
                    Ok(outcome) => {
                        tracing::info!(
                            applied = ?outcome.applied,
                            removed = ?outcome.removed,
                            failed = ?outcome.failed,
                            "incremental sync applied changes, reloading nginx"
                        );
                        if let Err(err) = nginx::reload(&app.config.nginx_binary_path, &app.config.nginx_conf_path) {
                            tracing::error!(error = %err, "nginx reload failed after incremental sync");
                        }
                    }
                    Err(err) => tracing::error!(error = %err, event_id = %event.id, "incremental sync failed for event"),
                }
            }
            _ = reconciliation_timer.tick() => {
                tracing::info!("running periodic full reconciliation");
                match sync::run_bootstrap(&app).await {
                    Ok(outcome) if outcome.applied.is_empty() && outcome.removed.is_empty() => {
                        tracing::debug!("periodic reconciliation: nothing changed");
                    }
                    Ok(outcome) => {
                        tracing::info!(
                            applied = ?outcome.applied,
                            removed = ?outcome.removed,
                            failed = ?outcome.failed,
                            "periodic reconciliation applied changes, reloading nginx"
                        );
                        if let Err(err) = nginx::reload(&app.config.nginx_binary_path, &app.config.nginx_conf_path) {
                            tracing::error!(error = %err, "nginx reload failed after periodic reconciliation");
                        }
                    }
                    Err(err) => tracing::error!(error = %err, "periodic reconciliation failed"),
                }
            }
        }
    };

    let _ = std::fs::remove_file(&app.config.healthcheck_marker_path);

    if !status.success() {
        anyhow::bail!("nginx exited unexpectedly with status {status}");
    }
    Ok(())
}

async fn shutdown_signal() {
    let ctrl_c = tokio::signal::ctrl_c();

    #[cfg(unix)]
    {
        let mut term = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .expect("install SIGTERM handler");
        tokio::select! {
            _ = ctrl_c => {},
            _ = term.recv() => {},
        };
    }

    #[cfg(not(unix))]
    {
        let _ = ctrl_c.await;
    }
}
