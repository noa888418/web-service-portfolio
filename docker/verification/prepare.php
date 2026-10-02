<?php
// Explicit, separate one-shot preparation. Never used as an HTTP router.
require '/app/vendor/autoload.php';
require '/verification/TestDatabaseGuard.php';
$stage = 'database guard';
try {
    $pdo = Tests\Support\TestDatabaseGuard::connect(); // Original guard, unchanged.
    if (trim(file_get_contents('/run/production/schema')) !== '') {
        throw new RuntimeException('Already prepared');
    }
    $stage = 'schema and bootstrap';
    $schema = 'users_test_'.bin2hex(random_bytes(12));
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');
    putenv('APP_ENV=production'); putenv('DB_SCHEMA='.$schema);
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
    $_ENV['DB_SCHEMA'] = $_SERVER['DB_SCHEMA'] = $schema;
    $app = require '/app/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $stage = 'production settings';
    if (config('app.env') !== 'production' || config('app.debug') || !config('session.secure')) { throw new RuntimeException; }
    $stage = 'migration';
    if ($kernel->call('migrate', ['--force' => true]) !== 0) { throw new RuntimeException; }
    $stage = 'credentials';
    $accounts = [];
    foreach (App\Demo\DemoSeeder::ACCOUNTS as $key => [$email]) {
        $accounts[$key] = ['email' => $email, 'password' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
    }
    $path = '/run/production/credentials.json';
    $file = fopen($path, 'x');
    fwrite($file, json_encode(['accounts' => $accounts], JSON_THROW_ON_ERROR)); fclose($file); chmod($path, 0600);
    $stage = 'guarded seed';
    config(['demo.purpose' => 'demo']);
    app(App\Demo\DemoSeeder::class)->run('test', 'production-integration', '1', true, $path);
    file_put_contents('/run/production/schema', $schema);
    echo "Prepared guarded isolated schema with production settings; values omitted.\n";
} catch (Throwable $error) { fwrite(STDERR, 'Preparation failed at '.$stage.' ('.get_class($error)."); values omitted.\n"); exit(1); }
