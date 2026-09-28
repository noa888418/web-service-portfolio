<?php

namespace Tests\Comments;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthHttpEnvironment;
use Tests\Support\CookieBrowser;

final class CommentApiTest extends TestCase
{
    private static AuthHttpEnvironment $http;
    private array $users;
    private const TIME = 1800000000;
    private const PASSWORD = 'comments-local-fixture-only';

    public static function setUpBeforeClass(): void { self::$http = new AuthHttpEnvironment($GLOBALS['users_test_schema']); }
    public static function tearDownAfterClass(): void { self::$http->close(); }

    protected function setUp(): void
    {
        $this->clean();
        self::$http->time(self::TIME);
        file_put_contents(self::$http->scenario, '');
        foreach (['.entered', '.release'] as $suffix) {
            if (is_file(self::$http->barrier.$suffix)) { unlink(self::$http->barrier.$suffix); }
        }
        static $hash;
        $hash ??= Hash::make(self::PASSWORD);
        foreach (['a' => 'employee', 'b' => 'employee', 'x' => 'it_staff', 'y' => 'it_staff', 'z' => 'it_staff'] as $name => $role) {
            $id = DB::table('users')->insertGetId(['display_name' => strtoupper($name), 'email' => $name.'@example.test', 'password' => $hash, 'role' => $role, 'is_active' => true]);
            $this->users[$name] = User::findOrFail($id);
        }
    }
    protected function tearDown(): void { file_put_contents(self::$http->scenario, ''); $this->clean(); }
    private function clean(): void
    {
        foreach (['comments', 'service_requests', 'sessions', 'cache', 'cache_locks', 'users'] as $table) { DB::table($table)->delete(); }
    }
    private function check(array $response, int $status, ?string $code = null): array
    {
        self::assertSame($status, $response['status'], 'Unexpected status; secrets omitted.');
        $cache = implode(',', $response['headers']['cache-control'] ?? []);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        if ($code !== null) { self::assertSame($code, $response['json']['error']['code']); }
        return $response['json'] ?? [];
    }
    private function browser(string $name = 'x', int $server = 0): CookieBrowser
    {
        $browser = new CookieBrowser(self::$http->urls[$server]);
        $this->check($browser->request('GET', '/sanctum/csrf-cookie'), 204);
        $this->check($browser->request('POST', '/login', ['email' => $name.'@example.test', 'password' => self::PASSWORD]), 200);
        return $browser;
    }
    private function fixture(string $status = 'open', ?string $assignee = null, int $version = 1): string
    {
        return (string) DB::table('service_requests')->insertGetId(['requester_id' => $this->users['a']->id,
            'title' => 'Comment fixture', 'body' => 'Fictional text', 'category' => 'inquiry', 'status' => $status,
            'assignee_id' => $assignee === null ? null : $this->users[$assignee]->id, 'version' => $version]);
    }
    private function row(string $id): array { return (array) DB::table('service_requests')->where('id', $id)->first(); }
    private function url(string $id): string { return '/api/requests/'.$id.'/comments'; }

