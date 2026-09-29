<?php

namespace Tests\Demo;

use App\Demo\DemoSeeder;
use App\Demo\DemoTarget;
use App\Demo\SeedRefused;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\CookieBrowser;
use Tests\Support\NormalHttpServer;

final class DemoTest extends TestCase
{
    private string $credentials;
    private string $barrier;
    private array $accounts;
    private array $files = [];
    private array $configuration;

    protected function setUp(): void
    {
        $this->clean();
        $this->configuration = config('database.connections.pgsql');
        config(['demo.purpose' => 'demo']);
        $this->credentials = tempnam(sys_get_temp_dir(), 'demo-credentials-');
        $this->barrier = tempnam(sys_get_temp_dir(), 'demo-barrier-');
        $this->files = [$this->credentials, $this->barrier, $this->barrier.'.entered', $this->barrier.'.release'];
        foreach (DemoSeeder::ACCOUNTS as $key => [$email]) {
            $this->accounts[$key] = ['email' => $email, 'password' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
        }
        file_put_contents($this->credentials, json_encode(['accounts' => $this->accounts]));
    }
    protected function tearDown(): void
    {
        config(['database.connections.pgsql' => $this->configuration, 'demo.purpose' => 'unspecified', 'app.debug' => false]);
        DB::statement('SET search_path TO "'.$GLOBALS['users_test_schema'].'"');
        $this->clean();
        foreach ($this->files as $file) { if (is_file($file)) { unlink($file); } }
    }
    private function clean(): void
    {
        foreach (['demo_seed_runs', 'comments', 'service_requests', 'sessions', 'cache', 'cache_locks', 'users'] as $table) { DB::table($table)->delete(); }
    }
    private function seed(string $publication = 'test-period-1'): string
    {
        return app(DemoSeeder::class)->run('test', $publication, '1', true, $this->credentials);
    }
    private function counts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(), ['users', 'service_requests', 'comments', 'demo_seed_runs']);
    }
    private function fingerprint(): string
    {
        $rows = [];
        foreach (['users', 'service_requests', 'comments', 'demo_seed_runs'] as $table) { $rows[] = DB::table($table)->orderBy('id')->get()->all(); }
        return hash('sha256', json_encode($rows));
    }
    private function worker(string $scenario = '', bool $production = false, string $purpose = 'demo', bool $allow = true, string $target = 'test'): array
    {
        $output = tempnam(sys_get_temp_dir(), 'seed-output-'); $this->files[] = $output;
        $env = array_merge(getenv(), ['DEPLOYMENT_PURPOSE' => $purpose, 'SEED_TEST_SCHEMA' => $GLOBALS['users_test_schema'],
            'SEED_TEST_SCENARIO' => $scenario, 'SEED_TEST_BARRIER' => $this->barrier, 'SEED_TEST_PRODUCTION' => $production ? '1' : '0']);
        $arguments = [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', 'tests/seed-worker.php', 'demo:seed',
            '--target='.$target, '--publication=test-period-1', '--seed-version=1', '--credentials='.$this->credentials];
        if ($allow) { $arguments[] = '--allow'; }
        $process = proc_open($arguments,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']], $pipes, dirname(__DIR__, 2), $env);
        return [$process, $output];
    }
    private function finish(array $worker): array
    {
        [$process, $file] = $worker;
        $exit = proc_close($process); $output = file_get_contents($file);
        foreach ($this->accounts as $entry) { self::assertTrue(! str_contains($output, $entry['password']), 'Secret appeared in output; value omitted.'); }
        self::assertStringNotContainsString('$argon2id$', $output);
        self::assertStringNotContainsString('SQLSTATE', $output);
        return [$exit, $output];
    }

    public function test_initial_seed_has_two_roles_split_owners_and_coherent_completed_history(): void
    {
        self::assertSame('created', $this->seed()); self::assertSame([4, 4, 4, 1], $this->counts());
        self::assertSame(['bug', 'improvement', 'inquiry'], DB::table('service_requests')->distinct()->orderBy('category')->pluck('category')->all());
        self::assertSame(['completed', 'in_progress', 'open', 'waiting_confirmation'], DB::table('service_requests')->orderBy('status')->pluck('status')->all());
        foreach (DemoSeeder::ACCOUNTS as $key => [$email, $name, $role]) {
            $user = DB::table('users')->where('email', $email)->first();
            self::assertSame($name, $user->display_name); self::assertSame($role, $user->role);
            self::assertTrue($user->is_active); self::assertSame(1, $user->auth_version);
            self::assertTrue(Hash::check($this->accounts[$key]['password'], $user->password), 'Generated credential failed verification.');
            self::assertTrue(str_starts_with($user->password, '$argon2id$'));
            if ($role === 'employee') { self::assertSame(2, DB::table('service_requests')->where('requester_id', $user->id)->count()); }
        }
        $completed = DB::table('service_requests')->where('status', 'completed')->first();
        $comment = DB::table('comments')->where('service_request_id', $completed->id)->first();
        self::assertLessThan(strtotime($comment->created_at), strtotime($completed->created_at));
        self::assertLessThan(strtotime($completed->updated_at), strtotime($comment->created_at));
        self::assertSame(5, $completed->version);
        self::assertSame('it_staff', DB::table('users')->where('id', $completed->assignee_id)->value('role'));
    }

    public function test_repeat_preserves_followup_data_stop_password_and_credential_file(): void
    {
        $this->seed();
        $a = DB::table('users')->where('email', 'employee-a@example.test')->first();
        DB::table('users')->where('id', $a->id)->update(['is_active' => false, 'auth_version' => 2, 'password' => Hash::make(bin2hex(random_bytes(32)))]);
        $request = DB::table('service_requests')->where('requester_id', $a->id)->first();
        DB::table('comments')->insert(['service_request_id' => $request->id, 'author_id' => $a->id, 'body' => 'Later fictional data']);
        DB::table('service_requests')->where('id', $request->id)->update(['title' => 'Changed locally']);
        $before = $this->fingerprint(); $file = hash_file('sha256', $this->credentials);
        self::assertSame('no-op', $this->seed()); self::assertSame($before, $this->fingerprint());
        self::assertSame($file, hash_file('sha256', $this->credentials));
        unlink($this->credentials); // No-op does not recover/replace credentials, even if the file is missing.
        self::assertSame('no-op', $this->seed()); self::assertSame($before, $this->fingerprint());
    }

    #[DataProvider('refusals')]
    public function test_preconditions_refuse_without_writes(string $reason): void
    {
        $target = 'test'; $allow = true; $publication = 'test-period-1'; $version = '1';
        if ($reason === 'purpose') { config(['demo.purpose' => 'business']); }
        elseif ($reason === 'debug') { config(['app.debug' => true]); }
        elseif ($reason === 'permission') { $allow = false; }
        elseif ($reason === 'target') { $target = 'aws'; }
        elseif ($reason === 'wrong_target') { $target = 'local'; }
        elseif ($reason === 'publication') { $publication = ''; }
        elseif ($reason === 'version') { $version = '2'; }
        elseif ($reason === 'host') { config(['database.connections.pgsql.host' => 'external-db']); }
        elseif ($reason === 'database') { config(['database.connections.pgsql.database' => 'portfolio_dev']); }
        elseif ($reason === 'role') { config(['database.connections.pgsql.username' => 'postgres']); }
        elseif ($reason === 'schema') { config(['database.connections.pgsql.search_path' => 'public']); }
        $before = $this->fingerprint(); $file = hash_file('sha256', $this->credentials);
        try { app(DemoSeeder::class)->run($target, $publication, $version, $allow, $this->credentials); self::fail('Unsafe seed accepted.'); }
        catch (SeedRefused) { self::assertSame($before, $this->fingerprint()); }
        self::assertSame($file, hash_file('sha256', $this->credentials));
    }
    public static function refusals(): array
    {
        return array_map(fn ($s) => [$s], ['purpose', 'debug', 'permission', 'target', 'wrong_target', 'publication', 'version', 'host', 'database', 'role', 'schema']);
    }

    public function test_live_schema_identity_is_checked_not_just_configuration(): void
    {
        DB::statement('SET search_path TO public');
        try { DemoTarget::verify('test'); self::fail('Mismatched live schema accepted.'); }
        catch (SeedRefused $e) { self::assertSame('database_identity', $e->getMessage()); }
    }

    public function test_nonempty_without_record_is_refused(): void
    {
        $this->seed(); DB::table('demo_seed_runs')->delete(); $before = $this->fingerprint();
        try { $this->seed(); self::fail('Nonempty database accepted.'); }
        catch (SeedRefused $e) { self::assertSame('nonempty_business_tables', $e->getMessage()); }
        self::assertSame($before, $this->fingerprint());
    }

    public function test_different_record_is_refused_even_when_business_data_is_empty(): void
    {
        DB::table('demo_seed_runs')->insert(['publication_id' => 'other-period', 'seed_version' => '1']);
        $before = $this->fingerprint();
        try { $this->seed(); self::fail('Different record accepted.'); }
        catch (SeedRefused $e) { self::assertSame('different_seed_record', $e->getMessage()); }
        self::assertSame($before, $this->fingerprint());
    }

    public function test_invalid_credential_file_leaves_tables_empty(): void
    {
        file_put_contents($this->credentials, '{"accounts":{}}');
        try { $this->seed(); self::fail('Invalid credentials accepted.'); }
        catch (SeedRefused $e) { self::assertSame('credentials_format', $e->getMessage()); }
        self::assertSame([0, 0, 0, 0], $this->counts());
    }

    public function test_production_environment_can_seed_only_the_explicit_demo_test_target(): void
    {
        [$exit, $output] = $this->finish($this->worker(production: true));
        self::assertSame(0, $exit); self::assertStringContainsString('created', $output);
        self::assertSame([4, 4, 4, 1], $this->counts());
        [$again, $message] = $this->finish($this->worker(production: true));
        self::assertSame(0, $again); self::assertStringContainsString('no-op', $message);
        self::assertSame([4, 4, 4, 1], $this->counts());
    }

    public function test_mid_insert_exception_rolls_back_data_and_record(): void
    {
        [$exit, $output] = $this->finish($this->worker('fail'));
        self::assertSame(1, $exit); self::assertStringContainsString('operation_failed', $output);
        self::assertSame([0, 0, 0, 0], $this->counts());
    }

    #[DataProvider('commandRefusals')]
    public function test_command_guards_apply_in_local_and_production(bool $production, string $reason): void
    {
        [$exit, $output] = $this->finish($this->worker(production: $production,
            purpose: $reason === 'purpose' ? 'business' : 'demo', allow: $reason !== 'permission', target: $reason === 'target' ? 'local' : 'test'));
        self::assertSame(1, $exit); self::assertStringContainsString('refused', $output);
        self::assertSame([0, 0, 0, 0], $this->counts());
    }
    public static function commandRefusals(): array
    {
        return [[false, 'purpose'], [true, 'purpose'], [false, 'permission'], [true, 'permission'], [false, 'target'], [true, 'target']];
    }

    public function test_two_processes_seed_once_using_explicit_barrier(): void
    {
        $first = $this->worker('hold'); $second = null;
        try {
            $deadline = microtime(true) + 4;
            while (! is_file($this->barrier.'.entered') && microtime(true) < $deadline) { usleep(10000); }
            self::assertFileExists($this->barrier.'.entered');
            self::assertSame([0, 0, 0, 0], $this->counts()); // Other connection cannot see partial seed.
            $second = $this->worker();
            self::assertNotSame(proc_get_status($first[0])['pid'], proc_get_status($second[0])['pid']);
            $deadline = microtime(true) + 3; $waiting = 0;
            do {
                $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND usename=current_user AND wait_event_type='Lock' AND query LIKE 'LOCK TABLE demo_seed_runs%'")->n;
                if ($waiting) { break; } usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, $waiting);
        } finally {
            touch($this->barrier.'.release');
            $one = $this->finish($first); $two = $second ? $this->finish($second) : null;
        }
        self::assertSame(0, $one[0]); self::assertStringContainsString('created', $one[1]);
        self::assertSame(0, $two[0]); self::assertStringContainsString('no-op', $two[1]);
        self::assertSame([4, 4, 4, 1], $this->counts());
    }

    #[DataProvider('invalidRecords')]
    public function test_seed_record_constraints(array $row, string $state): void
    {
        DB::beginTransaction();
        try {
            DB::table('demo_seed_runs')->insert(array_replace(['publication_id' => 'p', 'seed_version' => '1'], $row));
            self::fail('Invalid record accepted.');
        } catch (QueryException $e) { self::assertSame($state, $e->errorInfo[0]); }
        finally { DB::rollBack(); }
    }
    public static function invalidRecords(): array
    {
        return [[['id' => 2], '23514'], [['publication_id' => null], '23502'], [['seed_version' => null], '23502'],
            [['completed_at' => null], '23502'], [['publication_id' => ''], '23514'], [['seed_version' => str_repeat('a', 51)], '22001']];
    }

    private function check(array $response, int $status): array
    {
        self::assertSame($status, $response['status'], 'Unexpected HTTP status; values omitted.');
        self::assertStringContainsString('no-store', implode(',', $response['headers']['cache-control'] ?? []));
        return $response['json'] ?? [];
    }
    private function login(NormalHttpServer $server, string $key): CookieBrowser
    {
        $browser = new CookieBrowser($server->url);
        $this->check($browser->request('GET', '/sanctum/csrf-cookie'), 204);
        $this->check($browser->request('POST', '/login', $this->accounts[$key]), 200);
        return $browser;
    }

    public function test_normal_public_entrypoint_with_seeded_cookie_auth_and_scoped_reads(): void
    {
        $this->seed(); $server = new NormalHttpServer($GLOBALS['users_test_schema']);
        try {
            $all = DB::table('service_requests')->orderBy('id')->get();
            foreach (array_keys(DemoSeeder::ACCOUNTS) as $key) {
                $browser = new CookieBrowser($server->url);
                $this->check($browser->request('GET', '/api/me', headers: ['Accept' => 'text/html']), 401);
                $browser = $this->login($server, $key);
                $me = $this->check($browser->request('GET', '/api/me'), 200)['data'];
                $list = $this->check($browser->request('GET', '/api/requests'), 200);
                self::assertSame($me['role'] === 'employee' ? 2 : 4, $list['meta']['total']);
                foreach ($all as $row) {
                    $canRead = $me['role'] === 'it_staff' || $me['id'] === (string) $row->requester_id;
                    $detail = $this->check($browser->request('GET', '/api/requests/'.$row->id), $canRead ? 200 : 404);
                    $comments = $this->check($browser->request('GET', '/api/requests/'.$row->id.'/comments'), $canRead ? 200 : 404);
                    if ($canRead) { self::assertSame((string) $row->id, $detail['data']['id']); self::assertSame(1, $comments['meta']['total']); }
                    else { self::assertSame(['error'], array_keys($comments)); }
                }
                $this->check($browser->request('GET', '/_test/hold'), 404);
                $this->check($browser->request('POST', '/_test/stop', []), 404);
                $this->check($browser->request('POST', '/logout', []), 204);
                $this->check($browser->request('GET', '/api/me'), 401);
            }
            $log = file_get_contents($server->log);
            foreach ($this->accounts as $entry) { self::assertTrue(! str_contains($log, $entry['password']), 'Secret in HTTP log; omitted.'); }
        } finally { $server->close(); }
    }

    public function test_remaining_write_apis_use_normal_entrypoint_without_test_router(): void
    {
        $this->seed(); $server = new NormalHttpServer($GLOBALS['users_test_schema']);
        try {
            $a = $this->login($server, 'employee_a'); $x = $this->login($server, 'it_x');
            $created = $this->check($a->request('POST', '/api/requests', ['title' => 'Local fixture', 'body' => 'Fictional', 'category' => 'inquiry']), 201)['data'];
            $id = $created['id'];
            $candidates = $this->check($x->request('GET', '/api/requests/'.$id.'/assignee-candidates'), 200)['data'];
            self::assertCount(2, $candidates);
            $this->check($x->request('PATCH', '/api/requests/'.$id.'/assignee', ['assignee_id' => $candidates[0]['id'], 'expected_version' => 1]), 200);
            $this->check($x->request('PATCH', '/api/requests/'.$id.'/status', ['status' => 'in_progress', 'expected_version' => 2]), 200);
            $this->check($a->request('POST', '/api/requests/'.$id.'/comments', ['body' => 'Normal entrypoint']), 201);
        } finally { $server->close(); }
    }
}
