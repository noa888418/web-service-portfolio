<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function validateEnvironment(array $values): void
    {
        $required = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'test-db', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'portfolio_test', 'DB_USERNAME' => 'portfolio_test',
        ];
        foreach ($required as $key => $expected) {
            if (($values[$key] ?? null) !== $expected) {
                throw new RuntimeException('Test database guard refused configuration: '.$key);
            }
        }
        foreach (['DB_URL', 'DATABASE_URL', 'PGSERVICE', 'PGOPTIONS', 'DB_SOCKET', 'DB_SCHEMA', 'APP_CONFIG_CACHE'] as $key) {
            if (! empty($values[$key])) {
                throw new RuntimeException('Test database guard refused override: '.$key);
            }
        }
    }

    public static function connect(): PDO
    {
        self::validateEnvironment(getenv());
        if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Test database guard refuses cached Laravel configuration.');
        }
        $pdo = new PDO('pgsql:host=test-db;port=5432;dbname=portfolio_test;sslmode=disable',
            'portfolio_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $identity = $pdo->query(<<<'SQL'
            SELECT current_database() AS db, current_user AS username,
                shobj_description(d.oid, 'pg_database') AS marker, r.rolsuper
            FROM pg_database d JOIN pg_roles r ON r.rolname = current_user
            WHERE d.datname = current_database()
            SQL)->fetch(PDO::FETCH_ASSOC);
        if ($identity['db'] !== 'portfolio_test' || $identity['username'] !== 'portfolio_test'
            || $identity['marker'] !== 'portfolio-users-tests-only-v1' || $identity['rolsuper']) {
            throw new RuntimeException('Test database guard refused server identity.');
        }

        return $pdo;
    }
}
