<?php

namespace Tests\Comments;

use App\Models\Comment;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

final class CommentSchemaTest extends DatabaseTestCase
{
    private function row(): array
    {
        $user = DB::table('users')->insertGetId($this->validRow());
        $request = DB::table('service_requests')->insertGetId(['requester_id' => $user, 'title' => 'Fixture', 'body' => 'Fixture', 'category' => 'inquiry']);
        return ['service_request_id' => $request, 'author_id' => $user, 'body' => 'Plain text'];
    }

    public function test_schema_defaults_model_and_indexes(): void
    {
        $row = $this->row();
        $id = DB::table('comments')->insertGetId($row);
        $comment = Comment::findOrFail($id);
        self::assertIsString($comment->id);
        self::assertSame((string) $row['service_request_id'], $comment->service_request_id);
        self::assertSame((string) $row['author_id'], $comment->author_id);
        self::assertNotNull($comment->created_at);
        self::assertSame(['id', 'body', 'created_at'], array_keys($comment->toArray()));
        $columns = collect(DB::select("SELECT column_name, data_type, is_identity, datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'comments'"))->keyBy('column_name');
        self::assertCount(5, $columns);
        self::assertSame('YES', $columns['id']->is_identity);
        self::assertSame('timestamp with time zone', $columns['created_at']->data_type);
        self::assertSame(6, $columns['created_at']->datetime_precision);
        self::assertCount(3, DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'comments'"));
    }

    #[DataProvider('invalidRows')]
    public function test_direct_insert_constraints(array $override, string $state): void
    {
        $row = array_replace($this->row(), $override);
        try {
            DB::table('comments')->insert($row);
            self::fail('Invalid comment was accepted.');
        } catch (QueryException $error) { self::assertSame($state, $error->errorInfo[0]); }
    }
    public static function invalidRows(): array
    {
        $cases = [
            [['id' => 0], '23514'], [['id' => -1], '23514'],
            [['body' => ''], '23514'], [['body' => str_repeat('😀', 2001)], '23514'],
            [['service_request_id' => 9223372036854775807], '23503'],
            [['author_id' => 9223372036854775807], '23503'],
        ];
        foreach (['id', 'service_request_id', 'author_id', 'body', 'created_at'] as $field) { $cases[] = [[$field => null], '23502']; }
        return $cases;
    }

    public function test_unicode_limit_and_parent_cascade_preserves_other_comments(): void
    {
        $row = $this->row();
        DB::table('comments')->insert(array_replace($row, ['body' => str_repeat('😀', 2000)]));
        $other = DB::table('service_requests')->insertGetId(['requester_id' => $row['author_id'], 'title' => 'Other', 'body' => 'Other', 'category' => 'bug']);
        DB::table('comments')->insert(array_replace($row, ['service_request_id' => $other]));
        DB::table('service_requests')->where('id', $row['service_request_id'])->delete();
        self::assertSame(1, DB::table('comments')->count());
        self::assertSame($other, DB::table('comments')->value('service_request_id'));
        self::assertSame(1, DB::table('users')->count());
    }

    public function test_author_delete_is_restricted(): void
    {
        $row = $this->row();
        $author = DB::table('users')->insertGetId($this->validRow(['email' => 'author@example.test']));
        DB::table('comments')->insert(array_replace($row, ['author_id' => $author]));
        try { DB::table('users')->where('id', $author)->delete(); self::fail('Referenced author deleted.'); }
        catch (QueryException $error) { self::assertSame('23001', $error->errorInfo[0]); }
    }

    public function test_additive_migration_preserves_existing_users_and_requests(): void
    {
        // DDL and fixtures are confined to the guarded test schema and rolled back by the base class.
        $row = $this->row();
        $before = (array) DB::table('service_requests')->first();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000001_create_comments_table.php';
        $migration->down();
        $migration->up();
        self::assertSame($before, (array) DB::table('service_requests')->first());
        self::assertSame(1, DB::table('users')->where('id', $row['author_id'])->count());
        DB::table('comments')->insert($row);
        self::assertSame(1, DB::table('comments')->count());
    }

    public function test_mass_assignment_is_rejected(): void
    {
        $this->expectException(MassAssignmentException::class);
        Comment::create(['body' => 'forged']);
    }
}
