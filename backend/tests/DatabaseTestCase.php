<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    protected function validRow(array $overrides = []): array
    {
        static $hash;
        $hash ??= Hash::make('only-a-local-test-fixture');

        return array_replace([
            'display_name' => '社員A', 'email' => 'employee-a@example.test',
            'password' => $hash, 'role' => 'employee',
        ], $overrides);
    }
}
