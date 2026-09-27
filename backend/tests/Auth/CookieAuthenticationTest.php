<?php

namespace Tests\Auth;

use App\Auth\AccountSessions;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthHttpEnvironment;
use Tests\Support\CookieBrowser;

final class CookieAuthenticationTest extends TestCase
{
    private static AuthHttpEnvironment $http;
    private CookieBrowser $browser;
    private const TIME = 1800000000;
    private const PASSWORD = 'local-http-fixture-only';

    public static function setUpBeforeClass(): void
    {
        self::$http = new AuthHttpEnvironment($GLOBALS['users_test_schema']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$http->close();
    }

    protected function setUp(): void
    {
        // All four tables belong to the schema created by the existing DB guard.
        foreach (['sessions', 'cache', 'cache_locks', 'users'] as $table) {
            DB::table($table)->delete();
        }
        self::$http->time(self::TIME);
        foreach (['.entered', '.release'] as $suffix) {
            if (is_file(self::$http->barrier.$suffix)) {
                unlink(self::$http->barrier.$suffix);
            }
        }
        $this->browser = new CookieBrowser(self::$http->urls[0]);
    }

    protected function tearDown(): void
    {
        foreach (['sessions', 'cache', 'cache_locks', 'users'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function user(UserRole $role = UserRole::Employee): User
    {
        $user = new User;
        $user->display_name = 'Fixture';
        $user->email = 'fixture@example.test';
        $user->password = self::PASSWORD;
        $user->role = $role;
        $user->is_active = true;
        $user->save();

        return $user->fresh();
    }

    private function assertResponse(array $response, int $expected): void
    {
        self::assertSame($expected, $response['status'], 'Unexpected HTTP status (response secrets omitted).');
        $cache = implode(',', $response['headers']['cache-control'] ?? []);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
    }

    private function csrf(): void
    {
        $this->assertResponse($this->browser->request('GET', '/sanctum/csrf-cookie'), 204);
        self::assertArrayHasKey('XSRF-TOKEN', $this->browser->cookies);
    }

    private function login(): array
    {
        return $this->browser->request('POST', '/login', ['email' => ' FIXTURE@EXAMPLE.TEST ', 'password' => self::PASSWORD]);
    }

    private function sessionId(CookieBrowser $browser): string
    {
        return CookieValuePrefix::remove(app('encrypter')->decrypt(rawurldecode($browser->cookies['it_requests_session']), false));
    }

    #[DataProvider('roles')]
    public function test_cookie_flow_and_rotation(UserRole $role): void
    {
        $user = $this->user($role);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
        $this->csrf();
        $anonymous = clone $this->browser;
        $this->assertResponse($login = $this->login(), 200);
        self::assertSame(['data' => ['id' => $user->id, 'display_name' => 'Fixture', 'role' => $role->value]], $login['json']);
        self::assertTrue($this->sessionId($anonymous) !== $this->sessionId($this->browser));
        $this->assertResponse($anonymous->request('GET', '/api/me'), 401);
        $this->assertResponse($me = $this->browser->request('GET', '/api/me'), 200);
        self::assertSame($login['json'], $me['json']);
        $old = clone $this->browser;
        $oldToken = $this->browser->cookies['XSRF-TOKEN'];
        $this->assertResponse($this->browser->request('POST', '/logout', []), 204);
        self::assertTrue($oldToken !== $this->browser->cookies['XSRF-TOKEN']);
        $this->assertResponse($old->request('GET', '/api/me'), 401);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public static function roles(): array
    {
        return [[UserRole::Employee], [UserRole::ItStaff]];
    }

    public function test_failures_have_identical_responses(): void
    {
        $user = $this->user();
        $this->csrf();
        $wrong = $this->browser->request('POST', '/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertResponse($wrong, 401);
        $missing = $this->browser->request('POST', '/login', ['email' => 'missing@example.test', 'password' => self::PASSWORD]);
        $this->assertResponse($missing, 401);
        $user->is_active = false;
        $user->save();
        $this->assertResponse($stopped = $this->login(), 401);
        self::assertSame($wrong['json'], $missing['json']);
        self::assertSame($wrong['json'], $stopped['json']);
    }

    public function test_csrf_is_enforced_for_login_and_logout_even_with_same_origin_header(): void
    {
        $this->user();
        $this->csrf();
        $input = ['email' => 'fixture@example.test', 'password' => self::PASSWORD];
        $this->assertResponse($this->browser->request('POST', '/login', $input, false, ['Sec-Fetch-Site' => 'same-origin']), 419);
        $this->assertResponse($this->browser->request('POST', '/login', $input, true, ['X-XSRF-TOKEN' => 'invalid']), 419);
        $this->assertResponse($this->login(), 200);
        $this->assertResponse($this->browser->request('POST', '/logout', [], false), 419);
        $this->assertResponse($this->browser->request('POST', '/logout', [], true, ['X-XSRF-TOKEN' => 'invalid']), 419);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 200);
        $this->assertResponse($this->browser->request('POST', '/logout', []), 204);
    }

    #[DataProvider('revocations')]
    public function test_stop_and_auth_version_reject_old_sessions(string $field): void
    {
        $user = $this->user();
        $this->csrf();
        $this->assertResponse($this->login(), 200);
        DB::table('users')->where('id', $user->id)->update([$field => $field === 'is_active' ? false : 2]);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public static function revocations(): array
    {
        return [['is_active'], ['auth_version']];
    }

    public function test_idle_boundary_and_only_success_refreshes_activity(): void
    {
        $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        self::$http->time(self::TIME + 1799);
        $this->assertResponse($this->browser->request('GET', '/api/me?role=it_staff'), 403);
        self::$http->time(self::TIME + 1800);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public function test_success_refreshes_idle_but_absolute_boundary_does_not_move(): void
    {
        $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        for ($seconds = 1700; $seconds < 28800; $seconds += 1700) {
            self::$http->time(self::TIME + $seconds);
            $this->assertResponse($this->browser->request('GET', '/api/me'), 200);
        }
        self::$http->time(self::TIME + 28799);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 200);
        self::$http->time(self::TIME + 28800);
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public function test_email_limit_counts_success_and_normalization_and_recovers(): void
    {
        $this->user(); $this->csrf();
        for ($i = 0; $i < 5; $i++) {
            $this->assertResponse($this->login(), 200);
        }
        self::$http->time(self::TIME + 59);
        $this->assertResponse($limited = $this->login(), 429);
        self::assertSame('1', $limited['headers']['retry-after'][0]);
        self::$http->time(self::TIME + 60);
        $this->assertResponse($this->login(), 200);
    }

    public function test_ip_limit_counts_bad_csrf_and_ignores_forged_forwarding(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertResponse($this->browser->request('POST', '/login', [], false, ['X-Forwarded-For' => '192.0.2.'.$i]), 419);
        }
        $this->assertResponse($limited = $this->browser->request('POST', '/login', [], false), 429);
        self::assertSame('60', $limited['headers']['retry-after'][0]);
        self::$http->time(self::TIME + 60);
        $this->assertResponse($this->browser->request('POST', '/login', [], false), 419);
    }

    public function test_input_protection_and_error_contract(): void
    {
        $user = $this->user(); $this->csrf();
        $input = ['email' => $user->email, 'password' => self::PASSWORD];
        $this->assertResponse($this->browser->request('POST', '/login', $input + ['role' => 'it_staff']), 403);
        $this->assertResponse($this->browser->request('POST', '/login', $input + ['remember' => true]), 422);
        $this->assertResponse($invalid = $this->browser->request('POST', '/login', ['email' => 'bad', 'password' => '']), 422);
        self::assertSame(['code', 'message', 'fields'], array_keys($invalid['json']['error']));
        self::assertSame('employee', $user->fresh()->role->value);
        $this->assertResponse($this->browser->raw('POST', '/login', '{"email":"a","email":"b"}'), 400);
        $this->assertResponse($this->browser->raw('POST', '/login', '{"email":"a","\\u0065mail":"b"}'), 400);
        $this->assertResponse($this->browser->raw('POST', '/login', '[]'), 400);
        $this->assertResponse($this->browser->raw('POST', '/login', '{'), 400);
        $this->assertResponse($this->browser->raw('POST', '/login', '{}', true, ['Content-Type' => 'text/plain']), 415);
        $this->assertResponse($this->browser->raw('POST', '/login', str_repeat('x', 65537)), 413);
        $this->assertResponse($this->browser->request('POST', '/logout', []), 204);
        $this->assertResponse($this->browser->request('GET', '/api/me', headers: ['Authorization' => 'Bearer invalid-test-value']), 401);
    }

    public function test_cookie_privacy_and_log_redaction(): void
    {
        $user = $this->user(); $this->csrf();
        $this->assertResponse($login = $this->login(), 200);
        $cookies = implode('\n', $login['headers']['set-cookie']);
        self::assertStringContainsString('httponly', strtolower($cookies));
        self::assertStringContainsString('samesite=lax', strtolower($cookies));
        self::assertStringNotContainsString('domain=', strtolower($cookies));
        self::assertStringNotContainsString('; secure', strtolower($cookies));
        foreach ($login['headers']['set-cookie'] as $cookie) {
            if (str_starts_with($cookie, 'XSRF-TOKEN=')) {
                self::assertStringNotContainsString('httponly', strtolower($cookie));
            }
        }
        $row = DB::table('sessions')->where('id', $this->sessionId($this->browser))->first();
        self::assertNull($row->ip_address);
        self::assertNull($row->user_agent);
        self::assertStringNotContainsString('authenticated_at', $row->payload);
        $log = file_get_contents(self::$http->log);
        foreach ([self::PASSWORD, $user->password, $this->browser->cookies['XSRF-TOKEN'], $this->browser->cookies['it_requests_session']] as $secret) {
            self::assertFalse(str_contains($log, $secret), 'Secret unexpectedly present in HTTP log.');
        }
    }

    public function test_password_is_not_trimmed_by_http_middleware(): void
    {
        $user = $this->user();
        $user->password = '  '.self::PASSWORD.'  ';
        $user->save();
        $this->csrf();
        $this->assertResponse($this->login(), 401);
        $this->assertResponse($this->browser->request('POST', '/login', ['email' => $user->email, 'password' => '  '.self::PASSWORD.'  ']), 200);
    }

    public function test_failed_and_missing_accounts_also_consume_email_quota(): void
    {
        $user = $this->user();
        $user->is_active = false;
        $user->save();
        $this->csrf();
        foreach ([$user->email, 'absent@example.test'] as $email) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertResponse($this->browser->request('POST', '/login', ['email' => $email, 'password' => self::PASSWORD]), 401);
            }
            $this->assertResponse($this->browser->request('POST', '/login', ['email' => strtoupper($email), 'password' => self::PASSWORD]), 429);
        }
    }

    private function startHeldRequest(): array
    {
        $handle = $this->browser->handle('GET', '/_test/hold', null);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);
        $deadline = microtime(true) + 4;
        do {
            curl_multi_exec($multi, $running);
            if (is_file(self::$http->barrier.'.entered')) {
                return [$multi, $handle];
            }
            usleep(10000);
        } while ($running && microtime(true) < $deadline);
        self::fail('Authenticated request failed to reach its synchronization barrier.');
    }

    private function complete($multi, array $requests): array
    {
        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running);
        $results = [];
        foreach ($requests as [$browser, $handle]) {
            self::assertSame(0, curl_errno($handle), 'Concurrent HTTP transport error.');
            $results[] = $browser->finish($handle, curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $results;
    }

    public function test_session_lock_timeout_is_503_and_release_allows_logout(): void
    {
        $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        $old = clone $this->browser;
        $other = clone $this->browser;
        $other->base = self::$http->urls[1];
        [$multi, $hold] = $this->startHeldRequest();
        try {
            $start = hrtime(true);
            $response = $other->request('POST', '/logout', []);
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertResponse($response, 503);
            self::assertGreaterThanOrEqual(4.8, $elapsed);
            self::assertLessThan(7.0, $elapsed);
        } finally {
            touch(self::$http->barrier.'.release');
            $responses = $this->complete($multi, [[$this->browser, $hold]]);
        }
        $this->assertResponse($responses[0], 204);
        $this->assertResponse($other->request('POST', '/logout', []), 204);
        $this->assertResponse($old->request('GET', '/api/me'), 401);
        self::assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_logout_waits_for_prior_session_save_and_old_cookie_cannot_restore_it(): void
    {
        $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        $old = clone $this->browser;
        $other = clone $this->browser;
        $other->base = self::$http->urls[1];
        [$multi, $hold] = $this->startHeldRequest();
        $logout = $other->handle('POST', '/logout', '{}');
        curl_multi_add_handle($multi, $logout);
        try {
            // Observe the other worker attempting the advisory lock while the first
            // worker is held at its explicit barrier; do not infer ordering from sleep.
            $deadline = microtime(true) + 3;
            do {
                curl_multi_exec($multi, $running);
                $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND usename = current_user AND pid <> pg_backend_pid() AND query LIKE 'SELECT pg_try_advisory_xact_lock%'")->n;
                if ($waiting > 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, (int) $waiting, 'Logout did not attempt the held session lock.');
            self::assertSame(2, $running);
        } finally {
            touch(self::$http->barrier.'.release');
            $responses = $this->complete($multi, [[$this->browser, $hold], [$other, $logout]]);
        }
        $this->assertResponse($responses[0], 204);
        $this->assertResponse($responses[1], 204);
        $this->assertResponse($old->request('GET', '/api/me'), 401);
        // Even the late response of the pre-logout request only contains an invalid ID.
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public function test_stop_waits_for_user_lock_and_deletes_the_just_saved_session(): void
    {
        $user = $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        $manager = new CookieBrowser(self::$http->urls[1]);
        $this->assertResponse($manager->request('GET', '/sanctum/csrf-cookie'), 204);
        [$multi, $hold] = $this->startHeldRequest();
        $stop = $manager->handle('POST', '/_test/stop', json_encode(['user' => $user->id]));
        curl_multi_add_handle($multi, $stop);
        try {
            $deadline = microtime(true) + 3;
            do {
                curl_multi_exec($multi, $running);
                $blocked = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND usename = current_user AND wait_event_type = 'Lock' AND query LIKE '%users%for update%'")->n;
                if ($blocked > 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, (int) $blocked, 'Management stop did not wait for the shared user lock.');
        } finally {
            touch(self::$http->barrier.'.release');
            $responses = $this->complete($multi, [[$this->browser, $hold], [$manager, $stop]]);
        }
        $this->assertResponse($responses[0], 204);
        $this->assertResponse($responses[1], 204);
        self::assertFalse($user->fresh()->is_active);
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
    }

    public function test_stop_first_and_late_restored_payload_is_rejected_by_auth_version(): void
    {
        $user = $this->user(); $this->csrf(); $this->assertResponse($this->login(), 200);
        $old = (array) DB::table('sessions')->where('id', $this->sessionId($this->browser))->first();
        app(AccountSessions::class)->revoke($user->id, false);
        DB::table('sessions')->insert($old); // Simulate a stale worker outside the required lock protocol.
        $this->assertResponse($this->browser->request('GET', '/api/me'), 401);
        self::assertTrue($user->fresh()->is_active);
        self::assertSame(2, $user->fresh()->auth_version);
    }

    public function test_database_cache_failure_is_closed_and_does_not_log_input(): void
    {
        $this->user(); $this->csrf();
        DB::statement('ALTER TABLE cache RENAME TO unavailable_cache');
        try {
            $this->assertResponse($this->login(), 503);
            $log = file_get_contents(self::$http->log);
            self::assertFalse(str_contains($log, self::PASSWORD));
            self::assertStringNotContainsString('SQLSTATE', $log);
        } finally {
            DB::statement('ALTER TABLE unavailable_cache RENAME TO cache');
        }
    }

    public function test_two_sessions_racing_for_last_email_attempt_do_not_both_pass(): void
    {
        $this->user(); $this->csrf();
        for ($i = 0; $i < 4; $i++) {
            $this->assertResponse($this->browser->request('POST', '/login', ['email' => 'fixture@example.test', 'password' => 'wrong']), 401);
        }
        $other = new CookieBrowser(self::$http->urls[1]);
        $this->assertResponse($other->request('GET', '/sanctum/csrf-cookie'), 204);
        $body = json_encode(['email' => 'fixture@example.test', 'password' => self::PASSWORD]);
        $one = $this->browser->handle('POST', '/login', $body);
        $two = $other->handle('POST', '/login', $body);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $one);
        curl_multi_add_handle($multi, $two);
        $responses = $this->complete($multi, [[$this->browser, $one], [$other, $two]]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        self::assertSame([200, 429], $statuses);
        foreach (DB::table('cache')->pluck('key') as $key) {
            self::assertStringNotContainsString('fixture@example.test', $key);
            self::assertStringNotContainsString('127.0.0.1', $key);
        }
    }

    public function test_production_cookie_cannot_be_weakened_by_environment_override(): void
    {
        $process = proc_open([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $c = require "config/session.php"; echo json_encode([$c["secure"], $c["http_only"], $c["same_site"], $c["domain"]]);'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), array_merge(getenv(), ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false']));
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame([true, true, 'lax', null], json_decode($output, true));
    }
}
