<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthenticatedSession
{
    public function handle(Request $request, Closure $next)
    {
        $identity = Auth::guard('sanctum')->user();
        $user = $identity ? User::whereKey($identity->id)->sharedLock()->first() : null;
        $session = $request->session();
        $now = now()->getTimestamp();
        $start = $session->get('authenticated_at');
        $last = $session->get('last_authenticated_activity_at');
        if (! $user || ! $user->is_active || $session->get('auth_version') !== $user->auth_version
            || ! is_int($start) || ! is_int($last) || $start > $now || $last > $now
            || $now - $start >= 28800 || $now - $last >= 1800) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return ApiError::response(401, 'unauthenticated');
        }
        Auth::guard('web')->setUser($user);
        Auth::guard('sanctum')->setUser($user);
        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $session->put('last_authenticated_activity_at', now()->getTimestamp());
        }

        return $response;
    }
}
