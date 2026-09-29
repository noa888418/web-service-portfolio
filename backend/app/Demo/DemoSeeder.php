<?php

namespace App\Demo;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DemoSeeder
{
    public const ACCOUNTS = [
        'employee_a' => ['employee-a@example.test', '社員A', 'employee'],
        'employee_b' => ['employee-b@example.test', '社員B', 'employee'],
        'it_x' => ['it-x@example.test', 'IT担当者X', 'it_staff'],
        'it_y' => ['it-y@example.test', 'IT担当者Y', 'it_staff'],
    ];

    public function run(string $target, string $publication, string $version, bool $allow, string $path): string
    {
        if (config('demo.purpose') !== 'demo') { throw new SeedRefused('purpose'); }
        if (config('app.debug')) { throw new SeedRefused('debug_enabled'); }
        if (! $allow) { throw new SeedRefused('explicit_permission'); }
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $publication) || $version !== '1') {
            throw new SeedRefused('publication_or_seed_version');
        }
        DemoTarget::verify($target);

        return DB::transaction(function () use ($publication, $version, $path) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '10s'");
            // Lock the record first, then all business tables, before checking emptiness.
            // EXCLUSIVE conflicts with writers and row-locking HTTP operations.
            DB::statement('LOCK TABLE demo_seed_runs, users, service_requests, comments IN EXCLUSIVE MODE');
            $previous = DB::table('demo_seed_runs')->first();
            if ($previous) {
                if ($previous->publication_id !== $publication || $previous->seed_version !== $version) {
                    throw new SeedRefused('different_seed_record');
                }
                return 'no-op'; // Do not read credentials, rehash, reactivate or repair data.
            }
            foreach (['users', 'service_requests', 'comments'] as $table) {
                if (DB::table($table)->exists()) { throw new SeedRefused('nonempty_business_tables'); }
            }
            $credentials = $this->credentials($path);
            $ids = [];
            $start = now()->utc()->subDay();
            foreach (self::ACCOUNTS as $key => [$email, $name, $role]) {
                $user = new User;
                $user->email = $email; $user->display_name = $name; $user->role = $role;
                $user->password = $credentials[$key]['password']; // Existing Argon2id cast.
                $user->is_active = true; $user->auth_version = 1;
                $user->created_at = $start; $user->updated_at = $start;
                $user->save(); $ids[$key] = $user->id;
            }
            $fixtures = [
                ['employee_a', 'inquiry', 'open', null, 1, '検証端末の利用相談'],
                ['employee_b', 'bug', 'in_progress', 'it_x', 3, '架空プリンターの印刷不具合'],
                ['employee_a', 'improvement', 'waiting_confirmation', 'it_y', 4, '架空の案内ページの改善'],
                ['employee_b', 'inquiry', 'completed', 'it_x', 5, '検証用アカウントの利用方法'],
            ];
            foreach ($fixtures as $i => [$owner, $category, $status, $assignee, $requestVersion, $title]) {
                $created = $start->copy()->addHours($i + 1);
                $commented = $created->copy()->addMinutes(10);
                $updated = $status === 'open' ? $created : $created->copy()->addMinutes(20);
                $id = DB::table('service_requests')->insertGetId([
                    'requester_id' => $ids[$owner], 'title' => $title, 'body' => '架空データによるローカル検証用の依頼です。実在の機密情報は入力しないでください。',
                    'category' => $category, 'status' => $status, 'assignee_id' => $assignee ? $ids[$assignee] : null,
                    'version' => $requestVersion, 'created_at' => $created, 'updated_at' => $updated,
                ]);
                DB::table('comments')->insert([
                    'service_request_id' => $id, 'author_id' => $ids[$owner],
                    'body' => $status === 'completed' ? '案内内容を確認しました。対応の完了をお願いします。' : '検証用の補足情報です。',
                    'created_at' => $commented,
                ]);
            }
            DB::table('demo_seed_runs')->insert(['id' => 1, 'publication_id' => $publication, 'seed_version' => $version, 'completed_at' => now()]);
            return 'created';
        });
    }

    private function credentials(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 16384) { throw new SeedRefused('credentials_file'); }
        $data = json_decode(file_get_contents($path), true);
        $accounts = $data['accounts'] ?? null;
        if (! is_array($accounts) || array_keys($accounts) !== array_keys(self::ACCOUNTS)) { throw new SeedRefused('credentials_format'); }
        $passwords = [];
        foreach (self::ACCOUNTS as $key => [$email]) {
            $entry = $accounts[$key];
            if (! is_array($entry) || ($entry['email'] ?? null) !== $email || ! is_string($entry['password'] ?? null)
                || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $entry['password'])) { throw new SeedRefused('credentials_format'); }
            $passwords[] = $entry['password'];
        }
        if (count(array_unique($passwords)) !== 4) { throw new SeedRefused('credentials_not_distinct'); }
        return $accounts;
    }
}
