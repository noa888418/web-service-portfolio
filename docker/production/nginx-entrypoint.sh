#!/bin/sh
set -eu
case "${PHP_UPSTREAM:-}" in
  *[!a-zA-Z0-9.:-]*|'') echo 'Invalid PHP upstream.' >&2; exit 1 ;;
esac
sed "s/__PHP_UPSTREAM__/${PHP_UPSTREAM}/g" /etc/nginx/portfolio.conf.template > /tmp/nginx.conf
exec nginx -c /tmp/nginx.conf -g 'daemon off;'
