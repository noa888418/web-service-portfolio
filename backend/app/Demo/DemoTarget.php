<?php

namespace App\Demo;

use Illuminate\Support\Facades\DB;

final class DemoTarget
{
    public static function verify(string $target): void
    {
        $allowed = match ($target) {
            'local' => ['dev-db', 'portfolio_dev', 'portfolio_dev'],
            'test' => ['test-db', 'portfolio_test', 'portfolio_test'],
            default => throw new SeedRefused('target'),
        };
        $config = config('database.connections.pgsql');
        if (app()->configurationIsCached() || config('database.default') !== 'pgsql'
            || $config['driver'] !== 'pgsql' || (string) $config['port'] !== '5432'
            || [$config['host'], $config['database'], $config['username']] !== $allowed) {
            throw new SeedRefused('connection');
        }
        foreach (['DB_URL', 'DATABASE_URL', 'PGSERVICE', 'PGOPTIONS', 'DB_SOCKET', 'APP_CONFIG_CACHE'] as $name) {
            if (getenv($name)) { throw new SeedRefused('connection_override'); }
        }
        $schema = $config['search_path'];
        if (($target === 'local' && $schema !== 'public')
            || ($target === 'test' && ! preg_match('/\Ausers_test_[a-f0-9]{24}\z/D', $schema))) {
            throw new SeedRefused('schema');
        }
        // Inspect the live connection as well as its configured address, before writes.
        $identity = DB::selectOne("SELECT current_database() AS db, current_user AS username, session_user AS login,
            current_schema() AS schema, host(inet_server_addr()) AS address, inet_server_port() AS port,
            r.rolsuper, shobj_description(d.oid, 'pg_database') AS marker
            FROM pg_database d JOIN pg_roles r ON r.rolname = current_user WHERE d.datname = current_database()");
        if ($identity->db !== $allowed[1] || $identity->username !== $allowed[2] || $identity->login !== $allowed[2]
            || $identity->schema !== $schema || $identity->rolsuper || (int) $identity->port !== 5432
            || ! in_array($identity->address, gethostbynamel($allowed[0]) ?: [], true)
            || ($target === 'test' && $identity->marker !== 'portfolio-users-tests-only-v1')) {
            throw new SeedRefused('database_identity');
        }
    }
}
