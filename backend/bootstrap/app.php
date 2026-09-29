<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\ApiError;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withCommands([App\Console\Commands\DemoSeed::class])
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(App\Http\Middleware\ApiBoundary::class);
        // Validate raw JSON without trimming passwords or converting empty values.
        $middleware->remove([
            Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
            Illuminate\Http\Middleware\TrustProxies::class,
        ]);
        $middleware->web(replace: [
            Illuminate\Session\Middleware\StartSession::class => App\Http\Middleware\LockedSession::class,
            Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => App\Http\Middleware\VerifyCsrf::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error) {
            // Never include messages, traces, request values or SQL bindings.
            Illuminate\Support\Facades\Log::warning('request_failed', ['exception_type' => get_class($error)]);
            return false;
        });
        $exceptions->render(function (Throwable $error) {
            $status = $error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $error->getStatusCode() : 500;
            if ($error instanceof Illuminate\Database\QueryException || $error instanceof PDOException) {
                $status = 503;
            }
            $code = match ($status) {
                401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                405 => 'method_not_allowed', 419 => 'csrf_mismatch', 503 => 'temporarily_unavailable',
                default => 'internal_error',
            };
            $headers = $status === 405 ? ['Allow' => $error->getHeaders()['Allow'] ?? ''] : [];
            return ApiError::response($status, $code, headers: $headers);
        });
    })
    ->create();
