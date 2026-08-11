# Dev/CI-only image for the Rust agent. NOT the production image — see nginx/Dockerfile
# (SPEC.md §14). Exists so `cargo build`/`cargo test` run in a reproducible container.
#
# Build context must be the repo root (not agent/) so tests that load the real
# nginx/templates directory (../nginx/templates relative to this crate) can find it, e.g.:
#   docker build -f agent/Dockerfile -t apy-agent-dev .
FROM rust:1-alpine
RUN apk add --no-cache musl-dev ca-certificates
WORKDIR /repo
COPY agent/Cargo.toml agent/Cargo.lock* agent/
COPY agent/src agent/src
COPY nginx/templates nginx/templates
WORKDIR /repo/agent
RUN cargo build
CMD ["cargo", "test"]
