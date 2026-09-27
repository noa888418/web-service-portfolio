<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LoginLimits
{
    public function consume(string $purpose, string $value, int $limit): int
    {
        return DB::transaction(function () use ($purpose, $value, $limit): int {
            $key = 'login:'.PgLock::key($purpose, $value);
            PgLock::acquire(PgLock::key('counter', $key));
            $now = now()->getTimestamp();
            $window = Cache::get($key);
            if (! is_array($window) || $window['until'] <= $now) {
                $window = ['count' => 0, 'until' => $now + 60];
            }
            if ($window['count'] >= $limit) {
                return max(1, $window['until'] - $now);
            }
            $window['count']++;
            Cache::put($key, $window, $window['until'] - $now);

            return 0;
        });
    }
}
