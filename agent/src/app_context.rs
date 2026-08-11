use anyhow::Context;

use crate::config::AppConfig;
use crate::minio::MinioClient;
use crate::templates::TemplateEngine;

pub struct AppContext {
    pub config: AppConfig,
    pub pool: sqlx::MySqlPool,
    pub minio: MinioClient,
    pub templates: TemplateEngine,
}

impl AppContext {
    pub async fn build(config: AppConfig) -> anyhow::Result<Self> {
        std::fs::create_dir_all(&config.nginx_conf_d_path).with_context(|| {
            format!("creating {}", config.nginx_conf_d_path.display())
        })?;
        std::fs::create_dir_all(&config.cert_storage_dir)
            .with_context(|| format!("creating {}", config.cert_storage_dir.display()))?;
        std::fs::create_dir_all(&config.acme_challenge_dir)
            .with_context(|| format!("creating {}", config.acme_challenge_dir.display()))?;
        if let Some(p) = config.state_file_path.parent() {
            std::fs::create_dir_all(p).with_context(|| format!("creating {}", p.display()))?;
        }
        if let Some(p) = config.healthcheck_marker_path.parent() {
            std::fs::create_dir_all(p).with_context(|| format!("creating {}", p.display()))?;
        }

        let pool = crate::db::connect(&config.db).await?;
        let minio = MinioClient::connect(&config.minio)?;
        let templates = TemplateEngine::load(&config.nginx_templates_dir)?;

        Ok(Self {
            config,
            pool,
            minio,
            templates,
        })
    }
}
