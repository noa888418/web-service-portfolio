<?php

// Test-only command worker; guard the real test DB before any config overrides.
require dirname(__DIR__).'/vendor/autoload.php';
Tests\Support\TestDatabaseGuard::connect();
$schema = getenv('SEED_TEST_SCHEMA');
if (! is_string($schema) || ! preg_match('/\Ausers_test_[a-f0-9]{24}\z/D', $schema)) { throw new RuntimeException('Seed test schema refused.'); }
putenv(getenv('SEED_TEST_PRODUCTION') === '1' ? 'APP_ENV=production' : 'APP_ENV=local');
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app['config']->set('database.connections.pgsql.search_path', $schema);
if ($app['db']->connection()->selectOne('SELECT current_schema() AS name')->name !== $schema) { throw new RuntimeException('Seed schema identity refused.'); }
$scenario = getenv('SEED_TEST_SCENARIO');
$entered = false;
Illuminate\Support\Facades\DB::listen(function ($event) use ($scenario, &$entered) {
    if ($entered || ! str_starts_with($event->sql, 'insert into "users"')) { return; }
    $entered = true;
    if ($scenario === 'fail') { throw new RuntimeException('Injected seed failure'); }
    if ($scenario === 'hold') {
        touch(getenv('SEED_TEST_BARRIER').'.entered');
        $deadline = microtime(true) + 12;
        while (! is_file(getenv('SEED_TEST_BARRIER').'.release')) {
            if (microtime(true) >= $deadline) { throw new RuntimeException('Seed barrier timeout'); }
            usleep(10000);
        }
    }
});
$status = $kernel->handle(new Symfony\Component\Console\Input\ArgvInput);
$kernel->terminate(new Symfony\Component\Console\Input\ArgvInput, $status);
exit($status);
