<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountSessions
{
    // Management service only, no public endpoint. Same user -> session row order
    // as authentication. The shared user lock is held through session persistence.
    public function revoke(string $id, bool $stop = true): void
    {
        DB::transaction(function () use ($id, $stop): void {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $user->auth_version++;
            if ($stop) {
                $user->is_active = false;
            }
            $user->save();
            DB::table('sessions')->where('user_id', $id)->delete();
        });
    }
}
