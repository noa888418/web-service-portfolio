#!/bin/sh
set -eu
DB_SCHEMA=$(cat /run/schema)
echo "$DB_SCHEMA" | grep -Eq '^users_test_[a-f0-9]{24}$' || exit 1
export DB_SCHEMA
exec /usr/local/bin/run-php php-fpm --nodaemonize --fpm-config /usr/local/etc/php-fpm.conf
