<?php

namespace App\Http;

use App\Auth\LoginLimits;
use App\Models\User;
use App\Support\EmailAddressValidator;
use App\Support\EmailNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AuthController
{
    public function login(Request $request, LoginLimits $limits)
    {
        if ($error = AuthInput::check($request, ['email', 'password'])) {
            return $error;
        }
        $input = $request->json()->all();
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        $fields = [];
        try {
            if (! is_string($email)) {
                throw new \InvalidArgumentException;
            }
            $email = EmailNormalizer::normalize($email);
            EmailAddressValidator::validate($email);
        } catch (\InvalidArgumentException) {
            $fields['email'] = ['有効なメールアドレスを指定してください。'];
        }
        if (! is_string($password) || ! mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 1 || mb_strlen($password) > 128 || str_contains($password, "\0")) {
            $fields['password'] = ['パスワードは1～128文字で指定してください。'];
        }
        // A valid normalized email consumes its quota even when password input is invalid.
        if (! isset($fields['email'])) {
            $wait = $limits->consume('email', $email, 5);
            if ($wait) {
                return ApiError::response(429, 'rate_limited', headers: ['Retry-After' => (string) $wait]);
            }
        }
        if ($fields) {
            return ApiError::response(422, 'validation_failed', $fields);
        }
        $user = User::where('email', $email)->sharedLock()->first();
        if ($user) {
            $matches = Hash::check($password, $user->password);
        } else {
            // Burn the same configured Argon2id cost without a fixed deployable credential.
            Hash::make($password);
            $matches = false;
        }
        if (! $user || ! $matches || ! $user->is_active) {
            return ApiError::response(401, 'invalid_credentials');
        }
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->put([
            'authenticated_at' => now()->getTimestamp(),
            'last_authenticated_activity_at' => now()->getTimestamp(),
            'auth_version' => $user->auth_version,
        ]);

        return response()->json(['data' => $user->toArray()]);
    }

    public function logout(Request $request)
    {
        if ($error = AuthInput::check($request)) {
            return $error;
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        if ($error = AuthInput::check($request)) {
            return $error;
        }

        return response()->json(['data' => $request->user('sanctum')->toArray()]);
    }
}
