use std::path::Path;

use anyhow::{bail, Context};

/// Abstracts "is the current config tree valid" so `apply_domain_config`/`remove_domain_config`
/// are unit-testable without a real `nginx` binary.
pub trait ConfigTester {
    fn test(&self) -> anyhow::Result<()>;
}

pub struct RealNginxTester<'a> {
    pub binary_path: &'a str,
    pub main_conf_path: &'a Path,
}

impl ConfigTester for RealNginxTester<'_> {
    fn test(&self) -> anyhow::Result<()> {
        let out = std::process::Command::new(self.binary_path)
            .arg("-t")
            .arg("-c")
            .arg(self.main_conf_path)
            .output()
            .context("failed to invoke nginx -t")?;

        if out.status.success() {
            Ok(())
        } else {
            bail!("nginx -t failed: {}", String::from_utf8_lossy(&out.stderr));
        }
    }
}

pub fn sanitize_domain_filename(domain: &str) -> anyhow::Result<String> {
    let ok = !domain.is_empty()
        && domain
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.')
        && !domain.contains("..");

    if !ok {
        bail!("refusing to use unsafe domain value as filename: {domain:?}");
    }

    Ok(domain.to_ascii_lowercase())
}

/// Renders one domain's config into `conf.d/`, testing the *whole* config tree afterward (that's
/// the only way `nginx -t` can validate anything) and reverting just this domain's file if the
/// test fails — so one bad domain never blocks every other domain's valid changes.
pub fn apply_domain_config(
    conf_d_path: &Path,
    domain: &str,
    rendered: &str,
    tester: &dyn ConfigTester,
) -> anyhow::Result<()> {
    let filename = sanitize_domain_filename(domain)?;
    let target = conf_d_path.join(format!("{filename}.conf"));
    let tmp = conf_d_path.join(format!("{filename}.conf.tmp"));

    let previous = match std::fs::read_to_string(&target) {
        Ok(c) => Some(c),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
        Err(e) => return Err(e).context("reading existing config before swap"),
    };

    std::fs::write(&tmp, rendered).context("writing candidate config")?;
    std::fs::rename(&tmp, &target).context("swapping candidate config into place")?;

    if let Err(test_err) = tester.test() {
        match &previous {
            Some(content) => std::fs::write(&target, content)
                .context("restoring previous config after failed nginx -t")?,
            None => std::fs::remove_file(&target)
                .context("removing invalid new config after failed nginx -t")?,
        }
        return Err(test_err.context(format!(
            "nginx -t rejected config for domain {domain}; reverted"
        )));
    }

    Ok(())
}

/// Same isolation guarantee as `apply_domain_config`, for domains that dropped out of the active
/// set (disabled or deleted) and need their rendered file removed.
pub fn remove_domain_config(
    conf_d_path: &Path,
    domain: &str,
    tester: &dyn ConfigTester,
) -> anyhow::Result<()> {
    let filename = sanitize_domain_filename(domain)?;
    let target = conf_d_path.join(format!("{filename}.conf"));

    let previous = match std::fs::read_to_string(&target) {
        Ok(c) => c,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(e) => return Err(e).context("reading config before removal"),
    };

    std::fs::remove_file(&target).context("removing stale domain config")?;

    if let Err(test_err) = tester.test() {
        // Defensive: removing a file shouldn't normally break validation, but never leave the
        // tree in a broken state if it somehow does.
        let _ = std::fs::write(&target, previous);
        return Err(test_err.context(format!(
            "nginx -t failed after removing domain {domain}; restored it"
        )));
    }

    Ok(())
}

pub fn spawn(binary_path: &str, main_conf_path: &Path) -> anyhow::Result<tokio::process::Child> {
    tokio::process::Command::new(binary_path)
        .arg("-c")
        .arg(main_conf_path)
        .arg("-g")
        .arg("daemon off;")
        .stdout(std::process::Stdio::inherit())
        .stderr(std::process::Stdio::inherit())
        .spawn()
        .context("failed to spawn nginx")
}

