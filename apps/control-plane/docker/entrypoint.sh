#!/bin/sh
set -e

# Fase 11 (SPEC.md §13): one image, several roles — each is its own compose service,
# differing only by this first argument. The image itself stays a generic "dispatch by role"
# artifact; ordering between roles (e.g. migrate-before-web) is docker-compose's job (depends_on),
# never baked into this script's control flow.
case "$1" in
  web)
    exec frankenphp run --config /etc/frankenphp/Caddyfile
    ;;
  queue)
    exec php artisan queue:work --tries=3 --backoff=3
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  migrate)
    exec php artisan migrate --force
    ;;
  *)
    exec "$@"
    ;;
esac
