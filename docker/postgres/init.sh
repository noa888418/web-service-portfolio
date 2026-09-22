#!/bin/sh
set -eu
# Sourced by the official entrypoint after it switches to the postgres user.
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set=ON_ERROR_STOP=1 --set=app_user="$APP_DB_USER" \
    --set=app_password="$APP_DB_PASSWORD" --set=app_db="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'app_password';
ALTER DATABASE :"app_db" OWNER TO :"app_user";
REVOKE CONNECT ON DATABASE :"app_db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"app_db" TO :"app_user";
SQL
if [ "$POSTGRES_DB" = portfolio_test ]; then
    psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=ON_ERROR_STOP=1 <<'SQL'
COMMENT ON DATABASE portfolio_test IS 'portfolio-users-tests-only-v1';
SQL
fi
