use std::path::Path;

use anyhow::Context;

use crate::app_context::AppContext;
use crate::db;
use crate::models::AcmeChallengeRow;

/// Reconciles the shared ACME challenge directory against the currently-pending set: writes any
/// missing token file (content = key_authorization), leaves already-correct files untouched, and
/// removes any file whose token is no longer pending (completed/invalid/expired/revoked cleanup).
///
/// Deliberately takes no `ConfigTester`/reload dependency — `nginx/snippets/acme-challenge.conf`
/// serves this directory as static files via `alias`, so Nginx needs no `-t`/reload to pick up a
/// new or removed file, unlike every other config change this agent applies.
pub fn sync_challenge_files(dir: &Path, pending: &[AcmeChallengeRow]) -> anyhow::Result<()> {
    std::fs::create_dir_all(dir).with_context(|| format!("creating {}", dir.display()))?;

    let pending_tokens: std::collections::HashSet<&str> =
        pending.iter().map(|c| c.token.as_str()).collect();

    for challenge in pending {
        let path = dir.join(&challenge.token);
        let already_correct = std::fs::read(&path)
            .map(|existing| existing == challenge.key_authorization.as_bytes())
            .unwrap_or(false);
        if already_correct {
            continue;
        }

        let tmp_path = dir.join(format!("{}.tmp", challenge.token));
        std::fs::write(&tmp_path, &challenge.key_authorization)
            .with_context(|| format!("writing {}", tmp_path.display()))?;
        std::fs::rename(&tmp_path, &path)
            .with_context(|| format!("renaming {} into place", path.display()))?;
    }

    for entry in std::fs::read_dir(dir).with_context(|| format!("reading {}", dir.display()))? {
        let entry = entry?;
        let file_name = entry.file_name();
        let Some(name) = file_name.to_str() else { continue };
        if name.ends_with(".tmp") {
            continue;
        }
        if !pending_tokens.contains(name) {
            std::fs::remove_file(entry.path())
                .with_context(|| format!("removing stale challenge file {}", entry.path().display()))?;
        }
    }

    Ok(())
}

/// Fetches the current pending set from MySQL and reconciles the local directory against it.
pub async fn sync_challenges(app: &AppContext) -> anyhow::Result<()> {
    let pending = db::fetch_pending_acme_challenges(&app.pool).await?;
    sync_challenge_files(&app.config.acme_challenge_dir, &pending)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn unique_temp_dir(name: &str) -> std::path::PathBuf {
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        std::env::temp_dir().join(format!("apy-agent-test-acme-{name}-{nanos}"))
    }

    fn row(token: &str, key_authorization: &str) -> AcmeChallengeRow {
        AcmeChallengeRow {
            token: token.to_string(),
            key_authorization: key_authorization.to_string(),
        }
    }

    #[test]
    fn creates_directory_and_writes_missing_files() {
        let dir = unique_temp_dir("write-missing");

        sync_challenge_files(&dir, &[row("token-a", "token-a.thumbprint")]).unwrap();

        assert_eq!(
            std::fs::read_to_string(dir.join("token-a")).unwrap(),
            "token-a.thumbprint"
        );
    }

    #[test]
    fn leaves_already_correct_files_untouched() {
        let dir = unique_temp_dir("idempotent");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("token-a"), "token-a.thumbprint").unwrap();

        sync_challenge_files(&dir, &[row("token-a", "token-a.thumbprint")]).unwrap();

        assert_eq!(
            std::fs::read_to_string(dir.join("token-a")).unwrap(),
            "token-a.thumbprint"
        );
    }

    #[test]
    fn removes_files_no_longer_pending() {
        let dir = unique_temp_dir("cleanup");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("stale-token"), "stale content").unwrap();

        sync_challenge_files(&dir, &[]).unwrap();

        assert!(!dir.join("stale-token").exists());
    }

    #[test]
    fn writes_new_and_removes_stale_in_the_same_pass() {
        let dir = unique_temp_dir("mixed");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("stale-token"), "stale content").unwrap();

        sync_challenge_files(&dir, &[row("fresh-token", "fresh.thumbprint")]).unwrap();

        assert!(!dir.join("stale-token").exists());
        assert_eq!(
            std::fs::read_to_string(dir.join("fresh-token")).unwrap(),
            "fresh.thumbprint"
        );
    }

    #[test]
    fn overwrites_a_file_whose_content_changed() {
        let dir = unique_temp_dir("overwrite");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("token-a"), "old-content").unwrap();

        sync_challenge_files(&dir, &[row("token-a", "new-content")]).unwrap();

        assert_eq!(std::fs::read_to_string(dir.join("token-a")).unwrap(), "new-content");
    }
}
