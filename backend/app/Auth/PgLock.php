<?php

namespace App\Auth;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class PgLock
{
    public static function key(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose.':'.$value, config('app.key'));
    }

    // Caller must own a transaction. Unlike a cache lease this lock cannot expire
    // while an old request is still able to save its session.
    public static function acquire(string $key): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $locked = DB::selectOne("SELECT pg_try_advisory_xact_lock(('x' || substr(?, 1, 16))::bit(64)::bigint) AS locked", [$key])->locked;
            if ($locked) {
                return;
            }
            usleep(25_000);
        } while (hrtime(true) < $deadline);

        throw new ServiceUnavailableHttpException(null, 'Lock unavailable.');
    }
}
