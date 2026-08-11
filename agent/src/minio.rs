use std::path::{Path, PathBuf};

use anyhow::Context;
use aws_sdk_s3::config::{BehaviorVersion, Builder, Credentials, Region};

use crate::config::MinioConfig;

/// Placeholder convention — Fase 7 (certificate issuance) owns the real upload contract and may
/// redefine this. For now, a `ssl_certificates.storage_path` maps deterministically to two
/// objects in the bucket.
pub struct CertObjectKeys {
    pub crt: String,
    pub key: String,
}

pub fn cert_object_keys(storage_path: &str) -> CertObjectKeys {
    CertObjectKeys {
        crt: format!("{storage_path}.crt"),
        key: format!("{storage_path}.key"),
    }
}

pub struct CertPaths {
    pub crt_path: PathBuf,
    pub key_path: PathBuf,
}

pub struct MinioClient {
    s3: aws_sdk_s3::Client,
    bucket: String,
}

impl MinioClient {
    pub fn connect(cfg: &MinioConfig) -> anyhow::Result<Self> {
        let creds = Credentials::new(
            &cfg.access_key,
            &cfg.secret_key,
            None,
            None,
            "apy-agent-static",
        );

        let s3_config = Builder::new()
            .region(Region::new(cfg.region.clone()))
            .endpoint_url(&cfg.endpoint_url)
            .credentials_provider(creds)
            .force_path_style(cfg.force_path_style)
            .behavior_version(BehaviorVersion::latest())
            .build();

        Ok(Self {
            s3: aws_sdk_s3::Client::from_conf(s3_config),
            bucket: cfg.bucket.clone(),
        })
    }

    async fn download_object(&self, key: &str) -> anyhow::Result<Vec<u8>> {
        let out = self
            .s3
            .get_object()
            .bucket(&self.bucket)
            .key(key)
            .send()
            .await
            .with_context(|| format!("downloading s3://{}/{key}", self.bucket))?;

        let bytes = out
            .body
            .collect()
            .await
            .context("reading object body")?;

        Ok(bytes.into_bytes().to_vec())
    }

    /// Downloads the certificate chain and private key for `storage_path` into `dest_dir`.
    ///
    /// `content_hash` verification is intentionally not implemented here — nothing populates
    /// `ssl_certificates.content_hash` with a defined meaning yet (Fase 7 decides that).
    pub async fn download_certificate(
        &self,
        storage_path: &str,
        dest_dir: &Path,
    ) -> anyhow::Result<CertPaths> {
        let keys = cert_object_keys(storage_path);
        let crt_bytes = self
            .download_object(&keys.crt)
            .await
            .context("downloading certificate chain")?;
        let key_bytes = self
            .download_object(&keys.key)
            .await
            .context("downloading certificate private key")?;

        std::fs::create_dir_all(dest_dir)
            .with_context(|| format!("creating certificate directory {}", dest_dir.display()))?;

        let crt_path = dest_dir.join("fullchain.pem");
        let key_path = dest_dir.join("privkey.pem");
        std::fs::write(&crt_path, &crt_bytes).context("writing certificate chain to disk")?;
        std::fs::write(&key_path, &key_bytes).context("writing certificate private key to disk")?;

        // Private key material — never world/group readable, never logged (SPEC.md §15).
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            std::fs::set_permissions(&key_path, std::fs::Permissions::from_mode(0o600))
                .context("restricting private key file permissions")?;
        }

        Ok(CertPaths { crt_path, key_path })
    }
}
