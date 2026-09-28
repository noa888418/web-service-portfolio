<?php

namespace Tests\Requests;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthHttpEnvironment;
use Tests\Support\CookieBrowser;

final class RequestApiTest extends TestCase
{
    private static AuthHttpEnvironment $http;
    private array $users;
    private const TIME = 1800000000;
    private const PASSWORD = 'requests-http-fixture-only';

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
        $this->clearRows();
        self::$http->time(self::TIME);
        file_put_contents(self::$http->scenario, '');
        foreach (['.entered', '.release'] as $suffix) {
            if (is_file(self::$http->barrier.$suffix)) {
                unlink(self::$http->barrier.$suffix);
            }
        }
        static $hash;
        $hash ??= Hash::make(self::PASSWORD);
        foreach (['a' => 'employee', 'b' => 'employee', 'x' => 'it_staff', 'y' => 'it_staff'] as $name => $role) {
            $id = DB::table('users')->insertGetId(['display_name' => strtoupper($name), 'email' => $name.'@example.test',
                'password' => $hash, 'role' => $role, 'is_active' => true]);
            $this->users[$name] = User::findOrFail($id);
        }
    }

    protected function tearDown(): void
    {
        file_put_contents(self::$http->scenario, '');
        $this->clearRows();
    }

    private function clearRows(): void
    {
        foreach (['service_requests', 'sessions', 'cache', 'cache_locks', 'users'] as $table) {
            DB::table($table)->delete(); // Guarded random schema only, never public/dev-db.
        }
    }

    private function assertResponse(array $response, int $status): array
    {
        self::assertSame($status, $response['status'], 'Unexpected HTTP status; response values omitted.');
        $cache = implode(',', $response['headers']['cache-control'] ?? []);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('application/json', implode(',', $response['headers']['content-type'] ?? []));

        return $response['json'];
    }

    private function browser(string $name = 'a', int $server = 0): CookieBrowser
    {
        $browser = new CookieBrowser(self::$http->urls[$server]);
        self::assertSame(204, $browser->request('GET', '/sanctum/csrf-cookie')['status']);
        $this->assertResponse($browser->request('POST', '/login', ['email' => $name.'@example.test', 'password' => self::PASSWORD]), 200);

        return $browser;
    }

    private function input(array $replace = []): array
    {
        return array_replace(['title' => '依頼', 'body' => '架空の内容', 'category' => 'inquiry'], $replace);
    }

    private function fixture(string $owner = 'a', array $replace = []): string
    {
        return (string) DB::table('service_requests')->insertGetId(array_replace($this->input(), [
            'requester_id' => $this->users[$owner]->id, 'created_at' => '2026-09-27 00:00:00+00'], $replace));
    }

    public function test_employee_registration_and_role_scoped_reads(): void
    {
        $a = $this->browser();
        $response = $a->request('POST', '/api/requests', $this->input(['title' => '　端末の相談　', 'body' => "　<script>alert('fixture')</script>\r\n二行目\r三行目　"]));
        $data = $this->assertResponse($response, 201)['data'];
        self::assertSame('/api/requests/'.$data['id'], $response['headers']['location'][0]);
        self::assertIsString($data['id']);
        self::assertSame(['id' => $this->users['a']->id, 'display_name' => 'A'], $data['requester']);
        self::assertSame('端末の相談', $data['title']);
        self::assertSame("<script>alert('fixture')</script>\n二行目\n三行目", $data['body']);
        self::assertSame('open', $data['status']);
        self::assertNull($data['assignee']);
        self::assertSame(1, $data['version']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $data['created_at']);
        self::assertSame($data['created_at'], $data['updated_at']);
        self::assertSame(['id', 'title', 'category', 'requester', 'assignee', 'status', 'version', 'created_at', 'body', 'updated_at'], array_keys($data));
        self::assertSame($this->users['a']->id, (string) DB::table('service_requests')->value('requester_id'));
        self::assertSame('it_staff', DB::table('service_requests')->value('assignee_role'));
        self::assertSame($data, $this->assertResponse($a->request('GET', '/api/requests/'.$data['id']), 200)['data']);

        $b = $this->browser('b');
        $empty = $this->assertResponse($b->request('GET', '/api/requests'), 200);
        self::assertSame([], $empty['data']);
        self::assertSame(0, $empty['meta']['total']);
        $foreign = $this->assertResponse($b->request('GET', '/api/requests/'.$data['id']), 404);
        self::assertSame($foreign, $this->assertResponse($b->request('GET', '/api/requests/9223372036854775807'), 404));
        self::assertSame($foreign, $this->assertResponse($b->request('GET', '/api/requests/'.$data['id'].'?role=it_staff'), 404));
        $bData = $this->assertResponse($b->request('POST', '/api/requests', $this->input()), 201)['data'];
        self::assertSame(1, $this->assertResponse($a->request('GET', '/api/requests'), 200)['meta']['total']);
        self::assertSame(1, $this->assertResponse($b->request('GET', '/api/requests'), 200)['meta']['total']);
        foreach (['x', 'y'] as $name) {
            $it = $this->browser($name);
            $all = $this->assertResponse($it->request('GET', '/api/requests'), 200);
            self::assertSame(2, $all['meta']['total']);
            self::assertCount(2, $all['data']);
            self::assertArrayNotHasKey('body', $all['data'][0]);
            self::assertArrayNotHasKey('updated_at', $all['data'][0]);
            foreach ([$data, $bData] as $expected) {
                self::assertSame($expected, $this->assertResponse($it->request('GET', '/api/requests/'.$expected['id']), 200)['data']);
            }
            $this->assertResponse($it->request('POST', '/api/requests', $this->input()), 403);
            self::assertSame(2, DB::table('service_requests')->count());
        }
    }

    #[DataProvider('validInputs')]
    public function test_input_boundaries_and_categories(string $title, string $body, string $category): void
    {
        $result = $this->assertResponse($this->browser()->request('POST', '/api/requests', compact('title', 'body', 'category')), 201);
        self::assertSame($title, $result['data']['title']);
        self::assertSame($body, $result['data']['body']);
        self::assertSame($category, $result['data']['category']);
    }

    public static function validInputs(): array
    {
        return [['a', 'b', 'inquiry'], [str_repeat('あ', 100), str_repeat('本', 5000), 'bug'],
            [str_repeat('😀', 100), str_repeat('😀', 5000), 'improvement']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_has_no_partial_write(array $input): void
    {
        $json = $this->assertResponse($this->browser()->request('POST', '/api/requests', $input), 422);
        self::assertSame('validation_failed', $json['error']['code']);
        self::assertArrayHasKey('fields', $json['error']);
        self::assertSame(0, DB::table('service_requests')->count());
    }

    public static function invalidInputs(): array
    {
        $valid = ['title' => 'valid', 'body' => 'valid', 'category' => 'inquiry'];
        $cases = [[]];
        foreach (['title', 'body'] as $field) {
            foreach ([null, '', " \t　\u{00a0}", 123, [], "bad\0value", str_repeat('あ', $field === 'title' ? 101 : 5001)] as $value) {
                $cases[] = array_replace($valid, [$field => $value]);
            }
        }
        foreach (["bad\nline", "\nleading", "trailing\r"] as $value) {
            $cases[] = array_replace($valid, ['title' => $value]);
        }
        foreach (['unknown', ' inquiry ', 'INQUIRY', null, 1, []] as $value) {
            $cases[] = array_replace($valid, ['category' => $value]);
        }

        return array_map(fn ($case) => [$case], $cases);
    }

    public function test_protected_and_unknown_fields_are_rejected_in_body_and_query(): void
    {
        $browser = $this->browser();
        foreach (['id', 'requester_id', 'author_id', 'service_request_id', 'assignee_id', 'assignee_role', 'status', 'role', 'is_active', 'auth_version', 'password', 'created_at', 'updated_at', 'version'] as $field) {
            $this->assertResponse($browser->request('POST', '/api/requests', $this->input([$field => 'forged'])), 403);
            $this->assertResponse($browser->request('GET', '/api/requests?'.$field.'=forged'), 403);
        }
        foreach (['extra', 'expected_version', 'page', 'sort', 'per_page'] as $field) {
            $this->assertResponse($browser->request('POST', '/api/requests', $this->input([$field => 'forged'])), 422);
        }
        $this->assertResponse($browser->request('POST', '/api/requests?requester_id='.$this->users['b']->id, $this->input()), 403);
        $this->assertResponse($browser->request('GET', '/api/requests?per_page=100'), 422);
        self::assertSame(0, DB::table('service_requests')->count());
        self::assertSame('employee', $this->users['a']->fresh()->role->value);
    }

    #[DataProvider('counts')]
    public function test_pagination_and_order_include_only_visible_rows(int $count): void
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->fixture('a', ['created_at' => $i === 0 ? '2026-09-28 00:00:00+00' : '2026-09-27 00:00:00+00']);
        }
        $this->fixture('b');
        $expected = $count ? [$ids[0], ...array_reverse(array_slice($ids, 1))] : [];
        $browser = $this->browser();
        foreach ([1, 2, 3, 2147483647] as $page) {
            $json = $this->assertResponse($browser->request('GET', '/api/requests?page='.$page), 200);
            self::assertSame(['current_page' => $page, 'per_page' => 20, 'total' => $count, 'last_page' => max(1, (int) ceil($count / 20))], $json['meta']);
            self::assertSame(array_slice($expected, ($page - 1) * 20, 20), array_column($json['data'], 'id'));
        }
    }

    public static function counts(): array { return [[0], [20], [21]]; }

    public function test_bad_ids_and_page_parameters_do_not_become_database_errors(): void
    {
        $id = $this->fixture();
        $browser = $this->browser();
        foreach (['0', '-1', '01', '1.5', 'abc', '9223372036854775808', str_repeat('9', 40)] as $bad) {
            self::assertSame('not_found', $this->assertResponse($browser->request('GET', '/api/requests/'.$bad), 404)['error']['code']);
        }
        foreach (['', '0', '-1', '01', '1.5', '1e2', 'abc', '2147483648', '1&page[]=2'] as $bad) {
            $this->assertResponse($browser->request('GET', '/api/requests?page='.$bad), 422);
        }
        $this->assertResponse($browser->request('GET', '/api/requests/'.$id.'?role=it_staff'), 403);
        $this->assertResponse($browser->request('GET', '/api/requests/'.$id.'?page=1'), 422);
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->assertResponse($browser->request($method, '/api/requests/'.$id, []), 405);
        }
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->assertResponse($browser->request($method, '/api/requests/'.$id.'/comments', []), 405);
            $this->assertResponse($browser->request($method, '/api/requests/'.$id.'/comments/1', []), 404);
        }
        foreach (['status', 'assignee'] as $path) {
            $this->assertResponse($browser->request('GET', '/api/requests/'.$id.'/'.$path), 405);
            $this->assertResponse($browser->request('PATCH', '/api/requests/'.$id.'/'.$path, []), 403);
        }
        $this->assertResponse($browser->request('GET', '/api/requests/'.$id.'/assignee-candidates'), 403);
        self::assertSame(1, DB::table('service_requests')->count());
    }

    #[DataProvider('inactiveSessions')]
    public function test_authentication_is_required_for_all_three_routes(string $reason): void
    {
        $id = $this->fixture();
        if ($reason === 'anonymous') {
            $browser = new CookieBrowser(self::$http->urls[0]);
            self::assertSame(204, $browser->request('GET', '/sanctum/csrf-cookie')['status']);
        } else {
            $browser = $this->browser();
            if ($reason === 'stopped') {
                DB::table('users')->where('id', $this->users['a']->id)->update(['is_active' => false]);
            } elseif ($reason === 'version') {
                DB::table('users')->where('id', $this->users['a']->id)->increment('auth_version');
            } else {
                self::$http->time(self::TIME + ($reason === 'idle' ? 1800 : 28800));
            }
        }
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 401);
        $this->assertResponse($browser->request('GET', '/api/requests'), 401);
        $this->assertResponse($browser->request('GET', '/api/requests/'.$id), 401);
        self::assertSame(1, DB::table('service_requests')->count());
    }

    public static function inactiveSessions(): array { return [['anonymous'], ['stopped'], ['version'], ['idle'], ['absolute']]; }

    public function test_csrf_and_json_boundary_are_not_bypassed(): void
    {
        $browser = $this->browser();
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input(), false), 419);
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input(), true, ['X-XSRF-TOKEN' => 'invalid']), 419);
        $this->assertResponse($browser->raw('POST', '/api/requests', '{"title":"x","title":"y"}'), 400);
        $this->assertResponse($browser->raw('POST', '/api/requests', "{\"title\":\"\xff\"}"), 400);
        $this->assertResponse($browser->raw('POST', '/api/requests', '{}', true, ['Content-Type' => 'text/plain']), 415);
        $this->assertResponse($browser->raw('POST', '/api/requests', str_repeat('x', 65537)), 413);
        self::assertSame(0, DB::table('service_requests')->count());
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 201);
    }

    public function test_session_save_failure_rolls_back_request_insert(): void
    {
        $browser = $this->browser();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_test_session_save() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'fixture session write refused'; END $$;
            CREATE TRIGGER reject_test_session BEFORE INSERT OR UPDATE ON sessions
                FOR EACH ROW EXECUTE FUNCTION reject_test_session_save();
            SQL);
        try {
            $json = $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 503);
            self::assertSame('temporarily_unavailable', $json['error']['code']);
            self::assertSame(0, DB::table('service_requests')->count());
        } finally {
            DB::unprepared('DROP TRIGGER reject_test_session ON sessions; DROP FUNCTION reject_test_session_save()');
        }
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 201);
    }

    public function test_rendered_failure_after_insert_also_rolls_back(): void
    {
        $browser = $this->browser();
        file_put_contents(self::$http->scenario, 'fail_after_insert');
        $json = $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 500);
        self::assertSame('internal_error', $json['error']['code']);
        self::assertSame(0, DB::table('service_requests')->count());
        self::assertStringNotContainsString('fixture injected', file_get_contents(self::$http->log));
        self::assertStringNotContainsString('SQLSTATE', file_get_contents(self::$http->log));
        self::assertStringNotContainsString(self::PASSWORD, file_get_contents(self::$http->log));
    }

    public function test_insert_is_uncommitted_until_session_save_and_stop_waits_for_it(): void
    {
        $browser = $this->browser();
        $manager = new CookieBrowser(self::$http->urls[1]);
        self::assertSame(204, $manager->request('GET', '/sanctum/csrf-cookie')['status']);
        file_put_contents(self::$http->scenario, 'hold_after_insert');
        $create = $browser->handle('POST', '/api/requests', json_encode($this->input()));
        $stop = $manager->handle('POST', '/_test/stop', json_encode(['user' => $this->users['a']->id]));
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $create);
        $stopAdded = false;
        try {
            $deadline = microtime(true) + 3;
            do {
                curl_multi_exec($multi, $running);
                if (is_file(self::$http->barrier.'.entered')) {
                    break;
                }
                usleep(10000);
            } while ($running && microtime(true) < $deadline);
            self::assertFileExists(self::$http->barrier.'.entered');
            self::assertSame(0, DB::table('service_requests')->count(), 'Uncommitted insert escaped the session transaction.');
            curl_multi_add_handle($multi, $stop);
            $stopAdded = true;
            $deadline = microtime(true) + 3;
            do {
                curl_multi_exec($multi, $running);
                $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND usename = current_user AND wait_event_type = 'Lock' AND query LIKE '%users%for update%'")->n;
                if ($waiting > 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, (int) $waiting, 'Stop did not wait for the authenticated user lock.');
        } finally {
            touch(self::$http->barrier.'.release');
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.05);
                }
            } while ($running);
            $created = $browser->finish($create, curl_multi_getcontent($create));
            $stopped = $stopAdded ? $manager->finish($stop, curl_multi_getcontent($stop)) : null;
            self::assertSame(0, curl_errno($create));
            curl_multi_remove_handle($multi, $create);
            if ($stopAdded) {
                self::assertSame(0, curl_errno($stop));
                curl_multi_remove_handle($multi, $stop);
            }
            curl_multi_close($multi);
        }
        $this->assertResponse($created, 201);
        self::assertSame(204, $stopped['status']);
        self::assertSame(1, DB::table('service_requests')->count());
        self::assertFalse($this->users['a']->fresh()->is_active);
        self::assertSame(0, DB::table('sessions')->where('user_id', $this->users['a']->id)->count());
        // Session deletion also deletes its CSRF token: the old POST fails CSRF first.
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 419);
        $this->assertResponse($browser->request('GET', '/api/requests'), 401);
        $this->assertResponse($browser->request('POST', '/api/requests', $this->input()), 401);
        self::assertSame(1, DB::table('service_requests')->count());
    }
}
