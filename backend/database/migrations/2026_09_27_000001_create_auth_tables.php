<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sessions (
                id varchar(255) PRIMARY KEY,
                user_id bigint REFERENCES users(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                ip_address varchar(45), user_agent text,
                payload text NOT NULL,
                last_activity integer NOT NULL CHECK (last_activity >= 0)
            );
            CREATE INDEX sessions_user_id_index ON sessions(user_id);
            CREATE INDEX sessions_last_activity_index ON sessions(last_activity);
            CREATE TABLE cache (
                key varchar(255) PRIMARY KEY, value text NOT NULL,
                expiration integer NOT NULL CHECK (expiration >= 0)
            );
            CREATE INDEX cache_expiration_index ON cache(expiration);
            CREATE TABLE cache_locks (
                key varchar(255) PRIMARY KEY, owner varchar(255) NOT NULL,
                expiration integer NOT NULL CHECK (expiration >= 0)
            );
            CREATE INDEX cache_locks_expiration_index ON cache_locks(expiration);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE cache_locks; DROP TABLE cache; DROP TABLE sessions');
    }
};