/// Not called by the bootstrap flow in this phase (nginx isn't running yet when bootstrap
/// applies its changes) — written and unit-testable now so Fase 6's incremental-sync path can
/// call it once nginx is already up.
pub fn reload(binary_path: &str, main_conf_path: &Path) -> anyhow::Result<()> {
    let out = std::process::Command::new(binary_path)
        .arg("-s")
        .arg("reload")
        .arg("-c")
        .arg(main_conf_path)
        .output()
        .context("failed to invoke nginx -s reload")?;

    if out.status.success() {
        Ok(())
    } else {
        bail!(
            "nginx -s reload failed: {}",
            String::from_utf8_lossy(&out.stderr)
        );
    }
}

pub async fn wait_until_serving(
    addr: &str,
    timeout: std::time::Duration,
    child: &mut tokio::process::Child,
) -> anyhow::Result<()> {
    let deadline = tokio::time::Instant::now() + timeout;

    loop {
        if let Some(status) = child.try_wait().context("checking nginx process status")? {
            bail!("nginx exited during startup with status {status}");
        }

        if tokio::net::TcpStream::connect(addr).await.is_ok() {
            return Ok(());
        }

        if tokio::time::Instant::now() >= deadline {
            bail!("nginx did not start accepting connections on {addr} within {timeout:?}");
        }

        tokio::time::sleep(std::time::Duration::from_millis(200)).await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct AlwaysOk;
    impl ConfigTester for AlwaysOk {
        fn test(&self) -> anyhow::Result<()> {
            Ok(())
        }
    }

    struct AlwaysFail;
    impl ConfigTester for AlwaysFail {
        fn test(&self) -> anyhow::Result<()> {
            bail!("simulated nginx -t failure")
        }
    }

    fn unique_temp_dir(name: &str) -> std::path::PathBuf {
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let dir = std::env::temp_dir().join(format!("apy-agent-test-{name}-{nanos}"));
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    #[test]
    fn sanitize_domain_filename_rejects_unsafe_values() {
        assert!(sanitize_domain_filename("example.com").is_ok());
        assert!(sanitize_domain_filename("").is_err());
        assert!(sanitize_domain_filename("../etc/passwd").is_err());
        assert!(sanitize_domain_filename("a/b").is_err());
    }

    #[test]
    fn apply_new_domain_with_passing_test_keeps_the_file() {
        let dir = unique_temp_dir("apply-new-ok");
        apply_domain_config(&dir, "example.com", "server {}", &AlwaysOk).unwrap();
        assert_eq!(std::fs::read_to_string(dir.join("example.com.conf")).unwrap(), "server {}");
    }

    #[test]
    fn apply_new_domain_with_failing_test_removes_the_file() {
        let dir = unique_temp_dir("apply-new-fail");
        let result = apply_domain_config(&dir, "example.com", "server {}", &AlwaysFail);
        assert!(result.is_err());
        assert!(!dir.join("example.com.conf").exists());
    }

    #[test]
    fn apply_update_with_failing_test_restores_previous_content() {
        let dir = unique_temp_dir("apply-update-fail");
        std::fs::write(dir.join("example.com.conf"), "old content").unwrap();

        let result = apply_domain_config(&dir, "example.com", "new content", &AlwaysFail);

        assert!(result.is_err());
        assert_eq!(std::fs::read_to_string(dir.join("example.com.conf")).unwrap(), "old content");
    }

    #[test]
    fn remove_stale_domain_with_passing_test_deletes_the_file() {
        let dir = unique_temp_dir("remove-ok");
        std::fs::write(dir.join("example.com.conf"), "content").unwrap();

        remove_domain_config(&dir, "example.com", &AlwaysOk).unwrap();

        assert!(!dir.join("example.com.conf").exists());
    }

    #[test]
    fn remove_missing_domain_is_a_no_op() {
        let dir = unique_temp_dir("remove-missing");
        remove_domain_config(&dir, "example.com", &AlwaysOk).unwrap();
    }
}
