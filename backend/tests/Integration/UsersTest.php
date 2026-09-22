<?php

namespace Tests\Integration;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

final class UsersTest extends DatabaseTestCase
{
    public function test_empty_schema_is_migrated_with_postgresql_identity_and_timestamps(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame(0, User::count());
        self::assertSame(1, DB::table('migrations')->count());
        $columns = collect(DB::select('SELECT column_name, data_type, is_identity, identity_generation, datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', ['users']))->keyBy('column_name');
        self::assertSame('bigint', $columns['id']->data_type);
        self::assertSame('YES', $columns['id']->is_identity);
        self::assertSame('BY DEFAULT', $columns['id']->identity_generation);
        foreach (['created_at', 'updated_at'] as $column) {
            self::assertSame('timestamp with time zone', $columns[$column]->data_type);
            self::assertSame(6, $columns[$column]->datetime_precision);
        }
        self::assertSame('UTC', DB::selectOne('SHOW TIMEZONE')->TimeZone);
    }

    #[DataProvider('roles')]
    public function test_each_role_can_be_saved_with_safe_defaults(UserRole $role): void
    {
        $user = $this->newUser($role);
        $user->save();
        $user->refresh();
        self::assertSame($role, $user->role);
        self::assertIsString($user->id);
        self::assertGreaterThan(0, (int) $user->id);
        self::assertFalse($user->is_active);
        self::assertSame(1, $user->auth_version);
        self::assertSame('employee-a@example.test', $user->email);
        self::assertSame('社員A', $user->display_name);
        self::assertNotNull($user->created_at);
        self::assertNotNull($user->updated_at);
    }

    public static function roles(): array
    {
        return [[UserRole::Employee], [UserRole::ItStaff]];
    }

    public function test_normalized_duplicate_is_rejected_by_database(): void
    {
        $this->newUser()->save();
        $duplicate = $this->newUser();
        $duplicate->email = ' EMPLOYEE-A@EXAMPLE.TEST ';
        try {
            $duplicate->save();
            self::fail('Normalized duplicate was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23505', $error->errorInfo[0]);
        }
    }

    public function test_direct_insert_duplicate_is_rejected_without_model(): void
    {
        DB::table('users')->insert($this->validRow());
        try {
            DB::table('users')->insert($this->validRow(['display_name' => '別人']));
            self::fail('Direct duplicate was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23505', $error->errorInfo[0]);
        }
    }

    #[DataProvider('invalidRows')]
    public function test_direct_insert_enforces_constraints(array $overrides, string $sqlState): void
    {
        try {
            DB::table('users')->insert($this->validRow($overrides));
            self::fail('Invalid direct insert was accepted.');
        } catch (QueryException $error) {
            self::assertSame($sqlState, $error->errorInfo[0]);
        }
    }

    public static function invalidRows(): array
    {
        $cases = [
            [['id' => 0], '23514'], [['id' => -1], '23514'],
            [['role' => 'admin'], '23514'], [['role' => ''], '23514'],
            [['auth_version' => 0], '23514'], [['auth_version' => -1], '23514'],
            [['display_name' => ''], '23514'], [['display_name' => str_repeat('あ', 51)], '22001'],
            [['email' => ''], '23514'], [['email' => 'Employee-A@example.test'], '23514'],
            [['email' => ' employee-a@example.test'], '23514'],
            [['email' => 'employee-a@example.test '], '23514'],
            [['email' => "employee-a@example.test\t"], '23514'],
            [['email' => '社員@example.test'], '23514'],
            [['email' => str_repeat('a', 255)], '22001'],
        ];
        foreach (['id', 'display_name', 'email', 'password', 'role', 'is_active', 'auth_version', 'created_at', 'updated_at'] as $column) {
            $cases[] = [[$column => null], '23502'];
        }

        return $cases;
    }

    public function test_direct_insert_preserves_defaults_and_maximum_display_name(): void
    {
        DB::table('users')->insert($this->validRow(['display_name' => str_repeat('あ', 50)]));
        $user = User::sole();
        self::assertFalse($user->is_active);
        self::assertSame(1, $user->auth_version);
        self::assertSame(50, mb_strlen($user->display_name));
        self::assertNotNull($user->created_at);
    }

    public function test_unique_id_role_can_be_referenced_by_a_composite_foreign_key(): void
    {
        $user = $this->newUser(UserRole::ItStaff);
        $user->save();
        // PostgreSQL cannot reference a permanent table from a TEMPORARY table.
        // This table belongs to the isolated schema and is rolled back at tearDown.
        DB::statement('CREATE TABLE users_reference_probe (user_id bigint, role varchar(16), FOREIGN KEY (user_id, role) REFERENCES users(id, role))');
        DB::table('users_reference_probe')->insert(['user_id' => $user->id, 'role' => 'it_staff']);
        self::assertSame(1, DB::table('users_reference_probe')->count());
    }

    public function test_active_and_stopped_values_persist_without_claiming_login_enforcement(): void
    {
        $user = $this->newUser();
        $user->is_active = true;
        $user->save();
        self::assertTrue($user->fresh()->is_active);
        $user->is_active = false;
        $user->auth_version = 2;
        $user->save();
        self::assertFalse($user->fresh()->is_active);
        self::assertSame(2, $user->fresh()->auth_version);
    }

    public function test_password_hash_and_private_fields_are_not_serialized(): void
    {
        $plain = str_repeat('あ', 128);
        $user = $this->newUser();
        $user->password = $plain;
        $user->save();
        $user->refresh();
        self::assertSame('argon2id', password_get_info($user->getRawOriginal('password'))['algoName']);
        self::assertTrue(Hash::check($plain, $user->getRawOriginal('password')));
        self::assertFalse(Hash::check($plain.'x', $user->getRawOriginal('password')));
        $json = json_decode($user->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'display_name', 'role'], array_keys($json));
        self::assertIsString($json['id']);
    }

    public function test_model_does_not_allow_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        User::create(['role' => 'it_staff', 'is_active' => true]);
    }

    public function test_model_rejects_whitespace_only_display_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $user = new User;
        $user->display_name = " \u{3000}\t";
    }

    public function test_display_name_normalizes_line_endings_per_text_contract(): void
    {
        $user = $this->newUser();
        $user->display_name = " A\r\nB\rC ";
        $user->save();
        self::assertSame("A\nB\nC", $user->fresh()->display_name);
    }

    public function test_rerunning_migrate_keeps_existing_user(): void
    {
        $user = $this->newUser();
        $user->save();
        $user->refresh();
        $before = $user->getRawOriginal();
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
        self::assertSame(1, User::count());
        self::assertSame($before, $user->fresh()->getRawOriginal());
    }

    private function newUser(UserRole $role = UserRole::Employee): User
    {
        $user = new User;
        $user->display_name = ' 社員A ';
        $user->email = ' Employee-A@EXAMPLE.TEST ';
        $user->password = 'only-a-local-test-fixture';
        $user->role = $role;

        return $user;
    }
}
