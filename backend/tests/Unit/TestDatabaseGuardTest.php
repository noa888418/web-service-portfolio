<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

final class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('unsafeConfiguration')]
    public function test_guard_fails_closed_before_any_connection(string $key, string $value): void
    {
        $config = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'test-db', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'portfolio_test', 'DB_USERNAME' => 'portfolio_test',
        ];
        $config[$key] = $value;
        $this->expectException(RuntimeException::class);
        TestDatabaseGuard::validateEnvironment($config);
    }

    public static function unsafeConfiguration(): array
    {
        return [
            ['APP_ENV', 'local'], ['APP_ENV', 'production'], ['DB_CONNECTION', 'sqlite'],
            ['DB_HOST', 'dev-db'], ['DB_HOST', 'example.invalid'], ['DB_HOST', '127.0.0.1'],
            ['DB_PORT', '5433'], ['DB_DATABASE', 'portfolio_dev'], ['DB_USERNAME', 'postgres'],
            ['DB_URL', 'pgsql://example.invalid/db'], ['DATABASE_URL', 'pgsql://example.invalid/db'],
            ['PGSERVICE', 'external'], ['PGOPTIONS', '-c search_path=public'],
            ['DB_SOCKET', '/tmp'], ['DB_SCHEMA', 'public'],
            ['APP_CONFIG_CACHE', '/tmp/config.php'],
        ];
    }
}
