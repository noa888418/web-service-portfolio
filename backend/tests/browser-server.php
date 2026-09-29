<?php

// Isolated browser fixture only. The standard bootstrap guards the test DB and
// creates/migrates a new random schema; never use the development database.
require __DIR__.'/bootstrap.php';

use App\Demo\DemoSeeder;
use Illuminate\Support\Facades\DB;

$path = '/run/browser/credentials.json';
$server = null;
try {
    $accounts = [];
    foreach (DemoSeeder::ACCOUNTS as $key => [$email]) {
        $accounts[$key] = ['email' => $email, 'password' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
    }
    // Ephemeral isolated-test credentials, unrelated to .local or public demos.
    file_put_contents($path, json_encode(['accounts' => $accounts], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    config(['demo.purpose' => 'demo']);
    app(DemoSeeder::class)->run('test', 'browser-test', '1', true, $path);
    $owner = DB::table('users')->where('email', DemoSeeder::ACCOUNTS['employee_a'][0])->value('id');
    for ($i = 0; $i < 19; $i++) {
        DB::table('service_requests')->insert([
            'requester_id' => $owner,
            'title' => $i === 18 ? '<img src=x onerror="window.__xss=1"> <script>window.__xss=1</script>' : 'ページング検証 '.($i + 1),
            'body' => '隔離したブラウザーテスト専用の架空データ', 'category' => 'inquiry',
            'status' => 'open', 'version' => 1, 'created_at' => now()->addSeconds($i), 'updated_at' => now()->addSeconds($i),
        ]);
    }
    $env = array_merge(getenv(), ['APP_ENV' => 'local', 'DB_SCHEMA' => $GLOBALS['users_test_schema'],
        'APP_DEBUG' => 'false', 'SESSION_SECURE_COOKIE' => 'false']);
    $server = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-S', '0.0.0.0:8000', '-t', 'public', 'public/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__), $env);
    if (!is_resource($server)) { throw new RuntimeException; }
    exit(proc_close($server));
} catch (Throwable) {
    // Do not log SQL/bindings/credentials on failure.
    fwrite(STDERR, "Browser test fixture failed; values omitted.\n");
    exit(1);
} finally {
    if (is_file($path)) { unlink($path); }
}
