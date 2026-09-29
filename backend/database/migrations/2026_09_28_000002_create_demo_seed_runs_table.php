<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE demo_seed_runs (
                id smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                seed_version varchar(50) NOT NULL CHECK (char_length(seed_version) BETWEEN 1 AND 50),
                publication_id varchar(64) NOT NULL CHECK (char_length(publication_id) BETWEEN 1 AND 64),
                completed_at timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
    }

    public function down(): void { DB::statement('DROP TABLE demo_seed_runs'); }
};
