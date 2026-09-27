<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class VerifyCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false; // The real token check is required in tests, too.
    }

    protected function hasValidOrigin($request)
    {
        return false; // Laravel 13 origin shortcut must not bypass our token contract.
    }
}