    public function test_owner_and_it_post_read_plain_text_without_mutating_parent(): void
    {
        $id = $this->fixture(); $before = $this->row($id); $items = [];
        foreach (['a', 'x', 'y'] as $name) {
            $browser = $this->browser($name);
            $body = "　<script>fictional text</script>\r\nline\rend　";
            $item = $this->check($browser->request('POST', $this->url($id), ['body' => $body]), 201)['data'];
            self::assertSame(['id', 'body', 'author', 'created_at'], array_keys($item));
            self::assertIsString($item['id']);
            self::assertSame("<script>fictional text</script>\nline\nend", $item['body']);
            self::assertSame(['id' => $this->users[$name]->id, 'display_name' => strtoupper($name)], $item['author']);
            self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $item['created_at']);
            self::assertSame($item['body'], DB::table('comments')->where('id', $item['id'])->value('body'));
            $items[] = $item;
            self::assertSame($items, $this->check($browser->request('GET', $this->url($id)), 200)['data']);
            self::assertSame($before, $this->row($id));
            self::assertArrayNotHasKey('comments', $this->check($browser->request('GET', '/api/requests/'.$id), 200)['data']);
        }
    }

    public function test_other_employee_and_missing_parent_are_same_404_without_counts(): void
    {
        $id = $this->fixture(); $browser = $this->browser('b');
        DB::table('comments')->insert(['service_request_id' => $id, 'author_id' => $this->users['a']->id, 'body' => 'Private fixture']);
        foreach (['GET', 'POST'] as $method) {
            $input = $method === 'POST' ? ['body' => 'forged', 'author_id' => $this->users['x']->id] : null;
            $expected = $this->check($browser->request($method, $this->url($id), $input), 404, 'not_found');
            self::assertSame(['error'], array_keys($expected));
            foreach (['9223372036854775807', '9223372036854775808', '01', '0', 'bad'] as $missing) {
                self::assertSame($expected, $this->check($browser->request($method, $this->url($missing), $input), 404));
            }
        }
        self::assertSame(1, DB::table('comments')->count());
    }

    #[DataProvider('bodies')]
    public function test_normalized_body_boundaries(mixed $body, bool $valid): void
    {
        $id = $this->fixture(); $before = $this->row($id); $browser = $this->browser('a');
        $json = $this->check($browser->request('POST', $this->url($id), ['body' => $body]), $valid ? 201 : 422);
        self::assertSame($valid ? 1 : 0, DB::table('comments')->count());
        if ($valid) { self::assertContains(mb_strlen($json['data']['body'], 'UTF-8'), [1, 2000]); }
        else { self::assertSame(['body'], array_keys($json['error']['fields'])); }
        self::assertSame($before, $this->row($id));
    }
    public static function bodies(): array
    {
        return [['a', true], ['😀', true], [str_repeat('界', 2000), true], ["　".str_repeat('😀', 2000)."\r\n", true],
            [str_repeat('界', 2001), false], ['', false], [" \t\r\n　\u{00a0}", false], [null, false], [1, false], [true, false], [[], false], ["a\0b", false]];
    }

    public function test_missing_protected_unknown_fields_and_csrf_are_rejected(): void
    {
        $id = $this->fixture(); $before = $this->row($id); $browser = $this->browser('a'); $url = $this->url($id);
        $this->check($browser->request('POST', $url, []), 422);
        foreach (['id', 'author_id', 'service_request_id', 'requester_id', 'role', 'status', 'version', 'created_at', 'updated_at', 'assignee_id'] as $key) {
            $this->check($browser->request('POST', $url, ['body' => 'test', $key => 'forged']), 403);
            $this->check($browser->request('GET', $url.'?'.$key.'=1'), 403);
        }
        foreach (['expected_version', 'internal', 'is_internal', 'title'] as $key) {
            $this->check($browser->request('POST', $url, ['body' => 'test', $key => 1]), 422);
        }
        $this->check($browser->request('POST', $url.'?page=1', ['body' => 'test']), 422);
        $this->check($browser->request('POST', $url, ['body' => 'test'], false), 419);
        $this->check($browser->request('POST', $url, ['body' => 'test'], true, ['X-XSRF-TOKEN' => 'invalid']), 419);
        $this->check($browser->raw('POST', $url, '{"body":"one","body":"two"}'), 400);
        $this->check($browser->raw('POST', $url, '{}', true, ['Content-Type' => 'text/plain']), 415);
        self::assertSame(0, DB::table('comments')->count()); self::assertSame($before, $this->row($id));
    }

    public function test_pagination_and_timestamp_then_id_order_are_scoped_to_parent(): void
    {
        $id = $this->fixture(); $other = $this->fixture(); $browser = $this->browser('a');
        $url = $this->url($id);
        $empty = $this->check($browser->request('GET', $url), 200);
        self::assertSame([], $empty['data']);
        self::assertSame(['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1], $empty['meta']);
        $row = ['service_request_id' => $id, 'author_id' => $this->users['a']->id, 'body' => 'Fixture', 'created_at' => '2026-09-28 01:00:00+00'];
        DB::table('comments')->insert(array_replace($row, ['service_request_id' => $other]));
        $ids = [];
        for ($i = 0; $i < 20; $i++) { $ids[] = (string) DB::table('comments')->insertGetId($row); }
        $twenty = $this->check($browser->request('GET', $url), 200);
        self::assertSame($ids, array_column($twenty['data'], 'id')); self::assertSame(20, $twenty['meta']['total']);
        $early = (string) DB::table('comments')->insertGetId(array_replace($row, ['created_at' => '2026-09-27 01:00:00+00']));
        array_unshift($ids, $early);
        foreach ([1, 2, 3, 2147483647] as $page) {
            $json = $this->check($browser->request('GET', $url.'?page='.$page), 200);
            self::assertSame(array_slice($ids, ($page - 1) * 20, 20), array_column($json['data'], 'id'));
            self::assertSame(['current_page' => $page, 'per_page' => 20, 'total' => 21, 'last_page' => 2], $json['meta']);
        }
        foreach (['0', '-1', '01', '1.5', '2147483648', '1&page[]=2'] as $page) { $this->check($browser->request('GET', $url.'?page='.$page), 422); }
        foreach (['per_page=100', 'sort=desc', 'expected_version=1'] as $query) { $this->check($browser->request('GET', $url.'?'.$query), 422); }
    }

    public function test_completed_comments_remain_readable_but_no_post_edit_or_delete(): void
    {
        $id = $this->fixture('completed', 'y'); $before = $this->row($id);
        $comment = DB::table('comments')->insertGetId(['service_request_id' => $id, 'author_id' => $this->users['a']->id, 'body' => 'Existing']);
        foreach (['a', 'x'] as $name) {
            $browser = $this->browser($name); $url = $this->url($id);
            $this->check($browser->request('POST', $url, ['body' => 'Late']), 409, 'request_completed');
            self::assertSame('Existing', $this->check($browser->request('GET', $url), 200)['data'][0]['body']);
            foreach (['PATCH', 'DELETE'] as $method) {
                $this->check($browser->request($method, $url, ['body' => 'Change']), 405);
                $this->check($browser->request($method, $url.'/'.$comment, ['body' => 'Change']), 404);
            }
        }
        self::assertSame(1, DB::table('comments')->count()); self::assertSame($before, $this->row($id));
    }

    #[DataProvider('invalidIdentities')]
    public function test_authentication_failures(string $reason): void
    {
        $id = $this->fixture(); $before = $this->row($id);
        $browser = $reason === 'anonymous' ? new CookieBrowser(self::$http->urls[0]) : $this->browser('a');
        if ($reason === 'anonymous') { $this->check($browser->request('GET', '/sanctum/csrf-cookie'), 204); }
        elseif ($reason === 'stopped') { DB::table('users')->where('id', $this->users['a']->id)->update(['is_active' => false]); }
        elseif ($reason === 'version') { DB::table('users')->where('id', $this->users['a']->id)->increment('auth_version'); }
        elseif ($reason === 'absolute') {
            for ($seconds = 1200; $seconds < 28800; $seconds += 1200) {
                self::$http->time(self::TIME + $seconds);
                $this->check($browser->request('GET', '/api/me'), 200);
            }
            self::$http->time(self::TIME + 28800);
        } else { self::$http->time(self::TIME + 1800); }
        $this->check($browser->request('POST', $this->url($id), ['body' => 'Denied']), 401);
        $this->check($browser->request('GET', $this->url($id)), 401);
        self::assertSame(0, DB::table('comments')->count()); self::assertSame($before, $this->row($id));
    }
    public static function invalidIdentities(): array { return [['anonymous'], ['stopped'], ['version'], ['idle'], ['absolute']]; }

    #[DataProvider('failures')]
    public function test_comment_and_session_are_rolled_back_together(string $failure): void
    {
        $id = $this->fixture(); $browser = $this->browser('a'); $before = $this->row($id);
        if ($failure === 'session') {
            DB::unprepared("CREATE FUNCTION refuse_comment_session() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture session refused'; END $$;
                CREATE TRIGGER refuse_comment_session BEFORE INSERT OR UPDATE ON sessions FOR EACH ROW EXECUTE FUNCTION refuse_comment_session();");
        } else { file_put_contents(self::$http->scenario, 'fail_after_comment'); }
        try {
            $this->check($browser->request('POST', $this->url($id), ['body' => 'Private failure fixture']), $failure === 'session' ? 503 : 500);
            self::assertSame(0, DB::table('comments')->count()); self::assertSame($before, $this->row($id));
            $log = file_get_contents(self::$http->log);
            foreach (['SQLSTATE', 'Private failure fixture', self::PASSWORD, 'fixture session refused'] as $value) { self::assertStringNotContainsString($value, $log); }
        } finally {
            if ($failure === 'session') { DB::unprepared('DROP TRIGGER refuse_comment_session ON sessions; DROP FUNCTION refuse_comment_session()'); }
        }
    }
    public static function failures(): array { return [['session'], ['exception']]; }

    #[DataProvider('orders')]
    public function test_independent_sessions_serialize_comments_and_completion(string $order): void
    {
        $id = $this->fixture('waiting_confirmation', 'y', 7); $before = $this->row($id);
        $a = $this->browser('a', 0); $x = $this->browser('x', 1);
        self::assertTrue($a->cookies['it_requests_session'] !== $x->cookies['it_requests_session']);
        self::assertSame(2, DB::table('sessions')->whereNotNull('user_id')->distinct()->count('user_id'));
        $completeFirst = $order === 'complete_first';
        file_put_contents(self::$http->scenario, $completeFirst ? 'hold_after_update' : 'hold_after_comment');
        $statusUrl = '/api/requests/'.$id.'/status'; $statusBody = ['status' => 'completed', 'expected_version' => 7];
        $firstBrowser = $completeFirst ? $x : $a; $secondBrowser = $completeFirst ? $a : $x;
        [$multi, $first] = $this->start($firstBrowser, $completeFirst ? 'PATCH' : 'POST', $completeFirst ? $statusUrl : $this->url($id), $completeFirst ? $statusBody : ['body' => 'First']);
        $pending = [[$firstBrowser, $first]];
        try {
            $this->entered($multi);
            self::assertSame(0, DB::table('comments')->count()); self::assertSame($before, $this->row($id));
            // First worker captured its scenario already; only the second worker skips the barrier.
            file_put_contents(self::$http->scenario, '');
            $secondCompletes = $order === 'comment_first';
            $second = $secondBrowser->handle($secondCompletes ? 'PATCH' : 'POST', $secondCompletes ? $statusUrl : $this->url($id), json_encode($secondCompletes ? $statusBody : ['body' => 'Second']));
            curl_multi_add_handle($multi, $second); $pending[] = [$secondBrowser, $second];
            $this->waitForLock($multi, 'service_requests');
        } finally { $responses = $this->release($multi, $pending); }
        $this->check($responses[0], $completeFirst ? 200 : 201);
        $this->check($responses[1], $completeFirst ? 409 : ($order === 'two_comments' ? 201 : 200), $completeFirst ? 'request_completed' : null);
        $after = $this->row($id);
        if ($order === 'two_comments') {
            self::assertSame($before, $after);
            self::assertSame(['First', 'Second'], DB::table('comments')->orderBy('created_at')->orderBy('id')->pluck('body')->all());
            self::assertSame([$this->users['a']->id, $this->users['x']->id], array_map('strval', DB::table('comments')->orderBy('id')->pluck('author_id')->all()));
        } else {
            self::assertSame('completed', $after['status']); self::assertSame(8, $after['version']);
            self::assertSame($completeFirst ? 0 : 1, DB::table('comments')->count());
            if (! $completeFirst) { self::assertSame('First', DB::table('comments')->value('body')); }
        }
    }
    public static function orders(): array { return [['comment_first'], ['complete_first'], ['two_comments']]; }

    private function start(CookieBrowser $browser, string $method, string $url, array $body): array
    {
        $handle = $browser->handle($method, $url, json_encode($body));
        $multi = curl_multi_init(); curl_multi_add_handle($multi, $handle);
        return [$multi, $handle];
    }
    private function entered($multi): void
    {
        $deadline = microtime(true) + 3;
        do {
            curl_multi_exec($multi, $running);
            if (is_file(self::$http->barrier.'.entered')) { return; }
            usleep(10000);
        } while ($running && microtime(true) < $deadline);
        self::fail('Worker did not reach the explicit transaction barrier.');
    }
    private function waitForLock($multi, string $table): void
    {
        $deadline = microtime(true) + 3;
        do {
            curl_multi_exec($multi, $running);
            $n = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND usename = current_user AND wait_event_type = 'Lock' AND query LIKE ?", ['%'.$table.'%for update%'])->n;
            if ($n > 0) { self::assertGreaterThan(0, (int) $n); return; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Second worker did not wait on the expected database row.');
    }
    private function release($multi, array $requests): array
    {
        touch(self::$http->barrier.'.release');
        do { curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi, 0.05); } } while ($running);
        $responses = [];
        foreach ($requests as [$browser, $handle]) {
            self::assertSame(0, curl_errno($handle), 'HTTP transport failed.');
            $responses[] = $browser->finish($handle, curl_multi_getcontent($handle)); curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
        return $responses;
    }

}
