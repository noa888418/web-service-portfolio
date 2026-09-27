<?php

// Test-only entrypoint. Never routed from public/index.php.
require dirname(__DIR__).'/vendor/autoload.php';
Tests\Support\TestDatabaseGuard::connect();
$schema = getenv('AUTH_TEST_SCHEMA');
if (! is_string($schema) || ! preg_match('/\Ausers_test_[a-f0-9]{24}\z/D', $schema)) {
    throw new RuntimeException('HTTP test schema refused.');
}
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app['config']->set('database.connections.pgsql.search_path', $schema);
if ($app['db']->connection()->selectOne('SELECT current_schema() AS name')->name !== $schema) {
    throw new RuntimeException('HTTP test schema identity refused.');
}
$clock = getenv('AUTH_TEST_CLOCK');
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC((int) file_get_contents($clock)));
// Synchronization barriers exist only in this guarded test entrypoint.
Illuminate\Support\Facades\Route::get('/_test/hold', function () {
    touch(getenv('AUTH_TEST_BARRIER').'.entered');
    $deadline = microtime(true) + 12;
    while (! is_file(getenv('AUTH_TEST_BARRIER').'.release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }

    return response()->noContent();
})->middleware(['web', App\Http\Middleware\AuthenticatedSession::class]);
Illuminate\Support\Facades\Route::post('/_test/stop', function (Illuminate\Http\Request $request) {
    app(App\Auth\AccountSessions::class)->revoke((string) $request->json('user'));
    return response()->noContent();
})->middleware('web');
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
