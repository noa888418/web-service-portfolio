<?php

namespace App\Http\Middleware;

use App\Auth\LoginLimits;
use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;

final class ApiBoundary
{
    public function handle(Request $request, Closure $next)
    {
        $response = $this->dispatch($request, $next);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function dispatch(Request $request, Closure $next)
    {
        // No trusted proxies in the local direct-HTTP deployment. Forwarded headers
        // are not used as an identity; AWS proxy trust must be configured separately.
        if ($request->isMethod('POST') && $request->path() === 'login') {
            $wait = app(LoginLimits::class)->consume('ip', $request->ip() ?? 'unknown', 30);
            if ($wait) {
                return ApiError::response(429, 'rate_limited', headers: ['Retry-After' => (string) $wait]);
            }
        }
        if ($request->isMethod('POST') || $request->isMethod('PATCH')) {
            $raw = $request->getContent();
            if (strlen($raw) > 65536) {
                return ApiError::response(413, 'payload_too_large');
            }
            if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                return ApiError::response(415, 'unsupported_media_type');
            }
            try {
                $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ApiError::response(400, 'bad_request');
            }
            if (! $object instanceof \stdClass || $this->hasDuplicateKeys($raw)) {
                return ApiError::response(400, 'bad_request');
            }
        }

        return $next($request);
    }

    private function hasDuplicateKeys(string $json): bool
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^{}\[\]:,\s]+/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $i => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($matches[0][$i + 1] ?? '') === ':') {
                $key = json_decode($token, true, flags: JSON_THROW_ON_ERROR);
                $level = count($stack) - 1;
                if (isset($stack[$level][$key])) {
                    return true;
                }
                $stack[$level][$key] = true;
            }
        }

        return false;
    }
}
