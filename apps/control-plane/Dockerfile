# apy-gateway-control-plane (SPEC.md §14). Build context is this directory (apps/control-plane),
# self-contained — unlike nginx/Dockerfile, which needs the repo root.
#
#   docker build -f apps/control-plane/Dockerfile apps/control-plane
#
# One image, several roles (web/queue/scheduler/migrate — see docker/entrypoint.sh) — each is its
# own compose service in examples/docker-compose.yml, differing only by command. No process
# supervisor needed anywhere: FrankenPHP is a single binary for the web role, and the other roles
# are already single-process Artisan commands.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.3
# pdo_mysql: not present by default. pcntl: needed for `queue:work`'s graceful signal handling.
# Both confirmed to install cleanly against this exact base image before committing to this list.
RUN docker-php-ext-install pdo_mysql pcntl

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Deliberately no `config:cache`/`route:cache` here — `config:cache` freezes `env()` calls at
# build time, and this same image runs with different env per deployment (SPEC.md §13.3); caching
# would bake one environment's values into an image meant to be environment-agnostic, a real
# correctness bug, not just a missed optimization.
ENV LOG_CHANNEL=stderr \
    SERVER_NAME=:80

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
