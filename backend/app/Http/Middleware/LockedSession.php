<?php

namespace App\Http\Middleware;

use App\Auth\PgLock;
use App\Auth\RollbackResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;

final class LockedSession extends StartSession
{
    protected function handleRequestWhileBlocking(Request $request, $session, Closure $next)
    {
        try {
            return DB::transaction(function () use ($request, $session, $next) {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '10s'");
                PgLock::acquire(PgLock::key('session', $session->getId()));

                $response = $this->handleStatefulRequest($request, $session, $next);
                // Laravel may render an exception to a response inside the route pipeline.
                // Do not commit a partially completed business write behind that 5xx.
                if ($response->getStatusCode() >= 500) {
                    throw new RollbackResponse($response);
                }

                return $response;
            });
        } catch (RollbackResponse $failure) {
            return $failure->response;
        }
    }

    protected function storeCurrentUrl(Request $request, $session)
    {
        // API only: do not persist URLs or query strings in session payloads.
    }
}
