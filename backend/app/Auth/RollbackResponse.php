<?php

namespace App\Auth;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

// Internal control flow only: return a rendered failure after DB rollback.
final class RollbackResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Request transaction refused.');
    }
}
