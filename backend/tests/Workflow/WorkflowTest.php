<?php

namespace Tests\Workflow;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AuthHttpEnvironment;
use Tests\Support\CookieBrowser;

final class WorkflowTest extends TestCase
{
    private static AuthHttpEnvironment $http;
    private array $users;
    private const TIME = 1800000000;
    private const PASSWORD = 'workflow-local-fixture-only';

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
        foreach (['service_requests', 'sessions', 'cache', 'cache_locks', 'users'] as $table) { DB::table($table)->delete(); }
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
            'title' => 'Workflow fixture', 'body' => 'Fictional text', 'category' => 'inquiry', 'status' => $status,
            'assignee_id' => $assignee === null ? null : $this->users[$assignee]->id, 'version' => $version]);
    }
    private function row(string $id): array { return (array) DB::table('service_requests')->where('id', $id)->first(); }
    private function patch(CookieBrowser $browser, string $id, string $field, mixed $value, int $version = 1): array
    {
        return $browser->request('PATCH', '/api/requests/'.$id.'/'.($field === 'status' ? 'status' : 'assignee'), [$field => $value, 'expected_version' => $version]);
    }

    public function test_assign_replace_release_and_only_success_increments_version(): void
    {
        $id = $this->fixture(); $x = $this->browser(); $y = $this->browser('y');
        $initial = $this->row($id);
        $one = $this->check($this->patch($x, $id, 'assignee_id', $this->users['y']->id), 200)['data'];
        self::assertSame(['id' => $this->users['y']->id, 'display_name' => 'Y'], $one['assignee']);
        self::assertSame(2, $one['version']);
        self::assertNotSame($initial['updated_at'], $this->row($id)['updated_at']);
        $two = $this->check($this->patch($y, $id, 'assignee_id', $this->users['x']->id, 2), 200)['data'];
        self::assertSame(3, $two['version']); // Actor Y locks lower-ID X without reordering authentication.
        self::assertSame($this->users['x']->id, $two['assignee']['id']);
        $three = $this->check($this->patch($x, $id, 'assignee_id', null, 3), 200)['data'];
        self::assertSame(4, $three['version']); self::assertNull($three['assignee']);
        self::assertSame('open', $three['status']);
        $before = $this->row($id);
        $this->check($this->patch($x, $id, 'assignee_id', null, 4), 409, 'no_change');
        $this->check($this->patch($x, $id, 'assignee_id', $this->users['z']->id, 3), 409, 'stale_version');
        self::assertSame($before, $this->row($id));
        foreach (['requester_id', 'title', 'body', 'category', 'created_at'] as $field) { self::assertSame($initial[$field], $before[$field]); }
        self::assertSame(['id', 'title', 'category', 'requester', 'assignee', 'status', 'version', 'created_at', 'body', 'updated_at'], array_keys($three));
    }

    #[DataProvider('transitions')]
    public function test_every_status_transition(string $from, string $to, bool $allowed): void
    {
        $id = $this->fixture($from, 'z'); $browser = $this->browser(); $before = $this->row($id);
        $result = $this->check($this->patch($browser, $id, 'status', $to), $allowed ? 200 : 409);
        if ($allowed) {
            self::assertSame($to, $result['data']['status']); self::assertSame(2, $result['data']['version']);
            self::assertSame($this->users['z']->id, $result['data']['assignee']['id']);
            self::assertSame(2, $this->row($id)['version']);
        } else { self::assertSame($before, $this->row($id)); }
    }
    public static function transitions(): array
    {
        $cases = [];
        foreach (['open', 'in_progress', 'waiting_confirmation', 'completed'] as $from) {
            foreach (['open', 'in_progress', 'waiting_confirmation', 'completed'] as $to) {
                $cases[$from.' to '.$to] = [$from, $to, in_array($from.':'.$to, ['open:in_progress', 'in_progress:waiting_confirmation', 'waiting_confirmation:in_progress', 'waiting_confirmation:completed'], true)];
            }
        }
        return $cases;
    }

    public function test_unassigned_stopped_repair_and_completed_assignment_rules(): void
    {
        $id = $this->fixture(); $browser = $this->browser(); $before = $this->row($id);
        $this->check($this->patch($browser, $id, 'status', 'in_progress'), 409, 'invalid_assignee_state');
        self::assertSame($before, $this->row($id));
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['y']->id), 200);
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['y']->id, 2), 409, 'no_change');
        $this->check($this->patch($browser, $id, 'status', 'in_progress', 2), 200);
        $before = $this->row($id);
        $this->check($this->patch($browser, $id, 'assignee_id', null, 3), 409, 'invalid_assignee_state');
        DB::table('users')->where('id', $this->users['y']->id)->update(['is_active' => false]);
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['y']->id, 3), 409, 'no_change');
        $this->check($this->patch($browser, $id, 'status', 'waiting_confirmation', 3), 409, 'invalid_assignee_state');
        self::assertSame($before, $this->row($id));
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['z']->id, 3), 200);
        $this->check($this->patch($browser, $id, 'status', 'waiting_confirmation', 4), 200);
        $before = $this->row($id);
        $this->check($this->patch($browser, $id, 'assignee_id', null, 5), 409);
        self::assertSame($before, $this->row($id));
        $this->check($this->patch($browser, $id, 'status', 'completed', 5), 200);
        $before = $this->row($id);
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['x']->id, 6), 409, 'request_completed');
        $this->check($this->patch($browser, $id, 'assignee_id', null, 6), 409);
        self::assertSame($before, $this->row($id));
    }

    public function test_candidate_pagination_minimal_fields_and_completed_rejection(): void
    {
        $id = $this->fixture(); $browser = $this->browser();
        DB::table('users')->where('id', $this->users['z']->id)->update(['is_active' => false]);
        $ids = [$this->users['x']->id, $this->users['y']->id];
        $hash = $this->users['x']->password;
        for ($i = 0; $i < 18; $i++) {
            $ids[] = (string) DB::table('users')->insertGetId(['display_name' => 'Candidate', 'email' => 'candidate'.$i.'@example.test', 'password' => $hash, 'role' => 'it_staff', 'is_active' => true]);
        }
        $url = '/api/requests/'.$id.'/assignee-candidates';
        $twenty = $this->check($browser->request('GET', $url), 200);
        self::assertSame($ids, array_column($twenty['data'], 'id'));
        self::assertSame(['current_page' => 1, 'per_page' => 20, 'total' => 20, 'last_page' => 1], $twenty['meta']);
        foreach ($twenty['data'] as $item) { self::assertSame(['id', 'display_name'], array_keys($item)); }
        DB::table('users')->where('id', $this->users['z']->id)->update(['is_active' => true]);
        $ids = [$ids[0], $ids[1], $this->users['z']->id, ...array_slice($ids, 2)];
        foreach ([1, 2, 3, 2147483647] as $page) {
            $json = $this->check($browser->request('GET', $url.'?page='.$page), 200);
            self::assertSame(array_slice($ids, ($page - 1) * 20, 20), array_column($json['data'], 'id'));
            self::assertSame(['current_page' => $page, 'per_page' => 20, 'total' => 21, 'last_page' => 2], $json['meta']);
        }
        foreach (['0', '-1', '01', '2147483648', '1.5', '1&page[]=2'] as $page) { $this->check($browser->request('GET', $url.'?page='.$page), 422); }
        $this->check($browser->request('GET', $url.'?role=it_staff'), 403);
        $this->check($browser->request('GET', $url.'?per_page=100'), 422);
        $completed = $this->fixture('completed', 'y');
        $this->check($browser->request('GET', '/api/requests/'.$completed.'/assignee-candidates'), 409, 'request_completed');
    }

    public function test_employee_permissions_and_indistinguishable_not_found(): void
    {
        $id = $this->fixture(); $a = $this->browser('a'); $b = $this->browser('b'); $before = $this->row($id);
        foreach (['assignee-candidates', 'assignee', 'status'] as $endpoint) {
            $method = $endpoint === 'assignee-candidates' ? 'GET' : 'PATCH';
            $input = $method === 'GET' ? null : ['expected_version' => 1];
            $this->check($a->request($method, '/api/requests/'.$id.'/'.$endpoint, $input), 403);
            $other = $this->check($b->request($method, '/api/requests/'.$id.'/'.$endpoint, $input), 404);
            foreach (['9223372036854775807', '9223372036854775808', '0', '01', 'bad'] as $missing) {
                self::assertSame($other, $this->check($b->request($method, '/api/requests/'.$missing.'/'.$endpoint, $input), 404));
            }
        }
        self::assertSame($before, $this->row($id));
    }

    public function test_invalid_fields_candidates_and_versions_leave_every_business_column_unchanged(): void
    {
        $id = $this->fixture(); $browser = $this->browser(); $before = $this->row($id);
        DB::table('users')->where('id', $this->users['z']->id)->update(['is_active' => false]);
        foreach ([$this->users['a']->id, $this->users['z']->id, '9223372036854775807', '9223372036854775808', 3, '01', '0', '', [], true] as $value) {
            $this->check($this->patch($browser, $id, 'assignee_id', $value), 422);
            self::assertSame($before, $this->row($id));
        }
        foreach (['assignee' => ['assignee_id' => $this->users['y']->id], 'status' => ['status' => 'in_progress']] as $endpoint => $valid) {
            $url = '/api/requests/'.$id.'/'.$endpoint;
            foreach ([null, 0, -1, '1', 1.5, true, [], 2147483648] as $version) {
                $this->check($browser->request('PATCH', $url, $valid + ['expected_version' => $version]), 422);
            }
            $this->check($browser->request('PATCH', $url, $valid), 422);
            $this->check($browser->request('PATCH', $url, ['expected_version' => 1]), 422);
            foreach (['requester_id', 'role', 'assignee_role', 'id', 'version', 'is_active', 'auth_version', 'created_at', 'updated_at', 'password'] as $key) {
                $this->check($browser->request('PATCH', $url, $valid + ['expected_version' => 1, $key => 'forged']), 403);
            }
            $opposite = $endpoint === 'assignee' ? 'status' : 'assignee_id';
            $this->check($browser->request('PATCH', $url, $valid + ['expected_version' => 1, $opposite => 'forged']), 403);
            foreach (['title', 'body', 'category', 'extra'] as $key) {
                $this->check($browser->request('PATCH', $url, $valid + ['expected_version' => 1, $key => 'forged']), 422);
            }
            $this->check($browser->request('PATCH', $url.'?requester_id=1', $valid + ['expected_version' => 1]), 403);
            $this->check($browser->request('PATCH', $url.'?expected_version=1', $valid + ['expected_version' => 1]), 422);
            self::assertSame($before, $this->row($id));
        }
        foreach (['unknown', null, '', ' in_progress ', 1, []] as $status) { $this->check($this->patch($browser, $id, 'status', $status), 422); }
        self::assertSame($before, $this->row($id));
    }

    #[DataProvider('invalidIdentities')]
    public function test_authentication_and_expiration_cover_all_new_routes(string $reason): void
    {
        $id = $this->fixture();
        $browser = $reason === 'anonymous' ? new CookieBrowser(self::$http->urls[0]) : $this->browser();
        if ($reason === 'anonymous') { $this->check($browser->request('GET', '/sanctum/csrf-cookie'), 204); }
        elseif ($reason === 'stopped') { DB::table('users')->where('id', $this->users['x']->id)->update(['is_active' => false]); }
        elseif ($reason === 'version') { DB::table('users')->where('id', $this->users['x']->id)->increment('auth_version'); }
        else { self::$http->time(self::TIME + ($reason === 'idle' ? 1800 : 28800)); }
        $before = $this->row($id);
        $this->check($this->patch($browser, $id, 'assignee_id', $this->users['y']->id), 401);
        $this->check($this->patch($browser, $id, 'status', 'in_progress'), 401);
        $this->check($browser->request('GET', '/api/requests/'.$id.'/assignee-candidates'), 401);
        self::assertSame($before, $this->row($id));
    }
    public static function invalidIdentities(): array { return [['anonymous'], ['stopped'], ['version'], ['idle'], ['absolute']]; }

    public function test_patch_csrf_json_boundaries_and_version_overflow(): void
    {
        $id = $this->fixture('open', 'z'); $browser = $this->browser(); $before = $this->row($id);
        foreach (['assignee' => ['assignee_id' => $this->users['y']->id], 'status' => ['status' => 'in_progress']] as $endpoint => $input) {
            $url = '/api/requests/'.$id.'/'.$endpoint; $input['expected_version'] = 1;
            $this->check($browser->request('PATCH', $url, $input, false), 419);
            $this->check($browser->request('PATCH', $url, $input, true, ['X-XSRF-TOKEN' => 'invalid']), 419);
            $this->check($browser->raw('PATCH', $url, '{"expected_version":1,"expected_version":2}'), 400);
            $this->check($browser->raw('PATCH', $url, '[]'), 400);
            $this->check($browser->raw('PATCH', $url, '{}', true, ['Content-Type' => 'text/plain']), 415);
            $this->check($browser->raw('PATCH', $url, str_repeat('x', 65537)), 413);
        }
        self::assertSame($before, $this->row($id));
        $max = $this->fixture('open', 'z', 2147483647); $before = $this->row($max);
        $this->check($this->patch($browser, $max, 'status', 'in_progress', 2147483647), 500);
        self::assertSame($before, $this->row($max));
    }

    #[DataProvider('writeFailures')]
    public function test_late_failure_rolls_back_assignment_status_version_and_timestamp(string $endpoint, string $failure): void
    {
        $id = $this->fixture('open', 'z'); $browser = $this->browser(); $before = $this->row($id);
        if ($failure === 'session') {
            DB::unprepared("CREATE FUNCTION refuse_workflow_session() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture session refused'; END $$;
                CREATE TRIGGER refuse_workflow_session BEFORE INSERT OR UPDATE ON sessions FOR EACH ROW EXECUTE FUNCTION refuse_workflow_session();");
        } else { file_put_contents(self::$http->scenario, 'fail_after_update'); }
        try {
            $this->check($this->patch($browser, $id, $endpoint, $endpoint === 'status' ? 'in_progress' : $this->users['y']->id), $failure === 'session' ? 503 : 500);
            self::assertSame($before, $this->row($id));
            $log = file_get_contents(self::$http->log);
            self::assertStringNotContainsString('SQLSTATE', $log);
            self::assertStringNotContainsString(self::PASSWORD, $log);
            self::assertStringNotContainsString('fixture session refused', $log);
        } finally {
            if ($failure === 'session') { DB::unprepared('DROP TRIGGER refuse_workflow_session ON sessions; DROP FUNCTION refuse_workflow_session()'); }
        }
    }
    public static function writeFailures(): array { return [['assignee_id', 'session'], ['status', 'session'], ['assignee_id', 'exception'], ['status', 'exception']]; }

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

    #[DataProvider('races')]
    public function test_independent_actors_race_on_one_request(string $first, string $second, int $loserVersion): void
    {
        $id = $this->fixture('open', 'z'); $x = $this->browser('x', 0); $y = $this->browser('y', 1);
        self::assertTrue($x->cookies['it_requests_session'] !== $y->cookies['it_requests_session']);
        self::assertSame(2, DB::table('sessions')->whereNotNull('user_id')->distinct()->count('user_id'));
        $before = $this->row($id);
        file_put_contents(self::$http->scenario, 'hold_after_update');
        $firstField = $first === 'status' ? 'status' : 'assignee_id';
        $secondField = $second === 'status' ? 'status' : 'assignee_id';
        [$multi, $one] = $this->start($x, 'PATCH', '/api/requests/'.$id.'/'.$first, [$firstField => $first === 'status' ? 'in_progress' : $this->users['y']->id, 'expected_version' => 1]);
        $pending = [[$x, $one]];
        try {
            $this->entered($multi);
            self::assertSame($before, $this->row($id));
            $two = $y->handle('PATCH', '/api/requests/'.$id.'/'.$second, json_encode([$secondField => $second === 'status' ? 'in_progress' : $this->users['x']->id, 'expected_version' => $loserVersion]));
            curl_multi_add_handle($multi, $two); $pending[] = [$y, $two];
            $this->waitForLock($multi, 'service_requests');
        } finally { $responses = $this->release($multi, $pending); }
        $this->check($responses[0], 200); $rejected = $this->check($responses[1], 409, 'stale_version');
        self::assertSame(['error'], array_keys($rejected));
        $after = $this->row($id); self::assertSame(2, $after['version']);
        self::assertSame($first === 'status' ? 'in_progress' : 'open', $after['status']);
        self::assertSame($first === 'status' ? $this->users['z']->id : $this->users['y']->id, (string) $after['assignee_id']);
    }
    public static function races(): array
    {
        return [['assignee', 'assignee', 1], ['status', 'status', 1], ['assignee', 'status', 1], ['status', 'assignee', 1], ['assignee', 'status', 2]];
    }

    public function test_assignment_first_keeps_candidate_valid_until_commit_then_stop_invalidates_future_status(): void
    {
        $id = $this->fixture(); $x = $this->browser(); $manager = new CookieBrowser(self::$http->urls[1]);
        $this->check($manager->request('GET', '/sanctum/csrf-cookie'), 204);
        file_put_contents(self::$http->scenario, 'hold_after_update');
        [$multi, $one] = $this->start($x, 'PATCH', '/api/requests/'.$id.'/assignee', ['assignee_id' => $this->users['y']->id, 'expected_version' => 1]);
        $pending = [[$x, $one]];
        try {
            $this->entered($multi);
            $stop = $manager->handle('POST', '/_test/stop', json_encode(['user' => $this->users['y']->id]));
            curl_multi_add_handle($multi, $stop); $pending[] = [$manager, $stop];
            $this->waitForLock($multi, 'users');
        } finally { $responses = $this->release($multi, $pending); }
        $this->check($responses[0], 200); $this->check($responses[1], 204);
        self::assertFalse($this->users['y']->fresh()->is_active);
        self::assertSame($this->users['y']->id, (string) $this->row($id)['assignee_id']);
        $before = $this->row($id);
        $this->check($this->patch($x, $id, 'status', 'in_progress', 2), 409, 'invalid_assignee_state');
        self::assertSame($before, $this->row($id));
    }

    public function test_stop_first_makes_contended_assignment_503_then_inactive_candidate_422(): void
    {
        $id = $this->fixture(); $x = $this->browser(); $manager = new CookieBrowser(self::$http->urls[1]);
        $this->check($manager->request('GET', '/sanctum/csrf-cookie'), 204);
        $before = $this->row($id);
        file_put_contents(self::$http->scenario, 'hold_after_stop');
        [$multi, $stop] = $this->start($manager, 'POST', '/_test/stop', ['user' => $this->users['y']->id]);
        try {
            $this->entered($multi);
            $this->check($this->patch($x, $id, 'assignee_id', $this->users['y']->id), 503, 'temporarily_unavailable');
            self::assertSame($before, $this->row($id));
        } finally { $responses = $this->release($multi, [[$manager, $stop]]); }
        $this->check($responses[0], 204);
        self::assertFalse($this->users['y']->fresh()->is_active);
        $this->check($this->patch($x, $id, 'assignee_id', $this->users['y']->id), 422);
        self::assertSame($before, $this->row($id));
    }
}
