#!/bin/sh
set -eu
umask 077
[ "${APP_ENV:-}" = production ] && [ "${APP_DEBUG:-}" = false ] || { echo 'Production configuration refused.' >&2; exit 1; }
[ -n "${APP_KEY:-}" ] && [ -n "${DB_PASSWORD:-}" ] || { echo 'Runtime secrets missing.' >&2; exit 1; }
mkdir -p /app/storage/framework/views /app/storage/framework/cache /app/storage/framework/sessions /app/bootstrap/cache
# No config cache, migrations or seeding here. Secrets exist only in runtime environment.
exec "$@"
