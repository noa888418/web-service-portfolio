<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';

// No RefreshDatabase/migrate:fresh: even the test database's existing data is kept.
$guardConnection = TestDatabaseGuard::connect();
$schema = 'users_test_'.bin2hex(random_bytes(12));
$guardConnection->exec('CREATE SCHEMA "'.$schema.'"');
register_shutdown_function(static function () use ($guardConnection, $schema): void {
    // $schema is generated above, never read from a URL, .env or command argument.
    $guardConnection->exec('DROP SCHEMA "'.$schema.'" CASCADE');
});

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app['config']->set('database.connections.pgsql.search_path', $schema);
$app['config']->set('database.connections.pgsql.host', 'test-db');
$app['config']->set('database.connections.pgsql.database', 'portfolio_test');
$app['config']->set('database.connections.pgsql.username', 'portfolio_test');
$connection = $app['db']->connection();
if ($connection->selectOne('SELECT current_schema() AS name')->name !== $schema) {
    throw new RuntimeException('Test database guard refused schema.');
}
if ($app->make(Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Migration failed in the isolated test schema.');
}
$GLOBALS['users_test_app'] = $app;
$GLOBALS['users_test_schema'] = $schema;
