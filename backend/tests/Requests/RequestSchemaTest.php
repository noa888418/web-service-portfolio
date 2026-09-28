<?php

namespace Tests\Requests;

use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

final class RequestSchemaTest extends DatabaseTestCase
{
    private function row(): array
    {
        $id = DB::table('users')->insertGetId($this->validRow());

        return ['requester_id' => $id, 'title' => 'Local fixture', 'body' => 'Plain text', 'category' => 'inquiry'];
    }

    public function test_defaults_types_and_indexes(): void
    {
        $id = DB::table('service_requests')->insertGetId($this->row());
        $record = ServiceRequest::findOrFail($id);
        self::assertIsString($record->id);
        self::assertIsString($record->requester_id);
        self::assertSame('open', $record->status);
        self::assertNull($record->assignee_id);
        self::assertSame('it_staff', $record->assignee_role);
        self::assertSame(1, $record->version);
        self::assertNotNull($record->created_at);
        self::assertNotNull($record->updated_at);
        self::assertArrayNotHasKey('assignee_role', $record->toArray());
        $columns = collect(DB::select("SELECT column_name, data_type, is_identity, datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'service_requests'"))->keyBy('column_name');
        self::assertSame('YES', $columns['id']->is_identity);
        foreach (['created_at', 'updated_at'] as $field) {
            self::assertSame('timestamp with time zone', $columns[$field]->data_type);
            self::assertSame(6, $columns[$field]->datetime_precision);
        }
        $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'service_requests'");
        self::assertCount(4, $indexes); // PK plus the three designed indexes.
    }

    #[DataProvider('invalidRows')]
    public function test_direct_insert_constraints(array $override, string $state): void
    {
        $row = array_replace($this->row(), $override);
        try {
            DB::table('service_requests')->insert($row);
            self::fail('Invalid request row was accepted.');
        } catch (QueryException $error) {
            self::assertSame($state, $error->errorInfo[0]);
        }
    }

    public static function invalidRows(): array
    {
        $rows = [
            'id zero' => [['id' => 0], '23514'],
            'empty title' => [['title' => ''], '23514'],
            'title long' => [['title' => str_repeat('a', 101)], '22001'],
            'title LF' => [['title' => "a\nb"], '23514'],
            'title CR' => [['title' => "a\rb"], '23514'],
            'empty body' => [['body' => ''], '23514'],
            'body long' => [['body' => str_repeat('a', 5001)], '23514'],
            'category' => [['category' => 'unknown'], '23514'],
            'status' => [['status' => 'unknown'], '23514'],
            'missing requester' => [['requester_id' => 9223372036854775807], '23503'],
            'assignee helper' => [['assignee_role' => 'employee'], '23514'],
            'version zero' => [['version' => 0], '23514'],
            'version negative' => [['version' => -1], '23514'],
        ];
        foreach (['in_progress', 'waiting_confirmation', 'completed'] as $status) {
            $rows['unassigned '.$status] = [['status' => $status], '23514'];
        }
        foreach (['id', 'requester_id', 'title', 'body', 'category', 'assignee_role', 'status', 'version', 'created_at', 'updated_at'] as $field) {
            $rows['null '.$field] = [[$field => null], '23502'];
        }

        return $rows;
    }

    public function test_composite_fk_rejects_employee_as_assignee(): void
    {
        $row = $this->row();
        try {
            DB::table('service_requests')->insert($row + ['assignee_id' => $row['requester_id']]);
            self::fail('Employee assignee was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23503', $error->errorInfo[0]);
        }
    }

    public function test_it_reference_and_requester_delete_restriction(): void
    {
        $row = $this->row();
        $it = DB::table('users')->insertGetId($this->validRow(['email' => 'it@example.test', 'role' => 'it_staff']));
        DB::table('service_requests')->insert($row + ['assignee_id' => $it, 'status' => 'in_progress']);
        self::assertSame(1, DB::table('service_requests')->count());
        try {
            DB::table('users')->where('id', $row['requester_id'])->delete();
            self::fail('Referenced requester was deleted.');
        } catch (QueryException $error) {
            self::assertSame('23001', $error->errorInfo[0]); // PostgreSQL RESTRICT violation.
        }
    }

    public function test_mass_assignment_is_not_a_write_interface(): void
    {
        $this->expectException(MassAssignmentException::class);
        new ServiceRequest(['requester_id' => '1', 'status' => 'completed']);
    }
}
