use anyhow::Context;

#[derive(Debug, Clone, serde::Serialize)]
pub struct SslTemplateContext {
    pub crt_path: String,
    pub key_path: String,
}

#[derive(Debug, Clone)]
pub struct ProxyHostTemplateContext {
    pub domain: String,
    pub forward_scheme: String,
    pub forward_host: String,
    pub forward_port: u16,
    pub websockets_enabled: bool,
    pub ssl: Option<SslTemplateContext>,
}

pub struct TemplateEngine {
    tera: tera::Tera,
}

impl TemplateEngine {
    pub fn load(templates_dir: &std::path::Path) -> anyhow::Result<Self> {
        let pattern = format!("{}/*.tera", templates_dir.display());
        let tera = tera::Tera::new(&pattern)
            .with_context(|| format!("failed to parse Nginx Tera templates from {pattern}"))?;
        Ok(Self { tera })
    }

    pub fn render_proxy_host(&self, ctx: &ProxyHostTemplateContext) -> anyhow::Result<String> {
        let mut context = tera::Context::new();
        context.insert("domain", &ctx.domain);
        context.insert("forward_scheme", &ctx.forward_scheme);
        context.insert("forward_host", &ctx.forward_host);
        context.insert("forward_port", &ctx.forward_port);
        context.insert("websockets_enabled", &ctx.websockets_enabled);
        context.insert("ssl", &ctx.ssl);

        self.tera
            .render("proxy_host.conf.tera", &context)
            .with_context(|| format!("failed to render proxy_host template for {}", ctx.domain))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // Loads the templates that actually ship in the image (sibling `nginx/templates` directory)
    // so these tests can never drift from what production renders.
    fn engine() -> TemplateEngine {
        let dir = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../nginx/templates");
        TemplateEngine::load(&dir).unwrap()
    }

    fn base_ctx() -> ProxyHostTemplateContext {
        ProxyHostTemplateContext {
            domain: "example.com".to_string(),
            forward_scheme: "http".to_string(),
            forward_host: "10.0.0.5".to_string(),
            forward_port: 3000,
            websockets_enabled: false,
            ssl: None,
        }
    }

    #[test]
    fn renders_plain_http_server_block_without_ssl() {
        let rendered = engine().render_proxy_host(&base_ctx()).unwrap();
        assert!(rendered.contains("listen 80;"));
        assert!(!rendered.contains("listen 443"));
        assert!(rendered.contains("proxy_pass http://10.0.0.5:3000;"));
        assert!(!rendered.contains("websocket.conf"));
        assert!(rendered.contains("include /etc/nginx/snippets/acme-challenge.conf;"));
    }

    #[test]
    fn renders_ssl_server_block_with_redirect_when_certificate_present() {
        let mut ctx = base_ctx();
        ctx.ssl = Some(SslTemplateContext {
            crt_path: "/certs/example.com/fullchain.pem".to_string(),
            key_path: "/certs/example.com/privkey.pem".to_string(),
        });

        let rendered = engine().render_proxy_host(&ctx).unwrap();

        assert!(rendered.contains("listen 443 ssl;"));
        assert!(rendered.contains("return 301 https://$host$request_uri;"));
        assert!(rendered.contains("ssl_certificate     /certs/example.com/fullchain.pem;"));
        assert!(rendered.contains("ssl_certificate_key /certs/example.com/privkey.pem;"));
        // The :80 block must serve the ACME challenge itself, before falling through to the
        // unconditional redirect — otherwise HTTP-01 validation could never reach it.
        assert!(rendered.contains("include /etc/nginx/snippets/acme-challenge.conf;"));
    }

    #[test]
    fn ssl_branch_redirect_is_scoped_to_location_not_a_bare_server_level_return() {
        let mut ctx = base_ctx();
        ctx.ssl = Some(SslTemplateContext {
            crt_path: "/certs/example.com/fullchain.pem".to_string(),
            key_path: "/certs/example.com/privkey.pem".to_string(),
        });

        let rendered = engine().render_proxy_host(&ctx).unwrap();

        // A bare `return` directly in `server {}` would fire unconditionally ahead of any
        // `location`, including the acme-challenge one — the redirect must be nested inside its
        // own `location /` so `location ^~ /.well-known/acme-challenge/` (higher-priority prefix
        // match) can actually win for ACME requests.
        assert!(rendered.contains("location / {\n        return 301 https://$host$request_uri;\n    }"));
    }

    #[test]
    fn includes_websocket_snippet_only_when_enabled() {
        let mut ctx = base_ctx();
        ctx.websockets_enabled = true;

        let rendered = engine().render_proxy_host(&ctx).unwrap();

        assert!(rendered.contains("include /etc/nginx/snippets/websocket.conf;"));
    }
}
