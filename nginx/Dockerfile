# apy-gateway-nginx (SPEC.md §14): agent binary compiled here, packaged with Nginx.
# Both stages are Alpine/musl so the stage-1 binary runs unmodified in stage 2.
#
# Build context must be the repo root:
#   docker build -f nginx/Dockerfile -t apy-gateway-nginx .

FROM rust:1-alpine AS agent-build
RUN apk add --no-cache musl-dev ca-certificates
WORKDIR /agent
COPY agent/Cargo.toml agent/Cargo.lock* ./
COPY agent/src ./src
RUN cargo build --release

FROM nginx:1.27-alpine
RUN apk add --no-cache ca-certificates
COPY --from=agent-build /agent/target/release/apy-agent /usr/local/bin/apy-agent
COPY nginx/nginx.conf   /etc/nginx/nginx.conf
COPY nginx/templates    /etc/apy-agent/templates
COPY nginx/snippets     /etc/nginx/snippets
RUN mkdir -p /etc/nginx/conf.d /var/lib/apy-agent/certs /var/run/apy-agent \
    && rm -f /etc/nginx/conf.d/default.conf

ENV NGINX_CONF_PATH=/etc/nginx/nginx.conf \
    NGINX_CONF_D_PATH=/etc/nginx/conf.d \
    NGINX_SNIPPETS_DIR=/etc/nginx/snippets \
    NGINX_TEMPLATES_DIR=/etc/apy-agent/templates \
    STATE_FILE_PATH=/var/lib/apy-agent/state.json \
    CERT_STORAGE_DIR=/var/lib/apy-agent/certs \
    HEALTHCHECK_MARKER_PATH=/var/run/apy-agent/ready \
    NGINX_READINESS_CHECK_ADDR=127.0.0.1:80

HEALTHCHECK --interval=5s --timeout=3s --start-period=30s --retries=3 \
    CMD test -f "$HEALTHCHECK_MARKER_PATH" || exit 1

ENTRYPOINT ["/usr/local/bin/apy-agent"]
