use std::path::{Path, PathBuf};

use anyhow::Context;

/// Local, on-disk layout of the certificates a replica serves:
///
/// ```text
/// {cert_storage_dir}/{domain}/{certificate_id}-v{certificate_version}/fullchain.pem
///                                                                    /privkey.pem
/// ```
///
/// Every certificate version gets its own directory instead of overwriting `fullchain.pem` in
/// place. Why: `nginx -t` validates the *whole* tree, so if a renewed certificate is broken and
/// overwrote the files the currently-live config points at, the domain's config would be reverted
/// (see `nginx::apply_domain_config`) but the broken files would stay — and every later `nginx -t`
/// for *any* domain would fail, blocking all changes on the replica. With versioned directories,
/// a rejected version is simply deleted and the previous one is still intact on disk.
pub fn version_dir(cert_storage_dir: &Path, domain: &str, certificate_id: u64, certificate_version: u32) -> PathBuf {
    cert_storage_dir
        .join(domain)
        .join(format!("{certificate_id}-v{certificate_version}"))
}

/// Removes every version directory of `domain` except `keep` — called only after the config that
/// references `keep` passed `nginx -t`, so nothing still referenced is ever deleted.
pub fn prune_other_versions(cert_storage_dir: &Path, domain: &str, keep: &Path) -> anyhow::Result<()> {
    let domain_dir = cert_storage_dir.join(domain);
    let entries = match std::fs::read_dir(&domain_dir) {
        Ok(entries) => entries,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(e) => return Err(e).with_context(|| format!("reading {}", domain_dir.display())),
    };

    for entry in entries {
        let path = entry?.path();
        if path == keep {
            continue;
        }
        remove_path(&path)?;
    }

    Ok(())
}

/// Removes all local certificate material for `domain` (host deleted/disabled, or certificate
/// detached). Missing directory is not an error — removal is idempotent.
pub fn remove_domain(cert_storage_dir: &Path, domain: &str) -> anyhow::Result<()> {
    let domain_dir = cert_storage_dir.join(domain);
    match std::fs::remove_dir_all(&domain_dir) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e).with_context(|| format!("removing {}", domain_dir.display())),
    }
}

/// Discards a version directory whose config was rejected by `nginx -t`.
pub fn discard_version(dir: &Path) -> anyhow::Result<()> {
    match std::fs::remove_dir_all(dir) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e).with_context(|| format!("removing {}", dir.display())),
    }
}

/// Writes a private key so it is never readable by anyone but the agent's user, not even for the
/// instant between create and chmod (SPEC.md §15).
pub fn write_private_key(path: &Path, bytes: &[u8]) -> anyhow::Result<()> {
    use std::io::Write;

    let mut options = std::fs::OpenOptions::new();
    options.write(true).create(true).truncate(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }

    let mut file = options
        .open(path)
        .with_context(|| format!("creating {}", path.display()))?;
    file.write_all(bytes)
        .with_context(|| format!("writing {}", path.display()))
}

fn remove_path(path: &Path) -> anyhow::Result<()> {
    let result = if path.is_dir() {
        std::fs::remove_dir_all(path)
    } else {
        std::fs::remove_file(path)
    };
    result.with_context(|| format!("removing {}", path.display()))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn unique_temp_dir(name: &str) -> PathBuf {
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let dir = std::env::temp_dir().join(format!("apy-agent-test-cert-store-{name}-{nanos}"));
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    #[test]
    fn version_dir_is_unique_per_certificate_id_and_version() {
        let base = Path::new("/certs");
        assert_eq!(version_dir(base, "example.com", 7, 2), PathBuf::from("/certs/example.com/7-v2"));
        assert_ne!(version_dir(base, "example.com", 7, 2), version_dir(base, "example.com", 7, 3));
        assert_ne!(version_dir(base, "example.com", 7, 2), version_dir(base, "example.com", 8, 2));
    }

    #[test]
    fn prune_keeps_only_the_live_version() {
        let base = unique_temp_dir("prune");
        let old = version_dir(&base, "example.com", 1, 1);
        let live = version_dir(&base, "example.com", 1, 2);
        std::fs::create_dir_all(&old).unwrap();
        std::fs::create_dir_all(&live).unwrap();
        // Layout used before versioned directories existed — must be cleaned up too.
        std::fs::write(base.join("example.com").join("fullchain.pem"), "legacy").unwrap();

        prune_other_versions(&base, "example.com", &live).unwrap();

        assert!(live.exists());
        assert!(!old.exists());
        assert!(!base.join("example.com").join("fullchain.pem").exists());
    }

    #[test]
    fn prune_on_missing_domain_dir_is_a_no_op() {
        let base = unique_temp_dir("prune-missing");
        prune_other_versions(&base, "example.com", &base.join("x")).unwrap();
    }

    #[test]
    fn remove_domain_is_idempotent() {
        let base = unique_temp_dir("remove");
        std::fs::create_dir_all(version_dir(&base, "example.com", 1, 1)).unwrap();

        remove_domain(&base, "example.com").unwrap();
        remove_domain(&base, "example.com").unwrap();

        assert!(!base.join("example.com").exists());
    }

    #[cfg(unix)]
    #[test]
    fn private_key_is_created_with_owner_only_permissions() {
        use std::os::unix::fs::PermissionsExt;

        let base = unique_temp_dir("key-perms");
        let key = base.join("privkey.pem");

        write_private_key(&key, b"secret").unwrap();

        let mode = std::fs::metadata(&key).unwrap().permissions().mode() & 0o777;
        assert_eq!(mode, 0o600);
        assert_eq!(std::fs::read(&key).unwrap(), b"secret");
    }
}
